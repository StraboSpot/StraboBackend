<?php
/**
 * File: tests/strabosamples/smoke_test_invite_email.php
 * Description: The StraboSamples invitation email sent by
 *              StraboSamplesService::inviteCollaborators(). Uses only the
 *              @test.strabospot.org fixture users (always filed to mail.log,
 *              never sent), so it is safe on any transport setting.
 *
 *              Checks: a fresh invite and a re-invite after removal each
 *              email once and report emailed = true; already a collaborator,
 *              the owner's own email and an unknown address send nothing and
 *              carry no emailed key; the email names the sample, type,
 *              inviter, access level and the My Samples link; a sample with
 *              no type leaves the Type row out.
 *
 *              Needs tests/collaboration/setup_test_data.php users.
 *              Run: docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_invite_email.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/includes/StraboMail.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$users = array();
foreach (array('owner', 'editor', 'readonly', 'outsider') as $who) {
    $email = $who . '@test.strabospot.org';
    $pkey = (int)$db->get_var_prepared(
        "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
    if (!$pkey) {
        echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n";
        exit(1);
    }
    $users[$who] = array('email' => $email, 'pkey' => $pkey);
}
$ownerPkey = $users['owner']['pkey'];

$logFile = StraboMail::logFile();
clearstatcache();
$logMark = is_file($logFile) ? filesize($logFile) : 0;

/** Mail filed since the last call: array of {to, subject, body}. */
function newMail() {
    global $logFile, $logMark;
    clearstatcache();
    $size = is_file($logFile) ? filesize($logFile) : 0;
    $chunk = $size > $logMark ? file_get_contents($logFile, false, null, $logMark) : '';
    $logMark = $size;
    $out = array();
    foreach (preg_split('/^---$/m', $chunk) as $part) {
        if (preg_match('/^\s*\[[^\]]+\] To: (\S+)\nSubject: ([^\n]*)\n(.*)$/s', $part, $m)) {
            $out[] = array('to' => $m[1], 'subject' => $m[2], 'body' => $m[3]);
        }
    }
    return $out;
}

/** The single results row for one invite call. */
function one($r) {
    return (!empty($r['ok']) && count($r['results']) === 1) ? $r['results'][0] : array();
}

$stamp  = date('YmdHis') . '-' . mt_rand(1000, 9999);
$idMain = "invmail-main-$stamp";
$idBare = "invmail-bare-$stamp";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

try {
    echo "=== fixtures ===\n";
    $r = $svc->createSample(array('id' => $idMain, 'name' => 'InvMail Sample', 'display_sample_type' => 'Intact Sample'));
    check('main sample created', !empty($r['ok']));
    $r = $svc->createSample(array('id' => $idBare, 'name' => 'InvMail Bare'));
    check('sample without a type created', !empty($r['ok']));
    newMail();

    echo "\n=== fresh invite (edit) ===\n";
    $res = one($svc->inviteCollaborators($idMain, $ownerPkey, array($users['editor']['email']), 'edit'));
    check("status 'invited'", isset($res['status']) && $res['status'] === 'invited');
    check('emailed = true', isset($res['emailed']) && $res['emailed'] === true);
    $mail = newMail();
    check('exactly one email filed', count($mail) === 1);
    $m = $mail ? $mail[0] : array('to' => '', 'subject' => '', 'body' => '');
    check('sent to the invitee', $m['to'] === $users['editor']['email']);
    check('subject names inviter + sample',
        $m['subject'] === 'Test Owner (owner@test.strabospot.org) invited you to collaborate on sample "InvMail Sample" in StraboSamples');
    check('title', strpos($m['body'], 'You are invited to collaborate on a StraboSamples sample') !== false);
    check('greeting uses first name', strpos($m['body'], "Hi Test,") !== false);
    check('Sample row', strpos($m['body'], 'Sample: InvMail Sample') !== false);
    check('Type row', strpos($m['body'], 'Type: Intact Sample') !== false);
    check('Invited by row', strpos($m['body'], 'Invited by: Test Owner (owner@test.strabospot.org)') !== false);
    check('Access row = Can edit', strpos($m['body'], "Access: Can edit (you can change this sample's details)") !== false);
    check('button links My Samples', strpos($m['body'], 'https://strabospot.org/my_samples') !== false);
    check('footer names the invited account',
        strpos($m['body'], 'invited the StraboSpot account ' . $users['editor']['email'] . ' to a sample') !== false);

    echo "\n=== fresh invite (readonly), sample without a type ===\n";
    $res = one($svc->inviteCollaborators($idBare, $ownerPkey, array($users['readonly']['email']), 'readonly'));
    check("status 'invited' + emailed", isset($res['status']) && $res['status'] === 'invited' && ($res['emailed'] ?? null) === true);
    $mail = newMail();
    $m = $mail ? $mail[0] : array('body' => '');
    check('one email', count($mail) === 1);
    check('Access row = Read-only', strpos($m['body'], 'Access: Read-only (you can view this sample)') !== false);
    check('no Type row', strpos($m['body'], 'Type:') === false);

    echo "\n=== no email: already active, owner, unknown ===\n";
    $r = $svc->inviteCollaborators($idMain, $ownerPkey,
        array($users['editor']['email'], $users['owner']['email'], 'nobody-' . $stamp . '@test.strabospot.org'), 'edit');
    $st = array();
    foreach ($r['results'] as $row) $st[$row['status']] = $row;
    check('statuses already_active, is_owner, unknown',
        isset($st['already_active'], $st['is_owner'], $st['unknown']));
    check('none carry an emailed key',
        !array_key_exists('emailed', $st['already_active'] ?? array())
        && !array_key_exists('emailed', $st['is_owner'] ?? array())
        && !array_key_exists('emailed', $st['unknown'] ?? array()));
    check('no email filed', count(newMail()) === 0);

    echo "\n=== several at once: one email each ===\n";
    $r = $svc->inviteCollaborators($idMain, $ownerPkey,
        array($users['readonly']['email'], $users['outsider']['email']), 'readonly');
    $ok = !empty($r['ok']) && count($r['results']) === 2;
    foreach ($r['results'] as $row) $ok = $ok && $row['status'] === 'invited' && $row['emailed'] === true;
    check('both invited + emailed', $ok);
    $to = array_map(function ($x) { return $x['to']; }, newMail());
    sort($to);
    check('one email to each', $to === array($users['outsider']['email'], $users['readonly']['email']));

    echo "\n=== re-invite after removal ===\n";
    $r = $svc->removeCollaborator($idMain, $ownerPkey, $users['outsider']['pkey']);
    check('outsider removed', !empty($r['ok']));
    newMail();
    $res = one($svc->inviteCollaborators($idMain, $ownerPkey, array($users['outsider']['email']), 'edit'));
    check("status 're_enabled'", isset($res['status']) && $res['status'] === 're_enabled');
    check('emailed = true', ($res['emailed'] ?? null) === true);
    $mail = newMail();
    check('one email, new level in it',
        count($mail) === 1 && $mail[0]['to'] === $users['outsider']['email']
        && strpos($mail[0]['body'], 'Access: Can edit') !== false);
} finally {
    $svc->setUserpkey($ownerPkey);
    foreach (array($idMain, $idBare) as $sid) {
        $svc->deleteSample($sid, $ownerPkey);
    }
}

echo "\n";
if ($failures) {
    echo 'RESULT: ' . count($failures) . " FAILED\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "RESULT: all checks PASS\n";
