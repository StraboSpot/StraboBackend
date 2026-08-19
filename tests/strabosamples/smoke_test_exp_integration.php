<?php
/**
 * File: smoke_test_exp_integration.php
 * Description: Service-layer smoke test for samples/exp-integration. Calls
 *              exp_sample_sync() directly with realistic sample shapes and
 *              verifies the strabosamples.* mirror lands correctly:
 *                spine projection (name / igsn / lat-lng / display_type) →
 *                experimental_data JSONB shape →
 *                subsystem_link with subsystem='experimental' →
 *                children (composition / parameters / documents) →
 *                idempotency (re-call → same strabo_id, no new rows) →
 *                update flow (modified spine reflected) →
 *                empty-sample removal (DELETE on both sides)
 *
 *              Hermetic: builds a sentinel straboexp.project + experiment
 *              row and tears them down in finally (CASCADE on
 *              project → experiment → sample handles the source side).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_exp_integration.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/experimental/lib/sample_sync.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

// Pick a non-deleted user as the sample owner.
$row = $db->get_row_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array()
);
$ownerPkey = (int)$row->pkey;
$uuid_gen  = new UUID();

$stamp = time();
$projectName = "smoketest-expint-$stamp";

// --- Seed straboexp.project + straboexp.experiment ---
$projectPkey   = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
$projectUuid   = $uuid_gen->v4();
$db->prepare_query(
    "INSERT INTO straboexp.project (pkey, userpkey, uuid, name, ispublic)
     VALUES ($1, $2, $3, $4, FALSE)",
    array($projectPkey, $ownerPkey, $projectUuid, $projectName)
);
$experimentPkey = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
$experimentUuid = $uuid_gen->v4();
$db->prepare_query(
    "INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, uuid, json)
     VALUES ($1, $2, $3, $4, $5, '{}')",
    array($experimentPkey, $projectPkey, $ownerPkey, "expid-$stamp", $experimentUuid)
);

echo "owner=$ownerPkey  project_pkey=$projectPkey  experiment_pkey=$experimentPkey\n\n";

// Capture strabo_ids for cleanup.
$straboIds = array();

try {
    // ------ Round 1 ------
    echo "=== Round 1: first sync (create) ===\n";
    $sample = (object)array(
        'id'          => "expsample-$stamp",
        'name'        => 'Smoke Exp Sample',
        'igsn'        => "IGSN-$stamp",
        'description' => 'initial',
        'material'    => (object)array(
            'material' => (object)array('type' => 'igneous', 'name' => 'basalt', 'state' => 'solid', 'note' => ''),
            'provenance' => (object)array(
                'formation' => 'Columbia River',
                'location'  => (object)array(
                    'latitude'  => '45.5',
                    'longitude' => '-120.0',
                    'city'      => 'Portland',
                    'country'   => 'USA',
                ),
            ),
            'texture'    => (object)array('bedding' => 'horizontal'),
            'composition' => array(
                (object)array('mineral' => 'plagioclase', 'fraction' => '60', 'unit' => 'Vol%'),
                (object)array('mineral' => 'pyroxene',    'fraction' => '30', 'unit' => 'Vol%'),
            ),
        ),
        'parameters' => array(
            (object)array('control' => 'temperature', 'value' => '800', 'unit' => 'C'),
        ),
        'documents'  => array(
            (object)array('uuid' => "doc-$stamp", 'type' => 'image', 'format' => 'png', 'original_filename' => 'a.png'),
        ),
    );

    $strabo_id_1 = exp_sample_sync($db, $uuid_gen, $experimentPkey, $ownerPkey, $sample);
    check("first sync returns a strabo_id",                   !empty($strabo_id_1));
    if ($strabo_id_1) $straboIds[] = $strabo_id_1;

    echo "\n=== strabosamples.samples row written ===\n";
    $row = $db->get_row_prepared(
        "SELECT id, userpkey, name, igsn, description, latitude, longitude,
                display_sample_type, experimental_data::text AS exp_data
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($strabo_id_1, $ownerPkey)
    );
    check("samples row exists",                               $row !== null);
    check("id matches the returned strabo_id",                $row && $row->id === $strabo_id_1);
    check("name projected",                                   $row && $row->name === 'Smoke Exp Sample');
    check("igsn projected",                                   $row && $row->igsn === "IGSN-$stamp");
    check("latitude projected (45.5)",                        $row && (float)$row->latitude === 45.5);
    check("longitude projected (-120)",                       $row && (float)$row->longitude === -120.0);
    check("display_sample_type = 'igneous'",                  $row && $row->display_sample_type === 'igneous');

    echo "\n=== experimental_data JSONB shape ===\n";
    $exp = $row && $row->exp_data ? json_decode($row->exp_data, true) : null;
    check("experimental_data decoded",                        is_array($exp));
    check("contains material_type",                           isset($exp['material_type']) && $exp['material_type'] === 'igneous');
    check("contains provenance_formation",                    isset($exp['provenance_formation']) && $exp['provenance_formation'] === 'Columbia River');
    check("contains _sample_json (round-trip blob)",          isset($exp['_sample_json']));

    echo "\n=== sample_subsystem_links row ===\n";
    $link = $db->get_row_prepared(
        "SELECT subsystem, reference_id, reference_userpkey, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='experimental'",
        array($strabo_id_1, $ownerPkey)
    );
    check("link row exists",                                  $link !== null);
    check("subsystem = 'experimental'",                       $link && $link->subsystem === 'experimental');
    check("reference_id = experiment_pkey",                   $link && $link->reference_id === (string)$experimentPkey);
    $rm = $link && $link->rm ? json_decode($link->rm, true) : null;
    check("reference_metadata has project_pkey",              isset($rm['project_pkey']) && (int)$rm['project_pkey'] === $projectPkey);
    check("reference_metadata has project_uuid",              isset($rm['project_uuid']) && $rm['project_uuid'] === $projectUuid);

    echo "\n=== children projected ===\n";
    $comp = $db->get_results_prepared(
        "SELECT mineral, fraction FROM strabosamples.sample_composition
          WHERE sample_id=$1 AND sample_userpkey=$2 ORDER BY ordering",
        array($strabo_id_1, $ownerPkey)
    );
    check("composition count = 2",                            is_array($comp) && count($comp) === 2);
    check("first composition = plagioclase",                  $comp && $comp[0]->mineral === 'plagioclase');

    $param = $db->get_results_prepared(
        "SELECT control, value FROM strabosamples.sample_parameters
          WHERE sample_id=$1 AND sample_userpkey=$2 ORDER BY ordering",
        array($strabo_id_1, $ownerPkey)
    );
    check("parameters count = 1",                             is_array($param) && count($param) === 1);
    check("first parameter = temperature",                    $param && $param[0]->control === 'temperature');

    $docs = $db->get_results_prepared(
        "SELECT uuid, type FROM strabosamples.sample_documents
          WHERE sample_id=$1 AND sample_userpkey=$2 ORDER BY ordering",
        array($strabo_id_1, $ownerPkey)
    );
    check("documents count = 1",                              is_array($docs) && count($docs) === 1);
    check("first document uuid round-trips",                  $docs && $docs[0]->uuid === "doc-$stamp");

    echo "\n=== changelog: 'create' with source='experimental' ===\n";
    $clog = $db->get_row_prepared(
        "SELECT change_type, source_subsystem FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
          ORDER BY pkey DESC LIMIT 1",
        array($strabo_id_1, $ownerPkey)
    );
    check("change_type = 'create'",                           $clog && $clog->change_type === 'create');
    check("source_subsystem = 'experimental'",                $clog && $clog->source_subsystem === 'experimental');

    // ------ Round 2: idempotency ------
    echo "\n=== Round 2: re-sync same data (idempotent) ===\n";
    $strabo_id_2 = exp_sample_sync($db, $uuid_gen, $experimentPkey, $ownerPkey, $sample);
    check("same strabo_id returned",                          $strabo_id_2 === $strabo_id_1);

    $sampleCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($strabo_id_1, $ownerPkey)
    );
    check("strabosamples.samples still 1 row",                $sampleCount === 1);
    $linkCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2",
        array($strabo_id_1, $ownerPkey)
    );
    check("subsystem_links unchanged (1 row)",                $linkCount === 1);
    $compCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_composition
          WHERE sample_id=$1 AND sample_userpkey=$2",
        array($strabo_id_1, $ownerPkey)
    );
    check("composition replaced, still 2 rows",               $compCount === 2);

    // ------ Round 3: update ------
    echo "\n=== Round 3: modify name + add composition row ===\n";
    $sample->name = 'Smoke Exp Sample RENAMED';
    $sample->material->composition[] = (object)array('mineral' => 'olivine', 'fraction' => '10', 'unit' => 'Vol%');
    $strabo_id_3 = exp_sample_sync($db, $uuid_gen, $experimentPkey, $ownerPkey, $sample);
    check("update returns same strabo_id",                    $strabo_id_3 === $strabo_id_1);

    $row = $db->get_row_prepared(
        "SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($strabo_id_1, $ownerPkey)
    );
    check("name reflects rename",                             $row && $row->name === 'Smoke Exp Sample RENAMED');
    $compCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_composition
          WHERE sample_id=$1 AND sample_userpkey=$2",
        array($strabo_id_1, $ownerPkey)
    );
    check("composition count = 3 after add",                  $compCount === 3);

    $clog = $db->get_row_prepared(
        "SELECT change_type, source_subsystem FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
          ORDER BY pkey DESC LIMIT 1",
        array($strabo_id_1, $ownerPkey)
    );
    check("latest changelog = 'update'",                      $clog && $clog->change_type === 'update');
    check("source_subsystem still 'experimental'",            $clog && $clog->source_subsystem === 'experimental');

    // ------ Round 4: empty sample → removal ------
    echo "\n=== Round 4: empty sample removes both sides ===\n";
    $strabo_id_4 = exp_sample_sync($db, $uuid_gen, $experimentPkey, $ownerPkey, null);
    check("empty-sample returns null",                        $strabo_id_4 === null);

    $sampleCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($strabo_id_1, $ownerPkey)
    );
    check("strabosamples row removed",                        $sampleCount === 0);
    $expCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.sample WHERE experiment_pkey=$1",
        array($experimentPkey)
    );
    check("straboexp.sample row removed",                     $expCount === 0);

} finally {
    // Defensive cleanup.
    foreach ($straboIds as $sid) {
        $db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sid, $ownerPkey)
        );
    }
    $db->prepare_query("DELETE FROM straboexp.project WHERE pkey=$1", array($projectPkey));
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
