<?php
/**
 * File: smoke_test_api_core_writes.php
 * Description: Service-layer smoke test for samples/api-core-writes. Exercises
 *              createSample / updateSample / deleteSample including the
 *              §6.1 Field-link lat/lng read-only guard, parent validation,
 *              permission gates, and the strabosamples.sample_changelog
 *              writes per CRUD action.
 *
 *              Hermetic: seeds samples under unique time-stamped sentinel
 *              ids, with manual INSERTs into sample_collaborators (accepted
 *              edit + accepted readonly) so the permission gates can be
 *              exercised without going through the invite/accept flow.
 *              Cleans up via DELETE in a finally block — CASCADE wipes
 *              collaborators / links / changelog along with the sample.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_api_core_writes.php
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
    if (!$cond) {
        $failures[] = $label;
    }
}

// Three test users: owner (creates), editor (accepted edit collab), reader (accepted readonly).
$users = $db->get_results_prepared(
    "SELECT pkey, email FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 3", array()
);
if (!is_array($users) || count($users) < 3) {
    echo "Cannot find 3 distinct test users.\n";
    exit(1);
}
$ownerPkey  = (int)$users[0]->pkey;
$editorPkey = (int)$users[1]->pkey;
$readerPkey = (int)$users[2]->pkey;
echo "owner=$ownerPkey  editor=$editorPkey  reader=$readerPkey\n\n";

$stamp = time();
$ids = array();  // Track created sample ids for cleanup.

$svc = new StraboSamplesService($db, $neodb);

try {

    echo "=== createSample (owner, auto-minted id) ===\n";
    $svc->setUserpkey($ownerPkey);
    $r = $svc->createSample(array(
        'name'                => 'smoke writes 1',
        'description'         => 'initial description',
        'display_sample_type' => 'rock',
    ));
    check("create returns ok",                            !empty($r['ok']));
    check("create returns the assembled sample",          isset($r['sample']) && is_array($r['sample']));
    if (!empty($r['sample'])) {
        $auto = $r['sample'];
        $ids[] = array($auto['id'], $ownerPkey);
        check("minted id is a non-empty string",          is_string($auto['id']) && $auto['id'] !== '');
        check("userpkey = caller",                        (int)$auto['userpkey'] === $ownerPkey);
        check("name persisted",                           $auto['name'] === 'smoke writes 1');
        check("display_sample_type persisted",            $auto['display_sample_type'] === 'rock');
    }

    echo "\n=== changelog row appended on create ===\n";
    $clog = $db->get_row_prepared(
        "SELECT change_type, source_subsystem, changes
           FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
          ORDER BY pkey DESC LIMIT 1",
        array($auto['id'], $ownerPkey)
    );
    check("changelog row exists",                         $clog !== null);
    check("change_type = 'create'",                       $clog && $clog->change_type === 'create');
    check("source_subsystem = 'samples_api'",             $clog && $clog->source_subsystem === 'samples_api');

    echo "\n=== createSample (supplied id, JSONB) ===\n";
    $suppliedId = "smoketest-writes-supplied-$stamp";
    $r = $svc->createSample(array(
        'id'                  => $suppliedId,
        'name'                => 'with supplied id',
        'igsn'                => 'IGSN-' . $stamp,
        'micro_data'          => array('foo' => 'bar', 'n' => 7),
    ));
    check("supplied-id create returns ok",                !empty($r['ok']));
    if (!empty($r['ok'])) $ids[] = array($suppliedId, $ownerPkey);
    check("created sample has the supplied id",           !empty($r['sample']) && $r['sample']['id'] === $suppliedId);
    check("micro_data persisted (round-tripped)",         !empty($r['sample']) && is_array($r['sample']['micro_data']) && $r['sample']['micro_data']['foo'] === 'bar');

    echo "\n=== duplicate id rejected ===\n";
    $r = $svc->createSample(array('id' => $suppliedId, 'name' => 'dup attempt'));
    check("returns 'duplicate_id'",                       empty($r['ok']) && $r['error'] === 'duplicate_id');

    echo "\n=== parent validation ===\n";
    $r = $svc->createSample(array('name' => 'parent half pair', 'parent_sample_id' => 'x'));
    check("partial parent rejected",                      empty($r['ok']) && $r['error'] === 'parent_pair_required');
    $r = $svc->createSample(array('name' => 'bad parent', 'parent_sample_id' => 'nope-' . $stamp, 'parent_userpkey' => $ownerPkey));
    check("inaccessible parent rejected",                 empty($r['ok']) && $r['error'] === 'parent_not_accessible');
    $r = $svc->createSample(array('name' => 'child of supplied', 'parent_sample_id' => $suppliedId, 'parent_userpkey' => $ownerPkey));
    check("valid parent accepted",                        !empty($r['ok']));
    if (!empty($r['ok'])) $ids[] = array($r['sample']['id'], $ownerPkey);

    echo "\n=== JSONB input validation (invalid never reaches SQL) ===\n";
    // Pre-fix, a non-JSON string flowed verbatim into the INSERT and aborted
    // it inside PostgreSQL — surfaced as ok:true with NO row written.
    $badId = "smoketest-writes-badjson-$stamp";
    $r = $svc->createSample(array('id' => $badId, 'name' => 'bad json', 'custom_data' => 'not json at all'));
    check("create with non-JSON string rejected 'invalid_json_field'",
          empty($r['ok']) && $r['error'] === 'invalid_json_field');
    check("error detail names the field",
          isset($r['detail']['field']) && $r['detail']['field'] === 'custom_data');
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($badId, $ownerPkey));
    check("no row written for rejected create",           $n === 0);
    $r = $svc->createSample(array('id' => $badId, 'name' => 'scalar json', 'custom_data' => 42));
    check("create with scalar custom_data rejected",      empty($r['ok']) && $r['error'] === 'invalid_json_field');
    $r = $svc->createSample(array('id' => $badId, 'name' => 'pre-encoded', 'custom_data' => '{"depth_m": 4}'));
    check("pre-encoded JSON-object string still accepted", !empty($r['ok']));
    check("pre-encoded string round-trips decoded",
          !empty($r['sample']) && is_array($r['sample']['custom_data'])
          && $r['sample']['custom_data']['depth_m'] === 4);
    if (!empty($r['ok'])) $ids[] = array($badId, $ownerPkey);
    $r = $svc->updateSample($badId, $ownerPkey, array('field_data' => '[broken'));
    check("update with non-JSON string rejected 'invalid_json_field'",
          empty($r['ok']) && $r['error'] === 'invalid_json_field'
          && isset($r['detail']['field']) && $r['detail']['field'] === 'field_data');
    $v = $db->get_var_prepared(
        "SELECT custom_data FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($badId, $ownerPkey));
    check("rejected update left row untouched",           $v !== null && strpos($v, 'depth_m') !== false);
    $r = $svc->updateSample($badId, $ownerPkey, array('custom_data' => null));
    check("null still clears a JSONB field",              !empty($r['ok']) && $r['sample']['custom_data'] === null);

    echo "\n=== updateSample spine + JSONB ===\n";
    $beforeRow = $db->get_row_prepared(
        "SELECT modified_at FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($suppliedId, $ownerPkey)
    );
    sleep(1);  // ensure modified_at moves
    $r = $svc->updateSample($suppliedId, $ownerPkey, array(
        'name'        => 'renamed',
        'description' => 'new description',
        'experimental_data' => array('runs' => 3),
    ));
    check("update returns ok",                            !empty($r['ok']));
    $afterSample = $r['sample'];
    check("name updated",                                 $afterSample['name'] === 'renamed');
    check("description updated",                          $afterSample['description'] === 'new description');
    check("modified_at moved forward",                    $afterSample['modified_at'] !== $beforeRow->modified_at);
    check("modified_by = caller",                         (int)$afterSample['modified_by'] === $ownerPkey);
    check("experimental_data persisted",                  is_array($afterSample['experimental_data']) && $afterSample['experimental_data']['runs'] === 3);

    echo "\n=== changelog row appended on update ===\n";
    $clog = $db->get_row_prepared(
        "SELECT change_type, changes::text AS changes_text
           FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
          ORDER BY pkey DESC LIMIT 1",
        array($suppliedId, $ownerPkey)
    );
    check("update changelog change_type = 'update'",      $clog && $clog->change_type === 'update');
    $changes = json_decode($clog->changes_text, true);
    check("changes diff contains 'name' with old/new",    isset($changes['name']['old']) && isset($changes['name']['new']));
    check("name old = 'with supplied id'",                $changes['name']['old'] === 'with supplied id');
    check("name new = 'renamed'",                         $changes['name']['new'] === 'renamed');
    check("JSONB logged as { updated: true }",            isset($changes['experimental_data']['updated']) && $changes['experimental_data']['updated'] === true);

    echo "\n=== no-writable-fields rejected ===\n";
    $r = $svc->updateSample($suppliedId, $ownerPkey, array('id' => 'cannot-change', 'userpkey' => 9999));
    check("returns 'no_writable_fields'",                 empty($r['ok']) && $r['error'] === 'no_writable_fields');

    echo "\n=== editor collab can update; readonly cannot ===\n";
    // Manually accept-seed grants on the supplied-id sample.
    $editorUuid = bin2hex(random_bytes(8));
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
        array($suppliedId, $ownerPkey, $editorPkey, $editorUuid)
    );
    $readerUuid = bin2hex(random_bytes(8));
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'readonly', $4, TRUE, now(), $2)",
        array($suppliedId, $ownerPkey, $readerPkey, $readerUuid)
    );

    $svc->setUserpkey($editorPkey);
    $r = $svc->updateSample($suppliedId, $ownerPkey, array('description' => 'edited by editor'));
    check("editor update returns ok",                     !empty($r['ok']));
    check("editor description visible",                   $r['sample']['description'] === 'edited by editor');
    check("editor modified_by = editorPkey",              (int)$r['sample']['modified_by'] === $editorPkey);

    $svc->setUserpkey($readerPkey);
    $r = $svc->updateSample($suppliedId, $ownerPkey, array('description' => 'reader attempt'));
    check("reader update refused 'forbidden'",            empty($r['ok']) && $r['error'] === 'forbidden');

    echo "\n=== stranger blocked, delete owner-only ===\n";
    // True stranger (sentinel pkey, no grants): existence-hiding means every
    // verb answers not_found, indistinguishable from a missing sample.
    $svc->setUserpkey(999999901);
    $r = $svc->updateSample($suppliedId, $ownerPkey, array('description' => 'stranger attempt'));
    check("stranger update refused 'not_found' (existence hidden)", empty($r['ok']) && $r['error'] === 'not_found');
    $r = $svc->deleteSample($suppliedId, $ownerPkey);
    check("stranger delete refused 'not_found' (existence hidden)", empty($r['ok']) && $r['error'] === 'not_found');

    $svc->setUserpkey($readerPkey);
    $r = $svc->deleteSample($suppliedId, $ownerPkey);
    check("readonly cannot delete ('forbidden')",         empty($r['ok']) && $r['error'] === 'forbidden');

    $svc->setUserpkey($editorPkey);
    $r = $svc->deleteSample($suppliedId, $ownerPkey);
    check("editor cannot delete (owner-only, 'forbidden')", empty($r['ok']) && $r['error'] === 'forbidden');

    echo "\n=== Field-linked lat/lng read-only ===\n";
    $fieldSampleId = "smoketest-writes-field-$stamp";
    $svc->setUserpkey($ownerPkey);
    $r = $svc->createSample(array('id' => $fieldSampleId, 'name' => 'field-linked'));
    $ids[] = array($fieldSampleId, $ownerPkey);
    // Tag it as Field-linked by inserting a subsystem_link row directly.
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
           (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey)
         VALUES ($1, $2, 'field', 'spot-' || $1, $2)",
        array($fieldSampleId, $ownerPkey)
    );
    $r = $svc->updateSample($fieldSampleId, $ownerPkey, array('latitude' => 45.5));
    check("lat update refused 'field_link_read_only'",    empty($r['ok']) && $r['error'] === 'field_link_read_only');
    $r = $svc->updateSample($fieldSampleId, $ownerPkey, array('longitude' => -120.0));
    check("lng update refused 'field_link_read_only'",    empty($r['ok']) && $r['error'] === 'field_link_read_only');
    // Other fields should still update fine.
    $r = $svc->updateSample($fieldSampleId, $ownerPkey, array('name' => 'field-linked renamed'));
    check("non-geometry field still updatable on Field-linked sample", !empty($r['ok']));

    echo "\n=== delete (owner) ===\n";
    $r = $svc->deleteSample($suppliedId, $ownerPkey);
    check("delete returns ok",                            !empty($r['ok']));
    $still = $svc->getSample($suppliedId, $ownerPkey);
    check("getSample returns null after delete",          $still === null);
    $cascadeChild = $db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_collaborators WHERE sample_id=$1 AND sample_userpkey=$2",
        array($suppliedId, $ownerPkey)
    );
    check("cascade wiped collaborators",                  (int)$cascadeChild === 0);
    $cascadeLog = $db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_id=$1 AND sample_userpkey=$2",
        array($suppliedId, $ownerPkey)
    );
    check("cascade wiped changelog",                      (int)$cascadeLog === 0);

    echo "\n=== delete (not found) ===\n";
    $r = $svc->deleteSample('nope-' . $stamp, $ownerPkey);
    check("delete non-existent returns 'not_found'",      empty($r['ok']) && $r['error'] === 'not_found');

} finally {
    foreach ($ids as $pair) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($pair[0], $pair[1]));
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
