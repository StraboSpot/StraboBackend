<?php
/**
 * File: exportjobs/lib/ExportGatherer.php
 * Description: Export Builder GATHER stage (design §7.2, §7.3, decisions
 *              D3a + D12). For one scoped project: pull the candidate spots'
 *              full GeoJSON from Neo4j through the SAME fetcher every
 *              legacy generator uses (getDatasetSpotsSearch, chunked by
 *              spot id), then, when the recipe carries an area filter:
 *
 *                1. SPATIAL  located spots (no image_basemap / strat_section_id)
 *                            keep iff geometry INTERSECTS the polygon (GEOS via
 *                            geoPHP) — a contact line crossing the boundary is in.
 *                2. CHILDREN nested spots (pixel/section space, never tested
 *                            directly) keep iff their parent kept. Parent =
 *                            the spot owning the image named by image_basemap,
 *                            or the spot whose sed.strat_section id matches
 *                            strat_section_id; resolved recursively so a child
 *                            of a child follows its root.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/export_config.php';
require_once __DIR__ . '/ExportPlugin.php';
require_once dirname(__DIR__, 2) . '/includes/geophp/geoPHP.inc';

class ExportGatherer
{
	private $strabo;
	private $cfg;
	private $chunk;

	/**
	 * @param StraboSpot $strabo  class instance (any userpkey; the fetcher is
	 *                            anchored on the recipe scope owner explicitly)
	 */
	public function __construct($strabo, array $cfg = null)
	{
		$this->strabo = $strabo;
		$this->cfg = $cfg === null ? export_config() : $cfg;
		$this->chunk = isset($this->cfg['gather_chunk']) ? max(1, (int)$this->cfg['gather_chunk']) : 2000;
	}

	/**
	 * @param array      $sc        scope entry from ExportFinder::find (project_id, owner, dataset_ids, spot_ids|null)
	 * @param array|null $polygon   GeoJSON Polygon/MultiPolygon (assoc) or null
	 * @param callable   $progress  function($done, $total, $note)
	 * @return array {features: [], item_count, child_count, dropped_spatial, warnings: []}
	 */
	public function gather(array $sc, $polygon, $progress = null)
	{
		$features = $this->fetch($sc, $progress);
		$out = array('features' => $features, 'item_count' => count($features), 'child_count' => 0,
			'dropped_spatial' => 0, 'warnings' => array());
		if ($polygon === null) return $out;

		$poly = geoPHP::load(json_encode($polygon), 'json');
		if (!$poly) throw new ExportJobError('Could not read the area filter polygon.');

		// Index every feature by id; map image id -> owning spot id; strat section id -> defining spot id.
		$byId = array(); $imageOwner = array(); $stratOwner = array();
		foreach ($features as $f) {
			$id = self::spotId($f);
			$byId[$id] = $f;
			$p = isset($f['properties']) ? $f['properties'] : array();
			if (!empty($p['images']) && is_array($p['images'])) {
				foreach ($p['images'] as $img) {
					$iid = is_array($img) ? (isset($img['id']) ? $img['id'] : null) : (isset($img->id) ? $img->id : null);
					if ($iid !== null && $iid !== '') $imageOwner[(string)$iid] = $id;
				}
			}
			$sed = isset($p['sed']) ? $p['sed'] : null;
			$ss = is_array($sed) ? (isset($sed['strat_section']) ? $sed['strat_section'] : null)
				: (is_object($sed) && isset($sed->strat_section) ? $sed->strat_section : null);
			$ssid = is_array($ss) ? (isset($ss['strat_section_id']) ? $ss['strat_section_id'] : null)
				: (is_object($ss) && isset($ss->strat_section_id) ? $ss->strat_section_id : null);
			if ($ssid !== null && $ssid !== '') $stratOwner[(string)$ssid] = $id;
		}

		// Pass 1: located spots against the polygon.
		$kept = array(); $isChild = array();
		$n = count($features); $i = 0;
		foreach ($features as $f) {
			$id = self::spotId($f);
			if (self::parentRef($f) !== null) { $isChild[$id] = true; continue; }
			$geom = isset($f['geometry']) ? $f['geometry'] : null;
			if (!$geom) continue;
			$g = geoPHP::load(json_encode($geom), 'json');
			if ($g && $g->intersects($poly)) $kept[$id] = true;
			if ($progress && (++$i % 500) === 0) $progress($i, $n, "testing spots against the area: $i of $n");
		}

		// Pass 2: children follow their root parent.
		$childKept = 0;
		foreach (array_keys($isChild) as $id) {
			$root = $this->rootParent($id, $byId, $imageOwner, $stratOwner, 0);
			if ($root !== null && isset($kept[$root])) { $kept[$id] = true; $childKept++; }
		}

		$result = array();
		foreach ($features as $f) if (isset($kept[self::spotId($f)])) $result[] = $f;
		$out['features'] = $result;
		$out['item_count'] = count($result);
		$out['child_count'] = $childKept;
		$out['dropped_spatial'] = count($features) - count($result);
		return $out;
	}

	/** Parent reference of a nested spot: ['image', id] | ['strat', id] | null. */
	public static function parentRef(array $f)
	{
		$p = isset($f['properties']) ? $f['properties'] : array();
		if (!empty($p['image_basemap'])) return array('image', (string)$p['image_basemap']);
		if (!empty($p['strat_section_id'])) return array('strat', (string)$p['strat_section_id']);
		return null;
	}

	private function rootParent($id, $byId, $imageOwner, $stratOwner, $depth)
	{
		if ($depth > 20 || !isset($byId[$id])) return null;
		$ref = self::parentRef($byId[$id]);
		if ($ref === null) return $id;                       // located: it is its own root
		$parent = $ref[0] === 'image'
			? (isset($imageOwner[$ref[1]]) ? $imageOwner[$ref[1]] : null)
			: (isset($stratOwner[$ref[1]]) ? $stratOwner[$ref[1]] : null);
		if ($parent === null || $parent === $id) return null;
		return $this->rootParent($parent, $byId, $imageOwner, $stratOwner, $depth + 1);
	}

	public static function spotId(array $f)
	{
		return isset($f['properties']['id']) ? (string)$f['properties']['id'] : (isset($f['id']) ? (string)$f['id'] : '');
	}

	/** Pull candidate features through getDatasetSpotsSearch, chunked by spot id. */
	private function fetch(array $sc, $progress)
	{
		if (empty($sc['dataset_ids'])) return array();
		$base = array(
			'dsids'    => implode(',', array_map('intval', $sc['dataset_ids'])),
			'userpkey' => (int)$sc['owner'],
		);
		$features = array();
		if ($sc['spot_ids'] === null) {
			$fc = $this->strabo->getDatasetSpotsSearch(null, $base);
			$features = self::featuresOf($fc);
			if ($progress) $progress(count($features), count($features), 'gathered ' . count($features) . ' spots');
			return $features;
		}
		$ids = array_values(array_unique(array_map('intval', $sc['spot_ids'])));
		$total = count($ids);
		foreach (array_chunk($ids, $this->chunk) as $chunk) {
			$get = $base; $get['spot_ids'] = $chunk;
			$fc = $this->strabo->getDatasetSpotsSearch(null, $get);
			foreach (self::featuresOf($fc) as $f) $features[] = $f;
			if ($progress) $progress(count($features), $total, 'gathering spots ' . count($features) . " of $total");
		}
		// Dedupe (a spot in two scoped datasets comes back once per dataset).
		$seen = array(); $uniq = array();
		foreach ($features as $f) { $k = self::spotId($f); if (isset($seen[$k])) continue; $seen[$k] = true; $uniq[] = $f; }
		return $uniq;
	}

	/** Feature collection (array or JSON string) -> list of assoc features. */
	public static function featuresOf($fc)
	{
		if (is_string($fc)) $fc = json_decode($fc, true);
		if (is_object($fc)) $fc = json_decode(json_encode($fc), true);
		if (!is_array($fc) || empty($fc['features'])) return array();
		$out = array();
		foreach ($fc['features'] as $f) $out[] = is_array($f) ? $f : json_decode(json_encode($f), true);
		return $out;
	}
}
