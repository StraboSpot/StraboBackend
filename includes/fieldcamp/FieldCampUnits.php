<?php
/**
 * File: includes/fieldcamp/FieldCampUnits.php
 * Description: Field Camp Wizard, stage 3: the rock units of a camp's study
 *              area and the geologic unit tags made from them.
 *
 *              lookup() is the one place that asks Macrostrat. It calls the
 *              "legend for a bounding box" route of Macrostrat's API v3
 *              (a prototype in 11-2026, Daven Quinn), which returns the legend
 *              entries its maps draw inside the area. Zoom 10 is where
 *              Macrostrat switches to its most detailed maps, and the route
 *              answers for an area at most eight tiles across, so a larger
 *              area is asked at a lower zoom (and gets a less detailed map).
 *
 *              save() stores the answer with the camp, so the wizard does not
 *              depend on Macrostrat answering twice. A new lookup replaces
 *              the Macrostrat columns and keeps what the instructor set
 *              (chosen, tag name, unit label) and each unit's tag id.
 *
 *              tagsForCamp() turns the chosen units into project tags of type
 *              geologic_unit, with the keys and choice values of the app's
 *              form (StraboField src/assets/forms/geologic_unit.json).
 *
 *              Macrostrat data is CC-BY 4.0; each tag's notes carry the
 *              source map citation.
 *
 *              Tables: sql/fieldcamp.sql. Design: docs/Field_Camp_Builder/DESIGN.md.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class FieldCampUnits
{
	const LEGEND_URL = 'https://macrostrat.org/api/v3/map/carto/legend';
	const SOURCE_URL = 'https://macrostrat.org/api/v3/sources/';

	/** The zoom at which Macrostrat draws its most detailed ("large" scale) maps. */
	const DETAIL_ZOOM = 10;

	/** The legend route refuses an area more than this many tiles across at the zoom asked. */
	const TILES_ACROSS = 8;

	const MAX_NAME = 120;
	const MAX_LABEL = 20;

	/**
	 * Tests replace the HTTP call: a callable taking the URL and returning
	 * array(http code, body). Null = the real request.
	 */
	public static $fetcher = null;

	private static $tablesChecked = null;

	/** True once the stage 3 part of sql/fieldcamp.sql has been applied. */
	public static function installed($db)
	{
		if (self::$tablesChecked === null) {
			$found = $db->get_var_prepared("select to_regclass('public.fieldcamp_unit') is not null", array());
			self::$tablesChecked = ($found === true || $found === 't');
		}
		return self::$tablesChecked;
	}

	// -------------------------------------------------------------------
	// Macrostrat
	// -------------------------------------------------------------------

	/** A camp's saved study area as west/south/east/north floats, or null. */
	public static function campBbox($camp)
	{
		if (!$camp || $camp->bbox_west === null || $camp->bbox_west === '') return null;
		return array(
			'west' => (float)$camp->bbox_west, 'south' => (float)$camp->bbox_south,
			'east' => (float)$camp->bbox_east, 'north' => (float)$camp->bbox_north,
		);
	}

	/** The form the bounds take in the request and in fieldcamp.units_bbox. */
	public static function bboxString($bbox)
	{
		return rtrim(rtrim(sprintf('%.6F', $bbox['west']), '0'), '.') . ','
			. rtrim(rtrim(sprintf('%.6F', $bbox['south']), '0'), '.') . ','
			. rtrim(rtrim(sprintf('%.6F', $bbox['east']), '0'), '.') . ','
			. rtrim(rtrim(sprintf('%.6F', $bbox['north']), '0'), '.');
	}

	/**
	 * The most detailed zoom the legend route answers for this area: zoom 10
	 * when the area is at most eight zoom-10 tiles across (about 2.8 degrees),
	 * else the highest zoom at which it fits.
	 */
	public static function zoomFor($bbox)
	{
		$span = max($bbox['east'] - $bbox['west'], $bbox['north'] - $bbox['south']);
		$zoom = self::DETAIL_ZOOM;
		while ($zoom > 0 && $span > self::TILES_ACROSS * 360 / pow(2, $zoom)) $zoom--;
		return $zoom;
	}

	/**
	 * The rock units Macrostrat's maps show inside an area. This is the only
	 * function that knows how the units are found.
	 *
	 * @param array $bbox west/south/east/north, WGS84 degrees
	 * @return array ['error' => string, 'zoom' => int, 'detailed' => bool, 'bbox' => string,
	 *                'units' => [unit arrays in map order, youngest first], 'sources' => [source_id => source array]]
	 */
	public static function lookup($bbox)
	{
		$zoom = self::zoomFor($bbox);
		$out = array('error' => '', 'zoom' => $zoom, 'detailed' => ($zoom >= self::DETAIL_ZOOM),
			'bbox' => self::bboxString($bbox), 'units' => array(), 'sources' => array());

		$url = self::LEGEND_URL . '?bounds=' . rawurlencode($out['bbox']) . '&zoom=' . $zoom;
		list($code, $body) = self::httpGet($url);
		$data = json_decode((string)$body, true);

		if ($code !== 200 || !is_array($data) || ($data && !array_is_list($data))) {
			$detail = (is_array($data) && isset($data['detail']) && is_string($data['detail'])) ? $data['detail'] : '';
			error_log("FieldCampUnits::lookup $url answered $code " . substr((string)$body, 0, 200));
			$out['error'] = ($code === 400 && $detail !== '')
				? "Macrostrat could not answer for this study area: " . $detail
				: "Macrostrat did not answer. Please try again in a few minutes.";
			return $out;
		}

		$seen = array();
		foreach ($data as $row) {
			$unit = self::cleanRow($row);
			if ($unit === null) continue;
			// A map can list one unit several times (one entry per map label). Entries that read
			// the same in every column the instructor sees are one unit here.
			$same = $unit['source_id'] . '|' . mb_strtolower($unit['map_unit_name']) . '|' . $unit['age'] . '|'
				. $unit['lith'] . '|' . $unit['descrip'];
			if (isset($seen[$same])) continue;
			$seen[$same] = true;
			$out['units'][] = $unit;
		}

		foreach ($out['units'] as $unit) {
			$sid = $unit['source_id'];
			if (!isset($out['sources'][$sid])) $out['sources'][$sid] = self::lookupSource($sid);
		}
		return $out;
	}

	/** Name and citation of one source map. A failure here never fails the lookup. */
	private static function lookupSource($sourceId)
	{
		$source = array('source_id' => (int)$sourceId, 'name' => "Macrostrat source map " . (int)$sourceId,
			'ref_title' => '', 'url' => '', 'scale' => '');
		list($code, $body) = self::httpGet(self::SOURCE_URL . (int)$sourceId);
		$data = json_decode((string)$body, true);
		if ($code === 200 && is_array($data)) {
			foreach (array('name', 'ref_title', 'url', 'scale') as $k) {
				if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') $source[$k] = trim($data[$k]);
			}
		}
		return $source;
	}

	/** One legend row in our own shape, or null when it cannot be a unit (no id or no name). */
	private static function cleanRow($row)
	{
		if (!is_array($row)) return null;
		$legendId = (int)($row['legend_id'] ?? 0);
		$sourceId = (int)($row['source_id'] ?? 0);
		$name = self::text($row['map_unit_name'] ?? '');
		if ($name === '') $name = self::text($row['strat_name'] ?? '');
		if ($legendId <= 0 || $sourceId <= 0 || $name === '') return null;

		$color = strtolower(trim((string)($row['color'] ?? '')));
		if (preg_match('/^#?([0-9a-f]{6})$/', $color, $m)) $color = '#' . $m[1];
		elseif (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m)) $color = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		else $color = '';

		return array(
			'source_id' => $sourceId,
			'legend_id' => $legendId,
			'map_unit_name' => mb_substr($name, 0, 500),
			'strat_name' => self::text($row['strat_name'] ?? ''),
			'age' => self::text($row['age'] ?? ''),
			'lith' => self::text($row['lith'] ?? ''),
			'descrip' => self::text($row['descrip'] ?? ''),
			'comments' => self::text($row['comments'] ?? ''),
			't_age' => is_numeric($row['t_age'] ?? null) ? (float)$row['t_age'] : null,
			'b_age' => is_numeric($row['b_age'] ?? null) ? (float)$row['b_age'] : null,
			'lith_classes' => self::wordList($row['lith_classes'] ?? null),
			'lith_types' => self::wordList($row['lith_types'] ?? null),
			'color' => $color,
		);
	}

	private static function httpGet($url)
	{
		if (self::$fetcher !== null) return call_user_func(self::$fetcher, $url);
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 45,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERAGENT => 'StraboSpot',
			CURLOPT_HTTPHEADER => array('Accept: application/json'),
		));
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($body === false) { error_log("FieldCampUnits: $url: " . curl_error($ch)); $body = ''; $code = 0; }
		return array($code, (string)$body);
	}

	// -------------------------------------------------------------------
	// Saved units
	// -------------------------------------------------------------------

	/**
	 * Store a lookup with the camp. Units already saved keep the instructor's
	 * choices and their tag id; units the new lookup no longer returns are removed.
	 *
	 * @return array ['error' => string, 'total' => int, 'added' => int, 'removed' => int]
	 */
	public static function save($db, $camp, $lookup)
	{
		$out = array('error' => '', 'total' => 0, 'added' => 0, 'removed' => 0);
		$camppkey = (int)$camp->pkey;

		$existing = array();
		$usedNames = array(); $usedLabels = array();
		foreach (self::listUnits($db, $camppkey) as $u) {
			$existing[(int)$u->legend_id] = $u;
		}
		$incoming = array();
		foreach ($lookup['units'] as $unit) $incoming[$unit['legend_id']] = true;
		foreach ($existing as $legendId => $u) {
			if (!isset($incoming[$legendId])) continue;
			$usedNames[mb_strtolower($u->tag_name)] = true;
			$usedLabels[mb_strtolower($u->unit_label)] = true;
		}

		// How often each name comes up, to know which new names need their age to tell them apart.
		$nameCount = array();
		foreach ($lookup['units'] as $unit) {
			$k = mb_strtolower($unit['map_unit_name']);
			$nameCount[$k] = ($nameCount[$k] ?? 0) + 1;
		}

		$db->query("BEGIN");
		try {
			$order = 0;
			foreach ($lookup['units'] as $unit) {
				$order++;
				$cols = array($unit['source_id'], $unit['map_unit_name'], self::nullIfEmpty($unit['strat_name']),
					self::nullIfEmpty($unit['age']), self::nullIfEmpty($unit['lith']), self::nullIfEmpty($unit['descrip']),
					self::nullIfEmpty($unit['comments']), $unit['t_age'], $unit['b_age'],
					self::nullIfEmpty($unit['lith_classes']), self::nullIfEmpty($unit['lith_types']),
					self::nullIfEmpty($unit['color']), $order);

				if (isset($existing[$unit['legend_id']])) {
					$db->prepare_query("
						update fieldcamp_unit set source_id = $2, map_unit_name = $3, strat_name = $4, age = $5, lith = $6,
							descrip = $7, comments = $8, t_age = $9, b_age = $10, lith_classes = $11, lith_types = $12,
							color = $13, sort_order = $14
						where pkey = $1
						", array_merge(array((int)$existing[$unit['legend_id']]->pkey), $cols));
					continue;
				}

				$name = $unit['map_unit_name'];
				if ($nameCount[mb_strtolower($name)] > 1 && $unit['age'] !== '') $name .= ' (' . $unit['age'] . ')';
				$name = self::unique(mb_substr($name, 0, self::MAX_NAME - 4), $usedNames, ' ');
				$label = self::unique(self::suggestLabel($unit), $usedLabels, '');
				$chosen = preg_match('/^(water|ice|lake|lakes|glacier|glaciers)$/i', $unit['map_unit_name']) ? 'f' : 't';

				$db->prepare_query("
					insert into fieldcamp_unit (fieldcamp_pkey, legend_id, source_id, map_unit_name, strat_name, age, lith,
						descrip, comments, t_age, b_age, lith_classes, lith_types, color, sort_order,
						chosen, tag_name, unit_label, tag_id)
					values ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19)
					", array_merge(array($camppkey, $unit['legend_id']), $cols, array($chosen, $name, $label, self::newTagId())));
				$out['added']++;
			}

			foreach ($existing as $legendId => $u) {
				if (isset($incoming[$legendId])) continue;
				$db->prepare_query("delete from fieldcamp_unit where pkey = $1", array((int)$u->pkey));
				$out['removed']++;
			}

			$db->prepare_query("delete from fieldcamp_unit_source where fieldcamp_pkey = $1", array($camppkey));
			foreach ($lookup['sources'] as $s) {
				$db->prepare_query("
					insert into fieldcamp_unit_source (fieldcamp_pkey, source_id, name, ref_title, url, scale)
					values ($1, $2, $3, $4, $5, $6)
					", array($camppkey, (int)$s['source_id'], self::nullIfEmpty($s['name']), self::nullIfEmpty($s['ref_title']),
						self::nullIfEmpty($s['url']), self::nullIfEmpty(mb_substr((string)$s['scale'], 0, 40))));
			}

			$db->prepare_query("
				update fieldcamp set units_fetched_date = now(), units_zoom = $2, units_bbox = $3, modified_date = now()
				where pkey = $1
				", array($camppkey, (int)$lookup['zoom'], (string)$lookup['bbox']));

			// The database wrapper reports a failed statement in the log only, so count what landed.
			$have = (int)$db->get_var_prepared("select count(*) from fieldcamp_unit where fieldcamp_pkey = $1", array($camppkey));
			if ($have !== count($lookup['units'])) {
				throw new Exception("expected " . count($lookup['units']) . " units, table holds $have");
			}
			$db->query("COMMIT");
			$out['total'] = $have;
		} catch (\Throwable $e) {
			$db->query("ROLLBACK");
			error_log("FieldCampUnits::save camp $camppkey: " . $e->getMessage());
			$out['added'] = 0; $out['removed'] = 0;
			$out['error'] = "The rock units could not be saved. Nothing was changed; please try again.";
		}
		return $out;
	}

	/** A camp's units in map order (youngest first). */
	public static function listUnits($db, $camppkey, $chosenOnly = false)
	{
		$extra = $chosenOnly ? "and chosen = true" : "";
		$rows = $db->get_results_prepared("
			select * from fieldcamp_unit where fieldcamp_pkey = $1 $extra order by sort_order, pkey
			", array((int)$camppkey));
		return $rows ? $rows : array();
	}

	/** The source maps of a camp's units, keyed by source id. */
	public static function listSources($db, $camppkey)
	{
		$rows = $db->get_results_prepared(
			"select * from fieldcamp_unit_source where fieldcamp_pkey = $1 order by source_id", array((int)$camppkey));
		$out = array();
		if ($rows) foreach ($rows as $r) $out[(int)$r->source_id] = $r;
		return $out;
	}

	/** Has the study area changed since the units were looked up? */
	public static function areaChanged($camp)
	{
		$bbox = self::campBbox($camp);
		if ($bbox === null || !isset($camp->units_bbox) || (string)$camp->units_bbox === '') return false;
		return self::bboxString($bbox) !== (string)$camp->units_bbox;
	}

	/**
	 * Save the Rock units step: which units become tags, and each one's tag
	 * name and unit label. All or nothing.
	 *
	 * @param array $rows [['pkey' => int, 'chosen' => bool, 'name' => string, 'label' => string], ...]
	 * @return array list of problems (empty = saved)
	 */
	public static function saveSelection($db, $camp, $rows)
	{
		$units = array();
		foreach (self::listUnits($db, $camp->pkey) as $u) $units[(int)$u->pkey] = $u;

		$errors = array(); $clean = array(); $names = array();
		foreach ((array)$rows as $row) {
			if (!is_array($row)) continue;
			$pkey = (int)($row['pkey'] ?? 0);
			if (!isset($units[$pkey]) || isset($clean[$pkey])) continue;   // not this camp's unit
			$chosen = !empty($row['chosen']);
			$name = self::text($row['name'] ?? '');
			$label = self::text($row['label'] ?? '');
			$was = $units[$pkey]->map_unit_name;

			if ($name === '') {
				if ($chosen) $errors[] = "\"$was\" needs a tag name.";
				else $name = $units[$pkey]->tag_name;
			} elseif (mb_strlen($name) > self::MAX_NAME) {
				$errors[] = "The tag name of \"$was\" is too long (" . self::MAX_NAME . " characters at most).";
			}
			if ($label === '') {
				if ($chosen) $errors[] = "\"$was\" needs a unit label (the short code on the map, for example Kd).";
				else $label = $units[$pkey]->unit_label;
			} elseif (mb_strlen($label) > self::MAX_LABEL) {
				$errors[] = "The unit label of \"$was\" is too long (" . self::MAX_LABEL . " characters at most).";
			}
			if ($chosen && $name !== '') {
				$k = mb_strtolower($name);
				if (isset($names[$k])) $errors[] = "Two selected units are both named \"$name\". Give each its own name.";
				$names[$k] = true;
			}
			$clean[$pkey] = array($chosen, $name, $label);
		}
		if ($errors) return array_values(array_unique($errors));

		$db->query("BEGIN");
		foreach ($clean as $pkey => $c) {
			$db->prepare_query("
				update fieldcamp_unit set chosen = $2, tag_name = $3, unit_label = $4
				where pkey = $1 and fieldcamp_pkey = $5
				", array($pkey, $c[0] ? 't' : 'f', $c[1], $c[2], (int)$camp->pkey));
		}
		$db->prepare_query("update fieldcamp set modified_date = now() where pkey = $1", array((int)$camp->pkey));
		$db->query("COMMIT");
		return array();
	}

	// -------------------------------------------------------------------
	// Tags
	// -------------------------------------------------------------------

	/** The chosen units of a camp as project tags, in map order. */
	public static function tagsForCamp($db, $camppkey)
	{
		$sources = self::listSources($db, $camppkey);
		$tags = array();
		foreach (self::listUnits($db, $camppkey, true) as $u) {
			$tags[] = self::buildTag($u, $sources[(int)$u->source_id] ?? null);
		}
		return $tags;
	}

	/**
	 * One unit as a tag the way the app writes one (checked against a backup
	 * made by StraboField 2.32.0): a UUID id, created and modified timestamps
	 * in milliseconds, name, type, color and the fields of its geologic_unit
	 * form. Only values the form offers are set; anything Macrostrat says that
	 * has no field goes into the notes.
	 *
	 * @param object $u      fieldcamp_unit row
	 * @param object|null $source fieldcamp_unit_source row
	 */
	public static function buildTag($u, $source = null)
	{
		$made = isset($u->created_date) ? strtotime((string)$u->created_date) : false;
		$stamp = ($made ? $made : time()) * 1000;
		$tag = array(
			'id' => (string)$u->tag_id,
			'created_timestamp' => $stamp,
			'modified_timestamp' => $stamp,
			'name' => (string)$u->tag_name,
			'type' => 'geologic_unit',
			'unit_label_abbreviation' => (string)$u->unit_label,
			'map_unit_name' => (string)$u->map_unit_name,
		);
		if ((string)$u->color !== '') $tag['color'] = (string)$u->color;

		$tag += self::rockFields($u);
		$tag += self::ageFields($u->t_age, $u->b_age);

		if ((string)$u->descrip !== '') $tag['description'] = (string)$u->descrip;

		$notes = array();
		if ((string)$u->age !== '') $notes[] = "Age: " . $u->age . ".";
		if ((string)$u->lith !== '') $notes[] = "Lithology: " . $u->lith . ".";
		if ((string)$u->strat_name !== '' && mb_strtolower($u->strat_name) !== mb_strtolower($u->map_unit_name)) {
			$notes[] = "Stratigraphic name: " . $u->strat_name . ".";
		}
		if ((string)$u->comments !== '') $notes[] = (string)$u->comments;
		$cite = $source ? trim((string)($source->ref_title ?: $source->name)) : '';
		$notes[] = "Source: " . ($cite !== '' ? rtrim($cite, '.') . ", " : '')
			. "from Macrostrat (macrostrat.org, CC-BY 4.0), source map " . (int)$u->source_id
			. ", legend entry " . (int)$u->legend_id . ".";
		$tag['notes'] = implode("\n", $notes);

		return $tag;
	}

	/** Words that mean loose sediment, in a unit name or a lithology list. */
	private static $sedimentWords = array('alluvium', 'alluvial', 'colluvium', 'eolian', 'talus', 'gravel', 'sand', 'silt',
		'clay', 'mud', 'moraine', 'till', 'loess', 'drift', 'sediment', 'sediments', 'peat', 'unconsolidated',
		'landslide', 'outwash', 'dune', 'dunes', 'terrace', 'fan');

	/** Form choice lists: the word Macrostrat (or a unit name) uses => the form's value. */
	private static $choices = array(
		'sediment_type' => array('alluvium' => 'alluvium', 'colluvium' => 'colluvium', 'eolian' => 'eolian',
			'talus' => 'talus', 'gravel' => 'gravel', 'sand' => 'sand', 'silt' => 'silt', 'clay' => 'clay',
			'moraine' => 'moraine', 'till' => 'till', 'loess' => 'loess'),
		'sedimentary_rock_type' => array('sandstone' => 'sandstone', 'shale' => 'shale', 'siltstone' => 'siltstone',
			'mudstone' => 'mudstone', 'claystone' => 'claystone', 'conglomerate' => 'conglomerate',
			'limestone' => 'limestone', 'dolostone' => 'dolostone', 'dolomite' => 'dolostone', 'chert' => 'chert',
			'travertine' => 'travertine', 'evaporite' => 'evaporite', 'coal' => 'coal'),
		'volcanic_rock_type' => array('basalt' => 'basalt', 'andesite' => 'andesite', 'latite' => 'latite',
			'dacite' => 'dacite', 'rhyolite' => 'rhyolite', 'tuff' => 'tuff', 'trachyte' => 'trachyte',
			'trachyandesite' => 'trachyandesite', 'vitrophyre' => 'vitrophyre'),
		'plutonic_rock_types' => array('granite' => 'granite', 'syenite' => 'syenite', 'granodiorite' => 'granodiorite',
			'monzonite' => 'monzonite', 'tonalite' => 'tonalite', 'diorite' => 'diorite', 'gabbro' => 'gabbro',
			'anorthosite' => 'anorthosite'),
		'metamorphic_rock_types' => array('slate' => 'slate', 'phyllite' => 'phyllite', 'schist' => 'schist',
			'gneiss' => 'gneiss', 'migmatite' => 'migmatite', 'marble' => 'marble', 'greenschist' => 'greenschist',
			'amphibolite' => 'amphibolite', 'blueschist' => 'blueschist', 'eclogite' => 'eclogite',
			'hornfels' => 'hornfels', 'skarn' => 'skarn', 'quartzite' => 'quartzite', 'serpentinite' => 'serpentinite',
			'mylonite' => 'mylonite', 'cataclasite' => 'cataclasite', 'metavolcanic' => 'metavolcanic'),
	);

	/** The form's Unit Type group, as far as Macrostrat's lithology says it plainly. */
	private static function rockFields($u)
	{
		$out = array();
		$classes = self::splitList($u->lith_classes);
		$types = self::splitList($u->lith_types);
		$nameWords = preg_split('/[^a-z]+/', mb_strtolower((string)$u->map_unit_name), -1, PREG_SPLIT_NO_EMPTY);
		// "minor coal" is not what the unit is made of.
		$lith = array();
		foreach (self::splitList($u->lith) as $term) {
			if (strpos($term, 'minor ') === 0) continue;
			$lith[] = $term;
		}

		$quaternary = ($u->b_age !== null && $u->b_age !== '' && (float)$u->b_age <= 2.6);
		$nameIsSediment = (bool)array_intersect($nameWords, self::$sedimentWords);
		$lithIsSediment = $lith && !array_diff($lith, self::$sedimentWords);

		if (($quaternary && $nameIsSediment) || ($classes === array('sedimentary') && $lithIsSediment)) {
			$rock = 'sediment';
		} elseif (count($classes) === 1 && in_array($classes[0], array('sedimentary', 'igneous', 'metamorphic'), true)) {
			$rock = $classes[0];
		} else {
			return $out;   // mixed or unknown: leave it for the instructor
		}
		$out['rock_type'] = $rock;

		if ($rock === 'sediment') {
			$v = self::pickOne('sediment_type', $nameWords, $lith);
			if ($v !== null) $out['sediment_type'] = $v;
		} elseif ($rock === 'sedimentary') {
			$v = self::pickOne('sedimentary_rock_type', $nameWords, $lith);
			if ($v !== null) $out['sedimentary_rock_type'] = $v;
		} elseif ($rock === 'igneous') {
			$class = null;
			if (in_array('volcanic', $types, true) && !in_array('plutonic', $types, true)) $class = 'volcanic';
			if (in_array('plutonic', $types, true) && !in_array('volcanic', $types, true)) $class = 'plutonic';
			if ($class !== null) {
				$out['igneous_rock_class'] = $class;
				$field = $class === 'volcanic' ? 'volcanic_rock_type' : 'plutonic_rock_types';
				$v = self::pickOne($field, $nameWords, $lith);
				if ($v !== null) $out[$field] = $v;
			}
		} elseif ($rock === 'metamorphic') {
			$v = self::pickOne('metamorphic_rock_types', $nameWords, $lith);
			if ($v !== null) $out['metamorphic_rock_types'] = $v;
		}
		return $out;
	}

	/** The one choice a unit points at: by its name first, else by its lithology. Two candidates = no answer. */
	private static function pickOne($field, $nameWords, $lith)
	{
		foreach (array($nameWords, $lith) as $words) {
			$found = array();
			foreach ($words as $w) {
				if (isset(self::$choices[$field][$w])) $found[self::$choices[$field][$w]] = true;
			}
			if (count($found) === 1) return key($found);
			if (count($found) > 1) return null;
		}
		return null;
	}

	/**
	 * The geologic time scale down to the level this code fills in, with
	 * Macrostrat's own boundary ages (its "international" time scales, read
	 * 11-2026). Each entry: form field, form value, top Ma, bottom Ma, parent
	 * (field:value) or null. The parent is what the form needs selected for
	 * the field to show.
	 */
	private static $timescale = array(
		array('eon', 'phanerozoic', 0, 538.8, null),
		array('eon', 'proterozoic', 538.8, 2500, null),
		array('eon', 'archean', 2500, 4031, null),
		array('eon', 'hadean', 4031, 4600, null),
		array('era_phanerozoic', 'cenozoic', 0, 66, 'eon:phanerozoic'),
		array('era_phanerozoic', 'mesozoic', 66, 251.902, 'eon:phanerozoic'),
		array('era_phanerozoic', 'paleozoic', 251.902, 538.8, 'eon:phanerozoic'),
		array('period_cenozoic', 'quaternary', 0, 2.58, 'era_phanerozoic:cenozoic'),
		array('period_cenozoic', 'neogene', 2.58, 23.04, 'era_phanerozoic:cenozoic'),
		array('period_cenozoic', 'paleogene', 23.04, 66, 'era_phanerozoic:cenozoic'),
		array('epoch_quaternary', 'holocene', 0, 0.0117, 'period_cenozoic:quaternary'),
		array('epoch_quaternary', 'pleistocene', 0.0117, 2.58, 'period_cenozoic:quaternary'),
		array('epoch_neogene', 'pliocene', 2.58, 5.333, 'period_cenozoic:neogene'),
		array('epoch_neogene', 'miocene', 5.333, 23.04, 'period_cenozoic:neogene'),
		array('epoch_paleogene', 'oligocene', 23.04, 33.9, 'period_cenozoic:paleogene'),
		array('epoch_paleogene', 'eocene', 33.9, 56, 'period_cenozoic:paleogene'),
		array('epoch_paleogene', 'paleocene', 56, 66, 'period_cenozoic:paleogene'),
		array('period_mesozoic', 'cretaceous', 66, 143.1, 'era_phanerozoic:mesozoic'),
		array('period_mesozoic', 'jurassic', 143.1, 201.4, 'era_phanerozoic:mesozoic'),
		array('period_mesozoic', 'triassic', 201.4, 251.902, 'era_phanerozoic:mesozoic'),
		array('period_paleozoic', 'permian', 251.902, 298.9, 'era_phanerozoic:paleozoic'),
		array('period_paleozoic', 'carboniferous', 298.9, 358.86, 'era_phanerozoic:paleozoic'),
		array('period_paleozoic', 'pennsylvanian', 298.9, 323.4, 'era_phanerozoic:paleozoic'),
		array('period_paleozoic', 'mississippian', 323.4, 358.86, 'era_phanerozoic:paleozoic'),
		array('period_paleozoic', 'devonian', 358.86, 419.62, 'era_phanerozoic:paleozoic'),
		array('period_paleozoic', 'silurian', 419.62, 443.1, 'era_phanerozoic:paleozoic'),
		array('period_paleozoic', 'ordovician', 443.1, 486.85, 'era_phanerozoic:paleozoic'),
		array('period_paleozoic', 'cambrian', 486.85, 538.8, 'era_phanerozoic:paleozoic'),
		// The form's values for these two are cut short; they are spelled as the form spells them.
		array('era_proterozoic', 'neoproterozoic', 538.8, 1000, 'eon:proterozoic'),
		array('era_proterozoic', 'mesoproterozoi', 1000, 1600, 'eon:proterozoic'),
		array('era_proterozoic', 'paleoproterozo', 1600, 2500, 'eon:proterozoic'),
		array('era_archean', 'neoarchean', 2500, 2800, 'eon:archean'),
		array('era_archean', 'mesoarchean', 2800, 3200, 'eon:archean'),
		array('era_archean', 'paleoarchean', 3200, 3600, 'eon:archean'),
		array('era_archean', 'eoarchean', 3600, 4031, 'eon:archean'),
	);

	/**
	 * The form's Geologic Age group from a unit's top and bottom ages (Ma).
	 * An interval counts when the unit covers at least half of it, or when it
	 * holds more than 5% of the unit's own span. The second rule keeps a
	 * boundary that moved a little between time scale editions (a "Cambrian"
	 * unit dated with an older Cambrian base) from adding the neighbour.
	 */
	public static function ageFields($t, $b)
	{
		$out = array();
		if ($t === null || $b === null || $t === '' || $b === '') return $out;
		$t = (float)$t; $b = (float)$b;
		if ($b < $t) { $x = $t; $t = $b; $b = $x; }
		if ($b <= 0 && $t <= 0) return $out;
		$span = $b - $t;

		$picked = array();
		foreach (self::$timescale as $e) {
			list($field, $value, $top, $bottom) = $e;
			if ($span <= 0) {
				$in = ($t > $top && $t <= $bottom) || ($t == 0 && $top == 0);
			} else {
				$overlap = min($b, $bottom) - max($t, $top);
				$in = $overlap > 0 && ($overlap >= 0.5 * ($bottom - $top) || $overlap > 0.05 * $span);
			}
			if ($in) $picked["$field:$value"] = true;
		}
		// The form shows a field only when its parent is selected.
		$parents = array();
		foreach (self::$timescale as $e) $parents[$e[0] . ':' . $e[1]] = $e[4];
		foreach (array_keys($picked) as $key) {
			for ($p = $parents[$key]; $p !== null; $p = $parents[$p]) $picked[$p] = true;
		}
		foreach (self::$timescale as $e) {
			if (isset($picked[$e[0] . ':' . $e[1]])) $out[$e[0]][] = $e[1];
		}

		$out['min_absolute_age_of_geol_unit'] = $t;
		$out['max_absolute_age_of_geol_unit'] = $b;
		return $out;
	}

	// -------------------------------------------------------------------
	// Names, labels, ids
	// -------------------------------------------------------------------

	/** Map letters for the periods a unit label starts with, youngest first: [letter, top Ma, bottom Ma]. */
	private static $ageLetters = array(
		array('Q', 0, 2.58), array('T', 2.58, 66), array('K', 66, 143.1), array('J', 143.1, 201.4),
		array('Tr', 201.4, 251.902), array('P', 251.902, 298.9), array('IP', 298.9, 323.4), array('M', 323.4, 358.86),
		array('D', 358.86, 419.62), array('S', 419.62, 443.1), array('O', 443.1, 486.85), array('C', 486.85, 538.8),
		array('pC', 538.8, 4600),
	);

	/**
	 * A first guess at the short map label of a unit, the way geologic maps
	 * build one: the age letter, then lower-case initials of the name
	 * ("Hell Creek Formation", Late Cretaceous = Khc; "Alluvium" = Qal).
	 * The instructor can change it.
	 */
	public static function suggestLabel($unit)
	{
		$letters = '';
		$t = $unit['t_age']; $b = $unit['b_age'];
		if ($t !== null && $b !== null) {
			$first = null; $last = null;
			$mid = ($t + $b) / 2;
			foreach (self::$ageLetters as $a) {
				$overlap = min($b, $a[2]) - max($t, $a[1]);
				$in = ($b > $t) ? ($overlap > 0.05 * ($b - $t)) : ($mid >= $a[1] && $mid <= $a[2]);
				if (!$in) continue;
				if ($first === null) $first = $a[0];
				$last = $a[0];
			}
			if ($first !== null) $letters = ($last !== $first) ? $first . $last : $first;
		}

		$skip = array('formation', 'member', 'group', 'supergroup', 'subgroup', 'unit', 'units', 'of', 'and', 'the',
			'in', 'on', 'near', 'undifferentiated', 'undivided', 'deposits', 'rocks');
		$words = array();
		foreach (preg_split('/[^a-z]+/', mb_strtolower($unit['map_unit_name']), -1, PREG_SPLIT_NO_EMPTY) as $w) {
			if (!in_array($w, $skip, true)) $words[] = $w;
		}
		if (!$words) $initials = substr(preg_replace('/[^a-z]/', '', mb_strtolower($unit['map_unit_name'])), 0, 2);
		elseif (count($words) === 1) $initials = substr($words[0], 0, 2);
		else { $initials = ''; foreach (array_slice($words, 0, 3) as $w) $initials .= $w[0]; }

		$label = $letters . $initials;
		return $label === '' ? 'u' : $label;
	}

	/** $base, or $base with 2, 3, ... after it, whichever is not in $used yet (and is then added to it). */
	private static function unique($base, &$used, $gap)
	{
		$candidate = $base;
		for ($n = 2; isset($used[mb_strtolower($candidate)]); $n++) $candidate = $base . $gap . $n;
		$used[mb_strtolower($candidate)] = true;
		return $candidate;
	}

	/** A new tag id the way the app makes one (getNewUUID: a random version 4 UUID). */
	private static function newTagId()
	{
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	private static function text($v)
	{
		return is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
	}

	/** A JSON list of words (or null) as "a, b". */
	private static function wordList($v)
	{
		if (!is_array($v)) return '';
		$out = array();
		foreach ($v as $w) { if (is_string($w) && trim($w) !== '') $out[] = trim($w); }
		return implode(', ', $out);
	}

	/** "a, b" as array('a', 'b'), lower case. */
	private static function splitList($s)
	{
		$out = array();
		foreach (explode(',', mb_strtolower((string)$s)) as $w) { if (trim($w) !== '') $out[] = trim($w); }
		return $out;
	}

	private static function nullIfEmpty($v)
	{
		return ($v === null || $v === '') ? null : $v;
	}
}
