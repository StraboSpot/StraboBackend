<?php
/**
 * File: samples_collab.php
 * Description: Session-authed JSON proxy for sample-collaborator
 *              management (list / invite / update_level / remove).
 *
 *              Single dispatch over StraboSamplesService methods —
 *              collaborator ops are a small tightly-related cluster
 *              that's clumsier as four separate proxy files. Same
 *              session-auth + JSON-401 pattern as samples_create.php.
 *
 *              The documented /samplesdb/sample/{id}/collaborators
 *              endpoints are HTTP Basic / JWT only; this is the
 *              session-cookie entry point for the Phase 4 management
 *              modal in samples_detail.php.
 *
 *              For the list action, listCollaborators returns owner-
 *              visible grants (accepted + pending) but no user info —
 *              this proxy enriches each row with name/email/initials
 *              via one batched users lookup so the modal doesn't have
 *              to round-trip per row.
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

$sampleId  = isset($input['sample_id'])  ? (string)$input['sample_id']  : '';
$ownerPkey = isset($input['owner_pkey']) ? (int)$input['owner_pkey']    : 0;
$action    = isset($input['action'])     ? (string)$input['action']     : '';

if ($sampleId === '' || $ownerPkey <= 0 || $action === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'missing_required_fields'));
    exit;
}

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);

function mapSampleCollabStatusCode($result) {
    $err = isset($result['error']) ? $result['error'] : 'unknown';
    switch ($err) {
        case 'not_found':                return 404;
        case 'grant_not_found':          return 404;
        case 'forbidden':                return 403;
        case 'invalid_permission_level': return 400;
        case 'no_emails':                return 400;
        case 'invalid_action':           return 400;
        case 'not_authenticated':        return 401;
        default:                          return 500;
    }
}

switch ($action) {

    case 'list':
        $list = $svc->listCollaborators($sampleId, $ownerPkey);
        if ($list === null) {
            // canRead failed → either sample doesn't exist or caller is
            // unauthorized. Surface as forbidden; the modal is owner-only
            // and reaching this means something's off with the session.
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'forbidden'));
            exit;
        }
        // Enrich with display fields via one batched users lookup.
        $pkeys = array();
        foreach ($list as $entry) {
            $pkeys[] = (int)$entry['collaborator_pkey'];
        }
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
        foreach ($list as &$entry) {
            $u = isset($userInfo[$entry['collaborator_pkey']]) ? $userInfo[$entry['collaborator_pkey']] : null;
            if ($u) {
                $name = trim(($u->firstname ?: '') . ' ' . ($u->lastname ?: ''));
                $entry['name']     = $name !== '' ? $name : $u->email;
                $entry['email']    = $u->email;
                $entry['initials'] = strtoupper(
                    substr($u->firstname ?: $u->email, 0, 1) .
                    substr($u->lastname ?: '', 0, 1)
                );
            } else {
                // User row missing (deleted account or similar) — fall back.
                $entry['name']     = '(unknown user)';
                $entry['email']    = '';
                $entry['initials'] = '?';
            }
        }
        unset($entry);
        echo json_encode(array('ok' => true, 'collaborators' => $list));
        exit;

    case 'invite':
        $emails = isset($input['emails']) ? (array)$input['emails'] : array();
        $level  = isset($input['permission_level']) ? $input['permission_level'] : '';
        $result = $svc->inviteCollaborators($sampleId, $ownerPkey, $emails, $level);
        if (!empty($result['ok'])) {
            http_response_code(200);
        } else {
            http_response_code(mapSampleCollabStatusCode($result));
        }
        echo json_encode($result);
        exit;

    case 'update_level':
        $cpkey = isset($input['collaborator_pkey']) ? (int)$input['collaborator_pkey'] : 0;
        $level = isset($input['permission_level']) ? $input['permission_level'] : '';
        if ($cpkey <= 0) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'missing_collaborator_pkey'));
            exit;
        }
        $result = $svc->updateCollaboratorLevel($sampleId, $ownerPkey, $cpkey, $level);
        if (!empty($result['ok'])) {
            http_response_code(200);
        } else {
            http_response_code(mapSampleCollabStatusCode($result));
        }
        echo json_encode($result);
        exit;

    case 'remove':
        $cpkey = isset($input['collaborator_pkey']) ? (int)$input['collaborator_pkey'] : 0;
        if ($cpkey <= 0) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'missing_collaborator_pkey'));
            exit;
        }
        $result = $svc->removeCollaborator($sampleId, $ownerPkey, $cpkey);
        if (!empty($result['ok'])) {
            http_response_code(200);
        } else {
            http_response_code(mapSampleCollabStatusCode($result));
        }
        echo json_encode($result);
        exit;

    default:
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'invalid_action'));
        exit;
}
