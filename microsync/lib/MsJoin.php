<?php
/**
 * File: MsJoin.php
 * Description: Join links of synced StraboMicro projects (2026-10-30): the
 *              owner makes one link per project (QR code + short address) and
 *              approves the people who ask to join with it. Approval is
 *              always required: a removed person keeps whatever they
 *              downloaded, so nobody gets in on the code alone. Used by the
 *              website only (micro_join.php, micro_collaborators*.php).
 *
 *              Methods throw MsHttpError. Waiting people live in
 *              micro_join_requests, never in micro_members, so the member
 *              list and the app see only approved people.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsJoin {

	/** How long a link can stay open, in days; the page offers these. */
	const EXPIRY_DAYS = array(1, 3, 7, 14, 30);

	/** No 0/O, 1/I/L: the code may be read off a projector and typed. */
	const CODE_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	const CODE_LENGTH = 8;

	/** Most people waiting on one project; stops a leaked link flooding the list. */
	const MAX_WAITING = 200;

	const TITLE_MAX = 100;
	const DETAILS_MAX = 600;

	/** The project's open link with its state (open or expired), or null. */
	public static function linkOf($db, $pid) {
		return $db->row(
			"SELECT id, project_id, code, role, pdf_title, pdf_details,
			        to_char(expires_at, 'YYYY-MM-DD') AS expires_on, expires_at <= now() AS expired
			   FROM strabomicro.micro_join_links WHERE project_id = $1 AND revoked_at IS NULL",
			array($pid));
	}

	/** Make a new link (owner); the old one stops working. Answers the link. */
	public static function createLink($db, $me, $pid, $role, $days) {
		if (!in_array($role, MsMembers::ASSIGNABLE, true)) {
			throw new MsHttpError(400, 'bad_request', 'role must be editor, contributor, or viewer');
		}
		if (!in_array($days, self::EXPIRY_DAYS, true)) {
			throw new MsHttpError(400, 'bad_request', 'Please choose how long the link stays open');
		}
		$db->begin();
		MsStore::lockProject($db, $pid);
		MsMembers::ownerProject($db, $pid, $me);
		$db->q("UPDATE strabomicro.micro_join_links SET revoked_at = now() WHERE project_id = $1 AND revoked_at IS NULL", array($pid));
		$db->q(
			"INSERT INTO strabomicro.micro_join_links (project_id, code, role, created_by, expires_at)
			 VALUES ($1, $2, $3, $4, now() + ($5 || ' days')::interval)",
			array($pid, self::newCode(), $role, $me, (string)$days));
		$db->commit();
		return self::linkOf($db, $pid);
	}

	/** Stop the link (owner). People already in stay; people waiting stay on the list. */
	public static function revokeLink($db, $me, $pid) {
		$db->begin();
		MsStore::lockProject($db, $pid);
		MsMembers::ownerProject($db, $pid, $me);
		$db->q("UPDATE strabomicro.micro_join_links SET revoked_at = now() WHERE project_id = $1 AND revoked_at IS NULL", array($pid));
		$db->commit();
	}

	/** Remember what the owner entered for the printable page (owner). Answers the cleaned values. */
	public static function savePdfFields($db, $me, $pid, $title, $details) {
		$title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$title)), 0, self::TITLE_MAX, 'UTF-8');
		$details = mb_substr(trim(str_replace("\r", '', (string)$details)), 0, self::DETAILS_MAX, 'UTF-8');
		$db->begin();
		MsStore::lockProject($db, $pid);
		MsMembers::ownerProject($db, $pid, $me);
		$db->q(
			"UPDATE strabomicro.micro_join_links SET pdf_title = $2, pdf_details = $3 WHERE project_id = $1 AND revoked_at IS NULL",
			array($pid, $title, $details));
		$db->commit();
		return array('title' => $title, 'details' => $details);
	}

	/**
	 * The link behind a code, for the join page: open (bool) and the project
	 * name. Null for a code that never existed, or one replaced or revoked.
	 */
	public static function byCode($db, $code) {
		$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$code));
		if ($code === '') {
			return null;
		}
		$l = $db->row(
			"SELECT l.id, l.project_id, l.code, l.role, l.revoked_at IS NULL AS not_revoked, l.expires_at > now() AS not_expired,
			        p.strabo_id, p.name, p.sync_format, p.sync_state
			   FROM strabomicro.micro_join_links l
			   JOIN strabomicro.micro_projectmetadata p ON p.id = l.project_id
			  WHERE l.code = $1",
			array($code));
		if ($l === null || !self::isTrue($l['not_revoked']) || MsDelete::tombstone($db, $l['project_id']) !== null
			|| $l['sync_format'] !== 'entity' || $l['sync_state'] !== 'ready') {
			return null;
		}
		$l['id'] = (int)$l['id'];
		$l['project_id'] = (int)$l['project_id'];
		$l['open'] = self::isTrue($l['not_expired']);
		$l['project_name'] = MsMembers::projectName($db, array('id' => $l['project_id'], 'strabo_id' => $l['strabo_id'], 'name' => $l['name']));
		return $l;
	}

	/**
	 * Where this person stands with this link: member, invited, has_copy,
	 * requested, denied (on this same link), or none (may ask).
	 */
	public static function standing($db, $me, $link) {
		$m = MsMembers::memberRow($db, $link['project_id'], $me);
		if ($m !== null && $m['state'] === 'active') {
			return 'member';
		}
		if ($m !== null && $m['state'] === 'invited') {
			return 'invited';
		}
		$r = self::requestRow($db, $link['project_id'], $me);
		if ($r !== null && $r['state'] === 'requested') {
			return 'requested';
		}
		if ($r !== null && $r['state'] === 'denied' && (int)$r['link_id'] === $link['id']) {
			return 'denied';
		}
		if (self::ownsCopy($db, $link, $me)) {
			return 'has_copy';
		}
		return 'none';
	}

	/** Ask to join with a code. Answers the person's standing afterwards. */
	public static function ask($db, $me, $code) {
		$link = self::byCode($db, $code);
		if ($link === null || !$link['open']) {
			throw new MsHttpError(404, 'not_found', 'This join link is no longer open.');
		}
		$pid = $link['project_id'];
		$db->begin();
		MsStore::lockProject($db, $pid);
		$standing = self::standing($db, $me, $link);
		if ($standing !== 'none') {
			$db->rollback();
			return $standing;
		}
		$waiting = (int)$db->val("SELECT count(*) FROM strabomicro.micro_join_requests WHERE project_id = $1 AND state = 'requested'", array($pid));
		if ($waiting >= self::MAX_WAITING) {
			$db->rollback();
			throw new MsHttpError(409, 'too_many_waiting', 'Too many people are waiting to join this project. Please ask the project owner.');
		}
		$db->q(
			"INSERT INTO strabomicro.micro_join_requests (project_id, link_id, user_pkey, state)
			 VALUES ($1, $2, $3, 'requested')
			 ON CONFLICT (project_id, user_pkey) DO UPDATE
			   SET link_id = EXCLUDED.link_id, state = 'requested', requested_at = now(), decided_at = NULL, decided_by = NULL",
			array($pid, $link['id'], $me));
		$db->commit();
		return 'requested';
	}

	/** People waiting on this project, oldest first: user pkey, name, email, role, requested_at. */
	public static function waiting($db, $pid) {
		return $db->rows(
			"SELECT r.user_pkey, u.firstname, u.lastname, u.email, l.role, to_char(r.requested_at, 'YYYY-MM-DD HH24:MI') AS requested_at
			   FROM strabomicro.micro_join_requests r
			   JOIN strabomicro.micro_join_links l ON l.id = r.link_id
			   JOIN public.users u ON u.pkey = r.user_pkey
			  WHERE r.project_id = $1 AND r.state = 'requested'
			  ORDER BY r.requested_at, r.id",
			array($pid));
	}

	/** Approve someone (owner): an active member with the link's role, told by email. */
	public static function approve($db, $me, $pid, $pkey) {
		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = MsMembers::ownerProject($db, $pid, $me);
		$r = self::waitingRow($db, $pid, $pkey);
		MsMembers::refuseCopyOwner($db, $p, $pkey, 'has_copy',
			'This person already has their own project with the same id on StraboSpot, so they cannot join this one.');
		$role = $db->val("SELECT role FROM strabomicro.micro_join_links WHERE id = $1", array($r['link_id']));
		$row = MsMembers::memberRow($db, $pid, $pkey);
		if ($row === null) {
			$db->q(
				"INSERT INTO strabomicro.micro_members (project_id, user_pkey, role, state, invited_by, invite_token, responded_at)
				 VALUES ($1, $2, $3, 'active', $4, $5, now())",
				array($pid, $pkey, $role, $me, MsHttp::uuid4()));
		} elseif ($row['state'] !== 'active') {
			$db->q(
				"UPDATE strabomicro.micro_members
				    SET role = $3, state = 'active', invited_by = $4, invited_at = now(), responded_at = now(),
				        removed_at = NULL, removed_by = NULL, role_changed_at = NULL
				  WHERE project_id = $1 AND user_pkey = $2",
				array($pid, $pkey, $role, $me));
		}
		$db->q(
			"UPDATE strabomicro.micro_join_requests SET state = 'approved', decided_at = now(), decided_by = $3
			  WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $pkey, $me));
		MsLive::members($db, $pid);
		$db->commit();
		self::mailApproved($db, $pkey, $me, MsMembers::projectName($db, $p), $role);
	}

	/** Turn someone down (owner). They can ask again only with a new link. */
	public static function deny($db, $me, $pid, $pkey) {
		$db->begin();
		MsStore::lockProject($db, $pid);
		MsMembers::ownerProject($db, $pid, $me);
		self::waitingRow($db, $pid, $pkey);
		$db->q(
			"UPDATE strabomicro.micro_join_requests SET state = 'denied', decided_at = now(), decided_by = $3
			  WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $pkey, $me));
		$db->commit();
	}

	/** Approve everyone waiting (owner); one refusal does not stop the rest. Answers approved count and refused names. */
	public static function approveAll($db, $me, $pid) {
		$approved = 0;
		$refused = array();
		foreach (self::waiting($db, $pid) as $w) {
			try {
				self::approve($db, $me, $pid, (int)$w['user_pkey']);
				$approved++;
			} catch (MsHttpError $e) {
				if ($e->errorCode !== 'has_copy') {
					throw $e; // about the project or the owner, the same for everyone
				}
				$refused[] = trim($w['firstname'] . ' ' . $w['lastname']) . ': ' . $e->getMessage();
			}
		}
		return array('approved' => $approved, 'refused' => $refused);
	}

	// -----------------------------------------------------------------------

	private static function newCode() {
		$code = '';
		for ($i = 0; $i < self::CODE_LENGTH; $i++) {
			$code .= self::CODE_LETTERS[random_int(0, strlen(self::CODE_LETTERS) - 1)];
		}
		return $code;
	}

	private static function requestRow($db, $pid, $pkey) {
		return $db->row("SELECT link_id, state FROM strabomicro.micro_join_requests WHERE project_id = $1 AND user_pkey = $2", array($pid, $pkey));
	}

	private static function waitingRow($db, $pid, $pkey) {
		$r = self::requestRow($db, $pid, $pkey);
		if ($r === null || $r['state'] !== 'requested') {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'This person is no longer waiting.');
		}
		return $r;
	}

	private static function ownsCopy($db, $link, $pkey) {
		return $db->val(
			"SELECT 1 FROM strabomicro.micro_projectmetadata WHERE userpkey = $1 AND strabo_id = $2 AND id <> $3 LIMIT 1",
			array($pkey, $link['strabo_id'], $link['project_id'])) !== null;
	}

	private static function isTrue($v) {
		return $v === true || $v === 't';
	}

	/** Email the person approved. Never throws. */
	private static function mailApproved($db, $pkey, $ownerPkey, $projectName, $role) {
		require_once __DIR__ . '/../../includes/StraboMail.php';
		$to = $db->row("SELECT firstname, email FROM public.users WHERE pkey = $1", array($pkey));
		if ($to === null) {
			return;
		}
		$who = MsMembers::whoText($db, $ownerPkey);
		$first = trim((string)$to['firstname']);
		$m = StraboMail::render(array(
			'title'    => 'You can now open the StraboMicro project',
			'greeting' => 'Hi ' . ($first !== '' ? $first : 'there') . ',',
			'intro'    => array("$who approved your request to join the StraboMicro project \"$projectName\"."),
			'facts'    => array('Project' => $projectName, 'Role' => MsMembers::ROLE_TEXT[$role]),
			'after'    => array('Open StraboMicro, log in with this account, and choose File > Open Remote Project.'),
			'site_url' => MsMembers::SITE,
		));
		try {
			StraboMail::send($to['email'], "You can now open \"$projectName\" in StraboMicro", $m, array('to_name' => $first));
		} catch (Exception $e) {
			error_log('microsync join: mail to ' . $to['email'] . ' failed: ' . $e->getMessage());
		}
	}
}
