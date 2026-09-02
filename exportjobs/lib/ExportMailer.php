<?php
/**
 * File: exportjobs/lib/ExportMailer.php
 * Description: "Your export is ready" / "failed" notification (design §9.6,
 *              D9). Sent by the worker after a job reaches done or failed
 *              when the row has email_on_done. PHPMailer over the site's
 *              SMTP account, exactly the forgotpassword.php pattern; the
 *              message links to My Exports (never the file itself, no
 *              attachment). Failures are logged and never fail the job.
 *
 *              Transport comes from export_config()['mail_transport']:
 *              smtp (default), file (append to log_root/mail.log; the test
 *              suites use this) or none.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/export_config.php';

class ExportMailer
{
	private $db;
	private $cfg;
	private $log;

	public function __construct($db, array $cfg = null, $logFn = null)
	{
		$this->db  = $db;
		$this->cfg = $cfg === null ? export_config() : $cfg;
		$this->log = $logFn ? $logFn : function ($m) {};
	}

	/** Called by the runner with the finished row. Returns true when a message was sent (or filed). */
	public function notify(array $job)
	{
		$log = $this->log;
		if (empty($job['email_on_done'])) return false;
		if ($job['status'] !== 'done' && $job['status'] !== 'failed') return false;

		$u = $this->db->get_row_prepared(
			"SELECT email, firstname FROM users WHERE pkey = $1 AND (deleted IS NULL OR deleted = FALSE)",
			array((int)$job['userpkey']));
		if (!$u || !filter_var($u->email, FILTER_VALIDATE_EMAIL)) {
			$log("mail: no valid address for user {$job['userpkey']} (job {$job['uuid']})");
			return false;
		}
		$m = $this->compose($job, $u);
		$transport = isset($this->cfg['mail_transport']) ? $this->cfg['mail_transport'] : 'smtp';
		try {
			if ($transport === 'none') return false;
			if ($transport === 'file') {
				$line = '[' . date('Y-m-d H:i:s') . "] To: {$u->email}\nSubject: {$m['subject']}\n{$m['text']}\n---\n";
				file_put_contents(rtrim($this->cfg['log_root'], '/') . '/mail.log', $line, FILE_APPEND);
				$log("mail: filed for {$u->email} (job {$job['uuid']})");
				return true;
			}
			$this->sendSmtp($u->email, $m['subject'], $m['html']);
			$log("mail: sent to {$u->email} (job {$job['uuid']})");
			return true;
		} catch (Exception $e) {
			$log("mail: FAILED for {$u->email} (job {$job['uuid']}): " . $e->getMessage());
			return false;
		}
	}

	/** @return array {subject, text, html} */
	public function compose(array $job, $user)
	{
		$site = rtrim($this->cfg['site_url'], '/');
		$summary = $job['recipe_summary'] !== null && $job['recipe_summary'] !== '' ? $job['recipe_summary'] : 'Custom export';
		$name = ($user && !empty($user->firstname)) ? $user->firstname : 'there';
		if ($job['status'] === 'done') {
			$subject = 'Your StraboSpot export is ready';
			$lines = array(
				"Hi $name,",
				'',
				'Your StraboSpot export has finished building.',
				'',
				'Export: ' . $summary,
				'Spots: ' . number_format((int)$job['item_count'])
					. (!empty($job['child_count']) ? ' (plus ' . number_format((int)$job['child_count']) . ' nested child spots)' : ''),
				'Size: ' . self::humanBytes($job['result_bytes']),
				'Available until: ' . self::humanDate($job['expires_at']),
				'',
				'Download it from My Exports:',
				"$site/my_exports",
				'',
				'The file is removed after the date above; you can re-run the export from that page at any time.',
			);
		} else {
			$subject = 'Your StraboSpot export could not be built';
			$lines = array(
				"Hi $name,",
				'',
				'Your StraboSpot export did not finish.',
				'',
				'Export: ' . $summary,
				'Problem: ' . ($job['error_text'] !== null ? $job['error_text'] : 'unknown error'),
				'',
				'You can adjust and re-run it from My Exports:',
				"$site/my_exports",
			);
		}
		$lines[] = '';
		$lines[] = 'Thanks,';
		$lines[] = 'The StraboSpot Team';
		$text = implode("\n", $lines);
		$html = '<html><body><h2>StraboSpot</h2>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</body></html>';
		$html = preg_replace('#(https?://[^\s<]+)#', '<a href="$1">$1</a>', $html);
		return array('subject' => $subject, 'text' => $text, 'html' => $html);
	}

	private function sendSmtp($to, $subject, $html)
	{
		$root = dirname(__DIR__, 2);
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
		$mail->addAddress($to);
		$mail->isHTML(true);
		$mail->CharSet = 'UTF-8';
		$mail->Encoding = 'base64';
		$mail->Subject = $subject;
		$mail->Body = $html;
		$mail->send();
	}

	public static function humanBytes($n)
	{
		$n = (float)$n;
		if ($n >= 1073741824) return number_format($n / 1073741824, 2) . ' GB';
		if ($n >= 1048576)    return number_format($n / 1048576, 1) . ' MB';
		if ($n >= 1024)       return number_format($n / 1024, 0) . ' KB';
		return number_format($n) . ' bytes';
	}

	public static function humanDate($ts)
	{
		if (!$ts) return 'unknown';
		$t = strtotime($ts);
		return $t ? date('F j, Y', $t) : (string)$ts;
	}
}
