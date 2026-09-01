<?php
/**
 * File: my_exports.php
 * Description: My Exports (docs/ExportBuilder_Design.md §9.2): the session
 *              user's export jobs, newest first, with live progress while
 *              anything is queued or running (polls exportjobs/api.php
 *              ?action=status every 2 s, 10 s when idle, paused while the
 *              tab is hidden). Actions: Download (done), Re-run (any
 *              finished state), Edit and re-run (builder pre-filled),
 *              Cancel (queued / best-effort running), Details (README).
 *              Clear finished / Clear expired hide rows behind an in-page
 *              confirm (never window.confirm). Rows are rendered by
 *              exportjobs/js/my_exports.js; this page is the shell plus
 *              notices from the query string (?new=<uuid> after a build,
 *              ?notice=expired|notready&j=<uuid> from download.php).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . '/exportjobs/lib/export_config.php';

$meCfg = export_config();
$meNotice = null;
if (isset($_GET['new']) && preg_match('/^[0-9a-f-]{36}$/i', (string)$_GET['new'])) {
	$meNotice = array('kind' => 'new', 'uuid' => strtolower((string)$_GET['new']));
} elseif (isset($_GET['notice']) && in_array($_GET['notice'], array('expired', 'notready'), true)) {
	$meNotice = array('kind' => (string)$_GET['notice'], 'uuid' => (isset($_GET['j']) && preg_match('/^[0-9a-f-]{36}$/i', (string)$_GET['j'])) ? strtolower((string)$_GET['j']) : null);
}

function me_asset($path) {
	$mtime = @filemtime(__DIR__ . '/' . ltrim($path, '/'));
	return htmlspecialchars($path . ($mtime ? '?v=' . $mtime : ''));
}

include("includes/mheader.php");
?>

<style>
.me-top { display: flex; flex-wrap: wrap; gap: 0.75em; align-items: center; justify-content: space-between; margin-bottom: 1.25em; }
.me-top .me-actions { display: flex; flex-wrap: wrap; gap: 0.5em; }
.me-notice { background: rgba(228,76,101,0.12); border: 1px solid rgba(228,76,101,0.4); border-radius: 4px; padding: 0.6em 1em; margin-bottom: 1.25em; font-size: 0.92em; }
.me-notice.me-notice-ok { background: rgba(80,180,120,0.12); border-color: rgba(80,180,120,0.45); }
.me-list { display: flex; flex-direction: column; gap: 0.9em; }
.me-job { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; padding: 1em 1.25em; }
.me-job-head { display: flex; flex-wrap: wrap; gap: 0.5em 1em; align-items: baseline; }
.me-job-head .me-summary { font-weight: 600; flex: 1 1 20em; }
.me-pill { display: inline-block; font-size: 0.72em; text-transform: uppercase; letter-spacing: 0.06em; padding: 0.2em 0.6em; border-radius: 3px; border: 1px solid rgba(255,255,255,0.25); color: rgba(255,255,255,0.8); }
.me-pill-queued { border-color: rgba(255,255,255,0.35); }
.me-pill-running { border-color: #f0a050; color: #f0a050; }
.me-pill-done { border-color: #5cb87a; color: #5cb87a; }
.me-pill-failed { border-color: #f06880; color: #f06880; }
.me-pill-expired, .me-pill-cancelled { border-color: rgba(255,255,255,0.2); color: rgba(255,255,255,0.45); }
.me-meta { color: rgba(255,255,255,0.6); font-size: 0.88em; margin-top: 0.35em; }
.me-meta span + span:before { content: " · "; color: rgba(255,255,255,0.3); }
.me-progress { margin-top: 0.6em; }
.me-progress .me-bar { height: 6px; background: rgba(255,255,255,0.1); border-radius: 3px; overflow: hidden; }
.me-progress .me-bar > div { height: 100%; background: #e44c65; width: 0; transition: width 0.4s; }
.me-progress .me-bar.me-indeterminate > div { width: 30%; animation: me-slide 1.2s infinite ease-in-out; }
@keyframes me-slide { 0% { margin-left: 0; } 100% { margin-left: 70%; } }
.me-progress .me-note { font-size: 0.85em; color: rgba(255,255,255,0.7); margin-top: 0.3em; }
.me-error { color: #f06880; font-size: 0.9em; margin-top: 0.4em; }
.me-row-actions { display: flex; flex-wrap: wrap; gap: 0.5em; margin-top: 0.8em; align-items: center; }
.me-row-actions .button.small { font-size: 0.75em; height: 2.6em; line-height: 2.6em; padding: 0 1.2em; }
.me-row-actions a.me-link { font-size: 0.85em; color: rgba(255,255,255,0.7); }
.me-row-actions a.me-link:hover { color: #f06880; }
.me-details { display: none; margin-top: 0.8em; background: rgba(0,0,0,0.25); border-radius: 4px; padding: 0.8em 1em; }
.me-details pre { white-space: pre-wrap; font-size: 0.8em; color: rgba(255,255,255,0.8); margin: 0; max-height: 24em; overflow: auto; }
.me-job.me-open .me-details { display: block; }
.me-empty { text-align: center; color: rgba(255,255,255,0.65); padding: 2.5em 1em; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; }
.me-empty a { color: #f06880; }
.me-flash { outline: 2px solid #e44c65; }
.me-dialog-back { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; display: none; }
.me-dialog { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #1f1f2e; border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; padding: 1.5em; z-index: 10001; max-width: 26em; width: calc(100% - 2em); display: none; }
.me-dialog h3 { margin: 0 0 0.5em 0; font-size: 1.1em; }
.me-dialog p { color: rgba(255,255,255,0.75); font-size: 0.92em; }
.me-dialog .me-dialog-actions { display: flex; gap: 0.5em; justify-content: flex-end; }
.me-dialog.me-show, .me-dialog-back.me-show { display: block; }
.me-footnote { color: rgba(255,255,255,0.5); font-size: 0.82em; margin-top: 1.5em; text-align: center; }
</style>

<div id="main" class="wrapper style1">
	<div class="container">
		<header class="major">
			<h2>My Exports</h2>
		</header>

		<div id="me-notice" class="me-notice" style="display:none;"></div>

		<div class="me-top">
			<div class="me-actions">
				<a href="/export_builder" class="button primary small">New export</a>
			</div>
			<div class="me-actions">
				<a href="javascript:void(0);" id="me-clear-finished" class="button small">Clear finished</a>
				<a href="javascript:void(0);" id="me-clear-expired" class="button small">Clear expired</a>
			</div>
		</div>

		<div id="me-list" class="me-list" aria-live="polite"><div class="me-empty">Loading…</div></div>
		<div class="me-footnote">Finished exports are kept for <?php echo (int)$meCfg['retention_days']; ?> days, then the file is removed. Expired exports can be re-run at any time.</div>
	</div>
</div>

<div class="me-dialog-back" id="me-dialog-back"></div>
<div class="me-dialog" id="me-dialog" role="dialog" aria-modal="true" aria-labelledby="me-dialog-title">
	<h3 id="me-dialog-title"></h3>
	<p id="me-dialog-text"></p>
	<div class="me-dialog-actions">
		<a href="javascript:void(0);" id="me-dialog-cancel" class="button small">Cancel</a>
		<a href="javascript:void(0);" id="me-dialog-ok" class="button primary small">Confirm</a>
	</div>
</div>

<script>
window.MY_EXPORTS = <?php echo json_encode(array(
	'api'      => '/exportjobs/api.php',
	'download' => '/exportjobs/download.php',
	'builder'  => '/export_builder',
	'notice'   => $meNotice,
), JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="<?php echo me_asset('/exportjobs/js/my_exports.js'); ?>"></script>

<?php
include("includes/mfooter.php");
