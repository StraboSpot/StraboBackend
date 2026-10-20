<?php
/**
 * File: register.php
 * Description: User registration
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


require_once 'includes/StraboMail.php';
require_once 'includes/RegisterEmail.php';

include_once "./includes/config.inc.php";
include("db.php");

if($_POST['email'] != "") $email = $_POST['email'];

function check_email_address($email)
{
   $isValid = true;
   $atIndex = strrpos($email, "@");
   if (is_bool($atIndex) && !$atIndex)
   {
	  $isValid = false;
   }
   else
   {
	  $domain = substr($email, $atIndex+1);
	  $local = substr($email, 0, $atIndex);
	  $localLen = strlen($local);
	  $domainLen = strlen($domain);
	  if ($localLen < 1 || $localLen > 64)
	  {
		 // local part length exceeded
		 $isValid = false;
	  }
	  else if ($domainLen < 1 || $domainLen > 255)
	  {
		 // domain part length exceeded
		 $isValid = false;
	  }
	  else if ($local[0] == '.' || $local[$localLen-1] == '.')
	  {
		 // local part starts or ends with '.'
		 $isValid = false;
	  }
	  else if (preg_match('/\\.\\./', $local))
	  {
		 // local part has two consecutive dots
		 $isValid = false;
	  }
	  else if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $domain))
	  {
		 // character not valid in domain part
		 $isValid = false;
	  }
	  else if (preg_match('/\\.\\./', $domain))
	  {
		 // domain part has two consecutive dots
		 $isValid = false;
	  }
	  else if
(!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/',
				 str_replace("\\\\","",$local)))
	  {
		 // character not valid in local part unless
		 // local part is quoted
		 if (!preg_match('/^"(\\\\"|[^"])+"$/',
			 str_replace("\\\\","",$local)))
		 {
			$isValid = false;
		 }
	  }
	  // StraboMail::FIXTURE_DOMAIN has no DNS on purpose: test suites use it, and mail to it is only filed, never sent.
	  if ($isValid && $domain !== StraboMail::FIXTURE_DOMAIN && !(checkdnsrr($domain,"MX") || checkdnsrr($domain,"A")))
	  {
		 // domain not found in DNS
		 $isValid = false;
	  }
   }
   return $isValid;
}

function h($s){
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Problems with a typed address, in the page's error format. $checked = the
// address the user already confirmed after a "Did you mean" hint. $exceptpkey
// = the pending account changing its own address.
function email_errors($email, $emailconfirm, $checked, $exceptpkey = 0){
	global $db, $suggestion;
	$errors = array();
	if($email==""){
		$errors[] = "Email cannot be blank.";
		return $errors;
	}
	if($email!=$emailconfirm){
		$errors[] = "Email addresses don't match.";
		return $errors;
	}
	if(!check_email_address($email)){
		$errors[] = "Invalid Email Address.";
		return $errors;
	}
	$mailrow=$db->get_row_prepared("SELECT * FROM users WHERE email=$1", array($email));
	if($mailrow->pkey != "" && $mailrow->pkey != $exceptpkey){
		if($mailrow->deleted == "t"){
			$errors[] = "Email Address (".h($email).") has been deleted and is unusable. Please contact strabospot@gmail.com if you would like to re-activate this account.";
		}else{
			$errors[] = "Email Address (".h($email).") is already registered at StraboSpot.";
		}
		return $errors;
	}
	$suggestion = RegisterEmail::suggest($email);
	if($suggestion !== null && $checked !== $email){
		$errors[] = "Did you mean <b>".h($suggestion)."</b>? "
			."<button type=\"button\" class=\"small\" onclick=\"useSuggestedEmail(".h(json_encode($suggestion)).")\">Use ".h($suggestion)."</button><br>"
			."If <b>".h($email)."</b> is correct, submit again to use it.";
	}else{
		$suggestion = null;
	}
	return $errors;
}

function send_validation_mail($email, $firstname, $lastname, $hash){
	$welcome = StraboMail::render(array(
		'title'    => 'Confirm your StraboSpot account',
		'greeting' => 'Hi ' . $firstname . ',',
		'intro'    => array('Thanks for your interest in StraboSpot. Please confirm your email address to activate your account.'),
		'facts'    => array('Account' => $email),
		'button'   => array('Confirm my account', "https://www.strabospot.org/validate/$hash"),
		'after'    => array('If you did not create a StraboSpot account, you can ignore this message and nothing will happen.'),
		'footer'   => 'You received this because this address was used to register at StraboSpot (https://strabospot.org).',
	));
	try {
		StraboMail::send($email, 'Confirm your StraboSpot account', $welcome, array('to_name' => trim($firstname . ' ' . $lastname)));
	} catch (Exception $e) {
		error_log('register: validation mail to ' . $email . ' failed: ' . $e->getMessage());
	}
}

function suggestion_script(){
	// The hint prints above the form, so find the (only) email fields on the page.
	return "<script>\nfunction useSuggestedEmail(email){\n\tdocument.querySelector('input[name=email]').value = email;\n\tdocument.querySelector('input[name=emailconfirm]').value = email;\n}\n</script>\n";
}

$suggestion = null;
$sentemail = "";
$fixexpired = false;

if($_POST['fix_submit']!=""){

	// "Wrong address?" form from the sent page: change the pending account's
	// address, give it a new validation hash (the old link stops working) and
	// send the link to the new address.
	$fix = RegisterEmail::readFixToken($_POST['fixtoken'], $db, $jwtsecret);
	if($fix === null){
		$fixexpired = true;
	}else{
		list($row, $fixes) = $fix;

		$fixemail = strtolower(trim($_POST['email']));
		$fixemailconfirm = strtolower(trim($_POST['emailconfirm']));
		$errors = email_errors($fixemail, $fixemailconfirm, (string)$_POST['email_checked'], $row->pkey);
		if(count($errors) > 0){
			$fixerror = "<div style=\"color:#FF0000;padding:10px;\">Error:<br>".implode("<br>", $errors)."</div>";
			$sentemail = $row->email;
			$sentfixtoken = $_POST['fixtoken'];
		}else{
			$newhash = RegisterEmail::newHash();
			$db->prepare_query("UPDATE users SET email = $1, hash = $2 WHERE pkey = $3 AND active = false", array($fixemail, $newhash, $row->pkey));
			error_log('register: pending account ' . $row->pkey . ' changed address ' . $row->email . ' -> ' . $fixemail);
			send_validation_mail($fixemail, $row->firstname, $row->lastname, $newhash);
			$sentemail = $fixemail;
			$sentfixtoken = RegisterEmail::fixToken($row->pkey, $newhash, $fixes + 1, $jwtsecret);
			$fixemail = $fixemailconfirm = "";
			$suggestion = null;
		}
	}
}

if($_POST['submit']!=""){

	$firstname = trim($_POST['firstname']);
	$lastname = trim($_POST['lastname']);
	$email = strtolower(trim($_POST['email']));
	$emailconfirm = strtolower(trim($_POST['emailconfirm']));
	$password = $_POST['password'];
	$passwordconfirm = $_POST['passwordconfirm'];

	if($firstname==""){$error.=$errordelim."First Name cannot be blank.";$errordelim="<br>";}
	if($lastname==""){$error.=$errordelim."Last Name cannot be blank.";$errordelim="<br>";}
	foreach(email_errors($email, $emailconfirm, (string)$_POST['email_checked']) as $e){$error.=$errordelim.$e;$errordelim="<br>";}
	if($password==""){$error.=$errordelim."Password cannot be blank.";$errordelim="<br>";}

	if($password!=""){
		if($password!=$passwordconfirm){
			$error.=$errordelim."Passwords don't match.";$errordelim="<br>";
		}
	}

	//Check Captcha from Google

	$secret = $captchasecret;
	$response = $_POST["g-recaptcha-response"];

	$url = 'https://www.google.com/recaptcha/api/siteverify';
	$fields = array(
		'secret' => urlencode($secret),
		'response' => urlencode($response)
	);

	//url-ify the data for the POST
	foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
	rtrim($fields_string, '&');

	//open connection
	$ch = curl_init();

	//set the url, number of POST vars, POST data
	curl_setopt($ch,CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch,CURLOPT_POST, count($fields));
	curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);

	//execute post
	$result = curl_exec($ch);

	//close connection
	curl_close($ch);

	$result = json_decode($result);

	if(!$result->success){
		$error.=$errordelim."Captcha Error. Please check the captcha box.";$errordelim="<br>";
	}

	if(substr_count($email, '.') > 4){
		header("Location: /");
		exit();
	}

	if($error==""){

		//no errors, so let's put in user and send out confirmation email

		$randstring = RegisterEmail::newHash();

		$db->prepare_query("INSERT INTO users (firstname, lastname, password, hash, email) VALUES ($1, $2, crypt($3, gen_salt('md5')), $4, $5)",
			array($firstname, $lastname, $password, $randstring, $email));
		$newpkey = $db->get_var_prepared("SELECT pkey FROM users WHERE email=$1 AND hash=$2", array($email, $randstring));

		send_validation_mail($email, $firstname, $lastname, $randstring);

		$sentemail = $email;
		$sentfixtoken = $newpkey != "" ? RegisterEmail::fixToken($newpkey, $randstring, 0, $jwtsecret) : "";

		curl_setopt_array($ch = curl_init(), array(
		  CURLOPT_URL => "https://api.pushover.net/1/messages.json",
		  CURLOPT_POSTFIELDS => array(
			"token" => "$pushover_token",
			"user" => "$pushover_user",
			"message" => "New Strabo User: $firstname $lastname - $email",
			"title" => "New Strabo User",
		  ),
		  CURLOPT_SAFE_UPLOAD => true,
		  CURLOPT_RETURNTRANSFER => true,
		));
		curl_close($ch);
	}else{
		$error="<div style=\"color:#FF0000;padding:10px;\">Error:<br>$error</div>";
	}

}

include("includes/mheader.php");

?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Register for Account</h2>
						</header>

<?php if($fixexpired){ ?>
		This address can no longer be changed from here (the account was already activated, the address was already changed, or too much time has passed).<br><br>
		Please <a href="/register">register again</a> with the correct address, or contact strabospot@gmail.com for help.<br><br>
		Thanks,<br><br>
		The StraboSpot Team
<?php }elseif($sentemail != ""){ ?>
		<h2 class="wsite-content-title">Success!</h2><br><br>
		A confirmation link has been emailed to <b><?php echo h($sentemail)?></b>. Please allow a few minutes for this email to arrive.<br><br>
		Clicking on the link will activate your account.<br><br>
		If you have not received an email, please be sure to check your spam folder.<br><br>
<?php if($sentfixtoken != ""){ ?>
		<details id="fixemail"<?php if($fixerror != "") echo " open"?> style="margin-bottom:2em;">
			<summary style="cursor:pointer;">Wrong address? Fix it here.</summary>
			<div style="padding-top:1em;">
				If <b><?php echo h($sentemail)?></b> is not right, enter the correct address and we will send the link there instead.
				The link sent to <?php echo h($sentemail)?> will stop working.
				<?php echo $fixerror?>
				<form method="POST">
					<input type="hidden" name="fixtoken" value="<?php echo h($sentfixtoken)?>">
					<input type="hidden" name="email_checked" value="<?php echo $suggestion !== null ? h($fixemail) : ""?>">
					<div class="padTop">Correct Email:<input type="text" name="email" autocomplete="email" value="<?php echo h($fixemail)?>"></div>
					<div class="padTop">Confirm Correct Email:<input type="text" name="emailconfirm" autocomplete="email" value="<?php echo h($fixemailconfirm)?>"></div>
					<div align="center" class="padTop"><input class="primary" type="submit" name="fix_submit" value="Send Link to This Address"></div>
				</form>
			</div>
		</details>
<?php } ?>
		Thanks,<br><br>
		The StraboSpot Team
<?php }else{ ?>
						<?php echo $error?>
						<form method="POST">
								<input type="hidden" name="email_checked" value="<?php echo $suggestion !== null ? h($email) : ""?>">
								<div>First Name:</td><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="firstname" value="<?php echo h($firstname)?>"></div>
								<div class="padTop">Last Name:</td><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="lastname" value="<?php echo h($lastname)?>"></div>
								<div class="padTop">Email:</td><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="email" autocomplete="email" value="<?php echo h($email)?>"></div>
								<div class="padTop">Confirm Email:</td><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="text" name="emailconfirm" autocomplete="email" value="<?php echo h($emailconfirm)?>"></div>
								<div class="padTop">Password:</td><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="password" name="password" value="<?php echo h($password)?>"></div>
								<div class="padTop">Confirm Password:</td><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="password" name="passwordconfirm" value="<?php echo h($passwordconfirm)?>"></div>
								<div align="center" class="padTop"><div class="g-recaptcha" data-sitekey="6LdpswwUAAAAAFoitK0R2qztzQb8KT59pq1jUKEQ"></div></div></div>
								<div align="center"><input class="primary" type="submit" name="submit" value="Submit"></div>
						</form>
<?php } ?>
						<?php echo suggestion_script()?>

						<div class="bottomSpacer"></div>

					</div>
				</div>
<?php

include("includes/mfooter.php");

?>