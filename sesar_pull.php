<?php
/**
 * File: sesar_pull.php
 * Description: JSON endpoint behind "Pull from SESAR" (Phase 5: D5). POST,
 *              JSON body {action, ...}; session-authenticated and gated by
 *              SesarAccess::canUse. Bulk runs are driven by the browser one
 *              sample (or IGSN) per call, as minting (D4); SesarPull enforces
 *              every rule again.
 *
 *              Actions:
 *                preview      {sample_id}                     -> {ok, preview}
 *                apply        {sample_id, mode, accept[], seen{}, parent}
 *                                                             -> {ok, result}
 *                create_plan  {igsns: [...]}                  -> {ok, plan}
 *                create       {igsn}                          -> {ok, result}
 *                import_page  {page, search}                  -> {ok, page}
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

function sesar_pull_out($code, $payload)
{
	http_response_code($code);
	echo json_encode($payload);
	exit;
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT)) {
	$_SESSION['loggedin'] = 'no';
}
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey'])) {
	sesar_pull_out(401, array('ok' => false, 'error' => 'not_authenticated', 'message' => 'Your session has ended. Please log in again.'));
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();   // SESAR calls can take seconds; do not hold the session lock

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sesar_pull_out(405, array('ok' => false, 'error' => 'method', 'message' => 'POST only.'));
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['action']) || !is_string($input['action'])) {
	sesar_pull_out(400, array('ok' => false, 'error' => 'invalid_json', 'message' => 'Bad request.'));
}

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
include __DIR__ . '/neodb.php';
require_once __DIR__ . '/includes/sesar/SesarPull.php';

if (!SesarAccess::canUse($userpkey)) {
	sesar_pull_out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => 'SESAR features are not available for this account.'));
}
if (!SesarAccess::isConfigured()) {
	sesar_pull_out(503, array('ok' => false, 'error' => 'not_configured', 'message' => 'SESAR is not configured on this server yet.'));
}

$client = new SesarClient();
$pull = new SesarPull($db, $client, new SesarConnection($db, $client), new SesarSampleView($db, $neodb), $neodb);
$str = function ($k) use ($input) { return isset($input[$k]) && is_scalar($input[$k]) ? (string)$input[$k] : ''; };

try {
	switch ($input['action']) {
		case 'preview':
			sesar_pull_out(200, array('ok' => true, 'preview' => $pull->preview($userpkey, $str('sample_id'))));
			break;
		case 'apply':
			$res = $pull->apply($userpkey, $str('sample_id'), array(
				'mode'   => $str('mode'),
				'accept' => isset($input['accept']) && is_array($input['accept']) ? array_values(array_filter($input['accept'], 'is_string')) : array(),
				'seen'   => isset($input['seen']) && is_array($input['seen']) ? $input['seen'] : array(),
				'parent' => !empty($input['parent']),
			));
			sesar_pull_out(200, array('ok' => true, 'result' => $res));
			break;
		case 'create_plan':
			$igsns = isset($input['igsns']) && is_array($input['igsns']) ? array_values(array_filter($input['igsns'], 'is_scalar')) : array();
			if (empty($igsns)) sesar_pull_out(400, array('ok' => false, 'error' => 'validation', 'message' => 'Enter at least one IGSN.'));
			sesar_pull_out(200, array('ok' => true, 'plan' => $pull->createPlan($userpkey, $igsns)));
			break;
		case 'create':
			sesar_pull_out(200, array('ok' => true, 'result' => $pull->createOne($userpkey, $str('igsn'))));
			break;
		case 'import_page':
			sesar_pull_out(200, array('ok' => true, 'page' => $pull->importPage($userpkey, max(1, (int)$str('page')), $str('search'))));
			break;
		default:
			sesar_pull_out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	$code = ($e->kind === 'validation') ? 400 : (($e->kind === 'network') ? 504 : (in_array($e->status, array(403, 404, 409, 410), true) ? $e->status : 502));
	if ($e->kind === 'auth' && $e->status !== 403) $code = 409;
	if ($e->kind === 'no_permission' || $e->kind === 'no_account') $code = 409;
	if (in_array($e->status, array(0, 503, 504), true)) {   // the sandbox list can hit its 60 s gateway limit
		$e = new SesarError($e->status, 'SESAR did not answer in time. Please try again in a moment.', $e->errors);
	}
	sesar_pull_out($code, array('ok' => false, 'error' => $e->kind, 'message' => $e->getMessage(), 'fields' => $e->errors));
}
