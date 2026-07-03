<?php
/**
 * Collaboration Testing: Extended State / Role Matrix
 *
 * Fills two coverage gaps left by run_tests.php / run_merge_tests.php /
 * api_test_runner.sh, all of which use exactly ONE edit collaborator and only
 * the accepted=true state:
 *
 *   1. TWO editors on one project -> the creator-only dataset rule. During
 *      active collaboration canEditDataset() allows edits only by the dataset's
 *      creator. With a single editor this can't be distinguished from "any
 *      editor can edit any dataset". Two editors make the rule observable:
 *        - editor A may write A's dataset, NOT B's
 *        - editor B may write B's dataset, NOT A's
 *        - the owner may write the owner's dataset, NOT either editor's
 *          (surprising but intended: during active collab even the owner is
 *          bound by creator-only)
 *
 *   2. Invitation lifecycle states at the ENFORCEMENT layer (not just the
 *      website pages): pending (accepted=false) and denied/disabled both
 *      resolve to no-write, and a re-invited+re-accepted editor regains edit.
 *
 * Hermetic and self-contained (own users / project / datasets; full cleanup).
 * Drives the live /db/ REST API over HTTP (Basic Auth) for the write matrix and
 * CollaborationAuth directly for the fine-grained state resolution.
 *
 * Usage (inside the app container):
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/run_state_matrix_tests.php
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
require_once('db/services/CollaborationAuth.php');

$BASE = 'http://localhost/db';
$KNOWN_PASS = 'testpass123';
$rand = substr(bin2hex(random_bytes(6)), 0, 8);
$collabAuth = new CollaborationAuth($db, $neodb);

$pass = 0; $fail = 0; $fails = [];
function ok($cond, $msg){ global $pass, $fail, $fails; if($cond){ $pass++; echo "  \033[32mPASS\033[0m  $msg\n"; } else { $fail++; $fails[] = $msg; echo "  \033[31mFAIL\033[0m  $msg\n"; } }
function section($t){ echo "\n\033[1;34m== $t ==\033[0m\n"; }

// POST a single throwaway spot to a dataset as $email; returns HTTP code.
function postSpot($dataset, $email, $spotId){
    global $BASE, $KNOWN_PASS;
    $spot = array(
        'type' => 'Feature',
        'geometry' => array('type' => 'Point', 'coordinates' => array(-100.0, 40.0)),
        'properties' => array('id' => $spotId, 'name' => 'matrix spot')
    );
    $body = json_encode(array('type' => 'FeatureCollection', 'features' => array($spot)));
    $ch = curl_init("$BASE/datasetspots/$dataset");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_USERPWD, "$email:$KNOWN_PASS");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

// ---- seed --------------------------------------------------------------------
$seeded = array();
function seedUser($email){
    global $db, $neodb, $KNOWN_PASS, $rand, $seeded;
    $hash = substr(md5($email.$rand), 0, 21);
    $db->prepare_query(
        "INSERT INTO users (firstname,lastname,password,hash,email,active,deleted) VALUES ($1,$2,crypt($3,gen_salt('md5')),$4,$5,true,false)",
        array("SM","Matrix",$KNOWN_PASS,$hash,$email));
    $pk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1", array($email));
    $neodb->createNode(json_encode(array("userpkey"=>$pk,"email"=>$email)), "User");
    $seeded[] = $pk;
    return $pk;
}
function addCollab($projectId, $ownerPkey, $collabPkey, $level, $accepted, $disabled){
    global $db;
    $db->prepare_query(
        "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid)
         VALUES ($1,$2,$3,$4,$5,$6,$7)",
        array((string)$projectId,$ownerPkey,$collabPkey,$level,$accepted?'true':'false',$disabled?'true':'false', bin2hex(random_bytes(16))));
}

echo "Collaboration state/role matrix  (rand=$rand)\n";

// Emails MUST be lowercase: /db/index.php lowercases the Basic-Auth username
// before the users lookup, so a mixed-case seed email would never authenticate.
$pOwner  = "sm-owner-$rand@example.com";
$pEdA    = "sm-eda-$rand@example.com";
$pEdB    = "sm-edb-$rand@example.com";
$pRead   = "sm-read-$rand@example.com";
$pPend   = "sm-pend-$rand@example.com";

$owner  = seedUser($pOwner);
$edA    = seedUser($pEdA);
$edB    = seedUser($pEdB);
$reader = seedUser($pRead);
$pend   = seedUser($pPend);
echo "Users: owner=$owner edA=$edA edB=$edB reader=$reader pending=$pend\n";

$projectId = 9944000000 + rand(1, 999999);
$dsOwner = 8833000000 + rand(1, 999999);
$dsA     = $dsOwner + 1;
$dsB     = $dsOwner + 2;
$ts = time() * 1000;

// Project owned by owner. Datasets stored under owner (effectiveOwner) but
// carrying distinct created_by so the creator-only rule is testable.
$neodb->query("CREATE (p:Project {id:$projectId, desc_project_name:'Matrix P', userpkey:$owner, modified_timestamp:$ts})");
$neodb->query("MATCH (u:User {userpkey:$owner}) MATCH (p:Project {id:$projectId}) CREATE (u)-[:HAS_PROJECT]->(p)");
foreach (array(array($dsOwner,$owner),array($dsA,$edA),array($dsB,$edB)) as $d){
    $neodb->query("CREATE (d:Dataset {id:{$d[0]}, name:'ds{$d[0]}', userpkey:$owner, created_by:{$d[1]}, modified_timestamp:$ts})");
    $neodb->query("MATCH (p:Project {id:$projectId}) MATCH (d:Dataset {id:{$d[0]}}) CREATE (p)-[:HAS_DATASET]->(d)");
}
echo "Project=$projectId  dsOwner=$dsOwner dsA=$dsA dsB=$dsB\n";

addCollab($projectId, $owner, $edA,    'edit',     true,  false);
addCollab($projectId, $owner, $edB,    'edit',     true,  false);
addCollab($projectId, $owner, $reader, 'readonly', true,  false);
addCollab($projectId, $owner, $pend,   'edit',     false, false); // pending

$spotSeq = 7722000000 + rand(1, 900000);
$cleanupSpots = array();
function tryWrite($dataset, $email){ global $spotSeq, $cleanupSpots; $id = $spotSeq++; $cleanupSpots[] = $id; return postSpot($dataset, $email, $id); }

// =============================================================================
section("Two editors -> creator-only dataset rule (active collaboration)");

ok(tryWrite($dsA, $pEdA) < 300, "editor A CAN write A's own dataset");
ok(tryWrite($dsB, $pEdB) < 300, "editor B CAN write B's own dataset");
ok(tryWrite($dsB, $pEdA) == 403, "editor A CANNOT write B's dataset (403)");
ok(tryWrite($dsA, $pEdB) == 403, "editor B CANNOT write A's dataset (403)");

ok(tryWrite($dsOwner, $pOwner) < 300, "owner CAN write owner's own dataset");
ok(tryWrite($dsA, $pOwner) == 403, "owner CANNOT write editor A's dataset during active collab (403)");
ok(tryWrite($dsB, $pOwner) == 403, "owner CANNOT write editor B's dataset during active collab (403)");

$cReader = tryWrite($dsA, $pRead);
ok($cReader == 403, "readonly collaborator CANNOT write any dataset (403, got $cReader)");

// =============================================================================
section("Invitation states at the enforcement layer");

// Pending (accepted=false) -> 'none'
$ctx = $collabAuth->getProjectContext($pend, (string)$projectId);
ok($ctx->permissionLevel === 'none', "pending editor resolves to 'none' (got '{$ctx->permissionLevel}')");
ok(tryWrite($dsA, $pPend) != 200 && tryWrite($dsOwner, $pPend) != 200, "pending editor cannot write");

// Denied/disabled -> the collaborator branch requires accepted=true, so a
// disabled+unaccepted invite is 'none'; a disabled+accepted one is readonly.
$db->prepare_query("UPDATE collaborators SET disabled=true WHERE strabo_project_id=$1 AND collaborator_user_pkey=$2", array((string)$projectId, $edA));
$ctx = $collabAuth->getProjectContext($edA, (string)$projectId);
ok($ctx->permissionLevel === 'readonly', "accepted-then-disabled editor resolves to 'readonly'");
ok(tryWrite($dsA, $pEdA) == 403, "disabled editor cannot write even their own dataset (403)");

// Re-enable + confirm edit is restored (models owner re-invite path).
$db->prepare_query("UPDATE collaborators SET disabled=false, accepted=true, collaboration_level='edit' WHERE strabo_project_id=$1 AND collaborator_user_pkey=$2", array((string)$projectId, $edA));
$ctx = $collabAuth->getProjectContext($edA, (string)$projectId);
ok($ctx->permissionLevel === 'edit', "re-enabled editor regains 'edit'");
ok(tryWrite($dsA, $pEdA) < 300, "re-enabled editor CAN write their own dataset again");

// =============================================================================
section("cleanup");
foreach (array_unique($cleanupSpots) as $sid){ $neodb->query("MATCH (s:Spot {id:$sid}) DETACH DELETE s"); }
$db->prepare_query("DELETE FROM collaborators WHERE strabo_project_id=$1", array((string)$projectId));
foreach (array($dsOwner,$dsA,$dsB) as $d){ $neodb->query("MATCH (d:Dataset {id:$d}) DETACH DELETE d"); }
$neodb->query("MATCH (p:Project {id:$projectId}) DETACH DELETE p");
foreach ($seeded as $pk){ $neodb->query("MATCH (u:User) WHERE u.userpkey=$pk DETACH DELETE u"); }
// A successful spot upload mirror-writes a PG `project` row (project.user_pkey
// FKs users.pkey), so clear it before deleting the seeded users.
$pkList = "{" . implode(",", $seeded) . "}";
$db->prepare_query("DELETE FROM project WHERE user_pkey = ANY($1)", array($pkList));
$db->prepare_query("DELETE FROM users WHERE email LIKE $1", array("sm-%-$rand@example.com"));
$resid = (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE email LIKE $1", array("sm-%-$rand@example.com"));
ok($resid === 0, "cleanup: no residual users");

echo "\n========================================\n";
echo "RESULT: $pass passed, $fail failed\n";
echo "========================================\n";
if ($fail > 0){ echo "\nFailures:\n"; foreach ($fails as $m){ echo "  - $m\n"; } }
exit($fail === 0 ? 0 : 1);
