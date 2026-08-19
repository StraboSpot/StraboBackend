<?php
/**
 * File: loggedout.php
 * Description: Landing page for the auto-logout warning system. Destroys the
 *              (usually already expired) session, tells the user they were
 *              logged out due to inactivity, and offers a login link that
 *              returns them to the page they came from (login.php redirects
 *              to $_SESSION['uri'] after a successful login).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();

// Where the user was when the timeout hit. Accept only a same-site absolute
// path (no scheme/host, no protocol-relative //, no backslashes or control
// characters) since login.php sends it straight into a Location header.
$uri = isset($_GET['uri']) ? $_GET['uri'] : "";
if(!preg_match('#^/(?!/)[^\r\n\\\\]*$#', $uri)){
	$uri = "/";
}

// Make sure the old identity is really gone, whatever state it was in.
session_unset();
session_destroy();

// Fresh anonymous session carrying only the return URI for login.php.
session_start();
session_regenerate_id(true);
$_SESSION['uri'] = $uri;

include("includes/mheader.php");

?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>You Have Been Logged Out</h2>
						</header>

						<p>For your security, you were automatically logged out after a period of inactivity.</p>
						<p>Any unsaved changes from your previous session may have been lost.</p>

						<p><a href="/login">Log back in</a> to pick up where you left off.</p>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
?>
