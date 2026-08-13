<?php
/**
 * File: smoke_test_micro_smz_overlay.php
 * Description: Phase I (non-API surface coverage) — verifies the website
 *              .smz download (download_micro_file.php) overlays the
 *              strabosamples.* spine onto the project.json INSIDE the
 *              uploaded zip, so Samples-app edits surface in a downloaded
 *              .smz — while preserving the zip's exact structure (so the
 *              StraboMicro desktop client can still re-import it).
 *
 *              Contract under test: micro_overlay_smz() in
 *              microdb/lib/sample_overlay.php (the helper download_micro_file.php
 *              calls). Exercises:
 *                Part 1: overlay applied — edited spine surfaces in the served
 *                        zip's project.json; other entries + the ORIGINAL zip
 *                        are untouched; structure ({uuid}/project.json) preserved.
 *                Part 2: no spine edits → returns the ORIGINAL path verbatim
 *                        (zero rewrite — the perf short-circuit).
 *                Part 3: malformed project.json → returns original (never break
 *                        the download).
 *                Part 4: missing zip → returns the passed path (graceful).
 *
 *              Hermetic: builds its own zip under /ziptemp + seeds one
 *              strabosamples row, tears everything down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_micro_smz_overlay.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/microdb/lib/sample_overlay.php';

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
}

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

// Read a single entry out of a zip by name (test helper).
function zip_entry($path, $name) {
    $za = new ZipArchive();
    if ($za->open($path) !== true) return false;
    $raw = $za->getFromName($name);
    $za->close();
    return $raw;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array()
);
if (!is_array($users) || count($users) < 1) { echo "Need 1 user\n"; exit(1); }
$ownerPkey = (int)$users[0]->pkey;

$stamp        = time();
$sampleId     = "smoketest-smz-sample-$stamp";
$untrackedId  = "smoketest-smz-untracked-$stamp";
$uuidDir      = "11111111-2222-3333-4444-$stamp";   // mimic the desktop {uuid}/ prefix
$jsonEntry    = "$uuidDir/project.json";
$otherEntry   = "$uuidDir/tiles/abc/metadata.json";

$docRoot      = $_SERVER['DOCUMENT_ROOT'];
$workDir      = "$docRoot/ziptemp/smz_smoke_$stamp";
$srcZip       = "$workDir/project.zip";
$createdTemps = array();

try {
    @mkdir($workDir, 0775, true);

    // ---- Build a realistic uploaded zip: {uuid}/project.json + a tile file.
    $projectJson = array(
        'datasets' => array(
            array(
                'samples' => array(
                    array(
                        'id'                  => $sampleId,
                        'sampleID'            => 'OriginalUploadName',
                        // Divergent label + null name = the legacy-data shape
                        // (StraboMicro2's modal writes all three identically,
                        // but v1-era uploads can differ). The overlay must
                        // bring all three to the spine name.
                        'label'               => 'OriginalUploadLabel',
                        'name'                => null,
                        'sampleDescription'   => 'original upload description',
                        'sampleNotes'         => 'original upload notes',
                        'materialType'        => 'intact_rock',
                        'mainSamplingPurpose' => 'petrology',
                        'latitude'            => '40.0',
                        'longitude'           => '-100.0',
                        'micrographs'         => array(),
                    ),
                    array(  // no spine row — must pass through unchanged
                        'id'                => $untrackedId,
                        'sampleID'          => 'UntrackedSample',
                        'label'             => 'UntrackedLabel',
                        'sampleDescription' => 'untracked desc',
                    ),
                ),
            ),
        ),
    );
    $otherPayload = '{"tile":"metadata","keep":"me"}';

    $build = new ZipArchive();
    $build->open($srcZip, ZipArchive::CREATE);
    $build->addFromString($jsonEntry, json_encode($projectJson, JSON_PRETTY_PRINT));
    $build->addFromString($otherEntry, $otherPayload);
    $build->close();

    $origZipBytes = file_get_contents($srcZip);   // snapshot to prove non-mutation

    // ---- Seed the strabosamples spine with EDITED values.
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
            $sampleId, $ownerPkey,
            'EditedSampleName', 'edited via Samples app', 'edited notes',
            45.5, -120.5, 'tephra', 'geochronology'
        )
    );

    // ===================================================================
    echo "=== Part 1: overlay applied, structure + original preserved ===\n";
    // ===================================================================
    $served = micro_overlay_smz($db, $srcZip, $ownerPkey);
    if ($served !== $srcZip) $createdTemps[] = $served;

    check("returns a NEW path (overlay changed JSON → temp zip)", $served !== $srcZip && file_exists($served));

    $servedJson = json_decode(zip_entry($served, $jsonEntry));
    check("served zip retains the {uuid}/project.json entry", is_object($servedJson));

    $s0 = $servedJson->datasets[0]->samples[0];
    check("sampleID overlaid from spine (name)",            $s0->sampleID === 'EditedSampleName');
    check("label overlaid too (viewers render label)",      $s0->label === 'EditedSampleName');
    check("name overlaid too (modal triple-write parity)",  $s0->name === 'EditedSampleName');
    check("sampleDescription overlaid",                     $s0->sampleDescription === 'edited via Samples app');
    check("sampleNotes overlaid",                           $s0->sampleNotes === 'edited notes');
    check("latitude overlaid",                              (float)$s0->latitude === 45.5);
    check("longitude overlaid",                             (float)$s0->longitude === -120.5);
    check("materialType overlaid from display_sample_type", $s0->materialType === 'tephra');
    check("mainSamplingPurpose overlaid",                   $s0->mainSamplingPurpose === 'geochronology');

    $s1 = $servedJson->datasets[0]->samples[1];
    check("untracked sample left unchanged (no spine row)", $s1->sampleID === 'UntrackedSample');
    check("untracked sample label untouched",               $s1->label === 'UntrackedLabel');

    check("other zip entry (tile metadata) preserved byte-for-byte",
          zip_entry($served, $otherEntry) === $otherPayload);

    check("ORIGINAL source zip NOT mutated",
          file_get_contents($srcZip) === $origZipBytes);

    // ===================================================================
    echo "=== Part 2: no spine edits → returns original (no rewrite) ===\n";
    // ===================================================================
    // Remove the spine row; now the overlay is a no-op.
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey));

    $served2 = micro_overlay_smz($db, $srcZip, $ownerPkey);
    if ($served2 !== $srcZip) $createdTemps[] = $served2;
    check("returns the ORIGINAL path (zero-rewrite short-circuit)", $served2 === $srcZip);

    // ===================================================================
    echo "=== Part 3: malformed project.json → returns original ===\n";
    // ===================================================================
    $badZip = "$workDir/bad.zip";
    $bz = new ZipArchive();
    $bz->open($badZip, ZipArchive::CREATE);
    $bz->addFromString($jsonEntry, '{ this is : not json ');
    $bz->close();
    $served3 = micro_overlay_smz($db, $badZip, $ownerPkey);
    if ($served3 !== $badZip) $createdTemps[] = $served3;
    check("malformed JSON falls back to original path", $served3 === $badZip);

    // ===================================================================
    echo "=== Part 4: missing zip → returns the passed path ===\n";
    // ===================================================================
    $missing = "$workDir/does_not_exist.zip";
    check("missing zip returns the passed path (no crash)",
          micro_overlay_smz($db, $missing, $ownerPkey) === $missing);

} finally {
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey));
    foreach ($createdTemps as $t) { if ($t && strpos($t, '/ziptemp/') !== false) @unlink($t); }
    if (is_dir($workDir)) {
        array_map('unlink', glob("$workDir/*") ?: array());
        @rmdir($workDir);
    }
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
