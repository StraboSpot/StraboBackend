<?php
/**
 * File: e2e_micro_html_views.php
 * Description: Curl-level E2E that the public Micro HTML views apply the
 *              strabosamples.* spine overlay, added on samples/phase-i-deletes.
 *              These pages rendered sample data straight from the raw
 *              projectjson column, so a Samples-app edit was invisible.
 *
 *              The user-visible sample VALUES surface through the AJAX detail
 *              pane micrographDetailsPane.php (microproject.php / microproject2.php
 *              build the micrograph grid via MicroLanding and don't emit sample
 *              text inline — their overlay is harmless defense-in-depth, so they
 *              get a render-didn't-break smoke):
 *
 *                A. GET /micrographDetailsPane.php?pkey={pkey}&id={micrograph}
 *                   (public, anon) → rendered sample pane shows the overlaid
 *                   sampleID/description, not the uploaded values
 *                B. GET /microproject.php?id={pkey}  (public, anon) → 200 (the
 *                   overlay didn't break the landing page)
 *                C. GET /microproject2.php?id={pkey} (public, anon) → 200
 *
 *              Hermetic: a public micro_projectmetadata fixture + spine edit.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_micro_html_views.php
 *
 * @package    StraboMicro / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
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

function httpGet($path) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2emichtml_');
    $status = (int)shell_exec("curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . escapeshellarg("http://localhost" . $path));
    $body = file_get_contents($bodyFile); @unlink($bodyFile);
    return array('status' => $status, 'body' => $body);
}

$owner = (int)$db->get_var("SELECT pkey FROM users WHERE deleted=FALSE AND active=TRUE ORDER BY pkey LIMIT 1");
$stamp = time();
$projStraboId = "michtml-proj-$stamp";
$sampStraboId = "michtml-samp-$stamp";
$micrographId = "michtml-mic-$stamp";
$pmid = (int)$db->get_var("select nextval('micro_projectmetadata_id_seq')");

$projectjson = json_encode(array(
    'id'   => $projStraboId,
    'name' => 'Micro HTML View Test',
    'datasets' => array(array(
        'id'   => 'ds1',
        'name' => 'ds1',
        'samples' => array(array(
            'id'                => $sampStraboId,
            'sampleID'          => 'OrigHtmlSampleName',
            'sampleDescription' => 'orig html desc',
            'materialType'      => 'igneous',
            'micrographs'       => array(array(
                'id'   => $micrographId,
                'name' => 'micro-1',
            )),
        )),
    )),
));

try {
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, projectjson, ispublic) VALUES ($1,$2,$3,$4,TRUE)",
        array($pmid, $owner, $projStraboId, $projectjson));
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, description, display_sample_type, created_by, modified_by, created_at, modified_at)
         VALUES ($1,$2,$3,$4,$5,$2,$2,NOW(),NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET name=$3, description=$4, display_sample_type=$5, modified_at=NOW()",
        array($sampStraboId, $owner, 'EDITEDHtmlSampleName', 'edited html desc', 'tephra'));

    // A — micrographDetailsPane.php (the sample-rendering surface)
    echo "=== A: micrographDetailsPane.php (public, anon) — overlay rendered ===\n";
    $r = httpGet("/micrographDetailsPane.php?pkey=$pmid&id=" . urlencode($micrographId));
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes");
    check("A: 200",                                  $r['status'] === 200);
    check("A: pane shows overlaid sampleID",         is_string($r['body']) && strpos($r['body'], 'EDITEDHtmlSampleName') !== false);
    check("A: pane shows overlaid description",       is_string($r['body']) && strpos($r['body'], 'edited html desc') !== false);
    check("A: original sampleID NOT shown",          is_string($r['body']) && strpos($r['body'], 'OrigHtmlSampleName') === false);

    // B — microproject.php (overlay doesn't break the landing page)
    echo "\n=== B: microproject.php (public, anon) — renders OK with overlay ===\n";
    $r = httpGet("/microproject.php?id=$pmid");
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes");
    check("B: 200 (overlay didn't break page)",       $r['status'] === 200 && strlen($r['body']) > 1000);
    check("B: no stale-sample leak in landing HTML",  is_string($r['body']) && strpos($r['body'], 'OrigHtmlSampleName') === false);

    // C — microproject2.php
    echo "\n=== C: microproject2.php (public, anon) — renders OK with overlay ===\n";
    $r = httpGet("/microproject2.php?id=$pmid");
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes");
    check("C: 200 (overlay didn't break page)",       $r['status'] === 200 && strlen($r['body']) > 1000);

} finally {
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sampStraboId, $owner));
    $db->query("DELETE FROM micro_projectmetadata WHERE id=$pmid");
}

echo "\n=================================================\n";
if (empty($failures)) { echo "RESULT: all checks PASS\n"; exit(0); }
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
