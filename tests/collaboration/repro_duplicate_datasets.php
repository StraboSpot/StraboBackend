<?php
/**
 * Reproduction: collaborative dataset duplication
 *
 * Bug: when a collaborator uploads a dataset to an owner's collaborative
 * project, duplicate :Dataset nodes (same id) accumulate under the project.
 * See docs/duplicate_datasets_error.txt (project 16837490081659, dataset
 * 17791184209841 "CPM" appeared 4x).
 *
 * This script reproduces the upload path at the StraboSpot class level,
 * replicating exactly what ProjectDatasetSpotController::postAction does for a
 * collaborative edit (the userpkey swap + insertProject/insertDataset/
 * addDatasetToProject sequence). It avoids HTTP routing / CollaborationAuth
 * construction by hardcoding the known roles (editor = edit collaborator,
 * effectiveOwner = project owner).
 *
 * It runs the SAME upload 3x against two preconditions and reports, after each
 * upload, how many :Dataset nodes carry the id and how many HAS_DATASET edges
 * the project has to that id.
 *
 *   Precondition A (control): existing dataset node userpkey = OWNER
 *   Precondition B (suspect): existing dataset node userpkey = COLLABORATOR
 *
 * Usage: docker exec strabo-php php /srv/app/www/tests/collaboration/repro_duplicate_datasets.php
 *
 * Non-destructive to real data: uses dedicated id ranges (project 9999999911,
 * dataset 8888888811) and dedicated repro users, and cleans them up on each run.
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
require_once('db/strabospotclass.php');

// ---------------------------------------------------------------------------
// Repro fixture ids (dedicated ranges; safe to delete/recreate)
// ---------------------------------------------------------------------------
$REPRO_PROJECT = 9999999911;
$REPRO_DATASET = 8888888811;
$REPRO_SPOT    = 7777777711;

$ownerEmail  = 'repro_owner@test.strabospot.org';
$editorEmail = 'repro_editor@test.strabospot.org';

function line($s = '') { echo $s . "\n"; }
function hr() { echo str_repeat('-', 70) . "\n"; }

// ---------------------------------------------------------------------------
// User setup (create or reuse)
// ---------------------------------------------------------------------------
function ensureUser($db, $email) {
    $pkey = $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$email]);
    if ($pkey) return (int)$pkey;
    $hash = md5(uniqid(rand(), true));
    $pw   = crypt('testpass123', '$1$' . substr(md5(uniqid(rand(), true)), 0, 8));
    $db->prepare_query(
        "INSERT INTO users (email, password, firstname, lastname, hash, active, deleted)
         VALUES ($1, $2, 'Repro', 'User', $3, true, false)",
        [$email, $pw, $hash]
    );
    return (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$email]);
}

$ownerPkey  = ensureUser($db, $ownerEmail);
$editorPkey = ensureUser($db, $editorEmail);

line("Owner  pkey: $ownerPkey ($ownerEmail)");
line("Editor pkey: $editorPkey ($editorEmail)");
line();

// ---------------------------------------------------------------------------
// Neo4j inspection helpers
// ---------------------------------------------------------------------------
function countDatasetNodes($neodb, $datasetid) {
    $rows = $neodb->query("MATCH (d:Dataset) WHERE d.id = $datasetid RETURN d.userpkey AS userpkey, d.created_by AS created_by, d.modified_timestamp AS mt, id(d) AS neoid");
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'neoid'      => $r->get('neoid'),
            'userpkey'   => $r->get('userpkey'),
            'created_by' => $r->get('created_by'),
            'mt'         => $r->get('mt'),
        ];
    }
    return $out;
}

function countProjectLinks($neodb, $projectid, $datasetid) {
    return (int)$neodb->get_var("MATCH (p:Project)-[r:HAS_DATASET]->(d:Dataset) WHERE p.id = $projectid AND d.id = $datasetid RETURN count(r)");
}

function reportState($neodb, $label, $projectid, $datasetid) {
    $nodes = countDatasetNodes($neodb, $datasetid);
    $links = countProjectLinks($neodb, $projectid, $datasetid);
    $n = count($nodes);
    $flag = ($n > 1 || $links > 1) ? '  <-- DUPLICATE' : '';
    line(sprintf("  %-22s nodes=%d links=%d%s", $label, $n, $links, $flag));
    foreach ($nodes as $node) {
        line(sprintf("       node#%s userpkey=%s created_by=%s mt=%s",
            $node['neoid'], var_export($node['userpkey'], true),
            var_export($node['created_by'], true), var_export($node['mt'], true)));
    }
}

// ---------------------------------------------------------------------------
// Fixture teardown / seed
// ---------------------------------------------------------------------------
function teardown($db, $neodb, $projectid, $datasetid, $spotid, $ownerPkey) {
    $neodb->query("MATCH (d:Dataset) WHERE d.id = $datasetid DETACH DELETE d");
    $neodb->query("MATCH (s:Spot) WHERE s.id = $spotid DETACH DELETE s");
    $neodb->query("MATCH (p:Project) WHERE p.id = $projectid DETACH DELETE p");
    $db->query("DELETE FROM collaborators WHERE strabo_project_id = '$projectid'");
}

/**
 * Seed: a collaborative project owned by owner, editor is an accepted 'edit'
 * collaborator, and ONE existing dataset node linked to the project whose
 * userpkey is $datasetUserpkey (the variable under test).
 */
function seed($db, $neodb, $projectid, $datasetid, $ownerPkey, $editorPkey, $datasetUserpkey) {
    $ts = time() * 1000;
    $neodb->query("CREATE (p:Project {id: $projectid, desc_project_name: 'Repro Collab', userpkey: $ownerPkey, modified_timestamp: $ts})");
    $neodb->query("
        MATCH (p:Project {id: $projectid})
        CREATE (d:Dataset {id: $datasetid, name: 'CPM', userpkey: $datasetUserpkey, created_by: $editorPkey, modified_timestamp: $ts, datasettype: 'app', datecreated: " . time() . "})
        CREATE (p)-[:HAS_DATASET]->(d)
    ");
    $uuid = bin2hex(random_bytes(16));
    $db->prepare_query(
        "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, uuid, disabled)
         VALUES ($1, $2, $3, 'edit', true, $4, false)",
        [(string)$projectid, $ownerPkey, $editorPkey, $uuid]
    );
}

// ---------------------------------------------------------------------------
// The upload, replicating ProjectDatasetSpotController::postAction for a
// collaborative edit (editor is the requester; effectiveOwner = ownerPkey).
// ---------------------------------------------------------------------------
function editorUpload($neodb, $db, $projectid, $datasetid, $ownerPkey, $editorPkey, $iteration) {
    // requester = editor.
    // NOTE: StraboSpot() is a PHP4-style named constructor; PHP7 will not run it
    // on `new`, so set handlers + userpkey explicitly via the setters.
    $strabo = new StraboSpot($neodb, $editorPkey, $db);
    $strabo->setneohandler($neodb);
    $strabo->setdbhandler($db);
    $strabo->setuserpkey($editorPkey);

    $newTs = (time() * 1000) + $iteration; // bump so update branch fires

    $projectJson = json_encode([
        'id' => $projectid,
        'desc_project_name' => 'Repro Collab',
        'modified_timestamp' => $newTs,
    ]);
    $datasetJson = json_encode([
        'id' => $datasetid,
        'name' => 'CPM',
        'modified_timestamp' => $newTs,
        'datasettype' => 'app',
    ]);

    // --- controller logic for collaborative edit ---
    $originalUserpkey = $editorPkey;          // pre-swap requester
    $userpkey         = $ownerPkey;           // context->effectiveOwner
    $strabo->setuserpkey($userpkey);

    $isCollaborativeEdit = true;              // editor != owner, permission 'edit'
    $strabo->insertProject($projectJson, null, $isCollaborativeEdit, $userpkey, $originalUserpkey);

    // ProjectDatasetSpotController path: delete dataset relationships, then insert+link
    $strabo->deleteDatasetRelationships($datasetid);
    $strabo->insertDataset($datasetJson, null, $originalUserpkey);
    $strabo->addDatasetToProject($projectid, $datasetid, "HAS_DATASET", $userpkey, $originalUserpkey);

    $strabo->setuserpkey($originalUserpkey);
}

// ---------------------------------------------------------------------------
// Run a precondition scenario
// ---------------------------------------------------------------------------
function runScenario($db, $neodb, $title, $datasetUserpkey, $projectid, $datasetid, $spotid, $ownerPkey, $editorPkey) {
    hr();
    line("SCENARIO: $title");
    line("  (existing dataset node userpkey = " . ($datasetUserpkey == $ownerPkey ? "OWNER" : "COLLABORATOR/editor") . ")");
    hr();

    teardown($db, $neodb, $projectid, $datasetid, $spotid, $ownerPkey);
    seed($db, $neodb, $projectid, $datasetid, $ownerPkey, $editorPkey, $datasetUserpkey);

    reportState($neodb, "after seed", $projectid, $datasetid);
    for ($i = 1; $i <= 3; $i++) {
        editorUpload($neodb, $db, $projectid, $datasetid, $ownerPkey, $editorPkey, $i);
        reportState($neodb, "after editor upload #$i", $projectid, $datasetid);
    }
    line();

    return [
        'nodes' => count(countDatasetNodes($neodb, $datasetid)),
        'links' => countProjectLinks($neodb, $projectid, $datasetid),
    ];
}

$resA = runScenario($db, $neodb, "A. CONTROL - node userpkey = OWNER", $ownerPkey,  $REPRO_PROJECT, $REPRO_DATASET, $REPRO_SPOT, $ownerPkey, $editorPkey);
$resB = runScenario($db, $neodb, "B. SUSPECT - node userpkey = EDITOR", $editorPkey, $REPRO_PROJECT, $REPRO_DATASET, $REPRO_SPOT, $ownerPkey, $editorPkey);

// Cleanup
teardown($db, $neodb, $REPRO_PROJECT, $REPRO_DATASET, $REPRO_SPOT, $ownerPkey);
line("Repro fixtures cleaned up. (Repro users left in place for reuse.)");
line();

// ---------------------------------------------------------------------------
// Assertions: after a collaborator re-uploads 3x, the dataset must remain a
// SINGLE node with a SINGLE project link in BOTH scenarios.
// ---------------------------------------------------------------------------
hr();
$ok = true;
foreach (['A (owner-owned node)' => $resA, 'B (collaborator-owned node)' => $resB] as $name => $res) {
    $pass = ($res['nodes'] === 1 && $res['links'] === 1);
    $ok = $ok && $pass;
    line(sprintf("[%s] Scenario %s ended nodes=%d links=%d (expected 1/1)",
        $pass ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m", $name, $res['nodes'], $res['links']));
}
hr();

if (!$ok) {
    line("\033[31mFAILED: duplicate dataset nodes were created.\033[0m");
    exit(1);
}
line("\033[32mPASSED: no duplicate dataset nodes.\033[0m");
exit(0);
