<?php
/**
 * File: smoke_test_field_writeback.php
 * Description: Service-layer smoke test for samples/field-writeback.
 *              Exercises StraboSamplesService::updateSample for Field-
 *              linked samples and verifies that spine edits propagate
 *              back into the Neo4j Spot's `json_samples` JSON string
 *              (and, for rich spots, into spot.name + modified_timestamp).
 *
 *              Covered:
 *                rich-linked spine update → samples[0] reflects every
 *                inverse-mapped key; spot.name == new sample_id_name;
 *                modified_timestamp bumps
 *                legacy-linked spine update → matching samples[i] updates;
 *                spot.name UNCHANGED (parent spot's name is unrelated);
 *                modified_timestamp bumps
 *                non-Field-linked sample → updateSample succeeds with no
 *                Neo4j writes (writeback is a no-op)
 *                lat/lng update on Field-linked → still rejected with
 *                'field_link_read_only' (existing api-core-writes guard
 *                regression)
 *                writeback drops empty/null spine values from the entry
 *                JSONB-only update (no spine fields) → writeback skipped
 *                idempotent: re-running the same updateSample produces the
 *                same end-state in Neo4j with no spurious data
 *
 *              Hermetic: seeds Neo4j Project+Dataset+Spots (rich + legacy
 *              holder) and seeds strabosamples via field_sample_sync_spot
 *              to bring the Field link rows into existence, then exercises
 *              updateSample. Tears down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_field_writeback.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

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
$projectStraboId = (string)($stamp * 100 + 11);
$datasetStraboId = (string)($stamp * 100 + 22);
$spotRichId        = (int)($stamp * 100 + 31);
$spotLegacyHolder  = (int)($stamp * 100 + 32);
$legacySampleId    = $stamp * 100 + 33;
// Sample without any Field link (direct-API-created later)
$apiOnlySampleId   = "smoketest-fwb-api-only-$stamp";

$richSampleId    = (string)$spotRichId;
$legacySampleStr = (string)$legacySampleId;

// Seed Neo4j: Project, Dataset, two Spots; relationships HAS_DATASET, HAS_SPOT.
$neodb->query("CREATE (p:Project {id:$projectStraboId, userpkey:$ownerPkey, name:'fwb test $stamp'})");
$neodb->query("CREATE (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey, name:'ds $stamp'})");
$neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}),
                     (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey})
               CREATE (p)-[:HAS_DATASET]->(d)");

// Rich sample-spot.
$richSampleObj = array(
    'id'                    => $richSampleId,
    'sample_id_name'        => 'OrigRichName',
    'Sample_IGSN'           => 'IGSN-orig',
    'sample_description'    => 'orig desc',
    'sample_notes'          => 'orig notes',
    'material_type'         => 'intact_rock',
    'main_sampling_purpose' => 'petrology',
);
$richJsonSamples = json_encode(array($richSampleObj));
$richGeom = json_encode(array('type' => 'Point', 'coordinates' => array(-120.5, 45.5)));
$neodb->query("CREATE (s:Spot {
    id: $spotRichId,
    userpkey: $ownerPkey,
    isSample: 1,
    name: 'OrigRichName',
    modified_timestamp: 1000,
    json_samples: '" . addslashes($richJsonSamples) . "',
    geometry: '" . addslashes($richGeom) . "'
})");
$neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                     (s:Spot {id:$spotRichId, userpkey:$ownerPkey})
               CREATE (d)-[:HAS_SPOT]->(s)");

// Legacy holder spot with one inline sample.
$legacySampleObj = array(
    'id'                    => $legacySampleStr,
    'sample_id_name'        => 'OrigLegacyName',
    'sample_description'    => 'legacy orig desc',
    'material_type'         => 'sediment',
);
$legJsonSamples = json_encode(array($legacySampleObj));
$legGeom = json_encode(array('type' => 'Point', 'coordinates' => array(-100.0, 40.0)));
$neodb->query("CREATE (s:Spot {
    id: $spotLegacyHolder,
    userpkey: $ownerPkey,
    name: 'ParentSpotName',
    modified_timestamp: 1000,
    json_samples: '" . addslashes($legJsonSamples) . "',
    geometry: '" . addslashes($legGeom) . "'
})");
$neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                     (s:Spot {id:$spotLegacyHolder, userpkey:$ownerPkey})
               CREATE (d)-[:HAS_SPOT]->(s)");

// Materialize strabosamples rows + Field links via the field_sample_sync_spot path.
$richProps = (object)array(
    'id'       => $spotRichId,
    'samples'  => array((object)$richSampleObj),
    'isSample' => 1,
    'geometry' => (object)array('type' => 'Point', 'coordinates' => array(-120.5, 45.5)),
);
field_sample_sync_spot($db, $neodb, $richProps, $spotRichId, $ownerPkey, $projectStraboId, $datasetStraboId);

$legProps = (object)array(
    'id'       => $spotLegacyHolder,
    'samples'  => array((object)$legacySampleObj),
    'geometry' => (object)array('type' => 'Point', 'coordinates' => array(-100.0, 40.0)),
);
field_sample_sync_spot($db, $neodb, $legProps, $spotLegacyHolder, $ownerPkey, $projectStraboId, $datasetStraboId);

// Helper: read the Spot's json_samples + name + modified_timestamp from Neo4j.
function readSpotProps($neodb, $spotId, $userpkey) {
    $rec = $neodb->getRecord("MATCH (s:Spot {id:$spotId, userpkey:$userpkey}) RETURN s LIMIT 1");
    if (!$rec) return null;
    return $rec->get('s')->values();
}

echo "owner=$ownerPkey  rich=$spotRichId  legacy holder=$spotLegacyHolder  legacy sample=$legacySampleStr\n\n";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

try {
    // -------------------------------------------------------------------
    // PART 1: rich-linked spine update.
    // -------------------------------------------------------------------
    echo "=== Part 1: rich-linked update propagates samples[0] + spot.name + modified_timestamp ===\n";
    $tsBefore = readSpotProps($neodb, $spotRichId, $ownerPkey);
    $tsBeforeMs = (int)$tsBefore['modified_timestamp'];

    $res = $svc->updateSample($richSampleId, $ownerPkey, array(
        'name'                   => 'NewRichName',
        'igsn'                   => 'IGSN-new',
        'description'            => 'updated desc',
        'notes'                  => 'updated notes',
        'display_sample_type'    => 'tephra',
        'display_sample_purpose' => 'geochronology',
    ));
    check("rich update returned ok=true",                      isset($res['ok']) && $res['ok'] === true);

    $afterRich = readSpotProps($neodb, $spotRichId, $ownerPkey);
    $samplesArr = json_decode($afterRich['json_samples'], true);
    check("rich samples[] still has exactly 1 entry",          is_array($samplesArr) && count($samplesArr) === 1);
    check("rich samples[0].id preserved",                      isset($samplesArr[0]['id']) && (string)$samplesArr[0]['id'] === $richSampleId);
    check("samples[0].sample_id_name = 'NewRichName'",         isset($samplesArr[0]['sample_id_name']) && $samplesArr[0]['sample_id_name'] === 'NewRichName');
    check("samples[0].Sample_IGSN = 'IGSN-new'",               isset($samplesArr[0]['Sample_IGSN']) && $samplesArr[0]['Sample_IGSN'] === 'IGSN-new');
    check("samples[0].sample_description updated",             isset($samplesArr[0]['sample_description']) && $samplesArr[0]['sample_description'] === 'updated desc');
    check("samples[0].sample_notes updated",                   isset($samplesArr[0]['sample_notes']) && $samplesArr[0]['sample_notes'] === 'updated notes');
    check("samples[0].material_type = 'tephra'",              isset($samplesArr[0]['material_type']) && $samplesArr[0]['material_type'] === 'tephra');
    check("samples[0].main_sampling_purpose = 'geochronology'", isset($samplesArr[0]['main_sampling_purpose']) && $samplesArr[0]['main_sampling_purpose'] === 'geochronology');
    check("spot.name = 'NewRichName' (rich sync)",             isset($afterRich['name']) && $afterRich['name'] === 'NewRichName');
    check("spot.modified_timestamp bumped",                    isset($afterRich['modified_timestamp']) && (int)$afterRich['modified_timestamp'] > $tsBeforeMs);

    // strabosamples row also reflects (spine UPDATE already ran).
    $sgRow = $db->get_row_prepared(
        "SELECT name, display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($richSampleId, $ownerPkey)
    );
    check("strabosamples spine name updated",                  $sgRow && $sgRow->name === 'NewRichName');
    check("strabosamples display_sample_type updated",         $sgRow && $sgRow->display_sample_type === 'tephra');

    // -------------------------------------------------------------------
    // PART 2: legacy-linked spine update.
    // -------------------------------------------------------------------
    echo "\n=== Part 2: legacy-linked update propagates only the matching samples[i] ===\n";
    $tsBefore = readSpotProps($neodb, $spotLegacyHolder, $ownerPkey);
    $tsBeforeMs = (int)$tsBefore['modified_timestamp'];

    $res = $svc->updateSample($legacySampleStr, $ownerPkey, array(
        'name'        => 'NewLegacyName',
        'description' => 'legacy new desc',
    ));
    check("legacy update returned ok=true",                    isset($res['ok']) && $res['ok'] === true);

    $afterLeg = readSpotProps($neodb, $spotLegacyHolder, $ownerPkey);
    $samplesArr = json_decode($afterLeg['json_samples'], true);
    check("legacy samples[] still has 1 entry",                is_array($samplesArr) && count($samplesArr) === 1);
    check("legacy samples[0].sample_id_name updated",          isset($samplesArr[0]['sample_id_name']) && $samplesArr[0]['sample_id_name'] === 'NewLegacyName');
    check("legacy samples[0].sample_description updated",      isset($samplesArr[0]['sample_description']) && $samplesArr[0]['sample_description'] === 'legacy new desc');
    check("legacy spot.name UNCHANGED (parent spot)",          isset($afterLeg['name']) && $afterLeg['name'] === 'ParentSpotName');
    check("legacy spot.modified_timestamp bumped",             isset($afterLeg['modified_timestamp']) && (int)$afterLeg['modified_timestamp'] > $tsBeforeMs);

    // -------------------------------------------------------------------
    // PART 3: non-Field-linked update is a no-op for Neo4j.
    // -------------------------------------------------------------------
    echo "\n=== Part 3: non-Field-linked sample → updateSample succeeds, no Neo4j writes ===\n";
    // Create a sample direct via createSample so it carries no Field link.
    $created = $svc->createSample(array(
        'id'                  => $apiOnlySampleId,
        'name'                => 'ApiOnly',
        'description'         => 'direct API create',
        'display_sample_type' => 'metamorphic',
    ));
    check("createSample(api-only) returned ok=true",           isset($created['ok']) && $created['ok'] === true);
    // Confirm no Field link exists for it.
    $apiLink = $db->get_var_prepared(
        "SELECT 1 FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field' LIMIT 1",
        array($apiOnlySampleId, $ownerPkey)
    );
    check("api-only sample has NO Field link",                 empty($apiLink));

    $res = $svc->updateSample($apiOnlySampleId, $ownerPkey, array(
        'name' => 'ApiOnlyRenamed',
        'notes'=> 'API only sample notes',
    ));
    check("api-only update returned ok=true",                  isset($res['ok']) && $res['ok'] === true);
    // No Neo4j spot to check; the implicit assertion is that updateSample
    // didn't blow up. (The writeback returns null → caller treats as ok.)

    // -------------------------------------------------------------------
    // PART 4: lat/lng update on Field-linked → still rejected with 409.
    // -------------------------------------------------------------------
    echo "\n=== Part 4: lat/lng on Field-linked still rejected (existing guard regression) ===\n";
    $res = $svc->updateSample($richSampleId, $ownerPkey, array(
        'latitude'  => 50.0,
        'longitude' => -130.0,
    ));
    check("lat/lng on Field-linked: ok=false",                 isset($res['ok']) && $res['ok'] === false);
    check("lat/lng on Field-linked: error='field_link_read_only'", isset($res['error']) && $res['error'] === 'field_link_read_only');

    // -------------------------------------------------------------------
    // PART 5: writeback drops empty/null values from the entry.
    // -------------------------------------------------------------------
    echo "\n=== Part 5: empty/null spine value drops the key from the entry ===\n";
    $svc->updateSample($richSampleId, $ownerPkey, array(
        'notes' => null,
    ));
    $afterClear = readSpotProps($neodb, $spotRichId, $ownerPkey);
    $samplesArr = json_decode($afterClear['json_samples'], true);
    check("samples[0].sample_notes removed when spine set to null", !isset($samplesArr[0]['sample_notes']));

    // -------------------------------------------------------------------
    // PART 6: JSONB-only update doesn't trigger writeback.
    // -------------------------------------------------------------------
    echo "\n=== Part 6: JSONB-only update → no writeback, no Neo4j change ===\n";
    $tsBefore = readSpotProps($neodb, $spotRichId, $ownerPkey);
    $tsBeforeMs = (int)$tsBefore['modified_timestamp'];
    // Sleep a tiny bit so any (incorrect) writeback would be visible via
    // modified_timestamp bump. 1ms is enough since modified_timestamp uses
    // millisecond resolution.
    usleep(2000);
    $res = $svc->updateSample($richSampleId, $ownerPkey, array(
        'field_data' => array('opaque_blob' => 'noop'),
    ));
    check("JSONB-only update returned ok=true",                isset($res['ok']) && $res['ok'] === true);
    $afterNoop = readSpotProps($neodb, $spotRichId, $ownerPkey);
    check("spot.modified_timestamp UNCHANGED for JSONB-only",  isset($afterNoop['modified_timestamp']) && (int)$afterNoop['modified_timestamp'] === $tsBeforeMs);

    // -------------------------------------------------------------------
    // PART 7: idempotency — re-run rich update with same values, no surprise.
    // -------------------------------------------------------------------
    echo "\n=== Part 7: idempotent re-call writes same end state, no drift ===\n";
    $svc->updateSample($richSampleId, $ownerPkey, array(
        'name' => 'NewRichName',
        'display_sample_type' => 'tephra',
    ));
    $afterIdem = readSpotProps($neodb, $spotRichId, $ownerPkey);
    $samplesArr = json_decode($afterIdem['json_samples'], true);
    check("samples[0].sample_id_name still 'NewRichName' after idempotent re-call", $samplesArr[0]['sample_id_name'] === 'NewRichName');
    check("samples[0].material_type still 'tephra' after idempotent re-call",    $samplesArr[0]['material_type'] === 'tephra');

} finally {
    // -------------------------------------------------------------------
    // Cleanup.
    // -------------------------------------------------------------------
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
