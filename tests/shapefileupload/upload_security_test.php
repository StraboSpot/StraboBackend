<?php
/**
 * File: upload_security_test.php
 * Description: Attack tests for the website zip uploaders (load_shapefile.php and
 *              geotiff/upload/index.php). Both unzip into the served ziptemp/ folder,
 *              so a zip must never put a script or .htaccess there, and the values the
 *              shapefile form posts back (work folder, .shp path, project) must never
 *              reach a shell, another user's upload or another user's project.
 *
 *              Checks:
 *                A. a zip carrying shell.php, .htaccess, a nested .php and a ../ entry
 *                   next to a real shapefile: only the shapefile parts are extracted,
 *                   nothing escapes the work folder, the scripts are not reachable
 *                B. shell metacharacters in a posted shapefilename: refused, no command runs
 *                C. a work folder number this session did not create: refused
 *                D. the same folder from a second session of the same user: refused
 *                E. a .shp path outside the posted work folder: refused
 *                F. a non-numeric project id / a project owned by someone else: refused
 *                G. the normal two-step import still completes
 *                H. GeoTIFF zip with a shell-metacharacter .tif name + shell.php:
 *                   no command runs, no script extracted
 *
 *              Uses owner@test.strabospot.org (setup_test_data.php) and a forged session.
 *              Hermetic: a unique '96669'-prefixed project id, cleaned up at the end.
 *
 *              Run inside the container (7.4 and 8.4):
 *                docker exec strabo-php php /srv/app/www/tests/shapefileupload/upload_security_test.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$failures = array();
function check($label, $cond) {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
	if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

$docRoot = '/srv/app/www';

$ownerPkey = (int)$db->get_var_prepared(
	"SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE",
	array('owner@test.strabospot.org'));
if (!$ownerPkey) { echo "Test user owner@test.strabospot.org not found. Run setup_test_data.php first.\n"; exit(1); }

$sessionDir = '/var/lib/php/sessions';
$sessionFiles = array();
function forgeSession($pkey) {
	global $sessionDir, $sessionFiles;
	$sid  = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = $sessionDir . '/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600);
	@chown($path, 'www-data');
	@chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}

function post($sid, $fields, $url = "http://localhost/load_shapefile.php") {
	$cmd = "curl -s -b " . escapeshellarg("PHPSESSID=$sid");
	foreach ($fields as $k => $v) {
		$cmd .= " --data-urlencode " . escapeshellarg("$k=$v");
	}
	return (string)shell_exec($cmd . " " . escapeshellarg($url));
}

function upload($sid, $projId, $zipPath) {
	$cmd = "curl -s -b " . escapeshellarg("PHPSESSID=$sid")
		. " -F " . escapeshellarg("submitfile=Submit")
		. " -F " . escapeshellarg("projectid=$projId")
		. " -F " . escapeshellarg("zipfile=@$zipPath;filename=sec_test.zip;type=application/zip")
		. " " . escapeshellarg("http://localhost/load_shapefile.php");
	return (string)shell_exec($cmd);
}

function grab($html, $name) {
	if (preg_match('/name="' . preg_quote($name, '/') . '"\s+value\s*="([^"]*)"/', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES);
	return null;
}

function httpCode($url) {
	return trim((string)shell_exec("curl -s -o /dev/null -w '%{http_code}' " . escapeshellarg($url)));
}

$stamp   = time();
$projId  = 96669 * 10000000 + ($stamp % 10000000);
$otherProj = $projId + 1;
$workDir = "/tmp/shpsec_$stamp";
$marker  = "/tmp/shpsec_pwned_$stamp";
$randnums = array();

function cleanup() {
	global $db, $neodb, $projId, $otherProj, $ownerPkey, $docRoot, $randnums, $workDir, $sessionFiles, $marker;
	$neodb->query("MATCH (p:Project) WHERE p.id IN [$projId, $otherProj]
	               OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset)
	               OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot)
	               OPTIONAL MATCH (s)-[*1..3]->(c)
	               DETACH DELETE c, s, d, p");
	foreach ($randnums as $r) {
		shell_exec("rm -rf " . escapeshellarg("$docRoot/ziptemp/" . (int)$r) . " " . escapeshellarg("$docRoot/ogrtemp/" . (int)$r));
	}
	shell_exec("rm -rf " . escapeshellarg($workDir) . " " . escapeshellarg($marker) . " " . escapeshellarg("$docRoot/ziptemp/shpsec_escape_$GLOBALS[stamp].shp"));
	foreach ($sessionFiles as $f) @unlink($f);
}

try {
	// ---------------------------------------------------------------------
	// Fixtures: a real one-point shapefile, then a zip that also carries scripts
	// ---------------------------------------------------------------------
	@mkdir("$workDir/shp", 0777, true);
	file_put_contents("$workDir/in.geojson", json_encode(array('type' => 'FeatureCollection', 'features' => array(
		array('type' => 'Feature', 'properties' => array('notes' => 'sec test'),
		      'geometry' => array('type' => 'Point', 'coordinates' => array(-100.5, 38.5)))))));
	shell_exec("ogr2ogr -f 'ESRI Shapefile' -a_srs EPSG:4326 " . escapeshellarg("$workDir/shp/sec.shp") . " " . escapeshellarg("$workDir/in.geojson") . " 2>&1");
	check("fixture: shapefile built", file_exists("$workDir/shp/sec.shp"));

	$zipPath = "$workDir/evil.zip";
	$za = new ZipArchive();
	$za->open($zipPath, ZipArchive::CREATE);
	foreach (array("shp", "shx", "dbf", "prj") as $ext) $za->addFile("$workDir/shp/sec.$ext", "sec.$ext");
	$za->addFromString("shell.php", "<?php touch('$marker'); echo 'SHELL-RAN';");
	$za->addFromString(".htaccess", "SetHandler application/x-httpd-php\n");
	$za->addFromString("nested/inner.php", "<?php echo 'INNER-RAN';");
	$za->addFromString("evil.phtml", "<?php echo 'PHTML-RAN';");
	$za->addFromString("../shpsec_escape_$stamp.shp", "escape");
	$za->close();

	$neodb->query("CREATE (p:Project {id:$projId, userpkey:$ownerPkey, desc_project_name:'Shapefile security test'})");
	$neodb->query("CREATE (p:Project {id:$otherProj, userpkey:0, desc_project_name:'Not the test user'})");

	$sid  = forgeSession($ownerPkey);
	$sid2 = forgeSession($ownerPkey);

	// ---------------------------------------------------------------------
	echo "\n=== A. zip with scripts next to a shapefile ===\n";
	$html = upload($sid, $projId, $zipPath);
	$randnum = grab($html, 'randnum');
	$shp = grab($html, 'shapefilename');
	if ($randnum) $randnums[] = $randnum;
	note("randnum=$randnum shapefilename=$shp");
	check("A: column form returned for the real shapefile", $randnum && $shp === "ziptemp/$randnum/sec.shp");
	$extracted = array();
	if ($randnum && is_dir("$docRoot/ziptemp/$randnum")) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$docRoot/ziptemp/$randnum", FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) $extracted[] = substr($f->getPathname(), strlen("$docRoot/ziptemp/$randnum/"));
		sort($extracted);
	}
	note("extracted: " . implode(", ", $extracted));
	check("A: only the shapefile parts were extracted", $extracted === array("sec.dbf", "sec.prj", "sec.shp", "sec.shx"));
	check("A: ../ entry did not escape the work folder", !file_exists("$docRoot/ziptemp/shpsec_escape_$stamp.shp"));
	check("A: shell.php not reachable", httpCode("http://localhost/ziptemp/$randnum/shell.php") !== "200");
	check("A: no command ran", !file_exists($marker));

	// ---------------------------------------------------------------------
	echo "\n=== B. shell metacharacters in the posted shapefilename ===\n";
	foreach (array("ziptemp/$randnum/sec.shp\" ; touch $marker ; \"x.shp",
	               "ziptemp/$randnum/\$(touch $marker).shp",
	               "ziptemp/$randnum/`touch $marker`.shp") as $bad) {
		$out = post($sid, array('columnsubmit' => 'Submit', 'shapefilename' => $bad, 'randnum' => $randnum,
		                        'projectid' => $projId, 'epsg' => '4326', 'fixedcolumns' => 'notes:notes'));
		check("B: refused: " . substr($bad, strlen("ziptemp/$randnum/")), strpos($out, "Shapefile not found in this upload.") !== false);
	}
	check("B: no command ran", !file_exists($marker));

	// ---------------------------------------------------------------------
	echo "\n=== C/D/E. work folder and path checks ===\n";
	$out = post($sid, array('indsubmit' => 'Submit', 'randnum' => (string)($randnum - 1), 'projectid' => $projId));
	check("C: a folder this session did not create is refused", strpos($out, "This upload was not found.") !== false);
	$out = post($sid2, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'shapefilename0' => $shp));
	check("D: the same folder from another session is refused", strpos($out, "This upload was not found.") !== false);
	$out = post($sid, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => $projId, 'shapefilename0' => "ziptemp/$randnum/../../includes/x.shp"));
	check("E: a .shp path outside the work folder is refused", strpos($out, "Shapefile not found in this upload.") !== false);
	$out = post($sid, array('indsubmit' => 'Submit', 'projectid' => $projId, 'shapefilename' => $shp));
	check("E: a .shp path without a work folder is refused", strpos($out, "This upload was not found.") !== false);

	// ---------------------------------------------------------------------
	echo "\n=== F. project checks ===\n";
	$out = post($sid, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => "$projId or 1=1", 'shapefilename0' => $shp));
	check("F: non-numeric project id refused", strpos($out, "Project not found.") !== false);
	$out = post($sid, array('indsubmit' => 'Submit', 'randnum' => $randnum, 'projectid' => (string)$otherProj, 'shapefilename0' => $shp));
	check("F: another user's project refused", strpos($out, "Project not found.") !== false);
	$out = upload($sid, $otherProj, $zipPath);
	check("F: upload into another user's project refused", strpos($out, "Project not found.") !== false);

	// ---------------------------------------------------------------------
	echo "\n=== G. normal import still works ===\n";
	$out = post($sid, array('columnsubmit' => 'Submit', 'shapefilename' => $shp, 'randnum' => $randnum,
	                        'projectid' => $projId, 'epsg' => grab($html, 'epsg'), 'fixedcolumns' => 'notes:notes',
	                        'datasetname' => 'Sec <b>DS</b>', 'spotnameprefix' => 'sec'));
	check("G: import completes", strpos($out, "Shapefile Load Complete") !== false);
	$spots = (int)$neodb->get_var("MATCH (p:Project {id:$projId})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) RETURN count(s)");
	check("G: one spot imported", $spots === 1);

	// ---------------------------------------------------------------------
	echo "\n=== H. GeoTIFF zip upload ===\n";
	shell_exec("gdal_create -of GTiff -outsize 4 4 -bands 1 " . escapeshellarg("$workDir/plain.tif") . " 2>&1");
	$gz = "$workDir/geo.zip";
	$za = new ZipArchive();
	$za->open($gz, ZipArchive::CREATE);
	$za->addFile("$workDir/plain.tif", "a\$(touch $marker).tif");
	$za->addFromString("shell.php", "<?php touch('$marker');");
	$za->close();
	$before = (int)$db->get_var("SELECT last_value FROM file_seq");
	$cmd = "curl -s -b " . escapeshellarg("PHPSESSID=$sid")
		. " -F " . escapeshellarg("geotifffile=@$gz;filename=geo.zip;type=application/zip")
		. " " . escapeshellarg("http://localhost/geotiff/upload/index.php");
	$out = (string)shell_exec($cmd);
	$after = (int)$db->get_var("SELECT last_value FROM file_seq");
	for ($r = $before + 1; $r <= $after; $r++) $randnums[] = $r;
	note("response: " . substr(preg_replace('/\s+/', ' ', $out), 0, 120));
	check("H: upload answered (not referenced = expected)", strpos($out, "not referenced") !== false);
	check("H: no command ran", !file_exists($marker));
	$phpLeft = false;
	for ($r = $before + 1; $r <= $after; $r++) {
		if (glob("$docRoot/ziptemp/$r/*.php")) $phpLeft = true;
	}
	check("H: no script extracted", !$phpLeft && $after > $before);

} catch (Exception $e) {
	check("exception: " . $e->getMessage(), false);
}

cleanup();

echo "\n=================================================\n";
if ($failures) {
	echo "RESULT: " . count($failures) . " FAILED\n";
	foreach ($failures as $f) echo "  - $f\n";
	exit(1);
}
echo "RESULT: all checks PASS\n";
