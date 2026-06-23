<?php
/**
 * File: smoke_test_micro_getprojectinfo_overlay.php
 * Description: Coverage for the getProjectInfo() spine overlay added on
 *              samples/phase-i-deletes. getProjectInfo (GET /microdb/project
 *              and /jwtmicrodb/project — the Micro app's primary project read)
 *              returned the raw projectjson DB column with no spine overlay,
 *              so Samples-app edits were invisible there even though the
 *              sibling download endpoints overlaid them.
 *
 *              Drives the jwtmicrodb StraboMicro class (the higher-risk path —
 *              it reuses microdb's lib via a cross-dir require). microdb's
 *              getProjectInfo is character-identical with a same-dir require
 *              (lower risk; also covered by smoke_test_micro_export_readthrough).
 *
 *              Seeds a micro_projectmetadata row's projectjson directly +
 *              a strabosamples spine edit, then asserts getProjectInfo's
 *              returned projectDetails reflects the overlay.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_micro_getprojectinfo_overlay.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';   // class StraboMicro (JWT variant, cross-dir require)

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$owner = (int)$db->get_var("SELECT pkey FROM users WHERE deleted=FALSE AND active=TRUE ORDER BY pkey LIMIT 1");
$stamp = time();
$projStraboId = "micgpi-proj-$stamp";
$sampStraboId = "micgpi-samp-$stamp";
$pmid = (int)$db->get_var("select nextval('micro_projectmetadata_id_seq')");

$projectjson = json_encode(array(
    'id' => $projStraboId,
    'datasets' => array(array(
        'id' => 'ds1',
        'samples' => array(array(
            'id'                => $sampStraboId,
            'sampleID'          => 'OrigMicroName',
            'sampleDescription' => 'orig micro desc',
            'materialType'      => 'igneous',
            'micrographs'       => array(),
        )),
    )),
));

try {
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, projectjson) VALUES ($1,$2,$3,$4)",
        array($pmid, $owner, $projStraboId, $projectjson));
    // Spine edit (simulates a Samples-app edit).
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, description, display_sample_type, created_by, modified_by, created_at, modified_at)
         VALUES ($1,$2,$3,$4,$5,$2,$2,NOW(),NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET name=$3, description=$4, display_sample_type=$5, modified_at=NOW()",
        array($sampStraboId, $owner, 'EditedMicroName', 'edited micro desc', 'tephra'));

    $micro = new StraboMicro($neodb, $owner, $db);
    $micro->setuserpkey($owner);

    echo "=== jwtmicrodb getProjectInfo overlay ===\n";
    $out = $micro->getProjectInfo($projStraboId);
    $s = (isset($out->projectDetails->datasets[0]->samples[0])) ? $out->projectDetails->datasets[0]->samples[0] : null;
    check("getProjectInfo returned the project",       isset($out->count) && $out->count == 1);
    check("sample present in projectDetails",          $s !== null);
    check("sampleID overlaid to spine name",           $s && $s->sampleID === 'EditedMicroName');
    check("sampleDescription overlaid",                $s && $s->sampleDescription === 'edited micro desc');
    check("materialType overlaid to spine type",       $s && $s->materialType === 'tephra');

} finally {
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sampStraboId, $owner));
    $db->query("DELETE FROM micro_projectmetadata WHERE id=$pmid");
}

echo "\n";
if (empty($failures)) { echo "RESULT: all checks PASS\n"; exit(0); }
echo "RESULT: " . count($failures) . " failure(s):\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
