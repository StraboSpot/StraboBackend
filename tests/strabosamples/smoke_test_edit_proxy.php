<?php
/**
 * File: smoke_test_edit_proxy.php
 * Description: HTTP-layer smoke test for /samples_edit.php — the
 *              session-authed proxy that powers the in-page Edit
 *              Metadata modal in samples_detail.php. Covers update
 *              happy path + auth + error-to-status mapping + field-
 *              link read-only guard + defensive identity stripping.
 *
 *              Same chown'd www-data session-file harness as the
 *              other strabosamples proxy smoke tests.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/strabosamples/smoke_test_edit_proxy.php
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

$users = $db->get_results_prepared(
    "SELECT pkey, email FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 4", array()
);
if (!is_array($users) || count($users) < 4) {
    echo "Cannot find 4 distinct test users.\n";
    exit(1);
}
$ownerPkey    = (int)$users[0]->pkey;
$editorPkey   = (int)$users[1]->pkey;
$readerPkey   = (int)$users[2]->pkey;
$strangerPkey = (int)$users[3]->pkey;
echo "owner=$ownerPkey  editor=$editorPkey  reader=$readerPkey  stranger=$strangerPkey\n\n";

$sessionDir = '/var/lib/php/sessions';
function writeSession($dir, $pkey) {
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = $dir . '/sess_' . $sid;
    $body = 'loggedin|s:3:"yes";userpkey|i:' . $pkey . ';LAST_ACTIVITY|i:' . time() . ';';
    file_put_contents($path, $body);
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    return array($sid, $path);
}

list($ownerSid,    $ownerSessionFile)    = writeSession($sessionDir, $ownerPkey);
list($editorSid,   $editorSessionFile)   = writeSession($sessionDir, $editorPkey);
list($readerSid,   $readerSessionFile)   = writeSession($sessionDir, $readerPkey);
list($strangerSid, $strangerSessionFile) = writeSession($sessionDir, $strangerPkey);

function hit($sid, $jsonBody, $url = 'http://localhost/samples_edit.php') {
    $headers = array('Content-Type: application/json');
    if ($sid !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $sid;
    }
    $hdr = '';
    foreach ($headers as $h) { $hdr .= '-H ' . escapeshellarg($h) . ' '; }
    $payload = $jsonBody === null ? '' : ('-d ' . escapeshellarg($jsonBody));
    $bodyFile = tempnam(sys_get_temp_dir(), 'edit_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . $hdr . " -X POST $payload " . escapeshellarg($url);
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    $json = json_decode($body, true);
    return array('status' => $status, 'body' => $body, 'json' => $json);
}

$stamp = time();
$sampleId        = 'smoke-edit-proxy-' . $stamp;
$fieldLinkedId   = 'smoke-edit-proxy-fl-' . $stamp;

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

try {
    // Seed a "plain" sample owned by the owner — no Field link.
    $svc->createSample(array(
        'id' => $sampleId,
        'name' => 'edit-proxy-smoke',
        'description' => 'initial description',
        'notes' => 'initial notes',
        'latitude' => 10.0,
        'longitude' => 20.0,
        'display_sample_type' => 'intact_rock',
        'display_sample_purpose' => 'reference',
    ));

    // Seed a "Field-linked" sample with a sample_subsystem_links row
    // for subsystem='field'. Lat/lng updates must 409.
    $svc->createSample(array(
        'id' => $fieldLinkedId,
        'name' => 'edit-proxy-field-linked',
        'latitude' => 30.0,
        'longitude' => 40.0,
    ));
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
           (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
         VALUES ($1, $2, 'field', $3, $4, NULL)",
        array($fieldLinkedId, $ownerPkey, 'dummy-spot-' . $stamp, $ownerPkey)
    );

    // Seed accepted edit + readonly grants on the plain sample.
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
        array($sampleId, $ownerPkey, $editorPkey, bin2hex(random_bytes(8)))
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'readonly', $4, TRUE, now(), $2)",
        array($sampleId, $ownerPkey, $readerPkey, bin2hex(random_bytes(8)))
    );

    echo "=== Unauthenticated request ===\n";
    $r = hit(null, json_encode(array('sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'name' => 'x')));
    check('status = 401',                       $r['status'] === 401);
    check('error = not_authenticated',           isset($r['json']['error']) && $r['json']['error'] === 'not_authenticated');

    echo "\n=== Invalid JSON ===\n";
    $r = hit($ownerSid, '{nope');
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_json',                isset($r['json']['error']) && $r['json']['error'] === 'invalid_json');

    echo "\n=== Missing sample_id ===\n";
    $r = hit($ownerSid, json_encode(array('owner_pkey' => $ownerPkey, 'name' => 'x')));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_required_fields',     isset($r['json']['error']) && $r['json']['error'] === 'missing_required_fields');

    echo "\n=== Missing owner_pkey ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $sampleId, 'name' => 'x')));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_required_fields',     isset($r['json']['error']) && $r['json']['error'] === 'missing_required_fields');

    echo "\n=== No writable fields → 400 ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $sampleId, 'owner_pkey' => $ownerPkey)));
    check('status = 400',                       $r['status'] === 400);
    check('error = no_writable_fields',          isset($r['json']['error']) && $r['json']['error'] === 'no_writable_fields');

    echo "\n=== Non-JSON custom_data string → 400 (pre-fix: ok:true, silent PG abort) ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id'   => $sampleId,
        'owner_pkey'  => $ownerPkey,
        'custom_data' => 'definitely not json',
    )));
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_json_field',          isset($r['json']['error']) && $r['json']['error'] === 'invalid_json_field');
    check('detail names custom_data',            isset($r['json']['detail']['field']) && $r['json']['detail']['field'] === 'custom_data');
    $v = $db->get_var_prepared(
        "SELECT custom_data FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey));
    check('DB custom_data untouched by rejected update', $v === null);

    echo "\n=== Happy-path update (owner) ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id'   => $sampleId,
        'owner_pkey'  => $ownerPkey,
        'name'        => 'renamed-via-edit',
        'igsn'        => 'IGSN-NEW-001',
        'description' => 'updated description',
        'notes'       => 'updated notes',
        'latitude'    => 12.5,
        'longitude'   => -45.25,
        'display_sample_type'    => 'tephra',
        'display_sample_purpose' => 'analytical_quality_assurance',
    )));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    check('sample present in response',          isset($r['json']['sample']) && is_array($r['json']['sample']));
    if (isset($r['json']['sample'])) {
        $s = $r['json']['sample'];
        check('name persisted',                  isset($s['name']) && $s['name'] === 'renamed-via-edit');
        check('igsn persisted',                  isset($s['igsn']) && $s['igsn'] === 'IGSN-NEW-001');
        check('description persisted',           isset($s['description']) && $s['description'] === 'updated description');
        check('notes persisted',                 isset($s['notes']) && $s['notes'] === 'updated notes');
        check('latitude persisted',              isset($s['latitude']) && (float)$s['latitude'] === 12.5);
        check('longitude persisted',             isset($s['longitude']) && (float)$s['longitude'] === -45.25);
        check('material type persisted',         isset($s['display_sample_type']) && $s['display_sample_type'] === 'tephra');
        check('sample purpose persisted',        isset($s['display_sample_purpose']) && $s['display_sample_purpose'] === 'analytical_quality_assurance');
    }
    // Verify DB-level persistence (not just the response payload).
    $persisted = $db->get_row_prepared(
        "SELECT name, igsn, description, notes, latitude, longitude,
                display_sample_type, display_sample_purpose
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
    check('DB: name persisted',                 $persisted && $persisted->name === 'renamed-via-edit');
    check('DB: igsn persisted',                  $persisted && $persisted->igsn === 'IGSN-NEW-001');

    echo "\n=== Editor collaborator (accepted, edit) ===\n";
    $r = hit($editorSid, json_encode(array(
        'sample_id'   => $sampleId,
        'owner_pkey'  => $ownerPkey,
        'notes'       => 'note from editor',
    )));
    check('status = 200',                       $r['status'] === 200);
    check('editor can update',                   isset($r['json']['ok']) && $r['json']['ok'] === true);

    echo "\n=== Readonly collaborator → 403 ===\n";
    $r = hit($readerSid, json_encode(array(
        'sample_id'   => $sampleId,
        'owner_pkey'  => $ownerPkey,
        'notes'       => 'should be blocked',
    )));
    check('status = 403',                       $r['status'] === 403);
    check('error = forbidden',                   isset($r['json']['error']) && $r['json']['error'] === 'forbidden');

    echo "\n=== Stranger → 404 (existence hidden; no read access) ===\n";
    // Existence-hiding posture: a caller who can't READ the sample gets the
    // same not_found as a missing row on every verb — mutations can't be
    // used to probe which (id, owner) pairs exist. 'forbidden' is reserved
    // for readable-but-not-writable callers (readonly collab, above).
    $r = hit($strangerSid, json_encode(array(
        'sample_id'   => $sampleId,
        'owner_pkey'  => $ownerPkey,
        'notes'       => 'stranger-attempt',
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== Nonexistent sample → 404 ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id'   => 'no-such-sample-' . $stamp,
        'owner_pkey'  => $ownerPkey,
        'notes'       => 'ghost',
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== Field-linked sample: lat/lng → 409 ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id'   => $fieldLinkedId,
        'owner_pkey'  => $ownerPkey,
        'latitude'    => 99.0,
    )));
    check('status = 409',                       $r['status'] === 409);
    check('error = field_link_read_only',        isset($r['json']['error']) && $r['json']['error'] === 'field_link_read_only');
    $r = hit($ownerSid, json_encode(array(
        'sample_id'   => $fieldLinkedId,
        'owner_pkey'  => $ownerPkey,
        'longitude'   => 99.0,
    )));
    check('status = 409 (lng)',                 $r['status'] === 409);
    check('error = field_link_read_only (lng)',  isset($r['json']['error']) && $r['json']['error'] === 'field_link_read_only');

    echo "\n=== Field-linked sample: non-lat/lng spine fields succeed ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id'   => $fieldLinkedId,
        'owner_pkey'  => $ownerPkey,
        'description' => 'field-linked desc update',
    )));
    check('status = 200',                       $r['status'] === 200);
    check('description update on field-linked sample succeeds', isset($r['json']['ok']) && $r['json']['ok'] === true);
    // Verify lat/lng were NOT touched (still the original seed values).
    $stillThere = $db->get_row_prepared(
        "SELECT latitude, longitude FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($fieldLinkedId, $ownerPkey)
    );
    check('DB: latitude unchanged',             $stillThere && (float)$stillThere->latitude  === 30.0);
    check('DB: longitude unchanged',             $stillThere && (float)$stillThere->longitude === 40.0);

    echo "\n=== Defensive identity stripping ===\n";
    // Client tries to send userpkey / created_by / id — proxy strips, service
    // wouldn't accept them anyway, but defense-in-depth.
    $r = hit($ownerSid, json_encode(array(
        'sample_id'   => $sampleId,
        'owner_pkey'  => $ownerPkey,
        'notes'       => 'with-strip',
        'userpkey'    => $strangerPkey,
        'created_by'  => $strangerPkey,
        'modified_by' => $strangerPkey,
        'id'          => 'attacker-id-' . $stamp,
    )));
    check('status = 200',                       $r['status'] === 200);
    if (isset($r['json']['sample'])) {
        $s = $r['json']['sample'];
        check('userpkey unchanged after strip',  isset($s['userpkey']) && (int)$s['userpkey'] === $ownerPkey);
        check('id unchanged after strip',        isset($s['id']) && $s['id'] === $sampleId);
    }

    echo "\n=== Parent fields are NOT applied via this proxy ===\n";
    // The proxy strips parent_sample_id / parent_userpkey — Edit Metadata is
    // spine-only; parent ops go through /sample/{id}/parent.
    $r = hit($ownerSid, json_encode(array(
        'sample_id'        => $sampleId,
        'owner_pkey'       => $ownerPkey,
        'notes'            => 'after-parent-attempt',
        'parent_sample_id' => 'some-other-sample-' . $stamp,
        'parent_userpkey'  => $strangerPkey,
    )));
    check('status = 200',                       $r['status'] === 200);
    $parentRow = $db->get_row_prepared(
        "SELECT parent_sample_id, parent_userpkey
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
    check('DB: parent_sample_id NOT applied',    $parentRow && $parentRow->parent_sample_id === null);
    check('DB: parent_userpkey NOT applied',     $parentRow && $parentRow->parent_userpkey  === null);

} finally {
    $svc->setUserpkey($ownerPkey);
    // CASCADE wipes children/changelog/collabs/links with the sample.
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sampleId,      $ownerPkey));
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($fieldLinkedId, $ownerPkey));
    @unlink($ownerSessionFile);
    @unlink($editorSessionFile);
    @unlink($readerSessionFile);
    @unlink($strangerSessionFile);
}

echo "\n";
if (count($failures) === 0) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " FAIL(S)\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
