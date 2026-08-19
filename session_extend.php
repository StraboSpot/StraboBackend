<?php
/**
 * File: session_extend.php
 * Description: "Stay logged in" endpoint for the auto-logout warning system.
 *              If the caller's session is still valid, resets the idle-timeout
 *              clock (LAST_ACTIVITY) and returns the fresh remaining time.
 *              An expired or anonymous session is NOT resurrected: the
 *              response says ok=false and the client redirects to the
 *              logged-out page.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once(__DIR__ . "/includes/session_config.php");

session_start();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$loggedin = (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] == "yes");
$expired = (isset($_SESSION['LAST_ACTIVITY']) && (time() - (int)$_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT));

if($loggedin && !$expired){
	$_SESSION['LAST_ACTIVITY'] = time();
	session_write_close();
	echo json_encode(array("ok" => true, "remaining" => SESSION_IDLE_TIMEOUT));
}else{
	session_write_close();
	echo json_encode(array("ok" => false, "remaining" => 0));
}

?>
