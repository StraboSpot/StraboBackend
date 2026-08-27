<?php
/**
 * File: smoke_test_my_samples_type_filter.php
 * Description: Fixture + markup smoke for the My Samples "Type" filter
 *              (tri-state subsystem chips + any/all match pill + readout).
 *
 *              Hermetic fixture: one user (pkey 94570, 'spsty') owning
 *              EIGHT samples, one per subsystem-membership combination
 *              (none, f, m, e, fm, fe, me, fme), each with the matching
 *              sample_subsystem_links rows. Zero residue on exit.
 *
 *              Modes:
 *                (no arg)        setup + fetch /my_samples.php with a forged
 *                                session + markup/JSON checks + teardown
 *                setup           create fixture + session, print JSON
 *                                {sid, userpkey, ids} and EXIT (fixture kept)
 *                teardown <sid>  remove fixture + that session file
 *
 *              The interactive filter logic (chip cycling, any/all, URL
 *              round-trip, counts) lives in JS and is exercised in real
 *              Firefox by ui_test_my_samples_type_filter_ff.js, which
 *              drives the setup/teardown modes of this file.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_my_samples_type_filter.php
 *
 * @package    StraboSamples
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$UPK  = 94570;
$PFX  = 'spsty';
$BASE = 'http://localhost';
$sessionDir = '/var/lib/php/sessions';

// combo code => subsystems linked
$COMBOS = array(
	'none' => array(),
	'f'    => array('field'),
	'm'    => array('micro'),
	'e'    => array('experimental'),
	'fm'   => array('field', 'micro'),
	'fe'   => array('field', 'experimental'),
	'me'   => array('micro', 'experimental'),
	'fme'  => array('field', 'micro', 'experimental'),
);

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}

function forgeSession($pkey) {
	global $sessionDir;
	$sid  = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = $sessionDir . '/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600);
	@chown($path, 'www-data');
	@chgrp($path, 'www-data');
	return $sid;
}

function teardown($sid = null) {
	global $db, $UPK, $PFX, $sessionDir;
	$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($UPK)); // links cascade
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($UPK));
	if ($sid !== null && preg_match('/^[0-9a-v]{26}$/', $sid)) @unlink($sessionDir . '/sess_' . $sid);
}

function setup() {
	global $db, $UPK, $PFX, $COMBOS;
	teardown();
	$db->prepare_query(
		"INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted)
		 VALUES ($1, 'Tri', 'Typefilter', $2, 'x', 'x', TRUE, FALSE)",
		array($UPK, $PFX . '@example.com'));
	$ids = array();
	$i = 0;
	foreach ($COMBOS as $code => $systems) {
		$id = $PFX . '_' . $code;
		$ids[$code] = $id;
		$db->prepare_query(
			"INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by, modified_at)
			 VALUES ($1, $2, $3, $2, $2, now() - ($4 || ' seconds')::interval)",
			array($id, $UPK, 'Fixture ' . $code, (string)($i * 10)));
		foreach ($systems as $sys) {
			$db->prepare_query(
				"INSERT INTO strabosamples.sample_subsystem_links
				   (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
				 VALUES ($1, $2, $3, $4, $2, $5)",
				array($id, $UPK, $sys, $PFX . '_ref_' . $code . '_' . $sys,
				      $sys === 'micro' ? '{"micrograph_count": 2}' : '{}'));
		}
		$i++;
	}
	return $ids;
}

$mode = isset($argv[1]) ? $argv[1] : '';

if ($mode === 'teardown') {
	teardown(isset($argv[2]) ? $argv[2] : null);
	echo json_encode(array('ok' => true)) . PHP_EOL;
	exit(0);
}

$ids = setup();
$sid = forgeSession($UPK);

if ($mode === 'setup') {
	echo json_encode(array('sid' => $sid, 'userpkey' => $UPK, 'ids' => $ids)) . PHP_EOL;
	exit(0);
}

// ---------------------------------------------------------------------------
// Default mode: markup + embedded-JSON checks over real HTTP.
// ---------------------------------------------------------------------------
$ch = curl_init($BASE . '/my_samples.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Cookie: PHPSESSID=' . $sid));
$body   = (string)curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo PHP_EOL . "== Page shell" . PHP_EOL;
check('GET /my_samples.php as fixture user is 200', $status === 200, "status=$status");
check('no PHP warning/notice leaked into the page', stripos($body, '<b>Warning</b>') === false && stripos($body, '<b>Notice</b>') === false);
check('All chip present + active by default', preg_match('/class="ms-tab active" data-type="all"/', $body) === 1);
foreach (array('field', 'micro', 'experimental') as $k) {
	check("$k chip is a tri-state chip starting neutral",
		preg_match('/class="ms-tab ms-type-chip" data-type="' . $k . '" data-state="neutral"/', $body) === 1);
}
check('Match pill present and hidden by default', preg_match('/id="ms-type-match" hidden/', $body) === 1);
check('Match pill has any + all buttons', preg_match('/data-match="any"/', $body) === 1 && preg_match('/data-match="all"/', $body) === 1);
check('tri-state CSS states shipped', strpos($body, '.ms-type-chip[data-state="include"]') !== false && strpos($body, '.ms-type-chip[data-state="exclude"]') !== false);
check('JS ships parseTypeParam + typeReadout + syncTypeControls',
	strpos($body, 'function parseTypeParam') !== false && strpos($body, 'function typeReadout') !== false && strpos($body, 'function syncTypeControls') !== false);
check('no stale single-value state.type filter left behind', preg_match('/matchesType\(s, state\.type\)/', $body) === 0);

echo PHP_EOL . "== Embedded sample payload" . PHP_EOL;
$ok = preg_match('#<script type="application/json" id="ms-data">(.*?)</script>#s', $body, $m);
check('ms-data JSON block present', $ok === 1);
$data = $ok ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : null;
if (!is_array($data)) $data = $ok ? json_decode($m[1], true) : null;
check('payload decodes to an array', is_array($data));
$byId = array();
foreach ((is_array($data) ? $data : array()) as $s) $byId[$s['id']] = $s;
check('all 8 fixture samples present', count(array_intersect_key($byId, array_flip($ids))) === 8, 'have ' . count($byId));
foreach ($COMBOS as $code => $systems) {
	$s = isset($byId[$ids[$code]]) ? $byId[$ids[$code]] : null;
	$exp = array('field' => 0, 'micro' => 0, 'experimental' => 0);
	foreach ($systems as $sys) $exp[$sys] = $sys === 'micro' ? 2 : 1;
	$got = $s ? array_map('intval', (array)$s['badges']) : null;
	ksort($exp); if (is_array($got)) ksort($got);
	check("badges for '$code' = " . json_encode($exp), $got === $exp, $got === null ? 'missing' : json_encode($got));
}

teardown($sid);

echo PHP_EOL . ($failures ? count($failures) . ' FAILURE(S): ' . implode('; ', $failures) : 'ALL PASS') . PHP_EOL;
exit($failures ? 1 : 0);
