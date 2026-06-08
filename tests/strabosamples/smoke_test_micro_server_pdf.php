<?php
/**
 * File: smoke_test_micro_server_pdf.php
 * Description: Phase 1 smoke for samples/micro-server-pdf. Verifies:
 *
 *                Part 1: MicroProjectPDF generates a non-trivial PDF on disk
 *                        from seeded PG fixtures + an upload-time on-disk
 *                        project folder. Confirms PDF magic header + size.
 *                Part 2: pdf_dirty flag flips TRUE on a Samples-app spine
 *                        edit (updateSample on a Micro-linked sample).
 *                Part 3: Lazy regen via getProjectURL — flag TRUE pre-call,
 *                        FALSE post-call, regen happened (mtime bump on the
 *                        straboMicroFiles/<pkey>/project.pdf file).
 *                Part 4: No-op when pdf_dirty=FALSE — getProjectURL on a
 *                        clean project does NOT regenerate. PDF mtime
 *                        unchanged.
 *                Part 5: API-only sample (no Micro link) → updateSample
 *                        leaves pdf_dirty FALSE on any Micro project (clean
 *                        no-op in markMicroProjectPdfsDirty).
 *                Part 6: TOC structure — generated PDF contains the expected
 *                        section headers (cover stats / 'Table of Contents' /
 *                        'Project Details' / 'Dataset:' / 'Sample:'). Phase 1
 *                        layout grep — Phase 2/3 will tighten.
 *
 *              Hermetic: seeds micro_*metadata + on-disk straboMicroFiles
 *              dir + strabosamples row, then tears down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_micro_server_pdf.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/microdb/lib/sample_sync.php';
require_once '/srv/app/www/microdb/lib/MicroProjectPDF.php';
require_once '/srv/app/www/microdb/strabomicroclass.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

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

$stamp = time();
$projectStraboId = "smoketest-mspdf-$stamp";
$datasetStraboId = "smoketest-mspdf-ds-$stamp";
$sampleStraboId  = "smoketest-mspdf-sample-$stamp";
$apiOnlySampleId = "smoketest-mspdf-apionly-$stamp";

$projectInternalId = null;
$datasetInternalId = null;
$sampleInternalId  = null;
$straboFilesDir    = null;
$cleanupSamples    = array($sampleStraboId, $apiOnlySampleId);

try {

    // -------------------------------------------------------------------
    // Setup: seed micro_*metadata + on-disk dir + strabosamples row.
    // -------------------------------------------------------------------
    $projectInternalId = (int)$db->get_var("select nextval('micro_projectmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata
            (id, userpkey, strabo_id, name, startdate, enddate,
             purposeofstudy, areaofinterest, projectlocation, gpsdatum,
             notes, modifiedtimestamp)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)",
        array($projectInternalId, $ownerPkey, $projectStraboId,
              'Smoketest MS-PDF Project', '2026-01-01', '2026-12-31',
              'Test purpose of study', 'Test area', 'Test location',
              'WGS84', 'Project notes for smoke test',
              (string)(int)(microtime(true) * 1000))
    );

    $datasetInternalId = (int)$db->get_var("select nextval('micro_datasetmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_datasetmetadata (id, project_id, strabo_id, name)
         VALUES ($1, $2, $3, $4)",
        array($datasetInternalId, $projectInternalId, $datasetStraboId, 'Smoketest Dataset')
    );

    $sampleInternalId = (int)$db->get_var("select nextval('micro_samplemetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_samplemetadata
            (id, dataset_id, strabo_id, label, sampleid, sampledescription,
             samplenotes, materialtype, mainsamplingpurpose, latitude, longitude)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)",
        array($sampleInternalId, $datasetInternalId, $sampleStraboId,
              'Smoketest-Label', 'UploadTime-SampleID',
              'upload-time description', 'upload-time notes',
              'intact_rock', 'petrology', 40.5, -110.5)
    );

    // strabosamples spine row carrying the EDITED values.
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, description, notes, latitude, longitude,
             display_sample_type, display_sample_purpose,
             created_by, modified_by, created_at, modified_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $2, $2, NOW(), NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET
            name=$3, description=$4, notes=$5, latitude=$6, longitude=$7,
            display_sample_type=$8, display_sample_purpose=$9, modified_at=NOW()",
        array($sampleStraboId, $ownerPkey,
              'EditedSampleID-FromSamplesApp',
              'edited via Samples app',
              'edited notes',
              45.5, -120.5,
              'tephra',
              'geochronology')
    );

    // Materialize the Micro link so markMicroProjectPdfsDirty can find it.
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
            (sample_id, sample_userpkey, subsystem,
             reference_id, reference_userpkey, reference_metadata)
         VALUES ($1, $2, 'micro', $3, $2, $4::jsonb)
         ON CONFLICT (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey) DO NOTHING",
        array($sampleStraboId, $ownerPkey,
              (string)$projectInternalId,
              json_encode(array('dataset_id' => $datasetInternalId,
                                'project_strabo_id' => $projectStraboId)))
    );

    // Pretend the desktop client uploaded the project — create the on-disk
    // dir + a placeholder client PDF (which we'll later overwrite via regen).
    $docRoot = '/srv/app/www';
    $straboFilesDir = "$docRoot/straboMicroFiles/$projectInternalId";
    if (!is_dir($straboFilesDir)) mkdir($straboFilesDir, 0755, true);
    file_put_contents("$straboFilesDir/project.json", json_encode(array(
        'datasets' => array(
            array('samples' => array(
                array('id' => $sampleStraboId,
                      'sampleID' => 'UploadTime-SampleID',
                      'sampleDescription' => 'upload-time description')
            ))
        )
    )));
    file_put_contents("$straboFilesDir/project.pdf", "%PDF-1.4 placeholder\n%%EOF\n");
    $clientPdfMtime = filemtime("$straboFilesDir/project.pdf");

    echo "owner=$ownerPkey  project=$projectStraboId (internal id $projectInternalId)\n";
    echo "sample=$sampleStraboId  ds=$datasetStraboId (internal id $datasetInternalId)\n\n";

    // -------------------------------------------------------------------
    // PART 1: MicroProjectPDF generates a real PDF file.
    // -------------------------------------------------------------------
    echo "=== Part 1: MicroProjectPDF::generateToFile ===\n";
    $directOut = "/tmp/mspdf_direct_$stamp.pdf";
    $gen = new MicroProjectPDF($db, $projectInternalId, $ownerPkey);
    $gen->generateToFile($directOut);
    check("direct PDF generated on disk",                                file_exists($directOut));
    check("direct PDF is non-trivial size (>2KB)",                       filesize($directOut) > 2048);
    $head = file_get_contents($directOut, false, null, 0, 5);
    check("direct PDF starts with %PDF- magic",                          strpos($head, '%PDF-') === 0);

    // -------------------------------------------------------------------
    // PART 2: pdf_dirty flag flips TRUE on updateSample.
    // -------------------------------------------------------------------
    echo "\n=== Part 2: pdf_dirty flips TRUE on updateSample ===\n";
    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey($ownerPkey);

    // Pre-edit state.
    $pre = $db->get_var_prepared(
        "SELECT pdf_dirty FROM micro_projectmetadata WHERE id=$1",
        array($projectInternalId)
    );
    check("pre-edit: pdf_dirty = FALSE (default)",                       $pre === 'f');

    $res = $svc->updateSample($sampleStraboId, $ownerPkey, array(
        'description' => 'second edit via Samples app',
    ));
    check("updateSample: ok=true",                                       isset($res['ok']) && $res['ok'] === true);
    $post = $db->get_var_prepared(
        "SELECT pdf_dirty FROM micro_projectmetadata WHERE id=$1",
        array($projectInternalId)
    );
    check("post-edit: pdf_dirty = TRUE",                                 $post === 't');

    // -------------------------------------------------------------------
    // PART 3: Lazy regen on download — dirty=TRUE triggers regen,
    // mtime bumps, flag clears.
    // -------------------------------------------------------------------
    echo "\n=== Part 3: lazy regen via getProjectURL ===\n";
    $oldMtime = filemtime("$straboFilesDir/project.pdf");
    // Force a perceptible mtime gap so the assertion below is reliable.
    sleep(1);
    $sm = new StraboMicro($neodb, $ownerPkey, $db);
    $sm->setuuid(new UUID());
    $info = $sm->getProjectURL($projectStraboId);
    $newMtime = filemtime("$straboFilesDir/project.pdf");

    check("getProjectURL returned a url",                                is_object($info) && isset($info->url) && $info->url !== '');
    check("lazy regen happened: project.pdf mtime bumped",               $newMtime > $oldMtime);
    $afterRegen = $db->get_var_prepared(
        "SELECT pdf_dirty FROM micro_projectmetadata WHERE id=$1",
        array($projectInternalId)
    );
    check("lazy regen cleared the dirty flag",                           $afterRegen === 'f');
    check("regenerated PDF is a real PDF (magic)",
          strpos(file_get_contents("$straboFilesDir/project.pdf", false, null, 0, 5), '%PDF-') === 0);
    check("regenerated PDF size >2KB (real content)",                    filesize("$straboFilesDir/project.pdf") > 2048);

    // -------------------------------------------------------------------
    // PART 4: No-op when pdf_dirty = FALSE.
    // -------------------------------------------------------------------
    echo "\n=== Part 4: clean projects skip regen ===\n";
    $cleanMtimeBefore = filemtime("$straboFilesDir/project.pdf");
    sleep(1);
    $sm->getProjectURL($projectStraboId);
    $cleanMtimeAfter = filemtime("$straboFilesDir/project.pdf");
    check("clean call: project.pdf mtime UNCHANGED",                      $cleanMtimeAfter === $cleanMtimeBefore);

    // -------------------------------------------------------------------
    // PART 5: API-only sample (no Micro link) is a clean no-op.
    // -------------------------------------------------------------------
    echo "\n=== Part 5: API-only sample updateSample no-op for Micro PDF ===\n";
    $created = $svc->createSample(array(
        'id'   => $apiOnlySampleId,
        'name' => 'NoLinksHere',
    ));
    check("createSample(api-only) ok",                                    isset($created['ok']) && $created['ok'] === true);

    // Reset our dirty flag — proving the API-only edit doesn't flip it.
    $db->prepare_query("UPDATE micro_projectmetadata SET pdf_dirty=FALSE WHERE id=$1",
                       array($projectInternalId));
    $svc->updateSample($apiOnlySampleId, $ownerPkey, array(
        'description' => 'api-only edit',
    ));
    $afterApiOnly = $db->get_var_prepared(
        "SELECT pdf_dirty FROM micro_projectmetadata WHERE id=$1",
        array($projectInternalId)
    );
    check("api-only updateSample left pdf_dirty FALSE",                   $afterApiOnly === 'f');

    // -------------------------------------------------------------------
    // PART 6: TOC + section structure visible in generated PDF.
    // PDF binaries have compressed streams — we can't grep DejaVu-encoded
    // text reliably, but pdftotext isn't in the container. Instead
    // assert the document has the structural hallmarks we'd expect:
    // enough pages, page contents reference DejaVu fonts (which only
    // happens when we emit text), and the doc has multiple top-level
    // objects (project + dataset + sample, at minimum). Tightened in
    // Phase 2/3 when pdftotext becomes available.
    // -------------------------------------------------------------------
    echo "\n=== Part 6: PDF structural smoke ===\n";
    $direct = file_get_contents($directOut);
    // pdfkit/pdfkit-compatible PDFs end with %%EOF after the trailer.
    check("PDF ends with %%EOF",                                          substr_count($direct, '%%EOF') >= 1);
    // tFPDF embeds Font/F1, Font/F2 ... resource refs once per font;
    // confirm a DejaVu reference exists.
    check("PDF references DejaVu font",                                   stripos($direct, 'DejaVu') !== false);
    // Page count — Cover + TOC + Project Details + 1 Dataset + 1 Sample
    // + 0 Micrographs + 0 Spots = at least 5 pages. /Type /Page (not
    // /Type /Pages) counts pages.
    $pageCount = preg_match_all('|/Type\s*/Page[^s]|', $direct, $m);
    check("PDF has at least 5 pages (Cover, TOC, Project, Dataset, Sample)", $pageCount >= 5);

} finally {
    if ($projectInternalId !== null) {
        $db->prepare_query("DELETE FROM strabosamples.sample_subsystem_links
                            WHERE sample_id=$1 AND sample_userpkey=$2",
                           array($sampleStraboId, $ownerPkey));
        $db->prepare_query("DELETE FROM micro_micrographmetadata WHERE sample_id=$1",
                           array($sampleInternalId));
        $db->prepare_query("DELETE FROM micro_samplemetadata WHERE id=$1",
                           array($sampleInternalId));
        $db->prepare_query("DELETE FROM micro_datasetmetadata WHERE id=$1",
                           array($datasetInternalId));
        $db->prepare_query("DELETE FROM micro_projectmetadata WHERE id=$1",
                           array($projectInternalId));
    }
    if ($straboFilesDir !== null && is_dir($straboFilesDir)) {
        exec("rm -rf " . escapeshellarg($straboFilesDir));
    }
    foreach ($cleanupSamples as $sid) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                           array($sid, $ownerPkey));
    }
    @unlink("/tmp/mspdf_direct_$stamp.pdf");
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
