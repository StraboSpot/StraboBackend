<?php
/**
 * File: smoke_test_exp_public_anonymous.php
 * Description: HTTP-layer smoke test for anonymous access to PUBLIC
 *              StraboExperimental read paths (relaxed 2026-08-05: the
 *              blanket login gates in front of already-public-aware
 *              access-control SQL were replaced by soft session
 *              resolution — softlogincheck.php / no-user sentinel).
 *
 *              Covers, per audience (anonymous / owner / stranger / expired):
 *                pages — /epl/{uuid}, download_experiment.php,
 *                        download_project.php, overview_experiment.php,
 *                        overview_project.php
 *                api   — get_experiment, get_project, download_experiment,
 *                        download_pdf, download_project, download_project_pdf
 *                docs  — api/view_document.php (public-by-obscurity, /i/ parity)
 *                gates — every write path still rejects anonymous
 *                        (401 or redirect to login)
 *
 *              Positive: anonymous sees PUBLIC project data, read-only flags.
 *              Negative: anonymous/stranger never see PRIVATE data; expired
 *              sessions are downgraded to anonymous; owner unaffected.
 *
 *              Hermetic: sentinel projects/experiments inserted with explicit
 *              nextval pkeys (no lastval, no versions/spine side effects),
 *              cleaned up by uuid in finally. Document fixture file removed.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/experimental/smoke_test_exp_public_anonymous.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$PROJ_PUB_UUID  = 'ab000000-0000-4000-8000-0000000000aa';
$PROJ_PRIV_UUID = 'ab000000-0000-4000-8000-0000000000bb';
$EXP1_UUID      = 'ab000000-0000-4000-8000-0000000000a1';
$EXP2_UUID      = 'ab000000-0000-4000-8000-0000000000a2';
$EXP_PRIV_UUID  = 'ab000000-0000-4000-8000-0000000000b1';
$DOC_UUID       = 'ab000000-0000-4000-8000-0000000000d0';
$DOC_PATH       = '/srv/app/www/experimental/expimages/' . $DOC_UUID;
$DOC_BODY       = "anonymous-access smoke document fixture\n";

// Both fixture users must be NON-admins — admins legitimately bypass the
// owner-or-public access control, which would fake stranger failures.
require '/srv/app/www/adminkeys.php';
$admin_list = implode(',', array_map('intval', $admin_pkeys));
$user = $db->get_row_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE
     AND pkey NOT IN ($admin_list) ORDER BY pkey LIMIT 1", array());
$userpkey = (int)$user->pkey;
$other = $db->get_row_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE
     AND pkey NOT IN ($admin_list) AND pkey <> $1 ORDER BY pkey LIMIT 1",
    array($userpkey));
$other_userpkey = $other ? (int)$other->pkey : 0;

// --- session harness (same pattern as smoke_test_versioning_http) --------
$sessionDir = '/var/lib/php/sessions';
$forgedSessions = array();
function forgeSession($userpkey, $lastActivity) {
    global $sessionDir, $forgedSessions;
    $sid = substr(bin2hex(random_bytes(16)), 0, 26);
    $sessFile = $sessionDir . '/sess_' . $sid;
    file_put_contents($sessFile,
        'loggedin|s:3:"yes";userpkey|i:' . $userpkey . ';LAST_ACTIVITY|i:' . $lastActivity . ';');
    chmod($sessFile, 0600);
    @chown($sessFile, 'www-data');
    @chgrp($sessFile, 'www-data');
    $forgedSessions[] = $sessFile;
    return $sid;
}
$sidOwner    = forgeSession($userpkey, time());
$sidStranger = $other_userpkey ? forgeSession($other_userpkey, time()) : null;
$sidExpired  = forgeSession($userpkey, time() - 8000); // > 7200s ago -> downgrade

function httpGet($sid, $path) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'anon_body_');
    $hdrFile  = tempnam(sys_get_temp_dir(), 'anon_hdr_');
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

function httpPost($sid, $path, $payload = null) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'anon_post_');
    $hdrFile  = tempnam(sys_get_temp_dir(), 'anon_phdr_');
    $cookie = $sid !== null ? "-H " . escapeshellarg('Cookie: PHPSESSID=' . $sid) . " " : "";
    $data = $payload !== null
        ? "-H 'Content-Type: application/json' -d " . escapeshellarg(json_encode($payload)) . " "
        : "";
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -D " . escapeshellarg($hdrFile)
         . " -w '%{http_code}' -X POST $cookie$data" . escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec($cmd);
    $hdrs = file_get_contents($hdrFile);
    @unlink($bodyFile); @unlink($hdrFile);
    $loc = null;
    if (preg_match('/^Location:\s*(\S+)/mi', $hdrs, $m)) $loc = $m[1];
    return array('status' => $status, 'location' => $loc);
}

function expJson($title) {
    // No 'sample' block on purpose: keeps the fixture out of the samples spine.
    return json_encode(array(
        'facility'   => array('name' => 'Anon Smoke Facility'),
        'apparatus'  => array('type' => 'Paterson Apparatus'),
        'experiment' => array('title' => $title, 'description' => 'anon-access smoke'),
    ));
}

$proj_pub = null; $proj_priv = null; $doc_holding = null;

try {
    // ---- fixtures --------------------------------------------------------
    $proj_pub = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.project (pkey, userpkey, name, uuid, ispublic) VALUES ($1, $2, $3, $4, 't')",
        array($proj_pub, $userpkey, 'SMOKE anon public', $PROJ_PUB_UUID));
    $proj_priv = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.project (pkey, userpkey, name, uuid, ispublic) VALUES ($1, $2, $3, $4, 'f')",
        array($proj_priv, $userpkey, 'SMOKE anon private', $PROJ_PRIV_UUID));

    $exp1 = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, uuid, json) VALUES ($1, $2, $3, $4, $5, $6)",
        array($exp1, $proj_pub, $userpkey, 'ANON-1', $EXP1_UUID, expJson('Anon Smoke One')));
    $exp2 = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, uuid, json) VALUES ($1, $2, $3, $4, $5, $6)",
        array($exp2, $proj_pub, $userpkey, 'ANON-2', $EXP2_UUID, expJson('Anon Smoke Two')));
    $expPriv = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, uuid, json) VALUES ($1, $2, $3, $4, $5, $6)",
        array($expPriv, $proj_priv, $userpkey, 'ANON-P', $EXP_PRIV_UUID, expJson('Anon Smoke Private')));

    file_put_contents($DOC_PATH, $DOC_BODY);
    $doc_holding = (int)$db->get_var("SELECT nextval('straboexp.file_holdings_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.file_holdings (pkey, userpkey, uuid, original_filename) VALUES ($1, $2, $3, $4)",
        array($doc_holding, $userpkey, $DOC_UUID, 'anon_smoke.txt'));

    // ---- Part 1: anonymous reads of PUBLIC data --------------------------
    echo "\n== anonymous / public ==\n";
    $r = httpGet(null, "/epl/$PROJ_PUB_UUID");
    check('epl landing: HTTP 200, no redirect',
        $r['status'] === 200 && $r['location'] === null);
    check('epl landing: lists both experiments',
        strpos($r['body'], 'ANON-1') !== false && strpos($r['body'], 'ANON-2') !== false);

    $r = httpGet(null, "/experimental/download_experiment.php?u=$EXP1_UUID");
    check('download_experiment page: HTTP 200 with title',
        $r['status'] === 200 && strpos($r['body'], 'Anon Smoke One') !== false);
    $r = httpGet(null, "/experimental/download_project.php?u=$PROJ_PUB_UUID");
    check('download_project page: HTTP 200 with project name',
        $r['status'] === 200 && strpos($r['body'], 'SMOKE anon public') !== false);
    $r = httpGet(null, "/experimental/overview_experiment.php?u=$EXP1_UUID");
    check('overview_experiment page: HTTP 200', $r['status'] === 200);
    $r = httpGet(null, "/experimental/overview_project.php?u=$PROJ_PUB_UUID");
    check('overview_project page: HTTP 200', $r['status'] === 200);

    $r = httpGet(null, "/experimental/api/get_experiment.php?id=$exp1");
    check('api get_experiment: HTTP 200 JSON',
        $r['status'] === 200 && is_object($r['json']) && (int)$r['json']->pkey === $exp1);
    check('api get_experiment: read-only flags for anonymous',
        is_object($r['json']) && $r['json']->is_owner === false
        && $r['json']->can_edit === false && $r['json']->can_delete === false
        && $r['json']->project_is_public === true);
    $r = httpGet(null, "/experimental/api/get_project.php?id=$proj_pub");
    check('api get_project: HTTP 200 JSON', $r['status'] === 200 && is_object($r['json']));

    $r = httpGet(null, "/experimental/api/download_experiment.php?id=$exp1");
    check('api download_experiment: HTTP 200 with experiment payload',
        $r['status'] === 200 && is_object($r['json']) && $r['json']->experiment_id === 'ANON-1');
    $r = httpGet(null, "/experimental/api/download_pdf.php?id=$exp1");
    check('api download_pdf: HTTP 200 PDF',
        $r['status'] === 200 && strpos($r['body'], '%PDF') === 0);
    $r = httpGet(null, "/experimental/api/download_project.php?id=$proj_pub");
    check('api download_project: HTTP 200', $r['status'] === 200);
    $r = httpGet(null, "/experimental/api/download_project_pdf.php?id=$proj_pub");
    check('api download_project_pdf: HTTP 200 PDF',
        $r['status'] === 200 && strpos($r['body'], '%PDF') === 0);

    $r = httpGet(null, "/experimental/view_experiment?e=$exp1");
    check('SPA viewer shell: HTTP 200, no redirect',
        $r['status'] === 200 && $r['location'] === null
        && strpos($r['body'], 'vue-app') !== false);

    // ---- Part 2: documents (public-by-obscurity, /i/ parity) -------------
    echo "\n== anonymous / documents ==\n";
    $r = httpGet(null, "/experimental/api/view_document.php?uuid=$DOC_UUID&filename=anon_smoke.txt");
    check('view_document: HTTP 200 serves registered upload',
        $r['status'] === 200 && $r['body'] === $DOC_BODY);
    $r = httpGet(null, "/experimental/api/view_document.php?uuid=00000000-0000-4000-8000-000000000000&filename=x");
    check('view_document: unknown uuid 404', $r['status'] === 404);
    $r = httpGet(null, "/i/$DOC_UUID/anon_smoke.txt");
    check('legacy /i/ parity: HTTP 200 same bytes',
        $r['status'] === 200 && $r['body'] === $DOC_BODY);

    // ---- Part 3: anonymous NEVER sees PRIVATE data -----------------------
    echo "\n== anonymous / private (negative) ==\n";
    $r = httpGet(null, "/epl/$PROJ_PRIV_UUID");
    check('epl landing private: Project Not Found',
        $r['status'] === 200 && strpos($r['body'], 'Project Not Found') !== false
        && strpos($r['body'], 'ANON-P') === false);
    $r = httpGet(null, "/experimental/download_experiment.php?u=$EXP_PRIV_UUID");
    check('download_experiment page private: permission message, no data',
        $r['status'] === 200 && strpos($r['body'], 'not found or you do not have permission') !== false
        && strpos($r['body'], 'Anon Smoke Private') === false);
    foreach (array(
        "api get_experiment private" => "/experimental/api/get_experiment.php?id=$expPriv",
        "api get_project private" => "/experimental/api/get_project.php?id=$proj_priv",
        "api download_experiment private" => "/experimental/api/download_experiment.php?id=$expPriv",
        "api download_pdf private" => "/experimental/api/download_pdf.php?id=$expPriv",
        "api download_project private" => "/experimental/api/download_project.php?id=$proj_priv",
        "api download_project_pdf private" => "/experimental/api/download_project_pdf.php?id=$proj_priv",
    ) as $label => $path) {
        $r = httpGet(null, $path);
        check("$label: 404", $r['status'] === 404);
    }

    // ---- Part 4: write paths still reject anonymous ----------------------
    echo "\n== anonymous / write gates ==\n";
    $r = httpPost(null, "/experimental/api/save_experiment.php", array('project_pkey' => $proj_pub));
    check('save_experiment: 401', $r['status'] === 401);
    $r = httpPost(null, "/experimental/api/save_project.php", array('name' => 'x'));
    check('save_project: 401', $r['status'] === 401);
    $r = httpPost(null, "/experimental/api/delete_experiment.php?id=$exp1");
    check('delete_experiment: 401', $r['status'] === 401);
    $r = httpPost(null, "/experimental/api/delete_project.php?id=$proj_pub");
    check('delete_project: 401', $r['status'] === 401);
    $r = httpGet(null, "/experimental/api/project_public.php?projectid=$proj_pub&state=private");
    check('project_public toggle: 401', $r['status'] === 401);
    $flag = $db->get_var_prepared("SELECT ispublic FROM straboexp.project WHERE pkey = $1", array($proj_pub));
    check('project_public toggle: flag untouched', $flag === 't');
    $r = httpGet(null, "/experimental/api/activate_version.php?p=1");
    check('activate_version: redirected to login', $r['status'] === 302 && $r['location'] === '/login');
    $r = httpGet(null, "/experimental/api/delete_version.php?p=1");
    check('delete_version: redirected to login', $r['status'] === 302 && $r['location'] === '/login');
    $r = httpPost(null, "/experimental/api/upload_document.php");
    check('upload_document: redirected to login',
        $r['status'] === 302 && $r['location'] !== null && strpos($r['location'], '/login') === 0);

    // ---- Part 5: owner / stranger / expired regressions ------------------
    echo "\n== owner / stranger / expired ==\n";
    $r = httpGet($sidOwner, "/experimental/api/get_experiment.php?id=$expPriv");
    check('owner sees own private experiment, can_edit',
        $r['status'] === 200 && is_object($r['json']) && $r['json']->can_edit === true);
    $r = httpGet($sidOwner, "/experimental/download_experiment.php?u=$EXP_PRIV_UUID");
    check('owner download page private: HTTP 200 with title',
        $r['status'] === 200 && strpos($r['body'], 'Anon Smoke Private') !== false);
    if ($sidStranger) {
        $r = httpGet($sidStranger, "/experimental/api/get_experiment.php?id=$expPriv");
        check('stranger blocked from private: 404', $r['status'] === 404);
        $r = httpGet($sidStranger, "/experimental/api/get_experiment.php?id=$exp1");
        check('stranger sees public read-only',
            $r['status'] === 200 && is_object($r['json']) && $r['json']->can_edit === false);
    } else {
        echo "  SKIP  no second user on this instance (stranger checks)\n";
    }
    $r = httpGet($sidExpired, "/experimental/api/get_experiment.php?id=$expPriv");
    check('expired session downgraded: private 404', $r['status'] === 404);
    $sidExpired2 = forgeSession($userpkey, time() - 8000);
    $r = httpGet($sidExpired2, "/experimental/api/get_experiment.php?id=$exp1");
    check('expired session downgraded: public still served read-only',
        $r['status'] === 200 && is_object($r['json']) && $r['json']->can_edit === false);

    // hard gate untouched for private-only pages
    $r = httpGet(null, "/my_experimental_data");
    check('logincheck pages still redirect anonymous to login',
        $r['status'] === 302 && $r['location'] !== null && strpos($r['location'], '/login') === 0);

} finally {
    // ---- cleanup (experiments cascade on project delete) -----------------
    $db->prepare_query("DELETE FROM straboexp.project WHERE uuid IN ($1, $2)",
        array($PROJ_PUB_UUID, $PROJ_PRIV_UUID));
    $db->prepare_query("DELETE FROM straboexp.file_holdings WHERE uuid = $1", array($DOC_UUID));
    @unlink($DOC_PATH);
    foreach ($forgedSessions as $f) @unlink($f);
    // residue check
    $left = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.project WHERE uuid IN ($1, $2)",
        array($PROJ_PUB_UUID, $PROJ_PRIV_UUID))
        + (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.experiment WHERE uuid IN ($1, $2, $3)",
        array($EXP1_UUID, $EXP2_UUID, $EXP_PRIV_UUID))
        + (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.file_holdings WHERE uuid = $1", array($DOC_UUID));
    echo "\nresidue rows after cleanup: $left" . ($left === 0 ? " (clean)" : " (LEFTOVER!)") . "\n";
    if ($left !== 0) $failures[] = 'cleanup residue';
}

echo "\n" . (count($failures) === 0
    ? "ALL CHECKS PASSED\n"
    : count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) . "\n");
exit(count($failures) === 0 ? 0 : 1);
