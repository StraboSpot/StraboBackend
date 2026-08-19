<?php
/**
 * File: export.php
 * Description: Template Wizard - Export & Template Download
 *              GET (no args)      -> picker form (dataset + template + format)
 *              ?what=template     -> blank template workbook/CSV for a saved
 *                                    template (locked system columns, vocab
 *                                    dropdowns, embedded spec)
 *              ?what=export       -> a dataset's spots serialized through a
 *                                    template in long format (round-trips
 *                                    back through the Import page)
 *              Owner-only in both directions.
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

$what = isset($_GET['what']) ? $_GET['what'] : '';

function tw_stream_workbook($twsvc, $export, $isTemplate, $templateName, $basename)
{
    if (!class_exists('PHPExcel')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/PHPExcel.php';
    }
    $wb = $twsvc->buildWorkbook($export, $isTemplate, $templateName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $basename . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new PHPExcel_Writer_Excel2007($wb);
    $writer->save('php://output');
    exit;
}

function tw_stream_csv($twsvc, $export, $basename)
{
    $csv = $twsvc->buildCsv($export);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $basename . '.csv"');
    header('Cache-Control: max-age=0');
    echo $csv;
    exit;
}

function tw_safe_name($s)
{
    $s = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$s);
    return trim(preg_replace('/_+/', '_', $s), '_');
}

$downloadError = null;

if ($what === 'template' || $what === 'export') {
    $format = (isset($_GET['format']) && $_GET['format'] === 'csv') ? 'csv' : 'xlsx';
    $tplId  = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
    $tpl = $twsvc->getTemplate($tplId);
    if ($tpl === null) {
        $downloadError = 'Template not found.';
    } else {
        if ($what === 'template') {
            $headers = array();
            foreach ($twsvc->columnDefs($tpl['spec']) as $d) { $headers[] = $d['header']; }
            $export = array('headers' => $headers, 'rows' => array(), 'spec' => $tpl['spec']);
            $base = 'StraboSpot_' . tw_safe_name($tpl['name']) . '_template';
            if ($format === 'csv') { tw_stream_csv($twsvc, $export, $base); }
            tw_stream_workbook($twsvc, $export, true, $tpl['name'], $base);
        } else {
            $datasetId = isset($_GET['dataset_id']) ? (int)$_GET['dataset_id'] : 0;
            $export = $twsvc->exportLong($datasetId, $tpl['spec']);
            if (empty($export['ok'])) {
                $downloadError = $export['message'];
            } else {
                $base = 'StraboSpot_' . tw_safe_name($export['dataset_name'] !== '' ? $export['dataset_name'] : ('dataset_' . $datasetId))
                      . '_' . tw_safe_name($tpl['name']);
                if ($format === 'csv') { tw_stream_csv($twsvc, $export, $base); }
                tw_stream_workbook($twsvc, $export, false, $tpl['name'], $base);
            }
        }
    }
}

$myProjects = $twsvc->myProjects();
$templates  = $twsvc->listTemplates();
$prefillProject = isset($_GET['project_id']) ? trim($_GET['project_id']) : '';
$prefillDataset = isset($_GET['dataset_id']) ? trim($_GET['dataset_id']) : '';
if ($prefillDataset !== '' && $prefillProject === '') {
    // Deep link from My Field Data passes only the dataset — resolve its project.
    $pid = $neodb->get_var(
        "MATCH (p:Project {userpkey: " . (int)$userpkey . "})-[:HAS_DATASET]->(d:Dataset {id: " . (int)$prefillDataset . ", userpkey: " . (int)$userpkey . "}) RETURN p.id");
    if ($pid !== '' && $pid !== null) { $prefillProject = (string)$pid; }
}

include("includes/mheader.php");
?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Export Field Data</h2>
						</header>

						<!-- Content -->
							<section id="content">

							<?php if ($downloadError !== null): ?>
							<div style="background-color: #4c2e2e; color: #eceff4; padding: 12px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #bf616a;">
								<strong><?php echo htmlspecialchars($downloadError); ?></strong>
							</div>
							<?php endif; ?>

							<div style="background-color: #3b4252; color: #eceff4; padding: 10px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #5e81ac;">
								<strong>Export a dataset through one of your templates.</strong>
								<div style="margin: 10px 0 0 0; padding-left: 20px;">
									The file uses the long format (one row per measurement) and carries its template inside, so you can edit it
									locally and bring it back through the <a href="review.php">Import page</a> — unchanged rows import as
									"unchanged", edited ones as updates. Every spot exports; line/polygon spots show their centroid and keep
									their geometry.
								</div>
							</div>

							<?php if (empty($templates)): ?>
							<p>You have no saved templates yet — <a href="index.php">design one first</a>.</p>
							<?php else: ?>
							<form method="get" id="exportForm">
								<input type="hidden" name="what" value="export">
								<div class="row gtr-uniform gtr-25">
									<div class="col-3 col-12-small">
										<select id="tw-project">
											<option value="">-- Project --</option>
											<?php foreach ($myProjects as $p): ?>
											<option value="<?php echo htmlspecialchars($p['id']); ?>" <?php echo $prefillProject === (string)$p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-3 col-12-small">
										<select name="dataset_id" id="tw-dataset" data-selected="<?php echo htmlspecialchars($prefillDataset); ?>">
											<option value="">-- pick a project first --</option>
										</select>
									</div>
									<div class="col-3 col-12-small">
										<select name="template_id">
											<?php foreach ($templates as $t): ?>
											<option value="<?php echo (int)$t->pkey; ?>"><?php echo htmlspecialchars($t->name); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-3 col-12-small">
										<select name="format">
											<option value="xlsx">Excel (.xlsx)</option>
											<option value="csv">CSV (.csv)</option>
										</select>
									</div>
									<div class="col-12">
										<ul class="actions">
											<li><input type="submit" class="primary" value="Download Export"></li>
											<li><a href="index.php" class="button">Back to Wizard</a></li>
										</ul>
									</div>
								</div>
							</form>

							<hr>
							<h3>Blank template downloads</h3>
							<p>A fillable spreadsheet for a template (no data): pick the template, then
								<a href="#" onclick="twTpl('xlsx'); return false;">Excel</a> or
								<a href="#" onclick="twTpl('csv'); return false;">CSV</a>.
							</p>
							<select id="tw-tpl-only">
								<?php foreach ($templates as $t): ?>
								<option value="<?php echo (int)$t->pkey; ?>"><?php echo htmlspecialchars($t->name); ?></option>
								<?php endforeach; ?>
							</select>
							<script>
								function twTpl(fmt) {
									var id = document.getElementById('tw-tpl-only').value;
									window.location = 'export.php?what=template&template_id=' + encodeURIComponent(id) + '&format=' + fmt;
								}
							</script>
							<?php endif; ?>

							</section>

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
