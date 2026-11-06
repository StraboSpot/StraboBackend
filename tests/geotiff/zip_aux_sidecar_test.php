<?php
/**
 * File: zip_aux_sidecar_test.php
 * Description: My Maps (geotiff/upload/index.php) zip uploads whose georeferencing lives
 *              only in an ArcGIS ".tif.aux.xml" sidecar (GeodataXform GCPs), as in the
 *              USGS NGMDB map downloads (Jonathan Caine, USGS_B-1221-D, 2026-11-06).
 *              The 10-23 upload lockdown extracted only .tif/.tfw/.ovr parts, so the
 *              sidecar was dropped and the map was refused as "not referenced".
 *
 *              Checks:
 *                A. zip with .tif + .tif.aux.xml + .tif.txt (NGMDB layout): map saved,
 *                   reprojected bounds where the GCPs put it
 *                B. the same zip without the sidecar: still refused (not referenced)
 *                C. extracted sidecar is not downloadable from ziptemp (403)
 *
 *              Uses owner@test.strabospot.org (setup_test_data.php) and a forged session.
 *              Cleans up its geotiffs rows, map files and ziptemp folders.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/geotiff/zip_aux_sidecar_test.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

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
$sid  = substr(bin2hex(random_bytes(16)), 0, 26);
$sessPath = "$sessionDir/sess_$sid";
file_put_contents($sessPath, 'loggedin|s:3:"yes";userpkey|i:' . $ownerPkey . ';LAST_ACTIVITY|i:' . time() . ';');
chmod($sessPath, 0600);
@chown($sessPath, 'www-data');
@chgrp($sessPath, 'www-data');

$workDir = sys_get_temp_dir() . '/zipaux_' . getmypid();
@mkdir($workDir);
$randnums = array();
$hashes = array();

function cleanup() {
	global $db, $docRoot, $workDir, $randnums, $hashes, $sessPath;
	foreach ($hashes as $h) {
		if (!preg_match('/^[A-Za-z0-9]+$/', $h)) continue;
		$db->prepare_query("DELETE FROM geotiffs WHERE hash=$1", array($h));
		@unlink("$docRoot/geotiff/upload/files/$h.tif");
		@unlink("/var/www/geotiff/upload/maps/$h.map");
	}
	foreach ($randnums as $r) {
		$r = (int)$r;
		if ($r > 0 && is_dir("$docRoot/ziptemp/$r")) shell_exec("rm -rf " . escapeshellarg("$docRoot/ziptemp/$r"));
	}
	shell_exec("rm -rf " . escapeshellarg($workDir));
	@unlink($sessPath);
}

function uploadZip($zipPath) {
	global $db, $sid, $randnums;
	$before = (int)$db->get_var("SELECT last_value FROM file_seq");
	$cmd = "curl -s -b " . escapeshellarg("PHPSESSID=$sid")
		. " -F " . escapeshellarg("geotifffile=@$zipPath;filename=" . basename($zipPath) . ";type=application/zip")
		. " " . escapeshellarg("http://localhost/geotiff/upload/index.php");
	$out = (string)shell_exec($cmd);
	$after = (int)$db->get_var("SELECT last_value FROM file_seq");
	$mine = array();
	for ($r = $before + 1; $r <= $after; $r++) { $randnums[] = $r; $mine[] = $r; }
	return array($out, $mine);
}

// 300 x 300 px at 100 dpi = 3 x 3 inches; ArcGIS GCP sources are in those inches
// (y up from the bottom), targets in NAD27 / UTM 13N, as in the NGMDB sidecar.
$wkt = 'PROJCS[&quot;NAD_1927_UTM_Zone_13N&quot;,GEOGCS[&quot;GCS_North_American_1927&quot;,DATUM[&quot;D_North_American_1927&quot;,SPHEROID[&quot;Clarke_1866&quot;,6378206.4,294.9786982]],PRIMEM[&quot;Greenwich&quot;,0.0],UNIT[&quot;Degree&quot;,0.0174532925199433]],PROJECTION[&quot;Transverse_Mercator&quot;],PARAMETER[&quot;False_Easting&quot;,500000.0],PARAMETER[&quot;False_Northing&quot;,0.0],PARAMETER[&quot;Central_Meridian&quot;,-105.0],PARAMETER[&quot;Scale_Factor&quot;,0.9996],PARAMETER[&quot;Latitude_Of_Origin&quot;,0.0],UNIT[&quot;Meter&quot;,1.0],AUTHORITY[&quot;EPSG&quot;,26713]]';
$src = array(0,0, 3,0, 0,3, 3,3);
$dst = array(470000,4415000, 473000,4415000, 470000,4418000, 473000,4418000);
$dbl = function($a) { $s = ''; foreach ($a as $v) $s .= "<Double>$v</Double>"; return $s; };
$aux = "<?xml version=\"1.0\" encoding=\"utf-8\" ?><GeodataXform xsi:type='typens:PolynomialXform' xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance' xmlns:xs='http://www.w3.org/2001/XMLSchema' xmlns:typens='http://www.esri.com/schemas/ArcGIS/9.3'>"
	. "<PolynomialOrder>1</PolynomialOrder><SpatialReference xsi:type='typens:ProjectedCoordinateSystem'><WKT>$wkt</WKT><HighPrecision>true</HighPrecision><WKID>26713</WKID></SpatialReference>"
	. "<SourceGCPs xsi:type='typens:ArrayOfDouble'>" . $dbl($src) . "</SourceGCPs>"
	. "<TargetGCPs xsi:type='typens:ArrayOfDouble'>" . $dbl($dst) . "</TargetGCPs></GeodataXform>";

try {
	shell_exec("gdal_create -q -of GTiff -outsize 300 300 -bands 3 -burn 200"
		. " -mo TIFFTAG_XRESOLUTION=100 -mo TIFFTAG_YRESOLUTION=100 -mo TIFFTAG_RESOLUTIONUNIT=2 "
		. escapeshellarg("$workDir/map_1.tif") . " 2>&1");

	$withAux = "$workDir/with_aux.zip";
	$za = new ZipArchive();
	$za->open($withAux, ZipArchive::CREATE);
	$za->addFile("$workDir/map_1.tif", "map_1.tif");
	$za->addFromString("map_1.tif.aux.xml", $aux);
	$za->addFromString("map_1.tif.txt", "0\t0\t470000\t4415000\n");
	$za->addFromString("README.txt", "NGMDB-style download\n");
	$za->close();

	$noAux = "$workDir/no_aux.zip";
	$za = new ZipArchive();
	$za->open($noAux, ZipArchive::CREATE);
	$za->addFile("$workDir/map_1.tif", "map_1.tif");
	$za->addFromString("README.txt", "NGMDB-style download\n");
	$za->close();

	echo "\n=== A. zip with .tif.aux.xml sidecar ===\n";
	list($out, $mine) = uploadZip($withAux);
	note("response: " . substr(preg_replace('/\s+/', ' ', $out), 0, 120));
	$j = json_decode($out);
	$hash = ($j && isset($j->hash)) ? $j->hash : '';
	if ($hash !== '') $hashes[] = $hash;
	check("A: map saved (hash returned, no error)", $hash !== '' && empty($j->error));
	$row = $hash !== '' ? $db->get_row_prepared("SELECT userpkey FROM geotiffs WHERE hash=$1", array($hash)) : null;
	check("A: geotiffs row for the uploader", $row && (int)$row->userpkey === $ownerPkey);
	$info = $hash !== '' ? json_decode((string)shell_exec("gdalinfo -json " . escapeshellarg("$docRoot/geotiff/upload/files/$hash.tif"))) : null;
	$c = $info && isset($info->cornerCoordinates) ? $info->cornerCoordinates : null;
	// 470000-473000 E, 4415000-4418000 N in NAD27 UTM 13N is about -105.35..-105.32, 39.88..39.91
	$inBox = $c && $c->upperLeft[0] > -105.36 && $c->upperLeft[0] < -105.34
		&& $c->lowerRight[0] > -105.33 && $c->lowerRight[0] < -105.30
		&& $c->upperLeft[1] > 39.89 && $c->upperLeft[1] < 39.92
		&& $c->lowerRight[1] > 39.87 && $c->lowerRight[1] < 39.90;
	if ($c) note("bounds: UL " . implode(',', $c->upperLeft) . "  LR " . implode(',', $c->lowerRight));
	check("A: reprojected map lies where the GCPs put it", $inBox);

	echo "\n=== B. same zip without the sidecar ===\n";
	list($out) = uploadZip($noAux);
	note("response: " . substr(preg_replace('/\s+/', ' ', $out), 0, 120));
	$j = json_decode($out);
	if ($j && isset($j->hash) && $j->hash) $hashes[] = $j->hash;
	check("B: refused as not referenced", strpos($out, "not referenced") !== false);

	echo "\n=== C. sidecar not served from ziptemp ===\n";
	$found = '';
	foreach ($mine as $r) {
		if (file_exists("$docRoot/ziptemp/$r/map_1.tif.aux.xml")) $found = "ziptemp/$r/map_1.tif.aux.xml";
	}
	check("C: sidecar was extracted to the work folder", $found !== '');
	if ($found !== '') {
		$code = trim((string)shell_exec("curl -s -o /dev/null -w '%{http_code}' " . escapeshellarg("http://localhost/$found")));
		note("GET /$found -> $code");
		check("C: sidecar download refused (403)", $code === '403');
	}

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
