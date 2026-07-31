<?php
/**
 * File: smoke_test_shapefile_sample_sync.php
 * Description: Phase I (non-API surface coverage) — verifies that the
 *              "Load Shapefile" upload path (load_shapefile.php) mirrors
 *              sample sub-objects into strabosamples.* WITH full project/
 *              dataset context.
 *
 *              The risk: load_shapefile.php calls insertSpot() (which fires
 *              the field_sample_sync_spot hook) and only AFTERWARDS calls
 *              addSpotToDataset(). So at the instant the hook runs, the new
 *              spot has NO HAS_DATASET<-HAS_SPOT edge yet, and the hook's
 *              Cypher context fallback cannot resolve the project/dataset.
 *              Without an explicit setSampleSyncContext() the strabosamples
 *              subsystem-link lands with NULL project_id/dataset_id — the
 *              sample shows in My Samples but with no "which project/dataset"
 *              context. The fix: load_shapefile.php now calls
 *              setSampleSyncContext($projectid,$datasetid) before the loop.
 *
 *              This test reproduces that exact ordering by calling the hook
 *              directly against a spot that is NOT dataset-linked:
 *                Part 1 (the fix): explicit context → link carries project_id
 *                        + dataset_id even though the spot isn't linked yet.
 *                Part 2 (the pre-fix bug, negative control): NO context +
 *                        unlinked spot → link reference_metadata is NULL.
 *                Part 3: the sample row itself always lands (findable in
 *                        My Samples) regardless of context.
 *
 *              Hermetic: seeds Neo4j Project/Dataset/Spots (deliberately
 *              WITHOUT the spot->dataset edge), tears everything down.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_shapefile_sample_sync.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/db/lib/sample_sync.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array()
);
if (!is_array($users) || count($users) < 1) { echo "Need 1 user\n"; exit(1); }
$ownerPkey = (int)$users[0]->pkey;

$stamp           = time();
$projectStraboId = (string)($stamp * 100 + 11);
$datasetStraboId = (string)($stamp * 100 + 22);
$spotFixId       = (int)($stamp * 100 + 31);   // imported WITH context (the fix)
$spotBugId       = (int)($stamp * 100 + 32);   // imported WITHOUT context (pre-fix bug)
$sampleFixId     = (string)$spotFixId;
$sampleBugId     = (string)$spotBugId;

function linkMeta($db, $sampleId, $ownerPkey) {
    return $db->get_row_prepared(
        "SELECT reference_metadata->>'project_id' AS pid,
                reference_metadata->>'dataset_id' AS did
           FROM strabosamples.sample_subsystem_links
          WHERE subsystem='field' AND sample_id=$1 AND sample_userpkey=$2
          LIMIT 1",
        array($sampleId, $ownerPkey)
    );
}

try {
    // Seed Project + Dataset + HAS_DATASET. Crucially we DO seed the two Spot
    // nodes but DELIBERATELY do NOT link them to the dataset — exactly the
    // state load_shapefile.php is in when insertSpot's hook fires.
    $neodb->query("CREATE (p:Project {id:$projectStraboId, userpkey:$ownerPkey, desc_project_name:'shp smoke $stamp'})");
    $neodb->query("CREATE (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey, name:'shp ds $stamp'})");
    $neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}),
                         (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey})
                   CREATE (p)-[:HAS_DATASET]->(d)");

    $sampleObjFix = array(
        'id'                    => $sampleFixId,
        'sample_id_name'        => 'Shp-Fix',
        'sample_description'    => 'shapefile sample with context',
        'material_type'         => 'sedimentary',
        'main_sampling_purpose' => 'geochronology',
    );
    $sampleObjBug = array(
        'id'                    => $sampleBugId,
        'sample_id_name'        => 'Shp-Bug',
        'sample_description'    => 'shapefile sample without context',
        'material_type'         => 'igneous',
        'main_sampling_purpose' => 'petrology',
    );
    foreach (array(array($spotFixId,$sampleObjFix), array($spotBugId,$sampleObjBug)) as $pair) {
        list($sid, $obj) = $pair;
        $js = addslashes(json_encode(array($obj)));
        $neodb->query("CREATE (s:Spot {id:$sid, userpkey:$ownerPkey, isSample:1,
                        json_samples:'$js', wkt:'POINT (-100.0 40.0)'})");
        // NOTE: intentionally NO (d)-[:HAS_SPOT]->(s) — mimics load_shapefile
        // calling insertSpot before addSpotToDataset.
    }

    // ===================================================================
    echo "=== Part 1: explicit context (the fix) → link carries project/dataset ===\n";
    // ===================================================================
    $propsFix = (object)array('id'=>$spotFixId, 'isSample'=>1,
                              'samples'=>array((object)$sampleObjFix),
                              'wkt'=>'POINT (-100.0 40.0)');
    // This is what setSampleSyncContext($projectid,$datasetid) supplies into
    // insertSpot's hook call.
    field_sample_sync_spot($db, $neodb, $propsFix, $spotFixId, $ownerPkey,
                           $projectStraboId, $datasetStraboId);

    $m = linkMeta($db, $sampleFixId, $ownerPkey);
    check("link row created for context sample", is_object($m));
    check("reference_metadata.project_id populated", $m && $m->pid === $projectStraboId);
    check("reference_metadata.dataset_id populated", $m && $m->did === $datasetStraboId);

    // ===================================================================
    echo "=== Part 2: NO context + unlinked spot (pre-fix bug) → NULL metadata ===\n";
    // ===================================================================
    $propsBug = (object)array('id'=>$spotBugId, 'isSample'=>1,
                              'samples'=>array((object)$sampleObjBug),
                              'wkt'=>'POINT (-100.0 40.0)');
    // No project/dataset args → hook tries the Cypher fallback, which finds no
    // dataset edge (spot unlinked) → NULL context. This is the bug the fix avoids.
    field_sample_sync_spot($db, $neodb, $propsBug, $spotBugId, $ownerPkey);

    $mb = linkMeta($db, $sampleBugId, $ownerPkey);
    check("link row created for no-context sample", is_object($mb));
    check("reference_metadata.project_id is NULL (reproduces bug)", $mb && $mb->pid === null);
    check("reference_metadata.dataset_id is NULL (reproduces bug)", $mb && $mb->did === null);

    // ===================================================================
    echo "=== Part 3: sample row itself lands either way (My Samples findable) ===\n";
    // ===================================================================
    foreach (array($sampleFixId, $sampleBugId) as $sid) {
        $cnt = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sid, $ownerPkey));
        check("strabosamples.samples row exists for $sid", $cnt === 1);
    }

} finally {
    foreach (array($sampleFixId, $sampleBugId) as $sid) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sid, $ownerPkey));
    }
    $neodb->query("MATCH (s:Spot) WHERE s.id IN [$spotFixId,$spotBugId] AND s.userpkey=$ownerPkey DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}) DETACH DELETE d");
    $neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}) DETACH DELETE p");
}

echo "\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " FAILURE(S)\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
