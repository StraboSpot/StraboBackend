<?php
/**
 * File: includes/sesar/SesarPush.php
 * Description: Send StraboSamples changes to SESAR (Phase 6; decision D6 in
 *              docs/StraboSamples_IGSN_Feature_Request/IGSN_Design_Decisions.md,
 *              revised by the Phase 6 design review P1-P4, 2026-09-27).
 *
 *              status()   no SESAR call: would a push change anything? The
 *                         sample's owned fields (SesarMapper::ownedFields)
 *                         against the SNAPSHOT, the last SESAR record seen
 *                         (P1). Drives the "Changed since last sent to
 *                         SESAR" badge and the IGSN page column.
 *              preview()  reads SESAR now: "SESAR now" vs "we will send",
 *                         differing fields only. A field we would send whose
 *                         SESAR value no longer matches our snapshot was
 *                         changed at SESAR: pull first (P2, content check).
 *              apply()    PATCH of those fields only. Review mode sends the
 *                         values the user saw, and anything that moved since
 *                         refuses the push; bulk mode skips pull-first rows.
 *                         A minted IGSN without its link back gets one
 *                         (link-samples, never PATCH related_resources, which
 *                         replaces a record's links).
 *
 *              Rules: manual only; owner + managed links only; never
 *              collectors or other lists; never clears a SESAR value we do
 *              not hold; never external_sample_id on a linked (pulled) row,
 *              which is the lab's own id (P3).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarAccess.php';
require_once __DIR__ . '/SesarClient.php';
require_once __DIR__ . '/SesarConnection.php';
require_once __DIR__ . '/SesarMapper.php';
require_once __DIR__ . '/SesarSampleView.php';
require_once __DIR__ . '/SesarMint.php';
require_once __DIR__ . '/SesarPull.php';

class SesarPush
{
	/** Samples per bulk run (as mint and pull, D4). */
	const CAP = 100;

	/** Plain labels, in PUSH_FIELDS order. */
	const LABELS = array(
		'name'                    => 'Name',
		'latitude'                => 'Latitude',
		'longitude'               => 'Longitude',
		'latitude_end'            => 'End latitude',
		'longitude_end'           => 'End longitude',
		'sample_description'      => 'Description',
		'purpose'                 => 'Purpose',
		'sampling_start_date'     => 'Collection date',
		'sampling_date_precision' => 'Date precision',
		'parent_sample'           => 'Parent IGSN',
		'external_sample_id'      => 'External sample id',
	);

	/** List-only keys a PATCH answer lacks; kept from the record read before it. */
	const LIST_ONLY = array('sample_id', 'external_sample_id', 'last_update_date');

	private $db;
	private $client;
	private $conn;
	private $views;
	private $mint;
	private $pull;
	private $env;

	public function __construct($db, SesarClient $client, SesarConnection $conn, SesarSampleView $views, SesarMint $mint, SesarPull $pull)
	{
		$this->db = $db;
		$this->client = $client;
		$this->conn = $conn;
		$this->views = $views;
		$this->mint = $mint;
		$this->pull = $pull;
		$this->env = $client->environment();
	}

	// =======================================================================
	// Status (no SESAR call)
	// =======================================================================

	/**
	 * @return array {pushable, igsn, changed, fields[] (labels), link_back}
	 *   pushable false (with reason) when this sample has nothing to push to.
	 */
	public function status($userpkey, $sampleId)
	{
		$userpkey = (int)$userpkey;
		$v = $this->views->build((string)$sampleId, $userpkey);
		if ($v === null) throw new SesarError(404, 'This is not one of your samples.');
		$reg = $this->registration((string)$sampleId, $userpkey);
		return $this->statusFor($v, $reg);
	}

	/**
	 * Status from a view + registration row already in hand (the IGSN page
	 * builds both for every row). $reg may be null.
	 */
	public function statusFor(array $v, $reg)
	{
		$why = self::notPushable($reg);
		if ($why !== null) return array('pushable' => false, 'reason' => $why, 'igsn' => $reg ? $reg->igsn : null, 'changed' => false, 'fields' => array(), 'link_back' => false);
		$snap = json_decode((string)$reg->snapshot, true);
		$ours = $this->ours($v, $reg, false);
		$patch = SesarMapper::pushPatch($ours, is_array($snap) ? $snap : array());
		$linkBack = $this->needsLinkBack($reg);
		return array(
			'pushable'  => true,
			'igsn'      => (string)$reg->igsn,
			'changed'   => !empty($patch),
			'fields'    => array_values(array_map(function ($f) { return self::LABELS[$f]; }, array_keys($patch))),
			'link_back' => $linkBack,
		);
	}

	// =======================================================================
	// Preview + apply
	// =======================================================================

	/** @return array the single-push review (see describe()) */
	public function preview($userpkey, $sampleId)
	{
		return $this->describe($this->inspect((int)$userpkey, (string)$sampleId));
	}

	/**
	 * @param array $in mode: 'review' (seen{field: value we showed as "will
	 *                  send"} must match what would be sent now) | 'bulk'
	 * @return array {ok, igsn, landing_url, environment, sent[], link_back_added, notes[]}
	 */
	public function apply($userpkey, $sampleId, array $in)
	{
		$userpkey = (int)$userpkey;
		$sampleId = (string)$sampleId;
		$mode = (isset($in['mode']) && $in['mode'] === 'bulk') ? 'bulk' : 'review';
		$seen = isset($in['seen']) && is_array($in['seen']) ? $in['seen'] : array();

		if (!$this->lock($userpkey, $sampleId)) {
			throw new SesarError(409, 'This sample is busy with another SESAR action. Please wait a moment and try again.', array('busy' => array('busy')));
		}
		try {
			$x = $this->inspect($userpkey, $sampleId);
			if (!empty($x['conflicts'])) {
				throw new SesarError(409, 'Changed at SESAR since StraboSpot last read it (' . implode(', ', array_map(function ($f) { return self::LABELS[$f]; }, $x['conflicts']))
					. '). Use Pull from SESAR first, so those edits are not overwritten.', array('pull_first' => $x['conflicts']));
			}
			if ($mode === 'review') {
				$shown = array();
				foreach ($seen as $f => $val) $shown[(string)$f] = $val;
				$now = array();
				foreach ($x['patch'] as $f => $val) $now[$f] = self::display($f, $val);
				ksort($shown);
				ksort($now);
				if (json_encode($shown) !== json_encode($now)) {
					throw new SesarError(409, 'The values changed since this review was opened. Close it and open Send to SESAR again to see what would be sent now.',
						array('stale' => array('review')));
				}
			}

			$notes = array();
			$rec = $x['record'];
			$client = $this->client;
			$igsn = (string)$x['reg']->igsn;
			if (!empty($x['patch'])) {
				$patch = $x['patch'];
				try {
					$resp = $this->conn->withAccess($userpkey, function ($access) use ($client, $igsn, $patch) {
						return $client->updateSample($access, $igsn, $patch);
					});
				} catch (SesarError $e) {
					if ($e->kind === 'network' || $e->kind === 'server') {
						throw new SesarError($e->status, 'SESAR did not confirm the change. Sending again is safe: it sets the same values.', $e->errors);
					}
					throw $e;
				}
				if (is_array($resp) && !empty($resp['igsn'])) {
					foreach (self::LIST_ONLY as $k) {
						if (!array_key_exists($k, $resp) && array_key_exists($k, $rec)) $resp[$k] = $rec[$k];
					}
					$rec = $resp;
				} else {
					$rec = array_merge($rec, $patch);   // answer without a record: what we know it holds now
				}
			}

			$flags = SesarPull::fieldFlags($x['view'], $rec);
			$rrId = null;
			if ($x['link_back']) {
				$rrId = $this->addLinkBack($userpkey, $sampleId, $x['reg'], $rec, $notes);
			}

			$this->db->prepare_query(
				"UPDATE strabosamples.sesar_registrations
				    SET snapshot = $1::jsonb, snapshot_at = now(), sesar_status = COALESCE($2, sesar_status),
				        pushed_fingerprint = $3, pushed_at = CASE WHEN $4 THEN now() ELSE pushed_at END,
				        related_resource_id = COALESCE($5, related_resource_id),
				        sesar_sample_id = COALESCE(sesar_sample_id, $6),
				        field_flags = $7::jsonb, updated_at = now()
				  WHERE pkey = $8",
				array(json_encode($rec), isset($rec['metadata_store_status']) ? $rec['metadata_store_status'] : null,
				      SesarMapper::fingerprint($x['ours']), empty($x['patch']) ? 'f' : 't', $rrId,
				      (isset($rec['sample_id']) && is_numeric($rec['sample_id'])) ? (int)$rec['sample_id'] : null,
				      $flags === null ? null : json_encode(array_values($flags)),
				      (int)$x['reg']->pkey)
			);

			return array(
				'ok'              => true,
				'igsn'            => $igsn,
				'landing_url'     => SesarAccess::landingUrl($igsn, $this->env),
				'environment'     => $this->env,
				'sent'            => array_values(array_map(function ($f) { return self::LABELS[$f]; }, array_keys($x['patch']))),
				'link_back_added' => $rrId !== null,
				'notes'           => $notes,
			);
		} finally {
			$this->unlock($userpkey, $sampleId);
		}
	}

	// =======================================================================
	// Internals
	// =======================================================================

	/**
	 * Everything a push needs, read fresh from SESAR.
	 * @return array {view, reg, record, ours, patch, conflicts[], link_back}
	 */
	private function inspect($userpkey, $sampleId)
	{
		$v = $this->views->build($sampleId, $userpkey);
		if ($v === null) throw new SesarError(404, 'This is not one of your samples.');
		$reg = $this->registration($sampleId, $userpkey);
		$why = self::notPushable($reg);
		if ($why !== null) throw new SesarError(409, $why);

		$f = $this->pull->fetch($userpkey, (string)$reg->igsn);
		if (!$f['can_edit']) {
			throw new SesarError(403, 'Your SESAR account cannot edit ' . $reg->igsn . ', so changes cannot be sent to it.');
		}
		$rec = $f['record'];
		$snap = json_decode((string)$reg->snapshot, true);
		$snap = is_array($snap) ? $snap : array();
		$ours = $this->ours($v, $reg, true);
		$patch = SesarMapper::pushPatch($ours, $rec);

		// P2: a field we would send whose SESAR value moved since our snapshot
		// was edited at SESAR. A key the snapshot never held is not evidence.
		$conflicts = array();
		foreach (array_keys($patch) as $field) {
			if (!array_key_exists($field, $snap)) continue;
			if (!SesarMapper::sameValue($field, $snap[$field], isset($rec[$field]) ? $rec[$field] : null)) {
				$conflicts[] = $field;
			}
		}

		return array(
			'view'      => $v,
			'reg'       => $reg,
			'record'    => $rec,
			'ours'      => $ours,
			'patch'     => $patch,
			'conflicts' => $conflicts,
			'link_back' => $this->needsLinkBack($reg),
		);
	}

	private function describe(array $x)
	{
		$rows = array();
		foreach ($x['patch'] as $f => $val) {
			$rows[] = array(
				'field'    => $f,
				'label'    => self::LABELS[$f],
				'sesar'    => self::display($f, isset($x['record'][$f]) ? $x['record'][$f] : null),
				'send'     => self::display($f, $val),
				'conflict' => in_array($f, $x['conflicts'], true),
			);
		}
		$igsn = (string)$x['reg']->igsn;
		return array(
			'sample_id'   => $x['view']['id'],
			'name'        => (string)$x['view']['name'],
			'igsn'        => $igsn,
			'landing_url' => SesarAccess::landingUrl($igsn, $this->env),
			'environment' => $this->env,
			'origin'      => (string)$x['reg']->origin,
			'rows'        => $rows,
			'blocked'     => !empty($x['conflicts']),
			'link_back'   => $x['link_back'],
			'record'      => SesarPull::summary($x['record']),
		);
	}

	/** Owned fields as this row may send them (P3: no external_sample_id on linked rows). */
	private function ours(array $v, $reg, $lookup)
	{
		$v['parent_igsn'] = $this->mint->parentIgsnFor($v, $lookup);
		$ours = SesarMapper::ownedFields($v);
		if ($reg->origin === 'linked') unset($ours['external_sample_id']);
		return $ours;
	}

	private function needsLinkBack($reg)
	{
		return SesarMint::LINK_BACK && $reg->origin === 'minted' && $reg->related_resource_id === null;
	}

	/** Best effort: a failure is a note, never a failed push. @return int|null resource id */
	private function addLinkBack($userpkey, $sampleId, $reg, array $rec, array &$notes)
	{
		$sesarId = $reg->sesar_sample_id !== null ? (int)$reg->sesar_sample_id
			: ((isset($rec['sample_id']) && is_numeric($rec['sample_id'])) ? (int)$rec['sample_id'] : null);
		if ($sesarId === null) {
			$notes[] = 'The link back to StraboSpot could not be added yet (SESAR\'s sample number is unknown); a later send will try again.';
			return null;
		}
		try {
			$rrId = $this->mint->linkBackResource($userpkey, $sampleId);
			$client = $this->client;
			$this->conn->withAccess($userpkey, function ($access) use ($client, $rrId, $sesarId) {
				return $client->linkSamplesToResource($access, $rrId, array($sesarId));
			});
			return $rrId;
		} catch (SesarError $e) {
			$notes[] = 'The link back to StraboSpot could not be added at SESAR (' . $e->getMessage() . '); a later send will try again.';
			return null;
		}
	}

	/** Why this registration cannot be pushed to, or null. */
	private static function notPushable($reg)
	{
		if ($reg === null) return 'This sample is not registered or linked at SESAR. Use Register IGSN or Pull from SESAR first.';
		if ($reg->state === 'minting') return 'An IGSN registration for this sample has not finished. Open Register IGSN to complete it first.';
		if ($reg->state === 'deactivation_requested') return 'A deactivation request for ' . $reg->igsn . ' is waiting at SESAR, so changes are not sent to it.';
		if ($reg->access !== 'managed') return $reg->igsn . ' is linked read-only: it belongs to another SESAR account, so changes cannot be sent to it.';
		if ($reg->snapshot === null) return 'StraboSpot has not read ' . $reg->igsn . ' from SESAR yet. Use Pull from SESAR once first.';
		return null;
	}

	private function registration($sampleId, $userpkey)
	{
		return $this->db->get_row_prepared(
			"SELECT pkey, igsn, state, origin, access, snapshot::text AS snapshot, related_resource_id, sesar_sample_id
			   FROM strabosamples.sesar_registrations
			  WHERE sample_id = $1 AND sample_userpkey = $2 AND environment = $3 AND active",
			array((string)$sampleId, (int)$userpkey, $this->env)
		);
	}

	/** A value as the review shows it (and as the browser sends it back in seen{}). */
	private static function display($field, $v)
	{
		if ($v === null) return null;
		if (in_array($field, array('latitude', 'longitude', 'latitude_end', 'longitude_end'), true)) {
			return is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.') : (string)$v;
		}
		return trim((string)$v);
	}

	private function lock($userpkey, $key)
	{
		return $this->db->get_var_prepared("SELECT pg_try_advisory_lock($1, hashtext($2))",
			array(SesarMint::LOCK_NS, (int)$userpkey . ':' . $key)) === 't';
	}

	private function unlock($userpkey, $key)
	{
		$this->db->get_var_prepared("SELECT pg_advisory_unlock($1, hashtext($2))", array(SesarMint::LOCK_NS, (int)$userpkey . ':' . $key));
	}
}
