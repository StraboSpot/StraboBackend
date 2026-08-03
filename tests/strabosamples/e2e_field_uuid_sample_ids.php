<?php
/**
 * File: e2e_field_uuid_sample_ids.php
 * Description: END-TO-END coverage of STRING/UUID sample ids through the
 *              StraboField upload pathway (fix/field-string-sample-ids,
 *              2026-08-03). Before this fix a UUID sample id "succeeded"
 *              while breaking three side channels:
 *                (1) buildPgDataset's mirror INSERT overflowed
 *                    sample.strabo_sample_id varchar(20) → PHP warning HTML
 *                    leaked ahead of the JSON response (clients saw a
 *                    failed upload) and the mirror row was dropped;
 *                (2) the :Sample child node's id was (int)-cast → dropped
 *                    (alpha-leading UUID, PHP7 0!="" false) or truncated
 *                    (digit-leading UUID → e.g. 17794);
 *                (3) stub detection in db/lib/sample_sync.php was
 *                    numeric-only, so string-id stubs emitted as empty
 *                    legacy samples.
 *
 *              Scenarios (bulk POST /db/datasetspots/{id}):
 *                A. numeric control + alpha-UUID + digit-UUID legacy samples:
 *                   clean JSON response (no warning prefix), spine rows +
 *                   field links, :Sample node ids preserved (int for
 *                   numeric, string for UUIDs), PG mirror rows present
 *                   with full-length strabo_sample_id.
 *                B. DECOUPLED RICH: numeric spot id, samples[0].id = UUID →
 *                   spine row under the UUID, link reference_id = spot id.
 *                C. Stub handling, string ids: parent spot carrying a
 *                   {id: uuid} stub → no empty spine row, no second field
 *                   link, rich row's data intact.
 *                D. Stub regression, numeric ids: classic rich spot
 *                   (spot id == sample id) + parent stub → still skipped.
 *                E. Empty-entry gate: data-less numeric entry with NO rich
 *                   spot → no spine row (new shape rule).
 *                F. Remove mirror: deleting the stub-carrying parent spot
 *                   leaves the rich UUID sample row; deleting the rich spot
 *                   removes it.
 *                G. Download round-trip: UUID ids come back verbatim.
 *
 *              Hermetic: numeric fixtures under the '97779' prefix; UUID
 *              fixtures under the c0ffee97779 suffix namespace. Uses the
 *              four test users from setup_test_data.php (password
 *              testpass123). Cleans before and after; exits non-zero on
 *              any failure.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_field_uuid_sample_ids.php
 *
 * @package    StraboField / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
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
function note($msg) { echo "        $msg\n"; }

// -------------------------------------------------------------------------
// Users
// -------------------------------------------------------------------------
$password = 'testpass123';
$email = 'owner@test.strabospot.org';
$ownerPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
if (!$ownerPkey) {
    echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n";
    exit(1);
}
$up = "$email:$password";
echo "owner=$ownerPkey\n";

// -------------------------------------------------------------------------
// Fixture ids — numeric band 97779xxxxxxx; UUIDs in a c0ffee97779 namespace
// -------------------------------------------------------------------------
$stamp = time();
$base  = 97779 * 10000000 + ($stamp % 10000000);
$NEO_MIN = 977790000000; $NEO_MAX = 977799999999;

$projectId   = $base + 1;
$datasetId   = $base + 2;

$spotA_num   = $base + 11;  $sampleA_num = $base + 61;   // A numeric control
$spotA_alpha = $base + 12;                               // A alpha-UUID holder
$spotA_digit = $base + 13;                               // A digit-UUID holder
$spotB_rich  = $base + 21;                               // B decoupled rich spot (numeric)
$spotC_parent= $base + 22;                               // C parent w/ uuid stub
$spotD_rich  = $base + 31;                               // D classic rich (spot id == sample id)
$spotD_parent= $base + 32;                               // D parent w/ numeric stub
$spotE_empty = $base + 33;  $sampleE_num = $base + 62;   // E data-less numeric entry

$uuidAlpha = 'abcd0001-aaaa-4bbb-8ccc-c0ffee977791';
$uuidDigit = '70020002-dddd-4eee-8fff-c0ffee977792';
$uuidRich  = 'abcd0003-1111-4222-8333-c0ffee977793';

$UUIDS = array($uuidAlpha, $uuidDigit, $uuidRich);

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------
function http($method, $path, $jsonBody, $userPass = null) {
    if ($userPass === null) $userPass = $GLOBALS['up'];
    $bodyFile = tempnam(sys_get_temp_dir(), 'uuid_e2e_');
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
function legacyFeat($spotId, $samples, $ts) {
    return array('type' => 'Feature',
        'geometry' => array('type' => 'Point', 'coordinates' => array(-100.0, 40.0)),
        'properties' => array('id' => $spotId, 'name' => "Spot $spotId",
            'modified_timestamp' => $ts, 'samples' => $samples));
}
function richFeat($spotId, $sampleId, $label, $ts) {
    return array('type' => 'Feature',
        'geometry' => array('type' => 'Point', 'coordinates' => array(-101.0, 41.0)),
        'properties' => array('id' => $spotId, 'name' => "Spot $spotId",
            'modified_timestamp' => $ts, 'isSample' => 1,
            'samples' => array(array('id' => $sampleId, 'label' => $label,
                'sample_id_name' => $label, 'sample_description' => 'rich uuid e2e',
                'material_type' => 'igneous', 'main_sampling_purpose' => 'petrology'))));
}
function sampleEntry($id, $label) {
    return array('id' => $id, 'label' => $label, 'sample_id_name' => $label,
        'sample_description' => 'uuid e2e', 'material_type' => 'igneous',
        'main_sampling_purpose' => 'petrology');
}
function bulkBody($features) { return json_encode(array('id' => '', 'features' => $features)); }

function spineRow($db, $sid, $upk) {
    return $db->get_row_prepared(
        "SELECT name, field_data::text AS fd FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$sid, (int)$upk));
}
function fieldLinks($db, $sid, $upk) {
    return $db->get_results_prepared(
        "SELECT reference_id FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field' ORDER BY reference_id",
        array((string)$sid, (int)$upk));
}

function suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, $UUIDS) {
    $neodb->query("MATCH (s:Spot)-[:HAS_SAMPLE]->(x:Sample) WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX DETACH DELETE x");
    $neodb->query("MATCH (s:Spot) WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_MIN AND d.id <= $NEO_MAX DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id >= $NEO_MIN AND p.id <= $NEO_MAX DETACH DELETE p");
    $uuidList = "'" . implode("','", $UUIDS) . "'";
    foreach (array('sample_changelog', 'sample_collaborators', 'sample_subsystem_links') as $t) {
        $db->query("DELETE FROM strabosamples.$t WHERE sample_userpkey = $ownerPkey AND (sample_id LIKE '97779%' OR sample_id IN ($uuidList))");
    }
    $db->query("DELETE FROM strabosamples.samples WHERE userpkey = $ownerPkey AND (id LIKE '97779%' OR id IN ($uuidList))");
    $db->query("DELETE FROM sample  WHERE user_pkey = $ownerPkey AND sample_id LIKE 'UUIDE2E-%'");
    $db->query("DELETE FROM spot    WHERE user_pkey = $ownerPkey AND strabo_spot_id LIKE '97779%'");
    $db->query("DELETE FROM dataset WHERE user_pkey = $ownerPkey AND strabo_dataset_id LIKE '97779%'");
    $db->query("DELETE FROM project WHERE user_pkey = $ownerPkey AND strabo_project_id LIKE '97779%'");
}

suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, $UUIDS);

try {

$neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, desc_project_name:'UUID e2e', created_by:$ownerPkey, centroid:'POINT (-100.0 40.0)'})");
$neodb->query("MATCH (p:Project {id:$projectId, userpkey:$ownerPkey})
               CREATE (d:Dataset {id:$datasetId, userpkey:$ownerPkey, name:'uuid e2e ds', created_by:$ownerPkey})
               CREATE (p)-[:HAS_DATASET]->(d)");
$ts = (int)(microtime(true) * 1000);

// =========================================================================
echo "=== A: legacy samples — numeric + alpha-UUID + digit-UUID ===\n";
$r = http('POST', "/db/datasetspots/$datasetId", bulkBody(array(
    legacyFeat($spotA_alpha, array(sampleEntry($uuidAlpha, 'UUIDE2E-alpha')), $ts),
    legacyFeat($spotA_num,   array(sampleEntry($sampleA_num, 'UUIDE2E-num')), $ts),
    legacyFeat($spotA_digit, array(sampleEntry($uuidDigit, 'UUIDE2E-digit')), $ts),
)));
check("A upload HTTP 200", $r['status'] === 200);
check("A response is CLEAN JSON (no PHP-warning prefix)", strlen($r['body']) > 0 && $r['body'][0] === '{');
check("A response parses as JSON", is_array($r['json']));

foreach (array(array($sampleA_num, 'UUIDE2E-num', $spotA_num, 'numeric'),
               array($uuidAlpha,  'UUIDE2E-alpha', $spotA_alpha, 'alpha-UUID'),
               array($uuidDigit,  'UUIDE2E-digit', $spotA_digit, 'digit-UUID')) as $c) {
    list($sid, $label, $holder, $tag) = $c;
    $row = spineRow($db, $sid, $ownerPkey);
    check("A [$tag] spine row present, name=$label", $row && $row->name === $label);
    $links = fieldLinks($db, $sid, $ownerPkey);
    check("A [$tag] exactly one field link → holding spot", is_array($links) && count($links) === 1 && $links[0]->reference_id === (string)$holder);
}

// :Sample child nodes — id preserved per format
$xidNum = $neodb->get_var("MATCH (s:Spot {id:$spotA_num})-[:HAS_SAMPLE]->(x:Sample) RETURN x.id LIMIT 1");
check("A [numeric] :Sample node id is the int id", (string)$xidNum === (string)$sampleA_num);
$xidAlpha = $neodb->get_var("MATCH (s:Spot {id:$spotA_alpha})-[:HAS_SAMPLE]->(x:Sample) RETURN x.id LIMIT 1");
check("A [alpha-UUID] :Sample node id preserved as string", (string)$xidAlpha === $uuidAlpha);
$xidDigit = $neodb->get_var("MATCH (s:Spot {id:$spotA_digit})-[:HAS_SAMPLE]->(x:Sample) RETURN x.id LIMIT 1");
check("A [digit-UUID] :Sample node id preserved (NOT truncated to 70020002)", (string)$xidDigit === $uuidDigit);

// PG search-mirror rows with full-length strabo_sample_id
$mirror = $db->get_results(
    "SELECT sample_id, strabo_sample_id FROM sample WHERE user_pkey=$ownerPkey AND sample_id LIKE 'UUIDE2E-%' ORDER BY sample_id");
$mirrorMap = array();
if ($mirror) foreach ($mirror as $m) $mirrorMap[$m->sample_id] = $m->strabo_sample_id;
check("A mirror has all 3 rows", count($mirrorMap) === 3);
check("A mirror numeric strabo_sample_id intact", isset($mirrorMap['UUIDE2E-num']) && $mirrorMap['UUIDE2E-num'] === (string)$sampleA_num);
check("A mirror alpha UUID full 36 chars", isset($mirrorMap['UUIDE2E-alpha']) && $mirrorMap['UUIDE2E-alpha'] === $uuidAlpha);
check("A mirror digit UUID full 36 chars", isset($mirrorMap['UUIDE2E-digit']) && $mirrorMap['UUIDE2E-digit'] === $uuidDigit);

// =========================================================================
echo "=== B: DECOUPLED RICH — numeric spot id, samples[0].id = UUID ===\n";
// NOTE: datasetspots is a REPLACE-contents bulk endpoint; use the
// incremental single-spot controller for all additions after scenario A.
$r = http('POST', "/db/datasetsinglespot/$datasetId",
    json_encode(richFeat($spotB_rich, $uuidRich, 'UUIDE2E-rich', $ts)));
check("B upload HTTP 200 + clean JSON", $r['status'] === 200 && $r['body'][0] === '{');
$row = spineRow($db, $uuidRich, $ownerPkey);
check("B spine row under the UUID", $row && $row->name === 'UUIDE2E-rich');
$links = fieldLinks($db, $uuidRich, $ownerPkey);
check("B field link reference_id = the (numeric) sample-spot id", is_array($links) && count($links) === 1 && $links[0]->reference_id === (string)$spotB_rich);

// =========================================================================
echo "=== C: string-id stub on a parent spot — skipped, rich row untouched ===\n";
$r = http('POST', "/db/datasetsinglespot/$datasetId",
    json_encode(legacyFeat($spotC_parent, array(array('id' => $uuidRich)), $ts)));   // stub {id} only
check("C upload HTTP 200 + clean JSON", $r['status'] === 200 && $r['body'][0] === '{');
$row = spineRow($db, $uuidRich, $ownerPkey);
check("C rich row still present with data (name intact)", $row && $row->name === 'UUIDE2E-rich');
check("C rich row field_data not clobbered to stub", $row && strpos((string)$row->fd, 'UUIDE2E-rich') !== false);
$links = fieldLinks($db, $uuidRich, $ownerPkey);
check("C still exactly one field link (no stub link added)", is_array($links) && count($links) === 1 && $links[0]->reference_id === (string)$spotB_rich);

// =========================================================================
echo "=== D: numeric stub regression — classic rich, parent stub skipped ===\n";
$r = http('POST', "/db/datasetsinglespot/$datasetId",
    json_encode(richFeat($spotD_rich, $spotD_rich, 'UUIDE2E-classic', $ts)));
$r2 = http('POST', "/db/datasetsinglespot/$datasetId",
    json_encode(legacyFeat($spotD_parent, array(array('id' => $spotD_rich)), $ts)));
check("D uploads HTTP 200 + clean JSON", $r['status'] === 200 && $r['body'][0] === '{' && $r2['status'] === 200 && $r2['body'][0] === '{');
$row = spineRow($db, $spotD_rich, $ownerPkey);
check("D classic rich spine row present", $row && $row->name === 'UUIDE2E-classic');
$links = fieldLinks($db, $spotD_rich, $ownerPkey);
check("D one field link → rich spot itself", is_array($links) && count($links) === 1 && $links[0]->reference_id === (string)$spotD_rich);

// =========================================================================
echo "=== E: data-less numeric entry, NO rich spot → no spine row ===\n";
$r = http('POST', "/db/datasetsinglespot/$datasetId",
    json_encode(legacyFeat($spotE_empty, array(array('id' => $sampleE_num)), $ts)));
check("E upload HTTP 200", $r['status'] === 200);
$row = spineRow($db, $sampleE_num, $ownerPkey);
check("E no empty spine row materialized", $row === null || $row === false);

// =========================================================================
echo "=== F: remove mirror — stub-carrying parent delete leaves rich row ===\n";
$r = http('DELETE', "/db/feature/$spotC_parent", null, $up);
check("F parent delete HTTP 200", $r['status'] === 200);
$row = spineRow($db, $uuidRich, $ownerPkey);
check("F rich UUID row SURVIVES parent-spot delete", $row && $row->name === 'UUIDE2E-rich');

$r = http('DELETE', "/db/feature/$spotB_rich", null, $up);
check("F rich spot delete HTTP 200", $r['status'] === 200);
$row = spineRow($db, $uuidRich, $ownerPkey);
check("F rich UUID row REMOVED after rich-spot delete", !$row);

// =========================================================================
echo "=== G: download round-trip ===\n";
$r = http('GET', "/db/datasetspots/$datasetId", null, $up);
check("G GET HTTP 200", $r['status'] === 200);
$seen = array();
if (isset($r['json']['features'])) {
    foreach ($r['json']['features'] as $f) {
        if (!empty($f['properties']['samples'])) {
            foreach ($f['properties']['samples'] as $s) {
                if (isset($s['id'])) $seen[] = (string)$s['id'];
            }
        }
    }
}
check("G alpha UUID round-trips verbatim", in_array($uuidAlpha, $seen, true));
check("G digit UUID round-trips verbatim", in_array($uuidDigit, $seen, true));
check("G numeric id round-trips", in_array((string)$sampleA_num, $seen, true));

} finally {
    suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, $UUIDS);
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : "ALL CHECKS PASSED") . "\n";
exit(count($failures) ? 1 : 0);
