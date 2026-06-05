<?php
/**
 * File: smoke_test_changelog_proxy.php
 * Description: HTTP-layer smoke test for /samples_changelog.php — the
 *              session-authed proxy that powers the in-page changelog
 *              viewer in samples_detail.php. Covers list + auth +
 *              status-code mapping + list-enrichment (changed_by →
 *              actor display fields) + pagination + canRead gate.
 *
 *              Same chown'd www-data session-file harness as
 *              smoke_test_invitations_proxy.php / smoke_test_collab_proxy.php /
 *              smoke_test_create_proxy.php.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/strabosamples/smoke_test_changelog_proxy.php
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

function hit($sid, $jsonBody, $url = 'http://localhost/samples_changelog.php') {
    $headers = array('Content-Type: application/json');
    if ($sid !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $sid;
    }
    $hdr = '';
    foreach ($headers as $h) { $hdr .= '-H ' . escapeshellarg($h) . ' '; }
    $payload = $jsonBody === null ? '' : ('-d ' . escapeshellarg($jsonBody));
    $bodyFile = tempnam(sys_get_temp_dir(), 'clog_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . $hdr . " -X POST $payload " . escapeshellarg($url);
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    $json = json_decode($body, true);
    return array('status' => $status, 'body' => $body, 'json' => $json);
}

$stamp = time();
$sampleId = 'smoke-clog-proxy-' . $stamp;

// Seed a sample owned by the owner, then drive a sequence of writes that
// each append a changelog row. We need enough rows to exercise pagination.
$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

try {
    $svc->createSample(array('id' => $sampleId, 'name' => 'clog-proxy-smoke'));      // create
    $svc->updateSample($sampleId, $ownerPkey, array('name' => 'rename-1'));           // update
    $svc->updateSample($sampleId, $ownerPkey, array('description' => 'desc-1'));      // update
    $svc->updateSample($sampleId, $ownerPkey, array('notes' => 'note-1'));            // update
    $svc->replaceComposition($sampleId, $ownerPkey, array(                            // composition_change
        array('mineral' => 'quartz'),
    ));
    // 5 rows total now.

    // Seed accepted edit + readonly grants so we can verify the canRead gate
    // covers them too.
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
        array($sampleId, $ownerPkey, $editorPkey, bin2hex(random_bytes(8)))
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'readonly', $4, TRUE, now(), $2)",
        array($sampleId, $ownerPkey, $readerPkey, bin2hex(random_bytes(8)))
    );

    echo "=== Unauthenticated request ===\n";
    $r = hit(null, json_encode(array('action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey)));
    check('status = 401',                       $r['status'] === 401);
    check('error = not_authenticated',           isset($r['json']['error']) && $r['json']['error'] === 'not_authenticated');

    echo "\n=== Invalid JSON ===\n";
    $r = hit($ownerSid, '{nope');
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_json',                isset($r['json']['error']) && $r['json']['error'] === 'invalid_json');

    echo "\n=== Missing action ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $sampleId, 'owner_pkey' => $ownerPkey)));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_action',              isset($r['json']['error']) && $r['json']['error'] === 'missing_action');

    echo "\n=== Invalid action ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'fluff', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey)));
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_action',              isset($r['json']['error']) && $r['json']['error'] === 'invalid_action');

    echo "\n=== Missing sample_id ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'list', 'owner_pkey' => $ownerPkey)));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_required_fields',     isset($r['json']['error']) && $r['json']['error'] === 'missing_required_fields');

    echo "\n=== Missing owner_pkey ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'list', 'sample_id' => $sampleId)));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_required_fields',     isset($r['json']['error']) && $r['json']['error'] === 'missing_required_fields');

    echo "\n=== List as owner — happy path + per-entry shape ===\n";
    $r = hit($ownerSid, json_encode(array(
        'action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey,
    )));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    check('changelog is array',                  isset($r['json']['changelog']) && is_array($r['json']['changelog']));
    check('total = 5',                           isset($r['json']['total']) && $r['json']['total'] === 5);
    check('count = 5',                           isset($r['json']['count']) && $r['json']['count'] === 5);
    check('limit default = 50',                  isset($r['json']['limit']) && $r['json']['limit'] === 50);
    check('offset default = 0',                  isset($r['json']['offset']) && $r['json']['offset'] === 0);

    $first = $r['json']['changelog'][0];
    check('newest = composition_change',         isset($first['change_type']) && $first['change_type'] === 'composition_change');
    $last = end($r['json']['changelog']);
    check('oldest = create',                     isset($last['change_type']) && $last['change_type'] === 'create');

    echo "\n=== Per-entry shape ===\n";
    check('pkey is int',                         isset($first['pkey']) && is_int($first['pkey']));
    check('changed_by = owner',                  isset($first['changed_by']) && (int)$first['changed_by'] === $ownerPkey);
    check('changed_at present',                  isset($first['changed_at']) && !empty($first['changed_at']));
    check('source_subsystem = samples_api',      isset($first['source_subsystem']) && $first['source_subsystem'] === 'samples_api');
    check('changes decoded from JSONB',          isset($first['changes']) && is_array($first['changes']));

    echo "\n=== Actor enrichment ===\n";
    check('actor_name populated',                isset($first['actor_name']) && $first['actor_name'] !== '');
    check('actor_email populated',               isset($first['actor_email']) && $first['actor_email'] !== '');
    check('actor_initials populated',            isset($first['actor_initials']) && $first['actor_initials'] !== '');

    echo "\n=== Pagination (limit=2, offset=0) ===\n";
    $r = hit($ownerSid, json_encode(array(
        'action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey,
        'limit' => 2, 'offset' => 0,
    )));
    check('status = 200',                       $r['status'] === 200);
    check('page1 count = 2',                     isset($r['json']['count']) && $r['json']['count'] === 2);
    check('page1 total = 5',                     isset($r['json']['total']) && $r['json']['total'] === 5);
    check('page1 limit echoed = 2',              isset($r['json']['limit']) && $r['json']['limit'] === 2);
    check('page1 offset echoed = 0',             isset($r['json']['offset']) && $r['json']['offset'] === 0);
    $page1FirstPkey = $r['json']['changelog'][0]['pkey'];

    echo "\n=== Pagination (limit=2, offset=2) ===\n";
    $r = hit($ownerSid, json_encode(array(
        'action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey,
        'limit' => 2, 'offset' => 2,
    )));
    check('status = 200',                       $r['status'] === 200);
    check('page2 count = 2',                     isset($r['json']['count']) && $r['json']['count'] === 2);
    check('page2 first row differs from page1',  $r['json']['changelog'][0]['pkey'] !== $page1FirstPkey);

    echo "\n=== Pagination clamping ===\n";
    $r = hit($ownerSid, json_encode(array(
        'action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey,
        'limit' => 0, 'offset' => -10,
    )));
    check('limit=0 clamped to default 50',       isset($r['json']['limit']) && $r['json']['limit'] === 50);
    check('offset=-10 clamped to 0',             isset($r['json']['offset']) && $r['json']['offset'] === 0);
    $r = hit($ownerSid, json_encode(array(
        'action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey,
        'limit' => 99999,
    )));
    check('limit=99999 clamped to max 500',      isset($r['json']['limit']) && $r['json']['limit'] === 500);

    echo "\n=== canRead gate: editor (accepted edit) ===\n";
    $r = hit($editorSid, json_encode(array(
        'action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey,
    )));
    check('status = 200',                       $r['status'] === 200);
    check('editor can read full changelog',      isset($r['json']['total']) && $r['json']['total'] === 5);

    echo "\n=== canRead gate: reader (accepted readonly) ===\n";
    $r = hit($readerSid, json_encode(array(
        'action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey,
    )));
    check('status = 200',                       $r['status'] === 200);
    check('reader can read full changelog',      isset($r['json']['total']) && $r['json']['total'] === 5);

    echo "\n=== canRead gate: stranger → 404 ===\n";
    $r = hit($strangerSid, json_encode(array(
        'action' => 'list', 'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey,
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== Nonexistent sample → 404 ===\n";
    $r = hit($ownerSid, json_encode(array(
        'action' => 'list', 'sample_id' => 'this-sample-does-not-exist-' . $stamp, 'owner_pkey' => $ownerPkey,
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

} finally {
    $svc->setUserpkey($ownerPkey);
    // CASCADE wipes the changelog + collaborator rows with the sample.
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
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
