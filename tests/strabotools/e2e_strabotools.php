<?php
/**
 * StraboTools API: curl-level E2E test suite
 *
 * Drives the live HTTP endpoints (Basic auth on /db/, JWT on /jwtdb/)
 * end-to-end and asserts server state at the Neo4j / filesystem layer:
 *
 *   POST   /db/strabotools        create + idempotent re-upload by UUID
 *   GET    /db/strabotools/<id>   image bytes + ?format=json metadata
 *   GET    /db/mystrabotools      listing, newest first
 *   DELETE /db/strabotools/<id>   node + file removal
 *
 * Also proves the StraboField-isolation contract (no :Image nodes, no
 * relationships, invisible id space) and per-user UUID namespacing.
 *
 * Hermetic: creates its own users (strabotools_*@test.strabospot.org),
 * cleans up all DB rows, Neo4j nodes, and files. Run twice: both green.
 *
 * Usage (inside container): php /srv/app/www/tests/strabotools/e2e_strabotools.php
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$baseUrl = 'http://localhost';
$testPassword = 'testpass123';
$ownerEmail = 'strabotools_owner@test.strabospot.org';
$strangerEmail = 'strabotools_stranger@test.strabospot.org';
$toolsDir = '/srv/app/www/straboToolsImages';

$passed = 0;
$failed = 0;

function test($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[\033[32mPASS\033[0m] $name\n";
    } else {
        $failed++;
        echo "[\033[31mFAIL\033[0m] $name" . ($details !== '' ? " - $details" : "") . "\n";
    }
}

function section($title) {
    echo "\n\033[1;34m=== $title ===\033[0m\n\n";
}

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

function httpJson($method, $url, $basicUser = null, $password = null, $bearer = null, $jsonBody = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = [];
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        $headers[] = 'Content-Type: application/json';
    }
    if ($basicUser !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, "$basicUser:$password");
    }
    if ($bearer !== null) {
        $headers[] = "Authorization: Bearer $bearer";
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($response, true), 'raw' => $response, 'ctype' => $ctype];
}

function uploadAnalysis($url, $analysis, $blobPath, $basicUser = null, $password = null, $bearer = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $fields = [];
    if ($blobPath !== null) {
        $fields['image_file'] = new CURLFile($blobPath, 'image/jpeg', 'photo.jpg');
    }
    if ($analysis !== null) {
        $fields['analysis'] = is_string($analysis) ? $analysis : json_encode($analysis);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    if ($basicUser !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, "$basicUser:$password");
    }
    if ($bearer !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $bearer"]);
    }
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($response, true), 'raw' => $response];
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

section("Setup");

// Clean any residue from a previous crashed run
$db->query("DELETE FROM refresh_tokens WHERE userpkey IN (SELECT pkey FROM users WHERE email LIKE 'strabotools_%@test.strabospot.org')");
$db->query("DELETE FROM users WHERE email LIKE 'strabotools_%@test.strabospot.org'");
$neodb->query("MATCH (n:ToolsAnalysis) WHERE n.spotName STARTS WITH 'E2ETOOLS' DETACH DELETE n;");

foreach ([$ownerEmail => 'Owner', $strangerEmail => 'Stranger'] as $email => $last) {
    $db->prepare_query(
        "INSERT INTO users (email, password, firstname, lastname, hash, active, deleted)
         VALUES ($1, crypt($2, gen_salt('md5')), $3, $4, $5, TRUE, FALSE)",
        [$email, $testPassword, 'StraboTools', $last, md5(uniqid('', true))]
    );
}
$ownerPkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$ownerEmail]);
$strangerPkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$strangerEmail]);
test("Test users created", $ownerPkey > 0 && $strangerPkey > 0, "owner=$ownerPkey stranger=$strangerPkey");

// Storage dir (gitignored, may not exist in a fresh checkout)
$createdToolsDir = false;
if (!is_dir($toolsDir)) {
    mkdir($toolsDir, 0777, true);
    chmod($toolsDir, 0777);
    $createdToolsDir = true;
}
test("Storage dir writable", is_dir($toolsDir) && is_writable($toolsDir));

// Two distinct JPEG blobs (1x1 valid JPEG; v2 = same + trailing bytes after EOI)
$jpegB64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
         . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
         . 'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';
$blob1Path = sys_get_temp_dir() . '/e2etools_blob1.jpg';
$blob2Path = sys_get_temp_dir() . '/e2etools_blob2.jpg';
file_put_contents($blob1Path, base64_decode($jpegB64));
file_put_contents($blob2Path, base64_decode($jpegB64) . 'E2ETOOLS-V2-BYTES');
test("Test blobs prepared", sha1_file($blob1Path) !== sha1_file($blob2Path));

$uuidA = 'aaaaaaaa-1111-2222-3333-444444444444';
$uuidB = 'bbbbbbbb-1111-2222-3333-444444444444';
$uuidC = 'cccccccc-1111-2222-3333-444444444444';

$trickyNotes = "coarse-grained — `it's` \"near\" the contact\nline2 \\ backslash μm\"}) MATCH (x) DETACH DELETE x //";

$analysisA = [
    'schemaVersion' => 1,
    'id' => $uuidA,
    'captureDate' => '2026-07-07T18:08:00Z',
    'savedDate' => '2026-07-07T18:08:01Z',
    'spotName' => 'E2ETOOLS AP-23 granite ridge',
    'notes' => $trickyNotes,
    'attitudeRowMajor' => [0.11, 0.22, 0.33, 0.44, 0.55, 0.66, 0.77, 0.88, 0.99],
    'attitudeGapSeconds' => 0.004,
    'azimuthOffsetDegrees' => 0,
    'position' => [
        'latitude' => 38.95, 'longitude' => -95.23,
        'horizontalErrorMeters' => 4.0, 'verticalErrorMeters' => 6.0,
        'elevationMeters' => 264.0,
    ],
    'measurements' => [
        'fabricAzimuthDegrees' => 118.13,
        'fabricRakeDegrees' => 28.13,
        'axialRatio' => 1.45,
        'trendDegrees' => 338.66,
        'plungeDegrees' => 24.51,
        'colorIndexPercent' => 21.9,
        'colorIndexAdaptive' => false,
        'modePhaseCount' => 4,
        'modePercentages' => [30.7, 7.9, 33.1, 28.3],
    ],
    'appVersion' => '2.0 (1)',
    'deviceModel' => 'iPhone17,1',
];

// StraboField isolation baselines
$imageCountBefore = (int)$neodb->get_var("MATCH (n:Image) RETURN count(n);");

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

section("Create (POST /db/strabotools)");

$r = uploadAnalysis("$baseUrl/db/strabotools", $analysisA, $blob1Path, $ownerEmail, $testPassword);
test("Create returns 201", $r['code'] == 201, "got {$r['code']}: {$r['raw']}");
test("Create echoes client UUID", isset($r['body']['id']) && $r['body']['id'] === $uuidA);
test("Create returns self URL", isset($r['body']['self']) && $r['body']['self'] === "https://strabospot.org/db/strabotools/$uuidA");
$filenameA = isset($r['body']['filename']) ? $r['body']['filename'] : '';
test("Create returns numeric filename", $filenameA !== '' && ctype_digit((string)$filenameA), "got '$filenameA'");

test("File stored in straboToolsImages", file_exists("$toolsDir/$filenameA"));
test("Stored bytes match upload", file_exists("$toolsDir/$filenameA") && sha1_file("$toolsDir/$filenameA") === sha1_file($blob1Path));

$node = $neodb->getNode("MATCH (n:ToolsAnalysis) WHERE n.id = \"$uuidA\" AND n.userpkey = $ownerPkey RETURN n LIMIT 1;");
test("Node created with owner userpkey", !empty($node));
test("Scalar projection: spotName", isset($node['spotName']) && $node['spotName'] === $analysisA['spotName']);
test("Scalar projection: latitude", isset($node['latitude']) && abs($node['latitude'] - 38.95) < 1e-9);
test("Scalar projection: fabricAzimuthDegrees", isset($node['fabricAzimuthDegrees']) && abs($node['fabricAzimuthDegrees'] - 118.13) < 1e-9);
test("Zero-valued number survives (azimuthOffsetDegrees)", isset($node['azimuthOffsetDegrees']) && $node['azimuthOffsetDegrees'] === 0);
test("Boolean survives (colorIndexAdaptive)", isset($node['colorIndexAdaptive']) && $node['colorIndexAdaptive'] === false);
test("Cypher-hostile notes stored literally", isset($node['notes']) && $node['notes'] === $trickyNotes);
$storedJson = isset($node['analysis_json']) ? json_decode($node['analysis_json'], true) : null;
test("analysis_json round-trips arrays", is_array($storedJson)
    && $storedJson['attitudeRowMajor'] === $analysisA['attitudeRowMajor']
    && $storedJson['measurements']['modePercentages'] === $analysisA['measurements']['modePercentages']);
$createdTsA = isset($node['created_timestamp']) ? $node['created_timestamp'] : null;
test("created_timestamp set", $createdTsA !== null && $createdTsA > 0);

// ---------------------------------------------------------------------------
// Idempotent re-upload
// ---------------------------------------------------------------------------

section("Idempotent re-upload (same UUID)");

usleep(60000); // ensure a later modified_timestamp
$analysisA2 = $analysisA;
$analysisA2['notes'] = 'E2ETOOLS updated notes after retry';
$r = uploadAnalysis("$baseUrl/db/strabotools", $analysisA2, $blob2Path, $ownerEmail, $testPassword);
test("Re-upload returns 201", $r['code'] == 201, "got {$r['code']}: {$r['raw']}");
test("Re-upload keeps the same filename", isset($r['body']['filename']) && (string)$r['body']['filename'] === (string)$filenameA, "got '{$r['body']['filename']}' vs '$filenameA'");

$dupCount = (int)$neodb->get_var("MATCH (n:ToolsAnalysis) WHERE n.id = \"$uuidA\" AND n.userpkey = $ownerPkey RETURN count(n);");
test("No duplicate node", $dupCount === 1, "count=$dupCount");

$node = $neodb->getNode("MATCH (n:ToolsAnalysis) WHERE n.id = \"$uuidA\" AND n.userpkey = $ownerPkey RETURN n LIMIT 1;");
test("Notes updated in place", isset($node['notes']) && $node['notes'] === $analysisA2['notes']);
test("created_timestamp preserved", isset($node['created_timestamp']) && $node['created_timestamp'] === $createdTsA);
test("modified_timestamp advanced", isset($node['modified_timestamp']) && $node['modified_timestamp'] > $createdTsA);
test("File replaced with new bytes", sha1_file("$toolsDir/$filenameA") === sha1_file($blob2Path));

// ---------------------------------------------------------------------------
// Listing
// ---------------------------------------------------------------------------

section("Listing (GET /db/mystrabotools)");

usleep(60000);
$analysisB = $analysisA;
$analysisB['id'] = $uuidB;
$analysisB['spotName'] = 'E2ETOOLS second analysis';
unset($analysisB['position']); // position:null case — imported-from-library photo
$r = uploadAnalysis("$baseUrl/db/strabotools", $analysisB, $blob1Path, $ownerEmail, $testPassword);
test("Second analysis created", $r['code'] == 201);
$filenameB = isset($r['body']['filename']) ? $r['body']['filename'] : '';

$r = httpJson('GET', "$baseUrl/db/mystrabotools", $ownerEmail, $testPassword);
test("List returns 200", $r['code'] == 200, "got {$r['code']}");
$list = isset($r['body']['analyses']) ? $r['body']['analyses'] : null;
test("List has both analyses", is_array($list) && count($list) === 2, "count=" . (is_array($list) ? count($list) : 'null'));
test("List is newest-first", is_array($list) && count($list) === 2 && $list[0]['id'] === $uuidB && $list[1]['id'] === $uuidA);
test("List entry carries decoded analysis", is_array($list) && isset($list[0]['analysis']['spotName']) && $list[0]['analysis']['spotName'] === 'E2ETOOLS second analysis');
test("Positionless analysis lists cleanly", is_array($list) && !isset($list[0]['analysis']['position']['latitude']));

// ---------------------------------------------------------------------------
// Retrieval
// ---------------------------------------------------------------------------

section("Retrieval (GET /db/strabotools/<id>)");

$r = httpJson('GET', "$baseUrl/db/strabotools/$uuidA", $ownerEmail, $testPassword);
test("Image GET returns 200", $r['code'] == 200, "got {$r['code']}");
test("Image GET serves stored bytes", sha1($r['raw']) === sha1_file($blob2Path));
test("Image GET content-type is image/jpeg", strpos((string)$r['ctype'], 'image/jpeg') === 0, "got '{$r['ctype']}'");

$r = httpJson('GET', "$baseUrl/db/strabotools/$uuidA?format=json", $ownerEmail, $testPassword);
test("Metadata GET returns 200", $r['code'] == 200, "got {$r['code']}");
test("Metadata carries id + filename", isset($r['body']['id'], $r['body']['filename']) && $r['body']['id'] === $uuidA && (string)$r['body']['filename'] === (string)$filenameA);
test("Metadata carries decoded analysis", isset($r['body']['analysis']['notes']) && $r['body']['analysis']['notes'] === $analysisA2['notes']);

// ---------------------------------------------------------------------------
// Auth gates + per-user id space
// ---------------------------------------------------------------------------

section("Auth gates + per-user UUID namespace");

$r = httpJson('GET', "$baseUrl/db/strabotools/$uuidA", $strangerEmail, $testPassword);
test("Stranger GET -> 404", $r['code'] == 404, "got {$r['code']}");

$r = httpJson('DELETE', "$baseUrl/db/strabotools/$uuidA", $strangerEmail, $testPassword);
test("Stranger DELETE -> 404", $r['code'] == 404, "got {$r['code']}");
$stillThere = (int)$neodb->get_var("MATCH (n:ToolsAnalysis) WHERE n.id = \"$uuidA\" AND n.userpkey = $ownerPkey RETURN count(n);");
test("Owner's node untouched by stranger DELETE", $stillThere === 1);

$r = httpJson('GET', "$baseUrl/db/mystrabotools", $strangerEmail, $testPassword);
test("Stranger list is empty", $r['code'] == 200 && isset($r['body']['analyses']) && count($r['body']['analyses']) === 0);

// Same UUID uploaded by a different user must NOT hijack the owner's record
$analysisHijack = $analysisA;
$analysisHijack['spotName'] = 'E2ETOOLS stranger upload, same uuid';
$r = uploadAnalysis("$baseUrl/db/strabotools", $analysisHijack, $blob1Path, $strangerEmail, $testPassword);
test("Stranger upload with owner's UUID -> own record", $r['code'] == 201 && (string)$r['body']['filename'] !== (string)$filenameA);
$strangerFilename = isset($r['body']['filename']) ? $r['body']['filename'] : '';
$ownerNode = $neodb->getNode("MATCH (n:ToolsAnalysis) WHERE n.id = \"$uuidA\" AND n.userpkey = $ownerPkey RETURN n LIMIT 1;");
test("Owner's record not hijacked", isset($ownerNode['spotName']) && $ownerNode['spotName'] === $analysisA['spotName']);

// Anonymous: app-layer behavior depends on whether the vhost fronts /db/
// with extauth. Either the request is rejected (401/403) or it resolves
// to userpkey 0, which must see nothing.
$r = httpJson('GET', "$baseUrl/db/mystrabotools");
$anonOk = in_array($r['code'], [401, 403]) || ($r['code'] == 200 && isset($r['body']['analyses']) && count($r['body']['analyses']) === 0);
test("Anonymous request rejected or isolated", $anonOk, "code={$r['code']} body={$r['raw']}");

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

section("Validation");

$r = uploadAnalysis("$baseUrl/db/strabotools", null, $blob1Path, $ownerEmail, $testPassword);
test("Missing analysis -> 400", $r['code'] == 400, "got {$r['code']}");

$r = uploadAnalysis("$baseUrl/db/strabotools", 'this is not json', $blob1Path, $ownerEmail, $testPassword);
test("Malformed analysis JSON -> 400", $r['code'] == 400, "got {$r['code']}");

$badId = $analysisA;
$badId['id'] = 'Dfsgsdgf';
$r = uploadAnalysis("$baseUrl/db/strabotools", $badId, $blob1Path, $ownerEmail, $testPassword);
test("Non-UUID analysis.id -> 400", $r['code'] == 400, "got {$r['code']}");

$r = uploadAnalysis("$baseUrl/db/strabotools", $analysisA, null, $ownerEmail, $testPassword);
test("Missing image file -> 400", $r['code'] == 400, "got {$r['code']}");

$r = httpJson('GET', "$baseUrl/db/strabotools/not-a-uuid", $ownerEmail, $testPassword);
test("GET non-UUID id -> 404", $r['code'] == 404, "got {$r['code']}");

$r = httpJson('GET', "$baseUrl/db/strabotools/99999999-9999-9999-9999-999999999999", $ownerEmail, $testPassword);
test("GET unknown UUID -> 404", $r['code'] == 404, "got {$r['code']}");

$r = httpJson('DELETE', "$baseUrl/db/strabotools/not-a-uuid", $ownerEmail, $testPassword);
test("DELETE non-UUID id -> 404", $r['code'] == 404, "got {$r['code']}");

// ---------------------------------------------------------------------------
// JWT twin (/jwtdb/)
// ---------------------------------------------------------------------------

section("JWT twin (/jwtauth + /jwtdb)");

$r = httpJson('POST', "$baseUrl/jwtauth/login.php", null, null, null, ['email' => $ownerEmail, 'password' => $testPassword]);
test("JWT login returns tokens", $r['code'] == 200 && isset($r['body']['access_token'], $r['body']['refresh_token']), "got {$r['code']}: {$r['raw']}");
$accessToken = isset($r['body']['access_token']) ? $r['body']['access_token'] : '';
$refreshToken = isset($r['body']['refresh_token']) ? $r['body']['refresh_token'] : '';

$r = httpJson('GET', "$baseUrl/jwtdb/mystrabotools", null, null, $accessToken);
test("JWT list matches Basic list", $r['code'] == 200 && isset($r['body']['analyses']) && count($r['body']['analyses']) === 2, "got {$r['code']}: {$r['raw']}");

usleep(60000);
$analysisC = $analysisA;
$analysisC['id'] = $uuidC;
$analysisC['spotName'] = 'E2ETOOLS uploaded via jwtdb';
$r = uploadAnalysis("$baseUrl/jwtdb/strabotools", $analysisC, $blob1Path, null, null, $accessToken);
test("JWT upload returns 201", $r['code'] == 201, "got {$r['code']}: {$r['raw']}");
$filenameC = isset($r['body']['filename']) ? $r['body']['filename'] : '';
$jwtNodeCount = (int)$neodb->get_var("MATCH (n:ToolsAnalysis) WHERE n.id = \"$uuidC\" AND n.userpkey = $ownerPkey RETURN count(n);");
test("JWT upload owned by JWT user", $jwtNodeCount === 1);

$r = httpJson('POST', "$baseUrl/jwtauth/refresh.php", null, null, null, ['refresh_token' => $refreshToken]);
test("Refresh grants new access token (multi-use refresh)", $r['code'] == 200 && isset($r['body']['access_token']), "got {$r['code']}: {$r['raw']}");

$r = httpJson('DELETE', "$baseUrl/jwtdb/strabotools/$uuidC", null, null, $accessToken);
test("JWT DELETE returns 204", $r['code'] == 204, "got {$r['code']}");
test("JWT-deleted file removed", !file_exists("$toolsDir/$filenameC"));

// ---------------------------------------------------------------------------
// StraboField isolation
// ---------------------------------------------------------------------------

section("StraboField isolation");

$imageCountAfter = (int)$neodb->get_var("MATCH (n:Image) RETURN count(n);");
test("No :Image nodes created", $imageCountAfter === $imageCountBefore, "before=$imageCountBefore after=$imageCountAfter");

$relCount = (int)$neodb->get_var("MATCH (n:ToolsAnalysis)--() RETURN count(*);");
test("ToolsAnalysis nodes have no relationships", $relCount === 0, "count=$relCount");

$straboLabelCount = (int)$neodb->get_var("MATCH (n:ToolsAnalysis:Strabo) RETURN count(n);");
test("ToolsAnalysis nodes not tagged :Strabo", $straboLabelCount === 0, "count=$straboLabelCount");

$r = httpJson('GET', "$baseUrl/db/myimages", $ownerEmail, $testPassword);
$myimagesClean = !is_array($r['body']) || strpos(json_encode($r['body']), $uuidA) === false;
test("Analyses invisible to /db/myimages", $myimagesClean, $r['raw']);

test("Nothing written to dbimages", !file_exists("/srv/app/www/dbimages/$filenameA"));

// ---------------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------------

section("Delete (DELETE /db/strabotools/<id>)");

$r = httpJson('DELETE', "$baseUrl/db/strabotools/$uuidA", $ownerEmail, $testPassword);
test("DELETE returns 204", $r['code'] == 204, "got {$r['code']}");
$gone = (int)$neodb->get_var("MATCH (n:ToolsAnalysis) WHERE n.id = \"$uuidA\" AND n.userpkey = $ownerPkey RETURN count(n);");
test("Node deleted", $gone === 0);
test("File deleted", !file_exists("$toolsDir/$filenameA"));

$r = httpJson('DELETE', "$baseUrl/db/strabotools/$uuidA", $ownerEmail, $testPassword);
test("Re-DELETE -> 404", $r['code'] == 404, "got {$r['code']}");

// ---------------------------------------------------------------------------
// Cleanup + residue check
// ---------------------------------------------------------------------------

section("Cleanup");

foreach ([$filenameB, $strangerFilename] as $fn) {
    if ($fn !== '' && file_exists("$toolsDir/$fn")) {
        unlink("$toolsDir/$fn");
    }
}
$neodb->query("MATCH (n:ToolsAnalysis) WHERE n.userpkey IN [$ownerPkey, $strangerPkey] DETACH DELETE n;");
$db->query("DELETE FROM refresh_tokens WHERE userpkey IN ($ownerPkey, $strangerPkey)");
$db->query("DELETE FROM users WHERE email LIKE 'strabotools_%@test.strabospot.org'");

$residueNodes = (int)$neodb->get_var("MATCH (n:ToolsAnalysis) WHERE n.userpkey IN [$ownerPkey, $strangerPkey] RETURN count(n);");
$residueUsers = (int)$db->get_var("SELECT count(*) FROM users WHERE email LIKE 'strabotools_%@test.strabospot.org'");
$residueFiles = 0;
foreach ([$filenameA, $filenameB, $filenameC, $strangerFilename] as $fn) {
    if ($fn !== '' && file_exists("$toolsDir/$fn")) {
        $residueFiles++;
    }
}
test("Zero residue (nodes/users/files)", $residueNodes === 0 && $residueUsers === 0 && $residueFiles === 0,
    "nodes=$residueNodes users=$residueUsers files=$residueFiles");

if ($createdToolsDir) {
    $leftover = array_diff(scandir($toolsDir), ['.', '..']);
    if (count($leftover) === 0) {
        rmdir($toolsDir);
    }
}
unlink($blob1Path);
unlink($blob2Path);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n\033[1;33m=== RESULTS: $passed passed, $failed failed ===\033[0m\n\n";
exit($failed === 0 ? 0 : 1);
