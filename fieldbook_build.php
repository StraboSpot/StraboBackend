<?php
/**
 * File: fieldbook_build.php
 * Description: Interstitial page for the StraboField "Field Book" download
 *              (docs/Fieldbook_Design.md §14 M6, 2026-09-04). The My Field
 *              Data dropdown opens this page instead of streaming the PDF: it
 *              fires fieldbook_run.php in the background, shows the build's
 *              real progress from fieldbook_status.php (stage, day, spot,
 *              photo, elapsed) once a second, and sends the browser to
 *              fieldbook_fetch.php when the book is written. A book finished
 *              in the last ten minutes for the same datasets and options is
 *              served straight away; a build already running for them is
 *              attached to rather than started twice. Without JavaScript the
 *              page offers the direct download (searchdownload?type=fieldbook).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
include("logincheck.php");
include("prepare_connections.php");
session_write_close();
require_once("includes/fieldbook/FieldbookBuild.php");

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$params = FieldbookBuild::params($_GET);
if (!$params['userpkey']) $params['userpkey'] = (int)$userpkey;
$dsList = $params['dsids'] !== '' ? array_map('intval', explode(',', $params['dsids'])) : array();
$key = ''; $mode = 'nodata'; $title = ''; $subtitle = ''; $datasets = array();
if ($dsList) {
	$tree = Fieldbook::treeFromNeo4j($strabo, $params['userpkey'], $dsList);
	$meta = Fieldbook::meta($strabo, $params['userpkey'], $tree, $_GET);
	$title = $meta['title']; $subtitle = $meta['subtitle'];
	foreach ($tree as $t) foreach ($t['dsids'] as $d) $datasets[] = $t['dataset_names'][$d];
	$key = FieldbookBuild::key($userpkey, $params);
	$state = FieldbookBuild::load($key);
	if ($state && (int)$state['userpkey'] !== (int)$userpkey) $state = null;
	$mode = FieldbookBuild::modeFor($state, $key);
	if ($mode === 'fetch') { header('Location: /fieldbook_fetch?k=' . $key); exit(); }
	if ($mode === 'run') FieldbookBuild::save($key, FieldbookBuild::newState($userpkey, $params, $title));
}
$directUrl = '/searchdownload?type=fieldbook&' . http_build_query($params);
$opts = Fieldbook::options($_GET);

include("includes/mheader.php");
?>

<style>
.fb-card { margin: 0 0 1.5em 0; padding: 1em 1.25em; border-radius: 6px; background: rgba(255,255,255,0.06); border-left: 4px solid #6fbf73; line-height: 1.55; }
.fb-card.warn { border-left-color: #f0ad4e; }
.fb-card.fail { border-left-color: #e44c65; }
.fb-card p:last-child { margin-bottom: 0; }
.fb-facts { margin: 0 0 0.8em 0; padding: 0; list-style: none; }
.fb-facts li { padding: 0.15em 0; }
.fb-facts strong { display: inline-block; min-width: 7em; color: rgba(255,255,255,0.75); }
.fb-muted { color: rgba(255,255,255,0.65); font-size: 0.92em; }
.fb-spinner { display: inline-block; width: 1em; height: 1em; margin-right: 0.5em; vertical-align: -0.15em; border: 2px solid rgba(255,255,255,0.25); border-top-color: #fff; border-radius: 50%; animation: fb-spin 0.8s linear infinite; }
@keyframes fb-spin { to { transform: rotate(360deg); } }
.fb-bar { position: relative; height: 14px; margin: 0.6em 0 0.4em 0; border-radius: 7px; background: rgba(255,255,255,0.12); overflow: hidden; }
.fb-bar-fill { position: absolute; left: 0; top: 0; bottom: 0; width: 0; background: #6fbf73; transition: width 0.4s ease; }
.fb-bar-fill.pulse { width: 100%; background: linear-gradient(90deg, rgba(111,191,115,0.25), rgba(111,191,115,0.8), rgba(111,191,115,0.25)); background-size: 200% 100%; animation: fb-pulse 1.4s linear infinite; }
@keyframes fb-pulse { from { background-position: 200% 0; } to { background-position: -200% 0; } }
.fb-note { min-height: 1.6em; font-size: 1.05em; }
.fb-elapsed { float: right; color: rgba(255,255,255,0.55); font-size: 0.9em; }
.fb-steps { margin: 0.8em 0 0.8em 1.5em; padding: 0; }
.fb-steps li { padding: 0.15em 0; color: rgba(255,255,255,0.45); }
.fb-steps li.active { color: #fff; }
.fb-steps li.done { color: #6fbf73; }
.fb-steps li.done::after { content: " \2713"; }
.fb-steps li.failed { color: #e44c65; }
.fb-steps li.failed::after { content: " \2717"; }
</style>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Field Book</h2>
						</header>

<?php if ($mode === 'nodata') { ?>
						<div class="fb-card warn" id="fb-nodata">
							<p>No dataset was given for this Field Book.</p>
						</div>
						<ul class="actions"><li><a href="/my_field_data" class="button primary">Go to My StraboField Data</a></li></ul>
<?php } else { ?>
						<div class="fb-card" id="fb-book">
							<ul class="fb-facts">
								<li><strong>Book</strong> <?php echo $h($title); ?><?php if ($subtitle !== '') echo ' <span class="fb-muted">(' . $h($subtitle) . ')</span>'; ?></li>
<?php if (count($datasets) > 1) { ?>
								<li><strong>Datasets</strong> <?php echo $h(implode(', ', $datasets)); ?></li>
<?php } ?>
								<li><strong>Options</strong> <?php echo $h('basemap ' . $opts['map'] . ', photos ' . $opts['photos'] . ', stereonets ' . $opts['nets'] . ', ' . $opts['page'] . ' pages'); ?></li>
							</ul>
						</div>

						<div class="fb-card" id="fb-progress">
							<p><span class="fb-spinner" id="fb-spinner"></span><strong id="fb-headline"><?php echo $mode === 'attach' ? 'This Field Book is already being built. Joining that build&hellip;' : 'Building your Field Book&hellip;'; ?></strong><span class="fb-elapsed" id="fb-elapsed"></span></p>
							<div class="fb-bar"><div class="fb-bar-fill pulse" id="fb-bar"></div></div>
							<div class="fb-note" id="fb-note">Starting&hellip;</div>
							<ol class="fb-steps" id="fb-steps">
<?php foreach (FieldbookBuild::$stages as $sk => $label) { ?>
								<li data-stage="<?php echo $h($sk); ?>"><?php echo $h($label); ?></li>
<?php } ?>
							</ol>
							<p class="fb-muted">The first book of a project with many photos can take a few minutes while thumbnails are made; later books are quicker. Keep this page open: it opens the PDF by itself when the book is ready.</p>
						</div>

						<div class="fb-card fail" id="fb-failed" style="display:none">
							<p><strong>The Field Book could not be built.</strong></p>
							<p id="fb-error"></p>
							<ul class="actions">
								<li><a href="javascript:location.reload()" class="button primary" id="fb-retry">Try again</a></li>
								<li><a href="<?php echo $h($directUrl); ?>" class="button" id="fb-direct">Download directly</a></li>
								<li><a href="/my_field_data" class="button">Back to My StraboField Data</a></li>
							</ul>
						</div>

						<noscript>
						<div class="fb-card warn" id="fb-noscript">
							<p>JavaScript is off, so this page cannot show progress. <a href="<?php echo $h($directUrl); ?>">Download the Field Book directly</a> (the page stays blank until the whole book has been built and sent).</p>
						</div>
						</noscript>

						<script>
						// Fire the build (unless one is already running), poll its state once a second,
						// then replace this page with the finished PDF.
						var fbKey = <?php echo json_encode($key); ?>;
						var fbMode = <?php echo json_encode($mode); ?>;
						var fbStatusUrl = '/fieldbook_status?k=' + encodeURIComponent(fbKey);
						var fbRunUrl = '/fieldbook_run?k=' + encodeURIComponent(fbKey);
						var fbStages = <?php echo json_encode(array_keys(FieldbookBuild::$stages)); ?>;
						var fbFinished = false, fbTimer = null, fbLastElapsed = 0, fbTick = Date.now();
						function fbFmt(sec) { sec = Math.max(0, Math.round(sec)); var m = Math.floor(sec / 60), s = sec % 60; return (m ? m + 'm ' : '') + s + 's'; }
						function fbRender(d) {
							var idx = d.stage_index || 0, items = document.querySelectorAll('#fb-steps li');
							for (var i = 0; i < items.length; i++) {
								var cls = '';
								if (d.state === 'done' || i < idx) cls = 'done';
								else if (i === idx) cls = d.state === 'failed' ? 'failed' : 'active';
								items[i].className = cls;
							}
							var bar = document.getElementById('fb-bar');
							if (d.state === 'done') { bar.className = 'fb-bar-fill'; bar.style.width = '100%'; }
							else if (d.total > 0 && d.stage === 'build') { bar.className = 'fb-bar-fill'; bar.style.width = Math.max(2, Math.round(100 * d.done / d.total)) + '%'; }
							else { bar.className = 'fb-bar-fill pulse'; bar.style.width = ''; }
							document.getElementById('fb-note').textContent = d.note || '';
							fbLastElapsed = d.elapsed || 0; fbTick = Date.now();
						}
						function fbElapsed() {
							if (fbFinished) return;
							document.getElementById('fb-elapsed').textContent = fbFmt(fbLastElapsed + (Date.now() - fbTick) / 1000);
						}
						function fbFail(msg) {
							fbFinished = true;
							document.getElementById('fb-progress').style.display = 'none';
							document.getElementById('fb-error').textContent = msg || 'Unknown error.';
							document.getElementById('fb-failed').style.display = '';
						}
						function fbDone(d) {
							fbFinished = true;
							document.getElementById('fb-spinner').style.display = 'none';
							document.getElementById('fb-headline').textContent = 'Your Field Book is ready. Opening it…';
							document.getElementById('fb-note').innerHTML = (d.bytes ? (d.bytes / 1048576).toFixed(1) + ' MB. ' : '') + 'If it does not open, <a href="' + d.fetch_url + '">open the PDF</a>.';
							location.replace(d.fetch_url);
						}
						function fbPoll(delay) {
							setTimeout(function () {
								if (fbFinished) return;
								var xhr = new XMLHttpRequest();
								xhr.open('GET', fbStatusUrl + '&_=' + Date.now(), true);
								xhr.onload = function () {
									var d = null;
									try { d = JSON.parse(xhr.responseText); } catch (e) {}
									if (d && d.found) {
										fbRender(d);
										if (d.state === 'done' && d.fetch_url) { fbDone(d); return; }
										if (d.state === 'failed') { fbFail(d.error); return; }
									}
									fbPoll(1000);
								};
								xhr.onerror = function () { fbPoll(2000); };
								xhr.send();
							}, delay);
						}
						function fbStart() {
							if (fbMode === 'run') {
								var xhr = new XMLHttpRequest();
								xhr.open('GET', fbRunUrl, true);
								xhr.onload = function () {
									var d = null;
									try { d = JSON.parse(xhr.responseText); } catch (e) {}
									if (d && d.found && d.state === 'done' && d.fetch_url) { fbRender(d); fbDone(d); }
									else if (d && d.found && d.state === 'failed') { fbFail(d.error); }
									else if (!d || !d.found) { fbFail('The build request was refused (' + xhr.status + ').'); }
								};
								xhr.onerror = function () { if (!fbFinished) fbFail('The build request failed. Check your connection and try again.'); };
								xhr.send();
							}
							fbPoll(700);
							fbTimer = setInterval(fbElapsed, 500);
						}
						fbStart();
						</script>
<?php } ?>

						<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
?>
