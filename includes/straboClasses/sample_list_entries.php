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
 * Spot Name (Wesdome 2026-11-03): the spot the sample was taken at. For a legacy inline
 * sample that is the holding spot. A sample spot is named after its sample by the app, so
 * its Spot Name is the PARENT spot, the normal spot whose samples[] holds the sample spot's
 * id (stub or stale copy). spot_name is null when that parent is not in $features (the
 * caller can look it up, see sample_list_parent_names).
 *
 * @param array $features  GeoJSON features (arrays) from getDatasetSpotsSearch
 * @return array  list of array('sample' => entry, 'feature' => feature, 'spot_name' => string|null),
 *                in feature order
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

	// parent spot name by the id its samples[] entries carry (first parent wins)
	$parentName = array();
	foreach ($features as $f) {
		if ($isRich($f)) continue;
		foreach (($f['properties']['samples'] ?? array()) as $s) {
			$id = is_object($s) ? ($s->id ?? null) : ($s['id'] ?? null);
			if ($id === null || $id === '' || isset($parentName[(string)$id])) continue;
			$parentName[(string)$id] = (string)($f['properties']['name'] ?? '');
		}
	}

	$out = array();
	foreach ($features as $f) {
		$samples = $f['properties']['samples'] ?? null;
		if (empty($samples)) continue;
		$rich = $isRich($f);
		$spotId = (string)($f['properties']['id'] ?? '');
		$spotName = $rich ? ($parentName[$spotId] ?? null) : (string)($f['properties']['name'] ?? '');
		foreach ($samples as $s) {
			if (sample_list_entry_is_stub($s)) continue;
			$id = is_object($s) ? ($s->id ?? null) : ($s['id'] ?? null);
			if (!$rich && $id !== null && isset($richIds[(string)$id])) continue;
			$out[] = array('sample' => $s, 'feature' => $f, 'spot_name' => $spotName);
		}
	}
	return $out;
}

/**
 * Parent spot names for sample spots whose parent is not in the export (e.g. a dataset
 * Sample List when the parent spot is in another dataset). Searches the owner's normal
 * spots for a json_samples entry with that id.
 *
 * @param object $neodb  StraboDbNeo4j
 * @param int    $owner  data owner's userpkey
 * @param array  $ids    sample spot ids (numeric strings)
 * @return array  id => parent spot name, for the ids found
 */
function sample_list_parent_names($neodb, $owner, $ids) {
	$owner = (int)$owner;
	$ids = array_values(array_unique(array_filter(array_map('strval', $ids), function ($i) {
		return preg_match('/^[0-9]+$/', $i);
	})));
	if ($owner <= 0 || count($ids) == 0) return array();
	$found = array();
	foreach (array_chunk($ids, 200) as $chunk) {
		$list = implode(",", array_map(function ($i) { return "'\"id\":$i'"; }, $chunk));
		$q = "MATCH (p:Spot {userpkey: $owner}) WHERE coalesce(p.isSample, 0) <> 1 AND p.json_samples IS NOT NULL "
			. "WITH p, [t IN [$list] WHERE p.json_samples CONTAINS t] AS hits WHERE size(hits) > 0 "
			. "RETURN p.name AS name, p.json_samples AS js";
		foreach ($neodb->query($q) as $rec) {
			$entries = json_decode((string)$rec->get('js'), true);
			if (!is_array($entries)) continue;
			foreach ($entries as $e) {
				$id = (string)($e['id'] ?? '');
				if ($id !== '' && in_array($id, $chunk, true) && !isset($found[$id])) $found[$id] = (string)$rec->get('name');
			}
		}
	}
	return $found;
}

}
