<?php
/**
 * File: sesar_mint.php
 * Description: JSON endpoint behind the IGSN mint review (Phase 4: D2, D3,
 *              D4, D8). POST, JSON body {action, ...}; session-authenticated
 *              and gated by SesarAccess::canUse. The browser drives batches
 *              one sample per call (D4); every rule is enforced here again
 *              (SesarMint).
 *
 *              Actions:
 *                plan  {sample_ids: [...]}  -> {ok, plan}
 *                mint  {sample_id, choices: {sesar_code, object_type,
 *                       material, collector}, replace_existing, expect_parent}
 *                      -> {ok, result} | {ok:false, error, message, fields}
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

function sesar_mint_out($code, $payload)
{
	http_response_code($code);
	echo json_encode($payload);
	exit;
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT)) {
	$_SESSION['loggedin'] = 'no';
}
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey'])) {
	sesar_mint_out(401, array('ok' => false, 'error' => 'not_authenticated', 'message' => 'Your session has ended. Please log in again.'));
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();   // SESAR calls can take seconds; do not hold the session lock

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sesar_mint_out(405, array('ok' => false, 'error' => 'method', 'message' => 'POST only.'));
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['action']) || !is_string($input['action'])) {
	sesar_mint_out(400, array('ok' => false, 'error' => 'invalid_json', 'message' => 'Bad request.'));
}

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
include __DIR__ . '/neodb.php';
require_once __DIR__ . '/includes/sesar/SesarMint.php';

if (!SesarAccess::canUse($userpkey)) {
	sesar_mint_out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => 'IGSN registration is not available for this account.'));
}
if (!SesarAccess::isConfigured()) {
	sesar_mint_out(503, array('ok' => false, 'error' => 'not_configured', 'message' => 'SESAR is not configured on this server yet.'));
}

$client = new SesarClient();
$mint = new SesarMint($db, $client, new SesarConnection($db, $client), new SesarVocab($db, $client),
	new SesarSampleView($db, $neodb), SesarMint::serviceSpineWriter($db, $neodb));

try {
	switch ($input['action']) {
		case 'plan':
			$ids = isset($input['sample_ids']) && is_array($input['sample_ids']) ? $input['sample_ids'] : array();
			$ids = array_values(array_filter($ids, 'is_scalar'));
			if (empty($ids)) sesar_mint_out(400, array('ok' => false, 'error' => 'validation', 'message' => 'Choose at least one sample.'));
			sesar_mint_out(200, array('ok' => true, 'plan' => $mint->plan($userpkey, $ids)));
			break;
		case 'mint':
			$id = isset($input['sample_id']) && is_scalar($input['sample_id']) ? (string)$input['sample_id'] : '';
			$ch = isset($input['choices']) && is_array($input['choices']) ? $input['choices'] : array();
			$res = $mint->mintOne($userpkey, $id, array(
				'sesar_code'       => isset($ch['sesar_code']) ? $ch['sesar_code'] : '',
				'object_type'      => isset($ch['object_type']) ? $ch['object_type'] : '',
				'material'         => isset($ch['material']) ? $ch['material'] : '',
				'collector'        => isset($ch['collector']) ? $ch['collector'] : '',
				'replace_existing' => !empty($input['replace_existing']),
				'expect_parent'    => !empty($input['expect_parent']),
			));
			sesar_mint_out(200, array('ok' => true, 'result' => $res));
			break;
		default:
			sesar_mint_out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	$code = ($e->kind === 'validation') ? 400 : (($e->kind === 'network') ? 504 : ($e->status === 409 || $e->status === 404 ? $e->status : 502));
	if ($e->kind === 'auth' || $e->kind === 'no_permission' || $e->kind === 'no_account') $code = 409;
	sesar_mint_out($code, array('ok' => false, 'error' => $e->kind, 'message' => $e->getMessage(), 'fields' => $e->errors));
}
