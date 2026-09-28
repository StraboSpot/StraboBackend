<?php
/**
 * File: tests/sesar/smoke_test_sesar_cleanup.php
 * Description: Phase 9 suite for the launch cleanup of sandbox IGSNs
 *              (SesarSandboxCleanup + samplesdb/tools/sesar_sandbox_cleanup.php;
 *              review Q1-Q3): which fields are cleared (tracked sandbox IGSN
 *              still in the field, compared normalized, any row state),
 *              which are left (changed field, production row, deleted
 *              sample), the report-only untracked list (testers only, "not
 *              found at production" only, lookup errors listed apart), the
 *              report-only created list, apply (lock, re-check, changelog,
 *              rows untouched), re-running after a value comes back, and the
 *              CLI's dry run / argument handling (never --apply: that would
 *              clear the real dev test IGSNs).
 *
 *              Talks ONLY to FakeSesar. Fixture users 94780-94781, samples
 *              "sesarclean-*".
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_cleanup.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarSandboxCleanup.php';
require_once 'includes/sesar/SesarDeactivate.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once __DIR__ . '/FakeSesar.php';

$U = array(94780, 94781);
$STATE = '/tmp/fake_sesar_cleanup.json';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function cleanup() {
	global $db, $U;
	$in = implode(',', array_map('intval', $U));
	$rows = $db->get_results("SELECT id, userpkey FROM strabosamples.samples WHERE userpkey IN ($in)");
	foreach ((is_array($rows) ? $rows : array()) as $r) StraboSearchSync::removeSample($db, $r->id, (int)$r->userpkey);
	$db->query("DELETE FROM strabosamples.sesar_registrations WHERE sample_userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sample_subsystem_links WHERE sample_userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sample_changelog WHERE sample_userpkey IN ($in)");
	$db->query("UPDATE strabosamples.samples SET parent_sample_id = NULL, parent_userpkey = NULL WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey IN ($in)");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
}
function mk($id, $owner, $igsn, $created = null) {
	if ($created === null && strpos($id, 'sesarclean-k') !== 0) $created = gmdate('Y-m-d H:i:s', time() - 86400);   // only k* are "created with the row"
	global $db;
	$db->prepare_query("INSERT INTO strabosamples.samples (id, userpkey, name, igsn, latitude, longitude, created_by, modified_by, created_at)
		VALUES ($1, $2, $1, $3, 38.95, -95.25, $2, $2, COALESCE($4::timestamptz, now()))", array($id, $owner, $igsn, $created));
}
function track($id, $owner, $igsn, $origin = 'minted', $state = 'active', $env = 'sandbox', $created = null) {
	global $db;
	$active = !in_array($state, array('deactivated', 'unlinked'), true);
	$db->prepare_query(
		"INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, sesar_code, origin, access, state, active, created_by, created_at)
		 VALUES ($1, $2, $3, $4, 'IEFAK', $5, 'managed', $6, $7, $2, COALESCE($8::timestamptz, now()))",
		array($id, $owner, $env, $igsn, $origin, $state, $active ? 't' : 'f', $created));
}
function spine($id, $owner) {
	global $db;
	return $db->get_var_prepared("SELECT igsn FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($id, $owner));
}
function ids(array $list) { $o = array_map(function ($i) { return $i['sample_id']; }, $list); sort($o); return $o; }
function regSnapshot() {
	global $db, $U;
	return $db->get_var("SELECT md5(string_agg(pkey || state || active::text || coalesce(igsn,'') || updated_at::text, ',' ORDER BY pkey))
	                       FROM strabosamples.sesar_registrations WHERE sample_userpkey IN (" . implode(',', $U) . ")");
}

cleanup();
foreach ($U as $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Clean', 'Fixture', $2, 'x', 'x', TRUE, FALSE)",
		array($u, "sesarclean$u@test.strabospot.org"));
}

try {

$fake = new FakeSesar($STATE, true);
$prod = new SesarClient('production', $fake);
$cleanup = new SesarSandboxCleanup($db, $prod, SesarDeactivate::serviceIgsnClearer($db, null));
$A = $U[0]; $B = $U[1];

// A = a tester (has sandbox rows). B = nobody (no rows, not on the pilot list).
mk('sesarclean-c1', $A, '10.58052/IEFAK0301');                  track('sesarclean-c1', $A, '10.58052/IEFAK0301');
mk('sesarclean-c2', $A, 'IEFAK0302');                           track('sesarclean-c2', $A, '10.58052/IEFAK0302', 'linked');
mk('sesarclean-c3', $A, 'https://doi.org/10.58052/iefak0303');  track('sesarclean-c3', $A, '10.58052/IEFAK0303', 'linked', 'unlinked');
mk('sesarclean-c4', $A, 'MY-OWN-TEXT');                         track('sesarclean-c4', $A, '10.58052/IEFAK0304');
mk('sesarclean-c5', $A, null);                                  track('sesarclean-c5', $A, '10.58052/IEFAK0305', 'minted', 'deactivated');
mk('sesarclean-c6', $A, '10.58052/IEFAK0306');                  track('sesarclean-c6', $A, '10.58052/IEFAK0306');
                                                                track('sesarclean-c6', $A, '10.58052/IEFAK0306', 'minted', 'active', 'production');
                                                                track('sesarclean-c7', $A, '10.58052/IEFAK0307');   // sample deleted
mk('sesarclean-c8', $A, '10.58052/IEFAK0308');                  track('sesarclean-c8', $A, '10.58052/IEFAK0399', 'linked', 'unlinked');
                                                                track('sesarclean-c8', $A, '10.58052/IEFAK0308', 'linked');   // relinked
mk('sesarclean-p1', $A, '10.58052/IEFAK0309');                  track('sesarclean-p1', $A, '10.58052/IEFAK0309', 'minted', 'active', 'production');

mk('sesarclean-u0', $A, '10.58052/IEFAK0400');   // lookup fails
mk('sesarclean-u1', $A, '10.58052/IEFAK0401');   // not at production -> listed
mk('sesarclean-u2', $A, '10.58052/IEFAK0402');   // found at production
mk('sesarclean-u3', $A, 'IEFAK0403');            // deactivated at production (410)
mk('sesarclean-u4', $A, 'Carr_057_UM_#19');      // junk, never asked
mk('sesarclean-u5', $A, '10.58052/IEFAK0405');   // private at production (403)
mk('sesarclean-u6', $B, '10.58052/IEFAK0501');   // not a tester
$fake->seedSample('10.58052/IEFAK0402', array('name' => 'Real', '_owner' => 'x'));
$fake->seedSample('10.58052/IEFAK0403', array('name' => 'Gone', '_owner' => 'x'));
$fake->markDeactivated('10.58052/IEFAK0403');
$fake->seedSample('10.58052/IEFAK0405', array('name' => 'Private', '_owner' => 'x', '_private' => true));
$fake->set('fail_next', array('samples/by-igsn/' => 0));

$t0 = gmdate('Y-m-d H:i:s', time() - 7200);
mk('sesarclean-k1', $A, null);          track('sesarclean-k1', $A, '10.58052/IEFAK0601', 'linked');
mk('sesarclean-k2', $A, null);          track('sesarclean-k2', $A, '10.58052/IEFAK0602', 'linked');
mk('sesarclean-k2c', $A, null);
$db->prepare_query("UPDATE strabosamples.samples SET parent_sample_id = 'sesarclean-k2', parent_userpkey = $1 WHERE id = 'sesarclean-k2c' AND userpkey = $1", array($A));
mk('sesarclean-k3', $A, null, $t0);     track('sesarclean-k3', $A, '10.58052/IEFAK0603', 'linked');   // linked 2 h after creation
mk('sesarclean-k4', $A, null);          track('sesarclean-k4', $A, '10.58052/IEFAK0604', 'minted');
mk('sesarclean-k5', $A, null);          track('sesarclean-k5', $A, '10.58052/IEFAK0605', 'linked');
mk('sesarclean-k6', $A, null);          track('sesarclean-k6', $A, '10.58052/IEFAK0606', 'linked', 'unlinked');
                                        track('sesarclean-k6', $A, '10.58052/IEFAK0607', 'linked');   // two rows, one sample
$db->prepare_query("INSERT INTO strabosamples.sample_subsystem_links (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey)
	VALUES ('sesarclean-k5', $1, 'field', '123', $1)", array($A));

// ===========================================================================
section('constructor');
$threw = false;
try { new SesarSandboxCleanup($db, new SesarClient('sandbox', $fake), function () { return true; }); } catch (InvalidArgumentException $e) { $threw = true; }
check('refuses a sandbox client for the untracked check', $threw);

// ===========================================================================
section('plan (Q1 clear list)');
$before = regSnapshot();
$plan = $cleanup->plan($U);
check('clear = tracked sandbox IGSN still in the field, full / bare / URL form, any row state, relinked sample once',
	ids($plan['clear']) === array('sesarclean-c1', 'sesarclean-c2', 'sesarclean-c3', 'sesarclean-c8'), ids($plan['clear']));
check('field holding other text is left and reported as changed', ids($plan['changed']) === array('sesarclean-c4'), $plan['changed']);
check('empty field counted as already clear (k1-k6 rows + c5)', $plan['already_clear'] === 8, $plan['already_clear']);
check('row whose sample was deleted counted', $plan['sample_gone'] === 1, $plan['sample_gone']);
check('a production row with the same IGSN keeps it (c6), production-only row ignored (p1)',
	!in_array('sesarclean-c6', ids($plan['clear']), true) && !in_array('sesarclean-p1', ids($plan['clear']), true));
check('plan changes nothing', regSnapshot() === $before && spine('sesarclean-c1', $A) === '10.58052/IEFAK0301');

section('plan (Q2 untracked, report only)');
check('only "not found at production" on a tester\'s sample is listed', ids($plan['untracked']) === array('sesarclean-u1'), $plan['untracked']);
check('listed with the normalized IGSN and the raw field', $plan['untracked'][0]['igsn'] === '10.58052/IEFAK0401' && $plan['untracked'][0]['field'] === '10.58052/IEFAK0401');
check('a failed lookup is listed apart, never as not found', ids($plan['unchecked']) === array('sesarclean-u0') && $plan['unchecked'][0]['message'] !== '', $plan['unchecked']);
$asked = array_map(function ($c) { parse_str((string)parse_url($c['url'], PHP_URL_QUERY), $q); return $q['igsn']; }, $fake->calls('by-igsn'));
check('lookups went to PRODUCTION SESAR only', count($fake->calls('by-igsn')) > 0 && count(array_filter($fake->calls('by-igsn'), function ($c) { return strpos($c['url'], 'api.geosamples.org') !== false && strpos($c['url'], 'sandbox') === false; })) === count($fake->calls('by-igsn')),
	array_map(function ($c) { return $c['url']; }, $fake->calls('by-igsn')));
check('tracked values, junk text and non-testers are never looked up',
	!in_array('10.58052/IEFAK0301', $asked, true) && !in_array('10.58052/IEFAK0501', $asked, true) && count($asked) === 5, $asked);
check('no SESAR call other than by-igsn lookups', count($fake->calls()) === count($fake->calls('by-igsn')));

section('plan (Q3 created from sandbox records, report only)');
check('only a linked row written with its sample, no links, no children; one line per sample',
	ids($plan['created']) === array('sesarclean-k1', 'sesarclean-k6'), ids($plan['created']));

// ===========================================================================
section('apply');
$clBefore = (int)$db->get_var("SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey = $A");
$db->prepare_query("UPDATE strabosamples.samples SET igsn = 'EDITED-AFTER-PLAN' WHERE id = 'sesarclean-c2' AND userpkey = $1", array($A));
$other = pg_connect("host=$dbhost dbname=$dbname user=$dbusername password=$dbpassword", PGSQL_CONNECT_FORCE_NEW);
pg_query_params($other, "SELECT pg_advisory_lock($1, hashtext($2))", array(SesarDb::LOCK_NS, $A . ':sesarclean-c8'));
$r = $cleanup->apply($plan);
pg_query_params($other, "SELECT pg_advisory_unlock($1, hashtext($2))", array(SesarDb::LOCK_NS, $A . ':sesarclean-c8'));
pg_close($other);
check('cleared c1 + c3, skipped c2 (edited since the plan), c8 busy', $r['cleared'] === 2 && $r['skipped'] === 1
	&& count($r['failed']) === 1 && $r['failed'][0]['sample_id'] === 'sesarclean-c8' && strpos($r['failed'][0]['error'], 'busy') !== false, $r);
check('fields cleared', spine('sesarclean-c1', $A) === null && spine('sesarclean-c3', $A) === null, array(spine('sesarclean-c1', $A), spine('sesarclean-c3', $A)));
check('edited field kept, left-alone fields kept', spine('sesarclean-c2', $A) === 'EDITED-AFTER-PLAN' && spine('sesarclean-c4', $A) === 'MY-OWN-TEXT'
	&& spine('sesarclean-c6', $A) === '10.58052/IEFAK0306' && spine('sesarclean-u1', $A) === '10.58052/IEFAK0401');
check('each clear went through updateSample (changelog)', (int)$db->get_var("SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey = $A") === $clBefore + 2);
check('registration rows untouched (Q1)', regSnapshot() === $before);

section('re-run');
$r2 = $cleanup->apply($cleanup->plan($U));
check('second run clears what was busy (c8)', $r2['cleared'] === 1 && spine('sesarclean-c8', $A) === null, $r2);
$db->prepare_query("UPDATE strabosamples.samples SET igsn = 'IEFAK0301' WHERE id = 'sesarclean-c1' AND userpkey = $1", array($A));   // a Field upload brings it back
$p3 = $cleanup->plan($U);
check('a sandbox IGSN that came back is found again', ids($p3['clear']) === array('sesarclean-c1'), ids($p3['clear']));
$cleanup->apply($p3);
check('and cleared again', spine('sesarclean-c1', $A) === null);

// ===========================================================================
section('CLI');
exec('php /srv/app/www/samplesdb/tools/sesar_sandbox_cleanup.php --bogus 2>&1', $o1, $x1);
check('unknown argument -> exit 2 + usage', $x1 === 2 && strpos(implode("\n", $o1), 'Usage') !== false, $o1);
exec('php /srv/app/www/samplesdb/tools/sesar_sandbox_cleanup.php 2>&1', $o2, $x2);
$txt = implode("\n", $o2);
check('dry run: exit 0, names database + environment + mode, says nothing changed', $x2 === 0 && strpos($txt, 'database:') !== false
	&& strpos($txt, 'server SESAR:') !== false && strpos($txt, 'dry run') !== false && strpos($txt, 'Dry run: nothing changed.') !== false, substr($txt, 0, 400));
check('dry run printed the three lists', strpos($txt, '1. Sandbox IGSNs to clear') !== false && strpos($txt, '2. REPORT ONLY') !== false && strpos($txt, '3. REPORT ONLY') !== false);

} catch (Throwable $e) {
	check('no exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

cleanup();
@unlink($STATE);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
