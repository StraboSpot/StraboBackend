<?php
/**
 * File: exportjobs/lib/ExportFinder.php
 * Description: Export Builder FIND stage (design §7.1). Turns a recipe's
 *              scope + criteria into per-project spot-id lists.
 *
 *              - Scope is the authorization unit: every {project, owner}
 *                pair is checked through ExportAccess for the SESSION user
 *                stored on the job (never a URL value). Named datasets must
 *                belong to a listed project.
 *              - Criteria are the StraboSearch DSL rows, validated by the
 *                same SearchQueryBuilder::validate the search UI uses.
 *              - No non-spatial criteria  => the index is NOT consulted:
 *                dataset membership comes straight from Neo4j (authoritative,
 *                drift-proof; the common whole-dataset case).
 *              - Otherwise the index resolves the non-spatial criteria to
 *                spot ids (runItemIdsQuery). The spatial criterion (U2) is
 *                carried through untouched for the gather stage, which tests
 *                full geometries with GEOS (intersects semantics, D3a).
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/export_config.php';
require_once __DIR__ . '/ExportPlugin.php';
require_once __DIR__ . '/ExportAccess.php';
require_once dirname(__DIR__, 2) . '/searchdb/services/SearchQueryBuilder.php';

class ExportFinder
{
	private $db;
	private $neodb;
	private $userpkey;
	private $cfg;

	public function __construct($db, $neodb, $sessionUserpkey, array $cfg = null)
	{
		$this->db = $db;
		$this->neodb = $neodb;
		$this->userpkey = (int)$sessionUserpkey;
		$this->cfg = $cfg === null ? export_config() : $cfg;
	}

	/**
	 * Normalize + authorize recipe.scope.
	 * @return array list of {project_id, owner, dataset_ids (string[]|null), access}
	 */
	public function resolveScope(array $recipe)
	{
		$scope = isset($recipe['scope']) && is_array($recipe['scope']) ? $recipe['scope'] : array();
		$projects = isset($scope['projects']) && is_array($scope['projects']) ? $scope['projects'] : array();
		$datasets = isset($scope['datasets']) && is_array($scope['datasets']) ? $scope['datasets'] : array();
		if (!$projects) throw new ExportJobError('Select at least one project.');
		if (count($projects) > 50) throw new ExportJobError('Too many projects in one export (max 50).');

		$out = array();
		foreach ($projects as $p) {
			$pid = isset($p['id']) ? trim((string)$p['id']) : '';
			$owner = isset($p['owner']) ? (int)$p['owner'] : 0;
			if ($pid === '' || !preg_match('/^[0-9]{1,20}$/', $pid) || $owner <= 0) {
				throw new ExportJobError('Malformed project reference in scope.');
			}
			$level = ExportAccess::level($this->db, $this->userpkey, $pid, $owner);
			if ($level === null) throw new ExportJobError("You do not have access to project $pid.");
			$out["$pid|$owner"] = array('project_id' => $pid, 'owner' => $owner, 'dataset_ids' => null, 'access' => $level);
		}
		foreach ($datasets as $d) {
			$did = isset($d['id']) ? trim((string)$d['id']) : '';
			$pid = isset($d['project_id']) ? trim((string)$d['project_id']) : '';
			$owner = isset($d['owner']) ? (int)$d['owner'] : 0;
			if (!preg_match('/^[0-9]{1,20}$/', $did) || !isset($out["$pid|$owner"])) {
				throw new ExportJobError("Dataset $did is not in a selected project.");
			}
			if ($out["$pid|$owner"]['dataset_ids'] === null) $out["$pid|$owner"]['dataset_ids'] = array();
			$out["$pid|$owner"]['dataset_ids'][] = $did;
		}
		return array_values($out);
	}

	/**
	 * Validate recipe.criteria through the search DSL validator.
	 * @return array {dsl: validated DSL, polygon: GeoJSON array|null, has_nonspatial: bool}
	 */
	public function resolveCriteria(array $recipe)
	{
		$criteria = isset($recipe['criteria']) && is_array($recipe['criteria']) ? $recipe['criteria'] : array();
		// Validated rows carry U2 as {geojson: "<json>"} (the validator's
		// internal form). Recipes store validated rows, so turn that form back
		// into the GeoJSON object the validator accepts before re-validating.
		foreach ($criteria as &$c) {
			if (is_array($c) && isset($c['id']) && strtoupper((string)$c['id']) === 'U2'
				&& is_array($c['value']) && isset($c['value']['geojson']) && !isset($c['value']['type'])) {
				$g = is_string($c['value']['geojson']) ? json_decode($c['value']['geojson'], true) : $c['value']['geojson'];
				if (is_array($g)) $c['value'] = $g;
			}
		}
		unset($c);
		$qb = new SearchQueryBuilder($this->db, $this->userpkey);
		try {
			$dsl = $qb->validate(array('subsystems' => array('field'), 'pathway' => 'projects', 'criteria' => $criteria));
		} catch (SearchDslError $e) {
			throw new ExportJobError('Filter error: ' . $e->getMessage());
		}
		$polygon = null; $hasNonSpatial = false;
		foreach ($dsl['criteria'] as $c) {
			if ($c['id'] === 'U2') {
				if ($c['not']) throw new ExportJobError('A negated area filter is not supported in exports.');
				if ($polygon !== null) throw new ExportJobError('Only one area filter per export.');
				$polygon = $this->polygonFromValue($c['value']);
			} else {
				$hasNonSpatial = true;
			}
		}
		return array('dsl' => $dsl, 'polygon' => $polygon, 'has_nonspatial' => $hasNonSpatial);
	}

	/** U2 value ({bbox} or GeoJSON Polygon/MultiPolygon, already validated) -> GeoJSON assoc. */
	private function polygonFromValue($v)
	{
		if (isset($v['bbox'])) {
			list($w, $s, $e, $n) = array_map('floatval', $v['bbox']);
			return array('type' => 'Polygon', 'coordinates' => array(array(
				array($w, $s), array($e, $s), array($e, $n), array($w, $n), array($w, $s))));
		}
		if (isset($v['geojson'])) {
			$g = is_string($v['geojson']) ? json_decode($v['geojson'], true) : $v['geojson'];
			if (is_array($g)) return $g;
		}
		if (isset($v['type'])) return $v;
		throw new ExportJobError('Unrecognized area filter value.');
	}

	/**
	 * FIND: per scoped project, the candidate spot ids (non-spatial criteria
	 * applied). Spatial + children are the gather stage's job.
	 *
	 * @return array {
	 *   projects: [{project_id, owner, dataset_ids, access, spot_ids:int[]|null}],
	 *   polygon: GeoJSON|null, used_index: bool, index_synced_at: string|null,
	 *   candidate_count: int }
	 *   spot_ids null = "every spot in the scoped datasets" (fast path).
	 */
	public function find(array $recipe)
	{
		$scope = $this->resolveScope($recipe);
		$crit  = $this->resolveCriteria($recipe);
		$result = array('projects' => array(), 'polygon' => $crit['polygon'], 'used_index' => false,
			'index_synced_at' => null, 'candidate_count' => 0);

		if (!$crit['has_nonspatial']) {
			// Fast path: authoritative membership from the graph.
			foreach ($scope as $sc) {
				$sc['spot_ids'] = null;
				$sc['dataset_ids'] = $this->datasetIds($sc);
				$result['candidate_count'] += $this->countSpots($sc);
				$result['projects'][] = $sc;
			}
			$this->enforceMax($result['candidate_count']);
			return $result;
		}

		$qb = new SearchQueryBuilder($this->db, $this->userpkey);
		$limit = (int)$this->cfg['max_items'] + 1;
		$rows = $qb->runItemIdsQuery($crit['dsl'], $scope, $limit);
		$this->enforceMax(count($rows));
		$byProject = array();
		foreach ($rows as $r) {
			$k = $r['project_id'] . '|' . $r['project_userpkey'];
			if (!isset($byProject[$k])) $byProject[$k] = array();
			$byProject[$k][] = (int)$r['item_id'];
		}
		foreach ($scope as $sc) {
			$k = $sc['project_id'] . '|' . $sc['owner'];
			$sc['spot_ids'] = isset($byProject[$k]) ? array_values(array_unique($byProject[$k])) : array();
			$sc['dataset_ids'] = $this->datasetIds($sc);
			$result['candidate_count'] += count($sc['spot_ids']);
			$result['projects'][] = $sc;
		}
		$result['used_index'] = true;
		$result['index_synced_at'] = $this->db->get_var_prepared(
			"SELECT last_incremental_sync FROM strabosearch.sync_state WHERE source = 'field'", array());
		return $result;
	}

	/**
	 * Live count for the builder page ("N spots match"): the FIND number
	 * without transferring ids. Approximate when an area filter is present
	 * (the polygon is applied only at gather time) and, like find(), before
	 * nested children are added.
	 *
	 * @return array {count:int, approximate:bool, used_index:bool, over_max:bool, max_items:int}
	 */
	public function count(array $recipe)
	{
		$scope = $this->resolveScope($recipe);
		$crit  = $this->resolveCriteria($recipe);
		$n = 0; $used = false;
		if (!$crit['has_nonspatial']) {
			foreach ($scope as $sc) {
				$sc['dataset_ids'] = $this->datasetIds($sc);
				$n += $this->countSpots($sc);
			}
		} else {
			$qb = new SearchQueryBuilder($this->db, $this->userpkey);
			$n = $qb->runItemCountQuery($crit['dsl'], $scope);
			$used = true;
		}
		return array('count' => $n, 'approximate' => $crit['polygon'] !== null, 'used_index' => $used,
			'over_max' => $n > (int)$this->cfg['max_items'], 'max_items' => (int)$this->cfg['max_items']);
	}

	private function enforceMax($n)
	{
		if ($n > (int)$this->cfg['max_items']) {
			throw new ExportJobError('This selection has more than ' . number_format((int)$this->cfg['max_items'])
				. ' spots. Narrow it with filters or fewer datasets.');
		}
	}

	/** Dataset ids for a scope entry: the named ones, else every dataset of the project. Anchored walk. */
	public function datasetIds(array $sc)
	{
		if (!empty($sc['dataset_ids'])) {
			$want = array_map('intval', $sc['dataset_ids']);
			$rows = $this->neodb->query(
				"MATCH (u:User {userpkey: " . (int)$sc['owner'] . "})-[:HAS_PROJECT]->(p:Project {id: " . (int)$sc['project_id'] . "})
				 -[:HAS_DATASET]->(d:Dataset) WHERE d.id IN [" . implode(',', $want) . "] RETURN d.id AS id");
		} else {
			$rows = $this->neodb->query(
				"MATCH (u:User {userpkey: " . (int)$sc['owner'] . "})-[:HAS_PROJECT]->(p:Project {id: " . (int)$sc['project_id'] . "})
				 -[:HAS_DATASET]->(d:Dataset) RETURN d.id AS id");
		}
		$ids = array();
		foreach ($rows as $r) $ids[] = (string)$r->value('id');
		if (!empty($sc['dataset_ids']) && count($ids) !== count(array_unique($want))) {
			throw new ExportJobError('A selected dataset does not belong to project ' . $sc['project_id'] . '.');
		}
		return $ids;
	}

	private function countSpots(array $sc)
	{
		if (!$sc['dataset_ids']) return 0;
		$rows = $this->neodb->query(
			"MATCH (u:User {userpkey: " . (int)$sc['owner'] . "})-[:HAS_PROJECT]->(p:Project {id: " . (int)$sc['project_id'] . "})
			 -[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
			 WHERE d.id IN [" . implode(',', array_map('intval', $sc['dataset_ids'])) . "]
			 RETURN count(DISTINCT s) AS c");
		return $rows ? (int)$rows[0]->value('c') : 0;
	}
}
