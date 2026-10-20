<?php
/**
 * File: tests/account/mail_sender_test.php
 * Description: Site mail sender settings (StraboMail::smtpSettings):
 *              without $strabo_mail_smtp the site Gmail account is used as
 *              before; with it (Postmark, docs/Email_Sender_Runbook.md) the
 *              built message is From noreply@strabospot.org with Reply-To and
 *              the Postmark stream header. Messages are built and
 *              preSend()-ed only, never sent. Also: userRegister.php (the
 *              JSON registration API) sends the StraboMail validation message.
 *
 *   docker exec strabo-php php /srv/app/www/tests/account/mail_sender_test.php
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/StraboMail.php';

$BASE = 'http://localhost';
$rand = substr(bin2hex(random_bytes(4)), 0, 8);
$D = StraboMail::FIXTURE_DOMAIN;

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr($detail, 0, 400) . "]" : '') . "\n"; }
}
function mail_len() { $f = StraboMail::logFile(); clearstatcache(true, $f); return is_file($f) ? filesize($f) : 0; }
function mail_since($from) { $f = StraboMail::logFile(); clearstatcache(true, $f); return is_file($f) ? (string)substr(file_get_contents($f), $from) : ''; }
function mime($to) {
	$m = StraboMail::buildMailer($to, 'Sender check', StraboMail::render(array('title' => 'Sender check')));
	$m->preSend();
	return array($m, $m->getSentMIMEMessage());
}

echo "Mail sender suite\n";
$saved = isset($GLOBALS['strabo_mail_smtp']) ? $GLOBALS['strabo_mail_smtp'] : null;
unset($GLOBALS['strabo_mail_smtp']);

// --- default: the site Gmail account (dev has no Gmail password; a placeholder lets the message build)
$savedpass = $GLOBALS['straboemailpassword'] ?? null;
if ((string)$savedpass === '') $GLOBALS['straboemailpassword'] = 'placeholder';
$s = StraboMail::smtpSettings();
check('default: smtp.gmail.com:587 tls as the config Gmail account', $s['host'] === 'smtp.gmail.com' && $s['port'] === 587 && $s['secure'] === 'tls' && $s['from'] === $straboemailaddress && $s['user'] === $straboemailaddress && $s['reply_to'] === '' && $s['headers'] === array());
list($m, $mime) = mime("gmail-$rand@$D");
check('default: built message is From the Gmail account, no Reply-To, no Postmark header', $m->Host === 'smtp.gmail.com' && strpos($mime, "From: StraboSpot <$straboemailaddress>") !== false && stripos($mime, 'Reply-To:') === false && stripos($mime, 'X-PM-Message-Stream') === false);

$GLOBALS['straboemailpassword'] = $savedpass;

// --- configured: Postmark
$GLOBALS['strabo_mail_smtp'] = array(
	'host' => 'smtp.postmarkapp.com', 'port' => 587, 'user' => 'TOKEN-x', 'pass' => 'TOKEN-x',
	'from' => 'noreply@strabospot.org', 'from_name' => 'StraboSpot', 'reply_to' => 'strabospot@gmail.com',
	'headers' => array('X-PM-Message-Stream' => 'outbound'),
);
$s = StraboMail::smtpSettings();
check('configured: settings come from $strabo_mail_smtp', $s['host'] === 'smtp.postmarkapp.com' && $s['port'] === 587 && $s['secure'] === 'tls' && $s['user'] === 'TOKEN-x' && $s['from'] === 'noreply@strabospot.org');
list($m, $mime) = mime("pm-$rand@$D");
check('configured: server + login on the mailer', $m->Host === 'smtp.postmarkapp.com' && $m->Port === 587 && $m->Username === 'TOKEN-x' && $m->Password === 'TOKEN-x');
check('configured: From noreply@strabospot.org', strpos($mime, 'From: StraboSpot <noreply@strabospot.org>') !== false, substr($mime, 0, 600));
check('configured: Reply-To strabospot@gmail.com', strpos($mime, 'Reply-To: StraboSpot <strabospot@gmail.com>') !== false);
check('configured: X-PM-Message-Stream: outbound', strpos($mime, 'X-PM-Message-Stream: outbound') !== false);
check('configured: still HTML + text + inline logo', strpos($mime, 'multipart/alternative') !== false && strpos($mime, 'Content-ID: <' . StraboMail::LOGO_CID . '>') !== false);

$mark = mail_len();
StraboMail::send("filed-$rand@$D", 'Sender check', StraboMail::render(array('title' => 'Sender check')));
$f = mail_since($mark);
check('configured: filed fixture mail records From + Reply-To', strpos($f, "To: filed-$rand@$D\nSubject: Sender check\nFrom: noreply@strabospot.org\nReply-To: strabospot@gmail.com\n") !== false, $f);

$GLOBALS['strabo_mail_smtp']['pass'] = '';
$threw = false;
try { StraboMail::buildMailer("x-$rand@$D", 'x', StraboMail::render(array('title' => 'x'))); } catch (Exception $e) { $threw = strpos($e->getMessage(), 'not configured') !== false; }
check('configured: a missing token refuses to send', $threw);

if ($saved === null) unset($GLOBALS['strabo_mail_smtp']); else $GLOBALS['strabo_mail_smtp'] = $saved;

// --- userRegister.php (JSON registration API) uses StraboMail
$email = "apireg-$rand@$D";
$mark = mail_len();
$ch = curl_init("$BASE/userRegister.php");
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => array('Content-Type: application/json'), CURLOPT_TIMEOUT => 60,
	CURLOPT_POSTFIELDS => json_encode(array('first_name' => 'Api', 'last_name' => 'Fixture', 'email' => $email, 'password' => 'password123', 'confirm_password' => 'password123'))));
$b = (string)curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$j = json_decode($b, true);
$hash = (string)$db->get_var_prepared("SELECT hash FROM users WHERE email = $1", array($email));
$f = mail_since($mark);
check('userRegister: account created, answers valid', $c === 200 && isset($j['valid']) && $j['valid'] === 'true' && $hash !== '', "$c $b");
check('userRegister: one StraboMail validation mail with the https://strabospot.org link', substr_count($f, "To: $email") === 1 && strpos($f, 'Subject: Confirm your StraboSpot account') !== false && strpos($f, "https://strabospot.org/validate/$hash") !== false && strpos($f, 'Hi Api,') !== false, substr($f, 0, 500));
check('userRegister: no PHP warnings', stripos($b, 'Warning') === false && stripos($b, 'Fatal') === false, $b);

$db->prepare_query("DELETE FROM users WHERE email LIKE $1", array("%-$rand@$D"));
check('zero residue', (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE email LIKE $1", array("%-$rand@$D")) === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
