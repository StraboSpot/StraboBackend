<?php
/**
 * File: smoke_test_family_proxy.php
 * Description: HTTP-layer smoke test for /samples_family.php — the
 *              session-authed proxy that powers the mini-explorer
 *              mode of the Sample Overview family-tree widget.
 *              Covers happy path + auth + error-to-status mapping +
 *              canRead gate + orphaned-parent shape passthrough.
 *
 *              Same chown'd www-data session-file harness as the
 *              other strabosamples proxy smoke tests.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/strabosamples/smoke_test_family_proxy.php
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

function hit($sid, $jsonBody, $url = 'http://localhost/samples_family.php') {
    $headers = array('Content-Type: application/json');
    if ($sid !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $sid;
    }
    $hdr = '';
    foreach ($headers as $h) { $hdr .= '-H ' . escapeshellarg($h) . ' '; }
    $payload = $jsonBody === null ? '' : ('-d ' . escapeshellarg($jsonBody));
    $bodyFile = tempnam(sys_get_temp_dir(), 'fam_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . $hdr . " -X POST $payload " . escapeshellarg($url);
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    $json = json_decode($body, true);
    return array('status' => $status, 'body' => $body, 'json' => $json);
}

$stamp = time();
$parentId = 'smoke-family-parent-' . $stamp;
$focusId  = 'smoke-family-focus-'  . $stamp;
$childA   = 'smoke-family-childA-' . $stamp;
$childB   = 'smoke-family-childB-' . $stamp;
$privateParent = 'smoke-family-private-parent-' . $stamp;
$orphanFocus   = 'smoke-family-orphan-focus-' . $stamp;

$svc = new StraboSamplesService($db, $neodb);

try {
    // ---- Owner-owned mini-tree:
    //          parent
    //            |
    //          focus
    //         /    \
    //      childA  childB
    $svc->setUserpkey($ownerPkey);
    $svc->createSample(array('id' => $parentId, 'name' => 'Parent-Sample'));
    $svc->createSample(array('id' => $focusId,  'name' => 'Focus-Sample'));
    $svc->createSample(array('id' => $childA,   'name' => 'Child-A'));
    $svc->createSample(array('id' => $childB,   'name' => 'Child-B'));
    $svc->setParent($focusId, $ownerPkey, $parentId, $ownerPkey);
    $svc->setParent($childA,  $ownerPkey, $focusId,  $ownerPkey);
    $svc->setParent($childB,  $ownerPkey, $focusId,  $ownerPkey);

    // ---- Orphan-parent scenario: stranger owns a parent that this user
    // (editor) wouldn't normally see; editor has an edit grant on orphanFocus.
    $svc->setUserpkey($strangerPkey);
    $svc->createSample(array('id' => $privateParent, 'name' => 'Hidden-Parent'));
    $svc->setUserpkey($ownerPkey);
    $svc->createSample(array('id' => $orphanFocus, 'name' => 'Orphan-Focus'));
    // Point orphan focus at the stranger-owned parent — uses an internal
    // direct UPDATE because setParent would gate on canRead(parent).
    $db->prepare_query(
        "UPDATE strabosamples.samples
            SET parent_sample_id=$1, parent_userpkey=$2, modified_at=now(), modified_by=$3
          WHERE id=$4 AND userpkey=$5",
        array($privateParent, $strangerPkey, $ownerPkey, $orphanFocus, $ownerPkey)
    );

    // Seed accepted edit + readonly grants on focus + orphanFocus so we can
    // verify the canRead gate covers them too.
    foreach (array($focusId, $orphanFocus) as $sid) {
        $db->prepare_query(
            "INSERT INTO strabosamples.sample_collaborators
               (sample_id, sample_userpkey, collaborator_pkey, permission_level,
                uuid, accepted, accepted_at, added_by)
             VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
            array($sid, $ownerPkey, $editorPkey, bin2hex(random_bytes(8)))
        );
        $db->prepare_query(
            "INSERT INTO strabosamples.sample_collaborators
               (sample_id, sample_userpkey, collaborator_pkey, permission_level,
                uuid, accepted, accepted_at, added_by)
             VALUES ($1, $2, $3, 'readonly', $4, TRUE, now(), $2)",
            array($sid, $ownerPkey, $readerPkey, bin2hex(random_bytes(8)))
        );
    }

    echo "=== Unauthenticated request ===\n";
    $r = hit(null, json_encode(array('sample_id' => $focusId, 'owner_pkey' => $ownerPkey)));
    check('status = 401',                       $r['status'] === 401);
    check('error = not_authenticated',           isset($r['json']['error']) && $r['json']['error'] === 'not_authenticated');

    echo "\n=== Invalid JSON ===\n";
    $r = hit($ownerSid, '{nope');
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_json',                isset($r['json']['error']) && $r['json']['error'] === 'invalid_json');

    echo "\n=== Missing sample_id ===\n";
    $r = hit($ownerSid, json_encode(array('owner_pkey' => $ownerPkey)));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_required_fields',     isset($r['json']['error']) && $r['json']['error'] === 'missing_required_fields');

    echo "\n=== Missing owner_pkey ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $focusId)));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_required_fields',     isset($r['json']['error']) && $r['json']['error'] === 'missing_required_fields');

    echo "\n=== Happy path: owner views focus ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $focusId, 'owner_pkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    check('family present',                      isset($r['json']['family']) && is_array($r['json']['family']));
    if (isset($r['json']['family'])) {
        $fam = $r['json']['family'];
        check('focus.id matches',                isset($fam['focus']['id']) && $fam['focus']['id'] === $focusId);
        check('focus.name matches',              isset($fam['focus']['name']) && $fam['focus']['name'] === 'Focus-Sample');
        check('parent.id matches',               isset($fam['parent']['id']) && $fam['parent']['id'] === $parentId);
        check('parent not orphaned',              !isset($fam['parent']['orphaned']) || $fam['parent']['orphaned'] !== true);
        check('children is array',                isset($fam['children']) && is_array($fam['children']));
        check('children count = 2',               count($fam['children']) === 2);
        $childIds = array_map(function ($c) { return $c['id']; }, $fam['children']);
        sort($childIds);
        $expected = array($childA, $childB); sort($expected);
        check('children ids match A + B',         $childIds === $expected);
    }

    echo "\n=== Editor (accepted edit collab) ===\n";
    $r = hit($editorSid, json_encode(array('sample_id' => $focusId, 'owner_pkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    check('editor can read focus',               isset($r['json']['family']['focus']['id']) && $r['json']['family']['focus']['id'] === $focusId);

    echo "\n=== Reader (accepted readonly collab) ===\n";
    $r = hit($readerSid, json_encode(array('sample_id' => $focusId, 'owner_pkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    check('reader can read focus',               isset($r['json']['family']['focus']['id']) && $r['json']['family']['focus']['id'] === $focusId);

    echo "\n=== Stranger → 404 ===\n";
    $r = hit($strangerSid, json_encode(array('sample_id' => $focusId, 'owner_pkey' => $ownerPkey)));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== Nonexistent sample → 404 ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => 'no-such-sample-' . $stamp, 'owner_pkey' => $ownerPkey)));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== Orphaned-parent shape pass-through ===\n";
    // Owner can read focus, but parent is owned by stranger and not collab'd.
    $r = hit($ownerSid, json_encode(array('sample_id' => $orphanFocus, 'owner_pkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    check('orphan parent shape present',         isset($r['json']['family']['parent']['orphaned']) && $r['json']['family']['parent']['orphaned'] === true);
    check('orphan parent_sample_id preserved',   isset($r['json']['family']['parent']['parent_sample_id']) && $r['json']['family']['parent']['parent_sample_id'] === $privateParent);

    echo "\n=== Focus from a different node returns its own neighborhood ===\n";
    // Walking the mini-explorer: focus on childA → expect parent=focusId, no children.
    $r = hit($ownerSid, json_encode(array('sample_id' => $childA, 'owner_pkey' => $ownerPkey)));
    check('status = 200',                       $r['status'] === 200);
    check('childA focus.id matches',             isset($r['json']['family']['focus']['id']) && $r['json']['family']['focus']['id'] === $childA);
    check('childA parent.id = focusId',          isset($r['json']['family']['parent']['id']) && $r['json']['family']['parent']['id'] === $focusId);
    check('childA children = 0',                 isset($r['json']['family']['children']) && count($r['json']['family']['children']) === 0);

} finally {
    $svc->setUserpkey($ownerPkey);
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id IN ($1, $2, $3, $4, $5) AND userpkey=$6",
        array($parentId, $focusId, $childA, $childB, $orphanFocus, $ownerPkey)
    );
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($privateParent, $strangerPkey)
    );
    @unlink($ownerSessionFile);
    @unlink($editorSessionFile);
    @unlink($readerSessionFile);
    @unlink($strangerSessionFile);
}

echo "\n";
if (count($failures) === 0) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " FAIL(S)\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
