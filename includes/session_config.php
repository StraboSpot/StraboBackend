<?php
/**
 * File: session_config.php
 * Description: Single authority for the web-session idle timeout. Included
 *              by logincheck.php, sessioncheck.php, softlogincheck.php,
 *              session_time_left.php, session_extend.php and mheader.php
 *              (which passes the values to the client-side countdown) so
 *              the checks can never drift apart.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Seconds of inactivity before a session is considered expired.
// (The if-guards allow a local override to be defined earlier, e.g. for testing.)
if(!defined('SESSION_IDLE_TIMEOUT')) define('SESSION_IDLE_TIMEOUT', 7200);

// How long before expiry the "stay logged in?" warning appears.
if(!defined('SESSION_WARNING_SECONDS')) define('SESSION_WARNING_SECONDS', 300);

?>
