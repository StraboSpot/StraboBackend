<?php
/**
 * File: smoke_test_heal_ownerless.php
 * Description: Permanent smoke suite for the ownerless-project regression
 *              (insertProject stopped creating the HAS_PROJECT ownership
 *              edge 2025-12-06 → 2026-08-04; ownerless projects are
 *              invisible to the User-anchored StraboSearch walk).
 *
 *              Covers all three shipped pieces:
 *                1. insertProject edge restoration — new project gets the
 *                   edge; re-upload keeps exactly one (CREATE UNIQUE);
 *                   deleted edge is self-healed by the update path.
 *                2. heal_ownerless_projects.php — classification
 *                   (HEALABLE / DUP-SHADOW same-user only / NO-USER),
 *                   detect exit codes, --apply creates edges, idempotent.
 *                   Cross-user same-id copies (class projects — Strabo ids
 *                   are NOT globally unique) must be HEALABLE, not shadows.
 *                3. verify_extended [sanity] ownerless check — reports the
 *                   drift pre-heal, clean post-heal (scoped run).
 *
 *              Hermetic: userpkeys 94595 (owner), 94596 (ghost — no :User
 *              node), 94597 (decoy owner of a same-id copy); numeric id
 *              prefix 94595xxxxx. Zero residue on exit.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_heal_ownerless.php
 *
 * @package    StraboSpot Web Site — StraboSearch
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/db/strabospotclass.php';

$UPK       = 94595;      // fixture owner (exists as :User)
$GHOST_UPK = 94596;      // no :User node — NO-USER classification
$DECOY_UPK = 94597;      // owns a same-id copy — cross-user legitimacy
$P_INSERT  = 94595000111; // insertProject probe project
$P_HEAL    = 94595000222; // ownerless, healable
$P_SHADOW  = 94595000333; // ownerless + same-user owned sibling (dup shadow)
$P_GHOST   = 94595000444; // ownerless, userpkey has no :User
$P_CROSS   = 94595000555; // ownerless under 94595; owned copy under decoy

$HEAL = '/srv/app/www/searchdb/heal_ownerless_projects.php';
$VERIFY = '/srv/app/www/searchdb/verify_extended.php';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

/** Run a CLI tool, return array(exitCode, output). */
function run($cmd) {
	exec($cmd . ' 2>&1', $out, $code);
	return array($code, implode("\n", $out));
}

function edgeCount($neodb, $upk, $pid) {
	return (int)$neodb->get_var(
		"MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project {id: $pid}) RETURN count(*)");
}

function cleanup($db, $neodb) {
	global $UPK, $GHOST_UPK, $DECOY_UPK;
	$neodb->query("MATCH (p:Project) WHERE toString(p.id) =~ '94595000.*' DETACH DELETE p");
	$neodb->query("MATCH (u:User) WHERE u.userpkey IN [$UPK, $GHOST_UPK, $DECOY_UPK] DETACH DELETE u");
	$db->query("DELETE FROM strabosearch.item_hit WHERE project_userpkey IN ($UPK, $GHOST_UPK, $DECOY_UPK)");
	$db->query("DELETE FROM project WHERE user_pkey IN ($UPK, $GHOST_UPK, $DECOY_UPK)");
	$db->query("DELETE FROM users WHERE pkey IN ($UPK, $GHOST_UPK, $DECOY_UPK)");
}

// ===========================================================================
section('0. Cleanup + seed fixtures');

cleanup($db, $neodb);
$db->query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active)
	VALUES ($UPK, 'healfx', 'owner', 'healfx-$UPK@test.strabospot.org', 'x', 'x', false),
	       ($DECOY_UPK, 'healfx', 'decoy', 'healfx-$DECOY_UPK@test.strabospot.org', 'x', 'x', false)");
$neodb->query("CREATE (u:User {userpkey: $UPK, email: 'healfx-$UPK@test.strabospot.org'})");
$neodb->query("CREATE (u:User {userpkey: $DECOY_UPK, email: 'healfx-$DECOY_UPK@test.strabospot.org'})");

// Ownerless healable project (the regression population).
$neodb->query("CREATE (p:Project {id: $P_HEAL, userpkey: $UPK, desc_project_name: 'healfx healable'})");
// Dup shadow: ownerless node + SAME-USER owned sibling with the same id.
$neodb->query("CREATE (p:Project {id: $P_SHADOW, userpkey: $UPK, desc_project_name: 'healfx shadow orphan'})");
$neodb->query("CREATE (p:Project {id: $P_SHADOW, userpkey: $UPK, desc_project_name: 'healfx shadow owned'})");
$neodb->query("MATCH (u:User {userpkey: $UPK}), (p:Project {id: $P_SHADOW, desc_project_name: 'healfx shadow owned'})
               CREATE (u)-[:HAS_PROJECT]->(p)");
// Ghost: userpkey with no :User node.
$neodb->query("CREATE (p:Project {id: $P_GHOST, userpkey: $GHOST_UPK, desc_project_name: 'healfx ghost'})");
// Cross-user copy: decoy OWNS one; 94595's copy is ownerless → healable.
$neodb->query("CREATE (p:Project {id: $P_CROSS, userpkey: $DECOY_UPK, desc_project_name: 'healfx cross owned'})");
$neodb->query("MATCH (u:User {userpkey: $DECOY_UPK}), (p:Project {id: $P_CROSS, userpkey: $DECOY_UPK})
               CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("CREATE (p:Project {id: $P_CROSS, userpkey: $UPK, desc_project_name: 'healfx cross copy'})");
echo "  seeded 6 projects under upks $UPK/$GHOST_UPK/$DECOY_UPK\n";

// ===========================================================================
section('1. insertProject creates + self-heals the ownership edge');

$strabo = new StraboSpot($neodb, $UPK, $db);
$json = json_encode(array(
	'id' => $P_INSERT,
	'description' => array('project_name' => 'healfx insert probe'),
	'modified_timestamp' => round(microtime(true) * 1000)));

$strabo->insertProject($json);
check('new project gets HAS_PROJECT edge', edgeCount($neodb, $UPK, $P_INSERT) === 1,
	'count=' . edgeCount($neodb, $UPK, $P_INSERT));

$strabo->insertProject($json);
check('re-upload keeps exactly one edge (CREATE UNIQUE)', edgeCount($neodb, $UPK, $P_INSERT) === 1);

$neodb->query("MATCH (u:User {userpkey: $UPK})-[r:HAS_PROJECT]->(p:Project {id: $P_INSERT}) DELETE r");
$strabo->insertProject($json);
check('update path self-heals a missing edge', edgeCount($neodb, $UPK, $P_INSERT) === 1);

// ===========================================================================
section('2. heal script — detect classification');

list($code, $out) = run("php $HEAL --userpkey=$UPK");
check('detect exits 1 with healable drift', $code === 1, "exit=$code");
check('healable project reported', strpos($out, "id=$P_HEAL") !== false && strpos($out, 'HEALABLE') !== false);
check('cross-user copy is HEALABLE (not shadow)',
	preg_match('/HEALABLE\s+upk=' . $UPK . '\s+id=' . $P_CROSS . '/', $out) === 1);
check('same-user shadow reported as DUP-SHADOW',
	preg_match('/DUP-SHADOW\s+upk=' . $UPK . '\s+id=' . $P_SHADOW . '/', $out) === 1);
check('shadow NOT in healable list', preg_match('/HEALABLE\s+upk=' . $UPK . '\s+id=' . $P_SHADOW . '/', $out) === 0);

list($code, $out) = run("php $HEAL --userpkey=$GHOST_UPK");
check('ghost owner reported as NO-USER', strpos($out, 'NO-USER') !== false && strpos($out, "id=$P_GHOST") !== false);
check('no healable under ghost scope → exit 0', $code === 0, "exit=$code");

// ===========================================================================
section('3. verify_extended sanity sees the drift pre-heal');

list($code, $out) = run("php $VERIFY --only=sanity --source-userpkey=$UPK");
check('sanity reports ownerless drift pre-heal', strpos($out, 'ownerless Field projects') !== false);
check('pre-heal drift count is 2 (healable only)', preg_match('/ownerless Field projects[^:]*: 2\b/', $out) === 1);

// ===========================================================================
section('4. heal --apply');

list($code, $out) = run("php $HEAL --userpkey=$UPK --apply");
check('apply exits 0', $code === 0, "exit=$code");
check('apply created 2 edges', strpos($out, 'created 2 edge(s)') !== false);
check('healable project now owned', edgeCount($neodb, $UPK, $P_HEAL) === 1);
check('cross-user copy now owned by 94595', edgeCount($neodb, $UPK, $P_CROSS) === 1);
check('decoy copy untouched (still 1 edge)', edgeCount($neodb, $DECOY_UPK, $P_CROSS) === 1);
$shadowEdges = (int)$neodb->get_var(
	"MATCH (:User)-[r:HAS_PROJECT]->(p:Project {id: $P_SHADOW}) RETURN count(r)");
check('shadow pair untouched (1 edge total)', $shadowEdges === 1, "edges=$shadowEdges");

list($code, $out) = run("php $HEAL --userpkey=$UPK");
check('re-detect clean after apply (exit 0)', $code === 0, "exit=$code");

list($code, $out) = run("php $VERIFY --only=sanity --source-userpkey=$UPK");
check('sanity clean post-heal', strpos($out, 'ownerless Field projects') === false);

// ===========================================================================
section('5. Cleanup + residue check');

cleanup($db, $neodb);
$residue = (int)$neodb->get_var("MATCH (p:Project) WHERE toString(p.id) =~ '94595000.*' RETURN count(p)")
	+ (int)$neodb->get_var("MATCH (u:User) WHERE u.userpkey IN [$UPK, $GHOST_UPK, $DECOY_UPK] RETURN count(u)")
	+ (int)$db->get_var("SELECT count(*) FROM users WHERE pkey IN ($UPK, $GHOST_UPK, $DECOY_UPK)")
	+ (int)$db->get_var("SELECT count(*) FROM project WHERE user_pkey IN ($UPK, $GHOST_UPK, $DECOY_UPK)");
check('zero residue', $residue === 0, "got $residue");

// ===========================================================================
echo PHP_EOL . str_repeat('=', 70) . PHP_EOL;
if ($failures) {
	echo 'FAILURES (' . count($failures) . '):' . PHP_EOL;
	foreach ($failures as $f) echo '  - ' . $f . PHP_EOL;
	exit(1);
}
echo 'ALL CHECKS PASSED.' . PHP_EOL;
exit(0);
