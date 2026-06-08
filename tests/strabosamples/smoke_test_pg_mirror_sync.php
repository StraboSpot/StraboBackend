<?php
/**
 * File: smoke_test_pg_mirror_sync.php
 * Description: Verifies that a Samples-app spine edit propagates not only
 *              into the Neo4j Spot.json_samples (covered by
 *              smoke_test_field_writeback.php and smoke_test_field_export_verify.php)
 *              but ALSO into the Postgres `spot` mirror via the
 *              writeBackFieldSpot() → buildPgDataset() call extension.
 *
 *              The PG mirror (`spot.spotjson`, `spot.sample_exists`,
 *              `spot.keywords` tsvector) powers search hit cards and any
 *              PG-driven export. Prior to this branch, writeback wrote
 *              Neo4j but left the PG mirror stale until the next subsystem
 *              upload.
 *
 *              Covered:
 *                Part 1: baseline state — initial buildPgDataset() populates
 *                  spot.spotjson with the original sample values
 *                Part 2: rich-linked updateSample → PG spot.spotjson reflects
 *                  the edit (sample_id_name, material_type, etc.); spot.name
 *                  property in spotjson also updated; keywords tsvector now
 *                  matches the new sample text
 *                Part 3: legacy-linked updateSample → matching samples[i]
 *                  entry inside spotjson updated; parent spot's other fields
 *                  unchanged
 *                Part 4: reference_metadata fallback — manually NULL the
 *                  dataset_id in reference_metadata; updateSample should
 *                  still rebuild the PG mirror via Cypher fallback
 *                Part 5: non-Field-linked updateSample → no PG write (API-only
 *                  samples never had a PG spot row to begin with)
 *
 *              Hermetic: seeds Project (with centroid), Dataset (with
 *              centroid), rich + legacy spots (with wkt) in Neo4j;
 *              materializes strabosamples rows via field_sample_sync_spot;
 *              runs an initial buildPgDataset to establish baseline;
 *              tears everything down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_pg_mirror_sync.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';
require_once '/srv/app/www/db/strabospotclass.php';
// buildPgDataset reads spot WKT via geoPHP. The web entry points load this
// via prepare_connections.php; pull it in directly for the standalone test.
require_once '/srv/app/www/includes/geophp/geoPHP.inc';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array()
);
if (!is_array($users) || count($users) < 1) { echo "Need 1 user\n"; exit(1); }
$ownerPkey = (int)$users[0]->pkey;

$stamp           = time();
$projectStraboId = (int)($stamp * 100 + 11);
$datasetStraboId = (int)($stamp * 100 + 22);
$spotRichId        = (int)($stamp * 100 + 31);
$spotLegacyHolder  = (int)($stamp * 100 + 32);
$legacySampleId    = $stamp * 100 + 33;
$apiOnlySampleId   = "smoketest-pgmirror-api-only-$stamp";

$richSampleId    = (string)$spotRichId;
$legacySampleStr = (string)$legacySampleId;

// Seed Neo4j: Project (with centroid), Dataset (with centroid), two Spots.
// buildPgDataset() requires p.centroid + s.wkt to consider the dataset
// at all (line 5612 of db/strabospotclass.php: WHERE EXISTS(s.wkt) AND
// EXISTS(p.centroid)).
$neodb->query("CREATE (p:Project {id:$projectStraboId, userpkey:$ownerPkey,
    name:'pg mirror $stamp', centroid:'POINT(-110.0 42.5)'})");
$neodb->query("CREATE (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey,
    name:'ds $stamp', centroid:'POINT(-110.0 42.5)'})");
$neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}),
                     (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey})
               CREATE (p)-[:HAS_DATASET]->(d)");

// Rich sample-spot (isSample=1, samples[0] authoritative).
$richSampleObj = array(
    'id'                    => $richSampleId,
    'sample_id_name'        => 'BaselineRich',
    'Sample_IGSN'           => 'IGSN-base-rich',
    'sample_description'    => 'baseline rich description',
    'sample_notes'          => 'baseline rich notes',
    'material_type'         => 'intact_rock',
    'main_sampling_purpose' => 'petrology',
);
$richJsonSamples = json_encode(array($richSampleObj));
$richWkt = 'POINT(-120.5 45.5)';
$neodb->query("CREATE (s:Spot {
    id: $spotRichId,
    userpkey: $ownerPkey,
    isSample: 1,
    name: 'BaselineRich',
    modified_timestamp: 1000,
    json_samples: '" . addslashes($richJsonSamples) . "',
    wkt: '" . addslashes($richWkt) . "'
})");
$neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                     (s:Spot {id:$spotRichId, userpkey:$ownerPkey})
               CREATE (d)-[:HAS_SPOT]->(s)");

// Legacy holder spot.
$legacySampleObj = array(
    'id'                    => $legacySampleStr,
    'sample_id_name'        => 'BaselineLegacy',
    'sample_description'    => 'baseline legacy description',
    'material_type'         => 'sediment',
);
$legJsonSamples = json_encode(array($legacySampleObj));
$legWkt = 'POINT(-100.0 40.0)';
$neodb->query("CREATE (s:Spot {
    id: $spotLegacyHolder,
    userpkey: $ownerPkey,
    name: 'ParentLegacy',
    modified_timestamp: 1000,
    json_samples: '" . addslashes($legJsonSamples) . "',
    wkt: '" . addslashes($legWkt) . "'
})");
$neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                     (s:Spot {id:$spotLegacyHolder, userpkey:$ownerPkey})
               CREATE (d)-[:HAS_SPOT]->(s)");

// Materialize strabosamples rows + Field links.
$richProps = (object)array(
    'id'       => $spotRichId,
    'samples'  => array((object)$richSampleObj),
    'isSample' => 1,
);
field_sample_sync_spot($db, $neodb, $richProps, $spotRichId, $ownerPkey, (string)$projectStraboId, (string)$datasetStraboId);

$legProps = (object)array(
    'id'       => $spotLegacyHolder,
    'samples'  => array((object)$legacySampleObj),
);
field_sample_sync_spot($db, $neodb, $legProps, $spotLegacyHolder, $ownerPkey, (string)$projectStraboId, (string)$datasetStraboId);

// Run the initial buildPgDataset to establish the PG `spot` baseline state
// — this is what an upload would have done.
$straboInit = new StraboSpot($neodb, $ownerPkey, $db);
$straboInit->buildPgDataset($datasetStraboId, $ownerPkey);

echo "owner=$ownerPkey  project=$projectStraboId  dataset=$datasetStraboId\n";
echo "rich sample id=$richSampleId  legacy sample id=$legacySampleStr\n\n";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

// Helper: read PG spot row by strabo_spot_id + user_pkey.
function readPgSpot($db, $straboSpotId, $ownerPkey) {
    $row = $db->get_row_prepared(
        "SELECT spot_pkey, sample_exists, spotjson, keywords::text AS keywords_text
           FROM spot
          WHERE strabo_spot_id = $1 AND user_pkey = $2 LIMIT 1",
        array((string)$straboSpotId, (int)$ownerPkey)
    );
    return $row;
}

// Helper: find a sample entry by id in a decoded spotjson.
function findSampleInJson($spotjson, $sampleId) {
    $j = json_decode($spotjson, true);
    if (!is_array($j) || !isset($j['properties']['samples'])) return null;
    foreach ($j['properties']['samples'] as $s) {
        if (isset($s['id']) && (string)$s['id'] === (string)$sampleId) return $s;
    }
    return null;
}

try {
    // -------------------------------------------------------------------
    // PART 1: baseline — PG mirror reflects original sample values.
    // -------------------------------------------------------------------
    echo "=== Part 1: baseline PG mirror state ===\n";
    $richRow = readPgSpot($db, $spotRichId, $ownerPkey);
    check("baseline: rich PG spot row exists",                          $richRow !== null);
    check("baseline: rich sample_exists = TRUE",                        $richRow && $richRow->sample_exists === 't');
    $richSampInJson = $richRow ? findSampleInJson($richRow->spotjson, $richSampleId) : null;
    check("baseline: rich spotjson samples[].sample_id_name = 'BaselineRich'",
          $richSampInJson && isset($richSampInJson['sample_id_name']) && $richSampInJson['sample_id_name'] === 'BaselineRich');
    check("baseline: rich keywords tsvector contains 'baselinerich'",
          $richRow && stripos($richRow->keywords_text, 'baselinerich') !== false);

    $legRow = readPgSpot($db, $spotLegacyHolder, $ownerPkey);
    check("baseline: legacy PG spot row exists",                        $legRow !== null);
    check("baseline: legacy sample_exists = TRUE",                      $legRow && $legRow->sample_exists === 't');
    $legSampInJson = $legRow ? findSampleInJson($legRow->spotjson, $legacySampleStr) : null;
    check("baseline: legacy spotjson samples[].sample_id_name = 'BaselineLegacy'",
          $legSampInJson && isset($legSampInJson['sample_id_name']) && $legSampInJson['sample_id_name'] === 'BaselineLegacy');

    // -------------------------------------------------------------------
    // PART 2: rich-linked updateSample → PG mirror reflects edit.
    // -------------------------------------------------------------------
    echo "\n=== Part 2: rich-linked updateSample propagates to PG mirror ===\n";
    $res = $svc->updateSample($richSampleId, $ownerPkey, array(
        'name'                   => 'EditedRich',
        'description'            => 'edited rich description',
        'display_sample_type'    => 'tephra',
        'display_sample_purpose' => 'geochronology',
    ));
    check("rich updateSample: ok=true",                                 isset($res['ok']) && $res['ok'] === true);

    $richRow = readPgSpot($db, $spotRichId, $ownerPkey);
    check("rich PG spot row still exists after update",                 $richRow !== null);
    $richSampInJson = $richRow ? findSampleInJson($richRow->spotjson, $richSampleId) : null;
    check("rich spotjson samples[].sample_id_name = 'EditedRich'",
          $richSampInJson && isset($richSampInJson['sample_id_name']) && $richSampInJson['sample_id_name'] === 'EditedRich');
    check("rich spotjson samples[].sample_description = 'edited rich description'",
          $richSampInJson && isset($richSampInJson['sample_description']) && $richSampInJson['sample_description'] === 'edited rich description');
    check("rich spotjson samples[].material_type = 'tephra'",
          $richSampInJson && isset($richSampInJson['material_type']) && $richSampInJson['material_type'] === 'tephra');
    check("rich spotjson samples[].main_sampling_purpose = 'geochronology'",
          $richSampInJson && isset($richSampInJson['main_sampling_purpose']) && $richSampInJson['main_sampling_purpose'] === 'geochronology');
    // Rich path also updates spot.name in Neo4j, which flows to PG spotjson.
    $j = json_decode($richRow->spotjson, true);
    check("rich spotjson properties.name = 'EditedRich'",
          isset($j['properties']['name']) && $j['properties']['name'] === 'EditedRich');
    // tsvector reflects the new sample text (full-text search picks up edits).
    check("rich keywords tsvector contains 'editedrich'",
          stripos($richRow->keywords_text, 'editedrich') !== false);
    check("rich keywords tsvector contains 'tephra'",
          stripos($richRow->keywords_text, 'tephra') !== false);

    // -------------------------------------------------------------------
    // PART 3: legacy-linked updateSample → matching samples[i] entry updates.
    // -------------------------------------------------------------------
    echo "\n=== Part 3: legacy-linked updateSample propagates to PG mirror ===\n";
    $res = $svc->updateSample($legacySampleStr, $ownerPkey, array(
        'name'        => 'EditedLegacy',
        'description' => 'edited legacy description',
    ));
    check("legacy updateSample: ok=true",                               isset($res['ok']) && $res['ok'] === true);

    $legRow = readPgSpot($db, $spotLegacyHolder, $ownerPkey);
    $legSampInJson = $legRow ? findSampleInJson($legRow->spotjson, $legacySampleStr) : null;
    check("legacy spotjson samples[].sample_id_name = 'EditedLegacy'",
          $legSampInJson && isset($legSampInJson['sample_id_name']) && $legSampInJson['sample_id_name'] === 'EditedLegacy');
    check("legacy spotjson samples[].sample_description = 'edited legacy description'",
          $legSampInJson && isset($legSampInJson['sample_description']) && $legSampInJson['sample_description'] === 'edited legacy description');
    // Parent spot's other top-level fields are independent of the sample.
    $jLeg = json_decode($legRow->spotjson, true);
    check("legacy spotjson properties.name UNCHANGED ('ParentLegacy')",
          isset($jLeg['properties']['name']) && $jLeg['properties']['name'] === 'ParentLegacy');

    // -------------------------------------------------------------------
    // PART 4: reference_metadata fallback — strip dataset_id, re-edit,
    // confirm Cypher fallback finds the dataset and rebuilds the mirror.
    // -------------------------------------------------------------------
    echo "\n=== Part 4: dataset_id reference_metadata fallback ===\n";
    // NULL out the dataset_id field inside the JSONB reference_metadata.
    $db->prepare_query(
        "UPDATE strabosamples.sample_subsystem_links
            SET reference_metadata = jsonb_set(reference_metadata, '{dataset_id}', 'null'::jsonb)
          WHERE sample_id = $1 AND sample_userpkey = $2 AND subsystem = 'field'",
        array($richSampleId, $ownerPkey)
    );
    $check = $db->get_var_prepared(
        "SELECT reference_metadata->>'dataset_id'
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id = $1 AND sample_userpkey = $2 AND subsystem = 'field'",
        array($richSampleId, $ownerPkey)
    );
    check("fixture: dataset_id is now null in reference_metadata",       $check === null || $check === '');

    $svc->updateSample($richSampleId, $ownerPkey, array(
        'description' => 'fallback path test',
    ));
    $richRow = readPgSpot($db, $spotRichId, $ownerPkey);
    $richSampInJson = $richRow ? findSampleInJson($richRow->spotjson, $richSampleId) : null;
    check("fallback: rich spotjson samples[].sample_description updated",
          $richSampInJson && isset($richSampInJson['sample_description']) && $richSampInJson['sample_description'] === 'fallback path test');

    // -------------------------------------------------------------------
    // PART 5: non-Field-linked updateSample → no PG row to update, no error.
    // -------------------------------------------------------------------
    echo "\n=== Part 5: non-Field-linked updateSample is a clean no-op for PG mirror ===\n";
    $created = $svc->createSample(array(
        'id'   => $apiOnlySampleId,
        'name' => 'ApiOnly',
    ));
    check("createSample(api-only) ok",                                   isset($created['ok']) && $created['ok'] === true);

    $res = $svc->updateSample($apiOnlySampleId, $ownerPkey, array(
        'description' => 'API-only edit',
    ));
    check("api-only updateSample ok=true (no Neo4j/PG to write)",        isset($res['ok']) && $res['ok'] === true);

} finally {
    // -------------------------------------------------------------------
    // Cleanup.
    // -------------------------------------------------------------------
    // PG: spot/dataset/project rows. Find pkeys via strabo ids.
    $proj = $db->get_row_prepared(
        "SELECT project_pkey FROM project WHERE strabo_project_id=$1 AND user_pkey=$2",
        array((string)$projectStraboId, $ownerPkey)
    );
    if ($proj) {
        $db->prepare_query("DELETE FROM spot WHERE project_pkey=$1", array($proj->project_pkey));
        $db->prepare_query("DELETE FROM dataset WHERE project_pkey=$1", array($proj->project_pkey));
        $db->prepare_query("DELETE FROM project WHERE project_pkey=$1", array($proj->project_pkey));
    }

    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id IN ($1, $2, $3) AND userpkey=$4",
        array($richSampleId, $legacySampleStr, $apiOnlySampleId, $ownerPkey)
    );
    $neodb->query("MATCH (s:Spot {id:$spotRichId, userpkey:$ownerPkey}) DETACH DELETE s");
    $neodb->query("MATCH (s:Spot {id:$spotLegacyHolder, userpkey:$ownerPkey}) DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}) DETACH DELETE d");
    $neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}) DETACH DELETE p");
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
