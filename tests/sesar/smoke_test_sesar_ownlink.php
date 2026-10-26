<?php
/**
 * File: tests/sesar/smoke_test_sesar_ownlink.php
 * Description: Linking IGSNs that are already in a sample's IGSN field and
 *              come from the user's OWN SESAR account (mostly registered in
 *              StraboField through its own SESAR connection; Claire 10-26):
 *              SesarPull::ownMatches (who qualifies, who is skipped and why,
 *              no SESAR call when nothing qualifies, writes nothing) and
 *              SesarPull::linkOwn (link only, sample unchanged, refusals),
 *              plus the sesar_pull.php refusals for the two new actions.
 *
 *              Unit part talks ONLY to FakeSesar. Fixture users 94790-94791,
 *              samples "sesarown-*".
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_ownlink.php
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

$U = array(94790, 94791);
$STATE = '/tmp/fake_sesar_ownlink.json';
$KEY = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

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
	global $db, $U;
	$in = implode(',', array_map('intval', $U));
	$rows = $db->get_results("SELECT id, userpkey FROM strabosamples.samples WHERE userpkey IN ($in)");
	foreach ((is_array($rows) ? $rows : array()) as $r) StraboSearchSync::removeSample($db, $r->id, (int)$r->userpkey);
	$db->query("DELETE FROM strabosamples.sesar_registrations WHERE sample_userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sesar_connections WHERE userpkey IN ($in)");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
}
function mk($id, $owner, $igsn, $name = null) {
	global $db;
	$db->prepare_query(
		"INSERT INTO strabosamples.samples (id, userpkey, name, igsn, latitude, longitude, created_by, modified_by)
		 VALUES ($1, $2, $3, $4, 38.95, -95.25, $2, $2)",
		array($id, $owner, $name !== null ? $name : $id, $igsn)
	);
}
function spine($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($id, $owner));
}
function reg($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.sesar_registrations WHERE sample_id = $1 AND sample_userpkey = $2 AND active", array($id, $owner));
}
function ids(array $rows) { $x = array_column($rows, 'sample_id'); sort($x); return $x; }

cleanup();
foreach ($U as $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Own', 'Fixture', $2, 'x', 'x', TRUE, FALSE)",
		array($u, "sesarown$u@test.strabospot.org"));
}

try {
	SesarAccess::setEnvironmentForTests('sandbox');
	$fake = new FakeSesar($STATE, true);
	$client = new SesarClient('sandbox', $fake);
	$conn = new SesarConnection($db, $client, $KEY);
	$views = new SesarSampleView($db, null, function () { return null; });
	$pull = new SesarPull($db, $client, $conn, $views, null);

	$A = $U[0]; $B = $U[1];
	$ORCID_A = '0000-0001-0000-0090';
	$fake->addOrcidUser('idtok-A', $ORCID_A, true, array('IEFAK'));
	$conn->connectWithOrcid($A, 'idtok-A', $ORCID_A);
	$ORCID_B = '0000-0001-0000-0091';
	$fake->addOrcidUser('idtok-B', $ORCID_B, true, array('IEFAK'));
	$conn->connectWithOrcid($B, 'idtok-B', $ORCID_B);

	$base = array('_owner' => $ORCID_A, 'sesar_code' => 'IEFAK', 'latitude' => '38.95000000', 'longitude' => '-95.25000000',
		'object_type' => 'General sample types > Individual sample', 'last_update_date' => '2026-10-26T12:00:00Z', 'is_draft' => false, 'is_pending_review' => false);
	foreach (array('C01', 'C02', 'C04', 'C05', 'C06') as $n) $fake->seedSample('10.58052/IEFAK0' . $n, $base + array('name' => 'At SESAR ' . $n));
	$fake->seedSample('10.58052/IEFAK0C07', array('is_draft' => true) + $base + array('name' => 'Draft at SESAR'));
	$fake->seedSample('10.58052/IEZZZ0C03', array('_owner' => 'someone-else') + $base + array('name' => 'Foreign'));

	mk('sesarown-full', $A, '10.58052/IEFAK0C01', 'Full form');           // qualifies
	mk('sesarown-bare', $A, 'iefak0c02', 'Bare lower case');              // qualifies (normalized)
	mk('sesarown-foreign', $A, '10.58052/IEZZZ0C03', 'Foreign IGSN');      // another account's: left alone
	mk('sesarown-twin1', $A, '10.58052/IEFAK0C04', 'Twin one');            // two samples hold one IGSN: skipped
	mk('sesarown-twin2', $A, 'IEFAK0C04', 'Twin two');
	mk('sesarown-linked', $A, '10.58052/IEFAK0C05', 'Linked already');     // has a registration: not listed
	mk('sesarown-dupe', $A, '10.58052/IEFAK0C05', 'Holds a linked IGSN');  // skipped: IGSN linked elsewhere
	mk('sesarown-junk', $A, 'n/a', 'Junk');                                // not an IGSN: ignored
	mk('sesarown-unknown', $A, '10.58052/IEFAK0C99', 'Not at SESAR');      // not in the account: ignored
	mk('sesarown-draft', $A, '10.58052/IEFAK0C07', 'Draft');               // qualifies, state draft
	mk('sesarown-b', $B, '10.58052/IEFAK0C06', 'B holds A\'s IGSN');       // B: not in B's account
	$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, sesar_code, origin, access, state, created_by)
		VALUES ('sesarown-linked', $1, 'sandbox', '10.58052/IEFAK0C05', 'IEFAK', 'linked', 'managed', 'active', $1)", array($A));

	section('ownMatches');
	$before = count($fake->calls(''));
	$m = $pull->ownMatches($A);
	check('qualifying samples: full form, bare lower case, draft', ids($m['rows']) === array('sesarown-bare', 'sesarown-draft', 'sesarown-full'), $m['rows']);
	$byId = array_column($m['rows'], null, 'sample_id');
	check('IGSN normalized to the full form', $byId['sesarown-bare']['igsn'] === '10.58052/IEFAK0C02', $byId['sesarown-bare']);
	check('SESAR state carried (draft)', $byId['sesarown-draft']['state'] === 'draft' && $byId['sesarown-full']['state'] === 'registered');
	check('skipped: both twins + the one holding a linked IGSN', ids($m['skipped']) === array('sesarown-dupe', 'sesarown-twin1', 'sesarown-twin2'), $m['skipped']);
	$sk = array_column($m['skipped'], null, 'sample_id');
	check('twin reason says only one sample can hold it', strpos($sk['sesarown-twin1']['reason'], 'only one sample') !== false, $sk['sesarown-twin1']);
	check('linked-elsewhere reason', strpos($sk['sesarown-dupe']['reason'], 'already linked') !== false, $sk['sesarown-dupe']);
	check("another account's IGSN, junk, unknown and linked samples never listed",
		count(array_intersect(array_merge(ids($m['rows']), ids($m['skipped'])), array('sesarown-foreign', 'sesarown-junk', 'sesarown-unknown', 'sesarown-linked'))) === 0);
	check('ownMatches writes nothing', reg('sesarown-full', $A) === null && reg('sesarown-bare', $A) === null);
	check('ownMatches made SESAR list calls only', count(array_filter(array_slice($fake->calls(''), $before), function ($c) { return $c['method'] !== 'GET'; })) === 0);
	$mB = $pull->ownMatches($B);
	check("B: A's IGSN is not in B's account -> nothing", $mB['rows'] === array() && $mB['skipped'] === array(), $mB);

	$db->query("DELETE FROM strabosamples.sesar_connections WHERE userpkey = $B");
	$db->query("UPDATE strabosamples.samples SET igsn = NULL WHERE userpkey = $B");
	$before = count($fake->calls(''));
	$mB = $pull->ownMatches($B);
	check('no unlinked SESAR IGSN -> no SESAR call at all (works even when not connected)', count($fake->calls('')) === $before && $mB['rows'] === array());

	section('linkOwn');
	$r = $pull->linkOwn($A, 'sesarown-full');
	$g = reg('sesarown-full', $A);
	check('linked: ok, managed', !empty($r['ok']) && $r['access'] === 'managed', $r);
	check('registration: origin linked, active, IGSN, snapshot', $g !== null && $g->origin === 'linked' && $g->access === 'managed'
		&& $g->igsn === '10.58052/IEFAK0C01' && $g->snapshot !== null, $g);
	check('nothing applied to the sample', $r['applied'] === array() && spine('sesarown-full', $A)->name === 'Full form');
	$r = $pull->linkOwn($A, 'sesarown-bare');
	check('bare form links to the full IGSN; IGSN field left as typed', reg('sesarown-bare', $A)->igsn === '10.58052/IEFAK0C02'
		&& spine('sesarown-bare', $A)->igsn === 'iefak0c02', reg('sesarown-bare', $A));
	$e = err(function () use ($pull, $A) { $pull->linkOwn($A, 'sesarown-full'); });
	check('again -> 409 already linked', $e !== null && $e->status === 409);
	$e = err(function () use ($pull, $A) { $pull->linkOwn($A, 'sesarown-foreign'); });
	check("another account's IGSN -> 404, not linked", $e !== null && $e->status === 404 && reg('sesarown-foreign', $A) === null);
	$e = err(function () use ($pull, $A) { $pull->linkOwn($A, 'sesarown-junk'); });
	check('not an IGSN -> 400', $e !== null && $e->status === 400);
	$e = err(function () use ($pull, $A) { $pull->linkOwn($A, 'sesarown-b'); });
	check("someone else's sample -> 404", $e !== null && $e->status === 404);
	$e = err(function () use ($pull, $A) { $pull->linkOwn($A, 'sesarown-dupe'); });
	check('IGSN already linked to another sample -> refused (409)', $e !== null && $e->status === 409 && reg('sesarown-dupe', $A) === null);
	$pull->linkOwn($A, 'sesarown-draft');
	$m = $pull->ownMatches($A);
	check('after linking: nothing left to link, twins still reported', $m['rows'] === array() && count($m['skipped']) === 3, $m);

	section('sesar_pull.php refusals');
	foreach (array('own_matches' => array(), 'own_link' => array('sample_id' => 'sesarown-twin1')) as $act => $extra) {
		$ch = curl_init('http://localhost/sesar_pull.php');
		curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
			CURLOPT_POSTFIELDS => json_encode(array('action' => $act) + $extra)));
		curl_exec($ch);
		check("$act: no session -> 401", curl_getinfo($ch, CURLINFO_HTTP_CODE) === 401);
		curl_close($ch);
	}
	check('refused calls wrote nothing', reg('sesarown-twin1', $A) === null);

} finally {
	SesarAccess::setEnvironmentForTests(null);
	cleanup();
	@unlink($STATE);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
