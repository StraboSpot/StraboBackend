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

// ---- Render helpers ---------------------------------------------------------

function showResult($title, $bodyHtml){
	include("includes/mheader.php");
	?>
		<!-- Main -->
			<div id="main" class="wrapper style1">
				<div class="container">

					<header class="major">
						<h2><?php echo $title?></h2>
					</header>
		<?php echo $bodyHtml?>
				<div class="bottomSpacer"></div>

				</div>
			</div>
	<?php
	include("includes/mfooter.php");
	exit();
}

// ---- Validate token ---------------------------------------------------------

$token = $_GET['token'] ?? '';
// Tokens are 64 hex chars; strip anything else.
$token = preg_replace('/[^a-f0-9]/', '', $token);
if($token==""){
	showResult("Invalid Link", "This email-change link is invalid. Please request the change again from your account.");
}

$req = $db->get_row_prepared(
	"SELECT id, userpkey, new_email FROM email_change_requests WHERE token=$1 AND used_at IS NULL AND expires_at > now()",
	array($token)
);

if($req->id == ""){
	showResult("Link Expired or Invalid", "This email-change link is invalid, has already been used, or has expired. Please request the change again from your account.<br><br><div style=\"padding-left:150px;\"><a href=\"/change_email\">Change Email</a></div>");
}

$reqid     = (int)$req->id;
$userpkey  = (int)$req->userpkey;
$newemail  = strtolower(trim($req->new_email));

// ---- Re-check the target address is still free ------------------------------
// Someone else may have registered it in the interval between request and confirm.
$taken = $db->get_var_prepared("SELECT count(*) FROM users WHERE email=$1 AND pkey<>$2", array($newemail, $userpkey));
if($taken > 0){
	// Burn the request so the stale link can't be retried.
	$db->prepare_query("UPDATE email_change_requests SET used_at=now() WHERE id=$1", array($reqid));
	showResult("Email No Longer Available", "The email address <strong>".htmlspecialchars($newemail)."</strong> is no longer available. Please request the change again with a different address.<br><br><div style=\"padding-left:150px;\"><a href=\"/change_email\">Change Email</a></div>");
}

// ---- Commit the change ------------------------------------------------------

$oldrow   = $db->get_row_prepared("SELECT email, firstname FROM users WHERE pkey=$1", array($userpkey));
$oldemail = $oldrow->email;

if($oldemail == ""){
	showResult("Account Not Found", "We could not locate the account for this request. Please contact strabospot@gmail.com for assistance.");
}

// 1. The account email itself.
$db->prepare_query("UPDATE users SET email=$1 WHERE pkey=$2", array($newemail, $userpkey));

// 2. App tokens are keyed by email, not pkey - re-key them so active app
//    sessions keep matching the user row (extauth joins users.email = apptokens.email).
$db->prepare_query("UPDATE apptokens SET email=$1 WHERE email=$2", array($newemail, $oldemail));

// 3. The Neo4j User node carries a denormalized email property.
$neoemail = addslashes($newemail);
$neodb->query("match (u:User) where u.userpkey=$userpkey set u.email = \"$neoemail\"");

// 4. Mark this request used, and clear any other outstanding requests for the user.
$db->prepare_query("UPDATE email_change_requests SET used_at=now() WHERE id=$1", array($reqid));
$db->prepare_query("UPDATE email_change_requests SET used_at=now() WHERE userpkey=$1 AND used_at IS NULL", array($userpkey));

// ---- Notify the OLD address (security safety net) ---------------------------

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

// ---- Clear the web session so the user re-authenticates with the new email --
// Session vars (username, loggedin_username, credentials) are keyed on the old
// email; forcing a fresh login is the clean way to repopulate them correctly.
session_start();
$_SESSION = array();
session_destroy();

showResult("Email Address Updated", "Your StraboSpot email address has been changed to <strong>".htmlspecialchars($newemail)."</strong>.<br><br>Please log in again using your new email address.<br><br><div style=\"padding-left:150px;\"><a href=\"/login\">Login</a></div>");

?>
