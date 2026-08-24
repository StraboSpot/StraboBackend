<?php
/**
 * File: e2e_exp_update.php
 * Description: Tier 4 coverage — the Vue API save_experiment.php UPDATE branch
 *              syncs the spine over HTTP (samples/phase-i-followups). e2e_workflows
 *              B.4 covers the CREATE path; this proves the UPDATE path
 *              (input.pkey present) re-runs exp_sample_sync and the edited
 *              sample values land in strabosamples.* through the live endpoint.
 *
 *                A. POST save_experiment.php {pkey, data.sample:{...edited}} as
 *                   owner → spine sample reflects the edit
 *                B. anonymous → 401 (nothing changed)
 *
 *              Forged-session harness. Hermetic 'e2eexpupd-' prefix.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_exp_update.php
 *
 * @package    StraboExperimental / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/experimental/lib/sample_sync.php';

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
$uuid_gen = new UUID();

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
function httpPostJson($path, $sid, $bodyArr) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2eexpupd_');
    $payload  = tempnam(sys_get_temp_dir(), 'e2eexpupd_p_');
    file_put_contents($payload, json_encode($bodyArr));
    $args = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' -X POST -H 'Content-Type: application/json' ";
    if ($sid !== null) $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    $args .= "--data-binary @" . escapeshellarg($payload) . " ";
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body = file_get_contents($bodyFile); @unlink($bodyFile); @unlink($payload);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

$stamp = time();
$sid   = "e2eexpupd-sample-$stamp";   // not the spine id; exp mints its own strabo_id
$projUuid = "e2eexpupd-proj-$stamp";
$expUuid  = "e2eexpupd-exp-$stamp";

function makeSample($name) {
    return (object)array(
        'id' => 'human', 'name' => $name, 'igsn' => 'IGSN-' . $name, 'description' => 'd',
        'material' => (object)array('material' => (object)array('type' => 'igneous', 'name' => 'granite')),
    );
}
function cleanup($db, $owner) {
    // Spine id is an exp-minted UUID; clean by the test's distinctive sample
    // names (owner-scoped) so an orphan survives even after the straboexp link
    // is gone. (strabosamples.samples.id is text, straboexp.sample.strabo_id is
    // uuid, so a join needs a ::text cast — the name path avoids it entirely.)
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE userpkey=$1 AND name IN ('OrigUpdName','UpdatedOverHttp')",
        array((int)$owner));
    $db->query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'e2eexpupd-%'");
    $db->query("DELETE FROM straboexp.project WHERE uuid LIKE 'e2eexpupd-%'");
    // Sync hooks index the app-endpoint fixtures; raw source deletes never
    // unindex (orphaned globe markers, 2026-08-23).
    $db->query("DELETE FROM strabosearch.item_hit WHERE project_id LIKE 'e2eexpupd-%'");
}
cleanup($db, $owner);

try {
    $db->prepare_query("INSERT INTO straboexp.project (userpkey, uuid, name, ispublic) VALUES ($1,$2,'E2E Upd Project',FALSE)", array($owner, $projUuid));
    $projPkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.project WHERE uuid=$1", array($projUuid));
    $db->prepare_query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid, json) VALUES ($1,$2,'e2eupd-exp',$3,$4)",
        array($projPkey, $owner, $expUuid, json_encode(array('sample' => makeSample('OrigUpdName')))));
    $expPkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.experiment WHERE uuid=$1", array($expUuid));
    // Seed the spine via the real sync (create) so a strabo_id + spine row exist.
    $straboId = exp_sample_sync($db, $uuid_gen, $expPkey, $owner, makeSample('OrigUpdName'), $neodb);
    check("seed: spine row exists with original name",
        $straboId && $db->get_var_prepared("SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($straboId, $owner)) === 'OrigUpdName');

    $sidOwner = forgeSession($owner);

    // A — UPDATE over HTTP
    echo "=== A: save_experiment.php UPDATE → spine reflects the edit ===\n";
    $body = array('pkey' => $expPkey, 'data' => array(
        'experiment' => array('id' => 'e2eupd-exp', 'name' => 'updated'),
        'sample'     => (array)makeSample('UpdatedOverHttp'),
    ));
    $r = httpPostJson("/experimental/api/save_experiment.php", $sidOwner, $body);
    // The Vue API can prepend a PHP warning before the JSON (known infra wart) — find the JSON.
    $jpos = strpos($r['body'], '{');
    $j = $jpos !== false ? json_decode(substr($r['body'], $jpos), true) : null;
    note("HTTP {$r['status']}");
    check("A: endpoint 200 + success",  $r['status'] === 200 && is_array($j) && !empty($j['success']));
    check("A: spine name updated over HTTP ('UpdatedOverHttp')",
        $db->get_var_prepared("SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($straboId, $owner)) === 'UpdatedOverHttp');
    check("A: spine igsn updated over HTTP",
        $db->get_var_prepared("SELECT igsn FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($straboId, $owner)) === 'IGSN-UpdatedOverHttp');

    // B — anonymous rejected
    echo "\n=== B: save_experiment.php anonymous → 401 ===\n";
    $r = httpPostJson("/experimental/api/save_experiment.php", null, $body);
    note("HTTP {$r['status']}");
    check("B: anonymous rejected (401)", $r['status'] === 401);
    check("B: spine unchanged by the rejected call",
        $db->get_var_prepared("SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($straboId, $owner)) === 'UpdatedOverHttp');

} finally {
    cleanup($db, $owner);
    foreach ($sessionFiles as $f) @unlink($f);
}

echo "\n=================================================\n";
if (empty($failures)) { echo "RESULT: all checks PASS\n"; exit(0); }
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
