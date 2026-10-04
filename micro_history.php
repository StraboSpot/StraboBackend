<?php
/**
 * File: micro_history.php
 * Description: History of a synced StraboMicro project (collaboration v3
 *              §12b 17ae): who changed what and when, newest first, grouped
 *              like the app's Activity panel, with each change's fields
 *              (old -> new), a person filter and a date to look back from;
 *              and the project as it was at a date, or just before a
 *              change, downloaded as a separate copy (.smz with a new id,
 *              MsSmz as-of). Read only. The owner and every active member
 *              (Viewer included); the owner opens it from My StraboMicro
 *              Data (Options > History), members from the app's Activity
 *              panel (Full history on StraboSpot...).
 *                GET ?project_id=<pid>[&user=<pkey>][&until=<unix>][&before=<seq>]
 *                GET ?project_id=<pid>&download=1&seq=<S>|at=<unix>[&tz=<IANA zone>]
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once(__DIR__ . '/microdb/lib/micro_history_web.php');
require_once(__DIR__ . '/microsync/lib/MsSmz.php');

/** A non-negative whole number from the query, else $default. */
function micro_history_int($name, $default) {
	return isset($_GET[$name]) && preg_match('/^\d{1,15}$/', (string)$_GET[$name]) ? (int)$_GET[$name] : $default;
}

/** This page's URL with these query values (null drops one). */
function micro_history_url($pid, $params) {
	$q = array('project_id' => $pid);
	foreach ($params as $k => $v) {
		if ($v !== null && $v !== 0 && $v !== '') {
			$q[$k] = $v;
		}
	}
	return '/micro_history?' . http_build_query($q);
}

$msdb = micro_members_db($db);
$me = (int)$userpkey;
$pid = micro_history_int('project_id', 0);
$problem = null;
$p = null;
try {
	$p = MsStore::project($msdb, $pid, $me);
	if ($p['sync_format'] !== 'entity' || $p['sync_state'] !== 'ready') {
		$problem = 'The history of this project is available once its first upload to StraboSpot has finished.';
	}
} catch (MsHttpError $e) {
	if ($e->errorCode === 'access_removed') {
		$problem = 'You no longer have access to this project.';
	} elseif ($e->errorCode === 'project_deleted') {
		$problem = 'This project was deleted from StraboSpot.';
	} else {
		$problem = 'This project is not available.';
	}
}

// Download as of a date, or just before a change
if ($problem === null && isset($_GET['download'])) {
	$seq = micro_history_int('seq', null);
	$at = micro_history_int('at', null);
	$tz = isset($_GET['tz']) ? substr((string)$_GET['tz'], 0, 64) : '';
	try {
		$seq = MsHistory::resolveAsOf($msdb, $pid, $seq, $at);
		$when = MsHistory::timeOf($msdb, $pid, $seq);
		$asOf = array('seq' => $seq, 'id' => MsHttp::uuid4(), 'name' => MsHistory::asOfName($p['name'], $when, $tz));
		$file = strtolower(str_replace(' ', '_', trim(preg_replace('/[^A-Za-z0-9\-_ ]/', '', (string)$p['name']))));
		$file = ($file === '' ? 'project' : $file) . '_as_of_' . MsHistory::asOfStamp($when, $tz) . '.smz';
		if (MsSmz::send($db, $pid, $file, $asOf)) {
			exit();
		}
		$_SESSION['micro_history_msg'] = 'The project could not be put together for that date.';
	} catch (MsHttpError $e) {
		$_SESSION['micro_history_msg'] = $e->errorCode === 'before_history'
			? 'That is before this project started syncing with StraboSpot, so its history does not go back that far. '
				. 'Earlier versions are in StraboMicro (File > View Version History...) on the computer where it was made.'
			: $e->getMessage();
	}
	header('Location: ' . micro_history_url($pid, array()));
	exit();
}

$message = null;
if (isset($_SESSION['micro_history_msg'])) {
	$message = $_SESSION['micro_history_msg'];
	unset($_SESSION['micro_history_msg']);
}

if ($problem === null) {
	$filterUser = micro_history_int('user', 0);
	$until = micro_history_int('until', null);
	$before = micro_history_int('before', 0);
	$page = MsHistory::page($msdb, $pid, array('before' => $before, 'limit' => 200, 'me' => $me,
		'user' => $filterUser, 'until' => $until, 'detail' => true));
	$names = micro_history_names($msdb, $pid, $page['changes']);
	$groups = micro_history_groups($page['changes'], $me, $names);
	$range = MsHistory::range($msdb, $pid);
	$people = micro_history_people($msdb, $pid);
	$lastSeq = count($page['changes']) ? $page['changes'][count($page['changes']) - 1]['seq'] : 0;
	$filtered = $filterUser > 0 || $until !== null;
}

include 'includes/mheader.php';
?>
<style>
	.mh-intro { color: rgba(255,255,255,0.7); }
	.mh-message { background: rgba(228,76,101,0.15); border: 1px solid #e44c65; border-radius: 4px; padding: 0.8em 1em; margin-bottom: 1.5em; }
	.mh-tools { display: flex; flex-wrap: wrap; gap: 1.5em; margin-bottom: 2em; }
	.mh-tool { flex: 1 1 22em; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.12); border-radius: 4px; padding: 1em 1.2em; }
	.mh-tool h4 { margin: 0 0 0.6em 0; }
	.mh-tool label { font-size: 0.9em; margin: 0.4em 0 0.2em 0; }
	.mh-tool select { color-scheme: dark; background-color: rgba(255,255,255,0.08); }
	.mh-tool select option { background: #2a2a3a; color: #fff; }
	.mh-tool input[type="datetime-local"] { color-scheme: dark; width: 100%; background-color: rgba(255,255,255,0.08);
		border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: inherit; padding: 0.5em 0.7em; }
	.mh-tool .actions { margin-top: 1em; margin-bottom: 0; }
	.mh-tool .mh-help { font-size: 0.85em; color: rgba(255,255,255,0.6); margin: 0.6em 0 0 0; }
	.mh-showing { color: rgba(255,255,255,0.7); margin-bottom: 1em; }
	.mh-list { list-style: none; padding: 0; margin: 0; }
	.mh-list > li { border-bottom: 1px solid rgba(255,255,255,0.1); padding: 0; margin: 0; }
	.mh-list details > summary { cursor: pointer; padding: 0.7em 0.4em; display: flex; gap: 1em; align-items: baseline; list-style: none; }
	.mh-list details > summary::-webkit-details-marker { display: none; }
	.mh-list details > summary::before { content: '\25B8'; color: rgba(255,255,255,0.5); flex: 0 0 auto; }
	.mh-list details[open] > summary::before { content: '\25BE'; }
	.mh-list details > summary:hover { background: rgba(255,255,255,0.04); }
	.mh-line { flex: 1 1 auto; }
	.mh-when { flex: 0 0 auto; color: rgba(255,255,255,0.6); font-size: 0.9em; white-space: nowrap; }
	.mh-body { padding: 0.2em 1em 1em 2em; }
	.mh-item { margin: 0.6em 0 0.2em 0; font-weight: bold; }
	.mh-fields { margin: 0 0 0 1em; padding: 0; list-style: none; font-size: 0.95em; }
	.mh-fields li { margin: 0.15em 0; padding: 0; }
	.mh-old { color: rgba(255,255,255,0.6); text-decoration: line-through; }
	.mh-empty { color: rgba(255,255,255,0.5); font-style: italic; }
	.mh-body .actions { margin-top: 1em; margin-bottom: 0; }
	.mh-more { margin-top: 1.5em; }
	@media screen and (max-width: 736px) {
		.mh-list details > summary { flex-wrap: wrap; }
		.mh-when { flex-basis: 100%; padding-left: 1.4em; }
	}
</style>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Project History</h2>
		</header>

		<section id="content">
<?php if ($problem !== null) { ?>
			<p><?php echo htmlspecialchars($problem)?></p>
			<ul class="actions"><li><a href="/my_micro_data" class="button">My StraboMicro Data</a></li></ul>
<?php } else { ?>
			<h3><?php echo htmlspecialchars($p['name'])?></h3>
<?php if ($message !== null) { ?>
			<div class="mh-message"><?php echo htmlspecialchars($message)?></div>
<?php } ?>
			<p class="mh-intro">Every change synced to StraboSpot, newest first. Click a line to see what changed.
<?php if ($range !== null) { ?>
				The history starts <time class="mh-time" data-style="date" datetime="<?php echo htmlspecialchars($range['firstAt'])?>"><?php echo htmlspecialchars(substr($range['firstAt'], 0, 10))?></time>,
				when this project started syncing; earlier versions are in StraboMicro (File &gt; View Version History...).
<?php } ?>
			</p>

			<div class="mh-tools">
				<form class="mh-tool" method="get" action="/micro_history" id="mh-filter">
					<h4>Show</h4>
					<input type="hidden" name="project_id" value="<?php echo (int)$pid?>">
					<input type="hidden" name="until" value="">
					<label for="mh-user">Person</label>
					<select id="mh-user" name="user">
						<option value="">Everyone</option>
<?php foreach ($people as $u) { ?>
						<option value="<?php echo (int)$u['pkey']?>"<?php echo $u['pkey'] === $filterUser ? ' selected' : ''?>><?php echo htmlspecialchars($u['pkey'] === $me ? $u['name'] . ' (you)' : ($u['name'] !== '' ? $u['name'] : 'Unnamed user'))?></option>
<?php } ?>
					</select>
					<label for="mh-until">Changes up to</label>
					<input type="datetime-local" id="mh-until" data-unix="<?php echo $until === null ? '' : (int)$until?>">
					<ul class="actions">
						<li><input type="submit" class="button primary small" value="Show"></li>
<?php if ($filtered || $before > 0) { ?>
						<li><a href="<?php echo htmlspecialchars(micro_history_url($pid, array()))?>" class="button small">Show Newest</a></li>
<?php } ?>
					</ul>
				</form>

				<form class="mh-tool" method="get" action="/micro_history" id="mh-asof">
					<h4>Download the project as it was</h4>
					<input type="hidden" name="project_id" value="<?php echo (int)$pid?>">
					<input type="hidden" name="download" value="1">
					<input type="hidden" name="at" value="">
					<input type="hidden" name="tz" value="">
					<label for="mh-at">Date and time</label>
					<input type="datetime-local" id="mh-at" required
						data-min="<?php echo $range === null ? '' : htmlspecialchars($range['firstAt'])?>">
					<ul class="actions">
						<li><input type="submit" class="button small" value="Download as of This Date"
							title="Downloads a .smz file of the project as it was then. Opening it in StraboMicro makes a separate project; the synced project is not changed."></li>
					</ul>
					<p class="mh-help">A .smz file with the project as it was then. In StraboMicro it opens as its own separate project.</p>
				</form>
			</div>

<?php if ($filtered || $before > 0) { ?>
			<p class="mh-showing">
<?php
	$bits = array();
	if ($filterUser > 0) {
		$who = 'someone';
		foreach ($people as $u) {
			if ($u['pkey'] === $filterUser) {
				$who = $u['pkey'] === $me ? 'you' : $u['name'];
			}
		}
		$bits[] = 'changes by ' . htmlspecialchars($who);
	}
	if ($until !== null) {
		$bits[] = 'up to <time class="mh-time" datetime="' . gmdate('Y-m-d\TH:i:s\Z', $until) . '">' . gmdate('Y-m-d H:i', $until) . ' UTC</time>';
	}
	if ($before > 0) {
		$bits[] = 'older changes';
	}
	echo 'Showing ' . implode(', ', $bits) . '.';
?>
			</p>
<?php } ?>

<?php if (empty($groups)) { ?>
			<p class="mh-empty">No changes to show.</p>
<?php } else { ?>
			<ul class="mh-list">
<?php foreach ($groups as $g) { ?>
				<li>
					<details>
						<summary>
							<span class="mh-line"><?php echo htmlspecialchars(micro_history_line($g))?></span>
							<time class="mh-when mh-time" datetime="<?php echo htmlspecialchars($g['at'])?>"><?php echo htmlspecialchars(substr(str_replace('T', ' ', $g['at']), 0, 16))?> UTC</time>
						</summary>
						<div class="mh-body">
<?php foreach ($g['rows'] as $r) { ?>
							<div class="mh-item"><?php echo htmlspecialchars(micro_history_item($r, $names))?></div>
							<ul class="mh-fields">
<?php foreach (micro_history_details($r, $names) as $d) { ?>
								<li><?php
		if ($d['label'] !== null) {
			echo htmlspecialchars($d['label']) . ': ';
		}
		if (array_key_exists('text', $d)) {
			echo htmlspecialchars($d['text']);
		} else {
			echo $d['before'] === null ? '<span class="mh-empty">empty</span>' : '<span class="mh-old">' . htmlspecialchars($d['before']) . '</span>';
			echo ' &rarr; ';
			echo $d['after'] === null ? '<span class="mh-empty">empty</span>' : htmlspecialchars($d['after']);
		}
?></li>
<?php } ?>
							</ul>
<?php } ?>
<?php if ($range !== null && $g['oldestSeq'] - 1 >= $range['firstSeq']) { ?>
							<form method="get" action="/micro_history">
								<input type="hidden" name="project_id" value="<?php echo (int)$pid?>">
								<input type="hidden" name="download" value="1">
								<input type="hidden" name="seq" value="<?php echo (int)$g['oldestSeq'] - 1?>">
								<input type="hidden" name="tz" value="">
								<ul class="actions">
									<li><input type="submit" class="button small" value="Download as It Was Before This"
										title="Downloads a .smz file of the project as it was just before this change. Opening it in StraboMicro makes a separate project."></li>
								</ul>
							</form>
<?php } ?>
						</div>
					</details>
				</li>
<?php } ?>
			</ul>
<?php } ?>
<?php if ($page['more']) { ?>
			<ul class="actions mh-more">
				<li><a href="<?php echo htmlspecialchars(micro_history_url($pid, array('user' => $filterUser, 'until' => $until, 'before' => $lastSeq)))?>" class="button small">Show Older</a></li>
			</ul>
<?php } ?>
<?php } ?>
		</section>

	<div class="bottomSpacer"></div>

	</div>
</div>

<script>
(function () {
	// Times in the viewer's zone (the page prints UTC for browsers without JS)
	document.querySelectorAll('time.mh-time').forEach(function (t) {
		var d = new Date(t.getAttribute('datetime'));
		if (isNaN(d.getTime())) return;
		t.textContent = t.getAttribute('data-style') === 'date'
			? d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
			: d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
	});
	var tz = '';
	try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
	document.querySelectorAll('input[name="tz"]').forEach(function (i) { i.value = tz; });

	// datetime-local works in local time; the server gets unix seconds (the whole minute counts)
	function localValue(d) {
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}
	function unixOf(input) {
		var d = new Date(input.value);
		return isNaN(d.getTime()) ? '' : String(Math.floor(d.getTime() / 1000) + 59);
	}
	var until = document.getElementById('mh-until');
	if (until && until.getAttribute('data-unix')) {
		until.value = localValue(new Date(parseInt(until.getAttribute('data-unix'), 10) * 1000));
	}
	var at = document.getElementById('mh-at');
	if (at) {
		var min = at.getAttribute('data-min');
		if (min) at.min = localValue(new Date(min));
		at.max = localValue(new Date());
	}
	var filter = document.getElementById('mh-filter');
	if (filter) filter.addEventListener('submit', function () {
		filter.elements['until'].value = unixOf(until);
		// No empty values in the address
		['until', 'user'].forEach(function (n) { if (!filter.elements[n].value) filter.elements[n].disabled = true; });
	});
	var asof = document.getElementById('mh-asof');
	if (asof) asof.addEventListener('submit', function () {
		asof.elements['at'].value = unixOf(at);
	});
	// Re-enable filter fields when coming back with the browser's Back button
	window.addEventListener('pageshow', function () {
		if (filter) ['until', 'user'].forEach(function (n) { filter.elements[n].disabled = false; });
	});
})();
</script>

<?php
include 'includes/mfooter.php';
