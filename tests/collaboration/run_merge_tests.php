<?php
/**
 * Collaboration Testing: Project Upload Merge & Permission Tests
 *
 * Companion suite to run_tests.php. Covers what that file does not:
 *   A. Dataset upload (POST /db/datasetspots) permission boundaries
 *   B. Project-level upload merge of tags, templates, measurement templates
 *   C. Per-report ownership merge (mergeReports / mergeReportComments)
 *   D. Halted-state write behavior
 *   E. Non-merged project fields (rock_units, other_features, description, etc.)
 *
 * Run setup_test_data.php first to ensure fixtures exist.
 *
 * Usage: docker exec strabo-php php /srv/app/www/tests/collaboration/run_merge_tests.php
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$baseUrl       = 'http://localhost/db';
$testPassword  = 'testpass123';

$projectSoloId    = 9999999901;
$projectCollabId  = 9999999902;
$projectHaltedId  = 9999999903;

$datasetCollabOwner  = 8888888802;
$datasetCollabEditor = 8888888803;
$datasetHaltedOwner  = 8888888804;
$datasetHaltedEditor = 8888888805;

$ownerEmail    = 'owner@test.strabospot.org';
$editorEmail   = 'editor@test.strabospot.org';
$readonlyEmail = 'readonly@test.strabospot.org';
$outsiderEmail = 'outsider@test.strabospot.org';

$ownerPkey    = (int) $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$ownerEmail]);
$editorPkey   = (int) $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$editorEmail]);
$readonlyPkey = (int) $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$readonlyEmail]);
$outsiderPkey = (int) $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$outsiderEmail]);

if (!$ownerPkey || !$editorPkey || !$readonlyPkey || !$outsiderPkey) {
    die("ERROR: Test users not found. Run setup_test_data.php first.\n");
}

// ---------------------------------------------------------------------------
// Counters and helpers
// ---------------------------------------------------------------------------
$passed = 0;
$failed = 0;
$tests  = [];

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
        'raw'  => $response,
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
    $tests[] = ['name' => $name, 'passed' => $condition, 'details' => $details];
    echo "[$status] $name";
    if (!$condition && $details !== '') {
        echo " - $details";
    }
    echo "\n";
}

function section($title) {
    echo "\n\033[1;34m=== $title ===\033[0m\n\n";
}

/**
 * Direct-write a set of project-level fields onto the Neo4j Project node owned
 * by $ownerPkey. Values are JSON-encoded (or '' to clear) and stored under the
 * names getProject() reads from. Pass null to leave a field untouched.
 */
function seedProject($neodb, $projectId, $ownerPkey, $fields) {
    $map = [
        'tags'               => 'json_tags',
        'reports'            => 'json_reports',
        'rock_units'         => 'json_rock_units',
        'other_features'     => 'json_other_features',
        'description'        => 'json_description',
        'relationships'      => 'json_relationships',
        'relationship_types' => 'json_relationship_types',
        'templates'          => 'json_templates',
        'preferences'        => 'preferences',
        'tools'              => 'tools',
        'other_maps'         => 'other_maps',
    ];
    $sets = [];
    foreach ($fields as $key => $value) {
        if (!isset($map[$key])) continue;
        $col = $map[$key];
        if ($value === null) continue;
        if ($value === '') {
            $sets[] = "p.$col = ''";
        } else {
            $json = json_encode($value);
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $json);
            $sets[] = "p.$col = '$escaped'";
        }
    }
    if (empty($sets)) return;
    $setClause = implode(', ', $sets);
    $neodb->query("MATCH (p:Project {id: $projectId, userpkey: $ownerPkey}) SET $setClause");
}

/** Clear the merge-relevant fields on a project (used between scenarios). */
function resetProject($neodb, $projectId, $ownerPkey) {
    $neodb->query("
        MATCH (p:Project {id: $projectId, userpkey: $ownerPkey})
        REMOVE p.json_tags, p.json_reports, p.json_rock_units, p.json_other_features,
               p.json_description, p.json_relationships, p.json_relationship_types,
               p.json_templates, p.preferences, p.tools, p.other_maps
    ");
}

/** Fetch the project via the API as $user and decode its body. */
function fetchProject($baseUrl, $projectId, $user, $password) {
    return makeRequest('GET', "$baseUrl/project/$projectId", $user, $password);
}

/** Locate a report by id in a fetched project body (array shape from JSON decode). */
function findReport($body, $reportId) {
    if (!is_array($body) || empty($body['reports'])) return null;
    foreach ($body['reports'] as $r) {
        if (isset($r['id']) && (int)$r['id'] === (int)$reportId) return $r;
    }
    return null;
}

/** Locate a tag by id in a fetched project body. */
function findTag($body, $tagId) {
    if (!is_array($body) || empty($body['tags'])) return null;
    foreach ($body['tags'] as $t) {
        if (isset($t['id']) && (int)$t['id'] === (int)$tagId) return $t;
    }
    return null;
}

/** Build a minimal project upload payload that satisfies the controller. */
function projectPayload($projectId, $extra = []) {
    $payload = [
        'id'                 => $projectId,
        'modified_timestamp' => (int)(microtime(true) * 1000),
        'description'        => ['project_name' => 'Merge Test'],
    ];
    return array_merge($payload, $extra);
}

// ---------------------------------------------------------------------------
echo "\n\033[1;33m╔══════════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1;33m║   COLLABORATION MERGE & UPLOAD PERMISSION SUITE              ║\033[0m\n";
echo "\033[1;33m╚══════════════════════════════════════════════════════════════╝\033[0m\n";

// ===========================================================================
// SECTION A: Dataset upload permissions
// ===========================================================================
section("A. Dataset upload permissions (POST /db/datasetspots/{id})");

$spotFactory = function ($idOffset) {
    return [
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [-100.0 + ($idOffset / 1000.0), 40.0]],
        'properties' => [
            'id' => 6666666600 + $idOffset,
            'name' => "Merge Test Spot $idOffset",
        ],
    ];
};

// NOTE: do NOT add a top-level 'id' here. DatasetSpotsController treats a
// FeatureCollection with an 'id' as "add this existing spot to the dataset"
// and 404s if findSpot misses, which is not the path under test.
$bundle = function ($spot) {
    return ['type' => 'FeatureCollection', 'features' => [$spot]];
};

// A1: editor uploads to their own dataset
$r = makeRequest('POST', "$baseUrl/datasetspots/$datasetCollabEditor",
    $editorEmail, $testPassword, $bundle($spotFactory(1)));
test("A1. Editor uploads to their own dataset (active collab) → 200/201",
    $r['code'] == 200 || $r['code'] == 201, "HTTP {$r['code']}");

// A2: editor uploads to owner's dataset
$r = makeRequest('POST', "$baseUrl/datasetspots/$datasetCollabOwner",
    $editorEmail, $testPassword, $bundle($spotFactory(2)));
test("A2. Editor uploads to owner's dataset (active collab) → 403",
    $r['code'] == 403, "HTTP {$r['code']}");

// A3: readonly uploads to owner's dataset
$r = makeRequest('POST', "$baseUrl/datasetspots/$datasetCollabOwner",
    $readonlyEmail, $testPassword, $bundle($spotFactory(3)));
test("A3. Readonly uploads to owner's dataset (active collab) → 403",
    $r['code'] == 403, "HTTP {$r['code']}");

// A4: owner uploads to editor's dataset during active collab
$r = makeRequest('POST', "$baseUrl/datasetspots/$datasetCollabEditor",
    $ownerEmail, $testPassword, $bundle($spotFactory(4)));
test("A4. Owner uploads to editor's dataset (active collab) → 403",
    $r['code'] == 403, "HTTP {$r['code']}");

// A5: owner uploads to editor's former dataset on a halted project
$r = makeRequest('POST', "$baseUrl/datasetspots/$datasetHaltedEditor",
    $ownerEmail, $testPassword, $bundle($spotFactory(5)));
test("A5. Owner uploads to editor's dataset (halted project) → 200/201 (owner regains control)",
    $r['code'] == 200 || $r['code'] == 201, "HTTP {$r['code']}");

// A6: halted editor tries to upload to their own (now readonly) dataset
$r = makeRequest('POST', "$baseUrl/datasetspots/$datasetHaltedEditor",
    $editorEmail, $testPassword, $bundle($spotFactory(6)));
test("A6. Halted editor uploads to their own dataset → 403",
    $r['code'] == 403, "HTTP {$r['code']}");

// A7: outsider tries to upload to a collab dataset (no relationship)
$r = makeRequest('POST', "$baseUrl/datasetspots/$datasetCollabEditor",
    $outsiderEmail, $testPassword, $bundle($spotFactory(7)));
test("A7. Outsider uploads to a collab dataset → 403 or 404",
    $r['code'] == 403 || $r['code'] == 404, "HTTP {$r['code']}");

// ===========================================================================
// SECTION B: Project-level upload merge — tags
// ===========================================================================
section("B. Project upload merge — tags (POST /db/project)");

// B1. Owner upload adds a tag; server already has one tag. Both should remain.
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'tags' => [['id' => 1001, 'name' => 'ServerTag', 'spots' => [7777777702]]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, [
        'tags' => [['id' => 1002, 'name' => 'IncomingTag', 'spots' => [7777777702]]],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
test("B1. Owner upload + new tag → server tag preserved (union by id)",
    $r['code'] == 201 && findTag($g['body'], 1001) !== null && findTag($g['body'], 1002) !== null,
    "HTTP {$r['code']} | T1001=" . (findTag($g['body'], 1001) ? 'yes' : 'no') .
    " T1002=" . (findTag($g['body'], 1002) ? 'yes' : 'no'));

// B2. Owner upload merges spots into a tag that exists on both sides
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'tags' => [['id' => 1003, 'name' => 'Shared', 'spots' => [7777777702]]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, [
        'tags' => [['id' => 1003, 'name' => 'Shared', 'spots' => [7777777703]]],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$t = findTag($g['body'], 1003);
$hasBoth = $t && in_array(7777777702, $t['spots'] ?? []) && in_array(7777777703, $t['spots'] ?? []);
test("B2. Owner upload + overlapping tag → spots union by spot id",
    $r['code'] == 201 && $hasBoth,
    "HTTP {$r['code']} | spots=" . ($t ? json_encode($t['spots']) : 'tag missing'));

// B3. Edit collaborator upload — tags still merge
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'tags' => [['id' => 1004, 'name' => 'ServerTag', 'spots' => [7777777702]]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, [
        'tags' => [['id' => 1005, 'name' => 'EditorTag', 'spots' => [7777777703]]],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
test("B3. Editor upload + new tag → union by id in collaborative path",
    $r['code'] == 201 && findTag($g['body'], 1004) !== null && findTag($g['body'], 1005) !== null,
    "HTTP {$r['code']} | T1004=" . (findTag($g['body'], 1004) ? 'yes' : 'no') .
    " T1005=" . (findTag($g['body'], 1005) ? 'yes' : 'no'));

// B4. Readonly upload → 403
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $readonlyEmail, $testPassword,
    projectPayload($projectCollabId, [
        'tags' => [['id' => 1006, 'name' => 'ReadonlyTag', 'spots' => []]],
    ]));
test("B4. Readonly POSTs project → 403",
    $r['code'] == 403, "HTTP {$r['code']}");

// B5. Outsider POSTs the same project id → creates their own copy under outsider pkey
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $outsiderEmail, $testPassword,
    projectPayload($projectCollabId, [
        'tags' => [['id' => 1007, 'name' => 'OutsiderTag', 'spots' => []]],
    ]));
// Outsider was never a collaborator on this project, so canUploadProjectAsOwner should allow.
// Verify a row exists in Neo4j under outsider's pkey (without disturbing owner's copy).
$outsiderCopy = $neodb->query("MATCH (p:Project {id: $projectCollabId, userpkey: $outsiderPkey}) RETURN p");
$ownerCopyStillThere = $neodb->query("MATCH (p:Project {id: $projectCollabId, userpkey: $ownerPkey}) RETURN p");
test("B5. Outsider POSTs same project id → separate copy under their pkey; owner's untouched",
    ($r['code'] == 201) && count($outsiderCopy) === 1 && count($ownerCopyStillThere) === 1,
    "HTTP {$r['code']} | outsider rows=" . count($outsiderCopy) . " owner rows=" . count($ownerCopyStillThere));
// cleanup outsider's copy
$neodb->query("MATCH (p:Project {id: $projectCollabId, userpkey: $outsiderPkey}) DETACH DELETE p");

// B6. Templates: both sides have measurementTemplates; union by id
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'templates' => [
        'measurementTemplates'       => [['id' => 2001, 'name' => 'ServerMTemp']],
        'activeMeasurementTemplates' => [],
        'useMeasurementTemplates'    => true,
    ],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, [
        'templates' => [
            'measurementTemplates'       => [['id' => 2002, 'name' => 'IncomingMTemp']],
            'activeMeasurementTemplates' => [],
            'useMeasurementTemplates'    => true,
        ],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$mtemps = $g['body']['templates']['measurementTemplates'] ?? [];
$ids = array_column($mtemps, 'id');
test("B6. Owner upload merges measurementTemplates by id",
    $r['code'] == 201 && in_array(2001, $ids, true) && in_array(2002, $ids, true),
    "HTTP {$r['code']} | mtemp ids=" . json_encode($ids));

// B7. Collab project, tag on both sides: incoming PROPERTIES win, spots union.
// (Regression pin for the tag color/rename revert: combineNormalProject used
// to keep the server tag object wholesale.)
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'tags' => [['id' => 3101, 'name' => 'OldName', 'color' => 'red', 'spots' => [7777777702]]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, [
        'tags' => [['id' => 3101, 'name' => 'NewName', 'color' => 'blue', 'spots' => [7777777703]]],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$t = findTag($g['body'], 3101);
test("B7. Collab project + overlapping tag → incoming color/name win, spots union",
    $r['code'] == 201 && $t !== null
    && ($t['color'] ?? '') === 'blue' && ($t['name'] ?? '') === 'NewName'
    && in_array(7777777702, $t['spots'] ?? []) && in_array(7777777703, $t['spots'] ?? []),
    "HTTP {$r['code']} | tag=" . json_encode($t));

// B8. Collab project: tag DELETION still does not stick (union re-adds the
// server copy). Documented pending the tombstone design; this pin exists so
// a future change here is deliberate, not accidental.
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'tags' => [['id' => 3103, 'name' => 'Undeletable', 'spots' => []]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, ['tags' => []]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
test("B8. Collab project + tag omitted from upload → server tag survives (union, tombstones later)",
    $r['code'] == 201 && findTag($g['body'], 3103) !== null,
    "HTTP {$r['code']} | T3103=" . (findTag($g['body'], 3103) ? 'yes' : 'no'));

// ===========================================================================
// SECTION B-S: Solo-project tag snapshot semantics (no collaborator rows)
// ===========================================================================
// A project nobody but the owner has ever held has exactly one writer, so the
// uploaded tag list is the authoritative snapshot: deletions, spot-removals
// and property edits must all stick. This was the behavior from 2024-09-13
// (JMA overwrite fix) until the Dec-2025 collaboration work reintroduced the
// union unconditionally; these are the regression pins for that fix
// (user reports 2026-07-04 and 2026-08-16 / Monte_Grappa).
section("B-S. Solo project tag snapshot (POST /db/project, no collaborators)");

// BS1. Deleting one of two units sticks.
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [
        ['id' => 3001, 'name' => 'Unit Kept',    'type' => 'geologic_unit', 'spots' => [7777777701]],
        ['id' => 3002, 'name' => 'Unit Deleted', 'type' => 'geologic_unit', 'spots' => [7777777701]],
    ],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, [
        'tags' => [['id' => 3001, 'name' => 'Unit Kept', 'type' => 'geologic_unit', 'spots' => [7777777701]]],
    ]));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
test("BS1. Solo: tag absent from upload is DELETED (no resurrection)",
    $r['code'] == 201 && findTag($g['body'], 3001) !== null && findTag($g['body'], 3002) === null,
    "HTTP {$r['code']} | kept=" . (findTag($g['body'], 3001) ? 'yes' : 'no') .
    " deleted=" . (findTag($g['body'], 3002) ? 'STILL PRESENT' : 'gone'));

// BS2. Removing a spot from a unit sticks.
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [['id' => 3003, 'name' => 'Shrinking', 'spots' => [7777777701, 7777777702]]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, [
        'tags' => [['id' => 3003, 'name' => 'Shrinking', 'spots' => [7777777701]]],
    ]));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
$t = findTag($g['body'], 3003);
test("BS2. Solo: spot removed from tag stays removed",
    $r['code'] == 201 && $t !== null
    && in_array(7777777701, $t['spots'] ?? []) && !in_array(7777777702, $t['spots'] ?? []),
    "HTTP {$r['code']} | spots=" . ($t ? json_encode($t['spots']) : 'tag missing'));

// BS3. Property edits (color, name) stick.
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [['id' => 3004, 'name' => 'Old Unit', 'color' => 'red', 'spots' => [7777777701]]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, [
        'tags' => [['id' => 3004, 'name' => 'New Unit', 'color' => 'blue', 'spots' => [7777777701]]],
    ]));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
$t = findTag($g['body'], 3004);
test("BS3. Solo: tag color + name edits stick",
    $r['code'] == 201 && $t !== null
    && ($t['color'] ?? '') === 'blue' && ($t['name'] ?? '') === 'New Unit',
    "HTTP {$r['code']} | tag=" . json_encode($t));

// BS4. Deleting the LAST tag clears the stored copy (empty list must write
// through as "[]", not silently keep the stale server json_tags).
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [['id' => 3005, 'name' => 'Last Unit', 'spots' => []]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, ['tags' => []]));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
$storedTags = $neodb->get_var("MATCH (p:Project {id: $projectSoloId, userpkey: $ownerPkey}) RETURN p.json_tags");
test("BS4. Solo: deleting the last tag clears stored json_tags",
    $r['code'] == 201 && findTag($g['body'], 3005) === null
    && json_decode((string)$storedTags) === [],
    "HTTP {$r['code']} | stored json_tags=" . var_export($storedTags, true));

// BS5. A payload with NO tags field is a partial upload: server tags are
// retained (pre-collaboration overwrite parity; only an explicit list
// replaces).
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [['id' => 3006, 'name' => 'Survivor', 'spots' => []]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
test("BS5. Solo: upload without a tags field retains server tags",
    $r['code'] == 201 && findTag($g['body'], 3006) !== null,
    "HTTP {$r['code']} | T3006=" . (findTag($g['body'], 3006) ? 'yes' : 'no'));

// BS6. Convergence loop (the reporter's exact scenario): delete-then-redownload
// then re-upload the downloaded state → deletion survives the round trip.
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [
        ['id' => 3007, 'name' => 'Maiolica',          'type' => 'geologic_unit', 'spots' => [7777777701]],
        ['id' => 3008, 'name' => 'Scaglia Variegata', 'type' => 'geologic_unit', 'spots' => [7777777701]],
    ],
]);
makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, [
        'tags' => [['id' => 3007, 'name' => 'Maiolica', 'type' => 'geologic_unit', 'spots' => [7777777701]]],
    ]));
$g1 = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, ['tags' => $g1['body']['tags'] ?? []]));
$g2 = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
test("BS6. Solo: delete → download → re-upload round trip converges (deleted unit stays gone)",
    $r['code'] == 201 && findTag($g2['body'], 3007) !== null && findTag($g2['body'], 3008) === null,
    "HTTP {$r['code']} | tags=" . json_encode($g2['body']['tags'] ?? null));

// ===========================================================================
// SECTION B-M: Per-project union merge preference (project_merge_prefs)
// Multi-device shared-credential groups (several iPads, one account) look
// "solo" to the server; a project_merge_prefs row forces the union path so
// one device's upload cannot wipe units another device created. Requires
// sql/project_merge_prefs.sql applied. See docs/GeologicUnits_TagDeletion_Proposal.md.
// ===========================================================================
section("B-M. Union merge preference (multi-device solo project)");

$db->prepare_query("delete from project_merge_prefs where strabo_project_id = $1", array((string)$projectSoloId));
$db->prepare_query(
    "insert into project_merge_prefs (strabo_project_id, project_owner_user_pkey, union_tags, note) values ($1, $2, true, 'merge-suite fixture')",
    array((string)$projectSoloId, $ownerPkey));

// BM1. Flagged solo project: tag absent from the upload SURVIVES (union).
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [
        ['id' => 3101, 'name' => 'Unit Mine',   'type' => 'geologic_unit', 'spots' => [7777777701]],
        ['id' => 3102, 'name' => 'Unit Theirs', 'type' => 'geologic_unit', 'spots' => [7777777702]],
    ],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, [
        'tags' => [['id' => 3101, 'name' => 'Unit Mine', 'type' => 'geologic_unit', 'spots' => [7777777701]]],
    ]));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
test("BM1. Flagged solo: tag absent from upload is KEPT (union, no clobber)",
    $r['code'] == 201 && findTag($g['body'], 3101) !== null && findTag($g['body'], 3102) !== null,
    "HTTP {$r['code']} | mine=" . (findTag($g['body'], 3101) ? 'yes' : 'no') .
    " theirs=" . (findTag($g['body'], 3102) ? 'KEPT' : 'WIPED'));

// BM2. The three-iPad nightly cycle (the 2026-08-28 Wesdome report): each
// device uploads its own local unit list; nobody's units get deleted.
resetProject($neodb, $projectSoloId, $ownerPkey);
$mkUnit = function ($id, $name) {
    return ['id' => $id, 'name' => $name, 'type' => 'geologic_unit', 'spots' => []];
};
makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, ['tags' => [$mkUnit(3103, 'Basalt')]]));
makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, ['tags' => [$mkUnit(3103, 'Basalt'), $mkUnit(3104, 'Gabbro')]]));
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, ['tags' => [$mkUnit(3103, 'Basalt'), $mkUnit(3105, 'Granite')]]));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
test("BM2. Flagged solo: three-device alternating uploads accumulate all units",
    $r['code'] == 201 && findTag($g['body'], 3103) !== null
    && findTag($g['body'], 3104) !== null && findTag($g['body'], 3105) !== null,
    "HTTP {$r['code']} | units=" . json_encode(array_map(
        function ($t) { return $t['name'] ?? '?'; }, $g['body']['tags'] ?? [])));

// BM3. Flagged solo: property edits still win under union; spots still union.
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [['id' => 3106, 'name' => 'Old Name', 'color' => 'red', 'spots' => [7777777701]]],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, [
        'tags' => [['id' => 3106, 'name' => 'New Name', 'color' => 'blue', 'spots' => [7777777702]]],
    ]));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
$t = findTag($g['body'], 3106);
test("BM3. Flagged solo: incoming properties win, spot memberships union",
    $r['code'] == 201 && $t !== null
    && ($t['color'] ?? '') === 'blue' && ($t['name'] ?? '') === 'New Name'
    && in_array(7777777701, $t['spots'] ?? []) && in_array(7777777702, $t['spots'] ?? []),
    "HTTP {$r['code']} | tag=" . json_encode($t));

// BM4. Removing the flag restores solo replace semantics.
$db->prepare_query("delete from project_merge_prefs where strabo_project_id = $1", array((string)$projectSoloId));
resetProject($neodb, $projectSoloId, $ownerPkey);
seedProject($neodb, $projectSoloId, $ownerPkey, [
    'tags' => [
        ['id' => 3107, 'name' => 'Kept',    'type' => 'geologic_unit', 'spots' => []],
        ['id' => 3108, 'name' => 'Dropped', 'type' => 'geologic_unit', 'spots' => []],
    ],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectSoloId", $ownerEmail, $testPassword,
    projectPayload($projectSoloId, [
        'tags' => [['id' => 3107, 'name' => 'Kept', 'type' => 'geologic_unit', 'spots' => []]],
    ]));
$g = fetchProject($baseUrl, $projectSoloId, $ownerEmail, $testPassword);
test("BM4. Flag removed: solo replace semantics return (omitted tag deleted)",
    $r['code'] == 201 && findTag($g['body'], 3107) !== null && findTag($g['body'], 3108) === null,
    "HTTP {$r['code']} | kept=" . (findTag($g['body'], 3107) ? 'yes' : 'no') .
    " dropped=" . (findTag($g['body'], 3108) ? 'STILL PRESENT' : 'gone'));

// B-M cleanup: no pref rows left behind.
$db->prepare_query("delete from project_merge_prefs where strabo_project_id = $1", array((string)$projectSoloId));

// ===========================================================================
// SECTION C: Per-report ownership merge
// ===========================================================================
section("C. Per-report ownership merge (mergeReports / mergeReportComments)");

// Reusable seed/upload helper for report scenarios.
$seedSingleReport = function ($neodb, $projectId, $ownerPkey, $report) {
    seedProject($neodb, $projectId, $ownerPkey, ['reports' => [$report]]);
};

$reportFactory = function ($id, $straboUserId, $spots, $comments = []) {
    $r = [
        'id' => $id,
        'subject' => "Report $id",
        'type' => 'observation',
        'privacy' => 'project',
        'spots' => $spots,
        'images' => [],
        'tags' => [],
        'comments' => $comments,
    ];
    if ($straboUserId !== null) $r['straboUserId'] = $straboUserId;
    return $r;
};

$commentFactory = function ($id, $text, $straboUserId = null) {
    $c = ['id' => $id, 'text' => $text];
    if ($straboUserId !== null) $c['straboUserId'] = $straboUserId;
    return $c;
};

// --- C1: owner-owned report, owner uploads → full replace + comment added
resetProject($neodb, $projectCollabId, $ownerPkey);
$seedSingleReport($neodb, $projectCollabId, $ownerPkey,
    $reportFactory(3001, $ownerPkey, [7777777702], [$commentFactory(9001, 'server')]));
$incoming = $reportFactory(3001, $ownerPkey, [7777777703], [$commentFactory(9002, 'incoming')]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$rep = findReport($g['body'], 3001);
$cIds = array_column($rep['comments'] ?? [], 'id');
test("C1. Owner-owned report, owner uploads → spots replaced; comments union",
    $r['code'] == 201 && in_array(7777777703, $rep['spots'] ?? []) &&
    !in_array(7777777702, $rep['spots'] ?? []) &&
    in_array(9001, $cIds, true) && in_array(9002, $cIds, true),
    "HTTP {$r['code']} | spots=" . json_encode($rep['spots'] ?? []) . " | cIds=" . json_encode($cIds));

// --- C2: editor-owned report, owner uploads → server (editor's) spots preserved; comment merged
resetProject($neodb, $projectCollabId, $ownerPkey);
$seedSingleReport($neodb, $projectCollabId, $ownerPkey,
    $reportFactory(3002, $editorPkey, [7777777702], [$commentFactory(9003, 'server-by-editor')]));
$incoming = $reportFactory(3002, $editorPkey, [7777777703], [$commentFactory(9004, 'incoming-by-owner')]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$rep = findReport($g['body'], 3002);
$cIds = array_column($rep['comments'] ?? [], 'id');
test("C2. Editor-owned report, owner uploads → server spots preserved; comments union",
    $r['code'] == 201 && in_array(7777777702, $rep['spots'] ?? []) &&
    !in_array(7777777703, $rep['spots'] ?? []) &&
    in_array(9003, $cIds, true) && in_array(9004, $cIds, true),
    "HTTP {$r['code']} | spots=" . json_encode($rep['spots'] ?? []) . " | cIds=" . json_encode($cIds));

// --- C3: owner-owned report, editor uploads → server spots preserved; comment merged
resetProject($neodb, $projectCollabId, $ownerPkey);
$seedSingleReport($neodb, $projectCollabId, $ownerPkey,
    $reportFactory(3003, $ownerPkey, [7777777702], [$commentFactory(9005, 'server-by-owner')]));
$incoming = $reportFactory(3003, $ownerPkey, [7777777703], [$commentFactory(9006, 'incoming-by-editor')]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$rep = findReport($g['body'], 3003);
$cIds = array_column($rep['comments'] ?? [], 'id');
test("C3. Owner-owned report, editor uploads → server spots preserved; comments union",
    $r['code'] == 201 && in_array(7777777702, $rep['spots'] ?? []) &&
    !in_array(7777777703, $rep['spots'] ?? []) &&
    in_array(9005, $cIds, true) && in_array(9006, $cIds, true),
    "HTTP {$r['code']} | spots=" . json_encode($rep['spots'] ?? []) . " | cIds=" . json_encode($cIds));

// --- C4: editor-owned report, editor uploads → full replace + comment added
resetProject($neodb, $projectCollabId, $ownerPkey);
$seedSingleReport($neodb, $projectCollabId, $ownerPkey,
    $reportFactory(3004, $editorPkey, [7777777702], [$commentFactory(9007, 'server-by-editor')]));
$incoming = $reportFactory(3004, $editorPkey, [7777777703], [$commentFactory(9008, 'incoming-by-editor')]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$rep = findReport($g['body'], 3004);
$cIds = array_column($rep['comments'] ?? [], 'id');
test("C4. Editor-owned report, editor uploads → spots replaced; comments union",
    $r['code'] == 201 && in_array(7777777703, $rep['spots'] ?? []) &&
    !in_array(7777777702, $rep['spots'] ?? []) &&
    in_array(9007, $cIds, true) && in_array(9008, $cIds, true),
    "HTTP {$r['code']} | spots=" . json_encode($rep['spots'] ?? []) . " | cIds=" . json_encode($cIds));

// --- C5: legacy report (no straboUserId on either side), owner uploads → full replace
resetProject($neodb, $projectCollabId, $ownerPkey);
$seedSingleReport($neodb, $projectCollabId, $ownerPkey,
    $reportFactory(3005, null, [7777777702], []));
$incoming = $reportFactory(3005, null, [7777777703], []);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$rep = findReport($g['body'], 3005);
test("C5. Legacy (no straboUserId), owner uploads → spots replaced (legacy fallback)",
    $r['code'] == 201 && in_array(7777777703, $rep['spots'] ?? []),
    "HTTP {$r['code']} | spots=" . json_encode($rep['spots'] ?? []));

// --- C6: legacy report, editor uploads → server preserved (legacy fallback locks collaborators out)
resetProject($neodb, $projectCollabId, $ownerPkey);
$seedSingleReport($neodb, $projectCollabId, $ownerPkey,
    $reportFactory(3006, null, [7777777702], []));
$incoming = $reportFactory(3006, null, [7777777703], []);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$rep = findReport($g['body'], 3006);
test("C6. Legacy (no straboUserId), editor uploads → server spots preserved",
    $r['code'] == 201 && in_array(7777777702, $rep['spots'] ?? []) &&
    !in_array(7777777703, $rep['spots'] ?? []),
    "HTTP {$r['code']} | spots=" . json_encode($rep['spots'] ?? []));

// --- C7: comment id collision → incoming wins on collision
resetProject($neodb, $projectCollabId, $ownerPkey);
$seedSingleReport($neodb, $projectCollabId, $ownerPkey,
    $reportFactory(3007, $ownerPkey, [7777777702],
        [$commentFactory(9100, 'server-text'), $commentFactory(9101, 'server-other')]));
$incoming = $reportFactory(3007, $ownerPkey, [7777777702],
    [$commentFactory(9100, 'incoming-text'), $commentFactory(9102, 'incoming-new')]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$rep = findReport($g['body'], 3007);
$byId = [];
foreach (($rep['comments'] ?? []) as $c) $byId[$c['id']] = $c;
$collisionWins = isset($byId[9100]) && $byId[9100]['text'] === 'incoming-text';
$allThree = isset($byId[9100]) && isset($byId[9101]) && isset($byId[9102]);
test("C7. Comment id collision → incoming wins; non-colliding comments union",
    $r['code'] == 201 && $collisionWins && $allThree,
    "HTTP {$r['code']} | collisionWins=" . ($collisionWins ? 'yes' : 'no') .
    " ids=" . json_encode(array_keys($byId)));

// --- C8: report only on server, not in upload → preserved
resetProject($neodb, $projectCollabId, $ownerPkey);
$seedSingleReport($neodb, $projectCollabId, $ownerPkey,
    $reportFactory(3008, $editorPkey, [7777777702], []));
// upload contains an unrelated report (3009)
$incoming = $reportFactory(3009, $editorPkey, [7777777703], []);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
test("C8. Server-only report preserved when upload omits it",
    $r['code'] == 201 && findReport($g['body'], 3008) !== null && findReport($g['body'], 3009) !== null,
    "HTTP {$r['code']} | 3008=" . (findReport($g['body'], 3008) ? 'yes' : 'no') .
    " 3009=" . (findReport($g['body'], 3009) ? 'yes' : 'no'));

// --- C9: upload-only report → added as-is
resetProject($neodb, $projectCollabId, $ownerPkey);
$incoming = $reportFactory(3010, $editorPkey, [7777777703], []);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => [$incoming]]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
test("C9. Upload-only report added as-is",
    $r['code'] == 201 && findReport($g['body'], 3010) !== null,
    "HTTP {$r['code']} | report present=" . (findReport($g['body'], 3010) ? 'yes' : 'no'));

// ===========================================================================
// SECTION D: Halted state write behavior
// ===========================================================================
section("D. Halted state write behavior");

// D1: halted editor POSTs the project → blocked (former collaborator gate)
$r = makeRequest('POST', "$baseUrl/project/$projectHaltedId", $editorEmail, $testPassword,
    projectPayload($projectHaltedId, ['tags' => [['id' => 1010, 'name' => 'X', 'spots' => []]]]));
test("D1. Halted editor POSTs halted project → blocked (non-201)",
    $r['code'] != 201 && $r['code'] != 200,
    "HTTP {$r['code']}");

// D2: halted editor can still GET the project
$r = fetchProject($baseUrl, $projectHaltedId, $editorEmail, $testPassword);
test("D2. Halted editor can still GET project → 200",
    $r['code'] == 200, "HTTP {$r['code']}");

// D3: owner can POST to halted project (full control restored)
resetProject($neodb, $projectHaltedId, $ownerPkey);
seedProject($neodb, $projectHaltedId, $ownerPkey, ['tags' => []]);
$r = makeRequest('POST', "$baseUrl/project/$projectHaltedId", $ownerEmail, $testPassword,
    projectPayload($projectHaltedId, ['tags' => [['id' => 1011, 'name' => 'OwnerHaltedTag', 'spots' => []]]]));
$g = fetchProject($baseUrl, $projectHaltedId, $ownerEmail, $testPassword);
test("D3. Owner POSTs halted project → 201 and tag is stored",
    $r['code'] == 201 && findTag($g['body'], 1011) !== null,
    "HTTP {$r['code']} | T1011=" . (findTag($g['body'], 1011) ? 'yes' : 'no'));

// ===========================================================================
// SECTION E: Non-merged project fields under collaborative path
// ===========================================================================
// The merge functions only operate on tags / reports / templates. Every other
// top-level field returned by getProject() is taken from the side that "wins"
// the project as a whole:
//   - combineNormalProject:        from incoming  → owner edits stick
//   - combineCollaborativeProject: from server    → collaborator edits dropped
// This block characterizes that behavior rather than asserting it's the spec.
section("E. Non-merged project fields (rock_units, description, etc.)");

// E1. Owner edits rock_units → incoming wins (normal path)
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'rock_units' => [['id' => 'ru-server', 'name' => 'ServerRockUnit']],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, [
        'rock_units' => [['id' => 'ru-incoming', 'name' => 'IncomingRockUnit']],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$ruIds = array_column($g['body']['rock_units'] ?? [], 'id');
test("E1. Owner upload of rock_units → incoming wins (replace)",
    $r['code'] == 201 && in_array('ru-incoming', $ruIds, true) && !in_array('ru-server', $ruIds, true),
    "HTTP {$r['code']} | rock_units=" . json_encode($ruIds));

// E2. Editor edits rock_units → server kept (silently dropped from upload)
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'rock_units' => [['id' => 'ru-server-2', 'name' => 'ServerRockUnit2']],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, [
        'rock_units' => [['id' => 'ru-editor', 'name' => 'EditorRockUnit']],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$ruIds = array_column($g['body']['rock_units'] ?? [], 'id');
test("E2. Editor upload of rock_units → server kept; editor edits dropped (collab path)",
    $r['code'] == 201 && in_array('ru-server-2', $ruIds, true) && !in_array('ru-editor', $ruIds, true),
    "HTTP {$r['code']} | rock_units=" . json_encode($ruIds));

// E3. Editor edits other_features → server kept
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'other_features' => [['id' => 'of-server', 'name' => 'ServerOF']],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, [
        'other_features' => [['id' => 'of-editor', 'name' => 'EditorOF']],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$ofIds = array_column($g['body']['other_features'] ?? [], 'id');
test("E3. Editor upload of other_features → server kept",
    $r['code'] == 201 && in_array('of-server', $ofIds, true) && !in_array('of-editor', $ofIds, true),
    "HTTP {$r['code']} | other_features=" . json_encode($ofIds));

// E4. Editor edits relationships → server kept
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'relationships' => [['id' => 'rel-server', 'type' => 'crosses']],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, [
        'relationships' => [['id' => 'rel-editor', 'type' => 'cuts']],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$relIds = array_column($g['body']['relationships'] ?? [], 'id');
test("E4. Editor upload of relationships → server kept",
    $r['code'] == 201 && in_array('rel-server', $relIds, true) && !in_array('rel-editor', $relIds, true),
    "HTTP {$r['code']} | relationships=" . json_encode($relIds));

// E5. Editor edits preferences → server kept (preferences is a single object, not a list)
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'preferences' => ['marker' => 'server-marker'],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, [
        'preferences' => ['marker' => 'editor-marker'],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$marker = $g['body']['preferences']['marker'] ?? null;
test("E5. Editor upload of preferences → server kept",
    $r['code'] == 201 && $marker === 'server-marker',
    "HTTP {$r['code']} | marker=" . var_export($marker, true));

// E6. Owner edits preferences → incoming wins (normal path)
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'preferences' => ['marker' => 'server-marker'],
]);
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $ownerEmail, $testPassword,
    projectPayload($projectCollabId, [
        'preferences' => ['marker' => 'owner-marker'],
    ]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);
$marker = $g['body']['preferences']['marker'] ?? null;
test("E6. Owner upload of preferences → incoming wins",
    $r['code'] == 201 && $marker === 'owner-marker',
    "HTTP {$r['code']} | marker=" . var_export($marker, true));

// ===========================================================================
// SECTION F: Multi-report mixed-ownership in a single upload
// ===========================================================================
section("F. Multi-report mixed ownership in one upload");

// Seed three reports owned by different parties.
resetProject($neodb, $projectCollabId, $ownerPkey);
seedProject($neodb, $projectCollabId, $ownerPkey, [
    'reports' => [
        $reportFactory(4001, $ownerPkey,  [7777777702], [$commentFactory(9201, 'srv-owner-r1')]),
        $reportFactory(4002, $editorPkey, [7777777702], [$commentFactory(9202, 'srv-editor-r2')]),
        $reportFactory(4003, null,        [7777777702], [$commentFactory(9203, 'srv-legacy-r3')]),
    ],
]);

// Editor uploads modifications to all three in a single payload.
$multiIncoming = [
    $reportFactory(4001, $ownerPkey,  [7777777703], [$commentFactory(9211, 'inc-r1')]),
    $reportFactory(4002, $editorPkey, [7777777703], [$commentFactory(9212, 'inc-r2')]),
    $reportFactory(4003, null,        [7777777703], [$commentFactory(9213, 'inc-r3')]),
];
$r = makeRequest('POST', "$baseUrl/project/$projectCollabId", $editorEmail, $testPassword,
    projectPayload($projectCollabId, ['reports' => $multiIncoming]));
$g = fetchProject($baseUrl, $projectCollabId, $ownerEmail, $testPassword);

$r1 = findReport($g['body'], 4001); // owner-owned → editor uploading → server wins
$r2 = findReport($g['body'], 4002); // editor-owned → editor uploading → incoming wins
$r3 = findReport($g['body'], 4003); // legacy (project owner fallback) → editor → server wins

// F1: each report's spots respect that report's ownership independently
$f1 =
    $r1 && in_array(7777777702, $r1['spots'] ?? []) && !in_array(7777777703, $r1['spots'] ?? []) &&
    $r2 && in_array(7777777703, $r2['spots'] ?? []) && !in_array(7777777702, $r2['spots'] ?? []) &&
    $r3 && in_array(7777777702, $r3['spots'] ?? []) && !in_array(7777777703, $r3['spots'] ?? []);
test("F1. Editor uploads 3 reports of mixed ownership → each merged by its own owner rule",
    $r['code'] == 201 && $f1,
    "HTTP {$r['code']} | r1.spots=" . json_encode($r1['spots'] ?? []) .
    " r2.spots=" . json_encode($r2['spots'] ?? []) .
    " r3.spots=" . json_encode($r3['spots'] ?? []));

// F2: comments union by id across all three reports, regardless of ownership
$c1Ids = array_column($r1['comments'] ?? [], 'id');
$c2Ids = array_column($r2['comments'] ?? [], 'id');
$c3Ids = array_column($r3['comments'] ?? [], 'id');
$f2 =
    in_array(9201, $c1Ids, true) && in_array(9211, $c1Ids, true) &&
    in_array(9202, $c2Ids, true) && in_array(9212, $c2Ids, true) &&
    in_array(9203, $c3Ids, true) && in_array(9213, $c3Ids, true);
test("F2. Multi-report upload → comments union per-report regardless of ownership",
    $r['code'] == 201 && $f2,
    "HTTP {$r['code']} | r1.cIds=" . json_encode($c1Ids) .
    " r2.cIds=" . json_encode($c2Ids) .
    " r3.cIds=" . json_encode($c3Ids));

// ===========================================================================
// SECTION G: Image upload ownership (POST /db/image)
// ===========================================================================
section("G. Image upload ownership (POST /db/image)");

// Helper: write a tiny "image" file and POST it as multipart/form-data.
// ImageController doesn't actually decode the file — it just sha1's and copies it.
$uploadImage = function ($user, $password, $imageId) use ($baseUrl) {
    $tmp = sys_get_temp_dir() . '/strabo_merge_test_img_' . $imageId . '.bin';
    file_put_contents($tmp, "STRABO-TEST-IMAGE-PAYLOAD-$imageId");
    $cfile = new CURLFile($tmp, 'image/jpeg', "img_$imageId.jpg");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$baseUrl/image");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$password");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'id' => $imageId,
        'modified_timestamp' => (int)(microtime(true) * 1000),
        'image_file' => $cfile,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    return ['code' => $code, 'body' => json_decode($resp, true)];
};

// Read an image's userpkey + created_by directly from Neo4j (no auth filter).
$inspectImage = function ($imageId, $userpkey) use ($neodb) {
    $records = $neodb->query("MATCH (i:Image {id: $imageId, userpkey: $userpkey}) RETURN i");
    if (count($records) === 0) return null;
    return $records[0]->get('i')->values();
};

// Clean up any leftover test images first.
$testImageIds = [5555555501, 5555555502, 5555555503];
foreach ($testImageIds as $iid) {
    $neodb->query("MATCH (i:Image {id: $iid}) WHERE i.userpkey IN [$ownerPkey, $editorPkey, $readonlyPkey, $outsiderPkey] DETACH DELETE i");
}

// G1: editor uploads → Image node with userpkey=editor AND created_by=editor
$r = $uploadImage($editorEmail, $testPassword, 5555555501);
$node = $inspectImage(5555555501, $editorPkey);
test("G1. Editor uploads image → stored with userpkey=editor AND created_by=editor",
    $r['code'] == 201 && $node !== null &&
    (int)$node['userpkey'] === $editorPkey && (int)$node['created_by'] === $editorPkey,
    "HTTP {$r['code']} | userpkey=" . ($node['userpkey'] ?? 'null') .
    " created_by=" . ($node['created_by'] ?? 'null'));

// G2: readonly uploads → image is created with readonly's pkey (controller has no auth gate)
$r = $uploadImage($readonlyEmail, $testPassword, 5555555502);
$node = $inspectImage(5555555502, $readonlyPkey);
test("G2. Readonly uploads image → stored with userpkey=readonly (no auth gate at image endpoint)",
    $r['code'] == 201 && $node !== null &&
    (int)$node['userpkey'] === $readonlyPkey && (int)$node['created_by'] === $readonlyPkey,
    "HTTP {$r['code']} | userpkey=" . ($node['userpkey'] ?? 'null') .
    " created_by=" . ($node['created_by'] ?? 'null'));

// G3: same image id uploaded by two different users → two separate nodes (userpkey-scoped lookup)
$r1 = $uploadImage($ownerEmail,  $testPassword, 5555555503);
$r2 = $uploadImage($editorEmail, $testPassword, 5555555503);
$ownerNode  = $inspectImage(5555555503, $ownerPkey);
$editorNode = $inspectImage(5555555503, $editorPkey);
test("G3. Same image id from owner and editor → two separate Image nodes, one per uploader",
    $r1['code'] == 201 && $r2['code'] == 201 &&
    $ownerNode !== null && (int)$ownerNode['userpkey'] === $ownerPkey &&
    $editorNode !== null && (int)$editorNode['userpkey'] === $editorPkey,
    "owner HTTP {$r1['code']} editor HTTP {$r2['code']} | owner.userpkey=" .
    ($ownerNode['userpkey'] ?? 'null') . " editor.userpkey=" . ($editorNode['userpkey'] ?? 'null'));

// Cleanup image nodes (don't bother removing files — they're in /srv/app/www/dbimages and harmless).
foreach ($testImageIds as $iid) {
    $neodb->query("MATCH (i:Image {id: $iid}) WHERE i.userpkey IN [$ownerPkey, $editorPkey, $readonlyPkey, $outsiderPkey] DETACH DELETE i");
}

// ---------------------------------------------------------------------------
// Cleanup: clear the project of test-only state so subsequent runs and the
// existing read-only suite are not affected.
// ---------------------------------------------------------------------------
resetProject($neodb, $projectCollabId, $ownerPkey);
resetProject($neodb, $projectHaltedId, $ownerPkey);
resetProject($neodb, $projectSoloId, $ownerPkey);

// ---------------------------------------------------------------------------
// RESULTS
// ---------------------------------------------------------------------------
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
    foreach ($tests as $t) {
        if (!$t['passed']) {
            echo "  - {$t['name']}";
            if ($t['details']) echo " ({$t['details']})";
            echo "\n";
        }
    }
    echo "\n";
    exit(1);
}

echo "\033[32m✓ All tests passed!\033[0m\n\n";
exit(0);
