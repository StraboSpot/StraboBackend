<?php
/**
 * File: includes/fieldvocab/FieldVocab.php
 * Description: Output-time translation of stored StraboField choice NAMES
 *              (option_13, sedimentary_fe) to the LABELS the app's forms show
 *              ("joint", "sedimentary - Fe"). Stored data never changes: call
 *              this only in the final output step of a display or export
 *              surface, never in the shared spot getters (getDatasetSpots,
 *              getProject, getFeatureCollection, singleSpotJSON*), whose
 *              output also feeds version snapshots that are restored into
 *              Neo4j. See docs/edine_bug/TRANSLATION_SURFACE_AUDIT.md.
 *
 *              The map comes from the app's own forms (FieldVocabBuilder):
 *                1. fieldvocab_data/field_vocab_map.json (gitignored, kept
 *                   current by includes/fieldvocab/sync.php, nightly), else
 *                2. includes/fieldvocab/field_vocab_baseline.json (in the
 *                   repo, so a fresh checkout translates before its first sync).
 *              FIELDVOCAB_DATA_DIR overrides the data folder (tests).
 *
 *              Lookups are keyed by (form, field), never by name alone: the
 *              same name has different labels in different forms and even in
 *              different fields of one form. formsFor() picks the form(s)
 *              from where a value sits in the spot, mirroring the app.
 *
 *              Usage:
 *                $forms = FieldVocab::formsFor('orientation', $o);   // ['measurement.planar_orientation']
 *                FieldVocab::label($forms, 'feature_type', 'option_13');        // "joint"
 *                FieldVocab::label($forms, 'feature_type', array('a', 'b'));    // array of labels
 *                FieldVocab::labelText($forms, 'movement', $v);                 // "a, b" (app join)
 *                FieldVocab::label($forms, 'x', 'no_such', 'app');              // "no such" (app fallback)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class FieldVocab
{
	const MAP_FILE = 'field_vocab_map.json';

	/** @var array|null loaded map */
	private static $map = null;
	/** @var string where the loaded map came from: data | baseline | injected | none */
	private static $origin = 'none';

	/**
	 * Families whose form does not depend on the object: family => form keys.
	 * Several keys = one stored object filled from several forms (tabs); a
	 * name translates only when every form that knows it agrees on the label.
	 */
	private static $FIXED = array(
		'pet.metamorphic'     => array('pet.metamorphic'),
		'pet.alteration_or'   => array('pet.alteration_or'),
		'pet.fault'           => array('pet.fault'),
		'pet.minerals'        => array('pet.minerals'),
		'pet.reactions'       => array('pet.reactions'),
		'pet'                 => array('pet_deprecated.igneous', 'pet_deprecated.metamorphic', 'pet_deprecated.alteration_or'),   // flat legacy properties.pet
		'sed'                 => array('sed.interval'),                                                                        // properties.sed.character
		'sed.lithologies'     => array('sed.lithology', 'sed.composition', 'sed.texture', 'sed.stratification'),
		'sed.structures'      => array('sed.physical', 'sed.bedding_plane', 'sed.bioturbation', 'sed.pedogenic'),
		'sed.interpretations' => array('sed.process', 'sed.environment', 'sed.surfaces', 'sed.architecture'),
		'sed.diagenesis'      => array('sed.diagenesis'),
		'sed.fossils'         => array('sed.fossils'),
		'sed.interval'        => array('sed.interval'),
		'sed.strat_section'   => array('sed.strat_section'),
		'sed.bedding.beds'    => array('sed.bedding'),
		'tephra'              => array('tephra.basic', 'tephra.additional'),
		'samples'             => array('general.samples'),
		'images'              => array('general.images'),
		'earthquakes'         => array('general.earthquakes'),
		'outcrop_summaries'   => array('general.outcrop_summaries'),
		'site_safety'         => array('general.site_safety'),
		'trace'               => array('general.trace'),
		'surface_feature'     => array('general.surface_feature'),
		'reports'             => array('general.reports'),
		'project_description' => array('general.project_description'),
		'geography'           => array('general.geography'),
	);

	/* ---------------------------------------------------------------- map */

	/** Folder of the synced map (gitignored). */
	public static function dataDir()
	{
		$env = getenv('FIELDVOCAB_DATA_DIR');
		if ($env !== false && $env !== '') return rtrim($env, '/');
		return dirname(dirname(__DIR__)) . '/fieldvocab_data';
	}

	/** The synced map file. */
	public static function dataMapPath()
	{
		return self::dataDir() . '/' . self::MAP_FILE;
	}

	/** The repo-shipped fallback map. */
	public static function baselinePath()
	{
		return __DIR__ . '/field_vocab_baseline.json';
	}

	/**
	 * Decode a map file; null when missing or not a schema 1 map.
	 *
	 * @param string $path
	 * @return array|null
	 */
	public static function readMapFile($path)
	{
		if (!is_file($path)) return null;
		$m = json_decode((string)@file_get_contents($path), true);
		if (!is_array($m) || !isset($m['schema'], $m['forms']) || $m['schema'] !== 1 || !is_array($m['forms'])) return null;
		return $m;
	}

	/** The active map (loaded once per process). */
	public static function map()
	{
		if (self::$map === null) {
			$m = self::readMapFile(self::dataMapPath());
			self::$origin = 'data';
			if ($m === null) { $m = self::readMapFile(self::baselinePath()); self::$origin = 'baseline'; }
			if ($m === null) { $m = array('schema' => 1, 'forms' => array()); self::$origin = 'none'; }
			self::$map = $m;
		}
		return self::$map;
	}

	/**
	 * Replace the active map (tests), or pass null to reload from disk.
	 *
	 * @param array|null $map
	 */
	public static function setMap($map)
	{
		self::$map = $map;
		self::$origin = $map === null ? 'none' : 'injected';
	}

	/** {origin, tag, sha} of the active map, for logs and page footers. */
	public static function source()
	{
		$m = self::map();
		return array(
			'origin' => self::$origin,
			'tag'    => isset($m['source']['tag']) ? $m['source']['tag'] : null,
			'sha'    => isset($m['source']['sha']) ? $m['source']['sha'] : null,
		);
	}

	/* ------------------------------------------------------------ lookups */

	/**
	 * Is $field a choice (select) field in any of the forms?
	 *
	 * @param string|string[] $forms  form key(s), e.g. 'measurement.planar_orientation'
	 * @param string          $field
	 * @return bool
	 */
	public static function isChoiceField($forms, $field)
	{
		$m = self::map();
		foreach ((array)$forms as $fk) {
			if (isset($m['forms'][$fk]['fields'][$field])) return true;
		}
		return false;
	}

	/**
	 * Is $field a select_multiple in any of the forms?
	 *
	 * @param string|string[] $forms
	 * @param string          $field
	 * @return bool
	 */
	public static function isMultiple($forms, $field)
	{
		$m = self::map();
		foreach ((array)$forms as $fk) {
			if (isset($m['forms'][$fk]['fields'][$field]) && $m['forms'][$fk]['fields'][$field]['type'] === 'select_multiple') return true;
		}
		return false;
	}

	/**
	 * Translate a stored value. Scalars give a string, arrays (select_multiple)
	 * give an array of the same length. Values the map cannot resolve (not a
	 * choice field, unknown name, a name two forms label differently, a name
	 * listed twice in one choice list) go through $fallback:
	 *   null      the value unchanged (the surface's raw output), default
	 *   'app'     the app's own rule: underscores to spaces
	 *   callable  $fallback($value), e.g. a surface's existing prettifier
	 *
	 * @param string|string[]      $forms
	 * @param string               $field
	 * @param mixed                $value
	 * @param null|string|callable $fallback
	 * @return mixed
	 */
	public static function label($forms, $field, $value, $fallback = null)
	{
		if (is_array($value)) {
			$out = array();
			foreach ($value as $k => $v) $out[$k] = self::label($forms, $field, $v, $fallback);
			return $out;
		}
		if (!is_string($value) && !is_int($value) && !is_float($value)) return $value;   // null, bool, objects: untouched
		$hit = self::lookup((array)$forms, (string)$field, (string)$value);
		if ($hit !== null) return $hit;
		if ($fallback === null) return $value;
		if ($fallback === 'app') return str_replace('_', ' ', (string)$value);
		if (is_callable($fallback)) return call_user_func($fallback, $value);
		return $value;
	}

	/**
	 * label() joined for display: arrays as "a, b" (the app's join).
	 *
	 * @param string|string[]      $forms
	 * @param string               $field
	 * @param mixed                $value
	 * @param string               $sep
	 * @param null|string|callable $fallback
	 * @return string
	 */
	public static function labelText($forms, $field, $value, $sep = ', ', $fallback = null)
	{
		$l = self::label($forms, $field, $value, $fallback);
		if (is_array($l)) {
			$parts = array();
			foreach ($l as $x) {
				if (is_scalar($x)) $parts[] = (string)$x;
			}
			return implode($sep, $parts);
		}
		return is_scalar($l) ? (string)$l : '';
	}

	/**
	 * One name in one field across candidate forms; null when unresolved.
	 * Current choices win over retired ones; a name that is ambiguous in any
	 * candidate, or labeled differently by two candidates, is unresolved.
	 */
	private static function lookup(array $forms, $field, $name)
	{
		$m = self::map();
		$labels = array();
		foreach ($forms as $fk) {
			if (!isset($m['forms'][$fk]['fields'][$field])) continue;
			$f = $m['forms'][$fk]['fields'][$field];
			if (isset($f['ambiguous'][$name])) return null;
			if (isset($f['choices'][$name])) $labels[$f['choices'][$name]] = true;
			elseif (isset($f['retired_choices'][$name]['label'])) $labels[$f['retired_choices'][$name]['label']] = true;
		}
		if (count($labels) !== 1) return null;
		return (string)key($labels);
	}

	/* ----------------------------------------------------------- resolver */

	/**
	 * Which form(s) define the choice fields of a stored object, from where it
	 * sits in the spot (the app's own form selection, audit §2).
	 *
	 * Families:
	 *   orientation          properties.orientation_data[] and nested associated_orientation[] (by type)
	 *   _3d_structures       properties._3d_structures[] (by type)
	 *   fabrics              properties.fabrics[] (by type)
	 *   pet.igneous          properties.pet.igneous[] (by igneous_rock_class)
	 *   pet.metamorphic, pet.alteration_or, pet.fault, pet.minerals, pet.reactions
	 *   pet                  the flat legacy properties.pet (deprecated forms)
	 *   sed                  properties.sed itself (character)
	 *   sed.lithologies, sed.structures, sed.interpretations, sed.diagenesis, sed.fossils,
	 *   sed.interval, sed.strat_section, sed.bedding.beds
	 *   sed.bedding          properties.sed.bedding{} (by $context = properties.sed.character)
	 *   tephra, samples, images, earthquakes, outcrop_summaries, site_safety, trace,
	 *   surface_feature, reports, project_description, geography
	 *   tags                 project.tags[] (geologic units by type)
	 *
	 * @param string     $family
	 * @param array|null $obj      the stored object (for type-selected families)
	 * @param mixed      $context  family-specific extra (sed.bedding: the sed character)
	 * @return string[] form keys; empty = unknown (label() then falls back)
	 */
	public static function formsFor($family, $obj = null, $context = null)
	{
		if (isset(self::$FIXED[$family])) return self::$FIXED[$family];
		$type = is_array($obj) && isset($obj['type']) && is_string($obj['type']) ? $obj['type'] : null;
		switch ($family) {
			case 'orientation':
				return in_array($type, array('planar_orientation', 'linear_orientation', 'tabular_orientation'), true)
					? array('measurement.' . $type) : array();
			case '_3d_structures':
				return in_array($type, array('fabric', 'fault', 'fold', 'other', 'tensor'), true)
					? array('_3d_structures.' . $type) : array();
			case 'fabrics':
				return in_array($type, array('fault_rock', 'igneous_rock', 'metamorphic_rock'), true)
					? array('fabrics.' . $type) : array();
			case 'pet.igneous':
				$class = is_array($obj) && isset($obj['igneous_rock_class']) ? $obj['igneous_rock_class'] : null;
				if ($class === 'plutonic') return array('pet.plutonic');
				if ($class === 'volcanic') return array('pet.volcanic');
				return array('pet.plutonic', 'pet.volcanic');
			case 'sed.bedding':
				if ($context === 'interbedded') return array('sed.bedding_shared_interbedded');
				if ($context === 'package_succe') return array('sed.bedding_shared_package');
				return array('sed.bedding_shared_interbedded', 'sed.bedding_shared_package');
			case 'tags':
				return $type === 'geologic_unit' ? array('project.geologic_unit', 'project.tags') : array('project.tags');
		}
		return array();
	}
}
