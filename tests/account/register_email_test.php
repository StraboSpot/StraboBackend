<?php
/**
 * File: tests/account/register_email_test.php
 * Description: Registration guards against mistyped addresses: the Confirm
 *              Email field, the "Did you mean ...?" hint (RegisterEmail::
 *              suggest) and the "Wrong address? Fix it" form on the
 *              "link has been emailed" page. Registration itself is
 *              reCAPTCHA-gated, so the form checks look for the email errors
 *              next to the captcha error, and the fix flow starts from a
 *              pending fixture account inserted here. Fixture addresses under
 *              @test.strabospot.org are filed to the mail log, never sent.
 *
 *   docker exec strabo-php php /srv/app/www/tests/account/register_email_test.php
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/StraboMail.php';
require_once 'includes/RegisterEmail.php';

$BASE = 'http://localhost';
$rand = substr(bin2hex(random_bytes(4)), 0, 8);
$D = StraboMail::FIXTURE_DOMAIN;

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
function token_in($page) { return preg_match('/name="fixtoken" value="([^"]+)"/', $page, $m) ? html_entity_decode($m[1]) : ''; }
function user($pkey) { global $db; return $db->get_row_prepared("SELECT email, hash, active FROM users WHERE pkey = $1", array($pkey)); }

echo "Register email suite\n";

// --- suggest()
$cases = array(
	'a@gnail.com' => 'a@gmail.com', 'a@gmai.com' => 'a@gmail.com', 'a@gmil.com' => 'a@gmail.com',
	'a@gmial.com' => 'a@gmail.com', 'a@gmail.con' => 'a@gmail.com', 'a@gmail.co' => 'a@gmail.com',
	'a@gnail.co' => 'a@gmail.com', 'a@hotmial.com' => 'a@hotmail.com', 'a@yaho.com' => 'a@yahoo.com',
	'a@outlok.com' => 'a@outlook.com', 'a@iclod.com' => 'a@icloud.com', 'a@ku.edi' => 'a@ku.edu',
	'a@hotmal.fr' => 'a@hotmail.fr', 'a@gmal.co.uk' => 'a@gmail.co.uk',
	'a@gmail.com' => null, 'a@ymail.com' => null, 'a@email.com' => null, 'a@mail.com' => null,
	'a@yahoo.ca' => null, 'a@yahoo.co.uk' => null, 'a@hotmail.fr' => null, 'a@ku.edu' => null,
	'a@uvm.edu' => null, 'a@appstate.edu' => null, 'a@uit.no' => null, 'a@live.com' => null,
	' A@GNAIL.COM ' => 'a@gmail.com', 'nodomain' => null, 'a@localhost' => null,
);
$bad = array();
foreach ($cases as $in => $want) { $got = RegisterEmail::suggest($in); if ($got !== $want) $bad[] = "$in => " . var_export($got, true); }
check('suggest: ' . count($cases) . ' typo / real-domain cases', count($bad) == 0, implode('; ', $bad));

// --- tokens + hashes
$t = RegisterEmail::fixToken(12, 'abc', 0, 'k');
check('fixToken: signed over the hash (a new hash gives a different token)', $t !== RegisterEmail::fixToken(12, 'abd', 0, 'k') && preg_match('/^12\.0\.[0-9a-f]{64}$/', $t));
$h = RegisterEmail::newHash();
check('newHash: 21 letters/digits, validate-route safe', preg_match('/^[A-Za-z0-9]{21}$/', $h) && $h !== RegisterEmail::newHash());

// --- registration form (reCAPTCHA fails here, the email errors still show)
$base = array('submit' => 'Submit', 'firstname' => 'Reg', 'lastname' => 'Fixture', 'password' => 'pw', 'passwordconfirm' => 'pw');
list($c, $b) = post('/register.php', $base + array('email' => "reg-$rand@$D", 'emailconfirm' => "reg-$rand-x@$D"));
check('form: mismatched Confirm Email is refused', $c === 200 && strpos($b, "Email addresses don't match.") !== false, $c);
check('form: the page has the Confirm Email field', strpos($b, 'name="emailconfirm"') !== false);
check('form: the typed values come back in the fields', strpos($b, 'value="reg-' . $rand . '-x@' . $D . '"') !== false);
list($c, $b) = post('/register.php', $base + array('email' => "reg-$rand@$D", 'emailconfirm' => "REG-$rand@$D "));
// With REGISTER_SKIP_CAPTCHA (dev) this one registers for real; zero residue removes it.
$skip = defined('REGISTER_SKIP_CAPTCHA') && REGISTER_SKIP_CAPTCHA === true;
check('form: matching addresses (case / spaces ignored) pass the email checks', strpos($b, "don't match") === false && strpos($b, 'Invalid Email') === false && strpos($b, $skip ? "emailed to <b>reg-$rand@$D</b>" : 'check the captcha box') !== false, substr(strip_tags($b), 0, 300));
list($c, $b) = post('/register.php', $base + array('email' => "reg-$rand@gnail.com", 'emailconfirm' => "reg-$rand@gnail.com"));
$dnsok = checkdnsrr('gnail.com', 'MX') || checkdnsrr('gnail.com', 'A');
if ($dnsok) {
	check('form: gnail.com gets the "Did you mean" hint + Use button', strpos($b, 'Did you mean reg-' . $rand . '@<span class="regmsg-domain">gmail.com</span>?') !== false && strpos($b, 'useSuggestedEmail(&quot;reg-' . $rand . '@gmail.com&quot;)') !== false, substr(strip_tags($b), 0, 300));
	check('form: the hint remembers the address it warned about', strpos($b, 'name="email_checked" value="reg-' . $rand . '@gnail.com"') !== false);
	list($c, $b) = post('/register.php', $base + array('email' => "reg-$rand@gnail.com", 'emailconfirm' => "reg-$rand@gnail.com", 'email_checked' => "reg-$rand@gnail.com"));
	check('form: submitting the same address again accepts it', strpos($b, 'Did you mean') === false, substr(strip_tags($b), 0, 300));
} else {
	echo "  SKIP  gnail.com has no DNS from here\n";
}
check('form: no PHP warnings', stripos($b, 'Warning:') === false && stripos($b, 'Fatal error') === false);

// --- "Wrong address? Fix it" flow
$old = "fix-$rand@$D"; $new = "fixed-$rand@$D"; $taken = "taken-$rand@$D";
$hash0 = RegisterEmail::newHash();
$db->prepare_query("INSERT INTO users (firstname, lastname, password, hash, email, active, deleted) VALUES ('Fix', 'Fixture', 'x', $1, $2, false, false)", array($hash0, $old));
$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($old));
$db->prepare_query("INSERT INTO users (firstname, lastname, password, hash, email, active, deleted) VALUES ('Taken', 'Fixture', 'x', $1, $2, true, false)", array(RegisterEmail::newHash(), $taken));
$tok0 = RegisterEmail::fixToken($pkey, $hash0, 0, $jwtsecret);

list($c, $b) = post('/register.php', array('fix_submit' => 'x', 'fixtoken' => $tok0, 'email' => $new, 'emailconfirm' => "other-$rand@$D"));
check('fix: mismatched addresses refused, form stays open, nothing changed', strpos($b, "Email addresses don't match.") !== false && strpos($b, '<details id="fixemail" open') !== false && user($pkey)->email === $old);
list($c, $b) = post('/register.php', array('fix_submit' => 'x', 'fixtoken' => $tok0, 'email' => $taken, 'emailconfirm' => $taken));
check('fix: an address already registered is refused', strpos($b, "is already registered") !== false && user($pkey)->email === $old);

$mark = mail_len();
list($c, $b) = post('/register.php', array('fix_submit' => 'x', 'fixtoken' => $tok0, 'email' => $new, 'emailconfirm' => $new));
$u = user($pkey); $m = mail_since($mark);
check('fix: address changed and a NEW validation hash set', $u->email === $new && $u->hash !== $hash0 && preg_match('/^[A-Za-z0-9]{21}$/', $u->hash), json_encode($u));
check('fix: one validation mail to the new address with the new link', substr_count($m, "To: $new") === 1 && strpos($m, "https://strabospot.org/validate/" . $u->hash) !== false && strpos($m, "To: $old") === false, substr($m, 0, 400));
check('fix: page names the new address and offers the fix form again', strpos($b, "emailed to <b>$new</b>") !== false && token_in($b) === RegisterEmail::fixToken($pkey, $u->hash, 1, $jwtsecret));
$tok1 = token_in($b);

$ch = curl_init("$BASE/validate/$hash0"); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); $vb = (string)curl_exec($ch); $vc = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
check('fix: the first link now shows the friendly invalid-link page and activates nothing', $vc === 200 && strpos($vb, 'Link No Longer Valid') !== false && strpos($vb, 'href="/resendlink"') !== false && user($pkey)->active === 'f', "$vc " . substr(strip_tags($vb), 0, 200));
list($c, $b) = post('/register.php', array('fix_submit' => 'x', 'fixtoken' => $tok0, 'email' => "again-$rand@$D", 'emailconfirm' => "again-$rand@$D"));
check('fix: the old token is spent', strpos($b, 'can no longer be changed') !== false && user($pkey)->email === $new);
list($c, $b) = post('/register.php', array('fix_submit' => 'x', 'fixtoken' => preg_replace('/.$/', '0', $tok1) === $tok1 ? preg_replace('/.$/', '1', $tok1) : preg_replace('/.$/', '0', $tok1), 'email' => "again-$rand@$D", 'emailconfirm' => "again-$rand@$D"));
check('fix: a forged token is refused', strpos($b, 'can no longer be changed') !== false && user($pkey)->email === $new);

$db->prepare_query("UPDATE users SET signup_date = now() - interval '25 hours' WHERE pkey = $1", array($pkey));
list($c, $b) = post('/register.php', array('fix_submit' => 'x', 'fixtoken' => $tok1, 'email' => "again-$rand@$D", 'emailconfirm' => "again-$rand@$D"));
check('fix: refused after the 24 hour window', strpos($b, 'can no longer be changed') !== false && user($pkey)->email === $new);
$db->prepare_query("UPDATE users SET signup_date = now(), active = true WHERE pkey = $1", array($pkey));
list($c, $b) = post('/register.php', array('fix_submit' => 'x', 'fixtoken' => $tok1, 'email' => "again-$rand@$D", 'emailconfirm' => "again-$rand@$D"));
check('fix: refused once the account is active', strpos($b, 'can no longer be changed') !== false && user($pkey)->email === $new);
$db->prepare_query("UPDATE users SET active = false WHERE pkey = $1", array($pkey));
$u = user($pkey);
list($c, $b) = post('/register.php', array('fix_submit' => 'x', 'fixtoken' => RegisterEmail::fixToken($pkey, $u->hash, RegisterEmail::MAX_FIXES, $jwtsecret), 'email' => "again-$rand@$D", 'emailconfirm' => "again-$rand@$D"));
check('fix: refused after ' . RegisterEmail::MAX_FIXES . ' changes', strpos($b, 'can no longer be changed') !== false && user($pkey)->email === $new);
check('fix: no PHP warnings', stripos($b, 'Warning:') === false && stripos($b, 'Fatal error') === false);

$db->prepare_query("DELETE FROM users WHERE pkey = $1 OR email LIKE $2", array($pkey, "%-$rand@$D"));
check('zero residue', (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE email LIKE $1", array("%-$rand@$D")) === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
