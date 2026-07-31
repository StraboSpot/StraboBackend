<?php
/**
 * File: e2e_micro_download.php
 * Description: Curl-level END-TO-END coverage of the StraboMicro website
 *              download endpoints, proving each applies its strabosamples.*
 *              spine reconciliation *through the live HTTP endpoint*:
 *
 *                A.  GET /download_micro_file.php?project_id={id}  (.smz)
 *                    → micro_overlay_smz overlays the spine onto the
 *                      project.json INSIDE the served zip; the download
 *                      reflects Samples-app edits while preserving the
 *                      {uuid}/project.json structure for desktop re-import.
 *                A2. no spine edit → the served .smz carries the ORIGINAL
 *                    uploaded values (zero-rewrite short-circuit).
 *                B.  GET /download_micro_pdf.php?project_id={id}
 *                    → micro_regenerate_pdf_if_dirty regenerates project.pdf
 *                      when pdf_dirty=TRUE, serves a valid %PDF, clears the
 *                      flag (regen observed via mtime bump + flag clear).
 *                B2. clean project (pdf_dirty=FALSE) → served as-is, no regen
 *                    (project.pdf mtime unchanged).
 *                C.  graceful handling of an unknown project_id (no crash,
 *                    no valid artifact leaked).
 *
 *              Closes the Phase I gap: smoke_test_micro_smz_overlay.php (16/16)
 *              and smoke_test_micro_server_pdf.php (36/36) exercise
 *              micro_overlay_smz() / micro_regenerate_pdf_if_dirty() at the
 *              library layer (0 HTTP). This suite proves the two website
 *              endpoints actually invoke them and serve the result.
 *
 *              NOTE on auth: neither endpoint includes logincheck.php — both
 *              serve by project_id and key the spine work on the project's
 *              OWNER (micro_projectmetadata.userpkey), so no session is
 *              needed. (This is the known public-by-obscurity posture of
 *              Micro file serving — out of Phase I scope; tracked separately.)
 *
 *              Hermetic: 'e2emicro-' strabo_id prefix + on-disk
 *              straboMicroFiles/<id> built world-writable so Apache (www-data)
 *              can regenerate the PDF; cleans up DB rows + the disk dir in
 *              finally{}. Exits non-zero on any failure.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_micro_download.php
 *
 * @package    StraboMicro / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

$docRoot = '/srv/app/www';

// -------------------------------------------------------------------------
// Owner
// -------------------------------------------------------------------------

$ownerPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE",
    array('owner@test.strabospot.org'));
if (!$ownerPkey) { echo "Test user owner@test.strabospot.org not found. Run setup_test_data.php first.\n"; exit(1); }
echo "owner=$ownerPkey\n";

// -------------------------------------------------------------------------
// HTTP GET (these endpoints need no session)
// -------------------------------------------------------------------------

function httpGet($path) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2emi_body_');
    $args = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body   = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body);
}

// Pull a named entry out of a zip given as raw bytes.
function zipEntryFromBytes($bytes, $matchSuffix) {
    $tmp = tempnam(sys_get_temp_dir(), 'e2emi_zip_') . '.zip';
    file_put_contents($tmp, $bytes);
    $out = null;
    $za = new ZipArchive();
    if ($za->open($tmp) === true) {
        for ($i = 0; $i < $za->numFiles; $i++) {
            $name = $za->getNameIndex($i);
            if (substr($name, -strlen($matchSuffix)) === $matchSuffix) { $out = $za->getFromIndex($i); break; }
        }
        $za->close();
    }
    @unlink($tmp);
    return $out;
}

// -------------------------------------------------------------------------
// Fixtures
// -------------------------------------------------------------------------

$stamp        = time();
$projStraboId = "e2emicro-proj-$stamp";
$dsStraboId   = "e2emicro-ds-$stamp";
$sampleSid    = "e2emicro-sample-$stamp";
$microSid     = "e2emicro-micro-$stamp";
$uuidDir      = "aaaaaaaa-bbbb-cccc-dddd-$stamp";

$projId = $dsId = $sampId = $microId = null;
$filesDir = null;

function microCleanup($db, $docRoot, $projStraboId, $dsStraboId, $sampleSid, $ownerPkey) {
    // Resolve internal ids by strabo_id for a robust pre/post cleanup.
    $p = $db->get_var_prepared("SELECT id FROM micro_projectmetadata WHERE strabo_id=$1", array($projStraboId));
    if ($p) {
        $db->prepare_query("DELETE FROM micro_micrographmetadata WHERE sample_id IN (SELECT s.id FROM micro_samplemetadata s JOIN micro_datasetmetadata d ON s.dataset_id=d.id WHERE d.project_id=$1)", array((int)$p));
        $db->prepare_query("DELETE FROM micro_samplemetadata WHERE dataset_id IN (SELECT id FROM micro_datasetmetadata WHERE project_id=$1)", array((int)$p));
        $db->prepare_query("DELETE FROM micro_datasetmetadata WHERE project_id=$1", array((int)$p));
        $db->prepare_query("DELETE FROM micro_projectmetadata WHERE id=$1", array((int)$p));
        if ($docRoot) shell_exec("rm -rf " . escapeshellarg("$docRoot/straboMicroFiles/$p"));
    }
    $db->query("DELETE FROM strabosamples.sample_subsystem_links WHERE sample_id LIKE 'e2emicro-%'");
    $db->query("DELETE FROM strabosamples.samples WHERE id LIKE 'e2emicro-%'");
}

microCleanup($db, $docRoot, $projStraboId, $dsStraboId, $sampleSid, $ownerPkey);

try {
    // ---------------------------------------------------------------------
    // Seed PG micro_*metadata (project → dataset → sample → micrograph).
    // ---------------------------------------------------------------------
    $projId = (int)$db->get_var("select nextval('micro_projectmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, name, notes, modifiedtimestamp)
         VALUES ($1,$2,$3,$4,$5,$6)",
        array($projId, $ownerPkey, $projStraboId, 'E2E Micro Project', 'notes', (string)(int)(microtime(true)*1000)));
    $dsId = (int)$db->get_var("select nextval('micro_datasetmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_datasetmetadata (id, project_id, strabo_id, name) VALUES ($1,$2,$3,$4)",
        array($dsId, $projId, $dsStraboId, 'E2E Micro DS'));
    $sampId = (int)$db->get_var("select nextval('micro_samplemetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_samplemetadata (id, dataset_id, strabo_id, label, sampleid, sampledescription, samplenotes, materialtype, mainsamplingpurpose, latitude, longitude)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)",
        array($sampId, $dsId, $sampleSid, 'Label', 'UploadTime-SampleID', 'upload desc', 'upload notes', 'intact_rock', 'petrology', 40.0, -100.0));
    $microId = (int)$db->get_var("select nextval('micro_micrographmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_micrographmetadata (id, sample_id, strabo_id, name, imagetype, width, height)
         VALUES ($1,$2,$3,$4,$5,$6,$7)",
        array($microId, $sampId, $microSid, 'E2E Micrograph', 'BSE', 2048, 1536));

    // strabosamples spine row with EDITED values + the micro link.
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, description, notes, latitude, longitude,
             display_sample_type, display_sample_purpose, created_by, modified_by, created_at, modified_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$2,$2,NOW(),NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET name=$3, description=$4, notes=$5,
            latitude=$6, longitude=$7, display_sample_type=$8, display_sample_purpose=$9, modified_at=NOW()",
        array($sampleSid, $ownerPkey, 'EditedMicroName', 'edited micro desc', 'edited micro notes',
              45.5, -120.5, 'tephra', 'geochronology'));
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
            (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
         VALUES ($1,$2,'micro',$3,$2,$4::jsonb)
         ON CONFLICT (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey) DO NOTHING",
        array($sampleSid, $ownerPkey, (string)$projId,
              json_encode(array('dataset_id' => $dsId, 'project_strabo_id' => $projStraboId))));

    // ---------------------------------------------------------------------
    // On-disk straboMicroFiles/<projId>: project.json, project.zip, project.pdf.
    // World-writable so Apache (www-data) can regenerate the PDF.
    // ---------------------------------------------------------------------
    $filesDir = "$docRoot/straboMicroFiles/$projId";
    @mkdir("$filesDir/images", 0777, true);

    // project.json shape micro_overlay_smz reads: datasets[].samples[].
    $projectJson = array('datasets' => array(array('samples' => array(array(
        'id'                  => $sampleSid,
        'sampleID'            => 'UploadTime-SampleID',
        'sampleDescription'   => 'upload desc',
        'sampleNotes'         => 'upload notes',
        'materialType'        => 'intact_rock',
        'mainSamplingPurpose' => 'petrology',
        'latitude'            => '40.0',
        'longitude'           => '-100.0',
        'micrographs'         => array(),
    )))));
    file_put_contents("$filesDir/project.json", json_encode($projectJson, JSON_PRETTY_PRINT));

    // project.zip with the {uuid}/project.json structure the desktop uploads.
    $zip = new ZipArchive();
    $zip->open("$filesDir/project.zip", ZipArchive::CREATE);
    $zip->addFromString("$uuidDir/project.json", json_encode($projectJson, JSON_PRETTY_PRINT));
    $zip->addFromString("$uuidDir/tiles/x/meta.json", '{"keep":"me"}');
    $zip->close();

    file_put_contents("$filesDir/project.pdf", "%PDF-1.4 placeholder\n%%EOF\n");
    shell_exec("chmod -R 0777 " . escapeshellarg($filesDir));

    echo "project=$projStraboId (id $projId) sample=$sampleSid\n\n";

    // =====================================================================
    // A — .smz download applies the spine overlay
    // =====================================================================
    echo "=== A: download_micro_file.php (.smz) — overlay applied ===\n";
    $r = httpGet("/download_micro_file.php?project_id=$projId");
    $isZip = strncmp($r['body'], "PK\x03\x04", 4) === 0;
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes, magic=" . bin2hex(substr($r['body'], 0, 2)));
    check("A: 200", $r['status'] === 200);
    check("A: body is a valid zip (.smz)", $isZip);
    $pjRaw = $isZip ? zipEntryFromBytes($r['body'], '/project.json') : null;
    $pj = $pjRaw ? json_decode($pjRaw, true) : null;
    $s0 = (is_array($pj) && isset($pj['datasets'][0]['samples'][0])) ? $pj['datasets'][0]['samples'][0] : null;
    check("A: served zip retains {uuid}/project.json", $s0 !== null);
    check("A: sampleID overlaid from spine ('EditedMicroName')", $s0 && $s0['sampleID'] === 'EditedMicroName');
    check("A: sampleDescription overlaid",                       $s0 && $s0['sampleDescription'] === 'edited micro desc');
    check("A: materialType overlaid ('tephra')",                 $s0 && $s0['materialType'] === 'tephra');
    check("A: latitude overlaid (45.5)",                         $s0 && (float)$s0['latitude'] === 45.5);
    check("A: mainSamplingPurpose overlaid ('geochronology')",   $s0 && $s0['mainSamplingPurpose'] === 'geochronology');
    check("A: other zip entry preserved",                        zipEntryFromBytes($r['body'], 'tiles/x/meta.json') === '{"keep":"me"}');

    // =====================================================================
    // B — PDF download lazily regenerates when pdf_dirty=TRUE
    // =====================================================================
    echo "\n=== B: download_micro_pdf.php — lazy regen when dirty ===\n";
    $db->prepare_query("UPDATE micro_projectmetadata SET pdf_dirty=TRUE WHERE id=$1", array($projId));
    $oldMtime = filemtime("$filesDir/project.pdf");
    sleep(1);
    $r = httpGet("/download_micro_pdf.php?project_id=$projId");
    clearstatcache();
    $newMtime = filemtime("$filesDir/project.pdf");
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes, magic=" . substr($r['body'], 0, 5));
    check("B: 200", $r['status'] === 200);
    check("B: body is a valid PDF (%PDF)", strncmp($r['body'], '%PDF', 4) === 0);
    check("B: regenerated PDF is non-trivial (>2KB)", strlen($r['body']) > 2048);
    check("B: regen happened (project.pdf mtime bumped)", $newMtime > $oldMtime);
    $dirtyAfter = $db->get_var_prepared("SELECT pdf_dirty FROM micro_projectmetadata WHERE id=$1", array($projId));
    check("B: regen cleared pdf_dirty", $dirtyAfter === 'f');

    // =====================================================================
    // B2 — clean project → no regen
    // =====================================================================
    echo "\n=== B2: download_micro_pdf.php — clean project, no regen ===\n";
    clearstatcache();
    $cleanBefore = filemtime("$filesDir/project.pdf");
    sleep(1);
    $r = httpGet("/download_micro_pdf.php?project_id=$projId");
    clearstatcache();
    $cleanAfter = filemtime("$filesDir/project.pdf");
    note("HTTP {$r['status']}");
    check("B2: 200 + valid PDF", $r['status'] === 200 && strncmp($r['body'], '%PDF', 4) === 0);
    check("B2: clean project NOT regenerated (mtime unchanged)", $cleanAfter === $cleanBefore);

    // =====================================================================
    // A2 — .smz with no spine edit → original values served (short-circuit)
    // =====================================================================
    echo "\n=== A2: .smz with no spine edit → original values served ===\n";
    $db->query("DELETE FROM strabosamples.samples WHERE id LIKE 'e2emicro-%'");
    $r = httpGet("/download_micro_file.php?project_id=$projId");
    $pjRaw = (strncmp($r['body'], "PK\x03\x04", 4) === 0) ? zipEntryFromBytes($r['body'], '/project.json') : null;
    $pj = $pjRaw ? json_decode($pjRaw, true) : null;
    $s0 = (is_array($pj) && isset($pj['datasets'][0]['samples'][0])) ? $pj['datasets'][0]['samples'][0] : null;
    check("A2: 200 + valid zip", $r['status'] === 200 && $s0 !== null);
    check("A2: sampleID is the ORIGINAL upload value ('UploadTime-SampleID')", $s0 && $s0['sampleID'] === 'UploadTime-SampleID');
    check("A2: materialType is the ORIGINAL ('intact_rock')",                  $s0 && $s0['materialType'] === 'intact_rock');

    // =====================================================================
    // C — unknown project_id is handled gracefully (no valid artifact)
    // =====================================================================
    echo "\n=== C: unknown project_id → graceful, no artifact ===\n";
    $r = httpGet("/download_micro_file.php?project_id=999888777");
    $leakedZip = strncmp((string)$r['body'], "PK\x03\x04", 4) === 0;
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes");
    check("C: unknown id does not serve a valid .smz", !$leakedZip);

} finally {
    microCleanup($db, $docRoot, $projStraboId, $dsStraboId, $sampleSid, $ownerPkey);
}

echo "\n=================================================\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
}
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
