<?php
/**
 * File: includes/sesar/SesarMapper.php
 * Description: Pure mapping between StraboSamples samples and SESAR sample
 *              records (no database, no network). Decisions: D2 (mint form +
 *              forward mapping + suggestions), D5 (pull / reverse mapping,
 *              Field-linked rule), D6 (push fields + change fingerprint).
 *
 *              Input "sample view" (assembled by the caller):
 *                id, name, description, latitude, longitude,
 *                display_sample_type, display_sample_purpose,
 *                field_data (array|null), field_linked (bool), micro_linked (bool),
 *                latitude_end / longitude_end (Field LineString spots, optional),
 *                parent_igsn (optional: the parent's IGSN, if it has one),
 *                field_rock_types (optional: rock names from the linked Field
 *                  spot's geologic unit / petrology, most specific first;
 *                  choice names or labels, e.g. "granite", "basaltic-andesite")
 *
 *              Field stores choice NAMES (option_13, fabric___micro); SESAR
 *              gets LABELS via FieldVocab, never names.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/../fieldvocab/FieldVocab.php';

class SesarMapper
{
	/**
	 * Field sample_type choice name -> SESAR object type leaf label (D2
	 * suggestion step 1). Field's list was modeled on SESAR's old sample
	 * types; every choice except 'other' has a current SESAR leaf.
	 */
	const FIELD_SAMPLE_TYPE_TO_OBJECT_TYPE = array(
		'individual_sample'   => 'Individual sample',
		'core'                => 'Core',
		'oriented_core'       => 'Oriented Core',
		'grab'                => 'Grab',
		'dredge'              => 'Dredge',
		'trawl'               => 'Trawl',
		'cuttings'            => 'Cuttings',
		'site'                => 'Site',
		'terrestrial_section' => 'Terrestrial section',
		'ctd'                 => 'CTD sample',
		'hole'                => 'Hole',
		'rock_powder'         => 'Powder',
	);

	/**
	 * Field material_type choice name -> SESAR material label candidates,
	 * first registrable match wins. intact_rock / fragmented_roc have NO
	 * entry: SESAR refuses "Rock" (for_registration is enforced, sandbox
	 * 09-26), so rock samples get a material only from a specific rock name
	 * (field_rock_types) and are otherwise left blank (D2, option A).
	 */
	const FIELD_MATERIAL_TO_SESAR = array(
		'sediment'         => array('Sediment'),
		'tephra'           => array('Tephra'),
		'carbon_or_animal' => array('Organic biological material', 'Biological material'),
	);

	/** Object type fallback when nothing better is known (D2 step 3). */
	const DEFAULT_OBJECT_TYPE = 'Individual sample';

	/** Fields StraboSamples owns at SESAR: pushed when they differ (D6), fingerprinted. */
	const PUSH_FIELDS = array(
		'name', 'latitude', 'longitude', 'latitude_end', 'longitude_end',
		'sample_description', 'purpose', 'sampling_start_date', 'sampling_date_precision',
		'parent_sample', 'external_sample_id',
	);

	const MAX_NAME = 255;
	const MAX_PURPOSE = 500;
	const MAX_EXTERNAL_ID = 100;

	/** Coordinates closer than this are "the same place" (metres). */
	const SAME_PLACE_M = 10.0;

	// =======================================================================
	// D2 suggestions
	// =======================================================================

	/**
	 * @param array    $s          sample view
	 * @param string[] $leafLabels SESAR object type labels (vocab); empty = trust the maps
	 * @return string an object type leaf label
	 */
	public static function suggestObjectType(array $s, array $leafLabels = array())
	{
		$fd = isset($s['field_data']) && is_array($s['field_data']) ? $s['field_data'] : array();
		$candidates = array();
		if (isset($fd['sample_type']) && is_string($fd['sample_type'])) {
			$name = $fd['sample_type'];
			if (isset(self::FIELD_SAMPLE_TYPE_TO_OBJECT_TYPE[$name])) $candidates[] = self::FIELD_SAMPLE_TYPE_TO_OBJECT_TYPE[$name];
			$candidates[] = FieldVocab::label(FieldVocab::formsFor('samples'), 'sample_type', $name);   // "Individual Sample"
		}
		if (!empty($s['micro_linked'])) $candidates[] = 'Thin section';
		$mat = self::fieldMaterial($s);
		if ($mat === 'intact_rock' || $mat === 'fragmented_roc') $candidates[] = 'Rock hand sample';
		$candidates[] = self::DEFAULT_OBJECT_TYPE;

		foreach ($candidates as $c) {
			$hit = self::matchLabel($c, $leafLabels);
			if ($hit !== null) return $hit;
		}
		return self::DEFAULT_OBJECT_TYPE;
	}

	/**
	 * D2 option A: pre-fill the material only on a confident match that
	 * SESAR will register; otherwise null (the optional field stays blank).
	 * Specific rock names from the linked spot win over the coarse Field
	 * material_type. Matches SESAR labels and their synonyms, ignoring case,
	 * underscores and hyphens.
	 *
	 * @param array $registrable SesarVocab::registrableMaterials() rows
	 *                           ({label, synonyms}) or plain labels; empty =
	 *                           cannot verify, so no suggestion
	 * @return string|null a registrable material label, or null
	 */
	public static function suggestMaterial(array $s, array $registrable)
	{
		$candidates = array();
		if (!empty($s['field_rock_types']) && is_array($s['field_rock_types'])) {
			foreach ($s['field_rock_types'] as $r) {
				if (is_string($r)) $candidates[] = $r;
			}
		}
		$mat = self::fieldMaterial($s);
		if ($mat !== null && isset(self::FIELD_MATERIAL_TO_SESAR[$mat])) {
			$candidates = array_merge($candidates, self::FIELD_MATERIAL_TO_SESAR[$mat]);
		}
		foreach ($candidates as $c) {
			$hit = self::matchMaterial($c, $registrable);
			if ($hit !== null) return $hit;
		}
		return null;
	}

	/**
	 * The exact registrable label for a user-chosen material (form
	 * validation before POST), or null when SESAR would refuse it.
	 */
	public static function registrableMaterial($label, array $registrable)
	{
		$key = self::materialKey($label);
		if ($key === '') return null;
		foreach ($registrable as $m) {
			$l = is_array($m) ? (string)$m['label'] : (string)$m;
			if (self::materialKey($l) === $key) return $l;
		}
		return null;
	}

	// =======================================================================
	// D2 forward mapping (mint)
	// =======================================================================

	/**
	 * Why this sample cannot be minted, or empty when it can. Codes:
	 * no_name, no_location, bad_location.
	 */
	public static function mintBlockers(array $s)
	{
		$out = array();
		if (trim((string)(isset($s['name']) ? $s['name'] : '')) === '') $out[] = 'no_name';
		$lat = isset($s['latitude']) ? $s['latitude'] : null;
		$lon = isset($s['longitude']) ? $s['longitude'] : null;
		if ($lat === null || $lon === null || $lat === '' || $lon === '') $out[] = 'no_location';
		elseif (!is_numeric($lat) || !is_numeric($lon) || abs((float)$lat) > 90 || abs((float)$lon) > 180) $out[] = 'bad_location';
		return $out;
	}

	/**
	 * The fields StraboSamples fills automatically (shown read-only in the
	 * mint form; also the push/fingerprint source).
	 */
	public static function ownedFields(array $s)
	{
		$out = array(
			'name'               => mb_substr(trim((string)$s['name']), 0, self::MAX_NAME),
			'latitude'           => self::coord(isset($s['latitude']) ? $s['latitude'] : null),
			'longitude'          => self::coord(isset($s['longitude']) ? $s['longitude'] : null),
			'external_sample_id' => mb_substr((string)$s['id'], 0, self::MAX_EXTERNAL_ID),
		);
		if (isset($s['latitude_end'], $s['longitude_end']) && is_numeric($s['latitude_end']) && is_numeric($s['longitude_end'])) {
			$out['latitude_end'] = self::coord($s['latitude_end']);
			$out['longitude_end'] = self::coord($s['longitude_end']);
		}
		$desc = trim((string)(isset($s['description']) ? $s['description'] : ''));
		if ($desc !== '') $out['sample_description'] = $desc;
		$purpose = self::purposeLabel($s);
		if ($purpose !== null) $out['purpose'] = mb_substr($purpose, 0, self::MAX_PURPOSE);
		$date = self::collectionDate($s);
		if ($date !== null) {
			$out['sampling_start_date'] = $date;
			$out['sampling_date_precision'] = 'time';
		}
		if (!empty($s['parent_igsn'])) $out['parent_sample'] = (string)$s['parent_igsn'];
		return $out;
	}

	/**
	 * Full registration payload (POST /api/samples/). $choices: sesar_code,
	 * object_type (required); general_material_type, collector (optional),
	 * collector_individual (optional {label, fname, lname, individual_uri}:
	 * SESAR resolves a bare label by name and refuses names it knows more
	 * than once, "Ambiguous individual match by label" (sandbox 09-27), so
	 * the connected person is sent with their ORCID individual_uri).
	 */
	public static function registrationPayload(array $s, array $choices)
	{
		$p = self::ownedFields($s);
		$p['sesar_code'] = (string)$choices['sesar_code'];
		$p['object_type'] = (string)$choices['object_type'];
		if (!empty($choices['general_material_type'])) $p['general_material_type'] = (string)$choices['general_material_type'];
		$collector = isset($choices['collector']) ? trim((string)$choices['collector']) : '';
		if (!empty($choices['collector_individual']) && is_array($choices['collector_individual'])) {
			$p['collectors'] = array(array('individual' => $choices['collector_individual']));
		} elseif ($collector !== '') {
			$p['collectors'] = array(array('individual' => array('label' => $collector)));
		}
		return $p;
	}

	/** D6: sha256 over the owned fields in a canonical order. */
	public static function fingerprint(array $owned)
	{
		$canon = array();
		foreach (self::PUSH_FIELDS as $f) {
			$canon[$f] = array_key_exists($f, $owned) ? self::norm($f, $owned[$f]) : null;
		}
		return hash('sha256', json_encode($canon));
	}

	/**
	 * D6: PATCH body = owned fields that differ from SESAR's current record.
	 * Never sends lists (collectors) or user-chosen fields; never clears a
	 * SESAR value we do not hold (lat/lon cannot be cleared at SESAR anyway).
	 */
	public static function pushPatch(array $owned, array $sesarRecord)
	{
		$patch = array();
		foreach (self::PUSH_FIELDS as $f) {
			if (!array_key_exists($f, $owned)) continue;
			$theirs = isset($sesarRecord[$f]) ? $sesarRecord[$f] : null;
			if (!self::sameValue($f, $owned[$f], $theirs)) $patch[$f] = $owned[$f];
		}
		return $patch;
	}

	// =======================================================================
	// D5 reverse mapping (pull)
	// =======================================================================

	/**
	 * Proposed spine changes from a SESAR record. Each entry:
	 *   field, current, sesar, action
	 * action: same | fill (ours empty) | overwrite (both set, differ) |
	 *         flag (Field-linked: shown, never applied).
	 * Only spine fields; everything else lives in the snapshot.
	 */
	public static function pullProposals(array $s, array $record)
	{
		$fieldLinked = !empty($s['field_linked']);
		$pairs = array(
			array('name', isset($s['name']) ? $s['name'] : null, isset($record['name']) ? $record['name'] : null, false),
			array('description', isset($s['description']) ? $s['description'] : null,
			      isset($record['sample_description']) ? $record['sample_description'] : null, false),
			array('display_sample_purpose', self::purposeLabel($s), isset($record['purpose']) ? $record['purpose'] : null, $fieldLinked),
			array('display_sample_type', self::materialDisplay($s),
			      isset($record['general_material_type']) ? $record['general_material_type'] : null, $fieldLinked),
		);
		$out = array();
		foreach ($pairs as $p) {
			list($field, $cur, $theirs, $flagOnly) = $p;
			$out[] = self::proposal($field, $cur, $theirs, $flagOnly);
		}

		// Location travels as a pair; Field-linked samples keep the spot's location.
		$lat = isset($record['latitude']) && is_numeric($record['latitude']) ? (float)$record['latitude'] : null;
		$lon = isset($record['longitude']) && is_numeric($record['longitude']) ? (float)$record['longitude'] : null;
		if ($lat !== null && $lon !== null) {
			$hasOurs = isset($s['latitude'], $s['longitude']) && is_numeric($s['latitude']) && is_numeric($s['longitude']);
			$dist = $hasOurs ? self::distanceM((float)$s['latitude'], (float)$s['longitude'], $lat, $lon) : null;
			if ($hasOurs && $dist <= self::SAME_PLACE_M) $action = 'same';
			elseif ($fieldLinked) $action = 'flag';
			else $action = $hasOurs ? 'overwrite' : 'fill';
			$out[] = array(
				'field'      => 'location',
				'current'    => $hasOurs ? array((float)$s['latitude'], (float)$s['longitude']) : null,
				'sesar'      => array($lat, $lon),
				'action'     => $action,
				'distance_m' => $dist,
			);
		}
		return $out;
	}

	// =======================================================================
	// Stored IGSN values (free text in StraboSamples)
	// =======================================================================

	/**
	 * SESAR's own DOI prefix (10.58052/ + 9 letters/digits). NOT the only one:
	 * SESAR also hosts IGSNs under team prefixes (e.g. 10.60471/ODP01BXOT, the
	 * ODP collection, resolves at SESAR prod; checked 2026-09-26).
	 */
	const SESAR_PREFIX = '10.58052/';

	/**
	 * What a stored IGSN field value really is. The field is free text, so it
	 * holds sample names, placeholders and keyboard mashing as well as real
	 * IGSNs (dev 09-26: 919 real, 68 junk). Never send a stored value to
	 * SESAR (lookup, link, parent_sample) unless this says 'sesar'.
	 *
	 * @return array {kind, normalized}
	 *   kind: empty | sesar (well-formed 10.58052/ IGSN; existence NOT yet checked)
	 *         | doi (IGSN-shaped DOI under another prefix: may be a SESAR team
	 *           prefix OR another registrar; only a SESAR lookup can tell)
	 *         | invalid (not an IGSN)
	 *   normalized: "10.58052/IEJMA0002" for sesar, the DOI for doi, else null
	 */
	public static function classifyIgsn($value)
	{
		$v = trim((string)$value);
		if ($v === '') return array('kind' => 'empty', 'normalized' => null);
		// Accepted wrappers: DOI / IGSN resolver URLs, "doi:" and "igsn:" labels.
		$v = preg_replace('#^(https?://)?(dx\.)?doi\.org/#i', '', $v);
		$v = preg_replace('#^(https?://)?(www\.)?igsn\.org/#i', '', $v);
		$v = preg_replace('#^(doi|igsn)\s*:\s*#i', '', $v);
		if (preg_match('#^10\.58052/([A-Za-z0-9]{9})$#', $v, $m)) {
			return array('kind' => 'sesar', 'normalized' => self::SESAR_PREFIX . strtoupper($m[1]));
		}
		if (stripos($v, self::SESAR_PREFIX) === 0) return array('kind' => 'invalid', 'normalized' => null);   // SESAR prefix, wrong shape
		if (preg_match('#^10\.[0-9]{4,9}/\S+$#', $v)) {
			return array('kind' => 'doi', 'normalized' => $v);
		}
		// Bare 9-character IGSN (pre-DOI SESAR style, e.g. IEJMA0002, HRV003M16).
		if (preg_match('#^[A-Za-z0-9]{9}$#', $v)) {
			return array('kind' => 'sesar', 'normalized' => self::SESAR_PREFIX . strtoupper($v));
		}
		return array('kind' => 'invalid', 'normalized' => null);
	}

	/** "Marine and lacustrine samples > Core piece" -> "Core piece" (SESAR reads return paths). */
	public static function leaf($objectType)
	{
		$parts = explode('>', (string)$objectType);
		return trim(end($parts));
	}

	public static function distanceM($lat1, $lon1, $lat2, $lon2)
	{
		$r = 6371000.0;
		$p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
		$dp = $p2 - $p1; $dl = deg2rad($lon2 - $lon1);
		$a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
		return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
	}

	// =======================================================================
	// Helpers
	// =======================================================================

	private static function proposal($field, $cur, $theirs, $flagOnly)
	{
		$c = trim((string)$cur); $t = trim((string)$theirs);
		if ($t === '' || $c === $t || mb_strtolower($c) === mb_strtolower($t)) $action = 'same';
		elseif ($flagOnly) $action = 'flag';
		else $action = ($c === '') ? 'fill' : 'overwrite';
		return array('field' => $field, 'current' => $cur, 'sesar' => $theirs, 'action' => $action);
	}

	private static function fieldMaterial(array $s)
	{
		$fd = isset($s['field_data']) && is_array($s['field_data']) ? $s['field_data'] : array();
		if (isset($fd['material_type']) && is_string($fd['material_type'])) return $fd['material_type'];
		return null;
	}

	/** Purpose as a label: Field choice names translated, free text kept. */
	private static function purposeLabel(array $s)
	{
		$v = isset($s['display_sample_purpose']) ? trim((string)$s['display_sample_purpose']) : '';
		if ($v === '') return null;
		return (string)FieldVocab::label(FieldVocab::formsFor('samples'), 'main_sampling_purpose', $v);
	}

	private static function materialDisplay(array $s)
	{
		$v = isset($s['display_sample_type']) ? trim((string)$s['display_sample_type']) : '';
		if ($v === '') return null;
		return (string)FieldVocab::label(FieldVocab::formsFor('samples'), 'material_type', $v);
	}

	/** Field collection_date (ISO instant, e.g. 2025-07-12T04:45:10.722Z) -> SESAR date-time. */
	private static function collectionDate(array $s)
	{
		$fd = isset($s['field_data']) && is_array($s['field_data']) ? $s['field_data'] : array();
		if (empty($fd['collection_date']) || !is_string($fd['collection_date'])) return null;
		$t = strtotime($fd['collection_date']);
		return $t === false ? null : gmdate('Y-m-d\TH:i:s\Z', $t);
	}

	/** Coordinates as decimal strings (SESAR's decimal pattern), 6 places (~0.1 m). */
	private static function coord($v)
	{
		if ($v === null || !is_numeric($v)) return null;
		return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
	}

	/** Label match first across all terms, then synonyms (a synonym never beats an exact label). */
	private static function matchMaterial($candidate, array $registrable)
	{
		$hit = self::registrableMaterial($candidate, $registrable);
		if ($hit !== null) return $hit;
		$key = self::materialKey($candidate);
		if ($key === '') return null;
		foreach ($registrable as $m) {
			if (!is_array($m) || empty($m['synonyms'])) continue;
			foreach ($m['synonyms'] as $syn) {
				if (self::materialKey($syn) === $key) return (string)$m['label'];
			}
		}
		return null;
	}

	/** "Basaltic-Andesite" / "basaltic_andesite" -> "basaltic andesite". */
	private static function materialKey($v)
	{
		$v = mb_strtolower(trim((string)$v));
		return trim(preg_replace('/[\s_-]+/u', ' ', $v));
	}

	private static function matchLabel($candidate, array $labels)
	{
		if ($candidate === null || $candidate === '') return null;
		if (empty($labels)) return $candidate;
		$lc = mb_strtolower(trim($candidate));
		foreach ($labels as $l) {
			if (mb_strtolower(trim((string)$l)) === $lc) return (string)$l;
		}
		return null;
	}

	private static function norm($f, $v)
	{
		if ($v === null) return null;
		if (in_array($f, array('latitude', 'longitude', 'latitude_end', 'longitude_end'), true)) {
			return is_numeric($v) ? round((float)$v, 6) : null;
		}
		if ($f === 'sampling_start_date') {
			$t = strtotime((string)$v);
			return $t === false ? (string)$v : gmdate('Y-m-d\TH:i:s\Z', $t);
		}
		return trim((string)$v);
	}

	private static function sameValue($f, $ours, $theirs)
	{
		$a = self::norm($f, $ours);
		$b = self::norm($f, $theirs);
		if (is_float($a) || is_float($b)) return $a !== null && $b !== null && abs($a - $b) < 0.0000005;
		return $a === $b;
	}
}
