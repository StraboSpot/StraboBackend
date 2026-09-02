<?php
/**
 * File: resendlink.php
 * Description: Resends account validation email link
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

	if($_POST['submit_resend_vlink']!=""){

		$email = strtolower(trim($_POST['email']));

		// Validate email format
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$error = "Invalid email format";
			$myrow = null;
		} else {
			$myrow = $db->get_row_prepared("SELECT * FROM users WHERE email=$1 LIMIT 1", array($email));
		}

		if($db->num_rows > 0){
			//send email here

			$hash = $myrow->hash;

			$m = StraboMail::render(array(
				'title'    => 'Confirm your StraboSpot account',
				'greeting' => 'Hi ' . ($myrow->firstname !== '' ? $myrow->firstname : 'there') . ',',
				'intro'    => array('Here is a fresh link to confirm your StraboSpot account.'),
				'facts'    => array('Account' => $email),
				'button'   => array('Confirm my account', "https://www.strabospot.org/validate/$hash"),
				'after'    => array('If you did not request this link, you can ignore this message.'),
				'footer'   => 'You received this because a new validation link was requested for this address at StraboSpot (https://strabospot.org).',
			));
			try {
				StraboMail::send($email, 'Confirm your StraboSpot account', $m, array('to_name' => trim($myrow->firstname . ' ' . $myrow->lastname)));
			} catch (Exception $e) {
				error_log('resendlink: validation mail to ' . $email . ' failed: ' . $e->getMessage());
			}

			$error="Email sent. Please use link sent to validate account.";

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
							<h2>Resend Verification Link</h2>
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
	<p><input class="primary" type="submit" name="submit_resend_vlink" id="submit_resend_vlink" value="Resend Verification Link"></p>
	<input type="hidden" name="script" value="#session.script#">

  </form>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
?>
