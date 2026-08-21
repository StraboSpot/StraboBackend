<?php
/**
 * File: review.php
 * Description: Template Wizard - Review & Commit (Page 3)
 *              Receives data from the designer grid (action=stage) or a
 *              direct file upload (action=upload), stashes the parsed rows
 *              in a server-side state token, then:
 *                action=plan    -> validate + diff against the chosen
 *                                  project/dataset target (review screen)
 *                action=confirm -> re-plan with the user's resolutions;
 *                                  clean plan -> journaled commit with
 *                                  compensating rollback -> success page
 *                action=cancel  -> discard state, back to the wizard
 *
 *              Nothing is written outside commit(); hard errors always
 *              block the whole file (all-or-nothing).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir(dirname(__DIR__));
include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/services/FieldTabularService.php";

$twsvc = new FieldTabularService($db, $neodb, $strabo);
$twsvc->setUserpkey($userpkey);

const TW_MAX_UPLOAD_BYTES = 15728640; // 15 MB

$action = isset($_POST['action']) ? $_POST['action'] : '';

// View state for the render section below.
$view         = 'upload';   // upload | target | review | success
$pageError    = null;
$token        = null;
$sourceLabel  = null;
$rowCount     = 0;
$plan         = null;
$resolutions  = array();
$target       = array('project_id' => '', 'dataset_choice' => 'existing', 'dataset_id' => '', 'dataset_name' => '');
$commitInfo   = null;
$templateName = '';

$myProjects = $twsvc->myProjects();
$templates  = $twsvc->listTemplates();

function tw_b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function tw_unb64($s) { return base64_decode(strtr($s, '-_', '+/')); }

/** Pull target + resolutions out of a POST. */
function tw_read_target() {
    return array(
        'project_id'     => isset($_POST['project_id']) ? trim($_POST['project_id']) : '',
        'dataset_choice' => (isset($_POST['dataset_choice']) && $_POST['dataset_choice'] === 'new') ? 'new' : 'existing',
        'dataset_id'     => isset($_POST['dataset_id']) ? trim($_POST['dataset_id']) : '',
        'dataset_name'   => isset($_POST['dataset_name']) ? trim($_POST['dataset_name']) : '',
    );
}

function tw_read_resolutions() {
    $res = array('vocab' => array(), 'custom_columns' => array());
    if (isset($_POST['vocab_res']) && is_array($_POST['vocab_res'])) {
        foreach ($_POST['vocab_res'] as $gfB64 => $raws) {
            $gf = tw_unb64($gfB64);
            if (!is_array($raws)) { continue; }
            foreach ($raws as $rawB64 => $choice) {
                $raw = tw_unb64($rawB64);
                if ($choice === '__map__') {
                    $mapKey = 'vocab_map_' . $gfB64 . '_' . $rawB64;
                    $choice = isset($_POST[$mapKey]) ? $_POST[$mapKey] : '';
                }
                if ($choice !== '') {
                    $res['vocab'][$gf][$raw] = $choice;
                }
            }
        }
    }
    if (isset($_POST['custom_res']) && is_array($_POST['custom_res'])) {
        foreach ($_POST['custom_res'] as $hB64 => $choice) {
            $res['custom_columns'][tw_unb64($hB64)] = ($choice === 'ignore') ? 'ignore' : 'import';
        }
    }
    return $res;
}

function tw_service_target($t) {
    return array(
        'project_id'   => (int)$t['project_id'],
        'dataset_id'   => ($t['dataset_choice'] === 'existing' && $t['dataset_id'] !== '') ? (int)$t['dataset_id'] : null,
        'dataset_name' => ($t['dataset_choice'] === 'new') ? $t['dataset_name'] : '',
    );
}

if ($action === 'cancel') {
    if (isset($_POST['token'])) {
        $twsvc->discardState($_POST['token']);
    }
    header('Location: /TemplateWizard/');
    exit;
}

if ($action === 'stage') {
    // From the designer grid.
    $grid = json_decode(isset($_POST['grid_json']) ? $_POST['grid_json'] : '', true);
    $spec = json_decode(isset($_POST['spec_json']) ? $_POST['spec_json'] : '', true);
    $templateName = isset($_POST['template_name']) ? $_POST['template_name'] : '';
    if (!is_array($grid) || count($grid) < 2) {
        $pageError = 'No data rows received from the designer.';
    } else {
        $parsed = $twsvc->parseGrid($grid, is_array($spec) ? $spec : null);
        if (empty($parsed['ok'])) {
            $pageError = $parsed['message'];
        } else {
            $token = $twsvc->saveState(array(
                'parsed' => $parsed,
                'source' => 'designer grid' . ($templateName !== '' ? " (template: $templateName)" : ''),
            ));
            if ($token === null) {
                $pageError = 'Could not stash the upload for review — try again.';
            } else {
                $view = 'target';
                $sourceLabel = 'designer grid' . ($templateName !== '' ? " (template: $templateName)" : '');
                $rowCount = count($parsed['rows']);
                $target['project_id'] = isset($_POST['project_id']) ? trim($_POST['project_id']) : '';
            }
        }
    }
}

if ($action === 'upload') {
    if (!isset($_FILES['tabfile']) || !is_uploaded_file($_FILES['tabfile']['tmp_name'])) {
        $pageError = 'No file received — choose a .xlsx or .csv file first.';
    } elseif ($_FILES['tabfile']['size'] > TW_MAX_UPLOAD_BYTES) {
        $pageError = 'File is larger than 15 MB.';
    } else {
        $spec = null;
        if (isset($_POST['template_pkey']) && $_POST['template_pkey'] !== '') {
            $tpl = $twsvc->getTemplate((int)$_POST['template_pkey']);
            if ($tpl !== null) { $spec = $tpl['spec']; }
        }
        $parsed = $twsvc->parseUpload($_FILES['tabfile']['tmp_name'], $_FILES['tabfile']['name'], $spec);
        if (empty($parsed['ok'])) {
            $pageError = $parsed['message'];
        } else {
            $label = htmlspecialchars($_FILES['tabfile']['name'])
                   . (!empty($parsed['embedded_spec']) ? ' (embedded template recognized)' : '');
            $token = $twsvc->saveState(array('parsed' => $parsed, 'source' => $label));
            if ($token === null) {
                $pageError = 'Could not stash the upload for review — try again.';
            } else {
                $view = 'target';
                $sourceLabel = $label;
                $rowCount = count($parsed['rows']);
            }
        }
    }
}

if ($action === 'plan' || $action === 'confirm') {
    $token = isset($_POST['token']) ? $_POST['token'] : '';
    $state = $twsvc->loadState($token);
    if ($state === null) {
        $pageError = 'This review session expired (or was already completed). Upload the file again.';
        $view = 'upload';
    } else {
        $target = tw_read_target();
        $resolutions = tw_read_resolutions();
        $sourceLabel = $state['source'];
        $rowCount = count($state['parsed']['rows']);
        if ($target['project_id'] === ''
            || ($target['dataset_choice'] === 'existing' && $target['dataset_id'] === '')
            || ($target['dataset_choice'] === 'new' && $target['dataset_name'] === '')) {
            $pageError = 'Pick the target project and dataset first.';
            $view = 'target';
        } else {
            $plan = $twsvc->plan($state['parsed'], tw_service_target($target), $resolutions);
            $view = 'review';
            if ($action === 'confirm') {
                if (!empty($plan['clean'])) {
                    $commit = $twsvc->commit($plan);
                    if (!empty($commit['ok'])) {
                        $twsvc->discardState($token);
                        $commitInfo = $commit;
                        $view = 'success';
                    } else {
                        $pageError = $commit['message'];
                    }
                } else {
                    $pageError = 'There are still unresolved issues below — nothing was imported.';
                }
            }
        }
    }
}

include("includes/mheader.php");
?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Import Field Data</h2>
						</header>

						<!-- Content -->
							<section id="content">

							<?php if ($pageError !== null): ?>
							<div style="background-color: #4c2e2e; color: #eceff4; padding: 12px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #bf616a;">
								<strong><?php echo htmlspecialchars($pageError); ?></strong>
							</div>
							<?php endif; ?>

							<?php if ($view === 'upload'): ?>
							<!-- ============ UPLOAD ============ -->
							<div style="background-color: #3b4252; color: #eceff4; padding: 10px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #5e81ac;">
								<strong>Upload a spreadsheet of spots (.xlsx or .csv).</strong>
								<div style="margin: 10px 0 0 0; padding-left: 20px;">
									Files downloaded from the Template Wizard or a dataset export carry their template inside — they are recognized
									automatically. For anything else, columns are matched by header name; unknown columns become custom fields
									(you confirm them during review). Every change is shown for review before anything is saved, and imports are
									all-or-nothing. Need a starting point? <a href="index.php">Design a template</a> first &mdash; and if your
									spots carry multiple measurements, see the <a href="howto.php">how-to guide</a> for the row format.
								</div>
							</div>
							<form method="post" enctype="multipart/form-data">
								<input type="hidden" name="action" value="upload">
								<div class="row gtr-uniform gtr-25">
									<div class="col-6 col-12-small">
										<!-- styled picker: native input visually hidden inside the
										     label but kept focusable-offscreen for keyboard users
										     (samples_import.php pattern) -->
										<label for="tabfile" class="button">Choose File&hellip;</label>
										<span id="tw-file-name" style="opacity: 0.8; margin-left: 0.6em;">No file selected</span>
										<input type="file" name="tabfile" id="tabfile" accept=".xlsx,.xls,.csv"
											style="position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0;">
									</div>
									<div class="col-6 col-12-small">
										<select name="template_pkey">
											<option value="">Template: auto-detect (embedded or by header)</option>
											<?php foreach ($templates as $t): ?>
											<option value="<?php echo (int)$t->pkey; ?>"><?php echo htmlspecialchars($t->name); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-12">
										<ul class="actions">
											<li><input type="submit" class="primary" value="Upload &amp; Review" id="tw-upload-btn" disabled></li>
											<li><a href="index.php" class="button">Back to Wizard</a></li>
										</ul>
									</div>
								</div>
							</form>
							<script>
								(function () {
									var input = document.getElementById('tabfile');
									var name  = document.getElementById('tw-file-name');
									var btn   = document.getElementById('tw-upload-btn');
									input.addEventListener('change', function () {
										var has = input.files && input.files.length;
										name.textContent = has ? input.files[0].name : 'No file selected';
										btn.disabled = !has;
									});
								})();
							</script>
							<?php endif; ?>

							<?php if ($view === 'target' || $view === 'review'): ?>
							<!-- ============ TARGET PICKER (+ review when planned) ============ -->
							<div style="background-color: #3b4252; color: #eceff4; padding: 10px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #5e81ac;">
								<strong>Source:</strong> <?php echo $sourceLabel; ?> &mdash; <?php echo (int)$rowCount; ?> data row<?php echo $rowCount === 1 ? '' : 's'; ?>
							</div>

							<form method="post" id="planForm">
								<input type="hidden" name="action" value="plan" id="tw-action">
								<input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

								<h3>Where should this data go?</h3>
								<div class="row gtr-uniform gtr-25">
									<div class="col-4 col-12-small">
										<select name="project_id" id="tw-project">
											<option value="">-- Project --</option>
											<?php foreach ($myProjects as $p): ?>
											<option value="<?php echo htmlspecialchars($p['id']); ?>" <?php echo ($target['project_id'] === (string)$p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-4 col-12-small">
										<input type="radio" name="dataset_choice" id="dc-existing" value="existing" <?php echo $target['dataset_choice'] === 'existing' ? 'checked' : ''; ?>>
										<label for="dc-existing">Existing dataset</label>
										<select name="dataset_id" id="tw-dataset" data-selected="<?php echo htmlspecialchars($target['dataset_id']); ?>">
											<option value="">-- pick a project first --</option>
										</select>
									</div>
									<div class="col-4 col-12-small">
										<input type="radio" name="dataset_choice" id="dc-new" value="new" <?php echo $target['dataset_choice'] === 'new' ? 'checked' : ''; ?>>
										<label for="dc-new">New dataset named:</label>
										<input type="text" name="dataset_name" placeholder="e.g. Legacy stations 2019" value="<?php echo htmlspecialchars($target['dataset_name']); ?>">
									</div>
									<div class="col-12">
										<ul class="actions">
											<li><input type="submit" class="primary" value="<?php echo $view === 'target' ? 'Analyze' : 'Re-analyze'; ?>"></li>
										</ul>
									</div>
								</div>

							<?php if ($view === 'review' && $plan !== null): ?>
								<!-- ============ REVIEW ============ -->
								<hr>
								<h3>Review</h3>
								<p>
									<span class="tw-chip tw-chip-create"><?php echo (int)$plan['counts']['create']; ?> new spot<?php echo $plan['counts']['create'] === 1 ? '' : 's'; ?></span>
									<span class="tw-chip tw-chip-update"><?php echo (int)$plan['counts']['update']; ?> updated</span>
									<span class="tw-chip tw-chip-noop"><?php echo (int)$plan['counts']['noop']; ?> unchanged</span>
									<span class="tw-chip"><?php echo (int)$plan['counts']['orientations']; ?> orientation<?php echo $plan['counts']['orientations'] === 1 ? '' : 's'; ?></span>
									<span class="tw-chip"><?php echo (int)$plan['counts']['samples']; ?> sample<?php echo $plan['counts']['samples'] === 1 ? '' : 's'; ?></span>
								</p>

								<?php if (!empty($plan['hard_errors'])): ?>
								<h4 style="color:#bf616a;">Errors — fix these in the file and re-upload (nothing can import until they're gone)</h4>
								<div style="max-height: 300px; overflow-y: auto;">
									<table>
										<thead><tr><th>Row</th><th>Column</th><th>Problem</th></tr></thead>
										<tbody>
											<?php foreach (array_slice($plan['hard_errors'], 0, 200) as $e): ?>
											<tr><td><?php echo (int)$e['row']; ?></td><td><?php echo htmlspecialchars($e['column']); ?></td><td><?php echo htmlspecialchars($e['message']); ?></td></tr>
											<?php endforeach; ?>
										</tbody>
									</table>
									<?php if (count($plan['hard_errors']) > 200): ?><p>…and <?php echo count($plan['hard_errors']) - 200; ?> more.</p><?php endif; ?>
								</div>
								<?php endif; ?>

								<?php if (!empty($plan['soft_vocab'])): ?>
								<h4>Unrecognized vocabulary — choose how to import each value</h4>
								<?php foreach ($plan['soft_vocab'] as $gf => $raws): $gfB64 = tw_b64($gf); ?>
									<?php foreach ($raws as $raw => $info): $rawB64 = tw_b64($raw);
										$name = "vocab_res[$gfB64][$rawB64]"; $rid = 'vr_' . $gfB64 . '_' . $rawB64; ?>
									<div class="tw-vocab-item">
										<strong>&ldquo;<?php echo htmlspecialchars($raw); ?>&rdquo;</strong>
										(<?php echo htmlspecialchars($info['label']); ?>, <?php echo (int)$info['count']; ?> row<?php echo $info['count'] === 1 ? '' : 's'; ?>)
										<div style="margin-left: 1.5em;">
											<?php if ($info['suggestion'] !== null): ?>
											<div>
												<input type="radio" name="<?php echo $name; ?>" id="<?php echo $rid; ?>_map" value="__map__" checked>
												<label for="<?php echo $rid; ?>_map">Map to: <strong><?php echo htmlspecialchars($info['suggestion']); ?></strong></label>
												<input type="hidden" name="vocab_map_<?php echo $gfB64 . '_' . $rawB64; ?>" value="<?php echo htmlspecialchars($info['suggestion']); ?>">
											</div>
											<?php endif; ?>
											<?php if (!empty($info['has_other'])): ?>
											<div>
												<input type="radio" name="<?php echo $name; ?>" id="<?php echo $rid; ?>_other" value="__other__" <?php echo $info['suggestion'] === null ? 'checked' : ''; ?>>
												<label for="<?php echo $rid; ?>_other">Keep as &ldquo;Other&rdquo;: stores <em><?php echo htmlspecialchars($raw); ?></em> losslessly</label>
											</div>
											<?php endif; ?>
											<div>
												<input type="radio" name="<?php echo $name; ?>" id="<?php echo $rid; ?>_free" value="__freetext__" <?php echo ($info['suggestion'] === null && empty($info['has_other'])) ? 'checked' : ''; ?>>
												<label for="<?php echo $rid; ?>_free">Keep exactly as typed (free text)</label>
											</div>
										</div>
									</div>
									<?php endforeach; ?>
								<?php endforeach; ?>
								<?php endif; ?>

								<?php if (!empty($plan['soft_custom'])): ?>
								<h4>Unknown columns — import as custom fields?</h4>
								<?php foreach ($plan['soft_custom'] as $header): $hB64 = tw_b64($header); ?>
								<div class="tw-vocab-item">
									<strong><?php echo htmlspecialchars($header); ?></strong>
									<span style="margin-left: 1em;">
										<input type="radio" name="custom_res[<?php echo $hB64; ?>]" id="cc_<?php echo $hB64; ?>_i" value="import" checked>
										<label for="cc_<?php echo $hB64; ?>_i">Import as custom field</label>
										&nbsp;
										<input type="radio" name="custom_res[<?php echo $hB64; ?>]" id="cc_<?php echo $hB64; ?>_x" value="ignore">
										<label for="cc_<?php echo $hB64; ?>_x">Ignore this column</label>
									</span>
								</div>
								<?php endforeach; ?>
								<?php endif; ?>

								<?php if (!empty($plan['warnings'])): ?>
								<h4 style="color:#ebcb8b;">Heads up</h4>
								<ul>
									<?php foreach (array_slice($plan['warnings'], 0, 50) as $w): ?>
									<li>Row <?php echo (int)$w['row']; ?>: <?php echo htmlspecialchars($w['message']); ?></li>
									<?php endforeach; ?>
								</ul>
								<?php endif; ?>

								<hr>
								<ul class="actions">
									<?php if (empty($plan['hard_errors'])): ?>
									<li><button type="submit" class="button primary" id="confirmBtn"
										onclick="document.getElementById('tw-action').value='confirm';">Confirm &amp; Import</button></li>
									<?php endif; ?>
									<li><button type="submit" class="button"
										onclick="document.getElementById('tw-action').value='cancel';">Cancel</button></li>
								</ul>
							<?php endif; ?>
							</form>
							<?php endif; ?>

							<?php if ($view === 'success' && $commitInfo !== null): ?>
							<!-- ============ SUCCESS ============ -->
							<div style="background-color: #2e4034; color: #eceff4; padding: 15px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #a3be8c;">
								<h3 style="margin-top:0;">Import complete</h3>
								<ul>
									<li><strong><?php echo (int)$commitInfo['created']; ?></strong> spot<?php echo $commitInfo['created'] === 1 ? '' : 's'; ?> created</li>
									<li><strong><?php echo (int)$commitInfo['updated']; ?></strong> updated</li>
									<li><strong><?php echo (int)$commitInfo['noop']; ?></strong> unchanged</li>
									<?php if (!empty($commitInfo['dataset_created'])): ?>
									<li>New dataset created (id <?php echo (int)$commitInfo['dataset_id']; ?>)</li>
									<?php endif; ?>
								</ul>
								<p style="font-size: 0.85em;">Import run #<?php echo (int)$commitInfo['run_id']; ?> — journaled for traceability.</p>
							</div>
							<ul class="actions">
								<li><a href="/my_field_data.php" class="button primary">My Field Data</a></li>
								<li><a href="index.php" class="button">Template Wizard</a></li>
								<li><a href="review.php" class="button">Import another file</a></li>
							</ul>
							<?php endif; ?>

							</section>

						<style>
							.tw-chip { display: inline-block; padding: 2px 12px; border-radius: 12px; background: #3b4252; margin-right: 6px; }
							.tw-chip-create { background: #2e4034; }
							.tw-chip-update { background: #33415e; }
							.tw-chip-noop   { background: #3b4252; }
							.tw-vocab-item  { background: rgba(255,255,255,0.04); border-radius: 4px; padding: 8px 12px; margin-bottom: 8px; }
						</style>

						<script>
							(function () {
								var proj = document.getElementById('tw-project');
								var ds   = document.getElementById('tw-dataset');
								if (!proj || !ds) { return; }
								function loadDatasets() {
									var pid = proj.value;
									ds.innerHTML = '<option value="">loading…</option>';
									if (pid === '') {
										ds.innerHTML = '<option value="">-- pick a project first --</option>';
										return;
									}
									fetch('ajax.php?action=datasets&project_id=' + encodeURIComponent(pid), {credentials: 'same-origin'})
										.then(function (r) { return r.json(); })
										.then(function (res) {
											var want = ds.getAttribute('data-selected') || '';
											var html = '<option value="">-- Dataset --</option>';
											(res.datasets || []).forEach(function (d) {
												html += '<option value="' + d.id + '"' + (String(d.id) === want ? ' selected' : '') + '>'
													 + d.name.replace(/</g, '&lt;') + '</option>';
											});
											ds.innerHTML = html;
										})
										.catch(function () { ds.innerHTML = '<option value="">could not load datasets</option>'; });
								}
								proj.addEventListener('change', loadDatasets);
								if (proj.value !== '') { loadDatasets(); }
							})();
						</script>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
?>
