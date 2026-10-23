<?php
/**
 * File: e2e_geopackage_upload.php
 * Description: End-to-end test of load_geopackage.php over HTTP.
 *                A. a GeoPackage with a UTM point layer, a mixed-geometry layer, an empty layer
 *                   and an attribute-only table: the layer list shows the three feature layers
 *                B. two layers chosen: column page, import, "continue" to the second layer,
 *                   import; two datasets, points reprojected to WGS84, mixed geometries kept,
 *                   mapped strike/dip stored, unsafe column names renamed
 *                C. refused: unknown / shell-character layer names, a work folder from another
 *                   session, another user's project, a zip with scripts (only the .gpkg is
 *                   extracted), a text file named .gpkg, a zip with two GeoPackages
 *                D. round trip: Strabo's own GeoPackage export of the imported points, loaded
 *                   back with the form's own column guesses, gives the same names, locations,
 *                   strikes, dips and custom field
 *
 *              Uses owner@test.strabospot.org (setup_test_data.php) and a forged session.
 *              Hermetic: '96671'-prefixed projects, removed at the end.
 *
 *              docker exec strabo-php php /srv/app/www/tests/vectorimport/e2e_geopackage_upload.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/tests/vectorimport/vector_import_lib.php';

$failures = array();
function check($label, $cond) {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
	if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

$URL = "http://localhost/load_geopackage.php";
$stamp = time();
$projId = 96671 * 10000000 + ($stamp % 10000000);
$rtProj = $projId + 1;
$otherProj = $projId + 2;
$workDir = "/tmp/gpkge2e_$stamp";
$marker = "/tmp/gpkge2e_pwned_$stamp";
@mkdir($workDir, 0777, true);

function ogr($args) { return shell_exec("ogr2ogr $args 2>&1"); }

// Default choice of each column's select on a column page: "col:strabocol;..."
function defaultMapping($html) {
	$pairs = array();
	if (!preg_match('/var columns = \[(.*?)\];/s', $html, $m)) return "";
	foreach (json_decode("[" . $m[1] . "]", true) ?: array() as $col) {
		if (preg_match('/<select name="' . preg_quote($col, '/') . '_select">(.*?)<\/select>/s', $html, $sm)
		    && preg_match('/<option value="([^"]+)" selected>/', $sm[1], $om)) {
			$pairs[] = "$col:" . $om[1];
		}
	}
	return implode(";", $pairs);
}

function datasetsOf($projId) {
	global $neodb;
	$out = array();
	foreach ($neodb->get_results("MATCH (p:Project {id:$projId})-[:HAS_DATASET]->(d:Dataset) RETURN d.id AS id, d.name AS name") ?: array() as $r) {
		$out[$r->get('name')] = $r->get('id');
	}
	return $out;
}

try {
	// ---------------------------------------------------------------------
	// Fixture GeoPackage
	// ---------------------------------------------------------------------
	file_put_contents("$workDir/pts.geojson", json_encode(array('type' => 'FeatureCollection', 'features' => array(
		array('type' => 'Feature', 'properties' => array('name' => 'Station One', 'strike' => 120, 'dip' => 45, 'a:b' => 'colon', 'Strike (deg)' => 7, 'notes' => 'ünïcödé'),
		      'geometry' => array('type' => 'Point', 'coordinates' => array(500000, 4300000, 1500))),
		array('type' => 'Feature', 'properties' => array('name' => 'Station Two', 'strike' => 200, 'dip' => 30, 'a:b' => 'x', 'Strike (deg)' => 8, 'notes' => null),
		      'geometry' => array('type' => 'Point', 'coordinates' => array(501000, 4301000, 1510)))))));
	file_put_contents("$workDir/mixed.geojson", json_encode(array('type' => 'FeatureCollection', 'features' => array(
		array('type' => 'Feature', 'properties' => array('label' => 'a point'), 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.1, 39.1))),
		array('type' => 'Feature', 'properties' => array('label' => 'a line'), 'geometry' => array('type' => 'LineString', 'coordinates' => array(array(-105.1, 39.1), array(-105.2, 39.2)))),
		array('type' => 'Feature', 'properties' => array('label' => 'a polygon'), 'geometry' => array('type' => 'Polygon', 'coordinates' => array(array(array(-105, 39), array(-105.1, 39), array(-105.1, 39.1), array(-105, 39)))))))));
	file_put_contents("$workDir/empty.geojson", json_encode(array('type' => 'FeatureCollection', 'features' => array())));
	$gpkg = "$workDir/test.gpkg";
	ogr("-f GPKG -a_srs EPSG:32613 -nln utm_points " . escapeshellarg($gpkg) . " " . escapeshellarg("$workDir/pts.geojson"));
	ogr("-f GPKG -update -nlt GEOMETRY -nln mixed_features " . escapeshellarg($gpkg) . " " . escapeshellarg("$workDir/mixed.geojson"));
	ogr("-f GPKG -update -nlt POINT -a_srs EPSG:4326 -nln empty_layer " . escapeshellarg($gpkg) . " " . escapeshellarg("$workDir/empty.geojson"));
	ogr("-f GPKG -update -nlt NONE -nln notes_table " . escapeshellarg($gpkg) . " " . escapeshellarg("$workDir/pts.geojson"));
	check("fixture: GeoPackage built", file_exists($gpkg));

	$neodb->query("CREATE (p:Project {id:$projId, userpkey:$ownerPkey, desc_project_name:'GeoPackage e2e'})");
	$neodb->query("CREATE (p:Project {id:$rtProj, userpkey:$ownerPkey, desc_project_name:'GeoPackage round trip'})");
	$neodb->query("CREATE (p:Project {id:$otherProj, userpkey:0, desc_project_name:'Not the test user'})");
	$sid = viForgeSession($ownerPkey);
	$sid2 = viForgeSession($ownerPkey);

	// ---------------------------------------------------------------------
	echo "\n=== A. upload: layer list ===\n";
	$html = viPostFile($sid, $URL, array('submitfile' => 'Submit', 'projectid' => $projId), 'gpkgfile', $gpkg, 'test.gpkg', 'application/geopackage+sqlite3');
	$randnum = viGrab($html, 'randnum');
	$viRandnums[] = $randnum;
	check("A: layer list page", strpos($html, "Select Layer(s)") !== false && $randnum);
	check("A: three feature layers listed", substr_count($html, 'type="checkbox"') === 3);
	check("A: attribute table not listed", strpos($html, "notes_table") === false);
	check("A: UTM coordinate system shown", strpos($html, "EPSG:32613") !== false);
	check("A: empty layer unchecked", (bool)preg_match('/value="empty_layer"\s*>/', $html));

	// ---------------------------------------------------------------------
	echo "\n=== B. two layers ===\n";
	$page = viPost($sid, $URL, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'gpkgname' => 'test.gpkg',
	                                 'layer0' => 'mixed_features', 'layer1' => 'utm_points'));
	check("B: column page for the first layer", strpos($page, "Define Columns for GeoPackage") !== false && viGrab($page, 'layer') === 'mixed_features');
	check("B: second layer queued", viGrab($page, 'layerqueue') === '["utm_points"]');
	$out = viPost($sid, $URL, array('columnsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'gpkgname' => 'test.gpkg',
	                                'layer' => 'mixed_features', 'layerqueue' => '["utm_points"]', 'datasetname' => 'Mixed', 'spotnameprefix' => 'm',
	                                'fixedcolumns' => 'label:spot_name'));
	check("B: first layer imported", strpos($out, "GeoPackage Load Complete") !== false);
	check("B: continue button for the next layer", strpos($out, "Continue to load utm_points") !== false);

	$page = viPost($sid, $URL, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'gpkgname' => 'test.gpkg',
	                                 'layerqueue' => '["utm_points"]', 'spotnameprefix' => 'm'));
	check("B: column page for the second layer", viGrab($page, 'layer') === 'utm_points' && viGrab($page, 'layerqueue') === '[]');
	preg_match('/var columns = \[(.*?)\];/s', $page, $m);
	$cols = json_decode("[" . ($m[1] ?? "") . "]", true) ?: array();
	note("columns: " . implode(", ", $cols));
	check("B: unsafe column names renamed (a:b -> a_b, kept Strike (deg))", in_array("a_b", $cols, true) && in_array("Strike (deg)", $cols, true) && !in_array("a:b", $cols, true));
	$out = viPost($sid, $URL, array('columnsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'gpkgname' => 'test.gpkg',
	                                'layer' => 'utm_points', 'layerqueue' => '[]', 'datasetname' => 'UTM points', 'spotnameprefix' => '',
	                                'fixedcolumns' => 'name:spot_name;strike:planar_orientation_strike;dip:planar_orientation_dip;a_b:sfcustom_a_b;notes:spot_notes'));
	check("B: second layer imported, link back to My Field Data", strpos($out, "GeoPackage Load Complete") !== false && strpos($out, "my_field_data") !== false);

	$ds = datasetsOf($projId);
	check("B: two datasets", isset($ds['Mixed']) && isset($ds['UTM points']));
	$strabo = new StraboSpot($neodb, $ownerPkey, $db);
	$mixed = json_decode(json_encode($strabo->getDatasetSpots($ds['Mixed'])), true)['features'] ?? array();
	$types = array_map(function ($f) { return $f['geometry']['type'] ?? ''; }, $mixed);
	sort($types);
	check("B: mixed layer keeps point, line and polygon", $types === array('LineString', 'Point', 'Polygon'));
	$utm = json_decode(json_encode($strabo->getDatasetSpots($ds['UTM points'])), true)['features'] ?? array();
	$one = null;
	foreach ($utm as $f) if (($f['properties']['name'] ?? '') === 'Station One') $one = $f;
	note("Station One: " . json_encode($one['geometry'] ?? null));
	check("B: UTM point reprojected to WGS84", $one && abs($one['geometry']['coordinates'][0] + 105.0) < 1e-6 && abs($one['geometry']['coordinates'][1] - 38.8488182) < 1e-6);
	check("B: strike/dip stored", $one && ($one['properties']['orientation_data'][0]['strike'] ?? null) == 120 && ($one['properties']['orientation_data'][0]['dip'] ?? null) == 45);
	check("B: custom + unicode notes stored", $one && ($one['properties']['custom_fields']['a_b'] ?? null) === 'colon' && ($one['properties']['notes'] ?? null) === 'ünïcödé');
	$dsnode = $neodb->getNode("MATCH (d:Dataset {id:" . (int)$ds['UTM points'] . "}) RETURN d");
	check("B: dataset records GeoPackage + layer", ($dsnode['datasettype'] ?? '') === 'geopackage' && ($dsnode['layer'] ?? '') === 'utm_points' && ($dsnode['geopackage'] ?? '') === 'test.gpkg');

	// ---------------------------------------------------------------------
	echo "\n=== C. refused ===\n";
	foreach (array("not_a_layer", "utm_points\" ; touch $marker ; \"", "\$(touch $marker)") as $bad) {
		$out = viPost($sid, $URL, array('columnsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'layer' => $bad, 'fixedcolumns' => ''));
		check("C: layer refused: " . substr($bad, 0, 24), strpos($out, "Layer not found in this GeoPackage.") !== false);
	}
	$out = viPost($sid, $URL, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'layerqueue' => json_encode(array("x\$(touch $marker)"))));
	check("C: queued layer refused", strpos($out, "Layer not found in this GeoPackage.") !== false);
	check("C: no command ran", !file_exists($marker));
	$out = viPost($sid2, $URL, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'layer' => 'utm_points'));
	check("C: work folder from another session refused", strpos($out, "This upload was not found.") !== false);
	$out = viPost($sid, $URL, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $otherProj, 'layer' => 'utm_points'));
	check("C: another user's project refused", strpos($out, "Project not found.") !== false);

	$evil = "$workDir/evil.zip";
	$za = new ZipArchive();
	$za->open($evil, ZipArchive::CREATE);
	$za->addFile($gpkg, "data/test.gpkg");
	$za->addFromString("shell.php", "<?php touch('$marker');");
	$za->addFromString(".htaccess", "SetHandler application/x-httpd-php\n");
	$za->close();
	$html = viPostFile($sid, $URL, array('submitfile' => 'Submit', 'projectid' => $projId), 'gpkgfile', $evil, 'evil.zip', 'application/zip');
	$r2 = viGrab($html, 'randnum');
	$viRandnums[] = $r2;
	$files = $r2 ? array_values(array_diff(scandir("/srv/app/www/ziptemp/$r2"), array('.', '..'))) : array();
	note("extracted: " . implode(", ", $files));
	check("C: zip: layer list shown, only upload.gpkg extracted", strpos($html, "Select Layer(s)") !== false && $files === array('upload.gpkg'));

	file_put_contents("$workDir/fake.gpkg", "<?php touch('$marker'); ?> not a geopackage");
	$before = (int)$db->get_var("SELECT last_value FROM file_seq");
	$html = viPostFile($sid, $URL, array('submitfile' => 'Submit', 'projectid' => $projId), 'gpkgfile', "$workDir/fake.gpkg", 'fake.gpkg', 'application/octet-stream');
	$after = (int)$db->get_var("SELECT last_value FROM file_seq");
	for ($r = $before + 1; $r <= $after; $r++) $viRandnums[] = $r;
	check("C: text file named .gpkg refused", strpos($html, "could not be read as a GeoPackage") !== false);

	$two = "$workDir/two.zip";
	$za = new ZipArchive();
	$za->open($two, ZipArchive::CREATE);
	$za->addFile($gpkg, "a.gpkg");
	$za->addFile($gpkg, "b.gpkg");
	$za->close();
	$before = (int)$db->get_var("SELECT last_value FROM file_seq");
	$html = viPostFile($sid, $URL, array('submitfile' => 'Submit', 'projectid' => $projId), 'gpkgfile', $two, 'two.zip', 'application/zip');
	$after = (int)$db->get_var("SELECT last_value FROM file_seq");
	for ($r = $before + 1; $r <= $after; $r++) $viRandnums[] = $r;
	check("C: zip with two GeoPackages refused", strpos($html, "more than one GeoPackage") !== false);
	check("C: still no command ran", !file_exists($marker));

	// ---------------------------------------------------------------------
	echo "\n=== D. round trip through Strabo's own GeoPackage export ===\n";
	$export = "$workDir/export.gpkg";
	shell_exec("curl -s -o " . escapeshellarg($export) . " -b " . escapeshellarg("PHPSESSID=$sid") . " " . escapeshellarg("http://localhost/searchdownload.php?type=gpkg&dsids=" . $ds['UTM points']));
	check("D: export downloaded", geopackageIsSqliteFile($export));
	$html = viPostFile($sid, $URL, array('submitfile' => 'Submit', 'projectid' => $rtProj), 'gpkgfile', $export, 'export.gpkg', 'application/geopackage+sqlite3');
	$r3 = viGrab($html, 'randnum');
	$viRandnums[] = $r3;
	check("D: export has one feature layer (column page shown directly)", viGrab($html, 'layer') === 'points');
	// The form recognizes the export's prefixed columns (spot_name, po_strike, cust_...):
	// accept its guesses as they are.
	$mapping = defaultMapping($html);
	note("default mapping: $mapping");
	foreach (array("spot_name:spot_name", "po_strike:planar_orientation_strike", "po_dip:planar_orientation_dip", "cust_a_b:sfcustom_a_b", "notes:spot_notes") as $want) {
		check("D: export column guessed: $want", strpos(";$mapping;", ";$want;") !== false);
	}
	$out = viPost($sid, $URL, array('columnsubmit' => 'Submit', 'randnum' => $r3, 'projectid' => $rtProj, 'gpkgname' => 'export.gpkg',
	                                'layer' => 'points', 'layerqueue' => '[]', 'datasetname' => 'Round trip', 'spotnameprefix' => '', 'fixedcolumns' => $mapping));
	check("D: re-import completes", strpos($out, "GeoPackage Load Complete") !== false);
	$rt = datasetsOf($rtProj);
	$back = isset($rt['Round trip']) ? (json_decode(json_encode($strabo->getDatasetSpots($rt['Round trip'])), true)['features'] ?? array()) : array();
	$summary = function ($features) {
		$s = array();
		foreach ($features as $f) {
			$f = json_decode(json_encode($f), true);
			$o = $f['properties']['orientation_data'][0] ?? array();
			$s[] = sprintf("%s %.6f %.6f %s %s", $f['properties']['name'] ?? '', $f['geometry']['coordinates'][0], $f['geometry']['coordinates'][1], $o['strike'] ?? '-', $o['dip'] ?? '-');
		}
		sort($s);
		return $s;
	};
	note("original:  " . implode(" | ", $summary($utm)));
	note("roundtrip: " . implode(" | ", $summary($back)));
	check("D: names, locations, strikes and dips survive the round trip", count($back) == 2 && $summary($back) === $summary($utm));
	$customs = array();
	foreach ($back as $f) $customs[$f['properties']['name'] ?? ''] = $f['properties']['custom_fields']['a_b'] ?? null;
	check("D: custom field keeps its name (cust_a_b -> a_b)", ($customs['Station One'] ?? null) === 'colon');

} catch (Throwable $e) {
	check("exception: " . $e->getMessage() . " line " . $e->getLine(), false);
}

function geopackageIsSqliteFile($path) {
	return file_exists($path) && file_get_contents($path, false, null, 0, 16) === "SQLite format 3\0";
}

if (getenv('KEEP') === '1') { echo "KEPT projects $projId $rtProj\n"; exit(1); }
foreach (array($projId, $rtProj, $otherProj) as $p) viCleanupProject($p);
shell_exec("rm -rf " . escapeshellarg($workDir) . " " . escapeshellarg($marker));
viCleanup();

echo "\n=================================================\n";
if ($failures) {
	echo "RESULT: " . count($failures) . " FAILED\n";
	foreach ($failures as $f) echo "  - $f\n";
	exit(1);
}
echo "RESULT: all checks PASS\n";
