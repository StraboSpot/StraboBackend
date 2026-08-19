<?php
/**
 * File: smoke_test_collab_proxy.php
 * Description: HTTP-layer smoke test for /samples_collab.php — the
 *              session-authed JSON proxy for collaborator management.
 *              Covers all four actions (list / invite / update_level /
 *              remove) and the auth + dispatch surface around them.
 *              Service-level invite/update/remove behavior is already
 *              covered by the api-collab service smoke test; this file
 *              specifically exercises the proxy's HTTP path, JSON-body
 *              parsing, status-code mapping, and the list-enrichment
 *              join with the users table.
 *
 *              Reuses the chown'd www-data session-file harness from
 *              smoke_test_create_proxy.php.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/strabosamples/smoke_test_collab_proxy.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

// Three test users: owner (creates the sample), invitee (gets invited),
// stranger (used for forbidden checks).
$users = $db->get_results_prepared(
    "SELECT pkey, email FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 3", array()
);
if (!is_array($users) || count($users) < 3) {
    echo "Cannot find 3 distinct test users.\n";
    exit(1);
}
$ownerPkey    = (int)$users[0]->pkey;  $ownerEmail   = $users[0]->email;
$inviteePkey  = (int)$users[1]->pkey;  $inviteeEmail = $users[1]->email;
$strangerPkey = (int)$users[2]->pkey;  $strangerEmail= $users[2]->email;
echo "owner=$ownerPkey ({$ownerEmail})  invitee=$inviteePkey ({$inviteeEmail})  stranger=$strangerPkey\n\n";

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

list($ownerSid, $ownerSessionFile)       = writeSession($sessionDir, $ownerPkey);
list($strangerSid, $strangerSessionFile) = writeSession($sessionDir, $strangerPkey);

function hit($sid, $jsonBody) {
    $headers = array('Content-Type: application/json');
    if ($sid !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $sid;
    }
    $hdr = '';
    foreach ($headers as $h) { $hdr .= '-H ' . escapeshellarg($h) . ' '; }
    $payload = $jsonBody === null ? '' : ('-d ' . escapeshellarg($jsonBody));
    $bodyFile = tempnam(sys_get_temp_dir(), 'collab_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . $hdr . " -X POST $payload http://localhost/samples_collab.php";
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    $json = json_decode($body, true);
    return array('status' => $status, 'body' => $body, 'json' => $json);
}

$stamp = time();
$sampleId = 'smoke-collab-' . $stamp;

// Seed a test sample owned by the owner.
$db->prepare_query(
    "INSERT INTO strabosamples.samples (id, userpkey, created_by, modified_by, name)
     VALUES ($1, $2, $3, $4, $5)",
    array($sampleId, $ownerPkey, $ownerPkey, $ownerPkey, 'collab smoke sample')
);

try {

    echo "=== Unauthenticated request ===\n";
    $r = hit(null, json_encode(array('sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'list')));
    check('status = 401',                       $r['status'] === 401);
    check('error = not_authenticated',           isset($r['json']['error']) && $r['json']['error'] === 'not_authenticated');

    echo "\n=== Invalid JSON body ===\n";
    $r = hit($ownerSid, '{not valid');
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_json',                isset($r['json']['error']) && $r['json']['error'] === 'invalid_json');

    echo "\n=== Missing required fields ===\n";
    $r = hit($ownerSid, json_encode(array('action' => 'list')));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_required_fields',    isset($r['json']['error']) && $r['json']['error'] === 'missing_required_fields');

    echo "\n=== Invalid action ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'evict')));
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_action',              isset($r['json']['error']) && $r['json']['error'] === 'invalid_action');

    echo "\n=== List as owner — empty roster ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'list')));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    check('collaborators array, empty',           isset($r['json']['collaborators']) && is_array($r['json']['collaborators']) && count($r['json']['collaborators']) === 0);

    echo "\n=== Invite — unknown email ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'invite',
        'emails' => array('nobody-' . $stamp . '@nope.invalid'),
        'permission_level' => 'edit',
    )));
    check('status = 200',                       $r['status'] === 200);
    check('per-email status = unknown',          isset($r['json']['results'][0]['status']) && $r['json']['results'][0]['status'] === 'unknown');

    echo "\n=== Invite — owner self ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'invite',
        'emails' => array($ownerEmail),
        'permission_level' => 'edit',
    )));
    check('per-email status = is_owner',         isset($r['json']['results'][0]['status']) && $r['json']['results'][0]['status'] === 'is_owner');

    echo "\n=== Invite — fresh invitation ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'invite',
        'emails' => array($inviteeEmail),
        'permission_level' => 'readonly',
    )));
    check('status = 200',                       $r['status'] === 200);
    check('per-email status = invited',          isset($r['json']['results'][0]['status']) && $r['json']['results'][0]['status'] === 'invited');

    echo "\n=== List as owner — invitee shows up pending readonly ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'list')));
    check('1 collaborator returned',             isset($r['json']['collaborators']) && count($r['json']['collaborators']) === 1);
    $row = $r['json']['collaborators'][0];
    check('collaborator_pkey = invitee',          isset($row['collaborator_pkey']) && (int)$row['collaborator_pkey'] === $inviteePkey);
    check('permission_level = readonly',          isset($row['permission_level']) && $row['permission_level'] === 'readonly');
    check('accepted = false',                     isset($row['accepted']) && $row['accepted'] === false);
    check('email enriched',                       isset($row['email']) && $row['email'] === $inviteeEmail);
    check('name enriched (non-empty)',            isset($row['name']) && $row['name'] !== '');
    check('initials enriched',                    isset($row['initials']) && $row['initials'] !== '');

    echo "\n=== Invite same email again — already_active ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'invite',
        'emails' => array($inviteeEmail),
        'permission_level' => 'edit',
    )));
    check('per-email status = already_active',   isset($r['json']['results'][0]['status']) && $r['json']['results'][0]['status'] === 'already_active');

    echo "\n=== Update level — happy path ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'update_level',
        'collaborator_pkey' => $inviteePkey,
        'permission_level' => 'edit',
    )));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);

    echo "\n=== Update level — invalid level ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'update_level',
        'collaborator_pkey' => $inviteePkey,
        'permission_level' => 'admin',
    )));
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_permission_level',    isset($r['json']['error']) && $r['json']['error'] === 'invalid_permission_level');

    echo "\n=== Update level — missing collaborator_pkey ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'update_level',
        'permission_level' => 'edit',
    )));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_collaborator_pkey',   isset($r['json']['error']) && $r['json']['error'] === 'missing_collaborator_pkey');

    echo "\n=== Update level — grant_not_found ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'update_level',
        'collaborator_pkey' => 99999999,
        'permission_level' => 'edit',
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = grant_not_found',             isset($r['json']['error']) && $r['json']['error'] === 'grant_not_found');

    // Stranger sessions (no read access) get 404 not_found on every collab
    // mutation — existence-hiding; 'forbidden' is reserved for callers who
    // can read the sample but lack manage rights.
    echo "\n=== Stranger tries to invite — 404 existence hidden ===\n";
    $r = hit($strangerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'invite',
        'emails' => array($inviteeEmail),
        'permission_level' => 'edit',
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== Stranger tries to update_level — 404 existence hidden ===\n";
    $r = hit($strangerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'update_level',
        'collaborator_pkey' => $inviteePkey,
        'permission_level' => 'edit',
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== Stranger tries to remove — 404 existence hidden ===\n";
    $r = hit($strangerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'remove',
        'collaborator_pkey' => $inviteePkey,
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = not_found',                   isset($r['json']['error']) && $r['json']['error'] === 'not_found');

    echo "\n=== Remove — happy path ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'remove',
        'collaborator_pkey' => $inviteePkey,
    )));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);

    echo "\n=== List after remove — back to empty ===\n";
    $r = hit($ownerSid, json_encode(array('sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'list')));
    check('collaborators empty (soft-removed hidden)', isset($r['json']['collaborators']) && count($r['json']['collaborators']) === 0);

    echo "\n=== Re-invite after soft-removal — re_enabled ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'invite',
        'emails' => array($inviteeEmail),
        'permission_level' => 'readonly',
    )));
    check('per-email status = re_enabled',       isset($r['json']['results'][0]['status']) && $r['json']['results'][0]['status'] === 're_enabled');

    echo "\n=== Remove — grant_not_found ===\n";
    // Remove again — the row was re_enabled to pending, so removeCollaborator
    // works once. To test grant_not_found, remove twice.
    hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'remove',
        'collaborator_pkey' => $inviteePkey,
    )));
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'remove',
        'collaborator_pkey' => $inviteePkey,
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = grant_not_found',             isset($r['json']['error']) && $r['json']['error'] === 'grant_not_found');

    echo "\n=== Invite — no emails ===\n";
    $r = hit($ownerSid, json_encode(array(
        'sample_id' => $sampleId, 'owner_pkey' => $ownerPkey, 'action' => 'invite',
        'emails' => array(),
        'permission_level' => 'edit',
    )));
    check('status = 400',                       $r['status'] === 400);
    check('error = no_emails',                   isset($r['json']['error']) && $r['json']['error'] === 'no_emails');

} finally {
    // Cleanup — CASCADE wipes collaborators with the sample.
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
    @unlink($ownerSessionFile);
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
