<?php
/**
 * File: tests/sesar/smoke_test_sesar_mint.php
 * Description: Phase 4 suite for IGSN minting (D2, D3, D4, D8):
 *              SesarSampleView (Field spot location + rock names),
 *              SesarMint::plan (eligibility groups, stored-IGSN verdicts,
 *              family suggestions, parents-first order, suggestions,
 *              choices) and SesarMint::mintOne (choice validation, every
 *              duplicate guard: registered, lock, lost answers adopted by
 *              external_sample_id, replace confirmation, parent linking
 *              and the never-unlinked rule, spine write + changelog), then
 *              sesar_mint.php refusals over HTTP (forged sessions).
 *
 *              Unit part talks ONLY to FakeSesar; the HTTP part makes no
 *              SESAR call. Fixture users 94730-94732, samples "sesarmint-*".
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_mint.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarMint.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once __DIR__ . '/FakeSesar.php';

$U = array(94730, 94731, 94732);
$STATE = '/tmp/fake_sesar_mint.json';
$KEY = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$sessionFiles = array();

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 500) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function mintErr($fn) {
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

/** A fixture sample. $f: name, igsn, lat, lon, parent (id), parent_owner, field_data. */
function mk($id, $owner, array $f = array()) {
	global $db;
	$db->prepare_query(
		"INSERT INTO strabosamples.samples (id, userpkey, name, igsn, latitude, longitude, parent_sample_id, parent_userpkey,
		                                   field_data, display_sample_type, created_by, modified_by)
		 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9::jsonb, $10, $2, $2)",
		array($id, $owner, array_key_exists('name', $f) ? $f['name'] : $id, isset($f['igsn']) ? $f['igsn'] : null,
		      array_key_exists('lat', $f) ? $f['lat'] : 38.95, array_key_exists('lon', $f) ? $f['lon'] : -95.25,
		      isset($f['parent']) ? $f['parent'] : null, isset($f['parent']) ? (isset($f['parent_owner']) ? $f['parent_owner'] : $owner) : null,
		      isset($f['field_data']) ? json_encode($f['field_data']) : null, isset($f['type']) ? $f['type'] : null)
	);
}
function spineIgsn($id, $owner) {
	global $db;
	return $db->get_var_prepared("SELECT igsn FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($id, $owner));
}
function reg($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.sesar_registrations WHERE sample_id = $1 AND sample_userpkey = $2 AND active", array($id, $owner));
}
function rowOf(array $plan, $id) {
	foreach ($plan['rows'] as $r) if ($r['id'] === $id) return $r;
	return null;
}
function atSesar(FakeSesar $fake, $externalId) {
	$n = 0;
	foreach ($fake->state()['samples'] as $rec) if (isset($rec['external_sample_id']) && (string)$rec['external_sample_id'] === (string)$externalId) $n++;
	return $n;
}

cleanup();
foreach ($U as $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Mint', 'Fixture', $2, 'x', 'x', TRUE, FALSE)",
		array($u, "sesarmint$u@test.strabospot.org"));
}

try {

SesarAccess::setEnvironmentForTests('sandbox');
$fake = new FakeSesar($STATE, true);
$client = new SesarClient('sandbox', $fake);
$conn = new SesarConnection($db, $client, $KEY);
$vocab = new SesarVocab($db, $client);
$spots = array();   // spot id => spot (the fake spot loader)
$views = new SesarSampleView($db, null, function ($spotId, $u, $did) use (&$spots) { return isset($spots[$spotId]) ? $spots[$spotId] : null; });
$mint = new SesarMint($db, $client, $conn, $vocab, $views, SesarMint::serviceSpineWriter($db, null));
$db->query("DELETE FROM strabosamples.sesar_vocab_cache WHERE environment = 'sandbox'");

$A = $U[0]; $B = $U[1];
$fake->addOrcidUser('idtok-A', '0000-0001-0000-0001', true, array('IEFAK'));
$conn->connectWithOrcid($A, 'idtok-A', '0000-0001-0000-0001');
$CH = array('sesar_code' => 'IEFAK', 'object_type' => 'Individual sample', 'material' => '', 'collector' => 'Fixture, Mint');

// ===========================================================================
section('SesarSampleView::applySpot');
$v = array('latitude' => null, 'longitude' => null);
SesarSampleView::applySpot($v, array('id' => '1', 'wkt' => 'LINESTRING (-105.1 38.5, -105.2 38.6, -105.3 38.7)'));
check('LineString: first vertex = location, last = end', $v['latitude'] === 38.5 && $v['longitude'] === -105.1
	&& $v['latitude_end'] === 38.7 && $v['longitude_end'] === -105.3 && $v['location_from'] === 'line', $v);
$v = array('latitude' => null, 'longitude' => null);
SesarSampleView::applySpot($v, array('id' => '1', 'wkt' => 'LINESTRING (100 200, 300 400)', 'image_basemap' => 123));
check('image-basemap line: pixel coords never become a location', $v['latitude'] === null && !isset($v['latitude_end']));
$v = array('latitude' => null, 'longitude' => null);
SesarSampleView::applySpot($v, array('id' => '1', 'wkt' => 'POLYGON ((0 0, 1 0, 1 1, 0 0))'));
check('polygon: no location invented', $v['latitude'] === null);
$v = array('latitude' => 10.0, 'longitude' => 20.0);
SesarSampleView::applySpot($v, array('id' => '1', 'wkt' => 'LINESTRING (1 2, 3 4)'));
check('spine location wins over the spot line', $v['latitude'] === 10.0 && !isset($v['latitude_end']));
$v = array('latitude' => null, 'longitude' => null);
SesarSampleView::applySpot($v, array('id' => '77',
	'json_tags' => json_encode(array(
		array('type' => 'geologic_unit', 'spots' => array(77), 'rock_type' => 'igneous', 'plutonic_rock_types' => 'granite'),
		array('type' => 'geologic_unit', 'spots' => array(78), 'rock_type' => 'sedimentary', 'sedimentary_rock_type' => 'shale'),
		array('type' => 'other', 'spots' => array(77), 'plutonic_rock_types' => 'diorite'))),
	'pet' => json_encode(array('metamorphic' => array(array('metamorphic_rock_type' => 'quartzite', 'protolith' => 'sandstone')))),
	'sed' => json_encode(array('lithologies' => array(array('primary_lithology' => 'siliciclastic', 'siliciclastic_type' => 'mudstone'),
		array('primary_lithology' => 'limestone'))))));
check('rock names: unit tag of THIS spot, then pet, then sed specific, then coarse; no "siliciclastic"/protolith/other-spot/non-unit tags',
	$v['field_rock_types'] === array('granite', 'quartzite', 'mudstone', 'limestone'), $v['field_rock_types']);
$v = array('latitude' => null, 'longitude' => null);
SesarSampleView::applySpot($v, array('id' => '9', 'pet' => json_encode(array('rock_type' => array('metamorphic'), 'metamorphic_rock_type' => array('metabasite', 'schist')))));
check('old pet shape (lists) read too, category names dropped', $v['field_rock_types'] === array('metabasite', 'schist'), $v['field_rock_types']);

// ===========================================================================
section('plan: groups, reasons, suggestions, choices');
mk('sesarmint-ready', $A, array('field_data' => array('material_type' => 'sediment')));
mk('sesarmint-noloc', $A, array('lat' => null, 'lon' => null));
mk('sesarmint-noname', $A, array('name' => ''));
mk('sesarmint-junk', $A, array('igsn' => 'Carr_057_UM_#19'));
mk('sesarmint-unknown', $A, array('igsn' => 'IEZZZ0001'));
mk('sesarmint-found', $A, array('igsn' => 'https://doi.org/10.58052/IEOLD0001'));
mk('sesarmint-gone', $A, array('igsn' => '10.58052/IEOLD0002'));
mk('sesarmint-doi-unknown', $A, array('igsn' => '10.99999/ABCDEFGHI'));
mk('sesarmint-doi-team', $A, array('igsn' => '10.60471/ODP01BXOT'));
mk('sesarmint-b', $B);
$fake->seedSample('10.58052/IEOLD0001');
$fake->seedSample('10.58052/IEOLD0002');
$fake->markDeactivated('10.58052/IEOLD0002');
$fake->seedSample('10.60471/ODP01BXOT');

$p = $mint->plan($A, array('sesarmint-ready', 'sesarmint-noloc', 'sesarmint-noname', 'sesarmint-junk', 'sesarmint-unknown',
	'sesarmint-found', 'sesarmint-gone', 'sesarmint-doi-unknown', 'sesarmint-doi-team', 'sesarmint-b', 'sesarmint-nope'));
$g = function ($id) use ($p) { $r = rowOf($p, $id); return $r === null ? null : $r['group']; };
check('ready sample -> ready', $g('sesarmint-ready') === 'ready');
check('material suggested from Field material_type (sediment -> Sediment)', rowOf($p, 'sesarmint-ready')['material'] === 'Sediment', rowOf($p, 'sesarmint-ready'));
check('object type falls back to Individual sample', rowOf($p, 'sesarmint-ready')['object_type'] === 'Individual sample');
check('no location -> blocked, plain reason', $g('sesarmint-noloc') === 'blocked' && strpos(implode(' ', rowOf($p, 'sesarmint-noloc')['reasons']), 'no location') !== false);
check('no name -> blocked', $g('sesarmint-noname') === 'blocked');
check('junk IGSN text -> replace group (unchecked in the UI)', $g('sesarmint-junk') === 'replace');
check('bare SESAR-shaped IGSN SESAR does not know -> replace', $g('sesarmint-unknown') === 'replace');
check('IGSN registered at SESAR (URL form) -> blocked', $g('sesarmint-found') === 'blocked'
	&& strpos(implode(' ', rowOf($p, 'sesarmint-found')['reasons']), '10.58052/IEOLD0001') !== false);
check('deactivated IGSN -> replace', $g('sesarmint-gone') === 'replace');
check('other-prefix DOI SESAR does not know -> blocked (another registry)', $g('sesarmint-doi-unknown') === 'blocked');
check('team-prefix DOI SESAR hosts -> blocked (found)', $g('sesarmint-doi-team') === 'blocked');
check("another user's sample -> missing, never a row", rowOf($p, 'sesarmint-b') === null && in_array('sesarmint-b', $p['missing'], true));
check('unknown id -> missing', in_array('sesarmint-nope', $p['missing'], true));
check('one parallel lookup batch, only for SESAR-shaped values', count($fake->calls('samples/by-igsn/')) === 5, count($fake->calls('samples/by-igsn/')));
check('choices: codes, collector from the SESAR identity, object types grouped, registrable materials only',
	$p['choices']['codes'] === array('IEFAK') && $p['choices']['collector'] === 'User, Fake'
	&& isset($p['choices']['object_types']['General sample types']) && in_array('Limestone', $p['choices']['materials'], true)
	&& !in_array('Rock', $p['choices']['materials'], true), $p['choices']);
check('environment + cap reported', $p['environment'] === 'sandbox' && $p['cap'] === 100);

$fake->set('fail_next', array('samples/by-igsn/' => 0));
$p = $mint->plan($A, array('sesarmint-unknown'));
check('SESAR unreachable for the lookup -> blocked "could not check", never replace', rowOf($p, 'sesarmint-unknown')['group'] === 'blocked'
	&& strpos(implode(' ', rowOf($p, 'sesarmint-unknown')['reasons']), 'Could not check') !== false);

$many = array(); for ($i = 0; $i < 101; $i++) $many[] = 'x' . $i;
$e = mintErr(function () use ($mint, $A, $many) { $mint->plan($A, $many); });
check('more than 100 picked -> refused', $e !== null && strpos($e->getMessage(), 'at most 100') !== false);

// Field-linked: rock name from the spot -> registrable material; LineString ends.
mk('sesarmint-field', $A, array('lat' => null, 'lon' => null, 'field_data' => array('material_type' => 'intact_rock', 'sample_type' => 'oriented_core')));
$db->prepare_query("INSERT INTO strabosamples.sample_subsystem_links (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
	VALUES ('sesarmint-field', $1, 'field', '5550001', $1, '{\"dataset_id\": \"42\"}')", array($A));
$spots['5550001'] = array('id' => '5550001', 'wkt' => 'LINESTRING (-105.1 38.5, -105.3 38.7)',
	'pet' => json_encode(array('plutonic' => array(array('plutonic_rock_type' => 'granite')))));
$p = $mint->plan($A, array('sesarmint-field'));
$r = rowOf($p, 'sesarmint-field');
check('Field-linked: rock name -> Granite; oriented_core -> Oriented Core; line location note', $r['group'] === 'ready'
	&& $r['material'] === 'Granite' && $r['object_type'] === 'Oriented Core' && $r['location_note'] !== null, $r);
mk('sesarmint-rockonly', $A, array('field_data' => array('material_type' => 'intact_rock')));
check('intact rock without a rock name -> no material (SESAR refuses "Rock"); Rock hand sample',
	rowOf($mint->plan($A, array('sesarmint-rockonly')), 'sesarmint-rockonly')['material'] === null
	&& rowOf($mint->plan($A, array('sesarmint-rockonly')), 'sesarmint-rockonly')['object_type'] === 'Rock hand sample');

// ===========================================================================
section('plan: family (D8)');
mk('sesarmint-g', $A, array('igsn' => '10.58052/IEOLD0001'));                  // grandparent, has a live IGSN
mk('sesarmint-p', $A, array('parent' => 'sesarmint-g'));                        // parent, none
mk('sesarmint-c', $A, array('parent' => 'sesarmint-p'));                        // picked
mk('sesarmint-k1', $A, array('parent' => 'sesarmint-c'));                       // child, none
mk('sesarmint-k2', $A, array('parent' => 'sesarmint-c', 'igsn' => 'IEZZZ0009'));  // child with a value -> not suggested
mk('sesarmint-gk', $A, array('parent' => 'sesarmint-k2'));                      // grandchild under it, none
mk('sesarmint-foreignkid', $B, array('parent' => 'sesarmint-c', 'parent_owner' => $A));
$p = $mint->plan($A, array('sesarmint-c'));
$ids = array_map(function ($r) { return $r['id']; }, $p['rows']);
check('ancestor without IGSN suggested; walk stops at the ancestor that has one', in_array('sesarmint-p', $ids, true) && !in_array('sesarmint-g', $ids, true), $ids);
check('descendants without IGSN suggested (through a child that has one); child with a value not', in_array('sesarmint-k1', $ids, true)
	&& in_array('sesarmint-gk', $ids, true) && !in_array('sesarmint-k2', $ids, true), $ids);
check("another user's child never suggested", !in_array('sesarmint-foreignkid', $ids, true));
check('parents first: p, c, k1 in order', array_search('sesarmint-p', $ids) < array_search('sesarmint-c', $ids)
	&& array_search('sesarmint-c', $ids) < array_search('sesarmint-k1', $ids), $ids);
check('roles marked', rowOf($p, 'sesarmint-p')['role'] === 'ancestor' && rowOf($p, 'sesarmint-k1')['role'] === 'descendant' && rowOf($p, 'sesarmint-c')['role'] === 'picked');
check('parent in the run flagged in_run', rowOf($p, 'sesarmint-c')['parent']['in_run'] === true && rowOf($p, 'sesarmint-c')['parent_id'] === 'sesarmint-p');
check("p's parent outside the run: SESAR-verified IGSN", rowOf($p, 'sesarmint-p')['parent']['in_run'] === false
	&& rowOf($p, 'sesarmint-p')['parent']['igsn'] === '10.58052/IEOLD0001', rowOf($p, 'sesarmint-p')['parent']);
check("gk's parent (k2) holds an IGSN SESAR does not know: no parent IGSN", rowOf($p, 'sesarmint-gk')['parent']['igsn'] === null);

// ===========================================================================
section('mintOne: validation before any SESAR write');
$posts = function () use ($fake) { return count(array_filter($fake->calls('samples/'), function ($c) { return $c['method'] === 'POST' && preg_match('#/samples/$#', $c['url']); })); };
$n0 = $posts();
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-ready', array('sesar_code' => 'IEXXX') + $CH); });
check('SESAR code not yours -> refused', $e !== null && isset($e->errors['sesar_code']));
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-ready', array('object_type' => 'Spaceship') + $CH); });
check('unknown object type -> refused', $e !== null && isset($e->errors['object_type']));
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-ready', array('material' => 'Rock') + $CH); });
check('unregistrable material (Rock) -> refused here, before SESAR', $e !== null && isset($e->errors['material']));
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-noloc', $CH); });
check('no location -> refused', $e !== null && strpos($e->getMessage(), 'no location') !== false);
$e = mintErr(function () use ($mint, $B, $CH) { $mint->mintOne($B, 'sesarmint-ready', $CH); });
check("someone else's sample -> not found", $e !== null && $e->kind === 'not_found');
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-found', array('replace_existing' => true) + $CH); });
check('IGSN field holds a live SESAR IGSN -> refused even with replace', $e !== null && strpos($e->getMessage(), 'registered at SESAR') !== false);
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-junk', $CH); });
check('junk IGSN text without replace confirmation -> refused', $e !== null && strpos($e->getMessage(), 'Tick it') !== false);
check('none of that reached SESAR registration', $posts() === $n0);
check('no tracking rows left behind', $db->get_var("SELECT count(*) FROM strabosamples.sesar_registrations WHERE sample_userpkey = $A") === '0');

// ===========================================================================
section('mintOne: happy path');
$res = $mint->mintOne($A, 'sesarmint-ready', array('material' => 'sediment') + $CH);
$igsn = $res['igsn'];
check('registered: IGSN + sandbox landing URL', preg_match('#^10\.58052/IEFAK\d{4}$#', $igsn) && $res['landing_url'] === 'https://app-sandbox.geosamples.org/sample/igsn/' . $igsn
	&& $res['adopted'] === false, $res);
$rec = $fake->state()['samples'][$igsn];
check('payload: name, coordinates, external id, object type, material label (case fixed), collector',
	$rec['name'] === 'sesarmint-ready' && (float)$rec['latitude'] === 38.95 && $rec['external_sample_id'] === 'sesarmint-ready'
	&& $rec['object_type'] === 'General sample types > Individual sample' && $rec['general_material_type'] === 'Sediment'
	&& $rec['collectors'][0]['individual']['label'] === 'Fixture, Mint', $rec);
$rrs = $fake->state()['related'];
check('related resource created and linked: the sample page on strabospot.org', count($rec['related_resources']) === 1
	&& $rrs[(string)$rec['related_resources'][0]]['uri'] === 'https://strabospot.org/samples/' . $A . '/sesarmint-ready', $rrs);
$r = reg('sesarmint-ready', $A);
check('tracking row: active, managed, minted, code, SESAR sample_id (from the list), related resource, fingerprint',
	$r !== null && $r->state === 'active' && $r->access === 'managed' && $r->origin === 'minted' && $r->igsn === $igsn && $r->sesar_code === 'IEFAK'
	&& (int)$r->sesar_sample_id > 900000 && (int)$r->related_resource_id === (int)$rec['related_resources'][0]
	&& strlen($r->pushed_fingerprint) === 64 && $r->environment === 'sandbox', $r);
check('spine IGSN written', spineIgsn('sesarmint-ready', $A) === $igsn);
check('changelog records the IGSN change', (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.sample_changelog
	WHERE sample_id = 'sesarmint-ready' AND sample_userpkey = $1 AND changes->'igsn'->>'new' = $2", array($A, $igsn)) === 1);
check('last SESAR code remembered', $conn->summary($A)['last_sesar_code'] === 'IEFAK');
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-ready', $CH); });
check('second mint of the same sample -> refused, still one record at SESAR', $e !== null && strpos($e->getMessage(), 'Already registered') !== false
	&& atSesar($fake, 'sesarmint-ready') === 1);
$p = $mint->plan($A, array('sesarmint-ready'));
check('plan now shows it registered (blocked)', rowOf($p, 'sesarmint-ready')['group'] === 'blocked' && rowOf($p, 'sesarmint-ready')['registered_igsn'] === $igsn);

// Spine lost the IGSN (e.g. the spine write failed): a re-run repairs, no new record.
$db->prepare_query("UPDATE strabosamples.samples SET igsn = NULL WHERE id = 'sesarmint-ready' AND userpkey = $1", array($A));
$res = $mint->mintOne($A, 'sesarmint-ready', $CH);
check('re-run with the registration but an empty IGSN field: field repaired, nothing new at SESAR',
	$res['igsn'] === $igsn && spineIgsn('sesarmint-ready', $A) === $igsn && atSesar($fake, 'sesarmint-ready') === 1);

// Collector: the connected person goes by ORCID; an ambiguous typed name gets guidance.
mk('sesarmint-col1', $A);
$res = $mint->mintOne($A, 'sesarmint-col1', array('collector' => 'user, fake') + $CH);
$c = $fake->state()['samples'][$res['igsn']]['collectors'][0]['individual'];
check('collector = connected person -> SESAR individual with ORCID individual_uri + names', $c['individual_uri'] === '0000-0001-0000-0001'
	&& $c['label'] === 'User, Fake' && $c['lname'] === 'User', $c);
mk('sesarmint-col2', $A);
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-col2', array('collector' => 'Common, Name') + $CH); });
check('typed name SESAR finds ambiguous -> plain guidance, tracking row removed', $e !== null && isset($e->errors['collector'])
	&& strpos($e->getMessage(), 'more than one person') !== false && reg('sesarmint-col2', $A) === null, $e ? $e->getMessage() : '');

// Field-linked line sample.
$res = $mint->mintOne($A, 'sesarmint-field', array('object_type' => 'Oriented Core', 'material' => 'Granite') + $CH);
$rec = $fake->state()['samples'][$res['igsn']];
check('Field line: start + end coordinates sent', (float)$rec['latitude'] === 38.5 && (float)$rec['latitude_end'] === 38.7
	&& (float)$rec['longitude_end'] === -105.3, $rec);

// ===========================================================================
section('mintOne: replace (D3 revised)');
$res = $mint->mintOne($A, 'sesarmint-junk', array('replace_existing' => true) + $CH);
check('junk replaced by a real IGSN, note says so', spineIgsn('sesarmint-junk', $A) === $res['igsn'] && strpos(implode(' ', $res['notes']), 'Carr_057_UM_#19') !== false);
check('old text kept in the changelog', (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.sample_changelog
	WHERE sample_id = 'sesarmint-junk' AND sample_userpkey = $1 AND changes->'igsn'->>'old' = 'Carr_057_UM_#19'", array($A)) === 1);
$res = $mint->mintOne($A, 'sesarmint-gone', array('replace_existing' => true) + $CH);
check('deactivated IGSN replaced', spineIgsn('sesarmint-gone', $A) === $res['igsn']);
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-doi-unknown', array('replace_existing' => true) + $CH); });
check('other-registry DOI: refused even with replace', $e !== null && strpos($e->getMessage(), 'another registry') !== false);

// ===========================================================================
section('mintOne: family (D8)');
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-c', array('expect_parent' => true) + $CH); });
check('parent expected but not registered -> skipped, never minted unlinked', $e !== null && isset($e->errors['parent']) && atSesar($fake, 'sesarmint-c') === 0);
$rp = $mint->mintOne($A, 'sesarmint-p', $CH);
check("p's parent_sample = the grandparent's SESAR-verified IGSN", $fake->state()['samples'][$rp['igsn']]['parent_sample'] === '10.58052/IEOLD0001');
$rc = $mint->mintOne($A, 'sesarmint-c', array('expect_parent' => true) + $CH);
check("c's parent_sample = p's new IGSN (from the tracking row)", $fake->state()['samples'][$rc['igsn']]['parent_sample'] === $rp['igsn']);
$rgk = $mint->mintOne($A, 'sesarmint-gk', $CH);
check('parent holds an IGSN SESAR does not know: minted without parent_sample (raw text never sent)',
	empty($fake->state()['samples'][$rgk['igsn']]['parent_sample']));
$e = mintErr(function () use ($mint, $B, $CH) { $mint->mintOne($B, 'sesarmint-foreignkid', $CH); });
check('user without a SESAR connection -> "Connect your SESAR account first", nothing sent',
	$e !== null && strpos($e->getMessage(), 'Connect your SESAR account') !== false && atSesar($fake, 'sesarmint-foreignkid') === 0);

// ===========================================================================
section('mintOne: lost answers and failures (duplicate guards)');
mk('sesarmint-t1', $A);
$fake->set('register_then_timeout', true);
$res = $mint->mintOne($A, 'sesarmint-t1', $CH);
check('SESAR registered but the answer was lost: adopted by external_sample_id, one record', $res['adopted'] === true
	&& atSesar($fake, 'sesarmint-t1') === 1 && spineIgsn('sesarmint-t1', $A) === $res['igsn'] && reg('sesarmint-t1', $A)->state === 'active');

mk('sesarmint-t2', $A);
$fake->set('register_fail_after', true);
$res = $mint->mintOne($A, 'sesarmint-t2', $CH);
check('gateway error after registering: adopted, one record', $res['adopted'] === true && atSesar($fake, 'sesarmint-t2') === 1);

mk('sesarmint-t3', $A);
$fake->set('fail_next', array('samples/' => 0));
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-t3', $CH); });
check('no answer and nothing at SESAR: "not confirmed", row stays minting', $e !== null && isset($e->errors['outcome'])
	&& reg('sesarmint-t3', $A)->state === 'minting' && atSesar($fake, 'sesarmint-t3') === 0, $e ? $e->getMessage() : '');
$p = $mint->plan($A, array('sesarmint-t3'));
check('plan: pending attempt noted, still ready', rowOf($p, 'sesarmint-t3')['group'] === 'ready' && count(rowOf($p, 'sesarmint-t3')['notes']) === 1);
$res = $mint->mintOne($A, 'sesarmint-t3', $CH);
check('retry checks SESAR first, then registers once', $res['adopted'] === false && atSesar($fake, 'sesarmint-t3') === 1
	&& reg('sesarmint-t3', $A)->state === 'active');

mk('sesarmint-t4', $A);
$fake->set('fail_next', array('samples/' => 400));
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-t4', $CH); });
check('SESAR refuses the payload: error passed on, tracking row removed', $e !== null && $e->kind === 'validation' && reg('sesarmint-t4', $A) === null);

mk('sesarmint-t5', $A);
$fake->set('fail_next', array('related-resources/' => 500));
$res = $mint->mintOne($A, 'sesarmint-t5', $CH);
check('link-back failure never blocks the mint (noted)', !empty($res['igsn']) && strpos(implode(' ', $res['notes']), 'link back') !== false
	&& empty($fake->state()['samples'][$res['igsn']]['related_resources']));

// The link-back resource survives a refused registration; the retry reuses it (one URI, one resource).
mk('sesarmint-t8', $A);
$fake->set('fail_next', array('samples/' => 400));
mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-t8', $CH); });
$nRR = count((array)$fake->state()['related']);
$res = $mint->mintOne($A, 'sesarmint-t8', $CH);
$rec = $fake->state()['samples'][$res['igsn']];
check('retry after a refusal reuses the existing link-back resource (found via its label), no note',
	count((array)$fake->state()['related']) === $nRR && count($rec['related_resources']) === 1
	&& $fake->state()['related'][(string)$rec['related_resources'][0]]['label'] === 'StraboSpot sample page (sesarmint-t8)'
	&& empty($res['notes']), $res);

mk('sesarmint-t6', $A);
// A separate session, like a second web request (pg_connect would reuse ours,
// and advisory locks are re-entrant within one session).
$pg2 = pg_connect("host=$dbhost dbname=$dbname user=$dbusername password=$dbpassword", PGSQL_CONNECT_FORCE_NEW);
pg_query_params($pg2, "SELECT pg_advisory_lock($1, hashtext($2))", array(SesarMint::LOCK_NS, $A . ':sesarmint-t6'));
$e = mintErr(function () use ($mint, $A, $CH) { $mint->mintOne($A, 'sesarmint-t6', $CH); });
check('another mint of the same sample in flight -> busy, nothing sent', $e !== null && isset($e->errors['busy']) && atSesar($fake, 'sesarmint-t6') === 0);
pg_close($pg2);
$res = $mint->mintOne($A, 'sesarmint-t6', $CH);
check('lock released after each mint (next one works)', !empty($res['igsn']));

$fake->expireAccessTokens();
mk('sesarmint-t7', $A);
$res = $mint->mintOne($A, 'sesarmint-t7', $CH);
check('expired access token refreshed transparently', !empty($res['igsn']));

// ===========================================================================
section('HTTP: sesar_mint.php refusals (forged sessions, no SESAR calls)');
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
$r = http('POST', '/sesar_mint.php', null, array('action' => 'plan', 'sample_ids' => array('x')));
check('no session -> 401', $r['status'] === 401 && $r['json']['error'] === 'not_authenticated', $r['body']);
$r = http('POST', '/sesar_mint.php', $other, array('action' => 'mint', 'sample_id' => 'sesarmint-t7'));
check('non-pilot -> 403 (server-side gate)', $r['status'] === 403 && $r['json']['error'] === 'not_allowed', $r['body']);
$r = http('GET', '/sesar_mint.php', $pilot);
check('GET -> 405', $r['status'] === 405);
$r = http('POST', '/sesar_mint.php', $pilot, 'not json');
check('bad JSON -> 400', $r['status'] === 400);
$r = http('POST', '/sesar_mint.php', $pilot, array('action' => 'explode'));
check('unknown action -> 400', $r['status'] === 400 && $r['json']['error'] === 'unknown_action');
$r = http('POST', '/sesar_mint.php', $pilot, array('action' => 'plan', 'sample_ids' => array()));
check('plan with no samples -> 400', $r['status'] === 400 && $r['json']['error'] === 'validation');
$r = http('POST', '/sesar_mint.php', $pilot, array('action' => 'mint', 'sample_id' => 'sesarmint-t7', 'choices' => array()));
check("pilot minting someone else's sample -> 404, nothing sent", $r['status'] === 404 && $r['json']['error'] === 'not_found', $r['body']);

section('HTTP: a form on another site cannot act for a logged-in user (JSON content type required)');
function httpAs($path, $sid, $type, $body) {
	$ch = curl_init('http://localhost' . $path);
	$h = array('Cookie: PHPSESSID=' . $sid, 'Content-Type:' . ($type === null ? '' : ' ' . $type));
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_TIMEOUT => 30,
		CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h));
	$out = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('status' => $code, 'body' => $out, 'json' => json_decode($out, true));
}
// What a text/plain form posts: valid JSON, with the form's "=" tucked into a value.
$forged = '{"action":"status","sample_id":"sesarmint-t7","x":"="}';
foreach (array('/sesar_mint.php', '/sesar_pull.php', '/sesar_push.php', '/sesar_deactivate.php', '/sesar_connect.php') as $ep) {
	$bad = array();
	foreach (array('text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', null) as $type) {
		$r = httpAs($ep, $pilot, $type, $forged);
		if ($r['status'] !== 415 || $r['json']['error'] !== 'content_type') $bad[] = ($type === null ? 'none' : $type) . ' -> ' . $r['status'];
	}
	check("$ep refuses every content type a form can send (415)", empty($bad), $bad);
	$r = httpAs($ep, $pilot, 'application/json; charset=UTF-8', '{"action":"explode"}');
	check("$ep still takes JSON (with a charset)", $r['status'] === 400 && $r['json']['error'] === 'unknown_action', $r['body']);
}

} finally {
	SesarAccess::setEnvironmentForTests(null);
	cleanup();
	@unlink($STATE);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
