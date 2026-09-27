<?php
/**
 * File: tests/sesar/smoke_test_sesar_pull.php
 * Description: Phase 5 suite for Pull from SESAR (D5): SesarPull::preview
 *              (refusals, per-field actions and default ticks, Field-linked
 *              flags, parent proposals, one-IGSN-one-sample), apply (review
 *              mode applies only ticked fields with FRESH SESAR values and
 *              skips values that changed since the review; bulk fill /
 *              overwrite; Field-linked fields never applied; link row +
 *              snapshot + list-only ids; changelog), createPlan / createOne
 *              ("Create samples from IGSNs", parents first, held IGSNs
 *              refused), importPage, then sesar_pull.php refusals over HTTP.
 *
 *              Unit part talks ONLY to FakeSesar; the HTTP part makes no
 *              SESAR call. Fixture users 94740-94742, samples "sesarpull-*".
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_pull.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarPull.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once __DIR__ . '/FakeSesar.php';

$U = array(94740, 94741, 94742);
$STATE = '/tmp/fake_sesar_pull.json';
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

/** A fixture sample. $f: name, igsn, lat, lon, description, purpose, type, parent. */
function mk($id, $owner, array $f = array()) {
	global $db;
	$db->prepare_query(
		"INSERT INTO strabosamples.samples (id, userpkey, name, igsn, latitude, longitude, description, display_sample_purpose,
		                                   display_sample_type, parent_sample_id, parent_userpkey, created_by, modified_by)
		 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $2, $2)",
		array($id, $owner, array_key_exists('name', $f) ? $f['name'] : $id, isset($f['igsn']) ? $f['igsn'] : null,
		      array_key_exists('lat', $f) ? $f['lat'] : 38.95, array_key_exists('lon', $f) ? $f['lon'] : -95.25,
		      isset($f['description']) ? $f['description'] : null, isset($f['purpose']) ? $f['purpose'] : null,
		      isset($f['type']) ? $f['type'] : null,
		      isset($f['parent']) ? $f['parent'] : null, isset($f['parent']) ? $owner : null)
	);
}
function linkField($id, $owner) {
	global $db;
	$db->prepare_query("INSERT INTO strabosamples.sample_subsystem_links (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
		VALUES ($1, $2, 'field', '5550099', $2, '{\"dataset_id\": \"42\"}')", array($id, $owner));
}
function spine($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($id, $owner));
}
function reg($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.sesar_registrations WHERE sample_id = $1 AND sample_userpkey = $2 AND active", array($id, $owner));
}
function rowOf(array $rows, $field) {
	foreach ($rows as $r) if ($r['field'] === $field) return $r;
	return null;
}
function seenOf(array $preview) {
	$s = array();
	foreach ($preview['rows'] as $r) $s[$r['field']] = $r['sesar'];
	return $s;
}

cleanup();
foreach ($U as $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Pull', 'Fixture', $2, 'x', 'x', TRUE, FALSE)",
		array($u, "sesarpull$u@test.strabospot.org"));
}

try {

SesarAccess::setEnvironmentForTests('sandbox');
$fake = new FakeSesar($STATE, true);
$client = new SesarClient('sandbox', $fake);
$conn = new SesarConnection($db, $client, $KEY);
$views = new SesarSampleView($db, null, function () { return null; });
$pull = new SesarPull($db, $client, $conn, $views, null);

$A = $U[0]; $B = $U[1];
$ORCID_A = '0000-0001-0000-0040';
$fake->addOrcidUser('idtok-A', $ORCID_A, true, array('IEFAK'));
$conn->connectWithOrcid($A, 'idtok-A', $ORCID_A);
$LU = '2026-09-20T10:00:00Z';
$full = array('_owner' => $ORCID_A, 'name' => 'SESAR name', 'sample_description' => 'Described at SESAR', 'purpose' => 'Geochronology',
	'general_material_type' => 'Granite', 'latitude' => '39.00000000', 'longitude' => '-95.30000000', 'sesar_code' => 'IEFAK',
	'external_sample_id' => 'ext-1', 'last_update_date' => $LU, 'object_type' => 'General sample types > Individual sample',
	'locality' => 'Lawrence', 'collectors' => array(array('individual' => array('label' => 'Fixture, Pull'))));
$fake->seedSample('10.58052/IEFAK0001', $full);

// ===========================================================================
section('preview: refusals');
mk('sesarpull-none', $A);
mk('sesarpull-junk', $A, array('igsn' => 'Carr_057_UM_#19'));
mk('sesarpull-missing', $A, array('igsn' => 'IEZZZ0009'));
mk('sesarpull-gone', $A, array('igsn' => '10.58052/IEOLD0002'));
mk('sesarpull-private', $A, array('igsn' => '10.58052/IEPRV0001'));
mk('sesarpull-b', $B, array('igsn' => '10.58052/IEFAK0001'));
$fake->seedSample('10.58052/IEOLD0002');
$fake->markDeactivated('10.58052/IEOLD0002');
$fake->seedSample('10.58052/IEPRV0001', array('_private' => true));

$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-b'); });
check("another user's sample -> 404, no SESAR call", $e !== null && $e->status === 404 && count($fake->calls('samples/')) === 0);
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-none'); });
check('no IGSN -> 400 plain message', $e !== null && $e->status === 400 && strpos($e->getMessage(), 'no IGSN') !== false);
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-junk'); });
check('junk IGSN text -> 400, never sent to SESAR', $e !== null && $e->status === 400 && count($fake->calls('samples/')) === 0);
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-missing'); });
check('bare IGSN SESAR does not know -> 404 names the normalized IGSN', $e !== null && $e->status === 404
	&& strpos($e->getMessage(), '10.58052/IEZZZ0009') !== false, $e ? $e->getMessage() : null);
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-gone'); });
check('deactivated -> 410', $e !== null && $e->status === 410);
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-private'); });
check("someone else's private record -> 403 'not public'", $e !== null && $e->status === 403 && strpos($e->getMessage(), 'not public') !== false,
	$e ? array($e->status, $e->getMessage()) : null);

// ===========================================================================
section('preview: actions and default ticks (StraboSamples-only sample)');
mk('sesarpull-a', $A, array('name' => 'Old name', 'igsn' => 'https://doi.org/10.58052/iefak0001'));
$p = $pull->preview($A, 'sesarpull-a');
check('IGSN normalized from a URL, landing link, managed (can_edit)', $p['igsn'] === '10.58052/IEFAK0001' && $p['access'] === 'managed'
	&& strpos($p['landing_url'], 'sample/igsn/10.58052/IEFAK0001') !== false && $p['linked'] === false, $p);
check('name differs -> overwrite, unticked', rowOf($p['rows'], 'name')['action'] === 'overwrite' && rowOf($p['rows'], 'name')['checked'] === false);
check('empty description -> fill, ticked', rowOf($p['rows'], 'description')['action'] === 'fill' && rowOf($p['rows'], 'description')['checked'] === true);
check('purpose + material fill (not Field-linked)', rowOf($p['rows'], 'display_sample_purpose')['action'] === 'fill'
	&& rowOf($p['rows'], 'display_sample_type')['action'] === 'fill' && rowOf($p['rows'], 'display_sample_type')['sesar'] === 'Granite');
$loc = rowOf($p['rows'], 'location');
check('location ~7 km away -> overwrite, unticked, distance given', $loc['action'] === 'overwrite' && $loc['checked'] === false
	&& $loc['distance_m'] > 6000 && $loc['distance_m'] < 8000 && $loc['sesar'] === '39, -95.3', $loc);
check('no Field flags on a StraboSamples-only sample', $p['flags'] === array());
check('record summary: object type leaf, collectors, list-only last change date',
	$p['record']['Object type'] === 'Individual sample' && $p['record']['Collectors'] === 'Fixture, Pull'
	&& $p['record']['Last changed at SESAR'] === $LU, $p['record']);
check('preview writes nothing', reg('sesarpull-a', $A) === null);

// ===========================================================================
section('apply: review mode');
$seen = seenOf($p);
$res = $pull->apply($A, 'sesarpull-a', array('mode' => 'review', 'accept' => array('description', 'location', 'display_sample_type'), 'seen' => $seen));
$s = spine('sesarpull-a', $A);
check('only ticked fields applied', $res['applied'] === array('description', 'display_sample_type', 'location')
	&& $s->name === 'Old name' && $s->description === 'Described at SESAR' && $s->display_sample_type === 'Granite'
	&& abs($s->latitude - 39.0) < 1e-9 && abs($s->longitude + 95.3) < 1e-9 && $s->display_sample_purpose === null, array($res, $s));
check('stored IGSN text left as the user typed it', $s->igsn === 'https://doi.org/10.58052/iefak0001');
$r = reg('sesarpull-a', $A);
check('link row: linked, managed, active, canonical IGSN, SESAR code', $r !== null && $r->origin === 'linked' && $r->access === 'managed'
	&& $r->state === 'active' && $r->igsn === '10.58052/IEFAK0001' && $r->sesar_code === 'IEFAK', $r);
$snap = json_decode($r->snapshot, true);
check('snapshot + list-only ids stored (sample_id, last_update_date)', $snap['name'] === 'SESAR name' && $r->sesar_sample_id !== null
	&& strpos((string)$r->sesar_last_update, '2026-09-20') === 0 && $snap['last_update_date'] === $LU, array($r->sesar_sample_id, $r->sesar_last_update));
check('field_flags NULL for a sample without a Field link', $r->field_flags === null);
check('no push fingerprint on a linked row (Phase 6 compares with the snapshot)', $r->pushed_fingerprint === null);
$log = $db->get_row_prepared("SELECT changes::text AS c FROM strabosamples.sample_changelog WHERE sample_id = $1 AND sample_userpkey = $2
	AND change_type = 'update' ORDER BY pkey DESC LIMIT 1", array('sesarpull-a', $A));
check('changelog records the pulled values', $log !== null && strpos($log->c, 'Described at SESAR') !== false && strpos($log->c, 'Granite') !== false, $log);

$p = $pull->preview($A, 'sesarpull-a');
check('second preview: linked, applied fields now same', $p['linked'] === true && rowOf($p['rows'], 'description')['action'] === 'same'
	&& rowOf($p['rows'], 'location')['action'] === 'same');
$fake->editAtSesar('10.58052/IEFAK0001', array('purpose' => 'Changed purpose'));
$res = $pull->apply($A, 'sesarpull-a', array('mode' => 'review', 'accept' => array('display_sample_purpose', 'name'), 'seen' => seenOf($p)));
check('value changed at SESAR after the review -> skipped with a reason, others applied',
	$res['applied'] === array('name') && count($res['skipped']) === 1 && $res['skipped'][0]['field'] === 'display_sample_purpose'
	&& spine('sesarpull-a', $A)->display_sample_purpose === null && spine('sesarpull-a', $A)->name === 'SESAR name', $res);
$p = $pull->preview($A, 'sesarpull-a');
$seen = seenOf($p);
unset($seen['display_sample_purpose']);
$res = $pull->apply($A, 'sesarpull-a', array('mode' => 'review', 'accept' => array('display_sample_purpose'), 'seen' => $seen));
check('review mode: ticked field without its seen value -> skipped, never applied unseen',
	$res['applied'] === array() && count($res['skipped']) === 1 && $res['skipped'][0]['field'] === 'display_sample_purpose'
	&& spine('sesarpull-a', $A)->display_sample_purpose === null, $res);
check('re-pull refreshes the same row (no duplicate)', (int)$db->get_var_prepared(
	"SELECT count(*) FROM strabosamples.sesar_registrations WHERE sample_id = 'sesarpull-a' AND sample_userpkey = $1", array($A)) === 1
	&& json_decode(reg('sesarpull-a', $A)->snapshot, true)['purpose'] === 'Changed purpose');

// ===========================================================================
section('apply: bulk modes');
$fake->seedSample('10.58052/IEFAK0002', array_merge($full, array('name' => 'Bulk at SESAR', 'external_sample_id' => 'ext-2')));
mk('sesarpull-bulk', $A, array('name' => 'Bulk here', 'igsn' => '10.58052/IEFAK0002', 'purpose' => 'Mine'));
$res = $pull->apply($A, 'sesarpull-bulk', array('mode' => 'fill'));
$s = spine('sesarpull-bulk', $A);
check('fill: empty fields filled, differing ones (name, purpose, location) kept', $s->name === 'Bulk here' && $s->display_sample_purpose === 'Mine'
	&& $s->description === 'Described at SESAR' && $s->display_sample_type === 'Granite' && abs($s->latitude - 38.95) < 1e-9, $res);
$res = $pull->apply($A, 'sesarpull-bulk', array('mode' => 'overwrite'));
$s = spine('sesarpull-bulk', $A);
check('overwrite: differing fields replaced', $s->name === 'Bulk at SESAR' && $s->display_sample_purpose === 'Geochronology'
	&& abs($s->latitude - 39.0) < 1e-9, $res);
$res = $pull->apply($A, 'sesarpull-bulk', array('mode' => 'fill', 'accept' => array('name')));
check('bulk ignores accept[] (mode decides)', $res['applied'] === array());

// ===========================================================================
section('Field-linked sample: flags shown, never applied');
$fake->seedSample('10.58052/IEFAK0003', array_merge($full, array('name' => 'Field one at SESAR', 'external_sample_id' => 'ext-3')));
mk('sesarpull-field', $A, array('name' => 'Field one', 'igsn' => '10.58052/IEFAK0003', 'type' => 'intact_rock', 'purpose' => 'petrology'));
linkField('sesarpull-field', $A);
$p = $pull->preview($A, 'sesarpull-field');
$fl = array_map(function ($f) { return $f['field']; }, $p['flags']);
check('location, material, purpose are flags (not rows)', $fl === array('display_sample_purpose', 'display_sample_type', 'location')
	&& rowOf($p['rows'], 'location') === null && $p['field_linked'] === true, $p);
check('flag shows labels, not Field choice names', rowOf($p['flags'], 'display_sample_type')['current'] !== 'intact_rock', $p['flags']);
$res = $pull->apply($A, 'sesarpull-field', array('mode' => 'overwrite'));
$s = spine('sesarpull-field', $A);
check('overwrite on Field-linked: name applied, location/material/purpose untouched', $s->name === 'Field one at SESAR'
	&& $s->display_sample_type === 'intact_rock' && $s->display_sample_purpose === 'petrology' && abs($s->latitude - 38.95) < 1e-9, $res);
$ff = json_decode(reg('sesarpull-field', $A)->field_flags, true);
check('field_flags stored on the link row (with distance)', is_array($ff) && count($ff) === 3 && rowOf($ff, 'location')['distance_m'] > 6000, $ff);

// ===========================================================================
section('read-only link (another SESAR account\'s public record)');
$fake->seedSample('10.58052/IEOTH0001', array('name' => 'Theirs', 'latitude' => '1.5', 'longitude' => '2.5'));
mk('sesarpull-theirs', $A, array('igsn' => '10.58052/IEOTH0001', 'lat' => null, 'lon' => null));
$p = $pull->preview($A, 'sesarpull-theirs');
check('readonly access; location fill (ours empty)', $p['access'] === 'readonly' && rowOf($p['rows'], 'location')['action'] === 'fill');
$res = $pull->apply($A, 'sesarpull-theirs', array('mode' => 'fill'));
$r = reg('sesarpull-theirs', $A);
check('link row readonly; no list row -> sesar_sample_id unknown', $r->access === 'readonly' && $r->sesar_sample_id === null
	&& abs(spine('sesarpull-theirs', $A)->latitude - 1.5) < 1e-9, $r);

// ===========================================================================
section('parent links');
$fake->seedSample('10.58052/IEFAK0010', array_merge($full, array('name' => 'Kid', 'parent_sample' => '10.58052/IEFAK0001', 'external_sample_id' => 'ext-10')));
mk('sesarpull-kid', $A, array('name' => 'Kid', 'igsn' => '10.58052/IEFAK0010'));
$p = $pull->preview($A, 'sesarpull-kid');
$pr = rowOf($p['rows'], 'parent');
check('SESAR parent = IGSN of my linked sample -> parent row fill, ticked', $pr !== null && $pr['action'] === 'fill' && $pr['checked'] === true
	&& strpos($pr['sesar'], 'SESAR name') === 0, $pr);
$res = $pull->apply($A, 'sesarpull-kid', array('mode' => 'review', 'accept' => array(), 'seen' => seenOf($p), 'parent' => true));
$s = spine('sesarpull-kid', $A);
check('parent set via setParent (changelog parent_set)', $res['parent_set'] === true && $s->parent_sample_id === 'sesarpull-a'
	&& (int)$s->parent_userpkey === $A && (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.sample_changelog
	WHERE sample_id = 'sesarpull-kid' AND sample_userpkey = $1 AND change_type = 'parent_set'", array($A)) === 1, $res);
check('then: parent row same', rowOf($pull->preview($A, 'sesarpull-kid')['rows'], 'parent')['action'] === 'same');

$fake->seedSample('10.58052/IEFAK0011', array_merge($full, array('name' => 'Kid2', 'parent_sample' => '10.58052/IEFAK0001', 'external_sample_id' => 'ext-11')));
mk('sesarpull-kid2', $A, array('name' => 'Kid2', 'igsn' => '10.58052/IEFAK0011', 'parent' => 'sesarpull-bulk'));
$pr = rowOf($pull->preview($A, 'sesarpull-kid2')['rows'], 'parent');
check('different parent here -> overwrite, unticked, current parent named', $pr['action'] === 'overwrite' && $pr['checked'] === false
	&& $pr['current'] === 'Bulk at SESAR', $pr);
$res = $pull->apply($A, 'sesarpull-kid2', array('mode' => 'overwrite', 'parent' => true));
check('bulk never replaces an existing parent', $res['parent_set'] === false && spine('sesarpull-kid2', $A)->parent_sample_id === 'sesarpull-bulk');

// Cycle: sesarpull-a's SESAR record names Kid as ITS parent.
$fake->editAtSesar('10.58052/IEFAK0001', array('parent_sample' => '10.58052/IEFAK0010'));
$p = $pull->preview($A, 'sesarpull-a');
$res = $pull->apply($A, 'sesarpull-a', array('mode' => 'review', 'accept' => array(), 'seen' => seenOf($p), 'parent' => true));
check('a parent link that would make a loop is refused with a note', $res['parent_set'] === false
	&& strpos(implode(' ', $res['notes']), 'already below') !== false && spine('sesarpull-a', $A)->parent_sample_id === null, $res);
$fake->editAtSesar('10.58052/IEFAK0001', array('parent_sample' => '10.58052/IEELS0001'));
$p = $pull->preview($A, 'sesarpull-a');
check('SESAR parent not one of my samples -> note, no row', rowOf($p['rows'], 'parent') === null
	&& strpos((string)$p['parent_note'], '10.58052/IEELS0001') !== false, $p['parent_note']);
$fake->editAtSesar('10.58052/IEFAK0001', array('parent_sample' => null));

// ===========================================================================
section('guards');
mk('sesarpull-dupe', $A, array('igsn' => 'IEFAK0001'));
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-dupe'); });
check('same IGSN already linked to another of my samples -> 409 naming it', $e !== null && $e->status === 409
	&& strpos($e->getMessage(), 'SESAR name') !== false && ($e->errors['held'][0] ?? null) === 'sesarpull-a', $e ? $e->getMessage() : null);
mk('sesarpull-minting', $A, array('igsn' => '10.58052/IEFAK0002x'));
$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, origin, state, created_by)
	VALUES ('sesarpull-minting', $1, 'sandbox', NULL, 'minted', 'minting', $1)", array($A));
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-minting'); });
check("unfinished mint ('minting' row) -> 409", $e !== null && $e->status === 409 && strpos($e->getMessage(), 'not finished') !== false);

$pg2 = pg_connect("host=$dbhost dbname=$dbname user=$dbusername password=$dbpassword", PGSQL_CONNECT_FORCE_NEW);
pg_query_params($pg2, "SELECT pg_advisory_lock($1, hashtext($2))", array(SesarMint::LOCK_NS, $A . ':sesarpull-bulk'));
$e = err(function () use ($pull, $A) { $pull->apply($A, 'sesarpull-bulk', array('mode' => 'fill')); });
check('a mint or pull of the same sample in flight -> busy', $e !== null && isset($e->errors['busy']));
pg_close($pg2);

$fake->set('fail_next', array('samples/10.58052/IEFAK0002/' => 0));
$e = err(function () use ($pull, $A) { $pull->preview($A, 'sesarpull-bulk'); });
check('SESAR unreachable -> network error (endpoint says "try again")', $e !== null && $e->kind === 'network');

// ===========================================================================
section('create samples from IGSNs');
$fake->seedSample('10.58052/IEFAK0020', array_merge($full, array('name' => 'New parent', 'external_sample_id' => null)));
$fake->seedSample('10.58052/IEFAK0021', array_merge($full, array('name' => 'New child', 'parent_sample' => '10.58052/IEFAK0020',
	'external_sample_id' => null, 'latitude' => null, 'longitude' => null)));
$fake->seedSample('10.58052/IEFAK0022', array_merge($full, array('name' => 'Orphan kid', 'parent_sample' => '10.58052/IEFAK0010', 'external_sample_id' => null)));
$fake->seedSample('10.58052/IEOLD0003');
$fake->markDeactivated('10.58052/IEOLD0003');
$plan = $pull->createPlan($A, array('10.58052/IEFAK0021', 'iefak0020', 'IEFAK0020', 'hello world', 'IEZZZ0099',
	'10.58052/IEFAK0001', '10.58052/IEOLD0003', 'https://doi.org/10.58052/IEFAK0022'));
$byIgsn = array();
foreach ($plan['rows'] as $r) $byIgsn[$r['igsn'] === null ? $r['input'] : $r['igsn']] = $r;
check('duplicates collapsed (bare / lower-case / prefixed)', count($plan['rows']) === 7, array_keys($byIgsn));
$ready = array_values(array_filter($plan['rows'], function ($r) { return $r['group'] === 'ready'; }));
$pos = array(); foreach ($ready as $i => $r) $pos[$r['igsn']] = $i;
check('ready rows parents first (level by level) even when pasted after the child', count($ready) === 3
	&& $pos['10.58052/IEFAK0020'] < $pos['10.58052/IEFAK0021'] && $ready[$pos['10.58052/IEFAK0021']]['parent_in_run'] === true
	&& $ready[$pos['10.58052/IEFAK0021']]['depth'] === 1 && $pos['10.58052/IEFAK0022'] < $pos['10.58052/IEFAK0021'], $pos);
check('held IGSN -> "held" with the holding sample', $byIgsn['10.58052/IEFAK0001']['group'] === 'held'
	&& $byIgsn['10.58052/IEFAK0001']['holder']['id'] === 'sesarpull-a');
check('junk, unknown, deactivated -> blocked with reasons', $byIgsn['hello world']['group'] === 'blocked'
	&& $byIgsn['10.58052/IEZZZ0099']['reason'] === 'SESAR has no sample with this IGSN.'
	&& $byIgsn['10.58052/IEOLD0003']['reason'] === 'Deactivated at SESAR.', $byIgsn);
check('parent outside the run held by me is named', $byIgsn['10.58052/IEFAK0022']['parent_holder']['id'] === 'sesarpull-kid', $byIgsn['10.58052/IEFAK0022']);
$many = array(); for ($i = 0; $i < 101; $i++) $many[] = sprintf('IEFAK%04d', 5000 + $i);
$e = err(function () use ($pull, $A, $many) { $pull->createPlan($A, $many); });
check('more than 100 -> refused', $e !== null && strpos($e->getMessage(), 'at most 100') !== false);

$r1 = $pull->createOne($A, 'iefak0020');
$r2 = $pull->createOne($A, '10.58052/IEFAK0021');
$r3 = $pull->createOne($A, '10.58052/IEFAK0022');
$s1 = spine($r1['sample_id'], $A); $s2 = spine($r2['sample_id'], $A); $s3 = spine($r3['sample_id'], $A);
check('created: canonical IGSN, SESAR name/description/material/purpose/location', $s1->igsn === '10.58052/IEFAK0020' && $s1->name === 'New parent'
	&& $s1->description === 'Described at SESAR' && $s1->display_sample_type === 'Granite' && $s1->display_sample_purpose === 'Geochronology'
	&& abs($s1->latitude - 39.0) < 1e-9 && $r1['access'] === 'managed' && $r1['url'] === '/samples/' . $A . '/' . rawurlencode($r1['sample_id']), $r1);
check('child created after its parent gets the parent link; no location stays empty', $s2->parent_sample_id === $r1['sample_id']
	&& $r2['parent_linked'] === true && $s2->latitude === null, $r2);
check('parent held by an existing sample links too', $s3->parent_sample_id === 'sesarpull-kid');
check('each created sample has a linked row + snapshot', reg($r2['sample_id'], $A)->origin === 'linked' && reg($r2['sample_id'], $A)->snapshot !== null);
$e = err(function () use ($pull, $A) { $pull->createOne($A, 'IEFAK0020'); });
check('creating it again -> 409 held', $e !== null && $e->status === 409 && isset($e->errors['held']));
$e = err(function () use ($pull, $A) { $pull->createOne($A, 'nonsense'); });
check('not an IGSN -> 400', $e !== null && $e->status === 400);
$e = err(function () use ($pull, $A) { $pull->createOne($A, '10.58052/IEOLD0003'); });
check('deactivated -> 410, nothing created', $e !== null && $e->status === 410);

// ===========================================================================
section('import from my SESAR account');
$pg = $pull->importPage($A, 1);
$igs = array_map(function ($r) { return $r['igsn']; }, $pg['rows']);
check('only my own SESAR samples listed', !in_array('10.58052/IEOTH0001', $igs, true) && in_array('10.58052/IEFAK0001', $igs, true)
	&& $pg['count'] === count($igs), $igs);
$held = array(); foreach ($pg['rows'] as $r) if ($r['holder'] !== null) $held[$r['igsn']] = $r['holder']['id'];
check('rows held by my samples are marked with the sample', $held['10.58052/IEFAK0001'] === 'sesarpull-a' && isset($held['10.58052/IEFAK0020']), $held);
check('row fields: object type leaf, material, has_location', $pg['rows'][0]['object_type'] === 'Individual sample'
	&& $pg['rows'][0]['material'] === 'Granite' && $pg['rows'][0]['has_location'] === true, $pg['rows'][0]);
for ($i = 0; $i < 55; $i++) $fake->seedSample(sprintf('10.58052/IEFAK%04d', 100 + $i), array('_owner' => $ORCID_A, 'name' => 'Filler ' . $i));
$pg = $pull->importPage($A, 2);
check('paging: 50 per page, page 2 has the rest', $pg['pages'] === 2 && $pg['page'] === 2 && count($pg['rows']) === $pg['count'] - 50, array($pg['count'], count($pg['rows'])));
$pg = $pull->importPage($A, 1, 'Kid2');
check('search passed to SESAR', count($pg['rows']) === 1 && $pg['rows'][0]['igsn'] === '10.58052/IEFAK0011');
$fake->set('list_timeout', true);
$e = err(function () use ($pull, $A) { $pull->importPage($A, 1); });
check('SESAR list timeout (504) surfaces as an error, not a crash', $e !== null && $e->status === 504);
$fake->set('list_timeout', false);

// ===========================================================================
section('HTTP: sesar_pull.php refusals (forged sessions, no SESAR calls)');
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
$r = http('POST', '/sesar_pull.php', null, array('action' => 'preview', 'sample_id' => 'x'));
check('no session -> 401', $r['status'] === 401 && $r['json']['error'] === 'not_authenticated', $r['body']);
$r = http('POST', '/sesar_pull.php', $other, array('action' => 'preview', 'sample_id' => 'sesarpull-a'));
check('non-pilot -> 403 (server-side gate)', $r['status'] === 403 && $r['json']['error'] === 'not_allowed', $r['body']);
$r = http('GET', '/sesar_pull.php', $pilot);
check('GET -> 405', $r['status'] === 405);
$r = http('POST', '/sesar_pull.php', $pilot, 'not json');
check('bad JSON -> 400', $r['status'] === 400);
$r = http('POST', '/sesar_pull.php', $pilot, array('action' => 'explode'));
check('unknown action -> 400', $r['status'] === 400 && $r['json']['error'] === 'unknown_action');
$r = http('POST', '/sesar_pull.php', $pilot, array('action' => 'create_plan', 'igsns' => array()));
check('create_plan with nothing -> 400', $r['status'] === 400 && $r['json']['error'] === 'validation');
$r = http('POST', '/sesar_pull.php', $pilot, array('action' => 'apply', 'sample_id' => 'sesarpull-a', 'mode' => 'overwrite'));
check("pilot pulling someone else's sample -> 404, nothing changed", $r['status'] === 404 && $r['json']['error'] === 'not_found'
	&& spine('sesarpull-a', $A)->name === 'SESAR name', $r['body']);

} finally {
	SesarAccess::setEnvironmentForTests(null);
	cleanup();
	@unlink($STATE);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
