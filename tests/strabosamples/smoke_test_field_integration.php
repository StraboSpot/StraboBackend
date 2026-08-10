<?php
/**
 * File: smoke_test_field_integration.php
 * Description: Service-layer smoke test for samples/field-integration.
 *              Exercises field_sample_sync_spot + _remove_spot directly
 *              against seeded Neo4j Spots, verifying:
 *
 *                rich sample-spot (isSample=1, samples[0] authoritative) →
 *                legacy inline sample upload (non-rich parent spot) →
 *                stub-skip when a parent's samples[] entry id matches a
 *                rich sample-spot already in Neo4j (matches the migration's
 *                de-dup) →
 *                material_type='other' fall-through to other_material_type →
 *                re-call idempotency (zero new sample/link rows; no extra
 *                changelog 'create's) →
 *                Cypher fallback for project context when caller does NOT
 *                set explicit context (relies on the spot's existing
 *                Dataset->HAS_SPOT and Project->HAS_DATASET) →
 *                auto-seed of project collaborators on first creation →
 *                _remove_spot: rich → sample dropped; legacy → sample dropped.
 *
 *              Hermetic: seeds Neo4j Project+Dataset+Spots and a public
 *              collaborators row, then tears them down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_field_integration.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/lib/sample_sync.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 3", array()
);
if (!is_array($users) || count($users) < 3) { echo "Need 3 users\n"; exit(1); }
$ownerPkey        = (int)$users[0]->pkey;
$collaboratorPkey = (int)$users[1]->pkey;
$readonlyPkey     = (int)$users[2]->pkey;
$uuid_gen = new UUID();

$stamp           = time();
$projectStraboId = (string)($stamp * 100 + 11);  // numeric (Field id convention)
$datasetStraboId = (string)($stamp * 100 + 22);
// Distinct spot/sample ids — rich, legacy holder, legacy promoted-then-stub
$spotRichId        = (int)($stamp * 100 + 31);   // rich sample-spot
$spotLegacyHolder  = (int)($stamp * 100 + 32);   // parent spot, samples[] has one real legacy + one stub
$legacySampleId    = $stamp * 100 + 33;          // the legacy inline sample id
// The stub entry inside spotLegacyHolder->samples[] points back at spotRichId.

// Seed Neo4j: Project, Dataset, three Spots; relationships HAS_DATASET, HAS_SPOT.
$neodb->query("CREATE (p:Project {id:$projectStraboId, userpkey:$ownerPkey, name:'smoke field $stamp'})");
$neodb->query("CREATE (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey, name:'ds $stamp'})");
$neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}),
                     (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey})
               CREATE (p)-[:HAS_DATASET]->(d)");

// Rich sample-spot: isSample=1, json_samples = [{...authoritative}]. We write
// the json_samples as a string (matches db/strabospotclass.php:705 behavior).
$richSampleObj = array(
    'id'                    => (string)$spotRichId,
    'sample_id_name'        => 'Rich-A',
    'Sample_IGSN'           => 'IGSN-RA',
    'sample_description'    => 'rich sample description',
    'sample_notes'          => 'rich sample notes',
    'material_type'         => 'igneous',
    'main_sampling_purpose' => 'petrology',
);
$richJsonSamples = json_encode(array($richSampleObj));
// Spots store geometry as a WKT POINT string in `wkt` — there's no
// `geometry` JSON object. Per samples_field_extract_geom_from_spot()
// the helper reads wkt and applies a geographic range guard.
$richWkt = 'POINT (-120.5 45.5)';
$neodb->query("CREATE (s:Spot {
    id: $spotRichId,
    userpkey: $ownerPkey,
    isSample: 1,
    json_samples: '" . addslashes($richJsonSamples) . "',
    wkt: '" . addslashes($richWkt) . "'
})");
$neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                     (s:Spot {id:$spotRichId, userpkey:$ownerPkey})
               CREATE (d)-[:HAS_SPOT]->(s)");

// Pre-seed a MIXED project roster (edit + readonly) so first-create on the
// sample triggers auto-seed and proves per-collaborator level mirroring —
// the readonly member must NOT downgrade the editor's grant.
$collabUuid = $uuid_gen->v4();
$db->prepare_query(
    "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
                                collaboration_level, accepted, uuid, disabled)
     VALUES ($1, $2, $3, 'edit', TRUE, $4, FALSE)",
    array($projectStraboId, $ownerPkey, $collaboratorPkey, $collabUuid)
);
$db->prepare_query(
    "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
                                collaboration_level, accepted, uuid, disabled)
     VALUES ($1, $2, $3, 'readonly', TRUE, $4, FALSE)",
    array($projectStraboId, $ownerPkey, $readonlyPkey, $uuid_gen->v4())
);

echo "owner=$ownerPkey  collab=$collaboratorPkey  project=$projectStraboId  dataset=$datasetStraboId\n";
echo "rich spot=$spotRichId  legacy holder spot=$spotLegacyHolder  legacy sample id=$legacySampleId\n\n";

// strabo_id strings used throughout (Field ids are numeric, stored as text).
$richSampleId   = (string)$spotRichId;
$legacySampleStr = (string)$legacySampleId;

try {
    // -------------------------------------------------------------------
    // PART 1: rich sample-spot upload with EXPLICIT context.
    // -------------------------------------------------------------------
    echo "=== Part 1: rich sample-spot upload (explicit project/dataset context) ===\n";
    $richProps = (object)array(
        'id'        => $spotRichId,
        'samples'   => array((object)$richSampleObj),
        'isSample'  => 1,
        'wkt'       => 'POINT (-120.5 45.5)',
    );
    $mirrored = field_sample_sync_spot(
        $db, $neodb, $richProps, $spotRichId, $ownerPkey,
        $projectStraboId, $datasetStraboId
    );
    check("mirror returned one strabo_id",                     count($mirrored) === 1);
    check("mirrored id == rich spot id",                       isset($mirrored[0]) && $mirrored[0] === $richSampleId);

    $row = $db->get_row_prepared(
        "SELECT name, igsn, description, notes, display_sample_type, display_sample_purpose,
                latitude, longitude, field_data::text AS fd
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($richSampleId, $ownerPkey)
    );
    check("strabosamples row created for rich sample",         $row !== null);
    check("name='Rich-A' (from sample_id_name)",                $row && $row->name === 'Rich-A');
    check("igsn='IGSN-RA' (from Sample_IGSN)",                  $row && $row->igsn === 'IGSN-RA');
    check("description matches sample_description",            $row && $row->description === 'rich sample description');
    check("notes matches sample_notes",                        $row && $row->notes === 'rich sample notes');
    check("display_sample_type='igneous'",                     $row && $row->display_sample_type === 'igneous');
    check("display_sample_purpose='petrology'",                $row && $row->display_sample_purpose === 'petrology');
    check("latitude pulled from wkt Point",                    $row && abs((float)$row->latitude - 45.5) < 0.0001);
    check("longitude pulled from wkt Point",                   $row && abs((float)$row->longitude - (-120.5)) < 0.0001);
    $fd = $row && $row->fd ? json_decode($row->fd, true) : null;
    check("field_data preserves full sample sub-object",        is_array($fd) && isset($fd['sample_id_name']) && $fd['sample_id_name'] === 'Rich-A');

    $link = $db->get_row_prepared(
        "SELECT reference_id, reference_userpkey, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
        array($richSampleId, $ownerPkey)
    );
    check("link row exists for rich sample",                   $link !== null);
    check("reference_id == rich spot id",                      $link && $link->reference_id === (string)$spotRichId);
    $rm = $link && $link->rm ? json_decode($link->rm, true) : null;
    check("reference_metadata.rich = true",                    is_array($rm) && isset($rm['rich']) && $rm['rich'] === true);
    check("reference_metadata.dataset_id = explicit context",  is_array($rm) && isset($rm['dataset_id']) && (string)$rm['dataset_id'] === $datasetStraboId);
    check("reference_metadata.project_id = explicit context",  is_array($rm) && isset($rm['project_id']) && (string)$rm['project_id'] === $projectStraboId);

    $seeded = $db->get_row_prepared(
        "SELECT permission_level, accepted FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3",
        array($richSampleId, $ownerPkey, $collaboratorPkey)
    );
    check("auto-seed: project collaborator landed on rich",    $seeded !== null);
    check("auto-seed mirrors editor's own level ('edit' despite readonly co-member)",
          $seeded && $seeded->permission_level === 'edit');
    check("auto-seed accepted=true (pre-accepted)",            $seeded && in_array((string)$seeded->accepted, array('t','true','1'), true));
    $seededRo = $db->get_row_prepared(
        "SELECT permission_level, accepted FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3",
        array($richSampleId, $ownerPkey, $readonlyPkey)
    );
    check("auto-seed: readonly member landed on rich",         $seededRo !== null);
    check("auto-seed mirrors readonly member's own level",     $seededRo && $seededRo->permission_level === 'readonly');

    $createCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type='create'",
        array($richSampleId, $ownerPkey)
    );
    check("changelog: exactly one 'create'",                   $createCount === 1);

    // -------------------------------------------------------------------
    // PART 2: idempotent re-upload of the same rich sample-spot.
    // -------------------------------------------------------------------
    echo "\n=== Part 2: idempotent re-call (no row growth, no extra 'create') ===\n";
    field_sample_sync_spot(
        $db, $neodb, $richProps, $spotRichId, $ownerPkey,
        $projectStraboId, $datasetStraboId
    );
    $samCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($richSampleId, $ownerPkey)
    );
    check("still exactly 1 strabosamples row after re-sync",   $samCount === 1);
    $linkCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2",
        array($richSampleId, $ownerPkey)
    );
    check("still exactly 1 link row after re-sync",            $linkCount === 1);
    $createCount2 = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type='create'",
        array($richSampleId, $ownerPkey)
    );
    check("changelog: still exactly one 'create' after re-sync", $createCount2 === 1);
    $collabCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3",
        array($richSampleId, $ownerPkey, $collaboratorPkey)
    );
    check("collab grant still 1 (no re-seed on re-call)",      $collabCount === 1);

    // -------------------------------------------------------------------
    // PART 3: parent spot with legacy + stub. Expect stub skipped,
    // legacy mirrored.
    // -------------------------------------------------------------------
    echo "\n=== Part 3: parent spot with legacy inline + stub-back-to-rich ===\n";
    $legacySampleObj = array(
        'id'                    => $legacySampleStr,
        'sample_id_name'        => 'Legacy-One',
        'sample_description'    => 'legacy inline',
        'material_type'         => 'other',
        'other_material_type'   => 'shale (other)',
    );
    $stubSampleObj = array('id' => $richSampleId);  // points at rich → should be skipped
    $legacyHolderWkt = 'POINT (-100.0 40.0)';
    $legacyJsonSamples = json_encode(array($legacySampleObj, $stubSampleObj));
    $neodb->query("CREATE (s:Spot {
        id: $spotLegacyHolder,
        userpkey: $ownerPkey,
        json_samples: '" . addslashes($legacyJsonSamples) . "',
        wkt: '" . addslashes($legacyHolderWkt) . "'
    })");
    $neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                         (s:Spot {id:$spotLegacyHolder, userpkey:$ownerPkey})
                   CREATE (d)-[:HAS_SPOT]->(s)");

    $legacyProps = (object)array(
        'id'      => $spotLegacyHolder,
        'samples' => array((object)$legacySampleObj, (object)$stubSampleObj),
        'wkt'     => 'POINT (-100.0 40.0)',
    );
    $mirrored = field_sample_sync_spot(
        $db, $neodb, $legacyProps, $spotLegacyHolder, $ownerPkey,
        $projectStraboId, $datasetStraboId
    );
    check("mirror returned exactly one id (stub skipped)",     count($mirrored) === 1);
    check("mirrored id is the legacy sample, not stub",        isset($mirrored[0]) && $mirrored[0] === $legacySampleStr);

    // Confirm stub did NOT create a duplicate row for rich sample, and the
    // rich link's reference_id is unchanged (= rich spot id, not legacy holder).
    $stubLink = $db->get_row_prepared(
        "SELECT reference_id FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
        array($richSampleId, $ownerPkey)
    );
    check("rich link still points at rich spot, not stubbed",  $stubLink && $stubLink->reference_id === (string)$spotRichId);

    $legRow = $db->get_row_prepared(
        "SELECT display_sample_type, latitude, field_data::text AS fd
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($legacySampleStr, $ownerPkey)
    );
    check("legacy sample row created",                         $legRow !== null);
    check("legacy display_sample_type = 'shale (other)'",      $legRow && $legRow->display_sample_type === 'shale (other)');
    check("legacy latitude pulled from holder wkt",            $legRow && abs((float)$legRow->latitude - 40.0) < 0.0001);
    $legFd = $legRow && $legRow->fd ? json_decode($legRow->fd, true) : null;
    check("legacy field_data preserved",                       is_array($legFd) && isset($legFd['sample_id_name']) && $legFd['sample_id_name'] === 'Legacy-One');

    $legLink = $db->get_row_prepared(
        "SELECT reference_id, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
        array($legacySampleStr, $ownerPkey)
    );
    check("legacy link reference_id = holder spot id",         $legLink && $legLink->reference_id === (string)$spotLegacyHolder);
    $legRm = $legLink && $legLink->rm ? json_decode($legLink->rm, true) : null;
    check("legacy reference_metadata.rich = false",            is_array($legRm) && isset($legRm['rich']) && $legRm['rich'] === false);

    // -------------------------------------------------------------------
    // PART 4: Cypher context fallback (caller omits explicit context).
    // -------------------------------------------------------------------
    echo "\n=== Part 4: Cypher fallback for project/dataset context ===\n";
    // Re-run rich upload with no explicit context; relies on existing
    // Dataset->HAS_SPOT and Project->HAS_DATASET to be present.
    field_sample_sync_spot(
        $db, $neodb, $richProps, $spotRichId, $ownerPkey,
        null, null
    );
    $linkAfter = $db->get_row_prepared(
        "SELECT reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
        array($richSampleId, $ownerPkey)
    );
    $rmAfter = $linkAfter && $linkAfter->rm ? json_decode($linkAfter->rm, true) : null;
    check("Cypher fallback found dataset_id",                  is_array($rmAfter) && isset($rmAfter['dataset_id']) && (string)$rmAfter['dataset_id'] === $datasetStraboId);
    check("Cypher fallback found project_id",                  is_array($rmAfter) && isset($rmAfter['project_id']) && (string)$rmAfter['project_id'] === $projectStraboId);

    // -------------------------------------------------------------------
    // PART 4.5: wkt extractor — Point + range guard.
    //
    // Direct unit-style coverage of the canonical helper in
    // samplesdb/lib/field_geom.php, which feeds both the live sync
    // (db/lib/sample_sync.php) and the cutover migration
    // (samplesdb/migration/extract_field.php). Plus one full e2e sync
    // through a pixel-range holding spot to confirm the strabosamples
    // row gets NULL lat/lng end-to-end.
    // -------------------------------------------------------------------
    echo "\n=== Part 4.5: wkt extractor (Point + range guard) ===\n";
    require_once '/srv/app/www/samplesdb/lib/field_geom.php';

    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POINT (-122.5 47.5)'));
    check("real-world Point: lat populated",                   abs($lat - 47.5) < 0.0001);
    check("real-world Point: lng populated",                   abs($lng - (-122.5)) < 0.0001);

    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POINT (-180 -90)'));
    check("edge of range (-180, -90): accepted",               $lat === -90.0 && $lng === -180.0);
    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POINT (180 90)'));
    check("edge of range (180, 90): accepted",                 $lat === 90.0  && $lng === 180.0);

    // Pixel-range cases — both with and without a hypothetical image_basemap
    // tag, since the audit found ~169 dev spots with pixel coords that have
    // NO image_basemap set, so the tag isn't a reliable signal.
    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POINT (1454.7 656.1)'));
    check("pixel-range (1454, 656): rejected → null lat",       $lat === null);
    check("pixel-range (1454, 656): rejected → null lng",       $lng === null);

    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POINT (20.69 354.68)'));
    check("lat past 90 (354.68): rejected → null",              $lat === null && $lng === null);
    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POINT (-181 0)'));
    check("lng past -180: rejected → null",                     $lat === null && $lng === null);
    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POINT (0 -90.001)'));
    check("lat past -90: rejected → null",                      $lat === null && $lng === null);

    // Non-Point geometry — LineString and Polygon both stay null.
    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'LINESTRING (-100 40, -101 41)'));
    check("LINESTRING wkt: null lat/lng",                       $lat === null && $lng === null);
    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POLYGON ((0 0, 1 0, 1 1, 0 1, 0 0))'));
    check("POLYGON wkt: null lat/lng",                          $lat === null && $lng === null);

    // Degenerate inputs: empty / missing / wrong type.
    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => ''));
    check("empty wkt string: null",                             $lat === null && $lng === null);
    list($lat, $lng) = samples_field_extract_geom_from_spot(array());
    check("missing wkt key: null",                              $lat === null && $lng === null);
    list($lat, $lng) = samples_field_extract_geom_from_spot(array('wkt' => 'POINT (not a number 0)'));
    check("unparsable wkt: null",                               $lat === null && $lng === null);

    // Object-form spot props (the live sync passes the JSON-decoded
    // properties as either array or stdClass depending on caller).
    $objProps = (object)array('wkt' => 'POINT (5.5 -10.25)');
    list($lat, $lng) = samples_field_extract_geom_from_spot($objProps);
    check("object-form spot props: works",                      abs($lat - (-10.25)) < 0.0001 && abs($lng - 5.5) < 0.0001);

    // e2e: a holding spot with pixel-range wkt → strabosamples lat/lng stay NULL.
    $spotPixelHolder = (int)($stamp * 100 + 33);   // distinct from rich (31) / legacy holder (32)
    $pixelSampleId   = (int)($stamp * 100 + 34);
    $pixelSampleStr  = (string)$pixelSampleId;
    $pixelSampleObj  = array(
        'sample_id_name' => 'Pixel-One',
        'id'             => $pixelSampleId,
        'material_type'  => 'sediment',
    );
    $pixelJsonSamples = json_encode(array($pixelSampleObj));
    $pixelWkt = 'POINT (1500.123 800.456)';  // pixel coords — should be rejected
    $neodb->query("CREATE (s:Spot {
        id: $spotPixelHolder,
        userpkey: $ownerPkey,
        json_samples: '" . addslashes($pixelJsonSamples) . "',
        wkt: '" . addslashes($pixelWkt) . "'
    })");
    $neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                         (s:Spot {id:$spotPixelHolder, userpkey:$ownerPkey})
                   CREATE (d)-[:HAS_SPOT]->(s)");

    $pixelProps = (object)array(
        'id'      => $spotPixelHolder,
        'samples' => array((object)$pixelSampleObj),
        'wkt'     => 'POINT (1500.123 800.456)',
    );
    field_sample_sync_spot(
        $db, $neodb, $pixelProps, $spotPixelHolder, $ownerPkey,
        $projectStraboId, $datasetStraboId
    );
    $pixelRow = $db->get_row_prepared(
        "SELECT latitude, longitude FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($pixelSampleStr, $ownerPkey)
    );
    check("e2e pixel-range sync: row created",                  $pixelRow !== null);
    check("e2e pixel-range sync: latitude NULL",                $pixelRow && $pixelRow->latitude  === null);
    check("e2e pixel-range sync: longitude NULL",               $pixelRow && $pixelRow->longitude === null);

    // -------------------------------------------------------------------
    // PART 5: removal — _remove_spot mirrors deleteSingleSpot's intent.
    // -------------------------------------------------------------------
    echo "\n=== Part 5: remove paths ===\n";
    // Remove legacy holder first (before its Neo4j spot disappears, since
    // the helper reads json_samples from Neo4j).
    $n = field_sample_sync_remove_spot($db, $neodb, $spotLegacyHolder, $ownerPkey);
    check("remove helper processed >=1 legacy sample",         $n >= 1);
    $legGone = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($legacySampleStr, $ownerPkey)
    );
    check("legacy sample dropped from strabosamples",          $legGone === 0);

    // Now remove rich.
    $n = field_sample_sync_remove_spot($db, $neodb, $spotRichId, $ownerPkey);
    check("remove helper processed the rich sample",           $n >= 1);
    $richGone = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($richSampleId, $ownerPkey)
    );
    check("rich sample dropped from strabosamples",            $richGone === 0);

} finally {
    // -------------------------------------------------------------------
    // Cleanup. Order: strabosamples → Neo4j → collaborators.
    // -------------------------------------------------------------------
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id IN ($1, $2, $3) AND userpkey=$4",
        array($richSampleId, $legacySampleStr, isset($pixelSampleStr) ? $pixelSampleStr : '', $ownerPkey)
    );
    $neodb->query("MATCH (s:Spot {id:$spotRichId, userpkey:$ownerPkey}) DETACH DELETE s");
    $neodb->query("MATCH (s:Spot {id:$spotLegacyHolder, userpkey:$ownerPkey}) DETACH DELETE s");
    if (isset($spotPixelHolder)) {
        $neodb->query("MATCH (s:Spot {id:$spotPixelHolder, userpkey:$ownerPkey}) DETACH DELETE s");
    }
    $neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}) DETACH DELETE d");
    $neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}) DETACH DELETE p");
    $db->prepare_query(
        "DELETE FROM collaborators WHERE strabo_project_id=$1 AND project_owner_user_pkey=$2",
        array($projectStraboId, $ownerPkey)
    );
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
