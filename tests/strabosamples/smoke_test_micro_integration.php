<?php
/**
 * File: smoke_test_micro_integration.php
 * Description: Service-layer smoke test for samples/micro-integration.
 *              Exercises micro_sample_sync directly with realistic sample
 *              shapes and verifies the strabosamples.* mirror:
 *
 *                spine projection (sampleID → name, sampleDescription →
 *                description, samplenotes → notes, materialType →
 *                display_sample_type with fall-through to
 *                otherMaterialType, mainSamplingPurpose →
 *                display_sample_purpose) →
 *                micro_data JSONB shape (lowercase keys matching migration) →
 *                subsystem_link with subsystem='micro', reference_id =
 *                project internal pkey, reference_metadata = {dataset_id,
 *                project_strabo_id, micrograph_count} →
 *                auto-seed of project collaborators per §7.3.1 →
 *                idempotent re-call (no new sample/link/collab rows) →
 *                micro_sample_sync_remove_project deletes strabosamples
 *                rows when the source row is still resolvable
 *
 *              Hermetic: seeds micro_projectmetadata + datasetmetadata +
 *              samplemetadata + a public.collaborators row, then tears
 *              down via direct DELETEs (no CASCADE wired on the public
 *              source tables — we own the cleanup order).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_micro_integration.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/microdb/lib/sample_sync.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 3", array()
);
if (!is_array($users) || count($users) < 3) { echo "Need 3 users\n"; exit(1); }
$ownerPkey        = (int)$users[0]->pkey;
$collaboratorPkey = (int)$users[1]->pkey;
$readonlyPkey     = (int)$users[2]->pkey;
$uuid_gen = new UUID();

$stamp           = time();
$strabo_id_a     = "smoketest-micro-A-$stamp";
$strabo_id_b     = "smoketest-micro-B-$stamp";
$projectStraboId = "smoketest-microproject-$stamp";

// Seed source tables manually so the integration helper has something to
// resolve against (sample_sync writes to strabosamples; the Micro side
// is set up by the caller — loadProjectJSON in the real path).
$projectInternalId = (int)$db->get_var("SELECT nextval('micro_projectmetadata_id_seq')");
$datasetInternalId = (int)$db->get_var("SELECT nextval('micro_datasetmetadata_id_seq')");
$sampleInternalA   = (int)$db->get_var("SELECT nextval('micro_samplemetadata_id_seq')");
$sampleInternalB   = (int)$db->get_var("SELECT nextval('micro_samplemetadata_id_seq')");

$db->prepare_query(
    "INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, name, sharekey)
     VALUES ($1, $2, $3, $4, $5)",
    array($projectInternalId, $ownerPkey, $projectStraboId, "smoke micro proj $stamp", 'k'.$stamp)
);
$db->prepare_query(
    "INSERT INTO micro_datasetmetadata (id, project_id, strabo_id, name)
     VALUES ($1, $2, $3, $4)",
    array($datasetInternalId, $projectInternalId, "ds-$stamp", "smoke ds $stamp")
);
$db->prepare_query(
    "INSERT INTO micro_samplemetadata (id, dataset_id, strabo_id, label, sampleid, materialtype)
     VALUES ($1, $2, $3, $4, $5, $6)",
    array($sampleInternalA, $datasetInternalId, $strabo_id_a, 'A', 'A-label', 'igneous')
);
$db->prepare_query(
    "INSERT INTO micro_samplemetadata (id, dataset_id, strabo_id, label, sampleid, materialtype, othermaterialtype)
     VALUES ($1, $2, $3, $4, $5, $6, $7)",
    array($sampleInternalB, $datasetInternalId, $strabo_id_b, 'B', 'B-label', 'other', 'lunar regolith')
);

// Pre-seed a MIXED project roster (edit + readonly) so the §7.3.1 auto-seed
// proves per-collaborator level mirroring — the readonly member must NOT
// downgrade the editor's grant.
$collabUuid = $uuid_gen->v4();
$db->prepare_query(
    "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
                                collaboration_level, accepted, uuid, disabled)
     VALUES ($1, $2, $3, 'edit', TRUE, $4, FALSE)",
    array($projectStraboId, $ownerPkey, $collaboratorPkey, $collabUuid)
);
$db->prepare_query(
    "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
                                collaboration_level, accepted, uuid, disabled)
     VALUES ($1, $2, $3, 'readonly', TRUE, $4, FALSE)",
    array($projectStraboId, $ownerPkey, $readonlyPkey, $uuid_gen->v4())
);

echo "owner=$ownerPkey  collab=$collaboratorPkey  project=$projectInternalId ($projectStraboId)  dataset=$datasetInternalId\n\n";

try {
    // ------ Sample A: normal materialType ------
    echo "=== Sample A: sync, normal materialType='igneous' ===\n";
    $sampleA = (object)array(
        'id'                    => $strabo_id_a,
        'sampleID'              => 'A-label',
        'sampleDescription'     => 'normal sample',
        'sampleNotes'           => 'note for A',
        'latitude'              => '45.5',
        'longitude'             => '-120.0',
        'materialType'          => 'igneous',
        'otherMaterialType'     => '',
        'mainSamplingPurpose'   => 'petrology',
        'sampleSize'            => 'large',
        'color'                 => 'gray',
    );
    $ret = micro_sample_sync(
        $db, $sampleA, $projectStraboId, $projectInternalId, $ownerPkey, $datasetInternalId, 3
    );
    check("sync returns the strabo_id",                       $ret === $strabo_id_a);

    $row = $db->get_row_prepared(
        "SELECT id, userpkey, name, description, notes, latitude, longitude,
                display_sample_type, display_sample_purpose, micro_data::text AS md
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($strabo_id_a, $ownerPkey)
    );
    check("samples row exists",                               $row !== null);
    check("name = 'A-label' (from sampleID)",                  $row && $row->name === 'A-label');
    check("description matches",                              $row && $row->description === 'normal sample');
    check("notes matches",                                    $row && $row->notes === 'note for A');
    check("latitude = 45.5",                                   $row && (float)$row->latitude === 45.5);
    check("display_sample_type = 'igneous'",                  $row && $row->display_sample_type === 'igneous');
    check("display_sample_purpose = 'petrology'",             $row && $row->display_sample_purpose === 'petrology');

    $md = $row && $row->md ? json_decode($row->md, true) : null;
    check("micro_data has lowercase 'sampleid' key",          isset($md['sampleid']) && $md['sampleid'] === 'A-label');
    check("micro_data has 'materialtype'",                    isset($md['materialtype']) && $md['materialtype'] === 'igneous');
    check("micro_data has 'samplesize'",                      isset($md['samplesize']) && $md['samplesize'] === 'large');
    check("micro_data carries 'project_strabo_id'",           isset($md['project_strabo_id']) && $md['project_strabo_id'] === $projectStraboId);

    echo "\n=== sample_subsystem_link for A ===\n";
    $link = $db->get_row_prepared(
        "SELECT subsystem, reference_id, reference_userpkey, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='micro'",
        array($strabo_id_a, $ownerPkey)
    );
    check("link row exists",                                  $link !== null);
    check("subsystem = 'micro'",                              $link && $link->subsystem === 'micro');
    check("reference_id = project internal pkey",             $link && $link->reference_id === (string)$projectInternalId);
    $rm = $link && $link->rm ? json_decode($link->rm, true) : null;
    check("reference_metadata.dataset_id matches",            isset($rm['dataset_id']) && (int)$rm['dataset_id'] === $datasetInternalId);
    check("reference_metadata.project_strabo_id matches",     isset($rm['project_strabo_id']) && $rm['project_strabo_id'] === $projectStraboId);
    check("reference_metadata.micrograph_count = 3",          isset($rm['micrograph_count']) && (int)$rm['micrograph_count'] === 3);

    echo "\n=== auto-seed: project collaborator landed pre-accepted ===\n";
    $seeded = $db->get_row_prepared(
        "SELECT permission_level, accepted FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3",
        array($strabo_id_a, $ownerPkey, $collaboratorPkey)
    );
    check("collaborator seeded into sample_collaborators",    $seeded !== null);
    check("editor mirrors own level ('edit' despite readonly co-member)",
          $seeded && $seeded->permission_level === 'edit');
    check("seeded accepted = true",                           $seeded && in_array((string)$seeded->accepted, array('t','true','1'), true));
    $seededRo = $db->get_row_prepared(
        "SELECT permission_level FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3",
        array($strabo_id_a, $ownerPkey, $readonlyPkey)
    );
    check("readonly member seeded at own level",              $seededRo && $seededRo->permission_level === 'readonly');

    echo "\n=== changelog: 'create' source='micro' ===\n";
    $clog = $db->get_row_prepared(
        "SELECT change_type, source_subsystem FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type='create'
          ORDER BY pkey DESC LIMIT 1",
        array($strabo_id_a, $ownerPkey)
    );
    check("create changelog exists",                          $clog !== null);
    check("source_subsystem = 'micro'",                       $clog && $clog->source_subsystem === 'micro');

    // ------ Sample B: materialType='other' fall-through ------
    echo "\n=== Sample B: materialType='other' falls through to otherMaterialType ===\n";
    $sampleB = (object)array(
        'id'                    => $strabo_id_b,
        'sampleID'              => 'B-label',
        'materialType'          => 'other',
        'otherMaterialType'     => 'lunar regolith',
    );
    $ret = micro_sample_sync(
        $db, $sampleB, $projectStraboId, $projectInternalId, $ownerPkey, $datasetInternalId, 0
    );
    check("sync returns strabo_id_b",                         $ret === $strabo_id_b);
    $row = $db->get_row_prepared(
        "SELECT display_sample_type, micro_data::text AS md
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($strabo_id_b, $ownerPkey)
    );
    check("display_sample_type = 'lunar regolith'",           $row && $row->display_sample_type === 'lunar regolith');
    $md = $row && $row->md ? json_decode($row->md, true) : null;
    check("micro_data still preserves materialtype='other'",  isset($md['materialtype']) && $md['materialtype'] === 'other');
    check("micro_data preserves othermaterialtype='lunar regolith'", isset($md['othermaterialtype']) && $md['othermaterialtype'] === 'lunar regolith');

    // ------ Idempotency ------
    echo "\n=== idempotent re-sync of sample A ===\n";
    micro_sample_sync($db, $sampleA, $projectStraboId, $projectInternalId, $ownerPkey, $datasetInternalId, 3);
    $samCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($strabo_id_a, $ownerPkey)
    );
    check("only 1 strabosamples row after re-sync",           $samCount === 1);
    $linkCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2",
        array($strabo_id_a, $ownerPkey)
    );
    check("only 1 link row after re-sync",                    $linkCount === 1);
    $collabCount = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3",
        array($strabo_id_a, $ownerPkey, $collaboratorPkey)
    );
    check("collab grant still 1 (no re-seed)",                $collabCount === 1);

    // ------ Remove path ------
    echo "\n=== micro_sample_sync_remove_project ===\n";
    $removed = micro_sample_sync_remove_project($db, $projectStraboId, $ownerPkey);
    check("remove helper attempted 2 strabo_ids",             $removed === 2);
    $remaining = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples
          WHERE id IN ($1, $2) AND userpkey=$3",
        array($strabo_id_a, $strabo_id_b, $ownerPkey)
    );
    check("strabosamples rows for both samples removed",      $remaining === 0);

} finally {
    // Cleanup: defensive even if any DELETE fired earlier already cleared things.
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id IN ($1, $2) AND userpkey=$3",
        array($strabo_id_a, $strabo_id_b, $ownerPkey)
    );
    $db->prepare_query("DELETE FROM micro_samplemetadata WHERE id IN ($1, $2)",
        array($sampleInternalA, $sampleInternalB));
    $db->prepare_query("DELETE FROM micro_datasetmetadata WHERE id=$1", array($datasetInternalId));
    $db->prepare_query("DELETE FROM micro_projectmetadata WHERE id=$1", array($projectInternalId));
    $db->prepare_query("DELETE FROM collaborators WHERE strabo_project_id=$1 AND project_owner_user_pkey=$2",
        array($projectStraboId, $ownerPkey));
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
