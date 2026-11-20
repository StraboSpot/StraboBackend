<?php
/**
 * Explicit deletes of memos and tags (POST /db/projectDeletions/{id},
 * sql/field_deletions.sql, StraboSpot::dropDeletedItems).
 *
 * A project upload keeps every memo and tag it leaves out, so leaving one out
 * never deletes it (X0 pins that). A recorded delete takes the item out at once
 * and keeps it out against stale uploads, unless a memo copy was edited after
 * the delete.
 *
 * Needs the fixtures of setup_test_data.php (same as run_merge_tests.php).
 *
 * Usage: docker exec strabo-php php /srv/app/www/tests/collaboration/run_deletion_tests.php
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$baseUrl      = 'http://localhost/db';
$jwtBaseUrl   = 'http://localhost/jwtdb';
$testPassword = 'testpass123';

$projectSoloId   = 9999999901;
$projectCollabId = 9999999902;

$ownerEmail    = 'owner@test.strabospot.org';
$editorEmail   = 'editor@test.strabospot.org';
$readonlyEmail = 'readonly@test.strabospot.org';
$outsiderEmail = 'outsider@test.strabospot.org';

$ownerPkey  = (int) $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$ownerEmail]);
$editorPkey = (int) $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$editorEmail]);
if (!$ownerPkey || !$editorPkey) {
    die("ERROR: Test users not found. Run setup_test_data.php first.\n");
}
$hasTable = $db->get_var_prepared("SELECT to_regclass('public.field_deletions')::text", []);
if (!$hasTable) {
    die("ERROR: field_deletions table missing. Apply sql/field_deletions.sql first.\n");
}

$passed = 0;
$failed = 0;

function makeRequest($method, $url, $user, $password, $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$password");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

function test($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) { $passed++; $status = "\033[32mPASS\033[0m"; }
    else { $failed++; $status = "\033[31mFAIL\033[0m"; }
    echo "[$status] $name";
    if (!$condition && $details !== '') echo " - $details";
    echo "\n";
}

function section($title) { echo "\n\033[1;34m=== $title ===\033[0m\n\n"; }

function seed($neodb, $projectId, $ownerPkey, $reports, $tags) {
    $sets = [];
    foreach (['json_reports' => $reports, 'json_tags' => $tags] as $col => $value) {
        $json = json_encode($value);
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $json);
        $sets[] = "p.$col = '$escaped'";
    }
    $neodb->query("MATCH (p:Project {id: $projectId, userpkey: $ownerPkey}) SET " . implode(', ', $sets));
}

function clearDeletes($db, $projectId) {
    $db->prepare_query("DELETE FROM field_deletions WHERE strabo_project_id = $1", [(string)$projectId]);
}

function payload($projectId, $extra = []) {
    return array_merge([
        'id' => $projectId,
        'modified_timestamp' => (int)(microtime(true) * 1000),
        'description' => ['project_name' => 'Deletion Test'],
    ], $extra);
}

function ids($body, $key) {
    return array_map('strval', array_column(is_array($body) ? ($body[$key] ?? []) : [], 'id'));
}

function memo($id, $author, $modified) {
    $m = ['id' => $id, 'subject' => "Memo $id", 'notes' => 'n', 'spots' => [], 'tags' => [], 'images' => [],
          'modified_timestamp' => $modified];
    if ($author !== null) $m['straboUserId'] = $author;
    return $m;
}

function tag($id, $name) {
    return ['id' => $id, 'name' => $name, 'type' => 'geologic_unit', 'spots' => []];
}

$t0 = 1700000000000;

// ===========================================================================
section("X. Solo project (no collaborator rows)");

clearDeletes($db, $projectSoloId);
seed($neodb, $projectSoloId, $ownerPkey,
    [memo('m-a', $ownerPkey, $t0), memo('m-b', $ownerPkey, $t0)],
    [tag(5550001, 'Unit one'), tag(5550002, 'Unit two')]);

// X0. Leaving a memo out of an upload does not delete it (the union).
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    payload($projectSoloId, ['reports' => [memo('m-a', $ownerPkey, $t0)], 'tags' => [tag(5550001, 'Unit one'), tag(5550002, 'Unit two')]]));
$g = makeRequest('GET', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword);
test("X0. Upload without memo m-b keeps m-b (an omission never deletes)",
    $r['code'] == 201 && in_array('m-b', ids($g['body'], 'reports')), json_encode(ids($g['body'], 'reports')));

// X1. A recorded delete takes the memo out at once.
$r = makeRequest('POST', "$baseUrl/projectDeletions/$projectSoloId", $ownerEmail, $testPassword,
    ['deletions' => [['type' => 'report', 'id' => 'm-b', 'deleted_at' => $t0 + 1000]]]);
$g = makeRequest('GET', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword);
test("X1. Delete m-b: recorded 1, removed 1, gone from the project",
    $r['code'] == 200 && ($r['body']['recorded'] ?? null) === 1 && ($r['body']['removed'] ?? null) === 1
    && !in_array('m-b', ids($g['body'], 'reports')) && in_array('m-a', ids($g['body'], 'reports')), $r['raw']);

// X2. A stale device uploads m-b unchanged: it stays deleted.
makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    payload($projectSoloId, ['reports' => [memo('m-a', $ownerPkey, $t0), memo('m-b', $ownerPkey, $t0)]]));
$g = makeRequest('GET', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword);
test("X2. Stale upload still holding m-b does not bring it back",
    !in_array('m-b', ids($g['body'], 'reports')) && in_array('m-a', ids($g['body'], 'reports')), json_encode(ids($g['body'], 'reports')));

// X3. A copy edited after the delete comes back, and its delete record goes.
makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    payload($projectSoloId, ['reports' => [memo('m-a', $ownerPkey, $t0), memo('m-b', $ownerPkey, $t0 + 5000)]]));
$g = makeRequest('GET', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword);
$l = makeRequest('GET', "$baseUrl/projectDeletions/$projectSoloId", $ownerEmail, $testPassword);
test("X3. m-b edited after its delete is kept, delete record dropped",
    in_array('m-b', ids($g['body'], 'reports')) && !in_array('m-b', ids($l['body'], 'deletions')), $l['raw']);

// X4. Tag delete sticks against a stale upload (tags are unioned by default).
$r = makeRequest('POST', "$baseUrl/projectDeletions/$projectSoloId", $ownerEmail, $testPassword,
    ['deletions' => [['type' => 'tag', 'id' => 5550002, 'deleted_at' => $t0 + 1000]]]);
makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    payload($projectSoloId, ['tags' => [tag(5550001, 'Unit one'), tag(5550002, 'Unit two')]]));
$g = makeRequest('GET', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword);
test("X4. Deleted tag stays deleted after a stale upload carries it",
    ($r['body']['removed'] ?? null) === 1 && in_array('5550001', ids($g['body'], 'tags')) && !in_array('5550002', ids($g['body'], 'tags')),
    json_encode(ids($g['body'], 'tags')));

// X5. Deleting the last memos leaves an empty list stored.
$r = makeRequest('POST', "$jwtBaseUrl/projectDeletions/$projectSoloId", $ownerEmail, $testPassword, null);
test("X5a. jwtdb route exists (no token: refused, not 'No such function')",
    strpos((string)$r['raw'], 'No such function') === false, substr((string)$r['raw'], 0, 120));
$r = makeRequest('POST', "$baseUrl/projectDeletions/$projectSoloId", $ownerEmail, $testPassword,
    ['deletions' => [['type' => 'report', 'id' => 'm-a'], ['type' => 'report', 'id' => 'm-b']]]);
$g = makeRequest('GET', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword);
test("X5b. Deleting the last memos (no deleted_at: server time) leaves none",
    ($r['body']['removed'] ?? null) === 2 && count(ids($g['body'], 'reports')) === 0, $r['raw']);

// X6. Bad requests.
$r1 = makeRequest('POST', "$baseUrl/projectDeletions/$projectSoloId", $ownerEmail, $testPassword, ['deletions' => []]);
$r2 = makeRequest('POST', "$baseUrl/projectDeletions/$projectSoloId", $ownerEmail, $testPassword,
    ['deletions' => [['type' => 'spot', 'id' => '1']]]);
$r3 = makeRequest('POST', "$baseUrl/projectDeletions/abc", $ownerEmail, $testPassword, ['deletions' => [['type' => 'tag', 'id' => '1']]]);
test("X6. Empty list, unknown type, bad project id: 400", $r1['code'] == 400 && $r2['code'] == 400 && $r3['code'] == 400,
    "{$r1['code']} {$r2['code']} {$r3['code']}");

$r = makeRequest('POST', "$baseUrl/projectDeletions/$projectSoloId", $outsiderEmail, $testPassword,
    ['deletions' => [['type' => 'tag', 'id' => '5550001']]]);
$g = makeRequest('GET', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword);
test("X7. Someone else's project: 404, nothing deleted",
    $r['code'] == 404 && in_array('5550001', ids($g['body'], 'tags')), $r['raw']);

// ===========================================================================
section("Y. Project with collaborators");

clearDeletes($db, $projectCollabId);
seed($neodb, $projectCollabId, $ownerPkey,
    [memo('c-owner', $ownerPkey, $t0), memo('c-editor', $editorPkey, $t0)], []);

$r = makeRequest('POST', "$baseUrl/projectDeletions/$projectCollabId", $editorEmail, $testPassword,
    ['deletions' => [['type' => 'report', 'id' => 'c-owner', 'deleted_at' => $t0 + 1000]]]);
$g = makeRequest('GET', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword);
test("Y1. Editor cannot delete the owner's memo (refused, kept)",
    $r['code'] == 200 && count($r['body']['refused'] ?? []) === 1 && in_array('c-owner', ids($g['body'], 'reports')), $r['raw']);

$r = makeRequest('POST', "$baseUrl/projectDeletions/$projectCollabId", $editorEmail, $testPassword,
    ['deletions' => [['type' => 'report', 'id' => 'c-editor', 'deleted_at' => $t0 + 1000]]]);
$g = makeRequest('GET', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword);
test("Y2. Editor deletes their own memo",
    ($r['body']['removed'] ?? null) === 1 && !in_array('c-editor', ids($g['body'], 'reports')), $r['raw']);

makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    payload($projectCollabId, ['reports' => [memo('c-owner', $ownerPkey, $t0), memo('c-editor', $editorPkey, $t0)]]));
$g = makeRequest('GET', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword);
test("Y3. Owner's stale upload does not bring the editor's deleted memo back",
    !in_array('c-editor', ids($g['body'], 'reports')) && in_array('c-owner', ids($g['body'], 'reports')), json_encode(ids($g['body'], 'reports')));

makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    payload($projectCollabId, ['reports' => [memo('c-owner', $ownerPkey, $t0), memo('c-editor', $editorPkey, $t0)]]));
$g = makeRequest('GET', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword);
test("Y4. Editor's own stale upload (collaborative merge) does not bring it back",
    !in_array('c-editor', ids($g['body'], 'reports')), json_encode(ids($g['body'], 'reports')));

$r = makeRequest('POST', "$baseUrl/projectDeletions/$projectCollabId", $readonlyEmail, $testPassword,
    ['deletions' => [['type' => 'report', 'id' => 'c-owner']]]);
test("Y5. Read-only collaborator: 403", $r['code'] == 403, $r['raw']);

// ===========================================================================
clearDeletes($db, $projectSoloId);
clearDeletes($db, $projectCollabId);

echo "\nTotal: " . ($passed + $failed) . "  Passed: $passed  Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
