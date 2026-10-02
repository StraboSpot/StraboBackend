<?php
/**
 * File: micro_invitation.php
 * Description: Accept or decline a StraboMicro collaboration invitation
 *              from My StraboMicro Data (POST: pid, token, action). The
 *              invitation token from the page is the form's secret, as the
 *              uuid is for Field invitations. The result is shown once on
 *              My StraboMicro Data.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once(__DIR__ . '/microdb/lib/micro_members_web.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: /my_micro_data');
	exit();
}

$pid = isset($_POST['pid']) ? (int)$_POST['pid'] : 0;
$token = isset($_POST['token']) ? (string)$_POST['token'] : '';
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

try {
	if ($pid <= 0 || $token === '' || !in_array($action, array('accept', 'decline'), true)) {
		throw new MsHttpError(400, 'bad_request', 'This invitation link is not valid.');
	}
	$msdb = micro_members_db($db);
	if ($action === 'accept') {
		$r = MsMembers::acceptInviteFor($msdb, (int)$userpkey, $pid, $token);
		$_SESSION['micro_invite_result'] = array('ok' => true,
			'text' => 'You joined "' . $r['name'] . '" as ' . micro_role_label($r['role'])
				. '. Open StraboMicro and log in to download it (File > Open Remote Project).');
	} else {
		$r = MsMembers::declineInviteFor($msdb, (int)$userpkey, $pid, $token);
		$_SESSION['micro_invite_result'] = array('ok' => true, 'text' => 'You declined the invitation to "' . $r['name'] . '".');
	}
} catch (MsHttpError $e) {
	$_SESSION['micro_invite_result'] = array('ok' => false,
		'text' => $e->errorCode === 'not_found' ? 'This invitation is no longer open.' : $e->getMessage());
}

header('Location: /my_micro_data');
exit();
