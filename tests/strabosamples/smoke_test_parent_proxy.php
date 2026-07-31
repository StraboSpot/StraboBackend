<?php
/**
 * File: smoke_test_parent_proxy.php
 * Description: HTTP-layer smoke test for /samples_parent.php — the
 *              session-authed proxy behind the Edit Metadata modal's
 *              parent picker. Covers all three actions:
 *
 *                list  — candidate shape, excludes the target sample,
 *                        respects the caller's visibility predicate
 *                set   — happy path, re-parent, cycle rejection (deep +
 *                        self), inaccessible parent, pair-required,
 *                        collaborator-role gates, changelog entry
 *                clear — happy path, idempotent no-op, changelog entry
 *
 *              plus auth + error-to-status mapping. Same chown'd
 *              www-data session-file harness as the other strabosamples
 *              proxy smoke tests. Hermetic: sentinel ids cleaned up in
 *              finally (changelog rows cascade with the samples).
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/strabosamples/smoke_test_parent_proxy.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

$users = $db->get_results_prepared(
    "SELECT pkey, email FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 4", array()
);
if (!is_array($users) || count($users) < 4) {
    echo "Cannot find 4 distinct test users.\n";
    exit(1);
}
$ownerPkey    = (int)$users[0]->pkey;
$editorPkey   = (int)$users[1]->pkey;
$readerPkey   = (int)$users[2]->pkey;
$strangerPkey = (int)$users[3]->pkey;
echo "owner=$ownerPkey  editor=$editorPkey  reader=$readerPkey  stranger=$strangerPkey\n\n";

$sessionDir = '/var/lib/php/sessions';
function writeSession($dir, $pkey) {
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = $dir . '/sess_' . $sid;
    $body = 'loggedin|s:3:"yes";userpkey|i:' . $pkey . ';LAST_ACTIVITY|i:' . time() . ';';
    file_put_contents($path, $body);
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    return array($sid, $path);
}

list($ownerSid,    $ownerSessionFile)    = writeSession($sessionDir, $ownerPkey);
list($editorSid,   $editorSessionFile)   = writeSession($sessionDir, $editorPkey);
list($readerSid,   $readerSessionFile)   = writeSession($sessionDir, $readerPkey);
list($strangerSid, $strangerSessionFile) = writeSession($sessionDir, $strangerPkey);

function hit($sid, $jsonBody, $url = 'http://localhost/samples_parent.php') {
    $headers = array('Content-Type: application/json');
    if ($sid !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $sid;
    }
    $hdr = '';
    foreach ($headers as $h) { $hdr .= '-H ' . escapeshellarg($h) . ' '; }
    $payload = $jsonBody === null ? '' : ('-d ' . escapeshellarg($jsonBody));
    $bodyFile = tempnam(sys_get_temp_dir(), 'par_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . $hdr . " -X POST $payload " . escapeshellarg($url);
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    $json = json_decode($body, true);
    return array('status' => $status, 'body' => $body, 'json' => $json);
}

function dbParent($db, $id, $upk) {
    $row = $db->get_row_prepared(
        "SELECT parent_sample_id, parent_userpkey FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($id, $upk)
    );
    if (!$row) return array(null, null);
    return array($row->parent_sample_id, $row->parent_userpkey === null ? null : (int)$row->parent_userpkey);
}

function changelogCount($db, $id, $upk, $type) {
    return (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type=$3",
        array($id, $upk, $type)
    );
}

$stamp = time();
$idA        = 'smoke-parentproxy-A-'       . $stamp;   // top of the chain
$idB        = 'smoke-parentproxy-B-'       . $stamp;   // middle (collab grants live here)
$idC        = 'smoke-parentproxy-C-'       . $stamp;   // bottom of the chain
$idEditorS  = 'smoke-parentproxy-editor-'  . $stamp;   // editor-owned candidate parent
$idPrivate  = 'smoke-parentproxy-private-' . $stamp;   // stranger-owned, no grants

$svc = new StraboSamplesService($db, $neodb);

try {
    // ---- Fixtures. A, B, C owner-owned, initially parentless. Editor
    // owns a sample of their own; stranger owns a private one.
    $svc->setUserpkey($ownerPkey);
    $svc->createSample(array('id' => $idA, 'name' => 'PP-Top'));
    $svc->createSample(array('id' => $idB, 'name' => 'PP-Mid'));
    $svc->createSample(array('id' => $idC, 'name' => 'PP-Bottom'));
    $svc->setUserpkey($editorPkey);
    $svc->createSample(array('id' => $idEditorS, 'name' => 'PP-EditorOwn'));
    $svc->setUserpkey($strangerPkey);
    $svc->createSample(array('id' => $idPrivate, 'name' => 'PP-Private'));

    // Accepted edit + readonly grants on B.
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
        array($idB, $ownerPkey, $editorPkey, bin2hex(random_bytes(8)))
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'readonly', $4, TRUE, now(), $2)",
        array($idB, $ownerPkey, $readerPkey, bin2hex(random_bytes(8)))
    );

    echo "=== Unauthenticated request ===\n";
    $r = hit(null, json_encode(array('action' => 'set', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey)));
    check('status = 401',                       $r['status'] === 401);
    check('error = not_authenticated',           isset($r['json']['error']) && $r['json']['error'] === 'not_authenticated');

    echo "\n=== Invalid JSON ===\n";
    $r = hit($ownerSid, '{nope');
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_json',                isset($r['json']['error']) && $r['json']['error'] === 'invalid_json');

    echo "\n=== Missing sample_id ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'list', 'owner_pkey' => $ownerPkey)));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_required_fields',     isset($r['json']['error']) && $r['json']['error'] === 'missing_required_fields');

    echo "\n=== Unknown action ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'promote', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey)));
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_action',              isset($r['json']['error']) && $r['json']['error'] === 'invalid_action');

    echo "\n=== list: candidates exclude the target + invisible samples ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'list', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    $candIds = array();
    if (isset($r['json']['candidates']) && is_array($r['json']['candidates'])) {
        foreach ($r['json']['candidates'] as $c) { $candIds[] = $c['id']; }
    }
    check('A is a candidate',                    in_array($idA, $candIds, true));
    check('C is a candidate',                    in_array($idC, $candIds, true));
    check('target B excluded',                   !in_array($idB, $candIds, true));
    check('stranger private sample invisible',   !in_array($idPrivate, $candIds, true));
    $firstCand = isset($r['json']['candidates'][0]) ? $r['json']['candidates'][0] : array();
    check('candidate row shape (id/userpkey/name/display_sample_type)',
        array_key_exists('id', $firstCand) && array_key_exists('userpkey', $firstCand)
        && array_key_exists('name', $firstCand) && array_key_exists('display_sample_type', $firstCand));

    echo "\n=== set: owner sets B.parent = A ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'set', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey,
                                          'parent_sample_id' => $idA, 'parent_userpkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    list($pId, $pUk) = dbParent($db, $idB, $ownerPkey);
    check('DB parent = A',                       $pId === $idA && $pUk === $ownerPkey);
    check('changelog has parent_set',            changelogCount($db, $idB, $ownerPkey, 'parent_set') === 1);

    echo "\n=== set: owner extends chain C.parent = B ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'set', 'sample_id' => $idC, 'owner_pkey' => $ownerPkey,
                                          'parent_sample_id' => $idB, 'parent_userpkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);

    echo "\n=== set: cycle A.parent = C rejected (A -> B -> C chain) ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'set', 'sample_id' => $idA, 'owner_pkey' => $ownerPkey,
                                          'parent_sample_id' => $idC, 'parent_userpkey' => $ownerPkey)));
    check('status = 409',                       $r['status'] === 409);
    check('error = cycle_detected',              isset($r['json']['error']) && $r['json']['error'] === 'cycle_detected');
    list($pId, $pUk) = dbParent($db, $idA, $ownerPkey);
    check('A parent unchanged (null)',           $pId === null);

    echo "\n=== set: self-parent A.parent = A rejected ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'set', 'sample_id' => $idA, 'owner_pkey' => $ownerPkey,
                                          'parent_sample_id' => $idA, 'parent_userpkey' => $ownerPkey)));
    check('status = 409',                       $r['status'] === 409);
    check('error = cycle_detected',              isset($r['json']['error']) && $r['json']['error'] === 'cycle_detected');

    echo "\n=== set: inaccessible parent rejected ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'set', 'sample_id' => $idA, 'owner_pkey' => $ownerPkey,
                                          'parent_sample_id' => $idPrivate, 'parent_userpkey' => $strangerPkey)));
    check('status = 403',                       $r['status'] === 403);
    check('error = parent_not_accessible',       isset($r['json']['error']) && $r['json']['error'] === 'parent_not_accessible');

    echo "\n=== set: missing parent pair rejected ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'set', 'sample_id' => $idA, 'owner_pkey' => $ownerPkey)));
    check('status = 400',                       $r['status'] === 400);
    check('error = parent_pair_required',        isset($r['json']['error']) && $r['json']['error'] === 'parent_pair_required');

    echo "\n=== set: edit collaborator re-parents B to their own sample ===\n";
    $r = hit($editorSid, json_encode(array('action' => 'set', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey,
                                           'parent_sample_id' => $idEditorS, 'parent_userpkey' => $editorPkey)));
    check('status = 200',                       $r['status'] === 200);
    list($pId, $pUk) = dbParent($db, $idB, $ownerPkey);
    check('DB parent = editor-owned sample',     $pId === $idEditorS && $pUk === $editorPkey);

    echo "\n=== set: owner re-parents B back to A (change, not create) ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'set', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey,
                                          'parent_sample_id' => $idA, 'parent_userpkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    list($pId, $pUk) = dbParent($db, $idB, $ownerPkey);
    check('DB parent back to A',                 $pId === $idA && $pUk === $ownerPkey);

    echo "\n=== set: readonly collaborator forbidden ===\n";
    $r = hit($readerSid, json_encode(array('action' => 'set', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey,
                                           'parent_sample_id' => $idA, 'parent_userpkey' => $ownerPkey)));
    check('status = 403',                       $r['status'] === 403);
    check('error = forbidden',                   isset($r['json']['error']) && $r['json']['error'] === 'forbidden');

    echo "\n=== set: stranger forbidden ===\n";
    $r = hit($strangerSid, json_encode(array('action' => 'set', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey,
                                             'parent_sample_id' => $idPrivate, 'parent_userpkey' => $strangerPkey)));
    check('status = 403',                       $r['status'] === 403);
    check('error = forbidden',                   isset($r['json']['error']) && $r['json']['error'] === 'forbidden');

    echo "\n=== set: nonexistent sample 404 ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'set', 'sample_id' => 'no-such-' . $stamp, 'owner_pkey' => $ownerPkey,
                                          'parent_sample_id' => $idA, 'parent_userpkey' => $ownerPkey)));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== clear: owner clears B.parent ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'clear', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    list($pId, $pUk) = dbParent($db, $idB, $ownerPkey);
    check('DB parent cleared',                   $pId === null && $pUk === null);
    check('changelog has parent_clear',          changelogCount($db, $idB, $ownerPkey, 'parent_clear') === 1);

    echo "\n=== clear: idempotent no-op still ok, no extra changelog ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'clear', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    check('changelog parent_clear still = 1',    changelogCount($db, $idB, $ownerPkey, 'parent_clear') === 1);

    echo "\n=== clear: readonly collaborator forbidden ===\n";
    $r = hit($readerSid, json_encode(array('action' => 'clear', 'sample_id' => $idB, 'owner_pkey' => $ownerPkey)));
    check('status = 403',                       $r['status'] === 403);
    check('error = forbidden',                   isset($r['json']['error']) && $r['json']['error'] === 'forbidden');

} finally {
    $db->prepare_query(
        "DELETE FROM strabosamples.sample_collaborators WHERE sample_id=$1 AND sample_userpkey=$2",
        array($idB, $ownerPkey)
    );
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id IN ($1, $2, $3) AND userpkey=$4",
        array($idA, $idB, $idC, $ownerPkey)
    );
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($idEditorS, $editorPkey)
    );
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($idPrivate, $strangerPkey)
    );
    foreach (array($ownerSessionFile, $editorSessionFile, $readerSessionFile, $strangerSessionFile) as $f) {
        @unlink($f);
    }
}

echo "\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
