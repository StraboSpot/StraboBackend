<?php
/**
 * File: includes/sesar/SesarMint.php
 * Description: IGSN minting (Phase 4; decisions D2, D3, D4, D8 in
 *              docs/StraboSamples_IGSN_Feature_Request/IGSN_Design_Decisions.md).
 *
 *              plan()    what the mint review shows: every requested sample
 *                        plus its family (D8: descendants and ancestors
 *                        without IGSNs), parents first, each row READY,
 *                        REPLACE (its IGSN field holds something that is not
 *                        a live SESAR IGSN; unchecked by default, D3 revised)
 *                        or BLOCKED with plain reasons; per-row object type
 *                        and material suggestions; the form's choices.
 *              mintOne() registers ONE sample. The browser loops over the
 *                        checked rows (D4), but every rule is enforced here
 *                        again, never trusted from the browser: owner only,
 *                        choices valid, stored IGSN re-checked at SESAR,
 *                        parent_sample only from an active registration or a
 *                        SESAR-verified IGSN, one mint per sample at a time.
 *
 *              Duplicate guards (D3). SESAR does not enforce a unique
 *              external_sample_id, so we must:
 *              - a per-sample advisory lock for the whole mint;
 *              - a tracking row in state 'minting' BEFORE the POST; when
 *                SESAR does not answer, the row stays 'minting' and every
 *                later attempt first searches SESAR by external_sample_id
 *                and adopts what it finds instead of registering again.
 *
 *              The IGSN reaches the sample through
 *              StraboSamplesService::updateSample (Field writeback, search
 *              sync, changelog), so the old IGSN field text survives in the
 *              changelog when a REPLACE row is minted.
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
require_once __DIR__ . '/SesarVocab.php';
require_once __DIR__ . '/SesarSampleView.php';

class SesarMint
{
	/** Samples per run, family included (D4). */
	const CAP = 100;

	/** Family walk limits (a pathological tree must not hang the review). */
	const MAX_DEPTH = 25;
	const MAX_FAMILY = 500;

	/** Advisory lock namespace (first key of pg_try_advisory_lock). */
	const LOCK_NS = 7271;

	/** D2: the SESAR record links back to the sample's page (canonical host). */
	const PAGE_BASE = 'https://strabospot.org/samples/';
	const LINK_BACK = true;

	private $db;
	private $client;
	private $conn;
	private $vocab;
	private $views;
	private $spineWriter;
	private $env;

	/**
	 * @param callable $spineWriter fn($userpkey, $sampleId, $igsn) -> true | error string.
	 *        Production passes one built on StraboSamplesService::updateSample.
	 */
	public function __construct($db, SesarClient $client, SesarConnection $conn, SesarVocab $vocab, SesarSampleView $views, $spineWriter)
	{
		$this->db = $db;
		$this->client = $client;
		$this->conn = $conn;
		$this->vocab = $vocab;
		$this->views = $views;
		$this->spineWriter = $spineWriter;
		$this->env = $client->environment();
	}

	/** The production spine writer (StraboSamplesService handles writeback + search + changelog). */
	public static function serviceSpineWriter($db, $neodb)
	{
		return function ($userpkey, $sampleId, $igsn) use ($db, $neodb) {
			require_once __DIR__ . '/../../samplesdb/services/StraboSamplesService.php';
			$svc = new StraboSamplesService($db, $neodb);
			$svc->setUserpkey((int)$userpkey);
			$r = $svc->updateSample((string)$sampleId, (int)$userpkey, array('igsn' => $igsn));
			return !empty($r['ok']) ? true : (isset($r['error']) ? (string)$r['error'] : 'unknown');
		};
	}

	// =======================================================================
	// Plan (the review)
	// =======================================================================

	/**
	 * @param int      $userpkey the owner (owner-only minting, D3)
	 * @param string[] $ids      sample ids the user picked
	 */
	public function plan($userpkey, array $ids)
	{
		$userpkey = (int)$userpkey;
		$picked = array();
		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id !== '' && !in_array($id, $picked, true)) $picked[] = $id;
		}
		if (count($picked) > self::CAP) {
			throw new SesarError(400, 'Please choose at most ' . self::CAP . ' samples at a time.');
		}

		// ---- rows: picked samples, then their family (D8) ----------------
		$rows = array();    // id => row
		$views = array();   // id => sample view
		$missing = array();
		foreach ($picked as $id) {
			$v = $this->views->build($id, $userpkey);
			if ($v === null) { $missing[] = $id; continue; }
			$views[$id] = $v;
			$rows[$id] = array('id' => $id, 'role' => 'picked');
		}
		// Relatives are suggested only while they have no IGSN: the walk up
		// stops at the first ancestor that has one (its IGSN becomes the
		// parent link), descendants with one are skipped (their own children
		// still count).
		foreach (array_keys($views) as $id) {
			foreach (array_reverse($this->ancestorIds($userpkey, $id)) as $aid) {   // nearest first
				if (isset($rows[$aid])) break;
				$v = $this->views->build($aid, $userpkey);
				if ($v === null || $this->hasIgsn($v, $userpkey)) break;
				$views[$aid] = $v;
				$rows[$aid] = array('id' => $aid, 'role' => 'ancestor');
			}
			foreach ($this->descendantIds($userpkey, $id) as $did) {
				if (isset($rows[$did])) continue;
				$v = $this->views->build($did, $userpkey);
				if ($v === null || $this->hasIgsn($v, $userpkey)) continue;
				$views[$did] = $v;
				$rows[$did] = array('id' => $did, 'role' => 'descendant');
			}
		}

		// ---- what SESAR and our tracking table already know ---------------
		$regs = $this->activeRegistrations(array_keys($views), $userpkey);
		$toLookup = array();
		$cls = array();
		foreach ($views as $id => $v) {
			$cls[$id] = SesarMapper::classifyIgsn($v['igsn']);
			if (!isset($regs[$id]) && in_array($cls[$id]['kind'], array('sesar', 'doi'), true)) $toLookup[] = $cls[$id]['normalized'];
		}
		// Parents outside the run: an active registration, or a stored IGSN SESAR confirms.
		$parents = array();
		foreach ($views as $id => $v) {
			if ($v['parent_sample_id'] === null || (isset($views[$v['parent_sample_id']]) && (int)$v['parent_userpkey'] === $userpkey)) continue;
			$p = $this->parentFacts($v['parent_sample_id'], (int)$v['parent_userpkey']);
			$parents[$id] = $p;
			if ($p !== null && $p['reg_igsn'] === null && $p['cls']['kind'] !== 'empty' && $p['cls']['kind'] !== 'invalid') {
				$toLookup[] = $p['cls']['normalized'];
			}
		}
		$looked = $this->client->lookupIgsns($toLookup);

		// ---- vocab + suggestions -----------------------------------------
		$leaves = $this->vocab->labels(SesarVocab::OBJECT_TYPES);
		$registrable = $this->vocab->registrableMaterials();

		$out = array();
		foreach ($views as $id => $v) {
			$row = $rows[$id];
			$row['name'] = (string)$v['name'];
			$row['current_igsn'] = trim((string)$v['igsn']);
			$row['parent_id'] = ($v['parent_sample_id'] !== null && (int)$v['parent_userpkey'] === $userpkey) ? (string)$v['parent_sample_id'] : null;
			$row['depth'] = $this->depthInRun($id, $views, $userpkey);
			$row['object_type'] = SesarMapper::suggestObjectType($v, $leaves);
			$row['material'] = SesarMapper::suggestMaterial($v, $registrable);
			$row['location_note'] = isset($v['location_from']) && $v['location_from'] === 'line'
				? 'Location from the start and end of the Field line.' : null;
			$row['reasons'] = array();
			$row['notes'] = array();

			foreach (SesarMapper::mintBlockers($v) as $b) $row['reasons'][] = self::reasonText($b);
			$reg = isset($regs[$id]) ? $regs[$id] : null;
			$group = 'ready';
			if ($reg !== null && $reg->state !== 'minting') {
				$group = 'blocked';
				$row['reasons'][] = 'Already registered through StraboSpot as ' . $reg->igsn . '.';
				$row['registered_igsn'] = $reg->igsn;
			} elseif ($reg !== null) {
				$row['notes'][] = 'An earlier attempt got no answer from SESAR. SESAR is checked first, so it cannot be registered twice.';
			}
			if ($group !== 'blocked') {
				$verdict = $this->storedIgsnVerdict($cls[$id], $looked);
				if ($verdict['group'] === 'blocked') { $group = 'blocked'; $row['reasons'][] = $verdict['text']; }
				elseif ($verdict['group'] === 'replace') { $group = 'replace'; $row['notes'][] = $verdict['text']; }
			}
			if (!empty($row['reasons'])) $group = 'blocked';
			$row['group'] = $group;

			// Parent (D8): in this run, verified at SESAR, or none.
			$row['parent'] = null;
			if ($v['parent_sample_id'] !== null) {
				if ($row['parent_id'] !== null && isset($views[$row['parent_id']])) {
					$row['parent'] = array('name' => (string)$views[$row['parent_id']]['name'], 'in_run' => true, 'igsn' => null);
				} else {
					$p = isset($parents[$id]) ? $parents[$id] : null;
					$pigsn = $p === null ? null : $this->verifiedParentIgsn($p, $looked);
					$row['parent'] = array('name' => $p === null ? null : $p['name'], 'in_run' => false, 'igsn' => $pigsn);
				}
			}
			$out[] = $row;
		}

		// Parents first, level by level (D8); ties keep the picked order.
		$order = array_flip(array_keys($views));
		usort($out, function ($a, $b) use ($order) {
			if ($a['depth'] !== $b['depth']) return $a['depth'] - $b['depth'];
			return $order[$a['id']] - $order[$b['id']];
		});

		$summary = $this->conn->summary($userpkey);
		$codes = $summary['sesar_codes'];
		if (empty($codes) && !empty($summary['connected'])) {
			try { $codes = SesarConnection::codeList($this->conn->refreshCodes($userpkey)); } catch (SesarError $e) { /* form shows none */ }
		}
		return array(
			'environment' => $this->env,
			'cap'         => self::CAP,
			'rows'        => $out,
			'missing'     => $missing,
			'choices'     => array(
				'codes'        => $codes,
				'last_code'    => $summary['last_sesar_code'],
				'object_types' => $this->vocab->objectTypeGroups(),
				'materials'    => array_map(function ($m) { return $m['label']; }, $registrable),
				'collector'    => $this->defaultCollector($userpkey, $summary),
			),
		);
	}

	// =======================================================================
	// Mint one sample
	// =======================================================================

	/**
	 * @param array $in sesar_code, object_type (required); material, collector;
	 *                  replace_existing (bool): the user ticked a REPLACE row;
	 *                  expect_parent (bool): the parent was minted earlier in
	 *                  this run, so no parent IGSN = refuse (D8: never unlinked)
	 * @return array {ok:true, igsn, landing_url, adopted, notes[]}
	 * @throws SesarError  kind validation/not_found/... with a plain message
	 */
	public function mintOne($userpkey, $sampleId, array $in)
	{
		$userpkey = (int)$userpkey;
		$sampleId = (string)$sampleId;
		$v = $this->views->build($sampleId, $userpkey);
		if ($v === null) throw new SesarError(404, 'This is not one of your samples.');

		$choices = $this->validChoices($userpkey, $in);
		$blockers = SesarMapper::mintBlockers($v);
		if (!empty($blockers)) throw new SesarError(400, self::reasonText($blockers[0]));

		if (!$this->lock($userpkey, $sampleId)) {
			throw new SesarError(409, 'This sample is already being registered. Please wait a moment and try again.', array('busy' => array('busy')));
		}
		try {
			return $this->mintLocked($userpkey, $sampleId, $v, $choices, !empty($in['replace_existing']), !empty($in['expect_parent']));
		} finally {
			$this->unlock($userpkey, $sampleId);
		}
	}

	private function mintLocked($userpkey, $sampleId, array $v, array $choices, $replaceExisting, $expectParent)
	{
		$notes = array();
		$reg = $this->activeRegistration($sampleId, $userpkey);

		// Already registered: only repair a spine that missed the IGSN.
		if ($reg !== null && $reg->state !== 'minting') {
			if (trim((string)$v['igsn']) === '') {
				$this->writeSpine($userpkey, $sampleId, $reg->igsn, $notes);
				return $this->result($reg->igsn, false, array_merge(array('It was already registered; its IGSN field has been filled in.'), $notes));
			}
			throw new SesarError(409, 'Already registered through StraboSpot as ' . $reg->igsn . '.', array('registered' => array($reg->igsn)));
		}

		// The IGSN field must be empty, or hold something SESAR does not know (and the user said replace).
		$cls = SesarMapper::classifyIgsn($v['igsn']);
		if ($cls['kind'] !== 'empty') {
			$looked = in_array($cls['kind'], array('sesar', 'doi'), true) ? $this->client->lookupIgsns(array($cls['normalized'])) : array();
			$verdict = $this->storedIgsnVerdict($cls, $looked);
			if ($verdict['group'] === 'blocked') throw new SesarError(409, $verdict['text'], array('igsn' => array($verdict['text'])));
			if (!$replaceExisting) {
				throw new SesarError(409, 'Its IGSN field already holds "' . trim((string)$v['igsn']) . '". Tick it in the review to replace that value.',
					array('igsn' => array('replace_not_confirmed')));
			}
			$notes[] = 'Replaced the old IGSN field value "' . trim((string)$v['igsn']) . '" (kept in the sample history).';
		}

		// parent_sample: never from raw spine text (D3 rule).
		$parentIgsn = null;
		if ($v['parent_sample_id'] !== null) {
			$p = $this->parentFacts($v['parent_sample_id'], (int)$v['parent_userpkey']);
			if ($p !== null) {
				$looked = ($p['reg_igsn'] === null && in_array($p['cls']['kind'], array('sesar', 'doi'), true))
					? $this->client->lookupIgsns(array($p['cls']['normalized'])) : array();
				$parentIgsn = $this->verifiedParentIgsn($p, $looked);
			}
		}
		if ($expectParent && $parentIgsn === null) {
			throw new SesarError(409, 'Skipped: its parent was not registered, so it would lose its parent link.', array('parent' => array('parent_not_minted')));
		}
		$v['parent_igsn'] = $parentIgsn;

		// A previous attempt got no answer: SESAR may have it already.
		if ($reg !== null) {
			$found = $this->findOurRecord($userpkey, $sampleId, $choices['sesar_code']);
			if ($found !== null) {
				$this->finalize($userpkey, $reg->pkey, $v, $choices, $found, null, $notes);
				return $this->result($found['igsn'], true, array_merge(array('SESAR already had it from an earlier attempt; linked to that record.'), $notes));
			}
			$regPkey = (int)$reg->pkey;
		} else {
			$regPkey = $this->insertMintingRow($userpkey, $sampleId, $choices['sesar_code']);
		}

		// D2 link back to the sample page (best-effort, never blocks the mint).
		$rrId = null;
		if (self::LINK_BACK) {
			$url = self::PAGE_BASE . $userpkey . '/' . rawurlencode($sampleId);
			$label = 'StraboSpot sample page (' . $sampleId . ')';   // the id makes it findable: SESAR searches labels only
			$client = $this->client;
			try {
				$rrId = $this->conn->withAccess($userpkey, function ($access) use ($client, $url, $label, $sampleId) {
					try {
						return $client->createRelatedResource($access, $label, $url, 'This sample in StraboSamples (StraboSpot)');
					} catch (SesarError $e) {
						// One URI, one resource at SESAR: an earlier attempt (or an
						// earlier IGSN of this sample) already made it. Reuse it.
						if ($e->kind !== 'validation' || stripos($e->getMessage(), 'already exists') === false) throw $e;
						$id = $client->findRelatedResourceByUri($access, $url, (string)$sampleId);
						if ($id === null) throw $e;
						return $id;
					}
				});
			} catch (SesarError $e) {
				if (in_array($e->kind, array('auth', 'no_permission', 'no_account'), true)) {
					$this->deleteMintingRow($regPkey);
					throw $e;
				}
				$notes[] = 'The link back to StraboSpot could not be added at SESAR (' . $e->getMessage() . ').';
			}
		}

		$payload = SesarMapper::registrationPayload($v, $choices);
		if ($rrId !== null) $payload['related_resources'] = array($rrId);

		$client = $this->client;
		try {
			$rec = $this->conn->withAccess($userpkey, function ($access) use ($client, $payload) {
				return $client->registerSample($access, $payload);
			});
		} catch (SesarError $e) {
			if ($e->kind === 'network' || $e->kind === 'server') {
				// Outcome unknown: look before anyone registers it again.
				$found = null;
				try { $found = $this->findOurRecord($userpkey, $sampleId, $choices['sesar_code']); } catch (SesarError $e2) { /* stays minting */ }
				if ($found !== null) {
					$this->finalize($userpkey, $regPkey, $v, $choices, $found, $rrId, $notes);
					return $this->result($found['igsn'], true, $notes);
				}
				throw new SesarError($e->status, 'SESAR did not confirm the registration. Try again: SESAR is checked first, so it will not be registered twice.',
					array('outcome' => array('unknown')));
			}
			$this->deleteMintingRow($regPkey);
			if ($e->kind === 'validation' && stripos($e->getMessage(), 'ambiguous individual') !== false) {
				throw new SesarError(400, 'SESAR knows more than one person named "' . $choices['collector']
					. '", so it cannot tell who the collector is. Leave the collector as yourself, or clear it.', array('collector' => array('ambiguous')));
			}
			throw $e;
		}
		if (empty($rec['igsn'])) {
			throw new SesarError(502, 'SESAR answered without an IGSN. Try again: SESAR is checked first, so it will not be registered twice.',
				array('outcome' => array('unknown')));
		}
		$this->finalize($userpkey, $regPkey, $v, $choices, $rec, $rrId, $notes);
		return $this->result($rec['igsn'], false, $notes);
	}

	// =======================================================================
	// Helpers: registrations, locks, lookups
	// =======================================================================

	private function finalize($userpkey, $regPkey, array $v, array $choices, array $rec, $rrId, array &$notes)
	{
		$igsn = (string)$rec['igsn'];
		$sesarId = isset($rec['sample_id']) && is_numeric($rec['sample_id']) ? (int)$rec['sample_id'] : null;
		if ($sesarId === null) {
			// The POST / detail answers lack sample_id; the list has it (Phase 1).
			try {
				$f = $this->findOurRecord($userpkey, $v['id'], $choices['sesar_code'], $igsn);
				if ($f !== null && isset($f['sample_id']) && is_numeric($f['sample_id'])) $sesarId = (int)$f['sample_id'];
			} catch (SesarError $e) { /* filled by a later pull */ }
		}
		$owned = SesarMapper::ownedFields($v);
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_registrations
			    SET igsn = $1, sesar_code = $2, state = 'active', access = 'managed', active = TRUE,
			        sesar_sample_id = $3, related_resource_id = COALESCE($4, related_resource_id),
			        sesar_status = $5, snapshot = $6::jsonb, snapshot_at = now(), sesar_last_update = $7,
			        pushed_fingerprint = $8, pushed_at = now(), updated_at = now()
			  WHERE pkey = $9",
			array($igsn, $choices['sesar_code'], $sesarId, $rrId,
			      isset($rec['metadata_store_status']) ? $rec['metadata_store_status'] : null,
			      json_encode($rec),
			      !empty($rec['last_update_date']) ? $rec['last_update_date'] : null,
			      SesarMapper::fingerprint($owned), (int)$regPkey)
		);
		$this->conn->setLastCode($userpkey, $choices['sesar_code']);
		$this->writeSpine($userpkey, $v['id'], $igsn, $notes);
	}

	private function writeSpine($userpkey, $sampleId, $igsn, array &$notes)
	{
		$r = call_user_func($this->spineWriter, $userpkey, $sampleId, $igsn);
		if ($r !== true) {
			$notes[] = 'Registered at SESAR, but the IGSN could not be saved to the sample (' . $r . '). Run the registration again to fill it in.';
		}
	}

	private function result($igsn, $adopted, array $notes)
	{
		return array(
			'ok'          => true,
			'igsn'        => $igsn,
			'landing_url' => SesarAccess::landingUrl($igsn, $this->env),
			'adopted'     => (bool)$adopted,
			'environment' => $this->env,
			'notes'       => $notes,
		);
	}

	/** The newest of OUR records at SESAR for this sample (external_sample_id = our id), or null. */
	private function findOurRecord($userpkey, $sampleId, $code, $igsn = null)
	{
		$client = $this->client;
		$list = $this->conn->withAccess($userpkey, function ($access) use ($client, $sampleId, $code) {
			return $client->findByExternalId($access, (string)$sampleId, $code);
		});
		$best = null;
		foreach ($list as $r) {
			if (!is_array($r) || empty($r['igsn']) || (string)(isset($r['external_sample_id']) ? $r['external_sample_id'] : '') !== (string)$sampleId) continue;
			if ($igsn !== null && $r['igsn'] !== $igsn) continue;
			if ($best === null || strcmp((string)(isset($r['publish_date']) ? $r['publish_date'] : ''), (string)(isset($best['publish_date']) ? $best['publish_date'] : '')) > 0) $best = $r;
		}
		return $best;
	}

	/** Anything in its IGSN field, or a live registration: not a family suggestion. */
	private function hasIgsn(array $v, $userpkey)
	{
		if (trim((string)$v['igsn']) !== '') return true;
		$r = $this->activeRegistration($v['id'], $userpkey);
		return $r !== null && $r->state !== 'minting';
	}

	private function insertMintingRow($userpkey, $sampleId, $code)
	{
		$this->db->prepare_query(
			"INSERT INTO strabosamples.sesar_registrations
			        (sample_id, sample_userpkey, environment, igsn, sesar_code, origin, access, state, active, created_by)
			 VALUES ($1, $2, $3, NULL, $4, 'minted', 'managed', 'minting', TRUE, $2)
			 ON CONFLICT (sample_id, sample_userpkey, environment) WHERE active DO NOTHING",
			array((string)$sampleId, (int)$userpkey, $this->env, (string)$code)
		);
		$row = $this->activeRegistration($sampleId, $userpkey);
		if ($row === null || $row->state !== 'minting') {
			throw new SesarError(409, 'This sample is already being registered. Please wait a moment and try again.', array('busy' => array('busy')));
		}
		return (int)$row->pkey;
	}

	private function deleteMintingRow($pkey)
	{
		$this->db->prepare_query(
			"DELETE FROM strabosamples.sesar_registrations WHERE pkey = $1 AND state = 'minting'", array((int)$pkey));
	}

	private function activeRegistration($sampleId, $userpkey)
	{
		return $this->db->get_row_prepared(
			"SELECT pkey, igsn, state FROM strabosamples.sesar_registrations
			  WHERE sample_id = $1 AND sample_userpkey = $2 AND environment = $3 AND active",
			array((string)$sampleId, (int)$userpkey, $this->env)
		);
	}

	/** id => row for the user's active registrations among $ids. */
	private function activeRegistrations(array $ids, $userpkey)
	{
		if (empty($ids)) return array();
		$rows = $this->db->get_results_prepared(
			"SELECT sample_id, pkey, igsn, state FROM strabosamples.sesar_registrations
			  WHERE sample_userpkey = $1 AND environment = $2 AND active AND sample_id = ANY($3::text[])",
			array((int)$userpkey, $this->env, self::pgTextArray($ids))
		);
		$out = array();
		foreach ((is_array($rows) ? $rows : array()) as $r) $out[(string)$r->sample_id] = $r;
		return $out;
	}

	/** What we know about a parent outside the run (may belong to another user). */
	private function parentFacts($parentId, $parentOwner)
	{
		$r = $this->db->get_row_prepared(
			"SELECT s.name, s.igsn,
			        (SELECT igsn FROM strabosamples.sesar_registrations g
			          WHERE g.sample_id = s.id AND g.sample_userpkey = s.userpkey AND g.environment = $3
			            AND g.active AND g.state <> 'minting' LIMIT 1) AS reg_igsn
			   FROM strabosamples.samples s WHERE s.id = $1 AND s.userpkey = $2",
			array((string)$parentId, (int)$parentOwner, $this->env)
		);
		if ($r === null) return null;
		return array('name' => (string)$r->name, 'reg_igsn' => $r->reg_igsn, 'cls' => SesarMapper::classifyIgsn($r->igsn));
	}

	private function verifiedParentIgsn(array $p, array $looked)
	{
		if ($p['reg_igsn'] !== null) return (string)$p['reg_igsn'];
		$n = $p['cls']['normalized'];
		if ($n !== null && isset($looked[$n]) && in_array($looked[$n]['status'], array('found', 'private'), true)) {
			return (isset($looked[$n]['record']['igsn']) && is_string($looked[$n]['record']['igsn'])) ? $looked[$n]['record']['igsn'] : $n;
		}
		return null;
	}

	/**
	 * D3 revised: may a sample whose IGSN field is not empty be minted?
	 * @return array {group: ok|replace|blocked, text}
	 */
	private function storedIgsnVerdict(array $cls, array $looked)
	{
		if ($cls['kind'] === 'empty') return array('group' => 'ok', 'text' => null);
		if ($cls['kind'] === 'invalid') {
			return array('group' => 'replace', 'text' => 'Its IGSN field holds text that is not an IGSN. Tick it to register a real IGSN in its place.');
		}
		$n = $cls['normalized'];
		$st = isset($looked[$n]) ? $looked[$n]['status'] : 'error';
		if ($st === 'found' || $st === 'private') {
			return array('group' => 'blocked', 'text' => 'Its IGSN field holds ' . $n . ', which is registered at SESAR. (Linking to existing SESAR records comes in a later update.)');
		}
		if ($st === 'error') {
			return array('group' => 'blocked', 'text' => 'Could not check its current IGSN (' . $n . ') with SESAR just now. Please try again.');
		}
		if ($cls['kind'] === 'doi') {
			return array('group' => 'blocked', 'text' => 'Its IGSN field holds ' . $n . ', a DOI that SESAR does not know. It is probably an IGSN from another registry, so registering it again would make a duplicate.');
		}
		if ($st === 'gone') {
			return array('group' => 'replace', 'text' => 'Its IGSN field holds ' . $n . ', which was deactivated at SESAR. Tick it to register a new IGSN.');
		}
		return array('group' => 'replace', 'text' => 'Its IGSN field holds ' . $n . ', which SESAR does not know. Tick it to register a real IGSN in its place.');
	}

	private function validChoices($userpkey, array $in)
	{
		$code = strtoupper(trim((string)(isset($in['sesar_code']) ? $in['sesar_code'] : '')));
		$summary = $this->conn->summary($userpkey);
		if (empty($summary['connected'])) throw new SesarError(401, 'Connect your SESAR account first.');
		$codes = $summary['sesar_codes'];
		if (!in_array($code, $codes, true)) {
			try { $codes = SesarConnection::codeList($this->conn->refreshCodes($userpkey)); } catch (SesarError $e) { /* judged on the cached list */ }
		}
		if ($code === '' || !in_array($code, $codes, true)) {
			throw new SesarError(400, 'Choose one of your SESAR codes.', array('sesar_code' => array('invalid')));
		}
		$ot = trim((string)(isset($in['object_type']) ? $in['object_type'] : ''));
		$hit = null;
		foreach ($this->vocab->labels(SesarVocab::OBJECT_TYPES) as $l) {
			if (mb_strtolower($l) === mb_strtolower($ot)) { $hit = $l; break; }
		}
		if ($hit === null) throw new SesarError(400, 'Choose a SESAR object type.', array('object_type' => array('invalid')));
		$mat = trim((string)(isset($in['material']) ? $in['material'] : ''));
		$matLabel = null;
		if ($mat !== '') {
			$matLabel = SesarMapper::registrableMaterial($mat, $this->vocab->registrableMaterials());
			if ($matLabel === null) {
				throw new SesarError(400, '"' . $mat . '" is not a material SESAR accepts. Pick one from the list or leave it blank.', array('material' => array('invalid')));
			}
		}
		$collector = trim((string)(isset($in['collector']) ? $in['collector'] : ''));
		if (mb_strlen($collector) > 200) throw new SesarError(400, 'The collector name is too long.', array('collector' => array('too_long')));
		// The connected person as collector: send their SESAR individual (ORCID), not just the name.
		$me = self::sesarIndividual($summary);
		$ind = null;
		if ($collector !== '' && $me !== null && mb_strtolower($collector) === mb_strtolower((string)$me['label'])) {
			$ind = array_filter(array('label' => $me['label'], 'fname' => isset($me['fname']) ? $me['fname'] : null,
				'lname' => isset($me['lname']) ? $me['lname'] : null,
				'individual_uri' => isset($me['individual_uri']) ? $me['individual_uri'] : null), function ($x) { return $x !== null && $x !== ''; });
		}
		return array('sesar_code' => $code, 'object_type' => $hit, 'general_material_type' => $matLabel, 'collector' => $collector,
			'collector_individual' => $ind);
	}

	/** The connected account's SESAR individual {label, fname, lname, individual_uri, ...}, or null. */
	private static function sesarIndividual(array $summary)
	{
		$i = isset($summary['sesar_user']['user']['individual']) ? $summary['sesar_user']['user']['individual']
			: (isset($summary['sesar_user']['individual']) ? $summary['sesar_user']['individual'] : null);
		return (is_array($i) && isset($i['label']) && is_string($i['label']) && trim($i['label']) !== '') ? $i : null;
	}

	/** SESAR individual label ("Last, First") when known, else the StraboSpot account name. */
	private function defaultCollector($userpkey, array $summary)
	{
		$me = self::sesarIndividual($summary);
		if ($me !== null) return trim($me['label']);
		$r = $this->db->get_row_prepared("SELECT firstname, lastname FROM users WHERE pkey = $1", array((int)$userpkey));
		if ($r === null) return '';
		$last = trim((string)$r->lastname); $first = trim((string)$r->firstname);
		return ($last !== '' && $first !== '') ? $last . ', ' . $first : trim($first . ' ' . $last);
	}

	private function lock($userpkey, $sampleId)
	{
		return $this->db->get_var_prepared(
			"SELECT pg_try_advisory_lock($1, hashtext($2))", array(self::LOCK_NS, $userpkey . ':' . $sampleId)) === 't';
	}

	private function unlock($userpkey, $sampleId)
	{
		$this->db->get_var_prepared(
			"SELECT pg_advisory_unlock($1, hashtext($2))", array(self::LOCK_NS, $userpkey . ':' . $sampleId));
	}

	/** Same-owner ancestors, nearest first (a foreign parent ends the walk: never mint others' samples). */
	private function ancestorIds($userpkey, $id)
	{
		$out = array();
		$cur = $id;
		for ($i = 0; $i < self::MAX_DEPTH; $i++) {
			$r = $this->db->get_row_prepared(
				"SELECT parent_sample_id, parent_userpkey FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
				array((string)$cur, (int)$userpkey));
			if ($r === null || $r->parent_sample_id === null || (int)$r->parent_userpkey !== (int)$userpkey) break;
			if ($r->parent_sample_id === $id || in_array($r->parent_sample_id, $out, true)) break;   // cycle
			$out[] = (string)$r->parent_sample_id;
			$cur = $r->parent_sample_id;
		}
		return array_reverse($out);   // root first
	}

	/** Same-owner descendants, breadth first. */
	private function descendantIds($userpkey, $id)
	{
		$out = array();
		$frontier = array((string)$id);
		for ($d = 0; $d < self::MAX_DEPTH && !empty($frontier) && count($out) < self::MAX_FAMILY; $d++) {
			$rows = $this->db->get_results_prepared(
				"SELECT id FROM strabosamples.samples
				  WHERE userpkey = $1 AND parent_userpkey = $1 AND parent_sample_id = ANY($2::text[])
				  ORDER BY created_at, id",
				array((int)$userpkey, self::pgTextArray($frontier)));
			$next = array();
			foreach ((is_array($rows) ? $rows : array()) as $r) {
				$cid = (string)$r->id;
				if ($cid === (string)$id || in_array($cid, $out, true)) continue;
				$out[] = $cid;
				$next[] = $cid;
			}
			$frontier = $next;
		}
		return $out;
	}

	/** Number of same-owner ancestors that are also in the run (parents first, D8). */
	private function depthInRun($id, array $views, $userpkey)
	{
		$depth = 0;
		$cur = $views[$id];
		$seen = array($id => true);
		while ($cur['parent_sample_id'] !== null && (int)$cur['parent_userpkey'] === (int)$userpkey
			&& isset($views[$cur['parent_sample_id']]) && !isset($seen[$cur['parent_sample_id']])) {
			$seen[$cur['parent_sample_id']] = true;
			$cur = $views[$cur['parent_sample_id']];
			$depth++;
		}
		return $depth;
	}

	public static function reasonText($code)
	{
		switch ($code) {
			case 'no_name':      return 'It has no name. SESAR requires one.';
			case 'no_location':  return 'It has no location. SESAR requires a latitude and longitude.';
			case 'bad_location': return 'Its location is not a valid latitude and longitude.';
		}
		return (string)$code;
	}

	private static function pgTextArray(array $vals)
	{
		return '{' . implode(',', array_map(function ($v) {
			return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$v) . '"';
		}, $vals)) . '}';
	}
}
