<?php
/**
 * File: includes/sesar/SesarBatchExport.php
 * Description: "Export for SESAR batch upload" (Phase 8, B1 + B2). For users
 *              who register through SESAR's own batch upload (for example
 *              because SESAR has not granted them API access): the selected
 *              samples are written into the user's own SESAR batch template
 *              (SesarBatchTemplate), with the same values a mint would send
 *              (SesarMapper::batchRow). Our id goes in "Other Name(s)" as
 *              "StraboSpot <id>" so the IGSNs SESAR assigns can be matched
 *              back later (B2).
 *
 *              Needs no SESAR connection. The only network use is the public
 *              material vocabulary (cached; without it the material column
 *              stays blank, as in a mint with no confident match).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarMint.php';
require_once __DIR__ . '/SesarBatchTemplate.php';

class SesarBatchExport
{
	/** Phrase SESAR's Template Creator writes when object type is chosen per row. */
	const PER_SAMPLE_OBJECT_TYPE = 'Enter per sample in spreadsheet';

	private $db;
	private $views;
	private $mint;
	private $vocab;
	private $conn;
	private $env;

	public function __construct($db, SesarSampleView $views, SesarMint $mint, SesarVocab $vocab, SesarConnection $conn = null, $env = null)
	{
		$this->db = $db;
		$this->views = $views;
		$this->mint = $mint;
		$this->vocab = $vocab;
		$this->conn = $conn;
		$this->env = $env !== null ? $env : SesarAccess::environment();
	}

	/**
	 * What would be written, and why anything is left out.
	 *
	 * @param string[] $ids sample ids (owner-only)
	 * @return array {sesar_code, template_version, code_warning|null,
	 *   rows[] (header => value, in write order), samples[] {id, name,
	 *   included, reason|null, notes[]}, included, skipped,
	 *   unfilled_columns[] (columns we would fill that the template lacks)}
	 */
	public function plan($userpkey, array $ids, SesarBatchTemplate $t)
	{
		$userpkey = (int)$userpkey;
		$ids = array_values(array_unique(array_filter(array_map(function ($x) { return trim((string)$x); }, $ids), 'strlen')));
		if (empty($ids)) throw new SesarError(400, 'Select at least one sample.');
		if (count($ids) > SesarBatchTemplate::MAX_SAMPLES) {
			throw new SesarError(400, 'SESAR takes at most ' . SesarBatchTemplate::MAX_SAMPLES . ' samples per file. Select fewer samples.');
		}

		// ---- vocab: the template's own dropdowns first ---------------------
		$objectTypes = $t->allowedValues('Object Type');
		if ($objectTypes === null) {
			try { $objectTypes = $this->vocab->labels(SesarVocab::OBJECT_TYPES); } catch (SesarError $e) { $objectTypes = array(); }
		}
		$registrable = array();
		try { $registrable = $this->vocab->registrableMaterials(); } catch (SesarError $e) { $registrable = array(); }
		$matList = $t->allowedValues('General Material Type');
		if ($matList !== null && !empty($registrable)) {
			$inTemplate = array_flip(array_map('mb_strtolower', $matList));
			$registrable = array_values(array_filter($registrable, function ($m) use ($inTemplate) {
				return isset($inTemplate[mb_strtolower($m['label'])]);
			}));
		}
		$perRowType = $t->hasHeader('Object Type');

		// ---- samples ------------------------------------------------------
		$views = array();
		$out = array();
		foreach ($ids as $id) {
			$v = $this->views->build($id, $userpkey);
			if ($v === null) {
				$out[$id] = self::entry($id, $id, false, 'Not one of your samples.');
				continue;
			}
			$views[$id] = $v;
		}
		$regs = $this->registrations(array_keys($views), $userpkey);

		$order = $this->parentFirst($views, $userpkey);
		$rows = array();
		$used = array();
		foreach ($order as $id) {
			$v = $views[$id];
			$e = self::entry($id, (string)$v['name'], true, null);
			$reg = isset($regs[$id]) ? $regs[$id] : null;
			$cls = SesarMapper::classifyIgsn($v['igsn']);
			$blockers = SesarMapper::mintBlockers($v);
			if ($reg !== null && $reg->state === 'minting') {
				$e['included'] = false;
				$e['reason'] = 'An IGSN registration for this sample has not finished. Open Register IGSN to complete it first.';
			} elseif ($reg !== null) {
				$e['included'] = false;
				$e['reason'] = 'It already has an IGSN (' . $reg->igsn . ').';
			} elseif ($cls['kind'] === 'sesar' || $cls['kind'] === 'doi') {
				$e['included'] = false;
				$e['reason'] = 'Its IGSN field already holds ' . $cls['normalized'] . '.';
			} elseif (!empty($blockers)) {
				$e['included'] = false;
				$e['reason'] = SesarMint::reasonText($blockers[0]);
			}
			if (!$e['included']) { $out[$id] = $e; continue; }

			if ($cls['kind'] === 'invalid') {
				$e['notes'][] = 'Its IGSN field holds "' . trim((string)$v['igsn']) . '", which is not an IGSN. It stays until the new IGSN is added.';
			}
			$v['parent_igsn'] = $this->mint->parentIgsnFor($v, false);
			if ($v['parent_igsn'] === null && $v['parent_sample_id'] !== null) {
				$pname = isset($views[$v['parent_sample_id']]) ? (string)$views[$v['parent_sample_id']]['name'] : null;
				$e['notes'][] = ($pname !== null ? 'Its parent "' . $pname . '"' : 'Its parent') . ' has no IGSN yet, so Parent IGSN is left blank.'
					. ' Once both have IGSNs, set the parent at SESAR.';
			}
			$row = SesarMapper::batchRow($v, array(
				'object_type'           => $perRowType ? SesarMapper::suggestObjectType($v, $objectTypes) : null,
				'general_material_type' => SesarMapper::suggestMaterial($v, $registrable),
			));
			foreach ($row as $h => $val) {
				if ($val !== null && $val !== '') $used[$h] = true;
			}
			$rows[] = $row;
			$out[$id] = $e;
		}

		$unfilled = array();
		foreach (array_keys($used) as $h) {
			if (!$t->hasHeader($h)) $unfilled[] = $h;
		}

		$samples = array();
		foreach ($ids as $id) $samples[] = $out[$id];
		$included = count($rows);
		return array(
			'sesar_code'       => $t->sesarCode(),
			'template_version' => $t->templateVersion(),
			'code_warning'     => $this->codeWarning($userpkey, $t->sesarCode()),
			'existing_rows'    => $t->dataRowCount(),
			'rows'             => $rows,
			'samples'          => $samples,
			'included'         => $included,
			'skipped'          => count($samples) - $included,
			'unfilled_columns' => $unfilled,
		);
	}

	/** Plan + the filled template bytes. */
	public function fill($userpkey, array $ids, SesarBatchTemplate $t)
	{
		$plan = $this->plan($userpkey, $ids, $t);
		if ($plan['included'] === 0) throw new SesarError(400, 'None of the selected samples can go in the file. See the reasons listed.');
		return array('plan' => $plan, 'bytes' => $t->fill($plan['rows']));
	}

	/** A plain note when the template's code is not one the connected account can use (warning only). */
	private function codeWarning($userpkey, $code)
	{
		if ($this->conn === null) return null;
		try { $s = $this->conn->summary($userpkey); } catch (Throwable $e) { return null; }
		if (empty($s['connected']) || empty($s['sesar_codes'])) return null;
		foreach ($s['sesar_codes'] as $c) {
			if (strcasecmp($c, $code) === 0) return null;
		}
		return 'This template was made for SESAR code ' . $code . ', which is not one of the codes on your connected SESAR account ('
			. implode(', ', $s['sesar_codes']) . '). SESAR will refuse the upload if you cannot register under ' . $code . '.';
	}

	private function registrations(array $ids, $userpkey)
	{
		if (empty($ids)) return array();
		$rows = $this->db->get_results_prepared(
			"SELECT sample_id, igsn, state FROM strabosamples.sesar_registrations
			  WHERE sample_userpkey = $1 AND environment = $2 AND active AND sample_id = ANY($3::text[])",
			array((int)$userpkey, $this->env, self::pgTextArray($ids))
		);
		$out = array();
		foreach ((is_array($rows) ? $rows : array()) as $r) $out[(string)$r->sample_id] = $r;
		return $out;
	}

	/** Selection order, except a selected parent always comes before its children. */
	private function parentFirst(array $views, $userpkey)
	{
		$out = array();
		$done = array();
		$visit = function ($id, $depth) use (&$visit, &$out, &$done, $views, $userpkey) {
			if (isset($done[$id]) || $depth > 50) return;
			$v = $views[$id];
			$p = $v['parent_sample_id'];
			if ($p !== null && isset($views[$p]) && (int)$v['parent_userpkey'] === $userpkey) $visit($p, $depth + 1);
			if (isset($done[$id])) return;
			$done[$id] = true;
			$out[] = $id;
		};
		foreach (array_keys($views) as $id) $visit($id, 0);
		return $out;
	}

	private static function entry($id, $name, $included, $reason)
	{
		return array('id' => (string)$id, 'name' => (string)$name, 'included' => $included, 'reason' => $reason, 'notes' => array());
	}

	private static function pgTextArray(array $vals)
	{
		return '{' . implode(',', array_map(function ($v) {
			return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$v) . '"';
		}, $vals)) . '}';
	}
}
