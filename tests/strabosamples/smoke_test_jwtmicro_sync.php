<?php
/**
 * File: smoke_test_jwtmicro_sync.php
 * Description: Coverage for the jwtmicrodb (JWT Micro API) spine WRITE hooks
 *              added on samples/phase-i-deletes. jwtmicrodb is a pre-spine
 *              copy of microdb with no lib/ and zero sync integration — so a
 *              Micro project uploaded or deleted through the JWT API silently
 *              failed to mirror into / out of strabosamples.* (the StraboMicro
 *              app authenticates via this API, so the gap is live).
 *
 *              Fix: jwtmicrodb::loadProjectJSON now calls micro_sample_sync
 *              after each sample insert, and ::deleteProject calls
 *              micro_sample_sync_remove_project before the cascade delete,
 *              both reusing microdb's shared lib/sample_sync.php.
 *
 *              Drives the REAL jwtmicrodb StraboMicro class methods:
 *                A. loadProjectJSON → micro_samplemetadata + spine sample +
 *                   micro link all land
 *                B. deleteProject → spine sample removed (no orphan), source
 *                   rows gone
 *
 *              Hermetic: project/dataset/sample strabo ids under a per-run
 *              space; torn down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_jwtmicro_sync.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';   // defines class StraboMicro (JWT variant)

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$u = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted=FALSE AND active=TRUE ORDER BY pkey LIMIT 1", array());
if (!is_array($u) || count($u) < 1) { echo "Need 1 user\n"; exit(1); }
$owner = (int)$u[0]->pkey;

$stamp        = time();
$projStraboId = "jwtmic-proj-$stamp";
$dsStraboId   = "jwtmic-ds-$stamp";
$sampStraboId = "jwtmic-samp-$stamp";

// Internal project id from the real sequence (loadProjectJSON takes it as a param).
$pmid = (int)$db->get_var("select nextval('micro_projectmetadata_id_seq')");

$projectJson = json_encode(array(
    'id'   => $projStraboId,
    'name' => 'JWT Micro Sync Test',
    'datasets' => array(array(
        'id'   => $dsStraboId,
        'name' => 'ds1',
        'samples' => array(array(
            'id'                  => $sampStraboId,
            'sampleID'            => 'JWT-Sample-A',
            'sampleDescription'   => 'jwt micro sample',
            'materialType'        => 'igneous',
            'mainSamplingPurpose' => 'petrology',
            'latitude'            => 44.0,
            'longitude'           => -111.0,
            'micrographs'         => array(),
        )),
    )),
));

function spineCount($db, $owner, $sid) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sid, $owner));
}
function microLinkCount($db, $owner, $sid) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='micro'", array($sid, $owner));
}

$micro = new StraboMicro($neodb, $owner, $db);
$micro->setuserpkey($owner);

try {
    // ===================================================================
    // PART A: loadProjectJSON mirrors the sample into the spine
    // ===================================================================
    echo "=== A: jwtmicrodb loadProjectJSON → spine mirror ===\n";
    $micro->loadProjectJSON($projectJson, $pmid, "");

    check("A: micro_projectmetadata row created",
        (int)$db->get_var_prepared("SELECT count(*) FROM micro_projectmetadata WHERE id=$1", array($pmid)) === 1);
    check("A: micro_samplemetadata row created",
        (int)$db->get_var_prepared("SELECT count(*) FROM micro_samplemetadata WHERE strabo_id=$1", array($sampStraboId)) === 1);
    check("A: spine sample mirrored",        spineCount($db, $owner, $sampStraboId) === 1);
    check("A: micro subsystem link created", microLinkCount($db, $owner, $sampStraboId) === 1);

    $spine = $db->get_row_prepared(
        "SELECT name, description, display_sample_type, display_sample_purpose, latitude, longitude
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sampStraboId, $owner));
    check("A: spine name from sampleID",        $spine && $spine->name === 'JWT-Sample-A');
    check("A: spine description projected",      $spine && $spine->description === 'jwt micro sample');
    check("A: spine display_sample_type",        $spine && $spine->display_sample_type === 'igneous');
    check("A: spine display_sample_purpose",     $spine && $spine->display_sample_purpose === 'petrology');
    check("A: spine latitude projected",         $spine && abs((float)$spine->latitude - 44.0) < 0.0001);
    check("A: spine longitude projected",        $spine && abs((float)$spine->longitude - (-111.0)) < 0.0001);

    // ===================================================================
    // PART B: deleteProject removes the spine sample (no orphan)
    // ===================================================================
    echo "\n=== B: jwtmicrodb deleteProject → spine removal ===\n";
    $micro->deleteProject($projStraboId);

    check("B: spine sample removed (no orphan)", spineCount($db, $owner, $sampStraboId) === 0);
    check("B: micro link removed",               microLinkCount($db, $owner, $sampStraboId) === 0);
    check("B: micro_projectmetadata row gone",
        (int)$db->get_var_prepared("SELECT count(*) FROM micro_projectmetadata WHERE id=$1", array($pmid)) === 0);
    check("B: micro_samplemetadata row gone",
        (int)$db->get_var_prepared("SELECT count(*) FROM micro_samplemetadata WHERE strabo_id=$1", array($sampStraboId)) === 0);

} finally {
    // Defensive cleanup (deleteProject normally handles the source rows).
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sampStraboId, $owner));
    $sid = (int)$db->get_var_prepared("SELECT id FROM micro_projectmetadata WHERE id=$1", array($pmid));
    if ($sid) {
        $db->query("DELETE FROM micro_samplemetadata WHERE dataset_id IN (SELECT id FROM micro_datasetmetadata WHERE project_id=$pmid)");
        $db->query("DELETE FROM micro_datasetmetadata WHERE project_id=$pmid");
        $db->query("DELETE FROM micro_projectmetadata WHERE id=$pmid");
    }
    @exec("rm -rf /srv/app/www/straboMicroFiles/$pmid");
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
