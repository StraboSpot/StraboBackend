<?php
/**
 * File: smoke_test_drift_audit.php
 * Description: Smoke test for samplesdb/audit/audit_cross_system_drift.php.
 *              The auditor's clean-corpus behavior is proven by running it
 *              against the full dev DB; this suite proves the OTHER half —
 *              that it actually DETECTS drift when drift exists, and that
 *              it classifies design-allowed divergence as INFO rather than
 *              DRIFT. Seeds sentinel samples through the real sync helpers,
 *              then mutates one side directly (bypassing sync) to fabricate
 *              each drift class:
 *
 *                micro: source column mutated under the mirror   → DRIFT
 *                micro: source row without any sync              → DRIFT (coverage)
 *                micro: samples_api spine edit (no writeback)    → INFO, not DRIFT
 *                exp:   source row deleted under the mirror      → DRIFT (stale mirror)
 *                field: spine mutated directly (rogue write)     → DRIFT
 *                field: linked Spot deleted without sync         → DRIFT (stale link)
 *                field: Spot with samples never synced           → DRIFT (coverage)
 *                clean sentinels                                 → zero findings
 *
 *              Hermetic: all sentinel rows/nodes are torn down in finally{},
 *              including the strabosamples rows (FK cascade covers children).
 *              The auditor itself is read-only — all mutation happens here.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_drift_audit.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/microdb/lib/sample_sync.php';
require_once '/srv/app/www/experimental/lib/sample_sync.php';
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

// Prefer a user with no existing subsystem links so the scoped auditor run
// only probes our sentinels; fall back to the first active user.
$ownerPkey = (int)$db->get_var(
    "SELECT u.pkey FROM users u
      WHERE u.deleted = FALSE AND u.active = TRUE
        AND NOT EXISTS (SELECT 1 FROM strabosamples.sample_subsystem_links l
                         WHERE l.sample_userpkey = u.pkey)
      ORDER BY u.pkey LIMIT 1"
);
if ($ownerPkey <= 0) {
    $ownerPkey = (int)$db->get_var(
        "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1"
    );
}
if ($ownerPkey <= 0) { echo "Need a user\n"; exit(1); }
$uuid_gen  = new UUID();

$stamp = time();
$nowMs = $stamp * 1000;

// Sentinel ids.
$microClean   = "smoketest-drift-mclean-$stamp";   // synced, untouched          → no findings
$microMutated = "smoketest-drift-mmut-$stamp";     // source mutated post-sync   → DRIFT
$microNoSync  = "smoketest-drift-mnosync-$stamp";  // source row, never synced   → DRIFT coverage
$microApiEdit = "smoketest-drift-mapi-$stamp";     // samples_api spine edit     → INFO only
$expStale     = null;                              // exp uuid minted by sync    → DRIFT stale mirror
$spotClean    = (int)($stamp * 100 + 41);          // synced, untouched          → no findings
$spotRogue    = (int)($stamp * 100 + 42);          // spine mutated post-sync    → DRIFT
$spotDeleted  = (int)($stamp * 100 + 43);          // Spot deleted after sync    → DRIFT stale link
$spotNoSync   = (int)($stamp * 100 + 44);          // Spot w/ samples, no sync   → DRIFT coverage
$projectStraboId = (string)($stamp * 100 + 51);
$datasetStraboId = (string)($stamp * 100 + 52);

$microProjId = null; $microDsId = null; $microSampleIds = array();
$expProjPkey = null; $expExpPkey = null;

echo "owner=$ownerPkey  stamp=$stamp\n\n";

try {
    // =====================================================================
    // Seed — Micro (project + dataset + 4 source samples; 3 synced)
    // =====================================================================
    $microProjId = (int)$db->get_var("SELECT nextval('micro_projectmetadata_id_seq')");
    $microDsId   = (int)$db->get_var("SELECT nextval('micro_datasetmetadata_id_seq')");
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, name, sharekey)
         VALUES ($1, $2, $3, $4, $5)",
        array($microProjId, $ownerPkey, "smoketest-driftproj-$stamp", "drift audit proj $stamp", 'k'.$stamp)
    );
    $db->prepare_query(
        "INSERT INTO micro_datasetmetadata (id, project_id, strabo_id, name)
         VALUES ($1, $2, $3, $4)",
        array($microDsId, $microProjId, "drift-ds-$stamp", "drift ds $stamp")
    );
    foreach (array($microClean, $microMutated, $microNoSync, $microApiEdit) as $sid) {
        $mid = (int)$db->get_var("SELECT nextval('micro_samplemetadata_id_seq')");
        $microSampleIds[] = $mid;
        $db->prepare_query(
            "INSERT INTO micro_samplemetadata (id, dataset_id, strabo_id, label, sampleid,
                                               sampledescription, materialtype, latitude, longitude)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)",
            array($mid, $microDsId, $sid, 'L', "label-$sid", 'seeded description', 'igneous', '42.1', '-100.5')
        );
    }
    foreach (array($microClean, $microMutated, $microApiEdit) as $sid) {   // NOT $microNoSync
        // Mirror the source row EXACTLY (the real loadProjectJSON path passes
        // the same object it writes to micro_samplemetadata) — any field
        // present in the table but absent here would read as drift.
        $sampleObj = (object)array(
            'id'                  => $sid,
            'label'               => 'L',
            'sampleID'            => "label-$sid",
            'sampleDescription'   => 'seeded description',
            'materialType'        => 'igneous',
            'latitude'            => '42.1',
            'longitude'           => '-100.5',
        );
        micro_sample_sync($db, $sampleObj, "smoketest-driftproj-$stamp", $microProjId, $ownerPkey, $microDsId, 0);
    }

    // =====================================================================
    // Seed — Experimental (project + experiment + synced sample)
    // =====================================================================
    $expProjPkey = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.project (pkey, userpkey, uuid, name, ispublic)
         VALUES ($1, $2, $3, $4, FALSE)",
        array($expProjPkey, $ownerPkey, $uuid_gen->v4(), "drift audit exp proj $stamp")
    );
    $expExpPkey = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, uuid, json)
         VALUES ($1, $2, $3, $4, $5, '{}')",
        array($expExpPkey, $expProjPkey, $ownerPkey, "drift-expid-$stamp", $uuid_gen->v4())
    );
    $expStale = exp_sample_sync($db, $uuid_gen, $expExpPkey, $ownerPkey, (object)array(
        'name'        => "drift exp sample $stamp",
        'igsn'        => '',
        'id'          => "drift-exp-$stamp",
        'description' => 'exp seeded',
    ));
    check("exp sync minted a strabo_id", !empty($expStale));

    // =====================================================================
    // Seed — Field (3 rich sample-spots synced + 1 never synced)
    // =====================================================================
    foreach (array($spotClean, $spotRogue, $spotDeleted, $spotNoSync) as $spotId) {
        $entry = array(
            'id'                 => (string)$spotId,
            'sample_id_name'     => "drift-$spotId",
            'sample_description' => 'field seeded',
            'material_type'      => 'intact_rock',
        );
        $js = addslashes(json_encode(array($entry)));
        $neodb->query(
            "CREATE (s:Spot {id:$spotId, userpkey:$ownerPkey, isSample:1,
                             json_samples:'$js', wkt:'POINT (-101.25 39.75)',
                             modified_timestamp:$nowMs, name:'drift spot $spotId'})"
        );
        if ($spotId !== $spotNoSync) {
            $props = (object)array(
                'samples'  => array((object)$entry),
                'isSample' => 1,
                'wkt'      => 'POINT (-101.25 39.75)',
            );
            field_sample_sync_spot($db, $neodb, $props, $spotId, $ownerPkey, $projectStraboId, $datasetStraboId);
        }
    }

    // =====================================================================
    // Fabricate the drift
    // =====================================================================

    // Micro: mutate a source column, bypassing sync.
    $db->prepare_query(
        "UPDATE micro_samplemetadata SET sampledescription='mutated behind the mirror'
          WHERE strabo_id = $1 AND dataset_id = $2",
        array($microMutated, $microDsId)
    );

    // Micro: samples_api spine edit — the design-allowed INFO case.
    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey($ownerPkey);
    $ret = $svc->updateSample($microApiEdit, $ownerPkey, array('description' => 'edited via samples api'));
    check("samples_api edit succeeded", is_array($ret) && !empty($ret['ok']));

    // Exp: delete the source sample row, keeping the mirror.
    $db->prepare_query("DELETE FROM straboexp.sample WHERE experiment_pkey = $1", array($expExpPkey));

    // Field: rogue direct write to the spine (no writeback, no changelog source).
    $db->prepare_query(
        "UPDATE strabosamples.samples SET name='ROGUE OVERWRITE' WHERE id=$1 AND userpkey=$2",
        array((string)$spotRogue, $ownerPkey)
    );

    // Field: delete a linked Spot without the sync hook.
    $neodb->query("MATCH (s:Spot {id:$spotDeleted, userpkey:$ownerPkey}) DETACH DELETE s");

    // =====================================================================
    // Run the auditor (read-only), scoped to the owner, and parse the JSON.
    // =====================================================================
    echo "\n=== running auditor ===\n";
    $out = shell_exec("php /srv/app/www/samplesdb/audit/audit_cross_system_drift.php"
                    . " --user $ownerPkey --sample 0 --since 1 --json 2>&1");
    check("auditor produced output", is_string($out) && $out !== '');
    $parts = explode("---JSON---", (string)$out);
    $report = count($parts) === 2 ? json_decode(trim($parts[1]), true) : null;
    check("auditor emitted parseable JSON", is_array($report) && isset($report['findings']));
    $findings = is_array($report) && isset($report['findings']) ? $report['findings'] : array();

    $matches = function ($sampleId, $class = null, $kind = null) use ($findings) {
        foreach ($findings as $f) {
            if ($f['sample_id'] !== (string)$sampleId) continue;
            if ($class !== null && $f['class'] !== $class) continue;
            if ($kind  !== null && $f['kind']  !== $kind)  continue;
            return true;
        }
        return false;
    };

    echo "\n=== assertions ===\n";
    // DRIFT detection — one per fabricated class.
    check("micro source mutation detected (micro_data_mismatch DRIFT)",
        $matches($microMutated, 'DRIFT', 'micro_data_mismatch'));
    check("micro source mutation also drifts the spine (spine_mismatch DRIFT)",
        $matches($microMutated, 'DRIFT', 'spine_mismatch'));
    check("unsynced micro source detected (missing_spine_row DRIFT)",
        $matches($microNoSync, 'DRIFT', 'missing_spine_row'));
    check("deleted exp source detected (stale_exp_mirror DRIFT)",
        $matches($expStale, 'DRIFT', 'stale_exp_mirror'));
    check("rogue field spine write detected (spine_mismatch DRIFT)",
        $matches((string)$spotRogue, 'DRIFT', 'spine_mismatch'));
    check("deleted Spot detected (stale_field_link DRIFT)",
        $matches((string)$spotDeleted, 'DRIFT', 'stale_field_link'));
    check("unsynced Spot detected (field coverage missing_spine_row DRIFT)",
        $matches((string)$spotNoSync, 'DRIFT', 'missing_spine_row'));

    // Classification — the samples_api edit must be INFO, never DRIFT.
    check("samples_api micro edit classified INFO manual_edit_no_writeback",
        $matches($microApiEdit, 'INFO', 'manual_edit_no_writeback'));
    check("samples_api micro edit produced NO DRIFT finding",
        !$matches($microApiEdit, 'DRIFT'));

    // Clean sentinels — zero findings of any class.
    check("clean micro sentinel has zero findings",  !$matches($microClean));
    check("clean field sentinel has zero findings",  !$matches((string)$spotClean));

} finally {
    echo "\n=== teardown ===\n";
    // strabosamples rows (FK cascade removes children/links/changelog).
    $allSpine = array($microClean, $microMutated, $microNoSync, $microApiEdit,
                      (string)$spotClean, (string)$spotRogue, (string)$spotDeleted, (string)$spotNoSync);
    if (!empty($expStale)) $allSpine[] = (string)$expStale;
    foreach ($allSpine as $sid) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sid, $ownerPkey));
    }
    // Micro source rows.
    foreach ($microSampleIds as $mid) {
        $db->prepare_query("DELETE FROM micro_samplemetadata WHERE id=$1", array($mid));
    }
    if ($microDsId)   $db->prepare_query("DELETE FROM micro_datasetmetadata WHERE id=$1", array($microDsId));
    if ($microProjId) $db->prepare_query("DELETE FROM micro_projectmetadata WHERE id=$1", array($microProjId));
    // Exp source rows (sample already deleted mid-test; children cascade).
    if ($expExpPkey)  $db->prepare_query("DELETE FROM straboexp.sample WHERE experiment_pkey=$1", array($expExpPkey));
    if ($expExpPkey)  $db->prepare_query("DELETE FROM straboexp.experiment WHERE pkey=$1", array($expExpPkey));
    if ($expProjPkey) $db->prepare_query("DELETE FROM straboexp.project WHERE pkey=$1", array($expProjPkey));
    // Neo4j spots.
    foreach (array($spotClean, $spotRogue, $spotDeleted, $spotNoSync) as $spotId) {
        $neodb->query("MATCH (s:Spot {id:$spotId, userpkey:$ownerPkey}) DETACH DELETE s");
    }
    // Sync hooks index the app-endpoint fixtures; the raw source deletes
    // above never unindex (orphaned globe markers, 2026-08-23). Exp
    // project uuids are random, so that sweep is name-based.
    $db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_id LIKE 'smoketest-driftproj-%'", array());
    $db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_id LIKE 'smoketest-driftproj-%'", array());
    $db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_name LIKE 'drift audit exp proj %'", array());
    echo "teardown complete\n";
}

echo "\n" . (empty($failures)
    ? "RESULT: all checks PASS\n"
    : "RESULT: " . count($failures) . " FAILURE(S)\n");
exit(empty($failures) ? 0 : 1);
