<?php
/**
 * File: passwordreset.php
 * Description: Password reset form handler and email sender
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("./includes/config.inc.php");
include("db.php");

$hash = $_GET['hash'] ?? $_POST['hash'] ?? '';
// Only allow alphanumeric characters in hash
$hash = preg_replace('/[^a-zA-Z0-9]/', '', $hash);
if($hash==""){exit();}

$count=$db->get_var_prepared("SELECT count(*) FROM users WHERE hash=$1", array($hash));

if($count > 0){

	if($_POST['submit']!=""){
		//check passwords
		$password=$_POST['password'];
		$passwordconfirm=$_POST['passwordconfirm'];

		if($password==""){
			$error.=$errordelim."Password cannot be blank.";$errordelim="<br>";
		}

		if($password!=""){
			if($password != $passwordconfirm){
				$error.=$errordelim."Passwords do not match.";$errordelim="<br>";
			}
		}

		if($error==""){
			//update password - SECURE: Using prepared statement
			$db->prepare_query("UPDATE users SET password=crypt($1, gen_salt('md5')) WHERE hash=$2", array($password, $hash));

			include("includes/mheader.php");
			?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Success!</h2>
						</header>
				Password has been reset.
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
							<h2>Password Reset</h2>
						</header>
		<?php echo $error?>
		<form method="POST">
			<div class="row gtr-uniform gtr-50">
			<div class="col-12"><h3>New Password:</h3></div>
			<div class="col-12"><input type="password" name="password" value="<?php echo $password?>"></div>
			<div class="col-12"><h3>Confirm New Password:</h3></div>
			<div class="col-12"><input type="password" name="passwordconfirm" value="<?php echo $passwordconfirm?>"></div>
			<div class="col-12"><input class="primary" type="submit" name="submit" value="Submit"></div>
			</div>
		<input type="hidden" name="hash" value="<?php echo $hash?>">
		</form>

					<div class="bottomSpacer"></div>

					</div>
				</div>

	<?php

	include("includes/mfooter.php");

}

?>