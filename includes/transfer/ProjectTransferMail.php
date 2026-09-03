<?php
/**
 * File: includes/transfer/ProjectTransferMail.php
 * Description: The notification mails of a StraboField project transfer
 *              (docs/ProjectTransfer_Design.md §8), composed on the site
 *              template (includes/StraboMail.php) and sent through it.
 *              Every send is wrapped: a mail failure is logged and never
 *              breaks the page or the transfer. Fixture addresses under
 *              @test.strabospot.org are filed to mail.log by StraboMail.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/../StraboMail.php';
require_once __DIR__ . '/ProjectTransfer.php';

class ProjectTransferMail
{
	const SITE = 'https://strabospot.org';

	private $db;
	private $neodb;
	private $svc;

	public function __construct($db, $neodb)
	{
		$this->db = $db;
		$this->neodb = $neodb;
		$this->svc = new ProjectTransfer($db, $neodb);
	}

	// =======================================================================
	// The six events
	// =======================================================================

	/** To the recipient: a project is being offered, with the review link. */
	public function request($row)
	{
		$from = $this->user($row->from_user_pkey);
		$to = $this->user($row->to_user_pkey);
		if (!$from || !$to) return array();
		$name = $this->projectName($row);
		$counts = $this->counts($row, (int)$row->from_user_pkey);
		$who = $this->who($from);
		$facts = array(
			'Project'       => $name,
			'Current owner' => $who,
			'Datasets'      => (string)$counts['datasets'],
			'Spots'         => (string)$counts['spots'],
			'Photos'        => (string)$counts['images'],
			'Expires'       => date('F j, Y', strtotime($row->expires_date)),
		);
		$facts['After the transfer'] = $this->keep($row)
			? $from->firstname . ' stays on the project as an admin collaborator'
			: $from->firstname . ' will no longer have access to the project';
		$m = StraboMail::render(array(
			'title'    => 'A StraboField project is being offered to you',
			'greeting' => 'Hi ' . $this->first($to) . ',',
			'intro'    => array("$who wants to transfer the StraboField project \"$name\" to your StraboSpot account."),
			'facts'    => $facts,
			'button'   => array('Review the transfer', self::SITE . '/transfer_respond?t=' . $row->uuid),
			'after'    => array(
				'Nothing changes until you accept. Accepting makes you the owner of the project and everything in it: datasets, spots, photos, tags, samples and saved versions. The transfer cannot be undone from your account.',
				'You can also decline. The request expires on ' . date('F j, Y', strtotime($row->expires_date)) . ' if you do nothing.',
			),
			'site_url' => self::SITE,
			'footer'   => "You received this because $who asked to transfer a project to the StraboSpot account {$to->email}. If you were not expecting it, decline it or simply ignore this message.",
		));
		return array($this->send($to, "$who wants to transfer the StraboField project \"$name\" to you", $m));
	}

	/** To both parties: the transfer is complete. */
	public function accepted($row)
	{
		$from = $this->user($row->from_user_pkey);
		$to = $this->user($row->to_user_pkey);
		if (!$from || !$to) return array();
		$name = $this->projectName($row);
		$sum = $this->svc->summaryOf($row);
		$neo = isset($sum['neo']) ? $sum['neo'] : array();
		$facts = array(
			'Project'   => $name,
			'From'      => $this->who($from),
			'To'        => $this->who($to),
			'Datasets'  => (string)(isset($neo['datasets']) ? $neo['datasets'] : ''),
			'Spots'     => (string)(isset($neo['spots']) ? $neo['spots'] : ''),
			'Samples'   => (string)(isset($sum['samples']) ? $sum['samples'] : '0'),
			'Completed' => date('F j, Y, g:i a T', strtotime($row->completed_date)),
		);
		$out = array();

		$mTo = StraboMail::render(array(
			'title'    => 'Transfer complete: you now own "' . $name . '"',
			'greeting' => 'Hi ' . $this->first($to) . ',',
			'intro'    => array("The StraboField project \"$name\" now belongs to your account, with everything in it."),
			'facts'    => $facts,
			'button'   => array('Go to My StraboField Data', self::SITE . '/my_field_data'),
			'after'    => array(
				'On your devices: open StraboField, sign in as yourself and download the project from the server. Collaborators on the project keep their access; '
					. ($this->keep($row) ? $from->firstname . ' stays on as an admin collaborator.' : $from->firstname . ' no longer has access.'),
				'Samples that belong to this project moved with it. Their web addresses changed, so any links you shared to individual samples need to be shared again.',
			),
			'site_url' => self::SITE,
		));
		$out[] = $this->send($to, "Transfer complete: you now own \"$name\"", $mTo);

		$device = $this->keep($row)
			? 'You stay on the project as an admin collaborator, so StraboField on your devices keeps working with it as a collaborator: sync as usual. If you would rather not keep it, remove yourself from the project on the web.'
			: 'On every device that still holds this project, delete it from StraboField before the next sync. Uploading the old copy is refused by the server (it now belongs to ' . $to->firstname . '), and deleting it avoids sync errors.';
		$mFrom = StraboMail::render(array(
			'title'    => 'Your project "' . $name . '" has been transferred',
			'greeting' => 'Hi ' . $this->first($from) . ',',
			'intro'    => array($this->who($to) . " accepted the transfer. The StraboField project \"$name\" and everything in it now belong to their account."),
			'facts'    => $facts,
			'button'   => array('Go to My StraboField Data', self::SITE . '/my_field_data'),
			'after'    => array($device, 'Any DOI you minted for this project stays in your account and keeps resolving.'),
			'site_url' => self::SITE,
			'footer'   => 'This transfer was requested from your StraboSpot account and accepted by the receiving account. If you did not request it, contact StraboSpot right away.',
		));
		$out[] = $this->send($from, "Your project \"$name\" has been transferred to " . $this->who($to), $mFrom);
		return $out;
	}

	/** To the owner: the recipient declined. */
	public function declined($row)
	{
		$from = $this->user($row->from_user_pkey);
		$to = $this->user($row->to_user_pkey);
		if (!$from || !$to) return array();
		$name = $this->projectName($row);
		$m = StraboMail::render(array(
			'title'    => 'Transfer declined: "' . $name . '"',
			'greeting' => 'Hi ' . $this->first($from) . ',',
			'intro'    => array($this->who($to) . " declined the transfer of the StraboField project \"$name\". The project is unchanged and still belongs to you."),
			'facts'    => array('Project' => $name, 'Declined by' => $this->who($to), 'Declined on' => date('F j, Y', strtotime($row->decided_date))),
			'button'   => array('Go to My StraboField Data', self::SITE . '/my_field_data'),
			'site_url' => self::SITE,
		));
		return array($this->send($from, "Transfer declined: \"$name\"", $m));
	}

	/** To the recipient: the owner withdrew the request. */
	public function cancelled($row)
	{
		$from = $this->user($row->from_user_pkey);
		$to = $this->user($row->to_user_pkey);
		if (!$from || !$to) return array();
		$name = $this->projectName($row);
		$m = StraboMail::render(array(
			'title'    => 'Transfer request withdrawn: "' . $name . '"',
			'greeting' => 'Hi ' . $this->first($to) . ',',
			'intro'    => array($this->who($from) . " withdrew the request to transfer the StraboField project \"$name\" to you. Nothing has changed in your account."),
			'facts'    => array('Project' => $name, 'Withdrawn by' => $this->who($from)),
			'site_url' => self::SITE,
		));
		return array($this->send($to, "Transfer request withdrawn: \"$name\"", $m));
	}

	/** To the owner: the request expired without a response. */
	public function expired($row)
	{
		$from = $this->user($row->from_user_pkey);
		$to = $this->user($row->to_user_pkey);
		if (!$from || !$to) return array();
		$name = $this->projectName($row);
		$m = StraboMail::render(array(
			'title'    => 'Transfer request expired: "' . $name . '"',
			'greeting' => 'Hi ' . $this->first($from) . ',',
			'intro'    => array("Your request to transfer the StraboField project \"$name\" to {$to->email} expired after " . ProjectTransfer::EXPIRY_DAYS . " days without a response. The project is unchanged and still belongs to you."),
			'facts'    => array('Project' => $name, 'Offered to' => $to->email, 'Requested' => date('F j, Y', strtotime($row->created_date))),
			'after'    => array('You can send a new request from My StraboField Data at any time.'),
			'button'   => array('Go to My StraboField Data', self::SITE . '/my_field_data'),
			'site_url' => self::SITE,
		));
		return array($this->send($from, "Transfer request expired: \"$name\"", $m));
	}

	/** To the owner (and the site mailbox): the transfer could not be completed. */
	public function failed($row)
	{
		$from = $this->user($row->from_user_pkey);
		$to = $this->user($row->to_user_pkey);
		if (!$from || !$to) return array();
		$name = $this->projectName($row);
		$sum = $this->svc->summaryOf($row);
		$reason = isset($sum['error']) ? $sum['error'] : (isset($sum['reason']) ? $sum['reason'] : 'unknown problem');
		$applied = ($row->applied === 't' || $row->applied === true);
		$m = StraboMail::render(array(
			'title'    => 'Transfer not completed: "' . $name . '"',
			'greeting' => 'Hi ' . $this->first($from) . ',',
			'intro'    => array("The transfer of the StraboField project \"$name\" to " . $this->who($to) . ' could not be completed.'),
			'facts'    => array('Project' => $name, 'Problem' => $reason, 'Reference' => $row->uuid),
			'after'    => array($applied
				? 'The transfer had started. StraboSpot staff have been notified and will complete or roll it back; please do not upload this project from your devices until you hear back.'
				: 'Nothing has changed: the project is unchanged and still belongs to you.'),
			'site_url' => self::SITE,
		));
		$out = array($this->send($from, "Transfer not completed: \"$name\"", $m));
		$admin = isset($GLOBALS['straboemailaddress']) ? $GLOBALS['straboemailaddress'] : null;
		if ($admin && filter_var($admin, FILTER_VALIDATE_EMAIL) && $applied) {
			$out[] = $this->sendTo($admin, 'StraboSpot', "[admin] project transfer {$row->uuid} needs attention", $m);
		}
		return $out;
	}

	/** To both parties: an administrator reversed a completed transfer. */
	public function reversed($reversalRow)
	{
		$now = $this->user($reversalRow->to_user_pkey);    // original owner, holds it again
		$was = $this->user($reversalRow->from_user_pkey);  // had received it
		if (!$now || !$was) return array();
		$name = $this->projectName($reversalRow);
		$facts = array('Project' => $name, 'Now owned by' => $this->who($now), 'Reversed on' => date('F j, Y, g:i a T', strtotime($reversalRow->completed_date)));
		$out = array();
		$out[] = $this->send($now, "Transfer reversed: \"$name\" is back in your account", StraboMail::render(array(
			'title'    => 'Transfer reversed: "' . $name . '" is back in your account',
			'greeting' => 'Hi ' . $this->first($now) . ',',
			'intro'    => array("A StraboSpot administrator reversed the transfer of the StraboField project \"$name\". It belongs to your account again, with everything in it."),
			'facts'    => $facts,
			'after'    => array('On your devices: open StraboField and download the project from the server again before working on it.'),
			'button'   => array('Go to My StraboField Data', self::SITE . '/my_field_data'),
			'site_url' => self::SITE,
		)));
		$out[] = $this->send($was, "Transfer reversed: \"$name\" has been returned", StraboMail::render(array(
			'title'    => 'Transfer reversed: "' . $name . '" has been returned',
			'greeting' => 'Hi ' . $this->first($was) . ',',
			'intro'    => array("A StraboSpot administrator reversed the transfer of the StraboField project \"$name\" to you. It belongs to " . $this->who($now) . ' again.'),
			'facts'    => $facts,
			'after'    => array('On every device that still holds this project, delete it from StraboField before the next sync. Uploading it from your account is refused by the server.'),
			'site_url' => self::SITE,
		)));
		return $out;
	}

	// =======================================================================
	// Helpers
	// =======================================================================

	private function send($user, $subject, array $rendered)
	{
		return $this->sendTo($user->email, $user->firstname, $subject, $rendered);
	}

	private function sendTo($email, $name, $subject, array $rendered)
	{
		try {
			return StraboMail::send($email, $subject, $rendered, array('to_name' => (string)$name));
		} catch (\Throwable $e) {
			error_log('[project-transfer] mail to ' . $email . ' failed: ' . $e->getMessage());
			return 'failed';
		}
	}

	private function user($pkey)
	{
		return $this->db->get_row_prepared("SELECT pkey, email, firstname, lastname FROM users WHERE pkey = $1", array((int)$pkey));
	}

	private function who($u)
	{
		$n = trim($u->firstname . ' ' . $u->lastname);
		return ($n !== '' ? $n : 'A StraboSpot user') . ' (' . $u->email . ')';
	}

	private function first($u)
	{
		return $u->firstname !== '' ? $u->firstname : 'there';
	}

	private function keep($row)
	{
		return $row->keep_as_collaborator === 't' || $row->keep_as_collaborator === true;
	}

	private function projectName($row)
	{
		$n = (string)$row->project_name;
		if ($n === '') $n = $this->svc->projectName($row->strabo_project_id, (int)$row->from_user_pkey);
		if ($n === '') $n = $this->svc->projectName($row->strabo_project_id, (int)$row->to_user_pkey);
		return $n !== '' ? $n : ('project ' . $row->strabo_project_id);
	}

	private function counts($row, $ownerPkey)
	{
		$nids = $this->svc->projectNodeIds($row->strabo_project_id, $ownerPkey);
		if ($nids) return $this->svc->projectCounts($nids);
		$sum = $this->svc->summaryOf($row);
		return isset($sum['counts_at_request']) ? $sum['counts_at_request'] : array('datasets' => 0, 'spots' => 0, 'images' => 0);
	}
}
