<?php
/**
 * File: micro_collaborators_action.php
 * Description: The owner's changes from micro_collaborators.php (POST: pid,
 *              csrf, action): invite pasted addresses, change a role, remove
 *              someone or withdraw an invitation (MsMembers, shared with the
 *              app); make or revoke the join link and answer the people
 *              waiting (MsJoin). The result is shown once on the page.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once(__DIR__ . '/microdb/lib/micro_join_web.php');

$pid = isset($_POST['pid']) ? (int)$_POST['pid'] : 0;
$back = $pid > 0 ? '/micro_collaborators?project_id=' . $pid : '/my_micro_data';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: ' . $back);
	exit();
}

// Longest paste handled at once: each address sends its email during the request
const MICRO_COLLAB_MAX_PASTE = 100;

$me = (int)$userpkey;
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$result = array('pid' => $pid, 'ok' => false, 'text' => 'This request could not be handled.');

if (!micro_collab_token_ok(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
	$result['text'] = 'This page was open too long. Nothing was changed; please try again.';
} else {
	$msdb = micro_members_db($db);
	try {
		if ($action === 'invite') {
			$result = micro_collab_invite($msdb, $me, $pid, isset($_POST['emails']) ? (string)$_POST['emails'] : '', isset($_POST['role']) ? (string)$_POST['role'] : '');
		} elseif ($action === 'role') {
			$m = MsMembers::changeRoleFor($msdb, $me, $pid, isset($_POST['pkey']) ? (int)$_POST['pkey'] : 0, isset($_POST['role']) ? (string)$_POST['role'] : '');
			$result = array('pid' => $pid, 'ok' => true, 'text' => $m['user']['name'] . ' is now ' . micro_role_label($m['role']) . '.');
		} elseif ($action === 'remove') {
			MsMembers::removeFor($msdb, $me, $pid, isset($_POST['pkey']) ? (int)$_POST['pkey'] : 0);
			$result = array('pid' => $pid, 'ok' => true, 'text' => 'Removed.');
		} elseif ($action === 'create_link') {
			MsJoin::createLink($msdb, $me, $pid, isset($_POST['role']) ? (string)$_POST['role'] : '', isset($_POST['days']) ? (int)$_POST['days'] : 0);
			$result = array('pid' => $pid, 'ok' => true, 'text' => 'Join link created.');
		} elseif ($action === 'revoke_link') {
			MsJoin::revokeLink($msdb, $me, $pid);
			$result = array('pid' => $pid, 'ok' => true, 'text' => 'The join link no longer works. Anyone who already joined stays until you remove them.');
		} elseif ($action === 'approve') {
			MsJoin::approve($msdb, $me, $pid, isset($_POST['pkey']) ? (int)$_POST['pkey'] : 0);
			$result = array('pid' => $pid, 'ok' => true, 'text' => 'Approved.');
		} elseif ($action === 'deny') {
			MsJoin::deny($msdb, $me, $pid, isset($_POST['pkey']) ? (int)$_POST['pkey'] : 0);
			$result = array('pid' => $pid, 'ok' => true, 'text' => 'Request denied.');
		} elseif ($action === 'approve_all') {
			set_time_limit(0); // each approval sends its email during the request
			$all = MsJoin::approveAll($msdb, $me, $pid);
			$text = 'Approved ' . $all['approved'] . ($all['approved'] === 1 ? ' person.' : ' people.');
			if (count($all['refused']) > 0) {
				$text .= ' Could not approve: ' . implode(' ', $all['refused']);
			}
			$result = array('pid' => $pid, 'ok' => count($all['refused']) === 0, 'text' => $text);
		}
	} catch (MsHttpError $e) {
		$result = array('pid' => $pid, 'ok' => false, 'text' => $e->getMessage());
	}
}

$_SESSION['micro_collab_result'] = $result;
header('Location: ' . $back);
exit();

/**
 * Invite each pasted address on its own, so one refusal does not stop the
 * rest, and report what happened to each.
 */
function micro_collab_invite($msdb, $me, $pid, $text, $role) {
	$parsed = MsMembers::parseEmailList($text);
	if (count($parsed['valid']) === 0 && count($parsed['invalid']) === 0) {
		return array('pid' => $pid, 'ok' => false, 'text' => 'No email addresses were found.');
	}
	if (count($parsed['valid']) > MICRO_COLLAB_MAX_PASTE) {
		return array('pid' => $pid, 'ok' => false, 'text' => 'That is ' . count($parsed['valid']) . ' addresses. Please paste at most ' . MICRO_COLLAB_MAX_PASTE . ' at a time. Nothing was sent.');
	}
	set_time_limit(0);
	$report = array('invited' => array(), 'again' => array(), 'not_emailed' => array(), 'member' => array(), 'no_account' => array(), 'refused' => array());
	foreach ($parsed['invalid'] as $bad) {
		$report['refused'][] = array($bad, 'This is not a valid email address.');
	}
	foreach ($parsed['valid'] as $email) {
		try {
			$r = MsMembers::inviteFor($msdb, $me, $pid, $email, $role);
			$report[$r['status'] === 'reinvited' ? 'again' : 'invited'][] = $email;
			if (!$r['emailed']) {
				$report['not_emailed'][] = $email;
			}
		} catch (MsHttpError $e) {
			if ($e->errorCode === 'no_account') {
				$report['no_account'][] = $email;
			} elseif ($e->errorCode === 'already_member') {
				$report['member'][] = $email;
			} elseif ($e->errorCode === 'forbidden' || $e->errorCode === 'not_ready' || $e->errorCode === 'bad_request' && !in_array($role, MsMembers::ASSIGNABLE, true)) {
				throw $e; // about the project or the form, the same for every address
			} else {
				$report['refused'][] = array($email, $e->getMessage());
			}
		}
	}
	return array('pid' => $pid, 'ok' => true, 'report' => $report);
}
