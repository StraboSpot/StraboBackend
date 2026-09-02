<?php
/**
 * File: change_email.php
 * Description: Email-change request form for logged-in users. Submitting the
 *              form does NOT change the account email; it creates a pending,
 *              single-use, expiring request and emails a confirmation link to
 *              the NEW address. The change is only applied once the user clicks
 *              that link (see confirmemailchange.php).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once 'includes/StraboMail.php';

include("logincheck.php");

$userpkey = (int)$_SESSION['userpkey'];

include("includes/config.inc.php");
include("db.php");

// Confirmation links remain valid for this many hours.
$EMAIL_CHANGE_TTL_HOURS = 24;

$currentemail = $db->get_var_prepared("SELECT email FROM users WHERE pkey=$1", array($userpkey));

if($currentemail != ""){

	if($_POST['submit']!=""){

		$newemail = strtolower(trim($_POST['newemail']));
		$currentpassword = $_POST['currentpassword'];

		// Validate new email format
		if($newemail==""){
			$error.=$errordelim."New email address cannot be blank.";$errordelim="<br>";
		}elseif(!filter_var($newemail, FILTER_VALIDATE_EMAIL)){
			$error.=$errordelim."Please enter a valid email address.";$errordelim="<br>";
		}elseif($newemail==strtolower(trim($currentemail))){
			$error.=$errordelim."That is already your current email address.";$errordelim="<br>";
		}

		// Re-authenticate: require the current password before allowing a change.
		$pwok = $db->get_var_prepared("SELECT count(*) FROM users WHERE crypt($1, password) = password AND pkey=$2", array($currentpassword, $userpkey));
		if($pwok==0){
			$error.=$errordelim."Incorrect current password provided.";$errordelim="<br>";
		}

		// Reject an address that already belongs to another account
		// (including soft-deleted accounts, mirroring registration).
		if($newemail!="" && filter_var($newemail, FILTER_VALIDATE_EMAIL)){
			$existing = $db->get_row_prepared("SELECT pkey, deleted FROM users WHERE email=$1", array($newemail));
			if($existing->pkey != ""){
				// Postgres booleans arrive as the strings 't'/'f' through this driver,
				// so compare explicitly - a loose ==true would treat 'f' as truthy.
				$isDeleted = ($existing->deleted === true || $existing->deleted === 't');
				if($isDeleted){
					$error.=$errordelim."That email address is associated with a deleted account and cannot be used. Please contact strabospot@gmail.com for assistance.";$errordelim="<br>";
				}else{
					$error.=$errordelim."That email address is already registered to another StraboSpot account.";$errordelim="<br>";
				}
			}
		}

		if($error==""){

			// Supersede any earlier outstanding request for this user.
			$db->prepare_query("UPDATE email_change_requests SET used_at=now() WHERE userpkey=$1 AND used_at IS NULL", array($userpkey));

			// Cryptographically strong, single-use token (64 hex chars).
			$token = bin2hex(random_bytes(32));

			$db->prepare_query(
				"INSERT INTO email_change_requests (userpkey, new_email, token, expires_at) VALUES ($1, $2, $3, now() + ($4 || ' hours')::interval)",
				array($userpkey, $newemail, $token, $EMAIL_CHANGE_TTL_HOURS)
			);

			$m = StraboMail::render(array(
				'title'    => 'Confirm your new StraboSpot email address',
				'greeting' => 'Hi there,',
				'intro'    => array('We received a request to change the email address on a StraboSpot account to this address.'),
				'facts'    => array('New address' => $newemail, 'Link expires' => "in $EMAIL_CHANGE_TTL_HOURS hours"),
				'button'   => array('Confirm the change', "https://www.strabospot.org/changeemail/$token"),
				'after'    => array('If you did not request this change, you can safely ignore this email and the account will be unchanged.'),
				'footer'   => 'You received this because this address was entered as a new sign-in address at StraboSpot (https://strabospot.org).',
			));
			try {
				StraboMail::send($newemail, 'Confirm your new StraboSpot email address', $m);
			} catch (Exception $e) {
				error_log('change_email: confirmation mail to ' . $newemail . ' failed: ' . $e->getMessage());
			}

			include("includes/mheader.php");
			?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Check Your Email</h2>
						</header>
				A confirmation link has been sent to <strong><?php echo htmlspecialchars($newemail)?></strong>.<br><br>
				Please open that message and click the link to confirm your new email address. The link expires in <?php echo $EMAIL_CHANGE_TTL_HOURS?> hours.<br><br>
				Your account email will not change until you confirm it from your new inbox.
					<div class="bottomSpacer"></div>

					</div>
				</div>
			<?php
			include("includes/mfooter.php");
			exit();
		}
	}

	if($error!=""){
		$error="<div style=\"color:#e44c65;padding:10px;\">$error</div>";
	}

	include("includes/mheader.php");

	?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Change Email Address</h2>
						</header>
		<?php echo $error?>
		<p>Your current email address is <strong><?php echo htmlspecialchars($currentemail)?></strong>.<br>
		Enter your new email address below. We'll send a confirmation link to the new address; your account email changes only after you click that link.</p>
		<form method="POST">
			<div class="row gtr-uniform gtr-50">
			<div class="col-12"><h3>New Email Address:</h3></div>
			<div class="col-12"><input type="text" name="newemail" value="<?php echo htmlspecialchars($newemail)?>"></div>
			<div class="col-12"><h3>Current Password:</h3></div>
			<div class="col-12"><input type="password" name="currentpassword" value=""></div>
			<div class="col-12"><input class="primary" type="submit" name="submit" value="Submit"></div>
			</div>
		</form>

					<div class="bottomSpacer"></div>

					</div>
				</div>

	<?php

	include("includes/mfooter.php");

}

?>
