<?php
/**
 * File: smoke_test_api_collab.php
 * Description: Service-layer smoke test for samples/api-collab. Exercises the
 *              full §7 collaboration lifecycle without going through HTTP:
 *
 *                invite → list (owner) → list-pending (invitee) → accept →
 *                canEdit/canRead transitions → updateLevel (edit → readonly) →
 *                canEdit drops to false → remove → canRead drops to false →
 *                re-invite (soft-removed row is re-enabled) → deny →
 *                duplicate-id accept guard
 *
 *              Hermetic: seeds two test samples under sentinel-named ids
 *              keyed on time(), runs against any two real users picked at
 *              start, and cleans up at the end (DELETE cascades collaborators).
 *              Real data is never touched.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_api_collab.php
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

// Pick two distinct non-deleted users.
$owner   = $db->get_row_prepared(
    "SELECT pkey, email FROM users WHERE deleted = FALSE AND active = TRUE LIMIT 1", array()
);
$invitee = $db->get_row_prepared(
    "SELECT pkey, email FROM users WHERE deleted = FALSE AND active = TRUE AND pkey != $1 LIMIT 1",
    array((int)$owner->pkey)
);
$ownerPkey   = (int)$owner->pkey;
$inviteePkey = (int)$invitee->pkey;
$inviteeEmail = $invitee->email;
echo "Owner: pkey=$ownerPkey  Invitee: pkey=$inviteePkey ($inviteeEmail)\n\n";

// Unique-per-run sentinel sample ids.
$stamp = time();
$testId      = 'smoketest-collab-' . $stamp;
$dupTestId   = 'smoketest-dup-'    . $stamp;

// Seed test samples (DELETE later cascades collaborators/links/etc.)
$db->prepare_query(
    "INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
     VALUES ($1, $2, 'smoke owner sample', $2, $2)",
    array($testId, $ownerPkey)
);
$db->prepare_query(
    "INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
     VALUES ($1, $2, 'smoke owner sample (dup-id test)', $2, $2)",
    array($dupTestId, $ownerPkey)
);
// For duplicate-id guard: invitee ALSO owns a sample with id=$dupTestId
$db->prepare_query(
    "INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
     VALUES ($1, $2, 'invitee preexisting sample (same id)', $2, $2)",
    array($dupTestId, $inviteePkey)
);

$svc = new StraboSamplesService($db, $neodb);

// Run within a try/finally so cleanup happens even on assertion failure.
try {

    echo "=== invite (as owner) ===\n";
    $svc->setUserpkey($ownerPkey);
    $r = $svc->inviteCollaborators($testId, $ownerPkey, array($inviteeEmail), 'edit');
    check("invite returns ok=true",                  !empty($r['ok']));
    check("invite returns one result",               isset($r['results']) && count($r['results']) === 1);
    $r0 = $r['results'][0];
    check("status = 'invited'",                      isset($r0['status']) && $r0['status'] === 'invited');
    check("invite returns a uuid",                   !empty($r0['uuid']));
    $uuid = $r0['uuid'];

    echo "\n=== listCollaborators (owner sees pending + uuid) ===\n";
    $list = $svc->listCollaborators($testId, $ownerPkey);
    check("returns 1 collaborator",                  is_array($list) && count($list) === 1);
    if (!empty($list)) {
        check("collaborator is invitee",             (int)$list[0]['collaborator_pkey'] === $inviteePkey);
        check("not yet accepted",                    $list[0]['accepted'] === false);
        check("owner sees the uuid",                 isset($list[0]['uuid']));
    }

    echo "\n=== invitee cannot read pending sample ===\n";
    $svc->setUserpkey($inviteePkey);
    $hidden = $svc->getSample($testId, $ownerPkey);
    check("getSample returns null while pending",    $hidden === null);
    check("canRead is false",                        $svc->canRead($testId, $ownerPkey) === false);

    echo "\n=== invitee sees the pending invite ===\n";
    $invites = $svc->listMyInvitations();
    $found = null;
    foreach ($invites as $i) {
        if ($i['sample_id'] === $testId && (int)$i['sample_userpkey'] === $ownerPkey) {
            $found = $i; break;
        }
    }
    check("listMyInvitations contains the invite",   $found !== null);
    if ($found) {
        check("invite uuid matches what owner saw",  $found['uuid'] === $uuid);
        check("permission_level = 'edit'",           $found['permission_level'] === 'edit');
    }

    echo "\n=== accept ===\n";
    $r = $svc->acceptInvitation($testId, $uuid);
    check("accept returns ok=true",                  !empty($r['ok']));

    echo "\n=== invitee can now read + edit ===\n";
    $sample = $svc->getSample($testId, $ownerPkey);
    check("getSample returns the sample",            is_array($sample) && $sample['id'] === $testId);
    check("canRead is true",                         $svc->canRead($testId, $ownerPkey) === true);
    check("canEdit is true (edit grant)",            $svc->canEdit($testId, $ownerPkey) === true);
    check("canManage is false (owner-only)",         $svc->canManage($testId, $ownerPkey) === false);

    echo "\n=== owner downgrades to readonly ===\n";
    $svc->setUserpkey($ownerPkey);
    $r = $svc->updateCollaboratorLevel($testId, $ownerPkey, $inviteePkey, 'readonly');
    check("updateCollaboratorLevel returns ok",      !empty($r['ok']));
    $svc->setUserpkey($inviteePkey);
    check("invitee canRead still true",              $svc->canRead($testId, $ownerPkey) === true);
    check("invitee canEdit now false",               $svc->canEdit($testId, $ownerPkey) === false);

    echo "\n=== owner removes invitee ===\n";
    $svc->setUserpkey($ownerPkey);
    $r = $svc->removeCollaborator($testId, $ownerPkey, $inviteePkey);
    check("removeCollaborator returns ok",           !empty($r['ok']));
    $svc->setUserpkey($inviteePkey);
    check("invitee canRead now false",               $svc->canRead($testId, $ownerPkey) === false);

    echo "\n=== re-invite (soft-removed row is re-enabled) ===\n";
    $svc->setUserpkey($ownerPkey);
    $r = $svc->inviteCollaborators($testId, $ownerPkey, array($inviteeEmail), 'edit');
    check("re-invite returns ok",                    !empty($r['ok']));
    $rr = $r['results'][0];
    check("status = 're_enabled'",                   $rr['status'] === 're_enabled');
    check("re-invite returns a fresh uuid",          !empty($rr['uuid']) && $rr['uuid'] !== $uuid);
    $uuid2 = $rr['uuid'];

    echo "\n=== invitee denies the re-invite ===\n";
    $svc->setUserpkey($inviteePkey);
    $r = $svc->denyInvitation($testId, $uuid2);
    check("deny returns ok=true",                    !empty($r['ok']));
    $remaining = array_filter($svc->listMyInvitations(), function ($i) use ($testId) {
        return $i['sample_id'] === $testId;
    });
    check("no pending invite remains for testId",    count($remaining) === 0);

    echo "\n=== duplicate-id accept guard ===\n";
    // Owner invites invitee to $dupTestId. Invitee already owns a sample
    // with id=$dupTestId, so accept must refuse.
    $svc->setUserpkey($ownerPkey);
    $r = $svc->inviteCollaborators($dupTestId, $ownerPkey, array($inviteeEmail), 'edit');
    $dupUuid = isset($r['results'][0]['uuid']) ? $r['results'][0]['uuid'] : null;
    check("dup-test invite went through",            !empty($dupUuid));
    $svc->setUserpkey($inviteePkey);
    $r = $svc->acceptInvitation($dupTestId, $dupUuid);
    check("accept refused with 'duplicate_id_conflict'", empty($r['ok']) && $r['error'] === 'duplicate_id_conflict');

    echo "\n=== owner-only gates ===\n";
    // Invitee tries to invite someone to the owner's sample.
    $r = $svc->inviteCollaborators($testId, $ownerPkey, array('anyone@example.com'), 'edit');
    check("invitee invite refused with 'forbidden'", empty($r['ok']) && $r['error'] === 'forbidden');

    echo "\n=== unknown email returns 'unknown' ===\n";
    $svc->setUserpkey($ownerPkey);
    $r = $svc->inviteCollaborators($testId, $ownerPkey, array('definitely-not-a-real-user-' . time() . '@nowhere.invalid'), 'readonly');
    check("invite ok with results array",            !empty($r['ok']));
    check("status = 'unknown'",                      $r['results'][0]['status'] === 'unknown');

} finally {
    // Cleanup — DELETE cascades sample_collaborators
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($testId, $ownerPkey));
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($dupTestId, $ownerPkey));
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($dupTestId, $inviteePkey));
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
