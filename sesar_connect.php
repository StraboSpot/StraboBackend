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

require_once __DIR__ . '/includes/sesar/SesarEndpoint.php';
$userpkey = SesarEndpoint::user();
$input = SesarEndpoint::json();

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
require_once __DIR__ . '/includes/sesar/SesarOnboarding.php';
require_once __DIR__ . '/includes/sesar/SesarOrcid.php';
SesarEndpoint::gate($userpkey, 'SESAR connections are not available for this account.');

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
				SesarEndpoint::out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => 'Not available here.'));
			}
			$identity = (new SesarOrcid())->exchangeCode(isset($input['code']) ? $input['code'] : '');
			$status = $onboarding->acceptOrcid($userpkey, $identity);
			break;
		default:
			SesarEndpoint::out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	SesarEndpoint::fail($e, array(), array('status' => $onboarding->status($userpkey)));
}

$status['dev_code_paste'] = SesarAccess::devCodePaste();
SesarEndpoint::out(200, array('ok' => true, 'status' => $status));
