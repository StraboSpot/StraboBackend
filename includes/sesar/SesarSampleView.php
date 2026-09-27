<?php
/**
 * File: includes/sesar/SesarSampleView.php
 * Description: Assembles the "sample view" SesarMapper works from (see the
 *              SesarMapper header) for one StraboSamples sample: the spine
 *              row, its subsystem links and, for Field-linked samples, the
 *              holding Spot in Neo4j.
 *
 *              From the Spot (D2 + the 09-26 material revision):
 *              - location for spots the spine has none for: a LineString
 *                gives its first vertex as latitude/longitude and its last
 *                as latitude_end/longitude_end (as the Field app sends).
 *                Image-basemap and strat-section spots carry pixel
 *                coordinates, never a map location. Polygons stay without
 *                a location (minting is blocked with a clear reason).
 *              - field_rock_types, most specific first: geologic-unit tags
 *                holding the spot (project json_tags), then petrology
 *                (pet), then sedimentology (sed). SesarMapper matches them
 *                against SESAR's registrable materials; no match = blank.
 *
 *              Reading Neo4j is best-effort: a missing spot or a Cypher
 *              failure just means no extra location / rock names (and the
 *              Bolt connection is reset, per the poisoning rule).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class SesarSampleView
{
	/**
	 * Keys naming a specific rock (checked first), in order. Field stores
	 * choice NAMES (basaltic_andes); SesarMapper matches them ignoring case,
	 * underscores and hyphens, then SESAR synonyms.
	 */
	const SPECIFIC_ROCK_KEYS = array(
		'plutonic_rock_types', 'plutonic_rock_type', 'volcanic_rock_type', 'volcanic_rock_types',
		'metamorphic_rock_types', 'metamorphic_rock_type', 'metamorphic_rock_type_less_com',
		'sedimentary_rock_type', 'siliciclastic_type', 'principal_siliciclastic_type', 'siliciclastic_type_1',
		'carbonate_matrix_type',
	);

	/** Coarser keys, used after every specific one. */
	const COARSE_ROCK_KEYS = array('primary_lithology', 'primary_lithology_1', 'sediment_type');

	/** Coarse values that name no material SESAR could use. */
	const NOT_A_ROCK = array('siliciclastic', 'other', 'not_specified', 'igneous', 'metamorphic', 'sedimentary', 'sediment');

	private $db;
	private $spotLoader;

	/**
	 * @param callable|null $spotLoader fn($spotId, $userpkey, $datasetId) ->
	 *        {wkt, pet, sed, image_basemap, strat_section_id, json_tags} | null.
	 *        Default reads Neo4j through $neodb (tests pass a fake).
	 */
	public function __construct($db, $neodb = null, $spotLoader = null)
	{
		$this->db = $db;
		if ($spotLoader !== null) {
			$this->spotLoader = $spotLoader;
		} else {
			$this->spotLoader = function ($spotId, $userpkey, $datasetId) use ($neodb) {
				return SesarSampleView::loadSpot($neodb, $spotId, $userpkey, $datasetId);
			};
		}
	}

	/**
	 * @return array|null the sample view, plus the spine extras callers need:
	 *   userpkey, igsn, parent_sample_id, parent_userpkey
	 */
	public function build($sampleId, $ownerPkey)
	{
		$r = $this->db->get_row_prepared(
			"SELECT id, userpkey, name, igsn, description, latitude, longitude,
			        display_sample_type, display_sample_purpose,
			        parent_sample_id, parent_userpkey, field_data::text AS fd
			   FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
			array((string)$sampleId, (int)$ownerPkey)
		);
		if ($r === null) return null;
		$links = $this->db->get_results_prepared(
			"SELECT subsystem, reference_id, reference_userpkey, reference_metadata::text AS rm
			   FROM strabosamples.sample_subsystem_links
			  WHERE sample_id = $1 AND sample_userpkey = $2 ORDER BY pkey",
			array((string)$sampleId, (int)$ownerPkey)
		);
		$field = null;
		$micro = false;
		foreach ((is_array($links) ? $links : array()) as $l) {
			if ($l->subsystem === 'field' && $field === null) $field = $l;
			if ($l->subsystem === 'micro') $micro = true;
		}
		$fd = ($r->fd !== null && $r->fd !== '') ? json_decode($r->fd, true) : null;

		$view = array(
			'id'                     => (string)$r->id,
			'userpkey'               => (int)$r->userpkey,
			'name'                   => $r->name,
			'igsn'                   => $r->igsn,
			'description'            => $r->description,
			'latitude'               => $r->latitude !== null ? (float)$r->latitude : null,
			'longitude'              => $r->longitude !== null ? (float)$r->longitude : null,
			'display_sample_type'    => $r->display_sample_type,
			'display_sample_purpose' => $r->display_sample_purpose,
			'parent_sample_id'       => $r->parent_sample_id,
			'parent_userpkey'        => $r->parent_userpkey !== null ? (int)$r->parent_userpkey : null,
			'field_data'             => is_array($fd) ? $fd : null,
			'field_linked'           => $field !== null,
			'micro_linked'           => $micro,
			'field_rock_types'       => array(),
		);

		if ($field !== null && ctype_digit((string)$field->reference_id)) {
			$meta = ($field->rm !== null && $field->rm !== '') ? json_decode($field->rm, true) : array();
			$did = (is_array($meta) && isset($meta['dataset_id'])) ? (string)$meta['dataset_id'] : null;
			$spot = call_user_func($this->spotLoader, (string)$field->reference_id, (int)$field->reference_userpkey, $did);
			if (is_array($spot)) self::applySpot($view, $spot);
		}
		return $view;
	}

	/** Location + rock names from the holding spot (pure; unit-tested). */
	public static function applySpot(array &$view, array $spot)
	{
		$pixel = !empty($spot['image_basemap']) || !empty($spot['strat_section_id']);
		$wkt = isset($spot['wkt']) && is_string($spot['wkt']) ? trim($spot['wkt']) : '';
		if (!$pixel && $wkt !== '' && ($view['latitude'] === null || $view['longitude'] === null)
			&& preg_match('/^LINESTRING\s*\((.+)\)\s*$/i', $wkt, $m)) {
			$pts = array();
			foreach (explode(',', $m[1]) as $pair) {
				$xy = preg_split('/\s+/', trim($pair));
				if (count($xy) >= 2 && is_numeric($xy[0]) && is_numeric($xy[1])) $pts[] = array((float)$xy[1], (float)$xy[0]);
			}
			$ok = count($pts) >= 2;
			foreach ($pts as $p) {
				if (abs($p[0]) > 90 || abs($p[1]) > 180) $ok = false;
			}
			if ($ok) {
				$first = $pts[0];
				$last = $pts[count($pts) - 1];
				$view['latitude'] = $first[0];
				$view['longitude'] = $first[1];
				$view['latitude_end'] = $last[0];
				$view['longitude_end'] = $last[1];
				$view['location_from'] = 'line';
			}
		}

		$specific = array();
		$coarse = array();
		$spotId = isset($spot['id']) ? (string)$spot['id'] : null;
		foreach (self::decode(isset($spot['json_tags']) ? $spot['json_tags'] : null) as $tag) {
			if (!is_array($tag) || (isset($tag['type']) ? $tag['type'] : null) !== 'geologic_unit') continue;
			$members = isset($tag['spots']) && is_array($tag['spots']) ? array_map('strval', $tag['spots']) : array();
			if ($spotId === null || !in_array($spotId, $members, true)) continue;
			self::collect($tag, $specific, $coarse);
		}
		foreach (array('pet', 'sed') as $k) {
			$blob = self::decode(isset($spot[$k]) ? $spot[$k] : null);
			if (!empty($blob)) self::collect($blob, $specific, $coarse);
		}
		$names = array();
		foreach (array_merge($specific, $coarse) as $n) {
			$n = trim((string)$n);
			if ($n === '' || in_array(strtolower($n), self::NOT_A_ROCK, true)) continue;
			if (!in_array($n, $names, true)) $names[] = $n;
		}
		$view['field_rock_types'] = $names;
	}

	/** Walks any nesting depth; values may be strings or lists of strings. */
	private static function collect($node, array &$specific, array &$coarse)
	{
		if (!is_array($node)) return;
		foreach ($node as $k => $v) {
			if (is_string($k) && (in_array($k, self::SPECIFIC_ROCK_KEYS, true) || in_array($k, self::COARSE_ROCK_KEYS, true))) {
				$target = in_array($k, self::SPECIFIC_ROCK_KEYS, true) ? 'specific' : 'coarse';
				foreach ((is_array($v) ? $v : array($v)) as $one) {
					if (!is_string($one)) continue;
					if ($target === 'specific') $specific[] = $one; else $coarse[] = $one;
				}
				continue;
			}
			if (is_array($v)) self::collect($v, $specific, $coarse);
		}
	}

	private static function decode($v)
	{
		if (is_array($v)) return $v;
		if (!is_string($v) || $v === '') return array();
		$d = json_decode($v, true);
		return is_array($d) ? $d : array();
	}

	/**
	 * The holding spot, anchored through the owner's User node (Strabo ids
	 * are not unique across the graph). Best-effort, never throws.
	 */
	public static function loadSpot($neodb, $spotId, $userpkey, $datasetId)
	{
		if ($neodb === null || !ctype_digit((string)$spotId)) return null;
		$u = (int)$userpkey;
		$sid = (string)$spotId;
		$dsClause = ($datasetId !== null && ctype_digit((string)$datasetId)) ? " WHERE d.id = " . $datasetId : "";
		$q = "MATCH (u:User {userpkey: $u})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot {id: $sid})"
			. $dsClause
			. " RETURN s.wkt AS wkt, s.pet AS pet, s.sed AS sed, s.image_basemap AS ib, s.strat_section_id AS ss,"
			. " substring(toString(p.json_tags), 0, 2000000) AS tags LIMIT 1";
		try {
			$rows = $neodb->query($q);
			if (empty($rows) && $dsClause !== "") {
				$rows = $neodb->query(str_replace($dsClause, "", $q));   // spot moved to another dataset
			}
		} catch (\Throwable $e) {
			try { $neodb->reconnect(); } catch (\Throwable $e2) { /* next caller reconnects */ }
			return null;
		}
		if (empty($rows)) return null;
		$r = $rows[0];
		$get = function ($k) use ($r) { try { return $r->get($k); } catch (\Throwable $e) { return null; } };
		$pet = $get('pet');
		$sed = $get('sed');
		return array(
			'id'               => $sid,
			'wkt'              => $get('wkt'),
			'pet'              => is_string($pet) ? $pet : (is_array($pet) ? $pet : null),
			'sed'              => is_string($sed) ? $sed : (is_array($sed) ? $sed : null),
			'image_basemap'    => $get('ib'),
			'strat_section_id' => $get('ss'),
			'json_tags'        => $get('tags'),
		);
	}
}
