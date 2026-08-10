<?php
/**
 * File: samples_create.php
 * Description: Session-authed JSON proxy that forwards to
 *              StraboSamplesService::createSample().
 *
 *              The documented /samplesdb/ API requires HTTP Basic or
 *              JWT — neither is available to a session-authed web page.
 *              Rather than introduce a third auth path on the API,
 *              this thin proxy authenticates against $_SESSION (returns
 *              JSON 401 instead of redirecting to /login.php so the
 *              calling fetch can surface the error) and then calls the
 *              service directly with the session userpkey.
 *
 *              Same pattern as my_samples.php's server-side read path —
 *              no new auth model, just a write entry point.
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

// Identity/ownership are session-derived, never client-supplied.
unset($input['userpkey'], $input['created_by'], $input['modified_by']);

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);
$result = $svc->createSample($input);

if (!empty($result['ok'])) {
    http_response_code(201);
} else {
    $err = isset($result['error']) ? $result['error'] : 'unknown';
    switch ($err) {
        case 'duplicate_id':          http_response_code(409); break;
        case 'parent_not_accessible': http_response_code(404); break;  // existence-hiding: unreadable parent = missing
        case 'parent_pair_required':  http_response_code(400); break;
        case 'not_authenticated':     http_response_code(401); break;
        default:                      http_response_code(500);
    }
}

echo json_encode($result);
