<?php
/**
 * File: single_part_multi_test.php
 * Description: A GeoPackage saved from QGIS / ArcGIS declares shapefile layers Multi, so every single
 *              polygon or line is a one-part MultiPolygon / MultiLineString (Rip Langford, 2026-11-04:
 *              69 one-part MULTIPOLYGON + 65 one-part MULTILINESTRING spots). Uploads such a GeoPackage
 *              through load_geopackage.php (polygons, lines and points layers declared Multi, each with
 *              a single feature and a real two-part feature) and checks that single features are stored
 *              as Polygon / LineString / Point, holes kept, and two-part features stay Multi.
 *              Fails on the code before vectorImportSinglePart().
 *
 *              docker exec strabo-php php /srv/app/www/tests/vectorimport/single_part_multi_test.php
 *
 *              Uses owner@test.strabospot.org (setup_test_data.php) and a forged session.
 *              Hermetic: a '96672'-prefixed project, removed at the end.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/tests/vectorimport/vector_import_lib.php';
require_once '/srv/app/www/vector_import.php';

$failures = array();
function check($label, $cond) {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
	if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

$URL = "http://localhost/load_geopackage.php";
$stamp = time();
$projId = 96672 * 10000000 + ($stamp % 10000000);
$workDir = "/tmp/singlepart_$stamp";
@mkdir($workDir, 0777, true);

function ogr($args) { return shell_exec("ogr2ogr $args 2>&1"); }

// Stored spots of one dataset of the test project, by name: geometry type + coordinates.
function storedByName($projId, $dsname) {
	global $neodb, $db, $ownerPkey;
	$r = $neodb->get_results("MATCH (p:Project {id:$projId})-[:HAS_DATASET]->(d:Dataset) RETURN d.id AS id, d.name AS name");
	$dsid = null;
	foreach ($r ?: array() as $row) if ($row->get('name') === $dsname) $dsid = $row->get('id');
	if (!$dsid) return array();
	$strabo = new StraboSpot($neodb, $ownerPkey, $db);
	$out = array();
	foreach (json_decode(json_encode($strabo->getDatasetSpots($dsid)), true)['features'] ?? array() as $f) {
		$out[$f['properties']['name'] ?? ''] = $f['geometry'] ?? null;
	}
	return $out;
}

// Neo4j wkt prefix of each stored spot of the project (what the exports read).
function storedWktTypes($projId) {
	global $neodb;
	$t = array();
	foreach ($neodb->get_results("MATCH (p:Project {id:$projId})-[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(s:Spot) RETURN s.name AS n, s.wkt AS w") ?: array() as $row) {
		$t[$row->get('n')] = strtoupper(trim(explode('(', (string)$row->get('w'))[0]));
	}
	ksort($t);
	return $t;
}

try {
	echo "\n=== unit: vectorImportSinglePart ===\n";
	$poly = array(array(array(0, 0), array(1, 0), array(1, 1), array(0, 0)));
	check("unit: one-part MultiPolygon -> Polygon", vectorImportSinglePart(array('type' => 'MultiPolygon', 'coordinates' => array($poly))) === array('type' => 'Polygon', 'coordinates' => $poly));
	check("unit: one-part MultiLineString -> LineString", vectorImportSinglePart(array('type' => 'MultiLineString', 'coordinates' => array(array(array(0, 0), array(1, 1)))))['type'] === 'LineString');
	check("unit: one-point MultiPoint -> Point", vectorImportSinglePart(array('type' => 'MultiPoint', 'coordinates' => array(array(3, 4)))) === array('type' => 'Point', 'coordinates' => array(3, 4)));
	$two = array('type' => 'MultiPolygon', 'coordinates' => array($poly, $poly));
	check("unit: two-part MultiPolygon unchanged", vectorImportSinglePart($two) === $two);
	check("unit: Polygon, null, empty Multi unchanged", vectorImportSinglePart(array('type' => 'Polygon', 'coordinates' => $poly))['type'] === 'Polygon'
	      && vectorImportSinglePart(null) === null && vectorImportSinglePart(array('type' => 'MultiPolygon', 'coordinates' => array()))['coordinates'] === array());

	// ---------------------------------------------------------------------
	// Fixture: the way QGIS saves a shapefile as GeoPackage (layers declared Multi, plain features promoted)
	// ---------------------------------------------------------------------
	$outer = array(array(-105, 39), array(-104.9, 39), array(-104.9, 39.1), array(-105, 39.1), array(-105, 39));
	$hole  = array(array(-104.98, 39.02), array(-104.98, 39.04), array(-104.96, 39.04), array(-104.96, 39.02), array(-104.98, 39.02));
	$sq = function ($x, $y) { return array(array(array($x, $y), array($x + 0.01, $y), array($x + 0.01, $y + 0.01), array($x, $y + 0.01), array($x, $y))); };
	$fc = function ($features) { return json_encode(array('type' => 'FeatureCollection', 'features' => $features)); };
	$feat = function ($name, $type, $coords) { return array('type' => 'Feature', 'properties' => array('label' => $name), 'geometry' => array('type' => $type, 'coordinates' => $coords)); };
	file_put_contents("$workDir/polygons.geojson", $fc(array(
		$feat('poly single', 'Polygon', array($outer)),
		$feat('poly with hole', 'Polygon', array($outer, $hole)),
		$feat('poly two parts', 'MultiPolygon', array($sq(-106, 40), $sq(-106.5, 40.5))))));
	file_put_contents("$workDir/lines.geojson", $fc(array(
		$feat('line single', 'LineString', array(array(-105, 39), array(-105.1, 39.2), array(-105.3, 39.3))),
		$feat('line two parts', 'MultiLineString', array(array(array(-106, 40), array(-106.1, 40.1)), array(array(-106.5, 40.5), array(-106.6, 40.6)))))));
	file_put_contents("$workDir/points.geojson", $fc(array(
		$feat('point single', 'Point', array(-105.5, 39.5)),
		$feat('point two parts', 'MultiPoint', array(array(-105.6, 39.6), array(-105.7, 39.7))))));
	$gpkg = "$workDir/rip_like.gpkg";
	ogr("-f GPKG -nlt MULTIPOLYGON -nln polygons " . escapeshellarg($gpkg) . " " . escapeshellarg("$workDir/polygons.geojson"));
	ogr("-f GPKG -update -nlt MULTILINESTRING -nln lines " . escapeshellarg($gpkg) . " " . escapeshellarg("$workDir/lines.geojson"));
	ogr("-f GPKG -update -nlt MULTIPOINT -nln points " . escapeshellarg($gpkg) . " " . escapeshellarg("$workDir/points.geojson"));
	$info = (string)shell_exec("ogrinfo -ro -so -al " . escapeshellarg($gpkg) . " 2>&1");
	check("fixture: layers declared Multi Polygon / Multi Line String / Multi Point",
	      strpos($info, "Geometry: Multi Polygon") !== false && strpos($info, "Geometry: Multi Line String") !== false && strpos($info, "Geometry: Multi Point") !== false);

	$neodb->query("CREATE (p:Project {id:$projId, userpkey:$ownerPkey, desc_project_name:'Single-part Multi test'})");
	$sid = viForgeSession($ownerPkey);

	// ---------------------------------------------------------------------
	echo "\n=== upload through load_geopackage.php ===\n";
	$html = viPostFile($sid, $URL, array('submitfile' => 'Submit', 'projectid' => $projId), 'gpkgfile', $gpkg, 'rip_like.gpkg', 'application/geopackage+sqlite3');
	$randnum = viGrab($html, 'randnum');
	$viRandnums[] = $randnum;
	check("layer list page", strpos($html, "Select Layer(s)") !== false && $randnum);

	$layers = array('polygons', 'lines', 'points');
	$queue = $layers;
	$first = true;
	while ($queue) {
		$layer = array_shift($queue);
		$fields = array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'gpkgname' => 'rip_like.gpkg', 'spotnameprefix' => '');
		if ($first) {
			foreach ($layers as $i => $l) $fields["layer$i"] = $l;
			$first = false;
		} else {
			$fields['layerqueue'] = json_encode(array_merge(array($layer), $queue));
		}
		$page = viPost($sid, $URL, $fields);
		check("$layer: column page", viGrab($page, 'layer') === $layer);
		$out = viPost($sid, $URL, array('columnsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'gpkgname' => 'rip_like.gpkg',
		                                'layer' => $layer, 'layerqueue' => json_encode($queue), 'datasetname' => $layer, 'spotnameprefix' => '',
		                                'fixedcolumns' => 'label:spot_name'));
		check("$layer: imported", strpos($out, "GeoPackage Load Complete") !== false);
	}

	// ---------------------------------------------------------------------
	echo "\n=== stored geometry ===\n";
	$p = storedByName($projId, 'polygons');
	$l = storedByName($projId, 'lines');
	$pt = storedByName($projId, 'points');
	$type = function ($g) { return $g['type'] ?? '(none)'; };
	note("polygons: " . json_encode(array_map($type, $p)));
	note("lines:    " . json_encode(array_map($type, $l)));
	note("points:   " . json_encode(array_map($type, $pt)));
	check("single polygon stored as Polygon", $type($p['poly single'] ?? null) === 'Polygon' && count($p['poly single']['coordinates']) === 1);
	check("polygon with a hole stored as Polygon with its hole", $type($p['poly with hole'] ?? null) === 'Polygon' && count($p['poly with hole']['coordinates']) === 2);
	check("two-part polygon stays MultiPolygon (2 parts)", $type($p['poly two parts'] ?? null) === 'MultiPolygon' && count($p['poly two parts']['coordinates']) === 2);
	check("single line stored as LineString (3 vertices)", $type($l['line single'] ?? null) === 'LineString' && count($l['line single']['coordinates']) === 3);
	check("two-part line stays MultiLineString (2 parts)", $type($l['line two parts'] ?? null) === 'MultiLineString' && count($l['line two parts']['coordinates']) === 2);
	check("single point stored as Point", $type($pt['point single'] ?? null) === 'Point');
	check("two-point MultiPoint stays MultiPoint (2 points)", $type($pt['point two parts'] ?? null) === 'MultiPoint' && count($pt['point two parts']['coordinates']) === 2);
	$sp = $p['poly single']['coordinates'][0] ?? array();
	check("single polygon keeps its vertices", count($sp) === 5 && abs($sp[1][0] + 104.9) < 1e-9 && abs($sp[2][1] - 39.1) < 1e-9);

	$wkt = storedWktTypes($projId);
	note("wkt: " . json_encode($wkt));
	check("Neo4j wkt matches (what the exports read)", $wkt === array(
		'line single' => 'LINESTRING', 'line two parts' => 'MULTILINESTRING',
		'point single' => 'POINT', 'point two parts' => 'MULTIPOINT',
		'poly single' => 'POLYGON', 'poly two parts' => 'MULTIPOLYGON', 'poly with hole' => 'POLYGON'));

} catch (Throwable $e) {
	check("exception: " . $e->getMessage() . " line " . $e->getLine(), false);
}

if (getenv('KEEP') === '1') { echo "KEPT project $projId\n"; exit(1); }
viCleanupProject($projId);
shell_exec("rm -rf " . escapeshellarg($workDir));
viCleanup();

echo "\n=================================================\n";
if ($failures) {
	echo "RESULT: " . count($failures) . " FAILED\n";
	foreach ($failures as $f) echo "  - $f\n";
	exit(1);
}
echo "RESULT: all checks PASS\n";
