<?php
/**
 * File: includes/straboClasses/sample_list_entries.php
 * Description: The real samples in a spot feature collection, for the Sample List
 *              exports (straboOutputClass and doiOutputClass xlsSampleList).
 *
 *              StraboField can make a sample its own spot (isSample = 1, the sample
 *              at samples[0], spot id = sample id). The parent spot then keeps only a
 *              stub {id} in its samples[] (sometimes a stale full copy). Listing every
 *              samples[] entry gave each such sample an extra row holding only the
 *              parent's coordinates and the sample id (Wesdome report 2026-11-03).
 *              Same stub rule as db/lib/sample_sync.php, without the graph probe:
 *              - an entry with no data besides id / strabosamples_id is a stub;
 *              - an entry in a normal spot whose id is a sample spot in this same
 *                collection is a stale copy (the sample spot's row is kept).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (!function_exists('sample_list_entries')) {

function sample_list_entry_is_stub($entry) {
	foreach ((array)$entry as $k => $v) {
		if ($k === 'id' || $k === 'strabosamples_id') continue;
		if (is_array($v) || is_object($v)) {
			if (count((array)$v) > 0) return false;
			continue;
		}
		if ($v !== null && $v !== '') return false;
	}
	return true;
}

/**
 * @param array $features  GeoJSON features (arrays) from getDatasetSpotsSearch
 * @return array  list of array('sample' => entry, 'feature' => feature), in feature order
 */
function sample_list_entries($features) {
	$isRich = function ($f) {
		$p = $f['properties'] ?? array();
		return !empty($p['isSample']) && !empty($p['samples']);
	};

	$richIds = array();
	foreach ($features as $f) {
		if (!$isRich($f)) continue;
		foreach ($f['properties']['samples'] as $s) {
			$id = is_object($s) ? ($s->id ?? null) : ($s['id'] ?? null);
			if ($id !== null && $id !== '') $richIds[(string)$id] = true;
		}
	}

	$out = array();
	foreach ($features as $f) {
		$samples = $f['properties']['samples'] ?? null;
		if (empty($samples)) continue;
		$rich = $isRich($f);
		foreach ($samples as $s) {
			if (sample_list_entry_is_stub($s)) continue;
			$id = is_object($s) ? ($s->id ?? null) : ($s['id'] ?? null);
			if (!$rich && $id !== null && isset($richIds[(string)$id])) continue;
			$out[] = array('sample' => $s, 'feature' => $f);
		}
	}
	return $out;
}

}
