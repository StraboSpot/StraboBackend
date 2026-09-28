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

require_once __DIR__ . '/includes/sesar/SesarEndpoint.php';
$userpkey = SesarEndpoint::user();
$input = SesarEndpoint::json();

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
include __DIR__ . '/neodb.php';
require_once __DIR__ . '/includes/sesar/SesarDeactivate.php';
SesarEndpoint::gate($userpkey);

$client = new SesarClient();
$deact = new SesarDeactivate($db, $client, new SesarConnection($db, $client), SesarDeactivate::serviceIgsnClearer($db, $neodb));
$str = SesarEndpoint::reader($input);
$target = ($str('reg') !== '') ? array('reg' => (int)$str('reg')) : array('sample_id' => $str('sample_id'));

try {
	switch ($input['action']) {
		case 'preview':
			SesarEndpoint::out(200, array('ok' => true, 'preview' => $deact->preview($userpkey, $target)));
			break;
		case 'request':
			SesarEndpoint::out(200, array('ok' => true, 'result' => $deact->request($userpkey, $target, array(
				'reason' => $str('reason'), 'detail' => $str('detail'), 'confirm' => $str('confirm')))));
			break;
		case 'mark':
			SesarEndpoint::out(200, array('ok' => true, 'result' => $deact->markPending($userpkey, $target)));
			break;
		case 'release':
			SesarEndpoint::out(200, array('ok' => true, 'result' => $deact->release($userpkey, $target)));
			break;
		case 'check':
			SesarEndpoint::out(200, array('ok' => true, 'result' => $deact->check($userpkey, $target)));
			break;
		case 'keep':
			SesarEndpoint::out(200, array('ok' => true, 'result' => $deact->keepOrphan($userpkey, (int)$str('reg'))));
			break;
		default:
			SesarEndpoint::out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	SesarEndpoint::fail(SesarEndpoint::timeout($e, 'did not confirm'), array(403, 404, 409, 410));
}
