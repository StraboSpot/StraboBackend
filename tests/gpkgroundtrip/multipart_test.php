<?php
/**
 * File: tests/gpkgroundtrip/multipart_test.php
 * Description: GeoPackage export and round trip of multi-part lines and polygons (Rip Langford
 *              2026-11-04: Three Sisters/polygons, all MULTIPOLYGON, exported as "No data found").
 *              A project like his: the golden fixture (single points, lines, polygons) plus a
 *              "polygons" dataset of MultiPolygons and a "lines" dataset of MultiLineStrings, imported
 *              through load_geopackage.php the way a user loads GIS data. Checks:
 *                - the import stores MULTIPOLYGON / MULTILINESTRING spots (Rip's data)
 *                - dataset export of each: a file, layer MultiPolygon / MultiLineString, every spot with
 *                  its parts
 *                - project export: lines / polygons layers multi, every spot once, points layer unchanged
 *                  type, manifest row per spot
 *                - untouched export uploaded: every spot unchanged, nothing refused / new / missing
 *                - edited file (a QGIS save): a 2-part spot moved, a 4-part line reshaped, a 1-part
 *                  multi spot moved, a single fixture polygon moved (now a 1-part multi in the file),
 *                  a new 1-part feature, a new 2-part feature: preview, Apply, stored geometries (parts
 *                  kept, single spots stay single), other properties unchanged, the new 2-part feature
 *                  refused (a new spot has one geometry), a fresh export reads back unchanged
 *
 *              Uses the golden fixture of tests/fieldvocab (needs the test users from
 *              tests/collaboration/setup_test_data.php).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/gpkgroundtrip/multipart_test.php
 */

$SID_PREFIX = 'fvgpkgmp';
require_once __DIR__ . '/../fieldvocab/fixture_lib.php';
require_once __DIR__ . '/rt_lib.php';

$TMP = sys_get_temp_dir() . '/gpkgmp_' . getmypid();
$RANDNUM = 900000000 + getmypid();
$_SERVER['HTTP_HOST'] = 'localhost';

function text($html) { return trim(preg_replace('/\s+/', ' ', strip_tags($html))); }
function grab($html, $name) { return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }

/** Upload a file, choose "update", return [randnum, preview html]. */
function preview($file) {
	$html = post(array('submitfile' => 'Submit'), $file);
	$rn = randnum_of($html);
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'update', 'randnum' => $rn, 'gpkgname' => basename($file)));
	return array($rn, $html);
}

function apply($rn, $file, $answers) {
	return post(array('rtsubmit' => 'Apply changes', 'rtpath' => 'apply', 'randnum' => $rn, 'gpkgname' => basename($file)) + $answers);
}

/** Geometry of a spot as stored (geoPHP of wkt), as GeoJSON array. */
function stored_geometry($id) {
	$p = spot_props($id);
	return $p ? json_decode(geoPHP::load($p['wkt'], 'wkt')->out('json'), true) : null;
}

function untouched_props($p) {
	unset($p['wkt'], $p['origwkt'], $p['modified_timestamp'], $p['bbox']);
	return $p;
}

/** Spots of the project's imported datasets: name => [id, dataset name, wkt]. */
function imported_spots() {
	global $neodb, $OWNER;
	$out = array();
	foreach ($neodb->get_results("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = $OWNER AND d.name IN ['polygons', 'lines'] RETURN s.id AS id, s.name AS name, d.name AS ds, d.id AS dsid, s.wkt AS wkt") ?: array() as $r) {
		$out[(string)$r->get('name')] = array('id' => (string)$r->get('id'), 'ds' => (string)$r->get('ds'), 'dsid' => (string)$r->get('dsid'), 'wkt' => (string)$r->get('wkt'));
	}
	ksort($out);
	return $out;
}

function gpkg_layers($gpkg) {
	$out = array();
	foreach (geopackageLayers($gpkg) as $name => $info) $out[$name] = strtoupper($info['geometrytype']) . ' ' . $info['count'];
	ksort($out);
	return $out;
}

function parts($g) { return (strpos($g['type'] ?? '', 'Multi') === 0) ? count($g['coordinates']) : 1; }

function download($query, $name) {
	global $TMP;
	list($st, $bytes) = http('GET', '/searchdownload?type=gpkg&' . $query, null, 'session');
	$f = "$TMP/$name.gpkg";
	file_put_contents($f, $bytes);
	return array($f, $st, $bytes);
}

/** One import of a GeoPackage layer through load_geopackage.php into the fixture project. */
function import_layer($gpkg, $layer, $dsname) {
	$html = post(array('submitfile' => 'Submit', 'projectid' => GF_PROJECT), $gpkg);
	$rn = randnum_of($html);
	$page = post(array('indsubmit' => 'Submit', 'randnum' => $rn, 'projectid' => GF_PROJECT, 'gpkgname' => 'edited.gpkg', 'layer0' => $layer));
	check("import $layer: column page", strpos($page, 'Define Columns for GeoPackage') !== false && grab($page, 'layer') === $layer, substr(text($page), 0, 300));
	$out = post(array('columnsubmit' => 'Submit', 'randnum' => $rn, 'projectid' => GF_PROJECT, 'gpkgname' => 'edited.gpkg',
	                  'layer' => $layer, 'layerqueue' => '[]', 'datasetname' => $dsname, 'spotnameprefix' => '', 'fixedcolumns' => 'name:spot_name'));
	check("import $layer: GeoPackage Load Complete", strpos($out, 'GeoPackage Load Complete') !== false, substr(text($out), 0, 300));
}

function cleanup_imports() {
	global $neodb, $db, $OWNER;
	foreach ($neodb->get_results("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset) WHERE p.userpkey = $OWNER AND NOT d.id IN [" . GF_DS_A . ", " . GF_DS_B . "] RETURN d.id AS id") ?: array() as $r) {
		$d = (int)$r->get('id');
		$db->query("DELETE FROM spot WHERE user_pkey = $OWNER AND dataset_pkey IN (SELECT dataset_pkey FROM dataset WHERE strabo_dataset_id = '$d' AND user_pkey = $OWNER)");
		$db->query("DELETE FROM dataset WHERE user_pkey = $OWNER AND strabo_dataset_id = '$d'");
		$neodb->query("MATCH (d:Dataset {id: $d})-[:HAS_SPOT]->(s:Spot) WHERE d.userpkey = $OWNER DETACH DELETE s");
		$neodb->query("MATCH (d:Dataset {id: $d}) WHERE d.userpkey = $OWNER DETACH DELETE d");
	}
	$db->query("DELETE FROM spot WHERE user_pkey = $OWNER AND strabo_spot_id NOT LIKE '97791%' AND dataset_pkey IN (SELECT dataset_pkey FROM dataset WHERE strabo_dataset_id LIKE '97791%' AND user_pkey = $OWNER)");
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND project_id = $1", array((string)GF_PROJECT));
}

// GIS data like Rip's: every feature multi, 1 to 4 parts (a ring square at x, y of size d)
function sq($x, $y, $d) { return array(array($x, $y), array($x + $d, $y), array($x + $d, $y + $d), array($x, $y + $d), array($x, $y)); }
$MP = array(
	'MP one part'   => array(array(sq(-121.70, 44.10, 0.01))),
	'MP two parts'  => array(array(sq(-121.68, 44.10, 0.01)), array(sq(-121.66, 44.10, 0.01))),
	'MP three hole' => array(array(sq(-121.64, 44.10, 0.03), sq(-121.63, 44.11, 0.005)), array(sq(-121.60, 44.10, 0.01)), array(sq(-121.58, 44.10, 0.01))),
);
$ML = array(
	'ML one part'   => array(array(array(-121.70, 44.20), array(-121.69, 44.21))),
	'ML two parts'  => array(array(array(-121.68, 44.20), array(-121.67, 44.21)), array(array(-121.66, 44.20), array(-121.65, 44.21), array(-121.64, 44.20))),
	'ML four parts' => array(array(array(-121.62, 44.20), array(-121.61, 44.21)), array(array(-121.60, 44.20), array(-121.59, 44.21)), array(array(-121.58, 44.20), array(-121.57, 44.21)), array(array(-121.56, 44.20), array(-121.55, 44.21))),
);

echo "GeoPackage export + round trip of multi-part lines and polygons\n";
gf_cleanup();
cleanup_imports();
exec('rm -rf ' . escapeshellarg($TMP)); mkdir($TMP, 0775, true);
$OGR_BEFORE = glob('/srv/app/www/ogrtemp/*') ?: array();
$ZIP_BEFORE = glob('/srv/app/www/ziptemp/*') ?: array();
try {
	section('A project like Rip\'s: fixture + imported MultiPolygon and MultiLineString datasets');
	gf_upload();
	$S = gf_spots();
	$gis = "$TMP/gis.gpkg";
	$fc = function ($type, $set) { $f = array(); foreach ($set as $n => $c) $f[] = array('type' => 'Feature', 'properties' => array('name' => $n), 'geometry' => array('type' => $type, 'coordinates' => $c)); return json_encode(array('type' => 'FeatureCollection', 'features' => $f)); };
	file_put_contents("$TMP/mp.json", $fc('MultiPolygon', $MP));
	file_put_contents("$TMP/ml.json", $fc('MultiLineString', $ML));
	exec('ogr2ogr -f GPKG -nlt MULTIPOLYGON -a_srs EPSG:4326 -nln gis_polygons ' . escapeshellarg($gis) . ' ' . escapeshellarg("$TMP/mp.json") . ' 2>&1');
	exec('ogr2ogr -f GPKG -update -nlt MULTILINESTRING -a_srs EPSG:4326 -nln gis_lines ' . escapeshellarg($gis) . ' ' . escapeshellarg("$TMP/ml.json") . ' 2>&1');
	import_layer($gis, 'gis_polygons', 'polygons');
	import_layer($gis, 'gis_lines', 'lines');
	$IMP = imported_spots();
	$types = array();
	foreach ($IMP as $n => $s) $types[$n] = $s['ds'] . ' ' . strtoupper(strtok(trim($s['wkt']), ' ('));
	check('imported: 3 MULTIPOLYGON spots in "polygons", 3 MULTILINESTRING in "lines" (Rip\'s data)', $types === array(
		'ML four parts' => 'lines MULTILINESTRING', 'ML one part' => 'lines MULTILINESTRING', 'ML two parts' => 'lines MULTILINESTRING',
		'MP one part' => 'polygons MULTIPOLYGON', 'MP three hole' => 'polygons MULTIPOLYGON', 'MP two parts' => 'polygons MULTIPOLYGON'), json_encode($types));
	$DSP = $IMP['MP one part']['dsid'] ?? '';
	$DSL = $IMP['ML one part']['dsid'] ?? '';

	section('Dataset exports (Rip\'s case)');
	foreach (array('polygons' => array($DSP, 'MP', 'MULTIPOLYGON'), 'lines' => array($DSL, 'ML', 'MULTILINESTRING')) as $layer => list($dsid, $prefix, $gtype)) {
		list($f, $st, $bytes) = download("dsids=$dsid", "ds_$layer");
		check("$layer dataset: a GeoPackage, not \"No data found\"", $st === 200 && substr($bytes, 0, 15) === 'SQLite format 3', "HTTP $st " . substr($bytes, 0, 80));
		check("$layer dataset: one layer, $gtype, 3 features", gpkg_layers($f) === array($layer => "$gtype 3"), json_encode(gpkg_layers($f)));
		$got = array();
		foreach (layer_features($f, $layer) as $ft) {
			$sid = sid($ft);
			$name = array_search($sid, array_map(function ($s) { return $s['id']; }, $IMP), true);
			$got[$name] = gpkgRoundTripSame($ft['geometry'], stored_geometry($sid)) ? parts($ft['geometry']) . ' parts, same' : 'DIFFERENT';
		}
		ksort($got);
		$want = $layer === 'polygons' ? array('MP one part' => '1 parts, same', 'MP three hole' => '3 parts, same', 'MP two parts' => '2 parts, same')
		                              : array('ML four parts' => '4 parts, same', 'ML one part' => '1 parts, same', 'ML two parts' => '2 parts, same');
		check("$layer dataset: every spot with all its parts, geometry = stored", $got === $want, json_encode($got));
		$r = summary(classify($f));
		check("$layer dataset export uploaded untouched: 3 unchanged, nothing else", count($r['unchanged']) === 3 && !$r['update'] && !$r['conflict'] && !$r['new'] && !$r['refused'] && !$r['missing'], json_encode($r));
	}

	section('Shapefile exports of the same datasets (legacy and expanded)');
	foreach (array('shapefile', 'expandedShapefile') as $type) {
		foreach (array('polygons' => array($DSP, array(1, 2, 3)), 'lines' => array($DSL, array(1, 2, 4))) as $layer => list($dsid, $wantParts)) {
			list($st, $bytes) = http('GET', "/searchdownload?type=$type&userpkey=$OWNER&dsids=$dsid", null, 'session');
			$zip = "$TMP/{$type}_$layer.zip";
			file_put_contents($zip, $bytes);
			check("$type $layer: a zip, not \"No data found\"", $st === 200 && substr($bytes, 0, 2) === 'PK', "HTTP $st " . substr($bytes, 0, 80));
			$dir = "$TMP/{$type}_$layer";
			exec('rm -rf ' . escapeshellarg($dir) . '; mkdir -p ' . escapeshellarg($dir) . '; unzip -q -o ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($dir));
			$shp = "$dir/$layer.shp";
			$fc = json_decode((string)shell_exec('ogr2ogr -f GeoJSON /vsistdout/ ' . escapeshellarg($shp) . ' 2>/dev/null'), true);
			$parts = array_map(function ($f) { return parts($f['geometry']); }, $fc['features'] ?? array());
			sort($parts);
			check("$type $layer: $layer.shp with the 3 spots, parts " . json_encode($wantParts), $parts === $wantParts, json_encode($parts) . ' ' . implode(',', array_map('basename', glob("$dir/*.shp") ?: array())));
		}
	}

	section('Project export');
	list($EXPORT, $st, $bytes) = download('projectid=' . GF_PROJECT, 'export');
	check('project export downloaded', $st === 200 && substr($bytes, 0, 15) === 'SQLite format 3', "HTTP $st");
	$L = gpkg_layers($EXPORT);
	check('lines + polygons layers multi, points still Point', strpos($L['lines'] ?? '', 'MULTILINESTRING') === 0 && strpos($L['polygons'] ?? '', 'MULTIPOLYGON') === 0 && strpos($L['points'] ?? '', 'POINT ') === 0, json_encode($L));
	$nspots = (int)$neodb->get_var("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = $OWNER RETURN count(distinct s)");
	$manifest = count(geopackageQuery($EXPORT, 'SELECT spot_id FROM strabo_spots') ?: array());
	$inLayers = 0;
	foreach (array('points', 'lines', 'polygons') as $l) $inLayers += count(layer_features($EXPORT, $l));
	check("every spot of the project in the file once ($nspots spots, manifest $manifest, layers $inLayers)", $nspots > 6 && $manifest === $nspots && $inLayers === $nspots);
	$r = summary(classify($EXPORT));
	check('untouched project export: every spot unchanged, nothing refused / new / missing', count($r['unchanged']) === $nspots && !$r['update'] && !$r['conflict'] && !$r['new'] && !$r['refused'] && !$r['missing'], json_encode($r));

	section('Edit the file the way a QGIS save leaves it');
	$fileGeom = array();
	$edited = rebuild($EXPORT, "$TMP/edited.gpkg", function ($layer, $fs) use ($IMP, $S, &$fileGeom) {
		$out = array();
		foreach ($fs as $f) {
			$id = sid($f);
			if ($id === $IMP['MP two parts']['id'] || $id === $IMP['MP one part']['id']) $f['geometry'] = shift($f['geometry'], 0.001, 0.002);
			if ($id === $IMP['ML four parts']['id']) $f['geometry'] = shift($f['geometry'], 0.0005, 0, true);
			if ($id === (string)$S['surface']) $f['geometry'] = shift($f['geometry'], 0.002, 0);
			if ($id !== '') $fileGeom[$id] = $f['geometry'];
			$out[] = $f;
		}
		if ($layer === 'polygons') {
			$out[] = array('type' => 'Feature', 'properties' => array('strabo_id' => null), 'geometry' => array('type' => 'MultiPolygon', 'coordinates' => array(array(sq(-121.50, 44.10, 0.01)))));
			$out[] = array('type' => 'Feature', 'properties' => array('strabo_id' => null), 'geometry' => array('type' => 'MultiPolygon', 'coordinates' => array(array(sq(-121.48, 44.10, 0.01)), array(sq(-121.46, 44.10, 0.01)))));
		}
		return $out;
	}, null, 'EPSG:4326', array('lines' => 'MULTILINESTRING', 'polygons' => 'MULTIPOLYGON'));
	check('edited file: polygons layer in the file is MultiPolygon (fixture polygon now a 1-part multi)', strpos(gpkg_layers($edited)['polygons'] ?? '', 'MULTIPOLYGON') === 0);
	$watch = array('MP two parts' => $IMP['MP two parts']['id'], 'MP one part' => $IMP['MP one part']['id'], 'ML four parts' => $IMP['ML four parts']['id'], 'surface' => (string)$S['surface']);
	$before = array();
	foreach (array_merge(array_values($watch), array($IMP['MP three hole']['id'], $IMP['ML two parts']['id'], (string)$S['trace'])) as $id) $before[$id] = spot_props($id);
	$r = summary(classify($edited));
	$wantUpdate = array_values($watch); sort($wantUpdate);
	check('classify: the 4 edited spots updated, the rest unchanged, 1 new, 1 refused (2-part new feature)', $r['update'] === $wantUpdate && count($r['unchanged']) === $nspots - 4 && $r['new'] === 1 && $r['refused'] === 1 && !$r['conflict'] && !$r['missing'], json_encode($r));

	section('Preview and Apply');
	list($rn, $html) = preview($edited);
	check('preview: 4 updated', strpos($html, '4 updated, ') !== false, substr(text($html), 0, 400));
	check('preview: the 2-part new feature listed as refused', strpos(text($html), '2 parts (a spot has one geometry)') !== false, substr(text($html), 0, 600));
	$t0 = (int)round(microtime(true) * 1000);
	$html = apply($rn, $edited, array('newds' => 'new', 'newdsname' => 'MP additions', 'prefix' => 'MP Spot '));
	$t1 = (int)round(microtime(true) * 1000);
	check('result: 4 spots updated, 1 new spot created', strpos($html, '4 spots updated, 1 new spot created.') !== false, substr(text($html), 0, 500));

	section('Stored geometries');
	$want = array('MP two parts' => 'MULTIPOLYGON 2', 'MP one part' => 'POLYGON 1', 'ML four parts' => 'MULTILINESTRING 4', 'surface' => 'POLYGON 1');
	$got = array();
	foreach ($watch as $k => $id) {
		$p = spot_props($id);
		$g = stored_geometry($id);
		$got[$k] = strtoupper(strtok(trim($p['wkt']), ' (')) . ' ' . parts($g);
		check("$k: stored geometry = the file's", gpkgRoundTripSame(gpkgRoundTripNormal($g), gpkgRoundTripNormal($fileGeom[$id])), json_encode($g));
		check("$k: wkt = origwkt, modified_timestamp now", $p['wkt'] === $p['origwkt'] && (int)$p['modified_timestamp'] >= $t0 && (int)$p['modified_timestamp'] <= $t1);
		check("$k: every other property unchanged", untouched_props($p) === untouched_props($before[$id]));
	}
	check('types and parts: multi-part spots keep their parts, single spots stay single (a 1-part multi is stored unwrapped)', $got === $want, json_encode($got));
	foreach (array('MP three hole' => $IMP['MP three hole']['id'], 'ML two parts' => $IMP['ML two parts']['id'], 'trace' => (string)$S['trace']) as $k => $id) {
		check("$k (not edited): byte-identical in Neo4j", spot_props($id) === $before[$id]);
	}
	$newWkt = (string)$neodb->get_var("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = $OWNER AND toString(s.name) STARTS WITH 'MP Spot ' RETURN s.wkt");
	check('new spot: the 1-part feature, stored as POLYGON', strpos($newWkt, 'POLYGON') === 0, $newWkt);

	section('A fresh export reads back unchanged');
	list($again, $st, $bytes) = download('projectid=' . GF_PROJECT, 'again');
	$n2 = (int)$neodb->get_var("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = $OWNER RETURN count(distinct s)");
	$r = summary(classify($again));
	check("fresh export: every spot unchanged ($n2 spots)", count($r['unchanged']) === $n2 && !$r['update'] && !$r['conflict'] && !$r['new'] && !$r['refused'] && !$r['missing'], json_encode($r));
} catch (Throwable $e) {
	check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
	cleanup_imports();
	gf_cleanup();
	exec('rm -rf ' . escapeshellarg($TMP));
	foreach (array_diff(glob('/srv/app/www/ogrtemp/*') ?: array(), $OGR_BEFORE) as $dd) exec('rm -rf ' . escapeshellarg($dd));
	foreach (array_diff(glob('/srv/app/www/ziptemp/*') ?: array(), $ZIP_BEFORE) as $dd) exec('rm -rf ' . escapeshellarg($dd));
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
