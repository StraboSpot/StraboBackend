<?php
/**
 * File: exportjobs/plugins/FieldExportPlugin.php
 * Description: StraboField export plugin (design §8). Pipeline per job:
 *
 *   FIND     ExportFinder: scope authz + criteria -> candidate spot ids
 *   GATHER   ExportGatherer per project: full features, GEOS polygon
 *            intersects, children of matched parents -> final ids
 *   FORMAT   for each layout group, each chosen format runs the LEGACY
 *            generator in straboOutputClass with captureDir set; the
 *            generator re-fetches exactly the group's (owner, datasets,
 *            spot ids) through getDatasetSpotsSearch scope_groups with
 *            attribution columns on, and its delivery line lands in the
 *            bundle instead of the browser. Data paths are the legacy ones.
 *   SAMPLES  the cross-subsystem sample list (format key sample_list, M4)
 *            is a plugin-level format: the exported spot ids are looked up
 *            in the StraboSamples spine link table (subsystem = field), the
 *            linked samples are pulled through
 *            SampleTabularService::exportRowsForIds (same workbook as the
 *            My Samples export, plus read-only field_project / field_dataset
 *            / field_spot_id / field_spot_name context columns) and written
 *            as samples.xlsx (+ samples.csv when recipe.sample_list_csv).
 *            Micro / Experimental links on those samples show up in the
 *            linked_systems column. One list per layout group.
 *   EXTRAS   whole-project selections also get projects/<name>/project.json
 *            (doiDataOut) and geologic_units.xlsx (geologicUnitsOut).
 *
 * Layouts: merged (one output set, attribution columns), split_project
 * (projects/<name>/...), split_dataset (projects/<name>/datasets/<name>/...).
 *
 * Known limits (documented in the design doc §8): helper lookups inside the
 * generators (dataset name for filenames, daily notes) are anchored on the
 * FIRST owner of a merged group; a spot id list travels inline in the
 * Cypher, so very large filtered selections are slower than whole datasets.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/../lib/ExportPlugin.php';
require_once __DIR__ . '/../lib/export_config.php';
require_once __DIR__ . '/../lib/ExportFinder.php';
require_once __DIR__ . '/../lib/ExportGatherer.php';
require_once __DIR__ . '/../../includes/fieldbook/Fieldbook.php';   // option validation for the fieldbook format

class FieldExportPlugin implements ExportPlugin
{
	/** format key => straboOutputClass method (live generators only; design D7) */
	public static $formats = array(
		'shapefile' => 'expandedShapefileOut',
		'kmz'       => 'kmlOut',
		'xls'       => 'xlsOut',
		'stereonet' => 'stereonetOut',
		'fieldbook' => 'fieldbookOut',          // enhanced fieldbook (docs/Fieldbook_Design.md)
		'fieldbook_legacy' => 'legacyFieldbookOut',   // hidden: not in the builder's format list, exposable later (design D8)
		'images'    => 'downloadImages',
		'geojson'   => 'geoJSONOut',
		'gpkg'      => 'gpkgOut',
	);
	/** plugin-level formats (no straboOutputClass generator) => method */
	public static $pluginFormats = array(
		'sample_list' => 'writeSampleList',
	);
	public static $extras  = array('project_json', 'geologic_units');
	/** sample-list context columns, in order (SampleTabularService ignores them on import) */
	public static $sampleContextCols = array('field_project', 'field_dataset', 'field_spot_id', 'field_spot_name');
	public static $layouts = array('merged', 'split_project', 'split_dataset');

	private $db;
	private $neodb;
	private $cfg;

	public function __construct($db, $neodb, array $cfg = null)
	{
		$this->db = $db;
		$this->neodb = $neodb;
		$this->cfg = $cfg === null ? export_config() : $cfg;
		require_once dirname(__DIR__, 2) . '/includes/geophp/geoPHP.inc';
		require_once dirname(__DIR__, 2) . '/includes/UUID.php';
		require_once dirname(__DIR__, 2) . '/db/strabospotclass.php';
		require_once dirname(__DIR__, 2) . '/includes/straboClasses/straboOutputClass.php';
		require_once dirname(__DIR__, 2) . '/samplesdb/services/SampleTabularService.php';
	}

	public function key() { return 'field'; }

	/** Validate the format/layout/extras part of a recipe (also used by the create endpoint). */
	public static function validateOutput(array $recipe)
	{
		$formats = isset($recipe['formats']) && is_array($recipe['formats']) ? array_values(array_unique($recipe['formats'])) : array();
		$extras  = isset($recipe['extras'])  && is_array($recipe['extras'])  ? array_values(array_unique($recipe['extras']))  : array();
		foreach ($formats as $f) if (!isset(self::$formats[$f]) && !isset(self::$pluginFormats[$f])) throw new ExportJobError("Unknown format '$f'.");
		foreach ($extras as $e)  if (!in_array($e, self::$extras, true)) throw new ExportJobError("Unknown extra '$e'.");
		if (!$formats && !$extras) throw new ExportJobError('Choose at least one output format.');
		$layout = isset($recipe['layout']) ? (string)$recipe['layout'] : 'merged';
		if (!in_array($layout, self::$layouts, true)) throw new ExportJobError("Unknown layout '$layout'.");
		$fb = isset($recipe['fieldbook']) && is_array($recipe['fieldbook']) ? $recipe['fieldbook'] : array();
		foreach ($fb as $k => $v) {
			if (!isset(Fieldbook::$optionValues[$k])) throw new ExportJobError("Unknown fieldbook option '$k'.");
			if (!in_array(strtolower(trim((string)$v)), Fieldbook::$optionValues[$k], true)) throw new ExportJobError("Unknown fieldbook $k '$v'.");
		}
		return array('formats' => $formats, 'extras' => $extras, 'layout' => $layout,
			'sample_list_csv' => !empty($recipe['sample_list_csv']),
			'fieldbook' => Fieldbook::options(array('fieldbook' => $fb)));
	}

	public function run(array $job, array $recipe, $bundleDir, $progress)
	{
		$outSpec = self::validateOutput($recipe);
		$warnings = array();
		$readme = array();

		// ---- FIND
		$progress('resolve', 0, 0, 'resolving selection');
		$finder = new ExportFinder($this->db, $this->neodb, (int)$job['userpkey'], $this->cfg);
		$found = $finder->find($recipe);

		// ---- GATHER per project
		$projects = array();   // enriched scope entries
		$itemCount = 0; $childCount = 0; $dropped = 0;
		$nProj = count($found['projects']); $pi = 0;
		foreach ($found['projects'] as $sc) {
			$pi++;
			$strabo = $this->strabo($sc['owner']);
			$gath = new ExportGatherer($strabo, $this->cfg);
			$label = "project $pi of $nProj";
			$gr = $gath->gather($sc, $found['polygon'], function ($d, $t, $n) use ($progress, $label) {
				$progress('gather', $d, $t, "$label: $n");
			});
			$sc['final_ids'] = array_map(function ($f) { return (int)ExportGatherer::spotId($f); }, $gr['features']);
			$sc['whole_project'] = empty($recipe['scope']['datasets']) || !self::projectHasNamedDatasets($recipe, $sc);
			$meta = $this->projectMeta($sc);
			$sc['name'] = $meta['name'];
			$sc['dataset_names'] = $meta['datasets'];
			// spot id => {dataset id, name} for the final set (sample list context + per-dataset split)
			$sc['spot_map'] = $sc['final_ids'] ? $this->spotMap($sc) : array();
			$itemCount += $gr['item_count']; $childCount += $gr['child_count']; $dropped += $gr['dropped_spatial'];
			foreach ($gr['warnings'] as $w) $warnings[] = $w;
			$projects[] = $sc;
		}
		if ($itemCount === 0) {
			throw new ExportJobError('No spots matched this selection. Nothing to export.');
		}

		// ---- LAYOUT groups
		$groups = $this->layoutGroups($projects, $outSpec['layout'], $bundleDir);

		// ---- FORMAT
		$nFmt = count($outSpec['formats']) * count($groups); $fi = 0;
		$produced = array();
		$sampleTotal = 0; $sampleCross = 0; $sampleLists = array();
		foreach ($groups as $g) {
			if (!$g['members']) continue;
			if (!is_dir($g['dir']) && !mkdir($g['dir'], 0775, true)) throw new ExportJobError('Could not create ' . $g['dir']);
			$scopeGroups = array(); $allDs = array(); $firstDs = null; $firstOwner = null;
			foreach ($g['members'] as $m) {
				$scopeGroups[] = array('userpkey' => $m['owner'], 'dsids' => $m['dsids'], 'spot_ids' => $m['spot_ids']);
				foreach ($m['dsids'] as $d) $allDs[] = $d;
				if ($firstDs === null) { $firstDs = $m['dsids'][0]; $firstOwner = $m['owner']; }
			}
			$get = array(
				'dsids'        => (string)$firstDs,           // helper lookups (names) only; the fetch uses scope_groups
				'all_dsids'    => implode(',', array_unique($allDs)),
				'userpkey'     => $firstOwner,
				'scope_groups' => $scopeGroups,
				'attribution'  => 1,
				'book_tree'    => $g['members'],             // fieldbook: project / dataset sections + spot membership
				'fieldbook'    => $outSpec['fieldbook'],     // fieldbook options (map, photos, nets, page)
			);
			$strabo = $this->strabo($firstOwner);
			foreach ($outSpec['formats'] as $fmt) {
				$fi++;
				$progress('format:' . $fmt, $fi, $nFmt, 'writing ' . $fmt . ($g['label'] !== '' ? ' (' . $g['label'] . ')' : ''));
				if (isset(self::$pluginFormats[$fmt])) {
					$pm = self::$pluginFormats[$fmt];
					$r = $this->$pm($g, $outSpec, $progress);
					foreach ($r['produced'] as $pth) $produced[] = $pth;
					foreach ($r['warnings'] as $w) $warnings[] = $w;
					if (isset($r['samples'])) {
						$sampleTotal += $r['samples']; $sampleCross += $r['samples_cross'];
						$sampleLists[] = ($g['label'] !== '' ? $g['label'] . ': ' : '') . $r['samples'] . ' samples';
					}
					continue;
				}
				$out = new straboOutputClass($strabo, $get);
				$out->captureDir = $g['dir'];
				$out->progress = $progress;
				$method = self::$formats[$fmt];
				try {
					ob_start();                       // generators echo stray text; keep the worker log clean
					$out->$method();
					$stray = trim(ob_get_clean());
					if (!$out->captured) {
						$warnings[] = "$fmt" . ($g['label'] !== '' ? " ({$g['label']})" : '') . ': nothing produced' . ($stray !== '' ? " ($stray)" : '');
					}
					foreach ($out->captured as $c) $produced[] = $c['path'];
				} catch (ExportNoDataException $e) {
					ob_end_clean();
					$warnings[] = "$fmt" . ($g['label'] !== '' ? " ({$g['label']})" : '') . ': ' . $e->getMessage();
				}
			}
		}

		// ---- EXTRAS (whole-project selections)
		foreach ($outSpec['extras'] as $extra) {
			foreach ($projects as $sc) {
				if (!$sc['whole_project']) { $warnings[] = "$extra skipped for {$sc['name']}: only some of its datasets were selected"; continue; }
				$dir = $bundleDir . '/projects/' . $this->safeName($sc['name'], $sc['project_id']);
				if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new ExportJobError("Could not create $dir");
				$progress('format:' . $extra, 0, 0, "writing $extra for {$sc['name']}");
				$strabo = $this->strabo($sc['owner']);
				$out = new straboOutputClass($strabo, array('dsids' => '', 'userpkey' => $sc['owner']));
				$out->captureDir = $dir;
				ob_start();
				try {
					if ($extra === 'project_json') {
						$proj = $out->doiDataOut((int)$sc['project_id']);
						$out->captureString(json_encode($proj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 'project.json');
					} else {
						$r = $out->geologicUnitsOut((int)$sc['project_id']);
						if ($r === 'empty') $warnings[] = "geologic_units: {$sc['name']} has no geologic units";
					}
				} catch (ExportNoDataException $e) {
					$warnings[] = "$extra ({$sc['name']}): " . $e->getMessage();
				}
				ob_end_clean();
				foreach ($out->captured as $c) $produced[] = $c['path'];
			}
		}

		// ---- README lines
		$readme[] = 'Layout: ' . $outSpec['layout'];
		$readme[] = 'Formats: ' . implode(', ', $outSpec['formats']) . ($outSpec['extras'] ? '; extras: ' . implode(', ', $outSpec['extras']) : '');
		$readme[] = 'Selection evaluated ' . ($found['used_index'] ? 'through the StraboSearch index (synced ' . $found['index_synced_at'] . ')' : 'directly from the project data');
		if ($found['polygon']) $readme[] = 'Area filter: spots intersecting the drawn polygon' . ($dropped ? " ($dropped spots outside were left out)" : '');
		if (in_array('sample_list', $outSpec['formats'], true)) {
			$readme[] = 'Sample list: ' . $sampleTotal . ' samples linked to the exported spots'
				. ($sampleCross ? " ($sampleCross also linked in StraboMicro or StraboExperimental; see the linked_systems column)" : '')
				. (count($sampleLists) > 1 ? ' [' . implode('; ', $sampleLists) . ']' : '');
		}
		$crit = isset($recipe['criteria']) && is_array($recipe['criteria']) ? $recipe['criteria'] : array();
		foreach ($crit as $c) {
			if (!isset($c['id']) || strtoupper($c['id']) === 'U2') continue;
			$readme[] = 'Filter ' . strtoupper($c['id']) . (!empty($c['not']) ? ' NOT' : '') . ': ' . json_encode(isset($c['value']) ? $c['value'] : null, JSON_UNESCAPED_SLASHES);
		}
		$readme[] = '';
		$readme[] = 'Projects:';
		foreach ($projects as $sc) {
			$readme[] = '  ' . $sc['name'] . ' (id ' . $sc['project_id'] . ', owner ' . $sc['owner'] . ', access: ' . $sc['access'] . '): '
				. count($sc['final_ids']) . ' spots' . ($sc['whole_project'] ? ', whole project' : ', selected datasets only');
			foreach ($sc['dataset_ids'] as $d) {
				$readme[] = '    dataset ' . (isset($sc['dataset_names'][$d]) ? $sc['dataset_names'][$d] : $d) . ' (id ' . $d . ')';
			}
		}
		if ($found['used_index'] && $itemCount > 0) {
			$readme[] = '';
			$readme[] = 'Note: filtered selections are resolved against the search index; spots changed after the sync time above may be missing or extra.';
		}

		return array(
			'item_count'  => $itemCount,
			'child_count' => $childCount,
			'readme'      => $readme,
			'warnings'    => $warnings,
		);
	}

	// ------------------------------------------------------------------

	private $straboCache = array();
	private function strabo($owner)
	{
		$owner = (int)$owner;
		if (!isset($this->straboCache[$owner])) {
			$s = new StraboSpot($this->neodb, $owner, $this->db);
			if (method_exists($s, 'setuuid')) $s->setuuid(new UUID());
			$this->straboCache[$owner] = $s;
		}
		return $this->straboCache[$owner];
	}

	private static function projectHasNamedDatasets(array $recipe, array $sc)
	{
		foreach ($recipe['scope']['datasets'] as $d) {
			if ((string)$d['project_id'] === (string)$sc['project_id'] && (int)$d['owner'] === (int)$sc['owner']) return true;
		}
		return false;
	}

	/** Project name + dataset names, anchored through the owner's User node. */
	private function projectMeta(array $sc)
	{
		$rows = $this->neodb->query(
			"MATCH (u:User {userpkey: " . (int)$sc['owner'] . "})-[:HAS_PROJECT]->(p:Project {id: " . (int)$sc['project_id'] . "})
			 OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset)
			 RETURN p.desc_project_name AS n1, p.projectname AS n2, collect({id: toString(d.id), name: d.name}) AS ds");
		$name = 'project ' . $sc['project_id']; $ds = array();
		if ($rows && count($rows)) {
			$r = $rows[0];
			$n = $r->value('n1'); if ($n === null || $n === '') $n = $r->value('n2');
			if ($n !== null && $n !== '') $name = (string)$n;
			foreach ((array)$r->value('ds') as $d) {
				$d = is_array($d) ? $d : (array)$d;
				if (isset($d['id']) && $d['id'] !== null) $ds[(string)$d['id']] = isset($d['name']) && $d['name'] !== null && $d['name'] !== '' ? (string)$d['name'] : ('dataset ' . $d['id']);
			}
		}
		return array('name' => $name, 'datasets' => $ds);
	}

	public function safeName($name, $id)
	{
		$n = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim((string)$name));
		$n = trim($n, '_.');
		if ($n === '') $n = 'item';
		return substr($n, 0, 60) . '_' . $id;
	}

	/**
	 * Layout groups: each = {dir, label, members:[{owner, dsids, spot_ids}]}.
	 * split_dataset resolves per-dataset membership through the fetcher's
	 * own dataset restriction (spot_ids stays the project's final set).
	 */
	private function layoutGroups(array $projects, $layout, $bundleDir)
	{
		$groups = array();
		if ($layout === 'merged') {
			$members = array();
			foreach ($projects as $sc) {
				if (!$sc['final_ids']) continue;
				$members[] = $this->member($sc, $sc['dataset_ids']);
			}
			$groups[] = array('dir' => $bundleDir, 'label' => '', 'members' => $members);
			return $groups;
		}
		foreach ($projects as $sc) {
			if (!$sc['final_ids']) continue;
			$pdir = $bundleDir . '/projects/' . $this->safeName($sc['name'], $sc['project_id']);
			if ($layout === 'split_project') {
				$groups[] = array('dir' => $pdir, 'label' => $sc['name'], 'members' => array($this->member($sc, $sc['dataset_ids'])));
				continue;
			}
			foreach ($sc['dataset_ids'] as $d) {
				$dname = isset($sc['dataset_names'][$d]) ? $sc['dataset_names'][$d] : $d;
				$groups[] = array('dir' => $pdir . '/datasets/' . $this->safeName($dname, $d), 'label' => $sc['name'] . ' / ' . $dname,
					'members' => array($this->member($sc, array($d))));
			}
		}
		return $groups;
	}

	/** One layout-group member: owner + datasets + the project's final spot ids + context for the sample list. */
	private function member(array $sc, array $dsids)
	{
		return array('owner' => $sc['owner'], 'dsids' => $dsids, 'spot_ids' => $sc['final_ids'],
			'project_id' => $sc['project_id'], 'project_name' => $sc['name'],
			'dataset_names' => $sc['dataset_names'], 'spot_map' => $sc['spot_map']);
	}

	/**
	 * spot id => {ds: dataset id, name} for the project's final ids, anchored
	 * through the owner's User node (ids are not unique across accounts).
	 */
	private function spotMap(array $sc)
	{
		$map = array();
		$chunk = isset($this->cfg['gather_chunk']) ? max(1, (int)$this->cfg['gather_chunk']) : 2000;
		foreach (array_chunk(array_map('intval', $sc['final_ids']), $chunk) as $ids) {
			$rows = $this->neodb->query(
				"MATCH (u:User {userpkey: " . (int)$sc['owner'] . "})-[:HAS_PROJECT]->(p:Project {id: " . (int)$sc['project_id'] . "})
				 -[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
				 WHERE s.id IN [" . implode(',', $ids) . "]
				 RETURN toString(s.id) AS sid, toString(d.id) AS did, s.name AS name");
			if (!$rows) continue;
			foreach ($rows as $r) {
				$map[(string)$r->value('sid')] = array('ds' => (string)$r->value('did'), 'name' => (string)$r->value('name'));
			}
		}
		return $map;
	}

	/**
	 * sample_list format (design §7.3 / §8.1): the group's spot ids -> spine
	 * links (subsystem = field, anchored on the spot OWNER) -> the linked
	 * samples per sample owner through SampleTabularService::exportRowsForIds
	 * -> one samples.xlsx (+ samples.csv) in the group dir.
	 *
	 * @return array {produced: string[], warnings: string[], samples: int, samples_cross: int}
	 */
	private function writeSampleList(array $g, array $outSpec, $progress)
	{
		$produced = array(); $warnings = array();
		$label = $g['label'] !== '' ? " ({$g['label']})" : '';

		// sample owner => sample id => list of {spot id, spot name, project, dataset}
		$bySample = array();
		$chunk = isset($this->cfg['gather_chunk']) ? max(1, (int)$this->cfg['gather_chunk']) : 2000;
		foreach ($g['members'] as $m) {
			$want = array_map('strval', $m['dsids']);
			$ids = array();
			foreach ($m['spot_ids'] as $sid) {
				$sid = (string)$sid;
				// keep the spot when its dataset is one of the member's (split_dataset) or when unmapped
				if (isset($m['spot_map'][$sid]) && !in_array($m['spot_map'][$sid]['ds'], $want, true)) continue;
				$ids[] = $sid;
			}
			foreach (array_chunk($ids, $chunk) as $part) {
				$rows = $this->db->get_results_prepared(
					"SELECT sample_id, sample_userpkey, reference_id
					   FROM strabosamples.sample_subsystem_links
					  WHERE subsystem = 'field' AND reference_userpkey = $1 AND reference_id = ANY($2::text[])",
					array((int)$m['owner'], SampleTabularService::pgTextArray($part)));
				if (!is_array($rows)) continue;
				foreach ($rows as $r) {
					$sid = (string)$r->reference_id;
					$sm = isset($m['spot_map'][$sid]) ? $m['spot_map'][$sid] : array('ds' => '', 'name' => '');
					$dsName = $sm['ds'] !== '' && isset($m['dataset_names'][$sm['ds']]) ? $m['dataset_names'][$sm['ds']] : $sm['ds'];
					$bySample[(int)$r->sample_userpkey][(string)$r->sample_id][] = array(
						'spot_id' => $sid, 'spot_name' => $sm['name'], 'project' => $m['project_name'], 'dataset' => $dsName);
				}
			}
		}

		$svc = new SampleTabularService($this->db, $this->neodb);
		$rows = array(); $customKeys = array(); $cross = 0;
		foreach ($bySample as $owner => $samples) {
			$svc->setUserpkey($owner);
			$ex = $svc->exportRowsForIds(array_keys($samples), $owner);
			foreach ($ex['custom_keys'] as $k) $customKeys[$k] = true;
			foreach ($ex['rows'] as $row) {
				$ctx = array('field_project' => array(), 'field_dataset' => array(), 'field_spot_id' => array(), 'field_spot_name' => array());
				foreach ($samples[(string)$row['strabo_internal_id']] as $l) {
					$ctx['field_project'][$l['project']] = 1;
					$ctx['field_dataset'][$l['dataset']] = 1;
					$ctx['field_spot_id'][$l['spot_id']] = 1;
					$ctx['field_spot_name'][$l['spot_name']] = 1;
				}
				$row['_context'] = array();
				foreach ($ctx as $k => $vals) $row['_context'][$k] = implode('; ', array_keys($vals));
				if (preg_match('/\b(micro|experimental)\b/', (string)$row['linked_systems'])) $cross++;
				$rows[] = $row;
			}
		}
		$customKeys = array_keys($customKeys);
		sort($customKeys, SORT_NATURAL | SORT_FLAG_CASE);
		usort($rows, function ($a, $b) {
			$c = strcasecmp((string)$a['sample_id'], (string)$b['sample_id']);
			return $c !== 0 ? $c : strcmp((string)$a['strabo_internal_id'], (string)$b['strabo_internal_id']);
		});

		if (!$rows) {
			$warnings[] = 'sample_list' . $label . ': none of the exported spots has a linked sample';
			return array('produced' => $produced, 'warnings' => $warnings, 'samples' => 0, 'samples_cross' => 0);
		}

		$progress('format:sample_list', 0, 0, 'writing sample list' . $label . ' (' . count($rows) . ' samples)');
		$wb = $svc->buildWorkbook($rows, $customKeys, false, self::$sampleContextCols);
		if (!class_exists('PHPExcel_Writer_Excel2007')) {
			require_once dirname(__DIR__, 2) . '/PHPExcel/Writer/Excel2007.php';
		}
		$xlsx = $this->uniquePath($g['dir'], 'samples.xlsx');
		$writer = new PHPExcel_Writer_Excel2007($wb);
		$writer->save($xlsx);
		$wb->disconnectWorksheets();
		$produced[] = $xlsx;
		if (!empty($outSpec['sample_list_csv'])) {
			$csv = $this->uniquePath($g['dir'], 'samples.csv');
			file_put_contents($csv, $svc->buildCsv($rows, $customKeys, false, self::$sampleContextCols));
			$produced[] = $csv;
		}
		return array('produced' => $produced, 'warnings' => $warnings, 'samples' => count($rows), 'samples_cross' => $cross);
	}

	private function uniquePath($dir, $name)
	{
		$path = rtrim($dir, '/') . '/' . $name; $base = $path; $n = 1;
		while (file_exists($path)) { $n++; $path = preg_replace('/(\.[^.]*)?$/', "_$n$1", $base, 1); }
		return $path;
	}
}
