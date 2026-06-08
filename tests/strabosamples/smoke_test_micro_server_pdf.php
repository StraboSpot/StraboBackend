<?php
/**
 * File: smoke_test_micro_server_pdf.php
 * Description: Smoke for samples/micro-server-pdf (Phase 1) + samples/micro-
 *              server-pdf-micrographs (Phase 2). Verifies:
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
 *                Part 6: PDF structural smoke + Phase 2 header verification.
 *                        The PDF embeds DejaVu Unicode fonts so text is
 *                        glyph-encoded — can't grep "Instrument Information"
 *                        in the raw bytes (pdftotext is not in the container).
 *                        Instead use MicroProjectPDF::getRenderedSectionHeaders()
 *                        to confirm every Phase 2 sub-section header is
 *                        actually rendered.
 *                Part 7: Phase 2 composite image embed — the seeded JPEG at
 *                        straboMicroFiles/<pkey>/images/{strabo_id}.jpg lands
 *                        in the PDF (search for /DCTDecode + JPEG SOI marker
 *                        in the stream payload).
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

    // Phase 2 fixtures: micrograph + instrument + 2 detectors + orientation +
    // mineralogy (with 2 minerals) + grain info (size/shape) + fabric info
    // (with 1 fabric). Lets Part 6 confirm every Phase 2 sub-section header
    // is actually rendered.
    $micrographStraboId = "smoketest-mspdf-micro-$stamp";
    $micrographInternalId = (int)$db->get_var("select nextval('micro_micrographmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_micrographmetadata
            (id, sample_id, strabo_id, name, imagetype, width, height,
             scalepixelspercentimeter, scale, description, notes, polish, polishdescription)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)",
        array($micrographInternalId, $sampleInternalId, $micrographStraboId,
              'Smoketest Micrograph', 'BSE', 4096, 3072,
              5000.0, 'scale bar = 100um', 'micrograph description',
              'micrograph notes', true, 'mechanical polish')
    );

    $instrumentInternalId = (int)$db->get_var("select nextval('micro_instrument_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_instrument
            (id, micrograph_id, instrumenttype, datatype, instrumentbrand,
             instrumentmodel, university, laboratory, datacollectionsoftware,
             filamenttype, accelerationvoltage, beamcurrent, spotsize, workingdistance,
             instrumentnotes)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)",
        array($instrumentInternalId, $micrographInternalId,
              'SEM', 'image', 'JEOL', 'JSM-7100F',
              'Test University', 'Microscopy Lab', 'Aztec 4.0',
              'tungsten', 15.0, 2.5, 4.0, 10.0, 'instrument notes for smoke test')
    );

    $db->prepare_query(
        "INSERT INTO micro_instrumentdetector
            (instrument_id, detectortype, detectormake, detectormodel)
         VALUES ($1, $2, $3, $4)",
        array($instrumentInternalId, 'BSE', 'Oxford', 'AZtecOne')
    );
    $db->prepare_query(
        "INSERT INTO micro_instrumentdetector
            (instrument_id, detectortype, detectormake, detectormodel)
         VALUES ($1, $2, $3, $4)",
        array($instrumentInternalId, 'EDS', 'Oxford', 'X-Max 50')
    );

    $db->prepare_query(
        "INSERT INTO micro_micrographorientation
            (micrographmetadata_id, orientationmethod, toptrend, topplunge,
             topreferencecorner, sidetrend, sideplunge, sidereferencecorner,
             fabricreference, fabricstrike, fabricdip, lookdirection, topcorner)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)",
        array($micrographInternalId,
              'compass-clinometer', 45.0, 30.0, 'NW',
              135.0, 60.0, 'SE',
              'bedding', 90.0, 45.0, 'down', 'NE')
    );

    $mineralogyInternalId = (int)$db->get_var("select nextval('micro_mineralogy_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_mineralogy (id, micrograph_id, mineralogymethod, notes)
         VALUES ($1, $2, $3, $4)",
        array($mineralogyInternalId, $micrographInternalId, 'point counting', 'mineralogy smoke notes')
    );
    $db->prepare_query(
        "INSERT INTO micro_mineral (mineralogy_id, name, operator, percentage)
         VALUES ($1, $2, $3, $4)",
        array($mineralogyInternalId, 'quartz', '~', 35.0)
    );
    $db->prepare_query(
        "INSERT INTO micro_mineral (mineralogy_id, name, operator, percentage)
         VALUES ($1, $2, $3, $4)",
        array($mineralogyInternalId, 'feldspar', '=', 25.0)
    );

    $graininfoInternalId = (int)$db->get_var("select nextval('micro_graininfo_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_graininfo
            (id, micrograph_id, grainsizenotes, grainshapenotes, grainorientationnotes)
         VALUES ($1, $2, $3, $4, $5)",
        array($graininfoInternalId, $micrographInternalId,
              'grain size smoke notes', 'grain shape smoke notes', null)
    );
    $db->prepare_query(
        "INSERT INTO micro_grainsize
            (graininfo_id, phases, mean, median, mode, standarddeviation, sizeunit)
         VALUES ($1, $2, $3, $4, $5, $6, $7)",
        array($graininfoInternalId, 'quartz', 50.0, 48.0, 45.0, 12.5, 'um')
    );
    $db->prepare_query(
        "INSERT INTO micro_grainshape (graininfo_id, phases, shape)
         VALUES ($1, $2, $3)",
        array($graininfoInternalId, 'quartz', 'subhedral')
    );

    $fabricinfoInternalId = (int)$db->get_var("select nextval('micro_fabricinfo_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_fabricinfo (id, micrograph_id, notes) VALUES ($1, $2, $3)",
        array($fabricinfoInternalId, $micrographInternalId, 'fabric smoke notes')
    );
    $db->prepare_query(
        "INSERT INTO micro_fabric
            (fabric_info_id, fabriclabel, fabricelement, fabriccategory,
             fabricspacing, fabricdefinedby)
         VALUES ($1, $2, $3, $4, $5, $6)",
        array($fabricinfoInternalId, 'S1', 'foliation', 'planar', 'penetrative', 'mica')
    );

    // Pretend the desktop client uploaded the project — create the on-disk
    // dir + a placeholder client PDF (which we'll later overwrite via regen).
    $docRoot = '/srv/app/www';
    $straboFilesDir = "$docRoot/straboMicroFiles/$projectInternalId";
    if (!is_dir($straboFilesDir)) mkdir($straboFilesDir, 0755, true);
    if (!is_dir("$straboFilesDir/images")) mkdir("$straboFilesDir/images", 0755, true);
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

    // Composite image for the Phase 2 embed (mirrors what createProjectImages
    // writes server-side at upload time). 200x150 solid color so the JPEG is
    // small but unmistakably a JPEG (SOI/EOI markers + JFIF header).
    $imgPath = "$straboFilesDir/images/$micrographStraboId.jpg";
    $gd = imagecreatetruecolor(200, 150);
    imagefilledrectangle($gd, 0, 0, 199, 149, imagecolorallocate($gd, 70, 130, 180));
    imagejpeg($gd, $imgPath, 85);
    imagedestroy($gd);

    echo "owner=$ownerPkey  project=$projectStraboId (internal id $projectInternalId)\n";
    echo "sample=$sampleStraboId  ds=$datasetStraboId (internal id $datasetInternalId)\n";
    echo "micrograph=$micrographStraboId (internal id $micrographInternalId)\n\n";

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
    // PART 6: PDF structural smoke + Phase 2 section header verification.
    // tFPDF + DejaVu embeds Unicode text as glyph IDs (not ASCII) so the
    // raw bytes are not searchable for "Instrument Information" et al.
    // pdftotext is not in the container. Use the test-only
    // getRenderedSectionHeaders() accessor on MicroProjectPDF to confirm
    // each Phase 2 sub-section header was rendered, then back it up with
    // structural checks on the binary.
    // -------------------------------------------------------------------
    echo "\n=== Part 6: PDF structural + Phase 2 header verification ===\n";
    $direct = file_get_contents($directOut);
    check("PDF ends with %%EOF",                                          substr_count($direct, '%%EOF') >= 1);
    check("PDF references DejaVu font",                                   stripos($direct, 'DejaVu') !== false);
    // Cover + TOC + Project Details + 1 Dataset + 1 Sample + 1 Micrograph
    // = at least 6 pages (image embed may bump to 7 if it page-breaks).
    $pageCount = preg_match_all('|/Type\s*/Page[^s]|', $direct, $m);
    check("PDF has at least 6 pages (Cover, TOC, Project, Dataset, Sample, Micrograph)", $pageCount >= 6);

    $headers = $gen->getRenderedSectionHeaders();
    check("rendered headers include 'Project Details'",                   in_array('Project Details', $headers, true));
    check("rendered headers include the seeded Dataset section",          in_array('Dataset: Smoketest Dataset', $headers, true));
    check("rendered headers include a Sample section",
          (bool) array_filter($headers, function ($h) { return strpos($h, 'Sample: ') === 0; }));
    check("rendered headers include the seeded Micrograph section",       in_array('Micrograph: Smoketest Micrograph', $headers, true));
    check("rendered headers include 'Instrument Information'",            in_array('Instrument Information', $headers, true));
    check("rendered headers include 'Orientation Information'",           in_array('Orientation Information', $headers, true));
    check("rendered headers include 'Mineralogy'",                        in_array('Mineralogy', $headers, true));
    check("rendered headers include 'Grain Information'",                 in_array('Grain Information', $headers, true));
    check("rendered headers include 'Fabric Information'",                in_array('Fabric Information', $headers, true));

    // -------------------------------------------------------------------
    // PART 7: Phase 2 composite image embed.
    // The seeded JPEG should land in the PDF as a DCTDecode-filtered
    // stream object. Both the DCTDecode filter mention and the JPEG
    // start-of-image marker (FFD8) should be present in the body.
    // -------------------------------------------------------------------
    echo "\n=== Part 7: Phase 2 composite image embed ===\n";
    check("PDF declares a DCTDecode filter (embedded JPEG)",              strpos($direct, '/DCTDecode') !== false);
    check("PDF body contains JPEG SOI marker (FFD8FFE0 — JFIF header)",   strpos($direct, "\xFF\xD8\xFF\xE0") !== false);
    // Direct sanity: the seeded JPEG file is non-trivial in size, and the
    // PDF must be at least that large since the JPEG is embedded verbatim.
    check("PDF size >= seeded image size + base structure",                filesize($directOut) > filesize($imgPath));

} finally {
    if ($projectInternalId !== null) {
        $db->prepare_query("DELETE FROM strabosamples.sample_subsystem_links
                            WHERE sample_id=$1 AND sample_userpkey=$2",
                           array($sampleStraboId, $ownerPkey));
        // Phase 2 micrograph child rows must come down before the parent.
        $db->prepare_query(
            "DELETE FROM micro_instrumentdetector
              WHERE instrument_id IN (SELECT id FROM micro_instrument WHERE micrograph_id IN
                  (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1))",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_instrument WHERE micrograph_id IN
                (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1)",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_micrographorientation WHERE micrographmetadata_id IN
                (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1)",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_mineral
              WHERE mineralogy_id IN (SELECT id FROM micro_mineralogy WHERE micrograph_id IN
                  (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1))",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_mineralogy WHERE micrograph_id IN
                (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1)",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_grainsize
              WHERE graininfo_id IN (SELECT id FROM micro_graininfo WHERE micrograph_id IN
                  (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1))",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_grainshape
              WHERE graininfo_id IN (SELECT id FROM micro_graininfo WHERE micrograph_id IN
                  (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1))",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_grainorientation
              WHERE graininfo_id IN (SELECT id FROM micro_graininfo WHERE micrograph_id IN
                  (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1))",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_graininfo WHERE micrograph_id IN
                (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1)",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_fabric
              WHERE fabric_info_id IN (SELECT id FROM micro_fabricinfo WHERE micrograph_id IN
                  (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1))",
            array($sampleInternalId));
        $db->prepare_query(
            "DELETE FROM micro_fabricinfo WHERE micrograph_id IN
                (SELECT id FROM micro_micrographmetadata WHERE sample_id=$1)",
            array($sampleInternalId));
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
