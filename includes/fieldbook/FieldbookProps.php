<?php
/**
 * File: includes/fieldbook/FieldbookProps.php
 * Description: Generic property walker for the enhanced fieldbook
 *              (docs/Fieldbook_Design.md §4.1 "Other observations", §9).
 *              Every spot property family that has no designed block is
 *              rendered from here so that nothing the app stored is lost
 *              (decision D7). Also the scalar collector the key-parity
 *              harness uses.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

class FieldbookProps
{
	/** Spot keys handled by the designed blocks or that are system bookkeeping (never in the generic block). */
	public static $handled = array(
		'id', 'self', 'name', 'notes', 'images', 'orientation_data', 'samples',
		'geometrytype', 'modified_timestamp', 'date', 'time', 'image_basemap', 'strat_section_id',
		'lat', 'lng', 'altitude', 'gps_accuracy', 'altitude_accuracy', 'spot_radius',
		'symbology', 'viewed_timestamp', 'notesTimestamp', 'userpkey',
	);

	/** Preferred family order; anything else follows alphabetically. */
	public static $order = array('surface_feature', 'trace', '_3d_structures', 'other_features', 'sed', 'strat_section', 'tephra', 'pet', 'fabrics', 'custom_fields', 'data', 'isSample');

	public static $familyLabels = array(
		'surface_feature' => 'Surface feature', 'trace' => 'Trace feature', '_3d_structures' => '3D structures',
		'other_features' => 'Other features', 'sed' => 'Sedimentology', 'strat_section' => 'Strat section',
		'tephra' => 'Tephra', 'pet' => 'Petrology', 'fabrics' => 'Fabrics', 'custom_fields' => 'Custom fields',
		'data' => 'Data', 'isSample' => 'Is sample',
	);

	/** Vocabulary token (snake_case) => sentence case; free text untouched. */
	public static function humanize($v)
	{
		if ($v === null) return '';
		if (is_bool($v)) return $v ? 'Yes' : 'No';
		if (is_int($v) || is_float($v)) return (string)$v;
		if (is_array($v) || is_object($v)) {
			$parts = array();
			foreach ((array)$v as $x) $parts[] = self::humanize($x);
			return implode(', ', $parts);
		}
		$v = (string)$v;
		// vocabulary token (snake_case, possibly "5___definitely") => sentence case
		if (preg_match('/^[a-z][a-z0-9]*(_+[a-z0-9]+)*$/', $v) || preg_match('/^[0-9]+(_+[a-z0-9]+)+$/', $v)) {
			return ucfirst(preg_replace('/_+/', ' ', $v));
		}
		// ISO 8601 date-times as the app writes them => readable, UTC
		if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $v, $mm)) {
			$t = strtotime($v);
			if ($t !== false) return gmdate('F j, Y H:i', $t) . ' UTC';
		}
		return $v;
	}

	/** Leaf keys that hold bookkeeping, not observations (documented in the design doc §4.1). */
	public static $systemLeaf = array('created_by', 'self', 'symbology', 'viewed_timestamp', 'notesTimestamp', 'userpkey');

	/** Value for a leaf, with epoch timestamps (s or ms) under *timestamp keys rendered as dates. */
	public static function leaf($key, $v)
	{
		if (preg_match('/timestamp$/i', (string)$key) && preg_match('/^\d{10}(\d{3})?$/', (string)$v)) {
			return gmdate('F j, Y H:i', (int)substr((string)$v, 0, 10)) . ' UTC';
		}
		return self::humanize($v);
	}

	/** Key => label ("what_core_repository" => "What core repository", "_3d_structures" => "3D structures"). */
	public static function label($key)
	{
		$key = (string)$key;
		if (isset(self::$familyLabels[$key])) return self::$familyLabels[$key];
		$key = ltrim($key, '_');
		$key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
		$key = str_replace('_', ' ', $key);
		return ucfirst(trim($key));
	}

	/** True for a LIST of scalars (rendered inline, comma separated); associative data is walked key by key. */
	private static function isScalarList($v)
	{
		if (is_object($v)) $v = (array)$v;
		if (!is_array($v) || !self::isList($v)) return false;
		foreach ($v as $x) if (is_array($x) || is_object($x)) return false;
		return true;
	}

	/** True for a list (0..n-1 keys). */
	private static function isList($v)
	{
		if (!is_array($v)) return false;
		return array_keys($v) === range(0, count($v) - 1);
	}

	/**
	 * Families of a spot's properties: [{key, label, rows:[{k, v, d, h}]}].
	 * d = depth (0 = family level), h = heading row (no value).
	 */
	public static function families(array $props)
	{
		$keys = array();
		foreach ($props as $k => $v) {
			if (in_array($k, self::$handled, true)) continue;
			if ($v === null || $v === '' || $v === array()) continue;
			$keys[] = $k;
		}
		usort($keys, function ($a, $b) {
			$ia = array_search($a, self::$order, true); $ib = array_search($b, self::$order, true);
			if ($ia === false) $ia = 999; if ($ib === false) $ib = 999;
			if ($ia !== $ib) return $ia - $ib;
			return strcmp($a, $b);
		});
		$out = array();
		foreach ($keys as $k) {
			$rows = array();
			self::walk($props[$k], $rows, 0, $k);
			if ($rows) $out[] = array('key' => $k, 'label' => self::label($k), 'rows' => $rows);
		}
		return $out;
	}

	/** Recursive walk producing rows; item ids are skipped (legacy did the same). */
	public static function walk($v, array &$rows, $depth, $parentKey = '')
	{
		if (is_object($v)) $v = (array)$v;
		if (!is_array($v)) {
			if (in_array($parentKey, self::$systemLeaf, true)) return;
			$rows[] = array('k' => self::label($parentKey), 'v' => self::leaf($parentKey, $v), 'd' => $depth, 'h' => false);
			return;
		}
		if (self::isScalarList($v)) {
			if ($depth > 0 || $parentKey !== '') {
				$rows[] = array('k' => self::label($parentKey), 'v' => self::humanize($v), 'd' => $depth, 'h' => false);
			}
			return;
		}
		if (self::isList($v)) {
			$n = 0;
			foreach ($v as $item) {
				$n++;
				if (is_object($item)) $item = (array)$item;
				if (!is_array($item)) {
					$rows[] = array('k' => self::label($parentKey) . " $n", 'v' => self::humanize($item), 'd' => $depth, 'h' => false);
					continue;
				}
				$title = self::itemTitle($item, self::label($parentKey) . " $n");
				$rows[] = array('k' => $title, 'v' => '', 'd' => $depth, 'h' => true);
				foreach ($item as $k => $x) {
					if ($k === 'id' || in_array($k, self::$systemLeaf, true)) continue;
					if ($x === null || $x === '' || $x === array()) continue;
					self::walk($x, $rows, $depth + 1, $k);
				}
			}
			return;
		}
		// associative
		foreach ($v as $k => $x) {
			if ($k === 'id' && $depth > 0) continue;
			if (in_array($k, self::$systemLeaf, true)) continue;
			if ($x === null || $x === '' || $x === array()) continue;
			if (is_array($x) || is_object($x)) {
				if (self::isScalarList($x)) {
					$rows[] = array('k' => self::label($k), 'v' => self::humanize($x), 'd' => $depth, 'h' => false);
				} else {
					$rows[] = array('k' => self::label($k), 'v' => '', 'd' => $depth, 'h' => true);
					self::walk($x, $rows, $depth + 1, $k);
				}
			} else {
				$rows[] = array('k' => self::label($k), 'v' => self::leaf($k, $x), 'd' => $depth, 'h' => false);
			}
		}
	}

	/** Title for a list item: label / name / type / sample_id_name, else the fallback. */
	public static function itemTitle(array $item, $fallback)
	{
		foreach (array('label', 'name', 'sample_id_name', 'type', 'feature_type') as $k) {
			if (isset($item[$k]) && is_scalar($item[$k]) && (string)$item[$k] !== '') return self::humanize($item[$k]);
		}
		return $fallback;
	}

	/** Every scalar value in a nested structure (for the parity harness). */
	public static function scalars($v, array &$out)
	{
		if (is_object($v)) $v = (array)$v;
		if (is_array($v)) { foreach ($v as $x) self::scalars($x, $out); return; }
		if ($v === null || $v === '' || is_bool($v)) return;
		$out[] = (string)$v;
	}
}
