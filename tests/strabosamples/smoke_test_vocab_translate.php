<?php
/**
 * File: smoke_test_vocab_translate.php
 * Description: Smoke test for samples/vocab-translate-writeback.
 *
 *              Part 1 (unit): samples_vocab_translate_to_field() across
 *              every translation status — passthrough / translated /
 *              no_mapping / free_text — for both enum-bound spine
 *              columns plus passthrough for the non-enum columns
 *              (name, igsn, description, notes) and null/empty.
 *
 *              Part 2 (end-to-end against Neo4j): updateSample on a rich
 *              Field-linked sample with each translation status, verifies:
 *                - spine column gets the user-supplied value verbatim
 *                  (translation does NOT mutate the spine; only the push
 *                  to Field is translated)
 *                - Neo4j Spot's samples[0].material_type matches the
 *                  translated value for 'translated' status; is UNCHANGED
 *                  from the prior value for 'no_mapping' and 'free_text'
 *                - sample_changelog gets a 'writeback_translation' row
 *                  with the right note shape AND a separate 'update' row
 *                  with the spine diff (from the prior changelog-upload-
 *                  diffs work)
 *                - "translation skips only, nothing left to push" path:
 *                  emits a writeback_translation row with skipped_only
 *                  flag and does NOT touch Neo4j
 *
 *              Hermetic: seeds Neo4j Project+Dataset+rich Spot and
 *              creates the strabosamples link row via the existing
 *              field_sample_sync_spot hook, then tears down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_vocab_translate.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';
require_once '/srv/app/www/samplesdb/lib/vocab_translate.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

// =====================================================================
// Part 1 — translator unit tests
// =====================================================================

echo "[trans] display_sample_type — Field enum passthrough\n";
foreach (array('intact_rock','fragmented_roc','sediment','tephra','carbon_or_animal','other') as $v) {
    $r = samples_vocab_translate_to_field('display_sample_type', $v);
    check("passthrough $v", $r['status'] === 'passthrough' && $r['value'] === $v);
}

echo "\n[trans] display_sample_type — Experimental → Field translation\n";
$expected = array(
    'Igneous Rock'     => 'intact_rock',
    'Sedimentary Rock' => 'intact_rock',
    'Metamorphic Rock' => 'intact_rock',
    'Soil'             => 'sediment',
    'Mineral'          => 'intact_rock',
);
foreach ($expected as $src => $dst) {
    $r = samples_vocab_translate_to_field('display_sample_type', $src);
    check("$src → $dst translated",
        $r['status'] === 'translated' && $r['value'] === $dst && $r['from'] === $src);
}

echo "\n[trans] display_sample_type — Experimental no-mapping\n";
foreach (array('Glass','Ice','Ceramic','Plastic','Metal','Epos Lithologies','Standards','Commodity') as $v) {
    $r = samples_vocab_translate_to_field('display_sample_type', $v);
    check("no_mapping $v",
        $r['status'] === 'no_mapping' && $r['reason'] === 'experimental_value_no_field_bucket');
}

echo "\n[trans] display_sample_type — free text\n";
foreach (array('MyCustomRock','asdfbefgvb','6.728709','Gneiss') as $v) {
    $r = samples_vocab_translate_to_field('display_sample_type', $v);
    check("free_text $v", $r['status'] === 'free_text');
}

echo "\n[trans] display_sample_purpose — Field enum passthrough\n";
foreach (array('fabric___micro','petrology','geochronology','geochemistry','active_eruptio','other') as $v) {
    $r = samples_vocab_translate_to_field('display_sample_purpose', $v);
    check("purpose passthrough $v", $r['status'] === 'passthrough' && $r['value'] === $v);
}

echo "\n[trans] display_sample_purpose — free text (anything not in Field enum)\n";
foreach (array('MyCustomPurpose','exploration','test') as $v) {
    $r = samples_vocab_translate_to_field('display_sample_purpose', $v);
    check("purpose free_text $v", $r['status'] === 'free_text');
}

echo "\n[trans] Non-enum spine columns always passthrough\n";
foreach (array('name','igsn','description','notes') as $col) {
    $r = samples_vocab_translate_to_field($col, 'anything here');
    check("$col passthrough", $r['status'] === 'passthrough' && $r['value'] === 'anything here');
}

echo "\n[trans] Null / empty passthrough on enum-bound columns\n";
$r = samples_vocab_translate_to_field('display_sample_type', null);
check('null passthrough',  $r['status'] === 'passthrough' && $r['value'] === null);
$r = samples_vocab_translate_to_field('display_sample_type', '');
check('empty passthrough', $r['status'] === 'passthrough' && $r['value'] === '');

// =====================================================================
// Part 2 — End-to-end against Neo4j + PG via updateSample()
// =====================================================================

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array()
);
if (!is_array($users) || count($users) < 1) { echo "Need 1 user\n"; exit(1); }
$ownerPkey = (int)$users[0]->pkey;

$stamp           = time();
$projectStraboId = (string)($stamp * 100 + 11);
$datasetStraboId = (string)($stamp * 100 + 22);
$spotRichId      = (int)($stamp * 100 + 31);
$sampleId        = (string)$spotRichId;

// Seed Neo4j: Project, Dataset, one rich sample-spot.
$neodb->query("CREATE (p:Project {id:$projectStraboId, userpkey:$ownerPkey, name:'vtrans test $stamp'})");
$neodb->query("CREATE (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey, name:'ds $stamp'})");
$neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}),
                     (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey})
               CREATE (p)-[:HAS_DATASET]->(d)");
$richSampleObj = array(
    'id'                    => $sampleId,
    'sample_id_name'        => 'OrigName',
    'material_type'         => 'sediment',          // starting Field enum value
    'main_sampling_purpose' => 'petrology',
);
$richJsonSamples = json_encode(array($richSampleObj));
$richGeom = json_encode(array('type' => 'Point', 'coordinates' => array(-120.5, 45.5)));
$neodb->query("CREATE (s:Spot {
    id: $spotRichId, userpkey: $ownerPkey, isSample: 1,
    name: 'OrigName', modified_timestamp: 1000,
    json_samples: '" . addslashes($richJsonSamples) . "',
    geometry: '" . addslashes($richGeom) . "'
})");
$neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}),
                     (s:Spot {id:$spotRichId, userpkey:$ownerPkey})
               CREATE (d)-[:HAS_SPOT]->(s)");

// Use the field-integration hook to populate strabosamples + the link
// row. This is the real upload path; matches production behavior.
$richProps = (object)array(
    'id'       => $spotRichId,
    'samples'  => array((object)$richSampleObj),
    'isSample' => 1,
    'geometry' => (object)array('type' => 'Point', 'coordinates' => array(-120.5, 45.5)),
);
field_sample_sync_spot($db, $neodb, $richProps, $spotRichId, $ownerPkey, $projectStraboId, $datasetStraboId);

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

/** Pull the most recent changelog row of a given change_type. */
function latestChangelogOfType($db, $sampleId, $ownerPkey, $changeType) {
    $row = $db->get_row_prepared(
        "SELECT change_type, source_subsystem, changes
           FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type=$3
          ORDER BY changed_at DESC, pkey DESC LIMIT 1",
        array($sampleId, (int)$ownerPkey, $changeType)
    );
    if ($row === null) return null;
    $row->changes = json_decode($row->changes, true);
    return $row;
}

/** Read samples[0] from the rich Spot's json_samples. */
function readRichSampleEntry($neodb, $spotId, $ownerPkey) {
    $rec = $neodb->getRecord("MATCH (s:Spot {id:$spotId, userpkey:$ownerPkey}) RETURN s LIMIT 1");
    if (!$rec) return null;
    $props = $rec->get('s')->values();
    $arr = json_decode($props['json_samples'], true);
    return is_array($arr) && isset($arr[0]) ? $arr[0] : null;
}

try {
    echo "\n[e2e] Translated value — Experimental 'Igneous Rock' → Field 'intact_rock'\n";
    $r = $svc->updateSample($sampleId, $ownerPkey, array('display_sample_type' => 'Igneous Rock'));
    check('e2e1 update ok', !empty($r['ok']));
    $spineRow = $db->get_row_prepared(
        "SELECT display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
    check('e2e1 spine value is the user-supplied Igneous Rock (NOT translated)',
        $spineRow && $spineRow->display_sample_type === 'Igneous Rock');
    $entry = readRichSampleEntry($neodb, $spotRichId, $ownerPkey);
    check('e2e1 Neo4j Spot material_type is the TRANSLATED intact_rock',
        $entry && isset($entry['material_type']) && $entry['material_type'] === 'intact_rock');
    $cl = latestChangelogOfType($db, $sampleId, $ownerPkey, 'writeback_translation');
    check('e2e1 writeback_translation row exists', $cl !== null);
    check('e2e1 source_subsystem=samples_api',     $cl && $cl->source_subsystem === 'samples_api');
    check('e2e1 notes has one entry',              $cl && count($cl->changes['notes']) === 1);
    $note = $cl ? $cl->changes['notes'][0] : null;
    check('e2e1 note carries original Igneous Rock',
        $note && $note['original'] === 'Igneous Rock' && $note['translated'] === 'intact_rock');
    check('e2e1 spine update row also written (changelog-upload-diffs path)',
        latestChangelogOfType($db, $sampleId, $ownerPkey, 'update') !== null);

    echo "\n[e2e] No-mapping value — Experimental 'Glass' → skipped\n";
    $r = $svc->updateSample($sampleId, $ownerPkey, array('display_sample_type' => 'Glass'));
    check('e2e2 update ok', !empty($r['ok']));
    $spineRow = $db->get_row_prepared(
        "SELECT display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
    check('e2e2 spine value is Glass',
        $spineRow && $spineRow->display_sample_type === 'Glass');
    $entry = readRichSampleEntry($neodb, $spotRichId, $ownerPkey);
    // The prior translated write left material_type='intact_rock'. This
    // skip must NOT clobber it — the existing value is preserved.
    check('e2e2 Neo4j material_type UNCHANGED from prior (intact_rock)',
        $entry && $entry['material_type'] === 'intact_rock');
    $cl = latestChangelogOfType($db, $sampleId, $ownerPkey, 'writeback_translation');
    check('e2e2 writeback_translation row exists', $cl !== null);
    check('e2e2 skipped_only flag is true',        $cl && !empty($cl->changes['skipped_only']));
    $note = $cl ? $cl->changes['notes'][0] : null;
    check('e2e2 note reason=no_mapping',
        $note && $note['reason'] === 'experimental_value_no_field_bucket'
              && $note['skipped'] === 'Glass');

    echo "\n[e2e] Free-text value — 'MyCustomRock' → skipped\n";
    $r = $svc->updateSample($sampleId, $ownerPkey, array('display_sample_type' => 'MyCustomRock'));
    check('e2e3 update ok', !empty($r['ok']));
    $entry = readRichSampleEntry($neodb, $spotRichId, $ownerPkey);
    check('e2e3 Neo4j material_type STILL intact_rock (skip path)',
        $entry && $entry['material_type'] === 'intact_rock');
    $cl = latestChangelogOfType($db, $sampleId, $ownerPkey, 'writeback_translation');
    $note = $cl ? $cl->changes['notes'][0] : null;
    check('e2e3 note reason=free_text',
        $note && $note['reason'] === 'free_text' && $note['skipped'] === 'MyCustomRock');

    echo "\n[e2e] Mixed payload — passthrough + translated + free-text\n";
    $r = $svc->updateSample($sampleId, $ownerPkey, array(
        'name'                    => 'NewName-mixed',
        'display_sample_type'     => 'Soil',           // translated → sediment
        'display_sample_purpose'  => 'EveryDayPurpose' // free_text → skipped
    ));
    check('e2e4 update ok', !empty($r['ok']));
    $entry = readRichSampleEntry($neodb, $spotRichId, $ownerPkey);
    check('e2e4 sample_id_name pushed (passthrough non-enum)',
        $entry && $entry['sample_id_name'] === 'NewName-mixed');
    check('e2e4 material_type pushed as TRANSLATED sediment',
        $entry && $entry['material_type'] === 'sediment');
    // main_sampling_purpose may have been set or unchanged from seed
    // ('petrology'); important is that it's NOT 'EveryDayPurpose'.
    check('e2e4 main_sampling_purpose NOT the free-text value',
        $entry && (!isset($entry['main_sampling_purpose'])
                   || $entry['main_sampling_purpose'] !== 'EveryDayPurpose'));
    $cl = latestChangelogOfType($db, $sampleId, $ownerPkey, 'writeback_translation');
    check('e2e4 writeback_translation row exists', $cl !== null);
    check('e2e4 skipped_only flag NOT set (writeback DID push something)',
        $cl && empty($cl->changes['skipped_only']));
    check('e2e4 notes has 2 entries (one translated, one free_text skip)',
        $cl && count($cl->changes['notes']) === 2);

    echo "\n[e2e] Non-enum-only payload — no translation row\n";
    $cntBefore = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type='writeback_translation'",
        array($sampleId, $ownerPkey)
    );
    $r = $svc->updateSample($sampleId, $ownerPkey, array('description' => 'fresh description'));
    $cntAfter = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type='writeback_translation'",
        array($sampleId, $ownerPkey)
    );
    check('e2e5 update ok',                 !empty($r['ok']));
    check('e2e5 no new writeback_translation row (no enum-bound translations)',
        $cntAfter === $cntBefore);

} finally {
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
    $neodb->query("MATCH (s:Spot {id:$spotRichId, userpkey:$ownerPkey}) DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset {id:$datasetStraboId, userpkey:$ownerPkey}) DETACH DELETE d");
    $neodb->query("MATCH (p:Project {id:$projectStraboId, userpkey:$ownerPkey}) DETACH DELETE p");
}

echo "\n----------------------------------------\n";
if (empty($failures)) {
    echo "All checks PASS\n";
    exit(0);
}
echo count($failures) . " FAILED:\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
