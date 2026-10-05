<?php
/**
 * File: MsMembers.php
 * Description: Membership endpoints of the /microsync/v1/ API (collaboration
 *              Phase 2, spec v3 §3.1 and §3.2, decisions 17a to 17l): member
 *              list, invite by email, role change, remove and leave,
 *              ownership transfer, and the invitee's side (my invitations,
 *              accept, decline).
 *
 *              Every change takes the project write lock (MsStore::lockProject),
 *              so a push and a membership change of the same project are
 *              applied one after the other (a push reads the membership again
 *              under that lock). Invitation emails are sent after the commit;
 *              a mail failure never undoes the invitation.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsMembers {

	/** Roles the owner can give; "owner" only moves through a transfer. */
	const ASSIGNABLE = array('editor', 'contributor', 'viewer');

	const ROLE_TEXT = array(
		'editor'      => 'Editor (you can add, change, and delete anything in this project)',
		'contributor' => 'Contributor (you can add anything, and change or delete what you added)',
		'viewer'      => 'Viewer (you can see everything in this project)',
		'owner'       => 'Owner (you manage the project and its collaborators)',
	);

	const SITE = 'https://strabospot.org';

	/**
	 * GET projects/{pid}/members: active and invited members (the owner also
	 * sees declined invitations), plus a pending ownership transfer.
	 */
	public static function listMembers($ctx, $pid) {
		$db = $ctx->db;
		$p = MsStore::project($db, $pid, $ctx->me);
		MsHttp::json(200, self::memberList($db, $p));
	}

	/**
	 * POST projects/{pid}/members {email, role}: invite (owner). A pending
	 * invitation is sent again with the new role; a declined or removed
	 * person is invited again. 404 no_account when no StraboSpot account
	 * uses the address (17e); 409 invitee_has_copy when the invitee owns
	 * another server project with this straboId (P0-14).
	 */
	public static function invite($ctx, $pid) {
		$db = $ctx->db;
		$in = MsHttp::readJson(65536);
		$email = trim((string)MsHttp::prop($in, 'email', ''));
		$role = MsHttp::prop($in, 'role', 'contributor');
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new MsHttpError(400, 'bad_request', 'A valid email address is required');
		}
		if (!in_array($role, self::ASSIGNABLE, true)) {
			throw new MsHttpError(400, 'bad_request', 'role must be editor, contributor, or viewer');
		}

		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = self::ownerProject($db, $pid, $ctx->me);
		$invitee = $db->row(
			"SELECT pkey, firstname, email FROM public.users
			  WHERE lower(email) = lower($1) AND deleted = false ORDER BY pkey LIMIT 1",
			array($email));
		if ($invitee === null) {
			$db->rollback();
			throw new MsHttpError(404, 'no_account',
				'No StraboSpot account uses that email. Ask them to create one, then invite them again.');
		}
		$who = (int)$invitee['pkey'];
		if ($who === $ctx->me) {
			$db->rollback();
			throw new MsHttpError(400, 'self', 'You are already the owner of this project');
		}
		self::refuseCopyOwner($db, $p, $who, 'invitee_has_copy',
			'This person already has their own project with the same id on StraboSpot (for example an upload of a copy), so they cannot be invited to this one.');

		$row = self::memberRow($db, $pid, $who);
		$token = MsHttp::uuid4();
		if ($row === null) {
			$db->q(
				"INSERT INTO strabomicro.micro_members (project_id, user_pkey, role, state, invited_by, invite_token)
				 VALUES ($1, $2, $3, 'invited', $4, $5)",
				array($pid, $who, $role, $ctx->me, $token));
			$status = 'invited';
		} elseif ($row['state'] === 'active') {
			$db->rollback();
			throw new MsHttpError(409, 'already_member', 'This person is already a member; change their role instead',
				array('role' => $row['role']));
		} else {
			// invited (send again), declined or removed (invite again)
			$status = $row['state'] === 'invited' ? 'reinvited' : 'invited';
			$db->q(
				"UPDATE strabomicro.micro_members
				    SET role = $3, state = 'invited', invited_by = $4, invited_at = now(),
				        responded_at = NULL, removed_at = NULL, removed_by = NULL, role_changed_at = NULL,
				        invite_token = $5
				  WHERE project_id = $1 AND user_pkey = $2",
				array($pid, $who, $role, $ctx->me, $token));
		}
		$db->commit();

		$name = self::projectName($db, $p);
		$emailed = self::mailInvitation($db, $invitee, $ctx->me, $name, $role);
		$users = MsStore::users($db, array($who), true);
		MsHttp::json($status === 'invited' && $row === null ? 201 : 200, array(
			'status'  => $status,
			'member'  => array('user' => MsStore::user($users, $who), 'role' => $role, 'state' => 'invited'),
			'emailed' => $emailed,
		));
	}

	/** PATCH projects/{pid}/members/{pkey} {role}: change a member's role (owner). */
	public static function changeRole($ctx, $pid, $pkey) {
		$db = $ctx->db;
		$pkey = (int)$pkey;
		$in = MsHttp::readJson(65536);
		$role = MsHttp::prop($in, 'role');
		if (!in_array($role, self::ASSIGNABLE, true)) {
			throw new MsHttpError(400, 'bad_request', 'role must be editor, contributor, or viewer (ownership moves through a transfer)');
		}
		$db->begin();
		MsStore::lockProject($db, $pid);
		self::ownerProject($db, $pid, $ctx->me);
		$row = self::memberRow($db, $pid, $pkey);
		if ($row === null || !in_array($row['state'], array('active', 'invited'), true)) {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'This person is not a member of the project');
		}
		if ($row['role'] === 'owner') {
			$db->rollback();
			throw new MsHttpError(409, 'owner', 'The owner role moves only through an ownership transfer');
		}
		$db->q(
			"UPDATE strabomicro.micro_members
			    SET role = $3::varchar,
			        role_changed_at = CASE WHEN state = 'active' AND role <> $3::varchar THEN now() ELSE role_changed_at END
			  WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $pkey, $role));
		MsLive::members($db, $pid);
		$db->commit();
		$users = MsStore::users($db, array($pkey), true);
		MsHttp::json(200, array('member' => array('user' => MsStore::user($users, $pkey), 'role' => $role, 'state' => $row['state'])));
	}

	/**
	 * DELETE projects/{pid}/members/{pkey}: the owner removes someone (or
	 * withdraws an invitation), or a member leaves (pkey = self, 17j). The
	 * owner cannot leave before transferring ownership (17l). A removed
	 * member's next push is parked for the owner (MsSync::parkOrRefuse).
	 */
	public static function remove($ctx, $pid, $pkey) {
		$db = $ctx->db;
		$pkey = (int)$pkey;
		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = MsStore::project($db, $pid, $ctx->me);
		$leaving = $pkey === $ctx->me;
		if ($leaving) {
			if ($p['role'] === 'owner') {
				$db->rollback();
				throw new MsHttpError(409, 'owner_must_transfer', 'Transfer ownership to another member before leaving the project');
			}
		} elseif ($p['role'] !== 'owner') {
			$db->rollback();
			throw new MsHttpError(403, 'forbidden', 'Only the project owner can remove members');
		}
		$row = self::memberRow($db, $pid, $pkey);
		if ($row === null || $row['state'] === 'removed') {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'This person is not a member of the project');
		}
		if ($row['role'] === 'owner') {
			$db->rollback();
			throw new MsHttpError(409, 'owner', 'The owner cannot be removed');
		}
		$db->q(
			"UPDATE strabomicro.micro_members SET state = 'removed', removed_at = now(), removed_by = $3
			  WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $pkey, $ctx->me));
		// A transfer offered to this person ends with their membership
		$db->q(
			"UPDATE strabomicro.micro_members SET transfer_to = NULL
			  WHERE project_id = $1 AND role = 'owner' AND transfer_to = $2",
			array($pid, $pkey));
		MsLive::members($db, $pid);
		$db->commit();
		MsHttp::json(200, array('status' => $leaving ? 'left' : 'removed'));
	}

	/** POST projects/{pid}/transfer {pkey}: the owner offers ownership to an active member. */
	public static function offerTransfer($ctx, $pid) {
		$db = $ctx->db;
		$in = MsHttp::readJson(65536);
		$to = (int)MsHttp::prop($in, 'pkey', 0);
		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = self::ownerProject($db, $pid, $ctx->me);
		$row = $to > 0 ? self::memberRow($db, $pid, $to) : null;
		if ($row === null || $row['state'] !== 'active' || $row['role'] === 'owner') {
			$db->rollback();
			throw new MsHttpError(400, 'bad_request', 'Ownership can only go to another active member');
		}
		$db->q(
			"UPDATE strabomicro.micro_members SET transfer_to = $2
			  WHERE project_id = $1 AND role = 'owner' AND state = 'active'",
			array($pid, $to));
		$db->commit();
		$emailed = self::mailTransfer($db, $to, $ctx->me, self::projectName($db, $p));
		$users = MsStore::users($db, array($to), true);
		MsHttp::json(200, array('transferTo' => MsStore::user($users, $to), 'emailed' => $emailed));
	}

	/** DELETE projects/{pid}/transfer: the owner withdraws the offer. */
	public static function cancelTransfer($ctx, $pid) {
		$db = $ctx->db;
		$db->begin();
		MsStore::lockProject($db, $pid);
		self::ownerProject($db, $pid, $ctx->me);
		$db->q(
			"UPDATE strabomicro.micro_members SET transfer_to = NULL
			  WHERE project_id = $1 AND role = 'owner' AND state = 'active'",
			array($pid));
		$db->commit();
		MsHttp::json(200, array('transferTo' => null));
	}

	/**
	 * POST projects/{pid}/transfer/accept: the member offered ownership takes
	 * it; the previous owner becomes an Editor (§3.2) and
	 * micro_projectmetadata.userpkey (the owner for legacy readers) follows.
	 */
	public static function acceptTransfer($ctx, $pid) {
		// HELD (Jason, 2026-10-02): userpkey also keys StraboSamples (sample id
		// + owner), search, permalinks and My StraboMicro Data, which need a
		// re-key like Field's ProjectTransfer::rekeySamples. Built in a later
		// stage; until then accepting is refused and nothing changes.
		MsStore::project($ctx->db, $pid, $ctx->me);
		self::pendingTransfer($ctx->db, $pid, $ctx->me); // 404 when nothing is offered to me
		throw new MsHttpError(409, 'transfer_unavailable', 'Ownership transfer is not available yet.');

		$db = $ctx->db;
		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = MsStore::project($db, $pid, $ctx->me);
		$owner = self::pendingTransfer($db, $pid, $ctx->me);
		self::refuseCopyOwner($db, $p, $ctx->me, 'has_copy',
			'You already have your own project with the same id on StraboSpot, so you cannot take over this one.');
		$db->q(
			"UPDATE strabomicro.micro_members SET role = 'editor', transfer_to = NULL
			  WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $owner));
		$db->q(
			"UPDATE strabomicro.micro_members SET role = 'owner' WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $ctx->me));
		$db->q("UPDATE strabomicro.micro_projectmetadata SET userpkey = $2 WHERE id = $1", array($pid, $ctx->me));
		MsLive::members($db, $pid);
		$db->commit();
		MsHttp::json(200, array('role' => 'owner', 'previousOwner' => $owner));
	}

	/** POST projects/{pid}/transfer/decline: the member offered ownership turns it down. */
	public static function declineTransfer($ctx, $pid) {
		$db = $ctx->db;
		$db->begin();
		MsStore::lockProject($db, $pid);
		MsStore::project($db, $pid, $ctx->me);
		$owner = self::pendingTransfer($db, $pid, $ctx->me);
		$db->q(
			"UPDATE strabomicro.micro_members SET transfer_to = NULL WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $owner));
		$db->commit();
		MsHttp::json(200, array('transferTo' => null));
	}

	/**
	 * GET invites: my pending invitations (synced, ready projects only) and
	 * ownership transfers offered to me (17f: the app's notice after login
	 * and the Invitations section of Open Remote Project).
	 */
	public static function myInvites($ctx) {
		$db = $ctx->db;
		$invites = array();
		foreach (self::invitationsFor($db, $ctx->me) as $i) {
			unset($i['token']);
			$invites[] = $i;
		}
		$transfers = $db->rows(
			"SELECT p.id, p.strabo_id, COALESCE(e.body->>'name', p.name) AS name, o.user_pkey AS owner_pkey
			   FROM strabomicro.micro_members o
			   JOIN strabomicro.micro_projectmetadata p ON p.id = o.project_id
			   JOIN strabomicro.micro_members me
			          ON me.project_id = p.id AND me.user_pkey = $1 AND me.state = 'active'
			   LEFT JOIN strabomicro.micro_entities e
			          ON e.project_id = p.id AND e.entity_type = 'project' AND e.entity_id = p.strabo_id
			  WHERE o.transfer_to = $1 AND o.role = 'owner' AND o.state = 'active'
			  ORDER BY p.id",
			array($ctx->me));
		$users = MsStore::users($db, array_column($transfers, 'owner_pkey'), true);
		$outTr = array();
		foreach ($transfers as $r) {
			$outTr[] = array(
				'pid'      => (int)$r['id'],
				'straboId' => $r['strabo_id'],
				'name'     => $r['name'],
				'from'     => MsStore::user($users, $r['owner_pkey']),
			);
		}
		MsHttp::json(200, array('invitations' => $invites, 'transfers' => $outTr));
	}

	/**
	 * POST invites/{pid}/accept: I become an active member. Answers what the
	 * app needs to download the project as a synced copy (17f).
	 */
	public static function acceptInvite($ctx, $pid) {
		MsHttp::json(200, self::acceptInviteFor($ctx->db, $ctx->me, $pid));
	}

	/** POST invites/{pid}/decline */
	public static function declineInvite($ctx, $pid) {
		self::declineInviteFor($ctx->db, $ctx->me, $pid);
		MsHttp::json(200, array('status' => 'declined'));
	}

	/**
	 * Accept my invitation (the API and the website, micro_invitation.php).
	 * $token, when given, must match the invitation's token (website forms).
	 * Throws MsHttpError.
	 */
	public static function acceptInviteFor($db, $me, $pid, $token = null) {
		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = self::invitedProject($db, $pid, $me, $token);
		self::refuseCopyOwner($db, $p, $me, 'has_copy',
			'You already have your own project with the same id on StraboSpot, so you cannot join this one.');
		$db->q(
			"UPDATE strabomicro.micro_members SET state = 'active', responded_at = now()
			  WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $me));
		MsLive::members($db, $pid);
		$db->commit();
		return array(
			'pid'      => (int)$pid,
			'straboId' => $p['strabo_id'],
			'name'     => self::projectName($db, $p),
			'role'     => $p['invite_role'],
			'headSeq'  => (int)$p['head_seq'],
		);
	}

	/** Decline my invitation (the API and the website). Throws MsHttpError. */
	public static function declineInviteFor($db, $me, $pid, $token = null) {
		$db->begin();
		MsStore::lockProject($db, $pid);
		$p = self::invitedProject($db, $pid, $me, $token);
		$db->q(
			"UPDATE strabomicro.micro_members SET state = 'declined', responded_at = now()
			  WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $me));
		$db->commit();
		return array('pid' => (int)$pid, 'name' => self::projectName($db, $p));
	}

	/**
	 * My pending invitations to synced, ready projects (GET invites and the
	 * website's My StraboMicro Data page): pid, straboId, name, role,
	 * invitedBy, owner, invitedAt, plus the token for website forms.
	 */
	public static function invitationsFor($db, $me) {
		$invites = $db->rows(
			"SELECT p.id, p.strabo_id, COALESCE(e.body->>'name', p.name) AS name, m.role, m.invited_by, m.invite_token,
			        " . MsDb::iso('m.invited_at') . " AS invited_at,
			        (SELECT o.user_pkey FROM strabomicro.micro_members o
			          WHERE o.project_id = p.id AND o.role = 'owner' AND o.state = 'active') AS owner_pkey
			   FROM strabomicro.micro_members m
			   JOIN strabomicro.micro_projectmetadata p ON p.id = m.project_id
			   LEFT JOIN strabomicro.micro_entities e
			          ON e.project_id = p.id AND e.entity_type = 'project' AND e.entity_id = p.strabo_id
			  WHERE m.user_pkey = $1 AND m.state = 'invited'
			    AND p.sync_format = 'entity' AND p.sync_state = 'ready'
			    AND NOT " . MsDelete::deletedSql('p') . "
			  ORDER BY m.invited_at, p.id",
			array($me));
		$users = MsStore::users($db, array_merge(array_column($invites, 'invited_by'), array_column($invites, 'owner_pkey')), true);
		$out = array();
		foreach ($invites as $r) {
			$out[] = array(
				'pid'       => (int)$r['id'],
				'straboId'  => $r['strabo_id'],
				'name'      => $r['name'],
				'role'      => $r['role'],
				'invitedBy' => MsStore::user($users, $r['invited_by']),
				'owner'     => MsStore::user($users, $r['owner_pkey']),
				'invitedAt' => $r['invited_at'],
				'token'     => $r['invite_token'],
			);
		}
		return $out;
	}

	// -----------------------------------------------------------------------

	/** The member list answer (shared with the website's read-only list). */
	public static function memberList($db, $p) {
		$states = $p['role'] === 'owner' ? array('active', 'invited', 'declined') : array('active', 'invited');
		$rows = $db->rows(
			"SELECT user_pkey, role, state, invited_by, transfer_to,
			        " . MsDb::iso('invited_at') . " AS invited_at,
			        " . MsDb::iso('responded_at') . " AS responded_at
			   FROM strabomicro.micro_members
			  WHERE project_id = $1 AND state = ANY (ARRAY(SELECT jsonb_array_elements_text($2::jsonb)))
			  ORDER BY CASE role WHEN 'owner' THEN 0 ELSE 1 END, CASE state WHEN 'active' THEN 0 WHEN 'invited' THEN 1 ELSE 2 END, id",
			array($p['id'], json_encode($states)));
		$transferTo = null;
		$pkeys = array();
		foreach ($rows as $r) {
			$pkeys[] = $r['user_pkey'];
			$pkeys[] = $r['invited_by'];
			if ($r['role'] === 'owner' && $r['transfer_to'] !== null) {
				$transferTo = (int)$r['transfer_to'];
				$pkeys[] = $transferTo;
			}
		}
		$users = MsStore::users($db, $pkeys, true);
		$list = array();
		foreach ($rows as $r) {
			$list[] = array(
				'user'        => MsStore::user($users, $r['user_pkey']),
				'role'        => $r['role'],
				'state'       => $r['state'],
				'invitedBy'   => MsStore::user($users, $r['invited_by']),
				'invitedAt'   => $r['invited_at'],
				'respondedAt' => $r['responded_at'],
			);
		}
		return array(
			'pid'        => (int)$p['id'],
			'myRole'     => $p['role'],
			'members'    => $list,
			'transferTo' => $transferTo === null ? null : MsStore::user($users, $transferTo),
		);
	}

	/** Synced project I own; also refuses projects whose first upload is not done. */
	private static function ownerProject($db, $pid, $me) {
		$p = MsStore::project($db, $pid, $me);
		if ($p['role'] !== 'owner') {
			$db->rollback();
			throw new MsHttpError(403, 'forbidden', 'Only the project owner can manage collaborators');
		}
		if ($p['sync_format'] !== 'entity' || $p['sync_state'] !== 'ready') {
			$db->rollback();
			throw new MsHttpError(409, 'not_ready', 'The first upload of this project has not finished yet');
		}
		return $p;
	}

	/** A project with my pending invitation (404 otherwise, like any project I cannot see). */
	private static function invitedProject($db, $pid, $me, $token = null) {
		$p = $db->row(
			"SELECT p.id, p.strabo_id, p.name, p.head_seq, m.role AS invite_role, m.invite_token
			   FROM strabomicro.micro_projectmetadata p
			   JOIN strabomicro.micro_members m ON m.project_id = p.id AND m.user_pkey = $2
			  WHERE p.id = $1 AND m.state = 'invited' AND p.sync_format = 'entity' AND p.sync_state = 'ready'",
			array($pid, $me));
		if ($p === null || ($token !== null && $p['invite_token'] !== $token)) {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'No pending invitation for this project');
		}
		// Deleted by its owner since (17ad): the invitation waits for a restore
		$t = MsDelete::tombstone($db, $pid);
		if ($t !== null) {
			$db->rollback();
			throw MsDelete::deletedError($db, $t, $me, true);
		}
		return $p;
	}

	/** Owner pkey of a transfer offered to me, or 404. */
	private static function pendingTransfer($db, $pid, $me) {
		$owner = $db->val(
			"SELECT user_pkey FROM strabomicro.micro_members
			  WHERE project_id = $1 AND role = 'owner' AND state = 'active' AND transfer_to = $2",
			array($pid, $me));
		if ($owner === null) {
			$db->rollback();
			throw new MsHttpError(404, 'not_found', 'No ownership transfer is waiting for you on this project');
		}
		return (int)$owner;
	}

	private static function memberRow($db, $pid, $pkey) {
		return $db->row(
			"SELECT user_pkey, role, state FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2",
			array($pid, $pkey));
	}

	/**
	 * P0-14: a user who owns another server project with this straboId
	 * cannot join (ownership by straboId would become ambiguous).
	 */
	private static function refuseCopyOwner($db, $p, $pkey, $code, $message) {
		$other = $db->val(
			"SELECT 1 FROM strabomicro.micro_projectmetadata
			  WHERE userpkey = $1 AND strabo_id = $2 AND id <> $3 LIMIT 1",
			array($pkey, $p['strabo_id'], $p['id']));
		if ($other !== null) {
			$db->rollback();
			throw new MsHttpError(409, $code, $message);
		}
	}

	private static function projectName($db, $p) {
		$name = $db->val(
			"SELECT body->>'name' FROM strabomicro.micro_entities
			  WHERE project_id = $1 AND entity_type = 'project' AND entity_id = $2",
			array($p['id'], $p['strabo_id']));
		return $name !== null && $name !== '' ? $name : (string)$p['name'];
	}

	/** "Name (email)" of a user, for emails. */
	private static function whoText($db, $pkey) {
		$u = $db->row("SELECT firstname, lastname, email FROM public.users WHERE pkey = $1", array($pkey));
		if ($u === null) {
			return 'A StraboSpot user';
		}
		$name = trim($u['firstname'] . ' ' . $u['lastname']);
		return ($name !== '' ? $name : 'A StraboSpot user') . ($u['email'] !== '' ? ' (' . $u['email'] . ')' : '');
	}

	/** Email the invitee; true when sent or filed. Never throws. */
	private static function mailInvitation($db, $invitee, $inviterPkey, $projectName, $role) {
		require_once __DIR__ . '/../../includes/StraboMail.php';
		$who = self::whoText($db, $inviterPkey);
		$first = trim((string)$invitee['firstname']);
		$m = StraboMail::render(array(
			'title'    => 'You are invited to collaborate on a StraboMicro project',
			'greeting' => 'Hi ' . ($first !== '' ? $first : 'there') . ',',
			'intro'    => array("$who has invited you to collaborate on the StraboMicro project \"$projectName\"."),
			'facts'    => array(
				'Project'    => $projectName,
				'Invited by' => $who,
				'Role'       => self::ROLE_TEXT[$role],
			),
			'button'   => array('Review the invitation', self::SITE . '/my_micro_data'),
			'after'    => array(
				'You can accept or decline it in StraboMicro (it appears after you log in) or on your My StraboMicro Data page. Nothing changes in your account until you accept.',
				'Once accepted, StraboMicro downloads the project and keeps it in sync with the other members.',
			),
			'site_url' => self::SITE,
			'footer'   => 'You received this because ' . $who . ' invited the StraboSpot account ' . $invitee['email']
				. ' to a project. If you were not expecting it, you can decline it or simply ignore this message.',
		));
		try {
			return StraboMail::send($invitee['email'], "$who invited you to collaborate on \"$projectName\" in StraboMicro", $m,
				array('to_name' => $first)) !== 'none';
		} catch (Exception $e) {
			error_log('microsync invite: mail to ' . $invitee['email'] . ' failed: ' . $e->getMessage());
			return false;
		}
	}

	/** Email the member offered ownership; true when sent or filed. Never throws. */
	private static function mailTransfer($db, $toPkey, $ownerPkey, $projectName) {
		require_once __DIR__ . '/../../includes/StraboMail.php';
		$to = $db->row("SELECT firstname, email FROM public.users WHERE pkey = $1", array($toPkey));
		if ($to === null) {
			return false;
		}
		$who = self::whoText($db, $ownerPkey);
		$first = trim((string)$to['firstname']);
		$m = StraboMail::render(array(
			'title'    => 'You are offered ownership of a StraboMicro project',
			'greeting' => 'Hi ' . ($first !== '' ? $first : 'there') . ',',
			'intro'    => array("$who would like you to become the owner of the StraboMicro project \"$projectName\"."),
			'facts'    => array('Project' => $projectName, 'Offered by' => $who),
			'after'    => array(
				'Open the project in StraboMicro to accept or decline. As the owner you manage its collaborators; ' . $who . ' stays on the project as an Editor.',
			),
			'site_url' => self::SITE,
		));
		try {
			return StraboMail::send($to['email'], "$who offered you ownership of \"$projectName\" in StraboMicro", $m,
				array('to_name' => $first)) !== 'none';
		} catch (Exception $e) {
			error_log('microsync transfer: mail to ' . $to['email'] . ' failed: ' . $e->getMessage());
			return false;
		}
	}
}
