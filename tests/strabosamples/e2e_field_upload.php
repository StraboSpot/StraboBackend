<?php
/**
 * File: e2e_field_upload.php
 * Description: Curl-level END-TO-END coverage of the StraboField upload
 *              pathways, asserting the strabosamples.* mirror is correctly
 *              populated *through the live HTTP endpoints* (not the library
 *              layer). This closes the Phase I gap called out in
 *              docs/StraboSamples/phase_i_nonapi_coverage.md: the existing
 *              field_integration smoke proves field_sample_sync_spot() works
 *              when called directly, but nothing proved the actual REST
 *              controllers wire setSampleSyncContext() → insertSpot() → the
 *              sync hook end-to-end over HTTP.
 *
 *              Field is where the vast majority of sample-bearing spots enter
 *              the system, so every spot-upload controller in /db is exercised
 *              against the running Apache with real HTTP Basic Auth:
 *
 *                A.  POST /db/datasetspots/{id}        DatasetSpotsController
 *                                                      (the primary bulk path)
 *                A2. (removal sync via bulk re-upload that drops a spot)
 *                B.  POST /db/datasetsinglespot/{id}   DatasetSingleSpotController
 *                C.  POST /db/updatedatasetspots/{id}  UpdateDatasetSpotsController
 *                D.  POST /db/projectdatasetsspots     ProjectDatasetsSpotsController
 *                E.  collaborator upload → mirror owned by effectiveOwner
 *                F.  auth gates (readonly 403 / outsider 4xx) write nothing
 *
 *              Each upload is asserted at the database layer: Neo4j Spot
 *              created + HAS_SPOT-linked, and the strabosamples spine row +
 *              subsystem_link + changelog + auto-seeded collaborators all
 *              correct — most importantly reference_metadata.project_id /
 *              dataset_id, which proves the HTTP controller propagated sample
 *              sync context (the exact bug class Phase I fixed for Load
 *              Shapefile).
 *
 *              Hermetic: all fixture ids live under a '96666' numeric prefix,
 *              disjoint from every other suite (chaos 95555, repro 94444,
 *              setup_test_data 9999999900). Cleans up before and after.
 *              Uses the four test users from setup_test_data.php
 *              (password testpass123); exits non-zero on any failure.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_field_upload.php
 *
 * @package    StraboField / StraboSamples
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
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

// -------------------------------------------------------------------------
// Users (provisioned by tests/collaboration/setup_test_data.php)
// -------------------------------------------------------------------------

$password = 'testpass123';
$users = array();
foreach (array('owner', 'editor', 'readonly', 'outsider') as $who) {
    $email = "$who@test.strabospot.org";
    $pkey  = (int)$db->get_var_prepared(
        "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
    if (!$pkey) {
        echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n";
        exit(1);
    }
    $users[$who] = array('email' => $email, 'pkey' => $pkey, 'up' => "$email:$password");
}
$ownerPkey    = $users['owner']['pkey'];
$editorPkey   = $users['editor']['pkey'];
$readonlyPkey = $users['readonly']['pkey'];
$testPkeys    = array($ownerPkey, $editorPkey, $readonlyPkey, $users['outsider']['pkey']);
echo "owner=$ownerPkey editor=$editorPkey readonly=$readonlyPkey outsider={$users['outsider']['pkey']}\n";

// -------------------------------------------------------------------------
// Fixture ids — '96666' numeric prefix, all within [NEO_MIN, NEO_MAX]
// -------------------------------------------------------------------------

$stamp   = time();
$base    = 96666 * 10000000 + ($stamp % 10000000);   // 966660000000 .. 966669999999
$NEO_MIN = 966660000000;
$NEO_MAX = 966669999999;

$projectId       = $base + 1;
$datasetA        = $base + 2;   // bulk datasetspots + removal
$datasetB        = $base + 3;   // single spot
$datasetC        = $base + 4;   // updatedatasetspots
$datasetE        = $base + 5;   // created_by editor (collaborator upload)
$datasetD        = $base + 6;   // created by the projectdatasetsspots endpoint (NOT pre-seeded)

// Spots (rich sample id == spot id; legacy sample ids in a separate band)
$spotA_rich      = $base + 10;
$spotA_legacy    = $base + 11;
$spotA_plain     = $base + 12;
$legacyA_sample  = $base + 60;
$spotB_rich      = $base + 20;
$spotC_rich      = $base + 30;
$spotD_rich      = $base + 40;
$spotE_rich      = $base + 50;

echo "project=$projectId datasetA=$datasetA datasetB=$datasetB datasetC=$datasetC datasetD=$datasetD datasetE=$datasetE\n\n";

// -------------------------------------------------------------------------
// HTTP helper — curl the live Apache with HTTP Basic Auth
// -------------------------------------------------------------------------

function http($method, $path, $jsonBody, $userPass) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2e_body_');
    $cmd  = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    $cmd .= "-u " . escapeshellarg($userPass) . " ";
    $cmd .= "-X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) {
        $cmd .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
        $cmd .= "-d " . escapeshellarg($jsonBody) . " ";
    }
    $cmd .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec($cmd);
    $body   = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

// -------------------------------------------------------------------------
// Payload builders
// -------------------------------------------------------------------------

// A rich sample-spot Feature: isSample=1, authoritative sample object at
// samples[0] with id == spot id, real-world Point geometry.
function richFeature($spotId, $name, $igsn, $ts, $lng = -118.50, $lat = 34.20) {
    return array(
        'type'       => 'Feature',
        'geometry'   => array('type' => 'Point', 'coordinates' => array($lng, $lat)),
        'properties' => array(
            'id'                 => $spotId,
            'name'               => "Spot $spotId",
            'modified_timestamp' => $ts,
            'isSample'           => 1,
            'samples'            => array(array(
                'id'                    => $spotId,
                'sample_id_name'        => $name,
                'Sample_IGSN'           => $igsn,
                'sample_description'    => 'e2e rich description',
                'sample_notes'          => 'e2e rich notes',
                'material_type'         => 'igneous',
                'main_sampling_purpose' => 'petrology',
            )),
        ),
    );
}

// A legacy sample-spot: a normal spot with a full sample object inline in
// samples[] (no isSample flag). material_type='other' → other_material_type.
function legacyFeature($spotId, $sampleId, $name, $ts, $lng = -100.00, $lat = 40.00) {
    return array(
        'type'       => 'Feature',
        'geometry'   => array('type' => 'Point', 'coordinates' => array($lng, $lat)),
        'properties' => array(
            'id'                 => $spotId,
            'name'               => "Spot $spotId",
            'modified_timestamp' => $ts,
            'samples'            => array(array(
                'id'                  => $sampleId,
                'sample_id_name'      => $name,
                'sample_description'  => 'e2e legacy inline',
                'material_type'       => 'other',
                'other_material_type' => 'shale (other)',
            )),
        ),
    );
}

// A plain spot with no samples (negative control — must NOT mirror).
function plainFeature($spotId, $ts) {
    return array(
        'type'       => 'Feature',
        'geometry'   => array('type' => 'Point', 'coordinates' => array(-95.0, 39.0)),
        'properties' => array(
            'id'                 => $spotId,
            'name'               => "Spot $spotId",
            'modified_timestamp' => $ts,
        ),
    );
}

// Bulk body for DatasetSpotsController / UpdateDatasetSpotsController:
// {"id":"", "features":[...]}  (empty id forces the feature-collection branch)
function bulkBody($features) {
    return json_encode(array('id' => '', 'features' => $features));
}

// -------------------------------------------------------------------------
// DB assertion helpers
// -------------------------------------------------------------------------

function neoCount($neodb, $label, $id) {
    return (int)$neodb->get_var("MATCH (n:$label {id:$id}) RETURN count(n) AS c");
}
function spotLinkedToDataset($neodb, $datasetId, $spotId) {
    return (int)$neodb->get_var(
        "MATCH (d:Dataset {id:$datasetId})-[:HAS_SPOT]->(s:Spot {id:$spotId}) RETURN count(*) AS c") > 0;
}
function sampleRow($db, $sampleId, $userpkey) {
    return $db->get_row_prepared(
        "SELECT name, igsn, description, notes, display_sample_type, display_sample_purpose,
                latitude, longitude
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$sampleId, (int)$userpkey));
}
function linkRow($db, $sampleId, $userpkey) {
    $r = $db->get_row_prepared(
        "SELECT reference_id, reference_userpkey, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
        array((string)$sampleId, (int)$userpkey));
    if ($r) $r->meta = json_decode($r->rm, true);
    return $r;
}
function changelogCreateCount($db, $sampleId, $userpkey) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type='create'",
        array((string)$sampleId, (int)$userpkey));
}
function collabGrant($db, $sampleId, $userpkey, $collabPkey) {
    return $db->get_row_prepared(
        "SELECT permission_level FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3",
        array((string)$sampleId, (int)$userpkey, (int)$collabPkey));
}

// -------------------------------------------------------------------------
// Cleanup
// -------------------------------------------------------------------------

function e2eCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $testPkeys) {
    $neodb->query("MATCH (s:Spot)    WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_MIN AND d.id <= $NEO_MAX DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id >= $NEO_MIN AND p.id <= $NEO_MAX DETACH DELETE p");
    $pkeyList = implode(',', array_map('intval', $testPkeys));
    $db->query("DELETE FROM strabosamples.sample_changelog     WHERE sample_id LIKE '96666%' AND sample_userpkey IN ($pkeyList)");
    $db->query("DELETE FROM strabosamples.sample_collaborators  WHERE sample_id LIKE '96666%' AND sample_userpkey IN ($pkeyList)");
    $db->query("DELETE FROM strabosamples.sample_subsystem_links WHERE sample_id LIKE '96666%' AND sample_userpkey IN ($pkeyList)");
    $db->query("DELETE FROM strabosamples.samples               WHERE id LIKE '96666%' AND userpkey IN ($pkeyList)");
    $db->query("DELETE FROM collaborators WHERE strabo_project_id LIKE '96666%'");
    $db->query("DELETE FROM vprojects     WHERE projectid LIKE '96666%'");
}

e2eCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $testPkeys);

try {

    // ---------------------------------------------------------------------
    // Seed: owner's Project + four pre-existing Datasets (HAS_DATASET).
    // datasetE is created_by editor (for the collaborator-upload test).
    // Register editor(edit) + readonly(readonly) collaborators.
    // ---------------------------------------------------------------------
    $neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, desc_project_name:'E2E Field Upload', created_by:$ownerPkey})");
    foreach (array($datasetA => $ownerPkey, $datasetB => $ownerPkey, $datasetC => $ownerPkey, $datasetE => $editorPkey) as $dsId => $createdBy) {
        $neodb->query("MATCH (p:Project {id:$projectId, userpkey:$ownerPkey})
                       CREATE (d:Dataset {id:$dsId, userpkey:$ownerPkey, name:'ds $dsId', created_by:$createdBy})
                       CREATE (p)-[:HAS_DATASET]->(d)");
    }
    foreach (array('editor' => 'edit', 'readonly' => 'readonly') as $who => $level) {
        $db->prepare_query(
            "INSERT INTO collaborators
                (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
                 collaboration_level, accepted, uuid, disabled)
             VALUES ($1, $2, $3, $4, TRUE, $5, FALSE)",
            array((string)$projectId, $ownerPkey, $users[$who]['pkey'], $level, bin2hex(random_bytes(16))));
    }

    // =====================================================================
    // A — POST /db/datasetspots/{id} (the primary bulk path)
    // =====================================================================
    echo "=== A: POST /db/datasetspots/{id} — bulk upload (rich + legacy + plain) ===\n";
    $ts = (int)(microtime(true) * 1000);
    $body = bulkBody(array(
        richFeature($spotA_rich, 'A-Rich', 'IGSN-A', $ts),
        legacyFeature($spotA_legacy, $legacyA_sample, 'A-Legacy', $ts),
        plainFeature($spotA_plain, $ts),
    ));
    $r = http('POST', "/db/datasetspots/$datasetA", $body, $users['owner']['up']);
    note("HTTP {$r['status']}");
    check("A: upload returns 2xx", $r['status'] >= 200 && $r['status'] < 300);

    check("A: rich Spot node exists",        neoCount($neodb, 'Spot', $spotA_rich) === 1);
    check("A: rich Spot HAS_SPOT-linked",    spotLinkedToDataset($neodb, $datasetA, $spotA_rich));
    check("A: legacy Spot node exists",      neoCount($neodb, 'Spot', $spotA_legacy) === 1);
    check("A: plain Spot node exists",       neoCount($neodb, 'Spot', $spotA_plain) === 1);

    $row = sampleRow($db, $spotA_rich, $ownerPkey);
    check("A: rich sample mirrored",                     $row !== null);
    check("A: rich name='A-Rich'",                       $row && $row->name === 'A-Rich');
    check("A: rich igsn='IGSN-A'",                       $row && $row->igsn === 'IGSN-A');
    check("A: rich display_sample_type='igneous'",       $row && $row->display_sample_type === 'igneous');
    check("A: rich display_sample_purpose='petrology'",  $row && $row->display_sample_purpose === 'petrology');
    check("A: rich latitude from Point (34.20)",         $row && abs((float)$row->latitude  - 34.20) < 0.0001);
    check("A: rich longitude from Point (-118.50)",      $row && abs((float)$row->longitude - (-118.50)) < 0.0001);

    $link = linkRow($db, $spotA_rich, $ownerPkey);
    check("A: rich link exists",                         $link !== null);
    check("A: rich link reference_id == spot id",        $link && $link->reference_id === (string)$spotA_rich);
    check("A: HTTP path propagated project_id context",  $link && isset($link->meta['project_id']) && $link->meta['project_id'] === (string)$projectId);
    check("A: HTTP path propagated dataset_id context",  $link && isset($link->meta['dataset_id']) && $link->meta['dataset_id'] === (string)$datasetA);
    check("A: rich link rich=true",                      $link && isset($link->meta['rich']) && $link->meta['rich'] === true);

    check("A: rich changelog has exactly one 'create'",  changelogCreateCount($db, $spotA_rich, $ownerPkey) === 1);
    $gEd = collabGrant($db, $spotA_rich, $ownerPkey, $editorPkey);
    $gRo = collabGrant($db, $spotA_rich, $ownerPkey, $readonlyPkey);
    check("A: auto-seed landed editor on rich sample",   $gEd !== null);
    check("A: auto-seed landed readonly on rich sample", $gRo !== null);
    check("A: editor grant mirrors own level 'edit' (not batch-downgraded by readonly co-member)",
          $gEd && $gEd->permission_level === 'edit');
    check("A: readonly grant mirrors own level 'readonly'",
          $gRo && $gRo->permission_level === 'readonly');

    $legRow  = sampleRow($db, $legacyA_sample, $ownerPkey);
    $legLink = linkRow($db, $legacyA_sample, $ownerPkey);
    check("A: legacy sample mirrored",                       $legRow !== null);
    check("A: legacy display_sample_type='shale (other)'",   $legRow && $legRow->display_sample_type === 'shale (other)');
    check("A: legacy latitude from holder Point (40.00)",    $legRow && abs((float)$legRow->latitude - 40.00) < 0.0001);
    check("A: legacy link reference_id == holder spot id",   $legLink && $legLink->reference_id === (string)$spotA_legacy);
    check("A: legacy link rich=false",                       $legLink && isset($legLink->meta['rich']) && $legLink->meta['rich'] === false);

    check("A: plain spot produced NO sample row",        sampleRow($db, $spotA_plain, $ownerPkey) === null);

    // =====================================================================
    // A2 — removal sync: re-upload datasetA WITHOUT the rich spot.
    // DatasetSpotsController deletes server spots not in the incoming set,
    // which fires field_sample_sync_remove_spot → rich sample dropped.
    // =====================================================================
    echo "\n=== A2: bulk re-upload dropping the rich spot → removal sync ===\n";
    $ts2 = $ts + 100000;   // newer timestamp so the update gate stays open
    $body = bulkBody(array(
        legacyFeature($spotA_legacy, $legacyA_sample, 'A-Legacy', $ts2),
        plainFeature($spotA_plain, $ts2),
    ));
    $r = http('POST', "/db/datasetspots/$datasetA", $body, $users['owner']['up']);
    note("HTTP {$r['status']}");
    check("A2: re-upload returns 2xx",                   $r['status'] >= 200 && $r['status'] < 300);
    check("A2: rich Spot node removed from Neo4j",       neoCount($neodb, 'Spot', $spotA_rich) === 0);
    check("A2: rich sample dropped from strabosamples",  sampleRow($db, $spotA_rich, $ownerPkey) === null);
    check("A2: legacy sample survives re-upload",        sampleRow($db, $legacyA_sample, $ownerPkey) !== null);

    // =====================================================================
    // B — POST /db/datasetsinglespot/{id}
    // =====================================================================
    echo "\n=== B: POST /db/datasetsinglespot/{id} — single sample-spot ===\n";
    $ts = (int)(microtime(true) * 1000);
    // Single-spot controller reads $upload['properties']->id → send a bare Feature.
    $body = json_encode(richFeature($spotB_rich, 'B-Rich', 'IGSN-B', $ts));
    $r = http('POST', "/db/datasetsinglespot/$datasetB", $body, $users['owner']['up']);
    note("HTTP {$r['status']}");
    check("B: upload returns 2xx",                       $r['status'] >= 200 && $r['status'] < 300);
    check("B: Spot node exists + linked",                neoCount($neodb, 'Spot', $spotB_rich) === 1 && spotLinkedToDataset($neodb, $datasetB, $spotB_rich));
    $row  = sampleRow($db, $spotB_rich, $ownerPkey);
    $link = linkRow($db, $spotB_rich, $ownerPkey);
    check("B: sample mirrored, name='B-Rich'",           $row && $row->name === 'B-Rich');
    check("B: link project_id context propagated",       $link && isset($link->meta['project_id']) && $link->meta['project_id'] === (string)$projectId);
    check("B: link dataset_id context propagated",       $link && isset($link->meta['dataset_id']) && $link->meta['dataset_id'] === (string)$datasetB);

    // =====================================================================
    // C — POST /db/updatedatasetspots/{id}
    // =====================================================================
    echo "\n=== C: POST /db/updatedatasetspots/{id} — bulk update path ===\n";
    $ts = (int)(microtime(true) * 1000);
    $body = bulkBody(array(richFeature($spotC_rich, 'C-Rich', 'IGSN-C', $ts)));
    $r = http('POST', "/db/updatedatasetspots/$datasetC", $body, $users['owner']['up']);
    note("HTTP {$r['status']}");
    check("C: upload returns 2xx",                       $r['status'] >= 200 && $r['status'] < 300);
    check("C: Spot node exists + linked",                neoCount($neodb, 'Spot', $spotC_rich) === 1 && spotLinkedToDataset($neodb, $datasetC, $spotC_rich));
    $row  = sampleRow($db, $spotC_rich, $ownerPkey);
    $link = linkRow($db, $spotC_rich, $ownerPkey);
    check("C: sample mirrored, name='C-Rich'",           $row && $row->name === 'C-Rich');
    check("C: link project_id context propagated",       $link && isset($link->meta['project_id']) && $link->meta['project_id'] === (string)$projectId);
    check("C: link dataset_id context propagated",       $link && isset($link->meta['dataset_id']) && $link->meta['dataset_id'] === (string)$datasetC);

    // =====================================================================
    // D — POST /db/projectdatasetsspots (whole project+dataset+spots; the
    //     dataset is CREATED by this endpoint, not pre-seeded)
    // =====================================================================
    echo "\n=== D: POST /db/projectdatasetsspots — whole-project upload ===\n";
    $ts = (int)(microtime(true) * 1000);
    $body = json_encode(array('project' => array(
        'id'                 => $projectId,
        'description'        => array('project_name' => 'E2E Field Upload'),
        'modified_timestamp' => $ts,
        'datasets'           => array(array(
            'id'                 => $datasetD,
            'name'               => 'ds D',
            'modified_timestamp' => $ts,
            'spots'              => array('features' => array(
                richFeature($spotD_rich, 'D-Rich', 'IGSN-D', $ts),
            )),
        )),
    )));
    $r = http('POST', "/db/projectdatasetsspots", $body, $users['owner']['up']);
    note("HTTP {$r['status']}");
    check("D: upload returns 2xx",                       $r['status'] >= 200 && $r['status'] < 300);
    check("D: dataset D created + spot linked",          neoCount($neodb, 'Dataset', $datasetD) === 1 && spotLinkedToDataset($neodb, $datasetD, $spotD_rich));
    $row  = sampleRow($db, $spotD_rich, $ownerPkey);
    $link = linkRow($db, $spotD_rich, $ownerPkey);
    check("D: sample mirrored, name='D-Rich'",           $row && $row->name === 'D-Rich');
    check("D: link project_id context propagated",       $link && isset($link->meta['project_id']) && $link->meta['project_id'] === (string)$projectId);
    check("D: link dataset_id context propagated",       $link && isset($link->meta['dataset_id']) && $link->meta['dataset_id'] === (string)$datasetD);

    // =====================================================================
    // E — collaborator upload: editor uploads to a dataset they created.
    //     The mirror row must be owned by the project OWNER (effectiveOwner),
    //     NOT the editor — Field samples follow the spot's owner.
    // =====================================================================
    echo "\n=== E: collaborator (editor) upload → mirror owned by effectiveOwner ===\n";
    $ts = (int)(microtime(true) * 1000);
    $body = bulkBody(array(richFeature($spotE_rich, 'E-Rich', 'IGSN-E', $ts)));
    $r = http('POST', "/db/datasetspots/$datasetE", $body, $users['editor']['up']);
    note("HTTP {$r['status']}");
    check("E: collaborator upload returns 2xx",          $r['status'] >= 200 && $r['status'] < 300);
    check("E: sample owned by OWNER (effectiveOwner)",   sampleRow($db, $spotE_rich, $ownerPkey) !== null);
    check("E: sample NOT owned by editor",               sampleRow($db, $spotE_rich, $editorPkey) === null);
    $link = linkRow($db, $spotE_rich, $ownerPkey);
    check("E: link reference_userpkey == owner",         $link && (int)$link->reference_userpkey === $ownerPkey);
    check("E: link project_id context propagated",       $link && isset($link->meta['project_id']) && $link->meta['project_id'] === (string)$projectId);

    // =====================================================================
    // F — auth gates: rejected uploads must write nothing to strabosamples
    // =====================================================================
    echo "\n=== F: auth gates write nothing ===\n";
    $ts = (int)(microtime(true) * 1000);
    $roSpot = $base + 70;
    $r = http('POST', "/db/datasetspots/$datasetA", bulkBody(array(richFeature($roSpot, 'RO', 'IGSN-RO', $ts))), $users['readonly']['up']);
    note("readonly HTTP {$r['status']}");
    check("F: readonly collaborator upload forbidden (403)", $r['status'] === 403);
    check("F: readonly upload created no Spot",              neoCount($neodb, 'Spot', $roSpot) === 0);
    check("F: readonly upload created no sample row",        sampleRow($db, $roSpot, $ownerPkey) === null);

    $outSpot = $base + 71;
    $r = http('POST', "/db/datasetspots/$datasetA", bulkBody(array(richFeature($outSpot, 'OUT', 'IGSN-OUT', $ts))), $users['outsider']['up']);
    note("outsider HTTP {$r['status']}");
    check("F: outsider upload rejected (4xx)",               $r['status'] >= 400 && $r['status'] < 500);
    check("F: outsider upload created no Spot",              neoCount($neodb, 'Spot', $outSpot) === 0);
    check("F: outsider upload created no sample row",        sampleRow($db, $outSpot, $ownerPkey) === null);

} finally {
    e2eCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $testPkeys);
}

echo "\n=================================================\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
}
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
