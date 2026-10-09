<?php
/**
 * File: api_test.php
 * Description: Voice Stations build step 1, the user-side /db/ endpoints, end
 *              to end over HTTP (Apache Basic Auth included):
 *                - tester gate fails closed (unit) and 403s an unlisted account
 *                - POST /db/voicestation: every details check, audio checks,
 *                  ownership, new vs resend, foreign UUIDs, batch list and
 *                  completeness, best GPS fix, stored audio
 *                - GET /db/voicebatch: progress, done only when complete and
 *                  every station ready or failed, results in recording order
 *                - GET /db/voiceaudio: owner only, Content-Length, Range
 *                - POST /db/voiceconfirm: server-computed counts, resend,
 *                  hand entry on a failed station, discard rules
 *                - POST /db/voiceretry: back to the stage that failed
 *                - GET/POST /db/voiceconsent (step 6): text, agreement,
 *                  uploads refused (403 consent_required) without it
 *                - a confirm stamps the project's "Last Uploaded"
 *                  (Project.uploaddate); a resend or a discard does not
 *                - data and code folders never served
 *              The worker is simulated with direct SQL (runs + stages).
 *
 *              Needs on dev: the demo account maya.chen@test.strabospot.org
 *              (password demopass123, docs/StraboSamples/summary_doc/
 *              seed_demo.php) and config.inc.php VOICESTATIONS_ALLOW listing
 *              maya.chen@ and voicestations-test-b@test.strabospot.org.
 *              Creates test-b and an unlisted user; removes them and every
 *              row and file it made. Also removes ALL of Maya's Voice
 *              Stations rows, consents and audio, so do not keep demo data
 *              under her. Restores her project's uploaddate.
 *
 *              Usage: docker exec strabo-php php /srv/app/www/tests/voicestations/api_test.php
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
$W = sys_get_temp_dir() . '/vs_api_test_' . substr(md5(uniqid('', true)), 0, 6);
@mkdir($W, 0777, true);

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

/** HTTP call; $auth = array(email, password). Returns code, headers, body, json. */
function req($method, $path, $auth, $opts = array()) {
	global $HOST;
	$ch = curl_init($HOST . $path);
	$headers = array();
	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_USERPWD => $auth[0] . ':' . $auth[1],
		CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
			$p = strpos($line, ':');
			if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
			return strlen($line);
		},
	));
	$h = isset($opts['headers']) ? $opts['headers'] : array();
	if (isset($opts['multipart'])) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['multipart']);
	} elseif (isset($opts['json'])) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($opts['json']) ? $opts['json'] : json_encode($opts['json']));
		$h[] = 'Content-Type: application/json';
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('code' => $code, 'headers' => $headers, 'body' => $body, 'json' => json_decode($body, true));
}

/** Upload one station: $d = details array (or raw string), $audio = path or null. */
function upload($auth, $d, $audio = 'fixture') {
	global $FIX;
	$fields = array('details' => is_string($d) ? $d : json_encode($d));
	if ($audio === 'fixture') $audio = $FIX;
	if ($audio !== null) $fields['audio'] = new CURLFile($audio, 'audio/mp4', 'station.m4a');
	return req('POST', '/db/voicestation', $auth, array('multipart' => $fields));
}

$ms = new MsDb($db);

// ---------------------------------------------------------------- accounts
$PW = 'vs-test-' . substr(md5(uniqid('', true)), 0, 8);
$EMAIL_B = 'voicestations-test-b@test.strabospot.org';
$EMAIL_C = 'voicestations-test-c-' . substr(md5(uniqid('', true)), 0, 6) . '@test.strabospot.org';
$MAYA = array('maya.chen@test.strabospot.org', 'demopass123');
$B = array($EMAIL_B, $PW);
$C = array($EMAIL_C, $PW);
$ms->q("DELETE FROM users WHERE email = $1", array($EMAIL_B));
foreach (array($EMAIL_B, $EMAIL_C) as $e) {
	$ms->q("INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Voice', 'Test', $1, crypt($2, gen_salt('bf')), 'x', true)", array($e, $PW));
}
$upkMaya = (int)$ms->val("SELECT pkey FROM users WHERE email = $1", array($MAYA[0]));
$upkB = (int)$ms->val("SELECT pkey FROM users WHERE email = $1", array($EMAIL_B));
$upkC = (int)$ms->val("SELECT pkey FROM users WHERE email = $1", array($EMAIL_C));

$rec = $neodb->getRecord("MATCH (u:User {userpkey: $upkMaya})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset) RETURN p.id AS pid, d.id AS did LIMIT 1");
if (!$upkMaya || !$rec) {
	echo "Maya's demo project is missing: run docs/StraboSamples/summary_doc/seed_demo.php first.\n";
	exit(1);
}
$PID = (string)$rec->value('pid');
$DID = (string)$rec->value('did');
function projectUploaddate($set = null) {
	global $neodb, $upkMaya, $PID;
	$m = "MATCH (u:User {userpkey: $upkMaya})-[:HAS_PROJECT]->(p:Project) WHERE p.id = $PID OR p.id = '$PID' ";
	if ($set !== null) {
		$neodb->query($m . "SET p.uploaddate = $set");
	}
	return $neodb->get_var($m . 'RETURN p.uploaddate AS d');
}
$UPLOADDATE0 = projectUploaddate();
$ms->q("DELETE FROM voicestations.consents WHERE userpkey IN ($1, $2, $3)", array($upkMaya, $upkB, $upkC));

$t0 = time() - 3600;
function iso($t, $ms = 0) { return gmdate('Y-m-d\TH:i:s', $t) . sprintf('.%03dZ', $ms); }
function details($station, $batch, $list, $over = array()) {
	global $PID, $DID, $t0;
	$d = array(
		'station_uuid' => $station,
		'batch_uuid' => $batch,
		'batch_station_uuids' => $list,
		'project_id' => (int)$PID,
		'dataset_id' => $DID,
		'target' => array('kind' => 'new'),
		'started_at' => iso($t0, 120),
		'ended_at' => iso($t0 + 63, 480),
		'tz_offset_minutes' => -300,
		'gps_fixes' => array(
			array('lat' => 38.95, 'lon' => -95.25, 'alt' => 300.0, 'accuracy' => 12.0, 'time' => iso($t0 + 1)),
			array('lat' => 38.96, 'lon' => -95.26, 'alt' => 301.0, 'accuracy' => 5.0, 'time' => iso($t0 + 9)),
			array('lat' => 38.97, 'lon' => -95.27, 'alt' => null, 'accuracy' => 5.0, 'time' => iso($t0 + 20)),
			array('lat' => 38.98, 'lon' => -95.28, 'alt' => 302.0, 'accuracy' => null, 'time' => iso($t0 + 30)),
		),
		'photos' => array(array('id' => '17598634000001', 'taken_at' => iso($t0 + 40))),
		'strike_convention' => 'rhr',
		'app_version' => '0.1.0 (1)',
		'device_model' => 'iPhone15,2',
		'audio' => array('mime' => 'audio/mp4', 'seconds' => 3.0),
	);
	foreach ($over as $k => $v) {
		if ($v === '__unset') unset($d[$k]); else $d[$k] = $v;
	}
	return $d;
}

try {

// ---------------------------------------------------------------- gate
section('Tester gate');
check('undefined list = nobody', VsConfig::allowedBy($ms, $upkMaya, null) === false);
check('typo (a string) = nobody', VsConfig::allowedBy($ms, $upkMaya, 'everyone ') === false);
check("'everyone' = all", VsConfig::allowedBy($ms, $upkC, 'everyone') === true);
check('listed, any case = in', VsConfig::allowedBy($ms, $upkMaya, array(' MAYA.Chen@test.strabospot.org')) === true);
check('not listed = out', VsConfig::allowedBy($ms, $upkC, array($MAYA[0])) === false);
check('pkey 0 = out even for everyone', VsConfig::allowedBy($ms, 0, 'everyone') === false);
$ms->q("UPDATE users SET deleted = true WHERE pkey = $1", array($upkC));
check('deleted account = out even for everyone', VsConfig::allowedBy($ms, $upkC, 'everyone') === false);
$ms->q("UPDATE users SET deleted = false WHERE pkey = $1", array($upkC));

$u = uuid();
foreach (array(
	array('GET', '/db/voicestation'), array('POST', '/db/voicestation'), array('GET', "/db/voicebatch/$u"), array('GET', "/db/voiceaudio/$u"),
	array('POST', "/db/voiceconfirm/$u"), array('POST', "/db/voiceretry/$u"),
	array('GET', '/db/voiceconsent'), array('POST', '/db/voiceconsent')) as $r) {
	$x = req($r[0], $r[1], $C, array('json' => '{}'));
	check("unlisted account: {$r[0]} {$r[1]} = 403 not_available",
		$x['code'] === 403 && $x['json']['code'] === 'not_available' && isset($x['json']['Error']), $x['code'] . ' ' . $x['body']);
}
section('Access check: GET /db/voicestation (step 4 point 3)');
$x = req('GET', '/db/voicestation', $MAYA);
check('listed account: 200 available + limits',
	$x['code'] === 200 && $x['json'] === array('available' => true, 'max_seconds' => VsConfig::MAX_SECONDS,
		'max_bytes' => VsConfig::MAX_AUDIO_BYTES, 'max_photos' => VsConfig::MAX_PHOTOS,
		'consent' => array('version' => VsConfig::CONSENT_VERSION, 'accepted' => false)), $x['code'] . ' ' . $x['body']);
check('access check answers JSON', isset($x['headers']['content-type']) && strpos($x['headers']['content-type'], 'application/json') === 0, $x['headers']);
$x = req('GET', "/db/voicestation/$u", $MAYA);
check('GET /db/voicestation/{id} = 404', $x['code'] === 404, $x['code'] . ' ' . $x['body']);
$x = req('GET', '/db/voicestation', array($MAYA[0], 'wrong-password'));
check('access check, wrong password stops at Apache (401)', $x['code'] === 401, $x['code']);
$x = req('GET', "/db/voicebatch/$u", array($MAYA[0], 'wrong-password'));
check('wrong password stops at Apache (401)', $x['code'] === 401, $x['code']);

// ---------------------------------------------------------------- consent
section('Consent (step 6)');
$x = req('GET', '/db/voiceconsent', $MAYA);
check('GET: current version, not agreed yet, the full text',
	$x['code'] === 200 && $x['json']['version'] === VsConfig::CONSENT_VERSION && $x['json']['accepted_at'] === null
	&& $x['json']['text']['version'] === VsConfig::CONSENT_VERSION && count($x['json']['text']['sections']) === 5
	&& strpos(json_encode($x['json']['text']), 'strabospot@gmail.com') !== false, $x['body']);
check('GET /db/voiceconsent/{x} = 404', req('GET', '/db/voiceconsent/1', $MAYA)['code'] === 404);
$SC = uuid();
$x = upload($MAYA, details($SC, uuid(), array($SC)));
check('upload before agreeing -> 403 consent_required, nothing stored',
	$x['code'] === 403 && $x['json']['code'] === 'consent_required'
	&& (int)$ms->val("SELECT count(*) FROM voicestations.stations WHERE station_uuid = $1", array($SC)) === 0, $x['body']);
// agreeing to an older text does not count once the text has changed
$ms->q("INSERT INTO voicestations.consents (userpkey, version) VALUES ($1, $2)", array($upkMaya, VsConfig::CONSENT_VERSION - 1));
$x = upload($MAYA, details($SC, uuid(), array($SC)));
check('agreed only to an older version: upload still 403 consent_required', $x['code'] === 403 && $x['json']['code'] === 'consent_required', $x['body']);
check('... and the access check says not accepted', req('GET', '/db/voicestation', $MAYA)['json']['consent']['accepted'] === false);
$ms->q("DELETE FROM voicestations.consents WHERE userpkey = $1", array($upkMaya));
foreach (array(
	array('no version', new stdClass(), 400, 'version'),
	array('version as text', array('version' => '1'), 400, 'version'),
	array('app_version too long', array('version' => VsConfig::CONSENT_VERSION, 'app_version' => str_repeat('x', 101)), 400, 'app_version'),
	array('device_model not text', array('version' => VsConfig::CONSENT_VERSION, 'device_model' => 5), 400, 'device_model'),
) as $b) {
	$x = req('POST', '/db/voiceconsent', $MAYA, array('json' => $b[1]));
	check("POST {$b[0]} -> {$b[2]} field {$b[3]}", $x['code'] === $b[2] && $x['json']['field'] === $b[3], $x['body']);
}
$x = req('POST', '/db/voiceconsent', $MAYA, array('json' => array('version' => VsConfig::CONSENT_VERSION + 1)));
check('POST another version -> 409 consent_outdated', $x['code'] === 409 && $x['json']['code'] === 'consent_outdated', $x['body']);
check('... nothing stored by the refused ones', (int)$ms->val("SELECT count(*) FROM voicestations.consents WHERE userpkey = $1", array($upkMaya)) === 0);
$x = req('POST', '/db/voiceconsent', $MAYA, array('json' => array('version' => VsConfig::CONSENT_VERSION, 'app_version' => '0.1.0 (12, abc1234)', 'device_model' => 'iPhone13,4')));
check('POST agree -> 201 with the time', $x['code'] === 201 && $x['json']['existing'] === false
	&& preg_match('/^\d{4}-\d{2}-\d{2}T/', (string)$x['json']['accepted_at']), $x['body']);
$x = req('POST', '/db/voiceconsent', $MAYA, array('json' => array('version' => VsConfig::CONSENT_VERSION)));
check('POST again -> 200 existing, still one row, app details kept',
	$x['code'] === 200 && $x['json']['existing'] === true
	&& $ms->val("SELECT string_agg(app_version || ' ' || device_model, ',') FROM voicestations.consents WHERE userpkey = $1", array($upkMaya)) === '0.1.0 (12, abc1234) iPhone13,4', $x['body']);
check('GET now has accepted_at', req('GET', '/db/voiceconsent', $MAYA)['json']['accepted_at'] !== null);
check('access check now says accepted', req('GET', '/db/voicestation', $MAYA)['json']['consent']['accepted'] === true);
check('test-b agrees too', req('POST', '/db/voiceconsent', $B, array('json' => array('version' => VsConfig::CONSENT_VERSION)))['code'] === 201);

// ---------------------------------------------------------------- upload checks
section('Upload: details checks (400 naming the field, nothing stored)');
$S1 = uuid(); $S2 = uuid(); $BATCH = uuid();
$LIST = array($S1, $S2);
$bad = array(
	array('details missing', null, 'details'),
	array('details not an object', '[1,2]', 'details'),
	array('station_uuid malformed', array('station_uuid' => 'nope'), 'station_uuid'),
	array('batch_uuid missing', array('batch_uuid' => '__unset'), 'batch_uuid'),
	array('station not in its batch list', array('batch_station_uuids' => array($S2)), 'batch_station_uuids'),
	array('batch list entry not a UUID', array('batch_station_uuids' => array($S1, 'x')), 'batch_station_uuids'),
	array('project_id not digits', array('project_id' => 'abc'), 'project_id'),
	array('target kind unknown', array('target' => array('kind' => 'other')), 'target.kind'),
	array('existing target without Spot id', array('target' => array('kind' => 'existing', 'spot' => array('a' => 1))), 'target.spot_id'),
	array('existing target without Spot JSON', array('target' => array('kind' => 'existing', 'spot_id' => '123')), 'target.spot'),
	array('started_at without time zone', array('started_at' => '2026-10-07T15:02:11'), 'started_at'),
	array('ends before it starts', array('ended_at' => iso($t0 - 5)), 'ended_at'),
	array('Record to Stop over 2 hours', array('ended_at' => iso($t0 + 7201)), 'ended_at'),
	array('audio longer than 10 minutes', array('audio' => array('mime' => 'audio/mp4', 'seconds' => 606)), 'audio.seconds'),
	array('tz offset not whole minutes', array('tz_offset_minutes' => 1.5), 'tz_offset_minutes'),
	array('lat out of range', array('gps_fixes' => array(array('lat' => 91, 'lon' => 0, 'accuracy' => 1, 'time' => iso($t0)))), 'gps_fixes[0].lat'),
	array('lon as a string', array('gps_fixes' => array(array('lat' => 1, 'lon' => '2', 'accuracy' => 1, 'time' => iso($t0)))), 'gps_fixes[0].lon'),
	array('negative accuracy', array('gps_fixes' => array(array('lat' => 1, 'lon' => 2, 'accuracy' => -1, 'time' => iso($t0)))), 'gps_fixes[0].accuracy'),
	array('fix without time', array('gps_fixes' => array(array('lat' => 1, 'lon' => 2, 'accuracy' => 1))), 'gps_fixes[0].time'),
	array('too many fixes', array('gps_fixes' => array_fill(0, 2001, array('lat' => 1, 'lon' => 2, 'accuracy' => 1, 'time' => iso($t0)))), 'gps_fixes'),
	array('photo with an extra key', array('photos' => array(array('id' => '1', 'taken_at' => iso($t0), 'uri' => 'file:///x'))), 'photos[0]'),
	array('too many photos', array('photos' => array_fill(0, 51, array('id' => '1', 'taken_at' => iso($t0)))), 'photos'),
	array('unknown strike convention', array('strike_convention' => 'lhr'), 'strike_convention'),
	array('audio mime not m4a', array('audio' => array('mime' => 'audio/wav', 'seconds' => 3)), 'audio.mime'),
	array('recorded_on unknown', array('recorded_on' => 'tablet'), 'recorded_on'),
	array('recorded_on not text', array('recorded_on' => 1), 'recorded_on'),
	array('watch_model on a phone recording', array('recorded_on' => 'phone', 'watch_model' => 'Watch6,2 watchOS 26.6'), 'watch_model'),
	array('watch_model without recorded_on', array('watch_model' => 'Watch6,2 watchOS 26.6'), 'watch_model'),
	array('watch_model over 100 characters', array('recorded_on' => 'watch', 'watch_model' => str_repeat('W', 101)), 'watch_model'),
);
foreach ($bad as $b) {
	$d = $b[1] === null ? '' : (is_string($b[1]) ? $b[1] : details($S1, $BATCH, $LIST, $b[1]));
	$x = upload($MAYA, $d);
	check("{$b[0]} -> 400 field {$b[2]}", $x['code'] === 400 && $x['json']['code'] === 'bad_request' && $x['json']['field'] === $b[2], $x['code'] . ' ' . $x['body']);
}
$x = upload($MAYA, details($S1, $BATCH, $LIST), null);
check('audio missing -> 400 field audio', $x['code'] === 400 && $x['json']['field'] === 'audio', $x['body']);
file_put_contents("$W/notm4a.m4a", str_repeat('RIFF....WAVE', 100));
$x = upload($MAYA, details($S1, $BATCH, $LIST), "$W/notm4a.m4a");
check('audio not MPEG-4 -> 400 field audio', $x['code'] === 400 && $x['json']['field'] === 'audio', $x['body']);
$fh = fopen("$W/big.m4a", 'wb');
fwrite($fh, file_get_contents($FIX, false, null, 0, 64));
ftruncate($fh, VsConfig::MAX_AUDIO_BYTES + 1);
fclose($fh);
$x = upload($MAYA, details($S1, $BATCH, $LIST), "$W/big.m4a");
check('audio over 20 MB -> 413 too_large', $x['code'] === 413 && $x['json']['code'] === 'too_large', $x['code'] . ' ' . $x['body']);
$x = upload($MAYA, details($S1, $BATCH, $LIST, array('dataset_id' => '99999999999999')));
check('dataset not in your account -> 404 field dataset_id', $x['code'] === 404 && $x['json']['field'] === 'dataset_id', $x['body']);
$x = upload($B, details($S1, $BATCH, $LIST));
check("someone else's project -> 404", $x['code'] === 404, $x['body']);
check('nothing stored by any refused upload',
	(int)$ms->val("SELECT count(*) FROM voicestations.batches WHERE batch_uuid = $1", array($BATCH)) === 0
	&& !is_dir("$ROOT/audio/$upkB") && !is_file("$ROOT/audio/$upkMaya/$S1.m4a"));

// ---------------------------------------------------------------- upload ok
section('Upload: new, resend, batch completeness');
$x = upload($MAYA, details($S1, $BATCH, $LIST, array('project_id' => $PID, 'dataset_id' => (int)$DID)));
check('station 1 of 2 -> 201 new, stage uploaded (ids as string or number)', $x['code'] === 201 && $x['json']['stored'] === 'new' && $x['json']['stage'] === 'uploaded', $x['body']);
check('batch: not complete, 1 of 2, missing station 2',
	$x['json']['batch'] === array('complete' => false, 'expected' => 2, 'received' => 1, 'missing' => array($S2)), $x['json']['batch']);
$r = $ms->row("SELECT s.*, b.project_id, b.dataset_id FROM voicestations.stations s JOIN voicestations.batches b ON b.id = s.batch_id WHERE station_uuid = $1", array($S1));
check('row belongs to the login, ids stored as text', (int)$r['userpkey'] === $upkMaya && $r['project_id'] === $PID && $r['dataset_id'] === $DID);
check('best fix = smallest accuracy, earliest on a tie, null accuracy never',
	(float)$r['best_lat'] === 38.96 && (float)$r['best_accuracy'] === 5.0 && (float)$r['best_alt'] === 301.0, json_encode(array($r['best_lat'], $r['best_accuracy'])));
check('all 4 fixes, photos, details kept', count(json_decode($r['gps_fixes'])) === 4 && count(json_decode($r['photos'])) === 1
	&& json_decode($r['details'])->device_model === 'iPhone15,2');
check('no recorded_on (builds before the watch app) -> stored NULL, read as phone', $r['recorded_on'] === null && $r['watch_model'] === null);
$path = "$ROOT/audio/$upkMaya/$S1.m4a";
check('audio stored at audio/<userpkey>/<uuid>.m4a, byte identical, sha recorded',
	$r['audio_path'] === "audio/$upkMaya/$S1.m4a" && is_file($path) && md5_file($path) === md5_file($FIX)
	&& $r['audio_sha256'] === hash_file('sha256', $FIX) && (int)$r['audio_bytes'] === filesize($FIX));
check('no .part file left', !is_file("$path.part"));

$mtime = filemtime($path);
clearstatcache();
$x = upload($MAYA, details($S1, $BATCH, $LIST));
check('resend -> 200 already_stored', $x['code'] === 200 && $x['json']['stored'] === 'already_stored', $x['body']);
check('resend made no second row', (int)$ms->val("SELECT count(*) FROM voicestations.stations WHERE station_uuid = $1", array($S1)) === 1);
check('upper-case UUIDs mean the same station', upload($MAYA, details(strtoupper($S1), strtoupper($BATCH), array_map('strtoupper', $LIST)))['code'] === 200);

$x = upload($B, details($S1, uuid(), array($S1)));
check("another user resending this station UUID -> 409", $x['code'] === 409 && $x['json']['code'] === 'conflict', $x['body']);
$x = upload($MAYA, details($S1, uuid(), array($S1)));
check('same station in another batch -> 409', $x['code'] === 409 && strpos($x['json']['Error'], 'another batch') !== false, $x['body']);
// test-b owns no Field project, so seed a stored station of theirs directly
$SB = uuid(); $BB = uuid();
$bbId = $ms->val("INSERT INTO voicestations.batches (batch_uuid, userpkey, project_id, dataset_id, station_uuids) VALUES ($1, $2, $3, $4, ARRAY[$5]::uuid[]) RETURNING id", array($BB, $upkB, $PID, $DID, $SB));
$ms->q("INSERT INTO voicestations.stations (station_uuid, batch_id, userpkey, target_kind, started_at, ended_at, details) VALUES ($1, $2, $3, 'new', now(), now(), '{}')", array($SB, $bbId, $upkB));
$x = upload($B, details($SB, $BATCH, array($SB)));
check("another user's batch UUID -> 409 (batch already in use)", $x['code'] === 409 && strpos($x['json']['Error'], 'batch UUID') !== false, $x['body']);
check("... and Maya's batch list is untouched", $ms->val("SELECT array_to_json(station_uuids) FROM voicestations.batches WHERE batch_uuid = $1", array($BATCH)) === json_encode($LIST));
$x = upload($MAYA, details($S1, $BATCH, $LIST, array('dataset_id' => '1')));
check('batch sent with another dataset -> 409', $x['code'] === 409 && strpos($x['json']['Error'], 'dataset') !== false, $x['body']);

// 17 minutes Record to Stop, 60 s of audio: a call paused it; the 10 minute limit is on the audio
$d2 = details($S2, $BATCH, $LIST, array('started_at' => iso($t0 - 1560), 'ended_at' => iso($t0 - 540), 'gps_fixes' => array(), 'photos' => array(),
	'audio' => array('mime' => 'audio/mp4', 'seconds' => 60), 'recorded_on' => 'watch', 'watch_model' => 'Watch6,2 watchOS 26.6'));
$x = upload($MAYA, $d2);
check('station 2 (paused by a call, 17 min wall clock) -> 201, batch complete 2 of 2', $x['code'] === 201 && $x['json']['batch']['complete'] === true && $x['json']['batch']['missing'] === array(), $x['body']);
check('watch recording -> recorded_on watch + watch_model stored, device_model stays the phone',
	$ms->row("SELECT recorded_on, watch_model, device_model FROM voicestations.stations WHERE station_uuid = $1", array($S2))
	=== array('recorded_on' => 'watch', 'watch_model' => 'Watch6,2 watchOS 26.6', 'device_model' => 'iPhone15,2'));
check('no fixes -> no best fix (the "no location" case)',
	$ms->val("SELECT best_accuracy FROM voicestations.stations WHERE station_uuid = $1", array($S2)) === null);

// ---------------------------------------------------------------- batch
section('Batch status');
$x = req('GET', "/db/voicebatch/$BATCH", $MAYA);
check('in progress: complete, not done, no results yet',
	$x['code'] === 200 && $x['json']['complete'] === true && $x['json']['done'] === false && !isset($x['json']['stations'])
	&& $x['json']['stages']['uploaded'] === 2, $x['body']);
check("other tester asking for Maya's batch -> 404", req('GET', "/db/voicebatch/$BATCH", $B)['code'] === 404);
check('unknown batch -> 404', req('GET', '/db/voicebatch/' . uuid(), $MAYA)['code'] === 404);
check('malformed batch id -> 404', req('GET', '/db/voicebatch/xyz', $MAYA)['code'] === 404);

// simulate the worker: S1 ready with transcript + proposal, S2 failed after transcription
$id1 = (int)$ms->val("SELECT id FROM voicestations.stations WHERE station_uuid = $1", array($S1));
$id2 = (int)$ms->val("SELECT id FROM voicestations.stations WHERE station_uuid = $1", array($S2));
$tr1 = $ms->val("INSERT INTO voicestations.runs (station_id, kind, status, engine, model, raw_output, output) VALUES ($1, 'transcribe', 'done', 'whisper.cpp', 'large-v3-turbo', 'raw', $2::jsonb) RETURNING id",
	array($id1, '{"text": "strike 045 dip 30", "words": [{"w": "strike", "t0": 0.5, "t1": 0.9}], "empty": {}}'));
$pr1 = $ms->val("INSERT INTO voicestations.runs (station_id, kind, status, engine, model, prompt_version, transcript_run_id, output, validation, settings) VALUES ($1, 'extract', 'done', 'anthropic', 'claude-opus-5', 'v1', $2, $3::jsonb, $4::jsonb, '{\"vocab_version\": \"test-vocab-1\"}'::jsonb) RETURNING id",
	array($id1, $tr1, '{"orientation_data": [{"strike": 45, "dip": 30}]}', '{"flags": []}'));
$ms->q("UPDATE voicestations.stations SET stage = 'ready', current_transcript_run = $2, current_proposal_run = $3 WHERE id = $1", array($id1, $tr1, $pr1));
$x = req('GET', "/db/voicebatch/$BATCH", $MAYA);
check('one ready, one still uploaded: not done, no results or vocab', $x['json']['done'] === false && $x['json']['stages']['ready'] === 1
	&& !isset($x['json']['vocab']) && !isset($x['json']['stations']), $x['body']);
$tr2 = $ms->val("INSERT INTO voicestations.runs (station_id, kind, status, engine, model, output) VALUES ($1, 'transcribe', 'done', 'whisper.cpp', 'large-v3-turbo', '{\"text\": \"hello\"}'::jsonb) RETURNING id", array($id2));
$ms->q("UPDATE voicestations.stations SET stage = 'failed', error_text = 'extraction failed 3 times', current_transcript_run = $2 WHERE id = $1", array($id2, $tr2));
$x = req('GET', "/db/voicebatch/$BATCH", $MAYA);
$st = $x['json']['stations'];
check('done: complete and every station ready or failed', $x['json']['done'] === true && count($st) === 2, $x['body']);
check('results in recording order (station 2 was recorded first)', $st[0]['station_uuid'] === $S2 && $st[1]['station_uuid'] === $S1);
check('ready station: transcript + proposal + validation, best fix, not confirmed',
	$st[1]['stage'] === 'ready' && $st[1]['transcript']['text'] === 'strike 045 dip 30' && $st[1]['proposal']['orientation_data'][0]['strike'] === 45
	&& $st[1]['validation'] === array('flags' => array()) && $st[1]['best_fix']['accuracy'] === 5.0 && $st[1]['confirmed'] === null
	&& $st[1]['error'] === null, $st[1]);
check('ready station: its proposal run id + the vocab version its extraction used',
	$st[1]['proposal_run'] === (int)$pr1 && $st[1]['vocab_version'] === 'test-vocab-1', $st[1]);
check('failed station: no proposal run, no vocab version', $st[0]['proposal_run'] === null && $st[0]['vocab_version'] === null);
$vf = $x['json']['vocab']['forms']['measurement.planar_orientation'] ?? null;
check('done batch carries the review form choices (version + planar feature_type / movement)',
	is_string($x['json']['vocab']['version'] ?? null) && isset($vf['feature_type']['bedding'], $vf['movement']), $x['json']['vocab'] ?? null);
check('empty JSON object survives as {}', strpos($x['body'], '"empty":{}') !== false);
check('failed station: error shown, transcript kept, no proposal, no best fix',
	$st[0]['stage'] === 'failed' && $st[0]['error'] === 'extraction failed 3 times' && $st[0]['transcript']['text'] === 'hello'
	&& $st[0]['proposal'] === null && $st[0]['best_fix'] === null, $st[0]);
check('times are ISO 8601 UTC', preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $st[1]['started_at']) === 1, $st[1]['started_at']);

$S3 = uuid();
$x = upload($MAYA, details($S1, $BATCH, array($S1, $S2, $S3)));
check('a longer list (latest wins) makes the batch incomplete again', $x['json']['batch']['complete'] === false && $x['json']['batch']['missing'] === array($S3), $x['body']);
check('incomplete batch is never done, even with every station finished', req('GET', "/db/voicebatch/$BATCH", $MAYA)['json']['done'] === false);
upload($MAYA, details($S1, $BATCH, $LIST));
check('original list restores it', req('GET', "/db/voicebatch/$BATCH", $MAYA)['json']['done'] === true);

// ---------------------------------------------------------------- audio
section('Audio');
$x = req('GET', "/db/voiceaudio/$S1", $MAYA);
check('owner gets the original bytes, Content-Length, Accept-Ranges',
	$x['code'] === 200 && $x['body'] === file_get_contents($FIX) && (int)$x['headers']['content-length'] === filesize($FIX)
	&& $x['headers']['accept-ranges'] === 'bytes' && $x['headers']['content-type'] === 'audio/mp4', $x['code']);
$x = req('GET', "/db/voiceaudio/$S1", $MAYA, array('headers' => array('Range: bytes=0-99')));
check('Range 0-99 -> 206, 100 bytes, Content-Range', $x['code'] === 206 && strlen($x['body']) === 100
	&& $x['headers']['content-range'] === 'bytes 0-99/' . filesize($FIX) && $x['body'] === substr(file_get_contents($FIX), 0, 100));
$x = req('GET', "/db/voiceaudio/$S1", $MAYA, array('headers' => array('Range: bytes=-10')));
check('Range last 10 bytes', $x['code'] === 206 && $x['body'] === substr(file_get_contents($FIX), -10));
$x = req('GET', "/db/voiceaudio/$S1", $MAYA, array('headers' => array('Range: bytes=999999-')));
check('Range past the end -> 416', $x['code'] === 416);
check("other tester -> 404", req('GET', "/db/voiceaudio/$S1", $B)['code'] === 404);
check('direct URL to the data folder -> 403', req('GET', "/voicestations_data/audio/$upkMaya/$S1.m4a", $MAYA)['code'] === 403);
check('code folder never served -> 403', req('GET', '/voicestations/lib/VsService.php', $MAYA)['code'] === 403);

// ---------------------------------------------------------------- confirm
section('Confirm / discard');
$conf = array('outcome' => 'confirmed', 'review_seconds' => 41.5, 'spot_ids' => array('17598700000001', '17598700000002'),
	'proposal_run' => (int)$pr1,
	'n_unchanged' => 99, // the phone's own count: ignored
	'record' => array(
		'values' => array(
			array('action' => 'unchanged', 'path' => 'strike'), array('action' => 'unchanged', 'path' => 'dip'),
			array('action' => 'edited', 'from' => 30, 'to' => 32), array('action' => 'removed'), array('action' => 'added')),
		'flags' => array(array('id' => 'f1', 'action' => 'kept')),
	));
$x = req('POST', "/db/voiceconfirm/$S1", $B, array('json' => $conf));
check("other tester confirming Maya's station -> 404", $x['code'] === 404, $x['body']);
foreach (array(
	array('outcome unknown', array('outcome' => 'maybe'), 'outcome'),
	array('confirmed without Spot ids', array('spot_ids' => array()), 'spot_ids'),
	array('Spot id with odd characters', array('spot_ids' => array('a b')), 'spot_ids'),
	array('unknown value action', array('record' => array('values' => array(array('action' => 'kept')))), 'record.values[0].action'),
	array('flag without action', array('record' => array('flags' => array(array('id' => 'f1')))), 'record.flags[0].action'),
	array('negative review time', array('review_seconds' => -1), 'review_seconds'),
	array('discarded with Spot ids', array('outcome' => 'discarded'), 'spot_ids'),
	array('proposal_run not a number', array('proposal_run' => 'abc'), 'proposal_run'),
) as $b) {
	$x = req('POST', "/db/voiceconfirm/$S1", $MAYA, array('json' => array_merge($conf, $b[1])));
	check("{$b[0]} -> 400 field {$b[2]}", $x['code'] === 400 && $x['json']['field'] === $b[2], $x['body']);
}
$noRun = $conf; unset($noRun['proposal_run']);
$x = req('POST', "/db/voiceconfirm/$S1", $MAYA, array('json' => $noRun));
check('confirm without proposal_run when there is a proposal -> 400 field proposal_run',
	$x['code'] === 400 && $x['json']['field'] === 'proposal_run', $x['body']);
$x = req('POST', "/db/voiceconfirm/$S1", $MAYA, array('json' => array_merge($conf, array('proposal_run' => (int)$pr1 + 100000))));
check('confirm naming an older proposal -> 409 stale_proposal, nothing stored',
	$x['code'] === 409 && $x['json']['code'] === 'stale_proposal'
	&& $ms->val("SELECT count(*) FROM voicestations.confirms WHERE station_id = $1", array($id1)) === '0', $x['body']);
$x = req('POST', "/db/voiceconfirm/$S1", $MAYA, array('json' => 'not json'));
check('body not JSON -> 400', $x['code'] === 400);
projectUploaddate(1);
$x = req('POST', "/db/voiceconfirm/$S1", $MAYA, array('json' => $conf));
check('confirm stamps the project\'s Last Uploaded (Project.uploaddate)', (int)projectUploaddate() >= time() - 60);
$want = array('proposed' => 4, 'unchanged' => 2, 'edited' => 1, 'removed' => 1, 'added' => 1, 'flags' => 1);
check('confirm -> 201 with counts computed by the server', $x['code'] === 201 && $x['json']['counts'] === $want
	&& $x['json']['spot_ids'] === $conf['spot_ids'] && $x['json']['existing'] === false, $x['body']);
$c = $ms->row("SELECT c.*, s.confirmed_at FROM voicestations.confirms c JOIN voicestations.stations s ON s.id = c.station_id WHERE s.id = $1", array($id1));
check('confirm row: proposal run, record kept, station confirmed_at set',
	(int)$c['proposal_run_id'] === (int)$pr1 && json_decode($c['record'])->values[2]->to === 32 && $c['confirmed_at'] !== null && (int)$c['n_unchanged'] === 2);
projectUploaddate(1);
$x = req('POST', "/db/voiceconfirm/$S1", $MAYA, array('json' => array('outcome' => 'discarded')));
check('a resend does not stamp it again', (int)projectUploaddate() === 1);
check('resend (even a different one) -> 200 the stored record', $x['code'] === 200 && $x['json']['existing'] === true
	&& $x['json']['outcome'] === 'confirmed' && $x['json']['counts'] === $want, $x['body']);
$x = req('GET', "/db/voicebatch/$BATCH", $MAYA);
check('batch shows station 1 confirmed', $x['json']['stations'][1]['confirmed']['outcome'] === 'confirmed');

$x = req('POST', "/db/voiceconfirm/$S2", $MAYA, array('json' => array('outcome' => 'confirmed', 'spot_ids' => array('1'),
	'record' => array('values' => array(array('action' => 'edited'))))));
check('failed station (no proposal): an edited value -> 400', $x['code'] === 400 && $x['json']['field'] === 'record.values[0].action', $x['body']);

// retry before the hand entry consumes S2
section('Retry');
check('retry a ready station -> 409', req('POST', "/db/voiceretry/$S1", $MAYA)['code'] === 409);
check('other tester -> 404', req('POST', "/db/voiceretry/$S2", $B)['code'] === 404);
$ms->q("UPDATE voicestations.stations SET attempts = 3, lease_until = now(), leased_by = 'gpubox' WHERE id = $1", array($id2));
$x = req('POST', "/db/voiceretry/$S2", $MAYA);
$r2 = $ms->row("SELECT stage, attempts, error_text, lease_until, leased_by FROM voicestations.stations WHERE id = $1", array($id2));
check('failed with a transcript -> back to transcribed, attempts 0, error + lease cleared',
	$x['code'] === 200 && $x['json']['stage'] === 'transcribed' && $r2['stage'] === 'transcribed' && (int)$r2['attempts'] === 0
	&& $r2['error_text'] === null && $r2['lease_until'] === null && $r2['leased_by'] === null, $x['body']);
check('retry a station that is not failed -> 409', req('POST', "/db/voiceretry/$S2", $MAYA)['code'] === 409);
$x = req('POST', "/db/voiceconfirm/$S2", $MAYA, array('json' => array('outcome' => 'confirmed', 'spot_ids' => array('1'))));
check('confirm while still processing -> 409', $x['code'] === 409, $x['body']);

$ms->q("UPDATE voicestations.stations SET stage = 'failed', current_transcript_run = NULL, error_text = 'whisper crashed' WHERE id = $1", array($id2));
$x = req('POST', "/db/voiceretry/$S2", $MAYA);
check('failed with no transcript -> back to uploaded', $x['json']['stage'] === 'uploaded', $x['body']);
$ms->q("UPDATE voicestations.stations SET stage = 'failed', audio_deleted_at = now() WHERE id = $1", array($id2));
check('no transcript and audio gone -> 409', req('POST', "/db/voiceretry/$S2", $MAYA)['code'] === 409);
check('audio marked deleted -> 404', req('GET', "/db/voiceaudio/$S2", $MAYA)['code'] === 404);
$ms->q("UPDATE voicestations.stations SET audio_deleted_at = NULL WHERE id = $1", array($id2));

$x = req('POST', "/db/voiceconfirm/$S2", $MAYA, array('json' => array('outcome' => 'confirmed', 'spot_ids' => array('17598700000009'),
	'record' => array('values' => array(array('action' => 'added'), array('action' => 'added'))))));
check('failed station entered by hand -> 201, 0 proposed, 2 added, no proposal run',
	$x['code'] === 201 && $x['json']['counts']['proposed'] === 0 && $x['json']['counts']['added'] === 2
	&& $ms->val("SELECT proposal_run_id FROM voicestations.confirms WHERE station_id = $1", array($id2)) === null, $x['body']);
check('retry after confirm -> 409', req('POST', "/db/voiceretry/$S2", $MAYA)['code'] === 409);

// discard while still processing (P6.7)
$B2 = uuid(); $S4 = uuid();
upload($MAYA, details($S4, $B2, array($S4)));
projectUploaddate(1);
$x = req('POST', "/db/voiceconfirm/$S4", $MAYA, array('json' => array('outcome' => 'discarded', 'review_seconds' => 3)));
check('a discard does not stamp Last Uploaded', (int)projectUploaddate() === 1);
check('discard a station still uploaded -> 201, discarded_at set, no Spots',
	$x['code'] === 201 && $x['json']['outcome'] === 'discarded' && $x['json']['spot_ids'] === array()
	&& $ms->val("SELECT discarded_at IS NOT NULL FROM voicestations.stations WHERE station_uuid = $1", array($S4)) === 't', $x['body']);
check('unknown station -> 404', req('POST', '/db/voiceconfirm/' . uuid(), $MAYA, array('json' => array('outcome' => 'discarded')))['code'] === 404);

// ---------------------------------------------------------------- 503
section('Audio folder missing');
rename("$ROOT/audio", "$ROOT/audio_moved_by_test");
try {
	$S5 = uuid();
	$x = upload($MAYA, details($S5, uuid(), array($S5)));
	check('upload -> 503 unavailable, nothing created', $x['code'] === 503 && $x['json']['code'] === 'unavailable'
		&& !is_dir("$ROOT/audio") && (int)$ms->val("SELECT count(*) FROM voicestations.stations WHERE station_uuid = $1", array($S5)) === 0, $x['body']);
} finally {
	rename("$ROOT/audio_moved_by_test", "$ROOT/audio");
}

} finally {
	// ---------------------------------------------------------------- cleanup
	foreach (array($upkMaya, $upkB, $upkC) as $u) {
		foreach ($ms->rows("SELECT audio_path FROM voicestations.stations WHERE userpkey = $1 AND audio_path IS NOT NULL", array($u)) as $r) {
			@unlink("$ROOT/" . $r['audio_path']);
		}
		@rmdir("$ROOT/audio/$u");
		$ms->q("DELETE FROM voicestations.batches WHERE userpkey = $1", array($u));
	}
	$ms->q("DELETE FROM voicestations.consents WHERE userpkey IN ($1, $2, $3)", array($upkMaya, $upkB, $upkC));
	if (isset($UPLOADDATE0)) {
		projectUploaddate($UPLOADDATE0 === null ? 'null' : (int)$UPLOADDATE0);
	}
	$ms->q("DELETE FROM users WHERE pkey IN ($1, $2)", array($upkB, $upkC));
	array_map('unlink', glob("$W/*"));
	@rmdir($W);
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
