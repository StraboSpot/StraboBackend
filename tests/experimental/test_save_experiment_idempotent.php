<?php
/**
 * Idempotency test: exp_sample_sync()
 * -------------------------------------------------------------------------
 * Exercises the helper used by experimental/api/save_experiment.php and the
 * backfill tool. Confirms that re-saving an experiment preserves the existing
 * straboexp.sample row (and its strabo_id) and that children are replaced,
 * not duplicated.
 *
 * Hermetic: creates its own project + experiment rows under a sentinel
 * userpkey, asserts on them, then DELETE-cascades the project so nothing
 * survives between runs. Exits non-zero on any failure.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/tests/experimental/test_save_experiment_idempotent.php
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('includes/UUID.php');
require_once('experimental/lib/sample_sync.php');

$TEST_USERPKEY = 99999990;
$uuid_gen = new UUID();

$passed = 0;
$failed = 0;
$failures = [];

function check($cond, $label) {
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  [ok]   $label\n";
    } else {
        $failed++;
        $failures[] = $label;
        echo "  [FAIL] $label\n";
    }
}

function section($name) { echo "\n== $name ==\n"; }

function makeSample($name = 'Test Sample', $id = 'TS-001', $extraComp = false, $note = 'baseline note') {
    $s = new stdClass();
    $s->name = $name;
    $s->igsn = '';
    $s->id   = $id;
    $s->description = "Test sample for idempotency check";

    $s->parent = new stdClass();
    $s->parent->name = ''; $s->parent->igsn = ''; $s->parent->id = ''; $s->parent->description = '';

    $s->material = new stdClass();
    $s->material->material = new stdClass();
    $s->material->material->type  = 'Sedimentary Rock';
    $s->material->material->name  = 'Sandstone';
    $s->material->material->state = 'Solid';
    $s->material->material->note  = $note;

    $s->material->provenance = new stdClass();
    $s->material->provenance->formation = 'Test Fm';
    $s->material->provenance->member    = '';
    $s->material->provenance->submember = '';
    $s->material->provenance->source    = '';
    $s->material->provenance->location  = new stdClass();
    $s->material->provenance->location->street = ''; $s->material->provenance->location->building = '';
    $s->material->provenance->location->postcode = ''; $s->material->provenance->location->city = 'Lawrence';
    $s->material->provenance->location->state = 'KS';   $s->material->provenance->location->country = 'USA';
    $s->material->provenance->location->latitude = '38.96'; $s->material->provenance->location->longitude = '-95.25';

    $s->material->texture = new stdClass();
    $s->material->texture->bedding = ''; $s->material->texture->lineation = '';
    $s->material->texture->foliation = ''; $s->material->texture->fault = '';

    $comp1 = new stdClass(); $comp1->mineral = 'Quartz';  $comp1->fraction = '60'; $comp1->unit = 'Wt%'; $comp1->grainsize = '';
    $comp2 = new stdClass(); $comp2->mineral = 'Feldspar'; $comp2->fraction = '30'; $comp2->unit = 'Wt%'; $comp2->grainsize = '';
    $s->material->composition = $extraComp
        ? array($comp1, $comp2, (function(){ $c = new stdClass(); $c->mineral='Mica'; $c->fraction='10'; $c->unit='Wt%'; $c->grainsize=''; return $c; })())
        : array($comp1, $comp2);

    $p1 = new stdClass();
    $p1->control = 'Mass'; $p1->value = '12.3'; $p1->unit = 'g'; $p1->prefix = ''; $p1->note = '';
    $s->parameters = array($p1);

    $s->documents = array();
    return $s;
}

function countChildren($db, $sample_pkey) {
    $r = new stdClass();
    $r->comp = (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.sample_composition WHERE sample_pkey = $1", array($sample_pkey));
    $r->param = (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.sample_parameter   WHERE sample_pkey = $1", array($sample_pkey));
    $r->doc = (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.document             WHERE sample_pkey = $1", array($sample_pkey));
    return $r;
}

echo "==========================================================================\n";
echo "exp_sample_sync idempotency test\n";
echo "==========================================================================\n";
echo "Sentinel test userpkey: $TEST_USERPKEY\n";

// Pre-cleanup: drop any stale project from a prior aborted run.
$stale_projects = $db->get_results("SELECT pkey FROM straboexp.project WHERE userpkey = $TEST_USERPKEY");
if (is_array($stale_projects)) {
    foreach ($stale_projects as $sp) {
        $db->prepare_query("DELETE FROM straboexp.project WHERE pkey = $1", array((int)$sp->pkey));
    }
}

// Setup: create a project + experiment to anchor the sample on.
$project_pkey = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
$project_uuid = $uuid_gen->v4();
$db->prepare_query("
    INSERT INTO straboexp.project (pkey, userpkey, uuid, created_timestamp, modified_timestamp, name, notes, ispublic)
    VALUES ($1, $2, $3, NOW(), NOW(), $4, $5, $6)
", array($project_pkey, $TEST_USERPKEY, $project_uuid, 'Idempotency Test Project', 'test', 'f'));

$experiment_pkey = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
$experiment_uuid = $uuid_gen->v4();
$db->prepare_query("
    INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, created_timestamp, modified_timestamp, json, uuid)
    VALUES ($1, $2, $3, $4, NOW(), NOW(), $5, $6)
", array($experiment_pkey, $project_pkey, $TEST_USERPKEY, 'TestExp', '{}', $experiment_uuid));

echo "Setup: project_pkey=$project_pkey, experiment_pkey=$experiment_pkey\n";

try {

section("Test 1 — initial create mints strabo_id and inserts row");
$sample1 = makeSample();
$strabo_id_1 = exp_sample_sync($db, $uuid_gen, $experiment_pkey, $TEST_USERPKEY, $sample1);

$row1 = $db->get_row_prepared("SELECT pkey, strabo_id, name, material_note FROM straboexp.sample WHERE experiment_pkey = $1", array($experiment_pkey));
check($strabo_id_1 !== null, "create returned a strabo_id");
check(!empty($row1->pkey), "row inserted into straboexp.sample");
check($row1->strabo_id === $strabo_id_1, "row strabo_id matches returned value");
check($row1->name === 'Test Sample', "name persisted");

$row_count_1 = (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.sample WHERE experiment_pkey = $1", array($experiment_pkey));
check($row_count_1 === 1, "exactly 1 row for the experiment (got $row_count_1)");

$children1 = countChildren($db, (int)$row1->pkey);
check($children1->comp === 2, "2 composition rows inserted (got {$children1->comp})");
check($children1->param === 1, "1 parameter row inserted (got {$children1->param})");

section("Test 2 — re-save unchanged: row + strabo_id stable, children replaced 1:1");
$strabo_id_2 = exp_sample_sync($db, $uuid_gen, $experiment_pkey, $TEST_USERPKEY, $sample1);
$row2 = $db->get_row_prepared("SELECT pkey, strabo_id FROM straboexp.sample WHERE experiment_pkey = $1", array($experiment_pkey));
check($strabo_id_2 === $strabo_id_1, "strabo_id unchanged across re-save");
check((int)$row2->pkey === (int)$row1->pkey, "sample_pkey unchanged across re-save");
$row_count_2 = (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.sample WHERE experiment_pkey = $1", array($experiment_pkey));
check($row_count_2 === 1, "still exactly 1 row (got $row_count_2)");

$children2 = countChildren($db, (int)$row2->pkey);
check($children2->comp === 2, "still 2 composition rows after re-save (got {$children2->comp})");
check($children2->param === 1, "still 1 parameter row after re-save (got {$children2->param})");

section("Test 3 — edit a spine field: update applies, strabo_id stable");
$sample3 = makeSample('Test Sample', 'TS-001', false, 'EDITED note');
$strabo_id_3 = exp_sample_sync($db, $uuid_gen, $experiment_pkey, $TEST_USERPKEY, $sample3);
$row3 = $db->get_row_prepared("SELECT pkey, strabo_id, material_note FROM straboexp.sample WHERE experiment_pkey = $1", array($experiment_pkey));
check($strabo_id_3 === $strabo_id_1, "strabo_id stable across spine edit");
check((int)$row3->pkey === (int)$row1->pkey, "sample_pkey stable across spine edit");
check($row3->material_note === 'EDITED note', "material_note reflects the edit");

section("Test 4 — edit children (add composition): children replaced, parent + strabo_id stable");
$sample4 = makeSample('Test Sample', 'TS-001', true);
$strabo_id_4 = exp_sample_sync($db, $uuid_gen, $experiment_pkey, $TEST_USERPKEY, $sample4);
$row4 = $db->get_row_prepared("SELECT pkey, strabo_id FROM straboexp.sample WHERE experiment_pkey = $1", array($experiment_pkey));
check($strabo_id_4 === $strabo_id_1, "strabo_id stable across child edit");
check((int)$row4->pkey === (int)$row1->pkey, "sample_pkey stable across child edit");
$children4 = countChildren($db, (int)$row4->pkey);
check($children4->comp === 3, "3 composition rows after add (got {$children4->comp})");
check($children4->param === 1, "still 1 parameter row (got {$children4->param})");

section("Test 5 — empty sample on save: row + children removed");
$strabo_id_5 = exp_sample_sync($db, $uuid_gen, $experiment_pkey, $TEST_USERPKEY, null);
check($strabo_id_5 === null, "empty sample returns null");
$row_count_5 = (int)$db->get_var_prepared("SELECT count(*) FROM straboexp.sample WHERE experiment_pkey = $1", array($experiment_pkey));
check($row_count_5 === 0, "sample row removed (got $row_count_5)");

section("Test 6 — re-create after delete mints a NEW strabo_id (no leakage from the prior row)");
$sample6 = makeSample();
$strabo_id_6 = exp_sample_sync($db, $uuid_gen, $experiment_pkey, $TEST_USERPKEY, $sample6);
check($strabo_id_6 !== null, "fresh create returned a strabo_id");
check($strabo_id_6 !== $strabo_id_1, "strabo_id is different from the deleted row's (no resurrection)");

} finally {
    // Teardown: cascading delete on project removes experiment + sample + children.
    $db->prepare_query("DELETE FROM straboexp.project WHERE pkey = $1", array($project_pkey));
    echo "\nTeardown: project_pkey=$project_pkey removed (cascade).\n";
}

echo "\n==========================================================================\n";
echo "RESULT: passed=$passed, failed=$failed\n";
echo "==========================================================================\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
exit(0);
