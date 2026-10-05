<?php
/**
 * File: microdb/lib/micro_history_web.php
 * Description: The website history page of a synced StraboMicro project
 *              (micro_history.php; collaboration v3 §12b 17ae): the
 *              history list (microsync/lib/MsHistory.php) grouped into
 *              bursts (a new item's files join its creation; the project's
 *              first upload is one line) and worded like the app's Activity panel
 *              ("Maya added 3 spots to micrograph 'A'"), item names, the
 *              people in the history, and each change's fields as text.
 *
 *              Mirrors the StraboMicro app's src/utils/activityFeed.ts
 *              (grouping, wording) and src/utils/syncDecisionText.ts
 *              (labels); keep the two in step when either changes.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/micro_members_web.php';
require_once __DIR__ . '/../../microsync/lib/MsHistory.php';

/** Changes this far apart (or closer) form one burst (activityFeed.ts BURST_MS). */
const MICRO_HISTORY_BURST_SECONDS = 600;
/** The pushes of one first upload are at most this far apart (activityFeed.ts UPLOAD_GAP_MS). */
const MICRO_HISTORY_UPLOAD_GAP_SECONDS = 60;

function micro_history_type_label($type) {
	$labels = array('project' => 'project', 'dataset' => 'dataset', 'sample' => 'sample', 'micrograph' => 'micrograph',
		'spot' => 'spot', 'tag' => 'tag', 'group' => 'group', 'preset' => 'quick spot preset',
		'point_count' => 'point count session');
	return isset($labels[$type]) ? $labels[$type] : str_replace('_', ' ', $type);
}

function micro_history_type_plural($type) {
	$plurals = array('dataset' => 'datasets', 'sample' => 'samples', 'micrograph' => 'micrographs', 'spot' => 'spots',
		'point_count' => 'point count sessions');
	return isset($plurals[$type]) ? $plurals[$type] : micro_history_type_label($type) . 's';
}

/** "Mineralogy › Notes" from a dotted path (fieldLabel). */
function micro_history_field_label($path) {
	$labels = array('name' => 'Name', 'label' => 'Label', 'notes' => 'Notes', 'description' => 'Description',
		'color' => 'Color', 'labelColor' => 'Label color', 'showLabel' => 'Show label', 'opacity' => 'Opacity',
		'tags' => 'Tags', 'mineralogy' => 'Mineralogy', 'minerals' => 'Minerals', 'grainInfo' => 'Grain info',
		'fabricInfo' => 'Fabrics', 'fractureInfo' => 'Fractures', 'foldInfo' => 'Folds', 'veinInfo' => 'Veins',
		'associatedFiles' => 'Associated files', 'links' => 'Links', 'scalePixelsPerCentimeter' => 'Scale',
		'sketchLayers' => 'Sketch', 'strokes' => 'Strokes', 'textItems' => 'Text', 'geometry' => 'Shape',
		'points' => 'Shape', 'geometryType' => 'Shape type');
	$out = array();
	foreach (explode('.', $path) as $seg) {
		if (isset($labels[$seg])) {
			$out[] = $labels[$seg];
		} else {
			$words = strtolower(str_replace('_', ' ', preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $seg)));
			$out[] = ucfirst($words);
		}
	}
	return implode(' › ', $out);
}

/** A file ref's name: "Image", "Thumbnail", "Attached file 'a.txt'". */
function micro_history_file_label($role) {
	if (strpos($role, 'associated_file:') === 0) {
		return "Attached file '" . substr($role, strlen('associated_file:')) . "'";
	}
	$labels = array('image' => 'Image', 'thumbnail' => 'Thumbnail', 'tiles' => 'Image tiles', 'tiles_affine' => 'Overlay tiles');
	return isset($labels[$role]) ? $labels[$role] : ucfirst(str_replace('_', ' ', $role));
}

/** "Name, Notes"; a file ref reads as its kind; more than three: "and N more" (fieldsText). */
function micro_history_fields_text($paths) {
	$labels = array();
	foreach ($paths as $p) {
		$parts = explode('.', $p, 2);
		$label = $parts[0] === 'refs' && isset($parts[1]) ? micro_history_file_label($parts[1]) : micro_history_field_label($parts[0]);
		if (!in_array($label, $labels, true)) {
			$labels[] = $label;
		}
	}
	if (count($labels) > 3) {
		return implode(', ', array_slice($labels, 0, 3)) . ' and ' . (count($labels) - 3) . ' more';
	}
	return implode(', ', $labels);
}

/** " (3 micrographs, 40 spots)" or "" (containsLabel). */
function micro_history_contains_label($contains) {
	ksort($contains, SORT_STRING);
	$parts = array();
	foreach ($contains as $type => $n) {
		if ($n > 0) {
			$parts[] = $n . ' ' . ($n === 1 ? micro_history_type_label($type) : micro_history_type_plural($type));
		}
	}
	return count($parts) ? ' (' . implode(', ', $parts) . ')' : '';
}

function micro_history_quoted($type, $name) {
	return $name !== null && $name !== '' ? micro_history_type_label($type) . " '$name'" : 'an unnamed ' . micro_history_type_label($type);
}

function micro_history_key($type, $id) {
	return (string)$type . ':' . (string)$id;
}

/**
 * Names of the items a page of rows mentions (the items, their parents,
 * the parents they moved from), from the project today, deleted items
 * included (a tombstone keeps its body). 'type:id' => name.
 */
function micro_history_names($msdb, $pid, $rows) {
	$keys = array();
	foreach ($rows as $r) {
		$keys[micro_history_key($r['type'], $r['id'])] = true;
		if ($r['parentType'] !== null) {
			$keys[micro_history_key($r['parentType'], $r['parentId'])] = true;
			if ($r['movedFrom'] !== null) {
				$keys[micro_history_key($r['parentType'], $r['movedFrom'])] = true;
			}
		}
	}
	$names = array();
	if (empty($keys)) {
		return $names;
	}
	foreach ($msdb->rows(
		"SELECT entity_type, entity_id, COALESCE(body->>'name', body->>'sampleID') AS name
		   FROM strabomicro.micro_entities
		  WHERE project_id = $1 AND (entity_type || ':' || entity_id) = ANY (ARRAY(SELECT json_array_elements_text($2::json)))",
		array($pid, json_encode(array_keys($keys)))) as $e) {
		$names[micro_history_key($e['entity_type'], $e['entity_id'])] = $e['name'];
	}
	return $names;
}

/** Everyone in the history (who changed something, or whose change was accepted), by name. */
function micro_history_people($msdb, $pid) {
	$pkeys = array();
	foreach ($msdb->rows(
		"SELECT user_pkey AS pkey FROM strabomicro.micro_changes WHERE project_id = $1
		 UNION SELECT on_behalf_of FROM strabomicro.micro_changes WHERE project_id = $1 AND on_behalf_of IS NOT NULL",
		array($pid)) as $r) {
		$pkeys[] = (int)$r['pkey'];
	}
	$users = array_values(MsStore::users($msdb, $pkeys));
	usort($users, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
	return $users;
}

/** Cascaded deletes/restores (same push, parent in it too) fold into the topmost row (foldCascades). */
function micro_history_fold($rows) {
	$byPush = array();
	foreach ($rows as $i => $r) {
		if (($r['op'] === 'delete' || $r['op'] === 'restore') && $r['pushId'] !== null) {
			$byPush[$r['pushId'] . '|' . $r['op']][micro_history_key($r['type'], $r['id'])] = $i;
		}
	}
	$contains = array();
	$folded = array();
	foreach ($rows as $i => $r) {
		if (($r['op'] !== 'delete' && $r['op'] !== 'restore') || $r['pushId'] === null) {
			continue;
		}
		$same = $byPush[$r['pushId'] . '|' . $r['op']];
		$root = $i;
		$seen = array($i => true);
		while (true) {
			$pk = micro_history_key($rows[$root]['parentType'], $rows[$root]['parentId']);
			if (!isset($same[$pk]) || isset($seen[$same[$pk]])) {
				break;
			}
			$root = $same[$pk];
			$seen[$root] = true;
		}
		if ($root === $i) {
			continue;
		}
		$folded[$i] = $root;
		$t = $r['type'];
		$contains[$root][$t] = (isset($contains[$root][$t]) ? $contains[$root][$t] : 0) + 1;
	}
	$items = array();
	foreach ($rows as $i => $r) {
		if (isset($folded[$i])) {
			continue;
		}
		$items[] = array('row' => $r, 'contains' => isset($contains[$i]) ? $contains[$i] : array(), 'rows' => array($r));
	}
	// Keep the folded rows with their topmost item, for the details
	foreach ($folded as $i => $root) {
		foreach ($items as &$it) {
			if ($it['row']['seq'] === $rows[$root]['seq']) {
				$it['rows'][] = $rows[$i];
			}
		}
		unset($it);
	}
	return $items;
}

/** A change of files only (refs.*), not a move */
function micro_history_refs_only($r) {
	if ($r['op'] !== 'update' || $r['movedFrom'] !== null || !is_array($r['changedPaths']) || count($r['changedPaths']) === 0) {
		return false;
	}
	foreach ($r['changedPaths'] as $p) {
		if (strpos($p, 'refs.') !== 0) {
			return false;
		}
	}
	return true;
}

/** Same person, so two changes may share a line */
function micro_history_same_author($a, $b) {
	return (int)$a['user']['pkey'] === (int)$b['user']['pkey']
		&& ($a['onBehalfOf'] === null ? null : (int)$a['onBehalfOf']['pkey']) === ($b['onBehalfOf'] === null ? null : (int)$b['onBehalfOf']['pkey']);
}

/** The newest row an item stands for */
function micro_history_newest($it) {
	return isset($it['latest']) ? $it['latest'] : $it['row'];
}

/**
 * Files of a new item join its creation (same person, up to 10 minutes
 * later); the project's first upload (its creation and the creations after
 * it by the same person, each at most a minute after the one before, up
 * to the first change of another kind) becomes one item with 'upload'
 * (type => count). Items newest first (foldUploads in activityFeed.ts).
 */
function micro_history_fold_uploads($items) {
	$creates = array();
	foreach ($items as $i => $it) {
		if ($it['row']['op'] === 'create') {
			$creates[micro_history_key($it['row']['type'], $it['row']['id'])] = $i;
		}
	}
	$keep = array();
	foreach ($items as $i => $it) {
		$k = micro_history_key($it['row']['type'], $it['row']['id']);
		if (micro_history_refs_only($it['row']) && isset($creates[$k])) {
			$c = &$items[$creates[$k]];
			$dt = strtotime($it['row']['at']) - strtotime($c['row']['at']);
			if (micro_history_same_author($c['row'], $it['row']) && $dt >= 0 && $dt <= MICRO_HISTORY_BURST_SECONDS) {
				$c['rows'] = array_merge($c['rows'], $it['rows']);
				if ($it['row']['seq'] > micro_history_newest($c)['seq']) {
					$c['latest'] = $it['row'];
				}
				unset($c);
				continue;
			}
			unset($c);
		}
		$keep[] = $i;
	}
	$kept = array();
	foreach ($keep as $i) {
		$kept[] = $items[$i];
	}

	$start = -1;
	foreach ($kept as $i => $it) {
		if ($it['row']['op'] === 'create' && $it['row']['type'] === 'project') {
			$start = $i;
			break;
		}
	}
	if ($start < 0) {
		return $kept;
	}
	$end = $start;
	while ($end > 0) {
		$next = $kept[$end - 1]['row'];
		$prev = $kept[$end]['row'];
		if ($next['op'] !== 'create' || !micro_history_same_author($next, $prev)
			|| strtotime($next['at']) - strtotime($prev['at']) > MICRO_HISTORY_UPLOAD_GAP_SECONDS) {
			break;
		}
		$end--;
	}
	$added = array();
	$rows = array();
	$latest = null;
	for ($i = $end; $i <= $start; $i++) {
		$r = $kept[$i]['row'];
		if ($r['type'] !== 'project') {
			$added[$r['type']] = (isset($added[$r['type']]) ? $added[$r['type']] : 0) + 1;
		}
		$rows = array_merge($rows, $kept[$i]['rows']);
		$n = micro_history_newest($kept[$i]);
		if ($latest === null || $n['seq'] > $latest['seq']) {
			$latest = $n;
		}
	}
	$upload = array('row' => $kept[$end]['row'], 'latest' => $latest, 'contains' => array(), 'rows' => $rows, 'upload' => $added);
	return array_merge(array_slice($kept, 0, $end), array($upload), array_slice($kept, $start + 1));
}

function micro_history_kind($r) {
	return $r['op'] === 'update' && $r['movedFrom'] !== null ? 'move' : $r['op'];
}

function micro_history_burst_key($it) {
	$r = $it['row'];
	if (isset($it['upload'])) {
		return 'upload|' . micro_history_newest($it)['seq'];
	}
	return implode('|', array($r['user']['pkey'], $r['onBehalfOf'] === null ? '' : $r['onBehalfOf']['pkey'],
		micro_history_kind($r), $r['type'], micro_history_key($r['parentType'], $r['parentId'])));
}

/** The line's text after the person: "added 3 spots to micrograph 'A'" (describe). */
function micro_history_describe($items, $names) {
	if (isset($items[0]['upload'])) {
		return 'put the project on StraboSpot' . micro_history_contains_label($items[0]['upload']);
	}
	$first = $items[0]['row'];
	$ids = array();
	foreach ($items as $it) {
		$ids[$it['row']['id']] = true;
	}
	$n = count($ids);
	$type = $first['type'];
	$name = $first['name'] !== null ? $first['name']
		: (isset($names[micro_history_key($type, $first['id'])]) ? $names[micro_history_key($type, $first['id'])] : null);
	$parent = null;
	if ($first['parentType'] !== null && $first['parentId'] !== null && $first['parentType'] !== 'project') {
		$pk = micro_history_key($first['parentType'], $first['parentId']);
		$parent = micro_history_quoted($first['parentType'], isset($names[$pk]) ? $names[$pk] : null);
	}
	$many = $n . ' ' . micro_history_type_plural($type);
	$on = $first['parentType'] === 'micrograph' ? 'on' : 'in';
	switch (micro_history_kind($first)) {
		case 'create':
			return ($n === 1 ? 'added ' . micro_history_quoted($type, $name) : "added $many") . ($parent ? " to $parent" : '');
		case 'delete':
		case 'restore':
			$verb = $first['op'] === 'delete' ? 'deleted' : 'restored';
			$sum = array();
			foreach ($items as $it) {
				foreach ($it['contains'] as $t => $c) {
					$sum[$t] = (isset($sum[$t]) ? $sum[$t] : 0) + $c;
				}
			}
			$contains = micro_history_contains_label($sum);
			if ($n === 1) {
				return "$verb " . micro_history_quoted($type, $name) . $contains;
			}
			return "$verb $many" . ($parent ? ' ' . ($first['op'] === 'delete' ? 'from' : 'in') . " $parent" : '') . $contains;
		case 'move':
			return ($n === 1 ? 'moved ' . micro_history_quoted($type, $name) : "moved $many") . ($parent ? " to $parent" : '');
		case 'update':
			$paths = array();
			foreach ($items as $it) {
				$paths = array_merge($paths, is_array($it['row']['changedPaths']) ? $it['row']['changedPaths'] : array());
			}
			if ($type === 'project') {
				return 'changed the project settings' . (count($paths) ? ' (' . micro_history_fields_text($paths) . ')' : '');
			}
			if ($n === 1) {
				return 'changed ' . (count($paths) ? micro_history_fields_text($paths) . ' of ' : '') . micro_history_quoted($type, $name);
			}
			return "changed $many" . ($parent ? " $on $parent" : '');
		case 'import':
			return 'imported the project';
		case 'replace_project':
			return 'replaced the whole project';
		default:
			return 'changed ' . micro_history_quoted($type, $name);
	}
}

/**
 * Group a page of history rows (newest first; MsHistory::page with
 * detail) into lines: who, you, onBehalfOf, text, at (newest), oldestSeq,
 * rows (every change in the line, newest first).
 */
function micro_history_groups($rows, $me, $names) {
	$bursts = array();
	foreach (micro_history_fold_uploads(micro_history_fold($rows)) as $it) {
		$cur = count($bursts) ? $bursts[count($bursts) - 1] : null;
		if ($cur !== null && micro_history_burst_key($cur[0]) === micro_history_burst_key($it)
			&& strtotime(micro_history_newest($cur[0])['at']) - strtotime(micro_history_newest($it)['at']) <= MICRO_HISTORY_BURST_SECONDS) {
			$bursts[count($bursts) - 1][] = $it;
		} else {
			$bursts[] = array($it);
		}
	}
	$out = array();
	foreach ($bursts as $b) {
		$r = $b[0]['row'];
		$all = array();
		foreach ($b as $it) {
			$all = array_merge($all, $it['rows']);
		}
		usort($all, function ($x, $y) { return $y['seq'] - $x['seq']; });
		$out[] = array(
			'user'       => $r['user'],
			'you'        => (int)$r['user']['pkey'] === (int)$me,
			'onBehalfOf' => $r['onBehalfOf'],
			'text'       => micro_history_describe($b, $names),
			'at'         => micro_history_newest($b[0])['at'],
			'oldestSeq'  => $all[count($all) - 1]['seq'],
			'rows'       => $all,
		);
	}
	return $out;
}

/** The whole line: "Maya added 3 spots ..."; an accepted parked change names both (lineText). */
function micro_history_line($g) {
	$who = $g['you'] ? 'You' : ($g['user']['name'] !== '' ? $g['user']['name'] : 'Someone');
	if ($g['onBehalfOf'] !== null) {
		return "$who accepted " . ($g['onBehalfOf']['name'] !== '' ? $g['onBehalfOf']['name'] : 'someone') . "'s change: " . $g['text'];
	}
	return "$who " . $g['text'];
}

/** One change of a line as an item name: "Spot 'P1'" or "Project settings". */
function micro_history_item($r, $names) {
	if ($r['type'] === 'project') {
		return 'Project settings';
	}
	$k = micro_history_key($r['type'], $r['id']);
	$name = $r['name'] !== null ? $r['name'] : (isset($names[$k]) ? $names[$k] : null);
	return ucfirst(micro_history_quoted($r['type'], $name));
}

/**
 * The details of one change, as lines of {label, before, after} (plain
 * values; null = empty) or {label, text}.
 */
function micro_history_details($r, $names) {
	switch ($r['op']) {
		case 'create': return array(array('label' => null, 'text' => 'Added'));
		case 'delete': return array(array('label' => null, 'text' => 'Deleted'));
		case 'restore': return array(array('label' => null, 'text' => 'Restored'));
	}
	$out = array();
	foreach (isset($r['fields']) ? $r['fields'] : array() as $f) {
		if (!empty($f['file'])) {
			$out[] = array('label' => micro_history_file_label(substr($f['path'], 5)), 'text' => ucfirst($f['change']));
		} elseif ($f['path'] === 'parentId') {
			$from = micro_history_key($r['parentType'], $f['before']);
			$to = micro_history_key($r['parentType'], $f['after']);
			$out[] = array('label' => 'Location',
				'before' => micro_history_quoted($r['parentType'], isset($names[$from]) ? $names[$from] : null),
				'after' => micro_history_quoted($r['parentType'], isset($names[$to]) ? $names[$to] : null));
		} elseif (in_array(explode('.', $f['path'])[0], array('geometry', 'points', 'sketchLayers'), true)) {
			// Coordinates mean nothing as text (the app says "Shape changed" too, 16y)
			$out[] = array('label' => micro_history_field_label($f['path']), 'text' => 'Changed');
		} else {
			$out[] = array('label' => micro_history_field_label($f['path']), 'before' => $f['before'], 'after' => $f['after']);
		}
	}
	if (empty($out)) {
		$out[] = array('label' => null, 'text' => 'Changed');
	}
	return $out;
}
