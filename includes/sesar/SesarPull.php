<?php
/**
 * File: includes/sesar/SesarPull.php
 * Description: Pull from SESAR (Phase 5; decision D5 in
 *              docs/StraboSamples_IGSN_Feature_Request/IGSN_Design_Decisions.md).
 *
 *              preview()     what the single-sample review shows: every
 *                            spine field SESAR could fill or overwrite, the
 *                            Field-linked differences that are shown but
 *                            never applied, and a parent link when SESAR
 *                            names another of the user's samples as parent.
 *              apply()       applies one sample's pull. The browser says
 *                            WHICH fields (review) or which mode (bulk: fill
 *                            empty, optionally overwrite); the VALUES always
 *                            come from a fresh SESAR read here. Records the
 *                            link (sesar_registrations, origin 'linked';
 *                            managed when this SESAR account may edit the
 *                            record, else readonly) + the snapshot shown as
 *                            the "SESAR record" card.
 *              createPlan() / createOne()
 *                            "Create samples from IGSNs" and "Import from my
 *                            SESAR account": one new StraboSamples-only
 *                            sample per IGSN, parents first.
 *              importPage()  one page of the user's own SESAR samples, each
 *                            marked with the StraboSamples sample holding it.
 *              unlink()      removes a pulled link (origin 'linked') so the
 *                            IGSN can be linked to another sample; nothing
 *                            changes at SESAR or in the sample.
 *
 *              Reads: SESAR's detail GET has can_edit but lacks sample_id,
 *              external_sample_id and last_update_date; the account's own
 *              list filtered by ?igsn= has those (sandbox + prod 09-27), so a
 *              pull reads both. Owner only, as minting (D3).
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
require_once __DIR__ . '/SesarDeactivate.php';

class SesarPull
{
	/** IGSNs per create run (as the mint cap, D4). */
	const CAP = 100;

	/** Rows per page of the "Import from my SESAR account" list. */
	const IMPORT_PAGE = 50;

	/** Whole-account reads (Phase 8): SESAR allows page_size up to 2000. */
	const ACCOUNT_PAGE = 500;
	const ACCOUNT_MAX_PAGES = 40;

	/** Plain labels for the review. */
	const LABELS = array(
		'name'                   => 'Name',
		'description'            => 'Description',
		'display_sample_purpose' => 'Purpose',
		'display_sample_type'    => 'Material type',
		'location'               => 'Location',
		'parent'                 => 'Parent sample',
	);

	private $db;
	private $neodb;
	private $client;
	private $conn;
	private $views;
	private $env;

	public function __construct($db, SesarClient $client, SesarConnection $conn, SesarSampleView $views, $neodb = null)
	{
		$this->db = $db;
		$this->neodb = $neodb;
		$this->client = $client;
		$this->conn = $conn;
		$this->views = $views;
		$this->env = $client->environment();
	}

	// =======================================================================
	// Single sample: preview + apply
	// =======================================================================

	/**
	 * @return array {sample_id, name, igsn, landing_url, environment, access,
	 *   linked (already tracked), rows[] {field, label, current, sesar, action,
	 *   checked, distance_m?}, flags[] (Field-linked, shown only), parent_note,
	 *   record (SESAR summary for display)}
	 * @throws SesarError with a plain message when nothing can be pulled
	 */
	public function preview($userpkey, $sampleId)
	{
		$x = $this->inspect((int)$userpkey, (string)$sampleId);
		return $this->describe($x);
	}

	/**
	 * @param array $in mode: 'review' (fields listed in accept[], each with
	 *                  the SESAR value the user saw in seen{field: value}) |
	 *                  'fill' (bulk: empty fields only) | 'overwrite' (bulk:
	 *                  fill and overwrite); parent (bool): set the offered
	 *                  parent link (bulk sets only a MISSING parent)
	 * @return array {ok, igsn, landing_url, access, applied[], skipped[], flags[],
	 *                parent_set, notes[]}
	 */
	public function apply($userpkey, $sampleId, array $in)
	{
		$userpkey = (int)$userpkey;
		$sampleId = (string)$sampleId;
		$mode = isset($in['mode']) && in_array($in['mode'], array('review', 'fill', 'overwrite'), true) ? $in['mode'] : 'review';
		$accept = isset($in['accept']) && is_array($in['accept']) ? array_map('strval', $in['accept']) : array();
		$seen = isset($in['seen']) && is_array($in['seen']) ? $in['seen'] : array();

		if (!$this->lock($userpkey, $sampleId)) {
			throw new SesarError(409, 'This sample is busy with another SESAR action. Please wait a moment and try again.', array('busy' => array('busy')));
		}
		try {
			$x = $this->inspect($userpkey, $sampleId);
			$d = $this->describe($x);
			$updates = array();
			$applied = array();
			$skipped = array();
			foreach ($d['rows'] as $row) {
				$f = $row['field'];
				if ($f === 'parent' || !in_array($row['action'], array('fill', 'overwrite'), true)) continue;
				if ($mode === 'review') {
					if (!in_array($f, $accept, true)) continue;
					// The user approved the value they saw; SESAR may have changed since.
					// A ticked field without the value the user saw is never applied unseen.
					if (!array_key_exists($f, $seen)) {
						$skipped[] = array('field' => $f, 'why' => 'The reviewed value was not sent with the request. Pull again and apply from the review.');
						continue;
					}
					if (json_encode(self::displayValue($f, $seen[$f])) !== json_encode($row['sesar'])) {
						$skipped[] = array('field' => $f, 'why' => 'Changed at SESAR since the review was opened. Pull again to see the new value.');
						continue;
					}
				} elseif ($mode === 'fill' && $row['action'] !== 'fill') {
					continue;
				}
				$updates = array_merge($updates, self::spineUpdate($f, $x['record']));
				$applied[] = $f;
			}

			$notes = array();
			$svc = $this->service($userpkey);
			if (!empty($updates)) {
				$r = $svc->updateSample($sampleId, $userpkey, $updates);
				if (empty($r['ok'])) {
					throw new SesarError(409, 'The sample could not be updated (' . (isset($r['error']) ? $r['error'] : 'unknown') . '). Nothing was changed.');
				}
			}

			$parentSet = false;
			$p = $x['parent'];
			if ($p !== null && !empty($in['parent']) && in_array($p['action'], array('fill', 'overwrite'), true)
				&& ($mode === 'review' || $p['action'] === 'fill')) {
				$r = $svc->setParent($sampleId, $userpkey, $p['id'], $userpkey);
				if (!empty($r['ok'])) {
					$parentSet = true;
				} else {
					$notes[] = ($r['error'] === 'cycle_detected')
						? 'The parent link was not set: ' . $p['name'] . ' is already below this sample in its family.'
						: 'The parent link could not be set (' . $r['error'] . ').';
				}
			}

			$this->recordLink($userpkey, $sampleId, $x['record'], $x['can_edit'], $x['view']['field_linked'] ? $d['flags'] : null);
			return array(
				'ok'          => true,
				'igsn'        => $x['record']['igsn'],
				'landing_url' => SesarAccess::landingUrl($x['record']['igsn'], $this->env),
				'environment' => $this->env,
				'access'      => $x['can_edit'] ? 'managed' : 'readonly',
				'applied'     => $applied,
				'skipped'     => $skipped,
				'flags'       => $d['flags'],
				'parent_set'  => $parentSet,
				'notes'       => $notes,
			);
		} finally {
			$this->unlock($userpkey, $sampleId);
		}
	}

	// =======================================================================
	// Create samples from IGSNs (paste list + import tab)
	// =======================================================================

	/**
	 * What the create review shows for a pasted list (one IGSN per line,
	 * commas and spaces also split) or the ticked import rows.
	 *
	 * @param string[] $inputs raw values as typed
	 * @return array {environment, cap, rows[] {input, igsn, group: ready|held|
	 *   blocked, reason, name, parent_igsn, parent_in_run, holder {id,name,url}|null,
	 *   depth}}
	 */
	public function createPlan($userpkey, array $inputs)
	{
		$userpkey = (int)$userpkey;
		$rows = array();
		$seen = array();
		foreach ($inputs as $raw) {
			$raw = trim((string)$raw);
			if ($raw === '') continue;
			$cls = SesarMapper::classifyIgsn($raw);
			$key = $cls['normalized'] !== null ? strtoupper($cls['normalized']) : 'raw:' . $raw;
			if (isset($seen[$key])) continue;
			$seen[$key] = true;
			$rows[] = array('input' => $raw, 'igsn' => $cls['normalized'], 'kind' => $cls['kind'], 'group' => 'ready', 'reason' => null,
				'name' => null, 'parent_igsn' => null, 'parent_in_run' => false, 'holder' => null, 'depth' => 0);
		}
		if (count($rows) > self::CAP) {
			throw new SesarError(400, 'Please paste at most ' . self::CAP . ' IGSNs at a time.');
		}

		$holders = $this->holders($userpkey);
		$toLook = array();
		foreach ($rows as $i => $r) {
			if ($r['kind'] === 'invalid') {
				$rows[$i]['group'] = 'blocked';
				$rows[$i]['reason'] = 'Not an IGSN.';
			} elseif (isset($holders[strtoupper($r['igsn'])])) {
				$h = $holders[strtoupper($r['igsn'])];
				$rows[$i]['group'] = 'held';
				$rows[$i]['holder'] = $h;
				$rows[$i]['reason'] = 'Already in StraboSamples as "' . $h['name'] . '".';
			} else {
				$toLook[] = $r['igsn'];
			}
		}
		$looked = $this->client->lookupIgsns($toLook);
		foreach ($rows as $i => $r) {
			if ($r['group'] !== 'ready') continue;
			$l = isset($looked[$r['igsn']]) ? $looked[$r['igsn']] : array('status' => 'error', 'record' => null);
			switch ($l['status']) {
				case 'found':
					$rec = is_array($l['record']) ? $l['record'] : array();
					if (!empty($rec['igsn']) && is_string($rec['igsn'])) $rows[$i]['igsn'] = $rec['igsn'];
					$rows[$i]['name'] = isset($rec['name']) ? (string)$rec['name'] : null;
					$pc = SesarMapper::classifyIgsn(isset($rec['parent_sample']) ? $rec['parent_sample'] : '');
					$rows[$i]['parent_igsn'] = $pc['normalized'];
					break;
				case 'private':
					$rows[$i]['reason'] = 'Not public at SESAR; read with your SESAR connection when it is created.';
					break;
				case 'not_found':
					$rows[$i]['group'] = 'blocked';
					$rows[$i]['reason'] = 'SESAR has no sample with this IGSN.';
					break;
				case 'gone':
					$rows[$i]['group'] = 'blocked';
					$rows[$i]['reason'] = 'Deactivated at SESAR.';
					break;
				default:
					$rows[$i]['group'] = 'blocked';
					$rows[$i]['reason'] = 'Could not check it with SESAR just now. Please try again.';
			}
		}

		// Parents first (D8 order): depth = ancestors that are also in this run.
		$inRun = array();
		foreach ($rows as $r) if ($r['group'] === 'ready') $inRun[strtoupper($r['igsn'])] = $r;
		foreach ($rows as $i => $r) {
			if ($r['group'] !== 'ready') continue;
			$depth = 0;
			$cur = $r;
			$guard = array(strtoupper($r['igsn']) => true);
			while ($cur['parent_igsn'] !== null && isset($inRun[strtoupper($cur['parent_igsn'])]) && !isset($guard[strtoupper($cur['parent_igsn'])])) {
				$guard[strtoupper($cur['parent_igsn'])] = true;
				$cur = $inRun[strtoupper($cur['parent_igsn'])];
				$depth++;
			}
			$rows[$i]['depth'] = $depth;
			$rows[$i]['parent_in_run'] = $r['parent_igsn'] !== null && isset($inRun[strtoupper($r['parent_igsn'])]);
			if ($r['parent_igsn'] !== null && !$rows[$i]['parent_in_run'] && isset($holders[strtoupper($r['parent_igsn'])])) {
				$rows[$i]['parent_holder'] = $holders[strtoupper($r['parent_igsn'])];
			}
		}
		// Ready rows first, parents before children; ties keep the pasted order
		// (explicit: PHP 7 sorts are not stable).
		foreach ($rows as $i => $r) { $rows[$i]['_i'] = $i; unset($rows[$i]['kind']); }
		usort($rows, function ($a, $b) {
			$ga = $a['group'] === 'ready' ? 0 : 1; $gb = $b['group'] === 'ready' ? 0 : 1;
			if ($ga !== $gb) return $ga - $gb;
			if ($a['depth'] !== $b['depth']) return $a['depth'] - $b['depth'];
			return $a['_i'] - $b['_i'];
		});
		foreach ($rows as $i => $r) unset($rows[$i]['_i']);
		return array('environment' => $this->env, 'cap' => self::CAP, 'rows' => $rows);
	}

	/**
	 * Creates ONE StraboSamples sample from a SESAR record and links it. Every
	 * check runs again here: not already held by one of the user's samples,
	 * live at SESAR, parent only from the user's own samples.
	 *
	 * @return array {ok, sample_id, name, url, igsn, landing_url, access, parent_linked, notes[]}
	 */
	public function createOne($userpkey, $igsnInput)
	{
		$userpkey = (int)$userpkey;
		$cls = SesarMapper::classifyIgsn($igsnInput);
		if (!in_array($cls['kind'], array('sesar', 'doi'), true)) throw new SesarError(400, 'Not an IGSN.');
		$igsn = $cls['normalized'];
		if (!$this->lock($userpkey, 'igsn:' . strtoupper($igsn))) {
			throw new SesarError(409, 'This IGSN is already being imported. Please wait a moment and try again.', array('busy' => array('busy')));
		}
		try {
			$holders = $this->holders($userpkey);
			if (isset($holders[strtoupper($igsn)])) {
				$h = $holders[strtoupper($igsn)];
				throw new SesarError(409, 'Already in StraboSamples as "' . $h['name'] . '".', array('held' => array($h['id'])));
			}
			$f = $this->fetch($userpkey, $igsn);
			$rec = $f['record'];
			if (isset($holders[strtoupper($rec['igsn'])])) {   // input was a bare/URL form of a held IGSN
				$h = $holders[strtoupper($rec['igsn'])];
				throw new SesarError(409, 'Already in StraboSamples as "' . $h['name'] . '".', array('held' => array($h['id'])));
			}

			$input = array(
				'name' => (isset($rec['name']) && trim((string)$rec['name']) !== '') ? mb_substr(trim((string)$rec['name']), 0, 255) : $rec['igsn'],
				'igsn' => $rec['igsn'],
			);
			foreach (array('description', 'display_sample_purpose', 'display_sample_type') as $field) {
				$u = self::spineUpdate($field, $rec);
				if ($u[$field] !== null && $u[$field] !== '') $input[$field] = $u[$field];
			}
			$loc = self::spineUpdate('location', $rec);
			if ($loc['latitude'] !== null && $loc['longitude'] !== null) $input += $loc;

			$notes = array();
			$parentLinked = false;
			$pc = SesarMapper::classifyIgsn(isset($rec['parent_sample']) ? $rec['parent_sample'] : '');
			if ($pc['normalized'] !== null) {
				if (isset($holders[strtoupper($pc['normalized'])])) {
					$input['parent_sample_id'] = $holders[strtoupper($pc['normalized'])]['id'];
					$input['parent_userpkey'] = $userpkey;
					$parentLinked = true;
				} else {
					$notes[] = 'SESAR lists its parent as ' . $pc['normalized'] . ', which is not one of your samples, so no parent link was set.';
				}
			}

			$r = $this->service($userpkey)->createSample($input);
			if (empty($r['ok'])) {
				throw new SesarError(409, 'The sample could not be created (' . (isset($r['error']) ? $r['error'] : 'unknown') . ').');
			}
			$sid = (string)$r['sample']['id'];
			$this->recordLink($userpkey, $sid, $rec, $f['can_edit'], null);
			return array(
				'ok'            => true,
				'sample_id'     => $sid,
				'name'          => $input['name'],
				'url'           => '/samples/' . $userpkey . '/' . rawurlencode($sid),
				'igsn'          => $rec['igsn'],
				'landing_url'   => SesarAccess::landingUrl($rec['igsn'], $this->env),
				'environment'   => $this->env,
				'access'        => $f['can_edit'] ? 'managed' : 'readonly',
				'parent_linked' => $parentLinked,
				'notes'         => $notes,
			);
		} finally {
			$this->unlock($userpkey, 'igsn:' . strtoupper($igsn));
		}
	}

	// =======================================================================
	// Import from my SESAR account
	// =======================================================================

	/**
	 * @return array {count, page, pages, rows[] {igsn, name, sesar_code,
	 *   object_type, material, registered, has_location, parent_igsn, holder}}
	 */
	public function importPage($userpkey, $page, $search = null)
	{
		$userpkey = (int)$userpkey;
		$page = max(1, (int)$page);
		$q = array('scope' => 'personal', 'page' => $page, 'page_size' => self::IMPORT_PAGE);
		$search = trim((string)$search);
		if ($search !== '') $q['search'] = mb_substr($search, 0, 100);
		$client = $this->client;
		$res = $this->conn->withAccess($userpkey, function ($access) use ($client, $q) { return $client->listSamples($access, $q); });
		$holders = $this->holders($userpkey);
		$rows = array();
		foreach ($res['data'] as $r) {
			if (!is_array($r) || empty($r['igsn'])) continue;
			$pc = SesarMapper::classifyIgsn(isset($r['parent_sample']) ? $r['parent_sample'] : '');
			$rows[] = array(
				'igsn'         => (string)$r['igsn'],
				'name'         => isset($r['name']) ? (string)$r['name'] : '',
				'sesar_code'   => isset($r['sesar_code']) ? (string)$r['sesar_code'] : '',
				'object_type'  => isset($r['object_type']) ? SesarMapper::leaf($r['object_type']) : '',
				'material'     => isset($r['general_material_type']) ? (string)$r['general_material_type'] : '',
				'registered'   => isset($r['registration_date']) ? $r['registration_date'] : (isset($r['publish_date']) ? $r['publish_date'] : null),
				'has_location' => isset($r['latitude'], $r['longitude']) && is_numeric($r['latitude']) && is_numeric($r['longitude']),
				'parent_igsn'  => $pc['normalized'],
				'holder'       => isset($holders[strtoupper((string)$r['igsn'])]) ? $holders[strtoupper((string)$r['igsn'])] : null,
			);
		}
		return array(
			'count' => $res['count'],
			'page'  => $page,
			'pages' => max(1, (int)ceil($res['count'] / self::IMPORT_PAGE)),
			'rows'  => $rows,
		);
	}

	// =======================================================================
	// Whole account + batch match-back (Phase 8)
	// =======================================================================

	/**
	 * Every sample in the connected account (personal scope, drafts
	 * included), page by page. Shared by "Find my batch IGSNs" (B2) and the
	 * "My SESAR account" report (D9).
	 * @return array {rows[] (SESAR list rows), count, truncated}
	 */
	public function accountSamples($userpkey)
	{
		$client = $this->client;
		$rows = array();
		$count = 0;
		for ($page = 1; $page <= self::ACCOUNT_MAX_PAGES; $page++) {
			$q = array('scope' => 'personal', 'page' => $page, 'page_size' => self::ACCOUNT_PAGE, 'ordering' => 'igsn');
			$res = $this->conn->withAccess((int)$userpkey, function ($access) use ($client, $q) { return $client->listSamples($access, $q); });
			$count = $res['count'];
			foreach ($res['data'] as $r) {
				if (is_array($r) && !empty($r['igsn'])) $rows[] = $r;
			}
			if (count($res['data']) < self::ACCOUNT_PAGE || empty($res['next'])) break;
		}
		return array('rows' => $rows, 'count' => $count, 'truncated' => count($rows) < $count);
	}

	/** SESAR's workflow state for a list row: draft | pending | registered. */
	public static function recordState(array $r)
	{
		if (!empty($r['is_draft']) && $r['is_draft'] !== 'false') return 'draft';
		if (!empty($r['is_pending_review']) && $r['is_pending_review'] !== 'false') return 'pending';
		return 'registered';
	}

	/**
	 * B2 + B3: SESAR samples whose Other Name(s) carry "StraboSpot <id>" for
	 * one of the user's samples (the batch export writes it). Registered
	 * ones are ticked; drafts and pending ones are listed unticked.
	 *
	 * @return array {environment, rows[] {sample_id, name, igsn, sesar_name,
	 *   state, linkable, checked, reason|null, note|null}, truncated}
	 */
	public function batchMatches($userpkey)
	{
		$userpkey = (int)$userpkey;
		$acct = $this->accountSamples($userpkey);
		$byId = array();
		foreach ($acct['rows'] as $r) {
			$id = SesarMapper::idFromOtherNames(isset($r['other_names']) ? $r['other_names'] : array());
			if ($id !== null) $byId[$id][] = $r;
		}
		if (empty($byId)) return array('environment' => $this->env, 'rows' => array(), 'truncated' => $acct['truncated']);

		$samples = $this->db->get_results_prepared(
			"SELECT s.id, s.name, s.igsn,
			        (SELECT r.igsn FROM strabosamples.sesar_registrations r
			          WHERE r.sample_id = s.id AND r.sample_userpkey = s.userpkey AND r.environment = $3 AND r.active LIMIT 1) AS reg_igsn
			   FROM strabosamples.samples s WHERE s.userpkey = $1 AND s.id = ANY($2::text[])",
			array($userpkey, self::pgTextArray(array_keys($byId)), $this->env)
		);
		$holders = $this->holders($userpkey);
		$out = array();
		foreach ((is_array($samples) ? $samples : array()) as $s) {
			foreach ($byId[(string)$s->id] as $r) {
				$igsn = (string)$r['igsn'];
				$state = self::recordState($r);
				$cls = SesarMapper::classifyIgsn($s->igsn);
				$row = array(
					'sample_id'  => (string)$s->id,
					'name'       => (string)$s->name,
					'igsn'       => $igsn,
					'sesar_name' => isset($r['name']) ? (string)$r['name'] : '',
					'state'      => $state,
					'linkable'   => true,
					'checked'    => $state === 'registered',
					'reason'     => null,
					'note'       => null,
				);
				$held = isset($holders[strtoupper($igsn)]) ? $holders[strtoupper($igsn)] : null;
				if ($s->reg_igsn !== null && strcasecmp((string)$s->reg_igsn, $igsn) === 0) {
					continue;   // already linked: nothing to do
				} elseif ($s->reg_igsn !== null) {
					$row['reason'] = 'This sample is already linked to ' . $s->reg_igsn . '.';
				} elseif ($held !== null && $held['id'] !== (string)$s->id) {
					$row['reason'] = $igsn . ' is already in your sample "' . $held['name'] . '".';
				} elseif ($cls['kind'] === 'sesar' || $cls['kind'] === 'doi') {
					if (strcasecmp((string)$cls['normalized'], $igsn) !== 0) {
						$row['reason'] = 'Its IGSN field already holds ' . $cls['normalized'] . '.';
					} else {
						$row['note'] = 'Its IGSN field already holds this IGSN; linking lets StraboSpot manage it.';
					}
				} elseif ($cls['kind'] === 'invalid') {
					$row['checked'] = false;
					$row['note'] = 'Replaces "' . trim((string)$s->igsn) . '" in its IGSN field (the old value is kept in the sample\'s history).';
				}
				if ($row['reason'] !== null) { $row['linkable'] = false; $row['checked'] = false; }
				$out[] = $row;
			}
		}
		usort($out, function ($a, $b) { return strcasecmp($a['igsn'], $b['igsn']); });
		return array('environment' => $this->env, 'rows' => $out, 'truncated' => $acct['truncated']);
	}

	/**
	 * Links one batch-registered IGSN to the sample its Other Name(s) names.
	 * SESAR is re-read: the record must be this account's own and still
	 * carry "StraboSpot <sample id>". The IGSN goes into the sample (history
	 * kept by the service), then the usual pull link records it with nothing
	 * else changed.
	 * @return array apply() result
	 */
	public function batchLink($userpkey, $sampleId, $igsnInput)
	{
		$userpkey = (int)$userpkey;
		$sampleId = (string)$sampleId;
		$cls = SesarMapper::classifyIgsn($igsnInput);
		if ($cls['kind'] !== 'sesar') throw new SesarError(400, 'That is not a SESAR IGSN.');
		$igsn = $cls['normalized'];
		$v = $this->views->build($sampleId, $userpkey);
		if ($v === null) throw new SesarError(404, 'This is not one of your samples.');

		$client = $this->client;
		$row = $this->conn->withAccess($userpkey, function ($access) use ($client, $igsn) { return $client->findOwnByIgsn($access, $igsn); });
		if (!is_array($row)) throw new SesarError(404, $igsn . ' is not one of the samples in your SESAR account.');
		if (SesarMapper::idFromOtherNames(isset($row['other_names']) ? $row['other_names'] : array()) !== $sampleId) {
			throw new SesarError(409, $igsn . ' no longer names this sample in its Other Name(s) at SESAR, so it was not linked.');
		}
		$reg = $this->activeRegistration($sampleId, $userpkey);
		if ($reg !== null && strcasecmp((string)$reg->igsn, $igsn) !== 0) {
			throw new SesarError(409, 'This sample is already linked to ' . $reg->igsn . '.');
		}
		$cur = SesarMapper::classifyIgsn($v['igsn']);
		if (($cur['kind'] === 'sesar' || $cur['kind'] === 'doi') && strcasecmp((string)$cur['normalized'], $igsn) !== 0) {
			throw new SesarError(409, 'Its IGSN field already holds ' . $cur['normalized'] . '.');
		}
		if ($reg === null && strcasecmp((string)$cur['normalized'], $igsn) !== 0) {
			$r = $this->service($userpkey)->updateSample($sampleId, $userpkey, array('igsn' => $igsn));
			if (empty($r['ok'])) {
				throw new SesarError(409, 'The IGSN could not be added to the sample (' . (isset($r['error']) ? $r['error'] : 'unknown') . ').');
			}
		}
		// Nothing accepted = only the link (tracking row + snapshot) is recorded.
		return $this->apply($userpkey, $sampleId, array('mode' => 'review', 'accept' => array()));
	}

	private static function pgTextArray(array $vals)
	{
		return '{' . implode(',', array_map(function ($v) {
			return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$v) . '"';
		}, $vals)) . '}';
	}

	// =======================================================================
	// Internals
	// =======================================================================

	/**
	 * Removes this sample's pulled SESAR link. Only 'linked' rows in state
	 * 'active': a minted IGSN was made from this sample (its SESAR record
	 * links back here), so it is never moved; the row stays as history.
	 * @return array {ok, igsn}
	 */
	public function unlink($userpkey, $sampleId)
	{
		$userpkey = (int)$userpkey;
		$sampleId = (string)$sampleId;
		if ($this->views->build($sampleId, $userpkey) === null) throw new SesarError(404, 'This is not one of your samples.');
		if (!$this->lock($userpkey, $sampleId)) {
			throw new SesarError(409, 'This sample is busy with another SESAR action. Please wait a moment and try again.', array('busy' => array('busy')));
		}
		try {
			$reg = $this->activeRegistration($sampleId, $userpkey);
			if ($reg === null) throw new SesarError(404, 'This sample is not linked to a SESAR record.');
			if ($reg->origin !== 'linked' || $reg->state !== 'active') {
				throw new SesarError(409, 'Only a link made by Pull from SESAR can be unlinked. This IGSN was registered from this sample through StraboSpot.');
			}
			$this->db->prepare_query(
				"UPDATE strabosamples.sesar_registrations
				    SET state = 'unlinked', active = FALSE, unlinked_at = now(), updated_at = now()
				  WHERE pkey = $1 AND active",
				array((int)$reg->pkey)
			);
			return array('ok' => true, 'igsn' => (string)$reg->igsn);
		} finally {
			$this->unlock($userpkey, $sampleId);
		}
	}

	/**
	 * Everything a pull needs for one sample, read fresh.
	 * @return array {view, reg, record, can_edit, proposals, parent}
	 */
	private function inspect($userpkey, $sampleId)
	{
		$v = $this->views->build($sampleId, $userpkey);
		if ($v === null) throw new SesarError(404, 'This is not one of your samples.');

		$reg = $this->activeRegistration($sampleId, $userpkey);
		if ($reg !== null && $reg->state === 'minting') {
			throw new SesarError(409, 'An IGSN registration for this sample has not finished. Open Register IGSN to complete it first.');
		}
		if ($reg !== null) {
			$igsn = (string)$reg->igsn;
		} else {
			$cls = SesarMapper::classifyIgsn($v['igsn']);
			if ($cls['kind'] === 'empty') throw new SesarError(400, 'This sample has no IGSN to pull from.');
			if ($cls['kind'] === 'invalid') throw new SesarError(400, 'Its IGSN field ("' . trim((string)$v['igsn']) . '") is not an IGSN, so there is nothing to pull.');
			$igsn = $cls['normalized'];
		}

		$f = $this->fetch($userpkey, $igsn);
		$rec = $f['record'];

		// One IGSN, one sample (per user and environment). A link left by a
		// deleted sample does not count (no FK by design, D7).
		$other = $this->db->get_row_prepared(
			"SELECT r.sample_id, s.name FROM strabosamples.sesar_registrations r
			   JOIN strabosamples.samples s ON s.id = r.sample_id AND s.userpkey = r.sample_userpkey
			  WHERE r.sample_userpkey = $1 AND r.environment = $2 AND r.active AND upper(r.igsn) = upper($3) AND r.sample_id <> $4 LIMIT 1",
			array($userpkey, $this->env, (string)$rec['igsn'], $sampleId)
		);
		if ($other !== null) {
			throw new SesarError(409, $rec['igsn'] . ' is already linked to your sample "' . ($other->name !== null ? $other->name : $other->sample_id)
				. '". An IGSN can be linked to only one sample. To move it here, open that sample and use "Unlink from SESAR" on its SESAR record card, then pull again.',
				array('held' => array((string)$other->sample_id)));
		}

		return array(
			'view'      => $v,
			'reg'       => $reg,
			'record'    => $rec,
			'can_edit'  => $f['can_edit'],
			'proposals' => SesarMapper::pullProposals($v, $rec),
			'parent'    => $this->parentProposal($userpkey, $v, $rec),
		);
	}

	/**
	 * Field-vs-SESAR differences for a Field-linked sample view against a
	 * SESAR record (stored as field_flags); null when not Field-linked.
	 * Push refreshes them after sending.
	 */
	public static function fieldFlags(array $v, array $rec)
	{
		if (empty($v['field_linked'])) return null;
		$out = array();
		foreach (SesarMapper::pullProposals($v, $rec) as $p) {
			if ($p['action'] === 'flag') $out[] = self::proposalRow($p);
		}
		return $out;
	}

	private static function proposalRow(array $p)
	{
		$row = array(
			'field'   => $p['field'],
			'label'   => self::LABELS[$p['field']],
			'current' => self::displayValue($p['field'], $p['current']),
			'sesar'   => self::displayValue($p['field'], $p['sesar']),
			'action'  => $p['action'],
			'checked' => $p['action'] === 'fill',
		);
		if (array_key_exists('distance_m', $p)) $row['distance_m'] = $p['distance_m'] === null ? null : round($p['distance_m']);
		return $row;
	}

	private function describe(array $x)
	{
		$rows = array();
		$flags = array();
		foreach ($x['proposals'] as $p) {
			$row = self::proposalRow($p);
			if ($p['action'] === 'flag') $flags[] = $row;
			else $rows[] = $row;
		}
		$parentNote = null;
		$pp = $x['parent'];
		if ($pp !== null && $pp['action'] === 'elsewhere') {
			$parentNote = 'SESAR lists its parent as ' . $pp['igsn'] . ', which is not one of your samples.';
		} elseif ($pp !== null) {
			$rows[] = array(
				'field'   => 'parent',
				'label'   => self::LABELS['parent'],
				'current' => $pp['current_name'],
				'sesar'   => $pp['name'] . ' (' . $pp['igsn'] . ')',
				'action'  => $pp['action'],
				'checked' => $pp['action'] === 'fill',
			);
		}
		$rec = $x['record'];
		return array(
			'sample_id'   => $x['view']['id'],
			'name'        => (string)$x['view']['name'],
			'igsn'        => $rec['igsn'],
			'landing_url' => SesarAccess::landingUrl($rec['igsn'], $this->env),
			'environment' => $this->env,
			'access'      => $x['can_edit'] ? 'managed' : 'readonly',
			'linked'      => $x['reg'] !== null,
			'field_linked'=> !empty($x['view']['field_linked']),
			'rows'        => $rows,
			'flags'       => $flags,
			'parent_note' => $parentNote,
			'record'      => self::summary($rec),
		);
	}

	/**
	 * SESAR detail + this account's list row. Detail carries can_edit; the
	 * list row carries sample_id / external_sample_id / last_update_date and
	 * exists only for the account's own samples.
	 * @return array {record, can_edit}
	 * @throws SesarError plain messages for not found / deactivated / private
	 */
	public function fetch($userpkey, $igsn)
	{
		$client = $this->client;
		try {
			$rec = $this->conn->withAccess($userpkey, function ($access) use ($client, $igsn) { return $client->getSample($access, $igsn); });
		} catch (SesarError $e) {
			if ($e->kind === 'gone') {
				// Every signed-in read that meets a 410 records it (Phase 7, markGone).
				(new SesarDeactivate($this->db, $this->client, $this->conn, SesarDeactivate::serviceIgsnClearer($this->db, $this->neodb)))->markGone($userpkey, $igsn);
				throw new SesarError(410, $igsn . ' has been deactivated at SESAR, so there is nothing to pull.', array('igsn' => array('gone')));
			}
			if ($e->kind === 'not_found') throw new SesarError(404, 'SESAR has no sample with the IGSN ' . $igsn . '.', array('igsn' => array('not_found')));
			if ($e->status === 403 && empty($e->errors['permissions'])) {
				throw new SesarError(403, $igsn . ' is not public at SESAR and belongs to another SESAR account, so it cannot be read.', array('igsn' => array('private')));
			}
			throw $e;
		}
		if (!is_array($rec) || empty($rec['igsn'])) throw new SesarError(502, 'SESAR answered without a sample record. Please try again.');
		$row = null;
		try {
			$row = $this->conn->withAccess($userpkey, function ($access) use ($client, $rec) { return $client->findOwnByIgsn($access, $rec['igsn']); });
		} catch (SesarError $e) {
			if (in_array($e->kind, array('auth', 'no_permission', 'no_account'), true)) throw $e;
			// Network trouble on the second read: the ids simply stay unknown until the next pull.
		}
		if (is_array($row)) {
			foreach (array('sample_id', 'external_sample_id', 'last_update_date') as $k) {
				if (array_key_exists($k, $row) && !array_key_exists($k, $rec)) $rec[$k] = $row[$k];
			}
		}
		$canEdit = isset($rec['can_edit']) && ($rec['can_edit'] === true || $rec['can_edit'] === 'true' || $rec['can_edit'] === 1);
		return array('record' => $rec, 'can_edit' => $canEdit);
	}

	/**
	 * SESAR's parent_sample as one of the user's samples (D5).
	 * action: same | fill (ours has none) | overwrite (ours differs) |
	 *         elsewhere (not one of the user's samples); null = SESAR names none.
	 */
	private function parentProposal($userpkey, array $v, array $rec)
	{
		$pc = SesarMapper::classifyIgsn(isset($rec['parent_sample']) ? $rec['parent_sample'] : '');
		if ($pc['normalized'] === null) return null;
		$holders = $this->holders($userpkey);
		$h = isset($holders[strtoupper($pc['normalized'])]) ? $holders[strtoupper($pc['normalized'])] : null;
		if ($h === null || $h['id'] === $v['id']) return array('action' => 'elsewhere', 'igsn' => $pc['normalized']);
		$curName = null;
		if ($v['parent_sample_id'] !== null) {
			$curName = $this->db->get_var_prepared("SELECT name FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
				array((string)$v['parent_sample_id'], (int)$v['parent_userpkey']));
			if ($curName === null) $curName = (string)$v['parent_sample_id'];
		}
		if ($v['parent_sample_id'] === null) $action = 'fill';
		elseif ((string)$v['parent_sample_id'] === $h['id'] && (int)$v['parent_userpkey'] === $userpkey) $action = 'same';
		else $action = 'overwrite';
		return array('action' => $action, 'id' => $h['id'], 'name' => $h['name'], 'igsn' => $pc['normalized'], 'current_name' => $curName);
	}

	/**
	 * normalized IGSN (upper case) => {id, name} of the user's sample holding
	 * it: an active registration first, else a stored IGSN field value that
	 * classifies as an IGSN.
	 */
	private function holders($userpkey)
	{
		$out = array();
		$rows = $this->db->get_results_prepared(
			"SELECT id, name, igsn FROM strabosamples.samples
			  WHERE userpkey = $1 AND igsn IS NOT NULL AND btrim(igsn) <> ''",
			array((int)$userpkey)
		);
		foreach ((is_array($rows) ? $rows : array()) as $r) {
			$c = SesarMapper::classifyIgsn($r->igsn);
			if ($c['normalized'] === null) continue;
			$k = strtoupper($c['normalized']);
			if (!isset($out[$k])) $out[$k] = self::holder($userpkey, $r->id, $r->name);
		}
		$regs = $this->db->get_results_prepared(
			"SELECT r.sample_id, r.igsn, s.name FROM strabosamples.sesar_registrations r
			   JOIN strabosamples.samples s ON s.id = r.sample_id AND s.userpkey = r.sample_userpkey
			  WHERE r.sample_userpkey = $1 AND r.environment = $2 AND r.active AND r.igsn IS NOT NULL",
			array((int)$userpkey, $this->env)
		);
		foreach ((is_array($regs) ? $regs : array()) as $r) {
			$out[strtoupper((string)$r->igsn)] = self::holder($userpkey, $r->sample_id, $r->name);
		}
		return $out;
	}

	private static function holder($userpkey, $id, $name)
	{
		return array('id' => (string)$id, 'name' => (string)$name, 'url' => '/samples/' . (int)$userpkey . '/' . rawurlencode((string)$id));
	}

	/** Records (or refreshes) the link: tracking row + snapshot (D5). */
	private function recordLink($userpkey, $sampleId, array $rec, $canEdit, $flags)
	{
		$access = $canEdit ? 'managed' : 'readonly';
		$sesarId = isset($rec['sample_id']) && is_numeric($rec['sample_id']) ? (int)$rec['sample_id'] : null;
		$last = !empty($rec['last_update_date']) ? $rec['last_update_date'] : null;
		$status = isset($rec['metadata_store_status']) ? $rec['metadata_store_status'] : null;
		$flagsJson = $flags === null ? null : json_encode(array_values($flags));
		$params = array(json_encode($rec), $status, $last, $sesarId, $access, $flagsJson);

		$reg = $this->activeRegistration($sampleId, $userpkey);
		if ($reg === null) {
			$this->db->prepare_query(
				"INSERT INTO strabosamples.sesar_registrations
				        (sample_id, sample_userpkey, environment, igsn, sesar_code, origin, access, state, active,
				         snapshot, snapshot_at, sesar_status, sesar_last_update, sesar_sample_id, field_flags, created_by)
				 VALUES ($1, $2, $3, $4, $5, 'linked', $6, 'active', TRUE, $7::jsonb, now(), $8, $9, $10, $11::jsonb, $2)
				 ON CONFLICT (sample_id, sample_userpkey, environment) WHERE active DO NOTHING",
				array((string)$sampleId, (int)$userpkey, $this->env, (string)$rec['igsn'],
				      isset($rec['sesar_code']) ? $rec['sesar_code'] : null, $access, $params[0], $status, $last, $sesarId, $flagsJson)
			);
			$reg = $this->activeRegistration($sampleId, $userpkey);
			if ($reg !== null && $reg->state !== 'minting' && (string)$reg->igsn === (string)$rec['igsn']) {
				return;   // inserted (or a concurrent pull of the same record did)
			}
			if ($reg === null) return;
		}
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_registrations
			    SET snapshot = $1::jsonb, snapshot_at = now(), sesar_status = $2, sesar_last_update = COALESCE($3, sesar_last_update),
			        sesar_sample_id = COALESCE($4, sesar_sample_id), access = $5,
			        field_flags = $6::jsonb, updated_at = now()
			  WHERE pkey = $7",
			array($params[0], $status, $last, $sesarId, $access, $flagsJson, (int)$reg->pkey)
		);
	}

	private function activeRegistration($sampleId, $userpkey)
	{
		return $this->db->get_row_prepared(
			"SELECT pkey, igsn, state, origin FROM strabosamples.sesar_registrations
			  WHERE sample_id = $1 AND sample_userpkey = $2 AND environment = $3 AND active",
			array((string)$sampleId, (int)$userpkey, $this->env)
		);
	}

	private function service($userpkey)
	{
		require_once __DIR__ . '/../../samplesdb/services/StraboSamplesService.php';
		$svc = new StraboSamplesService($this->db, $this->neodb);
		$svc->setUserpkey((int)$userpkey);
		return $svc;
	}

	/** Spine columns for one pulled field, from the SESAR record. */
	private static function spineUpdate($field, array $rec)
	{
		$get = function ($k) use ($rec) { return (isset($rec[$k]) && trim((string)$rec[$k]) !== '') ? trim((string)$rec[$k]) : null; };
		switch ($field) {
			case 'name':                   return array('name' => $get('name') === null ? null : mb_substr($get('name'), 0, 255));
			case 'description':            return array('description' => $get('sample_description'));
			case 'display_sample_purpose': return array('display_sample_purpose' => $get('purpose'));
			case 'display_sample_type':    return array('display_sample_type' => $get('general_material_type'));
			case 'location':
				$lat = isset($rec['latitude']) && is_numeric($rec['latitude']) ? (float)$rec['latitude'] : null;
				$lon = isset($rec['longitude']) && is_numeric($rec['longitude']) ? (float)$rec['longitude'] : null;
				return array('latitude' => $lat, 'longitude' => $lon);
		}
		return array();
	}

	/** Review display: locations as "lat, lon" text, everything else trimmed text or null. */
	private static function displayValue($field, $v)
	{
		if ($v === null) return null;
		if ($field === 'location') {
			if (is_array($v) && count($v) === 2) return rtrim(rtrim(number_format((float)$v[0], 6, '.', ''), '0'), '.') . ', ' . rtrim(rtrim(number_format((float)$v[1], 6, '.', ''), '0'), '.');
			return (string)$v;
		}
		$s = trim((string)$v);
		return $s === '' ? null : $s;
	}

	/** SESAR record fields worth showing (the "SESAR record" card and the review). */
	public static function summary(array $rec)
	{
		$pick = function ($k) use ($rec) { return (isset($rec[$k]) && !is_array($rec[$k]) && trim((string)$rec[$k]) !== '') ? trim((string)$rec[$k]) : null; };
		$collectors = array();
		foreach ((isset($rec['collectors']) && is_array($rec['collectors']) ? $rec['collectors'] : array()) as $c) {
			if (is_string($c)) $collectors[] = $c;
			elseif (is_array($c) && isset($c['individual']['label'])) $collectors[] = (string)$c['individual']['label'];
			elseif (is_array($c) && isset($c['label'])) $collectors[] = (string)$c['label'];
		}
		$out = array(
			'IGSN'             => $pick('igsn'),
			'Name'             => $pick('name'),
			'SESAR code'       => $pick('sesar_code'),
			'Object type'      => $pick('object_type') === null ? null : SesarMapper::leaf($pick('object_type')),
			'Material'         => $pick('general_material_type'),
			'Description'      => $pick('sample_description'),
			'Purpose'          => $pick('purpose'),
			'Parent'           => $pick('parent_sample'),
			'Location'         => ($pick('latitude') !== null && $pick('longitude') !== null) ? $pick('latitude') . ', ' . $pick('longitude') : null,
			'Elevation'        => $pick('elevation') === null ? null : $pick('elevation') . ($pick('elevation_unit') !== null ? ' ' . $pick('elevation_unit') : ''),
			'Locality'         => $pick('locality'),
			'Country'          => $pick('country'),
			'Geologic unit'    => $pick('geologic_unit'),
			'Age'              => $pick('geologic_age_verbatim'),
			'Collection date'  => $pick('sampling_start_date'),
			'Collectors'       => empty($collectors) ? null : implode('; ', $collectors),
			'Sampling method'  => $pick('sampling_method'),
			'Field name'       => $pick('field_name'),
			'Current archive'  => $pick('current_archive'),
			'Registered'       => $pick('registration_date'),
			'Last changed at SESAR' => $pick('last_update_date'),
		);
		return array_filter($out, function ($v) { return $v !== null; });
	}

	private function lock($userpkey, $key)
	{
		// Same namespace as minting: a pull and a mint of one sample never overlap.
		return $this->db->get_var_prepared(
			"SELECT pg_try_advisory_lock($1, hashtext($2))", array(SesarMint::LOCK_NS, $userpkey . ':' . $key)) === 't';
	}

	private function unlock($userpkey, $key)
	{
		$this->db->get_var_prepared(
			"SELECT pg_advisory_unlock($1, hashtext($2))", array(SesarMint::LOCK_NS, $userpkey . ':' . $key));
	}
}
