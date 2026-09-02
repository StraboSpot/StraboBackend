<?php
/**
 * File: forgotpassword.php
 * Description: Forgot password form and email sender
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();

require_once 'includes/StraboMail.php';

include_once "./includes/config.inc.php";
include("db.php");
include("neodb.php");

	if($_POST['submit_forgot_password']!=""){

		$email = strtolower(trim($_POST['email']));

		// Validate email format
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$error = "Invalid email format";
			$myrow = null;
		} else {
			$myrow = $db->get_row_prepared("SELECT * FROM users WHERE email=$1 LIMIT 1", array($email));
		}

		if($db->num_rows > 0){

			$hash = $myrow->hash;

			$m = StraboMail::render(array(
				'title'    => 'Reset your StraboSpot password',
				'greeting' => 'Hi ' . ($myrow->firstname !== '' ? $myrow->firstname : 'there') . ',',
				'intro'    => array('We received a request to reset the password for your StraboSpot account.'),
				'facts'    => array('Account' => $email),
				'button'   => array('Reset my password', "https://www.strabospot.org/passwdreset/$hash"),
				'after'    => array('If you did not ask for a password reset, you can ignore this message; your password stays as it is.'),
				'footer'   => 'You received this because a password reset was requested for this address at StraboSpot (https://strabospot.org).',
			));
			try {
				StraboMail::send($email, 'Reset your StraboSpot password', $m, array('to_name' => trim($myrow->firstname . ' ' . $myrow->lastname)));
			} catch (Exception $e) {
				error_log('forgotpassword: reset mail to ' . $email . ' failed: ' . $e->getMessage());
			}

			$error="Email sent. Please use link sent to reset password.";

		}else{

			$error="Email address not found.";

		}

	}

include("includes/mheader.php");
?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Forgot Password</h2>
						</header>

<?php
if(error!=""){
?>
<div style="color:#FF2A00;"><?php echo $error?></div>
<?php
}

?>

  <form method="POST">

	<p>Email Address: <input type="text" name="email" ></p>
	<p><input class="primary" type="submit" name="submit_forgot_password" id="submit_forgot_password" value="Reset My Password"></p>
	<input type="hidden" name="script" value="#session.script#">

  </form>
  <p />
  <!--<b>dev username/password: test/test123</b> </cfoutput>-->

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
?>
