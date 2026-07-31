<?php
/**
 * File: smoke_test_field_export_verify.php
 * Description: Verifies that Samples-app spine edits propagate through the
 *              existing Field export pipeline. The contract under test:
 *
 *                  StraboSamplesService::updateSample()
 *                    → writeBackFieldSpot() updates Neo4j Spot.json_samples
 *                    → strabo->getDatasetSpotsSearch() reads the fresh Spot
 *                    → every downstream export format (GeoJSON, Shapefile,
 *                      GeoPackage, KML, Excel, GeMS, PDF fieldbook, DOI ZIP)
 *                      consumes the FeatureCollection from getDatasetSpotsSearch.
 *
 *              If the boundary (getDatasetSpotsSearch) returns the edited
 *              values, every export format gets them. This suite asserts at
 *              the boundary + spot-checks two output methods that return
 *              structured data:
 *                  geoJSONOut()  — captured via output buffering
 *                  doiDataOut($projectid)  — returns spotsDb when called with
 *                                            an explicit projectid arg
 *
 *              Hermetic: seeds Project + Dataset + rich + legacy spot fixtures,
 *              materializes strabosamples rows via field_sample_sync_spot, then
 *              tears everything down in finally{}.
 *
 *              Spot.wkt is set as 'POINT(lng lat)' — see
 *              samplesdb/lib/field_geom.php for the canonical geometry shape.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_field_export_verify.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';
require_once '/srv/app/www/db/strabospotclass.php';
require_once '/srv/app/www/includes/straboClasses/straboOutputClass.php';

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

$richSampleId    = (string)$spotRichId;
$legacySampleStr = (string)$legacySampleId;

// Seed Neo4j: Project, Dataset, two Spots; relationships HAS_DATASET, HAS_SPOT.
$neodb->query("CREATE (p:Project {id:$projectStraboId, userpkey:$ownerPkey, name:'export verify $stamp'})");
$neodb->query("CREATE (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey, name:'ds $stamp'})");
$neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}),
                     (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey})
               CREATE (p)-[:HAS_DATASET]->(d)");

// Rich sample-spot. Spot.wkt holds the geometry; json_samples holds the
// authoritative sample object (samples[0]).
$richSampleObj = array(
    'id'                    => $richSampleId,
    'sample_id_name'        => 'BaselineRichName',
    'Sample_IGSN'           => 'IGSN-baseline-rich',
    'sample_description'    => 'baseline rich desc',
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
    name: 'BaselineRichName',
    modified_timestamp: 1000,
    json_samples: '" . addslashes($richJsonSamples) . "',
    wkt: '" . addslashes($richWkt) . "'
})");
$neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                     (s:Spot {id:$spotRichId, userpkey:$ownerPkey})
               CREATE (d)-[:HAS_SPOT]->(s)");

// Legacy holder spot with one inline sample (non-rich parent spot).
$legacySampleObj = array(
    'id'                    => $legacySampleStr,
    'sample_id_name'        => 'BaselineLegacyName',
    'sample_description'    => 'baseline legacy desc',
    'material_type'         => 'sediment',
);
$legJsonSamples = json_encode(array($legacySampleObj));
$legWkt = 'POINT(-100.0 40.0)';
$neodb->query("CREATE (s:Spot {
    id: $spotLegacyHolder,
    userpkey: $ownerPkey,
    name: 'ParentLegacyHolder',
    modified_timestamp: 1000,
    json_samples: '" . addslashes($legJsonSamples) . "',
    wkt: '" . addslashes($legWkt) . "'
})");
$neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                     (s:Spot {id:$spotLegacyHolder, userpkey:$ownerPkey})
               CREATE (d)-[:HAS_SPOT]->(s)");

// Materialize strabosamples rows + Field links via the field_sample_sync_spot
// path. After this, both samples are first-class strabosamples rows with
// edits flowing through writeBackFieldSpot().
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

echo "owner=$ownerPkey  project=$projectStraboId  dataset=$datasetStraboId\n";
echo "rich sample id=$richSampleId  legacy sample id=$legacySampleStr\n\n";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

// Helper: pull the FeatureCollection through the boundary used by every
// Field export downstream.
$strabo = new StraboSpot($neodb, $ownerPkey, $db);

function fetchFeatures($strabo, $datasetId, $ownerPkey) {
    $getvals = array(
        'dsids'    => (string)$datasetId,
        'userpkey' => $ownerPkey,
        'type'     => 'doi',
    );
    $json = $strabo->getDatasetSpotsSearch(null, $getvals);
    return is_array($json) && isset($json['features']) ? $json['features'] : array();
}

// Helper: deep-convert object graph to assoc arrays. getFeatureCollection()
// returns samples[] as stdClass objects (decoded without assoc=true). The
// boundary check exercises the consumer perspective; normalize here so
// every assertion in this test reads the same shape.
function toArr($val) {
    return json_decode(json_encode($val), true);
}

// Helper: find a feature by spot id from the FeatureCollection.
function findFeature($features, $spotId) {
    foreach ($features as $f) {
        $f = toArr($f);
        $props = isset($f['properties']) ? $f['properties'] : array();
        if (isset($props['id']) && (string)$props['id'] === (string)$spotId) return $f;
    }
    return null;
}

// Helper: find a sample entry by id within a feature's properties.samples[].
function findSampleEntry($feature, $sampleId) {
    $feature = toArr($feature);
    $samples = isset($feature['properties']['samples']) ? $feature['properties']['samples'] : array();
    foreach ($samples as $s) {
        $s = toArr($s);
        if (isset($s['id']) && (string)$s['id'] === (string)$sampleId) return $s;
    }
    return null;
}

try {
    // -------------------------------------------------------------------
    // PART 1: baseline through getDatasetSpotsSearch.
    // -------------------------------------------------------------------
    echo "=== Part 1: baseline FeatureCollection via getDatasetSpotsSearch ===\n";
    $features = fetchFeatures($strabo, $datasetStraboId, $ownerPkey);
    check("baseline: feature collection has 2 spots",
          count($features) === 2);

    $rich = findFeature($features, $spotRichId);
    check("baseline: rich spot present in collection",                  $rich !== null);
    $richEntry = $rich ? findSampleEntry($rich, $richSampleId) : null;
    check("baseline: rich samples[].sample_id_name = 'BaselineRichName'",
          $richEntry && isset($richEntry['sample_id_name']) && $richEntry['sample_id_name'] === 'BaselineRichName');
    check("baseline: rich samples[].material_type = 'intact_rock'",
          $richEntry && isset($richEntry['material_type']) && $richEntry['material_type'] === 'intact_rock');
    check("baseline: rich samples[].Sample_IGSN = 'IGSN-baseline-rich'",
          $richEntry && isset($richEntry['Sample_IGSN']) && $richEntry['Sample_IGSN'] === 'IGSN-baseline-rich');

    $legacy = findFeature($features, $spotLegacyHolder);
    check("baseline: legacy holder spot present",                       $legacy !== null);
    $legacyEntry = $legacy ? findSampleEntry($legacy, $legacySampleStr) : null;
    check("baseline: legacy samples[].sample_id_name = 'BaselineLegacyName'",
          $legacyEntry && isset($legacyEntry['sample_id_name']) && $legacyEntry['sample_id_name'] === 'BaselineLegacyName');
    check("baseline: legacy samples[].sample_description = 'baseline legacy desc'",
          $legacyEntry && isset($legacyEntry['sample_description']) && $legacyEntry['sample_description'] === 'baseline legacy desc');

    // -------------------------------------------------------------------
    // PART 2: apply Samples-app spine edits.
    // -------------------------------------------------------------------
    echo "\n=== Part 2: Samples-app updateSample on rich + legacy ===\n";

    $resRich = $svc->updateSample($richSampleId, $ownerPkey, array(
        'name'                   => 'EditedRichName',
        'igsn'                   => 'IGSN-edited-rich',
        'description'            => 'edited rich desc',
        'notes'                  => 'edited rich notes',
        'display_sample_type'    => 'tephra',
        'display_sample_purpose' => 'geochronology',
    ));
    check("rich updateSample: ok=true",                                 isset($resRich['ok']) && $resRich['ok'] === true);

    $resLeg = $svc->updateSample($legacySampleStr, $ownerPkey, array(
        'name'        => 'EditedLegacyName',
        'description' => 'edited legacy desc',
    ));
    check("legacy updateSample: ok=true",                               isset($resLeg['ok']) && $resLeg['ok'] === true);

    // -------------------------------------------------------------------
    // PART 3: BOUNDARY CHECK — re-fetch via getDatasetSpotsSearch and
    // assert that the edited spine values are visible. This is the
    // determinative assertion: if the boundary returns the edits, every
    // downstream Field export format gets them.
    // -------------------------------------------------------------------
    echo "\n=== Part 3: BOUNDARY — getDatasetSpotsSearch reflects edits ===\n";
    $features = fetchFeatures($strabo, $datasetStraboId, $ownerPkey);
    check("boundary: feature collection still has 2 spots",             count($features) === 2);

    $rich = findFeature($features, $spotRichId);
    $richEntry = $rich ? findSampleEntry($rich, $richSampleId) : null;
    check("boundary: rich samples[].sample_id_name = 'EditedRichName'",
          $richEntry && isset($richEntry['sample_id_name']) && $richEntry['sample_id_name'] === 'EditedRichName');
    check("boundary: rich samples[].Sample_IGSN = 'IGSN-edited-rich'",
          $richEntry && isset($richEntry['Sample_IGSN']) && $richEntry['Sample_IGSN'] === 'IGSN-edited-rich');
    check("boundary: rich samples[].sample_description = 'edited rich desc'",
          $richEntry && isset($richEntry['sample_description']) && $richEntry['sample_description'] === 'edited rich desc');
    check("boundary: rich samples[].sample_notes = 'edited rich notes'",
          $richEntry && isset($richEntry['sample_notes']) && $richEntry['sample_notes'] === 'edited rich notes');
    check("boundary: rich samples[].material_type = 'tephra'",
          $richEntry && isset($richEntry['material_type']) && $richEntry['material_type'] === 'tephra');
    check("boundary: rich samples[].main_sampling_purpose = 'geochronology'",
          $richEntry && isset($richEntry['main_sampling_purpose']) && $richEntry['main_sampling_purpose'] === 'geochronology');
    // Rich sync also bumps spot.name (= sample_id_name).
    check("boundary: rich spot.name = 'EditedRichName'",
          $rich && isset($rich['properties']['name']) && $rich['properties']['name'] === 'EditedRichName');

    $legacy = findFeature($features, $spotLegacyHolder);
    $legacyEntry = $legacy ? findSampleEntry($legacy, $legacySampleStr) : null;
    check("boundary: legacy samples[].sample_id_name = 'EditedLegacyName'",
          $legacyEntry && isset($legacyEntry['sample_id_name']) && $legacyEntry['sample_id_name'] === 'EditedLegacyName');
    check("boundary: legacy samples[].sample_description = 'edited legacy desc'",
          $legacyEntry && isset($legacyEntry['sample_description']) && $legacyEntry['sample_description'] === 'edited legacy desc');
    // Legacy holder's parent spot.name is unrelated to the sample's name.
    check("boundary: legacy spot.name UNCHANGED ('ParentLegacyHolder')",
          $legacy && isset($legacy['properties']['name']) && $legacy['properties']['name'] === 'ParentLegacyHolder');

    // -------------------------------------------------------------------
    // PART 4: spot-check geoJSONOut() — captures the JSON it would
    // stream to a download response, asserts edited values present.
    // -------------------------------------------------------------------
    echo "\n=== Part 4: geoJSONOut output buffering ===\n";
    // straboOutputClass uses $this->get for params; pass dsids.
    $getParams = array('dsids' => (string)$datasetStraboId);
    $straboOut = new straboOutputClass($strabo, $getParams);

    ob_start();
    @$straboOut->geoJSONOut();
    $geoJsonRaw = ob_get_clean();
    // The method also calls header() — those go to PHP's output buffer in CLI
    // and we don't care about them; the JSON body is what we assert against.

    $decoded = json_decode($geoJsonRaw, true);
    check("geoJSONOut: output parses as JSON",                          is_array($decoded));
    check("geoJSONOut: type = 'FeatureCollection'",                     isset($decoded['type']) && $decoded['type'] === 'FeatureCollection');
    check("geoJSONOut: features has 2 entries",                         isset($decoded['features']) && count($decoded['features']) === 2);

    $rich = findFeature($decoded['features'], $spotRichId);
    $richEntry = $rich ? findSampleEntry($rich, $richSampleId) : null;
    check("geoJSONOut: rich samples[].sample_id_name = 'EditedRichName'",
          $richEntry && isset($richEntry['sample_id_name']) && $richEntry['sample_id_name'] === 'EditedRichName');
    check("geoJSONOut: rich samples[].material_type = 'tephra'",
          $richEntry && isset($richEntry['material_type']) && $richEntry['material_type'] === 'tephra');

    $legacy = findFeature($decoded['features'], $spotLegacyHolder);
    $legacyEntry = $legacy ? findSampleEntry($legacy, $legacySampleStr) : null;
    check("geoJSONOut: legacy samples[].sample_id_name = 'EditedLegacyName'",
          $legacyEntry && isset($legacyEntry['sample_id_name']) && $legacyEntry['sample_id_name'] === 'EditedLegacyName');

    // -------------------------------------------------------------------
    // PART 5: spot-check doiDataOut() — the path used by the
    // /fieldZips/<uuid>/ bulk ZIP build (build_field_download.php).
    // Called with an explicit projectid arg, it returns a structured
    // projectDb instead of emitting to stdout.
    // -------------------------------------------------------------------
    echo "\n=== Part 5: doiDataOut() structured return ===\n";
    $straboOut2 = new straboOutputClass($strabo, array());
    $project = $straboOut2->doiDataOut($projectStraboId);
    check("doiDataOut: returned object is not null",                    is_object($project));
    // spotsDb is a stdClass keyed by spot id (see straboOutputClass.php:9386).
    $spotsDbArr = (is_object($project) && isset($project->spotsDb)) ? (array)$project->spotsDb : array();
    check("doiDataOut: spotsDb is populated",                           count($spotsDbArr) > 0);

    // Find the rich + legacy spots in spotsDb.
    $foundRich = null;
    $foundLegacy = null;
    foreach ($spotsDbArr as $spot) {
        $sa = toArr($spot);
        $sid = isset($sa['properties']['id']) ? (string)$sa['properties']['id'] : '';
        if ($sid === (string)$spotRichId)        $foundRich   = $sa;
        if ($sid === (string)$spotLegacyHolder)  $foundLegacy = $sa;
    }
    check("doiDataOut: rich spot present in spotsDb",                   $foundRich !== null);
    $richEntry = $foundRich ? findSampleEntry($foundRich, $richSampleId) : null;
    check("doiDataOut: rich samples[].sample_id_name = 'EditedRichName'",
          $richEntry && isset($richEntry['sample_id_name']) && $richEntry['sample_id_name'] === 'EditedRichName');
    check("doiDataOut: rich samples[].material_type = 'tephra'",
          $richEntry && isset($richEntry['material_type']) && $richEntry['material_type'] === 'tephra');

    check("doiDataOut: legacy spot present in spotsDb",                 $foundLegacy !== null);
    $legacyEntry = $foundLegacy ? findSampleEntry($foundLegacy, $legacySampleStr) : null;
    check("doiDataOut: legacy samples[].sample_id_name = 'EditedLegacyName'",
          $legacyEntry && isset($legacyEntry['sample_id_name']) && $legacyEntry['sample_id_name'] === 'EditedLegacyName');

} finally {
    // -------------------------------------------------------------------
    // Cleanup.
    // -------------------------------------------------------------------
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id IN ($1, $2) AND userpkey=$3",
        array($richSampleId, $legacySampleStr, $ownerPkey)
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
