<?php
/**
 * File: gpkgroundtrip.php
 * Description: GeoPackage round trip, upload side (docs/GeoPackage/GeoPackage_RoundTrip_Design.md
 *              sections 5 and 6): find the strabo_export manifest in an uploaded GeoPackage, check
 *              that the original project can be updated, and sort every feature into updated,
 *              unchanged, conflict, new, ... against the manifest and the spots as they are now.
 *              Read-only: nothing here writes to the databases. Used by load_geopackage.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Two geometries are the same when every coordinate differs by at most this many degrees
// (float noise of a save in another coordinate system must not count as a move)
define('GPKG_RT_TOLERANCE', 1e-9);

// The layers a round trip reads, and the one geometry type each holds
$GPKG_RT_LAYERS = array('points' => 'Point', 'lines' => 'LineString', 'polygons' => 'Polygon');

/*
	The strabo_export row of a StraboSpot GeoPackage export (format 1), or null for any other
	GeoPackage (no table, unreadable, unknown format): those go through the normal import.
*/
function gpkgRoundTripManifest($path){
	$rows = geopackageQuery($path, "SELECT * FROM strabo_export");
	if(!is_array($rows) || count($rows) != 1) return null;
	$row = $rows[0];
	if(($row['format_version'] ?? "") !== "1") return null;
	if(!preg_match('/^[0-9]+$/', (string)($row['project_id'] ?? "")) || !preg_match('/^[0-9]+$/', (string)($row['owner_userpkey'] ?? ""))) return null;
	return $row;
}

// Host name to compare servers by: no port, no "www."
function gpkgRoundTripServerName($host){
	$host = strtolower(trim((string)$host));
	$host = preg_replace('/:[0-9]+$/', '', $host);
	return preg_replace('/^www\./', '', $host);
}

/*
	Can this user update the project named in the manifest? Returns array('ok' => bool, 'reason'
	=> text when not, 'projectid', 'projectname', 'owner', 'context' => ProjectContext).
	The manifest only names the project; access is checked on the server (design 4).
*/
function gpkgRoundTripTarget($neodb, $db, $userpkey, $export){
	$pid = (string)$export['project_id'];
	$owner = (int)$export['owner_userpkey'];
	$out = array('ok' => false, 'reason' => "", 'projectid' => $pid, 'projectname' => (string)($export['project_name'] ?? ""), 'owner' => $owner, 'context' => null);

	$server = gpkgRoundTripServerName($export['server'] ?? "");
	if($server !== "" && $server !== gpkgRoundTripServerName($_SERVER['HTTP_HOST'] ?? "")){
		$out['reason'] = "This file was exported from ".($export['server'] ?? "")." and cannot update a project on this site.";
		return $out;
	}

	$name = $neodb->get_var("MATCH (p:Project {id: $pid}) WHERE p.userpkey = $owner RETURN p.desc_project_name");
	$exists = (int)$neodb->get_var("MATCH (p:Project {id: $pid}) WHERE p.userpkey = $owner RETURN count(p)");
	if($exists == 0){
		$out['reason'] = "The project \"".$out['projectname']."\" this file was exported from no longer exists.";
		return $out;
	}
	if($name !== null && $name !== "") $out['projectname'] = (string)$name;

	require_once(__DIR__."/db/services/CollaborationAuth.php");
	$auth = new CollaborationAuth($db, $neodb);
	$context = $auth->getProjectContext((int)$userpkey, $pid);
	if((int)$context->effectiveOwner !== $owner || !in_array($context->permissionLevel, array('owner', 'edit'), true)){
		$out['reason'] = "You do not have edit access to the project \"".$out['projectname']."\" this file was exported from.";
		return $out;
	}

	$out['ok'] = true;
	$out['context'] = $context;
	return $out;
}

/*
	A feature geometry checked for a layer: array(geometry, "") or array(null, reason).
	A multi geometry with one part is unwrapped (QGIS often saves MultiLineString).
*/
function gpkgRoundTripGeometry($geometry, $wanttype){
	if(!is_array($geometry) || !isset($geometry['type'])) return array(null, "no geometry");
	$type = $geometry['type'];
	$coords = $geometry['coordinates'] ?? null;
	if($type === "Multi".$wanttype){
		if(!is_array($coords) || count($coords) == 0) return array(null, "no geometry");
		if(count($coords) > 1) return array(null, count($coords)." parts (a spot has one geometry)");
		$type = $wanttype;
		$coords = $coords[0];
	}
	if($type !== $wanttype) return array(null, "geometry type $type");
	if(!is_array($coords) || count($coords) == 0) return array(null, "no geometry");
	return array(array('type' => $type, 'coordinates' => $coords), "");
}

// Same geometry: same type, same structure, every coordinate within GPKG_RT_TOLERANCE (x and y only)
function gpkgRoundTripSame($a, $b){
	if(!is_array($a) || !is_array($b) || ($a['type'] ?? null) !== ($b['type'] ?? null)) return false;
	return gpkgRoundTripSameCoords($a['coordinates'] ?? null, $b['coordinates'] ?? null);
}

function gpkgRoundTripSameCoords($a, $b){
	if(!is_array($a) || !is_array($b) || count($a) != count($b)) return false;
	if(count($a) > 0 && !is_array(reset($a))){
		// a position
		if(count($a) < 2 || count($b) < 2) return false;
		return abs((float)$a[0] - (float)$b[0]) <= GPKG_RT_TOLERANCE && abs((float)$a[1] - (float)$b[1]) <= GPKG_RT_TOLERANCE;
	}
	foreach(array_keys($a) as $i){
		if(!array_key_exists($i, $b) || !gpkgRoundTripSameCoords($a[$i], $b[$i])) return false;
	}
	return true;
}

// Vertex count of a geometry (for the preview)
function gpkgRoundTripVertices($geometry){
	$count = 0;
	array_walk_recursive($geometry['coordinates'], function() use (&$count){ $count++; });
	return (int)($count / 2);
}

/*
	The project's spots as they are now, by spot id: name, dataset, geometry (the way the export
	builds it: geoPHP from wkt, none without origwkt), modified_timestamp, locked (image basemap /
	strat section), editable (this user may edit the dataset).
*/
function gpkgRoundTripServerSpots($neodb, $db, $target){
	$pid = $target['projectid'];
	$owner = (int)$target['owner'];
	require_once(__DIR__."/db/services/CollaborationAuth.php");
	$auth = new CollaborationAuth($db, $neodb);
	$spots = array();
	$rows = $neodb->get_results("MATCH (p:Project {id: $pid})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = $owner AND d.userpkey = $owner RETURN s.id AS id, s.name AS name, s.wkt AS wkt, s.origwkt AS origwkt, s.modified_timestamp AS mt, s.image_basemap AS ib, s.strat_section_id AS ss, d.id AS dsid, d.name AS dsname, d.created_by AS createdby ORDER BY d.id");
	foreach(is_array($rows) ? $rows : array() as $r){
		$id = (string)$r->get('id');
		if(isset($spots[$id])) continue;
		$geometry = null;
		if((string)$r->get('origwkt') !== "" && (string)$r->get('wkt') !== ""){
			$g = geoPHP::load((string)$r->get('wkt'), "wkt");
			if($g) $geometry = json_decode($g->out('json'), true);
		}
		$createdby = $r->get('createdby') ? (int)$r->get('createdby') : $owner;
		$spots[$id] = array(
			'name' => (string)$r->get('name'),
			'dataset_id' => (string)$r->get('dsid'),
			'dataset_name' => (string)$r->get('dsname'),
			'geometry' => $geometry,
			'modified_timestamp' => (string)$r->get('mt'),
			'locked' => ((string)$r->get('ib') !== "" || (string)$r->get('ss') !== ""),
			'editable' => $auth->canEditDataset($target['context'], $createdby),
		);
	}
	return $spots;
}

/*
	Sort the uploaded file against the manifest and the server (design 6.2, 6.3). Returns
	array of lists:
	  update     spots whose geometry changed in QGIS only (would be written)
	  unchanged  spots whose geometry did not change
	  conflict   geometry changed in QGIS and on the server since the export (user decides)
	  duplicate  one strabo_id on several changed features (user decides which one updates the spot)
	  new        features without a known strabo_id (would become new spots)
	  locked     moved, but drawn on an image basemap / strat section (never written)
	  noaccess   moved, in a dataset this user cannot edit
	  gone       spot no longer in the project
	  missing    in the manifest, no longer in the file ("deleted in QGIS", the spot is kept)
	  refused    features that cannot be used (no geometry, several parts, ...)
	  notread    feature layers other than points / lines / polygons
	Every entry: id, name, dataset_name, layer, and file / orig / server geometries where known.
*/
function gpkgRoundTripClassify($neodb, $db, $randnum, $gpkgpath, $layers, $target){
	global $GPKG_RT_LAYERS;

	$result = array();
	foreach(array('update', 'unchanged', 'conflict', 'duplicate', 'new', 'locked', 'noaccess', 'gone', 'missing', 'refused', 'notread') as $k){
		$result[$k] = array();
	}

	// The manifest: what the user started from (only compared with, never written)
	$manifest = array();
	foreach(geopackageQuery($gpkgpath, "SELECT spot_id, dataset_id, layer, original_geojson, editable FROM strabo_spots") ?: array() as $row){
		$id = (string)$row['spot_id'];
		if(!preg_match('/^[0-9]+$/', $id) || !isset($GPKG_RT_LAYERS[$row['layer']])) continue;
		$orig = json_decode((string)$row['original_geojson'], true);
		$manifest[$id] = array('layer' => $row['layer'], 'orig' => is_array($orig) ? $orig : null, 'editable' => ((string)$row['editable'] !== "0"));
	}

	$server = gpkgRoundTripServerSpots($neodb, $db, $target);

	// The file's features per strabo_id; features without a usable id are new
	$byId = array();
	$refusedIds = array();
	foreach($layers as $layer => $info){
		if(!isset($GPKG_RT_LAYERS[$layer])){
			$result['notread'][] = array('layer' => $layer, 'reason' => "only the points, lines and polygons layers are read");
			continue;
		}
		$options = geopackageReadOptions($info);
		if($options === ""){
			$result['notread'][] = array('layer' => $layer, 'reason' => "no coordinate system");
			continue;
		}
		$n = 0;
		foreach(vectorImportReadFeatures($randnum, $gpkgpath, $layer, $options) as $f){
			$n++;
			$sid = trim((string)($f['properties']['strabo_id'] ?? ""));
			list($geometry, $reason) = gpkgRoundTripGeometry($f['geometry'] ?? null, $GPKG_RT_LAYERS[$layer]);
			$known = ($sid !== "" && isset($manifest[$sid]) && $manifest[$sid]['layer'] === $layer);
			$entry = array(
				'id' => $known ? $sid : "",
				'name' => $known && isset($server[$sid]) ? $server[$sid]['name'] : "",
				'dataset_name' => $known && isset($server[$sid]) ? $server[$sid]['dataset_name'] : "",
				'layer' => $layer,
				'feature' => $n,
				'file' => $geometry,
				'ds_id' => trim((string)($f['properties']['ds_id'] ?? "")),
			);
			if($geometry === null){
				$entry['reason'] = $reason;
				$result['refused'][] = $entry;
				if($known) $refusedIds[$sid] = true;
			}elseif($known){
				$byId[$sid][] = $entry;
			}else{
				$result['new'][] = $entry;
			}
		}
	}

	foreach($manifest as $sid => $m){
		$sid = (string)$sid;   // numeric array keys come back as integers
		if(!isset($byId[$sid])){
			// not in the file: deleted in QGIS (the spot is kept), unless it was never written to the
			// file or its feature was refused (listed there)
			if($m['orig'] !== null && isset($server[$sid]) && !isset($refusedIds[$sid])){
				$result['missing'][] = array('id' => $sid, 'name' => $server[$sid]['name'], 'dataset_name' => $server[$sid]['dataset_name'], 'layer' => $m['layer']);
			}
			continue;
		}
		$entries = $byId[$sid];

		// One id on several features (copy and paste in QGIS): the one still at the exported
		// geometry keeps the id, the others are new features (design 6.3)
		if(count($entries) > 1){
			$keep = null;
			foreach($entries as $i => $e){
				if($keep === null && $m['orig'] !== null && gpkgRoundTripSame($e['file'], $m['orig'])) $keep = $i;
			}
			if($keep === null){
				$result['duplicate'][] = array('id' => $sid, 'name' => $entries[0]['name'], 'dataset_name' => $entries[0]['dataset_name'], 'layer' => $m['layer'], 'features' => $entries, 'orig' => $m['orig']);
				continue;
			}
			foreach($entries as $i => $e){
				if($i === $keep) continue;
				$e['id'] = "";
				$e['copy_of'] = $sid;
				$result['new'][] = $e;
			}
			$entries = array($entries[$keep]);
		}

		$e = $entries[0];
		$e['orig'] = $m['orig'];
		if(!isset($server[$sid])){
			$result['gone'][] = $e;
			continue;
		}
		$s = $server[$sid];
		$e['server'] = $s['geometry'];
		$e['modified_timestamp'] = $s['modified_timestamp'];

		if($m['orig'] !== null && gpkgRoundTripSame($e['file'], $m['orig'])){
			$result['unchanged'][] = $e;
		}elseif($s['locked'] || !$m['editable']){
			$result['locked'][] = $e;
		}elseif(!$s['editable']){
			$result['noaccess'][] = $e;
		}elseif($m['orig'] !== null && gpkgRoundTripSame($s['geometry'], $m['orig'])){
			$result['update'][] = $e;
		}else{
			// changed on the server since the export (or no exported geometry to compare with)
			$result['conflict'][] = $e;
		}
	}

	return $result;
}

// "Oct 23, 2026 14:05" from seconds or milliseconds (StraboField stamps are milliseconds)
function gpkgRoundTripTime($stamp){
	$t = (float)$stamp;
	if($t <= 0) return "";
	if($t > 100000000000) $t = $t / 1000;
	return date("M j, Y H:i", (int)$t);
}

function gpkgRoundTripHidden($fields){
	$html = "";
	foreach($fields as $k => $v){
		$html .= "<input type=\"hidden\" name=\"".htmlspecialchars($k, ENT_QUOTES)."\" value=\"".htmlspecialchars((string)$v, ENT_QUOTES)."\">";
	}
	return $html;
}

/*
	After the upload of a StraboSpot export: update the original project, or load the file as new
	data (today's flow). When the original cannot be updated, the page says why and only offers
	the normal import (design 5).
*/
function gpkgRoundTripChoicePage($target, $pickedprojectid, $pickedprojectname, $hidden){
	$pname = htmlspecialchars($target['projectname']);
	?>
	<div id="main" class="wrapper style1">
		<div class="container">
			<header class="major">
				<h2>Load GeoPackage</h2>
			</header>
			<form method="POST">
			<?php echo gpkgRoundTripHidden($hidden); ?>
			<?php if($target['ok']){ ?>
				<p>This GeoPackage was exported from the StraboSpot project <b><?php echo $pname?></b>.</p>
				<div style="padding-bottom:10px;">
					<input type="radio" id="rtupdate" name="rtpath" value="update" checked>
					<label for="rtupdate"><b>Update the original project "<?php echo $pname?>"</b> (recommended)<br>
					Geometry changes only: spots you moved or reshaped get their new geometry, features you drew become new spots.
					Names, notes and other attributes edited in the GeoPackage are not read.
					<?php if((string)$pickedprojectid !== (string)$target['projectid']){ ?>
						<br>The project picked on the first page (<?php echo htmlspecialchars($pickedprojectname)?>) is not used for this choice.
					<?php } ?>
					</label>
				</div>
				<div style="padding-bottom:20px;">
					<input type="radio" id="rtnew" name="rtpath" value="new">
					<label for="rtnew"><b>Load as new data</b> into <?php echo htmlspecialchars($pickedprojectname)?><br>
					Every layer becomes a new dataset, as with any other GeoPackage.</label>
				</div>
				<input class="primary" type="submit" name="rtsubmit" value="Continue">
			<?php }else{ ?>
				<p>This GeoPackage was exported from StraboSpot, but the original project cannot be updated:<br>
				<span style="color:#e44c65;"><?php echo htmlspecialchars($target['reason'])?></span></p>
				<p>You can load it as new data into <?php echo htmlspecialchars($pickedprojectname)?>.</p>
				<input type="hidden" name="rtpath" value="new">
				<input class="primary" type="submit" name="rtsubmit" value="Load as new data">
			<?php } ?>
			</form>
			<div style="padding-top:20px;"><a href="load_geopackage">Start again</a></div>
			<div class="bottomSpacer"></div>
		</div>
	</div>
	<?php
}

/*
	Preview of an update of the original project (design 6.6): counts, then a list per category.
	Read-only for now: Apply comes with phase 3.
*/
function gpkgRoundTripPreviewPage($target, $result, $hidden){
	$n = function($k) use ($result){ return count($result[$k]); };
	$notupdated = $n('locked') + $n('noaccess') + $n('gone') + $n('refused');
	$counts = array(
		$n('update')." updated",
		$n('unchanged')." unchanged",
		$n('new')." new",
		($n('conflict') + $n('duplicate'))." to decide",
		$notupdated." not updated",
	);
	if($n('missing') > 0) $counts[] = $n('missing')." deleted in the GeoPackage (kept)";

	$spotrow = function($e, $extra = ""){
		$name = $e['name'] !== "" ? htmlspecialchars($e['name']) : "(spot ".htmlspecialchars($e['id']).")";
		return "<tr><td>$name</td><td>".htmlspecialchars($e['dataset_name'] ?? "")."</td><td>".htmlspecialchars($e['layer'])."</td><td>$extra</td></tr>";
	};
	$section = function($title, $intro, $rows, $open = false){
		if($rows === "") return;
		echo "<details".($open ? " open" : "")." style=\"padding-bottom:15px;\"><summary><b>".htmlspecialchars($title)."</b></summary>";
		if($intro !== "") echo "<div style=\"padding:5px 0;\">".htmlspecialchars($intro)."</div>";
		echo "<table><thead><tr><th>Spot</th><th>Dataset</th><th>Layer</th><th></th></tr></thead>$rows</table></details>";
	};
	?>
	<div id="main" class="wrapper style1">
		<div class="container">
			<header class="major">
				<h2>Update "<?php echo htmlspecialchars($target['projectname'])?>"</h2>
			</header>
			<p>Only geometries change. Names, notes and other attributes edited in the GeoPackage are not read.</p>
			<p><b><?php echo htmlspecialchars(implode(", ", $counts))?></b></p>
			<?php
			$rows = "";
			foreach($result['update'] as $e){
				$rows .= $spotrow($e, gpkgRoundTripVertices($e['orig'])." &rarr; ".gpkgRoundTripVertices($e['file'])." vertices");
			}
			$section("Updated (".$n('update').")", "Moved or reshaped in the GeoPackage.", $rows, true);

			$rows = "";
			foreach($result['conflict'] as $e){
				$when = gpkgRoundTripTime($e['modified_timestamp']);
				$rows .= $spotrow($e, "changed on the server".($when !== "" ? " ($when)" : "")." since the export");
			}
			foreach($result['duplicate'] as $e){
				$rows .= $spotrow($e, count($e['features'])." changed copies of this spot: choose which one updates it");
			}
			$section("To decide (".($n('conflict') + $n('duplicate')).")", "Changed in the GeoPackage and also on the server since the export, or copied in the GeoPackage.", $rows, true);

			$rows = "";
			foreach($result['new'] as $e){
				$what = isset($e['copy_of']) ? "copy of spot ".htmlspecialchars($e['copy_of']) : "feature ".(int)$e['feature'];
				$rows .= "<tr><td>New spot</td><td>".htmlspecialchars($e['ds_id'] !== "" ? "ds_id ".$e['ds_id'] : "")."</td><td>".htmlspecialchars($e['layer'])."</td><td>$what</td></tr>";
			}
			$section("New (".$n('new').")", "Drawn in the GeoPackage. They become new spots.", $rows, true);

			$rows = "";
			foreach($result['locked'] as $e) $rows .= $spotrow($e, "drawn on an image basemap or strat section");
			foreach($result['noaccess'] as $e) $rows .= $spotrow($e, "you cannot edit this dataset");
			foreach($result['gone'] as $e) $rows .= $spotrow($e, "no longer in this project");
			foreach($result['refused'] as $e){
				$name = $e['id'] !== "" ? ($e['name'] !== "" ? htmlspecialchars($e['name']) : "(spot ".htmlspecialchars($e['id']).")") : "feature ".(int)$e['feature'];
				$rows .= "<tr><td>$name</td><td>".htmlspecialchars($e['dataset_name'])."</td><td>".htmlspecialchars($e['layer'])."</td><td>".htmlspecialchars($e['reason'])."</td></tr>";
			}
			$section("Not updated ($notupdated)", "", $rows);

			$rows = "";
			foreach($result['missing'] as $e) $rows .= $spotrow($e, "spot kept");
			$section("Deleted in the GeoPackage (".$n('missing').")", "Spots are never deleted this way.", $rows);

			if($n('notread') > 0){
				echo "<details style=\"padding-bottom:15px;\"><summary><b>Layers not read (".$n('notread').")</b></summary><ul>";
				foreach($result['notread'] as $l) echo "<li>".htmlspecialchars($l['layer']).": ".htmlspecialchars($l['reason'])."</li>";
				echo "</ul></details>";
			}
			?>
			<p style="padding-top:10px;"><i>Applying these changes is not available yet.</i></p>
			<form method="POST">
				<?php echo gpkgRoundTripHidden($hidden); ?>
				<input type="hidden" name="rtpath" value="new">
				<input type="submit" name="rtsubmit" value="Load as new data instead">
			</form>
			<div style="padding-top:20px;"><a href="load_geopackage">Start again</a></div>
			<div class="bottomSpacer"></div>
		</div>
	</div>
	<?php
}

?>
