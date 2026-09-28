<?php
/**
 * File: includes/sesar/SesarDeactivate.php
 * Description: SESAR deactivation requests (Phase 7; decision D7 in
 *              docs/StraboSamples_IGSN_Feature_Request/IGSN_Design_Decisions.md,
 *              revised by the Phase 7 design review Q1-Q3, 2026-09-27).
 *
 *              IGSNs are never deleted. A user asks, a SESAR curator
 *              approves (the IGSN becomes a 410 tombstone) or denies (SESAR
 *              emails the user). StraboSpot tracks it on the registration row:
 *                active -> deactivation_requested -> deactivated (history,
 *                active FALSE), or back to active when SESAR declined.
 *
 *              preview()   signed-in read before the dialog: may this account
 *                          ask (SESAR's can_deactivate, Q3)? Writes nothing
 *                          but a 410 (markGone). A request that seems to be
 *                          pending at SESAR already is only REPORTED (Jason
 *                          2026-09-27: opening a dialog never changes a row).
 *              markPending()  "Show as requested": the owner's choice to
 *                          record such a request (reason NULL = unknown).
 *              release()   "Show as active again": undoes a row recorded
 *                          without a reason. can_deactivate false is the only
 *                          evidence for it (SESAR lists no pending requests),
 *                          so the owner can always take it back. Requests
 *                          sent from here (they carry a reason) stay until
 *                          SESAR decides.
 *              request()   type-the-IGSN confirmation, SESAR's reason rules,
 *                          POST .../deactivate/. Works for a sample or for an
 *                          orphan row whose sample was deleted (Q2).
 *              check()     "Check with SESAR now": signed-in, so it also sees a
 *                          DENIAL (can_deactivate true again) (Q1).
 *              sweep()     nightly job: anonymous 410 lookups of every pending
 *                          request, approvals applied (Q1).
 *              markGone()  one place for "SESAR says 410": our own request ->
 *                          row deactivated + IGSN removed from the sample (D7);
 *                          not requested -> row deactivated, IGSN text kept,
 *                          badge shown, the owner decides (Build Plan, Phase 7).
 *              orphans() / keepOrphan()
 *                          "IGSNs whose sample was deleted" on the IGSN page.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarDb.php';
require_once __DIR__ . '/SesarAccess.php';
require_once __DIR__ . '/SesarClient.php';
require_once __DIR__ . '/SesarConnection.php';
require_once __DIR__ . '/SesarMapper.php';
require_once __DIR__ . '/SesarMint.php';

class SesarDeactivate
{
	/** SESAR's reasons, verbatim (DeactivateReasonEnum), with our plain labels. */
	const REASONS = array(
		'this was a test sample'     => 'This was a test sample',
		'this sample does not exist' => 'This sample does not exist',
		'duplicate igsn'             => 'Duplicate IGSN (another IGSN names the same specimen)',
		'other'                      => 'Other',
	);
	const MAX_OTHER = 250;
	const MAX_DUPLICATES = 1000;

	private $db;
	private $client;
	private $conn;
	private $env;
	/** @var callable fn($userpkey, $sampleId) -> true | error string: clears the sample's IGSN field */
	private $clearIgsn;

	/**
	 * @param callable|null $clearIgsn production passes serviceIgsnClearer()
	 *        (StraboSamplesService::updateSample: writeback, search, changelog)
	 */
	public function __construct($db, SesarClient $client, SesarConnection $conn, $clearIgsn = null)
	{
		$this->db = $db;
		$this->client = $client;
		$this->conn = $conn;
		$this->env = $client->environment();
		$this->clearIgsn = $clearIgsn;
	}

	public static function serviceIgsnClearer($db, $neodb)
	{
		return function ($userpkey, $sampleId) use ($db, $neodb) {
			require_once __DIR__ . '/../../samplesdb/services/StraboSamplesService.php';
			$svc = new StraboSamplesService($db, $neodb);
			$svc->setUserpkey((int)$userpkey);
			$r = $svc->updateSample((string)$sampleId, (int)$userpkey, array('igsn' => null));
			return !empty($r['ok']) ? true : (isset($r['error']) ? (string)$r['error'] : 'unknown');
		};
	}

	// =======================================================================
	// Dialog
	// =======================================================================

	/**
	 * @param array $target {sample_id} or {reg} (an orphan row's pkey)
	 * @return array {state: ready|pending|deactivated, igsn, landing_url, environment,
	 *                name, orphan, reasons{value: label}, message}
	 *   pending = SESAR is not taking a request for it now; nothing recorded
	 *   (markPending() records it when the owner says so).
	 */
	public function preview($userpkey, array $target)
	{
		$userpkey = (int)$userpkey;
		$reg = $this->target($userpkey, $target);
		$this->assertAskable($reg);
		$out = $this->base($reg);
		$det = $this->readDetail($userpkey, $reg);
		if ($det === 'gone') {
			$out['state'] = 'deactivated';
			$out['message'] = $reg->igsn . ' is already deactivated at SESAR.';
			return $out;
		}
		if (!$det['can_deactivate']) {
			if (!$det['can_edit']) {
				throw new SesarError(403, 'Your SESAR account cannot ask for ' . $reg->igsn . ' to be deactivated: it belongs to another SESAR account.');
			}
			$out['state'] = 'pending';
			$out['message'] = 'SESAR is not taking a deactivation request for ' . $reg->igsn . ' right now. That usually means a request is already waiting there'
				. ' (one made on SESAR\'s own site, for example). Nothing has changed in StraboSpot.';
			return $out;
		}
		$out['state'] = 'ready';
		return $out;
	}

	/**
	 * "Show as requested": record a request that seems to be pending at SESAR
	 * (SESAR is asked again first). Reason NULL = not sent from here.
	 * @return array {ok, igsn, state: requested|deactivated, message}
	 */
	public function markPending($userpkey, array $target)
	{
		$userpkey = (int)$userpkey;
		$reg = $this->target($userpkey, $target);
		$this->assertAskable($reg);
		$lockKey = $reg->orphan ? 'reg:' . $reg->pkey : (string)$reg->sample_id;
		if (!SesarDb::lock($this->db, $userpkey, $lockKey)) {
			throw new SesarError(409, 'This IGSN is busy with another SESAR action. Please wait a moment and try again.', array('busy' => array('busy')));
		}
		try {
			$det = $this->readDetail($userpkey, $reg);
			if ($det === 'gone') {
				return array('ok' => true, 'igsn' => $reg->igsn, 'state' => 'deactivated', 'message' => $reg->igsn . ' is already deactivated at SESAR.');
			}
			if ($det['can_deactivate']) {
				throw new SesarError(409, 'SESAR has no deactivation request waiting for ' . $reg->igsn . '. Use Request deactivation to ask for one.');
			}
			if (!$det['can_edit']) {
				throw new SesarError(403, 'Your SESAR account cannot ask for ' . $reg->igsn . ' to be deactivated: it belongs to another SESAR account.');
			}
			$this->markRequested($reg, null, null);
			return array('ok' => true, 'igsn' => $reg->igsn, 'state' => 'requested',
				'message' => $reg->igsn . ' is now shown as deactivation requested. Check with SESAR shows the decision; Show as active again takes this back.');
		} finally {
			SesarDb::unlock($this->db, $userpkey, $lockKey);
		}
	}

	/**
	 * "Show as active again": takes back a row recorded WITHOUT a reason
	 * (markPending, or request() meeting can_deactivate false). Nothing is
	 * sent to SESAR. A request sent from here is never released this way.
	 * @return array {ok, igsn, state: active, message}
	 */
	public function release($userpkey, array $target)
	{
		$userpkey = (int)$userpkey;
		$reg = $this->target($userpkey, $target);
		if ($reg->state !== 'deactivation_requested') throw new SesarError(409, 'There is no deactivation request shown for ' . $reg->igsn . '.');
		if ($reg->deactivation_reason !== null) {
			throw new SesarError(409, 'The deactivation request for ' . $reg->igsn . ' was sent to SESAR from StraboSpot, so it stays until SESAR decides. Use Check with SESAR.');
		}
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_registrations
			    SET state = 'active', deactivation_requested_at = NULL, deactivation_detail = NULL, updated_at = now()
			  WHERE pkey = $1 AND active AND state = 'deactivation_requested' AND deactivation_reason IS NULL",
			array((int)$reg->pkey)
		);
		return array('ok' => true, 'igsn' => $reg->igsn, 'state' => 'active', 'message' => $reg->igsn . ' is shown as active again.');
	}

	/**
	 * @param array $in reason (a REASONS key), detail (other text / duplicate
	 *                  IGSNs), confirm (the typed IGSN)
	 * @return array {ok, igsn, state: requested|deactivated, message}
	 */
	public function request($userpkey, array $target, array $in)
	{
		$userpkey = (int)$userpkey;
		$reason = isset($in['reason']) ? (string)$in['reason'] : '';
		$detail = trim(isset($in['detail']) ? (string)$in['detail'] : '');
		if (!isset(self::REASONS[$reason])) throw new SesarError(400, 'Choose a reason.', array('reason' => array('required')));
		if ($reason === 'other') {
			if ($detail === '') throw new SesarError(400, 'Say briefly why it should be deactivated.', array('detail' => array('required')));
			if (mb_strlen($detail) > self::MAX_OTHER) throw new SesarError(400, 'Keep the reason under ' . self::MAX_OTHER . ' characters.', array('detail' => array('too_long')));
		} elseif ($reason === 'duplicate igsn') {
			if ($detail === '') throw new SesarError(400, 'Enter the IGSN(s) this one duplicates.', array('detail' => array('required')));
			if (mb_strlen($detail) > self::MAX_DUPLICATES) throw new SesarError(400, 'That list is too long for SESAR (' . self::MAX_DUPLICATES . ' characters at most).', array('detail' => array('too_long')));
		} else {
			$detail = '';
		}

		$reg = $this->target($userpkey, $target);
		$this->assertAskable($reg);
		if (!self::sameIgsn(isset($in['confirm']) ? $in['confirm'] : '', $reg->igsn)) {
			throw new SesarError(400, 'Type the IGSN exactly (' . $reg->igsn . ') to confirm.', array('confirm' => array('mismatch')));
		}

		$lockKey = $reg->orphan ? 'reg:' . $reg->pkey : (string)$reg->sample_id;   // a sample shares its key with mint / pull / push
		if (!SesarDb::lock($this->db, $userpkey, $lockKey)) {
			throw new SesarError(409, 'This IGSN is busy with another SESAR action. Please wait a moment and try again.', array('busy' => array('busy')));
		}
		try {
			$det = $this->readDetail($userpkey, $reg);
			if ($det === 'gone') {
				return array('ok' => true, 'igsn' => $reg->igsn, 'state' => 'deactivated', 'message' => $reg->igsn . ' was already deactivated at SESAR.');
			}
			if (!$det['can_deactivate']) {
				if (!$det['can_edit']) {
					throw new SesarError(403, 'Your SESAR account cannot ask for ' . $reg->igsn . ' to be deactivated: it belongs to another SESAR account.');
				}
				$this->markRequested($reg, null, null);
				return array('ok' => true, 'igsn' => $reg->igsn, 'state' => 'requested',
					'message' => 'A deactivation request for ' . $reg->igsn . ' was already waiting at SESAR.');
			}
			$client = $this->client;
			$igsn = (string)$reg->igsn;
			try {
				$this->conn->withAccess($userpkey, function ($access) use ($client, $igsn, $reason, $detail) {
					return $client->requestDeactivation($access, $igsn, $reason, $detail === '' ? null : $detail);
				});
			} catch (SesarError $e) {
				if ($e->kind === 'validation' && stripos($e->getMessage(), 'already exists') !== false) {
					$this->markRequested($reg, $reason, $detail);
					return array('ok' => true, 'igsn' => $igsn, 'state' => 'requested',
						'message' => 'A deactivation request for ' . $igsn . ' was already waiting at SESAR.');
				}
				if ($e->kind === 'network' || $e->kind === 'server') {
					throw new SesarError($e->status, 'SESAR did not confirm the request. Use Check with SESAR, or ask again: a repeat request is recognised, not doubled.', $e->errors);
				}
				throw $e;
			}
			$this->markRequested($reg, $reason, $detail);
			return array('ok' => true, 'igsn' => $igsn, 'state' => 'requested',
				'message' => 'Deactivation of ' . $igsn . ' was requested. A SESAR curator reviews it; SESAR emails you when it is decided.');
		} finally {
			SesarDb::unlock($this->db, $userpkey, $lockKey);
		}
	}

	/**
	 * "Check with SESAR now" for a pending request (signed-in read).
	 * @return array {state: requested|deactivated|declined, igsn, message}
	 */
	public function check($userpkey, array $target)
	{
		$userpkey = (int)$userpkey;
		$reg = $this->target($userpkey, $target);
		if ($reg->state !== 'deactivation_requested') throw new SesarError(409, 'There is no deactivation request waiting for ' . $reg->igsn . '.');
		$det = $this->readDetail($userpkey, $reg);
		if ($det === 'gone') {
			return array('state' => 'deactivated', 'igsn' => $reg->igsn, 'message' => 'SESAR has deactivated ' . $reg->igsn . '.');
		}
		if ($det['can_deactivate']) {
			// No request pending any more and the record is live: the curator declined.
			$this->db->prepare_query(
				"UPDATE strabosamples.sesar_registrations
				    SET state = 'active', deactivation_declined_at = now(), updated_at = now()
				  WHERE pkey = $1 AND state = 'deactivation_requested'",
				array((int)$reg->pkey)
			);
			return array('state' => 'declined', 'igsn' => $reg->igsn,
				'message' => 'SESAR declined the deactivation request for ' . $reg->igsn . ' (SESAR\'s email to you gives the curator\'s reason). The IGSN stays active.');
		}
		return array('state' => 'requested', 'igsn' => $reg->igsn, 'message' => 'Still waiting for a SESAR curator.');
	}

	// =======================================================================
	// Nightly sweep + the one "SESAR says 410" path
	// =======================================================================

	/**
	 * Anonymous 410 lookups for every pending request in this environment.
	 * @param int[]|null $onlyUsers limit to these owners (test suites, so
	 *                   real pending rows on a dev database never interfere)
	 * @return array {checked, deactivated, errors}
	 */
	public function sweep(array $onlyUsers = null)
	{
		$params = array($this->env);
		$only = '';
		if ($onlyUsers !== null) {
			$params[] = '{' . implode(',', array_map('intval', $onlyUsers)) . '}';
			$only = ' AND sample_userpkey = ANY($2::int[])';
		}
		$rows = $this->db->get_results_prepared(
			"SELECT pkey, sample_userpkey, igsn FROM strabosamples.sesar_registrations
			  WHERE environment = $1 AND active AND state = 'deactivation_requested' AND igsn IS NOT NULL" . $only . "
			  ORDER BY pkey",
			$params
		);
		$rows = is_array($rows) ? $rows : array();
		$out = array('checked' => count($rows), 'deactivated' => 0, 'errors' => 0);
		foreach (array_chunk($rows, 8) as $chunk) {
			$looked = $this->client->lookupIgsns(array_map(function ($r) { return (string)$r->igsn; }, $chunk));
			foreach ($chunk as $r) {
				$st = isset($looked[(string)$r->igsn]) ? $looked[(string)$r->igsn]['status'] : 'error';
				if ($st === 'gone') {
					$this->markGone((int)$r->sample_userpkey, (string)$r->igsn);
					$out['deactivated']++;
				} elseif ($st === 'error') {
					$out['errors']++;
				}
			}
		}
		return $out;
	}

	/**
	 * SESAR answered 410 for $igsn: record it on the user's live row (if
	 * any). Our own request -> the IGSN is removed from the sample (D7);
	 * otherwise the IGSN text stays and the page shows "Deactivated at SESAR".
	 * @return string|null 'requested' | 'unrequested' | null (nothing tracked)
	 */
	public function markGone($userpkey, $igsn)
	{
		$reg = $this->db->get_row_prepared(
			"SELECT pkey, sample_id, sample_userpkey, igsn, state FROM strabosamples.sesar_registrations
			  WHERE sample_userpkey = $1 AND environment = $2 AND active AND upper(igsn) = upper($3) LIMIT 1",
			array((int)$userpkey, $this->env, (string)$igsn)
		);
		if ($reg === null) return null;
		$requested = $reg->state === 'deactivation_requested';
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_registrations
			    SET state = 'deactivated', active = FALSE, deactivated_at = now(), updated_at = now()
			  WHERE pkey = $1 AND active",
			array((int)$reg->pkey)
		);
		if ($requested && $this->clearIgsn !== null) {
			$s = $this->db->get_row_prepared("SELECT igsn FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
				array((string)$reg->sample_id, (int)$reg->sample_userpkey));
			$cls = $s !== null ? SesarMapper::classifyIgsn($s->igsn) : array('normalized' => null);
			// Only the IGSN we tracked; any other text in the field is the user's.
			if ($cls['normalized'] !== null && strtoupper($cls['normalized']) === strtoupper((string)$reg->igsn)) {
				call_user_func($this->clearIgsn, (int)$reg->sample_userpkey, (string)$reg->sample_id);
			}
		}
		return $requested ? 'requested' : 'unrequested';
	}

	// =======================================================================
	// Orphans: tracked IGSNs whose sample was deleted (Q2)
	// =======================================================================

	/** @return array[] {reg, igsn, landing_url, state, requested_at, sample_id, origin, releasable} */
	public function orphans($userpkey)
	{
		$rows = $this->db->get_results_prepared(
			"SELECT r.pkey, r.igsn, r.state, r.origin, r.sample_id, r.deactivation_requested_at, r.deactivation_reason, r.snapshot->>'name' AS name
			   FROM strabosamples.sesar_registrations r
			  WHERE r.sample_userpkey = $1 AND r.environment = $2 AND r.active AND r.igsn IS NOT NULL
			    AND r.state IN ('active', 'deactivation_requested') AND r.orphan_kept_at IS NULL
			    AND NOT EXISTS (SELECT 1 FROM strabosamples.samples s WHERE s.id = r.sample_id AND s.userpkey = r.sample_userpkey)
			  ORDER BY r.pkey",
			array((int)$userpkey, $this->env)
		);
		$out = array();
		foreach ((is_array($rows) ? $rows : array()) as $r) {
			$out[] = array(
				'reg'          => (int)$r->pkey,
				'igsn'         => (string)$r->igsn,
				'name'         => $r->name !== null ? (string)$r->name : null,
				'landing_url'  => SesarAccess::landingUrl((string)$r->igsn, $this->env),
				'state'        => (string)$r->state,
				'origin'       => (string)$r->origin,
				'sample_id'    => (string)$r->sample_id,
				'requested_at' => $r->deactivation_requested_at !== null ? date('c', strtotime($r->deactivation_requested_at)) : null,
				// Recorded without a reason (not sent from here): the owner may take it back.
				'releasable'   => $r->state === 'deactivation_requested' && $r->deactivation_reason === null,
			);
		}
		return $out;
	}

	/** "Keep": the specimen still exists; stop listing this IGSN as an orphan. */
	public function keepOrphan($userpkey, $regPkey)
	{
		$reg = $this->target((int)$userpkey, array('reg' => $regPkey));
		$this->db->prepare_query("UPDATE strabosamples.sesar_registrations SET orphan_kept_at = now(), updated_at = now() WHERE pkey = $1",
			array((int)$reg->pkey));
		return array('ok' => true, 'igsn' => $reg->igsn);
	}

	// =======================================================================
	// Internals
	// =======================================================================

	/** The user's live row for a sample, or an orphan row by pkey. */
	private function target($userpkey, array $t)
	{
		if (isset($t['reg']) && (string)$t['reg'] !== '') {
			$reg = $this->db->get_row_prepared(
				"SELECT r.pkey, r.sample_id, r.igsn, r.state, r.access, r.origin, r.deactivation_reason, r.snapshot->>'name' AS name,
				        EXISTS (SELECT 1 FROM strabosamples.samples s WHERE s.id = r.sample_id AND s.userpkey = r.sample_userpkey) AS has_sample
				   FROM strabosamples.sesar_registrations r
				  WHERE r.pkey = $1 AND r.sample_userpkey = $2 AND r.environment = $3 AND r.active",
				array((int)$t['reg'], $userpkey, $this->env)
			);
			if ($reg === null || $reg->has_sample === 't') throw new SesarError(404, 'That IGSN is not in your list of IGSNs whose sample was deleted.');
			$reg->orphan = true;
			return $reg;
		}
		$sampleId = isset($t['sample_id']) ? (string)$t['sample_id'] : '';
		$s = $this->db->get_row_prepared("SELECT name FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($sampleId, $userpkey));
		if ($s === null) throw new SesarError(404, 'This is not one of your samples.');
		$reg = $this->db->get_row_prepared(
			"SELECT pkey, sample_id, igsn, state, access, origin, deactivation_reason FROM strabosamples.sesar_registrations
			  WHERE sample_id = $1 AND sample_userpkey = $2 AND environment = $3 AND active",
			array($sampleId, $userpkey, $this->env)
		);
		if ($reg === null) throw new SesarError(404, 'This sample is not registered or linked at SESAR.');
		$reg->name = $s->name;
		$reg->orphan = false;
		return $reg;
	}

	private function assertAskable($reg)
	{
		if ($reg->state === 'minting') throw new SesarError(409, 'An IGSN registration for this sample has not finished. Open Register IGSN to complete it first.');
		if ($reg->state === 'deactivation_requested') throw new SesarError(409, 'Deactivation of ' . $reg->igsn . ' was already requested; a SESAR curator is reviewing it.');
		if ($reg->access !== 'managed') {
			throw new SesarError(409, $reg->igsn . ' is linked read-only: it belongs to another SESAR account, so StraboSpot cannot ask for it to be deactivated.');
		}
	}

	/** Signed-in detail: 'gone' (recorded via markGone) or {can_deactivate, can_edit}. */
	private function readDetail($userpkey, $reg)
	{
		$client = $this->client;
		$igsn = (string)$reg->igsn;
		try {
			$rec = $this->conn->withAccess($userpkey, function ($access) use ($client, $igsn) { return $client->getSample($access, $igsn); });
		} catch (SesarError $e) {
			if ($e->kind === 'gone') {
				$this->markGone($userpkey, $igsn);
				return 'gone';
			}
			if ($e->kind === 'not_found') throw new SesarError(404, 'SESAR has no sample with the IGSN ' . $igsn . '.');
			throw $e;
		}
		return array('can_deactivate' => self::truthy(isset($rec['can_deactivate']) ? $rec['can_deactivate'] : null),
		             'can_edit' => self::truthy(isset($rec['can_edit']) ? $rec['can_edit'] : null));
	}

	/** $reason null = not sent from here (a request that seems to be pending at SESAR; release() can take it back). */
	private function markRequested($reg, $reason, $detail)
	{
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_registrations
			    SET state = 'deactivation_requested', deactivation_reason = $1, deactivation_detail = $2,
			        deactivation_requested_at = now(), deactivation_declined_at = NULL, updated_at = now()
			  WHERE pkey = $3 AND active AND state = 'active'",
			array($reason, ($detail === null || $detail === '') ? null : $detail, (int)$reg->pkey)
		);
	}

	private function base($reg)
	{
		return array(
			'igsn'        => (string)$reg->igsn,
			'landing_url' => SesarAccess::landingUrl((string)$reg->igsn, $this->env),
			'environment' => $this->env,
			'name'        => isset($reg->name) && $reg->name !== null ? (string)$reg->name : (string)$reg->igsn,
			'orphan'      => !empty($reg->orphan),
			'reasons'     => self::REASONS,
			'message'     => null,
		);
	}

	/** "IEJMA0007", "10.58052/iejma0007" and the DOI URL all match 10.58052/IEJMA0007. */
	private static function sameIgsn($typed, $igsn)
	{
		$c = SesarMapper::classifyIgsn(trim((string)$typed));
		return $c['normalized'] !== null && strtoupper($c['normalized']) === strtoupper((string)$igsn);
	}

	private static function truthy($v) { return $v === true || $v === 'true' || $v === 1 || $v === '1'; }
}
