<?php
/**
 * File: smoke_test_invitations_proxy.php
 * Description: HTTP-layer smoke test for /samples_invitations.php — the
 *              session-authed proxy for the invitee side of collaboration.
 *              Covers list / accept / deny + auth + status mapping +
 *              list-enrichment (added_by → inviter display fields).
 *
 *              Same chown'd www-data session-file harness as
 *              smoke_test_create_proxy.php and smoke_test_collab_proxy.php.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/strabosamples/smoke_test_invitations_proxy.php
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

$users = $db->get_results_prepared(
    "SELECT pkey, email FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 3", array()
);
if (!is_array($users) || count($users) < 3) {
    echo "Cannot find 3 distinct test users.\n";
    exit(1);
}
// owner sends invites; invitee receives them; stranger is a third party
// for the unauth/cross-user checks.
$ownerPkey    = (int)$users[0]->pkey;  $ownerEmail   = $users[0]->email;
$inviteePkey  = (int)$users[1]->pkey;  $inviteeEmail = $users[1]->email;
$strangerPkey = (int)$users[2]->pkey;
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

list($inviteeSid, $inviteeSessionFile) = writeSession($sessionDir, $inviteePkey);
list($strangerSid, $strangerSessionFile) = writeSession($sessionDir, $strangerPkey);

function hit($sid, $jsonBody, $url = 'http://localhost/samples_invitations.php') {
    $headers = array('Content-Type: application/json');
    if ($sid !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $sid;
    }
    $hdr = '';
    foreach ($headers as $h) { $hdr .= '-H ' . escapeshellarg($h) . ' '; }
    $payload = $jsonBody === null ? '' : ('-d ' . escapeshellarg($jsonBody));
    $bodyFile = tempnam(sys_get_temp_dir(), 'inv_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . $hdr . " -X POST $payload " . escapeshellarg($url);
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    $json = json_decode($body, true);
    return array('status' => $status, 'body' => $body, 'json' => $json);
}

$stamp = time();
$sampleId = 'smoke-inv-' . $stamp;

// Seed a test sample owned by the owner, with one pending invite for the invitee.
$db->prepare_query(
    "INSERT INTO strabosamples.samples (id, userpkey, created_by, modified_by, name)
     VALUES ($1, $2, $3, $4, $5)",
    array($sampleId, $ownerPkey, $ownerPkey, $ownerPkey, 'inv smoke sample')
);
$inviteUuid = bin2hex(random_bytes(16));
$db->prepare_query(
    "INSERT INTO strabosamples.sample_collaborators
       (sample_id, sample_userpkey, collaborator_pkey, permission_level, uuid, accepted, added_by)
     VALUES ($1, $2, $3, 'edit', $4, FALSE, $5)",
    array($sampleId, $ownerPkey, $inviteePkey, $inviteUuid, $ownerPkey)
);

try {

    echo "=== Unauthenticated request ===\n";
    $r = hit(null, json_encode(array('action' => 'list')));
    check('status = 401',                       $r['status'] === 401);
    check('error = not_authenticated',           isset($r['json']['error']) && $r['json']['error'] === 'not_authenticated');

    echo "\n=== Invalid JSON ===\n";
    $r = hit($inviteeSid, '{nope');
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_json',                isset($r['json']['error']) && $r['json']['error'] === 'invalid_json');

    echo "\n=== Missing action ===\n";
    $r = hit($inviteeSid, json_encode(array()));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_action',              isset($r['json']['error']) && $r['json']['error'] === 'missing_action');

    echo "\n=== Invalid action ===\n";
    $r = hit($inviteeSid, json_encode(array('action' => 'fluff')));
    check('status = 400',                       $r['status'] === 400);
    check('error = invalid_action',              isset($r['json']['error']) && $r['json']['error'] === 'invalid_action');

    echo "\n=== List as invitee — finds the pending invite ===\n";
    $r = hit($inviteeSid, json_encode(array('action' => 'list')));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    check('invitations is array',                isset($r['json']['invitations']) && is_array($r['json']['invitations']));
    // Locate OUR seeded invite (other invites may exist on shared dev DB).
    $found = null;
    foreach ($r['json']['invitations'] as $inv) {
        if (isset($inv['uuid']) && $inv['uuid'] === $inviteUuid) { $found = $inv; break; }
    }
    check('seeded invite present',              $found !== null);
    if ($found) {
        check('sample_id matches',              isset($found['sample_id']) && $found['sample_id'] === $sampleId);
        check('sample_userpkey = owner',         isset($found['sample_userpkey']) && (int)$found['sample_userpkey'] === $ownerPkey);
        check('permission_level = edit',         isset($found['permission_level']) && $found['permission_level'] === 'edit');
        check('uuid matches',                    isset($found['uuid']) && $found['uuid'] === $inviteUuid);
        check('inviter_name enriched',            isset($found['inviter_name']) && $found['inviter_name'] !== '');
        check('inviter_initials enriched',        isset($found['inviter_initials']) && $found['inviter_initials'] !== '');
    }

    echo "\n=== Stranger lists — does not see this invite ===\n";
    $r = hit($strangerSid, json_encode(array('action' => 'list')));
    check('status = 200',                       $r['status'] === 200);
    $strangerSees = false;
    if (isset($r['json']['invitations']) && is_array($r['json']['invitations'])) {
        foreach ($r['json']['invitations'] as $inv) {
            if (isset($inv['uuid']) && $inv['uuid'] === $inviteUuid) { $strangerSees = true; break; }
        }
    }
    check('stranger does NOT see invitee\'s invite', !$strangerSees);

    echo "\n=== Accept — missing sample_id ===\n";
    $r = hit($inviteeSid, json_encode(array('action' => 'accept', 'uuid' => $inviteUuid)));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_sample_id',           isset($r['json']['error']) && $r['json']['error'] === 'missing_sample_id');

    echo "\n=== Accept — missing uuid ===\n";
    $r = hit($inviteeSid, json_encode(array('action' => 'accept', 'sample_id' => $sampleId)));
    check('status = 400',                       $r['status'] === 400);
    check('error = missing_uuid',                isset($r['json']['error']) && $r['json']['error'] === 'missing_uuid');

    echo "\n=== Accept — wrong uuid ===\n";
    $r = hit($inviteeSid, json_encode(array(
        'action' => 'accept', 'sample_id' => $sampleId, 'uuid' => 'deadbeef' . str_repeat('0', 24),
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = invitation_not_found',        isset($r['json']['error']) && $r['json']['error'] === 'invitation_not_found');

    echo "\n=== Accept — stranger can't accept invitee's invite ===\n";
    $r = hit($strangerSid, json_encode(array(
        'action' => 'accept', 'sample_id' => $sampleId, 'uuid' => $inviteUuid,
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = invitation_not_found',        isset($r['json']['error']) && $r['json']['error'] === 'invitation_not_found');

    echo "\n=== Accept — happy path ===\n";
    $r = hit($inviteeSid, json_encode(array(
        'action' => 'accept', 'sample_id' => $sampleId, 'uuid' => $inviteUuid,
    )));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    check('sample_userpkey echoed',              isset($r['json']['sample_userpkey']) && (int)$r['json']['sample_userpkey'] === $ownerPkey);
    // Verify DB state: accepted = TRUE, accepted_at set
    $acceptedRow = $db->get_row_prepared(
        "SELECT accepted, accepted_at FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND collaborator_pkey=$2 AND uuid=$3",
        array($sampleId, $inviteePkey, $inviteUuid)
    );
    check('DB: accepted = TRUE',                $acceptedRow && in_array($acceptedRow->accepted, array('t', true, 't', 'true', 1, '1'), true));
    check('DB: accepted_at populated',           $acceptedRow && $acceptedRow->accepted_at !== null);

    echo "\n=== Accept again — already_accepted ===\n";
    $r = hit($inviteeSid, json_encode(array(
        'action' => 'accept', 'sample_id' => $sampleId, 'uuid' => $inviteUuid,
    )));
    check('status = 409',                       $r['status'] === 409);
    check('error = already_accepted',            isset($r['json']['error']) && $r['json']['error'] === 'already_accepted');

    echo "\n=== Deny — happy path (after second pending invite) ===\n";
    // Seed a second pending invite under a new uuid.
    $secondUuid = bin2hex(random_bytes(16));
    // soft-remove the accepted row first so the unique-active-grant constraint
    // (if any) doesn't fight us, and so we have a clean re-invite scenario.
    $db->prepare_query(
        "UPDATE strabosamples.sample_collaborators
            SET removed_at = now()
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3 AND removed_at IS NULL",
        array($sampleId, $ownerPkey, $inviteePkey)
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level, uuid, accepted, added_by)
         VALUES ($1, $2, $3, 'readonly', $4, FALSE, $5)",
        array($sampleId, $ownerPkey, $inviteePkey, $secondUuid, $ownerPkey)
    );
    $r = hit($inviteeSid, json_encode(array(
        'action' => 'deny', 'sample_id' => $sampleId, 'uuid' => $secondUuid,
    )));
    check('status = 200',                       $r['status'] === 200);
    check('json.ok = true',                      isset($r['json']['ok']) && $r['json']['ok'] === true);
    $deniedRow = $db->get_row_prepared(
        "SELECT removed_at FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND collaborator_pkey=$2 AND uuid=$3",
        array($sampleId, $inviteePkey, $secondUuid)
    );
    check('DB: removed_at populated',            $deniedRow && $deniedRow->removed_at !== null);

    echo "\n=== Deny — already denied (looks like invitation_not_found) ===\n";
    $r = hit($inviteeSid, json_encode(array(
        'action' => 'deny', 'sample_id' => $sampleId, 'uuid' => $secondUuid,
    )));
    check('status = 404',                       $r['status'] === 404);
    check('error = invitation_not_found',        isset($r['json']['error']) && $r['json']['error'] === 'invitation_not_found');

    echo "\n=== Duplicate id conflict — invitee already owns same id ===\n";
    // Create a collision: invitee owns a sample with the same id as the
    // pending one. Seed a third pending invite to test.
    $collisionId = 'smoke-inv-collision-' . $stamp;
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, created_by, modified_by, name)
         VALUES ($1, $2, $3, $4, $5)",
        array($collisionId, $ownerPkey, $ownerPkey, $ownerPkey, 'collision parent sample')
    );
    // Invitee already owns a sample with this id
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, created_by, modified_by, name)
         VALUES ($1, $2, $3, $4, $5)",
        array($collisionId, $inviteePkey, $inviteePkey, $inviteePkey, 'invitee\'s own sample')
    );
    $collisionUuid = bin2hex(random_bytes(16));
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level, uuid, accepted, added_by)
         VALUES ($1, $2, $3, 'edit', $4, FALSE, $5)",
        array($collisionId, $ownerPkey, $inviteePkey, $collisionUuid, $ownerPkey)
    );
    $r = hit($inviteeSid, json_encode(array(
        'action' => 'accept', 'sample_id' => $collisionId, 'uuid' => $collisionUuid,
    )));
    check('status = 409',                       $r['status'] === 409);
    check('error = duplicate_id_conflict',       isset($r['json']['error']) && $r['json']['error'] === 'duplicate_id_conflict');
    // Cleanup the collision sample (the invitee's own)
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($collisionId, $inviteePkey));
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($collisionId, $ownerPkey));

} finally {
    // CASCADE wipes all collaborator rows with the sample.
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
    @unlink($inviteeSessionFile);
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
