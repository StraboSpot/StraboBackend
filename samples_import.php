<?php
/**
 * File: samples_import.php
 * Description: Tabular import for StraboSamples — upload a filled-out
 *              template (or edited export), review every change and
 *              resolve clarifications, then commit all-or-nothing.
 *
 *              Flow (two-phase, mirrors the Load Shapefile pattern):
 *                GET                  → upload form
 *                POST action=upload   → parse file, stash parsed rows in a
 *                                       server-side state file, render the
 *                                       REVIEW screen (plan counts, hard
 *                                       errors, vocab clarifications,
 *                                       custom-column decisions)
 *                POST action=confirm  → re-plan with the user's resolutions;
 *                                       clean plan → transactional commit via
 *                                       SampleTabularService → success page;
 *                                       otherwise re-render the review
 *                POST action=cancel   → discard state, back to My Samples
 *
 *              Hard errors always block the commit (all-or-nothing, agreed
 *              2026-07-03) — the review screen lists every one so a single
 *              fix pass on the file suffices.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/samplesdb/services/SampleTabularService.php";
require_once __DIR__ . "/samplesdb/lib/vocab.php";

$svc = new SampleTabularService($db, $neodb);
$svc->setUserpkey($userpkey);

const SI_MAX_UPLOAD_BYTES = 15728640; // 15 MB
const SI_MAX_CHANGE_ROWS  = 50;       // review detail: updated rows shown in full
const SI_MAX_CREATE_NAMES = 20;       // review detail: new-sample names listed

/**
 * Review-screen change value → display HTML. NULL/blank renders as a
 * dimmed placeholder ("blank" for current values, "cleared" for new —
 * a present-but-empty cell clears the field, and that must be visible).
 */
function si_change_html($v, $blankLabel)
{
    if ($v === null || $v === '') {
        return '<em class="si-dim">(' . htmlspecialchars($blankLabel) . ')</em>';
    }
    $s = (string)$v;
    if (mb_strlen($s) > 80) {
        $s = mb_substr($s, 0, 77) . '…';
    }
    return htmlspecialchars($s);
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

// View state for the render section below.
$view        = 'upload';   // upload | review | success
$uploadError = null;
$reviewPlan  = null;
$reviewToken = null;
$reviewFile  = null;
$reviewRes   = array();
$commitInfo  = null;
$commitError = null;

if ($action === 'cancel') {
    if (isset($_POST['token'])) {
        $svc->discardState($_POST['token']);
    }
    header('Location: /my_samples.php');
    exit;
}

if ($action === 'upload') {
    if (!isset($_FILES['tabfile']) || !is_uploaded_file($_FILES['tabfile']['tmp_name'])) {
        $uploadError = 'No file received — choose a .xlsx or .csv file first.';
    } elseif ($_FILES['tabfile']['size'] > SI_MAX_UPLOAD_BYTES) {
        $uploadError = 'File is larger than 15 MB.';
    } else {
        $parsed = $svc->parseUpload($_FILES['tabfile']['tmp_name'], $_FILES['tabfile']['name']);
        if (empty($parsed['ok'])) {
            $uploadError = $parsed['message'];
        } else {
            $reviewToken = $svc->saveState($parsed, $_FILES['tabfile']['name']);
            if ($reviewToken === null) {
                $uploadError = 'Could not start the import session (server temp storage unavailable). Please try again.';
            } else {
                $reviewPlan  = $svc->plan($parsed);
                $reviewFile  = $_FILES['tabfile']['name'];
                $view        = 'review';
            }
        }
    }
}

if ($action === 'confirm') {
    $reviewToken = isset($_POST['token']) ? $_POST['token'] : '';
    $state = $svc->loadState($reviewToken);
    if ($state === null) {
        $uploadError = 'This import session has expired — please upload the file again.';
    } else {
        // Decode resolutions. Raw values ride in base64-encoded array keys
        // so arbitrary vocab strings / column headers survive the POST.
        $resolutions = array('vocab' => array(), 'custom_columns' => array());
        if (isset($_POST['res_vocab']) && is_array($_POST['res_vocab'])) {
            foreach ($_POST['res_vocab'] as $col => $entries) {
                $field = ($col === 'material_type') ? 'display_sample_type'
                       : (($col === 'sampling_purpose') ? 'display_sample_purpose' : null);
                if ($field === null || !is_array($entries)) { continue; }
                foreach ($entries as $b64 => $choice) {
                    $raw = base64_decode($b64, true);
                    if ($raw === false || $choice === '') { continue; }
                    $resolutions['vocab'][$field][$raw] = $choice;
                }
            }
        }
        if (isset($_POST['res_custom']) && is_array($_POST['res_custom'])) {
            foreach ($_POST['res_custom'] as $b64 => $choice) {
                $header = base64_decode($b64, true);
                if ($header === false || !in_array($choice, array('import', 'ignore'))) { continue; }
                $resolutions['custom_columns'][$header] = $choice;
            }
        }

        $reviewPlan = $svc->plan($state['parsed'], $resolutions);
        $reviewFile = $state['filename'];
        $reviewRes  = $resolutions;

        if ($reviewPlan['clean']) {
            $result = $svc->commit($reviewPlan);
            if (!empty($result['ok'])) {
                $svc->discardState($reviewToken);
                $commitInfo = $result;
                $view = 'success';
            } else {
                $commitError = 'Import failed while saving (row ' . (isset($result['row']) ? $result['row'] : '?')
                             . ', ' . (isset($result['error']) ? $result['error'] : 'unknown') . '). Nothing was saved.';
                $view = 'review';
            }
        } else {
            $view = 'review';
        }
    }
}

$materialFlat = samples_vocab_material_flat();
$purposeFlat  = samples_vocab_sample_purposes();

include("includes/mheader.php");
?>

<style>
#si-wrap { max-width: 900px; margin: 0 auto; }
.si-panel { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12);
            border-radius: 8px; padding: 1.4em 1.6em; margin-bottom: 1.5em; }
.si-panel h3 { margin: 0 0 0.6em 0; }
.si-actions { display: flex; gap: 0.8em; flex-wrap: wrap; align-items: center; margin-top: 1em; }
.si-btn { display: inline-block; padding: 0.55em 1.3em; border-radius: 6px; border: 0;
          cursor: pointer; font-size: 0.95em; text-decoration: none; }
.si-btn-primary { background: #e2506b; color: #fff; }
.si-btn-primary:hover:not(:disabled) { background: #f06880; }
.si-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.si-btn-secondary { background: rgba(255,255,255,0.12); color: #fff; }
.si-btn-secondary:hover { background: rgba(255,255,255,0.2); }
.si-dl-links a { margin-right: 1.2em; }
.si-error { background: rgba(226,80,80,0.15); border: 1px solid rgba(226,80,80,0.5);
            border-radius: 6px; padding: 0.8em 1em; margin-bottom: 1em; }
.si-counts { display: flex; gap: 1em; flex-wrap: wrap; margin: 0.8em 0; }
.si-chip { padding: 0.4em 1em; border-radius: 999px; background: rgba(255,255,255,0.1); }
.si-chip strong { margin-right: 0.35em; }
.si-table { width: 100%; border-collapse: collapse; margin: 0.8em 0; font-size: 0.92em; }
.si-table th, .si-table td { text-align: left; padding: 0.45em 0.7em;
                             border-bottom: 1px solid rgba(255,255,255,0.1); }
.si-table th { opacity: 0.75; font-weight: 600; }
.si-issue-row { margin: 0.7em 0; display: flex; gap: 0.9em; align-items: center; flex-wrap: wrap; }
.si-issue-row code { background: rgba(255,255,255,0.1); padding: 0.15em 0.5em; border-radius: 4px; }
.si-issue-row select { min-width: 220px; }
.si-note { opacity: 0.75; font-size: 0.9em; }
.si-dim { opacity: 0.55; font-style: italic; }
.si-change-table td { vertical-align: top; }
.si-change-table .si-col-name { white-space: nowrap; }
.si-warn { background: rgba(240,200,80,0.12); border: 1px solid rgba(240,200,80,0.4);
           border-radius: 6px; padding: 0.7em 1em; margin: 0.8em 0; font-size: 0.92em; }
.si-success-big { font-size: 1.15em; margin-bottom: 0.7em; }
/* Styled file picker: the native input is visually hidden inside the label
   (kept technically focusable-offscreen so keyboard users still reach it). */
.si-file-label { position: relative; overflow: hidden; }
.si-file-label input[type="file"] {
    position: absolute; left: -9999px; width: 1px; height: 1px; opacity: 0;
}
.si-file-name { opacity: 0.8; margin-left: 0.6em; }
/* Busy overlay shown while an upload/validate or commit POST is in flight. */
#si-busy-overlay {
    position: fixed; top: 0; right: 0; bottom: 0; left: 0;
    background: rgba(20, 20, 30, 0.75);
    display: none; align-items: center; justify-content: center; z-index: 9999;
}
#si-busy-overlay.active { display: flex; }
.si-busy-panel {
    background: #2a2a3a; border: 1px solid rgba(255,255,255,0.15);
    border-radius: 8px; padding: 1.6em 2.2em; text-align: center; max-width: 420px;
}
.si-busy-spinner {
    width: 34px; height: 34px; margin: 0 auto 0.9em auto;
    border: 4px solid rgba(255,255,255,0.2); border-top-color: #e2506b;
    border-radius: 50%; animation: si-spin 0.9s linear infinite;
}
@keyframes si-spin { to { transform: rotate(360deg); } }
.si-busy-title { font-size: 1.05em; margin-bottom: 0.3em; }
.si-busy-note  { opacity: 0.7; font-size: 0.9em; }
</style>

<div id="main" class="wrapper style1">
    <div class="container">
        <header class="major">
            <h2>Import Samples</h2>
        </header>
        <div id="si-wrap">

<?php if ($view === 'upload'): ?>

        <?php if ($uploadError !== null): ?>
        <div class="si-error"><?= htmlspecialchars($uploadError) ?></div>
        <?php endif; ?>

        <div class="si-panel">
            <h3>1. Get a file to fill out</h3>
            <p class="si-note">Start from the empty template, or export your existing samples to
               clean them up and re-upload. Custom columns you add become custom fields on your samples.</p>
            <div class="si-dl-links">
                <a href="/samples_tabular_download.php?what=template">Download template (.xlsx)</a>
                <a href="/samples_tabular_download.php?what=export">Export my samples (.xlsx)</a>
                <a href="/samples_tabular_download.php?what=export&format=csv">Export as .csv</a>
            </div>
        </div>

        <div class="si-panel">
            <h3>2. Upload it back</h3>
            <p class="si-note">Accepted: .xlsx or .csv, up to 15&nbsp;MB. You will review every
               change before anything is saved — uploads never delete samples.</p>
            <form method="post" enctype="multipart/form-data" action="/samples_import.php" id="si-upload-form">
                <input type="hidden" name="action" value="upload">
                <div>
                    <label class="si-btn si-btn-secondary si-file-label">
                        Choose file&hellip;
                        <input type="file" name="tabfile" accept=".xlsx,.xls,.csv" id="si-file-input">
                    </label>
                    <span class="si-file-name" id="si-file-name">No file selected</span>
                </div>
                <div class="si-actions">
                    <button type="submit" class="si-btn si-btn-primary" id="si-upload-btn" disabled>Upload &amp; review</button>
                    <a class="si-btn si-btn-secondary" href="/my_samples.php">Back to My Samples</a>
                </div>
            </form>
        </div>

<?php elseif ($view === 'review'):
        $hasHard = !empty($reviewPlan['hard_errors']);
        $hasSoft = !empty($reviewPlan['soft_vocab']) || !empty($reviewPlan['soft_custom']);
?>

        <?php if ($commitError !== null): ?>
        <div class="si-error"><?= htmlspecialchars($commitError) ?></div>
        <?php endif; ?>

        <div class="si-panel">
            <h3>Review: <?= htmlspecialchars($reviewFile) ?></h3>
            <div class="si-counts">
                <span class="si-chip"><strong><?= (int)$reviewPlan['counts']['create'] ?></strong> new</span>
                <span class="si-chip"><strong><?= (int)$reviewPlan['counts']['update'] ?></strong> updated</span>
                <span class="si-chip"><strong><?= (int)$reviewPlan['counts']['noop'] ?></strong> unchanged</span>
            </div>
            <?php if (!empty($reviewPlan['ignored_headers'])): ?>
            <p class="si-note">Ignored read-only columns: <?= htmlspecialchars(implode(', ', $reviewPlan['ignored_headers'])) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($hasHard): ?>
        <div class="si-panel">
            <h3>Errors — fix these in your file and upload again</h3>
            <p class="si-note">Imports are all-or-nothing: nothing is saved while any row has an error.</p>
            <table class="si-table">
                <tr><th>Row</th><th>Column</th><th>Problem</th></tr>
                <?php foreach ($reviewPlan['hard_errors'] as $e): ?>
                <tr>
                    <td><?= (int)$e['row'] ?></td>
                    <td><?= htmlspecialchars($e['column']) ?></td>
                    <td><?= htmlspecialchars($e['message']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php
        // Change detail — old → new per field for every updated row, plus
        // the names of samples about to be created. Pure display: the data
        // rides on the plan rows the server diffed anyway, and it re-renders
        // live as vocab/custom resolutions re-plan (e.g. ignoring a custom
        // column drops its diffs out of this list).
        $siChangedRows = array();
        $siCreateNames = array();
        foreach ($reviewPlan['rows'] as $r) {
            if ($r['action'] === 'update' && !empty($r['changes'])) {
                $siChangedRows[] = $r;
            } elseif ($r['action'] === 'create' && isset($r['input']['name'])) {
                $siCreateNames[] = $r['input']['name'];
            }
        }
        $siShownRows  = array_slice($siChangedRows, 0, SI_MAX_CHANGE_ROWS);
        $siShownNames = array_slice($siCreateNames, 0, SI_MAX_CREATE_NAMES);
        ?>
        <?php if (!empty($siShownRows) || !empty($siShownNames)): ?>
        <div class="si-panel">
            <h3>What will change</h3>
            <?php if (!empty($siShownNames)): ?>
            <p class="si-note">Creating <?= count($siCreateNames) ?> new
               sample<?= count($siCreateNames) == 1 ? '' : 's' ?>:
               <?= htmlspecialchars(implode(', ', $siShownNames)) ?><?php
                   if (count($siCreateNames) > count($siShownNames)):
                       ?> &hellip;and <?= count($siCreateNames) - count($siShownNames) ?> more<?php
                   endif; ?></p>
            <?php endif; ?>
            <?php if (!empty($siShownRows)): ?>
            <table class="si-table si-change-table">
                <tr><th>Row</th><th>Sample</th><th>Column</th><th>Current value</th><th>New value</th></tr>
                <?php foreach ($siShownRows as $r): $nch = count($r['changes']); ?>
                    <?php foreach ($r['changes'] as $i => $c): ?>
                <tr>
                    <?php if ($i === 0): ?>
                    <td rowspan="<?= (int)$nch ?>"><?= (int)$r['n'] ?></td>
                    <td rowspan="<?= (int)$nch ?>" class="si-col-name"><?= htmlspecialchars((string)$r['sample_name']) ?></td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars($c['column']) ?></td>
                    <td><?= si_change_html($c['old'], 'blank') ?></td>
                    <td><?= si_change_html($c['new'], 'cleared') ?></td>
                </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </table>
            <?php if (count($siChangedRows) > count($siShownRows)): ?>
            <p class="si-note">&hellip;and <?= count($siChangedRows) - count($siShownRows) ?> more
               changed row<?= (count($siChangedRows) - count($siShownRows)) == 1 ? '' : 's' ?> not shown.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="post" action="/samples_import.php" id="si-confirm-form">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="token" value="<?= htmlspecialchars($reviewToken) ?>">

            <?php if (!empty($reviewPlan['soft_vocab'])): ?>
            <div class="si-panel">
                <h3>Values we don&rsquo;t recognize</h3>
                <p class="si-note">Map each to a standard value, or keep it as free text — both are fine.</p>
                <?php foreach ($reviewPlan['soft_vocab'] as $col => $entries):
                        $flat = ($col === 'material_type') ? $materialFlat : $purposeFlat; ?>
                    <?php foreach ($entries as $raw => $info): ?>
                    <div class="si-issue-row">
                        <code><?= htmlspecialchars($raw) ?></code>
                        <span class="si-note">(<?= htmlspecialchars($col) ?>,
                            <?= (int)$info['count'] ?> row<?= $info['count'] == 1 ? '' : 's' ?>)</span>
                        <select name="res_vocab[<?= htmlspecialchars($col) ?>][<?= base64_encode($raw) ?>]">
                            <option value="__freetext__">Keep as free text: &ldquo;<?= htmlspecialchars($raw) ?>&rdquo;</option>
                            <?php foreach ($flat as $value => $label):
                                    $sel = (isset($info['suggestion']) && $info['suggestion'] === $label) ? ' selected' : ''; ?>
                            <option value="<?= htmlspecialchars($value) ?>"<?= $sel ?>>Map to: <?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($reviewPlan['soft_custom'])): ?>
            <div class="si-panel">
                <h3>Custom columns</h3>
                <p class="si-note">These aren&rsquo;t standard StraboSpot columns. Import them as custom
                   fields (they round-trip on export), or ignore them.</p>
                <?php foreach ($reviewPlan['soft_custom'] as $i => $header): $b64 = base64_encode($header); ?>
                <div class="si-issue-row">
                    <code><?= htmlspecialchars($header) ?></code>
                    <!-- Site-theme radios: main.css hides the native input and
                         draws the control on the ADJACENT label's :before, so
                         the input+label pair must be siblings (never wrap the
                         input inside the label). The span keeps normal flow
                         inside this flex row so the theme's float layout works. -->
                    <span class="si-radio-pair">
                        <input type="radio" name="res_custom[<?= $b64 ?>]" value="import"
                               id="si-cc-<?= (int)$i ?>-import" checked>
                        <label for="si-cc-<?= (int)$i ?>-import">Import as custom field</label>
                    </span>
                    <span class="si-radio-pair">
                        <input type="radio" name="res_custom[<?= $b64 ?>]" value="ignore"
                               id="si-cc-<?= (int)$i ?>-ignore">
                        <label for="si-cc-<?= (int)$i ?>-ignore">Ignore</label>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($reviewPlan['warnings'])): ?>
            <div class="si-warn">
                <?php foreach ($reviewPlan['warnings'] as $w): ?>
                Row <?= (int)$w['row'] ?>: <?= htmlspecialchars($w['message']) ?><br>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="si-actions">
                <?php if (!$hasHard): ?>
                <button type="submit" class="si-btn si-btn-primary">
                    Confirm import (<?= (int)$reviewPlan['counts']['create'] ?> new,
                    <?= (int)$reviewPlan['counts']['update'] ?> updated)
                </button>
                <?php else: ?>
                <a class="si-btn si-btn-primary" href="/samples_import.php">Upload a corrected file</a>
                <?php endif; ?>
                <button type="submit" class="si-btn si-btn-secondary" name="action" value="cancel"
                        formnovalidate id="si-cancel-btn">Cancel</button>
            </div>
        </form>

<?php else: /* success */ ?>

        <div class="si-panel">
            <h3>Import complete</h3>
            <div class="si-success-big">
                <?= (int)$commitInfo['created'] ?> sample<?= $commitInfo['created'] == 1 ? '' : 's' ?> created,
                <?= (int)$commitInfo['updated'] ?> updated,
                <?= (int)$commitInfo['noop'] ?> unchanged.
            </div>
            <p class="si-note">Every change is recorded in each sample&rsquo;s changelog. Newly created
               samples receive internal ids — they&rsquo;ll appear in your next export.</p>
            <div class="si-actions">
                <a class="si-btn si-btn-primary" href="/my_samples.php">Back to My Samples</a>
                <a class="si-btn si-btn-secondary" href="/samples_import.php">Import another file</a>
            </div>
        </div>

<?php endif; ?>

        </div>
        <div class="bottomSpacer"></div>
    </div>
</div>

<!-- Busy overlay: shown by JS the moment a long-running POST leaves the page
     (upload/validate or confirm/commit), since both are synchronous form
     submits with no other feedback until the response renders. -->
<div id="si-busy-overlay" aria-hidden="true">
    <div class="si-busy-panel" role="status">
        <div class="si-busy-spinner"></div>
        <div class="si-busy-title" id="si-busy-title">Working&hellip;</div>
        <div class="si-busy-note">Please keep this page open.</div>
    </div>
</div>

<script>
(function() {
    'use strict';
    // ES5 on purpose — matches the Phase G browser-compat baseline for the
    // samples pages (Safari/iOS included).

    var overlay = document.getElementById('si-busy-overlay');
    function showBusy(title) {
        document.getElementById('si-busy-title').textContent = title;
        overlay.className = 'active';
        overlay.setAttribute('aria-hidden', 'false');
    }

    // --- Upload form: styled picker + filename readout + busy state ---
    var fileInput  = document.getElementById('si-file-input');
    var uploadForm = document.getElementById('si-upload-form');
    if (fileInput && uploadForm) {
        var fileName  = document.getElementById('si-file-name');
        var uploadBtn = document.getElementById('si-upload-btn');
        fileInput.addEventListener('change', function() {
            var has = fileInput.files && fileInput.files.length > 0;
            fileName.textContent = has ? fileInput.files[0].name : 'No file selected';
            uploadBtn.disabled = !has;
        });
        uploadForm.addEventListener('submit', function(e) {
            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault();
                return;
            }
            showBusy('Uploading & validating…');
            // Disable AFTER this tick so the submit serializes normally.
            setTimeout(function() { uploadBtn.disabled = true; }, 0);
        });
    }

    // --- Confirm form: busy state on commit (not on Cancel) ---
    var confirmForm = document.getElementById('si-confirm-form');
    if (confirmForm) {
        var cancelClicked = false;
        var cancelBtn = document.getElementById('si-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() { cancelClicked = true; });
        }
        confirmForm.addEventListener('submit', function() {
            if (cancelClicked) { return; }
            showBusy('Importing your changes…');
            setTimeout(function() {
                var btns = confirmForm.querySelectorAll('button[type="submit"]');
                for (var i = 0; i < btns.length; i++) { btns[i].disabled = true; }
            }, 0);
        });
    }
})();
</script>

<?php include("includes/mfooter.php"); ?>
