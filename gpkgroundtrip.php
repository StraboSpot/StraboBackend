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
				'dataset_id' => $known && isset($server[$sid]) ? $server[$sid]['dataset_id'] : "",
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
	data (today's flow) into the project chosen in $projectselect (a <select name="projectid">).
	When the original cannot be updated, the page says why and only offers the normal import
	(design 5).
*/
function gpkgRoundTripChoicePage($target, $projectselect, $hidden){
	$pname = htmlspecialchars($target['projectname']);
	?>
	<script type="text/javascript">
		function rtvalidate(){
			var update = document.getElementById("rtupdate");
			if((!update || !update.checked) && document.getElementById("projectid").value == ""){
				alert("Choose the project to load the GeoPackage into.");
				return false;
			}
			return true;
		}
	</script>
	<div id="main" class="wrapper style1">
		<div class="container">
			<header class="major">
				<h2>Load GeoPackage</h2>
			</header>
			<form method="POST" onsubmit="return rtvalidate();">
			<?php echo gpkgRoundTripHidden($hidden); ?>
			<?php if($target['ok']){ ?>
				<p>This GeoPackage was exported from the StraboSpot project <b><?php echo $pname?></b>.</p>
				<div style="padding-bottom:10px;">
					<input type="radio" id="rtupdate" name="rtpath" value="update" checked>
					<label for="rtupdate"><b>Update the original project "<?php echo $pname?>"</b> (recommended)<br>
					Geometry changes only: spots you moved or reshaped get their new geometry, features you drew become new spots.
					Names, notes and other attributes edited in the GeoPackage are not read.
					</label>
				</div>
				<div style="padding-bottom:20px;">
					<input type="radio" id="rtnew" name="rtpath" value="new">
					<label for="rtnew"><b>Load as new data</b><br>
					Every layer becomes a new dataset, as with any other GeoPackage, in this project:</label>
					<div style="padding-top:5px;" onclick="document.getElementById('rtnew').checked = true;"><?php echo $projectselect?></div>
				</div>
				<input class="primary" type="submit" name="rtsubmit" value="Continue">
			<?php }else{ ?>
				<p>This GeoPackage was exported from StraboSpot, but the original project cannot be updated:<br>
				<span style="color:#e44c65;"><?php echo htmlspecialchars($target['reason'])?></span></p>
				<p>You can load it as new data (every layer becomes a new dataset) into this project:</p>
				<div style="padding-bottom:20px;"><?php echo $projectselect?></div>
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
	The project's datasets: id => array('name', 'editable' for this user). For the new-spot
	dataset picker and for ds_id values typed in the GeoPackage.
*/
function gpkgRoundTripDatasets($neodb, $db, $target){
	$pid = $target['projectid'];
	$owner = (int)$target['owner'];
	require_once(__DIR__."/db/services/CollaborationAuth.php");
	$auth = new CollaborationAuth($db, $neodb);
	$datasets = array();
	foreach($neodb->get_results("MATCH (p:Project {id: $pid})-[:HAS_DATASET]->(d:Dataset) WHERE p.userpkey = $owner AND d.userpkey = $owner RETURN d.id AS id, d.name AS name, d.created_by AS createdby ORDER BY d.name") ?: array() as $r){
		$createdby = $r->get('createdby') ? (int)$r->get('createdby') : $owner;
		$datasets[(string)$r->get('id')] = array('name' => (string)$r->get('name'), 'editable' => $auth->canEditDataset($target['context'], $createdby));
	}
	return $datasets;
}

// The number after the highest "<prefix>N" spot name in the project, so new names never repeat
function gpkgRoundTripNextNumber($neodb, $target, $prefix){
	$pid = $target['projectid'];
	$owner = (int)$target['owner'];
	$max = 0;
	foreach($neodb->get_results("MATCH (p:Project {id: $pid})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = $owner AND d.userpkey = $owner AND exists(s.name) RETURN s.name AS name") ?: array() as $r){
		$name = (string)$r->get('name');
		if(strpos($name, $prefix) === 0 && preg_match('/^[0-9]+$/', substr($name, strlen($prefix)))) $max = max($max, (int)substr($name, strlen($prefix)));
	}
	return $max + 1;
}

// Default dataset for new spots: the exported dataset of a dataset export when it can be edited, else a new one
function gpkgRoundTripDefaultDataset($export, $datasets){
	$ids = explode(",", (string)($export['dataset_ids'] ?? ""));
	if(($export['scope'] ?? "") == "dataset" && count($ids) == 1 && isset($datasets[$ids[0]]) && $datasets[$ids[0]]['editable']) return $ids[0];
	return "new";
}

// First vertex of a geometry as "lon, lat" (to tell copies apart in the preview)
function gpkgRoundTripWhere($geometry){
	$c = $geometry['coordinates'];
	while(is_array($c) && is_array($c[0])) $c = $c[0];
	return number_format((float)$c[0], 6, ".", "").", ".number_format((float)$c[1], 6, ".", "");
}

/*
	Preview of an update of the original project (design 6.6): counts, a list per category, a
	choice per conflict and per copied spot, where new spots go and how they are named, Apply.
*/
function gpkgRoundTripPreviewPage($target, $result, $datasets, $defaultds, $hidden){
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
	$cando = $n('update') + $n('conflict') + $n('duplicate') + $n('new') > 0;

	$spotname = function($e){
		return $e['name'] !== "" ? htmlspecialchars($e['name']) : "(spot ".htmlspecialchars($e['id']).")";
	};
	$spotrow = function($e, $extra = "") use ($spotname){
		return "<tr><td>".$spotname($e)."</td><td>".htmlspecialchars($e['dataset_name'] ?? "")."</td><td>".htmlspecialchars($e['layer'])."</td><td>$extra</td></tr>";
	};
	$section = function($title, $intro, $rows, $open = false){
		if($rows === "") return;
		echo "<details".($open ? " open" : "")." style=\"padding-bottom:15px;\"><summary><b>".htmlspecialchars($title)."</b></summary>";
		if($intro !== "") echo "<div style=\"padding:5px 0;\">$intro</div>";
		echo "<table><thead><tr><th>Spot</th><th>Dataset</th><th>Layer</th><th></th></tr></thead>$rows</table></details>";
	};
	$radio = function($name, $value, $label, $id){
		return "<input type=\"radio\" class=\"rtchoice\" id=\"$id\" name=\"".htmlspecialchars($name, ENT_QUOTES)."\" value=\"".htmlspecialchars($value, ENT_QUOTES)."\" onchange=\"rtcheck();\"><label for=\"$id\">$label</label> ";
	};
	?>
	<script type="text/javascript">
		// Apply waits until every conflict and copied spot has an answer
		function rtcheck(){
			var groups = {}, answered = {};
			var inputs = document.querySelectorAll("input.rtchoice");
			for(var i = 0; i < inputs.length; i++){
				groups[inputs[i].name] = true;
				if(inputs[i].checked) answered[inputs[i].name] = true;
			}
			var open = 0;
			for(var g in groups){ if(!answered[g]) open++; }
			var b = document.getElementById("rtapply");
			if(b){ b.disabled = open > 0; }
			var note = document.getElementById("rtopen");
			if(note){ note.innerHTML = open > 0 ? open + " still to decide above." : ""; }
		}
		function rtall(value){
			var inputs = document.querySelectorAll("input.rtchoice[value='" + value + "']");
			for(var i = 0; i < inputs.length; i++){ inputs[i].checked = true; }
			rtcheck();
		}
	</script>
	<div id="main" class="wrapper style1">
		<div class="container">
			<header class="major">
				<h2>Update "<?php echo htmlspecialchars($target['projectname'])?>"</h2>
			</header>
			<p>Only geometries change. Names, notes and other attributes edited in the GeoPackage are not read.</p>
			<p><b><?php echo htmlspecialchars(implode(", ", $counts))?></b></p>
			<form method="POST">
			<?php
			echo gpkgRoundTripHidden($hidden);

			$rows = "";
			foreach($result['update'] as $e){
				$rows .= $spotrow($e, gpkgRoundTripVertices($e['orig'])." &rarr; ".gpkgRoundTripVertices($e['file'])." vertices");
			}
			$section("Updated (".$n('update').")", "Moved or reshaped in the GeoPackage.", $rows, true);

			$rows = "";
			$k = 0;
			foreach($result['conflict'] as $e){
				$when = gpkgRoundTripTime($e['modified_timestamp']);
				$rows .= $spotrow($e, "Changed on the server".($when !== "" ? " ($when)" : "")." since the export.<br>"
					.$radio("c[".$e['id']."]", "server", "Keep the server geometry", "rtc".$k."s")
					.$radio("c[".$e['id']."]", "file", "Use the GeoPackage geometry", "rtc".$k."f"));
				$k++;
			}
			$k = 0;
			foreach($result['duplicate'] as $e){
				$choices = "";
				foreach($e['features'] as $j => $f){
					$choices .= $radio("d[".$e['id']."]", (string)$f['feature'], "copy at ".gpkgRoundTripWhere($f['file']), "rtd".$k."_".$j);
				}
				$rows .= $spotrow($e, count($e['features'])." changed copies of this spot. Which one updates it? The others become new spots.<br>".$choices);
				$k++;
			}
			$intro = "";
			if($n('conflict') > 1){
				$intro = "For all: <a href=\"javascript:rtall('server');\">keep the server geometry</a> / <a href=\"javascript:rtall('file');\">use the GeoPackage geometry</a>";
			}
			$section("To decide (".($n('conflict') + $n('duplicate')).")", $intro, $rows, true);

			$rows = "";
			foreach($result['new'] as $e){
				$what = isset($e['copy_of']) ? "copy of spot ".htmlspecialchars($e['copy_of']) : "feature ".(int)$e['feature'];
				$dsname = (isset($datasets[$e['ds_id']]) && $datasets[$e['ds_id']]['editable']) ? $datasets[$e['ds_id']]['name'] : "";
				$rows .= "<tr><td>New spot</td><td>".htmlspecialchars($dsname)."</td><td>".htmlspecialchars($e['layer'])."</td><td>$what at ".gpkgRoundTripWhere($e['file'])."</td></tr>";
			}
			$section("New (".$n('new').")", "Drawn in the GeoPackage. They become new spots.", $rows, true);

			if($n('new') > 0){
				?>
				<div style="padding-bottom:15px;">
					<b>New spots go into</b> (unless their ds_id column names a dataset of this project you can edit):<br>
					<select name="newds" style="margin:5px 0;">
						<option value="new"<?php echo $defaultds == "new" ? " selected" : ""?>>A new dataset named below</option>
						<?php foreach($datasets as $id => $d){ if(!$d['editable']) continue; ?>
						<option value="<?php echo htmlspecialchars($id, ENT_QUOTES)?>"<?php echo (string)$defaultds === (string)$id ? " selected" : ""?>><?php echo htmlspecialchars($d['name'])?></option>
						<?php } ?>
					</select>
					New dataset name: <input type="text" name="newdsname" value="<?php echo htmlspecialchars("QGIS additions ".date("Y-m-d"), ENT_QUOTES)?>" style="margin:5px 0;"><br>
					Spot names: <input type="text" name="prefix" value="New Spot " style="margin:5px 0;"> followed by a number that continues after the project's highest one.
				</div>
				<?php
			}

			$rows = "";
			foreach($result['locked'] as $e) $rows .= $spotrow($e, "drawn on an image basemap or strat section");
			foreach($result['noaccess'] as $e) $rows .= $spotrow($e, "you cannot edit this dataset");
			foreach($result['gone'] as $e) $rows .= $spotrow($e, "no longer in this project");
			foreach($result['refused'] as $e){
				$name = $e['id'] !== "" ? $spotname($e) : "feature ".(int)$e['feature'];
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

			if($cando){
				?>
				<p style="padding-top:10px;">A version of the project is saved before anything is written (Versioning page), so the update can be rolled back.</p>
				<input type="hidden" name="rtpath" value="apply">
				<input class="primary" type="submit" id="rtapply" name="rtsubmit" value="Apply changes"> <span id="rtopen" style="color:#e44c65;"></span>
				<?php
			}else{
				echo "<p style=\"padding-top:10px;\">Nothing in this GeoPackage changes the project.</p>";
			}
			?>
			</form>
			<script type="text/javascript">rtcheck();</script>
			<form method="POST" style="padding-top:15px;">
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

/*
	Apply an update of the original project (design 7), from a classification made in this same
	request and the user's answers ($post: c[id] = server|file, d[id] = feature number, newds,
	newdsname, prefix). Runs as the project owner. A version of the project is saved first;
	without it nothing is written. Returns what happened:
	  error      message when nothing was written (version failed)
	  version    uuid of the saved version
	  updated    entries whose geometry was written
	  created    new spots: entry + 'spot_id', 'spot_name', 'dataset_id'
	  newdataset array(id, name) when a dataset was created
	  kept       conflicts answered "keep the server geometry"
	  skipped    entries not written, with 'reason'
*/
function gpkgRoundTripApply($neodb, $db, $userpkey, $export, $target, $result, $post){
	$pid = $target['projectid'];
	$owner = (int)$target['owner'];
	$out = array('error' => "", 'version' => "", 'updated' => array(), 'created' => array(), 'newdataset' => null, 'kept' => array(), 'skipped' => array());

	// What to write: updates, conflicts answered "GeoPackage", the chosen copy of a copied spot
	$writes = array();
	$new = $result['new'];
	foreach($result['update'] as $e) $writes[$e['id']] = $e;
	foreach($result['conflict'] as $e){
		$answer = $post['c'][$e['id']] ?? "";
		if($answer === "file"){
			$writes[$e['id']] = $e;
		}elseif($answer === "server"){
			$out['kept'][] = $e;
		}else{
			// it was an update on the preview page and changed on the server since
			$e['reason'] = "changed on the server after the preview";
			$out['skipped'][] = $e;
		}
	}
	foreach($result['duplicate'] as $e){
		$pick = (string)($post['d'][$e['id']] ?? "");
		$chosen = null;
		foreach($e['features'] as $f){
			if((string)$f['feature'] === $pick) $chosen = $f;
		}
		if($chosen === null){
			$e['reason'] = "no copy chosen";
			$out['skipped'][] = $e;
			continue;
		}
		$writes[$e['id']] = $chosen;
		foreach($e['features'] as $f){
			if($f['feature'] === $chosen['feature']) continue;
			$f['id'] = "";
			$f['copy_of'] = $e['id'];
			$new[] = $f;
		}
	}
	if(!count($writes) && !count($new)) return $out;

	$datasets = gpkgRoundTripDatasets($neodb, $db, $target);
	$newds = (string)($post['newds'] ?? "new");
	if($newds !== "new" && !(isset($datasets[$newds]) && $datasets[$newds]['editable'])) $newds = "new";
	$newdsname = trim((string)($post['newdsname'] ?? ""));
	if($newdsname === "") $newdsname = "QGIS additions ".date("Y-m-d");
	$prefix = (string)($post['prefix'] ?? "New Spot ");

	$strabo = new StraboSpot($neodb, $owner, $db);
	$strabo->setuuid(new UUID());

	// The undo, before any write
	$version = $strabo->createVersion($pid);
	if(!$version){
		$out['error'] = "The project version (the undo) could not be saved, so nothing was changed.";
		return $out;
	}
	$out['version'] = $version;

	require_once __DIR__.'/db/lib/search_sync.php';
	require_once __DIR__.'/db/lib/sample_sync.php';
	field_search_sync_suppress();

	$now = (int)round(microtime(true) * 1000);
	$touched = array();

	foreach($writes as $id => $e){
		if($strabo->updateSpotGeometry($id, $e['file'], $now)){
			$out['updated'][] = $e;
			$touched[$e['dataset_id']] = true;
		}else{
			$e['reason'] = "the geometry could not be written";
			$out['skipped'][] = $e;
		}
	}

	if(count($new)){
		include_once(__DIR__."/includes/straboClasses/straboModelClass.php");
		$smodel = new straboModelClass();
		$number = gpkgRoundTripNextNumber($neodb, $target, $prefix);
		$createdds = null;
		foreach($new as $e){
			$dsid = (isset($datasets[$e['ds_id']]) && $datasets[$e['ds_id']]['editable']) ? $e['ds_id'] : $newds;
			if($dsid === "new"){
				if($createdds === null){
					$createdds = (string)$smodel->getId();
					$neodb->createNode(json_encode(array('id' => (int)$createdds, 'userpkey' => $owner, 'created_by' => (int)$userpkey, 'name' => $newdsname,
						'datecreated' => time(), 'date' => date("c"), 'modified_timestamp' => $now, 'datasettype' => "geopackage")), "Dataset");
					$neodb->query("MATCH (p:Project {id: $pid}), (d:Dataset {id: $createdds}) WHERE p.userpkey = $owner AND d.userpkey = $owner CREATE (p)-[:HAS_DATASET]->(d)");
					$out['newdataset'] = array('id' => $createdds, 'name' => $newdsname);
					$datasets[$createdds] = array('name' => $newdsname, 'editable' => true);
				}
				$dsid = $createdds;
			}
			$spotid = $smodel->getId();
			$name = $prefix.$number;
			$number++;
			$strabo->insertSpot(json_encode(array(
				'type' => "Feature",
				'geometry' => $e['file'],
				'properties' => array('id' => $spotid, 'name' => $name, 'time' => date("c"), 'date' => date("c"), 'modified_timestamp' => $now),
			)));
			$strabo->addSpotToDataset($dsid, $spotid);
			$e['spot_id'] = (string)$spotid;
			$e['spot_name'] = $name;
			$e['dataset_id'] = $dsid;
			$e['dataset_name'] = $datasets[$dsid]['name'];
			$out['created'][] = $e;
			$touched[$dsid] = true;
		}
	}

	// StraboField keeps the newer copy: the touched datasets and the project are newer now
	foreach(array_keys($touched) as $dsid){
		$dsid = (int)$dsid;
		$neodb->query("MATCH (d:Dataset {id: $dsid}) WHERE d.userpkey = $owner SET d.modified_timestamp = $now");
	}
	$neodb->query("MATCH (p:Project {id: $pid}) WHERE p.userpkey = $owner SET p.modified_timestamp = $now");

	// Centers, the Postgres mirror, search, sample locations (as after an import)
	$strabo->setProjectCenter($pid, $owner);
	foreach(array_keys($touched) as $dsid){
		$strabo->setDatasetCenter($dsid, $owner);
		$strabo->buildPgDataset($dsid, $owner);
	}
	field_search_sync_resume();
	foreach(array_keys($touched) as $dsid){
		field_search_sync_dataset($db, $neodb, $dsid, $owner);
	}
	foreach($out['updated'] as $e){
		field_sample_sync_resync_spot($db, $neodb, $e['id'], $owner, $pid, $e['dataset_id']);
	}

	return $out;
}

// What Apply did (design 7.7)
function gpkgRoundTripResultPage($target, $out, $isowner){
	$spotname = function($e){
		return $e['name'] !== "" ? htmlspecialchars($e['name']) : "(spot ".htmlspecialchars($e['id']).")";
	};
	?>
	<div id="main" class="wrapper style1">
		<div class="container">
			<header class="major">
				<h2>Update "<?php echo htmlspecialchars($target['projectname'])?>"</h2>
			</header>
			<?php if($out['error'] !== ""){ ?>
				<p style="color:#e44c65;"><?php echo htmlspecialchars($out['error'])?></p>
			<?php }elseif($out['version'] === ""){ ?>
				<p>Nothing was changed.</p>
			<?php }else{ ?>
				<p><b><?php echo count($out['updated'])?> spot<?php echo count($out['updated']) == 1 ? "" : "s"?> updated, <?php echo count($out['created'])?> new spot<?php echo count($out['created']) == 1 ? "" : "s"?> created.</b></p>
				<?php
				if(count($out['updated'])){
					echo "<details style=\"padding-bottom:15px;\"><summary><b>Updated (".count($out['updated']).")</b></summary><ul>";
					foreach($out['updated'] as $e) echo "<li>".$spotname($e)." (".htmlspecialchars($e['dataset_name']).")</li>";
					echo "</ul></details>";
				}
				if(count($out['created'])){
					echo "<details style=\"padding-bottom:15px;\"><summary><b>New spots (".count($out['created']).")</b></summary><ul>";
					foreach($out['created'] as $e) echo "<li>".htmlspecialchars($e['spot_name'])." (".htmlspecialchars($e['dataset_name']).")</li>";
					echo "</ul></details>";
					if($out['newdataset'] !== null) echo "<p>New dataset: ".htmlspecialchars($out['newdataset']['name'])."</p>";
				}
				if(count($out['kept'])){
					echo "<details style=\"padding-bottom:15px;\"><summary><b>Server geometry kept (".count($out['kept']).")</b></summary><ul>";
					foreach($out['kept'] as $e) echo "<li>".$spotname($e)."</li>";
					echo "</ul></details>";
				}
				if(count($out['skipped'])){
					echo "<details open style=\"padding-bottom:15px;\"><summary><b>Not updated (".count($out['skipped']).")</b></summary><ul>";
					foreach($out['skipped'] as $e) echo "<li>".$spotname($e).": ".htmlspecialchars($e['reason'])."</li>";
					echo "</ul></details>";
				}
				?>
				<p>A version of the project was saved before the update<?php echo $isowner ? "; to undo it, roll back to that version on the <a href=\"versioning\">Versioning</a> page" : " (in the project owner's Versioning page, where they can roll it back)"?>.</p>
				<p><b>In StraboField, download this project again before editing it there</b>, or the app's older copy of these spots can replace the new geometries.</p>
			<?php } ?>
			<div style="padding-top:20px;"><a href="my_field_data">Continue to My Field Data</a></div>
			<div class="bottomSpacer"></div>
		</div>
	</div>
	<?php
}

?>
