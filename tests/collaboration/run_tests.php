<?php
/**
 * Collaboration Testing: Test Runner
 *
 * This script tests all collaboration scenarios via API calls.
 * Run setup_test_data.php first to create the test data.
 *
 * Usage: php run_tests.php
 *
 * @package StraboSpot Tests
 */

// Change to www directory for includes
chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

// Test configuration
$baseUrl = 'http://localhost/db';
$testPassword = 'testpass123';

// Test IDs (must match setup_test_data.php)
$projectSoloId = 9999999901;
$projectCollabId = 9999999902;
$projectHaltedId = 9999999903;
$datasetSolo1 = 8888888801;
$datasetCollabOwner = 8888888802;
$datasetCollabEditor = 8888888803;
$datasetHaltedOwner = 8888888804;
$datasetHaltedEditor = 8888888805;
$spotSolo1 = 7777777701;

// Test counters
$passed = 0;
$failed = 0;
$tests = [];

// Get user pkeys
$ownerPkey = $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", ['owner@test.strabospot.org']);
$editorPkey = $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", ['editor@test.strabospot.org']);
$readonlyPkey = $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", ['readonly@test.strabospot.org']);
$outsiderPkey = $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", ['outsider@test.strabospot.org']);

if (!$ownerPkey || !$editorPkey || !$readonlyPkey || !$outsiderPkey) {
    die("ERROR: Test users not found. Run setup_test_data.php first.\n");
}

// Helper functions
function makeRequest($method, $url, $user, $password, $data = null) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$password");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response
    ];
}

function test($name, $condition, $details = '') {
    global $passed, $failed, $tests;

    if ($condition) {
        $passed++;
        $status = "\033[32mPASS\033[0m";
    } else {
        $failed++;
        $status = "\033[31mFAIL\033[0m";
    }

    $tests[] = [
        'name' => $name,
        'passed' => $condition,
        'details' => $details
    ];

    echo "[$status] $name";
    if (!$condition && $details) {
        echo " - $details";
    }
    echo "\n";
}

function section($title) {
    echo "\n\033[1;34m=== $title ===\033[0m\n\n";
}

// ============================================================================
// START TESTS
// ============================================================================

echo "\n\033[1;33m╔══════════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;33m║         COLLABORATION FEATURE - TEST SUITE                   ║\033[0m\n";
echo "\033[1;33m╚══════════════════════════════════════════════════════════════╝\033[0m\n";

// ============================================================================
// SECTION 7.1: Normal Projects (No Collaborators)
// ============================================================================
section("7.1 Normal Projects (No Collaborators)");

// Test: Owner can view their project
$r = makeRequest('GET', "$baseUrl/project/$projectSoloId", 'owner@test.strabospot.org', $testPassword);
test(
    "Owner can view their solo project",
    $r['code'] == 200 && isset($r['body']['id']),
    "HTTP {$r['code']}"
);

// Test: Owner can view their dataset
$r = makeRequest('GET', "$baseUrl/dataset/$datasetSolo1", 'owner@test.strabospot.org', $testPassword);
test(
    "Owner can view their solo dataset",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

// Test: Owner can view their spot
$r = makeRequest('GET', "$baseUrl/feature/$spotSolo1", 'owner@test.strabospot.org', $testPassword);
test(
    "Owner can view their solo spot",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

// Test: Non-owner gets 404 for project
$r = makeRequest('GET', "$baseUrl/project/$projectSoloId", 'outsider@test.strabospot.org', $testPassword);
test(
    "Outsider gets 404 for owner's solo project",
    $r['code'] == 404,
    "HTTP {$r['code']}"
);

// Test: Non-owner gets 404 for dataset
$r = makeRequest('GET', "$baseUrl/dataset/$datasetSolo1", 'outsider@test.strabospot.org', $testPassword);
test(
    "Outsider gets 404 for owner's solo dataset",
    $r['code'] == 404,
    "HTTP {$r['code']}"
);

// Test: Editor (not a collaborator on this project) gets 404
$r = makeRequest('GET', "$baseUrl/project/$projectSoloId", 'editor@test.strabospot.org', $testPassword);
test(
    "Non-collaborator gets 404 for solo project",
    $r['code'] == 404,
    "HTTP {$r['code']}"
);

// ============================================================================
// SECTION 7.2: Active Collaboration
// ============================================================================
section("7.2 Active Collaboration");

// === Owner permissions ===
echo "\n--- Owner Permissions ---\n";

$r = makeRequest('GET', "$baseUrl/project/$projectCollabId", 'owner@test.strabospot.org', $testPassword);
test(
    "Owner can view collaborative project",
    $r['code'] == 200 && isset($r['body']['id']),
    "HTTP {$r['code']}"
);

$r = makeRequest('GET', "$baseUrl/dataset/$datasetCollabOwner", 'owner@test.strabospot.org', $testPassword);
test(
    "Owner can view their own dataset in collab project",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

$r = makeRequest('GET', "$baseUrl/dataset/$datasetCollabEditor", 'owner@test.strabospot.org', $testPassword);
test(
    "Owner can view editor's dataset in collab project",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

// === Edit Collaborator permissions ===
echo "\n--- Edit Collaborator Permissions ---\n";

$r = makeRequest('GET', "$baseUrl/project/$projectCollabId", 'editor@test.strabospot.org', $testPassword);
test(
    "Editor collaborator can view collaborative project",
    $r['code'] == 200 && isset($r['body']['id']),
    "HTTP {$r['code']}"
);

$r = makeRequest('GET', "$baseUrl/dataset/$datasetCollabOwner", 'editor@test.strabospot.org', $testPassword);
test(
    "Editor collaborator can view owner's dataset",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

$r = makeRequest('GET', "$baseUrl/dataset/$datasetCollabEditor", 'editor@test.strabospot.org', $testPassword);
test(
    "Editor collaborator can view their own dataset",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

// === Readonly Collaborator permissions ===
echo "\n--- Readonly Collaborator Permissions ---\n";

$r = makeRequest('GET', "$baseUrl/project/$projectCollabId", 'readonly@test.strabospot.org', $testPassword);
test(
    "Readonly collaborator can view collaborative project",
    $r['code'] == 200 && isset($r['body']['id']),
    "HTTP {$r['code']}"
);

$r = makeRequest('GET', "$baseUrl/dataset/$datasetCollabOwner", 'readonly@test.strabospot.org', $testPassword);
test(
    "Readonly collaborator can view owner's dataset",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

// === Outsider (non-collaborator) ===
echo "\n--- Non-Collaborator Access ---\n";

$r = makeRequest('GET', "$baseUrl/project/$projectCollabId", 'outsider@test.strabospot.org', $testPassword);
test(
    "Outsider gets 404 for collaborative project",
    $r['code'] == 404,
    "HTTP {$r['code']}"
);

// ============================================================================
// SECTION 7.3: Halted Collaboration
// ============================================================================
section("7.3 Halted Collaboration");

// When halted, owner regains full control, collaborators become readonly
$r = makeRequest('GET', "$baseUrl/project/$projectHaltedId", 'owner@test.strabospot.org', $testPassword);
test(
    "Owner can view halted collaborative project",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

$r = makeRequest('GET', "$baseUrl/dataset/$datasetHaltedEditor", 'owner@test.strabospot.org', $testPassword);
test(
    "Owner can view editor's dataset in halted project",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

// Halted collaborators become readonly - they can still READ but not EDIT
$r = makeRequest('GET', "$baseUrl/project/$projectHaltedId", 'editor@test.strabospot.org', $testPassword);
test(
    "Halted collaborator can still read project (readonly)",
    $r['code'] == 200,
    "HTTP {$r['code']}"
);

// ============================================================================
// SECTION: Write Operations (if tests above pass)
// ============================================================================
section("Write Operations");

// Generate unique spot ID for test
$newSpotId = 6666666600 + rand(1, 999);

// Test: Create a new spot in editor's dataset (editor should succeed)
$newSpot = [
    'type' => 'Feature',
    'geometry' => [
        'type' => 'Point',
        'coordinates' => [-100.0, 40.0]
    ],
    'properties' => [
        'id' => $newSpotId,
        'name' => 'New Editor Spot'
    ]
];

// Editor creates spot in their own dataset
$r = makeRequest(
    'POST',
    "$baseUrl/datasetspots/$datasetCollabEditor",
    'editor@test.strabospot.org',
    $testPassword,
    ['type' => 'FeatureCollection', 'features' => [$newSpot]]
);
test(
    "Editor can create spot in their own dataset",
    $r['code'] == 200 || $r['code'] == 201,
    "HTTP {$r['code']}"
);

// Readonly cannot create spots
$r = makeRequest(
    'POST',
    "$baseUrl/datasetspots/$datasetCollabEditor",
    'readonly@test.strabospot.org',
    $testPassword,
    ['type' => 'FeatureCollection', 'features' => [$newSpot]]
);
test(
    "Readonly collaborator cannot create spots (gets 403)",
    $r['code'] == 403,
    "HTTP {$r['code']}"
);

// Editor cannot edit owner's dataset
$r = makeRequest(
    'POST',
    "$baseUrl/datasetspots/$datasetCollabOwner",
    'editor@test.strabospot.org',
    $testPassword,
    ['type' => 'FeatureCollection', 'features' => [$newSpot]]
);
test(
    "Editor cannot create spots in owner's dataset (gets 403)",
    $r['code'] == 403,
    "HTTP {$r['code']}"
);

// ============================================================================
// RESULTS
// ============================================================================
echo "\n\033[1;33m╔══════════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;33m║                       TEST RESULTS                           ║\033[0m\n";
echo "\033[1;33m╚══════════════════════════════════════════════════════════════╝\033[0m\n\n";

$total = $passed + $failed;
$passRate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "Total Tests: $total\n";
echo "\033[32mPassed: $passed\033[0m\n";
echo "\033[31mFailed: $failed\033[0m\n";
echo "Pass Rate: $passRate%\n\n";

if ($failed > 0) {
    echo "\033[31mFailed Tests:\033[0m\n";
    foreach ($tests as $test) {
        if (!$test['passed']) {
            echo "  - {$test['name']}";
            if ($test['details']) {
                echo " ({$test['details']})";
            }
            echo "\n";
        }
    }
    echo "\n";
}

if ($failed == 0) {
    echo "\033[32m✓ All tests passed!\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m✗ Some tests failed. Review the output above.\033[0m\n\n";
    exit(1);
}
