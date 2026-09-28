<?php
/**
 * File: includes/sesar/SesarSandboxCleanup.php
 * Description: Launch cleanup of SESAR SANDBOX IGSNs before prod switches to
 *              production SESAR (IGSN Phase 9; design D3 + Phase 9 review
 *              Q1-Q3, IGSN_Design_Decisions.md).
 *
 *              clear     Samples whose IGSN field still holds an IGSN that a
 *                        sandbox registration row tracks for that sample
 *                        (compared normalized: the field may hold the bare
 *                        "IEJMA0003" form). Cleared through updateSample
 *                        (Field writeback, search, changelog). Never by
 *                        pattern: only what StraboSpot tracked (D3).
 *              untracked REPORT ONLY (Q2): SESAR-shaped values with no
 *                        registration row of any environment, on samples of
 *                        testers, that production SESAR does NOT know. The
 *                        sandbox holds copies of real records, so only "not
 *                        found at production" marks a test value.
 *              created   REPORT ONLY (Q3): samples made by "Create samples
 *                        from IGSNs" / Import from a sandbox record (row
 *                        origin 'linked', row written within a minute after
 *                        the sample), with no subsystem links and no children.
 *
 *              Registration rows, connections, onboarding and vocab rows
 *              are never touched (Q1), so the cleanup can run again: a later
 *              run clears a sandbox IGSN a Field app upload brought back.
 *              No SESAR call except the read-only production lookups.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/SesarAccess.php';
require_once __DIR__ . '/SesarClient.php';
require_once __DIR__ . '/SesarMapper.php';
require_once __DIR__ . '/SesarDb.php';

class SesarSandboxCleanup
{
	/** Seconds between a created sample and its 'linked' row (same request in SesarPull::createOne). */
	const CREATED_WINDOW = 60;

	private $db;
	/** @var SesarClient production client (Q2 lookups) */
	private $prod;
	/** @var callable fn($userpkey, $sampleId) -> true | error string */
	private $clearIgsn;

	public function __construct($db, SesarClient $productionClient, $clearIgsn)
	{
		if ($productionClient->environment() !== 'production') {
			throw new InvalidArgumentException('The untracked check must ask production SESAR.');
		}
		$this->db = $db;
		$this->prod = $productionClient;
		$this->clearIgsn = $clearIgsn;
	}

	/**
	 * Everything the cleanup would do, changing nothing.
	 * @return array {clear[], already_clear, changed[], sample_gone, untracked[], unchecked[], created[]}
	 *   clear / changed rows: {owner, sample_id, name, field, igsn}
	 *   untracked / unchecked: {owner, sample_id, name, field, igsn[, message]}
	 *   created: {owner, sample_id, name, created_at, igsn}
	 * @param int[]|null $onlyOwners limit every list to these sample owners (tests)
	 */
	public function plan(array $onlyOwners = null)
	{
		$only = $onlyOwners === null ? '' : '{' . implode(',', array_map('intval', $onlyOwners)) . '}';
		$out = array('clear' => array(), 'already_clear' => 0, 'changed' => array(), 'sample_gone' => 0,
			'untracked' => array(), 'unchecked' => array(), 'created' => array());

		// ---- clear: sandbox rows (every state) whose sample still holds them
		$rows = $this->db->get_results_prepared(
			"SELECT r.sample_id, r.sample_userpkey, r.igsn, s.id AS sid, s.name, s.igsn AS field,
			        EXISTS (SELECT 1 FROM strabosamples.sesar_registrations p
			                 WHERE p.sample_id = r.sample_id AND p.sample_userpkey = r.sample_userpkey
			                   AND p.environment = 'production' AND upper(p.igsn) = upper(r.igsn)) AS in_production
			   FROM strabosamples.sesar_registrations r
			   LEFT JOIN strabosamples.samples s ON s.id = r.sample_id AND s.userpkey = r.sample_userpkey
			  WHERE r.environment = 'sandbox' AND r.igsn IS NOT NULL
			    AND ($1 = '' OR r.sample_userpkey = ANY($1::int[]))
			  ORDER BY r.sample_userpkey, r.sample_id, r.pkey",
			array($only)
		);
		$seen = array();
		foreach ((is_array($rows) ? $rows : array()) as $r) {
			if ($r->sid === null) { $out['sample_gone']++; continue; }
			// A production row tracking the same IGSN makes it real: never clear.
			if ($r->in_production === 't' || $r->in_production === true) continue;
			$cls = SesarMapper::classifyIgsn($r->field);
			$item = array('owner' => (int)$r->sample_userpkey, 'sample_id' => (string)$r->sample_id,
				'name' => (string)$r->name, 'field' => (string)$r->field, 'igsn' => (string)$r->igsn);
			if ($cls['normalized'] !== null && strtoupper($cls['normalized']) === strtoupper((string)$r->igsn)) {
				$k = $item['owner'] . '|' . $item['sample_id'];
				if (!isset($seen[$k])) { $seen[$k] = true; $out['clear'][] = $item; }
			} elseif (trim((string)$r->field) === '') {
				$out['already_clear']++;
			} else {
				$out['changed'][] = $item;   // the field now holds something else: the user's, left alone
			}
		}
		// A sample with several rows (e.g. unlinked + relinked) is reported once, by its matching row.
		$out['changed'] = array_values(array_filter($out['changed'], function ($i) use ($seen) {
			return !isset($seen[$i['owner'] . '|' . $i['sample_id']]);
		}));

		// ---- untracked (Q2): testers = pilot list + anyone with a sandbox row
		$owners = SesarAccess::PILOT_USERPKEYS;
		$more = $this->db->get_results_prepared(
			"SELECT DISTINCT sample_userpkey FROM strabosamples.sesar_registrations WHERE environment = 'sandbox'", array());
		foreach ((is_array($more) ? $more : array()) as $m) $owners[] = (int)$m->sample_userpkey;
		$owners = array_values(array_unique(array_map('intval', $owners)));
		if ($onlyOwners !== null) $owners = array_values(array_intersect($owners, array_map('intval', $onlyOwners)));
		$cand = $this->db->get_results_prepared(
			"SELECT s.id, s.userpkey, s.name, s.igsn FROM strabosamples.samples s
			  WHERE s.userpkey = ANY($1::int[]) AND s.igsn IS NOT NULL AND btrim(s.igsn) <> ''
			  ORDER BY s.userpkey, s.id",
			array('{' . implode(',', $owners) . '}')
		);
		$tracked = array();
		$trows = $this->db->get_results_prepared(
			"SELECT sample_id, sample_userpkey, upper(igsn) AS igsn FROM strabosamples.sesar_registrations
			  WHERE sample_userpkey = ANY($1::int[]) AND igsn IS NOT NULL",
			array('{' . implode(',', $owners) . '}')
		);
		foreach ((is_array($trows) ? $trows : array()) as $t) $tracked[(int)$t->sample_userpkey . '|' . $t->sample_id . '|' . $t->igsn] = true;
		$ask = array();
		foreach ((is_array($cand) ? $cand : array()) as $c) {
			$cls = SesarMapper::classifyIgsn($c->igsn);
			if ($cls['kind'] !== 'sesar') continue;
			if (isset($tracked[(int)$c->userpkey . '|' . $c->id . '|' . strtoupper($cls['normalized'])])) continue;
			$ask[] = array('owner' => (int)$c->userpkey, 'sample_id' => (string)$c->id, 'name' => (string)$c->name,
				'field' => (string)$c->igsn, 'igsn' => $cls['normalized']);
		}
		foreach (array_chunk($ask, 8) as $chunk) {
			$looked = $this->prod->lookupIgsns(array_map(function ($a) { return $a['igsn']; }, $chunk));
			foreach ($chunk as $a) {
				$st = isset($looked[$a['igsn']]) ? $looked[$a['igsn']] : array('status' => 'error', 'message' => 'No answer.');
				if ($st['status'] === 'not_found') {
					$out['untracked'][] = $a;
				} elseif ($st['status'] === 'error') {
					$a['message'] = (string)$st['message'];
					$out['unchecked'][] = $a;
				}
				// found / private / gone at production = a real IGSN: not ours to judge
			}
		}

		// ---- created (Q3)
		$crows = $this->db->get_results_prepared(
			"SELECT s.id, s.userpkey, s.name, s.created_at, r.igsn
			   FROM strabosamples.sesar_registrations r
			   JOIN strabosamples.samples s ON s.id = r.sample_id AND s.userpkey = r.sample_userpkey
			  WHERE r.environment = 'sandbox' AND r.origin = 'linked'
			    AND ($2 = '' OR r.sample_userpkey = ANY($2::int[]))
			    AND r.created_at >= s.created_at AND r.created_at <= s.created_at + make_interval(secs => $1)
			    AND NOT EXISTS (SELECT 1 FROM strabosamples.sample_subsystem_links l
			                     WHERE l.sample_id = s.id AND l.sample_userpkey = s.userpkey)
			    AND NOT EXISTS (SELECT 1 FROM strabosamples.samples c
			                     WHERE c.parent_sample_id = s.id AND c.parent_userpkey = s.userpkey)
			  ORDER BY s.userpkey, s.created_at, s.id",
			array(self::CREATED_WINDOW, $only)
		);
		$listed = array();
		foreach ((is_array($crows) ? $crows : array()) as $c) {
			$k = (int)$c->userpkey . '|' . $c->id;
			if (isset($listed[$k])) continue;   // several rows, one sample
			$listed[$k] = true;
			$out['created'][] = array('owner' => (int)$c->userpkey, 'sample_id' => (string)$c->id, 'name' => (string)$c->name,
				'created_at' => (string)$c->created_at, 'igsn' => (string)$c->igsn);
		}
		return $out;
	}

	/**
	 * Clear the plan's "clear" list. Each sample is re-read under the
	 * per-sample lock (shared with mint / pull / push / deactivate) and
	 * cleared only if it still holds the tracked sandbox IGSN.
	 * @return array {cleared, skipped, failed[] {owner, sample_id, error}}
	 */
	public function apply(array $plan)
	{
		$out = array('cleared' => 0, 'skipped' => 0, 'failed' => array());
		foreach ($plan['clear'] as $c) {
			if (!SesarDb::lock($this->db, $c['owner'], $c['sample_id'])) {
				$out['failed'][] = array('owner' => $c['owner'], 'sample_id' => $c['sample_id'], 'error' => 'busy (another SESAR action holds this sample)');
				continue;
			}
			try {
				$now = $this->db->get_var_prepared("SELECT igsn FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
					array($c['sample_id'], $c['owner']));
				$cls = SesarMapper::classifyIgsn($now);
				if ($cls['normalized'] === null || strtoupper($cls['normalized']) !== strtoupper($c['igsn'])) {
					$out['skipped']++;
					continue;
				}
				$r = call_user_func($this->clearIgsn, $c['owner'], $c['sample_id']);
				if ($r === true) {
					$out['cleared']++;
				} else {
					$out['failed'][] = array('owner' => $c['owner'], 'sample_id' => $c['sample_id'], 'error' => (string)$r);
				}
			} finally {
				SesarDb::unlock($this->db, $c['owner'], $c['sample_id']);
			}
		}
		return $out;
	}
}
