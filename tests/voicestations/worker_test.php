<?php
/**
 * File: worker_test.php
 * Description: Voice Stations build step 2, the worker API /voiceworker/v1/,
 *              end to end over HTTP:
 *                - worker tokens (none, wrong, a user's Basic login) -> 401
 *                - claim checks, 204 when idle
 *                - transcription jobs: audio for the lease holder only,
 *                  heartbeat, result checks, measured audio length stored
 *                - extraction gating: batch complete and nothing live still
 *                  untranscribed; discarded stations never block it; earlier
 *                  stations' transcripts as context
 *                - order: oldest batch first, then recording order
 *                - fallback delay (test-delay worker, 120 s) for both stages
 *                - expired leases: back to the queue, or failed after the
 *                  3rd attempt; swept at claim and at batch status read
 *                - fail with and without retry; user retry afterwards
 *                - 410 for discarded stations, 409 lease_lost, 409
 *                  stale_transcript; nothing stored in those cases
 *                - two parallel claims never get the same station
 *
 *              Needs on dev: maya.chen@test.strabospot.org (seed_demo.php),
 *              VOICESTATIONS_ALLOW listing her, and VOICESTATIONS_WORKERS
 *              with test-a, test-b (delay 0) and test-delay (delay 120)
 *              whose tokens are below. Removes ALL of Maya's Voice Stations
 *              rows and audio. Refuses to run when other users have
 *              stations a worker could claim (they would be claimed).
 *              STOP any real worker pointed at dev first (e.g.
 *              docker compose -f voicestations/worker/compose.cpu.yml stop):
 *              it would race the test for its stations.
 *
 *              Usage: docker exec strabo-php php /srv/app/www/tests/voicestations/worker_test.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
set_time_limit(0);
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/voicestations/lib/bootstrap.php';

$HOST = 'http://localhost';
$FIX = __DIR__ . '/fixture_tone.m4a';
$ROOT = VsConfig::dataRoot();
$TOK_A = 'vs-dev-test-worker-a';
$TOK_B = 'vs-dev-test-worker-b';
$TOK_D = 'vs-dev-test-worker-delay';
$MAYA = array('maya.chen@test.strabospot.org', 'demopass123');

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr(is_string($detail) ? $detail : json_encode($detail), 0, 1500) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function section($name) { echo "\n== $name\n"; }

function uuid() {
	$b = random_bytes(16);
	$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
	$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
	$h = bin2hex($b);
	return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function curlFor($method, $path, $opts) {
	global $HOST;
	$ch = curl_init($HOST . $path);
	curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true));
	$h = isset($opts['headers']) ? $opts['headers'] : array();
	if (isset($opts['token'])) $h[] = 'Authorization: Bearer ' . $opts['token'];
	if (isset($opts['basic'])) curl_setopt($ch, CURLOPT_USERPWD, $opts['basic'][0] . ':' . $opts['basic'][1]);
	if (isset($opts['multipart'])) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['multipart']);
	} elseif (array_key_exists('json', $opts)) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($opts['json']) ? $opts['json'] : json_encode($opts['json']));
		$h[] = 'Content-Type: application/json';
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
	return $ch;
}
function done($ch, $body) {
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('code' => $code, 'body' => $body, 'json' => json_decode($body, true));
}
function req($method, $path, $opts = array()) {
	$ch = curlFor($method, $path, $opts);
	return done($ch, curl_exec($ch));
}
/** Worker call. */
function w($tok, $method, $route, $json = null) {
	$o = array('token' => $tok);
	if ($json !== null) $o['json'] = $json;
	return req($method, '/voiceworker/v1/' . $route, $o);
}
function claimBody($kinds) {
	$b = array('kinds' => $kinds);
	if (in_array('transcribe', $kinds)) $b['transcribe'] = array('engine' => 'whisper.cpp', 'model' => 'large-v3-turbo');
	if (in_array('extract', $kinds)) $b['extract'] = array('engine' => 'anthropic', 'model' => 'claude-test', 'prompt_version' => 'v1');
	return $b;
}
function claim($tok, $kinds = array('transcribe', 'extract')) {
	return w($tok, 'POST', 'claim', claimBody($kinds));
}
function transcript($text = 'Strike 045. Dip 32.') {
	return array(
		'text' => $text,
		'segments' => array(array('start' => 0.0, 'end' => 2.5, 'text' => $text)),
		'words' => array(array('w' => 'Strike', 'start' => 0.1, 'end' => 0.5, 'p' => 0.98),
			array('w' => '045.', 'start' => 0.6, 'end' => 1.1, 'p' => null)),
	);
}
function tResult($runId, $over = array()) {
	return array_merge(array('run_id' => $runId, 'engine' => 'whisper.cpp', 'model' => 'large-v3-turbo',
		'settings' => array('vad' => true, 'vad_pad_ms' => 200), 'audio_seconds' => 2.97,
		'raw_output' => '{"text":"raw"}', 'output' => transcript()), $over);
}
function xResult($runId, $over = array()) {
	return array_merge(array('run_id' => $runId, 'raw_output' => 'proposal raw',
		'output' => array('measurements' => array(array('kind' => 'planar', 'strike' => 45, 'dip' => 32))),
		'validation' => array('kept' => 2, 'dropped' => array()), 'input_tokens' => 900, 'output_tokens' => 120), $over);
}
function upload($d) {
	global $FIX, $MAYA;
	return req('POST', '/db/voicestation', array('basic' => $MAYA, 'multipart' => array(
		'details' => json_encode($d), 'audio' => new CURLFile($FIX, 'audio/mp4', 'station.m4a'))));
}

$ms = new MsDb($db);
$upkMaya = (int)$ms->val("SELECT pkey FROM users WHERE email = $1", array($MAYA[0]));
$rec = $neodb->getRecord("MATCH (u:User {userpkey: $upkMaya})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset) RETURN p.id AS pid, d.id AS did LIMIT 1");
if (!$upkMaya || !$rec) {
	echo "Maya's demo project is missing: run docs/StraboSamples/summary_doc/seed_demo.php first.\n";
	exit(1);
}
$PID = (string)$rec->value('pid');
$DID = (string)$rec->value('did');
$others = (int)$ms->val(
	"SELECT count(*) FROM voicestations.stations
	  WHERE userpkey <> $1 AND discarded_at IS NULL AND confirmed_at IS NULL
	    AND stage IN ('uploaded', 'transcribing', 'transcribed', 'extracting')", array($upkMaya));
if ($others > 0) {
	echo "Other users have $others Voice Stations a worker could claim; this test would take them. Stopping.\n";
	exit(1);
}

$t0 = time() - 7200;
function iso($t) { return gmdate('Y-m-d\TH:i:s', $t) . '.000Z'; }
/** Upload station $s of batch $b ($list = every station of the batch), recorded at $t0 + $offset. */
function station($s, $b, $list, $offset) {
	global $PID, $DID, $t0;
	return upload(array(
		'station_uuid' => $s, 'batch_uuid' => $b, 'batch_station_uuids' => $list,
		'project_id' => (int)$PID, 'dataset_id' => $DID, 'target' => array('kind' => 'new'),
		'started_at' => iso($t0 + $offset), 'ended_at' => iso($t0 + $offset + 30), 'tz_offset_minutes' => -300,
		'gps_fixes' => array(array('lat' => 38.95, 'lon' => -95.25, 'alt' => 300.0, 'accuracy' => 6.0, 'time' => iso($t0 + $offset + 2))),
		'photos' => array(), 'strike_convention' => 'rhr', 'app_version' => '0.1.0 (1)', 'device_model' => 'iPhone15,2',
		'audio' => array('mime' => 'audio/mp4', 'seconds' => 3.5),
	));
}
function st($uuid) {
	global $ms;
	return $ms->row("SELECT id, stage, attempts, leased_by, lease_until, error_text, audio_seconds,
	                        current_transcript_run, current_proposal_run
	                   FROM voicestations.stations WHERE station_uuid = $1", array($uuid));
}
function runRow($id) {
	global $ms;
	return $ms->row("SELECT status, worker, engine, model, prompt_version, error_text, output::text AS output,
	                        validation::text AS validation, raw_output, transcript_run_id
	                   FROM voicestations.runs WHERE id = $1", array($id));
}
function expire($uuid) {
	global $ms;
	$ms->q("UPDATE voicestations.stations SET lease_until = now() - interval '1 second' WHERE station_uuid = $1", array($uuid));
}
function age($uuid, $secs) {
	global $ms;
	$ms->q("UPDATE voicestations.stations SET stage_since = now() - make_interval(secs => $2) WHERE station_uuid = $1", array($uuid, $secs));
}
function batchStatus($b) {
	global $MAYA;
	return req('GET', "/db/voicebatch/$b", array('basic' => $MAYA));
}
function discard($s) {
	global $MAYA;
	return req('POST', "/db/voiceconfirm/$s", array('basic' => $MAYA, 'json' => array('outcome' => 'discarded')));
}
function wipe() {
	global $ms, $upkMaya, $ROOT;
	foreach ($ms->rows("SELECT audio_path FROM voicestations.stations WHERE userpkey = $1 AND audio_path IS NOT NULL", array($upkMaya)) as $r) {
		@unlink("$ROOT/" . $r['audio_path']);
	}
	$ms->q("DELETE FROM voicestations.batches WHERE userpkey = $1", array($upkMaya));
}

wipe();
try {

// ---------------------------------------------------------------- auth
section('Worker tokens');
check('ping needs no token', req('GET', '/voiceworker/v1/ping')['json'] === array('ok' => true, 'apiVersion' => 1));
$x = req('POST', '/voiceworker/v1/claim', array('json' => claimBody(array('transcribe'))));
check('no token -> 401 unauthorized', $x['code'] === 401 && $x['json']['code'] === 'unauthorized', $x['body']);
$x = w('not-a-worker-token', 'POST', 'claim', claimBody(array('transcribe')));
check('wrong token -> 401', $x['code'] === 401, $x['body']);
$x = req('POST', '/voiceworker/v1/claim', array('basic' => $MAYA, 'json' => claimBody(array('transcribe'))));
check("a user's Basic login -> 401", $x['code'] === 401, $x['body']);
check('unit: token with a trailing space part is not a token', VsWorker::identify('Bearer a b', array('x' => hash('sha256', 'a'))) === null);
check('unit: undefined worker list = nobody', VsWorker::identify('Bearer ' . $TOK_A, null) === null);
check('unit: plain string entry = hash with delay 0', VsWorker::identify('Bearer abc', array('w1' => hash('sha256', 'abc'))) === array('w1', 0));
check('unknown route -> 404', w($TOK_A, 'GET', 'nope')['code'] === 404);
check('wrong method -> 405', w($TOK_A, 'GET', 'claim')['code'] === 405);
check('other API version -> 404', req('GET', '/voiceworker/v2/ping')['code'] === 404);

section('Claim checks');
foreach (array(
	array('no kinds', '{}', 'kinds'),
	array('unknown kind', array('kinds' => array('sing')), 'kinds'),
	array('transcribe without engine', array('kinds' => array('transcribe'), 'transcribe' => array('model' => 'm')), 'transcribe.engine'),
	array('extract without prompt_version', array('kinds' => array('extract'), 'extract' => array('engine' => 'e', 'model' => 'm')), 'extract.prompt_version'),
) as $c) {
	$x = w($TOK_A, 'POST', 'claim', $c[1]);
	check("claim: {$c[0]} -> 400 field {$c[2]}", $x['code'] === 400 && $x['json']['field'] === $c[2], $x['body']);
}
$x = claim($TOK_A);
check('empty queue -> 204, no body', $x['code'] === 204 && $x['body'] === '', $x['code'] . ' ' . $x['body']);

// ---------------------------------------------------------------- transcription
section('Transcription job');
$B1 = uuid(); $S1 = uuid(); $S2 = uuid(); $S3 = uuid();
$L1 = array($S1, $S2, $S3);
// uploaded out of recording order: S2 first, then S1 (S3 later)
station($S2, $B1, $L1, 200);
station($S1, $B1, $L1, 100);
$x = claim($TOK_A, array('extract'));
check('extract-only worker gets nothing while stations wait for transcription', $x['code'] === 204, $x['body']);
$x = claim($TOK_D);
check('fallback worker (delay 120) leaves a fresh station alone', $x['code'] === 204, $x['body']);
$x = claim($TOK_A);
$j = $x['json']['job'];
check('claim -> the earliest RECORDED station of the batch', $x['code'] === 200 && $j['kind'] === 'transcribe' && $j['station_uuid'] === $S1, $x['body']);
$s1 = st($S1);
$sha = hash_file('sha256', $FIX);
check('job: run id, attempt 1 of 3, 2 min lease, audio path + sha256 + size',
	$j['attempt'] === 1 && $j['max_attempts'] === 3 && $j['lease_seconds'] === 120 && $j['run_id'] > 0
	&& $j['audio']['path'] === "jobs/$S1/audio" && $j['audio']['sha256'] === $sha && $j['audio']['bytes'] === filesize($FIX)
	&& $j['audio']['seconds_reported'] === 3.5, $j);
check('station: transcribing, leased by test-a, attempts 1', $s1['stage'] === 'transcribing' && $s1['leased_by'] === 'test-a' && (int)$s1['attempts'] === 1);
$r = runRow($j['run_id']);
check('run row made at claim: running, worker, engine, model', $r['status'] === 'running' && $r['worker'] === 'test-a'
	&& $r['engine'] === 'whisper.cpp' && $r['model'] === 'large-v3-turbo', $r);
$RUN1 = $j['run_id'];

$a = req('GET', "/voiceworker/v1/jobs/$S1/audio", array('token' => $TOK_A));
check('audio for the lease holder: the exact original bytes', $a['code'] === 200 && hash('sha256', $a['body']) === $sha, $a['code']);
$a = req('GET', "/voiceworker/v1/jobs/$S1/audio", array('token' => $TOK_B));
check('audio for another worker -> 409 lease_lost', $a['code'] === 409 && $a['json']['code'] === 'lease_lost', $a['body']);
$a = req('GET', "/voiceworker/v1/jobs/$S2/audio", array('token' => $TOK_A));
check('audio of a station nobody claimed -> 409', $a['code'] === 409, $a['body']);
check('audio of an unknown station -> 404', req('GET', '/voiceworker/v1/jobs/' . uuid() . '/audio', array('token' => $TOK_A))['code'] === 404);

$x = claim($TOK_B);
check('a second worker gets the NEXT station, not the leased one', $x['code'] === 200 && $x['json']['job']['station_uuid'] === $S2, $x['body']);
$RUN2 = $x['json']['job']['run_id'];

$ms->q("UPDATE voicestations.stations SET lease_until = now() + interval '10 seconds' WHERE station_uuid = $1", array($S1));
$x = w($TOK_A, 'POST', "jobs/$S1/heartbeat", array('run_id' => $RUN1));
$s1 = st($S1);
check('heartbeat -> lease pushed out to ~2 min', $x['code'] === 200 && $x['json']['lease_seconds'] === 120
	&& strtotime($s1['lease_until']) > time() + 100, $x['body']);
check('heartbeat by the wrong worker -> 409 lease_lost', w($TOK_B, 'POST', "jobs/$S1/heartbeat", array('run_id' => $RUN1))['code'] === 409);
check('heartbeat naming another run -> 409', w($TOK_A, 'POST', "jobs/$S1/heartbeat", array('run_id' => $RUN2))['code'] === 409);
check('heartbeat without run_id -> 400', w($TOK_A, 'POST', "jobs/$S1/heartbeat", array())['code'] === 400);

section('Transcription result checks (400, the run stays live)');
foreach (array(
	array('raw_output missing', array('raw_output' => null), 'raw_output'),
	array('raw_output over 1 MB', array('raw_output' => str_repeat('x', 1048577)), 'raw_output'),
	array('audio_seconds missing', array('audio_seconds' => null), 'audio_seconds'),
	array('audio_seconds negative', array('audio_seconds' => -1), 'audio_seconds'),
	array('output not an object', array('output' => 'text'), 'output'),
	array('text missing', array('output' => array('segments' => array(), 'words' => array())), 'output.text'),
	array('segment without end', array('output' => array('text' => 'a', 'segments' => array(array('start' => 0, 'text' => 'a')), 'words' => array())), 'output.segments[0]'),
	array('word with text p', array('output' => array('text' => 'a', 'segments' => array(), 'words' => array(array('w' => 'a', 'start' => 0, 'end' => 1, 'p' => 'high')))), 'output.words[0]'),
	array('settings a list', array('settings' => array(1, 2)), 'settings'),
	array('engine too long', array('engine' => str_repeat('e', 101)), 'engine'),
) as $c) {
	$body = tResult($RUN1, $c[1]);
	foreach ($c[1] as $k => $v) if ($v === null) unset($body[$k]);
	$x = w($TOK_A, 'POST', "jobs/$S1/result", $body);
	check("result: {$c[0]} -> 400 field {$c[2]}", $x['code'] === 400 && $x['json']['field'] === $c[2], $x['code'] . ' ' . substr($x['body'], 0, 300));
}
check('after bad results the run is still running', runRow($RUN1)['status'] === 'running' && st($S1)['stage'] === 'transcribing');
$x = w($TOK_B, 'POST', "jobs/$S1/result", tResult($RUN1));
check('result from a worker that does not hold the lease -> 409 lease_lost', $x['code'] === 409 && $x['json']['code'] === 'lease_lost', $x['body']);

$x = w($TOK_A, 'POST', "jobs/$S1/result", tResult($RUN1, array('engine' => 'whisper.cpp-cuda')));
$s1 = st($S1);
$r = runRow($RUN1);
check('result -> 200 transcribed', $x['code'] === 200 && $x['json']['stage'] === 'transcribed', $x['body']);
check('station: transcript run current, measured length stored, lease cleared, attempts reset',
	(int)$s1['current_transcript_run'] === $RUN1 && (float)$s1['audio_seconds'] === 2.97
	&& $s1['leased_by'] === null && $s1['lease_until'] === null && (int)$s1['attempts'] === 0, $s1);
check('run: done, restated engine kept, raw + output stored', $r['status'] === 'done' && $r['engine'] === 'whisper.cpp-cuda'
	&& $r['raw_output'] === '{"text":"raw"}' && json_decode($r['output'], true) == transcript(), $r); // jsonb reorders keys
$x = w($TOK_A, 'POST', "jobs/$S1/result", tResult($RUN1));
check('the same result again -> 409 (the run is no longer live)', $x['code'] === 409, $x['body']);

// empty transcript is a success
$x = w($TOK_B, 'POST', "jobs/$S2/result", tResult($RUN2, array('output' => array('text' => '', 'segments' => array(), 'words' => array()))));
check('empty transcript (silence) is a success -> transcribed', $x['code'] === 200 && st($S2)['stage'] === 'transcribed', $x['body']);

// ---------------------------------------------------------------- extraction gating
section('Extraction gating');
$x = claim($TOK_A, array('extract'));
check('batch not complete (S3 not uploaded) -> no extraction', $x['code'] === 204, $x['body']);
station($S3, $B1, $L1, 300);
$x = claim($TOK_A, array('extract'));
check('batch complete but S3 not transcribed -> no extraction', $x['code'] === 204, $x['body']);
$x = discard($S3);
check('(the user discards S3 before it is processed)', $x['code'] === 201, $x['body']);
// make discarded S3 look recorded between S1 and S2 and transcribed, so the
// context check below proves discarded stations are left out
$ms->q("UPDATE voicestations.stations SET started_at = started_at - interval '150 seconds',
               ended_at = ended_at - interval '150 seconds', current_transcript_run = $2
         WHERE station_uuid = $1", array($S3, $RUN1));
check('a discarded station is never offered for transcription', claim($TOK_A, array('transcribe'))['code'] === 204);
$x = claim($TOK_D, array('extract'));
check('fallback worker: the batch changed just now -> waits', $x['code'] === 204, $x['body']);
$x = claim($TOK_A, array('extract'));
$j = $x['json']['job'];
check('discarded S3 does not block extraction: S1 offered first (recording order)',
	$x['code'] === 200 && $j['kind'] === 'extract' && $j['station_uuid'] === $S1, $x['body']);
check('extract job: transcript run + transcript, no earlier stations for the first one',
	$j['transcript_run_id'] === $RUN1 && $j['transcript'] == transcript() && $j['earlier_stations'] === array(), $j);
check('extract job: station context (new Spot, times, strike convention, best fix, measured length)',
	$j['station']['target_kind'] === 'new' && $j['station']['strike_convention'] === 'rhr'
	&& $j['station']['best_fix']['accuracy'] === 6.0 && $j['station']['audio_seconds'] === 2.97
	&& $j['station']['tz_offset_minutes'] === -300 && $j['station']['started_at'] === iso($t0 + 100), $j['station']);
check('extract job: the app form choices + a version tag (joint = option_13, quality names are strings)',
	preg_match('/^.+-[0-9a-f]{12}$/', $j['vocab']['version']) === 1
	&& $j['vocab']['forms']['measurement.planar_orientation']['feature_type']['option_13'] === 'joint'
	&& $j['vocab']['forms']['measurement.linear_orientation']['feature_type']['mineral_align'] === 'mineral alignment'
	&& array_key_exists('5', $j['vocab']['forms']['measurement.planar_orientation']['quality']), $j['vocab']['version']);
$XRUN1 = $j['run_id'];
$r = runRow($XRUN1);
check('extract run row: transcript it reads, engine, model, prompt version', (int)$r['transcript_run_id'] === $RUN1
	&& $r['engine'] === 'anthropic' && $r['model'] === 'claude-test' && $r['prompt_version'] === 'v1', $r);
$x = claim($TOK_B, array('extract'));
$j2 = $x['json']['job'];
check('S2 extract job carries S1 as earlier context, not discarded S3',
	$x['code'] === 200 && $j2['station_uuid'] === $S2 && count($j2['earlier_stations']) === 1
	&& $j2['earlier_stations'][0]['station_uuid'] === $S1 && $j2['earlier_stations'][0]['transcript'] === 'Strike 045. Dip 32.', $x['body']);
$XRUN2 = $j2['run_id'];

foreach (array(
	array('validation missing', array('validation' => null), 'validation'),
	array('validation a string', array('validation' => 'ok'), 'validation'),
	array('tokens fractional', array('input_tokens' => 1.5), 'input_tokens'),
) as $c) {
	$body = xResult($XRUN1, $c[1]);
	foreach ($c[1] as $k => $v) if ($v === null) unset($body[$k]);
	$x = w($TOK_A, 'POST', "jobs/$S1/result", $body);
	check("extract result: {$c[0]} -> 400 field {$c[2]}", $x['code'] === 400 && $x['json']['field'] === $c[2], $x['body']);
}
$x = w($TOK_A, 'POST', "jobs/$S1/result", xResult($XRUN1));
$s1 = st($S1);
$r = runRow($XRUN1);
check('extract result -> ready, proposal run current', $x['code'] === 200 && $s1['stage'] === 'ready'
	&& (int)$s1['current_proposal_run'] === $XRUN1, $x['body']);
check('extract run: done, output + validation stored', $r['status'] === 'done'
	&& json_decode($r['validation'], true) === array('kept' => 2, 'dropped' => array()), $r);
$x = batchStatus($B1);
check('batch not done while S2 is extracting', $x['code'] === 200 && $x['json']['done'] === false, $x['body']);

// stale transcript
$ms->q("UPDATE voicestations.stations SET current_transcript_run = $2 WHERE station_uuid = $1", array($S2, $RUN1));
$x = w($TOK_B, 'POST', "jobs/$S2/result", xResult($XRUN2));
$s2 = st($S2);
check('transcript changed underneath -> 409 stale_transcript, nothing stored, back to transcribed',
	$x['code'] === 409 && $x['json']['code'] === 'stale_transcript' && $s2['stage'] === 'transcribed'
	&& $s2['current_proposal_run'] === null && runRow($XRUN2)['status'] === 'failed' && runRow($XRUN2)['output'] === null, $x['body']);
$ms->q("UPDATE voicestations.stations SET current_transcript_run = $2 WHERE station_uuid = $1", array($S2, $RUN2));
$x = claim($TOK_B, array('extract'));
$XRUN2 = $x['json']['job']['run_id'];
$x = w($TOK_B, 'POST', "jobs/$S2/result", xResult($XRUN2));
check('S2 extracted on the next claim', $x['code'] === 200 && st($S2)['stage'] === 'ready', $x['body']);
$x = batchStatus($B1);
check('batch done: S1 + S2 ready, discarded S3 does not hold it open', $x['json']['done'] === true
	&& count($x['json']['stations']) === 3, $x['body']);

// ---------------------------------------------------------------- order across batches
section('Order across batches');
$BA = uuid(); $SA = uuid(); $BB = uuid(); $SB = uuid();
station($SB, $BB, array($SB), 50);
station($SA, $BA, array($SA), 10);
$ms->q("UPDATE voicestations.batches SET created_at = now() - interval '1 hour' WHERE batch_uuid = $1", array($BB));
$x = claim($TOK_A, array('transcribe'));
check('oldest batch first (even though its station was recorded later)', $x['json']['job']['station_uuid'] === $SB, $x['body']);
$RB = $x['json']['job']['run_id'];

// ---------------------------------------------------------------- lease expiry
section('Lease expiry, attempts, fallback');
$x = claim($TOK_D, array('transcribe'));
check('fallback worker still waits on fresh SA', $x['code'] === 204, $x['body']);
age($SA, 121);
$x = claim($TOK_D, array('transcribe'));
check('after 121 s unclaimed the fallback worker takes SA', $x['code'] === 200 && $x['json']['job']['station_uuid'] === $SA, $x['body']);
$RA = $x['json']['job']['run_id'];

expire($SB);
$x = claim($TOK_B, array('transcribe'));
$sb = st($SB);
check('expired lease: the next claim sweeps it and the station is claimable again (attempt 2)',
	$x['code'] === 200 && $x['json']['job']['station_uuid'] === $SB && $x['json']['job']['attempt'] === 2, $x['body']);
$r = runRow($RB);
check('the abandoned run is failed "lease expired"', $r['status'] === 'failed' && strpos($r['error_text'], 'lease expired') === 0, $r);
$x = w($TOK_A, 'POST', "jobs/$SB/result", tResult($RB));
check("the first worker's late result -> 409 lease_lost, nothing stored",
	$x['code'] === 409 && $x['json']['code'] === 'lease_lost' && st($SB)['current_transcript_run'] === null, $x['body']);

// late result accepted when no sweep has happened yet
expire($SA);
$x = w($TOK_D, 'POST', "jobs/$SA/result", tResult($RA));
check('a result just past the lease, before any sweep, is still accepted (the run is live)', $x['code'] === 200 && st($SA)['stage'] === 'transcribed', $x['body']);

// third expiry fails the station; swept by the batch status read
expire($SB);
$x = claim($TOK_A, array('transcribe'));
check('attempt 3 after a second expiry', $x['code'] === 200 && $x['json']['job']['station_uuid'] === $SB && $x['json']['job']['attempt'] === 3, $x['body']);
expire($SB);
$x = batchStatus($BB);
$sb = st($SB);
check('third expiry: the batch status read sweeps it -> failed with a readable error, batch done',
	$sb['stage'] === 'failed' && strpos($sb['error_text'], 'stopped responding 3 times') !== false
	&& $x['json']['done'] === true && $x['json']['stations'][0]['error'] === $sb['error_text'], $x['body']);
check('3 run rows for the 3 attempts, all failed', (int)$ms->val(
	"SELECT count(*) FROM voicestations.runs WHERE station_id = $1 AND kind = 'transcribe' AND status = 'failed'", array($sb['id'])) === 3);

// user retry puts it back
$x = req('POST', "/db/voiceretry/$SB", array('basic' => $MAYA));
check('user retry -> uploaded again with fresh attempts', $x['code'] === 200 && st($SB)['stage'] === 'uploaded' && (int)st($SB)['attempts'] === 0, $x['body']);

// ---------------------------------------------------------------- fail
section('Fail');
$x = claim($TOK_A, array('transcribe'));
$RF = $x['json']['job']['run_id'];
check('(SB claimed again)', $x['json']['job']['station_uuid'] === $SB, $x['body']);
foreach (array(
	array('no error text', array('run_id' => $RF, 'retry' => true), 'error'),
	array('retry not a boolean', array('run_id' => $RF, 'error' => 'x', 'retry' => 'yes'), 'retry'),
) as $c) {
	$x = w($TOK_A, 'POST', "jobs/$SB/fail", $c[1]);
	check("fail: {$c[0]} -> 400 field {$c[2]}", $x['code'] === 400 && $x['json']['field'] === $c[2], $x['body']);
}
$x = w($TOK_A, 'POST', "jobs/$SB/fail", array('run_id' => $RF, 'error' => 'whisper-server timed out', 'retry' => true));
$sb = st($SB);
check('fail retry:true with attempts left -> back to uploaded, run failed with the error',
	$x['code'] === 200 && $x['json']['stage'] === 'uploaded' && $sb['stage'] === 'uploaded' && $sb['leased_by'] === null
	&& runRow($RF)['status'] === 'failed' && runRow($RF)['error_text'] === 'whisper-server timed out', $x['body']);
$x = claim($TOK_A, array('transcribe'));
$RF = $x['json']['job']['run_id'];
$x = w($TOK_A, 'POST', "jobs/$SB/fail", array('run_id' => $RF, 'error' => 'The audio could not be read.', 'retry' => false));
$sb = st($SB);
check('fail retry:false -> failed now, the error kept for the app', $x['json']['stage'] === 'failed'
	&& $sb['stage'] === 'failed' && $sb['error_text'] === 'The audio could not be read.', $x['body']);
check('fail on a run that is over -> 409', w($TOK_A, 'POST', "jobs/$SB/fail", array('run_id' => $RF, 'error' => 'x', 'retry' => true))['code'] === 409);

// retry:true at the attempt limit fails
$BC = uuid(); $SC = uuid();
station($SC, $BC, array($SC), 5);
$x = claim($TOK_A, array('transcribe'));
$ms->q("UPDATE voicestations.stations SET attempts = 3 WHERE station_uuid = $1", array($SC));
$x = w($TOK_A, 'POST', "jobs/$SC/fail", array('run_id' => $x['json']['job']['run_id'], 'error' => 'timed out again', 'retry' => true));
check('fail retry:true on the 3rd attempt -> failed', $x['json']['stage'] === 'failed' && st($SC)['stage'] === 'failed', $x['body']);

// ---------------------------------------------------------------- discarded while running
section('Discarded while a worker runs it');
$BD = uuid(); $SD = uuid();
station($SD, $BD, array($SD), 7);
$x = claim($TOK_A, array('transcribe'));
$RD = $x['json']['job']['run_id'];
check('(SD claimed)', $x['json']['job']['station_uuid'] === $SD, $x['body']);
discard($SD);
$a = req('GET', "/voiceworker/v1/jobs/$SD/audio", array('token' => $TOK_A));
check('audio of a discarded station -> 410 gone', $a['code'] === 410 && $a['json']['code'] === 'gone', $a['body']);
$x = w($TOK_A, 'POST', "jobs/$SD/heartbeat", array('run_id' => $RD));
check('heartbeat -> 410 gone (the worker can stop early)', $x['code'] === 410, $x['body']);
$x = w($TOK_A, 'POST', "jobs/$SD/result", tResult($RD));
$sd = st($SD);
check('result after a discard -> 410, nothing stored, the run closed', $x['code'] === 410
	&& $sd['current_transcript_run'] === null && $sd['leased_by'] === null && runRow($RD)['status'] === 'failed'
	&& runRow($RD)['output'] === null, $x['body']);
check('a discarded station is never claimed again', claim($TOK_A, array('transcribe'))['code'] === 204);

// ---------------------------------------------------------------- context cap
section('Earlier-station context: the 20 most recent, oldest first');
$BK = uuid(); $SK = array();
for ($i = 0; $i < 23; $i++) $SK[] = uuid();
foreach ($SK as $i => $u) station($u, $BK, $SK, 1000 + $i * 60);
for ($i = 0; $i < 23; $i++) {
	$x = claim($TOK_A, array('transcribe'));
	$k = array_search($x['json']['job']['station_uuid'], $SK, true);
	w($TOK_A, 'POST', 'jobs/' . $SK[$k] . '/result', tResult($x['json']['job']['run_id'],
		array('output' => array('text' => "station $k", 'segments' => array(), 'words' => array()))));
}
$last = null;
for ($i = 0; $i < 30 && $last === null; $i++) {   // earlier sections may leave a station or two ahead
	$x = claim($TOK_A, array('extract'));
	if ($x['code'] !== 200) break;
	if ($x['json']['job']['station_uuid'] === $SK[22]) $last = $x['json']['job'];
	w($TOK_A, 'POST', 'jobs/' . $x['json']['job']['station_uuid'] . '/result', xResult($x['json']['job']['run_id']));
}
$texts = $last ? array_map(function ($e) { return $e['transcript']; }, $last['earlier_stations']) : array();
check('23rd station sees stations 2..21 (20 of 22 earlier), in recording order',
	count($texts) === 20 && $texts[0] === 'station 2' && $texts[19] === 'station 21', $texts);

// ---------------------------------------------------------------- parallel claims
section('Parallel claims');
$BP = uuid(); $SP1 = uuid(); $SP2 = uuid();
station($SP1, $BP, array($SP1, $SP2), 1);
station($SP2, $BP, array($SP1, $SP2), 2);
$mh = curl_multi_init();
$hs = array();
foreach (array($TOK_A, $TOK_B, $TOK_A, $TOK_B) as $t) {
	$h = curlFor('POST', '/voiceworker/v1/claim', array('token' => $t, 'json' => claimBody(array('transcribe'))));
	curl_multi_add_handle($mh, $h);
	$hs[] = $h;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);
$got = array();
$codes = array();
foreach ($hs as $h) {
	$res = done($h, curl_multi_getcontent($h));
	$codes[] = $res['code'];
	if ($res['code'] === 200) $got[] = $res['json']['job']['station_uuid'];
}
curl_multi_close($mh);
sort($codes);
check('4 parallel claims, 2 stations: two 200s with different stations, two 204s',
	$codes === array(200, 200, 204, 204) && count(array_unique($got)) === 2, array($codes, $got));

} finally {
	wipe();
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
