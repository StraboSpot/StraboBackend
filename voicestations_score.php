<?php
/**
 * File: voicestations_score.php
 * Description: Strabo Voice trial scoring page (step 6 scoring page points
 *              1-6). Read-only: it SHOWS what the Python scorer stored in
 *              voicestations.scores (no scoring math here) next to the raw
 *              recording data: transcript (words seek the audio), proposal,
 *              confirm record and answer key.
 *              Only accounts in VOICESTATIONS_SCORERS (config.inc.php, fails
 *              closed); everyone else, signed in or not, gets a plain 404.
 *              ?run=<id> picks a scorer run (default the latest),
 *              &spot=<recording uuid> opens one recording, ?audio=<uuid>
 *              streams its audio (Range, for seeking).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once(__DIR__ . "/includes/session_config.php");
session_start();
$signedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === 'yes'
	&& !(isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT);
if ($signedIn) {
	$_SESSION['LAST_ACTIVITY'] = time();
}
include("prepare_connections.php");
require_once __DIR__ . "/voicestations/lib/bootstrap.php";

$ms = new MsDb($db);
if (!$signedIn || !VsConfig::allowedBy($ms, $userpkey, defined('VOICESTATIONS_SCORERS') ? VOICESTATIONS_SCORERS : null)) {
	http_response_code(404);
	echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1>"
		. "<p>The requested URL was not found on this server.</p></body></html>";
	exit;
}

function h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function isUuid($s) {
	return is_string($s) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $s) === 1;
}
function show($v) {
	if ($v === null || $v === '') return '<span class="vs-dim">none</span>';
	if (is_bool($v)) return $v ? 'yes' : 'no';
	if (is_array($v)) return h(json_encode($v));
	return h($v);
}
function pct($x) {
	return $x === null ? 'n/a' : round($x * 100) . '%';
}
function sec($s) {
	return ($s === null || $s === array()) ? 'n/a' : h($s['median']) . ' s <span class="vs-dim">(' . h($s['min']) . ' to ' . h($s['max']) . ', n ' . h($s['n']) . ')</span>';
}
function verdict($pass) {
	if ($pass === null) return '<span class="vs-tag vs-na">not scored yet</span>';
	return $pass ? '<span class="vs-tag vs-pass">pass</span>' : '<span class="vs-tag vs-fail">fail</span>';
}
/** One table row of bar results (By tester, By device). */
function barsRow($label, $t) {
	return '<tr><td>' . h($label) . '</td>'
		. '<td>' . (int)$t['bar1']['wrong'] . ' ' . verdict($t['bar1']['pass']) . '</td>'
		. '<td>' . (int)$t['bar1']['missing'] . '</td>'
		. '<td>' . pct($t['bar2']['share']) . ' ' . verdict($t['bar2']['pass']) . '</td>'
		. '<td>' . (int)$t['bar2']['zero_change_spots'] . ' of ' . (int)$t['bar2']['spots'] . '</td>'
		. '<td>' . sec($t['bar3']['voice']['per_measurement']) . '</td>'
		. '<td>' . sec($t['bar3']['voice']['recording']) . '</td>'
		. '<td>' . sec($t['bar3']['voice']['review']) . '</td>'
		. '<td>' . sec($t['bar3']['baseline']['per_measurement']) . ' ' . verdict($t['bar3']['pass']) . '</td>'
		. '<td>' . (int)$t['words']['right'] . ' of ' . (int)$t['words']['of'] . '</td></tr>' . "\n";
}

// ---------------------------------------------------------------- audio
if (isset($_GET['audio'])) {
	$u = strtolower((string)$_GET['audio']);
	$s = isUuid($u) ? $ms->row("SELECT audio_path, audio_mime FROM voicestations.stations
	                             WHERE station_uuid = $1 AND audio_deleted_at IS NULL", array($u)) : null;
	$path = $s && $s['audio_path'] ? VsConfig::dataRoot() . '/' . $s['audio_path'] : null;
	if (!$path || !is_file($path)) {
		http_response_code(404);
		exit;
	}
	VsService::sendAudio($path, $s['audio_mime']);
}

// ---------------------------------------------------------------- data
$runs = $ms->rows("SELECT id, scorer_version, key_sha256, " . MsDb::iso('created_at') . " AS at
                     FROM voicestations.scores ORDER BY id DESC LIMIT 200");
$runId = isset($_GET['run']) ? (int)$_GET['run'] : ($runs ? (int)$runs[0]['id'] : 0);
$res = null;
if ($runId) {
	$raw = $ms->val("SELECT results FROM voicestations.scores WHERE id = $1", array($runId));
	$res = $raw === null ? null : json_decode($raw, true);
}
$spot = isset($_GET['spot']) && isUuid(strtolower((string)$_GET['spot'])) ? strtolower((string)$_GET['spot']) : null;
$testers = array();
foreach ($ms->rows("SELECT DISTINCT s.userpkey, u.email, u.firstname, u.lastname FROM voicestations.stations s
                     LEFT JOIN public.users u ON u.pkey = s.userpkey") as $t) {
	$testers[(int)$t['userpkey']] = trim($t['firstname'] . ' ' . $t['lastname']) ?: $t['email'];
}
function who($upk) {
	global $testers;
	return isset($testers[(int)$upk]) ? $testers[(int)$upk] : "user $upk";
}
function spotLink($uuid, $name) {
	global $runId;
	return '<a href="?run=' . (int)$runId . '&amp;spot=' . h($uuid) . '">' . h($name ?: substr($uuid, 0, 8)) . '</a>';
}

include("includes/mheader.php");
?>
<style>
	.vs-score { color: rgba(255, 255, 255, 0.85); }
	.vs-score h3 { color: #ffffff; margin: 1.8em 0 0.5em; font-size: 1.2em; }
	.vs-score table { width: 100%; border-collapse: collapse; margin-bottom: 1em; font-size: 0.92em; }
	.vs-score th, .vs-score td { text-align: left; padding: 0.4em 0.6em; border-bottom: 1px solid rgba(255, 255, 255, 0.12); vertical-align: top; }
	.vs-score th { color: #ffffff; font-weight: 600; background: #2a2a3a; }
	.vs-score .vs-dim { color: rgba(255, 255, 255, 0.55); }
	.vs-score .vs-tag { display: inline-block; padding: 0 0.5em; border-radius: 3px; font-size: 0.85em; font-weight: 600; }
	.vs-score .vs-pass { background: #1e5631; color: #d6f5df; }
	.vs-score .vs-fail { background: #7a1f2b; color: #ffd9de; }
	.vs-score .vs-na { background: rgba(255, 255, 255, 0.12); color: rgba(255, 255, 255, 0.75); }
	.vs-score .vs-cards { display: flex; flex-wrap: wrap; gap: 1em; margin: 1em 0; }
	.vs-score .vs-card { flex: 1 1 15em; background: #2a2a3a; border-radius: 6px; padding: 0.9em 1.1em; }
	.vs-score .vs-card .vs-big { font-size: 1.7em; color: #ffffff; line-height: 1.3; }
	.vs-score .vs-card .vs-label { color: rgba(255, 255, 255, 0.65); font-size: 0.9em; }
	.vs-score form.vs-pick { display: flex; gap: 0.6em; align-items: center; flex-wrap: wrap; }
	.vs-score form.vs-pick select { background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.2); color: #ffffff; padding: 0.3em 0.5em; max-width: 100%; }
	.vs-score form.vs-pick select option { background: #2a2a3a; color: #ffffff; }
	.vs-score .vs-scroll { overflow-x: auto; }
	.vs-score .vs-words { line-height: 2; background: rgba(255, 255, 255, 0.05); padding: 0.8em 1em; border-radius: 6px; }
	.vs-score .vs-word { cursor: pointer; border-radius: 3px; padding: 0 1px; }
	.vs-score .vs-word:hover { background: #e44c65; color: #ffffff; }
	.vs-score .vs-word.vs-now { background: rgba(228, 76, 101, 0.45); }
	.vs-score audio { width: 100%; margin: 0.5em 0 1em; }
	.vs-score .vs-wrong { color: #ff8a9a; font-weight: 600; }
	.vs-score .vs-ok { color: #8fe0a8; }
	.vs-score code { background: rgba(255, 255, 255, 0.08); color: #ffffff; padding: 0 0.3em; }
	.vs-score a { color: #e44c65; }
	.vs-score a:hover { color: #f06880; }
</style>

<div id="main" class="wrapper style1">
	<div class="container">
		<header class="major">
			<h2>Strabo Voice scoring</h2>
		</header>
		<div class="vs-score">
<?php if (!$runs) { ?>
			<p>No scorer runs yet. Run the scorer with <code>python -m vsworker.score score ... --post</code> (see voicestations/worker/RUNBOOK.md).</p>
<?php } else { ?>
			<form class="vs-pick" method="get">
				<label for="vs-run">Scorer run</label>
				<select id="vs-run" name="run" onchange="this.form.submit()">
<?php foreach ($runs as $r) { ?>
					<option value="<?php echo (int)$r['id']; ?>"<?php echo (int)$r['id'] === $runId ? ' selected' : ''; ?>>Run <?php echo (int)$r['id']; ?>, <?php echo h(substr($r['at'], 0, 16)); ?> UTC, key <?php echo h(substr($r['key_sha256'], 0, 10)); ?>, <?php echo h($r['scorer_version']); ?></option>
<?php } ?>
				</select>
				<noscript><button type="submit" class="button small">Show</button></noscript>
<?php if ($spot) { ?>
				<a class="button small" href="?run=<?php echo (int)$runId; ?>">Back to the overview</a>
<?php } ?>
			</form>
<?php } ?>

<?php if ($res && !$spot) {
	$o = $res['overall'];
?>
			<p class="vs-dim">Export of <?php echo h($res['export_generated_at']); ?>. <?php echo count($res['stations']); ?> confirmed recordings, <?php echo (int)$res['discarded']; ?> discarded; <?php echo (int)$o['bar1']['recordings_scored']; ?> in the answer key.</p>
			<div class="vs-cards">
				<div class="vs-card"><div class="vs-label">Bar 1: wrong numbers saved (target 0)</div>
					<div class="vs-big"><?php echo (int)$o['bar1']['wrong']; ?> <?php echo verdict($o['bar1']['pass']); ?></div>
					<div class="vs-label"><?php echo (int)$o['bar1']['as_spoken']; ?> as spoken; from the proposal <?php echo (int)$o['bar1']['by_origin']['proposal']; ?>, edited <?php echo (int)$o['bar1']['by_origin']['edited']; ?>, typed <?php echo (int)$o['bar1']['by_origin']['typed']; ?>. Missing <?php echo (int)$o['bar1']['missing']; ?>, extra <?php echo (int)$o['bar1']['extra']; ?>. <?php echo (int)$o['bar1']['values_checked']; ?> values checked.</div></div>
				<div class="vs-card"><div class="vs-label">Bar 2: values accepted unchanged (target 80%)</div>
					<div class="vs-big"><?php echo pct($o['bar2']['share']); ?> <?php echo verdict($o['bar2']['pass']); ?></div>
					<div class="vs-label"><?php echo (int)$o['bar2']['unchanged']; ?> of <?php echo (int)$o['bar2']['proposed'] + (int)$o['bar2']['added']; ?> (<?php echo (int)$o['bar2']['added']; ?> typed by hand). Spots with no change: <?php echo (int)$o['bar2']['zero_change_spots']; ?> of <?php echo (int)$o['bar2']['spots']; ?>.</div></div>
				<div class="vs-card"><div class="vs-label">Bar 3: time per measurement, voice vs StraboField</div>
					<div class="vs-big"><?php echo isset($o['bar3']['voice']['per_measurement']['median']) ? h($o['bar3']['voice']['per_measurement']['median']) . ' s' : 'n/a'; ?> vs <?php echo isset($o['bar3']['baseline']['per_measurement']['median']) ? h($o['bar3']['baseline']['per_measurement']['median']) . ' s' : 'n/a'; ?> <?php echo verdict($o['bar3']['pass']); ?></div>
					<div class="vs-label">Medians. Every tester faster or equal: <?php echo $o['bar3']['pass_every_tester'] === null ? 'n/a' : ($o['bar3']['pass_every_tester'] ? 'yes' : 'no'); ?>. Small numbers: no significance test.</div></div>
			</div>

			<h3>By tester</h3>
			<div class="vs-scroll"><table>
				<tr><th>Tester</th><th>Wrong saved</th><th>Missing</th><th>Unchanged</th><th>No-change Spots</th><th>Voice per measurement</th><th>Recording</th><th>Review</th><th>StraboField per measurement</th><th>Words right</th></tr>
<?php foreach ($res['testers'] as $upk => $t) echo barsRow($t['name'] ?: $t['email'], $t); ?>
			</table></div>

<?php if (isset($o['by_device'])) { ?>
			<h3>By device</h3>
<?php if ((int)$o['by_device']['watch']['bar2']['spots'] === 0) { ?>
			<p class="vs-dim">No watch recordings yet: every confirmed recording was made on the phone.</p>
<?php } else { ?>
			<div class="vs-scroll"><table>
				<tr><th>Recorded on</th><th>Wrong saved</th><th>Missing</th><th>Unchanged</th><th>No-change Spots</th><th>Voice per measurement</th><th>Recording</th><th>Review</th><th>StraboField per measurement</th><th>Words right</th></tr>
<?php
	foreach (array('phone' => 'Phone', 'watch' => 'Watch') as $d => $label) echo barsRow("All testers, $label", $o['by_device'][$d]);
	foreach ($res['testers'] as $upk => $t) {
		if ((int)$t['by_device']['watch']['bar2']['spots'] === 0) continue;   // only testers who used a watch
		foreach (array('phone' => 'Phone', 'watch' => 'Watch') as $d => $label) echo barsRow(($t['name'] ?: $t['email']) . ", $label", $t['by_device'][$d]);
	}
?>
			</table></div>
			<p class="vs-dim">The StraboField stopwatch time is the same for both devices.</p>
<?php } } ?>
			<p class="vs-dim">Unchanged by kind (all testers):
<?php foreach ($o['bar2']['by_kind'] as $k => $v) { echo h($k) . ' ' . (int)$v['unchanged'] . ' of ' . (int)$v['of'] . '. '; } ?></p>

<?php
	$L = $res['lists'];
	$tables = array(
		array('Wrong numbers saved', $L['wrong'], array('Spot', 'Tester', '#', 'Field', 'Saved', 'Notebook', 'From', 'As spoken', 'Key corrected')),
		array('Missing (in the notebook, never saved)', $L['missing'], array('Spot', 'Tester', '#', 'Field', 'Notebook', 'Key corrected')),
		array('Extra (saved, not in the notebook; not scored as wrong)', $L['extra'], array('Spot', 'Tester', 'Kind', 'Values')),
		array('Convention mismatches (strike does not fit right-hand rule with its dip direction)', $L['convention'], array('Spot', 'Tester', 'Strike', 'Dip direction')),
		array('Hand-off check (confirmed values vs the Spot on the server; never counted as wrong)', $L['handoff'], array('Spot', 'Tester', 'Spot id', 'Problem', 'Only confirmed', 'Only on the server')),
	);
	foreach ($tables as $tb) {
?>
			<h3><?php echo h($tb[0]); ?> (<?php echo count($tb[1]); ?>)</h3>
<?php if (!$tb[1]) { ?>
			<p class="vs-dim">None.</p>
<?php continue; } ?>
			<div class="vs-scroll"><table>
				<tr><?php foreach ($tb[2] as $c) echo '<th>' . h($c) . '</th>'; ?></tr>
<?php foreach ($tb[1] as $x) {
		$cells = array(spotLink($x['station'], $x['spot_name']), h(who($x['tester'])));
		if ($tb[1] === $L['wrong']) {
			array_push($cells, h($x['key_n']), h($x['field']), '<span class="vs-wrong">' . show($x['saved']) . '</span>', show($x['key']),
				h($x['origin']), $x['as_spoken'] ? 'yes' : 'no', $x['corrected'] ? 'yes' : 'no');
		} elseif ($tb[1] === $L['missing']) {
			array_push($cells, h($x['key_n']), h($x['field']), show($x['key']), $x['corrected'] ? 'yes' : 'no');
		} elseif ($tb[1] === $L['extra']) {
			array_push($cells, h($x['kind']), show($x['values']));
		} elseif ($tb[1] === $L['convention']) {
			array_push($cells, h($x['strike']), h($x['dip_direction']));
		} else {
			array_push($cells, h($x['spot_id']), h($x['problem']),
				isset($x['confirmed_only']) ? show($x['confirmed_only']) : '', isset($x['spot_only']) ? show($x['spot_only']) : '');
		}
		echo '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>';
	} ?>
			</table></div>
<?php } ?>

			<h3>Answer key corrections (<?php echo count($res['corrected']); ?>)</h3>
<?php if (!$res['corrected']) { ?>
			<p class="vs-dim">None.</p>
<?php } else { ?>
			<div class="vs-scroll"><table>
				<tr><th>Date</th><th>Tester</th><th>Spot</th><th>#</th><th>Field</th><th>Old</th><th>New</th><th>Reason</th><th>Decided by</th></tr>
<?php foreach ($res['corrected'] as $c) { ?>
				<tr><td><?php echo h($c['date']); ?></td><td><?php echo h($c['tester']); ?></td><td><?php echo h($c['spot']); ?></td><td><?php echo h($c['n']); ?></td><td><?php echo h($c['field']); ?></td><td><?php echo h($c['old']); ?></td><td><?php echo h($c['new']); ?></td><td><?php echo h($c['reason']); ?></td><td><?php echo h($c['decided_by']); ?></td></tr>
<?php } ?>
			</table></div>
<?php } ?>

			<h3>All confirmed recordings (<?php echo count($res['stations']); ?>)</h3>
			<div class="vs-scroll"><table>
				<tr><th>Spot</th><th>Tester</th><th>Recorded</th><th>On</th><th>In the key</th><th>Unchanged</th><th>Edited</th><th>Removed</th><th>Typed</th><th>Measurements</th><th>Recording</th><th>Review</th></tr>
<?php foreach ($res['stations'] as $u => $st) { ?>
				<tr><td><?php echo spotLink($u, $st['spot_name']); ?></td><td><?php echo h(who($st['tester'])); ?></td>
					<td><?php echo h(substr((string)$st['started_at'], 0, 16)); ?></td><td><?php echo h(isset($st['recorded_on']) ? $st['recorded_on'] : 'phone'); ?></td><td><?php echo $st['in_key'] ? 'yes' : '<span class="vs-dim">no</span>'; ?></td>
					<td><?php echo (int)$st['counts']['unchanged']; ?></td><td><?php echo (int)$st['counts']['edited']; ?></td><td><?php echo (int)$st['counts']['removed']; ?></td><td><?php echo (int)$st['counts']['added']; ?></td>
					<td><?php echo (int)$st['timing']['n_measurements']; ?></td><td><?php echo show($st['timing']['recording'] === null ? null : round($st['timing']['recording'], 1)); ?> s</td><td><?php echo show($st['timing']['review']); ?> s</td></tr>
<?php } ?>
			</table></div>
<?php } ?>

<?php if ($spot) {
	$st = $ms->row("SELECT s.station_uuid, s.userpkey, s.details->>'spot_name' AS spot_name, " . MsDb::iso('s.started_at') . " AS started_at,
	                       s.audio_path IS NOT NULL AND s.audio_deleted_at IS NULL AS has_audio, s.audio_seconds,
	                       t.output AS transcript, c.record, c.outcome, c.review_seconds, p.output AS proposal
	                  FROM voicestations.stations s
	                  LEFT JOIN voicestations.confirms c ON c.station_id = s.id
	                  LEFT JOIN voicestations.runs t ON t.id = s.current_transcript_run
	                  LEFT JOIN voicestations.runs p ON p.id = c.proposal_run_id
	                 WHERE s.station_uuid = $1", array($spot));
	if (!$st) {
		echo '<p>That recording is not on the server.</p>';
	} else {
		$tr = json_decode((string)$st['transcript'], true);
		$record = json_decode((string)$st['record'], true);
		$scored = $res && isset($res['stations'][$spot]) ? $res['stations'][$spot] : null;
?>
			<h3>Spot <?php echo h($st['spot_name']); ?>, <?php echo h(who($st['userpkey'])); ?>, recorded <?php echo h(substr($st['started_at'], 0, 16)); ?> UTC</h3>
			<p class="vs-dim"><?php echo h($st['outcome'] ?: 'not settled'); ?>; audio <?php echo h(round((float)$st['audio_seconds'], 1)); ?> s; review <?php echo show($st['review_seconds']); ?> s.</p>
<?php if (MsDb::bool($st['has_audio'])) { ?>
			<audio id="vs-audio" controls preload="metadata" src="?audio=<?php echo h($spot); ?>"></audio>
<?php } else { ?>
			<p class="vs-dim">No audio on the server.</p>
<?php } ?>
			<h3>Transcript (click a word to hear it)</h3>
			<div class="vs-words">
<?php
		if ($tr && !empty($tr['words'])) {
			foreach ($tr['words'] as $i => $w) {
				echo '<span class="vs-word" data-start="' . h(isset($w['start']) ? $w['start'] : 0) . '" data-end="' . h(isset($w['end']) ? $w['end'] : 0) . '">' . h($w['w']) . '</span> ';
			}
		} else {
			echo h($tr ? $tr['text'] : 'No transcript.');
		}
?>
			</div>

<?php if ($scored && $scored['rows']) { ?>
			<h3>Against the answer key</h3>
			<div class="vs-scroll"><table>
				<tr><th>#</th><th>Kind</th><th>Field</th><th>Saved</th><th>Notebook</th><th>Result</th><th>From</th></tr>
<?php foreach ($scored['rows'] as $row) {
		if (!$row['fields']) {
			echo '<tr><td>' . h($row['key_n']) . '</td><td>' . h($row['kind']) . '</td><td colspan="5" class="vs-wrong">not saved</td></tr>';
			continue;
		}
		foreach ($row['fields'] as $f => $c) {
			$cls = $c['status'] === 'ok' ? 'vs-ok' : 'vs-wrong';
			$label = $c['status'] . (!empty($c['as_spoken']) ? ' (as spoken)' : '') . (!empty($c['corrected']) ? ' (key corrected)' : '');
			echo '<tr><td>' . h($row['key_n']) . '</td><td>' . h($row['kind']) . '</td><td>' . h($f) . '</td><td>' . show($c['saved'])
				. '</td><td>' . show($c['key']) . '</td><td class="' . $cls . '">' . h($label) . '</td><td>' . show(isset($c['origin']) ? $c['origin'] : null) . '</td></tr>';
		}
	} ?>
			</table></div>
<?php } elseif ($scored) { ?>
			<p class="vs-dim">This recording is not in the answer key yet.</p>
<?php } ?>

<?php if ($record && !empty($record['values'])) { ?>
			<h3>Confirm record: every value</h3>
			<div class="vs-scroll"><table>
				<tr><th>Item</th><th>Field</th><th>Proposed</th><th>Saved</th><th>Action</th><th>Origin</th></tr>
<?php foreach ($record['values'] as $v) { ?>
				<tr><td><?php echo h($v['ref']); ?></td><td><?php echo h($v['field']); ?></td><td><?php echo show(isset($v['proposed']) ? $v['proposed'] : null); ?></td><td><?php echo show(isset($v['final']) ? $v['final'] : null); ?></td><td><?php echo h($v['action']); ?></td><td><?php echo show(isset($v['origin']) ? $v['origin'] : null); ?></td></tr>
<?php } ?>
			</table></div>
<?php if (!empty($record['flags'])) { ?>
			<h3>Flags</h3>
			<div class="vs-scroll"><table>
				<tr><th>Item</th><th>Flag</th><th>Field</th><th>Action</th></tr>
<?php foreach ($record['flags'] as $f) { ?>
				<tr><td><?php echo h($f['ref']); ?></td><td><?php echo h($f['code']); ?></td><td><?php echo show(isset($f['field']) ? $f['field'] : null); ?></td><td><?php echo show($f['action']); ?></td></tr>
<?php } ?>
			</table></div>
<?php } ?>
<?php } ?>
<?php }
} ?>
		</div>
	</div>
</div>

<script>
(function () {
	var audio = document.getElementById('vs-audio');
	if (!audio) return;
	var words = Array.prototype.slice.call(document.querySelectorAll('.vs-word'));
	words.forEach(function (w) {
		w.addEventListener('click', function () {
			audio.currentTime = Math.max(0, parseFloat(w.getAttribute('data-start')) - 0.25);
			audio.play();
		});
	});
	audio.addEventListener('timeupdate', function () {
		var t = audio.currentTime;
		words.forEach(function (w) {
			var on = t >= parseFloat(w.getAttribute('data-start')) && t < parseFloat(w.getAttribute('data-end'));
			w.classList.toggle('vs-now', on);
		});
	});
})();
</script>
<?php
include("includes/mfooter.php");
?>
