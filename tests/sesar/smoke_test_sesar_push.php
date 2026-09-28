<?php
/**
 * File: tests/sesar/smoke_test_sesar_push.php
 * Description: Phase 6 suite for Send to SESAR (D6 + review P1-P4):
 *              SesarPush::status (no SESAR call; changed = owned fields vs
 *              the SNAPSHOT; refusals), preview (differing fields only,
 *              pull-first by content), apply (review mode must match what
 *              was shown; bulk; PATCH carries only differing fields; never
 *              clears; never external_sample_id on a linked row; parent
 *              IGSN; link back added by link-samples, failure = note; 403 /
 *              410 / busy), then sesar_push.php refusals over HTTP.
 *
 *              Unit part talks ONLY to FakeSesar; the HTTP part makes no
 *              SESAR call. Fixture users 94750-94752, samples "sesarpush-*".
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_push.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarPush.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once __DIR__ . '/FakeSesar.php';

$U = array(94750, 94751, 94752);
$STATE = '/tmp/fake_sesar_push.json';
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

/** A fixture sample. $f: name, igsn, lat, lon, description, purpose, parent. */
function mk($id, $owner, array $f = array()) {
	global $db;
	$db->prepare_query(
		"INSERT INTO strabosamples.samples (id, userpkey, name, igsn, latitude, longitude, description, display_sample_purpose,
		                                   parent_sample_id, parent_userpkey, created_by, modified_by)
		 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $2, $2)",
		array($id, $owner, array_key_exists('name', $f) ? $f['name'] : $id, isset($f['igsn']) ? $f['igsn'] : null,
		      array_key_exists('lat', $f) ? $f['lat'] : 38.95, array_key_exists('lon', $f) ? $f['lon'] : -95.25,
		      isset($f['description']) ? $f['description'] : null, isset($f['purpose']) ? $f['purpose'] : null,
		      isset($f['parent']) ? $f['parent'] : null, isset($f['parent']) ? $owner : null)
	);
}
function setSpine($id, $owner, $col, $val) {
	global $db;
	$db->prepare_query("UPDATE strabosamples.samples SET $col = $1 WHERE id = $2 AND userpkey = $3", array($val, $id, $owner));
}
/** Tracking row whose snapshot is what SESAR returns right now (as a pull or mint leaves it). */
function track($id, $owner, $igsn, $origin, array $extra = array()) {
	global $db, $pull;
	$snap = $pull->fetch($owner, $igsn)['record'];
	$db->prepare_query(
		"INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, sesar_code, origin, access, state, active,
		                                               snapshot, snapshot_at, sesar_sample_id, related_resource_id, created_by)
		 VALUES ($1, $2, 'sandbox', $3, 'IEFAK', $4, $5, $6, $7, $8::jsonb, now(), $9, $10, $2)",
		array($id, $owner, $igsn, $origin, isset($extra['access']) ? $extra['access'] : 'managed',
		      isset($extra['state']) ? $extra['state'] : 'active', 't', json_encode($snap),
		      isset($snap['sample_id']) ? $snap['sample_id'] : null,
		      array_key_exists('rr', $extra) ? $extra['rr'] : ($origin === 'minted' ? 555 : null))
	);
}
function reg($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.sesar_registrations WHERE sample_id = $1 AND sample_userpkey = $2 AND active", array($id, $owner));
}
function patches($igsn) {
	global $fake;
	return array_values(array_filter($fake->calls('samples/' . $igsn . '/'), function ($c) { return $c['method'] === 'PATCH'; }));
}
function seenOf(array $preview) {
	$s = array();
	foreach ($preview['rows'] as $r) $s[$r['field']] = $r['send'];
	return $s;
}
function atSesar($igsn) {
	global $fake;
	return $fake->state()['samples'][$igsn];
}

cleanup();
foreach ($U as $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Push', 'Fixture', $2, 'x', 'x', TRUE, FALSE)",
		array($u, "sesarpush$u@test.strabospot.org"));
}

try {

SesarAccess::setEnvironmentForTests('sandbox');
$fake = new FakeSesar($STATE, true);
$client = new SesarClient('sandbox', $fake);
$conn = new SesarConnection($db, $client, $KEY);
$views = new SesarSampleView($db, null, function () { return null; });
$pull = new SesarPull($db, $client, $conn, $views, null);
$mint = new SesarMint($db, $client, $conn, new SesarVocab($db, $client), $views, function () { return true; });
$push = new SesarPush($db, $client, $conn, $views, $mint, $pull);

$A = $U[0]; $B = $U[1];
$ORCID_A = '0000-0001-0000-0050';
$fake->addOrcidUser('idtok-A', $ORCID_A, true, array('IEFAK'));
$conn->connectWithOrcid($A, 'idtok-A', $ORCID_A);
$base = array('_owner' => $ORCID_A, 'sesar_code' => 'IEFAK', 'latitude' => '38.95000000', 'longitude' => '-95.25000000',
	'object_type' => 'General sample types > Individual sample', 'general_material_type' => 'Granite');

// A minted sample in step with SESAR, a linked one from a lab spreadsheet.
$fake->seedSample('10.58052/IEFAK0101', array_merge($base, array('name' => 'Minted', 'external_sample_id' => 'sesarpush-m',
	'sample_description' => 'Same text', 'purpose' => null)));
mk('sesarpush-m', $A, array('name' => 'Minted', 'igsn' => '10.58052/IEFAK0101', 'description' => 'Same text'));
track('sesarpush-m', $A, '10.58052/IEFAK0101', 'minted');
$fake->seedSample('10.58052/IEFAK0102', array_merge($base, array('name' => 'Lab sample', 'external_sample_id' => 'LAB-7',
	'sample_description' => 'Lab description')));
mk('sesarpush-l', $A, array('name' => 'Lab sample', 'igsn' => 'IEFAK0102', 'description' => 'Lab description'));
track('sesarpush-l', $A, '10.58052/IEFAK0102', 'linked');

// ===========================================================================
section('status (no SESAR call)');
$n0 = count($fake->calls());
mk('sesarpush-none', $A);
$s = $push->status($A, 'sesarpush-none');
check('no registration -> not pushable, plain reason', $s['pushable'] === false && strpos($s['reason'], 'Register IGSN or Pull') !== false, $s);
$s = $push->status($A, 'sesarpush-m');
check('minted, in step with SESAR -> not changed', $s['pushable'] === true && $s['changed'] === false && $s['fields'] === array(), $s);
$s = $push->status($A, 'sesarpush-l');
check('linked: the lab external id (LAB-7) is never a difference (P3)', $s['changed'] === false, $s);
setSpine('sesarpush-m', $A, 'name', 'Minted, renamed');
$s = $push->status($A, 'sesarpush-m');
check('rename -> changed, names the field', $s['changed'] === true && $s['fields'] === array('Name'), $s);
check('status made no SESAR call', count($fake->calls()) === $n0);
$e = err(function () use ($push, $B) { $push->status($B, 'sesarpush-m'); });
check("someone else's sample -> 404", $e !== null && $e->status === 404);

$fake->seedSample('10.58052/IEFAK0103', array_merge($base, array('name' => 'Theirs', '_owner' => 'someone-else')));
mk('sesarpush-ro', $A, array('name' => 'Theirs changed', 'igsn' => '10.58052/IEFAK0103'));
track('sesarpush-ro', $A, '10.58052/IEFAK0103', 'linked', array('access' => 'readonly'));
$s = $push->status($A, 'sesarpush-ro');
check('read-only link -> not pushable', $s['pushable'] === false && strpos($s['reason'], 'read-only') !== false, $s);

// ===========================================================================
section('preview + apply: review mode');
$p = $push->preview($A, 'sesarpush-m');
check('preview: only the differing field, SESAR now vs will send', count($p['rows']) === 1 && $p['rows'][0]['field'] === 'name'
	&& $p['rows'][0]['sesar'] === 'Minted' && $p['rows'][0]['send'] === 'Minted, renamed' && $p['blocked'] === false && $p['link_back'] === false, $p);
check('preview sends nothing', count(patches('10.58052/IEFAK0101')) === 0);

$e = err(function () use ($push, $A) { $push->apply($A, 'sesarpush-m', array('mode' => 'review', 'seen' => array())); });
check('review apply without the shown values -> 409 stale, nothing sent', $e !== null && $e->status === 409 && isset($e->errors['stale'])
	&& count(patches('10.58052/IEFAK0101')) === 0);
$seen = seenOf($p);
setSpine('sesarpush-m', $A, 'description', 'Edited after the review opened');
$e = err(function () use ($push, $A, $seen) { $push->apply($A, 'sesarpush-m', array('mode' => 'review', 'seen' => $seen)); });
check('sample edited after the review opened -> 409 stale, nothing sent', $e !== null && isset($e->errors['stale']) && count(patches('10.58052/IEFAK0101')) === 0);

$p = $push->preview($A, 'sesarpush-m');
$res = $push->apply($A, 'sesarpush-m', array('mode' => 'review', 'seen' => seenOf($p)));
$calls = patches('10.58052/IEFAK0101');
$body = json_decode($calls[0]['body'], true);
check('apply: ONE PATCH with exactly the differing fields', count($calls) === 1 && array_keys($body) === array('name', 'sample_description')
	&& $body['name'] === 'Minted, renamed', $body);
check('result names what was sent', $res['sent'] === array('Name', 'Description') && $res['link_back_added'] === false, $res);
check('SESAR now holds the values', atSesar('10.58052/IEFAK0101')['name'] === 'Minted, renamed'
	&& atSesar('10.58052/IEFAK0101')['sample_description'] === 'Edited after the review opened');
$r = reg('sesarpush-m', $A);
$snap = json_decode($r->snapshot, true);
check('snapshot = SESAR answer, list-only keys kept, pushed_at set', $snap['name'] === 'Minted, renamed'
	&& $snap['external_sample_id'] === 'sesarpush-m' && $r->pushed_at !== null, $snap);
check('status after the push: not changed', $push->status($A, 'sesarpush-m')['changed'] === false);

// ===========================================================================
section('pull first (P2: content check)');
setSpine('sesarpush-m', $A, 'name', 'Mine again');
$fake->editAtSesar('10.58052/IEFAK0101', array('name' => 'Curator fixed it'));
$p = $push->preview($A, 'sesarpush-m');
check('field we would send was edited at SESAR -> blocked, row flagged', $p['blocked'] === true && $p['rows'][0]['conflict'] === true
	&& $p['rows'][0]['sesar'] === 'Curator fixed it', $p);
$n = count(patches('10.58052/IEFAK0101'));
$e = err(function () use ($push, $A, $p) { $push->apply($A, 'sesarpush-m', array('mode' => 'review', 'seen' => seenOf($p))); });
check('review apply -> 409 pull first, names the field, nothing sent', $e !== null && $e->status === 409 && ($e->errors['pull_first'] ?? null) === array('name')
	&& strpos($e->getMessage(), 'Pull from SESAR first') !== false && count(patches('10.58052/IEFAK0101')) === $n, $e ? $e->getMessage() : null);
$e = err(function () use ($push, $A) { $push->apply($A, 'sesarpush-m', array('mode' => 'bulk')); });
check('bulk apply -> same refusal (row skipped)', $e !== null && isset($e->errors['pull_first']) && count(patches('10.58052/IEFAK0101')) === $n);
check('SESAR keeps the curator edit', atSesar('10.58052/IEFAK0101')['name'] === 'Curator fixed it');
// A pull brings the edit in; then an unrelated SESAR-side edit does not block.
$pull->apply($A, 'sesarpush-m', array('mode' => 'overwrite'));
check('after a pull the sample matches SESAR -> not changed', $push->status($A, 'sesarpush-m')['changed'] === false,
	$push->status($A, 'sesarpush-m'));
$fake->editAtSesar('10.58052/IEFAK0101', array('general_material_type' => 'Basalt', 'locality' => 'Edited locality'));
setSpine('sesarpush-m', $A, 'display_sample_purpose', 'Teaching');
$res = $push->apply($A, 'sesarpush-m', array('mode' => 'bulk'));
check('SESAR edits to fields we never send do not block; bulk sends', $res['sent'] === array('Purpose')
	&& atSesar('10.58052/IEFAK0101')['purpose'] === 'Teaching' && atSesar('10.58052/IEFAK0101')['general_material_type'] === 'Basalt', $res);

// ===========================================================================
section('what is never sent');
setSpine('sesarpush-l', $A, 'description', 'Lab description, corrected');
$res = $push->apply($A, 'sesarpush-l', array('mode' => 'bulk'));
$body = json_decode(patches('10.58052/IEFAK0102')[0]['body'], true);
check('linked row: description sent, external_sample_id NEVER (lab id stays LAB-7)', array_keys($body) === array('sample_description')
	&& atSesar('10.58052/IEFAK0102')['external_sample_id'] === 'LAB-7', $body);
$fake->seedSample('10.58052/IEFAK0104', array_merge($base, array('name' => 'Has more at SESAR', 'external_sample_id' => 'sesarpush-c',
	'sample_description' => 'Only SESAR has this', 'purpose' => 'Only SESAR purpose')));
mk('sesarpush-c', $A, array('name' => 'Has more at SESAR', 'igsn' => '10.58052/IEFAK0104'));
track('sesarpush-c', $A, '10.58052/IEFAK0104', 'minted');
$s = $push->status($A, 'sesarpush-c');
check('empty fields here never clear SESAR values -> not changed', $s['changed'] === false, $s);
check('no collectors / material / object type key can ever be sent', count(array_intersect(array('collectors', 'general_material_type', 'object_type', 'related_resources'),
	SesarMapper::PUSH_FIELDS)) === 0);

// ===========================================================================
section('parent IGSN');
mk('sesarpush-kid', $A, array('name' => 'Kid', 'igsn' => '10.58052/IEFAK0105', 'parent' => 'sesarpush-m'));
$fake->seedSample('10.58052/IEFAK0105', array_merge($base, array('name' => 'Kid', 'external_sample_id' => 'sesarpush-kid')));
track('sesarpush-kid', $A, '10.58052/IEFAK0105', 'minted');
$s = $push->status($A, 'sesarpush-kid');
check('our parent is registered, SESAR has none -> changed: Parent IGSN', $s['fields'] === array('Parent IGSN'), $s);
$push->apply($A, 'sesarpush-kid', array('mode' => 'bulk'));
check('parent_sample sent as the parent registration IGSN', atSesar('10.58052/IEFAK0105')['parent_sample'] === '10.58052/IEFAK0101');

// The parent's IGSN FIELD holds a well-formed IGSN SESAR does not know (no
// registration here): status and the send review must agree.
mk('sesarpush-ghostdad', $A, array('name' => 'Ghost dad', 'igsn' => '10.58052/IEFAK0999'));
mk('sesarpush-kid2', $A, array('name' => 'Kid 2', 'igsn' => '10.58052/IEFAK0107', 'parent' => 'sesarpush-ghostdad'));
$fake->seedSample('10.58052/IEFAK0107', array_merge($base, array('name' => 'Kid 2', 'external_sample_id' => 'sesarpush-kid2')));
track('sesarpush-kid2', $A, '10.58052/IEFAK0107', 'minted');
$s = $push->status($A, 'sesarpush-kid2');
$p = $push->preview($A, 'sesarpush-kid2');
check('parent IGSN field unknown to SESAR: status says not changed, as the review has nothing to send',
	$s['changed'] === false && $s['fields'] === array() && $p['rows'] === array(), array($s, $p['rows']));
// The same field holding an IGSN SESAR knows: status stays quiet (no SESAR call), the review sends it.
$fake->seedSample('10.58052/IEFAK0999', array_merge($base, array('name' => 'Ghost dad')));
$s = $push->status($A, 'sesarpush-kid2');
$p = $push->preview($A, 'sesarpush-kid2');
check('parent IGSN field known to SESAR but not linked here: the review still offers it',
	$s['changed'] === false && count($p['rows']) === 1 && $p['rows'][0]['field'] === 'parent_sample' && $p['rows'][0]['send'] === '10.58052/IEFAK0999', array($s, $p['rows']));
$push->apply($A, 'sesarpush-kid2', array('mode' => 'bulk'));
$s = $push->status($A, 'sesarpush-kid2');
check('after sending: parent stored at SESAR, status not changed', atSesar('10.58052/IEFAK0107')['parent_sample'] === '10.58052/IEFAK0999' && $s['changed'] === false, $s);

// ===========================================================================
section('link back (minted IGSN without one)');
$fake->seedSample('10.58052/IEFAK0106', array_merge($base, array('name' => 'No link', 'external_sample_id' => 'sesarpush-nl')));
mk('sesarpush-nl', $A, array('name' => 'No link', 'igsn' => '10.58052/IEFAK0106'));
track('sesarpush-nl', $A, '10.58052/IEFAK0106', 'minted', array('rr' => null));
$s = $push->status($A, 'sesarpush-nl');
check('status: not changed, but link back missing', $s['changed'] === false && $s['link_back'] === true, $s);
check('preview offers it', $push->preview($A, 'sesarpush-nl')['link_back'] === true);
$fake->set('fail_next', array('related-resources/' => 500));
$res = $push->apply($A, 'sesarpush-nl', array('mode' => 'review', 'seen' => array()));
check('link back failure = note, push still ok, still missing', $res['ok'] === true && $res['link_back_added'] === false
	&& count($res['notes']) === 1 && reg('sesarpush-nl', $A)->related_resource_id === null, $res);
$res = $push->apply($A, 'sesarpush-nl', array('mode' => 'review', 'seen' => array()));
$r = reg('sesarpush-nl', $A);
$rr = $fake->state()['related'][(string)$r->related_resource_id] ?? null;
check('link back added: resource for the page URL, linked by SESAR sample id, id stored',
	$res['link_back_added'] === true && $rr !== null && strpos($rr['uri'], '/samples/' . $A . '/sesarpush-nl') !== false
	&& ($rr['_samples'] ?? null) === array((int)$r->sesar_sample_id) && count(patches('10.58052/IEFAK0106')) === 0, array($res, $rr));
check('status: link back no longer missing', $push->status($A, 'sesarpush-nl')['link_back'] === false);
check('a linked (pulled) row never gets a link back', $push->status($A, 'sesarpush-l')['link_back'] === false);

// ===========================================================================
section('refusals');
$e = err(function () use ($push, $A) { $push->preview($A, 'sesarpush-ro'); });
check('read-only link -> 409, no SESAR call', $e !== null && $e->status === 409 && strpos($e->getMessage(), 'read-only') !== false);
$fake->seedSample('10.58052/IEFAK0107', array_merge($base, array('name' => 'Lost edit', '_owner' => 'someone-else')));
mk('sesarpush-lost', $A, array('name' => 'Lost edit here', 'igsn' => '10.58052/IEFAK0107'));
track('sesarpush-lost', $A, '10.58052/IEFAK0107', 'linked');
$e = err(function () use ($push, $A) { $push->preview($A, 'sesarpush-lost'); });
check('SESAR says can_edit false -> 403 plain message', $e !== null && $e->status === 403 && strpos($e->getMessage(), 'cannot edit') !== false,
	$e ? $e->getMessage() : null);
$fake->seedSample('10.58052/IEFAK0108', array_merge($base, array('name' => 'Gone', 'external_sample_id' => 'sesarpush-gone')));
mk('sesarpush-gone', $A, array('name' => 'Gone changed', 'igsn' => '10.58052/IEFAK0108'));
track('sesarpush-gone', $A, '10.58052/IEFAK0108', 'minted');
$fake->markDeactivated('10.58052/IEFAK0108');
$e = err(function () use ($push, $A) { $push->apply($A, 'sesarpush-gone', array('mode' => 'bulk')); });
check('deactivated at SESAR -> 410', $e !== null && $e->status === 410);
$g = $db->get_row_prepared("SELECT state, active FROM strabosamples.sesar_registrations WHERE sample_id = 'sesarpush-gone' AND sample_userpkey = $1", array($A));
check('the 410 is recorded (Phase 7): row deactivated, IGSN text kept (not our request)', $g->state === 'deactivated' && $g->active === 'f'
	&& $db->get_var_prepared("SELECT igsn FROM strabosamples.samples WHERE id = 'sesarpush-gone' AND userpkey = $1", array($A)) === '10.58052/IEFAK0108');
$fake->seedSample('10.58052/IEFAK0109', array_merge($base, array('name' => 'Asked', 'external_sample_id' => 'sesarpush-asked')));
mk('sesarpush-asked', $A, array('name' => 'Asked changed', 'igsn' => '10.58052/IEFAK0109'));
track('sesarpush-asked', $A, '10.58052/IEFAK0109', 'minted', array('state' => 'deactivation_requested'));
$s = $push->status($A, 'sesarpush-asked');
check('deactivation requested -> not pushable', $s['pushable'] === false && strpos($s['reason'], 'deactivation request') !== false, $s);

$pg2 = pg_connect("host=$dbhost dbname=$dbname user=$dbusername password=$dbpassword", PGSQL_CONNECT_FORCE_NEW);
pg_query_params($pg2, "SELECT pg_advisory_lock($1, hashtext($2))", array(SesarMint::LOCK_NS, $A . ':sesarpush-l'));
$e = err(function () use ($push, $A) { $push->apply($A, 'sesarpush-l', array('mode' => 'bulk')); });
check('a mint / pull / push of the same sample in flight -> busy', $e !== null && isset($e->errors['busy']));
pg_close($pg2);

// ===========================================================================
section('HTTP: sesar_push.php refusals (forged sessions, no SESAR calls)');
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
$r = http('POST', '/sesar_push.php', null, array('action' => 'status', 'sample_id' => 'x'));
check('no session -> 401', $r['status'] === 401 && $r['json']['error'] === 'not_authenticated', $r['body']);
$r = http('POST', '/sesar_push.php', $other, array('action' => 'status', 'sample_id' => 'sesarpush-m'));
check('non-pilot -> 403 (server-side gate)', $r['status'] === 403 && $r['json']['error'] === 'not_allowed', $r['body']);
$r = http('GET', '/sesar_push.php', $pilot);
check('GET -> 405', $r['status'] === 405);
$r = http('POST', '/sesar_push.php', $pilot, 'not json');
check('bad JSON -> 400', $r['status'] === 400);
$r = http('POST', '/sesar_push.php', $pilot, array('action' => 'explode'));
check('unknown action -> 400', $r['status'] === 400 && $r['json']['error'] === 'unknown_action');
$r = http('POST', '/sesar_push.php', $pilot, array('action' => 'apply', 'sample_id' => 'sesarpush-m', 'mode' => 'bulk'));
check("pilot pushing someone else's sample -> 404", $r['status'] === 404 && $r['json']['error'] === 'not_found', $r['body']);

} finally {
	SesarAccess::setEnvironmentForTests(null);
	cleanup();
	@unlink($STATE);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
