<?php
/**
 * File: smoke_test_micro_export_readthrough.php
 * Description: Verifies that the Micro download paths overlay
 *              strabosamples.* spine onto the upload-time project.json
 *              at download time, so Samples-app edits surface in
 *              every Micro export.
 *
 *              Strategy:
 *                Part 1: micro_sample_overlay_apply() unit-style — exercises
 *                        the in-memory mutation of a decoded project.json
 *                        structure (with samples that have + don't have a
 *                        strabosamples row, with NULL vs. populated spine
 *                        fields, and with mixed-shape inputs).
 *                Part 2: micro_sample_overlay_write_json() filesystem-style
 *                        — reads a tmp project.json, applies overlay,
 *                        writes to a destination path, verifies the file
 *                        on disk has the overlaid values.
 *                Part 3: getProjectURL() E2E — seeds full micro_*metadata
 *                        + on-disk straboMicroFiles/<pkey>/project.json,
 *                        materializes strabosamples row, applies
 *                        updateSample, calls getProjectURL, opens the
 *                        produced ziptemp ZIP, parses project.json, asserts
 *                        edits visible.
 *                Part 4: getSharedURL() E2E — same as Part 3 but via the
 *                        share URL path (which doesn't carry $this->userpkey).
 *                Part 5: malformed source JSON — confirms the helper falls
 *                        back to verbatim copy instead of breaking the
 *                        download.
 *
 *              Hermetic: seeds PG rows + an on-disk project directory,
 *              tears down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_micro_export_readthrough.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/microdb/lib/sample_overlay.php';
require_once '/srv/app/www/microdb/lib/sample_sync.php';
require_once '/srv/app/www/microdb/strabomicroclass.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

// CLI context — Apache normally sets DOCUMENT_ROOT, but php from the
// command line doesn't. Micro download methods read this directly when
// constructing on-disk paths, so set it before exercising them.
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
}

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

$stamp        = time();
$projectStraboId   = "smoketest-micro-$stamp";
$projectInternalId = null;   // set after insert
$datasetInternalId = null;
$sampleStraboId    = "smoketest-micro-sample-$stamp";
$apiOnlySampleId   = "smoketest-micro-apionly-$stamp";

// On-disk straboMicroFiles dir + project.json — will be set up below.
$docRoot = '/srv/app/www';
$straboFilesDir = null;
$cleanupZiptemps = array();
$cleanupSamplesIds = array($sampleStraboId, $apiOnlySampleId);

try {

    // -------------------------------------------------------------------
    // PART 1: micro_sample_overlay_apply() unit-style.
    // -------------------------------------------------------------------
    echo "=== Part 1: micro_sample_overlay_apply unit-style ===\n";

    // Seed a strabosamples row with edited spine.
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, description, notes, latitude, longitude,
             display_sample_type, display_sample_purpose, created_by, modified_by,
             created_at, modified_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $2, $2, NOW(), NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET
            name=$3, description=$4, notes=$5, latitude=$6, longitude=$7,
            display_sample_type=$8, display_sample_purpose=$9, modified_at=NOW()",
        array(
            $sampleStraboId, $ownerPkey,
            'EditedSampleName',
            'edited via Samples app',
            'edited notes',
            45.5, -120.5,
            'tephra',
            'geochronology'
        )
    );

    $projectJson = (object)array(
        'datasets' => array(
            (object)array(
                'samples' => array(
                    (object)array(
                        'id'                  => $sampleStraboId,
                        'sampleID'            => 'OriginalUploadName',
                        'sampleDescription'   => 'original upload description',
                        'sampleNotes'         => 'original upload notes',
                        'materialType'        => 'intact_rock',
                        'mainSamplingPurpose' => 'petrology',
                        'latitude'            => '40.0',
                        'longitude'           => '-100.0',
                        'micrographs'         => array(),
                    ),
                    // A sample without a strabosamples row — should pass through unchanged.
                    (object)array(
                        'id'                  => "smoketest-micro-untracked-$stamp",
                        'sampleID'            => 'UntrackedSample',
                        'sampleDescription'   => 'untracked desc',
                    ),
                ),
            ),
        ),
    );

    micro_sample_overlay_apply($projectJson, $db, $ownerPkey);

    $s0 = $projectJson->datasets[0]->samples[0];
    check("overlay: sampleID overlaid from spine.name",
          isset($s0->sampleID) && $s0->sampleID === 'EditedSampleName');
    check("overlay: sampleDescription overlaid",
          isset($s0->sampleDescription) && $s0->sampleDescription === 'edited via Samples app');
    check("overlay: sampleNotes overlaid",
          isset($s0->sampleNotes) && $s0->sampleNotes === 'edited notes');
    check("overlay: materialType overlaid from spine.display_sample_type",
          isset($s0->materialType) && $s0->materialType === 'tephra');
    check("overlay: mainSamplingPurpose overlaid from spine.display_sample_purpose",
          isset($s0->mainSamplingPurpose) && $s0->mainSamplingPurpose === 'geochronology');
    check("overlay: latitude overlaid",
          isset($s0->latitude) && (float)$s0->latitude === 45.5);
    check("overlay: longitude overlaid",
          isset($s0->longitude) && (float)$s0->longitude === -120.5);

    $s1 = $projectJson->datasets[0]->samples[1];
    check("overlay: untracked sample (no spine row) UNCHANGED",
          isset($s1->sampleID) && $s1->sampleID === 'UntrackedSample' &&
          isset($s1->sampleDescription) && $s1->sampleDescription === 'untracked desc');

    // -------------------------------------------------------------------
    // PART 2: micro_sample_overlay_write_json() filesystem-style.
    // -------------------------------------------------------------------
    echo "\n=== Part 2: micro_sample_overlay_write_json filesystem ===\n";

    // Reset the project JSON (overlay mutated in place — write a fresh
    // copy out and read it back through the helper).
    $tmpSrc  = "/tmp/micro_overlay_src_$stamp.json";
    $tmpDest = "/tmp/micro_overlay_dest_$stamp.json";
    $srcJson = json_encode(array(
        'datasets' => array(
            array(
                'samples' => array(
                    array(
                        'id'                  => $sampleStraboId,
                        'sampleID'            => 'OriginalSourceFile',
                        'sampleDescription'   => 'from source file',
                        'materialType'        => 'intact_rock',
                        'mainSamplingPurpose' => 'petrology',
                    ),
                ),
            ),
        ),
    ));
    file_put_contents($tmpSrc, $srcJson);

    $ok = micro_sample_overlay_write_json($db, $tmpSrc, $tmpDest, $ownerPkey, 9876543210);
    check("write_json returned true on valid source",                       $ok === true);
    check("write_json wrote destination file",                              file_exists($tmpDest));

    $outJson = json_decode(file_get_contents($tmpDest), true);
    check("write_json: destination parses as JSON",                         is_array($outJson));
    check("write_json: overlaid sampleID = 'EditedSampleName'",
          isset($outJson['datasets'][0]['samples'][0]['sampleID']) &&
          $outJson['datasets'][0]['samples'][0]['sampleID'] === 'EditedSampleName');
    check("write_json: overlaid materialType = 'tephra'",
          isset($outJson['datasets'][0]['samples'][0]['materialType']) &&
          $outJson['datasets'][0]['samples'][0]['materialType'] === 'tephra');
    check("write_json: modifiedtimestamp patched to 9876543210",
          isset($outJson['modifiedtimestamp']) && (int)$outJson['modifiedtimestamp'] === 9876543210);

    @unlink($tmpSrc);
    @unlink($tmpDest);

    // -------------------------------------------------------------------
    // PART 3: getProjectURL() E2E.
    // -------------------------------------------------------------------
    echo "\n=== Part 3: getProjectURL E2E ===\n";

    // Seed micro_projectmetadata.
    $projectInternalId = (int)$db->get_var("select nextval('micro_projectmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, name)
         VALUES ($1, $2, $3, $4)",
        array($projectInternalId, $ownerPkey, $projectStraboId, 'Smoketest Micro Project')
    );
    $datasetInternalId = (int)$db->get_var("select nextval('micro_datasetmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_datasetmetadata (id, project_id, strabo_id, name)
         VALUES ($1, $2, $3, $4)",
        array($datasetInternalId, $projectInternalId, "smoketest-micro-ds-$stamp", 'Smoketest Dataset')
    );

    // Set up the on-disk project directory.
    $straboFilesDir = "$docRoot/straboMicroFiles/$projectInternalId";
    if (!is_dir($straboFilesDir)) {
        mkdir($straboFilesDir, 0755, true);
    }
    $jsonObj = array(
        'modifiedTimestamp' => 1000,
        'datasets' => array(
            array(
                'samples' => array(
                    array(
                        'id'                  => $sampleStraboId,
                        'sampleID'            => 'UploadTimeName',
                        'sampleDescription'   => 'upload-time description',
                        'sampleNotes'         => 'upload-time notes',
                        'materialType'        => 'intact_rock',
                        'mainSamplingPurpose' => 'petrology',
                        'latitude'            => 40.0,
                        'longitude'           => -100.0,
                    ),
                ),
            ),
        ),
    );
    file_put_contents("$straboFilesDir/project.json", json_encode($jsonObj));
    // PDF placeholder — getProjectURL copies it if present, but doesn't require it.
    file_put_contents("$straboFilesDir/project.pdf", "%PDF-1.4 fake placeholder\n%%EOF\n");

    // Spine row already populated in Part 1; it's still in place with the
    // edited values. So getProjectURL should produce a JSON with the
    // edited spine.

    $sm = new StraboMicro($neodb, $ownerPkey, $db);
    $sm->setuuid(new UUID());
    $info = $sm->getProjectURL($projectStraboId);
    check("getProjectURL: returns url",                                  is_object($info) && isset($info->url) && $info->url !== '');
    check("getProjectURL: returns bytes > 0",                            is_object($info) && isset($info->bytes) && $info->bytes > 0);
    check("getProjectURL: url is under /ziptemp/ (not the static file)",
          is_object($info) && isset($info->url) && strpos($info->url, '/ziptemp/') === 0);

    // Resolve the absolute path of the produced ZIP, extract project.json.
    $zipPath = $docRoot . $info->url;
    $cleanupZiptemps[] = dirname($zipPath);
    check("getProjectURL: produced ZIP file exists on disk",             file_exists($zipPath));

    $extractDir = "/tmp/micro_export_extract_$stamp";
    @mkdir($extractDir, 0755, true);
    exec("cd " . escapeshellarg($extractDir) . " && unzip -o " . escapeshellarg($zipPath) . " > /dev/null 2>&1");

    $extractedJsonPath = "$extractDir/$projectStraboId/project.json";
    check("extracted project.json exists",                               file_exists($extractedJsonPath));
    $extJson = json_decode(file_get_contents($extractedJsonPath), true);
    check("extracted project.json parses",                               is_array($extJson));
    check("getProjectURL E2E: sampleID = 'EditedSampleName'",
          isset($extJson['datasets'][0]['samples'][0]['sampleID']) &&
          $extJson['datasets'][0]['samples'][0]['sampleID'] === 'EditedSampleName');
    check("getProjectURL E2E: sampleDescription = 'edited via Samples app'",
          isset($extJson['datasets'][0]['samples'][0]['sampleDescription']) &&
          $extJson['datasets'][0]['samples'][0]['sampleDescription'] === 'edited via Samples app');
    check("getProjectURL E2E: materialType = 'tephra'",
          isset($extJson['datasets'][0]['samples'][0]['materialType']) &&
          $extJson['datasets'][0]['samples'][0]['materialType'] === 'tephra');

    exec("rm -rf " . escapeshellarg($extractDir));

    // -------------------------------------------------------------------
    // PART 4: getSharedURL() E2E.
    // -------------------------------------------------------------------
    echo "\n=== Part 4: getSharedURL E2E ===\n";

    // getSharedURL doesn't filter by userpkey; it looks up the owner via
    // the internal project id passed in.
    $sm2 = new StraboMicro($neodb, /* unrelated viewer */ 0, $db);
    $sm2->setuuid(new UUID());
    $shared = $sm2->getSharedURL($projectInternalId);
    check("getSharedURL: returns url under /ziptemp/",
          is_object($shared) && isset($shared->url) && strpos($shared->url, '/ziptemp/') === 0);
    check("getSharedURL: returns bytes > 0",                             is_object($shared) && isset($shared->bytes) && $shared->bytes > 0);

    $sharedZipPath = $docRoot . $shared->url;
    $cleanupZiptemps[] = dirname($sharedZipPath);
    check("getSharedURL: produced ZIP exists",                           file_exists($sharedZipPath));

    $extractDir2 = "/tmp/micro_shared_extract_$stamp";
    @mkdir($extractDir2, 0755, true);
    exec("cd " . escapeshellarg($extractDir2) . " && unzip -o " . escapeshellarg($sharedZipPath) . " > /dev/null 2>&1");

    $extractedJsonPath2 = "$extractDir2/$projectStraboId/project.json";
    check("getSharedURL: extracted project.json exists",                 file_exists($extractedJsonPath2));
    $sharedJson = json_decode(file_get_contents($extractedJsonPath2), true);
    check("getSharedURL E2E: sampleID = 'EditedSampleName'",
          isset($sharedJson['datasets'][0]['samples'][0]['sampleID']) &&
          $sharedJson['datasets'][0]['samples'][0]['sampleID'] === 'EditedSampleName');
    check("getSharedURL E2E: materialType = 'tephra'",
          isset($sharedJson['datasets'][0]['samples'][0]['materialType']) &&
          $sharedJson['datasets'][0]['samples'][0]['materialType'] === 'tephra');

    exec("rm -rf " . escapeshellarg($extractDir2));

    // -------------------------------------------------------------------
    // PART 5: malformed source JSON → verbatim copy fallback.
    // -------------------------------------------------------------------
    echo "\n=== Part 5: malformed source falls back to verbatim copy ===\n";
    $badSrc  = "/tmp/micro_overlay_bad_$stamp.json";
    $badDest = "/tmp/micro_overlay_bad_dest_$stamp.json";
    file_put_contents($badSrc, '{not valid json');
    $ok = micro_sample_overlay_write_json($db, $badSrc, $badDest, $ownerPkey);
    check("malformed source: helper returns false",                       $ok === false);
    check("malformed source: destination still exists (verbatim fallback)", file_exists($badDest));
    check("malformed source: destination matches source verbatim",
          file_get_contents($badDest) === '{not valid json');

    @unlink($badSrc);
    @unlink($badDest);

} finally {
    // -------------------------------------------------------------------
    // Cleanup.
    // -------------------------------------------------------------------
    // ziptemp dirs created during the run.
    foreach ($cleanupZiptemps as $d) {
        if (is_dir($d)) exec("rm -rf " . escapeshellarg($d));
    }
    // On-disk project files.
    if ($straboFilesDir !== null && is_dir($straboFilesDir)) {
        exec("rm -rf " . escapeshellarg($straboFilesDir));
    }
    // PG rows.
    if ($projectInternalId !== null) {
        $db->prepare_query("DELETE FROM micro_datasetmetadata WHERE project_id=$1", array($projectInternalId));
        $db->prepare_query("DELETE FROM micro_projectmetadata WHERE id=$1", array($projectInternalId));
    }
    // strabosamples rows.
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id = ANY($1) AND userpkey=$2",
        array('{' . implode(',', array_map(function($x){return '"'.$x.'"';}, $cleanupSamplesIds)) . '}', $ownerPkey)
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
