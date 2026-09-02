<?php
/**
 * File: includes/fieldbook/Fieldbook.php
 * Description: Entry point of the enhanced fieldbook (docs/Fieldbook_Design.md
 *              §5). Called by straboOutputClass::fieldbookOut() on both
 *              doors: the legacy download page (streams to the browser)
 *              and the Export Builder worker (captureDir set). Fetches
 *              exactly what the legacy generator fetched (same data path,
 *              same scope_groups handling), adds the book tree (project /
 *              dataset names + spot membership), builds the model, renders.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/FieldbookModel.php';
require_once __DIR__ . '/FieldbookRenderer.php';
require_once __DIR__ . '/FieldbookMaps.php';

class Fieldbook
{
	public static $optionValues = array(
		'map' => array('outdoors', 'satellite', 'geology', 'none'),
		'photos' => array('sheets', 'full', 'none'),
		'nets' => array('on', 'off'),
		'page' => array('letter', 'a4'),
	);

	/** Normalise an options array (recipe key or fb_* query params); unknown values fall back to the default. */
	public static function options(array $get)
	{
		$src = array();
		if (isset($get['fieldbook']) && is_array($get['fieldbook'])) $src = $get['fieldbook'];
		foreach (array('map', 'photos', 'nets', 'page') as $k) if (isset($get['fb_' . $k])) $src[$k] = $get['fb_' . $k];
		$out = array();
		foreach (self::$optionValues as $k => $vals) {
			$v = isset($src[$k]) ? strtolower(trim((string)$src[$k])) : $vals[0];
			$out[$k] = in_array($v, $vals, true) ? $v : $vals[0];
		}
		return $out;
	}

	/** Build + deliver. $out is the straboOutputClass instance (strabo, get, capture seam, progress). */
	public static function run($out)
	{
		$get = $out->get;
		$strabo = $out->strabo;
		$dsids = isset($get['dsids']) ? trim((string)$get['dsids']) : '';
		if ($dsids === '' && empty($get['scope_groups'])) return;   // legacy: silent no-op without dsids
		$allDs = !empty($get['all_dsids']) ? (string)$get['all_dsids'] : $dsids;
		$dsList = array_values(array_unique(array_filter(array_map('intval', explode(',', $allDs)))));
		if (!empty($get['scope_groups'])) {
			foreach ($get['scope_groups'] as $sg) foreach ((array)$sg['dsids'] as $d) if (!in_array((int)$d, $dsList, true)) $dsList[] = (int)$d;
		}
		$owner = isset($get['userpkey']) ? (int)$get['userpkey'] : 0;

		$json = $strabo->getDatasetSpotsSearch(null, $get);
		$features = ($json && !empty($json['features'])) ? $json['features'] : array();
		if (!$features) { $out->noData('No spots found for this search.'); return; }

		$tags = $dsList ? $strabo->getTagsFromDatasetIds(implode(',', $dsList)) : '';
		$tree = (!empty($get['book_tree']) && is_array($get['book_tree'])) ? self::normaliseTree($get['book_tree']) : self::treeFromNeo4j($strabo, $owner, $dsList);
		$notes = array();
		foreach ($tree as $member) foreach ($member['dsids'] as $d) {
			if (isset($notes[(string)$d])) continue;
			$dn = $strabo->getDailyNotesFromDatasetID((int)$d);
			$notes[(string)$d] = is_array($dn) ? $dn : array();
		}
		$meta = self::meta($strabo, $owner, $tree, $get);
		$model = FieldbookModel::build($features, $tags, $notes, $tree, $meta);
		$renderer = new FieldbookRenderer($model, isset($out->progress) ? $out->progress : null, self::maps($meta['options']));
		$pdf = $renderer->render();
		if ($out->capturing()) { $out->capturePdf($pdf, $model->filename); }
		else { $pdf->Output($model->filename, 'I'); }
	}

	/** TEST ONLY: config keys merged over the map builder config (stub tile server, cache dir, set). */
	public static $mapsOverride = array();

	/** Map figure builder for this book (design §6): tile proxy + cache + budget from export_config, set from the options. */
	public static function maps(array $options, array $override = array())
	{
		$override = $override + self::$mapsOverride;
		$cfg = array('set' => isset($options['map']) ? $options['map'] : 'outdoors');
		if (function_exists('export_config')) {
			$ec = export_config();
			$cfg['tile_base'] = $ec['tile_base']; $cfg['cache_dir'] = $ec['tilecache_root'];
			$cfg['budget'] = $ec['tile_budget']; $cfg['ttl_days'] = $ec['tile_ttl_days'];
		} else {
			require_once dirname(__DIR__, 2) . '/exportjobs/lib/export_config.php';
			return self::maps($options, $override);
		}
		return new FieldbookMaps($override + $cfg);
	}

	/** Export Builder layout-group members => tree (design §5). */
	public static function normaliseTree(array $members)
	{
		$tree = array();
		foreach ($members as $m) {
			$m = (array)$m;
			$spotMap = array();
			foreach ((array)(isset($m['spot_map']) ? $m['spot_map'] : array()) as $sid => $info) {
				$info = (array)$info;
				$spotMap[(string)$sid] = array('ds' => isset($info['ds']) ? (string)$info['ds'] : '', 'name' => isset($info['name']) ? (string)$info['name'] : '');
			}
			$tree[] = array(
				'owner' => isset($m['owner']) ? (int)$m['owner'] : 0,
				'project_id' => isset($m['project_id']) ? (string)$m['project_id'] : '',
				'project_name' => isset($m['project_name']) ? (string)$m['project_name'] : 'Project',
				'dsids' => array_map('strval', (array)(isset($m['dsids']) ? $m['dsids'] : array())),
				'dataset_names' => (array)(isset($m['dataset_names']) ? $m['dataset_names'] : array()),
				'spot_map' => $spotMap,
			);
		}
		return $tree;
	}

	/** Legacy door: project / dataset names + spot membership, anchored through the owner's User node. */
	public static function treeFromNeo4j($strabo, $owner, array $dsList)
	{
		if (!$dsList) return array();
		$ids = implode(',', array_map('intval', $dsList));
		$rows = $strabo->neodb->query(
			"MATCH (u:User {userpkey: " . (int)$owner . "})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset) WHERE d.id IN [$ids]
			 OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot)
			 RETURN p.id AS pid, coalesce(p.desc_project_name, p.projectname, '') AS pname, d.id AS did, d.name AS dname, collect(s.id) AS sids");
		$byProject = array();
		if ($rows) foreach ($rows as $r) {
			$pid = (string)$r->value('pid'); $did = (string)$r->value('did');
			if (!isset($byProject[$pid])) $byProject[$pid] = array('owner' => (int)$owner, 'project_id' => $pid, 'project_name' => (string)$r->value('pname') !== '' ? (string)$r->value('pname') : ('Project ' . $pid), 'dsids' => array(), 'dataset_names' => array(), 'spot_map' => array());
			$byProject[$pid]['dsids'][] = $did;
			$dname = (string)$r->value('dname');
			$byProject[$pid]['dataset_names'][$did] = $dname !== '' ? $dname : ('Dataset ' . $did);
			foreach ((array)$r->value('sids') as $sid) $byProject[$pid]['spot_map'][(string)$sid] = array('ds' => $did, 'name' => '');
		}
		$tree = array_values($byProject);
		usort($tree, function ($a, $b) { return strcasecmp($a['project_name'], $b['project_name']); });
		foreach ($tree as $i => $t) {
			$names = $t['dataset_names'];
			usort($tree[$i]['dsids'], function ($a, $b) use ($names) { return strcasecmp($names[$a], $names[$b]); });
		}
		return $tree;
	}

	/** Title / subtitle / owner / generated / doi / options. */
	public static function meta($strabo, $owner, array $tree, array $get)
	{
		$pn = array(); $dn = array();
		foreach ($tree as $t) { $pn[] = $t['project_name']; foreach ($t['dsids'] as $d) $dn[] = $t['dataset_names'][$d]; }
		$pn = array_values(array_unique($pn));
		if (count($dn) === 1) { $title = $dn[0]; $subtitle = count($pn) === 1 ? 'Project: ' . $pn[0] : ''; }
		elseif (count($pn) === 1) { $title = $pn[0]; $subtitle = count($dn) . ' datasets'; }
		elseif ($pn) { $title = 'StraboSpot field book'; $subtitle = count($pn) . ' projects, ' . count($dn) . ' datasets'; }
		else { $title = 'StraboSpot field book'; $subtitle = ''; }
		$ownerName = '';
		if ($owner && isset($strabo->db)) {
			$row = $strabo->db->get_row_prepared('SELECT firstname, lastname FROM users WHERE pkey = $1', array($owner));
			if ($row) $ownerName = trim((isset($row->firstname) ? $row->firstname : '') . ' ' . (isset($row->lastname) ? $row->lastname : ''));
		}
		return array(
			'title' => $title, 'subtitle' => $subtitle, 'owner' => $ownerName,
			'generated' => date('F j, Y'), 'doi' => '',
			'options' => self::options($get),
		);
	}
}
