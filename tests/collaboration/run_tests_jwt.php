<?php
/**
 * Collaboration Testing: JWT Twin of the Permission Matrix
 *
 * Mirrors run_tests.php's role x operation matrix, but drives the JWT-authenticated
 * REST surface (/jwtdb/) with Bearer tokens instead of the Basic-Auth surface (/db/).
 *
 * Motivation: /db/ and /jwtdb/ share the same controllers and the same
 * CollaborationAuth class, but they are SEPARATE bootstraps (jwtdb/index.php does
 * its own auth + userpkey wiring + setauthhandler()). Every pre-existing
 * collaboration test hit /db/ only, so a regression or divergence in the JWT
 * bootstrap would have been invisible. StraboField mobile is the primary
 * collaborative client and authenticates via JWT, so this is the highest-traffic
 * unguarded surface. This suite proves the JWT path enforces the identical matrix.
 *
 * Fixture: reuses setup_test_data.php (run it first, same as run_tests.php).
 *
 * Usage (inside the app container):
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/setup_test_data.php
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/run_tests_jwt.php
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

// Test configuration
$loginUrl = 'http://localhost/jwtauth/login.php';
$baseUrl  = 'http://localhost/jwtdb';
$testPassword = 'testpass123';

// Test IDs (must match setup_test_data.php)
$projectSoloId      = 9999999901;
$projectCollabId    = 9999999902;
$projectHaltedId    = 9999999903;
$datasetSolo1       = 8888888801;
$datasetCollabOwner = 8888888802;
$datasetCollabEditor= 8888888803;
$datasetHaltedEditor= 8888888805;
$spotSolo1          = 7777777701;

// Test counters
$passed = 0;
$failed = 0;
$tests = [];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Exchange email+password for a JWT access token via the real login endpoint.
 * Returns the bearer token string, or null on failure.
 */
function jwtLogin($email, $password) {
    global $loginUrl;
    $ch = curl_init($loginUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $email, 'password' => $password]));
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

/**
 * Make a request against /jwtdb/ with a Bearer token (or no auth if $token null).
 */
function jwtRequest($method, $url, $token, $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'code' => $httpCode,
        'body' => json_decode($response, true),
        'raw'  => $response
    ];
}

function test($name, $condition, $details = '') {
    global $passed, $failed, $tests;
    if ($condition) { $passed++; $status = "\033[32mPASS\033[0m"; }
    else { $failed++; $status = "\033[31mFAIL\033[0m"; }
    $tests[] = ['name' => $name, 'passed' => $condition, 'details' => $details];
    echo "[$status] $name";
    if (!$condition && $details) { echo " - $details"; }
    echo "\n";
}

function section($title) {
    echo "\n\033[1;34m=== $title ===\033[0m\n\n";
}

// ---------------------------------------------------------------------------
// START
// ---------------------------------------------------------------------------

echo "\n\033[1;33m╔══════════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;33m║      COLLABORATION FEATURE - JWT (/jwtdb/) TEST SUITE        ║\033[0m\n";
echo "\033[1;33m╚══════════════════════════════════════════════════════════════╝\033[0m\n";

// Acquire tokens for each role via the real login endpoint.
section("JWT Token Acquisition");

$ownerToken    = jwtLogin('owner@test.strabospot.org', $testPassword);
$editorToken   = jwtLogin('editor@test.strabospot.org', $testPassword);
$readonlyToken = jwtLogin('readonly@test.strabospot.org', $testPassword);
$outsiderToken = jwtLogin('outsider@test.strabospot.org', $testPassword);

test("Owner obtains a JWT access token",    !empty($ownerToken),    $ownerToken ? 'ok' : 'no token');
test("Editor obtains a JWT access token",   !empty($editorToken),   $editorToken ? 'ok' : 'no token');
test("Readonly obtains a JWT access token", !empty($readonlyToken), $readonlyToken ? 'ok' : 'no token');
test("Outsider obtains a JWT access token", !empty($outsiderToken), $outsiderToken ? 'ok' : 'no token');

if (!$ownerToken || !$editorToken || !$readonlyToken || !$outsiderToken) {
    echo "\n\033[31mERROR: could not acquire all tokens. Run setup_test_data.php first.\033[0m\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Unauthenticated / malformed-token gate (JWT-specific)
// ---------------------------------------------------------------------------
section("JWT Auth Gate");

$r = jwtRequest('GET', "$baseUrl/project/$projectSoloId", null);
test("No Authorization header → 401", $r['code'] == 401, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/project/$projectSoloId", 'not-a-real-token');
test("Garbage bearer token → 401", $r['code'] == 401, "HTTP {$r['code']}");

// ---------------------------------------------------------------------------
// 7.1 Normal Projects (No Collaborators)
// ---------------------------------------------------------------------------
section("7.1 Normal Projects (No Collaborators)");

$r = jwtRequest('GET', "$baseUrl/project/$projectSoloId", $ownerToken);
test("Owner can view their solo project", $r['code'] == 200 && isset($r['body']['id']), "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/dataset/$datasetSolo1", $ownerToken);
test("Owner can view their solo dataset", $r['code'] == 200, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/feature/$spotSolo1", $ownerToken);
test("Owner can view their solo spot", $r['code'] == 200, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/project/$projectSoloId", $outsiderToken);
test("Outsider gets 404 for owner's solo project", $r['code'] == 404, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/dataset/$datasetSolo1", $outsiderToken);
test("Outsider gets 404 for owner's solo dataset", $r['code'] == 404, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/project/$projectSoloId", $editorToken);
test("Non-collaborator gets 404 for solo project", $r['code'] == 404, "HTTP {$r['code']}");

// ---------------------------------------------------------------------------
// 7.2 Active Collaboration
// ---------------------------------------------------------------------------
section("7.2 Active Collaboration");

echo "\n--- Owner Permissions ---\n";
$r = jwtRequest('GET', "$baseUrl/project/$projectCollabId", $ownerToken);
test("Owner can view collaborative project", $r['code'] == 200 && isset($r['body']['id']), "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/dataset/$datasetCollabOwner", $ownerToken);
test("Owner can view their own dataset in collab project", $r['code'] == 200, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/dataset/$datasetCollabEditor", $ownerToken);
test("Owner can view editor's dataset in collab project", $r['code'] == 200, "HTTP {$r['code']}");

echo "\n--- Edit Collaborator Permissions ---\n";
$r = jwtRequest('GET', "$baseUrl/project/$projectCollabId", $editorToken);
test("Editor collaborator can view collaborative project", $r['code'] == 200 && isset($r['body']['id']), "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/dataset/$datasetCollabOwner", $editorToken);
test("Editor collaborator can view owner's dataset", $r['code'] == 200, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/dataset/$datasetCollabEditor", $editorToken);
test("Editor collaborator can view their own dataset", $r['code'] == 200, "HTTP {$r['code']}");

echo "\n--- Readonly Collaborator Permissions ---\n";
$r = jwtRequest('GET', "$baseUrl/project/$projectCollabId", $readonlyToken);
test("Readonly collaborator can view collaborative project", $r['code'] == 200 && isset($r['body']['id']), "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/dataset/$datasetCollabOwner", $readonlyToken);
test("Readonly collaborator can view owner's dataset", $r['code'] == 200, "HTTP {$r['code']}");

echo "\n--- Non-Collaborator Access ---\n";
$r = jwtRequest('GET', "$baseUrl/project/$projectCollabId", $outsiderToken);
test("Outsider gets 404 for collaborative project", $r['code'] == 404, "HTTP {$r['code']}");

// ---------------------------------------------------------------------------
// 7.3 Halted Collaboration
// ---------------------------------------------------------------------------
section("7.3 Halted Collaboration");

$r = jwtRequest('GET', "$baseUrl/project/$projectHaltedId", $ownerToken);
test("Owner can view halted collaborative project", $r['code'] == 200, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/dataset/$datasetHaltedEditor", $ownerToken);
test("Owner can view editor's dataset in halted project", $r['code'] == 200, "HTTP {$r['code']}");

$r = jwtRequest('GET', "$baseUrl/project/$projectHaltedId", $editorToken);
test("Halted collaborator can still read project (readonly)", $r['code'] == 200, "HTTP {$r['code']}");

// ---------------------------------------------------------------------------
// Write Operations (the core of the enforcement matrix)
// ---------------------------------------------------------------------------
section("Write Operations");

$newSpotId = 6666666700 + rand(1, 999);
$newSpot = [
    'type' => 'Feature',
    'geometry' => ['type' => 'Point', 'coordinates' => [-100.0, 40.0]],
    'properties' => ['id' => $newSpotId, 'name' => 'New Editor Spot (JWT)']
];
$fc = ['type' => 'FeatureCollection', 'features' => [$newSpot]];

$r = jwtRequest('POST', "$baseUrl/datasetspots/$datasetCollabEditor", $editorToken, $fc);
test("Editor can create spot in their own dataset", $r['code'] == 200 || $r['code'] == 201, "HTTP {$r['code']}");

$r = jwtRequest('POST', "$baseUrl/datasetspots/$datasetCollabEditor", $readonlyToken, $fc);
test("Readonly collaborator cannot create spots (gets 403)", $r['code'] == 403, "HTTP {$r['code']}");

$r = jwtRequest('POST', "$baseUrl/datasetspots/$datasetCollabOwner", $editorToken, $fc);
test("Editor cannot create spots in owner's dataset (gets 403)", $r['code'] == 403, "HTTP {$r['code']}");

$r = jwtRequest('POST', "$baseUrl/datasetspots/$datasetCollabEditor", $outsiderToken, $fc);
test("Outsider cannot create spots in collab dataset (404/403)", $r['code'] == 404 || $r['code'] == 403, "HTTP {$r['code']}");

// Clean up the spot the editor just created so the suite stays idempotent.
$neodb->query("MATCH (s:Spot {id: $newSpotId}) DETACH DELETE s");

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------
echo "\n\033[1;33m╔══════════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;33m║                  JWT TEST RESULTS                            ║\033[0m\n";
echo "\033[1;33m╚══════════════════════════════════════════════════════════════╝\033[0m\n\n";

$total = $passed + $failed;
$passRate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;
echo "Total Tests: $total\n";
echo "\033[32mPassed: $passed\033[0m\n";
echo "\033[31mFailed: $failed\033[0m\n";
echo "Pass Rate: $passRate%\n\n";

if ($failed > 0) {
    echo "\033[31mFailed Tests:\033[0m\n";
    foreach ($tests as $t) {
        if (!$t['passed']) {
            echo "  - {$t['name']}" . ($t['details'] ? " ({$t['details']})" : "") . "\n";
        }
    }
    echo "\n";
    exit(1);
}

echo "\033[32m✓ All JWT tests passed!\033[0m\n\n";
exit(0);
