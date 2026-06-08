<?php
/**
 * File: smoke_test_exp_export_readthrough.php
 * Description: Verifies the Experimental download paths overlay
 *              strabosamples.* spine onto the experiment.json embedded
 *              sample at download time, so Samples-app edits surface
 *              in every Experimental export (JSON + PDF, single-experiment
 *              + project-level).
 *
 *              Strategy:
 *                Part 1: experimental_sample_overlay_apply unit-style on
 *                        a single experiment object. Exercises the
 *                        spine → JSON-path mapping including the nested
 *                        material.material.type + material.provenance.
 *                        location.{latitude,longitude} writes.
 *                Part 2: experimental_sample_overlay_apply_batch — multi-
 *                        experiment batched lookup across mixed owners.
 *                        Confirms each tuple gets the right spine row.
 *                Part 3: Missing sample block / missing strabo_id / no
 *                        spine row — all are clean no-ops.
 *                Part 4: Nested-path creation. An experiment.json with no
 *                        material/provenance/location sub-objects gets
 *                        the chain created when the spine carries lat/lng.
 *                Part 5: Conservative null — spine NULL values do NOT
 *                        wipe existing JSON values (only non-null spine
 *                        values overlay).
 *
 *              Hermetic: seeds strabosamples.samples rows and tears them
 *              down in finally{}. No PG straboexp.* fixtures needed —
 *              the helpers take decoded JSON objects directly.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_exp_export_readthrough.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/experimental/lib/sample_overlay.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 2", array()
);
if (!is_array($users) || count($users) < 2) { echo "Need 2 users\n"; exit(1); }
$ownerAPkey = (int)$users[0]->pkey;
$ownerBPkey = (int)$users[1]->pkey;

$stamp = time();
$sampleA = "smoketest-exp-overlay-A-$stamp";
$sampleB = "smoketest-exp-overlay-B-$stamp";
$sampleNullSpine = "smoketest-exp-overlay-null-$stamp";
$cleanupSamples = array(
    array($sampleA, $ownerAPkey),
    array($sampleB, $ownerBPkey),
    array($sampleNullSpine, $ownerAPkey),
);

try {

    // Seed strabosamples.samples rows. Each row carries the edited spine
    // that the overlay should propagate to the JSON.
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, igsn, description, latitude, longitude,
             display_sample_type, created_by, modified_by, created_at, modified_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $2, $2, NOW(), NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET
            name=$3, igsn=$4, description=$5, latitude=$6, longitude=$7,
            display_sample_type=$8, modified_at=NOW()",
        array($sampleA, $ownerAPkey,
              'EditedExpName', 'IGSN-EXP-NEW', 'edited via Samples app',
              45.5, -120.5, 'tephra')
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, igsn, description, latitude, longitude,
             display_sample_type, created_by, modified_by, created_at, modified_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $2, $2, NOW(), NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET
            name=$3, igsn=$4, description=$5, latitude=$6, longitude=$7,
            display_sample_type=$8, modified_at=NOW()",
        array($sampleB, $ownerBPkey,
              'OwnerBSample', 'IGSN-B', 'owner B desc',
              33.3, -90.0, 'sediment')
    );
    // Null-spine row — only id + userpkey set, rest null. Used to verify
    // that NULL spine values don't wipe existing JSON values.
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, created_by, modified_by, created_at, modified_at)
         VALUES ($1, $2, $2, $2, NOW(), NOW())
         ON CONFLICT (id, userpkey) DO NOTHING",
        array($sampleNullSpine, $ownerAPkey)
    );

    // -------------------------------------------------------------------
    // PART 1: experimental_sample_overlay_apply single-experiment.
    // -------------------------------------------------------------------
    echo "=== Part 1: experimental_sample_overlay_apply single ===\n";

    $expA = (object)array(
        'sample' => (object)array(
            'strabo_id'   => $sampleA,
            'id'          => 'human-id-A',
            'name'        => 'OriginalUploadName',
            'igsn'        => 'IGSN-EXP-OLD',
            'description' => 'original description',
            'material'    => (object)array(
                'material'   => (object)array('type' => 'igneous', 'name' => 'granite'),
                'provenance' => (object)array(
                    'location' => (object)array('latitude' => '40.0', 'longitude' => '-100.0'),
                ),
            ),
        ),
        'experiment' => (object)array('name' => 'TestExperiment'),
    );

    experimental_sample_overlay_apply($expA, $db, $ownerAPkey);

    check("single: sample.name overlaid from spine.name",
          $expA->sample->name === 'EditedExpName');
    check("single: sample.igsn overlaid",
          $expA->sample->igsn === 'IGSN-EXP-NEW');
    check("single: sample.description overlaid",
          $expA->sample->description === 'edited via Samples app');
    check("single: sample.material.material.type overlaid",
          isset($expA->sample->material->material->type) &&
          $expA->sample->material->material->type === 'tephra');
    check("single: sample.material.material.name UNCHANGED",
          isset($expA->sample->material->material->name) &&
          $expA->sample->material->material->name === 'granite');
    check("single: provenance.location.latitude overlaid",
          isset($expA->sample->material->provenance->location->latitude) &&
          (string)$expA->sample->material->provenance->location->latitude === '45.5');
    check("single: provenance.location.longitude overlaid",
          isset($expA->sample->material->provenance->location->longitude) &&
          (string)$expA->sample->material->provenance->location->longitude === '-120.5');
    check("single: non-sample sections UNCHANGED",
          isset($expA->experiment->name) && $expA->experiment->name === 'TestExperiment');

    // -------------------------------------------------------------------
    // PART 2: experimental_sample_overlay_apply_batch — multi-owner.
    // -------------------------------------------------------------------
    echo "\n=== Part 2: experimental_sample_overlay_apply_batch ===\n";

    $exp1 = (object)array(
        'sample' => (object)array('strabo_id' => $sampleA, 'name' => 'pre-overlay-1'),
    );
    $exp2 = (object)array(
        'sample' => (object)array('strabo_id' => $sampleB, 'name' => 'pre-overlay-2'),
    );
    $exp3 = (object)array(
        'sample' => (object)array('strabo_id' => $sampleA, 'name' => 'pre-overlay-3'),
    );

    experimental_sample_overlay_apply_batch(array(
        array($exp1, $ownerAPkey),
        array($exp2, $ownerBPkey),
        array($exp3, $ownerAPkey),
    ), $db);

    check("batch: exp1 sample.name = 'EditedExpName' (owner A spine)",
          $exp1->sample->name === 'EditedExpName');
    check("batch: exp2 sample.name = 'OwnerBSample' (owner B spine)",
          $exp2->sample->name === 'OwnerBSample');
    check("batch: exp3 sample.name = 'EditedExpName' (owner A reused)",
          $exp3->sample->name === 'EditedExpName');

    // -------------------------------------------------------------------
    // PART 3: missing sample / strabo_id / spine row → clean no-op.
    // -------------------------------------------------------------------
    echo "\n=== Part 3: missing pieces are clean no-ops ===\n";

    $noSample = (object)array('experiment' => (object)array('name' => 'NoSampleHere'));
    experimental_sample_overlay_apply($noSample, $db, $ownerAPkey);
    check("no sample block: unchanged",
          !isset($noSample->sample) && isset($noSample->experiment->name) &&
          $noSample->experiment->name === 'NoSampleHere');

    $noStraboId = (object)array(
        'sample' => (object)array('name' => 'NoStraboId', 'id' => 'human-id'),
    );
    experimental_sample_overlay_apply($noStraboId, $db, $ownerAPkey);
    check("no strabo_id: sample.name unchanged",
          $noStraboId->sample->name === 'NoStraboId');

    $noRow = (object)array(
        'sample' => (object)array(
            'strabo_id' => "smoketest-exp-unknown-$stamp",
            'name'      => 'NoSpineRow',
        ),
    );
    experimental_sample_overlay_apply($noRow, $db, $ownerAPkey);
    check("no spine row: sample.name unchanged",
          $noRow->sample->name === 'NoSpineRow');

    // -------------------------------------------------------------------
    // PART 4: nested-path creation when source JSON omits intermediate
    // branches.
    // -------------------------------------------------------------------
    echo "\n=== Part 4: nested-path creation ===\n";

    // A sample with only top-level fields. Overlay should build the
    // material.provenance.location chain when applying lat/lng.
    $flat = (object)array(
        'sample' => (object)array(
            'strabo_id' => $sampleA,
            'name'      => 'pre-overlay-flat',
        ),
    );
    experimental_sample_overlay_apply($flat, $db, $ownerAPkey);
    check("nested: material chain created",
          isset($flat->sample->material) && is_object($flat->sample->material));
    check("nested: material.material.type set",
          isset($flat->sample->material->material->type) &&
          $flat->sample->material->material->type === 'tephra');
    check("nested: material.provenance.location.latitude set",
          isset($flat->sample->material->provenance->location->latitude) &&
          (string)$flat->sample->material->provenance->location->latitude === '45.5');
    check("nested: material.provenance.location.longitude set",
          isset($flat->sample->material->provenance->location->longitude) &&
          (string)$flat->sample->material->provenance->location->longitude === '-120.5');

    // -------------------------------------------------------------------
    // PART 5: conservative null — NULL spine values do NOT wipe JSON.
    // -------------------------------------------------------------------
    echo "\n=== Part 5: conservative null ===\n";

    $withValues = (object)array(
        'sample' => (object)array(
            'strabo_id'   => $sampleNullSpine,
            'name'        => 'KeepThisName',
            'igsn'        => 'IGSN-KEEP',
            'description' => 'keep this description',
            'material'    => (object)array(
                'material' => (object)array('type' => 'keep-this-type'),
            ),
        ),
    );
    experimental_sample_overlay_apply($withValues, $db, $ownerAPkey);
    check("null spine: sample.name preserved ('KeepThisName')",
          $withValues->sample->name === 'KeepThisName');
    check("null spine: sample.igsn preserved",
          $withValues->sample->igsn === 'IGSN-KEEP');
    check("null spine: sample.description preserved",
          $withValues->sample->description === 'keep this description');
    check("null spine: material.material.type preserved ('keep-this-type')",
          isset($withValues->sample->material->material->type) &&
          $withValues->sample->material->material->type === 'keep-this-type');

} finally {
    foreach ($cleanupSamples as $pair) {
        $db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            $pair
        );
    }
}

echo "\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
