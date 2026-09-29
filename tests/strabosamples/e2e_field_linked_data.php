<?php
/**
 * File: e2e_field_linked_data.php
 * Description: The read-only strabosamples_linked key on Field samples, over
 *              real HTTP (samplesdb/lib/field_linked_data.php).
 *
 *                A. owner downloads GET /db/datasetspots: StraboMicro and
 *                   StraboExperimental data on the samples that have it
 *                   (rich numeric id, rich UUID id, legacy inline entry,
 *                   entry linked through strabosamples_id); no key on
 *                   Field-only samples or parent stubs
 *                B. a Field project collaborator gets the same key; the
 *                   /jwtdb/ route shares the controller
 *                C. the app sends the download back, tampered, through
 *                   /db/datasetspots: nothing of the key is stored (Neo4j,
 *                   PG mirror, spine, changelog), stubs stay stubs, and the
 *                   next download rebuilds the key from the real data
 *                D. same through /db/projectdatasetsspots, /db/feature and
 *                   /db/datasetsinglespot
 *                E. helper unit checks (strip, clean, prune)
 *                F. the ids work for the people who get them: /microdb/
 *                   project reads (?owner=) and /expdb/experiment|project for
 *                   the owner, a Field project collaborator, a sample
 *                   collaborator; not for outsiders, disabled or removed
 *                   collaborators; public projects open; migrated Field links
 *                   without project_id resolve through Neo4j; bad ids refused
 *
 *              Needs the test users from tests/collaboration/setup_test_data.php.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_field_linked_data.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/microdb/strabomicroclass.php';
require_once '/srv/app/www/samplesdb/lib/field_linked_data.php';
if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$password = 'testpass123';
function pkeyOf($db, $email) {
    return (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
}
$ownerEmail  = 'owner@test.strabospot.org';
$collabEmail = 'editor@test.strabospot.org';
$ownerPkey  = pkeyOf($db, $ownerEmail);
$collabPkey = pkeyOf($db, $collabEmail);
if ($ownerPkey <= 0 || $collabPkey <= 0) {
    echo "Test users not found. Run tests/collaboration/setup_test_data.php first.\n";
    exit(1);
}
$OWNER  = "$ownerEmail:$password";
$COLLAB = "$collabEmail:$password";
$readonlyEmail = 'readonly@test.strabospot.org';
$outsiderEmail = 'outsider@test.strabospot.org';
$readonlyPkey = pkeyOf($db, $readonlyEmail);
$outsiderPkey = pkeyOf($db, $outsiderEmail);
$READONLY = "$readonlyEmail:$password";
$OUTSIDER = "$outsiderEmail:$password";
$KEY = FIELD_SAMPLE_LINKED_KEY;

$stamp = time();
$base  = 97781 * 10000000 + ($stamp % 1000000) * 10;
$NEO_MIN = 977810000000; $NEO_MAX = 977819999999;
$projectId = $base + 1;
$datasetId = $base + 2;
$sRich     = $base + 11;   // rich, numeric id; also in StraboMicro
$sUuidSpot = $base + 12;   // rich, spot id numeric, sample id a UUID; in StraboExperimental
$sFieldOnly= $base + 13;   // rich, Field only
$sParent   = $base + 14;   // parent spot holding the {id} stub of $sRich
$sLegacy   = $base + 15;   // legacy spot with an inline sample that is in StraboMicro
$sLinked   = $base + 16;   // rich, strabosamples_id -> a StraboMicro-made sample
$sExtra    = $base + 17;   // D: single-spot endpoints
$uuidSample  = 'e2e97781-aaaa-4bbb-8ccc-' . sprintf('%012d', $stamp % 1000000000000);
$legacyId    = 'lgcy-97781-' . $stamp;
$microOnlyId = 'mcro-97781-' . $stamp;
$microStraboId = 'e2e-linked-micro-' . $stamp;
$micrographId  = 'mg-97781-' . $stamp;
$expProjectName = 'linked-data e2e exp ' . $stamp;

function http($method, $path, $body, $userPass, $cookie = null) {
    $f = tempnam(sys_get_temp_dir(), 'linked_e2e_');
    $cmd = "curl -s -o " . escapeshellarg($f) . " -w '%{http_code}' -X " . escapeshellarg($method) . " ";
    if ($userPass !== null) $cmd .= "-u " . escapeshellarg($userPass) . " ";
    if ($cookie !== null) $cmd .= "-H " . escapeshellarg("Cookie: PHPSESSID=$cookie") . " ";
    if ($body !== null) {
        $cmd .= "-H 'Content-Type: application/json' -d " . escapeshellarg(is_string($body) ? $body : json_encode($body)) . " ";
    }
    $status = (int)shell_exec($cmd . escapeshellarg("http://localhost" . $path));
    $raw = file_get_contents($f); @unlink($f);
    $s = strpos($raw, '{');
    return array('status' => $status, 'raw' => $raw, 'json' => $s === false ? null : json_decode(substr($raw, $s), true));
}
function feat($spotId, $name, $ts, array $samples, $rich) {
    $p = array('id' => $spotId, 'name' => $name, 'modified_timestamp' => $ts, 'samples' => $samples);
    if ($rich) $p['isSample'] = 1;
    return array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.5, 39.5)),
        'properties' => $p);
}
function sampleObj($id, $name, array $extra = array()) {
    return array_merge(array('id' => (string)$id, 'sample_id_name' => $name, 'label' => $name,
        'sample_description' => "$name description", 'material_type' => 'intact_rock',
        'main_sampling_purpose' => 'petrology'), $extra);
}
function download($datasetId, $userPass, $prefix = '/db') {
    $r = http('GET', "$prefix/datasetSpots/$datasetId", null, $userPass);
    $by = array();
    foreach ((isset($r['json']['features']) ? $r['json']['features'] : array()) as $f) $by[(string)$f['properties']['id']] = $f;
    return array($r, $by);
}
function sampleOf($by, $spotId, $i = 0) {
    return isset($by[(string)$spotId]['properties']['samples'][$i]) ? $by[(string)$spotId]['properties']['samples'][$i] : null;
}
function forgeSession($pkey) {
    $sid = substr(bin2hex(random_bytes(16)), 0, 26);
    $p = '/var/lib/php/sessions/sess_' . $sid;
    file_put_contents($p, 'loggedin|s:3:"yes";userpkey|i:' . $pkey . ';LAST_ACTIVITY|i:' . time() . ';');
    chmod($p, 0600); @chown($p, 'www-data'); @chgrp($p, 'www-data');
    return array($sid, $p);
}
/** A count that must come back: a failed query (null) fails the suite
 *  instead of reading as zero. */
function mustCount($v, $what) {
    if ($v === null || $v === '' || $v === false) throw new Exception("count query failed: $what");
    return (int)$v;
}
/** Every stored trace of the key for this suite's spots. */
function storedTraces($db, $neodb, $ownerPkey, $NEO_MIN, $NEO_MAX, $key) {
    $out = array();
    $n = mustCount($neodb->get_var("MATCH (s:Spot) WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX AND s.userpkey = $ownerPkey
        AND (s.json_samples CONTAINS '$key' OR s.$key IS NOT NULL OR s.json_$key IS NOT NULL) RETURN count(s)"), 'neo4j');
    if ($n) $out[] = "neo4j:$n";
    $n = mustCount($db->get_var_prepared("SELECT count(*) FROM spot WHERE user_pkey=$1 AND strabo_spot_id LIKE '97781%' AND spotjson LIKE $2",
        array($ownerPkey, "%$key%")), 'pg mirror');
    if ($n) $out[] = "pg_mirror:$n";
    $n = mustCount($db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE userpkey=$1
        AND (coalesce(field_data::text,'') LIKE $2 OR coalesce(custom_data::text,'') LIKE $2)", array($ownerPkey, "%$key%")), 'spine');
    if ($n) $out[] = "spine:$n";
    $n = mustCount($db->get_var_prepared("SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey=$1
        AND coalesce(changes::text,'') LIKE $2", array($ownerPkey, "%$key%")), 'changelog');
    if ($n) $out[] = "changelog:$n";
    return $out;
}
function spineSnapshot($db, $ownerPkey, array $ids) {
    $rows = $db->get_results_prepared("SELECT id, name, description, micro_data::text AS md, experimental_data::text AS ed,
            field_data::text AS fd, custom_data::text AS cd
          FROM strabosamples.samples WHERE userpkey=$1 AND id IN (SELECT jsonb_array_elements_text($2::jsonb)) ORDER BY id",
        array($ownerPkey, json_encode(array_map('strval', $ids))));
    return json_encode($rows);
}

function suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, $extraIds, $microStraboId, $expProjectName) {
    // Field uploads also create (:Sample) nodes keyed on the sample id (numeric or string).
    $neodb->query("MATCH (x:Sample) WHERE toString(x.id) STARTS WITH '97781' OR toString(x.id) STARTS WITH 'e2e97781-'
        OR toString(x.id) STARTS WITH 'lgcy-97781-' OR toString(x.id) STARTS WITH 'mcro-97781-' DETACH DELETE x");
    $neodb->query("MATCH (s:Spot) WHERE s.id >= $NEO_MIN AND s.id <= $NEO_MAX DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_MIN AND d.id <= $NEO_MAX DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id >= $NEO_MIN AND p.id <= $NEO_MAX DETACH DELETE p");
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey=$1 AND (id LIKE '97781%' OR id LIKE 'e2e97781-%'
        OR id LIKE 'lgcy-97781-%' OR id LIKE 'mcro-97781-%')", array($ownerPkey));
    // StraboMicro's own delete: micrographs hang ~20 child tables off the samples.
    $sm = new StraboMicro($neodb, $ownerPkey, $db);
    foreach ((array)$db->get_results_prepared("SELECT strabo_id FROM micro_projectmetadata WHERE userpkey=$1 AND strabo_id LIKE 'e2e-linked-micro-%'", array($ownerPkey)) as $m) {
        if ($m) $sm->deleteProject($m->strabo_id);
    }
    $db->prepare_query("DELETE FROM straboexp.project WHERE userpkey=$1 AND name LIKE 'linked-data e2e exp %'", array($ownerPkey));
    $db->prepare_query("DELETE FROM collaborators WHERE project_owner_user_pkey=$1 AND strabo_project_id LIKE '97781%'", array($ownerPkey));
    $db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_userpkey=$1 AND (project_id LIKE '97781%' OR project_id LIKE 'e2e-linked-micro-%' OR project_name LIKE 'linked-data e2e exp %')", array($ownerPkey));
    $db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_userpkey=$1 AND project_id LIKE '97781%'", array($ownerPkey));
    $db->query("DELETE FROM spot    WHERE user_pkey = $ownerPkey AND strabo_spot_id LIKE '97781%'");
    $db->query("DELETE FROM dataset WHERE user_pkey = $ownerPkey AND strabo_dataset_id LIKE '97781%'");
    $db->query("DELETE FROM project WHERE user_pkey = $ownerPkey AND strabo_project_id LIKE '97781%'");
}

suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, array(), $microStraboId, $expProjectName);
$sessFile = null;

try {

// ------------------------------------------------------------------ setup
echo "=== setup ===\n";
$ts = (int)(microtime(true) * 1000);
$userNode = $neodb->get_var("MATCH (u:User {userpkey:$ownerPkey}) RETURN id(u)");
$neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, desc_project_name:'linked data e2e', created_by:$ownerPkey})");
if ($userNode !== null && $userNode !== '') {
    $neodb->query("MATCH (u:User {userpkey:$ownerPkey}), (p:Project {id:$projectId, userpkey:$ownerPkey}) CREATE (u)-[:HAS_PROJECT]->(p)");
}
$neodb->query("MATCH (p:Project {id:$projectId, userpkey:$ownerPkey})
               CREATE (d:Dataset {id:$datasetId, userpkey:$ownerPkey, name:'linked data e2e ds', created_by:$ownerPkey})
               CREATE (p)-[:HAS_DATASET]->(d)");
$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, accepted_date, uuid)
    VALUES ($1, $2, $3, 'edit', true, now(), $4)", array((string)$projectId, $ownerPkey, $collabPkey, bin2hex(random_bytes(16))));

// StraboMicro first: its samples exist before the Field upload that links to one.
$microInternal = (int)$db->get_var("SELECT nextval('strabomicro.micro_projectmetadata_id_seq')");
@mkdir("/srv/app/www/straboMicroFiles/$microInternal", 0755, true);
$sm = new StraboMicro($neodb, $ownerPkey, $db);
$sm->setuuid(new UUID());
$ms = function ($id, $name, $micrographs = array()) {
    return (object)array('id' => $id, 'sampleID' => $name, 'label' => $name, 'latitude' => 39.5, 'longitude' => -105.5,
        'materialType' => 'intact_rock', 'mainSamplingPurpose' => 'fabric___micro', 'sampleDescription' => "$name in thin section",
        'micrographs' => $micrographs);
};
$sm->loadProjectJSON(json_encode(array('id' => $microStraboId, 'name' => 'Linked E2E Micro Project', 'startDate' => '2026-09-01',
    'datasets' => array(array('id' => $microStraboId . '-ds', 'name' => 'Linked E2E Micro Dataset',
        'samples' => array($ms((string)$sRich, 'RICH', array(array('id' => $micrographId, 'name' => 'XZ overview'))), $ms($legacyId, 'LEGACY'), $ms($microOnlyId, 'MICRO-MADE')))))),
    $microInternal, '');
@mkdir("/srv/app/www/straboMicroFiles/$microInternal/images", 0755, true);
copy('/srv/app/www/includes/images/image-not-found.jpg', "/srv/app/www/straboMicroFiles/$microInternal/images/$micrographId.jpg");
$jpgBytes = filesize("/srv/app/www/straboMicroFiles/$microInternal/images/$micrographId.jpg");

$features = array(
    feat($sRich, 'RICH', $ts, array(sampleObj($sRich, 'RICH')), true),
    feat($sUuidSpot, 'UUID', $ts, array(sampleObj($uuidSample, 'UUID')), true),
    feat($sFieldOnly, 'FIELD-ONLY', $ts, array(sampleObj($sFieldOnly, 'FIELD-ONLY')), true),
    feat($sParent, 'PARENT', $ts, array(array('id' => (string)$sRich)), false),
    feat($sLegacy, 'LEGACY-HOLDER', $ts, array(sampleObj($legacyId, 'LEGACY')), false),
    feat($sLinked, 'LINKED', $ts, array(sampleObj($sLinked, 'LINKED', array('strabosamples_id' => $microOnlyId))), true),
);
$r = http('POST', "/db/datasetspots/$datasetId", array('id' => '', 'features' => $features), $OWNER);
check("Field upload 2xx", $r['status'] >= 200 && $r['status'] < 300);

// StraboExperimental links to the UUID sample, with composition.
$expPkey = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
$db->prepare_query("INSERT INTO straboexp.project (pkey, userpkey, uuid, name) VALUES ($1,$2,$3,$4)",
    array($expPkey, $ownerPkey, bin2hex(random_bytes(16)), $expProjectName));
list($sid, $sessFile) = forgeSession($ownerPkey);
$r = http('POST', '/experimental/api/save_experiment.php', array('project_pkey' => $expPkey, 'experiment_id' => 'LNK-' . $stamp,
    'data' => array('experiment' => array('id' => 'LNK-' . $stamp),
        'sample' => array('strabo_id' => $uuidSample, 'id' => 'UUID', 'name' => 'UUID', 'description' => 'deformed at 900 C',
            'material' => array('material' => array('type' => 'Igneous Rock', 'name' => 'granite', 'state' => 'solid'),
                'composition' => array(array('mineral' => 'Quartz', 'fraction' => '40', 'unit' => 'Vol%'),
                                       array('mineral' => 'Feldspar', 'fraction' => '60', 'unit' => 'Vol%')))))),
    null, $sid);
check("Experimental save linked to the UUID sample", $r['status'] === 200 && isset($r['json']['strabo_id']) && $r['json']['strabo_id'] === $uuidSample);

// ------------------------------------------------------------------ A
echo "\n=== A: owner download ===\n";
list($r, $by) = download($datasetId, $OWNER);
check("download 200 with all six spots", $r['status'] === 200 && count($by) === 6);
$rich = sampleOf($by, $sRich);
$k = isset($rich[$KEY]) ? $rich[$KEY] : null;
check("rich numeric sample has the key", is_array($k));
check("  id + owner", $k && $k['id'] === (string)$sRich && (int)$k['owner'] === $ownerPkey);
check("  micro project + dataset: Micro's own ids + names", $k && isset($k['micro']['projects'][0])
    && $k['micro']['projects'][0]['project_id'] === $microStraboId
    && $k['micro']['projects'][0]['dataset_id'] === $microStraboId . '-ds'
    && $k['micro']['projects'][0]['project_name'] === 'Linked E2E Micro Project'
    && $k['micro']['projects'][0]['dataset_name'] === 'Linked E2E Micro Dataset'
    && $k['micro']['projects'][0]['micrograph_count'] === 1);
check("  micro has projects only (no data block)", $k && array_keys($k['micro']) === array('projects'));
$mp = $k ? $k['micro']['projects'][0]['project_id'] : '';
$w = http('GET', '/microdb/webProject/' . rawurlencode($mp), null, $OWNER);
check("  that project_id works on the Micro API (/microdb/webProject)", $w['status'] === 200 && substr($w['raw'], 0, 2) === 'PK');
check("  no experimental part", $k && !isset($k['experimental']));
check("  Field fields not repeated", $k && !isset($k['field']) && !isset($k['field_data']));

$u = sampleOf($by, $sUuidSpot);
$k = isset($u[$KEY]) ? $u[$KEY] : null;
check("rich UUID sample has the key", is_array($k) && $k['id'] === $uuidSample);
$x0 = $k && isset($k['experimental']['experiments'][0]) ? $k['experimental']['experiments'][0] : array();
check("  experiment: pkeys, typed ID, uuid, project name", $x0
    && $x0['experiment_id'] === 'LNK-' . $stamp && is_int($x0['experiment_pkey'])
    && $x0['project_pkey'] === $expPkey && $x0['project_name'] === $expProjectName
    && preg_match('/^[0-9a-f-]{36}$/', $x0['experiment_uuid']));
$g = http('GET', '/experimental/api/get_experiment.php?id=' . (int)($x0 ? $x0['experiment_pkey'] : 0), null, null, $sid);
check("  experiment_pkey works on the Experimental API (get_experiment.php)", $g['status'] === 200
    && strpos($g['raw'], $x0 ? $x0['experiment_uuid'] : 'none') !== false);
$g = http('GET', '/experimental/api/get_project.php?id=' . $expPkey, null, null, $sid);
check("  project_pkey works on the Experimental API (get_project.php)", $g['status'] === 200 && strpos($g['raw'], $expProjectName) !== false);
check("  composition rows in order", $k && count($k['experimental']['composition']) === 2
    && $k['experimental']['composition'][0]['mineral'] === 'Quartz' && $k['experimental']['composition'][1]['fraction'] === '60');
check("  parameters + documents present as lists", $k && $k['experimental']['parameters'] === array() && $k['experimental']['documents'] === array());
check("  experimental data, internal _sample_json dropped", $k && isset($k['experimental']['data']['material_name'])
    && $k['experimental']['data']['material_name'] === 'granite' && !isset($k['experimental']['data']['_sample_json']));
check("  no micro part", $k && !isset($k['micro']));

check("Field-only sample has no key", ($x = sampleOf($by, $sFieldOnly)) && !array_key_exists($KEY, $x));
$stub = sampleOf($by, $sParent);
check("parent stub untouched (id only)", $stub === array('id' => (string)$sRich));
$leg = sampleOf($by, $sLegacy);
check("legacy inline sample has the key with its micro project", isset($leg[$KEY]['micro']['projects'][0]['project_id'])
    && $leg[$KEY]['id'] === $legacyId && $leg[$KEY]['micro']['projects'][0]['project_id'] === $microStraboId);
// No null or empty-string value anywhere in any key; lists always present.
$noEmpty = function ($v) use (&$noEmpty) {
    if ($v === null || $v === '') return false;
    if (is_array($v)) foreach ($v as $x) if (!$noEmpty($x)) return false;
    return true;
};
$allClean = true;
foreach ($by as $f) foreach ($f['properties']['samples'] as $smp) if (isset($smp[$KEY]) && !$noEmpty($smp[$KEY])) $allClean = false;
check("no null or empty-string values anywhere in the key", $allClean);
$lnk = sampleOf($by, $sLinked);
check("entry linked through strabosamples_id resolves to the linked sample", isset($lnk[$KEY]['id']) && $lnk[$KEY]['id'] === $microOnlyId
    && $lnk['strabosamples_id'] === $microOnlyId && $lnk['id'] === (string)$sLinked);
$fullDownload = $r['json'];

// ------------------------------------------------------------------ B
echo "\n=== B: collaborator + /jwtdb/ ===\n";
list($r, $cby) = download($datasetId, $COLLAB);
$ck = sampleOf($cby, $sRich);
check("collaborator download 200", $r['status'] === 200 && count($cby) === 6);
check("collaborator gets the same key", isset($ck[$KEY]) && $ck[$KEY] == $rich[$KEY]);
check("key owner is the project owner, not the downloader", isset($ck[$KEY]['owner']) && (int)$ck[$KEY]['owner'] === $ownerPkey);
$jw = file_get_contents('/srv/app/www/jwtdb/index.php');
check("/jwtdb/ loads the same DatasetSpotsController", strpos($jw, '../db/controllers/*.php') !== false);

// ------------------------------------------------------------------ C
echo "\n=== C: the app sends it all back, tampered, via /db/datasetspots ===\n";
$ids = array((string)$sRich, $uuidSample, (string)$sFieldOnly, $legacyId, $microOnlyId);
$before = spineSnapshot($db, $ownerPkey, $ids);
$createDeleteSql = "SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey=$1 AND change_type IN ('create','delete')";
$changelogBefore = mustCount($db->get_var_prepared($createDeleteSql, array($ownerPkey)), 'changelog before');
$back = $fullDownload;
$ts2 = $ts + 5000;
$junk = array('id' => 'hijack', 'owner' => 1, 'micro' => array('projects' => array(array('project_id' => 'TAMPERED'))));
foreach ($back['features'] as &$f) {
    $f['properties']['modified_timestamp'] = $ts2;
    foreach ($f['properties']['samples'] as &$s) {
        if (isset($s[$KEY]['micro'])) $s[$KEY]['micro']['projects'][0]['project_id'] = 'TAMPERED';
    }
    unset($s);
    if ((string)$f['properties']['id'] === (string)$sFieldOnly) $f['properties']['samples'][0][$KEY] = $junk;   // invented on a Field-only sample
    if ((string)$f['properties']['id'] === (string)$sParent)    $f['properties']['samples'][0][$KEY] = $junk;   // on a stub
    $f['properties'][$KEY] = $junk;                                                                            // at spot level
}
unset($f);
$r = http('POST', "/db/datasetspots/$datasetId", array('id' => '', 'features' => $back['features']), $OWNER);
check("re-upload 2xx, clean JSON", $r['status'] >= 200 && $r['status'] < 300 && is_array($r['json']));
check("nothing of the key stored (Neo4j, PG mirror, spine, changelog)", storedTraces($db, $neodb, $ownerPkey, $NEO_MIN, $NEO_MAX, $KEY) === array());
check("spine rows unchanged", spineSnapshot($db, $ownerPkey, $ids) === $before);
$changelogAfter = mustCount($db->get_var_prepared($createDeleteSql, array($ownerPkey)), 'changelog after');
check("no create/delete in the changelog from the re-upload", $changelogAfter === $changelogBefore);
check("stub did not become a sample", mustCount($db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE userpkey=$1 AND id=$2",
    array($ownerPkey, (string)$sParent)), 'stub row') === 0);
$js = (string)$neodb->get_var("MATCH (s:Spot {id:$sParent, userpkey:$ownerPkey}) RETURN s.json_samples");
check("stored stub is still {id} only", json_decode($js, true) === array(array('id' => (string)$sRich)));
list($r, $by2) = download($datasetId, $OWNER);
$k2 = sampleOf($by2, $sRich);
check("next download rebuilds the key from real data", isset($k2[$KEY]['micro']['projects'][0]['project_id'])
    && $k2[$KEY]['micro']['projects'][0]['project_id'] === $microStraboId);
check("invented key on a Field-only sample is gone", ($x = sampleOf($by2, $sFieldOnly)) && !array_key_exists($KEY, $x));
check("spot-level key is gone", isset($by2[(string)$sRich]) && !array_key_exists($KEY, $by2[(string)$sRich]['properties']));

// ------------------------------------------------------------------ D
echo "\n=== D: the other upload endpoints ===\n";
$ts3 = $ts2 + 5000;
$pdss = array('project' => array('id' => $projectId, 'description' => array('project_name' => 'linked data e2e'), 'modified_timestamp' => $ts3,
    'datasets' => array(array('id' => $datasetId, 'name' => 'linked data e2e ds', 'modified_timestamp' => $ts3,
        'spots' => array('features' => array(feat($sRich, 'RICH', $ts3,
            array(sampleObj($sRich, 'RICH', array($KEY => $junk))), true)))))));
$r = http('POST', '/db/projectdatasetsspots', $pdss, $OWNER);
check("/db/projectdatasetsspots 2xx", $r['status'] >= 200 && $r['status'] < 300);
check("  nothing stored", storedTraces($db, $neodb, $ownerPkey, $NEO_MIN, $NEO_MAX, $KEY) === array());

$ts4 = $ts3 + 5000;
$one = feat($sExtra, 'EXTRA', $ts4, array(sampleObj($sExtra, 'EXTRA', array($KEY => $junk))), true);
$one['properties'][$KEY] = $junk;
$r = http('POST', "/db/datasetsinglespot/$datasetId", $one, $OWNER);
check("/db/datasetsinglespot 2xx", $r['status'] >= 200 && $r['status'] < 300);
check("  spot stored", (int)$neodb->get_var("MATCH (s:Spot {id:$sExtra, userpkey:$ownerPkey}) RETURN count(s)") === 1);
check("  nothing stored", storedTraces($db, $neodb, $ownerPkey, $NEO_MIN, $NEO_MAX, $KEY) === array());

$ts5 = $ts4 + 5000;
$one = feat($sExtra, 'EXTRA', $ts5, array(sampleObj($sExtra, 'EXTRA edited', array($KEY => $junk))), true);
$r = http('POST', "/db/feature/$sExtra", $one, $OWNER);
check("/db/feature 2xx", $r['status'] >= 200 && $r['status'] < 300);
$js = (string)$neodb->get_var("MATCH (s:Spot {id:$sExtra, userpkey:$ownerPkey}) RETURN s.json_samples");
check("  edit landed", strpos($js, 'EXTRA edited') !== false);
check("  nothing stored", storedTraces($db, $neodb, $ownerPkey, $NEO_MIN, $NEO_MAX, $KEY) === array());

// ------------------------------------------------------------------ F
echo "\n=== F: following the ids on the Micro and Experimental APIs ===\n";
$microEps = array('projectPDF', 'webProject', 'project', 'projectURL');
function microStatuses($eps, $pid, $q, $cred) {
    $out = array();
    foreach ($eps as $ep) $out[$ep] = http('GET', "/microdb/$ep/" . rawurlencode($pid) . $q, null, $cred)['status'];
    return $out;
}
$all = function ($st, $code) { foreach ($st as $v) if ($v !== $code) return false; return true; };
$oq = "?owner=$ownerPkey";

check("Micro: owner reads all four without ?owner", $all(microStatuses($microEps, $microStraboId, '', $OWNER), 200));
check("Micro: owner reads with ?owner too", $all(microStatuses($microEps, $microStraboId, $oq, $OWNER), 200));
check("Micro: Field project collaborator without ?owner = 404 (their own namespace)", $all(microStatuses($microEps, $microStraboId, '', $COLLAB), 404));
check("Micro: Field project collaborator with ?owner = 200", $all(microStatuses($microEps, $microStraboId, $oq, $COLLAB), 200));
$u = http('GET', "/microdb/projectURL/" . rawurlencode($microStraboId) . $oq, null, $COLLAB);
check("Micro: projectURL for the collaborator counts this project's micrographs", isset($u['json']['micrograph_count']) && (int)$u['json']['micrograph_count'] === 1
    && isset($u['json']['url']) && strpos($u['json']['url'], "/$microStraboId.zip") !== false);
check("Micro: outsider with ?owner = 404", $all(microStatuses($microEps, $microStraboId, $oq, $OUTSIDER), 404));
function imageBytes($id, $cred) {
    $r = http('GET', '/microdb/image/' . rawurlencode($id), null, $cred);
    return strlen($r['raw']);
}
$j = http('GET', '/microdb/project/' . rawurlencode($microStraboId) . $oq, null, $COLLAB);
$imgUrl = '';
foreach ((isset($j['json']['projectDetails']['datasets']) ? $j['json']['projectDetails']['datasets'] : array()) as $d)
    foreach ($d['samples'] as $smp) foreach ((isset($smp['micrographs']) ? $smp['micrographs'] : array()) as $m) $imgUrl = $m['imageURL'];
check("Micro: collaborator's project JSON carries the micrograph imageURL", substr($imgUrl, -strlen("/microdb/image/$micrographId")) === "/microdb/image/$micrographId");
check("Micro: owner gets the micrograph image", imageBytes($micrographId, $OWNER) === $jpgBytes);
check("Micro: collaborator gets the micrograph image", imageBytes($micrographId, $COLLAB) === $jpgBytes);
check("Micro: outsider gets the not-found placeholder instead", imageBytes($micrographId, $OUTSIDER) !== $jpgBytes);
check("Micro: an image id that is not an id shape gets the placeholder", imageBytes("../../includes/images/image-not-found", $OWNER) !== $jpgBytes);

// Migrated Field links record only dataset_id: the Neo4j dataset -> project walk decides.
$db->prepare_query("UPDATE strabosamples.sample_subsystem_links SET reference_metadata = reference_metadata - 'project_id'
    WHERE sample_userpkey = $1 AND subsystem = 'field' AND sample_id IN ($2, $3)", array($ownerPkey, (string)$sRich, $legacyId));
$np = (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE sample_userpkey=$1 AND subsystem='field'
    AND sample_id IN ($2, $3) AND reference_metadata ? 'project_id'", array($ownerPkey, (string)$sRich, $legacyId));
check("Micro: links now lack project_id (migrated shape)", $np === 0);
check("Micro: collaborator still reads it via the Neo4j walk", $all(microStatuses($microEps, $microStraboId, $oq, $COLLAB), 200));

$db->prepare_query("UPDATE collaborators SET disabled = true WHERE strabo_project_id = $1 AND collaborator_user_pkey = $2", array((string)$projectId, $collabPkey));
check("Micro: disabled Field collaborator = 404", $all(microStatuses($microEps, $microStraboId, $oq, $COLLAB), 404));
$db->prepare_query("UPDATE collaborators SET disabled = false WHERE strabo_project_id = $1 AND collaborator_user_pkey = $2", array((string)$projectId, $collabPkey));

// Sample-level collaboration (readonly@ is not on the Field project).
check("Micro: readonly@ (no collaboration) = 404", $all(microStatuses($microEps, $microStraboId, $oq, $READONLY), 404));
$db->prepare_query("INSERT INTO strabosamples.sample_collaborators (sample_id, sample_userpkey, collaborator_pkey, permission_level, uuid, accepted, accepted_at, added_by)
    VALUES ($1, $2, $3, 'readonly', $4, true, now(), $2)", array($legacyId, $ownerPkey, $readonlyPkey, bin2hex(random_bytes(16))));
check("Micro: accepted sample collaborator reads it", $all(microStatuses($microEps, $microStraboId, $oq, $READONLY), 200));
$db->prepare_query("UPDATE strabosamples.sample_collaborators SET removed_at = now() WHERE sample_id = $1 AND collaborator_pkey = $2", array($legacyId, $readonlyPkey));
check("Micro: removed sample collaborator = 404", $all(microStatuses($microEps, $microStraboId, $oq, $READONLY), 404));

$db->prepare_query("UPDATE micro_projectmetadata SET ispublic = true WHERE id = $1", array($microInternal));
check("Micro: public project readable by an outsider", $all(microStatuses($microEps, $microStraboId, $oq, $OUTSIDER), 200));
$db->prepare_query("UPDATE micro_projectmetadata SET ispublic = false WHERE id = $1", array($microInternal));

check("Micro: an id that is not an id shape is refused", $all(microStatuses($microEps, "x' OR '1'='1", '', $OWNER), 404));
check("Micro: non-numeric ?owner refused", $all(microStatuses($microEps, $microStraboId, '?owner=abc', $COLLAB), 404));

// Experimental: a second experiment in the same project holds a sample nobody else can reach.
$r = http('POST', '/experimental/api/save_experiment.php', array('project_pkey' => $expPkey, 'experiment_id' => 'LNK2-' . $stamp,
    'data' => array('experiment' => array('id' => 'LNK2-' . $stamp),
        'sample' => array('id' => 'OTHER', 'name' => 'OTHER', 'description' => 'unrelated lab sample'))), null, $sid);
check("Exp: second (unlinked) experiment saved", $r['status'] === 200 && !empty($r['json']['pkey']));
$exp2 = !empty($r['json']['pkey']) ? (int)$r['json']['pkey'] : 0;
$exp1 = (int)$x0['experiment_pkey'];

$g = http('GET', "/expdb/experiment/$exp1", null, $OWNER);
check("Exp: owner reads the experiment (with the sample)", $g['status'] === 200 && $g['json']['uuid'] === $x0['experiment_uuid']
    && $g['json']['data']['sample']['strabo_id'] === $uuidSample && $g['json']['is_owner'] === true);
$g = http('GET', "/expdb/project/$expPkey", null, $OWNER);
check("Exp: owner's project lists both experiments", $g['status'] === 200 && count($g['json']['experiments']) === 2);
$g = http('GET', "/expdb/experiment/$exp1", null, $COLLAB);
check("Exp: Field project collaborator reads the linked experiment", $g['status'] === 200 && $g['json']['pkey'] === $exp1 && $g['json']['is_owner'] === false);
$g = http('GET', "/expdb/experiment/$exp2", null, $COLLAB);
check("Exp: ...but not the unlinked one", $g['status'] === 404);
$g = http('GET', "/expdb/project/$expPkey", null, $COLLAB);
check("Exp: collaborator's project view lists only the linked experiment", $g['status'] === 200
    && count($g['json']['experiments']) === 1 && $g['json']['experiments'][0]['pkey'] === $exp1);
check("Exp: outsider = 404 (experiment + project)", http('GET', "/expdb/experiment/$exp1", null, $OUTSIDER)['status'] === 404
    && http('GET', "/expdb/project/$expPkey", null, $OUTSIDER)['status'] === 404);
$db->prepare_query("UPDATE straboexp.project SET ispublic = true WHERE pkey = $1", array($expPkey));
$g = http('GET', "/expdb/project/$expPkey", null, $OUTSIDER);
check("Exp: public project readable by an outsider, all experiments", $g['status'] === 200 && count($g['json']['experiments']) === 2
    && http('GET', "/expdb/experiment/$exp2", null, $OUTSIDER)['status'] === 200);
$db->prepare_query("UPDATE straboexp.project SET ispublic = false WHERE pkey = $1", array($expPkey));
check("Exp: bad ids = 400, write verbs refused", http('GET', '/expdb/experiment/abc', null, $OWNER)['status'] === 400
    && http('DELETE', "/expdb/experiment/$exp1", null, $OWNER)['status'] === 400
    && (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.experiment WHERE pkey=$1", array($exp1)) === 1);
check("Exp: no credentials = 401", http('GET', "/expdb/experiment/$exp1", null, null)['status'] === 401);

// ------------------------------------------------------------------ E
echo "\n=== E: helpers ===\n";
$o = json_decode(json_encode(array('name' => 'x', $KEY => 1, 'samples' => array(array('id' => '1', $KEY => 2), array('id' => '2')))));
check("strip on objects counts 2 and removes both", field_linked_strip_properties($o) === 2
    && !property_exists($o, $KEY) && !property_exists($o->samples[0], $KEY) && $o->samples[1]->id === '2' && $o->name === 'x');
$a = array('samples' => array(array('id' => '1', $KEY => 2)), $KEY => 1);
check("strip on arrays", field_linked_strip_properties($a) === 2 && $a === array('samples' => array(array('id' => '1'))));
$n = null;
check("strip on null is a no-op", field_linked_strip_properties($n) === 0);
check("clean drops _keys, keeps the rest", _field_linked_clean('{"_sample_json":"x","a":1}') === array('a' => 1));
check("clean of null / empty", _field_linked_clean(null) === null && _field_linked_clean('{}') === null);
check("prune drops null / '' / emptied objects, keeps lists", _field_linked_prune(array('a' => null, 'b' => '', 'c' => 0,
    'd' => array('e' => null), 'composition' => array(), 'f' => array(array('g' => '', 'h' => 'x'))))
    === array('c' => 0, 'composition' => array(), 'f' => array(array('h' => 'x'))));
$empty = array('type' => 'FeatureCollection', 'features' => array());
check("attach on an empty collection", field_linked_attach($db, $empty, $ownerPkey) === 0);

} finally {
    if ($sessFile) @unlink($sessFile);
    suiteCleanup($db, $neodb, $NEO_MIN, $NEO_MAX, $ownerPkey, array(), $microStraboId, $expProjectName);
}

echo "\n" . (count($failures) === 0 ? "ALL PASS" : count($failures) . " FAILED:\n  - " . implode("\n  - ", $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
