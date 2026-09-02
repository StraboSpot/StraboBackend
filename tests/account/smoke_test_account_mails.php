<?php
/**
 * File: tests/account/smoke_test_account_mails.php
 * Description: The password-reset and resend-validation-link pages send the
 *              StraboMail branded template to the account address (fixture
 *              addresses under @test.strabospot.org are filed to
 *              exportjobs_data/log/mail.log, never sent), and unknown
 *              addresses send nothing. Registration is reCAPTCHA-gated and is
 *              covered by the template unit checks only.
 *
 *   docker exec strabo-php php /srv/app/www/tests/account/smoke_test_account_mails.php
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/StraboMail.php';

$BASE = 'http://localhost';
$rand = substr(bin2hex(random_bytes(4)), 0, 8);
$email = "acctmail-$rand@test.strabospot.org";
$hash = substr(md5($email), 0, 21);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr($detail, 0, 400) . "]" : '') . "\n"; }
}
function post($path, array $fields) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_TIMEOUT => 60));
	$b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
	return array($c, (string)$b);
}
function mail_len() { $f = StraboMail::logFile(); clearstatcache(true, $f); return is_file($f) ? filesize($f) : 0; }
function mail_since($from) { $f = StraboMail::logFile(); clearstatcache(true, $f); return is_file($f) ? (string)substr(file_get_contents($f), $from) : ''; }

echo "Account mails smoke suite\n";
$db->prepare_query("INSERT INTO users (firstname, lastname, password, hash, email, active, deleted) VALUES ('Acct', 'Fixture', 'x', $1, $2, false, false)", array($hash, $email));

// forgot password
$mark = mail_len();
list($c, $b) = post('/forgotpassword.php', array('submit_forgot_password' => 'Submit', 'email' => $email));
$m = mail_since($mark);
check('forgotpassword: page confirms the send', $c === 200 && strpos($b, 'Email sent') !== false, "$c");
check('forgotpassword: one templated mail to the account address', substr_count($m, "To: $email") === 1 && strpos($m, 'Subject: Reset your StraboSpot password') !== false, substr($m, 0, 300));
check('forgotpassword: greeting by first name, account fact, reset link with the hash', strpos($m, 'Hi Acct,') !== false && strpos($m, "Account: $email") !== false && strpos($m, "https://www.strabospot.org/passwdreset/$hash") !== false, substr($m, 0, 900));
check('forgotpassword: no PHP warnings on the page', stripos($b, 'Warning:') === false && stripos($b, 'Fatal error') === false);

// resend validation link
$mark = mail_len();
list($c, $b) = post('/resendlink.php', array('submit_resend_vlink' => 'Submit', 'email' => $email));
$m = mail_since($mark);
check('resendlink: page confirms the send', $c === 200 && strpos($b, 'Email sent') !== false, "$c");
check('resendlink: one templated mail with the validate link', substr_count($m, "To: $email") === 1 && strpos($m, 'Subject: Confirm your StraboSpot account') !== false && strpos($m, "https://www.strabospot.org/validate/$hash") !== false, substr($m, 0, 600));

// unknown address -> nothing
$mark = mail_len();
list($c, $b) = post('/forgotpassword.php', array('submit_forgot_password' => 'Submit', 'email' => "nobody-$rand@test.strabospot.org"));
list($c2, $b2) = post('/resendlink.php', array('submit_resend_vlink' => 'Submit', 'email' => "nobody-$rand@test.strabospot.org"));
check('unknown address: both pages report not found and file no mail', strpos($b, 'not found') !== false && strpos($b2, 'not found') !== false && mail_since($mark) === '');

// registration template (reCAPTCHA blocks the page; render the same message shape)
$r = StraboMail::render(array('title' => 'Confirm your StraboSpot account', 'greeting' => 'Hi Acct,', 'facts' => array('Account' => $email), 'button' => array('Confirm my account', 'https://www.strabospot.org/validate/' . $hash)));
check('registration message shape renders with the validate button', strpos($r['html'], 'https://www.strabospot.org/validate/' . $hash) !== false && strpos($r['html'], 'cid:' . StraboMail::LOGO_CID) !== false);

$db->prepare_query("DELETE FROM users WHERE email = $1", array($email));
check('zero residue', (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE email = $1", array($email)) === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
