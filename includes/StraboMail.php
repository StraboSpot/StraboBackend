<?php
/**
 * File: includes/StraboMail.php
 * Description: Branded HTML notification email for the site: one template
 *              (logo header, title, greeting, paragraphs, fact table, call to
 *              action button, closing, footer) rendered to HTML + a plain text
 *              alternative, and one sender over the site's SMTP account
 *              (PHPMailer, the forgotpassword.php pattern) with the logo
 *              embedded inline (CID), so it shows with images blocked-by-URL
 *              clients as well.
 *
 *              Used by exportjobs/lib/ExportMailer.php (export ready / failed)
 *              and invite_collaborators.php (collaboration invitation).
 *
 *              Transport (StraboMail::transport):
 *                - explicit $opts['transport'] (smtp | file | none), else
 *                - $GLOBALS['strabo_mail_transport'] (config.inc.php), else
 *                - STRABO_MAIL_TRANSPORT env var, else smtp.
 *              Addresses under @test.strabospot.org (the suites' fixture
 *              domain) are ALWAYS filed, never sent, so suites can drive the
 *              real Apache pages without mailing anyone. "file" appends to
 *              exportjobs_data/log/mail.log (export_config()['log_root']).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class StraboMail
{
	const FIXTURE_DOMAIN = 'test.strabospot.org';
	const LOGO_CID       = 'strabospot-logo';

	// Palette = the site theme (massets/css/main.css): dark #272833 / #1c1d26, accent #e44c65.
	const C_DARK   = '#272833';
	const C_ACCENT = '#e44c65';
	const C_TEXT   = '#2b2d3a';
	const C_MUTED  = '#6f7286';
	const C_PAGE   = '#eef0f4';
	const C_RULE   = '#e1e3ea';

	/** Absolute path of the inline logo (120 x 139 PNG, resampled from files/strabospot_pub_logo.png). */
	public static function logoPath()
	{
		return __DIR__ . '/images/email_logo.png';
	}

	/**
	 * Render a notification.
	 *
	 * @param array $m {
	 *   title       string   headline (required)
	 *   greeting    string   e.g. "Hi Jason,"
	 *   intro       string[] paragraphs before the facts
	 *   facts       array    label => value rows (values may be arrays: [text, url] for a link)
	 *   button      array    [label, url]  call to action
	 *   after       string[] paragraphs after the button
	 *   closing     string[] sign-off lines (default "Thanks," / "The StraboSpot Team")
	 *   footer      string   small print under the card
	 *   site_url    string   default https://strabospot.org
	 * }
	 * @return array {html, text}
	 */
	public static function render(array $m)
	{
		$site = isset($m['site_url']) ? rtrim($m['site_url'], '/') : 'https://strabospot.org';
		$title = isset($m['title']) ? (string)$m['title'] : 'StraboSpot';
		$greeting = isset($m['greeting']) ? (string)$m['greeting'] : '';
		$intro = isset($m['intro']) ? (array)$m['intro'] : array();
		$facts = isset($m['facts']) ? (array)$m['facts'] : array();
		$button = isset($m['button']) && is_array($m['button']) && count($m['button']) === 2 ? $m['button'] : null;
		$after = isset($m['after']) ? (array)$m['after'] : array();
		$closing = isset($m['closing']) ? (array)$m['closing'] : array('Thanks,', 'The StraboSpot Team');
		$footer = isset($m['footer']) ? (string)$m['footer'] : 'This message was sent by StraboSpot (' . $site . ').';

		$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
		$p = function ($s) use ($e) {
			return '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.55;color:' . self::C_TEXT . ';">' . self::linkify($e($s)) . '</p>';
		};

		// ---- plain text
		$t = array();
		$t[] = strtoupper('StraboSpot');
		$t[] = $title;
		$t[] = '';
		if ($greeting !== '') { $t[] = $greeting; $t[] = ''; }
		foreach ($intro as $s) { $t[] = $s; $t[] = ''; }
		foreach ($facts as $k => $v) {
			if (is_array($v)) $v = $v[0] . (isset($v[1]) && $v[1] !== '' ? ' (' . $v[1] . ')' : '');
			$t[] = $k . ': ' . $v;
		}
		if ($facts) $t[] = '';
		if ($button) { $t[] = $button[0] . ':'; $t[] = $button[1]; $t[] = ''; }
		foreach ($after as $s) { $t[] = $s; $t[] = ''; }
		foreach ($closing as $s) $t[] = $s;
		$t[] = '';
		$t[] = '-- ';
		$t[] = $footer;
		$text = implode("\n", $t);

		// ---- HTML (tables + inline styles: mail clients)
		$h = array();
		$h[] = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		$h[] = '<title>' . $e($title) . '</title></head>';
		$h[] = '<body style="margin:0;padding:0;background:' . self::C_PAGE . ';font-family:Helvetica,Arial,sans-serif;">';
		$h[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::C_PAGE . ';"><tr><td align="center" style="padding:28px 12px;">';
		$h[] = '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">';
		// header bar
		$h[] = '<tr><td style="background:' . self::C_DARK . ';padding:18px 28px;border-top:4px solid ' . self::C_ACCENT . ';">';
		$h[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>';
		$h[] = '<td style="vertical-align:middle;padding-right:14px;"><a href="' . $e($site) . '" style="text-decoration:none;"><img src="cid:' . self::LOGO_CID . '" width="48" height="56" alt="StraboSpot" style="display:block;border:0;width:48px;height:56px;"></a></td>';
		$h[] = '<td style="vertical-align:middle;"><a href="' . $e($site) . '" style="text-decoration:none;color:#ffffff;font-size:22px;letter-spacing:2px;font-weight:300;">STRABOSPOT</a></td>';
		$h[] = '</tr></table></td></tr>';
		// body
		$h[] = '<tr><td style="padding:28px 28px 8px 28px;">';
		$h[] = '<h1 style="margin:0 0 18px 0;font-size:22px;line-height:1.3;font-weight:400;color:' . self::C_DARK . ';">' . $e($title) . '</h1>';
		if ($greeting !== '') $h[] = $p($greeting);
		foreach ($intro as $s) $h[] = $p($s);
		if ($facts) {
			$h[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:6px 0 18px 0;border-top:1px solid ' . self::C_RULE . ';">';
			foreach ($facts as $k => $v) {
				if (is_array($v)) {
					$val = isset($v[1]) && $v[1] !== '' ? '<a href="' . $e($v[1]) . '" style="color:' . self::C_ACCENT . ';">' . $e($v[0]) . '</a>' : $e($v[0]);
				} else {
					$val = self::linkify($e($v));
				}
				$h[] = '<tr><td style="padding:9px 12px 9px 0;font-size:13px;color:' . self::C_MUTED . ';white-space:nowrap;vertical-align:top;border-bottom:1px solid ' . self::C_RULE . ';">' . $e($k) . '</td>'
					. '<td style="padding:9px 0;font-size:15px;color:' . self::C_TEXT . ';vertical-align:top;border-bottom:1px solid ' . self::C_RULE . ';">' . $val . '</td></tr>';
			}
			$h[] = '</table>';
		}
		if ($button) {
			$h[] = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 22px 0;"><tr><td style="background:' . self::C_ACCENT . ';border-radius:5px;">';
			$h[] = '<a href="' . $e($button[1]) . '" style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:#ffffff;text-decoration:none;">' . $e($button[0]) . '</a>';
			$h[] = '</td></tr></table>';
			$h[] = '<p style="margin:-10px 0 18px 0;font-size:12px;line-height:1.5;color:' . self::C_MUTED . ';">Or open this link: <a href="' . $e($button[1]) . '" style="color:' . self::C_ACCENT . ';word-break:break-all;">' . $e($button[1]) . '</a></p>';
		}
		foreach ($after as $s) $h[] = $p($s);
		if ($closing) {
			$h[] = '<p style="margin:18px 0 0 0;font-size:15px;line-height:1.55;color:' . self::C_TEXT . ';">' . implode('<br>', array_map($e, $closing)) . '</p>';
		}
		$h[] = '</td></tr>';
		// footer
		$h[] = '<tr><td style="padding:18px 28px 22px 28px;"><p style="margin:0;font-size:12px;line-height:1.5;color:' . self::C_MUTED . ';border-top:1px solid ' . self::C_RULE . ';padding-top:14px;">' . self::linkify($e($footer)) . '</p></td></tr>';
		$h[] = '</table></td></tr></table></body></html>';
		$html = implode("\n", $h);

		return array('html' => $html, 'text' => $text);
	}

	/** Turn bare http(s) URLs in already-escaped text into links (trailing sentence punctuation stays outside). */
	private static function linkify($escaped)
	{
		return preg_replace_callback('#https?://[^\s<&]+(?:&amp;[^\s<&]+)*#', function ($m) {
			$url = rtrim($m[0], '.,;:!?)');
			$tail = substr($m[0], strlen($url));
			return '<a href="' . $url . '" style="color:' . self::C_ACCENT . ';">' . $url . '</a>' . $tail;
		}, $escaped);
	}

	/** Which transport applies to this recipient. */
	public static function transport($to, array $opts = array())
	{
		if (substr(strtolower((string)$to), -strlen('@' . self::FIXTURE_DOMAIN)) === '@' . self::FIXTURE_DOMAIN) return 'file';
		if (isset($opts['transport']) && in_array($opts['transport'], array('smtp', 'file', 'none'), true)) return $opts['transport'];
		if (isset($GLOBALS['strabo_mail_transport']) && in_array($GLOBALS['strabo_mail_transport'], array('smtp', 'file', 'none'), true)) return $GLOBALS['strabo_mail_transport'];
		$env = getenv('STRABO_MAIL_TRANSPORT');
		if ($env !== false && in_array($env, array('smtp', 'file', 'none'), true)) return $env;
		return 'smtp';
	}

	/** Where "file" transport appends. */
	public static function logFile()
	{
		if (!function_exists('export_config')) require_once __DIR__ . '/../exportjobs/lib/export_config.php';
		$cfg = export_config();
		$dir = rtrim($cfg['log_root'], '/');
		if (!is_dir($dir)) @mkdir($dir, 0775, true);
		return $dir . '/mail.log';
	}

	/**
	 * Send (or file) a rendered message.
	 *
	 * @param string $to        recipient address (validated)
	 * @param string $subject
	 * @param array  $rendered  from render(): {html, text}
	 * @param array  $opts      transport (smtp|file|none), to_name
	 * @return string transport used: smtp | file | none
	 * @throws Exception on a bad address or an SMTP failure
	 */
	public static function send($to, $subject, array $rendered, array $opts = array())
	{
		if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new Exception('invalid recipient address');
		$transport = self::transport($to, $opts);
		if ($transport === 'none') return 'none';
		if ($transport === 'file') {
			$line = '[' . date('Y-m-d H:i:s') . "] To: $to\nSubject: $subject\n" . $rendered['text'] . "\n---\n";
			file_put_contents(self::logFile(), $line, FILE_APPEND);
			return 'file';
		}

		$root = dirname(__DIR__);
		if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
			require_once $root . '/includes/PHPMailer/PHPMailer/src/Exception.php';
			require_once $root . '/includes/PHPMailer/PHPMailer/src/PHPMailer.php';
			require_once $root . '/includes/PHPMailer/PHPMailer/src/SMTP.php';
		}
		$from = isset($GLOBALS['straboemailaddress']) ? $GLOBALS['straboemailaddress'] : null;
		$pass = isset($GLOBALS['straboemailpassword']) ? $GLOBALS['straboemailpassword'] : null;
		if (!$from || !$pass) throw new Exception('site mail account is not configured');

		$mail = new PHPMailer\PHPMailer\PHPMailer(true);
		$mail->isSMTP();
		$mail->SMTPDebug = 0;
		$mail->Host = 'smtp.gmail.com';
		$mail->SMTPAuth = true;
		$mail->SMTPSecure = 'tls';
		$mail->Port = 587;
		$mail->Username = $from;
		$mail->Password = $pass;
		$mail->From = $from;
		$mail->FromName = 'StraboSpot';
		$mail->addAddress($to, isset($opts['to_name']) ? (string)$opts['to_name'] : '');
		$mail->isHTML(true);
		$mail->CharSet = 'UTF-8';
		$mail->Encoding = 'base64';
		$mail->Subject = $subject;
		$mail->Body = $rendered['html'];
		$mail->AltBody = $rendered['text'];
		$logo = self::logoPath();
		if (is_file($logo)) $mail->addEmbeddedImage($logo, self::LOGO_CID, 'strabospot.png', 'base64', 'image/png');
		$mail->send();
		return 'smtp';
	}
}
