<?php
/**
 * File: sesar_push.php
 * Description: JSON endpoint behind "Send to SESAR" (Phase 6: D6 + the P1-P4
 *              review). POST, JSON body {action, ...}; session-authenticated
 *              and gated by SesarAccess::canUse. Bulk runs are driven by the
 *              browser one sample per call, as minting (D4); SesarPush
 *              enforces every rule again.
 *
 *              Actions:
 *                status   {sample_id}                -> {ok, status}   (no SESAR call)
 *                preview  {sample_id}                -> {ok, preview}
 *                apply    {sample_id, mode, seen{}}  -> {ok, result}
 *              Errors: {ok:false, error, message, fields}
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
require_once __DIR__ . '/includes/sesar/SesarPush.php';
SesarEndpoint::gate($userpkey);

$client = new SesarClient();
$conn = new SesarConnection($db, $client);
$views = new SesarSampleView($db, $neodb);
$push = new SesarPush($db, $client, $conn, $views,
	new SesarMint($db, $client, $conn, new SesarVocab($db, $client), $views, SesarMint::serviceSpineWriter($db, $neodb)),
	new SesarPull($db, $client, $conn, $views, $neodb));
$str = SesarEndpoint::reader($input);

try {
	switch ($input['action']) {
		case 'status':
			SesarEndpoint::out(200, array('ok' => true, 'status' => $push->status($userpkey, $str('sample_id'))));
			break;
		case 'preview':
			SesarEndpoint::out(200, array('ok' => true, 'preview' => $push->preview($userpkey, $str('sample_id'))));
			break;
		case 'apply':
			$res = $push->apply($userpkey, $str('sample_id'), array(
				'mode' => $str('mode'),
				'seen' => isset($input['seen']) && is_array($input['seen']) ? $input['seen'] : array(),
			));
			SesarEndpoint::out(200, array('ok' => true, 'result' => $res));
			break;
		default:
			SesarEndpoint::out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	SesarEndpoint::fail(SesarEndpoint::timeout($e, 'Sending again is safe'), array(403, 404, 409, 410));
}
