<?php
/**
 * File: includes/transfer/ProjectTransfer.php
 * Description: StraboField project ownership transfer service
 *              (docs/ProjectTransfer_Design.md). Owns the request lifecycle
 *              (request / accept / decline / cancel / expire), the eligibility
 *              rules (§5), the idempotent, resumable rewrite of every store
 *              that records ownership (§6), the admin reverse, and a
 *              read-only verify that recounts each store.
 *
 *              Ownership of a Field project lives in: the Neo4j
 *              (:User)-[:HAS_PROJECT]->(:Project) edge, the `userpkey`
 *              property on every node of the project subtree (+ IS_TAGGED /
 *              IS_RELATED_TO edge properties), the PG mirror tables
 *              (project / dataset / spot / image / sample / rock_type),
 *              collaborators, versions / verlog / vprojects,
 *              project_merge_prefs, the strabosamples spine (composite
 *              (id, userpkey) identity) and the strabosearch index. DOIs
 *              deliberately stay with the minter (D1).
 *
 *              PostgreSQL and Neo4j cannot share a transaction. Steps are
 *              ordered and written so that each is re-runnable: the Neo4j
 *              rewrite anchors on the project's internal node ids (never on
 *              userpkey) and SET is idempotent; the PG side is ONE
 *              transaction; the mirror refresh and the search re-extract are
 *              idempotent by construction. A failure records the step and
 *              error on the row; admin_transfers.php retries from there.
 *
 *              No mail is sent from this class (the pages do that through
 *              includes/StraboMail.php) so the service can be exercised
 *              headless by tests/transfer/.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class ProjectTransfer
{
	const EXPIRY_DAYS = 7;

	/** Same advisory-lock class as StraboSpot::lockUploadKey so an upload and a transfer serialize. */
	const LOCK_CLASS = 7501;

	// Service steps (§6). `step` on the row = last COMPLETED step.
	const STEP_NONE      = 0;
	const STEP_PREFLIGHT = 1;
	const STEP_NEO       = 2;
	const STEP_PG        = 3;
	const STEP_MIRROR    = 4;
	const STEP_SEARCH    = 5;
	const STEP_DONE      = 5;

	/** What each step is doing, for the progress list the pages poll (transfer_status.php). */
	const STEP_LABELS = array(
		self::STEP_PREFLIGHT => 'Checking the project',
		self::STEP_NEO       => 'Moving datasets, spots and photos',
		self::STEP_PG        => 'Moving collaborators, versions and samples',
		self::STEP_MIRROR    => 'Refreshing project keywords',
		self::STEP_SEARCH    => 'Updating the search index',
	);

	const CHANGE_TYPE = 'ownership_transfer';

	/**
	 * D6 soft launch: who sees "Transfer to Other Account" on My Field Data.
	 * userpkey 3 always; these signed-in emails too (Jason's test accounts,
	 * so a project can go back and forth). Full launch = make
	 * canInitiate() return true and drop the list.
	 */
	const PILOT_EMAILS = array('jasonash1@gmail.com');

	public static function canInitiate($userpkey, $sessionEmail)
	{
		if ((int)$userpkey === 3) return true;
		return in_array(strtolower(trim((string)$sessionEmail)), self::PILOT_EMAILS, true);
	}

	private $db;
	private $neodb;
	private $heldLocks = array();
	private $inTxn = false;

	public function __construct($db, $neodb)
	{
		$this->db = $db;
		$this->neodb = $neodb;
	}

	// =======================================================================
	// Static helpers usable without an instance
	// =======================================================================

	public static function tableExists($db)
	{
		static $known = null;
		if ($known === null) {
			$known = !empty($db->get_var("SELECT to_regclass('project_transfers')"));
		}
		return $known;
	}

	/**
	 * D4 tombstone: has this (project id, owner) been transferred away and not
	 * reversed? Returns the row or null. Used by
	 * CollaborationAuth::canUploadProjectAsOwner on the upload path.
	 */
	public static function transferredAway($db, $projectId, $userpkey)
	{
		if (!self::tableExists($db)) return null;
		return $db->get_row_prepared(
			"SELECT * FROM project_transfers
			  WHERE strabo_project_id = $1 AND from_user_pkey = $2
			    AND applied AND tombstone_cleared_date IS NULL
			  ORDER BY pkey DESC LIMIT 1",
			array((string)$projectId, (int)$userpkey)
		);
	}

	/** The one message an initiator ever sees (D5): never confirms an account exists. */
	public static function neutralMessage()
	{
		return 'If a StraboSpot account exists at that address, we have sent them a transfer request. '
			. 'It expires in ' . self::EXPIRY_DAYS . ' days. You can cancel it from My Field Data until then.';
	}

	// =======================================================================
	// Lookups
	// =======================================================================

	/**
	 * Recipient by exact email. Active, not deleted, and with a Neo4j :User
	 * node (created at activation). Null when any of that fails; the CALLER
	 * must not reveal which.
	 */
	public function resolveRecipient($email)
	{
		$email = strtolower(trim((string)$email));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
		$row = $this->db->get_row_prepared(
			"SELECT pkey, email, firstname, lastname FROM users
			  WHERE lower(email) = $1 AND active = TRUE AND deleted = FALSE
			  ORDER BY pkey LIMIT 1",
			array($email)
		);
		if (!$row) return null;
		if (!$this->userNodeExists((int)$row->pkey)) return null;
		return $row;
	}

	public function userNodeExists($pkey)
	{
		$n = $this->neodb->get_var("MATCH (u:User {userpkey: " . (int)$pkey . "}) RETURN count(u)");
		return (int)$n > 0;
	}

	public function userRow($pkey)
	{
		return $this->db->get_row_prepared(
			"SELECT pkey, email, firstname, lastname, active, deleted FROM users WHERE pkey = $1",
			array((int)$pkey)
		);
	}

	/**
	 * Internal Neo4j ids of the Project node(s) with this Strabo id owned by
	 * $userpkey, by property OR by HAS_PROJECT edge (the two can disagree;
	 * the transfer heals both). Duplicated (id, owner) nodes are a known
	 * prod shape, hence a list.
	 */
	public function projectNodeIds($projectId, $userpkey)
	{
		$lit = self::idLit($projectId);
		$upk = (int)$userpkey;
		$rows = $this->neo("
			MATCH (p:Project {id: $lit})
			OPTIONAL MATCH (u:User)-[:HAS_PROJECT]->(p)
			WITH p, collect(u.userpkey) AS owners
			WHERE p.userpkey = $upk OR $upk IN owners
			RETURN id(p) AS nid
		");
		$out = array();
		foreach ((array)$rows as $r) $out[] = (int)$r->value('nid');
		return array_values(array_unique($out));
	}

	public function projectName($projectId, $userpkey)
	{
		$lit = self::idLit($projectId);
		$upk = (int)$userpkey;
		$name = $this->neodb->get_var("
			MATCH (p:Project {id: $lit}) WHERE p.userpkey = $upk
			RETURN coalesce(p.desc_project_name, p.projectname, '') LIMIT 1
		");
		return (string)$name;
	}

	/** Strabo ids of every spot under these project nodes. */
	public function spotIdsUnder(array $nids)
	{
		if (!$nids) return array();
		$list = implode(',', array_map('intval', $nids));
		$out = array();
		foreach ((array)$this->neo("MATCH (p:Project) WHERE id(p) IN [$list] MATCH (p)-[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(s:Spot) RETURN DISTINCT s.id AS sid") as $r) {
			$sid = $r->value('sid');
			if ($sid !== null && $sid !== '') $out[] = (string)$sid;
		}
		return $out;
	}

	/** Spine sample ids hosted (Field-linked) by these spots under $owner. */
	public function hostedSampleIds(array $spotIds, $owner)
	{
		if (!$spotIds || !$this->samplesSchemaExists()) return array();
		$ids = array();
		foreach (array_chunk($spotIds, 500) as $chunk) {
			$rows = (array)$this->db->get_results_prepared(
				"SELECT DISTINCT sample_id FROM strabosamples.sample_subsystem_links
				  WHERE subsystem = 'field' AND sample_userpkey = $1 AND reference_userpkey = $1
				    AND reference_id = ANY($2::text[])",
				array((int)$owner, self::pgTextArray($chunk)));
			foreach ($rows as $r) $ids[] = (string)$r->sample_id;
		}
		return array_values(array_unique($ids));
	}

	/** How many of the project's samples already exist under the recipient's key. */
	public function sampleIdClashes(array $nids, $from, $to)
	{
		$ids = $this->hostedSampleIds($this->spotIdsUnder($nids), $from);
		if (!$ids) return 0;
		$n = 0;
		foreach (array_chunk($ids, 500) as $chunk) {
			$n += (int)$this->db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $1 AND id = ANY($2::text[])", array((int)$to, self::pgTextArray($chunk)));
		}
		return $n;
	}

	/** Dataset + spot counts for the acceptance page and the audit row. */
	public function projectCounts($nids)
	{
		if (!$nids) return array('datasets' => 0, 'spots' => 0, 'images' => 0);
		$list = implode(',', array_map('intval', $nids));
		$rec = $this->neoRecord("
			MATCH (p:Project) WHERE id(p) IN [$list]
			OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset)
			OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot)
			OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image)
			RETURN count(DISTINCT d) AS datasets, count(DISTINCT s) AS spots, count(DISTINCT i) AS images
		");
		return array(
			'datasets' => $rec ? (int)$rec->value('datasets') : 0,
			'spots'    => $rec ? (int)$rec->value('spots') : 0,
			'images'   => $rec ? (int)$rec->value('images') : 0,
		);
	}

	// =======================================================================
	// Eligibility (§5). Run at initiation AND at acceptance.
	// =======================================================================

	/**
	 * @return array ['ok' => bool, 'reason' => string|null, 'nids' => int[]]
	 *   reason is the PLAIN reason (for the audit row and for the recipient
	 *   at acceptance). Initiators only ever see neutralMessage().
	 */
	public function checkEligibility($projectId, $fromPkey, $toPkey, array $opts = array())
	{
		$projectId = (string)$projectId;
		$from = (int)$fromPkey;
		$to = (int)$toPkey;
		$skipPending = !empty($opts['skip_pending']);    // at acceptance: this row IS the pending one

		$nids = $this->projectNodeIds($projectId, $from);
		if (!$nids) {
			return array('ok' => false, 'reason' => 'The current account does not own a project with this id.', 'nids' => array());
		}
		if ($to <= 0 || $to === $from) {
			return array('ok' => false, 'reason' => 'A project cannot be transferred to the account that already owns it.', 'nids' => $nids);
		}
		$u = $this->userRow($to);
		$active  = $u && ($u->active === 't' || $u->active === true);
		$deleted = $u && ($u->deleted === 't' || $u->deleted === true);
		if (!$u || !$active || $deleted) {
			return array('ok' => false, 'reason' => 'The receiving account is not an active StraboSpot account.', 'nids' => $nids);
		}
		if (!$this->userNodeExists($to)) {
			return array('ok' => false, 'reason' => 'The receiving account has never signed in and cannot hold projects yet.', 'nids' => $nids);
		}
		// Same-id collision on the receiving side (Strabo project ids are only
		// unique per user): a live project, or a restorable version row.
		if ($this->projectNodeIds($projectId, $to)) {
			return array('ok' => false, 'reason' => 'The receiving account already has a project with this id.', 'nids' => $nids);
		}
		$nv = (int)$this->db->get_var_prepared(
			"SELECT (SELECT count(*) FROM vprojects WHERE projectid = $1 AND userpkey = $2)
			      + (SELECT count(*) FROM versions  WHERE projectid = $1 AND userpkey = $2)",
			array($projectId, $to)
		);
		if ($nv > 0) {
			return array('ok' => false, 'reason' => 'The receiving account holds a saved version of a project with this id.', 'nids' => $nids);
		}
		// Sample ids collide too: the spine identity is (id, userpkey).
		$clash = $this->sampleIdClashes($nids, $from, $to);
		if ($clash > 0) {
			return array('ok' => false, 'reason' => 'The receiving account already holds ' . $clash . ' sample(s) with the same ids as samples in this project.', 'nids' => $nids);
		}
		// No tombstone check on the receiving side: a voluntary transfer back
		// to a previous owner is legitimate, and completing it clears their
		// tombstone (see execute()).
		if (!$skipPending && $this->pendingRow($projectId, $from)) {
			return array('ok' => false, 'reason' => 'A transfer request for this project is already pending.', 'nids' => $nids);
		}
		return array('ok' => true, 'reason' => null, 'nids' => $nids);
	}

	public function pendingRow($projectId, $fromPkey)
	{
		return $this->db->get_row_prepared(
			"SELECT * FROM project_transfers WHERE strabo_project_id = $1 AND from_user_pkey = $2 AND status = 'pending' LIMIT 1",
			array((string)$projectId, (int)$fromPkey)
		);
	}

	// =======================================================================
	// Request lifecycle
	// =======================================================================

	/**
	 * Initiate. ALWAYS returns ok = true with the neutral message unless the
	 * caller is not the owner or the input is malformed; an ineligible
	 * recipient produces a `refused` audit row and NO mail, and the caller
	 * still shows the neutral message. `mail` tells the page whether to
	 * send the request mail (only for a real pending row).
	 *
	 * @return array ['ok'=>bool, 'message'=>string, 'row'=>object|null, 'mail'=>bool, 'reason'=>string|null]
	 */
	public function request($projectId, $fromPkey, $toEmail, $keepAsCollaborator = true, $requestedBy = null)
	{
		$projectId = (string)$projectId;
		$from = (int)$fromPkey;
		$requestedBy = $requestedBy === null ? $from : (int)$requestedBy;
		$email = strtolower(trim((string)$toEmail));

		if ($projectId === '' || $from <= 0) {
			return array('ok' => false, 'message' => 'Missing project.', 'row' => null, 'mail' => false, 'reason' => 'bad input');
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return array('ok' => false, 'message' => 'Please enter a complete email address.', 'row' => null, 'mail' => false, 'reason' => 'bad email');
		}
		$nids = $this->projectNodeIds($projectId, $from);
		if (!$nids) {
			return array('ok' => false, 'message' => 'You do not own a project with this id.', 'row' => null, 'mail' => false, 'reason' => 'not owner');
		}
		if ($this->pendingRow($projectId, $from)) {
			return array('ok' => false, 'message' => 'A transfer request for this project is already pending. Cancel it from My Field Data first.', 'row' => null, 'mail' => false, 'reason' => 'pending exists');
		}

		$name = $this->projectName($projectId, $from);
		$recipient = $this->resolveRecipient($email);
		$reason = null;
		if (!$recipient) {
			$reason = 'No active StraboSpot account at ' . $email . '.';
			$toPkey = null;
		} else {
			$toPkey = (int)$recipient->pkey;
			$elig = $this->checkEligibility($projectId, $from, $toPkey);
			if (!$elig['ok']) $reason = $elig['reason'];
		}

		$uuid = self::newUuid();
		$summary = array(
			'requested_email' => $email,
			'counts_at_request' => $this->projectCounts($nids),
			'nids' => $nids,
		);
		if ($reason !== null) {
			// Refused: audit only. from = to placeholder keeps the FK happy
			// when no account matched (the reason carries the address).
			$summary['reason'] = $reason;
			$this->pq(
				"INSERT INTO project_transfers (uuid, kind, strabo_project_id, from_user_pkey, to_user_pkey, status,
				        keep_as_collaborator, project_name, expires_date, decided_date, requested_by_pkey, summary)
				 VALUES ($1, 'transfer', $2, $3, $4, 'refused', $5, $6, now(), now(), $7, $8::jsonb)",
				array($uuid, $projectId, $from, $toPkey === null ? $from : $toPkey, $keepAsCollaborator ? 't' : 'f',
					$name, $requestedBy, json_encode($summary))
			);
			return array('ok' => true, 'message' => self::neutralMessage(), 'row' => $this->getByUuid($uuid), 'mail' => false, 'reason' => $reason);
		}

		$this->pq(
			"INSERT INTO project_transfers (uuid, kind, strabo_project_id, from_user_pkey, to_user_pkey, status,
			        keep_as_collaborator, project_name, expires_date, requested_by_pkey, summary)
			 VALUES ($1, 'transfer', $2, $3, $4, 'pending', $5, $6, now() + ($7 || ' days')::interval, $8, $9::jsonb)",
			array($uuid, $projectId, $from, $toPkey, $keepAsCollaborator ? 't' : 'f', $name,
				(string)self::EXPIRY_DAYS, $requestedBy, json_encode($summary))
		);
		return array('ok' => true, 'message' => self::neutralMessage(), 'row' => $this->getByUuid($uuid), 'mail' => true, 'reason' => null);
	}

	public function getByUuid($uuid)
	{
		return $this->db->get_row_prepared("SELECT * FROM project_transfers WHERE uuid = $1", array((string)$uuid));
	}

	public function getByPkey($pkey)
	{
		return $this->db->get_row_prepared("SELECT * FROM project_transfers WHERE pkey = $1", array((int)$pkey));
	}

	/** Lazy expiry (no cron): call before any listing. Returns the rows expired by THIS call (so the caller can mail once). */
	public function expireStale()
	{
		$rows = (array)$this->db->get_results_prepared(
			"SELECT * FROM project_transfers WHERE status = 'pending' AND expires_date < now() ORDER BY pkey");
		if ($rows) {
			$this->pq("UPDATE project_transfers SET status = 'expired', decided_date = now()
			            WHERE status = 'pending' AND expires_date < now()");
		}
		return $rows;
	}

	/** Owner (or the admin) withdraws a pending request. */
	public function cancel($uuid, $actingPkey)
	{
		$row = $this->getByUuid($uuid);
		if (!$row) return array('ok' => false, 'reason' => 'Unknown transfer request.');
		if ($row->status !== 'pending') return array('ok' => false, 'reason' => 'This request is no longer pending.');
		if ((int)$row->from_user_pkey !== (int)$actingPkey && (int)$actingPkey !== 3) {
			return array('ok' => false, 'reason' => 'Only the project owner can cancel this request.');
		}
		$this->pq("UPDATE project_transfers SET status = 'cancelled', decided_date = now(), decided_by_pkey = $2 WHERE pkey = $1 AND status = 'pending'",
			array((int)$row->pkey, (int)$actingPkey));
		return array('ok' => true, 'row' => $this->getByPkey($row->pkey));
	}

	/** Recipient declines. */
	public function decline($uuid, $actingPkey)
	{
		$row = $this->getByUuid($uuid);
		if (!$row) return array('ok' => false, 'reason' => 'Unknown transfer request.');
		if ($row->status !== 'pending') return array('ok' => false, 'reason' => 'This request is no longer pending.');
		if ((int)$row->to_user_pkey !== (int)$actingPkey) return array('ok' => false, 'reason' => 'This request was not sent to your account.');
		$this->pq("UPDATE project_transfers SET status = 'declined', decided_date = now(), decided_by_pkey = $2 WHERE pkey = $1 AND status = 'pending'",
			array((int)$row->pkey, (int)$actingPkey));
		return array('ok' => true, 'row' => $this->getByPkey($row->pkey));
	}

	/**
	 * Recipient accepts: re-check eligibility (plain reason on failure, row
	 * -> failed), then execute. Returns the execute() result.
	 */
	public function accept($uuid, $actingPkey)
	{
		$row = $this->getByUuid($uuid);
		if (!$row) return array('ok' => false, 'reason' => 'Unknown transfer request.', 'row' => null);
		if ($row->status !== 'pending') return array('ok' => false, 'reason' => 'This request is no longer pending.', 'row' => $row);
		if ((int)$row->to_user_pkey !== (int)$actingPkey) return array('ok' => false, 'reason' => 'This request was not sent to your account.', 'row' => $row);
		if (strtotime($row->expires_date) < time()) {
			$this->expireStale();
			return array('ok' => false, 'reason' => 'This request has expired.', 'row' => $this->getByPkey($row->pkey));
		}
		$elig = $this->checkEligibility($row->strabo_project_id, (int)$row->from_user_pkey, (int)$row->to_user_pkey, array('skip_pending' => true));
		if (!$elig['ok']) {
			$summary = $this->summaryOf($row);
			$summary['reason'] = $elig['reason'];
			$this->pq("UPDATE project_transfers SET status = 'failed', decided_date = now(), decided_by_pkey = $2, summary = $3::jsonb WHERE pkey = $1",
				array((int)$row->pkey, (int)$actingPkey, json_encode($summary)));
			return array('ok' => false, 'reason' => $elig['reason'], 'row' => $this->getByPkey($row->pkey));
		}
		$this->pq("UPDATE project_transfers SET decided_date = now(), decided_by_pkey = $2 WHERE pkey = $1",
			array((int)$row->pkey, (int)$actingPkey));
		return $this->execute($this->getByPkey($row->pkey), (int)$actingPkey);
	}

	public function listOutgoing($pkey)
	{
		$this->expireStale();
		return (array)$this->db->get_results_prepared(
			"SELECT t.*, u.email AS to_email, u.firstname AS to_firstname, u.lastname AS to_lastname
			   FROM project_transfers t JOIN users u ON u.pkey = t.to_user_pkey
			  WHERE t.from_user_pkey = $1 AND t.status = 'pending' ORDER BY t.created_date",
			array((int)$pkey)
		);
	}

	public function listIncoming($pkey)
	{
		$this->expireStale();
		return (array)$this->db->get_results_prepared(
			"SELECT t.*, u.email AS from_email, u.firstname AS from_firstname, u.lastname AS from_lastname
			   FROM project_transfers t JOIN users u ON u.pkey = t.from_user_pkey
			  WHERE t.to_user_pkey = $1 AND t.status = 'pending' ORDER BY t.created_date",
			array((int)$pkey)
		);
	}

	// =======================================================================
	// Execution (§6)
	// =======================================================================

	/**
	 * Run (or resume) the rewrite for a row. Idempotent per step; resumes
	 * from row.step + 1. Locks both (project, owner) upload keys for the
	 * duration. Never throws: returns ['ok', 'row', 'error'].
	 */
	public function execute($row, $actorPkey)
	{
		if (!$row) return array('ok' => false, 'row' => null, 'error' => 'no row');
		if (!in_array($row->status, array('pending', 'failed'), true)) {
			return array('ok' => false, 'row' => $row, 'error' => 'Row is ' . $row->status . '; nothing to execute.');
		}
		$pid  = (string)$row->strabo_project_id;
		$from = (int)$row->from_user_pkey;
		$to   = (int)$row->to_user_pkey;
		$keep = ($row->keep_as_collaborator === 't' || $row->keep_as_collaborator === true);
		$summary = $this->summaryOf($row);
		$step = (int)$row->step;
		unset($summary['error'], $summary['failed_step']);
		// Wall-clock per step (ms), shown on the admin detail panel.
		if (!isset($summary['timings']) || !is_array($summary['timings'])) $summary['timings'] = array();
		$t0 = microtime(true);
		$lap = function ($name) use (&$summary, &$t0) {
			$now = microtime(true);
			$summary['timings'][$name] = (int)round(($now - $t0) * 1000);
			$t0 = $now;
		};

		try {
			$this->lockKey("project:$pid:$from");
			$this->lockKey("project:$pid:$to");

			if ($step < self::STEP_PREFLIGHT) {
				// Nothing has been rewritten yet (a retry after a failed
				// acceptance lands here too): the world may have changed.
				$elig = $this->checkEligibility($pid, $from, $to, array('skip_pending' => true));
				if (!$elig['ok']) throw new \RuntimeException($elig['reason']);
				$lap('eligibility');
				$this->stepPreflight($row, $pid, $from, $to, $summary);
				$lap('preflight');
				$step = self::STEP_PREFLIGHT;
				$this->saveProgress($row->pkey, $step, $summary);
			}
			$nids = array_map('intval', (array)$summary['nids']);

			if ($step < self::STEP_NEO) {
				// From here the old owner's copy is no longer clean: tombstone on.
				$this->pq("UPDATE project_transfers SET applied = TRUE WHERE pkey = $1", array((int)$row->pkey));
				$this->stepNeo($pid, $from, $to, $nids, $summary);
				$lap('neo4j');
				$step = self::STEP_NEO;
				$this->saveProgress($row->pkey, $step, $summary);
			}
			if ($step < self::STEP_PG) {
				$this->stepPg($row, $pid, $from, $to, $keep, $nids, (int)$actorPkey, $summary);
				$lap('postgres');
				$step = self::STEP_PG;
				// saved inside the transaction
			}
			if ($step < self::STEP_MIRROR) {
				$this->stepMirror($pid, $to, $summary);
				$lap('mirror');
				$step = self::STEP_MIRROR;
				$this->saveProgress($row->pkey, $step, $summary);
			}
			if ($step < self::STEP_SEARCH) {
				$this->stepSearch($pid, $from, $to, $summary);
				$lap('search');
				$step = self::STEP_SEARCH;
			}
			$summary['after'] = $this->verifyCounts($pid, $from, $to, $nids, isset($summary['sample_ids']) ? $summary['sample_ids'] : array());
			$lap('recount');
			$this->pq("UPDATE project_transfers SET status = 'accepted', step = $2, completed_date = now(), summary = $3::jsonb WHERE pkey = $1",
				array((int)$row->pkey, self::STEP_DONE, json_encode($summary)));
			// The new owner holds the project again: any tombstone that was
			// blocking THEIR uploads of this id is obsolete.
			$this->pq("UPDATE project_transfers SET tombstone_cleared_date = now()
			            WHERE strabo_project_id = $1 AND from_user_pkey = $2 AND applied AND tombstone_cleared_date IS NULL AND pkey <> $3",
				array($pid, $to, (int)$row->pkey));
			$this->releaseLocks();
			return array('ok' => true, 'row' => $this->getByPkey($row->pkey), 'error' => null);
		} catch (\Throwable $e) {
			if ($this->inTxn) { $this->db->query("ROLLBACK"); $this->inTxn = false; }
			$summary['error'] = substr($e->getMessage(), 0, 2000);
			$summary['failed_step'] = $step + 1;
			$this->neoReset();
			try {
				$this->pq("UPDATE project_transfers SET status = 'failed', step = $2, summary = $3::jsonb WHERE pkey = $1",
					array((int)$row->pkey, $step, json_encode($summary)));
			} catch (\Throwable $e2) {
				error_log('[project-transfer] could not record failure for ' . $row->pkey . ': ' . $e2->getMessage());
			}
			error_log('[project-transfer] ' . $row->uuid . ' FAILED at step ' . ($step + 1) . ': ' . $e->getMessage());
			$this->releaseLocks();
			return array('ok' => false, 'row' => $this->getByPkey($row->pkey), 'error' => $e->getMessage());
		}
	}

	/** Step 1: resolve node ids (never again by userpkey), snapshot counts. */
	protected function stepPreflight($row, $pid, $from, $to, array &$summary)
	{
		$nids = $this->projectNodeIds($pid, $from);
		if (!$nids && !empty($summary['nids'])) $nids = array_map('intval', (array)$summary['nids']);
		if (!$nids) throw new \RuntimeException('Project not found under the current owner.');
		$summary['nids'] = $nids;
		$summary['before'] = $this->verifyCounts($pid, $from, $to, $nids, array());
		$summary['project_name'] = $this->projectName($pid, $from);
		$summary['started'] = date('c');
		if (empty($summary['kind'])) $summary['kind'] = $row->kind;
	}

	/** Step 2: Neo4j subtree rewrite + HAS_PROJECT flip, anchored on internal ids. */
	protected function stepNeo($pid, $from, $to, array $nids, array &$summary)
	{
		$list = implode(',', $nids);
		$lit = self::idLit($pid);
		$nSpots = 0; $nKids = 0; $nDatasets = 0;

		$dsRows = $this->neo("MATCH (p:Project) WHERE id(p) IN [$list] MATCH (p)-[:HAS_DATASET]->(d:Dataset) RETURN DISTINCT id(d) AS dnid, d.id AS did");
		$datasetIds = array();
		foreach ((array)$dsRows as $r) {
			$dnid = (int)$r->value('dnid');
			$datasetIds[] = (string)$r->value('did');
			// Every node hanging off this dataset belongs to the project:
			// spots, and everything a spot points at within two hops
			// (images, orientations + associated orientations, rock units,
			// samples, traces, other features, 3D structures, inferences +
			// their relationships, legacy tag nodes). Other spots, datasets,
			// projects and users are excluded from the kid set by label.
			$rec = $this->neoRecord("
				MATCH (d:Dataset) WHERE id(d) = $dnid
				OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot)
				OPTIONAL MATCH (s)-[*1..2]->(x)
				WHERE NOT x:Spot AND NOT x:Dataset AND NOT x:Project AND NOT x:User
				WITH d, collect(DISTINCT s) AS spots, collect(DISTINCT x) AS kids
				FOREACH (n IN spots | SET n.userpkey = $to)
				FOREACH (n IN kids  | SET n.userpkey = $to)
				SET d.userpkey = $to
				RETURN size(spots) AS nspots, size(kids) AS nkids
			");
			if ($rec) { $nSpots += (int)$rec->value('nspots'); $nKids += (int)$rec->value('nkids'); }
			$nDatasets++;
		}
		// Project-level legacy children (RockUnit / Relationship / Tag nodes
		// hung directly off the project) and the project node itself.
		$this->neo("
			MATCH (p:Project) WHERE id(p) IN [$list]
			OPTIONAL MATCH (p)-[]->(x) WHERE NOT x:Dataset AND NOT x:User AND NOT x:Project
			WITH p, collect(DISTINCT x) AS kids
			FOREACH (n IN kids | SET n.userpkey = $to)
			SET p.userpkey = $to
		");
		// Edge properties carry the owner too (deleteProject matches on them).
		// Anchored on the subtree: Neo4j 3 has no relationship-property
		// index, and an unanchored `()-[r:IS_TAGGED {..}]->()` walks every
		// tag edge in the graph (~1.5 s per query on a prod-sized store).
		$rec = $this->neoRecord(self::projectEdgeMatch($list, $lit, $from) . " SET r.userpkey = $to RETURN count(r) AS n");
		$summary['edges_rewritten'] = $rec ? (int)$rec->value('n') : 0;
		// Ownership edge: drop every HAS_PROJECT into these nodes that is not
		// from the new owner, then make sure the new owner's exists.
		$this->neo("MATCH (u:User)-[r:HAS_PROJECT]->(p:Project) WHERE id(p) IN [$list] AND u.userpkey <> $to DELETE r");
		$this->neo("MATCH (u:User {userpkey: $to}), (p:Project) WHERE id(p) IN [$list] CREATE UNIQUE (u)-[:HAS_PROJECT]->(p)");

		$summary['dataset_ids'] = array_values(array_unique($datasetIds));
		$summary['neo'] = array('datasets' => $nDatasets, 'spots' => $nSpots, 'children' => $nKids);
	}

	/** Step 3: one PostgreSQL transaction over every relational store. */
	protected function stepPg($row, $pid, $from, $to, $keep, array $nids, $actorPkey, array &$summary)
	{
		$spotIds = $this->spotIdsUnder($nids);
		$isReversal = ($row->kind === 'reversal');

		$this->db->query("BEGIN");
		$this->inTxn = true;

		// --- PG mirror (project / dataset / spot / image / sample / rock_type)
		// The recipient may already hold copy rows for this project (an
		// accepted collaborator gets one per upload); drop them or the
		// recipient ends up with duplicates. Cascades take the children.
		$copies = (array)$this->db->get_results_prepared("SELECT project_pkey FROM project WHERE strabo_project_id = $1 AND user_pkey = $2", array($pid, $to));
		$nCopies = 0;
		foreach ($copies as $c) { $this->pq("DELETE FROM project WHERE project_pkey = $1", array((int)$c->project_pkey)); $nCopies++; }
		$owned = (array)$this->db->get_results_prepared("SELECT project_pkey FROM project WHERE strabo_project_id = $1 AND user_pkey = $2", array($pid, $from));
		$mirror = array('project' => 0, 'dataset' => 0, 'spot' => 0, 'image' => 0, 'sample' => 0, 'rock_type' => 0, 'recipient_copies_removed' => $nCopies);
		foreach ($owned as $o) {
			$ppk = (int)$o->project_pkey;
			foreach (array('dataset', 'spot', 'image', 'sample', 'rock_type') as $t) {
				$mirror[$t] += (int)$this->pq("UPDATE $t SET user_pkey = $2 WHERE project_pkey = $1", array($ppk, $to));
			}
			$mirror['project'] += (int)$this->pq("UPDATE project SET user_pkey = $2 WHERE project_pkey = $1", array($ppk, $to));
		}
		$summary['mirror'] = $mirror;

		// --- collaborators (D1)
		$removed = (array)$this->db->get_results_prepared(
			"SELECT collaboration_level, accepted, created_date, accepted_date, uuid, disabled FROM collaborators
			  WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3",
			array($pid, $from, $to));
		$summary['removed_collaborator_rows'] = array_map(function ($r) { return (array)$r; }, $removed);
		$this->pq("DELETE FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3", array($pid, $from, $to));
		$nCollab = (int)$this->pq("UPDATE collaborators SET project_owner_user_pkey = $3 WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array($pid, $from, $to));
		$summary['collaborators_rewritten'] = $nCollab;
		if ($keep) {
			$exists = $this->db->get_var_prepared("SELECT pkey FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3 LIMIT 1", array($pid, $to, $from));
			if ($exists) {
				$this->pq("UPDATE collaborators SET collaboration_level = 'admin', accepted = TRUE, disabled = FALSE, accepted_date = now() WHERE pkey = $1", array((int)$exists));
			} else {
				$this->pq("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, created_date, accepted_date, uuid, disabled)
				           VALUES ($1, $2, $3, 'admin', TRUE, now(), now(), $4, FALSE)", array($pid, $to, $from, self::newUuid()));
			}
			$summary['old_owner_kept_as_admin'] = true;
		}
		if ($isReversal && !empty($summary['restore_collaborator_rows'])) {
			// Put back the rows the original transfer removed (the then-
			// recipient's own collaborator rows), now under the restored owner.
			foreach ((array)$summary['restore_collaborator_rows'] as $rr) {
				$rr = (array)$rr;
				$dupe = $this->db->get_var_prepared("SELECT pkey FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3 LIMIT 1", array($pid, $to, $from));
				if ($dupe) continue;
				$this->pq("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, created_date, accepted_date, uuid, disabled)
				           VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)",
					array($pid, $to, $from, $rr['collaboration_level'], $this->b($rr['accepted']), $rr['created_date'], $rr['accepted_date'], $rr['uuid'] . '-r', $this->b($rr['disabled'])));
			}
		}

		// --- versions / verlog / vprojects / merge prefs
		$summary['versions']    = (int)$this->pq("UPDATE versions  SET userpkey = $3 WHERE projectid = $1 AND userpkey = $2", array($pid, $from, $to));
		$summary['verlog']      = (int)$this->pq("UPDATE verlog    SET userpkey = $3 WHERE projectid = $1 AND userpkey = $2", array($pid, $from, $to));
		$this->pq("DELETE FROM vprojects WHERE projectid = $1 AND userpkey = $2", array($pid, $to));
		$summary['vprojects']   = (int)$this->pq("UPDATE vprojects SET userpkey = $3 WHERE projectid = $1 AND userpkey = $2", array($pid, $from, $to));
		$this->pq("DELETE FROM project_merge_prefs WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array($pid, $to));
		$summary['merge_prefs'] = (int)$this->pq("UPDATE project_merge_prefs SET project_owner_user_pkey = $3 WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array($pid, $from, $to));

		// --- StraboSamples spine (D3: re-key in place)
		$summary['sample_ids'] = array();
		if ($this->samplesSchemaExists()) {
			$summary['sample_ids'] = $this->rekeySamples($row, $pid, $from, $to, $keep, $spotIds, $actorPkey);
		}
		$summary['samples'] = count($summary['sample_ids']);

		// Progress is saved inside the transaction so a commit = step done.
		$this->pq("UPDATE project_transfers SET step = $2, summary = $3::jsonb WHERE pkey = $1",
			array((int)$row->pkey, self::STEP_PG, json_encode($summary)));

		if ($this->db->query("COMMIT") === false) throw new \RuntimeException('COMMIT failed: ' . $this->db->last_error);
		$this->inTxn = false;
	}

	/**
	 * Samples hosted by the project's spots move to the new owner. The
	 * spine's identity is (id, userpkey) with cascade-only FKs, so the
	 * parent row is COPIED under the new key first, children are re-pointed,
	 * then the old parent row is deleted (nothing is left to cascade).
	 * Returns the moved sample ids.
	 */
	protected function rekeySamples($row, $pid, $from, $to, $keep, array $spotIds, $actorPkey)
	{
		$sampleIds = $this->hostedSampleIds($spotIds, $from);
		if (!$sampleIds) return array();
		// Never let ON CONFLICT DO NOTHING hide a same-id sample on the
		// receiving side: that would keep theirs and delete ours below.
		foreach (array_chunk($sampleIds, 500) as $chunk) {
			$c = (int)$this->db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $1 AND id = ANY($2::text[])", array($to, self::pgTextArray($chunk)));
			if ($c > 0) throw new \RuntimeException("Sample id collision: the receiving account already holds $c sample(s) with ids from this project.");
		}

		$changes = json_encode(array(
			'from_userpkey' => $from, 'to_userpkey' => $to,
			'transfer_uuid' => $row->uuid, 'kind' => $row->kind, 'project_id' => $pid,
		));
		foreach (array_chunk($sampleIds, 500) as $chunk) {
			$arr = self::pgTextArray($chunk);
			// 1. copy parent rows under the new key
			$this->pq(
				"INSERT INTO strabosamples.samples
				   (id, userpkey, name, igsn, description, notes, latitude, longitude, display_sample_type,
				    display_sample_purpose, parent_sample_id, parent_userpkey, created_at, created_by, modified_at,
				    modified_by, field_data, micro_data, experimental_data, custom_data)
				 SELECT id, $2, name, igsn, description, notes, latitude, longitude, display_sample_type,
				        display_sample_purpose, parent_sample_id, parent_userpkey, created_at, created_by, modified_at,
				        modified_by, field_data, micro_data, experimental_data, custom_data
				   FROM strabosamples.samples WHERE userpkey = $1 AND id = ANY($3::text[])
				 ON CONFLICT (id, userpkey) DO NOTHING",
				array($from, $to, $arr));
			// 2. re-point children
			foreach (array('sample_collaborators', 'sample_changelog', 'sample_composition', 'sample_parameters', 'sample_documents', 'sample_subsystem_links') as $t) {
				$this->pq("UPDATE strabosamples.$t SET sample_userpkey = $2 WHERE sample_userpkey = $1 AND sample_id = ANY($3::text[])", array($from, $to, $arr));
			}
			// the Field reference (the spot) changed owner too
			$this->pq("UPDATE strabosamples.sample_subsystem_links SET reference_userpkey = $2, modified_at = now()
			            WHERE subsystem = 'field' AND sample_userpkey = $2 AND reference_userpkey = $1 AND sample_id = ANY($3::text[])", array($from, $to, $arr));
			// 3. anything pointing at a moved parent follows it (moving or not)
			$this->pq("UPDATE strabosamples.samples SET parent_userpkey = $2 WHERE parent_userpkey = $1 AND parent_sample_id = ANY($3::text[])", array($from, $to, $arr));
			// 4. drop the old parent rows (children already moved: nothing cascades)
			$this->pq("DELETE FROM strabosamples.samples WHERE userpkey = $1 AND id = ANY($2::text[])", array($from, $arr));
			// 5. the recipient is now the owner: end any collaborator row of theirs on these samples
			$this->pq("UPDATE strabosamples.sample_collaborators SET removed_at = now()
			            WHERE sample_userpkey = $1 AND collaborator_pkey = $1 AND removed_at IS NULL AND sample_id = ANY($2::text[])", array($to, $arr));
			// 6. old owner stays on (D1) as an edit collaborator on the samples
			if ($keep) {
				$this->pq(
					"INSERT INTO strabosamples.sample_collaborators
					   (sample_id, sample_userpkey, collaborator_pkey, permission_level, uuid, accepted, accepted_at, added_by)
					 SELECT s.id, s.userpkey, $2, 'edit', md5(random()::text || s.id || clock_timestamp()::text), TRUE, now(), $3
					   FROM strabosamples.samples s
					  WHERE s.userpkey = $1 AND s.id = ANY($4::text[])
					    AND NOT EXISTS (SELECT 1 FROM strabosamples.sample_collaborators c
					                     WHERE c.sample_id = s.id AND c.sample_userpkey = s.userpkey
					                       AND c.collaborator_pkey = $2 AND c.removed_at IS NULL)",
					array($to, $from, $actorPkey, $arr));
			}
			// 7. audit trail on every moved sample
			$this->pq(
				"INSERT INTO strabosamples.sample_changelog (sample_id, sample_userpkey, changed_by, change_type, source_subsystem, changes)
				 SELECT id, userpkey, $2, $3, 'field', $4::jsonb FROM strabosamples.samples WHERE userpkey = $1 AND id = ANY($5::text[])",
				array($to, $actorPkey, self::CHANGE_TYPE, $changes, $arr));
		}
		return $sampleIds;
	}

	/** Step 4: refresh the mirror project row's keyword vector (it embeds the owner's name). */
	protected function stepMirror($pid, $to, array &$summary)
	{
		$u = $this->userRow($to);
		$fn = $u ? (string)$u->firstname : '';
		$ln = $u ? (string)$u->lastname : '';
		$n = (int)$this->pq(
			"UPDATE project SET keywords = to_tsvector(coalesce(project_name,'') || ' ' || coalesce(notes,'') || ' ' || $3 || ' ' || $4),
			                    last_modified = now()
			  WHERE strabo_project_id = $1 AND user_pkey = $2",
			array($pid, $to, $fn, $ln));
		$summary['mirror_keywords_refreshed'] = $n;
	}

	/** Step 5: StraboSearch slice moves owner (remove old slice, re-extract as new owner). */
	protected function stepSearch($pid, $from, $to, array &$summary)
	{
		if (empty($this->db->get_var("SELECT to_regclass('strabosearch.item_hit')"))) { $summary['search'] = 'index absent'; return; }
		require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';
		if (!function_exists('neoIdLiteral')) require_once __DIR__ . '/../../searchdb/extractors/_row_builders.php';
		StraboSearchSync::removeFieldProject($this->db, $pid, $from);
		$n = 0;
		foreach ((array)(isset($summary['dataset_ids']) ? $summary['dataset_ids'] : array()) as $did) {
			StraboSearchSync::syncFieldDataset($this->db, $this->neodb, $did, $to);
			$n++;
		}
		$ns = 0;
		foreach ((array)(isset($summary['sample_ids']) ? $summary['sample_ids'] : array()) as $sid) {
			StraboSearchSync::removeSample($this->db, $sid, $from);
			StraboSearchSync::touchSample($this->db, $sid, $to);
			$ns++;
		}
		$summary['search'] = array('datasets_reextracted' => $n, 'samples_touched' => $ns);
	}

	// =======================================================================
	// Reverse (D6): a new row, parties swapped, executed the same way
	// =======================================================================

	/**
	 * @return array ['ok', 'row' (the reversal row), 'reason'|'error']
	 */
	public function reverse($pkey, $adminPkey)
	{
		$orig = $this->getByPkey($pkey);
		if (!$orig) return array('ok' => false, 'reason' => 'Unknown transfer.', 'row' => null);
		if ($orig->status !== 'accepted' || $orig->reversed_date !== null) {
			return array('ok' => false, 'reason' => 'Only a completed, not yet reversed transfer can be reversed.', 'row' => $orig);
		}
		$pid = (string)$orig->strabo_project_id;
		$newFrom = (int)$orig->to_user_pkey;   // current owner
		$newTo   = (int)$orig->from_user_pkey; // original owner gets it back

		$elig = $this->checkEligibility($pid, $newFrom, $newTo, array('skip_pending' => true));
		if (!$elig['ok']) return array('ok' => false, 'reason' => $elig['reason'], 'row' => $orig);

		$os = $this->summaryOf($orig);
		$summary = array(
			'reverses_pkey' => (int)$orig->pkey,
			'reverses_uuid' => $orig->uuid,
			'nids' => $elig['nids'],
			'restore_collaborator_rows' => isset($os['removed_collaborator_rows']) ? $os['removed_collaborator_rows'] : array(),
			'kind' => 'reversal',
		);
		$uuid = self::newUuid();
		$this->pq(
			"INSERT INTO project_transfers (uuid, kind, strabo_project_id, from_user_pkey, to_user_pkey, status,
			        keep_as_collaborator, project_name, expires_date, decided_date, requested_by_pkey, decided_by_pkey, summary)
			 VALUES ($1, 'reversal', $2, $3, $4, 'pending', FALSE, $5, now(), now(), $6, $6, $7::jsonb)",
			array($uuid, $pid, $newFrom, $newTo, $orig->project_name, (int)$adminPkey, json_encode($summary)));
		$rev = $this->getByUuid($uuid);
		$res = $this->execute($rev, (int)$adminPkey);
		if ($res['ok']) $this->markReversed((int)$orig->pkey, (int)$adminPkey);
		return array('ok' => $res['ok'], 'row' => $res['row'], 'reason' => $res['error'], 'error' => $res['error']);
	}

	/**
	 * Stamp the original row once its reversal has completed. Called by
	 * reverse() on success and by the admin Retry of a failed reversal row
	 * (so a resumed reversal still marks what it reverses). Idempotent.
	 */
	public function markReversed($origPkey, $adminPkey)
	{
		$this->pq("UPDATE project_transfers SET reversed_date = coalesce(reversed_date, now()), reversed_by_pkey = coalesce(reversed_by_pkey, $2),
		            tombstone_cleared_date = coalesce(tombstone_cleared_date, now()) WHERE pkey = $1", array((int)$origPkey, (int)$adminPkey));
	}

	// =======================================================================
	// Verify: read-only recount of every store for a transfer row
	// =======================================================================

	/**
	 * @return array ['stores' => [name => ['from' => n, 'to' => n]], 'edge_owners' => int[], 'clean' => bool]
	 *   clean = no store (DOIs excepted) still holds the old owner and the
	 *   HAS_PROJECT edge belongs to the new owner only.
	 */
	public function verify($row)
	{
		$summary = $this->summaryOf($row);
		$pid = (string)$row->strabo_project_id;
		$from = (int)$row->from_user_pkey;
		$to = (int)$row->to_user_pkey;
		$nids = !empty($summary['nids']) ? array_map('intval', (array)$summary['nids']) : array_merge($this->projectNodeIds($pid, $to), $this->projectNodeIds($pid, $from));
		$nids = array_values(array_unique($nids));
		return $this->verifyCounts($pid, $from, $to, $nids, isset($summary['sample_ids']) ? (array)$summary['sample_ids'] : array());
	}

	private function verifyCounts($pid, $from, $to, array $nids, array $sampleIds)
	{
		$stores = array();
		$edgeOwners = array();
		if ($nids) {
			$list = implode(',', $nids);
			$rows = $this->neo("
				MATCH (p:Project) WHERE id(p) IN [$list]
				OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset)
				OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot)
				OPTIONAL MATCH (s)-[*1..2]->(x)
				WHERE NOT x:Spot AND NOT x:Dataset AND NOT x:Project AND NOT x:User
				WITH collect(DISTINCT p) + collect(DISTINCT d) + collect(DISTINCT s) + collect(DISTINCT x) AS ns
				UNWIND ns AS n
				RETURN n.userpkey AS upk, count(n) AS c
			");
			$stores['neo4j_nodes'] = array('from' => 0, 'to' => 0, 'other' => 0);
			foreach ((array)$rows as $r) {
				$u = (int)$r->value('upk'); $c = (int)$r->value('c');
				if ($u === $from) $stores['neo4j_nodes']['from'] += $c;
				elseif ($u === $to) $stores['neo4j_nodes']['to'] += $c;
				else $stores['neo4j_nodes']['other'] += $c;
			}
			foreach ((array)$this->neo("MATCH (u:User)-[:HAS_PROJECT]->(p:Project) WHERE id(p) IN [$list] RETURN DISTINCT u.userpkey AS upk") as $r) {
				$edgeOwners[] = (int)$r->value('upk');
			}
			$lit = self::idLit($pid);
			$stores['neo4j_edge_props'] = array(
				'from' => (int)$this->neodb->get_var(self::projectEdgeMatch($list, $lit, $from) . " RETURN count(r)"),
				'to'   => (int)$this->neodb->get_var(self::projectEdgeMatch($list, $lit, $to) . " RETURN count(r)"),
			);
		}
		$cnt = function ($sql, $params) { return (int)$this->db->get_var_prepared($sql, $params); };
		$stores['pg_project'] = array(
			'from' => $cnt("SELECT count(*) FROM project WHERE strabo_project_id = $1 AND user_pkey = $2", array($pid, $from)),
			'to'   => $cnt("SELECT count(*) FROM project WHERE strabo_project_id = $1 AND user_pkey = $2", array($pid, $to)));
		foreach (array('dataset', 'spot', 'image', 'sample', 'rock_type') as $t) {
			$stores["pg_$t"] = array(
				'from' => $cnt("SELECT count(*) FROM $t x JOIN project p ON p.project_pkey = x.project_pkey WHERE p.strabo_project_id = $1 AND (x.user_pkey = $2 OR p.user_pkey = $2)", array($pid, $from)),
				'to'   => $cnt("SELECT count(*) FROM $t x JOIN project p ON p.project_pkey = x.project_pkey WHERE p.strabo_project_id = $1 AND x.user_pkey = $2 AND p.user_pkey = $2", array($pid, $to)));
		}
		$stores['collaborators_as_owner'] = array(
			'from' => $cnt("SELECT count(*) FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array($pid, $from)),
			'to'   => $cnt("SELECT count(*) FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array($pid, $to)));
		foreach (array('versions' => 'projectid', 'verlog' => 'projectid', 'vprojects' => 'projectid') as $t => $col) {
			$stores[$t] = array(
				'from' => $cnt("SELECT count(*) FROM $t WHERE $col = $1 AND userpkey = $2", array($pid, $from)),
				'to'   => $cnt("SELECT count(*) FROM $t WHERE $col = $1 AND userpkey = $2", array($pid, $to)));
		}
		$stores['merge_prefs'] = array(
			'from' => $cnt("SELECT count(*) FROM project_merge_prefs WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array($pid, $from)),
			'to'   => $cnt("SELECT count(*) FROM project_merge_prefs WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array($pid, $to)));
		$stores['dois_untouched'] = array(
			'from' => $cnt("SELECT count(*) FROM dois WHERE strabo_project_id = $1 AND user_pkey = $2", array($pid, $from)),
			'to'   => $cnt("SELECT count(*) FROM dois WHERE strabo_project_id = $1 AND user_pkey = $2", array($pid, $to)));
		if ($this->samplesSchemaExists()) {
			if ($sampleIds) {
				$arr = self::pgTextArray($sampleIds);
				$stores['samples_spine'] = array(
					'from' => $cnt("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $1 AND id = ANY($2::text[])", array($from, $arr)),
					'to'   => $cnt("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $1 AND id = ANY($2::text[])", array($to, $arr)));
				$stores['samples_children'] = array(
					'from' => $cnt("SELECT (SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_collaborators WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_composition WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_parameters WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_documents WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))", array($from, $arr)),
					'to'   => $cnt("SELECT (SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_collaborators WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_composition WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_parameters WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))
					                     + (SELECT count(*) FROM strabosamples.sample_documents WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[]))", array($to, $arr)));
			} else {
				// Before the PG step the moved set is unknown: count Field-hosted samples by project.
				$stores['samples_spine'] = array(
					'from' => $cnt("SELECT count(DISTINCT sample_id) FROM strabosamples.sample_subsystem_links WHERE subsystem = 'field' AND sample_userpkey = $2 AND reference_metadata->>'project_id' = $1", array($pid, $from)),
					'to'   => $cnt("SELECT count(DISTINCT sample_id) FROM strabosamples.sample_subsystem_links WHERE subsystem = 'field' AND sample_userpkey = $2 AND reference_metadata->>'project_id' = $1", array($pid, $to)));
			}
		}
		if (!empty($this->db->get_var("SELECT to_regclass('strabosearch.item_hit')"))) {
			$stores['search_items'] = array(
				'from' => $cnt("SELECT count(*) FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND project_id = $1 AND project_userpkey = $2", array($pid, $from)),
				'to'   => $cnt("SELECT count(*) FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND project_id = $1 AND project_userpkey = $2", array($pid, $to)));
			$stores['search_images'] = array(
				'from' => $cnt("SELECT count(*) FROM strabosearch.image_hit WHERE image_subsystem = 'field' AND project_id = $1 AND project_userpkey = $2", array($pid, $from)),
				'to'   => $cnt("SELECT count(*) FROM strabosearch.image_hit WHERE image_subsystem = 'field' AND project_id = $1 AND project_userpkey = $2", array($pid, $to)));
			if ($sampleIds) {
				$arr = self::pgTextArray($sampleIds);
				$stores['search_samples'] = array(
					'from' => $cnt("SELECT count(*) FROM strabosearch.item_hit WHERE item_type = 'sample' AND item_userpkey = $1 AND item_id = ANY($2::text[])", array($from, $arr)),
					'to'   => $cnt("SELECT count(*) FROM strabosearch.item_hit WHERE item_type = 'sample' AND item_userpkey = $1 AND item_id = ANY($2::text[])", array($to, $arr)));
			}
		}
		$clean = true;
		foreach ($stores as $name => $c) {
			if ($name === 'dois_untouched') continue;
			if (!empty($c['from'])) $clean = false;
		}
		if ($edgeOwners !== array($to)) $clean = false;
		return array('stores' => $stores, 'edge_owners' => $edgeOwners, 'clean' => $clean, 'checked' => date('c'));
	}

	// =======================================================================
	// Internals
	// =======================================================================

	private function samplesSchemaExists()
	{
		static $k = null;
		if ($k === null) $k = !empty($this->db->get_var("SELECT to_regclass('strabosamples.samples')"));
		return $k;
	}

	private function saveProgress($pkey, $step, array $summary)
	{
		$this->pq("UPDATE project_transfers SET step = $2, summary = $3::jsonb WHERE pkey = $1", array((int)$pkey, (int)$step, json_encode($summary)));
	}

	/**
	 * Progress of a row for the polling pages: status, last completed step,
	 * the failed step if any, and the step labels in order.
	 */
	public function progressOf($row)
	{
		$summary = $this->summaryOf($row);
		return array(
			'found'       => true,
			'status'      => (string)$row->status,
			'step'        => (int)$row->step,
			'steps'       => self::STEP_DONE,
			'failed_step' => isset($summary['failed_step']) ? (int)$summary['failed_step'] : null,
			'applied'     => ($row->applied === 't' || $row->applied === true),
			'labels'      => array_values(self::STEP_LABELS),
		);
	}

	public function summaryOf($row)
	{
		$s = isset($row->summary) && $row->summary !== null && $row->summary !== '' ? json_decode($row->summary, true) : array();
		return is_array($s) ? $s : array();
	}

	/** prepare_query that throws instead of returning false. Returns rows affected / selected. */
	private function pq($sql, array $params = array())
	{
		$r = $this->db->prepare_query($sql, $params);
		if ($r === false) {
			$err = isset($this->db->last_error) ? $this->db->last_error : 'unknown database error';
			throw new \RuntimeException('PostgreSQL: ' . $err . ' [' . substr(preg_replace('/\s+/', ' ', $sql), 0, 160) . ']');
		}
		return $r;
	}

	private function neo($cypher)
	{
		try {
			return $this->neodb->query($cypher);
		} catch (\Throwable $e) {
			$this->neoReset();
			throw new \RuntimeException('Neo4j: ' . $e->getMessage() . ' [' . substr(preg_replace('/\s+/', ' ', $cypher), 0, 160) . ']');
		}
	}

	private function neoRecord($cypher)
	{
		$rows = $this->neo($cypher);
		return (is_array($rows) && isset($rows[0])) ? $rows[0] : null;
	}

	/** A failed Cypher run leaves the Bolt connection dead for the next caller. */
	private function neoReset()
	{
		try { if (method_exists($this->neodb, 'reconnect')) $this->neodb->reconnect(); } catch (\Throwable $e) { /* best effort */ }
	}

	private function lockKey($key)
	{
		$this->pq("SELECT pg_advisory_lock(" . self::LOCK_CLASS . ", hashtext($1))", array($key));
		$this->heldLocks[] = $key;
	}

	private function releaseLocks()
	{
		foreach ($this->heldLocks as $key) {
			try { $this->db->prepare_query("SELECT pg_advisory_unlock(" . self::LOCK_CLASS . ", hashtext($1))", array($key)); } catch (\Throwable $e) { /* connection close releases anyway */ }
		}
		$this->heldLocks = array();
	}

	private function b($v)
	{
		return ($v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1') ? 't' : 'f';
	}

	/** Numeric Strabo ids are LONGs in Neo4j: never quote them (see neoIdLiteral in searchdb). */
	/**
	 * Cypher prefix binding `r` = every DISTINCT IS_TAGGED / IS_RELATED_TO
	 * edge of this project stamped with $upk, reached from the project
	 * nodes rather than by a graph-wide edge scan. IS_TAGGED edges always
	 * end at a Tag node and every Tag hangs off its Project; IS_RELATED_TO
	 * joins spots, tags, orientations and samples, all of which are the
	 * project's direct children or a spot's direct children. Append
	 * `SET ...` / `RETURN count(r)`.
	 */
	private static function projectEdgeMatch($list, $lit, $upk)
	{
		return "
			MATCH (p:Project) WHERE id(p) IN [$list]
			OPTIONAL MATCH (p)-->(k) WHERE NOT k:Dataset AND NOT k:User AND NOT k:Project
			WITH collect(DISTINCT k) AS ks
			MATCH (p2:Project) WHERE id(p2) IN [$list]
			OPTIONAL MATCH (p2)-[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(s:Spot)
			OPTIONAL MATCH (s)-[*0..1]->(c) WHERE NOT c:Dataset AND NOT c:Project AND NOT c:User
			WITH ks + collect(DISTINCT c) AS ns
			UNWIND ns AS a
			MATCH (a)-[r:IS_TAGGED|IS_RELATED_TO]-()
			WHERE r.projectid = $lit AND r.userpkey = " . (int)$upk . "
			WITH DISTINCT r
		";
	}

	public static function idLit($id)
	{
		if (ctype_digit((string)$id)) return (string)$id;
		return "'" . str_replace(array("\\", "'"), array("\\\\", "\\'"), (string)$id) . "'";
	}

	/** PostgreSQL text[] literal for a bound parameter. */
	public static function pgTextArray(array $values)
	{
		$parts = array();
		foreach ($values as $v) $parts[] = '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$v) . '"';
		return '{' . implode(',', $parts) . '}';
	}

	public static function newUuid()
	{
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}
}
