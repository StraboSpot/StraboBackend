<?php
/**
 * File: smoke_test_versioning_http.php
 * Description: HTTP-layer smoke test for the restored StraboExperimental
 *              versioning workflow + ispublic toggle (both broken since the
 *              Vue rewrite 7df1cfc, restored 2026-08-01).
 *
 *              Covers:
 *                toggle   — /experimental/api/project_public.php flips
 *                           straboexp.project.ispublic; owner-only; 401
 *                           unauthenticated; foreign project untouched
 *                snapshot — save_experiment (create + update) and
 *                           delete_experiment each write a straboexp.versions
 *                           row BEFORE mutating
 *                restore  — /experimental/api/activate_version.php restores a
 *                           snapshot: current state snapshotted first,
 *                           experiments replaced, samples spine converges on
 *                           the SAME strabo_id (custom_data survives = row
 *                           continuity), spine rows absent from the snapshot
 *                           are torn down (no orphans)
 *                junk     — a snapshot sample that is contentless junk
 *                           ({strabo_id: ...} only, 07-29 bug window) does
 *                           NOT resurrect sample rows and its live spine row
 *                           is torn down
 *                delete   — /experimental/api/delete_version.php removes own
 *                           rows only; foreign versions 404/no-op
 *                page     — /versioning.php renders the StraboExperimental
 *                           block with the new endpoint links
 *
 *              Hermetic: sentinel project + versions cleaned by uuid; spine
 *              rows removed by collected id.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/experimental/smoke_test_versioning_http.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$SPINE = strpos(file_get_contents('/srv/app/www/experimental/lib/sample_sync.php'),
                'StraboSamplesService') !== false;
echo "spine mirroring: " . ($SPINE ? "ON" : "OFF") . "\n";

$PROJ_UUID  = 'c0000000-0000-4000-8000-00000000cccc';
$OTHER_UUID = 'c0000000-0000-4000-8000-00000000dddd';

$user = $db->get_row_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array());
$userpkey = (int)$user->pkey;
$other = $db->get_row_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE AND pkey <> $1 ORDER BY pkey LIMIT 1",
    array($userpkey));
$other_userpkey = $other ? (int)$other->pkey : 0;

// --- session harness (same pattern as smoke_test_save_experiment_http) ---
$sessionDir = '/var/lib/php/sessions';
$sid  = substr(bin2hex(random_bytes(16)), 0, 26);
$sessFile = $sessionDir . '/sess_' . $sid;
file_put_contents($sessFile, 'loggedin|s:3:"yes";userpkey|i:' . $userpkey . ';LAST_ACTIVITY|i:' . time() . ';');
chmod($sessFile, 0600);
@chown($sessFile, 'www-data');
@chgrp($sessFile, 'www-data');

function httpGet($sid, $path) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'vexp_body_');
    $hdrFile  = tempnam(sys_get_temp_dir(), 'vexp_hdr_');
    $cookie = $sid !== null ? "-H " . escapeshellarg('Cookie: PHPSESSID=' . $sid) . " " : "";
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -D " . escapeshellarg($hdrFile)
         . " -w '%{http_code}' $cookie" . escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    $hdrs = file_get_contents($hdrFile);
    @unlink($bodyFile); @unlink($hdrFile);
    $loc = null;
    if (preg_match('/^Location:\s*(\S+)/mi', $hdrs, $m)) $loc = $m[1];
    return array('status' => $status, 'body' => $body, 'location' => $loc,
                 'json' => json_decode($body));
}

function hitSave($sid, $payload) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'vexp_save_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' "
         . "-H 'Content-Type: application/json' -H " . escapeshellarg('Cookie: PHPSESSID=' . $sid) . " "
         . "-X POST -d " . escapeshellarg(json_encode($payload)) . " "
         . "http://localhost/experimental/api/save_experiment.php";
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body));
}

function hitDeleteExp($sid, $pkey) {
    $cmd = "curl -s -o /dev/null -w '%{http_code}' -X POST "
         . "-H " . escapeshellarg('Cookie: PHPSESSID=' . $sid) . " "
         . escapeshellarg("http://localhost/experimental/api/delete_experiment.php?id=" . $pkey);
    return (int)shell_exec($cmd);
}

function makeSampleData($name, $id) {
    return array(
        'facility'  => array('name' => 'Versioning Smoke Facility'),
        'apparatus' => array('type' => 'Paterson Apparatus'),
        'daq'       => new stdClass(),
        'sample'    => array(
            'name' => $name, 'igsn' => '', 'id' => $id, 'description' => 'versioning smoke',
            'material' => array(
                'material' => array('type' => 'Sedimentary Rock', 'name' => 'Sandstone', 'state' => 'Solid', 'note' => ''),
                'composition' => array(array('mineral' => 'Quartz', 'fraction' => '60', 'unit' => 'Wt%', 'grainsize' => '')),
            ),
            'parameters' => array(array('control' => 'Mass', 'value' => '12.3', 'unit' => 'g', 'prefix' => '', 'note' => '')),
        ),
        'experiment' => array('id' => $id),
        'data'       => new stdClass(),
    );
}

function versionsCount($db, $uuid) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.versions WHERE uuid = $1", array($uuid));
}
function latestVersion($db, $uuid) {
    return $db->get_row_prepared(
        "SELECT pkey, experimentcount, json FROM straboexp.versions WHERE uuid = $1 ORDER BY pkey DESC LIMIT 1",
        array($uuid));
}

$project_pkey = null;
$other_project_pkey = null;
$spine_ids = array();

try {
    $project_pkey = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.project (pkey, userpkey, name, uuid, ispublic) VALUES ($1, $2, $3, $4, 'f')",
        array($project_pkey, $userpkey, 'SMOKE versioning', $PROJ_UUID));

    // ---- Part 1: ispublic toggle ----------------------------------------
    echo "\n== toggle ==\n";
    $r = httpGet($sid, "/experimental/api/project_public.php?projectid=$project_pkey&state=public");
    check('toggle public: HTTP 200 + success JSON',
        $r['status'] === 200 && is_object($r['json']) && !empty($r['json']->success));
    $flag = $db->get_var_prepared("SELECT ispublic FROM straboexp.project WHERE pkey = $1", array($project_pkey));
    check('toggle public: DB flag now true', $flag === 't');

    $r = httpGet($sid, "/experimental/api/project_public.php?projectid=$project_pkey&state=private");
    $flag = $db->get_var_prepared("SELECT ispublic FROM straboexp.project WHERE pkey = $1", array($project_pkey));
    check('toggle private: DB flag now false', $r['status'] === 200 && $flag === 'f');

    $r = httpGet(null, "/experimental/api/project_public.php?projectid=$project_pkey&state=public");
    check('toggle unauthenticated: HTTP 401', $r['status'] === 401);

    if ($other_userpkey) {
        $other_project_pkey = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
        $db->prepare_query(
            "INSERT INTO straboexp.project (pkey, userpkey, name, uuid, ispublic) VALUES ($1, $2, $3, $4, 'f')",
            array($other_project_pkey, $other_userpkey, 'SMOKE versioning other', $OTHER_UUID));
        $r = httpGet($sid, "/experimental/api/project_public.php?projectid=$other_project_pkey&state=public");
        $flag = $db->get_var_prepared("SELECT ispublic FROM straboexp.project WHERE pkey = $1", array($other_project_pkey));
        check('toggle foreign project: HTTP 404', $r['status'] === 404);
        check('toggle foreign project: flag untouched', $flag === 'f');
    } else {
        echo "  SKIP  no second user on this instance (foreign-project checks)\n";
    }

    // ---- Part 2: snapshots on write paths -------------------------------
    echo "\n== snapshot ==\n";
    check('baseline: no versions for sentinel project', versionsCount($db, $PROJ_UUID) === 0);

    $r = hitSave($sid, array('project_pkey' => $project_pkey, 'experiment_id' => 'SMK-V1',
                             'data' => makeSampleData('Version Sample One', 'SMK-V1')));
    check('create: HTTP 200', $r['status'] === 200 && is_object($r['json']) && !empty($r['json']->success));
    $exp1 = is_object($r['json']) ? (int)$r['json']->pkey : 0;
    $sid1 = is_object($r['json']) && !empty($r['json']->strabo_id) ? $r['json']->strabo_id : null;
    if ($sid1) $spine_ids[] = $sid1;
    check('create: snapshot written', versionsCount($db, $PROJ_UUID) === 1);
    $v = latestVersion($db, $PROJ_UUID);
    check('create: snapshot captured PRE-add state (0 experiments)', $v && (int)$v->experimentcount === 0);

    $r = hitSave($sid, array('pkey' => $exp1, 'experiment_id' => 'SMK-V1',
                             'data' => makeSampleData('Version Sample One EDITED', 'SMK-V1')));
    check('update: HTTP 200', $r['status'] === 200);
    check('update: snapshot written', versionsCount($db, $PROJ_UUID) === 2);
    $vRestore = latestVersion($db, $PROJ_UUID);
    check('update: snapshot captured PRE-edit state (1 experiment)',
        $vRestore && (int)$vRestore->experimentcount === 1);
    $vj = $vRestore ? json_decode($vRestore->json) : null;
    $snapSid = ($vj && isset($vj->experiments[0]->sample->strabo_id)) ? $vj->experiments[0]->sample->strabo_id : null;
    check('update: snapshot embeds sample strabo_id', $snapSid === $sid1);

    // ---- Part 3: restore ------------------------------------------------
    echo "\n== restore ==\n";
    // Row-continuity marker: if the restore drops + recreates the spine row,
    // this custom_data is lost.
    if ($SPINE && $sid1) {
        $db->prepare_query(
            "UPDATE strabosamples.samples SET custom_data =
                 coalesce(custom_data, '{}'::jsonb) || '{\"_versioning_smoke\": \"marker\"}'::jsonb
             WHERE id = $1 AND userpkey = $2", array($sid1, $userpkey));
    }

    // Second experiment whose sample must be TORN DOWN by the restore
    // (absent from the snapshot we activate).
    $r = hitSave($sid, array('project_pkey' => $project_pkey, 'experiment_id' => 'SMK-V2',
                             'data' => makeSampleData('Version Sample Two', 'SMK-V2')));
    $exp2 = is_object($r['json']) ? (int)$r['json']->pkey : 0;
    $sid2 = is_object($r['json']) && !empty($r['json']->strabo_id) ? $r['json']->strabo_id : null;
    if ($sid2) $spine_ids[] = $sid2;
    check('setup: second experiment created', $exp2 > 0 && $sid2 !== null);
    $countBefore = versionsCount($db, $PROJ_UUID); // 3

    $r = httpGet($sid, "/experimental/api/activate_version.php?p=" . (int)$vRestore->pkey);
    check('restore: HTTP 302 to /my_experimental_data',
        $r['status'] === 302 && $r['location'] === '/my_experimental_data');
    check('restore: current state snapshotted first', versionsCount($db, $PROJ_UUID) === $countBefore + 1);

    $newProj = $db->get_row_prepared(
        "SELECT pkey FROM straboexp.project WHERE uuid = $1 AND userpkey = $2", array($PROJ_UUID, $userpkey));
    check('restore: project exists after restore', $newProj && !empty($newProj->pkey));
    $project_pkey = $newProj ? (int)$newProj->pkey : $project_pkey; // pkey changes on restore

    $exps = $db->get_results_prepared(
        "SELECT pkey, id FROM straboexp.experiment WHERE project_pkey = $1", array($project_pkey));
    $exps = (array)$exps;
    check('restore: exactly the snapshot experiment set', count($exps) === 1 && $exps[0]->id === 'SMK-V1');

    $srow = $db->get_row_prepared(
        "SELECT strabo_id FROM straboexp.sample WHERE experiment_pkey = $1",
        array($exps ? (int)$exps[0]->pkey : 0));
    check('restore: sample row back on the SAME strabo_id', $srow && $srow->strabo_id === $sid1);

    if ($SPINE) {
        $marker = $db->get_var_prepared(
            "SELECT custom_data ->> '_versioning_smoke' FROM strabosamples.samples
             WHERE id = $1 AND userpkey = $2", array($sid1, $userpkey));
        check('restore: spine row CONTINUITY (custom_data survived)', $marker === 'marker');
        $n = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            array($sid2, $userpkey));
        check('restore: absent-from-snapshot spine row torn down', $n === 0);
    }

    // ---- Part 4: versioning.php page ------------------------------------
    echo "\n== page ==\n";
    $r = httpGet($sid, "/versioning.php");
    check('page: renders StraboExperimental block',
        $r['status'] === 200 && strpos($r['body'], 'StraboExperimental Data:') !== false);
    check('page: links point at new endpoints',
        strpos($r['body'], 'experimental/api/activate_version.php?p=') !== false
        && strpos($r['body'], 'experimental/api/delete_version.php?p=') !== false);

    // ---- Part 5: junk snapshot sample does not resurrect ----------------
    echo "\n== junk ==\n";
    $junkData = makeSampleData('ignored', 'SMK-JUNK');
    $junkData['sample'] = array('strabo_id' => $sid1); // contentless junk (07-29 window shape)
    $junkExp = (object)$junkData;
    $junkExp->created_timestamp = null;
    $junkExp->modified_timestamp = null;
    $junkExp->experimentid = 'SMK-JUNK';
    $junkVer = array(
        'project' => array('userpkey' => $userpkey, 'uuid' => $PROJ_UUID,
            'created_timestamp' => null, 'name' => 'SMOKE versioning', 'notes' => null, 'ispublic' => 'f'),
        'experiments' => array($junkExp),
    );
    $vjPkey = (int)$db->get_var("SELECT nextval('straboexp.versions_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.versions (pkey, uuid, userpkey, projectname, experimentcount, json)
         VALUES ($1, $2, $3, $4, $5, $6)",
        array($vjPkey, $PROJ_UUID, $userpkey, 'SMOKE versioning', 1, json_encode($junkVer)));

    $r = httpGet($sid, "/experimental/api/activate_version.php?p=$vjPkey");
    check('junk restore: HTTP 302', $r['status'] === 302);
    $newProj = $db->get_row_prepared(
        "SELECT pkey FROM straboexp.project WHERE uuid = $1 AND userpkey = $2", array($PROJ_UUID, $userpkey));
    $project_pkey = $newProj ? (int)$newProj->pkey : $project_pkey;
    $nExp = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.experiment WHERE project_pkey = $1", array($project_pkey));
    check('junk restore: experiment restored', $nExp === 1);
    $nSample = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.sample WHERE experiment_pkey IN
           (SELECT pkey FROM straboexp.experiment WHERE project_pkey = $1)", array($project_pkey));
    check('junk restore: NO sample rows resurrected', $nSample === 0);
    if ($SPINE) {
        $n = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            array($sid1, $userpkey));
        check('junk restore: live spine row torn down (no orphan)', $n === 0);
    }

    // ---- Part 6: snapshot on delete + delete_version --------------------
    echo "\n== delete ==\n";
    $expDel = $db->get_var_prepared(
        "SELECT pkey FROM straboexp.experiment WHERE project_pkey = $1 LIMIT 1", array($project_pkey));
    $countBefore = versionsCount($db, $PROJ_UUID);
    $status = hitDeleteExp($sid, (int)$expDel);
    check('delete experiment: HTTP 200', $status === 200);
    check('delete experiment: snapshot written first', versionsCount($db, $PROJ_UUID) === $countBefore + 1);

    $countBefore = versionsCount($db, $PROJ_UUID);
    $r = httpGet($sid, "/experimental/api/delete_version.php?p=$vjPkey");
    check('delete version: HTTP 302 to /versioning',
        $r['status'] === 302 && $r['location'] === '/versioning');
    check('delete version: row removed', versionsCount($db, $PROJ_UUID) === $countBefore - 1);

    if ($other_userpkey) {
        $foreignPkey = (int)$db->get_var("SELECT nextval('straboexp.versions_pkey_seq')");
        $db->prepare_query(
            "INSERT INTO straboexp.versions (pkey, uuid, userpkey, projectname, experimentcount, json)
             VALUES ($1, $2, $3, $4, 0, '{}')",
            array($foreignPkey, $OTHER_UUID, $other_userpkey, 'SMOKE versioning other'));
        $r = httpGet($sid, "/experimental/api/activate_version.php?p=$foreignPkey");
        check('foreign version: activate 404', $r['status'] === 404);
        $r = httpGet($sid, "/experimental/api/delete_version.php?p=$foreignPkey");
        $still = (int)$db->get_var_prepared(
            "SELECT count(*) FROM straboexp.versions WHERE pkey = $1", array($foreignPkey));
        check('foreign version: delete is a no-op', $still === 1);
    }

} finally {
    $db->prepare_query(
        "DELETE FROM straboexp.sample WHERE experiment_pkey IN
           (SELECT e.pkey FROM straboexp.experiment e
             JOIN straboexp.project p ON e.project_pkey = p.pkey WHERE p.uuid = $1)", array($PROJ_UUID));
    $db->prepare_query("DELETE FROM straboexp.project WHERE uuid = $1", array($PROJ_UUID));
    $db->prepare_query("DELETE FROM straboexp.project WHERE uuid = $1", array($OTHER_UUID));
    $db->prepare_query("DELETE FROM straboexp.versions WHERE uuid = $1", array($PROJ_UUID));
    $db->prepare_query("DELETE FROM straboexp.versions WHERE uuid = $1", array($OTHER_UUID));
    if ($SPINE) {
        foreach (array_unique($spine_ids) as $sidClean) {
            $db->prepare_query("DELETE FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
                array($sidClean, $userpkey));
        }
    }
    // On search/* branches the sync hooks index these writes; the direct
    // SQL cleanup above bypasses the hooks, so sweep the index slice too.
    if ($db->get_var("SELECT to_regclass('strabosearch.item_hit')")) {
        foreach (array($PROJ_UUID, $OTHER_UUID) as $uClean) {
            $db->prepare_query(
                "DELETE FROM strabosearch.item_hit WHERE project_subsystem = 'exp' AND project_id = $1",
                array($uClean));
        }
        foreach (array_unique($spine_ids) as $sidClean) {
            $db->prepare_query(
                "DELETE FROM strabosearch.item_hit WHERE item_type = 'sample' AND item_id = $1",
                array($sidClean));
        }
    }
    @unlink($sessFile);
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S)\n" : "ALL CHECKS PASSED\n");
exit(count($failures) ? 1 : 0);
