<?php
/**
 * Collaboration Testing: Endpoint Permutations
 *
 * Covers three collaboration-gated /db/ endpoints that had NO test coverage,
 * each exercising a distinct gate:
 *
 *   datasettimestamp  (POST) -> canEditDataset  (creator-only during active collab)
 *   projecttimestamp  (POST) -> canEditProjectMetadata (OWNER ONLY - the
 *                               metadata gate that even 'edit' collaborators fail)
 *   movespottodataset (POST) -> canEditDataset on BOTH source and target datasets
 *
 * Two tallies (as in e2e_collab_lifecycle.php):
 *   [FUNCTIONAL]  expected-behavior checks, should PASS.
 *   [AUTHZ PROBE] negative-authorization checks that assert the SECURE
 *                 expectation. The MoveSpot source-side probe FAILS today: the
 *                 controller resolves the spot's source dataset with
 *                 getDatasetId(), which filters by the REQUESTING user's
 *                 userpkey (strabospotclass.php:1459-1467) *before* the
 *                 effective-owner swap. A collaborator's spots are stored under
 *                 the project owner's pkey, so the lookup returns nothing and
 *                 the source-dataset permission check (MoveSpotToDatasetController
 *                 :56-64) is skipped entirely -> an editor can move another
 *                 editor's spot out of a dataset they cannot edit. The owner is
 *                 correctly blocked because owner-project data IS under the
 *                 owner's pkey, so that path is a passing positive control.
 *
 * Hermetic and self-contained; drives the live /db/ REST API over HTTP.
 *
 * Usage (inside the app container):
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/run_endpoint_permutation_tests.php
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$BASE = 'http://localhost/db';
$KNOWN_PASS = 'testpass123';
$rand = substr(bin2hex(random_bytes(6)), 0, 8);

$fpass = 0; $ffail = 0; $funcFails = [];
$findings = 0; $secok = 0; $findingList = [];

function fok($cond, $msg){ global $fpass, $ffail, $funcFails; if($cond){ $fpass++; echo "  \033[32mPASS\033[0m  $msg\n"; } else { $ffail++; $funcFails[] = $msg; echo "  \033[31mFAIL\033[0m  $msg\n"; } }
function probe($secure, $msg){ global $findings, $secok, $findingList; if($secure){ $secok++; echo "  \033[32mSECURE\033[0m  $msg\n"; } else { $findings++; $findingList[] = $msg; echo "  \033[31m⚠ FINDING\033[0m  $msg\n"; } }
function section($t){ echo "\n\033[1;34m== $t ==\033[0m\n"; }

// POST a JSON body to $url as $email; returns HTTP code.
function postJson($path, $email, $payload){
    global $BASE, $KNOWN_PASS;
    $ch = curl_init("$BASE/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_USERPWD, "$email:$KNOWN_PASS");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

// Which dataset currently contains a spot (via the production HAS_SPOT edge)?
function spotDataset($spotId){
    global $neodb;
    $r = $neodb->query("MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot {id:$spotId}) RETURN d.id as did");
    return count($r) ? $r[0]->get('did') : null;
}

// ---- seed --------------------------------------------------------------------
$seeded = array();
function seedUser($email){
    global $db, $neodb, $KNOWN_PASS, $rand, $seeded;
    $hash = substr(md5($email.$rand), 0, 21);
    $db->prepare_query(
        "INSERT INTO users (firstname,lastname,password,hash,email,active,deleted) VALUES ($1,$2,crypt($3,gen_salt('md5')),$4,$5,true,false)",
        array("EP","Perm",$KNOWN_PASS,$hash,$email));
    $pk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1", array($email));
    $neodb->createNode(json_encode(array("userpkey"=>$pk,"email"=>$email)), "User");
    $seeded[] = $pk;
    return $pk;
}
function addCollab($projectId, $ownerPkey, $collabPkey, $level){
    global $db;
    $db->prepare_query(
        "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid)
         VALUES ($1,$2,$3,$4,true,false,$5)",
        array((string)$projectId,$ownerPkey,$collabPkey,$level, bin2hex(random_bytes(16))));
}

echo "Collaboration endpoint permutations  (rand=$rand)\n";

// Lowercase emails (Basic-Auth username is lowercased before lookup).
$eOwner = "ep-owner-$rand@example.com";
$eEdA   = "ep-eda-$rand@example.com";
$eEdB   = "ep-edb-$rand@example.com";
$eRead  = "ep-read-$rand@example.com";

$owner  = seedUser($eOwner);
$edA    = seedUser($eEdA);
$edB    = seedUser($eEdB);
$reader = seedUser($eRead);
echo "Users: owner=$owner edA=$edA edB=$edB reader=$reader\n";

$projectId = 9933000000 + rand(1, 999999);
$dsOwner = 8822000000 + rand(1, 999999);
$dsA  = $dsOwner + 1;
$dsA2 = $dsOwner + 2;
$dsB  = $dsOwner + 3;
$spA = 7711000000 + rand(1, 999999);
$spB = $spA + 1;
$ts = time() * 1000;

$neodb->query("CREATE (p:Project {id:$projectId, desc_project_name:'Perm P', userpkey:$owner, modified_timestamp:$ts})");
$neodb->query("MATCH (u:User {userpkey:$owner}) MATCH (p:Project {id:$projectId}) CREATE (u)-[:HAS_PROJECT]->(p)");
foreach (array(array($dsOwner,$owner),array($dsA,$edA),array($dsA2,$edA),array($dsB,$edB)) as $d){
    $neodb->query("CREATE (dd:Dataset {id:{$d[0]}, name:'ds{$d[0]}', userpkey:$owner, created_by:{$d[1]}, modified_timestamp:$ts})");
    $neodb->query("MATCH (p:Project {id:$projectId}) MATCH (dd:Dataset {id:{$d[0]}}) CREATE (p)-[:HAS_DATASET]->(dd)");
}
// Spots linked via HAS_SPOT (production convention: addSpotToDataset default).
$neodb->query("CREATE (s:Spot {id:$spA, name:'spA', userpkey:$owner, created_by:$edA, modified_timestamp:$ts})");
$neodb->query("MATCH (d:Dataset {id:$dsA}) MATCH (s:Spot {id:$spA}) CREATE (d)-[:HAS_SPOT]->(s)");
$neodb->query("CREATE (s:Spot {id:$spB, name:'spB', userpkey:$owner, created_by:$edB, modified_timestamp:$ts})");
$neodb->query("MATCH (d:Dataset {id:$dsB}) MATCH (s:Spot {id:$spB}) CREATE (d)-[:HAS_SPOT]->(s)");
echo "Project=$projectId dsOwner=$dsOwner dsA=$dsA dsA2=$dsA2 dsB=$dsB spA=$spA spB=$spB\n";

addCollab($projectId, $owner, $edA, 'edit');
addCollab($projectId, $owner, $edB, 'edit');
addCollab($projectId, $owner, $reader, 'readonly');

$tsBody = function($v){ return array('timestamp' => $v); };

// =============================================================================
section("[FUNCTIONAL] datasettimestamp POST -> creator-only gate");

fok(postJson("datasettimestamp/$dsOwner", $eOwner, $tsBody($ts+1)) == 200, "owner CAN set timestamp on owner's dataset");
fok(postJson("datasettimestamp/$dsA", $eEdA, $tsBody($ts+2)) == 200, "editor A CAN set timestamp on A's own dataset");
fok(postJson("datasettimestamp/$dsB", $eEdA, $tsBody($ts+3)) == 403, "editor A CANNOT set timestamp on B's dataset (403)");
fok(postJson("datasettimestamp/$dsA", $eRead, $tsBody($ts+4)) == 403, "readonly CANNOT set dataset timestamp (403)");
fok(postJson("datasettimestamp/$dsA", $eOwner, $tsBody($ts+5)) == 403, "owner CANNOT set timestamp on editor A's dataset during active collab (403)");

// =============================================================================
section("[FUNCTIONAL] projecttimestamp POST -> owner-only metadata gate");

fok(postJson("projecttimestamp/$projectId", $eOwner, $tsBody($ts+10)) == 200, "owner CAN set project timestamp");
fok(postJson("projecttimestamp/$projectId", $eEdA, $tsBody($ts+11)) == 403, "edit collaborator CANNOT set project timestamp (403 - metadata is owner-only)");
fok(postJson("projecttimestamp/$projectId", $eRead, $tsBody($ts+12)) == 403, "readonly CANNOT set project timestamp (403)");

// =============================================================================
section("[FUNCTIONAL] movespottodataset POST -> both-datasets gate");

// Editor A moves their own spot from dsA to dsA2 (both created by A) -> allowed.
$code = postJson("movespottodataset", $eEdA, array('spot_id'=>$spA, 'dataset_id'=>$dsA2, 'modified_timestamp'=>$ts+20));
fok($code == 201, "editor A CAN move own spot A->A2 (both own datasets) (got $code)");
fok(spotDataset($spA) == $dsA2, "spot A actually relocated to dsA2");

// Readonly cannot move (blocked by target-dataset check).
$code = postJson("movespottodataset", $eRead, array('spot_id'=>$spA, 'dataset_id'=>$dsA2, 'modified_timestamp'=>$ts+21));
fok($code == 403 || $code == 404, "readonly CANNOT move spots (got $code)");

// Move spA back to dsA for a clean probe baseline.
$neodb->query("MATCH (:Dataset)-[r:HAS_SPOT]->(s:Spot {id:$spA}) DELETE r");
$neodb->query("MATCH (d:Dataset {id:$dsA}) MATCH (s:Spot {id:$spA}) CREATE (d)-[:HAS_SPOT]->(s)");

// =============================================================================
section("[AUTHZ PROBE] movespottodataset source-dataset enforcement");

// Positive control: owner tries to move editor A's spot (spA, in dsA) into the
// owner's own dataset. Owner is not the creator and collab is active, so the
// SOURCE check must block it. This path works because owner-project data lives
// under the owner's pkey, so getDatasetId() resolves the source.
$before = spotDataset($spB);
$code = postJson("movespottodataset", $eOwner, array('spot_id'=>$spA, 'dataset_id'=>$dsOwner, 'modified_timestamp'=>$ts+30));
probe($code == 403 && spotDataset($spA) == $dsA,
    "owner cannot move editor A's spot out of A's dataset during active collab [positive control] (got $code, spot in ".spotDataset($spA).")");

// The real probe: editor A moves editor B's spot (spB, in dsB) into A's own
// dataset dsA. A has NO edit rights on dsB. The target check passes (dsA is
// A's), and the source check should block on dsB -- but is skipped for
// collaborators, so the move succeeds.
$code = postJson("movespottodataset", $eEdA, array('spot_id'=>$spB, 'dataset_id'=>$dsA, 'modified_timestamp'=>$ts+31));
probe(spotDataset($spB) == $dsB,
    "editor A cannot move editor B's spot out of B's dataset (spB should stay in dsB; got HTTP $code, spB now in ".var_export(spotDataset($spB),true).")");

// =============================================================================
section("cleanup");
$db->prepare_query("DELETE FROM collaborators WHERE strabo_project_id=$1", array((string)$projectId));
foreach (array($spA,$spB) as $s){ $neodb->query("MATCH (s:Spot {id:$s}) DETACH DELETE s"); }
foreach (array($dsOwner,$dsA,$dsA2,$dsB) as $d){ $neodb->query("MATCH (d:Dataset {id:$d}) DETACH DELETE d"); }
$neodb->query("MATCH (p:Project {id:$projectId}) DETACH DELETE p");
foreach ($seeded as $pk){ $neodb->query("MATCH (u:User) WHERE u.userpkey=$pk DETACH DELETE u"); }
$pkList = "{" . implode(",", $seeded) . "}";
$db->prepare_query("DELETE FROM project WHERE user_pkey = ANY($1)", array($pkList));
$db->prepare_query("DELETE FROM users WHERE email LIKE $1", array("ep-%-$rand@example.com"));
$resid = (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE email LIKE $1", array("ep-%-$rand@example.com"));
fok($resid === 0, "cleanup: no residual users");

// =============================================================================
echo "\n========================================\n";
echo "FUNCTIONAL:   $fpass passed, $ffail failed\n";
echo "AUTHZ PROBES: $secok secure, \033[1;31m$findings finding(s)\033[0m\n";
echo "========================================\n";
if ($ffail > 0){ echo "\nFunctional failures:\n"; foreach ($funcFails as $m){ echo "  - $m\n"; } }
if ($findings > 0){
    echo "\n\033[1;31mSECURITY FINDINGS:\033[0m\n";
    foreach ($findingList as $m){ echo "  ⚠ $m\n"; }
    echo "\nMoveSpot resolves the source dataset with getDatasetId(), which filters\n";
    echo "by the requesting user's userpkey BEFORE the effective-owner swap. A\n";
    echo "collaborator's data lives under the owner's pkey, so the lookup misses\n";
    echo "and the source-dataset permission check never runs. Fix: resolve the\n";
    echo "source via getDatasetContext()/getDatasetOwnerInfo() (owner-agnostic),\n";
    echo "the same way the target dataset is resolved.\n";
}
echo "\n";
exit(($ffail > 0 || $findings > 0) ? 1 : 0);
