<?php
/**
 * File: e2e_doi_overlay.php
 * Description: Curl-level E2E that DOI minting applies the strabosamples.*
 *              spine overlay at mint time (Tier 3b, samples/phase-i-followups).
 *              A DOI is a permanent, citable, immutable snapshot — so it must
 *              freeze the sample values as they are when minted, not the
 *              pre-spine-edit upload values. Before this fix build_m_doi.php /
 *              build_e_doi.php froze raw values.
 *
 *                A. Exp: POST build_e_doi.php?p={proj} → the frozen
 *                   doi/doiFiles/<uuid>/project.json carries the overlaid sample
 *                B. Micro: POST build_m_doi.php?p={pid} → the frozen
 *                   doi/doiFiles/<uuid>/project.json (copied from the regenerated
 *                   smzFiles store) carries the overlaid sample
 *
 *              Forged-session harness. Hermetic: straboexp/micro fixtures +
 *              spine edits + the minted doiFiles dirs + dois rows, all cleaned
 *              up in finally{}. (build_*_doi.php sleep(3) each, so this runs
 *              ~6s+.)
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_doi_overlay.php
 *
 * @package    StraboSamples
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

$owner = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array('owner@test.strabospot.org'));
if (!$owner) { echo "Run tests/collaboration/setup_test_data.php first.\n"; exit(1); }

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
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2edoi_');
    $args = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    if ($sid !== null) $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body = file_get_contents($bodyFile); @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

$stamp   = time();
$sidOwner = forgeSession($owner);
$mintedUuids = array();
$docRoot = '/srv/app/www';

// ---- Exp fixtures ----
$expSid  = "e2edoi-exp-sample-$stamp";
$expProjUuid = "e2edoi-exp-proj-$stamp";
$expUuid = "e2edoi-exp-$stamp";
// ---- Micro fixtures ----
$micSid  = "e2edoi-mic-sample-$stamp";
$micProjStraboId = "e2edoi-mic-proj-$stamp";
$micPid  = (int)$db->get_var("select nextval('micro_projectmetadata_id_seq')");
$micSmzDir = "$docRoot/straboMicroView/smzFiles/$micPid";

function cleanup($db) {
    $db->query("DELETE FROM strabosamples.samples WHERE id LIKE 'e2edoi-%'");
    $db->query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'e2edoi-exp-%'");
    $db->query("DELETE FROM straboexp.project WHERE uuid LIKE 'e2edoi-exp-%'");
    $db->query("DELETE FROM micro_projectmetadata WHERE strabo_id LIKE 'e2edoi-mic-%'");
    $db->query("DELETE FROM dois WHERE project_name LIKE 'E2E DOI%'");
}
cleanup($db);

try {
    // ===================================================================
    // A — Exp DOI mint applies overlay
    // ===================================================================
    echo "=== A: build_e_doi.php freezes overlaid sample ===\n";
    $db->prepare_query("INSERT INTO straboexp.project (userpkey, uuid, name, ispublic) VALUES ($1,$2,'E2E DOI Exp',FALSE)", array($owner, $expProjUuid));
    $expProjPkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.project WHERE uuid=$1", array($expProjUuid));
    $expJson = json_encode(array(
        'experiment' => array('name' => 'DOI Exp'),
        'sample' => array(
            'strabo_id' => $expSid, 'id' => 'h', 'name' => 'OrigExpDoiName', 'igsn' => 'IGSN-ORIG',
            'description' => 'orig', 'material' => array('material' => array('type' => 'igneous', 'name' => 'granite')),
        ),
    ));
    $db->prepare_query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid, json) VALUES ($1,$2,'e2edoi-exp',$3,$4)",
        array($expProjPkey, $owner, $expUuid, $expJson));
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, igsn, description, created_by, modified_by, created_at, modified_at)
         VALUES ($1,$2,$3,$4,$5,$2,$2,NOW(),NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET name=$3, igsn=$4, description=$5, modified_at=NOW()",
        array($expSid, $owner, 'EditedExpDoiName', 'IGSN-EDITED', 'edited via samples app'));

    $r = httpGet("/build_e_doi.php?p=$expProjPkey", $sidOwner);
    $mintUuid = (is_array($r['json']) && isset($r['json']['uuid'])) ? $r['json']['uuid'] : null;
    note("HTTP {$r['status']}, uuid=" . ($mintUuid ?: 'NONE'));
    check("A: mint succeeded + uuid returned", $r['status'] === 200 && $mintUuid !== null);
    if ($mintUuid) {
        $mintedUuids[] = $mintUuid;
        $frozen = @file_get_contents("$docRoot/doi/doiFiles/$mintUuid/project.json");
        $fj = $frozen ? json_decode($frozen) : null;
        $s = ($fj && isset($fj->experiments[0]->sample)) ? $fj->experiments[0]->sample : null;
        check("A: frozen DOI sample.name overlaid",  $s && $s->name === 'EditedExpDoiName');
        check("A: frozen DOI sample.igsn overlaid",  $s && $s->igsn === 'IGSN-EDITED');
        check("A: frozen DOI original name absent",  $frozen && strpos($frozen, 'OrigExpDoiName') === false);
    }

    // ===================================================================
    // B — Micro DOI mint applies overlay (via the smzFiles regen)
    // ===================================================================
    echo "\n=== B: build_m_doi.php freezes overlaid sample ===\n";
    $rawMicJson = json_encode(array(
        'id' => $micProjStraboId,
        'datasets' => array(array('id' => 'ds1', 'samples' => array(array(
            'id' => $micSid, 'sampleID' => 'OrigMicDoiName', 'sampleDescription' => 'orig', 'micrographs' => array(),
        )))),
    ));
    $db->prepare_query("INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, projectjson, files_dirty) VALUES ($1,$2,$3,$4,TRUE)",
        array($micPid, $owner, $micProjStraboId, $rawMicJson));
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by, created_at, modified_at)
         VALUES ($1,$2,$3,$2,$2,NOW(),NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET name=$3, modified_at=NOW()",
        array($micSid, $owner, 'EditedMicDoiName'));
    @mkdir($micSmzDir, 0775, true);
    file_put_contents("$micSmzDir/project.json", $rawMicJson);

    $r = httpGet("/build_m_doi.php?p=$micPid", $sidOwner);
    $mintUuid = (is_array($r['json']) && isset($r['json']['uuid'])) ? $r['json']['uuid'] : null;
    note("HTTP {$r['status']}, uuid=" . ($mintUuid ?: 'NONE'));
    check("B: mint succeeded + uuid returned", $r['status'] === 200 && $mintUuid !== null);
    if ($mintUuid) {
        $mintedUuids[] = $mintUuid;
        $frozen = @file_get_contents("$docRoot/doi/doiFiles/$mintUuid/project.json");
        $fj = $frozen ? json_decode($frozen) : null;
        $s = ($fj && isset($fj->datasets[0]->samples[0])) ? $fj->datasets[0]->samples[0] : null;
        check("B: frozen DOI sampleID overlaid",     $s && $s->sampleID === 'EditedMicDoiName');
        check("B: frozen DOI original name absent",  $frozen && strpos($frozen, 'OrigMicDoiName') === false);
    }

} finally {
    cleanup($db);
    foreach ($mintedUuids as $u) { @exec("rm -rf " . escapeshellarg("$docRoot/doi/doiFiles/$u")); }
    @exec("rm -rf " . escapeshellarg($micSmzDir));
    foreach ($sessionFiles as $f) @unlink($f);
}

echo "\n=================================================\n";
if (empty($failures)) { echo "RESULT: all checks PASS\n"; exit(0); }
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
