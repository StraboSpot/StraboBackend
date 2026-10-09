<?php
/**
 * File: score_test.php
 * Description: Voice Stations step 6 scoring routes over HTTP:
 *                - score/* needs the scorer token; worker tokens, a wrong
 *                  token, none -> 401; the scorer token cannot claim jobs
 *                - GET score/export: settled recordings only (confirmed and
 *                  discarded), with record, transcript, proposal, counts,
 *                  timing, the uploaded Spots, the Stopwatch Spots dataset
 *                  (measurement counts) and the form choices
 *                - POST score/results: validation, stored as one run
 *              Seeds directly in SQL + Neo4j under Maya's demo account and
 *              removes everything it made.
 *
 *              Needs on dev: VOICESTATIONS_SCORER = sha256('vs-dev-test-scorer'),
 *              the worker test tokens (worker_test.php), Maya's demo project.
 *
 *              Usage: docker exec strabo-php php /srv/app/www/tests/voicestations/score_test.php
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
$SCORER = 'vs-dev-test-scorer';
$WORKER = 'vs-dev-test-worker-a';
$MAYA_EMAIL = 'maya.chen@test.strabospot.org';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr(is_string($detail) ? $detail : json_encode($detail), 0, 1500) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function section($name) { echo "\n== $name\n"; }
function req($method, $path, $token = null, $json = null) {
	global $HOST;
	$ch = curl_init($HOST . $path);
	$h = array();
	if ($token !== null) $h[] = 'Authorization: Bearer ' . $token;
	curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true));
	if ($json !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($json) ? $json : json_encode($json));
		$h[] = 'Content-Type: application/json';
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('code' => $code, 'body' => $body, 'json' => json_decode($body, true));
}
function uuid() {
	$h = bin2hex(random_bytes(16));
	return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-8' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
}

$ms = new MsDb($db);
$upk = (int)$ms->val("SELECT pkey FROM users WHERE email = $1", array($MAYA_EMAIL));
$rec = $neodb->getRecord("MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset) RETURN p.id AS pid, d.id AS did LIMIT 1");
if (!$upk || !$rec) {
	echo "Maya's demo project is missing: run docs/StraboSamples/summary_doc/seed_demo.php first.\n";
	exit(1);
}
$PID = (string)$rec->value('pid');
$DID = (string)$rec->value('did');
$SW_DID = 17000000000000 + random_int(1000, 999999);
$SW_SPOT = $SW_DID + 1;
$S1 = uuid(); $S2 = uuid(); $S3 = uuid(); $B = uuid();
$scoreIds = array();

try {

section('Tokens');
check('export without a token -> 401', req('GET', '/voiceworker/v1/score/export')['code'] === 401);
check('export with a wrong token -> 401', req('GET', '/voiceworker/v1/score/export', 'nope')['code'] === 401);
$x = req('GET', '/voiceworker/v1/score/export', $WORKER);
check('a WORKER token cannot read the export -> 401', $x['code'] === 401 && $x['json']['code'] === 'unauthorized', $x['body']);
$x = req('POST', '/voiceworker/v1/claim', $SCORER, array('kinds' => array('transcribe')));
check('the SCORER token cannot claim jobs -> 401', $x['code'] === 401, $x['body']);
check('POST on export -> 405', req('POST', '/voiceworker/v1/score/export', $SCORER, '{}')['code'] === 405);
check('GET on results -> 405', req('GET', '/voiceworker/v1/score/results', $SCORER)['code'] === 405);
check('unit: authorized() fails closed on a missing hash',
	VsScore::authorized('Bearer x', null) === false && VsScore::authorized('Bearer x', 'abc') === false);

section('Export');
// three of Maya's recordings: confirmed (with an uploaded Spot), discarded, still processing
$bid = $ms->val("INSERT INTO voicestations.batches (batch_uuid, userpkey, project_id, dataset_id, station_uuids)
                 VALUES ($1, $2, $3, $4, ARRAY[$5, $6, $7]::uuid[]) RETURNING id", array($B, $upk, $PID, $DID, $S1, $S2, $S3));
$details = json_encode(array('spot_name' => 'V-07', 'strike_convention' => 'rhr',
	'audio' => array('recorded_seconds' => 31.5, 'pauses' => array(array('atAudioSeconds' => 12.0)))));
$ids = array();
foreach (array($S1, $S2, $S3) as $i => $s) {
	$ids[$s] = $ms->val("INSERT INTO voicestations.stations (station_uuid, batch_id, userpkey, target_kind, started_at, ended_at,
	                       details, stage, audio_seconds) VALUES ($1, $2, $3, 'new', now() - interval '1 hour' + ($4 || ' minutes')::interval,
	                       now(), $5::jsonb, 'ready', 31.4) RETURNING id", array($s, $bid, $upk, (string)$i, $details));
}
$tr = $ms->val("INSERT INTO voicestations.runs (station_id, kind, status, engine, model, output) VALUES ($1, 'transcribe', 'done', 'whisper', 'turbo', $2::jsonb) RETURNING id",
	array($ids[$S1], json_encode(array('text' => 'Bedding strike 45 dip 32.', 'words' => array()))));
$pr = $ms->val("INSERT INTO voicestations.runs (station_id, kind, status, engine, model, output) VALUES ($1, 'extract', 'done', 'anthropic', 'claude', $2::jsonb) RETURNING id",
	array($ids[$S1], json_encode(array('items' => array(array('ref' => 'm1', 'kind' => 'orientation'))))));
$ms->q("UPDATE voicestations.stations SET current_transcript_run = $1, current_proposal_run = $2 WHERE id = $3", array($tr, $pr, $ids[$S1]));
$ms->q("UPDATE voicestations.stations SET recorded_on = 'watch', watch_model = 'Watch6,2 watchOS 26.6' WHERE id = $1", array($ids[$S2]));
$spotId = 17999000000000 + random_int(1000, 999999);
$record = array('format' => 1, 'spots' => array(array('card' => 'A', 'spot_id' => (string)$spotId)),
	'values' => array(array('ref' => 'm1', 'field' => 'strike', 'action' => 'unchanged', 'proposed' => 45, 'final' => 45)));
$ms->q("INSERT INTO voicestations.confirms (station_id, userpkey, proposal_run_id, outcome, record, n_proposed, n_unchanged, review_seconds, spot_ids)
        VALUES ($1, $2, $3, 'confirmed', $4::jsonb, 1, 1, 22.5, ARRAY[$5]::text[])", array($ids[$S1], $upk, $pr, json_encode($record), (string)$spotId));
$ms->q("INSERT INTO voicestations.confirms (station_id, userpkey, outcome, record, review_seconds) VALUES ($1, $2, 'discarded', '{}'::jsonb, 2)", array($ids[$S2], $upk));
// the uploaded Spot, and a Stopwatch Spots dataset with one Spot of 3 measurements (a plane with two lines)
$od = json_encode(array(array('type' => 'planar_orientation', 'strike' => 45, 'dip' => 32, 'associated_orientation' => array(
	array('type' => 'linear_orientation', 'trend' => 1, 'plunge' => 2), array('type' => 'linear_orientation', 'trend' => 3, 'plunge' => 4)))));
$neodb->query("MATCH (d:Dataset {userpkey: $upk}) WHERE d.id = $DID OR d.id = '$DID'
               CREATE (d)-[:HAS_SPOT]->(:Spot {id: $spotId, userpkey: $upk, name: 'V-07', geometrytype: 'Point', wkt: 'POINT (-95.2 38.9)',
                      origwkt: 'POINT (-95.2 38.9)', json_orientation_data: '" . addslashes($od) . "'})");
$neodb->query("MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project) WHERE p.id = $PID OR p.id = '$PID'
               CREATE (p)-[:HAS_DATASET]->(d:Dataset {id: $SW_DID, userpkey: $upk, name: 'Stopwatch Spots'})
               CREATE (d)-[:HAS_SPOT]->(:Spot {id: $SW_SPOT, userpkey: $upk, name: 'SW-1', date: '2026-10-09',
                      json_orientation_data: '" . addslashes($od) . "'})");

$x = req('GET', '/voiceworker/v1/score/export', $SCORER);
check('export -> 200 JSON', $x['code'] === 200 && is_array($x['json']), substr($x['body'], 0, 400));
$e = $x['json'];
$mine = array();
foreach ($e['stations'] as $st) if (in_array($st['station_uuid'], array($S1, $S2, $S3), true)) $mine[$st['station_uuid']] = $st;
check('settled recordings only: confirmed + discarded, not the one still processing',
	isset($mine[$S1]) && isset($mine[$S2]) && !isset($mine[$S3]) && $mine[$S2]['outcome'] === 'discarded', array_keys($mine));
$c = $mine[$S1];
check('confirmed: record, counts, review time, recording time, pauses, spot name',
	$c['record']['values'][0]['final'] === 45 && $c['counts']['unchanged'] === 1 && $c['review_seconds'] === 22.5
	&& $c['recorded_seconds'] === 31.5 && count($c['pauses']) === 1 && $c['spot_name'] === 'V-07', $c);
check('recorded_on: NULL (older build) exported as phone; a watch recording as watch with its model',
	$c['recorded_on'] === 'phone' && $c['watch_model'] === null
	&& $mine[$S2]['recorded_on'] === 'watch' && $mine[$S2]['watch_model'] === 'Watch6,2 watchOS 26.6', array($c['recorded_on'], $mine[$S2]['recorded_on']));
check('confirmed: transcript + proposal of the reviewed run',
	$c['transcript']['text'] === 'Bedding strike 45 dip 32.' && $c['proposal']['items'][0]['ref'] === 'm1' && $c['proposal_run'] === (int)$pr);
check('confirmed: the uploaded Spot, read back from Neo4j',
	isset($c['spots'][(string)$spotId]['properties']['orientation_data'][0]['strike'])
	&& $c['spots'][(string)$spotId]['properties']['orientation_data'][0]['strike'] == 45, $c['spots']);
$sw = array_values(array_filter($e['stopwatch'], function ($d) use ($SW_DID) { return $d['dataset_id'] === (string)$SW_DID; }));
check('Stopwatch Spots dataset found by name; its Spot counts 3 measurements (plane + 2 lines)',
	count($sw) === 1 && $sw[0]['spots'][0]['name'] === 'SW-1' && $sw[0]['spots'][0]['n_measurements'] === 3, $e['stopwatch']);
check('tester listed with email; form choices included',
	in_array($MAYA_EMAIL, array_column($e['testers'], 'email'), true) && isset($e['vocab']['forms']['measurement.planar_orientation']['feature_type']));

section('Results');
$sha = str_repeat('ab', 32);
foreach (array(
	array('no scorer_version', array('key_sha256' => $sha, 'results' => array('a' => 1)), 'scorer_version'),
	array('bad key hash', array('scorer_version' => 'score-1', 'key_sha256' => 'xyz', 'results' => array('a' => 1)), 'key_sha256'),
	array('results not an object', array('scorer_version' => 'score-1', 'key_sha256' => $sha, 'results' => array(1, 2)), 'results'),
) as $b) {
	$x = req('POST', '/voiceworker/v1/score/results', $SCORER, $b[1]);
	check("{$b[0]} -> 400 field {$b[2]}", $x['code'] === 400 && $x['json']['field'] === $b[2], $x['body']);
}
$x = req('POST', '/voiceworker/v1/score/results', $WORKER, array('scorer_version' => 'score-1', 'key_sha256' => $sha, 'results' => array('a' => 1)));
check('a worker token cannot store results -> 401', $x['code'] === 401);
$x = req('POST', '/voiceworker/v1/score/results', $SCORER, array('scorer_version' => 'score-1', 'key_sha256' => $sha, 'results' => array('overall' => array('bar1' => array('wrong' => 0)))));
check('results -> 201 with the run id', $x['code'] === 201 && is_int($x['json']['id']), $x['body']);
$scoreIds[] = $x['json']['id'];
$row = $ms->row("SELECT scorer_version, key_sha256, results->'overall'->'bar1'->>'wrong' AS w FROM voicestations.scores WHERE id = $1", array($x['json']['id']));
check('stored: version, key hash, results', $row['scorer_version'] === 'score-1' && $row['key_sha256'] === $sha && $row['w'] === '0', $row);

} finally {
	$ms->q("DELETE FROM voicestations.batches WHERE batch_uuid = $1", array($B));
	foreach ($scoreIds as $id) $ms->q("DELETE FROM voicestations.scores WHERE id = $1", array($id));
	if (isset($spotId)) $neodb->query("MATCH (s:Spot {id: $spotId, userpkey: $upk}) DETACH DELETE s");
	$neodb->query("MATCH (d:Dataset {id: $SW_DID, userpkey: $upk}) OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot) DETACH DELETE s, d");
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
