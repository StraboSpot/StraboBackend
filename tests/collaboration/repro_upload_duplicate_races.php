<?php
/**
 * File: repro_upload_duplicate_races.php
 * Description: Regression test for the two duplicate-node bug classes in
 *              the Field upload paths. Both create duplicate Dataset/Spot
 *              nodes in Neo4j, which then poison collaboration auth
 *              (getDatasetContext resolves an arbitrary copy, handing
 *              collaborators spurious 403s) and double every export.
 *
 *              A. CONCURRENT TOCTOU — two simultaneous bulk uploads of the
 *                 same project (owner + edit collaborator) both miss the
 *                 existence probe in insertSpot/insertDataset and both
 *                 CREATE. Found by the StraboSamples Phase E chaos harness
 *                 (3/3 reproductions). Fixed by pg_advisory_lock
 *                 serialization of the probe→create window.
 *
 *              B. SEQUENTIAL ORPHAN DUP — the Field client's sync posts
 *                 datasets to bare /db/dataset with the id only in the
 *                 body. That path resolved no collaboration context, so a
 *                 collaborator re-uploading an owner-linked dataset created
 *                 an ORPHAN copy under their own pkey, which the following
 *                 POST /db/projectDatasets/{id} then linked into the
 *                 owner's project alongside the original. Observed on prod
 *                 2026-06-08: dataset 17794144325906 duplicated 4x by user
 *                 4 (created 2026-05-22 by user 905, owner 2272). Fixed by
 *                 resolving the body id in DatasetController::postAction.
 *
 *              Self-contained: uses the four test users provisioned by
 *              setup_test_data.php plus fixture ids under a '94444'
 *              prefix. Cleans up before and after. Exits non-zero on any
 *              failure so it can gate CI.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/collaboration/repro_upload_duplicate_races.php
 *
 * @package    StraboField
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

// -------------------------------------------------------------------------
// Users
// -------------------------------------------------------------------------

$password = 'testpass123';
$users = array();
foreach (array('owner', 'editor', 'outsider') as $who) {
    $email = "$who@test.strabospot.org";
    $pkey = (int)$db->get_var_prepared(
        "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
    if (!$pkey) {
        echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n";
        exit(1);
    }
    $users[$who] = array('email' => $email, 'pkey' => $pkey);
}
echo "owner={$users['owner']['pkey']} editor={$users['editor']['pkey']} outsider={$users['outsider']['pkey']}\n\n";

// -------------------------------------------------------------------------
// Fixture ids — '94444' prefix, disjoint from all other suites
// -------------------------------------------------------------------------

$stamp  = time();
$idBase = "94444" . substr((string)$stamp, -4);
$NEO_MIN = 944440000000;
$NEO_MAX = 944449999999;

$projectId = (int)($idBase . "001");
$datasetId = (int)($idBase . "002");
$spotAId   = (int)($idBase . "010");
$spotBId   = (int)($idBase . "011");

// -------------------------------------------------------------------------
// HTTP helpers (sync + async)
// -------------------------------------------------------------------------

function curlAsync($method, $path, $jsonBody, $userPass) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'race_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    $cmd .= "-u " . escapeshellarg($userPass) . " ";
    $cmd .= "-X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) {
        $cmd .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
        $cmd .= "-d " . escapeshellarg($jsonBody) . " ";
    }
    $cmd .= escapeshellarg("http://localhost" . $path);
    $pipes = array();
    $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    return array('proc' => $proc, 'pipes' => $pipes, 'bodyFile' => $bodyFile);
}

function curlWait(array $h) {
    $status = (int)stream_get_contents($h['pipes'][1]);
    fclose($h['pipes'][1]);
    fclose($h['pipes'][2]);
    proc_close($h['proc']);
    $body = file_get_contents($h['bodyFile']);
    @unlink($h['bodyFile']);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

function http($method, $path, $jsonBody, $userPass) {
    return curlWait(curlAsync($method, $path, $jsonBody, $userPass));
}

// -------------------------------------------------------------------------
// Payload builders
// -------------------------------------------------------------------------

function bulkPayload($projectId, $datasetId, $spotAId, $spotBId, $ts, $tag) {
    $features = array();
    foreach (array($spotAId => 'A', $spotBId => 'B') as $sid => $label) {
        $features[] = array(
            'type'       => 'Feature',
            'geometry'   => array('type' => 'Point', 'coordinates' => array(-99.5, 33.5)),
            'properties' => array(
                'id'                 => $sid,
                'name'               => "RaceSpot$label $tag",
                'modified_timestamp' => $ts,
            ),
        );
    }
    return json_encode(array('project' => array(
        'id'                 => $projectId,
        'description'        => array('project_name' => 'Race Repro Project'),
        'modified_timestamp' => $ts,
        'datasets'           => array(array(
            'id'                 => $datasetId,
            'name'               => 'Race Repro Dataset',
            'modified_timestamp' => $ts,
            'spots'              => array('features' => $features),
        )),
    )));
}

// -------------------------------------------------------------------------
// Cleanup
// -------------------------------------------------------------------------

function raceCleanup($db, $neodb, $NEO_MIN, $NEO_MAX) {
    $neodb->query("MATCH (s:Spot) WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_MIN AND d.id <= $NEO_MAX DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id >= $NEO_MIN AND p.id <= $NEO_MAX DETACH DELETE p");
    $db->query("DELETE FROM collaborators WHERE strabo_project_id LIKE '94444%'");
    $db->query("DELETE FROM vprojects WHERE projectid LIKE '94444%'");
}

raceCleanup($db, $neodb, $NEO_MIN, $NEO_MAX);

$ownerUP    = "{$users['owner']['email']}:$password";
$editorUP   = "{$users['editor']['email']}:$password";
$outsiderUP = "{$users['outsider']['email']}:$password";
$ownerPkey  = $users['owner']['pkey'];

function countNodes($neodb, $label, $id) {
    return (int)$neodb->get_var("MATCH (n:$label {id:$id}) RETURN count(n) AS c");
}

try {

    // Seed: project node (bulk controller requires existence for canRead)
    // + edit-level collaborations for editor AND outsider.
    $neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, name:'Race Repro Project'})");
    foreach (array('editor', 'outsider') as $who) {
        $db->prepare_query(
            "INSERT INTO collaborators
                (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
                 collaboration_level, accepted, uuid, disabled)
             VALUES ($1, $2, $3, 'edit', TRUE, $4, FALSE)",
            array((string)$projectId, $ownerPkey, $users[$who]['pkey'], bin2hex(random_bytes(16))));
    }

    // =====================================================================
    // A — concurrent create race (owner + editor bulk-upload, clean slate)
    // =====================================================================
    echo "=== A: concurrent bulk-upload create race ===\n";

    for ($iter = 1; $iter <= 3; $iter++) {
        $neodb->query("MATCH (s:Spot) WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX DETACH DELETE s");
        $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_MIN AND d.id <= $NEO_MAX DETACH DELETE d");

        $ts = (int)(microtime(true) * 1000);
        $payload = bulkPayload($projectId, $datasetId, $spotAId, $spotBId, $ts, "a$iter");

        $h1 = curlAsync('POST', '/db/projectdatasetsspots', $payload, $ownerUP);
        $h2 = curlAsync('POST', '/db/projectdatasetsspots', $payload, $editorUP);
        $r1 = curlWait($h1);
        $r2 = curlWait($h2);
        note("iter $iter HTTP: owner={$r1['status']} editor={$r2['status']}");

        check("A iter $iter: both uploads 2xx",
              $r1['status'] >= 200 && $r1['status'] < 300 && $r2['status'] >= 200 && $r2['status'] < 300);

        $bad = array();
        $c = countNodes($neodb, 'Dataset', $datasetId);   if ($c !== 1) $bad[] = "dataset nodes=$c";
        $c = countNodes($neodb, 'Spot', $spotAId);        if ($c !== 1) $bad[] = "spotA nodes=$c";
        $c = countNodes($neodb, 'Spot', $spotBId);        if ($c !== 1) $bad[] = "spotB nodes=$c";
        $c = countNodes($neodb, 'Project', $projectId);   if ($c !== 1) $bad[] = "project nodes=$c";
        check("A iter $iter: no duplicate Neo4j nodes", empty($bad));
        if (!empty($bad)) note("VIOLATIONS: " . implode('; ', $bad));
    }

    // After the racing rounds, auth must be DETERMINISTIC. The per-dataset
    // model says only the dataset's creator may edit it during active
    // collaboration, and which racer created it is inherently racy — so
    // resolve created_by from the single surviving node, then assert the
    // creator's re-upload succeeds and the non-creator's is cleanly 403'd
    // with no node changes. (With duplicate nodes, getDatasetContext
    // resolves an arbitrary copy and these outcomes flip randomly.)
    $createdBy = (int)$neodb->get_var("MATCH (d:Dataset {id:$datasetId}) RETURN d.created_by");
    $creatorUP    = ($createdBy === $users['editor']['pkey']) ? $editorUP : $ownerUP;
    $nonCreatorUP = ($createdBy === $users['editor']['pkey']) ? $ownerUP : $editorUP;
    note("dataset created_by=$createdBy");

    $ts = (int)(microtime(true) * 1000);
    $r = http('POST', '/db/projectdatasetsspots',
        bulkPayload($projectId, $datasetId, $spotAId, $spotBId, $ts, "post-race"), $creatorUP);
    check("A aftermath: creator's re-upload authorized (2xx)",
          $r['status'] >= 200 && $r['status'] < 300);

    $ts = (int)(microtime(true) * 1000);
    $r = http('POST', '/db/projectdatasetsspots',
        bulkPayload($projectId, $datasetId, $spotAId, $spotBId, $ts, "non-creator"), $nonCreatorUP);
    check("A aftermath: non-creator deterministically denied (403)", $r['status'] === 403);
    $c = countNodes($neodb, 'Dataset', $datasetId);
    check("A aftermath: still exactly one Dataset node", $c === 1);

    // =====================================================================
    // B — sequential orphan dup via bare POST /db/dataset (prod 2026-06-08
    // replay). The dataset was created by EDITOR; OUTSIDER (a different
    // edit collaborator, same as prod user 4) re-posts it bare.
    // =====================================================================
    echo "\n=== B: bare POST /db/dataset collaborator orphan dup ===\n";

    // The dataset now exists, linked to the owner's project, created_by =
    // owner (from A's owner upload). Re-stamp created_by to the editor so
    // the cast matches prod: creator(905)=editor, re-uploader(4)=outsider.
    $neodb->query("MATCH (d:Dataset {id:$datasetId}) SET d.created_by = {$users['editor']['pkey']}");

    $bare = json_encode(array(
        'id'                 => $datasetId,
        'name'               => 'Race Repro Dataset bare',
        'modified_timestamp' => (int)(microtime(true) * 1000),
    ));

    // Replays the Field client's sequence: bare dataset POST, then link.
    $r = http('POST', '/db/dataset', $bare, $outsiderUP);
    note("bare POST /db/dataset as outsider: HTTP {$r['status']}");
    check("B: non-creator collaborator bare POST is rejected (403), not orphan-created",
          $r['status'] === 403);

    $r = http('POST', "/db/projectDatasets/$projectId",
        json_encode(array('id' => $datasetId)), $outsiderUP);
    note("POST /db/projectDatasets as outsider: HTTP {$r['status']}");

    $c = countNodes($neodb, 'Dataset', $datasetId);
    check("B: still exactly one Dataset node after the replayed sequence", $c === 1);
    $orphans = (int)$neodb->get_var(
        "MATCH (d:Dataset {id:$datasetId}) WHERE NOT EXISTS((d)--()) RETURN count(d) AS c");
    check("B: no orphan copy left behind", $orphans === 0);

    // The CREATOR (editor) re-posting their own dataset bare must still
    // work — update in place, no duplicate.
    $bare = json_encode(array(
        'id'                 => $datasetId,
        'name'               => 'Race Repro Dataset editor-updated',
        'modified_timestamp' => (int)(microtime(true) * 1000) + 10,
    ));
    $r = http('POST', '/db/dataset', $bare, $editorUP);
    note("bare POST /db/dataset as creator (editor): HTTP {$r['status']}");
    check("B: creator's bare re-POST succeeds", $r['status'] >= 200 && $r['status'] < 300);
    $c = countNodes($neodb, 'Dataset', $datasetId);
    check("B: still exactly one Dataset node after creator update", $c === 1);
    $name = (string)$neodb->get_var("MATCH (d:Dataset {id:$datasetId}) RETURN d.name");
    check("B: creator's update landed on the linked node", $name === 'Race Repro Dataset editor-updated');

    // =====================================================================
    // C — non-collaborative self re-upload regression guard
    // =====================================================================
    echo "\n=== C: solo bare POST /db/dataset (no collaboration) ===\n";

    $soloDatasetId = (int)($idBase . "003");
    $bare = json_encode(array(
        'id'                 => $soloDatasetId,
        'name'               => 'Solo Dataset v1',
        'modified_timestamp' => (int)(microtime(true) * 1000),
    ));
    $r = http('POST', '/db/dataset', $bare, $ownerUP);
    check("C: first bare POST creates (2xx)", $r['status'] >= 200 && $r['status'] < 300);

    $bare = json_encode(array(
        'id'                 => $soloDatasetId,
        'name'               => 'Solo Dataset v2',
        'modified_timestamp' => (int)(microtime(true) * 1000) + 10,
    ));
    $r = http('POST', '/db/dataset', $bare, $ownerUP);
    check("C: second bare POST succeeds (2xx)", $r['status'] >= 200 && $r['status'] < 300);
    $c = countNodes($neodb, 'Dataset', $soloDatasetId);
    check("C: exactly one node after re-POST", $c === 1);
    $name = (string)$neodb->get_var("MATCH (d:Dataset {id:$soloDatasetId}) RETURN d.name");
    check("C: update landed", $name === 'Solo Dataset v2');

} finally {
    raceCleanup($db, $neodb, $NEO_MIN, $NEO_MAX);
}

echo "\n=================================================\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
}
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
