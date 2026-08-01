<?php
/**
 * File: smoke_test_save_experiment_http.php
 * Description: HTTP-layer smoke test for /experimental/api/save_experiment.php.
 *
 *              Regression suite for the FK-ordering bug (2026-07-29): the
 *              create path ran exp_sample_sync() BEFORE inserting the
 *              experiment row, so the straboexp.sample INSERT failed on
 *              sample_exp_fkey and a PHP warning leaked into the response
 *              body ahead of the JSON — the Vue client reported "Failed to
 *              save experiment" on every successful create, and the
 *              normalized sample rows were silently never written.
 *
 *              Covers:
 *                create — response is pure JSON (no leaked warning), sample
 *                         row + children written, strabo_id consistent
 *                         between response, experiment JSON, and sample row
 *                update — strabo_id stable, no duplicate rows
 *                heal   — an experiment left broken by the bug window
 *                         (embedded strabo_id, no sample row) re-saved via
 *                         HTTP gets its row back on the SAME strabo_id
 *                guard  — a strabo_id already claimed by another sample row
 *                         is not adopted; garbage strabo_id is not adopted
 *
 *              Spine (strabosamples.*) assertions run only when the checked-
 *              out sample_sync.php writes the spine (samples/* branches), so
 *              the same file passes on develop.
 *
 *              Hermetic: sentinel project cascades experiments + samples on
 *              cleanup; spine rows removed by collected id.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/experimental/smoke_test_save_experiment_http.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

// Does this checkout mirror into the strabosamples spine?
$SPINE = strpos(file_get_contents('/srv/app/www/experimental/lib/sample_sync.php'),
                'StraboSamplesService') !== false;
echo "spine mirroring: " . ($SPINE ? "ON (samples branch)" : "OFF (develop)") . "\n";

$user = $db->get_row_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array());
$userpkey = (int)$user->pkey;

// --- session harness (same pattern as tests/strabosamples proxies) ---
$sessionDir = '/var/lib/php/sessions';
$sid  = substr(bin2hex(random_bytes(16)), 0, 26);
$sessFile = $sessionDir . '/sess_' . $sid;
file_put_contents($sessFile, 'loggedin|s:3:"yes";userpkey|i:' . $userpkey . ';LAST_ACTIVITY|i:' . time() . ';');
chmod($sessFile, 0600);
@chown($sessFile, 'www-data');
@chgrp($sessFile, 'www-data');

function hit($sid, $payload) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'sexp_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' "
         . "-H 'Content-Type: application/json' -H " . escapeshellarg('Cookie: PHPSESSID=' . $sid) . " "
         . "-X POST -d " . escapeshellarg(json_encode($payload)) . " "
         . "http://localhost/experimental/api/save_experiment.php";
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body));
}

function makeSampleData($name, $id) {
    return array(
        'facility'  => array('name' => 'Smoke Facility'),
        'apparatus' => array('type' => 'Paterson Apparatus'),
        'daq'       => new stdClass(),
        'sample'    => array(
            'name' => $name, 'igsn' => '', 'id' => $id, 'description' => 'http smoke',
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

$project_pkey = null;
$spine_ids = array();

try {
    $project_pkey = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.project (pkey, userpkey, name, uuid) VALUES ($1, $2, $3, $4)",
        array($project_pkey, $userpkey, 'SMOKE save_experiment http', 'a0000000-0000-4000-8000-00000000aaaa'));

    // ---- Part 1: create ------------------------------------------------
    echo "\n== create ==\n";
    $r = hit($sid, array('project_pkey' => $project_pkey, 'experiment_id' => 'SMK-1',
                         'data' => makeSampleData('Smoke Sample One', 'SMK-1')));
    check('create: HTTP 200', $r['status'] === 200);
    check('create: body is pure JSON (no leaked warning)', $r['json'] !== null && $r['body'][0] === '{');
    check('create: success flag', is_object($r['json']) && !empty($r['json']->success));
    $exp1 = is_object($r['json']) ? (int)$r['json']->pkey : 0;
    $sid1 = is_object($r['json']) && !empty($r['json']->strabo_id) ? $r['json']->strabo_id : null;
    check('create: strabo_id returned', $sid1 !== null);
    if ($sid1) $spine_ids[] = $sid1;

    $row = $db->get_row_prepared(
        "SELECT pkey, strabo_id FROM straboexp.sample WHERE experiment_pkey = $1", array($exp1));
    check('create: straboexp.sample row written', $row && !empty($row->pkey));
    check('create: sample row strabo_id matches response', $row && $row->strabo_id === $sid1);

    $embedded = $db->get_var_prepared(
        "SELECT json::jsonb -> 'sample' ->> 'strabo_id' FROM straboexp.experiment WHERE pkey = $1", array($exp1));
    check('create: strabo_id embedded in experiment JSON matches', $embedded === $sid1);

    if ($row && !empty($row->pkey)) {
        $nComp = (int)$db->get_var_prepared(
            "SELECT count(*) FROM straboexp.sample_composition WHERE sample_pkey = $1", array((int)$row->pkey));
        $nParam = (int)$db->get_var_prepared(
            "SELECT count(*) FROM straboexp.sample_parameter WHERE sample_pkey = $1", array((int)$row->pkey));
        check('create: composition child written', $nComp === 1);
        check('create: parameter child written', $nParam === 1);
    }
    if ($SPINE && $sid1) {
        $n = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            array($sid1, $userpkey));
        check('create: spine row written', $n === 1);
    }

    // ---- Part 2: update keeps strabo_id stable --------------------------
    echo "\n== update ==\n";
    $r = hit($sid, array('pkey' => $exp1, 'experiment_id' => 'SMK-1',
                         'data' => makeSampleData('Smoke Sample One EDITED', 'SMK-1')));
    check('update: HTTP 200 + pure JSON', $r['status'] === 200 && $r['json'] !== null && $r['body'][0] === '{');
    check('update: strabo_id unchanged', is_object($r['json']) && $r['json']->strabo_id === $sid1);
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.sample WHERE experiment_pkey = $1", array($exp1));
    check('update: still exactly one sample row', $n === 1);

    // ---- Part 3: heal a bug-window experiment ---------------------------
    // Fabricate the bug's leftover state: experiment row whose JSON carries a
    // strabo_id, but no straboexp.sample row (and, on samples branches, a
    // spine row already registered under that id).
    echo "\n== heal ==\n";
    $healId = 'b1111111-2222-4333-8444-555555555555';
    $spine_ids[] = $healId;
    $exp2 = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
    $healData = makeSampleData('Broken Window Sample', 'SMK-HEAL');
    $healData['sample']['strabo_id'] = $healId;
    $db->prepare_query(
        "INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, json, uuid)
         VALUES ($1, $2, $3, $4, $5, $6)",
        array($exp2, $project_pkey, $userpkey, 'SMK-HEAL',
              json_encode($healData), 'a0000000-0000-4000-8000-00000000bbbb'));
    if ($SPINE) {
        $db->prepare_query(
            "INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
             VALUES ($1, $2, $3, $4, $5) ON CONFLICT (id, userpkey) DO NOTHING",
            array($healId, $userpkey, 'Broken Window Sample', $userpkey, $userpkey));
    }

    $r = hit($sid, array('pkey' => $exp2, 'experiment_id' => 'SMK-HEAL', 'data' => $healData));
    check('heal: HTTP 200 + pure JSON', $r['status'] === 200 && $r['json'] !== null && $r['body'][0] === '{');
    check('heal: response reuses embedded strabo_id', is_object($r['json']) && $r['json']->strabo_id === $healId);
    $row = $db->get_row_prepared(
        "SELECT strabo_id FROM straboexp.sample WHERE experiment_pkey = $1", array($exp2));
    check('heal: sample row created on the SAME strabo_id', $row && $row->strabo_id === $healId);
    if ($SPINE) {
        $n = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            array($healId, $userpkey));
        check('heal: spine converged on one row', $n === 1);
    }

    // ---- Part 4: claimed / garbage strabo_id not adopted ----------------
    echo "\n== guard ==\n";
    $stealData = makeSampleData('Claim Thief', 'SMK-THIEF');
    $stealData['sample']['strabo_id'] = $sid1; // already claimed by exp1's row
    $r = hit($sid, array('project_pkey' => $project_pkey, 'experiment_id' => 'SMK-THIEF', 'data' => $stealData));
    $exp3 = is_object($r['json']) ? (int)$r['json']->pkey : 0;
    $sid3 = is_object($r['json']) && !empty($r['json']->strabo_id) ? $r['json']->strabo_id : null;
    if ($sid3) $spine_ids[] = $sid3;
    check('guard: claimed id not adopted — fresh mint', $sid3 !== null && $sid3 !== $sid1);
    $row = $db->get_row_prepared(
        "SELECT strabo_id FROM straboexp.sample WHERE experiment_pkey = $1", array($exp3));
    check('guard: row written under the fresh id', $row && $row->strabo_id === $sid3);
    $still = $db->get_var_prepared(
        "SELECT strabo_id FROM straboexp.sample WHERE experiment_pkey = $1", array($exp1));
    check('guard: original owner untouched', $still === $sid1);

    $junkData = makeSampleData('Junk Id', 'SMK-JUNK');
    $junkData['sample']['strabo_id'] = 'not-a-uuid-at-all';
    $r = hit($sid, array('project_pkey' => $project_pkey, 'experiment_id' => 'SMK-JUNK', 'data' => $junkData));
    $sid4 = is_object($r['json']) && !empty($r['json']->strabo_id) ? $r['json']->strabo_id : null;
    if ($sid4) $spine_ids[] = $sid4;
    check('guard: garbage id rejected — clean JSON + fresh uuid',
        $r['json'] !== null && $sid4 !== null
        && preg_match('/^[0-9a-f-]{36}$/', $sid4) === 1);

    // ---- Part 5: no sample metadata → no sample rows (2026-07-31 bug) ----
    // The Vue Add page always sends a sample key ({} when untouched); PHP's
    // empty() is false for any object, so create used to mint a junk
    // strabo_id + empty sample row + nameless spine sample for every
    // experiment saved without sample data.
    echo "\n== empty sample ==\n";

    // 5a: create with untouched sample section ({}), facility only
    $r = hit($sid, array('project_pkey' => $project_pkey, 'experiment_id' => 'SMK-EMPTY',
                         'data' => array('facility' => array('name' => 'Smoke Facility'),
                                         'apparatus' => array('type' => 'Paterson Apparatus'),
                                         'sample' => new stdClass())));
    check('empty create: HTTP 200 + pure JSON + success',
        $r['status'] === 200 && is_object($r['json']) && !empty($r['json']->success));
    $exp5 = is_object($r['json']) ? (int)$r['json']->pkey : 0;
    check('empty create: strabo_id is null in response',
        is_object($r['json']) && $r['json']->strabo_id === null);
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.sample WHERE experiment_pkey = $1", array($exp5));
    check('empty create: NO straboexp.sample row', $n === 0);
    $embedded = $db->get_var_prepared(
        "SELECT json::jsonb -> 'sample' ->> 'strabo_id' FROM straboexp.experiment WHERE pkey = $1", array($exp5));
    check('empty create: no strabo_id in stored JSON', $embedded === null);
    if ($SPINE) {
        $n = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.sample_subsystem_links
              WHERE subsystem = 'experimental' AND reference_id = $1 AND reference_userpkey = $2",
            array((string)$exp5, $userpkey));
        check('empty create: NO spine link row', $n === 0);
    }

    // 5b: skeleton of empty strings (modal opened, nothing entered) → same
    $skeleton = array('name' => '', 'igsn' => '', 'id' => '', 'description' => '',
                      'material' => array('material' => array('type' => '', 'name' => '', 'state' => '', 'note' => ''),
                                          'composition' => array()),
                      'parameters' => array());
    $r = hit($sid, array('pkey' => $exp5, 'experiment_id' => 'SMK-EMPTY',
                         'data' => array('facility' => array('name' => 'Smoke Facility'),
                                         'sample' => $skeleton)));
    check('skeleton update: HTTP 200 + strabo_id null',
        $r['status'] === 200 && is_object($r['json']) && $r['json']->strabo_id === null);
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.sample WHERE experiment_pkey = $1", array($exp5));
    check('skeleton update: still NO sample row', $n === 0);

    // 5c: heal a junk experiment left by the bug window — sample JSON is
    // just {strabo_id}, empty sample row + nameless spine row exist.
    $junkId = 'c2222222-3333-4444-8555-666666666666';
    $spine_ids[] = $junkId;
    $exp6 = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
    $junkJson = array('facility' => array('name' => 'Smoke Facility'),
                      'sample' => array('strabo_id' => $junkId));
    $db->prepare_query(
        "INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, json, uuid)
         VALUES ($1, $2, $3, $4, $5, $6)",
        array($exp6, $project_pkey, $userpkey, 'SMK-JUNKROW',
              json_encode($junkJson), 'a0000000-0000-4000-8000-00000000cccc'));
    $junkSamplePkey = (int)$db->get_var("SELECT nextval('straboexp.sample_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.sample (pkey, experiment_pkey, userpkey, name, strabo_id, json)
         VALUES ($1, $2, $3, '', $4, '{}')",
        array($junkSamplePkey, $exp6, $userpkey, $junkId));
    if ($SPINE) {
        $db->prepare_query(
            "INSERT INTO strabosamples.samples (id, userpkey, experimental_data, created_by, modified_by)
             VALUES ($1, $2, '{}'::jsonb, $3, $4) ON CONFLICT (id, userpkey) DO NOTHING",
            array($junkId, $userpkey, $userpkey, $userpkey));
        $db->prepare_query(
            "INSERT INTO strabosamples.sample_subsystem_links
               (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey)
             VALUES ($1, $2, 'experimental', $3, $4)
             ON CONFLICT DO NOTHING",
            array($junkId, $userpkey, (string)$exp6, $userpkey));
    }

    $r = hit($sid, array('pkey' => $exp6, 'experiment_id' => 'SMK-JUNKROW', 'data' => $junkJson));
    check('junk heal: HTTP 200 + strabo_id null',
        $r['status'] === 200 && is_object($r['json']) && $r['json']->strabo_id === null);
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.sample WHERE experiment_pkey = $1", array($exp6));
    check('junk heal: empty sample row removed', $n === 0);
    $embedded = $db->get_var_prepared(
        "SELECT json::jsonb -> 'sample' ->> 'strabo_id' FROM straboexp.experiment WHERE pkey = $1", array($exp6));
    check('junk heal: stale strabo_id stripped from stored JSON', $embedded === null);
    if ($SPINE) {
        $n = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            array($junkId, $userpkey));
        check('junk heal: junk spine row removed', $n === 0);
    }

    // 5d: clearing a REAL sample via skeleton removes its rows
    $r = hit($sid, array('pkey' => $exp1, 'experiment_id' => 'SMK-1',
                         'data' => array('facility' => array('name' => 'Smoke Facility'),
                                         'sample' => $skeleton)));
    check('clear: HTTP 200 + strabo_id null',
        $r['status'] === 200 && is_object($r['json']) && $r['json']->strabo_id === null);
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.sample WHERE experiment_pkey = $1", array($exp1));
    check('clear: sample row removed', $n === 0);
    if ($SPINE) {
        $n = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            array($sid1, $userpkey));
        check('clear: spine row removed', $n === 0);
    }

} finally {
    if ($project_pkey) {
        $db->prepare_query(
            "DELETE FROM straboexp.sample WHERE experiment_pkey IN
               (SELECT pkey FROM straboexp.experiment WHERE project_pkey = $1)", array($project_pkey));
        $db->prepare_query("DELETE FROM straboexp.experiment WHERE project_pkey = $1", array($project_pkey));
        $db->prepare_query("DELETE FROM straboexp.project WHERE pkey = $1", array($project_pkey));
        // save_experiment snapshots a project version before create/update
        // (versioning restoration) — remove the sentinel project's snapshots.
        $db->prepare_query("DELETE FROM straboexp.versions WHERE uuid = $1",
            array('a0000000-0000-4000-8000-00000000aaaa'));
        // On search/* branches the sync hooks index these writes; the direct
        // SQL cleanup above bypasses the hooks, so sweep the index slice too.
        if ($db->get_var("SELECT to_regclass('strabosearch.item_hit')")) {
            $db->prepare_query(
                "DELETE FROM strabosearch.item_hit WHERE project_subsystem = 'exp' AND project_id = $1",
                array('a0000000-0000-4000-8000-00000000aaaa'));
            foreach (array_unique($spine_ids) as $sidClean) {
                $db->prepare_query(
                    "DELETE FROM strabosearch.item_hit WHERE item_type = 'sample' AND item_id = $1",
                    array($sidClean));
            }
        }
        if ($SPINE) {
            foreach (array_unique($spine_ids) as $sidClean) {
                $db->prepare_query("DELETE FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
                    array($sidClean, $userpkey));
            }
        }
    }
    @unlink($sessFile);
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S)\n" : "ALL CHECKS PASSED\n");
exit(count($failures) ? 1 : 0);
