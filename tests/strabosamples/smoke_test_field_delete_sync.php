<?php
/**
 * File: smoke_test_field_delete_sync.php
 * Description: Coverage for the Field bulk-delete + move-spot spine sync
 *              hooks added on samples/phase-i-deletes. Before this work,
 *              Field had only a per-spot removal hook
 *              (field_sample_sync_remove_spot), so the bulk dataset/project
 *              delete methods — which DETACH DELETE many spots in one Cypher
 *              statement — orphaned every mirrored sample row in
 *              strabosamples.*. The move-spot controller likewise never
 *              refreshed the spine link's project/dataset context.
 *
 *              This drives the REAL StraboSpot class methods (with setuuid,
 *              exactly as db/index.php wires them) so the wiring is proven,
 *              not just the free helpers:
 *
 *                A. deleteSingleDataset   (createVersion path)
 *                B. deleteProject         (createVersion path; also the
 *                                          mechanism switchVersion restores
 *                                          through)
 *                C. deleteDatasetSpots    (DELETE-verb path; dataset node
 *                                          survives, its spots' samples go)
 *                D. deleteProjectDatasets (DELETE-verb path)
 *                E. resyncSpotSamples     (move-spot: link project/dataset
 *                                          context refreshed, lat/lng kept)
 *                F. priority-aware survival: deleting the Field side of a
 *                                          sample also linked to another
 *                                          subsystem keeps the sample row.
 *
 *              Hermetic: all fixtures under a per-run numeric id space;
 *              torn down (spine + Neo4j + versions rows) in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_field_delete_sync.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/db/strabospotclass.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$u = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted=FALSE AND active=TRUE ORDER BY pkey LIMIT 1", array()
);
if (!is_array($u) || count($u) < 1) { echo "Need 1 user\n"; exit(1); }
$owner    = (int)$u[0]->pkey;
$uuid_gen = new UUID();

$strabo = new StraboSpot($neodb, $owner, $db);
$strabo->setuuid($uuid_gen);

// Per-run id space (unique, bigint-safe). All ids derive from $base.
$base = time();
function nid($base, $n) { return (int)($base * 1000 + $n); }

// Track every sample id + project id touched, for teardown.
$allSampleIds = array();
$allProjectIds = array();
$allDatasetIds = array();
$allSpotIds = array();

/**
 * Seed a sample-bearing Spot in a dataset and mirror it into the spine
 * with explicit (project,dataset) context. Returns the sample strabo_id.
 */
function seedSampleSpot($db, $neodb, $owner, $projId, $dsId, $spotId, array $sampleObj, $isRich, $wkt) {
    global $allSampleIds, $allSpotIds;
    $js = json_encode(array($sampleObj));
    $richProp = $isRich ? ', isSample:1' : '';
    $neodb->query("CREATE (s:Spot {id:$spotId, userpkey:$owner$richProp,
        json_samples:'" . addslashes($js) . "', wkt:'" . addslashes($wkt) . "'})");
    $neodb->query("MATCH (d:Dataset {id:$dsId,userpkey:$owner}),(s:Spot {id:$spotId,userpkey:$owner})
                   CREATE (d)-[:HAS_SPOT]->(s)");
    $props = new stdClass();
    $props->id = $spotId;
    $props->samples = array((object)$sampleObj);
    if ($isRich) $props->isSample = 1;
    $props->wkt = $wkt;
    field_sample_sync_spot($db, $neodb, $props, $spotId, $owner, (string)$projId, (string)$dsId);
    $sid = (string)$sampleObj['id'];
    $allSampleIds[] = $sid;
    $allSpotIds[] = $spotId;
    return $sid;
}

function seedProjectDataset($neodb, $owner, $projId, $dsId, $stamp) {
    global $allProjectIds, $allDatasetIds;
    $neodb->query("CREATE (p:Project {id:$projId, userpkey:$owner, desc_project_name:'delsync $stamp', name:'delsync $stamp'})");
    $neodb->query("CREATE (d:Dataset {id:$dsId, userpkey:$owner, name:'ds $stamp'})");
    $neodb->query("MATCH (p:Project {id:$projId,userpkey:$owner}),(d:Dataset {id:$dsId,userpkey:$owner})
                   CREATE (p)-[:HAS_DATASET]->(d)");
    $allProjectIds[] = $projId;
    $allDatasetIds[] = $dsId;
}

function spineCount($db, $owner, $sid) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sid, $owner));
}
function fieldLinkCount($db, $owner, $sid) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'", array($sid, $owner));
}
function neoSpotCount($neodb, $owner, $spotId) {
    return (int)$neodb->get_var("MATCH (s:Spot {id:$spotId,userpkey:$owner}) RETURN count(s)");
}

try {
    // ===================================================================
    // PART A: deleteSingleDataset — removes the dataset's mirrored samples
    // ===================================================================
    echo "=== Part A: deleteSingleDataset (createVersion path) ===\n";
    $pA = nid($base, 101); $dA = nid($base, 102);
    $spotA1 = nid($base, 103); $spotA2 = nid($base, 104);
    seedProjectDataset($neodb, $owner, $pA, $dA, $base);
    $sA1 = seedSampleSpot($db, $neodb, $owner, $pA, $dA, $spotA1,
        array('id'=>(string)$spotA1, 'sample_id_name'=>'A-rich', 'material_type'=>'igneous'), true, 'POINT (-120.0 45.0)');
    $sA2 = seedSampleSpot($db, $neodb, $owner, $pA, $dA, $spotA2,
        array('id'=>(string)nid($base,105), 'sample_id_name'=>'A-legacy', 'material_type'=>'sediment'), false, 'POINT (-121.0 46.0)');
    check("A: rich sample mirrored before delete",    spineCount($db,$owner,$sA1) === 1);
    check("A: legacy sample mirrored before delete",  spineCount($db,$owner,$sA2) === 1);

    $strabo->deleteSingleDataset($dA);

    check("A: rich sample removed from spine",        spineCount($db,$owner,$sA1) === 0);
    check("A: legacy sample removed from spine",      spineCount($db,$owner,$sA2) === 0);
    check("A: rich spot gone from Neo4j",             neoSpotCount($neodb,$owner,$spotA1) === 0);
    check("A: legacy spot gone from Neo4j",           neoSpotCount($neodb,$owner,$spotA2) === 0);
    check("A: dataset node gone from Neo4j",          (int)$neodb->get_var("MATCH (d:Dataset {id:$dA,userpkey:$owner}) RETURN count(d)") === 0);

    // ===================================================================
    // PART B: deleteProject — removes EVERY dataset's mirrored samples
    // ===================================================================
    echo "\n=== Part B: deleteProject (createVersion path; switchVersion mechanism) ===\n";
    $pB = nid($base, 201); $dB1 = nid($base, 202); $dB2 = nid($base, 203);
    $spotB1 = nid($base, 204); $spotB2 = nid($base, 205);
    seedProjectDataset($neodb, $owner, $pB, $dB1, $base);
    // second dataset under the same project
    $neodb->query("CREATE (d:Dataset {id:$dB2, userpkey:$owner, name:'ds2 $base'})");
    $neodb->query("MATCH (p:Project {id:$pB,userpkey:$owner}),(d:Dataset {id:$dB2,userpkey:$owner}) CREATE (p)-[:HAS_DATASET]->(d)");
    $allDatasetIds[] = $dB2;
    $sB1 = seedSampleSpot($db, $neodb, $owner, $pB, $dB1, $spotB1,
        array('id'=>(string)$spotB1, 'sample_id_name'=>'B-1', 'material_type'=>'igneous'), true, 'POINT (-110.0 40.0)');
    $sB2 = seedSampleSpot($db, $neodb, $owner, $pB, $dB2, $spotB2,
        array('id'=>(string)$spotB2, 'sample_id_name'=>'B-2', 'material_type'=>'metamorphic'), true, 'POINT (-111.0 41.0)');
    check("B: both samples mirrored before delete",   spineCount($db,$owner,$sB1) === 1 && spineCount($db,$owner,$sB2) === 1);

    $strabo->deleteProject($pB);

    check("B: dataset-1 sample removed from spine",    spineCount($db,$owner,$sB1) === 0);
    check("B: dataset-2 sample removed from spine",    spineCount($db,$owner,$sB2) === 0);
    check("B: all project spots gone from Neo4j",      neoSpotCount($neodb,$owner,$spotB1) === 0 && neoSpotCount($neodb,$owner,$spotB2) === 0);
    check("B: project node gone from Neo4j",           (int)$neodb->get_var("MATCH (p:Project {id:$pB,userpkey:$owner}) RETURN count(p)") === 0);

    // ===================================================================
    // PART C: deleteDatasetSpots — DELETE-verb path; dataset node survives
    // ===================================================================
    echo "\n=== Part C: deleteDatasetSpots (DELETE-verb path) ===\n";
    $pC = nid($base, 301); $dC = nid($base, 302); $spotC = nid($base, 303);
    seedProjectDataset($neodb, $owner, $pC, $dC, $base);
    $sC = seedSampleSpot($db, $neodb, $owner, $pC, $dC, $spotC,
        array('id'=>(string)$spotC, 'sample_id_name'=>'C-1', 'material_type'=>'igneous'), true, 'POINT (-100.0 35.0)');
    check("C: sample mirrored before delete",          spineCount($db,$owner,$sC) === 1);

    $strabo->deleteDatasetSpots($dC);

    check("C: sample removed from spine",              spineCount($db,$owner,$sC) === 0);
    check("C: spot gone from Neo4j",                   neoSpotCount($neodb,$owner,$spotC) === 0);
    check("C: dataset node SURVIVES (spots-only delete)", (int)$neodb->get_var("MATCH (d:Dataset {id:$dC,userpkey:$owner}) RETURN count(d)") === 1);

    // ===================================================================
    // PART D: deleteProjectDatasets — DELETE-verb path
    // ===================================================================
    echo "\n=== Part D: deleteProjectDatasets (DELETE-verb path) ===\n";
    $pD = nid($base, 401); $dD1 = nid($base, 402); $dD2 = nid($base, 403);
    $spotD1 = nid($base, 404); $spotD2 = nid($base, 405);
    seedProjectDataset($neodb, $owner, $pD, $dD1, $base);
    $neodb->query("CREATE (d:Dataset {id:$dD2, userpkey:$owner, name:'dD2 $base'})");
    $neodb->query("MATCH (p:Project {id:$pD,userpkey:$owner}),(d:Dataset {id:$dD2,userpkey:$owner}) CREATE (p)-[:HAS_DATASET]->(d)");
    $allDatasetIds[] = $dD2;
    $sD1 = seedSampleSpot($db, $neodb, $owner, $pD, $dD1, $spotD1,
        array('id'=>(string)$spotD1, 'sample_id_name'=>'D-1', 'material_type'=>'igneous'), true, 'POINT (-90.0 30.0)');
    $sD2 = seedSampleSpot($db, $neodb, $owner, $pD, $dD2, $spotD2,
        array('id'=>(string)$spotD2, 'sample_id_name'=>'D-2', 'material_type'=>'sediment'), true, 'POINT (-91.0 31.0)');
    check("D: both samples mirrored before delete",    spineCount($db,$owner,$sD1) === 1 && spineCount($db,$owner,$sD2) === 1);

    $strabo->deleteProjectDatasets($pD);

    check("D: both samples removed from spine",        spineCount($db,$owner,$sD1) === 0 && spineCount($db,$owner,$sD2) === 0);
    check("D: both spots gone from Neo4j",             neoSpotCount($neodb,$owner,$spotD1) === 0 && neoSpotCount($neodb,$owner,$spotD2) === 0);

    // ===================================================================
    // PART E: resyncSpotSamples — move refreshes link context, keeps lat/lng
    // ===================================================================
    echo "\n=== Part E: resyncSpotSamples (move-spot) ===\n";
    $pE1 = nid($base, 501); $dE1 = nid($base, 502);
    $pE2 = nid($base, 503); $dE2 = nid($base, 504);
    $spotE = nid($base, 505);
    seedProjectDataset($neodb, $owner, $pE1, $dE1, $base);
    seedProjectDataset($neodb, $owner, $pE2, $dE2, $base);
    $sE = seedSampleSpot($db, $neodb, $owner, $pE1, $dE1, $spotE,
        array('id'=>(string)$spotE, 'sample_id_name'=>'E-move', 'material_type'=>'igneous'), true, 'POINT (-118.25 34.75)');

    $linkBefore = $db->get_row_prepared(
        "SELECT reference_metadata::text AS rm, (SELECT latitude FROM strabosamples.samples WHERE id=$1 AND userpkey=$2) AS lat,
                (SELECT longitude FROM strabosamples.samples WHERE id=$1 AND userpkey=$2) AS lng
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'", array($sE, $owner));
    $rmB = $linkBefore && $linkBefore->rm ? json_decode($linkBefore->rm, true) : null;
    check("E: link initially points at source dataset", is_array($rmB) && (string)$rmB['dataset_id'] === (string)$dE1);
    check("E: lat populated from wkt before move",      $linkBefore && abs((float)$linkBefore->lat - 34.75) < 0.0001);

    // Simulate the controller move: rewire HAS_SPOT, then resync with new context.
    $neodb->query("MATCH (d:Dataset {id:$dE1,userpkey:$owner})-[r:HAS_SPOT]->(s:Spot {id:$spotE,userpkey:$owner}) DELETE r");
    $neodb->query("MATCH (d:Dataset {id:$dE2,userpkey:$owner}),(s:Spot {id:$spotE,userpkey:$owner}) CREATE (d)-[:HAS_SPOT]->(s)");
    $strabo->resyncSpotSamples($spotE, (string)$pE2, (string)$dE2);

    $linkAfter = $db->get_row_prepared(
        "SELECT reference_id, reference_metadata::text AS rm,
                (SELECT latitude FROM strabosamples.samples WHERE id=$1 AND userpkey=$2) AS lat,
                (SELECT longitude FROM strabosamples.samples WHERE id=$1 AND userpkey=$2) AS lng
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'", array($sE, $owner));
    $rmA = $linkAfter && $linkAfter->rm ? json_decode($linkAfter->rm, true) : null;
    check("E: still exactly one field link (no dup)",   fieldLinkCount($db,$owner,$sE) === 1);
    check("E: link dataset_id now the TARGET dataset",  is_array($rmA) && (string)$rmA['dataset_id'] === (string)$dE2);
    check("E: link project_id now the TARGET project",  is_array($rmA) && (string)$rmA['project_id'] === (string)$pE2);
    check("E: reference_id unchanged (same spot)",      $linkAfter && (string)$linkAfter->reference_id === (string)$spotE);
    check("E: latitude preserved across move",          $linkAfter && abs((float)$linkAfter->lat - 34.75) < 0.0001);
    check("E: longitude preserved across move",         $linkAfter && abs((float)$linkAfter->lng - (-118.25)) < 0.0001);

    // ===================================================================
    // PART F: priority-aware survival — Field delete keeps a multi-subsystem sample
    // ===================================================================
    echo "\n=== Part F: priority-aware survival (sample also in another subsystem) ===\n";
    $pF = nid($base, 601); $dF = nid($base, 602); $spotF = nid($base, 603);
    seedProjectDataset($neodb, $owner, $pF, $dF, $base);
    $sF = seedSampleSpot($db, $neodb, $owner, $pF, $dF, $spotF,
        array('id'=>(string)$spotF, 'sample_id_name'=>'F-shared', 'material_type'=>'igneous'), true, 'POINT (-95.0 38.0)');
    // Attach a SECOND subsystem (micro) link to the same spine sample.
    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey($owner);
    $svc->upsertSample('micro', $sF, $owner,
        array('name'=>'F-shared','igsn'=>null,'description'=>null,'notes'=>null,'latitude'=>null,'longitude'=>null,'display_sample_type'=>null,'display_sample_purpose'=>null),
        array('seeded_micro'=>true),
        array('reference_id'=>'microref-'.$spotF, 'reference_userpkey'=>$owner, 'reference_metadata'=>array('seeded'=>true)));
    $linksBefore = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE sample_id=$1 AND sample_userpkey=$2", array($sF,$owner));
    check("F: sample has 2 subsystem links before delete", $linksBefore === 2);

    $strabo->deleteSingleDataset($dF);

    check("F: sample row SURVIVES (micro still owns it)",  spineCount($db,$owner,$sF) === 1);
    check("F: field link dropped",                         fieldLinkCount($db,$owner,$sF) === 0);
    check("F: micro link remains",                         (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='micro'", array($sF,$owner)) === 1);
    check("F: field_data nulled out",                      $db->get_var_prepared(
        "SELECT field_data FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sF,$owner)) === null);
    check("F: Field spot gone from Neo4j",                 neoSpotCount($neodb,$owner,$spotF) === 0);

    // ===================================================================
    // PART G: helper return values + empty-dataset no-op
    // ===================================================================
    echo "\n=== Part G: helper edge cases ===\n";
    check("G: remove_dataset on unknown dataset returns 0", field_sample_sync_remove_dataset($db,$neodb,nid($base,999),$owner) === 0);
    check("G: remove_project on unknown project returns 0", field_sample_sync_remove_project($db,$neodb,nid($base,998),$owner) === 0);

} finally {
    // -------------------------------------------------------------------
    // Cleanup: spine samples (cascades links/children) → Neo4j → versions.
    // -------------------------------------------------------------------
    foreach (array_unique($allSampleIds) as $sid) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sid, $owner));
    }
    foreach (array_unique($allSpotIds) as $spotId) {
        $neodb->query("MATCH (s:Spot {id:$spotId,userpkey:$owner}) DETACH DELETE s");
    }
    foreach (array_unique($allDatasetIds) as $dsId) {
        $neodb->query("MATCH (d:Dataset {id:$dsId,userpkey:$owner}) DETACH DELETE d");
    }
    foreach (array_unique($allProjectIds) as $projId) {
        $neodb->query("MATCH (p:Project {id:$projId,userpkey:$owner}) DETACH DELETE p");
        $db->query("DELETE FROM versions WHERE projectid='$projId' AND userpkey=$owner");
    }
}

echo "\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
