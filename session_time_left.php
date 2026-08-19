<?php
/**
 * File: session_time_left.php
 * Description: Read-only JSON status for the auto-logout warning system.
 *              Reports whether the caller is logged in and how many seconds
 *              remain before the idle timeout expires the session.
 *
 *              CRITICAL: this endpoint must NEVER update LAST_ACTIVITY (and
 *              therefore must never include logincheck.php, sessioncheck.php,
 *              softlogincheck.php or mheader.php). If a status poll counted
 *              as activity, polling would keep every session alive forever.
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
$remaining = 0;

if($loggedin){
	if(isset($_SESSION['LAST_ACTIVITY'])){
		$remaining = SESSION_IDLE_TIMEOUT - (time() - (int)$_SESSION['LAST_ACTIVITY']);
	}else{
		// Logged in but never stamped (fresh login): full window remains.
		$remaining = SESSION_IDLE_TIMEOUT;
	}
	if($remaining <= 0){
		// Already expired; the next gatekeeper hit will clear the session.
		$loggedin = false;
		$remaining = 0;
	}
}

session_write_close();

echo json_encode(array("loggedin" => $loggedin, "remaining" => $remaining));

?>
