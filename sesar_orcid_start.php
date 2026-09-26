<?php
/**
 * File: sesar_orcid_start.php
 * Description: Opens in the "Connect SESAR" popup (samples_igsn.php): binds a
 *              one-time state nonce to the session and sends the user to
 *              ORCID sign-in with the WEB ORCID client. ORCID returns to
 *              sesar_orcid_callback.php. (D1; the Field app's
 *              orcid_callback.php is a different flow and is not touched.)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include_once("includes/config.inc.php");
require_once __DIR__ . "/includes/sesar/SesarOrcid.php";

$userpkey = (int)$_SESSION['userpkey'];
if (!SesarAccess::canUse($userpkey) || !SesarAccess::isConfigured()) {
	http_response_code(403);
	header('Content-Type: text/plain; charset=utf-8');
	echo "SESAR connections are not available for this account.";
	exit;
}

header('Cache-Control: no-store');
header('Location: ' . SesarOrcid::authorizeUrl(SesarOrcid::newState()));
