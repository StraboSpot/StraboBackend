<?php
/**
 * File: e2e_field_linking_id.php
 * Description: Field linking id over real HTTP, the way the StraboField app
 *              will use it (docs: StraboField_to_StraboSamples_Howto_v2):
 *
 *                A. POST /samplesdb/sample creates the "sample from another
 *                   system"; GET /samplesdb/mysamples lists it (the picker)
 *                B. upload an ESTABLISHED rich sample-spot + parent stub via
 *                   /db/datasetspots (no key): row under the local id
 *                C. re-upload with strabosamples_id: GET /samplesdb/sample/{B}
 *                   shows the field link; the local-id row is gone (404)
 *                D. GET /db/datasetspots round-trips id + strabosamples_id
 *                   untouched, stub untouched
 *                E. PUT a name on the samples API: writeback lands in the
 *                   spot and keeps both ids
 *                F. re-upload WITHOUT the key: unlinked again
 *                G. /StraboFieldDatasetDetail/?sample_id={B} while linked
 *                H. deleting the spot of a linked, web-made sample keeps it
 *
 *              Needs the test users from tests/collaboration/setup_test_data.php.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_field_linking_id.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$password = 'testpass123';
$email    = 'owner@test.strabospot.org';
$ownerPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
if ($ownerPkey <= 0) {
    echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n";
    exit(1);
}
$up = "$email:$password";

$stamp = time();
$base  = 97778 * 10000000 + ($stamp % 10000000);
$NEO_MIN = 977780000000; $NEO_MAX = 977789999999;
$projectId = $base + 1;
$datasetId = $base + 2;
$spotRich  = $base + 11;   // rich sample-spot; spot id == local sample id
$spotParent= $base + 12;   // parent with the stub
$linkId    = 'abcd0001-2222-4333-8444-c0ffee977781';

function http($method, $path, $jsonBody, $userPass = null) {
    if ($userPass === null) $userPass = $GLOBALS['up'];
    $bodyFile = tempnam(sys_get_temp_dir(), 'link_e2e_');
    $cmd  = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    $cmd .= "-u " . escapeshellarg($userPass) . " -X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) {
        $cmd .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
        $cmd .= "-d " . escapeshellarg($jsonBody) . " ";
    }
    $cmd .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}
function richFeat($spotId, $name, $ts, $linkId = null) {
    $s = array('id' => $spotId, 'label' => $name, 'sample_id_name' => $name,
        'sample_description' => 'collected in the field', 'material_type' => 'intact_rock',
        'main_sampling_purpose' => 'petrology');
    if ($linkId !== null) $s['strabosamples_id'] = $linkId;
    return array('type' => 'Feature',
        'geometry' => array('type' => 'Point', 'coordinates' => array(-101.0, 41.0)),
        'properties' => array('id' => $spotId, 'name' => $name, 'modified_timestamp' => $ts,
            'isSample' => 1, 'samples' => array($s)));
}
function parentFeat($spotId, $stubId, $ts) {
    return array('type' => 'Feature',
        'geometry' => array('type' => 'Point', 'coordinates' => array(-101.0, 41.0)),
        'properties' => array('id' => $spotId, 'name' => "Parent $spotId", 'modified_timestamp' => $ts,
            'samples' => array(array('id' => $stubId))));
}
function bulkBody($features) { return json_encode(array('id' => '', 'features' => $features)); }
function fieldLinkRefs($detail) {
    $out = array();
    $links = isset($detail['subsystem_links']) ? $detail['subsystem_links'] : array();
    // links may be grouped by subsystem or flat; accept both
    $flat = array();
    foreach ((array)$links as $k => $v) {
        if (is_array($v) && isset($v['subsystem'])) $flat[] = $v;
        elseif (is_array($v)) foreach ($v as $vv) if (is_array($vv)) { if (!isset($vv['subsystem'])) $vv['subsystem'] = $k; $flat[] = $vv; }
    }
    foreach ($flat as $l) if ($l['subsystem'] === 'field') $out[] = (string)$l['reference_id'];
    return $out;
}
function pickerById($r) {
    $out = array();
    foreach ((isset($r['json']['samples']) ? $r['json']['samples'] : array()) as $s) $out[(string)$s['id']] = $s;
    return $out;
}
function downloadedFeature($datasetId, $spotId) {
    $r = http('GET', "/db/datasetspots/$datasetId", null);
    $feats = isset($r['json']['features']) ? $r['json']['features'] : array();
    foreach ($feats as $f) if ((string)$f['properties']['id'] === (string)$spotId) return $f;
    return null;
}
function suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, $linkId) {
    $neodb->query("MATCH (s:Spot)-[:HAS_SAMPLE]->(x:Sample) WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX DETACH DELETE x");
    $neodb->query("MATCH (s:Spot) WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_MIN AND d.id <= $NEO_MAX DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id >= $NEO_MIN AND p.id <= $NEO_MAX DETACH DELETE p");
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1 AND (id LIKE '97778%' OR id = $2)",
        array($ownerPkey, $linkId));
    $db->query("DELETE FROM sample  WHERE user_pkey = $ownerPkey AND strabo_sample_id LIKE '97778%'");
    $db->query("DELETE FROM spot    WHERE user_pkey = $ownerPkey AND strabo_spot_id LIKE '97778%'");
    $db->query("DELETE FROM dataset WHERE user_pkey = $ownerPkey AND strabo_dataset_id LIKE '97778%'");
    $db->query("DELETE FROM project WHERE user_pkey = $ownerPkey AND strabo_project_id LIKE '97778%'");
}

suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, $linkId);

try {

$neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, desc_project_name:'linking id e2e', created_by:$ownerPkey, centroid:'POINT (-101.0 41.0)'})");
$neodb->query("MATCH (p:Project {id:$projectId, userpkey:$ownerPkey})
               CREATE (d:Dataset {id:$datasetId, userpkey:$ownerPkey, name:'linking id e2e ds', created_by:$ownerPkey})
               CREATE (p)-[:HAS_DATASET]->(d)");
$ts = (int)(microtime(true) * 1000);

echo "=== A: the sample from another system + the picker ===\n";
$r = http('POST', '/samplesdb/sample', json_encode(array('id' => $linkId, 'name' => 'Thin section TS-9',
    'description' => 'made in the lab')));
check("POST /samplesdb/sample 200", $r['status'] === 200);
$r = http('GET', '/samplesdb/mysamples', null);
$seen = false;
foreach ((isset($r['json']['samples']) ? $r['json']['samples'] : array()) as $s) {
    if ($s['id'] === $linkId && (int)$s['userpkey'] === $ownerPkey) $seen = true;
}
check("picker lists it as owned", $seen);

echo "\n=== B: established Field sample uploads with no key ===\n";
$r = http('POST', "/db/datasetspots/$datasetId", bulkBody(array(
    richFeat($spotRich, 'Field FS-1', $ts), parentFeat($spotParent, $spotRich, $ts))));
check("upload 2xx", $r['status'] >= 200 && $r['status'] < 300);
$r = http('GET', "/samplesdb/sample/$spotRich", null);
check("row exists under the local id", $r['status'] === 200 && fieldLinkRefs($r['json']) === array((string)$spotRich));

echo "\n=== B2: picker omit=field hides the field-only row, keeps the lab sample ===\n";
$r = http('GET', '/samplesdb/mysamples?omit=field&include_subsystem_flags=1', null);
$by = pickerById($r);
check("200 with the omit echo", $r['status'] === 200 && isset($r['json']['omit']) && $r['json']['omit'] === array('field'));
check("field-only row hidden", !isset($by[(string)$spotRich]));
check("lab sample listed, has_field_data false", isset($by[$linkId]) && $by[$linkId]['has_field_data'] === false);
check("row carries has_field_data / has_micro_data / has_experimental_data / experimental_link_count",
    isset($by[$linkId]) && array_key_exists('has_micro_data', $by[$linkId])
    && array_key_exists('has_experimental_data', $by[$linkId]) && $by[$linkId]['has_experimental_data'] === false
    && array_key_exists('experimental_link_count', $by[$linkId]));
check("count matches the rows", (int)$r['json']['count'] === count($by));
$r = http('GET', '/samplesdb/mysamples', null);
check("plain list still shows the field-only row", isset(pickerById($r)[(string)$spotRich]));
$r = http('GET', '/samplesdb/mysamples?omit=bogus', null);
check("unknown omit value = 400", $r['status'] === 400 && isset($r['json']['Error']));

echo "\n=== C: same spot re-uploads WITH strabosamples_id ===\n";
$ts2 = $ts + 1000;
$r = http('POST', "/db/datasetspots/$datasetId", bulkBody(array(
    richFeat($spotRich, 'Field FS-1', $ts2, $linkId), parentFeat($spotParent, $spotRich, $ts2))));
check("upload 2xx", $r['status'] >= 200 && $r['status'] < 300);
check("upload response is clean JSON (no PHP warnings)", is_array($r['json']));
$r = http('GET', "/samplesdb/sample/$linkId", null);
check("linked sample now shows the field link to the spot",
    $r['status'] === 200 && fieldLinkRefs($r['json']) === array((string)$spotRich));
check("Field's name won the shared field", isset($r['json']['name']) && $r['json']['name'] === 'Field FS-1');
$r = http('GET', "/samplesdb/sample/$spotRich", null);
check("local-id row is gone (404)", $r['status'] === 404);

echo "\n=== C2: a web-made sample linked to a spot now counts as a Field sample ===\n";
// It holds only a Field slice now (no Micro/Exp origin), so omit=field hides it
// until it is unlinked (checked again in F). A Micro/Exp sample in the same
// position stays listed with has_field_data true (smoke 2b).
$r = http('GET', '/samplesdb/mysamples?omit=field&include_subsystem_flags=1', null);
$by = pickerById($r);
check("omit=field hides the linked web-made sample (Field is its only origin now)", !isset($by[$linkId]));
check("folded local-id row is not listed", !isset($by[(string)$spotRich]));
$r = http('GET', '/samplesdb/mysamples?include_subsystem_flags=1', null);
$by = pickerById($r);
check("plain list shows it with has_field_data true", isset($by[$linkId]) && $by[$linkId]['has_field_data'] === true);

echo "\n=== D: download round-trips both ids ===\n";
$f = downloadedFeature($datasetId, $spotRich);
$s0 = $f ? $f['properties']['samples'][0] : array();
check("samples[0].id still the local id", isset($s0['id']) && (string)$s0['id'] === (string)$spotRich);
check("samples[0].strabosamples_id intact", isset($s0['strabosamples_id']) && $s0['strabosamples_id'] === $linkId);
$p = downloadedFeature($datasetId, $spotParent);
$stub = $p ? $p['properties']['samples'][0] : array();
check("parent stub still {id} only", is_array($stub) && array_keys($stub) === array('id') && (string)$stub['id'] === (string)$spotRich);

echo "\n=== E: samples-side edit writes back into the spot ===\n";
$r = http('PUT', "/samplesdb/sample/$linkId", json_encode(array('name' => 'Renamed on the web')));
if ($r['status'] !== 200) $r = http('POST', "/samplesdb/sample/$linkId", json_encode(array('name' => 'Renamed on the web')));
check("edit accepted", $r['status'] === 200);
$f = downloadedFeature($datasetId, $spotRich);
$s0 = $f ? $f['properties']['samples'][0] : array();
check("writeback reached samples[0]", isset($s0['sample_id_name']) && $s0['sample_id_name'] === 'Renamed on the web');
check("writeback kept id + strabosamples_id", isset($s0['id'], $s0['strabosamples_id'])
    && (string)$s0['id'] === (string)$spotRich && $s0['strabosamples_id'] === $linkId);

echo "\n=== G: ?sample_id= landing resolves the linked id ===\n";
$html = (string)@file_get_contents('http://localhost/StraboFieldDatasetDetail/?sample_id=' . urlencode($linkId));
check("page highlights the holding spot", preg_match('/highlight_spot_id:\s*"?' . $spotRich . '\b/', $html) === 1);

echo "\n=== F: re-upload without the key unlinks ===\n";
$ts3 = (int)(microtime(true) * 1000) + 5000;
$r = http('POST', "/db/datasetspots/$datasetId", bulkBody(array(richFeat($spotRich, 'Field FS-1', $ts3))));
check("upload 2xx", $r['status'] >= 200 && $r['status'] < 300);
$r = http('GET', "/samplesdb/sample/$spotRich", null);
check("local-id row is back with the field link", $r['status'] === 200 && fieldLinkRefs($r['json']) === array((string)$spotRich));
$r = http('GET', "/samplesdb/sample/$linkId", null);
check("the other sample survives, without a field link", $r['status'] === 200 && fieldLinkRefs($r['json']) === array());
$r = http('GET', '/samplesdb/mysamples?omit=field&include_subsystem_flags=1', null);
$by = pickerById($r);
check("unlinked web-made sample is back in the omit=field picker, unflagged",
    isset($by[$linkId]) && $by[$linkId]['has_field_data'] === false && !isset($by[(string)$spotRich]));

echo "\n=== H: deleting the spot of a linked web-made sample keeps the sample ===\n";
$ts4 = $ts3 + 5000;
$r = http('POST', "/db/datasetspots/$datasetId", bulkBody(array(richFeat($spotRich, 'Field FS-1', $ts4, $linkId))));
$r = http('GET', "/samplesdb/sample/$linkId", null);
check("re-linked", $r['status'] === 200 && fieldLinkRefs($r['json']) === array((string)$spotRich));
$r = http('DELETE', "/db/feature/$spotRich", null);
// 204 exactly: a PHP fatal after the delete (09-24: getProjectIdFromSpotId returned NULL ->
// setProjectCenter built invalid Cypher) still came back as 200 with an error page as the body.
check("spot delete 204 with no error body", $r['status'] === 204 && stripos($r['body'], 'error') === false);
$r = http('GET', "/samplesdb/sample/$linkId", null);
check("linked sample survives the spot delete, field link gone",
    $r['status'] === 200 && fieldLinkRefs($r['json']) === array());
check("its field_data is cleared", array_key_exists('field_data', (array)$r['json']) && $r['json']['field_data'] === null);

} finally {
    suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, $linkId);
}

echo "\n";
if ($failures) {
    echo count($failures) . " FAILURE(S):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL CHECKS PASSED\n";
