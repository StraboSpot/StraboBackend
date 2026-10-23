<?php
/**
 * File: shapefile_import_dump.php
 * Description: Imports point, line and polygon shapefiles that map to most Strabo sections
 *              (spot, planar + linear orientation, rock unit, trace, sample, tag, custom column)
 *              through load_shapefile.php, then prints the stored result as normalized JSON
 *              (generated ids and timestamps removed). Used to show that a change to the
 *              loader leaves imports unchanged: run it on the old and the new code and diff.
 *
 *              docker exec strabo-php php /srv/app/www/tests/vectorimport/shapefile_import_dump.php > /tmp/new.json
 *
 *              Uses owner@test.strabospot.org (setup_test_data.php) and a forged session.
 *              Hermetic: a '96670'-prefixed project, removed at the end.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/tests/vectorimport/vector_import_lib.php';

$stamp  = time();
$projId = 96670 * 10000000 + ($stamp % 10000000);
$workDir = "/tmp/shpdump_$stamp";
@mkdir($workDir, 0777, true);

$layers = array(
	'pts' => array('type' => 'Point', 'features' => array(
		array(array('name' => 'P1', 'notes' => 'first point', 'alt' => 1500, 'strike' => 120, 'dip' => 45, 'ptype' => 'bedding',
		            'trend' => 200, 'plunge' => 10, 'unit' => 'Dakota Fm', 'rtype' => 'sedimentary', 'sample' => 'S-1',
		            'tagname' => 'Field day', 'cust' => 'custom value'), array(-105.1, 39.1)),
		array(array('name' => 'P2', 'notes' => 'second', 'alt' => 1510, 'strike' => 200, 'dip' => 30, 'ptype' => 'not a type',
		            'trend' => 90, 'plunge' => 5, 'unit' => 'Morrison Fm', 'rtype' => 'sedimentary', 'sample' => '',
		            'tagname' => 'Field day', 'cust' => ''), array(-105.2, 39.2)),
	), 'map' => 'name:spot_name;notes:spot_notes;alt:spot_altitude;strike:planar_orientation_strike;dip:planar_orientation_dip;ptype:planar_orientation_feature_type;trend:linear_orientation_trend;plunge:linear_orientation_plunge;unit:rock_unit_name;rtype:rock_unit_rock_type;sample:sample_sample_id_name;tagname:tag_name;cust:sfcustom_cust'),
	'lines' => array('type' => 'LineString', 'features' => array(
		array(array('lname' => 'L1', 'ctype' => 'depositional', 'tnotes' => 'a contact'), array(array(-105.1, 39.1), array(-105.2, 39.3))),
		array(array('lname' => 'L2', 'ctype' => '', 'tnotes' => 'no type'), array(array(-105.3, 39.1), array(-105.4, 39.3))),
	), 'map' => 'lname:spot_name;ctype:trace_contact_type;tnotes:trace_tace_notes'),
	'polys' => array('type' => 'Polygon', 'features' => array(
		array(array('pname' => 'A1', 'unit' => 'Pierre Sh'), array(array(array(-105, 39), array(-105.1, 39), array(-105.1, 39.1), array(-105, 39)))),
	), 'map' => 'pname:spot_name;unit:rock_unit_name'),
);

$sid = viForgeSession($ownerPkey);
$neodb->query("CREATE (p:Project {id:$projId, userpkey:$ownerPkey, desc_project_name:'Shapefile import dump'})");

$result = array();
try {
	foreach ($layers as $lname => $layer) {
		$features = array();
		foreach ($layer['features'] as $f) {
			$features[] = array('type' => 'Feature', 'properties' => $f[0], 'geometry' => array('type' => $layer['type'], 'coordinates' => $f[1]));
		}
		file_put_contents("$workDir/$lname.geojson", json_encode(array('type' => 'FeatureCollection', 'features' => $features)));
		shell_exec("ogr2ogr -f 'ESRI Shapefile' -a_srs EPSG:4326 " . escapeshellarg("$workDir/$lname.shp") . " " . escapeshellarg("$workDir/$lname.geojson") . " 2>&1");
		$zip = "$workDir/$lname.zip";
		$za = new ZipArchive();
		$za->open($zip, ZipArchive::CREATE);
		foreach (array("shp", "shx", "dbf", "prj") as $ext) $za->addFile("$workDir/$lname.$ext", "$lname.$ext");
		$za->close();

		$html = viPostFile($sid, "http://localhost/load_shapefile.php", array('submitfile' => 'Submit', 'projectid' => $projId), 'zipfile', $zip, "$lname.zip", 'application/zip');
		$randnum = viGrab($html, 'randnum');
		$GLOBALS['viRandnums'][] = $randnum;
		$out = viPost($sid, "http://localhost/load_shapefile.php", array(
			'columnsubmit' => 'Submit', 'shapefilename' => viGrab($html, 'shapefilename'), 'randnum' => $randnum,
			'projectid' => $projId, 'epsg' => viGrab($html, 'epsg'), 'arcid' => '', 'featuretype' => '', 'filecolumns' => '',
			'shapefilearray' => '', 'datasetname' => "Dump $lname", 'spotnameprefix' => "$lname-", 'fixedcolumns' => $layer['map']));
		$result['responses'][$lname] = array(
			'column_form_columns' => preg_match('/var columns = \[(.*?)\];/s', $html, $m) ? $m[1] : null,
			'complete' => strpos($out, "Shapefile Load Complete") !== false,
			'vocab_error' => strpos($out, "Error!") !== false,
		);
	}
	$result['graph'] = viDumpProject($projId, $ownerPkey);
} catch (Throwable $e) {
	$result['exception'] = $e->getMessage();
}

viCleanupProject($projId);
shell_exec("rm -rf " . escapeshellarg($workDir));
viCleanup();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
