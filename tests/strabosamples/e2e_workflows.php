<?php
/**
 * File: tests/strabosamples/e2e_workflows.php
 * Description: Phase B of the pre-cutover test plan — end-to-end workflow
 *              regression. Drives the real REST APIs the production clients
 *              hit, not direct service calls, and asserts the strabosamples
 *              mirror + Neo4j / micro / exp state line up at every step.
 *
 *              Workflows covered (one Part per workflow):
 *
 *                B.1 — Field upload via POST /db/projectdatasetsspots
 *                      (HTTP Basic Auth). Mixed rich + legacy fixture.
 *                B.2 — Field re-upload idempotency. Same fixture, second
 *                      upload. Zero new samples, zero new links,
 *                      modified_at bumps, changelog handles no-op vs diff.
 *                B.3 — Micro upload via StraboMicro::loadProjectJSON. The
 *                      HTTP entry is a multipart zip; loadProjectJSON is
 *                      the service-level boundary where the strabosamples
 *                      mirror hook fires. Documented compromise.
 *                B.4 — Experimental save via POST /experimental/api/save_experiment.php
 *                      (session auth).
 *                B.5 — Modal edit → writeback → re-upload. Edit a Field-
 *                      linked sample via /samples_edit.php; verify the
 *                      Neo4j Spot's json_samples picks up the edit; re-
 *                      upload the project; verify the changelog records
 *                      a spine_diff or stays idempotent as appropriate.
 *                B.6 — Hard delete cascades. DELETE /samplesdb/sample/<id>
 *                      (HTTP Basic Auth). Verify CASCADE removal across
 *                      changelog / composition / parameters / documents /
 *                      collaborators / subsystem_links.
 *                B.7 — Spot delete in Neo4j. DELETE /db/feature/<id>.
 *                      Verify the strabosamples row goes away via
 *                      field_sample_sync_remove_spot.
 *
 *              Auth model:
 *                - HTTP Basic: owner@test.strabospot.org / testpass123
 *                  (seeded by tests/collaboration/setup_test_data.php).
 *                - Session: PHP session file forged under
 *                  /var/lib/php/sessions chown'd www-data, 26-hex-char
 *                  SID. Same pattern as the Phase 4 proxy smokes.
 *
 *              IDs use a `96666666` bigint prefix so cleanup queries can
 *              target the e2e range and so we never collide with the collab
 *              tests' `9999999900+` / `8888888800+` / `7777777700+` ranges
 *              or with real prod data.
 *
 *              Hermetic: seeds + tears down in finally{}. Cleanup also
 *              runs at the START so a previous crashed run doesn't leak.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_workflows.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/microdb/strabomicroclass.php';

if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

// -------------------------------------------------------------------------
// Test framework
// -------------------------------------------------------------------------

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

// -------------------------------------------------------------------------
// Test users — provisioned by tests/collaboration/setup_test_data.php
// -------------------------------------------------------------------------

$ownerEmail    = 'owner@test.strabospot.org';
$ownerPassword = 'testpass123';
$ownerPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE",
    array($ownerEmail)
);
if (!$ownerPkey) {
    echo "Test user $ownerEmail not found. Run tests/collaboration/setup_test_data.php first.\n";
    exit(1);
}
echo "owner=$ownerPkey ($ownerEmail)\n\n";

// -------------------------------------------------------------------------
// ID scheme: 96666666 prefix + stamp-derived suffix
// -------------------------------------------------------------------------

$stamp = time();
$projectId            = (int)("96666666" . substr((string)$stamp, -3) . "1");  // e.g. 966666661171
$datasetId            = (int)("96666666" . substr((string)$stamp, -3) . "2");
$spotRichId           = (int)("96666666" . substr((string)$stamp, -3) . "3");
$spotLegacyHolderId   = (int)("96666666" . substr((string)$stamp, -3) . "4");
$legacySampleIdStr    = (string)("96666666" . substr((string)$stamp, -3) . "5");
$standaloneSampleId   = "e2e-standalone-" . $stamp;
$microProjectStraboId = "e2e-micro-" . $stamp;
$expSampleId          = "e2e-exp-" . $stamp;

// -------------------------------------------------------------------------
// HTTP wrappers
// -------------------------------------------------------------------------

/**
 * POST/PUT/DELETE JSON via HTTP Basic Auth. Returns ['status'=>int,
 * 'body'=>string, 'json'=>mixed].
 */
function httpBasic($method, $path, $jsonBody, $email, $password) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2e_body_');
    $args = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    $args .= "-u " . escapeshellarg("$email:$password") . " ";
    $args .= "-X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) {
        $args .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
        $args .= "-d " . escapeshellarg($jsonBody) . " ";
    }
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

/**
 * POST JSON via a forged PHP session. Returns same shape as httpBasic.
 */
function httpSession($method, $path, $jsonBody, $sid) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2e_body_');
    $args = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    if ($sid !== null) {
        $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    }
    $args .= "-X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) {
        $args .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
        $args .= "-d " . escapeshellarg($jsonBody) . " ";
    }
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

function forgeSession($pkey) {
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = '/var/lib/php/sessions/sess_' . $sid;
    $body = 'loggedin|s:3:"yes";userpkey|i:' . $pkey . ';LAST_ACTIVITY|i:' . time() . ';';
    file_put_contents($path, $body);
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    return array($sid, $path);
}

// -------------------------------------------------------------------------
// Pre-flight cleanup: blow away anything left from a prior crashed run
// -------------------------------------------------------------------------

function e2eCleanup($db, $neodb, $ownerPkey, $projectId, $datasetId,
                    $spotRichId, $spotLegacyHolderId, $legacySampleIdStr,
                    $standaloneSampleId, $microProjectStraboId, $expSampleId) {
    // strabosamples — order matters because of CASCADE but FKs handle it.
    // Just nuke samples; CASCADE removes children.
    $stale = array(
        (string)$spotRichId,
        $legacySampleIdStr,
        $standaloneSampleId,
        $expSampleId,
        "e2e-micro-sample-" . substr((string)$microProjectStraboId, strlen('e2e-micro-')),
    );
    foreach ($stale as $id) {
        $db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($id, $ownerPkey)
        );
    }
    // Neo4j: remove the project + dataset + spots (DETACH DELETE removes
    // relationships too).
    $neodb->query("MATCH (s:Spot) WHERE s.id IN [$spotRichId, $spotLegacyHolderId] DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset {id:$datasetId}) DETACH DELETE d");
    $neodb->query("MATCH (p:Project {id:$projectId}) DETACH DELETE p");
    // Micro: remove the project + cascade child tables (smoke-test pattern).
    $microInternalId = $db->get_var_prepared(
        "SELECT id FROM strabomicro.micro_projectmetadata WHERE strabo_id=$1 AND userpkey=$2",
        array($microProjectStraboId, $ownerPkey)
    );
    if ($microInternalId) {
        $db->prepare_query(
            "DELETE FROM strabomicro.micro_samplemetadata WHERE dataset_id IN
                (SELECT id FROM strabomicro.micro_datasetmetadata WHERE project_id=$1)",
            array($microInternalId));
        $db->prepare_query(
            "DELETE FROM strabomicro.micro_datasetmetadata WHERE project_id=$1",
            array($microInternalId));
        $db->prepare_query(
            "DELETE FROM strabomicro.micro_projectmetadata WHERE id=$1",
            array($microInternalId));
        // Best-effort on-disk dir cleanup.
        $dir = "/srv/app/www/straboMicroFiles/$microInternalId";
        if (is_dir($dir)) exec("rm -rf " . escapeshellarg($dir));
    }
    // Experimental: nuke any straboexp.sample row keyed by name+userpkey
    // (the minted strabo_id varies per run). Plus tear down the test
    // project we provisioned (CASCADE drops experiments + samples).
    $db->prepare_query(
        "DELETE FROM straboexp.sample WHERE userpkey=$1 AND name LIKE 'B.4 Exp Sample%'",
        array($ownerPkey));
    $db->prepare_query(
        "DELETE FROM straboexp.project WHERE userpkey=$1 AND name LIKE 'B.4 e2e exp project%'",
        array($ownerPkey));
    // strabosamples — best-effort sweep of any minted strabo_ids attached
    // to deleted exp samples. Use a name-based filter since the id is
    // randomly minted.
    $db->prepare_query(
        "DELETE FROM strabosamples.samples
          WHERE userpkey=$1 AND name='B.4 Exp Sample' AND experimental_data IS NOT NULL",
        array($ownerPkey));
    // Sync hooks index the app-endpoint fixtures; the raw source deletes
    // above never unindex (orphaned globe markers, 2026-08-23). Field by
    // exact project id, micro by prefix, exp by fixture name (random uuid).
    $db->prepare_query(
        "DELETE FROM strabosearch.item_hit WHERE project_id=$1 AND project_userpkey=$2",
        array((string)$projectId, $ownerPkey));
    $db->prepare_query(
        "DELETE FROM strabosearch.image_hit WHERE project_id=$1 AND project_userpkey=$2",
        array((string)$projectId, $ownerPkey));
    $db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_id LIKE 'e2e-micro-%'", array());
    $db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_id LIKE 'e2e-micro-%'", array());
    $db->prepare_query(
        "DELETE FROM strabosearch.item_hit WHERE project_name LIKE 'B.4 e2e exp project%' AND project_userpkey=$1",
        array($ownerPkey));
}

e2eCleanup($db, $neodb, $ownerPkey, $projectId, $datasetId, $spotRichId,
           $spotLegacyHolderId, $legacySampleIdStr, $standaloneSampleId,
           $microProjectStraboId, $expSampleId);

try {

    // -------------------------------------------------------------------
    // PART B.1: Field upload via POST /db/projectdatasetsspots
    // -------------------------------------------------------------------
    echo "=== B.1: Field upload (POST /db/projectdatasetsspots) ===\n";

    $richSampleObj = array(
        'id'                    => (string)$spotRichId,
        'sample_id_name'        => 'E2E-Rich-A',
        'Sample_IGSN'           => 'IGSN-E2E-RA',
        'sample_description'    => 'e2e rich sample description',
        'sample_notes'          => 'e2e rich sample notes',
        'material_type'         => 'intact_rock',
        'main_sampling_purpose' => 'petrology',
    );
    $legacySampleObj = array(
        'id'                    => $legacySampleIdStr,
        'sample_id_name'        => 'E2E-Legacy-A',
        'sample_description'    => 'e2e legacy sample description',
        'material_type'         => 'sediment',
        'main_sampling_purpose' => 'geochronology',
    );
    $modifiedTs = (int)(microtime(true) * 1000);

    $fixture = array(
        'project' => array(
            'id'                            => $projectId,
            'description'                   => array('project_name' => 'E2E Test Project'),
            'modified_timestamp'            => $modifiedTs,
            'datasets'                      => array(
                array(
                    'id'                  => $datasetId,
                    'name'                => 'E2E Test Dataset',
                    'modified_timestamp'  => $modifiedTs,
                    'spots'               => array(
                        'features' => array(
                            // Rich sample-spot
                            array(
                                'type'       => 'Feature',
                                'geometry'   => array(
                                    'type'        => 'Point',
                                    'coordinates' => array(-120.5, 45.5),
                                ),
                                'properties' => array(
                                    'id'                 => $spotRichId,
                                    'name'               => 'E2E Rich Spot',
                                    'isSample'           => 1,
                                    'modified_timestamp' => $modifiedTs,
                                    'samples'            => array($richSampleObj),
                                ),
                            ),
                            // Legacy holder spot with inline samples[]
                            array(
                                'type'       => 'Feature',
                                'geometry'   => array(
                                    'type'        => 'Point',
                                    'coordinates' => array(-118.25, 34.05),
                                ),
                                'properties' => array(
                                    'id'                 => $spotLegacyHolderId,
                                    'name'               => 'E2E Legacy Holder',
                                    'modified_timestamp' => $modifiedTs,
                                    'samples'            => array($legacySampleObj),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );

    // The bulk controller's auth check requires the Project to already
    // exist in Neo4j (`canRead()` does a MATCH on (Project) — see
    // CollaborationAuth::getProjectContext). The real Field client gets
    // there by uploading the project once (POST /db/project) before its
    // first bulk-spots upload. We pre-seed here to keep the test scope
    // tight to the bulk-upload path (B.1 is about strabosamples mirroring
    // off the bulk path, not about exercising project creation).
    $neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, name:'E2E Test Project'})");

    $r = httpBasic('POST', '/db/projectdatasetsspots', json_encode($fixture), $ownerEmail, $ownerPassword);
    // /db/projectdatasetsspots returns 201 Created on success.
    check("B.1 upload returned HTTP 2xx",                                  $r['status'] >= 200 && $r['status'] < 300);

    // Neo4j assertions: project + dataset + both spots present, rich spot
    // has wkt + json_samples set.
    $projCount = (int)$neodb->get_var("MATCH (p:Project {id:$projectId, userpkey:$ownerPkey}) RETURN count(p) AS c");
    check("B.1 Neo4j: project node present",                               $projCount === 1);
    $dsCount = (int)$neodb->get_var("MATCH (d:Dataset {id:$datasetId, userpkey:$ownerPkey}) RETURN count(d) AS c");
    check("B.1 Neo4j: dataset node created",                               $dsCount === 1);
    $richWkt        = (string)$neodb->get_var("MATCH (s:Spot {id:$spotRichId, userpkey:$ownerPkey}) RETURN s.wkt");
    $richJsonSamps  = (string)$neodb->get_var("MATCH (s:Spot {id:$spotRichId, userpkey:$ownerPkey}) RETURN s.json_samples");
    $richIsSample   = (string)$neodb->get_var("MATCH (s:Spot {id:$spotRichId, userpkey:$ownerPkey}) RETURN s.isSample");
    check("B.1 Neo4j: rich spot has wkt",                                  $richWkt !== '');
    check("B.1 Neo4j: rich spot wkt looks like POINT(-120.5 45.5)",        strpos($richWkt, '-120.5') !== false && strpos($richWkt, '45.5') !== false);
    check("B.1 Neo4j: rich spot isSample=1",                               (int)$richIsSample === 1);
    check("B.1 Neo4j: rich spot json_samples is set",                      $richJsonSamps !== '');
    $jsDecoded = json_decode($richJsonSamps, true);
    check("B.1 Neo4j: rich spot json_samples decodes to an array of 1",    is_array($jsDecoded) && count($jsDecoded) === 1);
    check("B.1 Neo4j: rich json_samples sample_id_name preserved",         is_array($jsDecoded) && isset($jsDecoded[0]['sample_id_name']) && $jsDecoded[0]['sample_id_name'] === 'E2E-Rich-A');

    // strabosamples assertions — rich
    $richRow = $db->get_row_prepared(
        "SELECT name, igsn, description, notes, display_sample_type, display_sample_purpose,
                latitude, longitude, field_data::text AS fd
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$spotRichId, $ownerPkey)
    );
    check("B.1 strabosamples: rich row created",                           $richRow !== null);
    check("B.1 strabosamples: rich name='E2E-Rich-A'",                     $richRow && $richRow->name === 'E2E-Rich-A');
    check("B.1 strabosamples: rich igsn from Sample_IGSN",                 $richRow && $richRow->igsn === 'IGSN-E2E-RA');
    check("B.1 strabosamples: rich display_sample_type='intact_rock'",     $richRow && $richRow->display_sample_type === 'intact_rock');
    check("B.1 strabosamples: rich display_sample_purpose='petrology'",    $richRow && $richRow->display_sample_purpose === 'petrology');
    check("B.1 strabosamples: rich latitude from wkt",                     $richRow && abs((float)$richRow->latitude - 45.5) < 0.0001);
    check("B.1 strabosamples: rich longitude from wkt",                    $richRow && abs((float)$richRow->longitude - (-120.5)) < 0.0001);

    // strabosamples assertions — legacy
    $legRow = $db->get_row_prepared(
        "SELECT name, description, display_sample_type, display_sample_purpose
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($legacySampleIdStr, $ownerPkey)
    );
    check("B.1 strabosamples: legacy row created",                         $legRow !== null);
    check("B.1 strabosamples: legacy name='E2E-Legacy-A'",                 $legRow && $legRow->name === 'E2E-Legacy-A');
    check("B.1 strabosamples: legacy display_sample_type='sediment'",      $legRow && $legRow->display_sample_type === 'sediment');

    // Link reference_id — rich = sample-spot id; legacy = holding-spot id
    $richLink = $db->get_row_prepared(
        "SELECT reference_id, reference_userpkey, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
        array((string)$spotRichId, $ownerPkey)
    );
    check("B.1 link: rich link exists",                                    $richLink !== null);
    check("B.1 link: rich reference_id == sample-spot id",                 $richLink && (string)$richLink->reference_id === (string)$spotRichId);
    $richMeta = $richLink && $richLink->rm ? json_decode($richLink->rm, true) : null;
    check("B.1 link: rich reference_metadata.rich = true",                 is_array($richMeta) && !empty($richMeta['rich']));
    check("B.1 link: rich reference_metadata.project_id matches",          is_array($richMeta) && isset($richMeta['project_id']) && (int)$richMeta['project_id'] === $projectId);
    check("B.1 link: rich reference_metadata.dataset_id matches",          is_array($richMeta) && isset($richMeta['dataset_id']) && (int)$richMeta['dataset_id'] === $datasetId);

    $legLink = $db->get_row_prepared(
        "SELECT reference_id, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
        array($legacySampleIdStr, $ownerPkey)
    );
    check("B.1 link: legacy link exists",                                  $legLink !== null);
    check("B.1 link: legacy reference_id == holding-spot id",              $legLink && (string)$legLink->reference_id === (string)$spotLegacyHolderId);
    $legMeta = $legLink && $legLink->rm ? json_decode($legLink->rm, true) : null;
    check("B.1 link: legacy reference_metadata.rich = false",              is_array($legMeta) && empty($legMeta['rich']));

    // Changelog: both samples got a 'create' row with source_subsystem='FIELD'
    $richCreate = $db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
            AND change_type='create' AND source_subsystem='field'",
        array((string)$spotRichId, $ownerPkey)
    );
    check("B.1 changelog: rich got a 'create' row sourced field",          (int)$richCreate === 1);
    $legCreate = $db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
            AND change_type='create' AND source_subsystem='field'",
        array($legacySampleIdStr, $ownerPkey)
    );
    check("B.1 changelog: legacy got a 'create' row sourced field",        (int)$legCreate === 1);

    // -------------------------------------------------------------------
    // PART B.2: Field re-upload idempotency
    // -------------------------------------------------------------------
    echo "\n=== B.2: Field re-upload idempotency ===\n";

    // Snapshot pre-re-upload state.
    $samplesBefore = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples
          WHERE id IN ($1, $2) AND userpkey=$3",
        array((string)$spotRichId, $legacySampleIdStr, $ownerPkey)
    );
    $linksBefore = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id IN ($1, $2) AND sample_userpkey=$3 AND subsystem='field'",
        array((string)$spotRichId, $legacySampleIdStr, $ownerPkey)
    );
    $changelogBefore = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog
          WHERE sample_id IN ($1, $2) AND sample_userpkey=$3",
        array((string)$spotRichId, $legacySampleIdStr, $ownerPkey)
    );
    $modifiedAtBefore = $db->get_var_prepared(
        "SELECT modified_at::text FROM strabosamples.samples
          WHERE id=$1 AND userpkey=$2",
        array((string)$spotRichId, $ownerPkey)
    );

    // Bump the modified_timestamp on the fixture so the upload's update
    // path runs (the dataset gate compares incoming vs server timestamp).
    $fixture['project']['modified_timestamp']                              = $modifiedTs + 1000;
    $fixture['project']['datasets'][0]['modified_timestamp']               = $modifiedTs + 1000;
    foreach ($fixture['project']['datasets'][0]['spots']['features'] as &$f) {
        $f['properties']['modified_timestamp'] = $modifiedTs + 1000;
    }
    unset($f);
    // Mutate the rich sample's description so an UPDATE-with-diff is forced.
    $fixture['project']['datasets'][0]['spots']['features'][0]['properties']['samples'][0]['sample_description'] = 'e2e rich edited via re-upload';

    sleep(1);  // make modified_at gap measurable

    $r = httpBasic('POST', '/db/projectdatasetsspots', json_encode($fixture), $ownerEmail, $ownerPassword);
    check("B.2 re-upload returned HTTP 2xx",                               $r['status'] >= 200 && $r['status'] < 300);

    $samplesAfter = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples
          WHERE id IN ($1, $2) AND userpkey=$3",
        array((string)$spotRichId, $legacySampleIdStr, $ownerPkey)
    );
    check("B.2 zero new strabosamples rows",                               $samplesAfter === $samplesBefore);
    $linksAfter = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id IN ($1, $2) AND sample_userpkey=$3 AND subsystem='field'",
        array((string)$spotRichId, $legacySampleIdStr, $ownerPkey)
    );
    check("B.2 zero new link rows",                                        $linksAfter === $linksBefore);

    $modifiedAtAfter = $db->get_var_prepared(
        "SELECT modified_at::text FROM strabosamples.samples
          WHERE id=$1 AND userpkey=$2",
        array((string)$spotRichId, $ownerPkey)
    );
    check("B.2 modified_at bumped on rich sample",                         $modifiedAtAfter !== null && $modifiedAtAfter > $modifiedAtBefore);

    // Rich row's description should now reflect the edit.
    $richDesc = $db->get_var_prepared(
        "SELECT description FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$spotRichId, $ownerPkey)
    );
    check("B.2 rich description carries the re-upload edit",               $richDesc === 'e2e rich edited via re-upload');

    // Changelog should have grown — at least one 'update' row appears.
    $changelogAfter = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog
          WHERE sample_id IN ($1, $2) AND sample_userpkey=$3",
        array((string)$spotRichId, $legacySampleIdStr, $ownerPkey)
    );
    check("B.2 changelog grew on re-upload",                               $changelogAfter > $changelogBefore);
    $richUpdate = $db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
            AND change_type='update' AND source_subsystem='field'",
        array((string)$spotRichId, $ownerPkey)
    );
    check("B.2 rich got a field 'update' changelog row",                   (int)$richUpdate >= 1);

    // -------------------------------------------------------------------
    // PART B.5: Modal edit → writeback → re-upload
    //
    // Drive /samples_edit.php with a session-authed PUT, verify the Neo4j
    // Spot's json_samples picks up the edit (writeback fired), then re-
    // upload the project and verify the upload-side spine wins (last-
    // writer-wins per §10.4) + a writeback_translation entry exists in
    // the changelog if any value was dropped by the translator.
    // -------------------------------------------------------------------
    echo "\n=== B.5: Modal edit → writeback → re-upload ===\n";

    list($ownerSid, $ownerSessionFile) = forgeSession($ownerPkey);

    // Edit the legacy sample's description + material type via the modal
    // proxy (legacy edit is the cleaner test because rich-sample edits
    // also trigger a Neo4j Spot scalar bump for spot.name which adds
    // noise; the legacy path only touches samples[i] within json_samples).
    $editBody = json_encode(array(
        'sample_id'          => $legacySampleIdStr,
        'owner_pkey'         => $ownerPkey,
        'description'        => 'edited via modal proxy',
        'display_sample_type'=> 'tephra',  // canonical vocab — translator passes through
    ));
    $r = httpSession('POST', '/samples_edit.php', $editBody, $ownerSid);
    check("B.5 modal edit: HTTP 2xx",                                      $r['status'] >= 200 && $r['status'] < 300);
    check("B.5 modal edit: json.ok = true",                                 isset($r['json']['ok']) && $r['json']['ok'] === true);

    // strabosamples row reflects the edit.
    $editedSpine = $db->get_row_prepared(
        "SELECT description, display_sample_type FROM strabosamples.samples
          WHERE id=$1 AND userpkey=$2",
        array($legacySampleIdStr, $ownerPkey)
    );
    check("B.5 strabosamples: description edited",                          $editedSpine && $editedSpine->description === 'edited via modal proxy');
    check("B.5 strabosamples: display_sample_type edited",                  $editedSpine && $editedSpine->display_sample_type === 'tephra');

    // Writeback should have updated the holding spot's json_samples for
    // the legacy sample. samples[i] whose 'id' matches legacySampleIdStr
    // should now carry sample_description='edited via modal proxy'.
    $holderJs = (string)$neodb->get_var(
        "MATCH (s:Spot {id:$spotLegacyHolderId, userpkey:$ownerPkey}) RETURN s.json_samples"
    );
    $holderArr = json_decode($holderJs, true);
    $found = false;
    if (is_array($holderArr)) {
        foreach ($holderArr as $samp) {
            if (isset($samp['id']) && (string)$samp['id'] === $legacySampleIdStr) {
                $found = isset($samp['sample_description']) && $samp['sample_description'] === 'edited via modal proxy';
                $foundType = isset($samp['material_type']) && $samp['material_type'] === 'tephra';
                break;
            }
        }
    }
    check("B.5 Neo4j writeback: legacy sample description in json_samples", $found);
    check("B.5 Neo4j writeback: legacy sample material_type in json_samples", isset($foundType) && $foundType);

    // Re-upload — upload-side state should win (last-writer-wins). Roll
    // the description forward via the fixture and POST again.
    // The re-upload's timestamp must beat the spot's CURRENT stored
    // modified_timestamp — which the B.5 writeback just bumped to real
    // now() — or insertSpot's anti-clobber gate (strabospotclass ~:905)
    // silently skips the update AND the sample-sync hook. The original
    // "$modifiedTs + 2000" anchored to fixture-build time, so the check
    // raced suite runtime against a 2s margin: it passed only when the
    // suite reached B.5 in under 2 seconds, and started losing reliably
    // once the StraboSearch live-sync hooks added per-upload work. Use a
    // fresh now()+margin instead — the semantics under test ("a genuinely
    // newer client upload wins, last-writer-wins per §10.4") are unchanged.
    $reuploadTs = (int)(microtime(true) * 1000) + 60000;
    $fixture['project']['modified_timestamp']                          = $reuploadTs;
    $fixture['project']['datasets'][0]['modified_timestamp']           = $reuploadTs;
    foreach ($fixture['project']['datasets'][0]['spots']['features'] as &$f) {
        $f['properties']['modified_timestamp'] = $reuploadTs;
    }
    unset($f);
    $fixture['project']['datasets'][0]['spots']['features'][1]['properties']['samples'][0]['sample_description']
        = 'overwritten by re-upload';
    $r = httpBasic('POST', '/db/projectdatasetsspots', json_encode($fixture), $ownerEmail, $ownerPassword);
    check("B.5 re-upload: HTTP 2xx",                                       $r['status'] >= 200 && $r['status'] < 300);

    $postUploadSpine = $db->get_row_prepared(
        "SELECT description, display_sample_type FROM strabosamples.samples
          WHERE id=$1 AND userpkey=$2",
        array($legacySampleIdStr, $ownerPkey)
    );
    check("B.5 post-re-upload: description = upload-side state",            $postUploadSpine && $postUploadSpine->description === 'overwritten by re-upload');

    @unlink($ownerSessionFile);

    // -------------------------------------------------------------------
    // PART B.3: Micro upload via StraboMicro::loadProjectJSON
    //
    // The HTTP entry (POST /microdb/uploadMicroProject) is a multipart-zip
    // that extracts then calls loadProjectJSON. The strabosamples sync
    // hook fires from inside loadProjectJSON, so driving the service
    // boundary directly gives the same coverage of the sync path with no
    // zip-handling test infrastructure. Documented compromise.
    // -------------------------------------------------------------------
    echo "\n=== B.3: Micro upload via loadProjectJSON ===\n";

    $microSampleStraboId = "e2e-micro-sample-" . $stamp;
    $microSampleObj = (object)array(
        'id'                  => $microSampleStraboId,
        'sampleID'            => 'B.3-Micro-Sample',
        'label'               => 'B.3-Label',
        'latitude'            => 36.5,
        'longitude'           => -115.5,
        'materialType'        => 'intact_rock',
        'mainSamplingPurpose' => 'petrology',
        'sampleDescription'   => 'micro upload e2e description',
        'sampleNotes'         => 'micro upload e2e notes',
    );
    $microDatasetStraboId = "e2e-micro-ds-" . $stamp;
    $microProjectJson = json_encode(array(
        'id'        => $microProjectStraboId,
        'name'      => 'B.3 Micro Project',
        'startDate' => '2026-01-01',
        'datasets'  => array(
            array(
                'id'      => $microDatasetStraboId,
                'name'    => 'B.3 Micro Dataset',
                'samples' => array($microSampleObj),
            ),
        ),
    ));
    $microProjectInternalId = (int)$db->get_var("SELECT nextval('strabomicro.micro_projectmetadata_id_seq')");
    @mkdir("/srv/app/www/straboMicroFiles/$microProjectInternalId", 0755, true);

    $uuid_gen = new UUID();
    $sm = new StraboMicro($neodb, $ownerPkey, $db);
    $sm->setuuid($uuid_gen);
    $sm->loadProjectJSON($microProjectJson, $microProjectInternalId, '');

    // Track the strabosamples sample id for cleanup. micro_sample_sync
    // upserts on (strabo_id, userpkey) — for this fixture the id is the
    // sample's strabo_id we passed in.
    $cleanupSamples[] = $microSampleStraboId;  // tracked via closure
    $microRow = $db->get_row_prepared(
        "SELECT name, description, notes, display_sample_type, display_sample_purpose,
                latitude, longitude, micro_data::text AS md
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($microSampleStraboId, $ownerPkey)
    );
    check("B.3 strabosamples: micro row created",                          $microRow !== null);
    check("B.3 strabosamples: micro name from sampleID",                   $microRow && $microRow->name === 'B.3-Micro-Sample');
    check("B.3 strabosamples: micro display_sample_type from materialType", $microRow && $microRow->display_sample_type === 'intact_rock');
    check("B.3 strabosamples: micro display_sample_purpose from mainSamplingPurpose", $microRow && $microRow->display_sample_purpose === 'petrology');
    check("B.3 strabosamples: micro latitude preserved",                   $microRow && abs((float)$microRow->latitude - 36.5) < 0.0001);
    check("B.3 strabosamples: micro longitude preserved",                  $microRow && abs((float)$microRow->longitude - (-115.5)) < 0.0001);
    $md = $microRow && $microRow->md ? json_decode($microRow->md, true) : null;
    check("B.3 strabosamples: micro_data JSONB carries sampleid",          is_array($md) && isset($md['sampleid']) && $md['sampleid'] === 'B.3-Micro-Sample');

    $microLink = $db->get_row_prepared(
        "SELECT reference_id, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='micro'",
        array($microSampleStraboId, $ownerPkey)
    );
    check("B.3 link: micro link exists",                                   $microLink !== null);
    check("B.3 link: micro reference_id = project internal id",            $microLink && (int)$microLink->reference_id === $microProjectInternalId);

    $microCreate = $db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
            AND change_type='create' AND source_subsystem='micro'",
        array($microSampleStraboId, $ownerPkey)
    );
    check("B.3 changelog: micro got a 'create' row sourced micro",         (int)$microCreate === 1);

    // -------------------------------------------------------------------
    // PART B.4: Experimental save via Vue API
    //
    // POST /experimental/api/save_experiment.php with session auth + JSON.
    // Vue API mints the experiment + an UPDATE-or-INSERT against
    // straboexp.sample (1:1 with experiment, minting strabo_id on first
    // appearance), then exp_sample_sync mirrors to strabosamples.
    // -------------------------------------------------------------------
    echo "\n=== B.4: Experimental save via /experimental/api/save_experiment.php ===\n";

    // Pre-seed a straboexp.project the owner owns. Necessary because the
    // endpoint validates project ownership before creating an experiment.
    $expProjectPkey = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
    $expProjectUuid = bin2hex(random_bytes(16));
    $db->prepare_query(
        "INSERT INTO straboexp.project (pkey, userpkey, uuid, name)
         VALUES ($1, $2, $3, $4)",
        array($expProjectPkey, $ownerPkey, $expProjectUuid, 'B.4 e2e exp project')
    );

    list($expSid, $expSessionFile) = forgeSession($ownerPkey);

    $expBody = json_encode(array(
        'project_pkey'  => $expProjectPkey,
        'experiment_id' => 'B.4-exp-' . $stamp,
        'data' => array(
            'experiment' => array('id' => 'B.4-exp-' . $stamp),
            'sample'     => array(
                'id'          => $expSampleId,
                'name'        => 'B.4 Exp Sample',
                'igsn'        => 'IGSN-B4',
                'description' => 'B.4 exp sample description',
                'material' => array(
                    'material' => array(
                        'type'  => 'Igneous Rock',
                        'name'  => 'granite',
                        'state' => 'solid',
                    ),
                    'provenance' => array(
                        'location' => array(
                            'latitude'  => '37.5',
                            'longitude' => '-122.5',
                            'city'      => 'San Francisco',
                        ),
                    ),
                ),
            ),
        ),
    ));
    $r = httpSession('POST', '/experimental/api/save_experiment.php', $expBody, $expSid);
    // Vue API leaks a PHP warning into the response body before the JSON;
    // strip any leading non-JSON prefix before parsing. Pre-existing
    // infrastructure issue, unrelated to Phase B coverage.
    $jsonStart = strpos((string)$r['body'], '{');
    $clean = $jsonStart !== false ? substr($r['body'], $jsonStart) : '';
    $json = $clean === '' ? null : json_decode($clean, true);
    check("B.4 save_experiment: HTTP 200",                                 $r['status'] === 200);
    check("B.4 save_experiment: json.success = true",                       isset($json['success']) && $json['success'] === true);
    check("B.4 save_experiment: response carries strabo_id",                isset($json['strabo_id']) && !empty($json['strabo_id']));

    $mintedStraboId = isset($json['strabo_id']) ? $json['strabo_id'] : null;
    // The strabosamples row's id IS the minted strabo_id.
    if ($mintedStraboId) {
        $expRow = $db->get_row_prepared(
            "SELECT name, igsn, description, display_sample_type,
                    latitude, longitude, experimental_data::text AS ed
               FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($mintedStraboId, $ownerPkey)
        );
        check("B.4 strabosamples: exp row created at minted strabo_id",     $expRow !== null);
        check("B.4 strabosamples: exp name='B.4 Exp Sample'",                $expRow && $expRow->name === 'B.4 Exp Sample');
        check("B.4 strabosamples: exp igsn='IGSN-B4'",                       $expRow && $expRow->igsn === 'IGSN-B4');
        check("B.4 strabosamples: exp latitude preserved",                   $expRow && abs((float)$expRow->latitude - 37.5) < 0.0001);
        check("B.4 strabosamples: exp longitude preserved",                  $expRow && abs((float)$expRow->longitude - (-122.5)) < 0.0001);
        $ed = $expRow && $expRow->ed ? json_decode($expRow->ed, true) : null;
        check("B.4 strabosamples: experimental_data JSONB present",          is_array($ed) && !empty($ed));

        $expLink = $db->get_row_prepared(
            "SELECT reference_id FROM strabosamples.sample_subsystem_links
              WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='experimental'",
            array($mintedStraboId, $ownerPkey)
        );
        check("B.4 link: experimental link exists",                          $expLink !== null);

        $expCreate = $db->get_var_prepared(
            "SELECT COUNT(*) FROM strabosamples.sample_changelog
              WHERE sample_id=$1 AND sample_userpkey=$2
                AND change_type='create' AND source_subsystem='experimental'",
            array($mintedStraboId, $ownerPkey)
        );
        check("B.4 changelog: experimental got a 'create' row",              (int)$expCreate === 1);

        // Track for finally{} cleanup.
        $cleanupMintedStraboId = $mintedStraboId;
    }

    @unlink($expSessionFile);

    // -------------------------------------------------------------------
    // PART B.6: Hard delete cascades
    // -------------------------------------------------------------------
    echo "\n=== B.6: Hard delete cascades (DELETE /samplesdb/sample/<id>) ===\n";

    // Create a fresh api-only sample so we can attach composition without
    // disturbing the B.1/B.2/B.7 fixtures. POST /samplesdb/sample.
    $createBody = json_encode(array(
        'id'                     => $standaloneSampleId,
        'name'                   => 'B.6 standalone',
        'display_sample_type'    => 'intact_rock',
        'display_sample_purpose' => 'petrology',
    ));
    $r = httpBasic('POST', '/samplesdb/sample', $createBody, $ownerEmail, $ownerPassword);
    check("B.6 createSample: HTTP 2xx",                                    $r['status'] >= 200 && $r['status'] < 300);

    // Attach 1 composition + 1 parameter + 1 document row via PUT.
    $compBody = json_encode(array('items' => array(
        array('mineral' => 'quartz', 'fraction' => '0.5', 'unit' => 'volume', 'grainsize' => 'fine')
    )));
    $r = httpBasic('PUT', '/samplesdb/sample/' . urlencode($standaloneSampleId) . '/composition?owner_pkey=' . $ownerPkey,
                   $compBody, $ownerEmail, $ownerPassword);
    check("B.6 PUT composition: HTTP 2xx",                                 $r['status'] >= 200 && $r['status'] < 300);

    $paramBody = json_encode(array('items' => array(
        array('control' => 'temperature', 'value' => '450', 'unit' => 'C')
    )));
    $r = httpBasic('PUT', '/samplesdb/sample/' . urlencode($standaloneSampleId) . '/parameters?owner_pkey=' . $ownerPkey,
                   $paramBody, $ownerEmail, $ownerPassword);
    check("B.6 PUT parameters: HTTP 2xx",                                  $r['status'] >= 200 && $r['status'] < 300);

    $docBody = json_encode(array('items' => array(
        array('uuid' => 'doc-' . $stamp, 'type' => 'analysis', 'description' => 'B.6 doc')
    )));
    $r = httpBasic('PUT', '/samplesdb/sample/' . urlencode($standaloneSampleId) . '/documents?owner_pkey=' . $ownerPkey,
                   $docBody, $ownerEmail, $ownerPassword);
    check("B.6 PUT documents: HTTP 2xx",                                   $r['status'] >= 200 && $r['status'] < 300);

    // Pre-delete state
    $sampleBefore = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $compBefore  = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_composition WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $paramBeforeCount = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_parameters WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $docBeforeCount   = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_documents WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $clogBefore  = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));

    check("B.6 pre-delete: sample row present",                            $sampleBefore === 1);
    check("B.6 pre-delete: composition row present",                       $compBefore === 1);
    check("B.6 pre-delete: parameters row present",                        $paramBeforeCount === 1);
    check("B.6 pre-delete: documents row present",                         $docBeforeCount === 1);
    check("B.6 pre-delete: changelog rows present",                        $clogBefore >= 1);

    // DELETE via /samplesdb/sample/<id>
    $r = httpBasic('DELETE', '/samplesdb/sample/' . urlencode($standaloneSampleId) . '?owner_pkey=' . $ownerPkey,
                   null, $ownerEmail, $ownerPassword);
    check("B.6 DELETE: HTTP 2xx",                                          $r['status'] >= 200 && $r['status'] < 300);

    // Post-delete state — CASCADE removes children.
    $sampleAfter = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $compAfter   = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_composition WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $paramAfter  = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_parameters WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $docAfter    = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_documents WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $clogAfter   = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_changelog WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $collabAfter = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_collaborators WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));
    $linkAfter   = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_subsystem_links WHERE sample_id=$1 AND sample_userpkey=$2",
        array($standaloneSampleId, $ownerPkey));

    check("B.6 post-delete: sample row gone",                              $sampleAfter === 0);
    check("B.6 CASCADE: composition row removed",                          $compAfter === 0);
    check("B.6 CASCADE: parameters row removed",                           $paramAfter === 0);
    check("B.6 CASCADE: documents row removed",                            $docAfter === 0);
    check("B.6 CASCADE: changelog rows removed",                           $clogAfter === 0);
    check("B.6 CASCADE: collaborators rows removed (none seeded — sanity)",$collabAfter === 0);
    check("B.6 CASCADE: subsystem_links rows removed (none seeded — sanity)", $linkAfter === 0);

    // -------------------------------------------------------------------
    // PART B.7: Spot delete in Neo4j drops the strabosamples row
    // -------------------------------------------------------------------
    echo "\n=== B.7: Spot delete (DELETE /db/feature/<id>) ===\n";

    // The B.1 rich spot is still in Neo4j + has a strabosamples row.
    // Sanity-check pre-delete.
    $preDeleteSample = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$spotRichId, $ownerPkey));
    check("B.7 pre-delete: rich strabosamples row exists",                 $preDeleteSample === 1);
    $preDeleteSpot = (int)$neodb->get_var(
        "MATCH (s:Spot {id:$spotRichId, userpkey:$ownerPkey}) RETURN count(s)");
    check("B.7 pre-delete: rich Neo4j Spot exists",                        $preDeleteSpot === 1);

    $r = httpBasic('DELETE', '/db/feature/' . $spotRichId, null, $ownerEmail, $ownerPassword);
    check("B.7 DELETE: HTTP 2xx",                                          $r['status'] >= 200 && $r['status'] < 300);

    $postDeleteSpot = (int)$neodb->get_var(
        "MATCH (s:Spot {id:$spotRichId, userpkey:$ownerPkey}) RETURN count(s)");
    check("B.7 Neo4j: rich Spot gone",                                     $postDeleteSpot === 0);
    $postDeleteSample = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$spotRichId, $ownerPkey));
    check("B.7 strabosamples: rich row removed via sample_sync_remove_spot", $postDeleteSample === 0);
    $postDeleteLinks = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.sample_subsystem_links WHERE sample_id=$1 AND sample_userpkey=$2",
        array((string)$spotRichId, $ownerPkey));
    check("B.7 strabosamples: rich link removed via CASCADE",              $postDeleteLinks === 0);

} finally {
    e2eCleanup($db, $neodb, $ownerPkey, $projectId, $datasetId, $spotRichId,
               $spotLegacyHolderId, $legacySampleIdStr, $standaloneSampleId,
               $microProjectStraboId, $expSampleId);
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
