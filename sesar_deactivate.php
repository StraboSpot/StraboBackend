<?php
/**
 * File: sesar_deactivate.php
 * Description: JSON endpoint behind "Request deactivation" (Phase 7: D7 + the
 *              Q1-Q3 review). POST, JSON body {action, ...}; session-
 *              authenticated and gated by SesarAccess::canUse; SesarDeactivate
 *              enforces every rule (owner's own rows, managed only, typed-IGSN
 *              confirmation, SESAR's reason rules).
 *
 *              A target is {sample_id} or {reg} (an orphan row: its sample was
 *              deleted).
 *              Actions:
 *                preview  {target}                          -> {ok, preview}   (writes nothing)
 *                mark     {target}                          -> {ok, result}    ("Show as requested")
 *                release  {target}                          -> {ok, result}    ("Show as active again")
 *                request  {target, reason, detail, confirm} -> {ok, result}
 *                check    {target}                          -> {ok, result}
 *                keep     {reg}                             -> {ok, result}
 *              Errors: {ok:false, error, message, fields}
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

function sesar_deact_out($code, $payload)
{
	http_response_code($code);
	echo json_encode($payload);
	exit;
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT)) {
	$_SESSION['loggedin'] = 'no';
}
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey'])) {
	sesar_deact_out(401, array('ok' => false, 'error' => 'not_authenticated', 'message' => 'Your session has ended. Please log in again.'));
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();   // SESAR calls can take seconds; do not hold the session lock

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sesar_deact_out(405, array('ok' => false, 'error' => 'method', 'message' => 'POST only.'));
}
// JSON only: a form on another site cannot send this content type, so it
// cannot act for a logged-in user (the page scripts always send it).
$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim(explode(';', (string)$_SERVER['CONTENT_TYPE'])[0])) : '';
if ($ctype !== 'application/json') {
	sesar_deact_out(415, array('ok' => false, 'error' => 'content_type', 'message' => 'Bad request.'));
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['action']) || !is_string($input['action'])) {
	sesar_deact_out(400, array('ok' => false, 'error' => 'invalid_json', 'message' => 'Bad request.'));
}

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
include __DIR__ . '/neodb.php';
require_once __DIR__ . '/includes/sesar/SesarDeactivate.php';

if (!SesarAccess::canUse($userpkey)) {
	sesar_deact_out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => 'SESAR features are not available for this account.'));
}
if (!SesarAccess::isConfigured()) {
	sesar_deact_out(503, array('ok' => false, 'error' => 'not_configured', 'message' => 'SESAR is not configured on this server yet.'));
}

$client = new SesarClient();
$deact = new SesarDeactivate($db, $client, new SesarConnection($db, $client), SesarDeactivate::serviceIgsnClearer($db, $neodb));
$str = function ($k) use ($input) { return isset($input[$k]) && is_scalar($input[$k]) ? (string)$input[$k] : ''; };
$target = ($str('reg') !== '') ? array('reg' => (int)$str('reg')) : array('sample_id' => $str('sample_id'));

try {
	switch ($input['action']) {
		case 'preview':
			sesar_deact_out(200, array('ok' => true, 'preview' => $deact->preview($userpkey, $target)));
			break;
		case 'request':
			sesar_deact_out(200, array('ok' => true, 'result' => $deact->request($userpkey, $target, array(
				'reason' => $str('reason'), 'detail' => $str('detail'), 'confirm' => $str('confirm')))));
			break;
		case 'mark':
			sesar_deact_out(200, array('ok' => true, 'result' => $deact->markPending($userpkey, $target)));
			break;
		case 'release':
			sesar_deact_out(200, array('ok' => true, 'result' => $deact->release($userpkey, $target)));
			break;
		case 'check':
			sesar_deact_out(200, array('ok' => true, 'result' => $deact->check($userpkey, $target)));
			break;
		case 'keep':
			sesar_deact_out(200, array('ok' => true, 'result' => $deact->keepOrphan($userpkey, (int)$str('reg'))));
			break;
		default:
			sesar_deact_out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	$code = ($e->kind === 'validation') ? 400 : (($e->kind === 'network') ? 504 : (in_array($e->status, array(403, 404, 409, 410), true) ? $e->status : 502));
	if ($e->kind === 'auth') $code = 409;
	if ($e->kind === 'no_permission' || $e->kind === 'no_account') $code = 409;
	if (in_array($e->status, array(0, 503, 504), true) && stripos($e->getMessage(), 'did not confirm') === false) {
		$e = new SesarError($e->status, 'SESAR did not answer in time. Please try again in a moment.', $e->errors);
	}
	sesar_deact_out($code, array('ok' => false, 'error' => $e->kind, 'message' => $e->getMessage(), 'fields' => $e->errors));
}
