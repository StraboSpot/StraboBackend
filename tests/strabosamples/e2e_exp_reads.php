<?php
/**
 * File: e2e_exp_reads.php
 * Description: Curl-level E2E that the StraboExperimental NON-download read
 *              surfaces apply the strabosamples.* spine overlay, added on
 *              samples/phase-i-deletes. Before this work the SPA's primary
 *              read (get_experiment.php) and the public HTML overviews served
 *              raw experiment.json — a Samples-app edit was invisible there
 *              even though the four download_* endpoints reflected it.
 *
 *                A. GET /experimental/api/get_experiment.php?id={exp} (JSON,
 *                   owner) → data.sample overlaid
 *                B. get_experiment.php anonymous, private project → 404
 *                   (anonymous PUBLIC reads allowed since 2026-08-05 —
 *                   see tests/experimental/smoke_test_exp_public_anonymous.php)
 *                C. GET /experimental/overview_experiment.php?u={uuid} (HTML)
 *                   → rendered body shows the overlaid name/igsn/description
 *                D. GET /experimental/overview_project.php?u={projuuid} (HTML)
 *                   → rendered body shows the overlaid sample name
 *
 *              Forged-session harness from e2e_exp_download.php. Hermetic
 *              'e2eexpread-' prefix; cleans up at start + in finally{}.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_exp_reads.php
 *
 * @package    StraboExperimental / StraboSamples
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

$ownerPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array('owner@test.strabospot.org'));
if (!$ownerPkey) { echo "Run tests/collaboration/setup_test_data.php first.\n"; exit(1); }
echo "owner=$ownerPkey\n\n";

$sessionDir = '/var/lib/php/sessions';
$sessionFiles = array();
function forgeSession($pkey) {
    global $sessionDir, $sessionFiles;
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = $sessionDir . '/sess_' . $sid;
    file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
    chmod($path, 0600); @chown($path, 'www-data'); @chgrp($path, 'www-data');
    $sessionFiles[] = $path;
    return $sid;
}
function httpGet($path, $sid) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2eexpread_');
    $args  = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    if ($sid !== null) $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body = file_get_contents($bodyFile); @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

$stamp    = time();
$sid      = "e2eexpread-sample-$stamp";
$projUuid = "e2eexpread-proj-$stamp";
$expUuid  = "e2eexpread-exp-$stamp";

function expJson($straboId) {
    return json_encode(array(
        'experiment' => array('name' => 'Read Exp', 'title' => 'Read Exp'),
        'sample'     => array(
            'strabo_id'   => $straboId,
            'id'          => 'human-' . $straboId,
            'name'        => 'OriginalReadName',
            'igsn'        => 'IGSN-ORIG-READ',
            'description' => 'original read description',
            'material'    => array('material' => array('type' => 'igneous', 'name' => 'granite')),
        ),
    ));
}
function cleanup($db) {
    $db->query("DELETE FROM strabosamples.samples WHERE id LIKE 'e2eexpread-%'");
    $db->query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'e2eexpread-%'");
    $db->query("DELETE FROM straboexp.project WHERE uuid LIKE 'e2eexpread-%'");
}
cleanup($db);

try {
    $db->prepare_query("INSERT INTO straboexp.project (userpkey, uuid, name, ispublic) VALUES ($1,$2,'E2E Read Project',FALSE)", array($ownerPkey, $projUuid));
    $projPkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.project WHERE uuid=$1", array($projUuid));
    $db->prepare_query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid, json) VALUES ($1,$2,'e2eread-exp',$3,$4)",
        array($projPkey, $ownerPkey, $expUuid, expJson($sid)));
    $expPkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.experiment WHERE uuid=$1", array($expUuid));

    // Spine edit
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, igsn, description, display_sample_type, created_by, modified_by, created_at, modified_at)
         VALUES ($1,$2,$3,$4,$5,$6,$2,$2,NOW(),NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET name=$3, igsn=$4, description=$5, display_sample_type=$6, modified_at=NOW()",
        array($sid, $ownerPkey, 'EditedReadName', 'IGSN-NEW-READ', 'edited via Samples app', 'tephra'));

    $sidOwner = forgeSession($ownerPkey);

    // A — get_experiment.php JSON
    echo "=== A: get_experiment.php (JSON) — overlay applied ===\n";
    $r = httpGet("/experimental/api/get_experiment.php?id=$expPkey", $sidOwner);
    note("HTTP {$r['status']}");
    $s = (is_array($r['json']) && isset($r['json']['data']['sample'])) ? $r['json']['data']['sample'] : null;
    check("A: 200 + data.sample present",        $r['status'] === 200 && $s !== null);
    check("A: sample.name overlaid",             $s && $s['name'] === 'EditedReadName');
    check("A: sample.igsn overlaid",             $s && $s['igsn'] === 'IGSN-NEW-READ');
    check("A: sample.description overlaid",       $s && $s['description'] === 'edited via Samples app');
    check("A: material.material.type overlaid",   $s && isset($s['material']['material']['type']) && $s['material']['material']['type'] === 'tephra');
    check("A: material.material.name UNCHANGED",   $s && isset($s['material']['material']['name']) && $s['material']['material']['name'] === 'granite');

    // B — anonymous on a PRIVATE project → 404 (existence hidden; public reads are anonymous-allowed since 2026-08-05)
    echo "\n=== B: get_experiment.php anonymous, private → 404 ===\n";
    $r = httpGet("/experimental/api/get_experiment.php?id=$expPkey", null);
    note("HTTP {$r['status']}");
    check("B: anonymous denied on private experiment (404)", $r['status'] === 404);

    // C — overview_experiment.php HTML
    echo "\n=== C: overview_experiment.php (HTML) — overlay rendered ===\n";
    $r = httpGet("/experimental/overview_experiment.php?u=$expUuid", $sidOwner);
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes");
    check("C: 200",                              $r['status'] === 200);
    check("C: rendered HTML shows edited name",  is_string($r['body']) && strpos($r['body'], 'EditedReadName') !== false);
    check("C: rendered HTML shows edited igsn",  is_string($r['body']) && strpos($r['body'], 'IGSN-NEW-READ') !== false);
    check("C: original name NOT shown",          is_string($r['body']) && strpos($r['body'], 'OriginalReadName') === false);

    // D — overview_project.php HTML
    echo "\n=== D: overview_project.php (HTML) — overlay rendered ===\n";
    $r = httpGet("/experimental/overview_project.php?u=$projUuid", $sidOwner);
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes");
    check("D: 200",                              $r['status'] === 200);
    check("D: rendered HTML shows edited name",  is_string($r['body']) && strpos($r['body'], 'EditedReadName') !== false);
    check("D: original name NOT shown",          is_string($r['body']) && strpos($r['body'], 'OriginalReadName') === false);

} finally {
    cleanup($db);
    foreach ($sessionFiles as $f) @unlink($f);
}

echo "\n=================================================\n";
if (empty($failures)) { echo "RESULT: all checks PASS\n"; exit(0); }
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
