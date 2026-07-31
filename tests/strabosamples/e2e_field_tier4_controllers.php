<?php
/**
 * File: e2e_field_tier4_controllers.php
 * Description: Tier 4 coverage — the Field spot controllers that are hooked but
 *              had no E2E test (samples/phase-i-followups). e2e_field_upload
 *              covered the bulk/single dataset-spot controllers + the PLURAL
 *              ProjectDatasetsSpotsController; this covers the remaining
 *              near-identical variants over the live /db REST API (HTTP Basic
 *              Auth, setup_test_data.php users / testpass123):
 *
 *                A. POST /db/projectdatasetspot       ProjectDatasetSpotController
 *                   (SINGULAR — creates project+dataset+one spot) → sample mirrors
 *                B. POST /db/feature/{id}             FeatureController::postAction
 *                   (single-spot update of a dataset-linked spot) → mirror updates
 *                C. DELETE /db/feature/{id}           FeatureController::deleteAction
 *                   → sample leaves the mirror
 *                D. POST /db/projectdatasetdeletespot ProjectDatasetDeleteSpotController
 *                   → targeted spot leaves the mirror
 *
 *              Hermetic: per-run id space; Neo4j project/dataset/spots (created
 *              by the endpoints) + spine rows cleaned in finally{}.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_field_tier4_controllers.php
 *
 * @package    StraboField / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

$email = 'owner@test.strabospot.org';
$ownerPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
if (!$ownerPkey) { echo "Run tests/collaboration/setup_test_data.php first.\n"; exit(1); }
$up = "$email:testpass123";

function http($method, $path, $jsonBody, $userPass) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2et4_');
    $cmd  = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' -u " . escapeshellarg($userPass) . " -X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) $cmd .= "-H 'Content-Type: application/json' -d " . escapeshellarg($jsonBody) . " ";
    $cmd .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile); @unlink($bodyFile);
    return array('status' => $status, 'body' => $body);
}

function richFeature($spotId, $name, $ts) {
    return array(
        'type'     => 'Feature',
        'geometry' => array('type' => 'Point', 'coordinates' => array(-118.5, 34.2)),
        'properties' => array(
            'id' => $spotId, 'name' => "Spot $spotId", 'modified_timestamp' => $ts, 'isSample' => 1,
            'samples' => array(array(
                'id' => $spotId, 'sample_id_name' => $name, 'Sample_IGSN' => 'IGSN-' . $name,
                'sample_description' => 't4 desc', 'material_type' => 'igneous', 'main_sampling_purpose' => 'petrology',
            )),
        ),
    );
}

function spineName($db, $owner, $sid) {
    return $db->get_var_prepared("SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array((string)$sid, $owner));
}
function spineCount($db, $owner, $sid) {
    return (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array((string)$sid, $owner));
}

$base = time();
$P1 = $base * 1000 + 1;
$D1 = $base * 1000 + 2;
$S1 = $base * 1000 + 3;
$S2 = $base * 1000 + 4;
$ts = (int)(microtime(true) * 1000);

$projObj = array('id' => $P1, 'description' => array('project_name' => 'T4 Project'), 'modified_timestamp' => $ts);
$dsObj   = array('id' => $D1, 'name' => 'T4 ds', 'modified_timestamp' => $ts);

function cleanupNeo($neodb, $owner, $P1, $D1, $S1, $S2) {
    foreach (array($S1, $S2) as $s) $neodb->query("MATCH (s:Spot {id:$s,userpkey:$owner}) DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset {id:$D1,userpkey:$owner}) DETACH DELETE d");
    $neodb->query("MATCH (p:Project {id:$P1,userpkey:$owner}) DETACH DELETE p");
}

try {
    // ===================================================================
    // A — ProjectDatasetSpotController (singular)
    // ===================================================================
    echo "=== A: POST /db/projectdatasetspot (singular) → sample mirrors ===\n";
    $bodyA = json_encode(array('project' => $projObj, 'dataset' => $dsObj, 'spot' => richFeature($S1, 'T4A', $ts)));
    $r = http('POST', "/db/projectdatasetspot", $bodyA, $up);
    note("HTTP {$r['status']}");
    check("A: 2xx", $r['status'] >= 200 && $r['status'] < 300);
    check("A: sample S1 mirrored, name='T4A'", spineName($db, $ownerPkey, $S1) === 'T4A');
    $link = $db->get_row_prepared(
        "SELECT reference_metadata::text AS rm FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'", array((string)$S1, $ownerPkey));
    $rm = $link ? json_decode($link->rm, true) : null;
    check("A: link project/dataset context propagated",
        is_array($rm) && (string)$rm['project_id'] === (string)$P1 && (string)$rm['dataset_id'] === (string)$D1);

    // ===================================================================
    // B — FeatureController postAction (single-spot update)
    // ===================================================================
    echo "\n=== B: POST /db/feature/{id} → mirror updates ===\n";
    $ts2 = (int)(microtime(true) * 1000) + 1000;
    $bodyB = json_encode(richFeature($S1, 'T4B-Updated', $ts2));
    $r = http('POST', "/db/feature/$S1", $bodyB, $up);
    note("HTTP {$r['status']}");
    check("B: 2xx", $r['status'] >= 200 && $r['status'] < 300);
    check("B: mirror updated, name='T4B-Updated'", spineName($db, $ownerPkey, $S1) === 'T4B-Updated');

    // ===================================================================
    // C — FeatureController deleteAction
    // ===================================================================
    echo "\n=== C: DELETE /db/feature/{id} → sample leaves the mirror ===\n";
    $r = http('DELETE', "/db/feature/$S1", null, $up);
    note("HTTP {$r['status']}");
    check("C: 2xx", $r['status'] >= 200 && $r['status'] < 300);
    check("C: sample S1 removed from mirror", spineCount($db, $ownerPkey, $S1) === 0);

    // ===================================================================
    // D — ProjectDatasetDeleteSpotController
    // ===================================================================
    echo "\n=== D: POST /db/projectdatasetdeletespot → targeted spot leaves the mirror ===\n";
    // Add a second sample-spot S2 to the same dataset via the singular controller.
    $bodyD0 = json_encode(array('project' => $projObj, 'dataset' => $dsObj, 'spot' => richFeature($S2, 'T4D', $ts)));
    $r = http('POST', "/db/projectdatasetspot", $bodyD0, $up);
    check("D: S2 seeded + mirrored", $r['status'] >= 200 && $r['status'] < 300 && spineName($db, $ownerPkey, $S2) === 'T4D');

    $bodyD = json_encode(array('project' => array('id' => $P1), 'datasets' => array(array('id' => $D1)), 'spotId' => $S2));
    $r = http('POST', "/db/projectdatasetdeletespot", $bodyD, $up);
    note("HTTP {$r['status']}");
    check("D: 2xx", $r['status'] >= 200 && $r['status'] < 300);
    check("D: sample S2 removed from mirror", spineCount($db, $ownerPkey, $S2) === 0);

} finally {
    foreach (array($S1, $S2) as $s) $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array((string)$s, $ownerPkey));
    cleanupNeo($neodb, $ownerPkey, $P1, $D1, $S1, $S2);
    $db->prepare_query("DELETE FROM versions WHERE projectid=$1 AND userpkey=$2", array((string)$P1, $ownerPkey));
    $db->prepare_query("DELETE FROM vprojects WHERE projectid=$1 AND userpkey=$2", array((string)$P1, $ownerPkey));
}

echo "\n=================================================\n";
if (empty($failures)) { echo "RESULT: all checks PASS\n"; exit(0); }
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
