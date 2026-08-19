<?php
/**
 * File: confirmemailchange.php
 * Description: Landing page for the email-change confirmation link sent to a
 *              user's NEW address (routed as /changeemail/{token}). Validates
 *              the single-use, expiring token and, on success, commits the
 *              change: updates the account email, re-keys the user's app tokens,
 *              updates the Neo4j User node, notifies the OLD address, and clears
 *              the web session so the user logs back in with the new address.
 *
 *              NOTE: the page chrome (mheader/mfooter) is included at GLOBAL
 *              scope at the bottom of this file, NOT from inside a function.
 *              mheader -> prepare_connections -> db.php rebuilds $db from the
 *              config credentials, which are global; including it from a
 *              function scope would hide those vars and spawn a broken
 *              connection. All logic here therefore only computes the result
 *              title/body, and rendering happens once at the end.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Import PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'includes/PHPMailer/PHPMailer/src/Exception.php';
require 'includes/PHPMailer/PHPMailer/src/PHPMailer.php';
require 'includes/PHPMailer/PHPMailer/src/SMTP.php';

include_once "./includes/config.inc.php";
include("db.php");
include("neodb.php");

/**
 * Process the confirmation token and return [title, bodyHtml] for rendering.
 * Performs the actual commit (DB + Neo4j) and OLD-address notification as a
 * side effect on success. Uses the global $db / $neodb connections and the
 * global mail credentials.
 */
function processEmailChangeConfirmation(){
	global $db, $neodb, $straboemailaddress, $straboemailpassword;

	$token = $_GET['token'] ?? '';
	// Tokens are 64 hex chars; strip anything else.
	$token = preg_replace('/[^a-f0-9]/', '', $token);
	if($token==""){
		return array("Invalid Link", "This email-change link is invalid. Please request the change again from your account.");
	}

	$req = $db->get_row_prepared(
		"SELECT id, userpkey, new_email FROM email_change_requests WHERE token=$1 AND used_at IS NULL AND expires_at > now()",
		array($token)
	);

	if($req->id == ""){
		return array("Link Expired or Invalid", "This email-change link is invalid, has already been used, or has expired. Please request the change again from your account.<br><br><div style=\"padding-left:150px;\"><a href=\"/change_email\">Change Email</a></div>");
	}

	$reqid    = (int)$req->id;
	$userpkey = (int)$req->userpkey;
	$newemail = strtolower(trim($req->new_email));

	// Re-check the target address is still free - someone else may have
	// registered it between the request and this confirmation.
	$taken = $db->get_var_prepared("SELECT count(*) FROM users WHERE email=$1 AND pkey<>$2", array($newemail, $userpkey));
	if($taken > 0){
		// Burn the request so the stale link can't be retried.
		$db->prepare_query("UPDATE email_change_requests SET used_at=now() WHERE id=$1", array($reqid));
		return array("Email No Longer Available", "The email address <strong>".htmlspecialchars($newemail)."</strong> is no longer available. Please request the change again with a different address.<br><br><div style=\"padding-left:150px;\"><a href=\"/change_email\">Change Email</a></div>");
	}

	$oldrow   = $db->get_row_prepared("SELECT email, firstname FROM users WHERE pkey=$1", array($userpkey));
	$oldemail = $oldrow->email;

	if($oldemail == ""){
		return array("Account Not Found", "We could not locate the account for this request. Please contact strabospot@gmail.com for assistance.");
	}

	// ---- Commit the change --------------------------------------------------

	// 1. The account email itself.
	$db->prepare_query("UPDATE users SET email=$1 WHERE pkey=$2", array($newemail, $userpkey));

	// 2. App tokens are keyed by email, not pkey - re-key them so active app
	//    sessions keep matching the user row (extauth joins users.email = apptokens.email).
	$db->prepare_query("UPDATE apptokens SET email=$1 WHERE email=$2", array($newemail, $oldemail));

	// 3. The Neo4j User node carries a denormalized email property.
	$neoemail = addslashes($newemail);
	$neodb->query("match (u:User) where u.userpkey=$userpkey set u.email = \"$neoemail\"");

	// 4. Mark this request used, and clear any other outstanding requests.
	$db->prepare_query("UPDATE email_change_requests SET used_at=now() WHERE id=$1", array($reqid));
	$db->prepare_query("UPDATE email_change_requests SET used_at=now() WHERE userpkey=$1 AND used_at IS NULL", array($userpkey));

	// ---- Notify the OLD address (security safety net) -----------------------
	$notify= "<html><body>
				<h2>StraboSpot</h2>
				The email address on your StraboSpot account was just changed to <strong>$newemail</strong>.<br><br>
				If you made this change, no action is needed.<br><br>
				If you did <strong>not</strong> request this change, please contact us immediately at strabospot@gmail.com.<br><br>
				Thanks,<br><br>
				The StraboSpot Team
				<br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br>
				</body></html>";

	try {
		$mail = new PHPMailer(true);
		$mail->isSMTP();
		$mail->SMTPDebug = 0;
		$mail->Debugoutput = 'html';
		$mail->Host = 'smtp.gmail.com';
		$mail->SMTPAuth = true;
		$mail->SMTPSecure= 'tls';
		$mail->Port = 587;
		$mail->Username = $straboemailaddress;
		$mail->Password = $straboemailpassword;
		$mail->From = $straboemailaddress;
		$mail->FromName = 'StraboSpot';
		$mail->addAddress($oldemail);
		$mail->isHTML(true);
		$mail->CharSet = 'UTF-8';
		$mail->Encoding = 'base64';
		$mail->Subject = 'Your StraboSpot Email Address Was Changed';
		$mail->Body = $notify;
		$mail->send();
	} catch (Exception $e) {
		// Notification is best-effort; the change itself has already succeeded.
	}

	// ---- Clear the web session so the user re-authenticates -----------------
	// Session vars (username, loggedin_username, credentials) are keyed on the
	// old email; forcing a fresh login repopulates them correctly.
	if(session_status() !== PHP_SESSION_ACTIVE){
		session_start();
	}
	$_SESSION = array();
	session_destroy();

	return array("Email Address Updated", "Your StraboSpot email address has been changed to <strong>".htmlspecialchars($newemail)."</strong>.<br><br>Please log in again using your new email address.<br><br><div style=\"padding-left:150px;\"><a href=\"/login\">Login</a></div>");
}

list($resultTitle, $resultBody) = processEmailChangeConfirmation();

// Render the page chrome at GLOBAL scope (see file header note).
include("includes/mheader.php");
?>
		<!-- Main -->
			<div id="main" class="wrapper style1">
				<div class="container">

					<header class="major">
						<h2><?php echo $resultTitle?></h2>
					</header>
	<?php echo $resultBody?>
			<div class="bottomSpacer"></div>

				</div>
			</div>
<?php
include("includes/mfooter.php");
?>
