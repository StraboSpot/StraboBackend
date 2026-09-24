<?php
/**
 * File: includes/fieldbook/FieldbookModel.php
 * Description: Document model of the enhanced fieldbook
 *              (docs/Fieldbook_Design.md §4, §5). Pure data: builds the
 *              Book > Project > Dataset > Day > Spot tree plus the summary
 *              tables from the legacy feature collection, the project tags
 *              (every tag, M7) and memos (reports the reader may see, M7),
 *              and the daily notes. No PDF calls; FieldbookRenderer lays it
 *              out.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/FieldbookProps.php';

class FieldbookModel
{
	public $meta;        // title, subtitle, owner, generated, doi, options
	public $projects = array();
	public $counts = array('spots' => 0, 'children' => 0, 'images' => 0, 'samples' => 0, 'orientations' => 0, 'days' => 0, 'memos' => 0, 'hiddenMemos' => 0, 'tags' => 0, 'units' => 0);   // tags = tags that are not geologic units; units counted apart (users treat them as different things)
	public $dateRange = array(null, null);   // [first day key, last day key]
	public $summary = array('units' => array(), 'tags' => array(), 'samples' => array(), 'images' => array());
	public $notes = array();  // build notes for the colophon
	public $filename = 'strabospot_fieldbook.pdf';

	/**
	 * @param array $features   legacy feature collection 'features' array
	 * @param mixed $tags       getTagsFromDatasetIds() result ("" or array of stdClass)
	 * @param array $notesByDs  dataset id => array of {date, notes}
	 * @param array $tree       [{owner, project_id, project_name, dsids[], dataset_names[id=>name], spot_map[id=>{ds,name}]}]
	 * @param array $meta       title/subtitle/owner/generated/doi/options
	 * @param array $projectData Fieldbook::projectData() result: "owner|project_id" => {tags, reports, hidden, authors, spot_names} (M7)
	 */
	public static function build(array $features, $tags, array $notesByDs, array $tree, array $meta, array $projectData = array())
	{
		$m = new FieldbookModel();
		$m->meta = $meta + array('title' => 'Field book', 'subtitle' => '', 'owner' => '', 'generated' => date('F j, Y'), 'doi' => '', 'options' => array());
		$tags = is_array($tags) ? $tags : array();

		// ---- tree skeleton
		$dsIndex = array();   // dataset id => [pi, di]
		foreach ($tree as $member) {
			$pid = (string)$member['project_id'];
			$pi = null;
			foreach ($m->projects as $i => $p) if ($p['id'] === $pid && $p['owner'] == $member['owner']) { $pi = $i; break; }
			if ($pi === null) {
				$m->projects[] = array('id' => $pid, 'owner' => $member['owner'], 'name' => (string)$member['project_name'], 'datasets' => array(), 'tags' => array(), 'memos' => array(), 'hiddenMemos' => 0);
				$pi = count($m->projects) - 1;
			}
			foreach ($member['dsids'] as $d) {
				$d = (string)$d;
				if (isset($dsIndex[$d])) continue;
				$name = isset($member['dataset_names'][$d]) ? (string)$member['dataset_names'][$d] : ('dataset ' . $d);
				$m->projects[$pi]['datasets'][] = array('id' => $d, 'name' => $name, 'days' => array(), 'spotCount' => 0, 'notes' => isset($notesByDs[$d]) ? $notesByDs[$d] : array());
				$dsIndex[$d] = array($pi, count($m->projects[$pi]['datasets']) - 1);
			}
		}
		if (!$m->projects) {
			$m->projects[] = array('id' => '', 'owner' => 0, 'name' => $m->meta['title'], 'datasets' => array(array('id' => '', 'name' => $m->meta['title'], 'days' => array(), 'spotCount' => 0, 'notes' => array())), 'tags' => array(), 'memos' => array(), 'hiddenMemos' => 0);
			$dsIndex[''] = array(0, 0);
		}
		$spotToDs = array();
		foreach ($tree as $member) foreach ((array)$member['spot_map'] as $sid => $info) {
			$info = (array)$info;
			if (isset($info['ds']) && isset($dsIndex[(string)$info['ds']])) $spotToDs[(string)$sid] = (string)$info['ds'];
		}
		$firstDs = key($dsIndex);

		// ---- spot blocks
		$blocks = array(); $byId = array();
		foreach ($features as $f) {
			$b = self::spotBlock($f, $tags);
			$blocks[] = $b;
			$byId[$b['id']] = count($blocks) - 1;
		}
		// children under their image basemap; orphans stay top-level with a note
		$imageOwner = array();   // image id => block index
		foreach ($blocks as $i => $b) foreach ($b['images'] as $ii => $img) $imageOwner[(string)$img['id']] = array($i, $ii);
		$childOf = array();
		foreach ($blocks as $i => $b) {
			if ($b['childOf'] === '') continue;
			if (isset($imageOwner[$b['childOf']])) { $childOf[$i] = $imageOwner[$b['childOf']]; }
			else { $blocks[$i]['orphan'] = true; }
		}
		// nesting (children may themselves carry images with children)
		$attach = function (&$parent, $child) { $parent['children'][] = $child; };
		$topLevel = array();
		foreach ($blocks as $i => $b) if (!isset($childOf[$i])) $topLevel[] = $i;
		// attach deepest-first so nested children travel with their parent
		$children = array();
		foreach ($childOf as $i => $loc) $children[$loc[0]][$loc[1]][] = $i;
		$build = null;
		$build = function ($i) use (&$build, &$blocks, &$children) {
			$b = $blocks[$i];
			if (isset($children[$i])) {
				foreach ($children[$i] as $ii => $kids) {
					foreach ($kids as $k) $b['images'][$ii]['children'][] = $build($k);
				}
			}
			return $b;
		};

		// ---- assign to datasets and days
		foreach ($topLevel as $i) {
			$b = $build($i);
			$d = isset($spotToDs[$b['id']]) ? $spotToDs[$b['id']] : $firstDs;
			if (!isset($spotToDs[$b['id']]) && count($dsIndex) > 1) $b['unassigned'] = true;
			list($pi, $di) = $dsIndex[$d];
			$ds =& $m->projects[$pi]['datasets'][$di];
			if (!isset($ds['days'][$b['dayKey']])) {
				$ds['days'][$b['dayKey']] = array('key' => $b['dayKey'], 'label' => $b['dayLabel'], 'spots' => array(), 'notes' => array());
			}
			$ds['days'][$b['dayKey']]['spots'][] = $b;
			unset($ds);
		}

		// ---- order, number, count, summaries
		foreach ($m->projects as $pi => $p) {
			foreach ($p['datasets'] as $di => $ds) {
				ksort($ds['days']);
				$ds['days'] = array_values($ds['days']);
				foreach ($ds['days'] as $dayi => $day) {
					usort($day['spots'], function ($a, $b) { return strcmp($a['sortKey'], $b['sortKey']); });
					$n = 0;
					foreach ($day['spots'] as $si => $s) { $n++; $day['spots'][$si]['n'] = $n; }
					foreach ((array)$ds['notes'] as $dn) {
						$dn = (array)$dn;
						if (isset($dn['date']) && substr((string)$dn['date'], 0, 10) === $day['key']) $day['notes'][] = isset($dn['notes']) ? (string)$dn['notes'] : '';
					}
					$ds['days'][$dayi] = $day;
					$m->counts['days']++;
					if ($m->dateRange[0] === null || $day['key'] < $m->dateRange[0]) $m->dateRange[0] = $day['key'];
					if ($m->dateRange[1] === null || $day['key'] > $m->dateRange[1]) $m->dateRange[1] = $day['key'];
					foreach ($day['spots'] as $s) $m->tally($s, $p['name'], $ds['name'], $day['label']);
				}
				$ds['spotCount'] = 0;
				foreach ($ds['days'] as $day) $ds['spotCount'] += count($day['spots']);
				unset($ds['notes']);
				$m->projects[$pi]['datasets'][$di] = $ds;
			}
		}
		// ---- project-level data (M7): every project tag + the memos the reader may see
		$names = array();   // spot id => name for every spot in the book (children included)
		foreach ($blocks as $b) $names[$b['id']] = $b['name'];
		foreach ($tree as $member) foreach ((array)$member['spot_map'] as $sid => $info) { $info = (array)$info; if (!isset($names[(string)$sid]) && isset($info['name']) && (string)$info['name'] !== '') $names[(string)$sid] = (string)$info['name']; }
		foreach ($m->projects as $pi => $p) {
			$key = $p['owner'] . '|' . $p['id'];
			if (!isset($projectData[$key])) continue;
			$pd = $projectData[$key];
			$m->projects[$pi]['tags'] = self::projectTags((array)$pd['tags'], $byId, $blocks);
			$m->projects[$pi]['memos'] = self::memos($pd, $byId, $names, $m->projects[$pi]['tags']);
			$m->projects[$pi]['hiddenMemos'] = isset($pd['hidden']) ? (int)$pd['hidden'] : 0;
			$m->counts['memos'] += count($m->projects[$pi]['memos']);
			$m->counts['hiddenMemos'] += $m->projects[$pi]['hiddenMemos'];
			foreach ($m->projects[$pi]['tags'] as $t) {
				$table = $t['type'] === 'geologic_unit' ? 'units' : 'tags';
				$m->counts[$table]++;
				if (isset($m->summary[$table][$t['name']])) continue;   // tallied from the spots already
				$m->summary[$table][$t['name']] = array('name' => $t['name'], 'type' => $t['type'], 'rows' => $t['rows'], 'count' => count($t['inBook']));
			}
			foreach ($m->projects[$pi]['memos'] as $memo) {
				$m->counts['images'] += count($memo['images']);
				foreach ($memo['images'] as $img) $m->summary['images'][] = array('title' => $img['title'] !== '' ? $img['title'] : ('Image ' . $img['id']), 'caption' => $img['caption'], 'spot' => 'Memo: ' . $memo['subject'], 'spotId' => '');
			}
		}
		if ($m->counts['hiddenMemos']) $m->notes[] = 'Memos: ' . $m->counts['hiddenMemos'] . ($m->counts['hiddenMemos'] === 1 ? ' memo is' : ' memos are') . ' not shown (addressed to a narrower audience than this reader).';
		ksort($m->summary['units']); ksort($m->summary['tags']);
		$m->filename = self::filenameFor($m);
		return $m;
	}

	/**
	 * Every tag of a project (M7): name, type, the tag's own fields, which of its spots are in this book
	 * (spot tags + sub-feature tags), how many are outside it. Order: geologic units first, then by name.
	 */
	public static function projectTags(array $tags, array $byId, array $blocks)
	{
		$out = array();
		foreach ($tags as $t) {
			$t = (array)$t;
			$rows = array();
			foreach ($t as $k => $v) {
				if (in_array($k, array('date', 'spots', 'features', 'id', 'name', 'type'), true)) continue;
				if ($v === null || $v === '' || $v === array()) continue;
				FieldbookProps::walk($v, $rows, 0, $k);
			}
			$sids = array();
			foreach ((array)(isset($t['spots']) ? $t['spots'] : array()) as $sid) $sids[(string)$sid] = true;
			$features = 0;
			if (!empty($t['features']) && (is_array($t['features']) || is_object($t['features']))) foreach ((array)$t['features'] as $sid => $fids) { $sids[(string)$sid] = true; $features += count((array)$fids); }
			$inBook = array(); $outside = 0;
			foreach (array_keys($sids) as $sid) { if (isset($byId[$sid])) $inBook[] = array('id' => $sid, 'name' => $blocks[$byId[$sid]]['name'], 'inBook' => true); else $outside++; }
			$out[] = array(
				'id' => isset($t['id']) ? (string)$t['id'] : '',
				'name' => isset($t['name']) && (string)$t['name'] !== '' ? (string)$t['name'] : 'tag',
				'type' => isset($t['type']) ? (string)$t['type'] : '',
				'rows' => $rows, 'inBook' => $inBook, 'outside' => $outside, 'features' => $features,
			);
		}
		usort($out, function ($a, $b) {
			$ua = $a['type'] === 'geologic_unit' ? 0 : 1; $ub = $b['type'] === 'geologic_unit' ? 0 : 1;
			if ($ua !== $ub) return $ua - $ub;
			return strcasecmp($a['name'], $b['name']);
		});
		return $out;
	}

	/**
	 * Memos (reports) of a project the reader may see (M7), in creation order: subject, type, audience, author,
	 * dates, notes, the spots they cite (linked when in the book), tags by name, images (rendered like a spot's
	 * photos), comments, and any other field the app stored ("rows").
	 */
	public static function memos(array $pd, array $byId, array $names, array $projectTags)
	{
		$tagNames = array();
		foreach ($projectTags as $t) if ($t['id'] !== '') $tagNames[$t['id']] = $t['name'];
		$authors = isset($pd['authors']) ? (array)$pd['authors'] : array();
		$spotNames = isset($pd['spot_names']) ? (array)$pd['spot_names'] : array();
		$known = array('id', 'subject', 'report_type', 'report_privacy', 'privacy', 'notes', 'created_timestamp', 'updated_timestamp', 'modified_timestamp', 'images', 'spots', 'tags', 'comments', 'straboUserId', '_author', '_audience');
		$aud = array('anyone' => 'Anyone', 'collaborators' => 'Collaborators', 'only_me' => 'Only me');
		$out = array();
		foreach ((array)$pd['reports'] as $rep) {
			$rep = (array)$rep;
			$rows = array();
			foreach ($rep as $k => $v) {
				if (in_array($k, $known, true)) continue;
				if ($v === null || $v === '' || $v === array()) continue;
				FieldbookProps::walk($v, $rows, 0, $k);
			}
			$spots = array();
			foreach ((array)(isset($rep['spots']) ? $rep['spots'] : array()) as $sid) {
				$sid = (string)$sid;
				$name = isset($names[$sid]) ? $names[$sid] : (isset($spotNames[$sid]) ? (string)$spotNames[$sid] : '');
				$spots[] = array('id' => $sid, 'name' => $name !== '' ? $name : ('Spot ' . $sid), 'inBook' => isset($byId[$sid]));
			}
			$tags = array();
			foreach ((array)(isset($rep['tags']) ? $rep['tags'] : array()) as $tid) { $tid = (string)$tid; $tags[] = isset($tagNames[$tid]) ? $tagNames[$tid] : ('Tag ' . $tid); }
			$comments = array();
			foreach ((array)(isset($rep['comments']) ? $rep['comments'] : array()) as $c) {
				$c = (array)$c;
				$comments[] = array(
					'name' => isset($c['name']) ? (string)$c['name'] : '',
					'text' => isset($c['text']) ? (string)$c['text'] : '',
					'date' => isset($c['created_timestamp']) ? self::epochDate($c['created_timestamp']) : '',
				);
			}
			$author = isset($rep['_author']) ? (int)$rep['_author'] : 0;
			$created = isset($rep['created_timestamp']) ? self::epochDate($rep['created_timestamp']) : '';
			$updated = isset($rep['updated_timestamp']) ? self::epochDate($rep['updated_timestamp']) : '';
			$out[] = array(
				'id' => isset($rep['id']) ? (string)$rep['id'] : '',
				'subject' => isset($rep['subject']) && trim((string)$rep['subject']) !== '' ? trim((string)$rep['subject']) : 'Untitled memo',
				'type' => isset($rep['report_type']) ? FieldbookProps::humanize($rep['report_type']) : '',
				'audience' => isset($rep['_audience']) && isset($aud[$rep['_audience']]) ? $aud[$rep['_audience']] : '',
				'author' => $author && isset($authors[$author]) && $authors[$author] !== '' ? (string)$authors[$author] : '',
				'created' => $created, 'updated' => $updated !== $created ? $updated : '',
				'sortKey' => (isset($rep['created_timestamp']) ? sprintf('%020d', (int)substr(preg_replace('/\D/', '', (string)$rep['created_timestamp']), 0, 13)) : sprintf('%020d', 0)) . '-' . (isset($rep['id']) ? (string)$rep['id'] : ''),
				'notes' => isset($rep['notes']) ? trim((string)$rep['notes']) : '',
				'spots' => $spots, 'tags' => $tags, 'images' => self::imageBlocks(isset($rep['images']) ? $rep['images'] : array()),
				'comments' => $comments, 'rows' => $rows,
			);
		}
		usort($out, function ($a, $b) { return strcmp($a['sortKey'], $b['sortKey']); });
		return $out;
	}

	/** Epoch seconds or milliseconds => "Month d, Y H:i UTC" ('' when not a timestamp). */
	public static function epochDate($v)
	{
		$v = (string)$v;
		if (!preg_match('/^\d{10}(\d{3})?$/', $v)) return FieldbookProps::humanize($v);
		return gmdate('F j, Y H:i', (int)substr($v, 0, 10)) . ' UTC';
	}

	/** Image attribute blocks from an app images array (spot images and memo images share the shape). */
	public static function imageBlocks($images)
	{
		$out = array();
		foreach ((array)$images as $img) {
			$img = (array)$img;
			$rows = array();
			foreach ($img as $k => $v) {
				if (in_array($k, array('id', 'self', 'annotated', 'title', 'width', 'height', 'image_type', 'caption'), true)) continue;
				if ($v === null || $v === '' || $v === array()) continue;
				FieldbookProps::walk($v, $rows, 0, $k);
			}
			$out[] = array(
				'id' => isset($img['id']) ? (string)$img['id'] : '',
				'title' => isset($img['title']) ? (string)$img['title'] : '',
				'caption' => isset($img['caption']) ? (string)$img['caption'] : '',
				'type' => isset($img['image_type']) ? (string)$img['image_type'] : '',   // raw name: the renderer branches on it (photo / sketch)
				'typeLabel' => isset($img['image_type']) && (string)$img['image_type'] !== ''
					? FieldVocab::labelText(FieldVocab::formsFor('images'), 'image_type', (string)$img['image_type'], ', ', array('FieldbookProps', 'humanize')) : '',
				'annotated' => !empty($img['annotated']),
				'width' => isset($img['width']) ? (int)$img['width'] : 0, 'height' => isset($img['height']) ? (int)$img['height'] : 0,
				'rows' => $rows, 'children' => array(),
			);
		}
		return $out;
	}

	/**
	 * Label of a sub-feature of a spot by id (a tag attached to an orientation, a sample, an image...):
	 * "Bedding (orientation)"; the bare id when nothing in the properties carries it.
	 */
	public static function featureLabel(array $props, $fid, $parentKey = '')
	{
		$fid = (string)$fid;
		foreach ($props as $k => $v) {
			if (is_object($v)) $v = (array)$v;
			if (!is_array($v)) continue;
			if (isset($v['id']) && is_scalar($v['id']) && (string)$v['id'] === $fid) {
				$ctx = is_int($k) ? $parentKey : (string)$k;
				$ctx = preg_replace('/^json_/', '', $ctx);
				$ctx = preg_replace('/(_data|s)$/', '', $ctx);
				$title = 'Feature ' . $fid;
				foreach (array('label', 'name', 'sample_id_name', 'feature_type', 'title', 'type') as $tk) {   // feature_type before type: "Bedding", not "Planar orientation"
					if (isset($v[$tk]) && is_scalar($v[$tk]) && (string)$v[$tk] !== '') { $title = FieldbookProps::title($v[$tk]); break; }
				}
				return $title . ($ctx !== '' ? ' (' . strtolower(FieldbookProps::label($ctx)) . ')' : '');
			}
			$found = self::featureLabel($v, $fid, is_int($k) ? $parentKey : (string)$k);
			if ($found !== '') return $found;
		}
		return $parentKey === '' ? 'Feature ' . $fid : '';
	}

	/** Counts + summary tables, recursing into children. */
	private function tally(array $s, $pname, $dsname, $daylabel, $isChild = false)
	{
		$this->counts[$isChild ? 'children' : 'spots']++;
		$this->counts['orientations'] += $s['orientationCount'];
		$this->counts['samples'] += count($s['samples']);
		$this->counts['images'] += count($s['images']);
		foreach ($s['units'] as $u) {
			$k = $u['name'];
			if (!isset($this->summary['units'][$k])) $this->summary['units'][$k] = array('name' => $u['name'], 'rows' => $u['rows'], 'count' => 0);
			$this->summary['units'][$k]['count']++;
		}
		foreach ($s['tags'] as $t) {
			$k = $t['name'];
			if (!isset($this->summary['tags'][$k])) $this->summary['tags'][$k] = array('name' => $t['name'], 'type' => $t['type'], 'rows' => $t['rows'], 'count' => 0);
			$this->summary['tags'][$k]['count']++;
		}
		foreach ($s['samples'] as $smp) $this->summary['samples'][] = array('title' => $smp['title'], 'spot' => $s['name'], 'spotId' => $s['id'], 'day' => $daylabel);
		foreach ($s['images'] as $img) {
			$this->summary['images'][] = array('title' => $img['title'] !== '' ? $img['title'] : ('Image ' . $img['id']), 'caption' => $img['caption'], 'spot' => $s['name'], 'spotId' => $s['id']);
			foreach ($img['children'] as $c) $this->tally($c, $pname, $dsname, $daylabel, true);
		}
	}

	/** One spot feature => block (designed fields + generic families). */
	public static function spotBlock(array $f, array $tags)
	{
		$raw = isset($f['properties']) ? (array)$f['properties'] : array();
		// Choice values print as their form labels (Field choice translation): render from a
		// display copy; $raw keeps the stored names for logic (symbol slots, image types).
		$p = (array)FieldVocab::displayProperties($raw, array('images:image_type'));
		$geom = isset($f['geometry']) ? $f['geometry'] : null;
		if (is_array($geom)) $geom = (object)$geom;
		$id = isset($p['id']) ? (string)$p['id'] : '';
		$b = array(
			'id' => $id, 'n' => 0,
			'name' => isset($p['name']) && (string)$p['name'] !== '' ? (string)$p['name'] : ('Spot ' . $id),
			'geomType' => isset($p['geometrytype']) && $p['geometrytype'] !== '' ? (string)$p['geometrytype'] : ($geom && isset($geom->type) ? (string)$geom->type : ''),
			'childOf' => isset($p['image_basemap']) ? (string)$p['image_basemap'] : '',
			'inStrat' => isset($p['strat_section_id']) && (string)$p['strat_section_id'] !== '',
			'orphan' => false, 'unassigned' => false,
			'notes' => isset($p['notes']) ? (string)$p['notes'] : '',
			'meta' => array(), 'coords' => array(),
			'orientations' => array(), 'orientationCount' => 0,
			'samples' => array(), 'units' => array(), 'tags' => array(), 'images' => array(), 'families' => array(),
			'geometry' => null,   // GeoJSON (assoc) for the maps: top-level located spots only
			'point' => null,      // [lon, lat] representative point for markers
			'pixel' => null,      // GeoJSON (assoc) in image pixels for spots drawn on an image basemap (overlays, M4)
		);
		if ($b['childOf'] !== '') {
			// the app keeps the pixel geometry as original_geometry (x from the left, y from the bottom) and
			// converts it to lon/lat in geometry; older uploads carry the pixels in geometry itself
			$og = isset($f['original_geometry']) ? $f['original_geometry'] : null;
			if (is_object($og)) $og = json_decode(json_encode($og), true);
			if (is_array($og) && isset($og['type']) && isset($og['coordinates'])) $b['pixel'] = $og;
			elseif ($geom && isset($geom->type)) {
				$pt = self::representativePoint($geom);
				if ($pt && (abs($pt[0]) > 180 || abs($pt[1]) > 90)) $b['pixel'] = json_decode(json_encode($geom), true);
			}
		}
		// day + sort key from the creation timestamp buried in the id (legacy rule), else the app date
		$ut = null;
		if (preg_match('/^\d{13,}$/', $id)) $ut = (int)substr($id, 0, 10);
		elseif (isset($p['date']) && (string)$p['date'] !== '' && ($t = strtotime((string)$p['date'])) !== false) $ut = $t;
		if ($ut === null) $ut = 0;
		$b['dayKey'] = $ut ? date('Y-m-d', $ut) : '0000-00-00';
		$b['dayLabel'] = $ut ? date('l, F j, Y', $ut) : 'Undated';
		$b['sortKey'] = sprintf('%020d', $ut) . '-' . $id;
		$b['meta'][] = array('Created', $ut ? date('F j, Y', $ut) : '');
		if (isset($p['modified_timestamp']) && preg_match('/^\d{10}/', (string)$p['modified_timestamp'])) {
			$b['meta'][] = array('Last modified', date('F j, Y', (int)substr((string)$p['modified_timestamp'], 0, 10)));
		}
		if (isset($p['date']) && (string)$p['date'] !== '' && ($t = strtotime((string)$p['date'])) !== false) $b['meta'][] = array('Date', date('F j, Y', $t));
		if (isset($p['time']) && (string)$p['time'] !== '' && ($t = strtotime((string)$p['time'])) !== false) $b['meta'][] = array('Time', date('H:i', $t) . ' UTC');
		// location
		if ($b['childOf'] !== '') {
			// the app stores a real-world location for spots drawn on an image basemap (older
			// uploads may carry image pixels instead: outside the lon/lat range => "image position")
			$b['coords'][] = array('Location', 'On image basemap ' . $b['childOf']);
			$pt = $geom && isset($geom->type) ? self::representativePoint($geom) : null;
			if ($pt && abs($pt[0]) <= 180 && abs($pt[1]) <= 90) {
				$b['coords'][] = array('Longitude', sprintf('%.5f', $pt[0])); $b['coords'][] = array('Latitude', sprintf('%.5f', $pt[1]));
			} elseif ($pt) {
				$b['coords'][] = array('Image position', sprintf('%s, %s px', rtrim(rtrim(sprintf('%.2f', $pt[0]), '0'), '.'), rtrim(rtrim(sprintf('%.2f', $pt[1]), '0'), '.')));
			}
		} elseif ($b['inStrat']) {
			$b['coords'][] = array('Location', 'In strat section ' . (string)$p['strat_section_id']);
		} elseif ($geom && isset($geom->type)) {
			$pt = self::representativePoint($geom);
			if ($pt && abs($pt[0]) <= 180 && abs($pt[1]) <= 90) {
				$label = strtolower($geom->type) === 'point' ? '' : ' (centroid)';
				$b['coords'][] = array('Longitude' . $label, sprintf('%.5f', $pt[0]));
				$b['coords'][] = array('Latitude' . $label, sprintf('%.5f', $pt[1]));
				$b['point'] = $pt;
				$b['geometry'] = json_decode(json_encode($geom), true);
			}
		}
		foreach (array('altitude' => 'Altitude', 'gps_accuracy' => 'GPS accuracy', 'altitude_accuracy' => 'Altitude accuracy', 'spot_radius' => 'Spot radius') as $k => $lab) {
			if (isset($p[$k]) && (string)$p[$k] !== '') $b['coords'][] = array($lab, FieldbookProps::humanize($p[$k]) . ($k === 'altitude' ? ' m' : ''));
		}
		if (isset($p['lat']) && isset($p['lng']) && !$b['coords']) {
			$b['coords'][] = array('Longitude', (string)$p['lng']); $b['coords'][] = array('Latitude', (string)$p['lat']);
		}
		// orientations
		if (!empty($p['orientation_data'])) {
			$rawOs = array_values((array)$raw['orientation_data']);
			foreach (array_values((array)$p['orientation_data']) as $oi => $o) {
				$ro = isset($rawOs[$oi]) ? (array)$rawOs[$oi] : array();
				$row = self::orientationRow((array)$o, $ro);
				$b['orientationCount']++;
				if (!empty($o->associated_orientation) || (is_array($o) && !empty($o['associated_orientation']))) {
					$ao = is_object($o) ? $o->associated_orientation : $o['associated_orientation'];
					$rawAo = isset($ro['associated_orientation']) ? array_values((array)$ro['associated_orientation']) : array();
					foreach (array_values((array)$ao) as $ai => $a) {
						$row['children'][] = self::orientationRow((array)$a, isset($rawAo[$ai]) ? (array)$rawAo[$ai] : array());
						$b['orientationCount']++;
					}
				}
				$b['orientations'][] = $row;
			}
		}
		// samples
		if (!empty($p['samples'])) {
			foreach ((array)$p['samples'] as $s) {
				$s = (array)$s;
				$rows = array();
				foreach ($s as $k => $v) {
					if ($k === 'id' || $k === 'label') continue;
					if ($v === null || $v === '' || $v === array()) continue;
					FieldbookProps::walk($v, $rows, 0, $k);
				}
				$b['samples'][] = array('title' => FieldbookProps::itemTitle($s, 'Sample'), 'rows' => $rows);
			}
		}
		// tags + geologic units (project tags listing this spot). getTagsFromDatasetIds is not
		// user-anchored, so a collaborated project contributes one copy per collaborator: dedupe.
		// A tag lists the spots it is on (spots) and, for a tag on a sub-feature, features = {spot id: [feature ids]} (M7).
		$seenTag = array();
		foreach ($tags as $t) {
			$t = (array)FieldVocab::displayTag($t);
			$hit = false;
			if (!empty($t['spots'])) foreach ((array)$t['spots'] as $sid) if ((string)$sid === $id) { $hit = true; break; }
			$on = array();
			if (!empty($t['features']) && (is_array($t['features']) || is_object($t['features']))) {
				foreach ((array)$t['features'] as $sid => $fids) if ((string)$sid === $id) foreach ((array)$fids as $fid) $on[] = self::featureLabel($p, $fid);
			}
			if (!$hit && !$on) continue;
			$rows = array();
			foreach ($t as $k => $v) {
				if (in_array($k, array('date', 'spots', 'features', 'id', 'name', 'type'), true)) continue;
				if ($v === null || $v === '' || $v === array()) continue;
				FieldbookProps::walk($v, $rows, 0, $k);
			}
			$item = array('name' => isset($t['name']) ? (string)$t['name'] : 'tag', 'type' => isset($t['type']) ? (string)$t['type'] : '', 'rows' => $rows, 'on' => $on);
			$sig = $item['type'] . '|' . $item['name'] . '|' . json_encode($rows) . '|' . json_encode($on);
			if (isset($seenTag[$sig])) continue;
			$seenTag[$sig] = true;
			if ($item['type'] === 'geologic_unit') $b['units'][] = $item; else $b['tags'][] = $item;
		}
		// images (photos rendered in M4; the attributes are listed now so nothing is lost)
		if (!empty($p['images'])) $b['images'] = self::imageBlocks($p['images']);
		$b['families'] = FieldbookProps::families($p);
		return $b;
	}

	/**
	 * @param array $o    orientation from the display copy (labels)
	 * @param array $raw  the same orientation as stored (featureKey = raw feature_type for the nets' symbol slots)
	 */
	private static function orientationRow(array $o, array $raw = array())
	{
		$type = isset($o['type']) ? (string)$o['type'] : '';
		$kind = $type === 'planar_orientation' ? 'Plane' : ($type === 'linear_orientation' ? 'Line' : ($type === 'tabular_orientation' ? 'Tabular zone' : FieldbookProps::humanize($type)));
		$planar = ($type !== 'linear_orientation');
		$row = array(
			'kind' => $kind, 'planar' => $planar,
			'feature' => isset($o['feature_type']) ? FieldbookProps::humanize($o['feature_type']) : '',
			'featureKey' => isset($raw['feature_type']) && is_scalar($raw['feature_type']) ? (string)$raw['feature_type'] : '',
			'a' => $planar ? (isset($o['strike']) ? (string)$o['strike'] : '') : (isset($o['trend']) ? (string)$o['trend'] : ''),
			'b' => $planar ? (isset($o['dip']) ? (string)$o['dip'] : '') : (isset($o['plunge']) ? (string)$o['plunge'] : ''),
			'dipdir' => isset($o['dip_direction']) ? (string)$o['dip_direction'] : '',
			'quality' => isset($o['quality']) ? FieldbookProps::humanize($o['quality']) : '',
			'facing' => isset($o['facing']) ? FieldbookProps::humanize($o['facing']) : '',
			'notes' => isset($o['notes']) ? (string)$o['notes'] : '',
			'more' => array(), 'children' => array(),
			'raw' => $o,
		);
		foreach ($o as $k => $v) {
			if (in_array($k, array('id', 'type', 'feature_type', 'strike', 'dip', 'trend', 'plunge', 'dip_direction', 'quality', 'facing', 'notes', 'associated_orientation'), true)) continue;
			if ($v === null || $v === '' || $v === array()) continue;
			$rows = array();
			FieldbookProps::walk($v, $rows, 0, $k);
			foreach ($rows as $r) $row['more'][] = $r;
		}
		unset($row['raw']);
		return $row;
	}

	/** Every orientation row of a dataset (or any spot list), children spots included, in book order. */
	public static function collectOrientations(array $spots)
	{
		$out = array();
		foreach ($spots as $s) {
			foreach ($s['orientations'] as $o) $out[] = $o;
			foreach ($s['images'] as $img) if (!empty($img['children'])) foreach (self::collectOrientations($img['children']) as $o) $out[] = $o;
		}
		return $out;
	}

	/** Every image id of a spot list, children spots included. */
	public static function collectImageIds(array $spots)
	{
		$out = array();
		foreach ($spots as $s) foreach ($s['images'] as $img) {
			if ($img['id'] !== '') $out[] = (string)$img['id'];
			if (!empty($img['children'])) foreach (self::collectImageIds($img['children']) as $id) $out[] = $id;
		}
		return $out;
	}

	/** Every image id of the book. */
	public function imageIds()
	{
		$out = array();
		foreach ($this->projects as $p) {
			foreach ($p['datasets'] as $ds) foreach ($ds['days'] as $day) foreach (self::collectImageIds($day['spots']) as $id) $out[] = $id;
			if (!empty($p['memos'])) foreach ($p['memos'] as $memo) foreach ($memo['images'] as $img) $out[] = $img['id'];
		}
		return array_values(array_unique($out));
	}

	public static function datasetOrientations(array $ds)
	{
		$out = array();
		foreach ($ds['days'] as $day) foreach (self::collectOrientations($day['spots']) as $o) $out[] = $o;
		return $out;
	}

	/** [lon, lat] for any GeoJSON geometry (point itself; mean of vertices otherwise). */
	public static function representativePoint($geom)
	{
		if (!isset($geom->coordinates)) return null;
		$pts = array();
		$collect = function ($c) use (&$collect, &$pts) {
			if (!is_array($c) || !$c) return;
			if (is_numeric($c[0])) { if (count($c) >= 2) $pts[] = array((float)$c[0], (float)$c[1]); return; }
			foreach ($c as $x) $collect($x);
		};
		$collect(json_decode(json_encode($geom->coordinates), true));
		if (!$pts) return null;
		$sx = 0; $sy = 0;
		foreach ($pts as $p) { $sx += $p[0]; $sy += $p[1]; }
		return array($sx / count($pts), $sy / count($pts));
	}

	private static function filenameFor(FieldbookModel $m)
	{
		$name = 'strabospot';
		$np = count($m->projects); $nd = 0; $lastDs = '';
		foreach ($m->projects as $p) foreach ($p['datasets'] as $d) { $nd++; $lastDs = $d['name']; }
		if ($np === 1 && $nd === 1) $name = $lastDs;
		elseif ($np === 1) $name = $m->projects[0]['name'];
		$safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($name));
		$safe = trim($safe, '_');
		if ($safe === '') $safe = 'strabospot';
		return substr($safe, 0, 60) . '_fieldbook_' . date('Y-m-d') . '.pdf';
	}

	/** Every scalar the book will print for one spot block (parity harness). */
	public static function blockScalars(array $b, array &$out)
	{
		$out[] = $b['name'];
		foreach ($b['meta'] as $r) $out[] = $r[1];
		foreach ($b['coords'] as $r) $out[] = $r[1];
		if ($b['notes'] !== '') $out[] = $b['notes'];
		foreach ($b['orientations'] as $o) self::orientationScalars($o, $out);
		foreach ($b['samples'] as $s) { $out[] = $s['title']; foreach ($s['rows'] as $r) { $out[] = $r['k']; if ($r['v'] !== '') $out[] = $r['v']; } }
		foreach (array_merge($b['units'], $b['tags']) as $t) { $out[] = $t['name']; foreach ($t['rows'] as $r) { $out[] = $r['k']; if ($r['v'] !== '') $out[] = $r['v']; } if (!empty($t['on'])) foreach ($t['on'] as $o) $out[] = $o; }
		foreach ($b['images'] as $img) {
			$out[] = $img['title']; $out[] = $img['caption']; $out[] = $img['typeLabel']; $out[] = $img['id'];
			foreach ($img['rows'] as $r) { $out[] = $r['k']; if ($r['v'] !== '') $out[] = $r['v']; }
			foreach ($img['children'] as $c) self::blockScalars($c, $out);
		}
		foreach ($b['families'] as $fam) { $out[] = $fam['label']; foreach ($fam['rows'] as $r) { $out[] = $r['k']; if ($r['v'] !== '') $out[] = $r['v']; } }
	}

	private static function orientationScalars(array $o, array &$out)
	{
		foreach (array('kind', 'feature', 'a', 'b', 'dipdir', 'quality', 'facing', 'notes') as $k) if ($o[$k] !== '') $out[] = $o[$k];
		foreach ($o['more'] as $r) { $out[] = $r['k']; if ($r['v'] !== '') $out[] = $r['v']; }
		foreach ($o['children'] as $c) self::orientationScalars($c, $out);
	}
}
