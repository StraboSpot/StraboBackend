<?php
/**
 * File: smoke_test_changelog_upload_diffs.php
 * Description: Service-layer smoke test for samples/changelog-upload-diffs.
 *              Drives StraboSamplesService::upsertSample directly across
 *              Field / Micro / Experimental sources and verifies the
 *              changelog row carries:
 *
 *                - source_subsystem = field/micro/experimental (not samples_api)
 *                - spine_written = true|false reflecting §10.4 priority
 *                - spine_diff = {field: {old, new}} ONLY when writeSpine=true
 *                  AND at least one supplied spine field actually changed
 *
 *              No HTTP layer, no Neo4j seeding — the patch is purely in the
 *              PG changelog row produced by upsertSample, so we exercise the
 *              service directly with synthetic spineFields + a stub
 *              subsystemData payload.
 *
 *              Hermetic: invents a fresh sample id per run; tears down
 *              samples + changelog rows in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_changelog_upload_diffs.php
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
    if (!$cond) $failures[] = $label;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array()
);
if (!is_array($users) || count($users) < 1) { echo "Need 1 user\n"; exit(1); }
$ownerPkey = (int)$users[0]->pkey;

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

// One id per source so the §10.4 priority cases stay independent.
$stamp        = time();
$sampleA      = 'smoke-cul-A-' . $stamp;  // Field-owned across the run
$sampleB      = 'smoke-cul-B-' . $stamp;  // Micro-created, later taken over by Field
$createdIds   = array($sampleA, $sampleB);

/**
 * Pull the most recent changelog row for ($sampleId, $ownerPkey).
 */
function latestChangelog($db, $sampleId, $ownerPkey) {
    $row = $db->get_row_prepared(
        "SELECT change_type, source_subsystem, changes
           FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
          ORDER BY changed_at DESC, pkey DESC
          LIMIT 1",
        array($sampleId, (int)$ownerPkey)
    );
    if ($row === null) return null;
    $row->changes = json_decode($row->changes, true);
    return $row;
}

try {
    // --- Sample A: Field is the highest-priority source ---

    echo "\n[A1] Field CREATE (new row)\n";
    $r = $svc->upsertSample(
        'field', $sampleA, $ownerPkey,
        array('name' => 'A-name-1', 'display_sample_type' => 'intact_rock',
              'display_sample_purpose' => 'geochemistry'),
        array('raw' => 'field-payload-1'),
        array('reference_id' => 'spot-' . $stamp, 'reference_userpkey' => $ownerPkey)
    );
    check('A1 upsert ok', !empty($r['ok']) && $r['created'] === true);
    $cl = latestChangelog($db, $sampleA, $ownerPkey);
    check('A1 changelog row exists',            $cl !== null);
    check('A1 change_type=create',              $cl && $cl->change_type === 'create');
    check('A1 source_subsystem=field',          $cl && $cl->source_subsystem === 'field');
    check('A1 spine_written=true',              $cl && $cl->changes['spine_written'] === true);
    check('A1 no spine_diff key on create',     $cl && !array_key_exists('spine_diff', $cl->changes));

    echo "\n[A2] Field UPDATE re-upload, SAME spine values (idempotent)\n";
    $r = $svc->upsertSample(
        'field', $sampleA, $ownerPkey,
        array('name' => 'A-name-1', 'display_sample_type' => 'intact_rock',
              'display_sample_purpose' => 'geochemistry'),
        array('raw' => 'field-payload-1-resync'),
        array('reference_id' => 'spot-' . $stamp, 'reference_userpkey' => $ownerPkey)
    );
    check('A2 upsert ok',           !empty($r['ok']) && $r['created'] === false);
    $cl = latestChangelog($db, $sampleA, $ownerPkey);
    check('A2 change_type=update',  $cl && $cl->change_type === 'update');
    check('A2 source_subsystem=field', $cl && $cl->source_subsystem === 'field');
    check('A2 spine_written=true',  $cl && $cl->changes['spine_written'] === true);
    check('A2 no spine_diff (no actual change)',
        $cl && !array_key_exists('spine_diff', $cl->changes));

    echo "\n[A3] Field UPDATE re-upload, CHANGED display_sample_type + name\n";
    $r = $svc->upsertSample(
        'field', $sampleA, $ownerPkey,
        array('name' => 'A-name-2', 'display_sample_type' => 'tephra',
              'display_sample_purpose' => 'geochemistry'),  // purpose unchanged
        array('raw' => 'field-payload-2'),
        array('reference_id' => 'spot-' . $stamp, 'reference_userpkey' => $ownerPkey)
    );
    check('A3 upsert ok',                !empty($r['ok']) && $r['created'] === false);
    $cl = latestChangelog($db, $sampleA, $ownerPkey);
    check('A3 source_subsystem=field',   $cl && $cl->source_subsystem === 'field');
    check('A3 spine_diff present',       $cl && isset($cl->changes['spine_diff']));
    check('A3 spine_diff has name',
        $cl && isset($cl->changes['spine_diff']['name'])
            && $cl->changes['spine_diff']['name']['old'] === 'A-name-1'
            && $cl->changes['spine_diff']['name']['new'] === 'A-name-2');
    check('A3 spine_diff has display_sample_type',
        $cl && isset($cl->changes['spine_diff']['display_sample_type'])
            && $cl->changes['spine_diff']['display_sample_type']['old'] === 'intact_rock'
            && $cl->changes['spine_diff']['display_sample_type']['new'] === 'tephra');
    check('A3 spine_diff omits unchanged display_sample_purpose',
        $cl && isset($cl->changes['spine_diff'])
            && !array_key_exists('display_sample_purpose', $cl->changes['spine_diff']));

    echo "\n[A4] Micro UPDATE upload over Field-owned spine (§10.4: writeSpine=false)\n";
    $r = $svc->upsertSample(
        'micro', $sampleA, $ownerPkey,
        // Micro tries to push different spine values, but Field has higher
        // priority so spine isn't touched and no diff is logged.
        array('name' => 'A-micro-name', 'display_sample_type' => 'sediment'),
        array('raw' => 'micro-payload-1'),
        array('reference_id' => 'micro-' . $stamp, 'reference_userpkey' => $ownerPkey)
    );
    check('A4 upsert ok',              !empty($r['ok']));
    $cl = latestChangelog($db, $sampleA, $ownerPkey);
    check('A4 source_subsystem=micro', $cl && $cl->source_subsystem === 'micro');
    check('A4 spine_written=false',    $cl && $cl->changes['spine_written'] === false);
    check('A4 no spine_diff when spine skipped',
        $cl && !array_key_exists('spine_diff', $cl->changes));
    $spineNow = $db->get_row_prepared(
        "SELECT name, display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleA, $ownerPkey)
    );
    check('A4 Field spine values untouched (name)',
        $spineNow && $spineNow->name === 'A-name-2');
    check('A4 Field spine values untouched (display_sample_type)',
        $spineNow && $spineNow->display_sample_type === 'tephra');

    echo "\n[A5] Experimental UPDATE upload over Field-owned spine (writeSpine=false)\n";
    $r = $svc->upsertSample(
        'experimental', $sampleA, $ownerPkey,
        array('name' => 'A-exp-name', 'display_sample_type' => 'Igneous Rock'),
        array('raw' => 'exp-payload-1'),
        array('reference_id' => 'exp-' . $stamp, 'reference_userpkey' => $ownerPkey)
    );
    check('A5 upsert ok',                     !empty($r['ok']));
    $cl = latestChangelog($db, $sampleA, $ownerPkey);
    check('A5 source_subsystem=experimental', $cl && $cl->source_subsystem === 'experimental');
    check('A5 spine_written=false',           $cl && $cl->changes['spine_written'] === false);
    check('A5 no spine_diff when spine skipped',
        $cl && !array_key_exists('spine_diff', $cl->changes));

    // --- Sample B: Micro creates first, then Field takes over the spine ---

    echo "\n[B1] Micro CREATE (Micro is highest source currently present)\n";
    $r = $svc->upsertSample(
        'micro', $sampleB, $ownerPkey,
        array('name' => 'B-micro-1', 'display_sample_type' => 'sediment'),
        array('raw' => 'micro-payload-B1'),
        array('reference_id' => 'micro-B-' . $stamp, 'reference_userpkey' => $ownerPkey)
    );
    check('B1 upsert ok',                $r['ok'] && $r['created'] === true);
    $cl = latestChangelog($db, $sampleB, $ownerPkey);
    check('B1 change_type=create',       $cl && $cl->change_type === 'create');
    check('B1 source_subsystem=micro',   $cl && $cl->source_subsystem === 'micro');
    check('B1 spine_written=true',       $cl && $cl->changes['spine_written'] === true);
    check('B1 no spine_diff on create',  $cl && !array_key_exists('spine_diff', $cl->changes));

    echo "\n[B2] Field UPDATE upload over Micro-owned spine (Field higher → takes spine)\n";
    $r = $svc->upsertSample(
        'field', $sampleB, $ownerPkey,
        array('name' => 'B-field-1', 'display_sample_type' => 'intact_rock'),
        array('raw' => 'field-payload-B2'),
        array('reference_id' => 'spot-B-' . $stamp, 'reference_userpkey' => $ownerPkey)
    );
    check('B2 upsert ok',                $r['ok'] && $r['created'] === false);
    $cl = latestChangelog($db, $sampleB, $ownerPkey);
    check('B2 source_subsystem=field',   $cl && $cl->source_subsystem === 'field');
    check('B2 spine_written=true',       $cl && $cl->changes['spine_written'] === true);
    check('B2 spine_diff present',       $cl && isset($cl->changes['spine_diff']));
    check('B2 spine_diff name old=Micro value',
        $cl && isset($cl->changes['spine_diff']['name'])
            && $cl->changes['spine_diff']['name']['old'] === 'B-micro-1'
            && $cl->changes['spine_diff']['name']['new'] === 'B-field-1');
    check('B2 spine_diff display_sample_type old=Micro value',
        $cl && isset($cl->changes['spine_diff']['display_sample_type'])
            && $cl->changes['spine_diff']['display_sample_type']['old'] === 'sediment'
            && $cl->changes['spine_diff']['display_sample_type']['new'] === 'intact_rock');

} finally {
    // Hermetic teardown (FK CASCADE handles changelog + links).
    foreach ($createdIds as $id) {
        $db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($id, $ownerPkey)
        );
    }
}

echo "\n----------------------------------------\n";
if (empty($failures)) {
    echo "All checks PASS\n";
    exit(0);
}
echo count($failures) . " FAILED:\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
