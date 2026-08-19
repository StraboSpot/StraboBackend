<?php
/**
 * File: e2e_exp_delete.php
 * Description: Curl-level END-TO-END coverage of the StraboExperimental
 *              DELETE endpoints' strabosamples.* spine cleanup, added on
 *              samples/phase-i-deletes. Before this work the Vue delete
 *              endpoints did a raw `DELETE FROM straboexp.experiment` with no
 *              spine removal, orphaning every mirrored sample row + link.
 *
 *              Proves the fix through the live session-gated HTTP endpoints
 *              (not just the exp_sample_sync null-branch the lib smoke
 *              already covers):
 *
 *                A. POST delete_experiment.php?id={exp} (owner) → experiment
 *                   gone + straboexp.sample gone + spine sample removed
 *                B. priority-aware: an experiment whose sample is ALSO linked
 *                   to Field → deleting the experiment keeps the spine sample
 *                   (only the experimental link/data drops)
 *                C. POST delete_project.php?id={proj} (owner) → every
 *                   experiment's sample removed from the spine in bulk
 *                D. delete_experiment.php anonymous → 401 (nothing removed)
 *                E. delete_experiment.php stranger, private → 404 (nothing removed)
 *
 *              Seeds via the REAL exp_sample_sync() so each fixture has a
 *              genuine straboexp.sample row + spine sample + experimental
 *              link to remove. Forged-session harness from e2e_exp_download.php.
 *
 *              Hermetic: straboexp rows under an 'e2eexpdel-' uuid prefix;
 *              spine ids (exp UUIDs) tracked + cleaned in finally{}. Uses the
 *              setup_test_data.php users. Exits non-zero on any failure.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_exp_delete.php
 *
 * @package    StraboExperimental / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/experimental/lib/sample_sync.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

$uuid_gen = new UUID();

// ----- users -----
$users = array();
foreach (array('owner', 'outsider') as $who) {
    $email = "$who@test.strabospot.org";
    $pkey  = (int)$db->get_var_prepared(
        "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
    if (!$pkey) {
        echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n";
        exit(1);
    }
    $users[$who] = $pkey;
}
$ownerPkey    = $users['owner'];
$strangerPkey = $users['outsider'];
echo "owner=$ownerPkey stranger=$strangerPkey\n\n";

// ----- forged sessions -----
$sessionDir   = '/var/lib/php/sessions';
$sessionFiles = array();
function forgeSession($pkey) {
    global $sessionDir, $sessionFiles;
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = $sessionDir . '/sess_' . $sid;
    file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    $sessionFiles[] = $path;
    return $sid;
}

// ----- HTTP POST (id in query string; optional PHPSESSID cookie) -----
function httpPost($path, $sid) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2eexpdel_body_');
    $args  = "-s -X POST -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    if ($sid !== null) $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body   = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

$stamp = time();
$spineIds = array();   // exp-minted strabo_ids to clean up

function makeSample($humanId, $name) {
    return (object)array(
        'id'          => $humanId,
        'name'        => $name,
        'igsn'        => 'IGSN-' . $name,
        'description' => 'desc ' . $name,
        'material'    => (object)array(
            'material'   => (object)array('type' => 'igneous', 'name' => 'granite'),
            'provenance' => (object)array('location' => (object)array('latitude' => '40.0', 'longitude' => '-100.0')),
        ),
    );
}

// Seed a project + experiment, then mirror a sample through exp_sample_sync.
// Returns array(projPkey, expPkey, strabo_id).
function seedExp($db, $neodb, $uuid_gen, $ownerPkey, $projUuid, $expUuid, $humanName, $ispublic, &$spineIds) {
    $existsProj = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.project WHERE uuid=$1", array($projUuid));
    if (!$existsProj) {
        $db->prepare_query("INSERT INTO straboexp.project (userpkey, uuid, name, ispublic) VALUES ($1,$2,$3,$4)",
            array($ownerPkey, $projUuid, 'E2E Del ' . $humanName, $ispublic ? 'TRUE' : 'FALSE'));
    }
    $projPkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.project WHERE uuid=$1", array($projUuid));
    $sample   = makeSample('human-' . $humanName, $humanName);
    $expJson  = json_encode(array('experiment' => array('name' => $humanName), 'sample' => $sample));
    $db->prepare_query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid, json) VALUES ($1,$2,$3,$4,$5)",
        array($projPkey, $ownerPkey, 'e2edel-' . $humanName, $expUuid, $expJson));
    $expPkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.experiment WHERE uuid=$1", array($expUuid));
    $sid = exp_sample_sync($db, $uuid_gen, $expPkey, $ownerPkey, $sample, $neodb);
    if ($sid) $spineIds[] = $sid;
    return array($projPkey, $expPkey, $sid);
}

function spineCount($db, $owner, $sid) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sid, $owner));
}
function linkCount($db, $owner, $sid, $subsystem) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem=$3", array($sid, $owner, $subsystem));
}
function expSampleRows($db, $expPkey) {
    return (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.sample WHERE experiment_pkey=$1", array($expPkey));
}
function expRows($db, $expPkey) {
    return (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.experiment WHERE pkey=$1", array($expPkey));
}

function delCleanup($db, $neodb, $owner, $spineIds) {
    foreach (array_unique($spineIds) as $sid) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sid, $owner));
    }
    $db->query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'e2eexpdel-%'");
    $db->query("DELETE FROM straboexp.project WHERE uuid LIKE 'e2eexpdel-%'");
}

// pre-clean any stale rows
$db->query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'e2eexpdel-%'");
$db->query("DELETE FROM straboexp.project WHERE uuid LIKE 'e2eexpdel-%'");

$sidOwner    = forgeSession($ownerPkey);
$sidStranger = forgeSession($strangerPkey);

try {
    // =====================================================================
    // A — delete_experiment.php removes the spine sample
    // =====================================================================
    echo "=== A: delete_experiment.php (owner) → spine sample removed ===\n";
    list($pA, $eA, $sA) = seedExp($db, $neodb, $uuid_gen, $ownerPkey,
        "e2eexpdel-projA-$stamp", "e2eexpdel-expA-$stamp", "AloneA", false, $spineIds);
    check("A: seeded straboexp.sample row",      expSampleRows($db, $eA) === 1);
    check("A: seeded spine sample",              $sA && spineCount($db, $ownerPkey, $sA) === 1);
    check("A: seeded experimental link",         $sA && linkCount($db, $ownerPkey, $sA, 'experimental') === 1);

    $r = httpPost("/experimental/api/delete_experiment.php?id=$eA", $sidOwner);
    note("HTTP {$r['status']}");
    check("A: endpoint 200",                     $r['status'] === 200);
    check("A: straboexp.experiment deleted",     expRows($db, $eA) === 0);
    check("A: straboexp.sample deleted",         expSampleRows($db, $eA) === 0);
    check("A: spine sample removed (no orphan)", $sA && spineCount($db, $ownerPkey, $sA) === 0);

    // =====================================================================
    // B — priority-aware: experiment sample ALSO linked to Field survives
    // =====================================================================
    echo "\n=== B: delete_experiment.php priority-aware (sample also in Field) ===\n";
    list($pB, $eB, $sB) = seedExp($db, $neodb, $uuid_gen, $ownerPkey,
        "e2eexpdel-projB-$stamp", "e2eexpdel-expB-$stamp", "SharedB", false, $spineIds);
    // Attach a Field link to the same spine sample.
    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey($ownerPkey);
    $svc->upsertSample('field', $sB, $ownerPkey,
        array('name'=>'SharedB','igsn'=>null,'description'=>null,'notes'=>null,'latitude'=>null,'longitude'=>null,'display_sample_type'=>null,'display_sample_purpose'=>null),
        array('seeded_field'=>true),
        array('reference_id'=>'fieldref-'.$eB, 'reference_userpkey'=>$ownerPkey, 'reference_metadata'=>array('seeded'=>true)));
    check("B: sample has experimental + field links", linkCount($db,$ownerPkey,$sB,'experimental') === 1 && linkCount($db,$ownerPkey,$sB,'field') === 1);

    $r = httpPost("/experimental/api/delete_experiment.php?id=$eB", $sidOwner);
    note("HTTP {$r['status']}");
    check("B: endpoint 200",                       $r['status'] === 200);
    check("B: spine sample SURVIVES (field owns it)", spineCount($db,$ownerPkey,$sB) === 1);
    check("B: experimental link dropped",          linkCount($db,$ownerPkey,$sB,'experimental') === 0);
    check("B: field link remains",                 linkCount($db,$ownerPkey,$sB,'field') === 1);
    check("B: experimental_data nulled out",       $db->get_var_prepared(
        "SELECT experimental_data FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sB,$ownerPkey)) === null);

    // =====================================================================
    // C — delete_project.php bulk-removes every experiment's spine sample
    // =====================================================================
    echo "\n=== C: delete_project.php (owner) → all experiments' spine samples removed ===\n";
    $projC = "e2eexpdel-projC-$stamp";
    list($pC, $eC1, $sC1) = seedExp($db, $neodb, $uuid_gen, $ownerPkey, $projC, "e2eexpdel-expC1-$stamp", "ProjC1", false, $spineIds);
    list($pC2, $eC2, $sC2) = seedExp($db, $neodb, $uuid_gen, $ownerPkey, $projC, "e2eexpdel-expC2-$stamp", "ProjC2", false, $spineIds);
    check("C: two experiments in one project",     $pC === $pC2 && $eC1 !== $eC2);
    check("C: both spine samples seeded",          spineCount($db,$ownerPkey,$sC1) === 1 && spineCount($db,$ownerPkey,$sC2) === 1);

    $r = httpPost("/experimental/api/delete_project.php?id=$pC", $sidOwner);
    note("HTTP {$r['status']}");
    check("C: endpoint 200",                       $r['status'] === 200);
    check("C: both experiments deleted",           expRows($db,$eC1) === 0 && expRows($db,$eC2) === 0);
    check("C: project deleted",                    (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.project WHERE pkey=$1", array($pC)) === 0);
    check("C: spine sample 1 removed (no orphan)", spineCount($db,$ownerPkey,$sC1) === 0);
    check("C: spine sample 2 removed (no orphan)", spineCount($db,$ownerPkey,$sC2) === 0);

    // =====================================================================
    // D — anonymous → 401, nothing removed
    // =====================================================================
    echo "\n=== D: delete_experiment.php anonymous → 401, nothing removed ===\n";
    list($pD, $eD, $sD) = seedExp($db, $neodb, $uuid_gen, $ownerPkey,
        "e2eexpdel-projD-$stamp", "e2eexpdel-expD-$stamp", "AnonD", false, $spineIds);
    $r = httpPost("/experimental/api/delete_experiment.php?id=$eD", null);
    note("HTTP {$r['status']}");
    check("D: anonymous rejected (401)",           $r['status'] === 401);
    check("D: experiment NOT deleted",             expRows($db, $eD) === 1);
    check("D: spine sample untouched",             spineCount($db,$ownerPkey,$sD) === 1);

    // =====================================================================
    // E — stranger on a private experiment → 404, nothing removed
    // =====================================================================
    echo "\n=== E: delete_experiment.php stranger, private → 404, nothing removed ===\n";
    $r = httpPost("/experimental/api/delete_experiment.php?id=$eD", $sidStranger);
    note("HTTP {$r['status']}");
    check("E: stranger denied (404)",              $r['status'] === 404);
    check("E: experiment NOT deleted",             expRows($db, $eD) === 1);
    check("E: spine sample untouched",             spineCount($db,$ownerPkey,$sD) === 1);

} finally {
    delCleanup($db, $neodb, $ownerPkey, $spineIds);
    foreach ($sessionFiles as $f) @unlink($f);
}

echo "\n=================================================\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
}
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
