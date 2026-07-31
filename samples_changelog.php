<?php
/**
 * File: samples_changelog.php
 * Description: Session-authed JSON proxy for the per-sample changelog
 *              viewer in samples_detail.php. Same dispatch pattern as
 *              samples_collab.php / samples_invitations.php.
 *
 *              Action:
 *                list — {sample_id, owner_pkey, limit?, offset?}
 *                       Returns the underlying listChangelog wrapper
 *                       (changelog/count/total/limit/offset) with each
 *                       row enriched with the actor's display fields
 *                       (changed_by → name/email/initials) via one
 *                       batched users lookup.
 *
 *              The documented /samplesdb/sample/{id}/changelog endpoint
 *              is HTTP Basic / JWT only; this is the session-cookie
 *              entry point for the Phase 4 in-page viewer.
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

switch ($action) {

    case 'list':
        $sampleId  = isset($input['sample_id'])  ? (string)$input['sample_id']  : '';
        $ownerPkey = isset($input['owner_pkey']) ? (int)$input['owner_pkey']    : 0;
        if ($sampleId === '' || $ownerPkey <= 0) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'missing_required_fields'));
            exit;
        }
        // Pass through pagination; the service clamps limit/offset to its
        // own bounds (default 50, max 500) so we don't double-validate here.
        $limit  = array_key_exists('limit',  $input) ? (int)$input['limit']  : null;
        $offset = array_key_exists('offset', $input) ? (int)$input['offset'] : null;

        $result = $svc->listChangelog($sampleId, $ownerPkey, $limit, $offset);
        if ($result === null) {
            // canRead failed → sample missing or not visible to this user.
            // Same status as the documented controller's 404.
            http_response_code(404);
            echo json_encode(array('ok' => false, 'error' => 'not_found'));
            exit;
        }

        // Enrich each row with the actor's display info (changed_by → users).
        // Single batched lookup keyed on the distinct pkeys we just got back.
        $pkeys = array();
        foreach ($result['changelog'] as $row) {
            $pkeys[(int)$row['changed_by']] = true;
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
        foreach ($result['changelog'] as &$row) {
            $u = isset($userInfo[(int)$row['changed_by']]) ? $userInfo[(int)$row['changed_by']] : null;
            if ($u) {
                $name = trim(($u->firstname ?: '') . ' ' . ($u->lastname ?: ''));
                $row['actor_name']     = $name !== '' ? $name : $u->email;
                $row['actor_email']    = $u->email;
                $row['actor_initials'] = strtoupper(
                    substr($u->firstname ?: $u->email, 0, 1) .
                    substr($u->lastname  ?: '', 0, 1)
                );
            } else {
                // User row missing (deleted account etc.) — graceful fallback.
                $row['actor_name']     = '(unknown user)';
                $row['actor_email']    = '';
                $row['actor_initials'] = '?';
            }
        }
        unset($row);

        echo json_encode(array(
            'ok'        => true,
            'changelog' => $result['changelog'],
            'count'     => $result['count'],
            'total'     => $result['total'],
            'limit'     => $result['limit'],
            'offset'    => $result['offset'],
        ));
        exit;

    default:
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'invalid_action'));
        exit;
}
