<?php
/**
 * File: sesar_connect.php
 * Description: JSON endpoint behind the SESAR connection panel on
 *              samples_igsn.php (D1 + the 2026-09-26 onboarding addition).
 *              POST, JSON body {action, ...}; session-authenticated and gated
 *              by SesarAccess::canUse. Returns {ok, status} where status is
 *              SesarOnboarding::status() (never a token), or {ok:false,
 *              error, message, fields?}.
 *
 *              Actions:
 *                status          where the user stands (no SESAR call)
 *                check           ask SESAR again ("Check again")
 *                request_access  file SESAR's API access request for the user
 *                create_code     create a personal SESAR code (IE + 3 chars)
 *                disconnect      forget the connection and ORCID sign-in
 *                dev_code        DEV ONLY ($sesar_dev_paste_code): a pasted
 *                                ORCID ?code= stands in for the callback
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once __DIR__ . '/includes/session_config.php';
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

function sesar_out($code, $payload)
{
	http_response_code($code);
	echo json_encode($payload);
	exit;
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT)) {
	$_SESSION['loggedin'] = 'no';
}
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey'])) {
	sesar_out(401, array('ok' => false, 'error' => 'not_authenticated', 'message' => 'Your session has ended. Please log in again.'));
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();   // SESAR calls can take seconds; do not hold the session lock

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sesar_out(405, array('ok' => false, 'error' => 'method', 'message' => 'POST only.'));
}
// JSON only: a form on another site cannot send this content type, so it
// cannot act for a logged-in user (the page scripts always send it).
$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim(explode(';', (string)$_SERVER['CONTENT_TYPE'])[0])) : '';
if ($ctype !== 'application/json') {
	sesar_out(415, array('ok' => false, 'error' => 'content_type', 'message' => 'Bad request.'));
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['action']) || !is_string($input['action'])) {
	sesar_out(400, array('ok' => false, 'error' => 'invalid_json', 'message' => 'Bad request.'));
}

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
require_once __DIR__ . '/includes/sesar/SesarOnboarding.php';
require_once __DIR__ . '/includes/sesar/SesarOrcid.php';

if (!SesarAccess::canUse($userpkey)) {
	sesar_out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => 'SESAR connections are not available for this account.'));
}
if (!SesarAccess::isConfigured()) {
	sesar_out(503, array('ok' => false, 'error' => 'not_configured', 'message' => 'SESAR is not configured on this server yet.'));
}

$client = new SesarClient();
$onboarding = new SesarOnboarding($db, $client, new SesarConnection($db, $client));

try {
	switch ($input['action']) {
		case 'status':
			$status = $onboarding->status($userpkey);
			break;
		case 'check':
			$status = $onboarding->check($userpkey);
			break;
		case 'request_access':
			$status = $onboarding->requestAccess($userpkey, isset($input['form']) && is_array($input['form']) ? $input['form'] : array());
			break;
		case 'create_code':
			$status = $onboarding->createCode($userpkey, isset($input['suffix']) ? $input['suffix'] : '');
			break;
		case 'disconnect':
			$status = $onboarding->disconnect($userpkey);
			break;
		case 'dev_code':
			if (!SesarAccess::devCodePaste()) {
				sesar_out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => 'Not available here.'));
			}
			$identity = (new SesarOrcid())->exchangeCode(isset($input['code']) ? $input['code'] : '');
			$status = $onboarding->acceptOrcid($userpkey, $identity);
			break;
		default:
			sesar_out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	$code = ($e->kind === 'validation') ? 400 : (($e->kind === 'network') ? 504 : 502);
	if ($e->kind === 'auth' || $e->kind === 'no_permission' || $e->kind === 'no_account') $code = 409;
	if ($e->kind === 'forbidden') $code = 403;
	sesar_out($code, array('ok' => false, 'error' => $e->kind, 'message' => $e->getMessage(),
		'fields' => $e->errors, 'status' => $onboarding->status($userpkey)));
}

$status['dev_code_paste'] = SesarAccess::devCodePaste();
sesar_out(200, array('ok' => true, 'status' => $status));
