<?php
/**
 * File: includes/sesar/SesarReports.php
 * Description: The two D9 reports (Phase 8), XLSX or CSV:
 *
 *              1. "My IGSNs": one row per sample of the user's that holds an
 *                 IGSN (selected, or all with one): links, StraboSamples
 *                 values, SESAR values, sync state. With "Refresh from SESAR
 *                 first" the SESAR values are read live (the account list
 *                 once, public lookups for IGSNs outside the account). R1:
 *                 the refresh NEVER writes the stored SESAR copy (the
 *                 pull-first baseline); it adds "Changed at SESAR since last
 *                 read" (live vs stored) instead, and a live 410 shows as
 *                 Deactivated without being recorded.
 *              2. "My SESAR account": every sample in the connected SESAR
 *                 account, drafts included, with "In StraboSamples?".
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarDb.php';
require_once __DIR__ . '/SesarPush.php';
require_once __DIR__ . '/../../samplesdb/lib/vocab.php';

class SesarReports
{
	const SITE = 'https://strabospot.org';
	const DOI_BASE = 'https://doi.org/';
	const LOOKUP_CHUNK = 25;

	const IGSN_HEADERS = array(
		'StraboSpot id', 'Sample name', 'IGSN', 'DOI link', 'SESAR page', 'StraboSpot page',
		'Latitude', 'Longitude', 'Material type', 'Parent sample', 'Linked to',
		'SESAR site', 'SESAR status', 'Managed by StraboSpot', 'SESAR code', 'Object type', 'SESAR material',
		'Collection date', 'Collectors', 'Registered at SESAR',
		'Last sent to SESAR', 'Changed here since sent', 'Changed at SESAR since last read', 'Differs from StraboField',
		'SESAR values from',
	);

	const ACCOUNT_HEADERS = array(
		'IGSN', 'Name', 'SESAR status', 'SESAR code', 'Object type', 'Material', 'Collection date', 'Registered at SESAR',
		'Parent IGSN', 'Latitude', 'Longitude', 'Other names', 'SESAR page',
		'In StraboSamples', 'StraboSamples sample', 'StraboSpot page',
	);

	/** Columns written as clickable links in the XLSX. */
	const LINK_HEADERS = array('DOI link', 'SESAR page', 'StraboSpot page');

	private $db;
	private $client;
	private $conn;
	private $views;
	private $pull;
	private $push;
	private $env;

	public function __construct($db, SesarClient $client, SesarConnection $conn, SesarSampleView $views, SesarPull $pull, SesarPush $push)
	{
		$this->db = $db;
		$this->client = $client;
		$this->conn = $conn;
		$this->views = $views;
		$this->pull = $pull;
		$this->push = $push;
		$this->env = $client->environment();
	}

	// =======================================================================
	// Report 1: My IGSNs
	// =======================================================================

	/**
	 * @param string[]|null $ids sample ids, or null = every sample with an IGSN
	 * @param bool $refresh read SESAR live (R1: never written back)
	 * @return array {headers, rows[] (header => value), notes[]}
	 */
	public function igsnReport($userpkey, $ids, $refresh)
	{
		$userpkey = (int)$userpkey;
		$params = array($userpkey, $this->env);
		$filter = '';
		if (is_array($ids)) {
			$ids = array_values(array_unique(array_filter(array_map('strval', $ids), 'strlen')));
			if (empty($ids)) throw new SesarError(400, 'Select at least one sample.');
			$params[] = SesarDb::pgTextArray($ids);
			$filter = ' AND s.id = ANY($3::text[])';
		}
		$res = $this->db->get_results_prepared(
			"SELECT s.id, s.name, s.igsn, s.latitude, s.longitude, s.display_sample_type,
			        p.name AS parent_name,
			        (SELECT string_agg(DISTINCT l.subsystem, ', ' ORDER BY l.subsystem) FROM strabosamples.sample_subsystem_links l
			          WHERE l.sample_id = s.id AND l.sample_userpkey = s.userpkey) AS linked,
			        r.pkey AS reg_pkey, r.igsn AS reg_igsn, r.state AS reg_state, r.origin AS reg_origin, r.access AS reg_access,
			        r.snapshot::text AS reg_snapshot, r.snapshot_at, r.pushed_at, r.field_flags::text AS field_flags,
			        r.related_resource_id AS reg_rr, r.sesar_sample_id AS reg_sesar_id
			   FROM strabosamples.samples s
			   LEFT JOIN strabosamples.samples p ON p.id = s.parent_sample_id AND p.userpkey = s.parent_userpkey
			   LEFT JOIN strabosamples.sesar_registrations r
			          ON r.sample_id = s.id AND r.sample_userpkey = s.userpkey AND r.active AND r.environment = $2
			  WHERE s.userpkey = $1 AND ((s.igsn IS NOT NULL AND btrim(s.igsn) <> '') OR r.pkey IS NOT NULL)" . $filter . "
			  ORDER BY lower(s.name) NULLS LAST, s.id",
			$params
		);
		$res = is_array($res) ? $res : array();

		// ---- SESAR values: live (refresh) or stored copies -----------------
		$notes = array();
		$live = array();       // upper IGSN => ['status' => found|gone|private|not_found|error, 'record' => array|null]
		if ($refresh && !empty($res)) {
			$want = array();
			foreach ($res as $r) {
				$igsn = $this->igsnOf($r);
				if ($igsn !== null) $want[strtoupper($igsn)] = $igsn;
			}
			$live = $this->liveRecords($userpkey, $want, $notes);
		}

		$mat = function_exists('samples_vocab_material_flat') ? samples_vocab_material_flat() : array();
		$rows = array();
		foreach ($res as $r) {
			$igsn = $this->igsnOf($r);
			$cls = SesarMapper::classifyIgsn($r->igsn);
			$snap = ($r->reg_snapshot !== null) ? json_decode($r->reg_snapshot, true) : null;
			$l = ($igsn !== null && isset($live[strtoupper($igsn)])) ? $live[strtoupper($igsn)] : null;
			$rec = null;
			$from = '';
			if ($l !== null && $l['status'] === 'found' && is_array($l['record'])) {
				$rec = $l['record'];
				$from = 'SESAR now';
			} elseif (is_array($snap)) {
				$rec = $snap;
				$from = 'Stored copy of ' . self::day($r->snapshot_at);
			}
			$sum = is_array($rec) ? SesarPull::summary($rec) : array();

			// Sync state (managed rows only; P1 = sample vs stored copy).
			$changedHere = '';
			if ($r->reg_access === 'managed' && $r->reg_state !== null) {
				$v = $this->views->build((string)$r->id, $userpkey);
				if ($v !== null) {
					try {
						$st = $this->push->statusFor($v, (object)array('pkey' => $r->reg_pkey, 'igsn' => $r->reg_igsn, 'state' => $r->reg_state,
							'origin' => $r->reg_origin, 'access' => $r->reg_access, 'snapshot' => $r->reg_snapshot,
							'related_resource_id' => $r->reg_rr, 'sesar_sample_id' => $r->reg_sesar_id));
						$changedHere = $st['pushable'] ? implode(', ', $st['fields']) : '';
					} catch (Throwable $e) { $changedHere = ''; }
				}
			}
			$changedThere = '';
			if ($l !== null && $l['status'] === 'found' && is_array($snap) && is_array($l['record'])) {
				$changedThere = implode(', ', self::changedFields($snap, $l['record']));
			}
			$flags = ($r->field_flags !== null) ? json_decode($r->field_flags, true) : null;
			$differs = is_array($flags) ? implode(', ', array_map(function ($f) { return (string)$f['label']; }, $flags)) : '';

			$rows[] = array(
				'StraboSpot id'   => (string)$r->id,
				'Sample name'     => (string)$r->name,
				'IGSN'            => $igsn !== null ? $igsn : trim((string)$r->igsn),
				'DOI link'        => $igsn !== null ? self::DOI_BASE . $igsn : '',
				'SESAR page'      => ($igsn !== null && $cls['kind'] !== 'doi') ? SesarAccess::landingUrl($igsn, $this->env) : '',
				'StraboSpot page' => self::SITE . '/samples/' . $userpkey . '/' . rawurlencode((string)$r->id),
				'Latitude'        => $r->latitude !== null ? (float)$r->latitude : '',
				'Longitude'       => $r->longitude !== null ? (float)$r->longitude : '',
				'Material type'   => $r->display_sample_type !== null ? (isset($mat[$r->display_sample_type]) ? $mat[$r->display_sample_type] : (string)$r->display_sample_type) : '',
				'Parent sample'   => (string)$r->parent_name,
				'Linked to'       => (string)$r->linked,
				'SESAR site'      => $this->env === 'sandbox' ? 'Test site (sandbox)' : 'SESAR',
				'SESAR status'    => $this->statusText($r, $cls, $rec, $l),
				'Managed by StraboSpot' => $this->managedText($r, $cls),
				'SESAR code'      => isset($sum['SESAR code']) ? $sum['SESAR code'] : '',
				'Object type'     => isset($sum['Object type']) ? $sum['Object type'] : '',
				'SESAR material'  => isset($sum['Material']) ? $sum['Material'] : '',
				'Collection date' => isset($sum['Collection date']) ? $sum['Collection date'] : '',
				'Collectors'      => isset($sum['Collectors']) ? $sum['Collectors'] : '',
				'Registered at SESAR' => isset($sum['Registered']) ? self::day($sum['Registered']) : '',
				'Last sent to SESAR'  => $r->pushed_at !== null ? self::day($r->pushed_at) : '',
				'Changed here since sent' => $changedHere,
				'Changed at SESAR since last read' => $changedThere,
				'Differs from StraboField' => $differs,
				'SESAR values from' => $from,
			);
		}
		return array('headers' => self::IGSN_HEADERS, 'rows' => $rows, 'notes' => $notes);
	}

	/** The IGSN a row is about: the tracked one first, else a stored value that classifies. */
	private function igsnOf($r)
	{
		if ($r->reg_igsn !== null) return (string)$r->reg_igsn;
		$c = SesarMapper::classifyIgsn($r->igsn);
		return $c['normalized'];
	}

	/**
	 * Live SESAR records for the report. The account list (one paged read)
	 * covers the user's own samples, drafts included; anything else is looked
	 * up publicly. Failure leaves $live short and adds a note; the report
	 * then falls back to stored copies.
	 */
	private function liveRecords($userpkey, array $want, array &$notes)
	{
		$live = array();
		$sum = $this->conn->summary($userpkey);
		if (!empty($sum['connected'])) {
			try {
				$acct = $this->pull->accountSamples($userpkey);
				foreach ($acct['rows'] as $row) {
					$k = strtoupper((string)$row['igsn']);
					if (isset($want[$k])) $live[$k] = array('status' => 'found', 'record' => $row);
				}
			} catch (SesarError $e) {
				$notes[] = 'Your SESAR account could not be read (' . $e->getMessage() . '). Stored copies were used where StraboSpot has them.';
			}
		}
		$rest = array();
		foreach ($want as $k => $igsn) {
			if (!isset($live[$k]) && strpos($igsn, SesarMapper::SESAR_PREFIX) === 0) $rest[] = $igsn;
		}
		$failed = 0;
		foreach (array_chunk($rest, self::LOOKUP_CHUNK) as $chunk) {
			foreach ($this->client->lookupIgsns($chunk) as $igsn => $a) {
				if ($a['status'] === 'error') { $failed++; continue; }
				$live[strtoupper($igsn)] = $a;
			}
		}
		if ($failed > 0) $notes[] = 'SESAR did not answer for ' . $failed . ' IGSN' . ($failed === 1 ? '' : 's') . '; stored copies were used for those.';
		return $live;
	}

	private function statusText($r, array $cls, $rec, $l)
	{
		if ($l !== null && $l['status'] === 'gone') return 'Deactivated at SESAR';
		if ($l !== null && $l['status'] === 'not_found') return 'Not found at SESAR';
		if ($l !== null && $l['status'] === 'private') return 'Not public (another SESAR account)';
		if ($r->reg_state === 'deactivation_requested') return 'Deactivation requested';
		if ($cls['kind'] === 'doi') return 'Other DOI prefix (not SESAR)';
		if ($cls['kind'] === 'invalid' && $r->reg_igsn === null) return 'Not a valid IGSN';
		if (!is_array($rec)) return '';
		switch (SesarPull::recordState($rec)) {
			case 'draft':   return 'Draft';
			case 'pending': return 'Waiting for curator review';
		}
		return 'Registered';
	}

	private function managedText($r, array $cls)
	{
		if ($r->reg_state !== null && $r->reg_access === 'managed') return $r->reg_origin === 'minted' ? 'Yes (registered here)' : 'Yes (linked)';
		if ($r->reg_state !== null) return 'Read-only (another SESAR account)';
		return 'No';
	}

	/** Owned push fields whose value at SESAR moved since the stored copy (labels). */
	public static function changedFields(array $snap, array $live)
	{
		$out = array();
		foreach (SesarMapper::PUSH_FIELDS as $f) {
			if ($f === 'external_sample_id' || !array_key_exists($f, $live)) continue;   // list rows carry it, detail copies may not
			$a = isset($snap[$f]) ? $snap[$f] : null;
			$b = $live[$f];
			if (!SesarMapper::sameValue($f, $a, $b)) $out[] = SesarPush::LABELS[$f];
		}
		return $out;
	}

	// =======================================================================
	// Report 2: My SESAR account
	// =======================================================================

	/** @return array {headers, rows[], notes[]} */
	public function accountReport($userpkey)
	{
		$userpkey = (int)$userpkey;
		$acct = $this->pull->accountSamples($userpkey);
		$holders = $this->pull->holdersFor($userpkey);
		$rows = array();
		foreach ($acct['rows'] as $r) {
			$igsn = (string)$r['igsn'];
			$sum = SesarPull::summary($r);
			$h = isset($holders[strtoupper($igsn)]) ? $holders[strtoupper($igsn)] : null;
			$pc = SesarMapper::classifyIgsn(isset($r['parent_sample']) ? $r['parent_sample'] : '');
			$names = array();
			foreach ((isset($r['other_names']) && is_array($r['other_names']) ? $r['other_names'] : array()) as $n) {
				$names[] = is_array($n) ? (string)(isset($n['name']) ? $n['name'] : (isset($n['label']) ? $n['label'] : '')) : (string)$n;
			}
			$state = SesarPull::recordState($r);
			$rows[] = array(
				'IGSN'            => $igsn,
				'Name'            => isset($r['name']) ? (string)$r['name'] : '',
				'SESAR status'    => $state === 'draft' ? 'Draft' : ($state === 'pending' ? 'Waiting for curator review' : 'Registered'),
				'SESAR code'      => isset($sum['SESAR code']) ? $sum['SESAR code'] : '',
				'Object type'     => isset($sum['Object type']) ? $sum['Object type'] : '',
				'Material'        => isset($sum['Material']) ? $sum['Material'] : '',
				'Collection date' => isset($sum['Collection date']) ? $sum['Collection date'] : '',
				'Registered at SESAR' => isset($sum['Registered']) ? self::day($sum['Registered']) : '',
				'Parent IGSN'     => $pc['normalized'] !== null ? $pc['normalized'] : '',
				'Latitude'        => isset($r['latitude']) && is_numeric($r['latitude']) ? (float)$r['latitude'] : '',
				'Longitude'       => isset($r['longitude']) && is_numeric($r['longitude']) ? (float)$r['longitude'] : '',
				'Other names'     => implode('; ', array_filter($names, 'strlen')),
				'SESAR page'      => SesarAccess::landingUrl($igsn, $this->env),
				'In StraboSamples' => $h !== null ? 'Yes' : 'No',
				'StraboSamples sample' => $h !== null ? $h['name'] : '',
				'StraboSpot page' => $h !== null ? self::SITE . $h['url'] : '',
			);
		}
		$notes = array();
		if ($acct['truncated']) $notes[] = 'Only the first ' . count($rows) . ' of ' . $acct['count'] . ' SESAR samples are listed.';
		return array('headers' => self::ACCOUNT_HEADERS, 'rows' => $rows, 'notes' => $notes);
	}

	// =======================================================================
	// Writers
	// =======================================================================

	/** @return string .xlsx bytes */
	public static function xlsx($title, array $headers, array $rows, array $notes = array())
	{
		if (!class_exists('PHPExcel')) require_once dirname(dirname(__DIR__)) . '/PHPExcel.php';
		if (!class_exists('PHPExcel_Writer_Excel2007')) require_once dirname(dirname(__DIR__)) . '/PHPExcel/Writer/Excel2007.php';
		$wb = new PHPExcel();
		$wb->getProperties()->setCreator('strabospot.org')->setLastModifiedBy('strabospot.org')->setTitle($title);
		$sh = $wb->getActiveSheet();
		$sh->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title), 0, 31));
		foreach ($headers as $i => $h) $sh->getCellByColumnAndRow($i, 1)->setValueExplicit($h, PHPExcel_Cell_DataType::TYPE_STRING);
		$lastCol = PHPExcel_Cell::stringFromColumnIndex(count($headers) - 1);
		$sh->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
		$sh->freezePane('A2');
		$n = 2;
		foreach ($rows as $row) {
			foreach ($headers as $i => $h) {
				$v = isset($row[$h]) ? $row[$h] : '';
				$cell = $sh->getCellByColumnAndRow($i, $n);
				if (is_int($v) || is_float($v)) $cell->setValueExplicit($v, PHPExcel_Cell_DataType::TYPE_NUMERIC);
				else $cell->setValueExplicit((string)$v, PHPExcel_Cell_DataType::TYPE_STRING);
				if ($v !== '' && in_array($h, self::LINK_HEADERS, true)) $cell->getHyperlink()->setUrl((string)$v);
			}
			$n++;
		}
		if (!empty($rows)) $sh->setAutoFilter('A1:' . $lastCol . ($n - 1));
		foreach ($headers as $i => $h) $sh->getColumnDimensionByColumn($i)->setWidth(min(48, max(12, mb_strlen($h) + 4)));
		if (!empty($notes)) {
			$ns = $wb->createSheet();
			$ns->setTitle('Notes');
			foreach (array_values($notes) as $i => $t) $ns->setCellValue('A' . ($i + 1), $t);
			$ns->getColumnDimension('A')->setWidth(120);
		}
		$wb->setActiveSheetIndex(0);
		$w = new PHPExcel_Writer_Excel2007($wb);
		ob_start();
		$w->save('php://output');
		return ob_get_clean();
	}

	/** @return string UTF-8 CSV with BOM (Excel), like the samples export */
	public static function csv(array $headers, array $rows)
	{
		$fh = fopen('php://temp', 'r+');
		fputcsv($fh, $headers);
		foreach ($rows as $row) {
			$line = array();
			foreach ($headers as $h) $line[] = isset($row[$h]) ? (string)$row[$h] : '';
			fputcsv($fh, $line);
		}
		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);
		return "\xEF\xBB\xBF" . $csv;
	}

	// =======================================================================

	private static function day($ts)
	{
		if ($ts === null || $ts === '') return '';
		$t = strtotime((string)$ts);
		return $t === false ? (string)$ts : gmdate('Y-m-d', $t);
	}
}
