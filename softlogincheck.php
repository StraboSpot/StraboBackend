<?php
/**
 * File: softlogincheck.php
 * Description: Soft session resolution for read paths that serve BOTH
 *              logged-in and anonymous visitors (public-data pages).
 *
 * Unlike logincheck.php this never redirects to /login.php. It resolves
 * the session (same 2-hour timeout downgrade) and clears any stale
 * identity, so anonymous/expired visitors reach prepare_connections.php
 * with an empty userpkey — which it maps to the no-user sentinel (99999,
 * matches no users row, never in adminkeys). The including page's own
 * access-control SQL (owner OR ispublic) then decides visibility.
 *
 * Use ONLY on read paths whose queries enforce owner-or-public access.
 * Write paths and private reads must keep using logincheck.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once("adminkeys.php");
include_once(__DIR__ . "/includes/session_config.php");

session_start();

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT)) {
	$_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] != "yes") {
	// Anonymous or expired session: drop any stale identity so
	// prepare_connections.php falls through to the no-user sentinel.
	$_SESSION['userpkey'] = "";
}

?>
