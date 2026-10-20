<?php
/**
 * File: tests/account/send_test_mail.php
 * Description: Sends ONE real copy of the registration confirmation email to
 *              the given address through the configured site mail server
 *              (StraboMail::smtpSettings: $strabo_mail_smtp, else Gmail), and
 *              prints the server and From it used (never the password). For
 *              mail-tester.com checks (docs/Email_Sender_Runbook.md Step 6).
 *              The confirm link is a dummy and validates nothing.
 *
 *   docker exec strabo-php php /srv/app/www/tests/account/send_test_mail.php test-xxxx@srv1.mail-tester.com
 */

if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

chdir(dirname(__DIR__, 2));
$_SERVER['DOCUMENT_ROOT'] = getcwd();
require_once 'includes/config.inc.php';
require_once 'includes/StraboMail.php';

$to = isset($argv[1]) ? trim($argv[1]) : '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "usage: php send_test_mail.php ADDRESS\n"); exit(2); }

$cfg = StraboMail::smtpSettings();
echo "server:   " . $cfg['host'] . ':' . $cfg['port'] . "\n";
echo "from:     " . $cfg['from_name'] . ' <' . $cfg['from'] . ">\n";
echo "reply-to: " . ($cfg['reply_to'] !== '' ? $cfg['reply_to'] : '(none)') . "\n";
foreach ($cfg['headers'] as $name => $value) echo "header:   $name: $value\n";

$rendered = StraboMail::render(array(
	'title'    => 'Confirm your StraboSpot account',
	'greeting' => 'Hi Test,',
	'intro'    => array('Thanks for your interest in StraboSpot. Please confirm your email address to activate your account.'),
	'facts'    => array('Account' => $to),
	'button'   => array('Confirm my account', 'https://strabospot.org/validate/' . bin2hex(random_bytes(16))),
	'after'    => array('If you did not create a StraboSpot account, you can ignore this message and nothing will happen.'),
	'footer'   => 'You received this because this address was used to register at StraboSpot (https://strabospot.org).',
));

try {
	$transport = StraboMail::send($to, 'Confirm your StraboSpot account', $rendered, array('to_name' => 'Test User', 'transport' => 'smtp'));
	echo "sent:     $transport\n";
} catch (Exception $e) {
	echo "FAILED:   " . $e->getMessage() . "\n";
	exit(1);
}
