<?php
/**
 * Collaboration Testing: Website Lifecycle E2E (forged session)
 *
 * Drives the REAL Apache website endpoints that manage the collaboration
 * lifecycle end-to-end, which NO existing test touched (every prior fixture
 * short-circuited by INSERTing collaborators rows via SQL):
 *
 *     invite_collaborators.php      (owner invites -> pending row)
 *     accept_collaboration.php      (collaborator accepts -> active)
 *     update_collaboration_level.php(owner changes edit<->readonly)
 *     delete_collaborator.php       (owner revokes)
 *     halt_collaboration.php        (owner disables all)
 *     deny_collaboration.php        (invitee rejects)
 *
 * The suite has TWO independently-tallied parts:
 *
 *   [FUNCTIONAL]  Happy-path lifecycle + state transitions that prior suites
 *                 never exercised: pending (accepted=false) must behave as
 *                 'none'/404, accept activates, downgrade, revoke, re-invite,
 *                 halt. These are expected to PASS.
 *
 *   [AUTHZ PROBE] Negative-authorization probes against the uuid-keyed pages.
 *                 Each asserts the SECURE expectation (an unauthorized caller
 *                 is blocked / a value is validated). Where the current code
 *                 has no ownership/identity check, these FAIL and the failure
 *                 IS the finding. halt_collaboration.php is included as a
 *                 positive control because it is correctly owner-scoped.
 *
 * Hermetic: seeds throwaway users + a Neo4j project/dataset/spot, exercises
 * every path, removes all residue. Safe to re-run.
 *
 * Usage (inside the app container):
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/e2e_collab_lifecycle.php
 *
 * @package StraboSpot Tests
 */

include "/srv/app/www/includes/config.inc.php";
include "/srv/app/www/db.php";
include "/srv/app/www/neodb.php";
require_once "/srv/app/www/db/services/CollaborationAuth.php";

$BASE    = "http://localhost";
$SESSDIR = "/var/lib/php/sessions";
$PREFIX  = "e2ecollab";
$KNOWN_PASS = "testpass123";
$rand    = substr(bin2hex(random_bytes(6)), 0, 8);

$collabAuth = new CollaborationAuth($db, $neodb);

// Two independent tallies.
$fpass = 0; $ffail = 0;   // functional
$findings = 0; $secok = 0; // authorization probes
$discrep = 0;              // design/behavior discrepancies
$funcFails = [];
$findingList = [];
$discrepList = [];

function fok($cond, $msg) {
    global $fpass, $ffail, $funcFails;
    if ($cond) { $fpass++; echo "  \033[32mPASS\033[0m  $msg\n"; }
    else { $ffail++; $funcFails[] = $msg; echo "  \033[31mFAIL\033[0m  $msg\n"; }
}

/**
 * Authorization probe. $secure is the condition that holds IFF the app is safe.
 * If it does not hold, we record a security FINDING (the whole point of the probe).
 */
function probe($secure, $msg) {
    global $findings, $secok, $findingList;
    if ($secure) { $secok++; echo "  \033[32mSECURE\033[0m  $msg\n"; }
    else { $findings++; $findingList[] = $msg; echo "  \033[31m⚠ FINDING\033[0m  $msg\n"; }
}

/**
 * Records a design/behavior discrepancy: the app is self-consistent enough to
 * run, but two parts of it disagree about intent. $consistent is true IFF there
 * is no discrepancy. These do not indicate a broken test; they flag a decision
 * the maintainer should make.
 */
function discrepancy($consistent, $msg) {
    global $discrep, $discrepList;
    if ($consistent) { echo "  \033[32mCONSISTENT\033[0m  $msg\n"; }
    else { $discrep++; $discrepList[] = $msg; echo "  \033[1;33m◆ DISCREPANCY\033[0m  $msg\n"; }
}

function section($t){ echo "\n\033[1;34m== $t ==\033[0m\n"; }

function http($url, $opts = array()){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if (!empty($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['post']));
    }
    $headers = array();
    if (!empty($opts['sid'])) { $headers[] = "Cookie: PHPSESSID=".$opts['sid']; }
    if (!empty($opts['basic'])) { curl_setopt($ch, CURLOPT_USERPWD, $opts['basic']); }
    if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($code, (string)$body);
}

// ---- Seed helpers -----------------------------------------------------------
$seeded_pkeys = array();
$seeded_emails = array();
$sessfiles = array();

function seedUser($email){
    global $db, $neodb, $KNOWN_PASS, $rand, $seeded_pkeys, $seeded_emails;
    $hash = substr(md5($email.$rand), 0, 21);
    $db->prepare_query(
        "INSERT INTO users (firstname,lastname,password,hash,email,active,deleted) VALUES ($1,$2,crypt($3,gen_salt('md5')),$4,$5,true,false)",
        array("E2E", "Collab", $KNOWN_PASS, $hash, $email)
    );
    $pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1", array($email));
    $neodb->createNode(json_encode(array("userpkey"=>$pkey, "email"=>$email, "firstname"=>"E2E", "lastname"=>"Collab")), "User");
    $seeded_pkeys[] = $pkey;
    $seeded_emails[] = $email;
    return $pkey;
}

function forgeSession($pkey, $email){
    global $SESSDIR, $rand, $sessfiles;
    $sid = "e2ecol".$rand.substr(md5($email), 0, 8);
    $p  = "";
    $p .= "loggedin|".serialize("yes");
    $p .= "LAST_ACTIVITY|".serialize(time());
    $p .= "userpkey|".serialize((string)$pkey);
    $p .= "username|".serialize($email);
    $p .= "loggedin_username|".serialize($email);
    $p .= "firstname|".serialize("E2E");
    $p .= "lastname|".serialize("Collab");
    $p .= "userlevel|".serialize("user");
    $f = "$SESSDIR/sess_$sid";
    file_put_contents($f, $p);
    @chmod($f, 0666);
    $sessfiles[] = $f;
    return $sid;
}

function collabRow($projectId, $ownerPkey, $collabPkey){
    global $db;
    return $db->get_row_prepared(
        "SELECT * FROM collaborators WHERE strabo_project_id=$1 AND project_owner_user_pkey=$2 AND collaborator_user_pkey=$3",
        array($projectId, $ownerPkey, $collabPkey)
    );
}

function isDisabled($row){ return $row && ($row->disabled === true || $row->disabled === 't'); }
function isAccepted($row){ return $row && ($row->accepted === true || $row->accepted === 't'); }

// Test-controlled reset of a row to a known state (used to isolate probes).
function forceRow($uuid, $level, $accepted, $disabled){
    global $db;
    $db->prepare_query(
        "UPDATE collaborators SET collaboration_level=$1, accepted=$2, disabled=$3 WHERE uuid=$4",
        array($level, $accepted ? 'true' : 'false', $disabled ? 'true' : 'false', $uuid)
    );
}

// =============================================================================
echo "StraboSpot collaboration lifecycle E2E  (rand=$rand)\n";

$ownerEmail    = "$PREFIX-owner-$rand@example.com";
$collabEmail   = "$PREFIX-collab-$rand@example.com";
$attackerEmail = "$PREFIX-attacker-$rand@example.com";

$ownerPkey    = seedUser($ownerEmail);
$collabPkey   = seedUser($collabEmail);
$attackerPkey = seedUser($attackerEmail);
echo "Seeded users: owner=$ownerPkey collab=$collabPkey attacker=$attackerPkey\n";

// Numeric project id (accept/deny require is_numeric).
$projectId = 9955000000 + rand(1, 999999);
$datasetId = 8844000000 + rand(1, 999999);
$spotId    = 7733000000 + rand(1, 999999);
$ts = time() * 1000;

$neodb->query("CREATE (p:Project {id:$projectId, desc_project_name:'Lifecycle E2E Project', userpkey:$ownerPkey, modified_timestamp:$ts})");
$neodb->query("MATCH (u:User {userpkey:$ownerPkey}) MATCH (p:Project {id:$projectId}) CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("CREATE (d:Dataset {id:$datasetId, name:'LC DS', userpkey:$ownerPkey, created_by:$ownerPkey, modified_timestamp:$ts})");
$neodb->query("MATCH (p:Project {id:$projectId}) MATCH (d:Dataset {id:$datasetId}) CREATE (p)-[:HAS_DATASET]->(d)");
$neodb->query("CREATE (s:Spot {id:$spotId, name:'LC Spot', userpkey:$ownerPkey, modified_timestamp:$ts})");
$neodb->query("MATCH (d:Dataset {id:$datasetId}) MATCH (s:Spot {id:$spotId}) CREATE (d)-[:CONTAINS_SPOT]->(s)");
echo "Seeded project=$projectId dataset=$datasetId spot=$spotId\n";

$ownerSid    = forgeSession($ownerPkey, $ownerEmail);
$collabSid   = forgeSession($collabPkey, $collabEmail);
$attackerSid = forgeSession($attackerPkey, $attackerEmail);

// =============================================================================
// FUNCTIONAL: Invite -> pending
// =============================================================================
section("[FUNCTIONAL] invite_collaborators.php -> pending invitation");

list($c, $b) = http("$BASE/invite_collaborators?p=$projectId", array(
    'sid' => $ownerSid,
    'post' => array('addresses' => $collabEmail, 'collaborationlevel' => 'edit')
));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
fok($row !== null, "invite created a collaborators row");
fok($row && $row->collaboration_level === 'edit', "invited at 'edit' level (got '".($row->collaboration_level ?? 'null')."')");
fok(!isAccepted($row), "new invite is pending (accepted=false)");
fok(!isDisabled($row), "new invite is enabled (disabled=false)");
fok($row && strlen($row->uuid) > 10, "invite carries a uuid token");
$uuid = $row ? $row->uuid : '';

// Pending must behave as 'none' (the accepted=true gate in getProjectContext).
$ctx = $collabAuth->getProjectContext($collabPkey, (string)$projectId);
fok($ctx->permissionLevel === 'none', "pending collaborator resolves to 'none' (got '{$ctx->permissionLevel}')");
fok($collabAuth->canRead($ctx) === false, "pending collaborator canRead()=false");

// Prove the enforcement over HTTP too (Basic Auth /db/): pending -> 404.
list($c, $b) = http("$BASE/db/project/$projectId", array('basic' => "$collabEmail:$KNOWN_PASS"));
fok($c == 404, "pending collaborator GET /db/project -> 404 (got $c)");

// =============================================================================
// FUNCTIONAL: Accept -> active edit
// =============================================================================
section("[FUNCTIONAL] accept_collaboration.php -> active");

list($c, $b) = http("$BASE/accept_collaboration?p=$projectId&u=$uuid", array('sid' => $collabSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
fok(isAccepted($row), "invite is now accepted");
fok($row && $row->accepted_date != "", "accepted_date is set");

$ctx = $collabAuth->getProjectContext($collabPkey, (string)$projectId);
fok($ctx->permissionLevel === 'edit', "accepted collaborator resolves to 'edit'");
fok($collabAuth->canCreateDataset($ctx) === true, "edit collaborator canCreateDataset()=true");
fok($collabAuth->canEditProjectMetadata($ctx) === false, "edit collaborator canEditProjectMetadata()=false");

list($c, $b) = http("$BASE/db/project/$projectId", array('basic' => "$collabEmail:$KNOWN_PASS"));
fok($c == 200, "active collaborator GET /db/project -> 200 (got $c)");

// =============================================================================
// FUNCTIONAL: Owner downgrades edit -> readonly
// =============================================================================
section("[FUNCTIONAL] update_collaboration_level.php (owner downgrade)");

http("$BASE/update_collaboration_level?u=$uuid&l=readonly", array('sid' => $ownerSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
fok($row && $row->collaboration_level === 'readonly', "level downgraded to readonly");
$ctx = $collabAuth->getProjectContext($collabPkey, (string)$projectId);
fok($ctx->permissionLevel === 'readonly', "downgraded collaborator resolves to 'readonly'");
fok($collabAuth->canCreateDataset($ctx) === false, "readonly collaborator canCreateDataset()=false");

// Restore to edit for the probe phase.
http("$BASE/update_collaboration_level?u=$uuid&l=edit", array('sid' => $ownerSid));

// =============================================================================
// AUTHZ PROBES: uuid-keyed pages must verify the caller
// =============================================================================
section("[AUTHZ PROBE] uuid-keyed lifecycle pages");

// P1: a stranger (not the project owner) changes the collaboration level.
forceRow($uuid, 'readonly', true, false);
http("$BASE/update_collaboration_level?u=$uuid&l=edit", array('sid' => $attackerSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
probe($row && $row->collaboration_level === 'readonly',
    "update_collaboration_level rejects a non-owner caller (level stayed 'readonly', got '".($row->collaboration_level ?? 'null')."')");

// P2: the collaborator self-escalates to 'owner' using their own uuid.
forceRow($uuid, 'edit', true, false);
http("$BASE/update_collaboration_level?u=$uuid&l=owner", array('sid' => $collabSid));
$ctx = $collabAuth->getProjectContext($collabPkey, (string)$projectId);
probe($collabAuth->canEditProjectMetadata($ctx) === false,
    "collaborator cannot self-escalate to owner-metadata rights via own uuid (canEditProjectMetadata stayed false)");

// P3: the collaboration level accepts only a known vocabulary.
forceRow($uuid, 'edit', true, false);
http("$BASE/update_collaboration_level?u=$uuid&l=banana", array('sid' => $ownerSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
probe($row && in_array($row->collaboration_level, array('edit','readonly'), true),
    "update_collaboration_level validates the level value (rejected 'banana', got '".($row->collaboration_level ?? 'null')."')");

// P4: a stranger revokes a collaborator via delete_collaborator.
forceRow($uuid, 'edit', true, false);
http("$BASE/delete_collaborator?p=$projectId&u=$uuid", array('sid' => $attackerSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
probe(!isDisabled($row),
    "delete_collaborator rejects a non-owner caller (collaboration stayed enabled)");

// P5: a stranger disables a collaborator via deny_collaboration.
forceRow($uuid, 'edit', true, false);
http("$BASE/deny_collaboration?p=$projectId&u=$uuid", array('sid' => $attackerSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
probe(!isDisabled($row),
    "deny_collaboration rejects a caller who is not the invitee (collaboration stayed enabled)");

// Positive control: halt_collaboration IS owner-scoped (WHERE project_owner_user_pkey=$userpkey).
// A non-owner calling it should change nothing.
forceRow($uuid, 'edit', true, false);
http("$BASE/halt_collaboration?p=$projectId", array('sid' => $attackerSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
probe(!isDisabled($row),
    "halt_collaboration ignores a non-owner caller [positive control - expected SECURE]");

// =============================================================================
// FUNCTIONAL: Owner revoke, then re-invite
// =============================================================================
section("[FUNCTIONAL] owner revoke + re-invite");

forceRow($uuid, 'edit', true, false);
http("$BASE/delete_collaborator?p=$projectId&u=$uuid", array('sid' => $ownerSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
fok(isDisabled($row), "owner revoke disables the collaborator");
fok(!isAccepted($row), "owner revoke clears accepted flag");
$ctx = $collabAuth->getProjectContext($collabPkey, (string)$projectId);
fok($ctx->permissionLevel === 'none', "revoked (unaccepted) collaborator resolves to 'none'");

// Owner re-invites the same user: invite page UPDATEs the existing row back on.
http("$BASE/invite_collaborators?p=$projectId", array(
    'sid' => $ownerSid,
    'post' => array('addresses' => $collabEmail, 'collaborationlevel' => 'edit')
));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
fok(!isDisabled($row), "re-invite re-enables the row (disabled=false)");
fok($row && $row->collaboration_level === 'edit', "re-invite restores 'edit' level");

// =============================================================================
// FUNCTIONAL: Halt
// =============================================================================
section("[FUNCTIONAL] halt_collaboration.php (owner)");

forceRow($uuid, 'edit', true, false);
http("$BASE/halt_collaboration?p=$projectId", array('sid' => $ownerSid));
$row = collabRow($projectId, $ownerPkey, $collabPkey);
fok(isDisabled($row), "halt disables the collaborator (disabled=true)");
// Halt is suspend-not-revoke: accepted stays true so the collaborator keeps
// readonly access (finding D fix). Revoke (delete_collaborator) is what clears
// accepted and drops them to 'none'.
fok(isAccepted($row), "halt preserves accepted=true (suspend, not revoke)");
$ctx = $collabAuth->getProjectContext($collabPkey, (string)$projectId);
fok($ctx->permissionLevel === 'readonly', "halted collaborator resolves to 'readonly' (got '{$ctx->permissionLevel}')");
fok($collabAuth->canRead($ctx) === true, "halted collaborator can still read");
fok($collabAuth->canCreateDataset($ctx) === false, "halted collaborator cannot create datasets");
// Owner regains full control on the halted project.
$octx = $collabAuth->getProjectContext($ownerPkey, (string)$projectId);
fok($collabAuth->canEditDataset($octx, $ownerPkey) === true, "owner regains edit control when halted");

// =============================================================================
// cleanup
// =============================================================================
section("cleanup");
foreach ($sessfiles as $f) { @unlink($f); }
$db->prepare_query("DELETE FROM collaborators WHERE strabo_project_id=$1", array((string)$projectId));
$neodb->query("MATCH (s:Spot {id:$spotId}) DETACH DELETE s");
$neodb->query("MATCH (d:Dataset {id:$datasetId}) DETACH DELETE d");
$neodb->query("MATCH (p:Project {id:$projectId}) DETACH DELETE p");
foreach ($seeded_pkeys as $pk) {
    $neodb->query("MATCH (u:User) WHERE u.userpkey=$pk DETACH DELETE u");
}
$db->prepare_query("DELETE FROM users WHERE email LIKE $1", array("$PREFIX-%-$rand@example.com"));
$resid = (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE email LIKE $1", array("$PREFIX-%-$rand@example.com"));
fok($resid === 0, "cleanup: no residual users");

// =============================================================================
echo "\n========================================\n";
echo "FUNCTIONAL:   $fpass passed, $ffail failed\n";
echo "AUTHZ PROBES: $secok secure, \033[1;31m$findings finding(s)\033[0m\n";
echo "DISCREPANCY:  \033[1;33m$discrep flagged\033[0m\n";
echo "========================================\n";
if ($ffail > 0) {
    echo "\nFunctional failures:\n";
    foreach ($funcFails as $m) { echo "  - $m\n"; }
}
if ($discrep > 0) {
    echo "\n\033[1;33mDESIGN/BEHAVIOR DISCREPANCIES (maintainer decision needed):\033[0m\n";
    foreach ($discrepList as $m) { echo "  ◆ $m\n"; }
}
if ($findings > 0) {
    echo "\n\033[1;31mSECURITY FINDINGS (uuid-keyed pages lack a caller-authorization check):\033[0m\n";
    foreach ($findingList as $m) { echo "  ⚠ $m\n"; }
    echo "\nThese pages mutate collaborators by uuid alone. A collaborator knows\n";
    echo "their own uuid (it is in their invite link), so the escalation/tamper\n";
    echo "paths are reachable by real users, not just holders of a secret token.\n";
    echo "Fix pattern: gate each on project ownership, exactly as halt_collaboration.php does.\n";
}
echo "\n";
exit(($ffail > 0 || $findings > 0) ? 1 : 0);
