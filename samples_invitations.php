<?php
/**
 * File: samples_invitations.php
 * Description: Session-authed JSON proxy for the invitee-side of
 *              sample-collaboration. Same dispatch pattern as
 *              samples_collab.php; same session-auth + JSON-401 trick.
 *
 *              Actions:
 *                list   — enriched pending invitations for the caller
 *                accept — {sample_id, uuid}
 *                deny   — {sample_id, uuid}
 *
 *              listMyInvitations returns rows with sample_name + type
 *              but no inviter info; this proxy enriches each row with
 *              added_by's name / email / initials via one batched users
 *              lookup so the banner doesn't round-trip per invitation.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();

header('Content-Type: application/json');

if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey'])) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'not_authenticated'));
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];

include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.inc.php';
include       $_SERVER['DOCUMENT_ROOT'] . '/db.php';
include       $_SERVER['DOCUMENT_ROOT'] . '/neodb.php';
require_once  $_SERVER['DOCUMENT_ROOT'] . '/samplesdb/services/StraboSamplesService.php';

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'invalid_json'));
    exit;
}

$action = isset($input['action']) ? (string)$input['action'] : '';
if ($action === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'missing_action'));
    exit;
}

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);

function mapInvitationsStatusCode($err) {
    switch ($err) {
        case 'invitation_not_found':  return 404;
        case 'already_accepted':      return 409;
        case 'duplicate_id_conflict': return 409;
        case 'missing_uuid':          return 400;
        case 'missing_sample_id':     return 400;
        default:                       return 500;
    }
}

switch ($action) {

    case 'list':
        $invites = $svc->listMyInvitations();
        // Enrich each row with the inviter's display info (added_by → users).
        $pkeys = array();
        foreach ($invites as $i) {
            $pkeys[(int)$i['added_by']] = true;
        }
        $pkeys = array_keys($pkeys);
        $userInfo = array();
        if (!empty($pkeys)) {
            $placeholders = array();
            for ($i = 0; $i < count($pkeys); $i++) {
                $placeholders[] = '$' . ($i + 1);
            }
            $userRows = $db->get_results_prepared(
                "SELECT pkey, firstname, lastname, email FROM users WHERE pkey IN (" . implode(',', $placeholders) . ")",
                $pkeys
            );
            if (is_array($userRows)) {
                foreach ($userRows as $u) {
                    $userInfo[(int)$u->pkey] = $u;
                }
            }
        }
        foreach ($invites as &$inv) {
            $u = isset($userInfo[(int)$inv['added_by']]) ? $userInfo[(int)$inv['added_by']] : null;
            if ($u) {
                $name = trim(($u->firstname ?: '') . ' ' . ($u->lastname ?: ''));
                $inv['inviter_name']     = $name !== '' ? $name : $u->email;
                $inv['inviter_email']    = $u->email;
                $inv['inviter_initials'] = strtoupper(
                    substr($u->firstname ?: $u->email, 0, 1) .
                    substr($u->lastname  ?: '', 0, 1)
                );
            } else {
                $inv['inviter_name']     = '(unknown user)';
                $inv['inviter_email']    = '';
                $inv['inviter_initials'] = '?';
            }
        }
        unset($inv);
        echo json_encode(array('ok' => true, 'invitations' => $invites));
        exit;

    case 'accept':
    case 'deny':
        $sampleId = isset($input['sample_id']) ? (string)$input['sample_id'] : '';
        $uuid     = isset($input['uuid'])      ? (string)$input['uuid']      : '';
        if ($sampleId === '') {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'missing_sample_id'));
            exit;
        }
        if ($uuid === '') {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'missing_uuid'));
            exit;
        }
        $result = $action === 'accept'
            ? $svc->acceptInvitation($sampleId, $uuid)
            : $svc->denyInvitation($sampleId, $uuid);
        if (!empty($result['ok'])) {
            http_response_code(200);
        } else {
            $err = isset($result['error']) ? $result['error'] : 'unknown';
            http_response_code(mapInvitationsStatusCode($err));
        }
        echo json_encode($result);
        exit;

    default:
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'invalid_action'));
        exit;
}
