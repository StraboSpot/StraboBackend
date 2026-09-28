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

require_once __DIR__ . '/includes/sesar/SesarEndpoint.php';
$userpkey = SesarEndpoint::user();
$input = SesarEndpoint::json();

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
include __DIR__ . '/neodb.php';
require_once __DIR__ . '/includes/sesar/SesarMint.php';
SesarEndpoint::gate($userpkey, 'IGSN registration is not available for this account.');

$client = new SesarClient();
$mint = new SesarMint($db, $client, new SesarConnection($db, $client), new SesarVocab($db, $client),
	new SesarSampleView($db, $neodb), SesarMint::serviceSpineWriter($db, $neodb));

try {
	switch ($input['action']) {
		case 'plan':
			$ids = isset($input['sample_ids']) && is_array($input['sample_ids']) ? $input['sample_ids'] : array();
			$ids = array_values(array_filter($ids, 'is_scalar'));
			if (empty($ids)) SesarEndpoint::out(400, array('ok' => false, 'error' => 'validation', 'message' => 'Choose at least one sample.'));
			SesarEndpoint::out(200, array('ok' => true, 'plan' => $mint->plan($userpkey, $ids)));
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
			SesarEndpoint::out(200, array('ok' => true, 'result' => $res));
			break;
		default:
			SesarEndpoint::out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	SesarEndpoint::fail($e, array(404, 409));
}
