<?php
/**
 * File: tests/sesar/smoke_test_sesar_foundation.php
 * Description: Phase 2 suite for the StraboSamples IGSN (SESAR) foundation
 *              (docs/StraboSamples_IGSN_Feature_Request/IGSN_Build_Plan.md):
 *              soft-launch gate + environment default, token encryption,
 *              SesarClient request shapes and error kinds, SesarConnection
 *              storage / refresh / rotation (incl. two processes refreshing
 *              at once), SesarMapper (D2 suggestions + forward mapping, D5
 *              pull proposals, D6 fingerprint + push patch), SesarVocab cache.
 *
 *              Talks ONLY to tests/sesar/FakeSesar.php (never the network).
 *              Fixture users 94710-94712. Needs the DDL in
 *              samplesdb/schema/sesar.sql.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_foundation.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarAccess.php';
require_once 'includes/sesar/SesarCrypto.php';
require_once 'includes/sesar/SesarClient.php';
require_once 'includes/sesar/SesarConnection.php';
require_once 'includes/sesar/SesarMapper.php';
require_once 'includes/sesar/SesarVocab.php';
require_once __DIR__ . '/FakeSesar.php';

$U1 = 94710; $U2 = 94711; $U3 = 94712;
$USERS = array($U1, $U2, $U3);
$STATE = '/tmp/fake_sesar_foundation.json';
$KEY = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function throwsKind($fn, $kind) {
	try { $fn(); return 'no exception'; }
	catch (SesarError $e) { return $e->kind === $kind ? true : ('kind ' . $e->kind . ': ' . $e->getMessage()); }
}
function cleanup() {
	global $db, $USERS;
	$in = implode(',', array_map('intval', $USERS));
	$db->query("DELETE FROM strabosamples.sesar_connections WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sesar_vocab_cache WHERE environment = 'sandbox' AND vocab LIKE 'test-%'");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
}

cleanup();
foreach ($USERS as $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Sesar', 'Fixture', $2, 'x', 'x', TRUE, FALSE)",
		array($u, "sesar$u@test.strabospot.org"));
}

try {

// ===========================================================================
section('Gate + environment (D10)');
check('Jason (3) may use it', SesarAccess::canUse(3));
check('Claire (7217) may use it', SesarAccess::canUse(7217));
check('string "3" (session value) is cast', SesarAccess::canUse('3'));
check('fixture user may not', !SesarAccess::canUse($U1));
check('0 / null may not', !SesarAccess::canUse(0) && !SesarAccess::canUse(null));
$saved = isset($GLOBALS['sesar_env']) ? $GLOBALS['sesar_env'] : null;
unset($GLOBALS['sesar_env']);
check('missing $sesar_env means sandbox', SesarAccess::environment() === 'sandbox');
$GLOBALS['sesar_env'] = 'prod';
check('unknown $sesar_env means sandbox (a typo never registers real IGSNs)', SesarAccess::environment() === 'sandbox');
$GLOBALS['sesar_env'] = 'production';
check('production is honored', SesarAccess::environment() === 'production'
	&& SesarAccess::apiBase() === 'https://api.geosamples.org/api/');
$GLOBALS['sesar_env'] = $saved;
check('dev config is sandbox', SesarAccess::environment() === 'sandbox', SesarAccess::environment());
check('dev config is complete (client id, secret, key)', SesarAccess::isConfigured());
check('landing URL keeps the DOI slash', SesarAccess::landingUrl('10.58052/IEJMA0001', 'sandbox')
	=== 'https://app-sandbox.geosamples.org/sample/igsn/10.58052/IEJMA0001');

// ===========================================================================
section('Token encryption at rest (D1)');
$sealed = SesarCrypto::seal('secret-refresh-token', $KEY);
check('sealed value is versioned and hides the plaintext', strpos($sealed, 'v1:') === 0 && strpos($sealed, 'secret-refresh') === false);
check('round trip', SesarCrypto::open($sealed, $KEY) === 'secret-refresh-token');
check('two seals differ (random nonce)', SesarCrypto::seal('x', $KEY) !== SesarCrypto::seal('x', $KEY));
check('wrong key opens to null', SesarCrypto::open($sealed, random_bytes(32)) === null);
$tampered = substr($sealed, 0, -4) . (substr($sealed, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
check('tampered value opens to null', SesarCrypto::open($tampered, $KEY) === null);
check('garbage / null key open to null', SesarCrypto::open('plain', $KEY) === null && SesarCrypto::open($sealed, null) === null);

// ===========================================================================
section('SesarClient request shapes + error kinds');
$fake = new FakeSesar($STATE, true);
$fake->addOrcidUser('orcid-id-token-1', '0000-0000-0000-0001', true);
$fake->addOrcidUser('orcid-id-token-noperm', '0000-0000-0000-0002', false);
$client = new SesarClient('sandbox', $fake);
$pair = $client->tokenFromOrcid('orcid-id-token-1');
$c = $fake->calls('auth/token/strabospot-web/');
check('ORCID exchange goes to the web connection name', count($c) === 1, $fake->calls());
check('ORCID exchange is FORM-encoded with field token', count($c) === 1
	&& strpos($c[0]['content_type'], 'x-www-form-urlencoded') !== false && strpos($c[0]['body'], 'token=orcid-id-token-1') === 0, $c);
check('pair has access + refresh with exp claims', SesarClient::jwtExpiry($pair['access']) > time() && SesarClient::jwtExpiry($pair['refresh']) > time());
check('unknown ORCID token -> kind auth', throwsKind(function () use ($client) { $client->tokenFromOrcid('nope'); }, 'auth'));
check('account without upload permission -> kind no_permission (guided UI)',
	throwsKind(function () use ($client) { $client->tokenFromOrcid('orcid-id-token-noperm'); }, 'no_permission'));
$fake->set('fail_next', array('auth/user/' => 0));
check('no response -> kind network', throwsKind(function () use ($client, $pair) { $client->currentUser($pair['access']); }, 'network'));
check('igsnPath keeps the DOI slash', SesarClient::igsnPath('10.58052/IEJMA0001') === '10.58052/IEJMA0001');
check('SESAR code list parsed from a plain array', SesarConnection::codeList($client->codesForCreate($pair['access'])) === array('IEFAK'));
check('unknown deactivation reason refused before any call',
	throwsKind(function () use ($client, $pair) { $client->requestDeactivation($pair['access'], '10.58052/X', 'because'); }, 'validation'));

// ===========================================================================
section('SesarConnection: connect, storage, cached access');
$conn = new SesarConnection($db, $client, $KEY);
$sum = $conn->connectWithOrcid($U1, 'orcid-id-token-1', '0000-0000-0000-0001');
check('connected summary', $sum['connected'] === true && $sum['status'] === 'connected' && $sum['orcid'] === '0000-0000-0000-0001', $sum);
check('summary carries SESAR codes and identity, no tokens', $sum['sesar_codes'] === array('IEFAK')
	&& strpos(json_encode($sum), 'refresh_token') === false && strpos(json_encode($sum), 'eyJ') === false, $sum);
$row = $db->get_row_prepared("SELECT * FROM strabosamples.sesar_connections WHERE userpkey = $1", array($U1));
check('tokens stored encrypted (no JWT text in the row)', strpos($row->refresh_token_enc, 'v1:') === 0
	&& strpos(json_encode($row), 'eyJ') === false);
check('connection row records environment + connection name', $row->environment === 'sandbox' && $row->connection_name === 'strabospot-web');
$refreshCalls = count($fake->calls('auth/token/refresh/'));
$a1 = $conn->accessToken($U1);
check('fresh access token served from cache (no refresh call)', count($fake->calls('auth/token/refresh/')) === $refreshCalls && $a1 !== '');
check('no-permission connect raises no_permission and stores nothing',
	throwsKind(function () use ($conn, $U2) { $conn->connectWithOrcid($U2, 'orcid-id-token-noperm', 'x'); }, 'no_permission') === true
	&& $db->get_var_prepared("SELECT count(*) FROM strabosamples.sesar_connections WHERE userpkey = $1", array($U2)) == 0);
check('never-connected user -> kind auth', throwsKind(function () use ($conn, $U3) { $conn->accessToken($U3); }, 'auth'));

// ===========================================================================
section('Refresh + rotation');
$db->prepare_query("UPDATE strabosamples.sesar_connections SET access_expires_at = now() - interval '1 minute' WHERE userpkey = $1", array($U1));
$oldRefresh = SesarCrypto::open($db->get_var_prepared("SELECT refresh_token_enc FROM strabosamples.sesar_connections WHERE userpkey = $1", array($U1)), $KEY);
$a2 = $conn->accessToken($U1);
$newRefresh = SesarCrypto::open($db->get_var_prepared("SELECT refresh_token_enc FROM strabosamples.sesar_connections WHERE userpkey = $1", array($U1)), $KEY);
check('expired access -> one refresh call', count($fake->calls('auth/token/refresh/')) === $refreshCalls + 1);
check('new access token returned and cached', $a2 !== $a1 && $conn->accessToken($U1) === $a2);
check('rotated refresh token stored', $newRefresh !== null && $newRefresh !== $oldRefresh);
check('old refresh token is dead at SESAR', throwsKind(function () use ($client, $oldRefresh) { $client->refresh($oldRefresh); }, 'auth'));

// Network failure during refresh keeps the stored pair.
$db->prepare_query("UPDATE strabosamples.sesar_connections SET access_expires_at = now() - interval '1 minute' WHERE userpkey = $1", array($U1));
$fake->set('fail_next', array('auth/token/refresh/' => 0));
check('refresh network failure -> kind network', throwsKind(function () use ($conn, $U1) { $conn->accessToken($U1); }, 'network'));
check('... and the stored refresh token is untouched', SesarCrypto::open($db->get_var_prepared(
	"SELECT refresh_token_enc FROM strabosamples.sesar_connections WHERE userpkey = $1", array($U1)), $KEY) === $newRefresh);
check('... connection still usable next time', $conn->accessToken($U1) !== '');

// ===========================================================================
section('Two processes refreshing at once (rotation race)');
$db->prepare_query("UPDATE strabosamples.sesar_connections SET access_expires_at = now() - interval '1 minute' WHERE userpkey = $1", array($U1));
$fake->set('refresh_delay_us', 700000);   // hold each refresh open so the children overlap
$before = count($fake->calls('auth/token/refresh/'));
$procs = array(); $pipes = array();
$startAt = microtime(true) + 3.0;   // every child connects first, then all start together
for ($i = 0; $i < 2; $i++) {
	$cmd = 'php ' . escapeshellarg(__DIR__ . '/_child_refresh.php') . ' ' . escapeshellarg($STATE) . ' ' . (int)$U1 . ' ' . escapeshellarg(base64_encode($KEY)) . ' ' . sprintf('%.6f', $startAt);
	$procs[$i] = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes[$i]);
}
$outs = array();
for ($i = 0; $i < 2; $i++) {
	$outs[$i] = json_decode(trim(stream_get_contents($pipes[$i][1])), true);
	$err = stream_get_contents($pipes[$i][2]);
	proc_close($procs[$i]);
	if ($err !== '') echo "    child $i stderr: " . substr($err, 0, 300) . "\n";
}
$fake->set('refresh_delay_us', 0);
check('both children were ready before the start instant (else the run is inconclusive)', empty($outs[0]['late']) && empty($outs[1]['late']), $outs);
check('the children overlapped in time', isset($outs[0]['start'], $outs[1]['start']) && abs($outs[0]['start'] - $outs[1]['start']) < 0.2, $outs);
check('both processes got a token', !empty($outs[0]['ok']) && !empty($outs[1]['ok']), $outs);
check('they share ONE refreshed token (the waiter re-read the row)', !empty($outs[0]['token']) && $outs[0]['token'] === $outs[1]['token'], $outs);
check('exactly one refresh call reached SESAR', count($fake->calls('auth/token/refresh/')) === $before + 1,
	count($fake->calls('auth/token/refresh/')) - $before);
check('connection still connected afterwards', $conn->summary($U1)['status'] === 'connected');

// ===========================================================================
section('Revoked refresh token -> needs_reconnect');
$cur = SesarCrypto::open($db->get_var_prepared("SELECT refresh_token_enc FROM strabosamples.sesar_connections WHERE userpkey = $1", array($U1)), $KEY);
$client->refresh($cur);   // someone else used it: now blacklisted at SESAR, our copy is stale
$db->prepare_query("UPDATE strabosamples.sesar_connections SET access_expires_at = now() - interval '1 minute' WHERE userpkey = $1", array($U1));
check('stale refresh -> kind auth (reconnect)', throwsKind(function () use ($conn, $U1) { $conn->accessToken($U1); }, 'auth'));
$sum = $conn->summary($U1);
check('status needs_reconnect, tokens cleared', $sum['status'] === 'needs_reconnect' && $sum['connected'] === false
	&& $db->get_var_prepared("SELECT refresh_token_enc FROM strabosamples.sesar_connections WHERE userpkey = $1", array($U1)) === null, $sum);
$sum = $conn->connectWithOrcid($U1, 'orcid-id-token-1', '0000-0000-0000-0001');
check('reconnect restores the connection', $sum['connected'] === true && $sum['last_error'] === null);
$conn->setLastCode($U1, 'IEFAK');
$conn->disconnect($U1);
$sum = $conn->summary($U1);
check('disconnect clears tokens, keeps remembered code', $sum['connected'] === false && $sum['status'] === 'disconnected' && $sum['last_sesar_code'] === 'IEFAK');

// ===========================================================================
section('SesarMapper: D2 suggestions');
$leaves = array('Individual sample', 'Rock hand sample', 'Core', 'Thin section', 'Oriented Core', 'Grab', 'Powder');
$s = function ($fd, $extra = array()) { return array_merge(array('id' => 'S1', 'name' => 'A', 'latitude' => 38.9, 'longitude' => -95.2, 'field_data' => $fd), $extra); };
check('Field individual_sample -> Individual sample', SesarMapper::suggestObjectType($s(array('sample_type' => 'individual_sample')), $leaves) === 'Individual sample');
check('Field oriented_core -> Oriented Core', SesarMapper::suggestObjectType($s(array('sample_type' => 'oriented_core')), $leaves) === 'Oriented Core');
check('Field rock_powder -> Powder', SesarMapper::suggestObjectType($s(array('sample_type' => 'rock_powder')), $leaves) === 'Powder');
check('Field "field" + intact rock -> Rock hand sample', SesarMapper::suggestObjectType($s(array('sample_type' => 'field', 'material_type' => 'intact_rock')), $leaves) === 'Rock hand sample');
check('Micro-linked, no Field type -> Thin section', SesarMapper::suggestObjectType($s(null, array('micro_linked' => true)), $leaves) === 'Thin section');
check('nothing known -> Individual sample', SesarMapper::suggestObjectType($s(null), $leaves) === 'Individual sample');
check('suggestion only returns labels that exist in the vocab', SesarMapper::suggestObjectType($s(array('sample_type' => 'trawl')), $leaves) === 'Individual sample');
check('vocab label casing wins', SesarMapper::suggestObjectType($s(array('sample_type' => 'core')), array('CORE', 'Individual sample')) === 'CORE');
// D2 option A: registrable names only, blank unless confident (SESAR refuses "Rock", sandbox 09-26).
$reg = array(array('label' => 'Sediment', 'synonyms' => array()), array('label' => 'Limestone', 'synonyms' => array()),
	array('label' => 'Granite', 'synonyms' => array()), array('label' => 'Biological material', 'synonyms' => array()),
	array('label' => 'Basaltic andesite', 'synonyms' => array()),
	array('label' => 'Dolomite', 'synonyms' => array('dolostone', 'pure dolomitic or magnesian carbonate sedimentary rock')));
check('material: intact rock alone -> blank (Rock is not registrable)', SesarMapper::suggestMaterial($s(array('material_type' => 'intact_rock')), $reg) === null);
check('material: fragmented rock alone -> blank', SesarMapper::suggestMaterial($s(array('material_type' => 'fragmented_roc')), $reg) === null);
check('material: spot rock name wins (granite -> Granite)', SesarMapper::suggestMaterial($s(array('material_type' => 'intact_rock'), array('field_rock_types' => array('granite'))), $reg) === 'Granite');
check('material: first registrable rock name wins', SesarMapper::suggestMaterial($s(null, array('field_rock_types' => array('mylonite', 'limestone'))), $reg) === 'Limestone');
check('material: hyphen/underscore names match (basaltic-andesite)', SesarMapper::suggestMaterial($s(null, array('field_rock_types' => array('basaltic-andesite'))), $reg) === 'Basaltic andesite'
	&& SesarMapper::suggestMaterial($s(null, array('field_rock_types' => array('Basaltic_Andesite'))), $reg) === 'Basaltic andesite');
check('material: SESAR synonym matches (dolostone -> Dolomite)', SesarMapper::suggestMaterial($s(null, array('field_rock_types' => array('dolostone'))), $reg) === 'Dolomite');
check('material: unmatched rock name + intact rock -> blank', SesarMapper::suggestMaterial($s(array('material_type' => 'intact_rock'), array('field_rock_types' => array('mylonite'))), $reg) === null);
check('material: sediment -> Sediment', SesarMapper::suggestMaterial($s(array('material_type' => 'sediment')), $reg) === 'Sediment');
check('material: carbon_or_animal -> first registrable candidate', SesarMapper::suggestMaterial($s(array('material_type' => 'carbon_or_animal')), $reg) === 'Biological material');
check('material: tephra not in the registrable list -> blank', SesarMapper::suggestMaterial($s(array('material_type' => 'tephra')), $reg) === null);
check('material: other -> no suggestion', SesarMapper::suggestMaterial($s(array('material_type' => 'other')), $reg) === null);
check('material: no vocab -> no suggestion (cannot verify)', SesarMapper::suggestMaterial($s(array('material_type' => 'sediment')), array()) === null);
check('material: plain label lists accepted', SesarMapper::suggestMaterial($s(array('material_type' => 'sediment')), array('Sediment')) === 'Sediment');
check('registrableMaterial: exact label back, any case', SesarMapper::registrableMaterial('  limestone ', $reg) === 'Limestone');
check('registrableMaterial: broad category refused', SesarMapper::registrableMaterial('Rock', $reg) === null && SesarMapper::registrableMaterial('', $reg) === null);
check('registrableMaterial: synonyms are not a valid submitted value', SesarMapper::registrableMaterial('dolostone', $reg) === null);

// ===========================================================================
section('SesarMapper: D2 forward mapping');
check('blocker: no location', SesarMapper::mintBlockers(array('name' => 'A', 'latitude' => null, 'longitude' => null)) === array('no_location'));
check('blocker: no name', SesarMapper::mintBlockers(array('name' => '  ', 'latitude' => 1, 'longitude' => 2)) === array('no_name'));
check('blocker: out-of-range location', SesarMapper::mintBlockers(array('name' => 'A', 'latitude' => 91, 'longitude' => 2)) === array('bad_location'));
check('no blockers for a good sample', SesarMapper::mintBlockers(array('name' => 'A', 'latitude' => 0, 'longitude' => 0)) === array());
$sample = array('id' => str_repeat('x', 120), 'name' => '  KS-001  ', 'description' => 'granite chip', 'latitude' => 38.95717839, 'longitude' => -95.2552,
	'display_sample_purpose' => 'fabric___micro', 'parent_igsn' => '10.58052/IEFAK0001',
	'field_data' => array('collection_date' => '2025-07-12T04:45:10.722Z'), 'latitude_end' => 38.96, 'longitude_end' => -95.25);
$o = SesarMapper::ownedFields($sample);
check('name trimmed', $o['name'] === 'KS-001');
check('coordinates are 6-place decimal strings', $o['latitude'] === '38.957178' && $o['longitude'] === '-95.2552', $o);
check('LineString end coordinates included', $o['latitude_end'] === '38.96' && $o['longitude_end'] === '-95.25');
check('purpose sent as the Field LABEL, not the stored name', $o['purpose'] === 'fabric / microstructure', $o['purpose']);
check('collection date -> SESAR date-time + precision', $o['sampling_start_date'] === '2025-07-12T04:45:10Z' && $o['sampling_date_precision'] === 'time');
check('external_sample_id truncated to 100', strlen($o['external_sample_id']) === 100);
check('parent IGSN carried', $o['parent_sample'] === '10.58052/IEFAK0001');
$p = SesarMapper::registrationPayload($sample, array('sesar_code' => 'IEFAK', 'object_type' => 'Rock hand sample', 'general_material_type' => 'Limestone', 'collector' => 'Claire Martin'));
check('payload adds code, object type, material, collector', $p['sesar_code'] === 'IEFAK' && $p['object_type'] === 'Rock hand sample'
	&& $p['general_material_type'] === 'Limestone' && $p['collectors'] === array(array('individual' => array('label' => 'Claire Martin'))));
$bare = SesarMapper::registrationPayload(array('id' => 'S2', 'name' => 'B', 'latitude' => 1, 'longitude' => 2), array('sesar_code' => 'IEFAK', 'object_type' => 'Core'));
check('optional fields omitted when empty', !isset($bare['sample_description']) && !isset($bare['purpose']) && !isset($bare['collectors'])
	&& !isset($bare['general_material_type']) && !isset($bare['sampling_start_date']) && !isset($bare['parent_sample']), $bare);

// ===========================================================================
section('SesarMapper: stored IGSN values are free text (classify before any SESAR use)');
$cls = function ($v) { $c = SesarMapper::classifyIgsn($v); return $c['kind'] . ($c['normalized'] !== null ? ':' . $c['normalized'] : ''); };
check('canonical SESAR IGSN', $cls('10.58052/IEJMA0002') === 'sesar:10.58052/IEJMA0002');
check('DOI URL, lower case -> normalized', $cls(' https://doi.org/10.58052/iejma0002 ') === 'sesar:10.58052/IEJMA0002');
check('igsn.org URL + igsn: label -> normalized', $cls('https://igsn.org/IEJMA0002') === 'sesar:10.58052/IEJMA0002' && $cls('IGSN: IEJMA0002') === 'sesar:10.58052/IEJMA0002');
check('bare 9-character IGSN gets the prefix SESAR needs', $cls('IEJMA0002') === 'sesar:10.58052/IEJMA0002' && $cls('hrv003m16') === 'sesar:10.58052/HRV003M16');
check('other DOI prefixes are kept for a SESAR lookup (SESAR hosts team prefixes)', $cls('10.60471/ODP01BXOT') === 'doi:10.60471/ODP01BXOT'
	&& $cls('https://doi.org/10.60510/ICDP5054EHW1001') === 'doi:10.60510/ICDP5054EHW1001');
check('legacy 3-letter namespaces without IE are valid (WHO000L1B, ABC000123)', $cls('WHO000L1B') === 'sesar:10.58052/WHO000L1B' && $cls('ABC000123') === 'sesar:10.58052/ABC000123');
check('SESAR prefix with the wrong length is invalid', $cls('10.58052/IEJMA00021') === 'invalid' && $cls('10.58052/') === 'invalid');
foreach (array('sdfbsbd', 'UC0068', 'Carr_057_UM_#19', 'testing IGSN', 'Ignshere', 'L', '96-13-D30') as $junk) {
	check("junk '$junk' is invalid", $cls($junk) === 'invalid');
}
check('blank is empty', $cls('   ') === 'empty' && $cls(null) === 'empty');

// ===========================================================================
section('SesarMapper: D6 fingerprint + push patch');
$fp = SesarMapper::fingerprint($o);
check('fingerprint stable across key order', $fp === SesarMapper::fingerprint(array_reverse($o, true)));
$o2 = $o; $o2['sample_description'] = 'granite chip, weathered';
check('fingerprint changes when an owned field changes', SesarMapper::fingerprint($o2) !== $fp);
check('fingerprint ignores coordinate formatting noise', SesarMapper::fingerprint(array_merge($o, array('latitude' => '38.95717800'))) === $fp);
// Register via the fake to get SESAR's own formatting back.
$conn->connectWithOrcid($U1, 'orcid-id-token-1', '0000-0000-0000-0001');
$acc = $conn->accessToken($U1);
$parentRec = $client->registerSample($acc, SesarMapper::registrationPayload(array('id' => 'P', 'name' => 'P', 'latitude' => 1, 'longitude' => 1), array('sesar_code' => 'IEFAK', 'object_type' => 'Core')));
$o['parent_sample'] = $parentRec['igsn'];
$rec = $client->registerSample($acc, SesarMapper::registrationPayload(array_merge($sample, array('parent_igsn' => $parentRec['igsn'])),
	array('sesar_code' => 'IEFAK', 'object_type' => 'Rock hand sample')));
check('fake SESAR assigned an IGSN under the code', strpos($rec['igsn'], '10.58052/IEFAK') === 0, $rec);
check('push patch is EMPTY right after minting (string decimals, path object type ignored)', SesarMapper::pushPatch($o, $rec) === array(), SesarMapper::pushPatch($o, $rec));
$o3 = $o; $o3['sample_description'] = 'updated'; $o3['latitude'] = '38.957179';
$patch = SesarMapper::pushPatch($o3, $rec);
check('push patch holds only the changed fields', array_keys($patch) === array('latitude', 'sample_description'), $patch);
check('push never sends lists or user-chosen fields', !isset($patch['collectors']) && !isset($patch['object_type']) && !isset($patch['sesar_code']));
$after = $client->updateSample($acc, $rec['igsn'], $patch);
check('after PATCH the patch is empty again', SesarMapper::pushPatch($o3, $after) === array(), SesarMapper::pushPatch($o3, $after));

// ===========================================================================
section('SesarMapper: D5 pull proposals');
$sesarRec = array('name' => 'KS-001', 'sample_description' => 'Granite chip from SESAR', 'purpose' => 'Geochemistry',
	'general_material_type' => 'Sediment', 'latitude' => '38.97', 'longitude' => '-95.2552');
$standalone = array('name' => 'ks-001', 'description' => '', 'display_sample_purpose' => null, 'display_sample_type' => null,
	'latitude' => null, 'longitude' => null, 'field_linked' => false);
$byField = function ($props) { $o = array(); foreach ($props as $p) $o[$p['field']] = $p; return $o; };
$pp = $byField(SesarMapper::pullProposals($standalone, $sesarRec));
check('name differing only by case = same', $pp['name']['action'] === 'same');
check('empty description = fill', $pp['description']['action'] === 'fill');
check('standalone: purpose + material fill', $pp['display_sample_purpose']['action'] === 'fill' && $pp['display_sample_type']['action'] === 'fill');
check('standalone: missing location = fill', $pp['location']['action'] === 'fill');
$linked = array('name' => 'Field name', 'description' => 'from field', 'display_sample_purpose' => 'petrology', 'display_sample_type' => 'intact_rock',
	'latitude' => 38.95, 'longitude' => -95.2552, 'field_linked' => true);
$pl = $byField(SesarMapper::pullProposals($linked, $sesarRec));
check('Field-linked: name/description still offered (overwrite)', $pl['name']['action'] === 'overwrite' && $pl['description']['action'] === 'overwrite');
check('Field-linked: purpose + material FLAGGED, never applied', $pl['display_sample_purpose']['action'] === 'flag' && $pl['display_sample_type']['action'] === 'flag');
check('Field-linked: material compared as the Field LABEL', $pl['display_sample_type']['current'] === 'intact rock');
check('Field-linked: location FLAGGED with distance (~2.2 km)', $pl['location']['action'] === 'flag'
	&& $pl['location']['distance_m'] > 2000 && $pl['location']['distance_m'] < 2400, $pl['location']);
$near = $byField(SesarMapper::pullProposals(array_merge($linked, array('latitude' => 38.97003)), $sesarRec));
check('within 10 m = same place', $near['location']['action'] === 'same', $near['location']);
check('leaf() of a hierarchical path', SesarMapper::leaf('Analytical preparations > Sectioned specimen > Thin section') === 'Thin section');

// ===========================================================================
section('SesarVocab cache');
$vocab = new SesarVocab($db, $client);
$db->query("DELETE FROM strabosamples.sesar_vocab_cache WHERE environment = 'sandbox'");
$n0 = count($fake->calls('vocab/object-types/'));
$labels = $vocab->labels(SesarVocab::OBJECT_TYPES);
check('cache miss fetches from SESAR', count($fake->calls('vocab/object-types/')) === $n0 + 1 && in_array('Rock hand sample', $labels, true));
$vocab->labels(SesarVocab::OBJECT_TYPES);
check('cache hit makes no call', count($fake->calls('vocab/object-types/')) === $n0 + 1);
$db->query("UPDATE strabosamples.sesar_vocab_cache SET fetched_at = now() - interval '8 days' WHERE environment = 'sandbox'");
$fake->set('fail_next', array('vocab/object-types/' => 503));
check('stale cache served when SESAR is down', in_array('Core', $vocab->labels(SesarVocab::OBJECT_TYPES), true));
$rm = $vocab->registrableMaterials();
$rmLabels = array_map(function ($m) { return $m['label']; }, $rm);
check('registrableMaterials drops for_registration=false terms', !in_array('Rock', $rmLabels, true) && !in_array('Igneous rock', $rmLabels, true)
	&& in_array('Limestone', $rmLabels, true) && in_array('Sediment', $rmLabels, true), $rmLabels);
$dolo = array_values(array_filter($rm, function ($m) { return $m['label'] === 'Dolomite'; }));
check('registrableMaterials splits alt_label into synonyms', count($dolo) === 1 && $dolo[0]['synonyms'] === array('dolostone', 'pure dolomitic or magnesian carbonate sedimentary rock'), $dolo);
check('live vocab feeds the suggestion (granite -> Granite)', SesarMapper::suggestMaterial(array('field_rock_types' => array('granite')), $rm) === 'Granite');
$groups = $vocab->objectTypeGroups();
check('object types grouped for the dropdown', isset($groups['General sample types']) && in_array('Rock hand sample', $groups['General sample types'], true)
	&& $groups['Material sample'] === array('Material sample') && in_array('Thin section', $groups['Analytical preparations'], true), $groups);

} catch (Throwable $e) {
	check('suite ran without an uncaught exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

cleanup();
@unlink($STATE);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
