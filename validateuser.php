<?php
/**
 * File: validateuser.php
 * Description: Error!
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once "./includes/config.inc.php";
include("db.php");
include("neodb.php");

$hash = $_GET['hash'] ?? '';
$hash = preg_replace('/[^a-zA-Z0-9]/', '', $hash);

// The StraboMicro join link this person signed up from (/register?join=CODE): the way back after confirming.
$joinCode = isset($_GET['join']) && is_string($_GET['join']) ? substr(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['join']), 0, 16) : '';

function validate_login_link($joinCode){
	if($joinCode !== ""){
		return '<p>Log in to continue joining the StraboMicro project.</p><p><a href="/login?uri=/join/' . $joinCode . '" class="button primary">Log In and Continue</a></p>';
	}
	return '<div style="padding-left:150px;"><a href="/login">Login</a></div><br>';
}

$row = null;
if($hash!=""){
	$row=$db->get_row_prepared("SELECT * FROM users WHERE hash=$1", array($hash));
}

if(!$row || $row->pkey==""){
	// Unknown link: mistyped, or replaced by a newer one (the address was
	// corrected at registration, which gives the account a new link).
	include("includes/mheader.php");
	?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Link No Longer Valid</h2>
						</header>
		This confirmation link is not valid. It may have been replaced by a newer link, for example if the
		email address was corrected after registering.<br><br>
		If you registered for a StraboSpot account, please use the most recent confirmation email we sent,
		or <a href="/resendlink">request a new confirmation link</a>.<br><br>
		If your account is already active, you can <a href="/login">log in</a>.<br><br>
		If you did not register for a StraboSpot account, you can ignore the email.<br><br>
		Thanks,<br><br>
		The StraboSpot Team
						<div class="bottomSpacer"></div>

					</div>
				</div>
	<?php
	include("includes/mfooter.php");
	exit();
}else{
	if($row->active=="t"){
		include("includes/mheader.php");
		?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Error!</h2>
						</header>
		Your account has already been activated.<br>
		Please use the login link below to login to your account.<br><br>
		<?php echo validate_login_link($joinCode)?>
		Thanks,<br><br>
		The StraboSpot Team
						<div class="bottomSpacer"></div>

					</div>
				</div>
		<?php
		include("includes/mfooter.php");
		exit();
	}
}

$db->prepare_query("UPDATE users SET active = true WHERE hash=$1", array($hash));

//also insert node
$userpkey = (int)$row->pkey;
$firstname = $row->firstname;
$lastname = $row->lastname;
$email = $row->email;

$injson["userpkey"]=$userpkey;
$injson["firstname"]=$firstname;
$injson["lastname"]=$lastname;
$injson["email"]=$email;

$jsoninjson = json_encode($injson);

$count = $neodb->get_var("match (u:User) where u.userpkey=$userpkey return count(u)");

if($count==0){
	$neodb->createNode($jsoninjson,"User");
}

//Add user to MailChimp:
exec('curl -X POST \'https://us8.api.mailchimp.com/3.0/lists/693e23d78d/members?skip_merge_validation=false\' --user "key:'.$mailchimpAPIkey.'" -d \'{"email_address":"'.$email.'","email_type":"html","status":"subscribed","merge_fields":{"FNAME": "'.$firstname.'", "LNAME": "'.$lastname.'", "BIRTHDAY": "", "ADDRESS": { "addr1": "", "city": "", "state": "", "zip": ""}}}\' > /dev/null 2>&1 &', $out);

include("includes/mheader.php");
?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Account Activated!</h2>
						</header>
Congratulations! Your account has been activated.<br><br>
Please use the login link below to login to your account.<br><br>
<?php echo validate_login_link($joinCode)?>
Thanks,<br><br>
The StraboSpot Team
						<div class="bottomSpacer"></div>

					</div>
				</div>
<?php
include("includes/mfooter.php");
?>