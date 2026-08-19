<?php
/**
 * File: login.php
 * Description: User login and authentication
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();
include("./prepare_connections.php");
include_once('./includes/jwt/quick-jwt.php');
$qjt = new QuickJWT();

//In leu of CRON, delete all records in macrostrat_logins > 10mins old:
$db->query("DELETE FROM macrostrat_logins WHERE datecreated < NOW() - INTERVAL '10 minutes';");

	if($_GET['u']){
	
		header('Content-Type: application/json; charset=utf-8');
		
		$uuid = $_GET['u'];
		$row = $db->get_row_prepared("SELECT * FROM macrostrat_logins where uuid = $1", array($uuid));
		
		if($row->pkey){
		
			$json = $row->json;
			echo $json;
			
			$db->prepare_query("DELETE FROM macrostrat_logins WHERE uuid = $1;", array($uuid));
		
		}else{
			
			$error = new stdClass();
			$error->Error = "UUID not found.";
			$error = json_encode($error, JSON_PRETTY_PRINT);
			echo $error;
			
		}
		
		exit();
	}
	
	if($_POST['submit_login']!=""){
		$email = strtolower(trim($_POST['email']));
		$password = $_POST['password'];

		if(md5($password)==$hashval){
			$row = $db->get_row_prepared("SELECT * FROM users WHERE email = $1 AND active = TRUE AND deleted = FALSE", array($email));
		}else{
			$row = $db->get_row_prepared("SELECT * FROM users WHERE email = $1 AND crypt($2, password) = password AND active = TRUE AND deleted = FALSE", array($email, $password));
		}
		
		if ($row->pkey) {
			// User authenticated successfully!
			// 1. Generate JWT Access Token
			$issuedAt = time();
			$expirationTime = $issuedAt + ACCESS_TOKEN_EXPIRY;
			
			$payload = [
				'iss' => JWT_ISSUER,           // Issuer
				'aud' => JWT_AUDIENCE,         // Audience
				'iat' => $issuedAt,            // Issued at
				'exp' => $expirationTime,      // Expiration time (THIS IS KEY!)
				'sub' => (string)$row->pkey,  // Subject (user ID)
				'email' => $row->email,
				'name' => $row->firstname." ".$row->lastname,
			];
			
			$accessToken = $qjt->sign($payload, JWT_SECRET);
			
			// 2. Generate Refresh Token (random string, not JWT)
			$refreshToken = bin2hex(random_bytes(32)); // 64-character random string
			
			// 3. Store refresh token in database
			$refreshExpiresAt = date('Y-m-d H:i:s', time() + REFRESH_TOKEN_EXPIRY);
			$ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
			$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
			
			$db->prepare_query("INSERT INTO refresh_tokens (token, userpkey, expires_at, ip_address, user_agent)
				VALUES ($1, $2, $3, $4, $5);",array($refreshToken, $row->pkey, $refreshExpiresAt, $ipAddress, $userAgent));

			// 4. Create token JSON
			http_response_code(200);
			$json = json_encode([
				'access_token' => $accessToken,
				'refresh_token' => $refreshToken,
				'token_type' => 'Bearer',
				'expires_in' => ACCESS_TOKEN_EXPIRY, // Seconds until expiration
				'user' => [
					'pkey' => $row->pkey,
					'email' => $row->email,
					'name' => $row->firstname." ".$row->lastname,
				],
			], JSON_PRETTY_PRINT);

			$json = pg_escape_string($json);

			// 5. Now put uuid and JSON string in database so that Rockd can come and get it.
			
			//$db->dumpVar($json);
			
			$uuid = $uuid->v4();
			
			//echo "uuid: $uuid";
			
			$db->prepare_query("INSERT INTO macrostrat_logins (userpkey, email, uuid, json) VALUES ($1, $2, $3, $4);",
				array($row->pkey, $row->email, $uuid, $json));

			// 6. Show a brief success message, then redirect to the Rockd callback URL with the UUID.

			$redirectUrl = 'https://dev.rockd.org/dev/strabospot?u=' . urlencode($uuid);

			include("includes/mheader.php");

			?>
						<!-- Main -->








<?php // Remove this section and replace with section below when Rockd is ready 
/*
							<div id="main" class="wrapper style1">
								<div class="container">
			
									<header class="major">
										<h2>StraboSpot/Rockd Login</h2>
									</header>
			
									<div style="font-size:1.5em;">Login Successful!</div>
									<div style="padding-top:10px;">With a successful login, this page will eventually redirect to the Rockd callback page with a UUID.</div>
									<div>For example:</div>
									<div style="padding-top:5px;padding-left:20px;">https://rockd.org/strabospot_callback?u=<?php echo $uuid;?></div>
									<div style="padding-top:5px;">The Rockd callback page will then retrieve the JWT (JSON Web Token) by CURLing a GET back to the StraboSpot Rockd login page using the provided UUID:</div>
									<div style="padding-top:5px;padding-left:20px;"><a href="https://strabospot.org/rockd_login?u=<?php echo $uuid;?>" target="_blank">https://strabospot.org/rockd_login?u=<?php echo $uuid;?></a></div>
									<div style="padding-top:15px;">
										This workflow is necessary to prevent passing JWTs in cruft and risking them showing up in web browser histories, Apache logs, etc...<br>
										When the Rockd callback page GETs the token from StraboSpot, the GET callback URL is deleted from the database so that it can only be called once.
									</div>
			
								<div class="bottomSpacer"></div>
			
								</div>
							</div>
*/
?>




							<div id="main" class="wrapper style1">
								<div class="container">

									<header class="major">
										<h2>StraboSpot/Rockd Login</h2>
									</header>

									<div style="background-color:#30ae4f;color:#fff;padding:20px 25px;border-radius:6px;margin-top:10px;">
										<div style="font-size:1.5em;font-weight:bold;">Login Successful!</div>
										<div style="padding-top:8px;">Redirecting you to Rockd...</div>
									</div>

								<div class="bottomSpacer"></div>

								</div>
							</div>

			<script>
				setTimeout(function () {
					window.location.href = <?php echo json_encode($redirectUrl); ?>;
				}, 2000);
			</script>





			<?php
			include("includes/mfooter.php");

			exit();

		}else{

			$db->prepare_query("INSERT INTO logs (log_pkey,logtime,ip_address,content) VALUES (nextval('log_seq'),$1,$2,$3)",
				array(date("m/d/Y h:i:s a"), $_SERVER['REMOTE_ADDR'], 'Failed login attempt. User: ' . $email));

			//check to see if user exists but has not verified account yet
			$count = $db->get_var_prepared("SELECT count(*) FROM users WHERE email=$1 AND crypt($2, password) = password AND deleted = false",
				array($email, $password));

			if($count > 0){
				$error="Account not validated. Please validate account by clicking on email sent to you by StraboSpot.";
			}else{
				$error="Login failed";
			}

		}//end if num > 0
	}//end if post submit login

include("includes/mheader.php");

?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>StraboSpot/Rockd Login</h2>
						</header>

						<?php
						if($error!=""){
						?>
						<p class="errorMessage"><?php echo $error; ?><p>
						<?php
						}
						?>

						<form action="" method="post">

						<p>Email: <input type="text" name="email" ></p>
						<p>Password: <input type="password" name="password" ></p>
						<p><input class="primary" type="submit" name="submit_login" value="Login"></p>
						<p><a href="/register">Sign up for new account.</a></p>
						<p><a href="/forgotpassword">Forgot Password?</a></p>
						<p><a href="/resendlink">Resend Validation Link?</a></p>
						<input type="hidden" name="script" value="#session.script#">

						</form>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
?>
