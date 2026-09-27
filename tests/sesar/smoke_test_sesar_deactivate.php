<?php
/**
 * File: tests/sesar/smoke_test_sesar_deactivate.php
 * Description: Phase 7 suite for SESAR deactivation requests (D7 + review
 *              Q1-Q3): SesarDeactivate::preview (may ask? pending adopted;
 *              read-only / other account refused), request (reason rules,
 *              typed-IGSN confirmation, POST, row state), check (still
 *              pending / declined / approved), sweep (anonymous 410 lookups
 *              -> deactivated + IGSN removed from the sample; lookup errors
 *              retried), markGone (unrequested 410 keeps the IGSN text; only
 *              the tracked IGSN is ever cleared), orphans + keep, then
 *              sesar_deactivate.php refusals over HTTP.
 *
 *              Unit part talks ONLY to FakeSesar; the HTTP part makes no
 *              SESAR call. Fixture users 94760-94762, samples "sesardeact-*".
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_deactivate.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarPush.php';
require_once 'includes/sesar/SesarDeactivate.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once __DIR__ . '/FakeSesar.php';

$U = array(94760, 94761, 94762);
$STATE = '/tmp/fake_sesar_deact.json';
$KEY = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$sessionFiles = array();

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function err($fn) {
	try { $fn(); return null; }
	catch (SesarError $e) { return $e; }
}
function cleanup() {
	global $db, $U, $sessionFiles;
	$in = implode(',', array_map('intval', $U));
	$rows = $db->get_results("SELECT id, userpkey FROM strabosamples.samples WHERE userpkey IN ($in)");
	foreach ((is_array($rows) ? $rows : array()) as $r) StraboSearchSync::removeSample($db, $r->id, (int)$r->userpkey);
	$db->query("DELETE FROM strabosamples.sesar_registrations WHERE sample_userpkey IN ($in)");
	$db->query("UPDATE strabosamples.samples SET parent_sample_id = NULL, parent_userpkey = NULL WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sesar_connections WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sesar_vocab_cache WHERE environment = 'sandbox'");   // fake terms must not linger
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
	foreach ($sessionFiles as $f) @unlink($f);
}
function mk($id, $owner, $igsn) {
	global $db;
	$db->prepare_query("INSERT INTO strabosamples.samples (id, userpkey, name, igsn, latitude, longitude, created_by, modified_by)
		VALUES ($1, $2, $1, $3, 38.95, -95.25, $2, $2)", array($id, $owner, $igsn));
}
function track($id, $owner, $igsn, $origin, $access = 'managed', $state = 'active') {
	global $db;
	$db->prepare_query(
		"INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, sesar_code, origin, access, state, active,
		                                               snapshot, snapshot_at, created_by)
		 VALUES ($1, $2, 'sandbox', $3, 'IEFAK', $4, $5, $6, TRUE, $7::jsonb, now(), $2)",
		array($id, $owner, $igsn, $origin, $access, $state, json_encode(array('igsn' => $igsn, 'name' => 'Snap ' . $id))));
}
function row($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.sesar_registrations WHERE sample_id = $1 AND sample_userpkey = $2 ORDER BY pkey DESC LIMIT 1",
		array($id, $owner));
}
function spineIgsn($id, $owner) {
	global $db;
	return $db->get_var_prepared("SELECT igsn FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($id, $owner));
}
function deactCalls() {
	global $fake;
	return array_values(array_filter($fake->calls('/deactivate/'), function ($c) { return $c['method'] === 'POST'; }));
}

cleanup();
foreach ($U as $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Deact', 'Fixture', $2, 'x', 'x', TRUE, FALSE)",
		array($u, "sesardeact$u@test.strabospot.org"));
}

try {

SesarAccess::setEnvironmentForTests('sandbox');
$fake = new FakeSesar($STATE, true);
$client = new SesarClient('sandbox', $fake);
$conn = new SesarConnection($db, $client, $KEY);
$deact = new SesarDeactivate($db, $client, $conn, SesarDeactivate::serviceIgsnClearer($db, null));
$views = new SesarSampleView($db, null, function () { return null; });
$pull = new SesarPull($db, $client, $conn, $views, null);
$push = new SesarPush($db, $client, $conn, $views, new SesarMint($db, $client, $conn, new SesarVocab($db, $client), $views, null), $pull);

$A = $U[0]; $B = $U[1];
$ORCID_A = '0000-0001-0000-0060';
$fake->addOrcidUser('idtok-A', $ORCID_A, true, array('IEFAK'));
$conn->connectWithOrcid($A, 'idtok-A', $ORCID_A);
$base = array('_owner' => $ORCID_A, 'sesar_code' => 'IEFAK', 'latitude' => '38.95000000', 'longitude' => '-95.25000000');
foreach (array('0201', '0202', '0205', '0206', '0207', '0208') as $n) {
	$fake->seedSample('10.58052/IEFAK' . $n, array_merge($base, array('name' => 'Rec ' . $n)));
}
$fake->seedSample('10.58052/IEFAK0203', array('name' => 'Theirs', '_owner' => 'someone-else'));
$fake->seedSample('10.58052/IEFAK0204', array('name' => 'Theirs too', '_owner' => 'someone-else'));

mk('sesardeact-m', $A, '10.58052/IEFAK0201'); track('sesardeact-m', $A, '10.58052/IEFAK0201', 'minted');
mk('sesardeact-l', $A, 'IEFAK0202');         track('sesardeact-l', $A, '10.58052/IEFAK0202', 'linked');
mk('sesardeact-ro', $A, '10.58052/IEFAK0203'); track('sesardeact-ro', $A, '10.58052/IEFAK0203', 'linked', 'readonly');
mk('sesardeact-x', $A, '10.58052/IEFAK0204'); track('sesardeact-x', $A, '10.58052/IEFAK0204', 'linked');   // stale "managed"

// ===========================================================================
section('preview');
$p = $deact->preview($A, array('sample_id' => 'sesardeact-m'));
check('ready: IGSN, landing link, SESAR\'s 4 reasons, sample name', $p['state'] === 'ready' && $p['igsn'] === '10.58052/IEFAK0201'
	&& count($p['reasons']) === 4 && $p['name'] === 'sesardeact-m' && $p['orphan'] === false && strpos($p['landing_url'], 'IEFAK0201') !== false, $p);
check('preview sends nothing', count(deactCalls()) === 0);
$e = err(function () use ($deact, $A) { $deact->preview($A, array('sample_id' => 'sesardeact-ro')); });
check('read-only link -> 409, no SESAR call', $e !== null && $e->status === 409 && strpos($e->getMessage(), 'read-only') !== false);
$e = err(function () use ($deact, $A) { $deact->preview($A, array('sample_id' => 'sesardeact-x')); });
check('SESAR says another account owns it -> 403', $e !== null && $e->status === 403, $e ? $e->getMessage() : null);
$e = err(function () use ($deact, $B) { $deact->preview($B, array('sample_id' => 'sesardeact-m')); });
check("someone else's sample -> 404", $e !== null && $e->status === 404);
mk('sesardeact-none', $A, null);
$e = err(function () use ($deact, $A) { $deact->preview($A, array('sample_id' => 'sesardeact-none')); });
check('no registration -> 404 plain message', $e !== null && $e->status === 404 && strpos($e->getMessage(), 'not registered') !== false);

// ===========================================================================
section('request: validation (nothing sent)');
$t = array('sample_id' => 'sesardeact-m');
$cases = array(
	'no reason'                    => array('reason' => '', 'confirm' => 'IEFAK0201'),
	'unknown reason'               => array('reason' => 'bored', 'confirm' => 'IEFAK0201'),
	'other without text'           => array('reason' => 'other', 'detail' => '  ', 'confirm' => 'IEFAK0201'),
	'other over 250'               => array('reason' => 'other', 'detail' => str_repeat('x', 251), 'confirm' => 'IEFAK0201'),
	'duplicate without IGSNs'      => array('reason' => 'duplicate igsn', 'confirm' => 'IEFAK0201'),
	'typed IGSN does not match'    => array('reason' => 'this was a test sample', 'confirm' => 'IEFAK0202'),
	'typed IGSN empty'             => array('reason' => 'this was a test sample', 'confirm' => ''),
);
foreach ($cases as $name => $in) {
	$e = err(function () use ($deact, $A, $t, $in) { $deact->request($A, $t, $in); });
	check($name . ' -> 400', $e !== null && $e->status === 400, $e ? $e->getMessage() : null);
}
check('nothing was sent to SESAR', count(deactCalls()) === 0);

// ===========================================================================
section('request: sent');
$r = $deact->request($A, $t, array('reason' => 'this was a test sample', 'detail' => 'ignored for this reason', 'confirm' => ' iefak0201 '));
$row = row('sesardeact-m', $A);
$calls = deactCalls();
check('typed bare lowercase IGSN accepted; one POST with SESAR\'s reason only', $r['state'] === 'requested' && count($calls) === 1
	&& json_decode($calls[0]['body'], true) === array('deactivate_reason' => 'this was a test sample'), array($r, $calls[0]['body'] ?? null));
check('row: deactivation_requested, reason + date stored, still active', $row->state === 'deactivation_requested' && $row->active === 't'
	&& $row->deactivation_reason === 'this was a test sample' && $row->deactivation_requested_at !== null && $row->deactivation_detail === null);
check('sample untouched until SESAR approves', spineIgsn('sesardeact-m', $A) === '10.58052/IEFAK0201');
check('Send to SESAR is blocked while requested', $push->status($A, 'sesardeact-m')['pushable'] === false);
$e = err(function () use ($deact, $A, $t) { $deact->request($A, $t, array('reason' => 'other', 'detail' => 'again', 'confirm' => 'IEFAK0201')); });
check('asking again -> 409 already requested, nothing sent', $e !== null && $e->status === 409 && count(deactCalls()) === 1);

$r = $deact->request($A, array('sample_id' => 'sesardeact-l'), array('reason' => 'duplicate igsn', 'detail' => '10.58052/IEFAK0201', 'confirm' => 'https://doi.org/10.58052/IEFAK0202'));
$calls = deactCalls();
check('pulled (linked) managed IGSN may be deactivated (Q3); duplicate list sent; DOI URL accepted as confirmation',
	$r['state'] === 'requested' && json_decode(end($calls)['body'], true) === array('deactivate_reason' => 'duplicate igsn', 'duplicate_igsns' => '10.58052/IEFAK0201')
	&& row('sesardeact-l', $A)->deactivation_detail === '10.58052/IEFAK0201', end($calls));

// A request already pending at SESAR (made on SESAR's site) is adopted, not an error.
mk('sesardeact-p', $A, '10.58052/IEFAK0205'); track('sesardeact-p', $A, '10.58052/IEFAK0205', 'minted');
$fake->editAtSesar('10.58052/IEFAK0205', array('deactivation_requested' => array('deactivate_reason' => 'other')));
$p = $deact->preview($A, array('sample_id' => 'sesardeact-p'));
check('already pending at SESAR -> preview says so, row adopted (reason unknown)', $p['state'] === 'pending'
	&& row('sesardeact-p', $A)->state === 'deactivation_requested' && row('sesardeact-p', $A)->deactivation_reason === null, $p);

// ===========================================================================
section('check with SESAR');
$c = $deact->check($A, array('sample_id' => 'sesardeact-m'));
check('still pending -> requested', $c['state'] === 'requested' && row('sesardeact-m', $A)->state === 'deactivation_requested', $c);
$fake->denyDeactivation('10.58052/IEFAK0201');
$c = $deact->check($A, array('sample_id' => 'sesardeact-m'));
$row = row('sesardeact-m', $A);
check('curator declined -> back to active, declined date stored', $c['state'] === 'declined' && $row->state === 'active'
	&& $row->deactivation_declined_at !== null && spineIgsn('sesardeact-m', $A) === '10.58052/IEFAK0201', $c);
$e = err(function () use ($deact, $A) { $deact->check($A, array('sample_id' => 'sesardeact-m')); });
check('check with nothing pending -> 409', $e !== null && $e->status === 409);
$deact->request($A, array('sample_id' => 'sesardeact-m'), array('reason' => 'other', 'detail' => 'Asked again', 'confirm' => 'IEFAK0201'));
check('may ask again after a denial; declined date cleared', row('sesardeact-m', $A)->state === 'deactivation_requested'
	&& row('sesardeact-m', $A)->deactivation_declined_at === null && row('sesardeact-m', $A)->deactivation_detail === 'Asked again');

// ===========================================================================
section('nightly sweep (approvals)');
$fake->markDeactivated('10.58052/IEFAK0201');   // curator approved
$fake->set('fail_next', array('samples/by-igsn/' => 500));   // night 1: the first lookup (the approved one) fails
$s = $deact->sweep();
check('night 1: 3 pending checked, lookup error counted, nothing changed', $s === array('checked' => 3, 'deactivated' => 0, 'errors' => 1)
	&& row('sesardeact-m', $A)->state === 'deactivation_requested', $s);
$s = $deact->sweep();
$row = row('sesardeact-m', $A);
check('night 2: retried, 1 deactivated', $s === array('checked' => 3, 'deactivated' => 1, 'errors' => 0), $s);
check('approved row: deactivated, inactive (history), date set', $row->state === 'deactivated' && $row->active === 'f' && $row->deactivated_at !== null);
check('our request approved -> IGSN removed from the sample (D7)', spineIgsn('sesardeact-m', $A) === null);
$log = $db->get_var_prepared("SELECT changes::text FROM strabosamples.sample_changelog WHERE sample_id = 'sesardeact-m' AND sample_userpkey = $1
	ORDER BY pkey DESC LIMIT 1", array($A));
check('the old IGSN is kept in the sample history', $log !== null && strpos($log, 'IEFAK0201') !== false, $log);
check('still pending at SESAR: those rows stay requested', row('sesardeact-p', $A)->state === 'deactivation_requested'
	&& row('sesardeact-l', $A)->state === 'deactivation_requested');
check('a new IGSN may be registered (no live row left)', $db->get_var_prepared("SELECT count(*) FROM strabosamples.sesar_registrations
	WHERE sample_id = 'sesardeact-m' AND sample_userpkey = $1 AND active", array($A)) === '0');

// Only the tracked IGSN is ever cleared: the user retyped the field meanwhile.
mk('sesardeact-t', $A, '10.58052/IEFAK0206'); track('sesardeact-t', $A, '10.58052/IEFAK0206', 'minted');
$deact->request($A, array('sample_id' => 'sesardeact-t'), array('reason' => 'this was a test sample', 'confirm' => 'IEFAK0206'));
$db->query("UPDATE strabosamples.samples SET igsn = 'my own note' WHERE id = 'sesardeact-t' AND userpkey = $A");
$fake->markDeactivated('10.58052/IEFAK0206');
$deact->sweep();
check('field no longer holds the tracked IGSN -> left alone', row('sesardeact-t', $A)->state === 'deactivated' && spineIgsn('sesardeact-t', $A) === 'my own note');

// ===========================================================================
section('410 we did not request (Build Plan Phase 7 note)');
mk('sesardeact-u', $A, '10.58052/IEFAK0207'); track('sesardeact-u', $A, '10.58052/IEFAK0207', 'linked');
$fake->markDeactivated('10.58052/IEFAK0207');
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesardeact-u'); });
$row = row('sesardeact-u', $A);
check('a pull meets the 410 -> row deactivated, IGSN text KEPT', $e !== null && $e->status === 410 && $row->state === 'deactivated'
	&& $row->active === 'f' && spineIgsn('sesardeact-u', $A) === '10.58052/IEFAK0207');

// ===========================================================================
section('orphans: tracked IGSNs whose sample was deleted (Q2)');
track('sesardeact-gone1', $A, '10.58052/IEFAK0208', 'minted');
track('sesardeact-gone2', $A, '10.58052/IEFAK0202x', 'minted');
$o = $deact->orphans($A);
$byIgsn = array();
foreach ($o as $x) $byIgsn[$x['igsn']] = $x;
check('orphans: rows without a sample, with the snapshot name', count($o) === 2 && $byIgsn['10.58052/IEFAK0208']['name'] === 'Snap sesardeact-gone1'
	&& !isset($byIgsn['10.58052/IEFAK0201']), $o);
$reg1 = $byIgsn['10.58052/IEFAK0208']['reg'];
$reg2 = $byIgsn['10.58052/IEFAK0202x']['reg'];
$p = $deact->preview($A, array('reg' => $reg1));
check('orphan preview: ready, marked orphan', $p['state'] === 'ready' && $p['orphan'] === true, $p);
$r = $deact->request($A, array('reg' => $reg1), array('reason' => 'this sample does not exist', 'confirm' => 'IEFAK0208'));
check('orphan request sent', $r['state'] === 'requested');
$o = $deact->orphans($A);
check('still listed, now as requested', count($o) === 2 && in_array('deactivation_requested', array_map(function ($x) { return $x['state']; }, $o), true));
$k = $deact->keepOrphan($A, $reg2);
check('Keep -> no longer listed', $k['ok'] === true && count($deact->orphans($A)) === 1);
$e = err(function () use ($deact, $A) {
	global $db;
	$deact->preview($A, array('reg' => (int)$db->get_var("SELECT pkey FROM strabosamples.sesar_registrations WHERE sample_id = 'sesardeact-l'")));
});
check('a row whose sample exists is not an orphan target -> 404', $e !== null && $e->status === 404);
$e = err(function () use ($deact, $B, $reg1) { $deact->keepOrphan($B, $reg1); });
check("someone else's orphan -> 404", $e !== null && $e->status === 404);
$fake->markDeactivated('10.58052/IEFAK0208');
$c = $deact->check($A, array('reg' => $reg1));
check('orphan approval: deactivated, list empty of it (no sample to change)', $c['state'] === 'deactivated' && count($deact->orphans($A)) === 0, $c);

// ===========================================================================
section('busy');
$db->query("UPDATE strabosamples.sesar_registrations SET state = 'active' WHERE sample_id = 'sesardeact-p' AND sample_userpkey = $A");
$fake->denyDeactivation('10.58052/IEFAK0205');
$pg2 = pg_connect("host=$dbhost dbname=$dbname user=$dbusername password=$dbpassword", PGSQL_CONNECT_FORCE_NEW);
pg_query_params($pg2, "SELECT pg_advisory_lock($1, hashtext($2))", array(SesarMint::LOCK_NS, $A . ':sesardeact-p'));
$e = err(function () use ($deact, $A) { $deact->request($A, array('sample_id' => 'sesardeact-p'), array('reason' => 'this was a test sample', 'confirm' => 'IEFAK0205')); });
check('a mint / pull / push of the same sample in flight -> busy (shared lock key)', $e !== null && isset($e->errors['busy']));
pg_close($pg2);

// ===========================================================================
section('HTTP: sesar_deactivate.php refusals (forged sessions, no SESAR calls)');
function forgeSession($pkey) {
	global $sessionFiles;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = '/var/lib/php/sessions/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600); @chown($path, 'www-data'); @chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}
function http($method, $path, $sid, $json = null) {
	$ch = curl_init('http://localhost' . $path);
	$h = array();
	if ($sid !== null) $h[] = 'Cookie: PHPSESSID=' . $sid;
	$o = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30);
	if ($json !== null) { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = is_string($json) ? $json : json_encode($json); }
	$o[CURLOPT_HTTPHEADER] = $h;
	curl_setopt_array($ch, $o);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('status' => $code, 'body' => $body, 'json' => json_decode($body, true));
}
$pilot = forgeSession(3);
$other = forgeSession($A);
$r = http('POST', '/sesar_deactivate.php', null, array('action' => 'preview', 'sample_id' => 'x'));
check('no session -> 401', $r['status'] === 401 && $r['json']['error'] === 'not_authenticated', $r['body']);
$r = http('POST', '/sesar_deactivate.php', $other, array('action' => 'preview', 'sample_id' => 'sesardeact-l'));
check('non-pilot -> 403 (server-side gate)', $r['status'] === 403 && $r['json']['error'] === 'not_allowed', $r['body']);
$r = http('GET', '/sesar_deactivate.php', $pilot);
check('GET -> 405', $r['status'] === 405);
$r = http('POST', '/sesar_deactivate.php', $pilot, array('action' => 'explode'));
check('unknown action -> 400', $r['status'] === 400 && $r['json']['error'] === 'unknown_action');
$r = http('POST', '/sesar_deactivate.php', $pilot, array('action' => 'request', 'sample_id' => 'sesardeact-l', 'reason' => 'other', 'detail' => 'x', 'confirm' => 'IEFAK0202'));
check("pilot asking about someone else's sample -> 404", $r['status'] === 404 && $r['json']['error'] === 'not_found', $r['body']);
$r = http('POST', '/sesar_deactivate.php', $pilot, array('action' => 'keep', 'reg' => $reg2));
check("pilot keeping someone else's orphan -> 404", $r['status'] === 404, $r['body']);

} finally {
	SesarAccess::setEnvironmentForTests(null);
	cleanup();
	@unlink($STATE);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
