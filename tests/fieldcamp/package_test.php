<?php
/**
 * File: tests/fieldcamp/package_test.php
 * Description: Suite for the Field Camp Wizard, stage 4: custom maps and the
 *              project package (includes/fieldcamp/FieldCampPackage.php,
 *              field_camp_package.php, the Maps and Package steps of
 *              field_camp.php, maps_save in field_camp_action.php).
 *
 *              The yardstick is a backup the app made itself:
 *              fixtures/app_backup_data.json and app_backup_other_maps.json
 *              are data.json and other_maps.json of a "Save & Zip" backup from
 *              StraboField 2.32.0 on an iPad (Jason, 11-10-2026: one project,
 *              a Default dataset, three tags, one My Maps custom map, one
 *              spot, offline tiles). The package is compared with it key by
 *              key and type by type, and then read the way the app's import
 *              reads a backup (useImport.js loadProjectData / checkForMaps).
 *
 *              Fixture users 94721-94722, My Maps entries with hashes starting
 *              "fcpkgtest"; everything the suite makes is removed again at the
 *              start and at the end.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/package_test.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/fieldcamp/FieldCamp.php';
require_once 'includes/fieldcamp/FieldCampUnits.php';
require_once 'includes/fieldcamp/FieldCampPackage.php';

$INSTR = 94721; $OUTSIDER = 94722;
$FIX = array($INSTR => 'fcpkg-instructor@example.org', $OUTSIDER => 'fcpkg-outsider@example.org');
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$TOKEN = str_repeat('ab78', 8);
$FIXDIR = __DIR__ . '/fixtures';

$pass = 0; $fail = 0; $skip = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

function cleanup() {
	global $db, $FIX;
	$fixpkeys = implode(',', array_keys($FIX));
	$rows = $db->get_results_prepared("select pkey from fieldcamp where owner_user_pkey in ($fixpkeys)", array());
	if ($rows) {
		$in = implode(',', array_map(function ($r) { return (int)$r->pkey; }, $rows));
		foreach (array('fieldcamp_map', 'fieldcamp_unit', 'fieldcamp_unit_source') as $t) $db->query("delete from $t where fieldcamp_pkey in ($in)");
		$db->query("delete from fieldcamp where pkey in ($in)");
	}
	$db->query("delete from geotiffs where hash like 'fcpkgtest%'");
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fixpkeys)");
	$db->query("delete from users where pkey in ($fixpkeys)");
}

$sids = array();
function forge($pkey, $email) {
	global $SESS, $sids, $TOKEN;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$data = 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';'
		. 'fieldcamp_csrf|s:' . strlen($TOKEN) . ':"' . $TOKEN . '";';
	file_put_contents("$SESS/sess_$sid", $data);
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
function http($method, $path, $sid, $post = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	$hdr = array();
	if ($sid !== null) $hdr[] = 'Cookie: PHPSESSID=' . $sid;
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $hdr));
	if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$headers = substr((string)$raw, 0, $hs); $body = substr((string)$raw, $hs);
	$loc = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
	return array($code, (string)$body, $loc, $headers);
}
function post_follow($sid, $fields) {
	global $TOKEN;
	list($code, $body, $loc) = http('POST', '/field_camp_action', $sid, $fields + array('csrf' => $TOKEN));
	if ($loc === '') return array($code, $body, $loc);
	list($c2, $b2) = http('GET', $loc, $sid);
	return array($c2, $b2, $loc);
}
function php_error($body) {
	return (bool)preg_match('~<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>~', $body);
}
/** gdalinfo text as geotiffs.gdalinfo holds it, for a map with these corners. */
function gdalinfo_text($w, $s, $e, $n) {
	$dms = function ($v, $pos, $neg) {
		$h = $v < 0 ? $neg : $pos; $v = abs($v);
		$d = floor($v); $m = floor(($v - $d) * 60); $sec = ($v - $d - $m / 60) * 3600;
		return sprintf("%3dd%2d'%5.2f\"%s", $d, $m, $sec, $h);
	};
	$corner = function ($label, $x, $y) use ($dms) {
		return sprintf("%-11s (%12.7f,%12.7f) (%s, %s)", $label, $x, $y, $dms($x, 'E', 'W'), $dms($y, 'N', 'S'));
	};
	return "Driver: GTiff/GeoTIFF\nSize is 100, 100\nCorner Coordinates:\n" . $corner('Upper Left', $w, $n) . "\n" . $corner('Lower Left', $w, $s) . "\n"
		. $corner('Upper Right', $e, $n) . "\n" . $corner('Lower Right', $e, $s) . "\n" . $corner('Center', ($w + $e) / 2, ($s + $n) / 2) . "\nBand 1 Block=100x1 Type=Byte\n";
}
/** What a JSON value is, for comparing shapes: object, list, string, number, boolean, null. */
function kind($v) {
	if (is_object($v)) return 'object';
	if (is_array($v)) return 'list';
	if (is_string($v)) return 'string';
	if (is_int($v) || is_float($v)) return 'number';
	if (is_bool($v)) return 'boolean';
	return 'null';
}
/** Keys of $ours missing from or extra to $theirs, and keys whose JSON kind differs. Both decoded as objects. */
function shape_diff($ours, $theirs, $ignore = array()) {
	$a = array_keys(get_object_vars($ours)); $b = array_keys(get_object_vars($theirs));
	$out = array();
	foreach (array_diff($b, $a, $ignore) as $k) $out[] = "missing $k";
	foreach (array_diff($a, $b, $ignore) as $k) $out[] = "extra $k";
	foreach (array_intersect($a, $b) as $k) {
		if (in_array($k, $ignore, true)) continue;
		if (kind($ours->$k) !== kind($theirs->$k)) $out[] = "$k is " . kind($ours->$k) . ", the app writes " . kind($theirs->$k);
	}
	return $out;
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Package', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fcpkghash' . $pk, $email));
}
FieldCamp::addInstructor($db, $INSTR, 'package test', 3);

// The study area of Jason's reference camp (near Canon City, Colorado).
$AREA = array('west' => -105.389271, 'south' => 38.517549, 'east' => -105.159931, 'north' => 38.587088);
$maps = array(
	'fcpkgtest0001' => array($INSTR, 'Canon_City_geology.tif', gdalinfo_text(-105.33, 38.50, -105.20, 38.56), '2026-11-01'),
	'fcpkgtest0002' => array($INSTR, 'Arizona_Illustrated.tif', gdalinfo_text(-115.5855677, 30.1961601, -108.3224288, 37.9990426), '2026-11-02'),
	'fcpkgtest0003' => array($INSTR, 'not_referenced.tif', "Driver: GTiff/GeoTIFF\nSize is 10, 10\n", '2026-11-03'),
	'fcpkgtest0004' => array($INSTR, 'USGS_B-1221-D.zip', gdalinfo_text(-105.40, 38.40, -105.38, 38.60), '2026-11-04'),
	'fcpkgtest0005' => array($OUTSIDER, 'someone_elses.tif', gdalinfo_text(-105.33, 38.50, -105.20, 38.56), '2026-11-05'),
	'fcpkgtest0006' => array($OUTSIDER, 'public_here.tif', gdalinfo_text(-105.30, 38.52, -105.25, 38.55), '2026-11-06', true),
	'fcpkgtest0007' => array($OUTSIDER, 'public_elsewhere.tif', gdalinfo_text(-90.0, 40.0, -89.0, 41.0), '2026-11-07', true),
);
foreach ($maps as $hash => $m) {
	$db->prepare_query("insert into geotiffs (userpkey, hash, name, gdalinfo, uploaddate, ispublic) values ($1, $2, $3, $4, $5, $6)",
		array($m[0], $hash, $m[1], $m[2], $m[3], empty($m[4]) ? 'f' : 't'));
}
/** The fixture maps in a list (this machine's real public maps over the same area are left aside). */
function ours($list) {
	return array_values(array_filter($list, function ($m) { return strpos($m['hash'], 'fcpkgtest') === 0; }));
}
list($clean) = FieldCamp::validateBasics(array('camp_name' => 'Package Camp', 'camp_year' => '2027', 'project_name' => 'KU Field Camp 2027 (test)', 'start_date' => '2027-06-01', 'end_date' => '2027-06-20'));
$campid = FieldCamp::createCamp($db, $INSTR, $clean);
FieldCamp::saveArea($db, $campid, $AREA);
$camp = FieldCamp::getCamp($db, $campid);

// ===========================================================================
section('Maps: where a map is');
check('tables installed', FieldCampPackage::installed($db));
$e = FieldCampPackage::extentFromGdalinfo($maps['fcpkgtest0002'][2]);
check('extent read from the corners gdalinfo saved', $e && abs($e['west'] + 115.5855677) < 1e-5 && abs($e['south'] - 30.1961601) < 1e-5 && abs($e['east'] + 108.3224288) < 1e-5 && abs($e['north'] - 37.9990426) < 1e-5, $e);
$real = "Corner Coordinates:\nUpper Left  (-115.5855677,  37.9990426) (115d35'' 8.04\"W, 37d59''56.55\"N)\nLower Left  (-115.5855677,  30.1961601) (115d35'' 8.04\"W, 30d11''46.18\"N)\nUpper Right (-108.3224288,  37.9990426) (108d19''20.74\"W, 37d59''56.55\"N)\nLower Right (-108.3224288,  30.1961601) (108d19''20.74\"W, 30d11''46.18\"N)\nCenter      (-111.9539982,  34.0976013) (111d57''14.39\"W, 34d 5''51.36\"N)\n";
$e = FieldCampPackage::extentFromGdalinfo($real);
check('also in the form older uploads were stored (doubled minute marks)', $e && abs($e['west'] + 115.5855677) < 1e-5 && abs($e['north'] - 37.9990426) < 1e-5, $e);
$utm = "Corner Coordinates:\nUpper Left  (  476000.000, 4263000.000) (105d16'31.10\"W, 38d30'55.20\"N)\nLower Left  (  476000.000, 4260000.000) (105d16'30.90\"W, 38d29'17.90\"N)\nUpper Right (  480000.000, 4263000.000) (105d13'45.90\"W, 38d30'55.40\"N)\nLower Right (  480000.000, 4260000.000) (105d13'45.80\"W, 38d29'18.10\"N)\n";
$e = FieldCampPackage::extentFromGdalinfo($utm);
check('a projected map (UTM metres) is read by its longitude and latitude', $e && abs($e['west'] + 105.27531) < 1e-4 && abs($e['north'] - 38.51539) < 1e-4, $e);
check('a map without corners has no extent', FieldCampPackage::extentFromGdalinfo($maps['fcpkgtest0003'][2]) === null && FieldCampPackage::extentFromGdalinfo('') === null && FieldCampPackage::extentFromGdalinfo(null) === null);
check('southern and eastern hemispheres', (function () { $e = FieldCampPackage::extentFromGdalinfo(gdalinfo_text(150.5, -34.25, 151.5, -33.5)); return $e && abs($e['west'] - 150.5) < 1e-4 && abs($e['south'] + 34.25) < 1e-4; })());

$list = ours(FieldCampPackage::listMyMaps($db, $camp));
$by = array(); foreach ($list as $m) $by[$m['hash']] = $m;
check('the owner\'s four maps, newest first, then the public map over the area', array_keys($by) === array('fcpkgtest0004', 'fcpkgtest0003', 'fcpkgtest0002', 'fcpkgtest0001', 'fcpkgtest0006'), array_keys($by));
check('someone else\'s private map and a public map elsewhere are not offered', !isset($by['fcpkgtest0005']) && !isset($by['fcpkgtest0007']));
check('the public map is marked public, the owner\'s are not', $by['fcpkgtest0006']['public'] === true && $by['fcpkgtest0006']['overlaps'] === true && $by['fcpkgtest0001']['public'] === false);
list($cleanNA) = FieldCamp::validateBasics(array('camp_name' => 'No Area', 'camp_year' => '2027', 'project_name' => 'No Area'));
$noAreaId = FieldCamp::createCamp($db, $INSTR, $cleanNA);
check('without a study area no public map is offered', count(ours(FieldCampPackage::listMyMaps($db, FieldCamp::getCamp($db, $noAreaId)))) === 4);
check('a map inside the study area overlaps', $by['fcpkgtest0001']['overlaps'] === true);
check('a map elsewhere does not', $by['fcpkgtest0002']['overlaps'] === false);
check('a map that touches the area\'s edge strip overlaps', $by['fcpkgtest0004']['overlaps'] === true);
check('a map with no location is neither', $by['fcpkgtest0003']['overlaps'] === null && $by['fcpkgtest0003']['extent'] === null);
check('nothing is selected at first; titles start from the file names', !$by['fcpkgtest0001']['chosen'] && $by['fcpkgtest0001']['title'] === 'Canon City geology' && $by['fcpkgtest0004']['title'] === 'USGS B-1221-D');

// ===========================================================================
section('Maps: the selection');
check('save two maps', FieldCampPackage::saveMaps($db, $camp, array(
	array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => '  Canon City   Map '),
	array('hash' => 'fcpkgtest0004', 'chosen' => true, 'title' => 'USGS B-1221-D'),
	array('hash' => 'fcpkgtest0002', 'chosen' => false, 'title' => ''),
)) === array());
$rows = FieldCampPackage::listCampMaps($db, $campid);
check('two rows, titles trimmed', count($rows) === 2 && $rows[0]->title === 'Canon City Map' && $rows[1]->geotiff_hash === 'fcpkgtest0004', $rows);
$errs = FieldCampPackage::saveMaps($db, $camp, array(array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => ' ')));
check('a selected map needs a title', count($errs) === 1 && strpos($errs[0], 'needs a title') !== false, $errs);
$errs = FieldCampPackage::saveMaps($db, $camp, array(array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => 'Same'), array('hash' => 'fcpkgtest0004', 'chosen' => true, 'title' => 'same')));
check('two maps cannot share a title', count($errs) === 1 && strpos($errs[0], 'both titled') !== false, $errs);
$errs = FieldCampPackage::saveMaps($db, $camp, array(array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => str_repeat('t', 101))));
check('too long a title is refused', count($errs) === 1, $errs);
check('a refused save changes nothing', count(FieldCampPackage::listCampMaps($db, $campid)) === 2);
check('someone else\'s private map, a public map elsewhere and an unknown map are not taken', FieldCampPackage::saveMaps($db, $camp, array(
	array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => 'Canon City Map'),
	array('hash' => 'fcpkgtest0005', 'chosen' => true, 'title' => 'Not mine'),
	array('hash' => 'fcpkgtest0007', 'chosen' => true, 'title' => 'Public, far away'),
	array('hash' => 'nosuchmap', 'chosen' => true, 'title' => 'Nothing'),
	'junk',
)) === array() && array_map(function ($r) { return $r->geotiff_hash; }, FieldCampPackage::listCampMaps($db, $campid)) === array('fcpkgtest0001'));
check('a public map over the area can be selected', FieldCampPackage::saveMaps($db, $camp, array(
	array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => 'Canon City Map'),
	array('hash' => 'fcpkgtest0006', 'chosen' => true, 'title' => 'Public geology'),
)) === array() && count(FieldCampPackage::listCampMaps($db, $campid)) === 2);
$cmPub = FieldCampPackage::customMapsForCamp($db, $camp);
check('and becomes a custom map like any other', count($cmPub) === 2 && $cmPub[1]['id'] === 'fcpkgtest0006' && $cmPub[1]['title'] === 'Public geology' && $cmPub[1]['source'] === 'strabospot_mymaps' && $cmPub[1]['bbox'] === '-105.3,38.52,-105.25,38.55', $cmPub);
$db->prepare_query("update geotiffs set ispublic = false where hash = $1", array('fcpkgtest0006'));
check('made private again by its owner: it drops out of the project and off the list',
	count(FieldCampPackage::customMapsForCamp($db, $camp)) === 1 && count(array_filter(ours(FieldCampPackage::listMyMaps($db, $camp)), function ($m) { return $m['hash'] === 'fcpkgtest0006'; })) === 0);
$db->prepare_query("update geotiffs set ispublic = true where hash = $1", array('fcpkgtest0006'));
FieldCampPackage::saveMaps($db, $camp, array(array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => 'Canon City Map')));
check('the list shows the selection and the saved title', (function () use ($db, $camp) {
	foreach (FieldCampPackage::listMyMaps($db, $camp) as $m) { if ($m['hash'] === 'fcpkgtest0001') return $m['chosen'] && $m['title'] === 'Canon City Map'; }
	return false;
})());

// ===========================================================================
section('Maps: the custom map object, against the app\'s');
$appData = json_decode(file_get_contents("$FIXDIR/app_backup_data.json"));
$appOther = json_decode(file_get_contents("$FIXDIR/app_backup_other_maps.json"));
$appMap = $appData->otherMapsDb->{'62b11568865a7'};
$ours = json_decode(json_encode(FieldCampPackage::customMap('62b11568865a7', 'Canon City Map', '-105.3113443,38.4893179,-105.2588832,38.5058905')));
check('same map, same title, same bbox: identical to what the app wrote', $ours == $appMap && json_encode($ours) === json_encode($appMap), json_encode($ours));
check('a map with no known bbox has no bbox key (the app then looks it up)', !array_key_exists('bbox', FieldCampPackage::customMap('x', 'T', '')));
$cm = FieldCampPackage::customMapsForCamp($db, $camp);
check('the camp\'s map, with the bbox read from its saved extent', count($cm) === 1 && $cm[0]['id'] === 'fcpkgtest0001' && $cm[0]['title'] === 'Canon City Map' && $cm[0]['bbox'] === '-105.33,38.5,-105.2,38.56', $cm);
$realFile = '/srv/app/www/geotiff/upload/files/6ad8fb51121d7.tif';
if (is_file($realFile)) {
	list($code, $body) = http('GET', '/geotiff/bbox/6ad8fb51121d7', null);
	$answer = json_decode($body, true);
	check('for a map whose file is here, the bbox is exactly what /geotiff/bbox gives the app', $code === 200 && isset($answer['data']['bbox'])
		&& FieldCampPackage::bboxString('6ad8fb51121d7', '') === $answer['data']['bbox'], array($body, FieldCampPackage::bboxString('6ad8fb51121d7', '')));
} else {
	$skip++; echo "  SKIP  no My Maps file on this machine to compare the bbox with /geotiff/bbox\n";
}

// ===========================================================================
section('Package: data.json against the app\'s own backup');
// Rock units: the Montana answer saved as a fixture (see units_test.php); which area it is does not matter here.
FieldCampUnits::$fetcher = function ($url) use ($FIXDIR) {
	if (strpos($url, '/sources/') !== false) return array(200, json_encode(array('name' => 'Geologic map of Testland', 'ref_title' => 'Tester, A., 2007, Geologic map of Testland.')));
	return array(200, file_get_contents("$FIXDIR/macrostrat_legend_montana.json"));
};
FieldCampUnits::save($db, $camp, FieldCampUnits::lookup(FieldCampUnits::campBbox($camp)));
FieldCampUnits::$fetcher = null;
$camp = FieldCamp::getCamp($db, $campid);

$before = time();
$data = FieldCampPackage::buildData($db, $camp);
$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$d = json_decode($json);
check('it is JSON', is_object($d), json_last_error_msg());
check('top level: the app\'s five keys, same kinds', shape_diff($d, $appData) === array(), shape_diff($d, $appData));
check('projectDb: the app\'s keys, same kinds', shape_diff($d->projectDb, $appData->projectDb) === array(), shape_diff($d->projectDb, $appData->projectDb));
check('project: the app\'s keys, same kinds', shape_diff($d->projectDb->project, $appData->projectDb->project) === array(), shape_diff($d->projectDb->project, $appData->projectDb->project));
check('project description: the app\'s keys, same kinds', shape_diff($d->projectDb->project->description, $appData->projectDb->project->description) === array(), shape_diff($d->projectDb->project->description, $appData->projectDb->project->description));
$ourDs = array_values(get_object_vars($d->projectDb->datasets)); $appDs = array_values(get_object_vars($appData->projectDb->datasets));
check('one dataset, the app\'s keys, same kinds', count($ourDs) === 1 && shape_diff($ourDs[0], $appDs[0]) === array() && shape_diff($ourDs[0]->images, $appDs[0]->images) === array(), shape_diff($ourDs[0], $appDs[0]));
check('values a fresh project has are the app\'s values', $d->projectDb->project->other_features == $appData->projectDb->project->other_features
	&& $d->projectDb->project->relationship_types == $appData->projectDb->project->relationship_types
	&& $d->projectDb->project->useContinuousTagging === false && $d->projectDb->project->description->gps_datum === $appData->projectDb->project->description->gps_datum
	&& $d->projectDb->_persist == $appData->projectDb->_persist && $ourDs[0]->name === $appDs[0]->name);
foreach (array('activeDatasetsIds', 'addTagToSelectedSpot', 'backupFileName', 'changedImageIds', 'deviceBackUpDirectoryExists', 'downloadsDirectory', 'isImageTransferring',
	'isMultipleFeaturesTaggingEnabled', 'isTestingMode', 'projectTransferProgress', 'readOnlyDatasetsIds', 'sampleSpotIdsToUpload') as $k) {
	if ($k === 'activeDatasetsIds') continue;
	if ($d->projectDb->$k != $appData->projectDb->$k) { check("projectDb.$k has the app's value", false, array($d->projectDb->$k, $appData->projectDb->$k)); }
}
check('the other projectDb flags have the app\'s values', $d->projectDb->addTagToSelectedSpot === false && $d->projectDb->backupFileName === '' && $d->projectDb->changedImageIds === array() && $d->projectDb->projectTransferProgress === 0);

$p = $d->projectDb->project;
$appP = $appData->projectDb->project;
check('ids are numbers in the app\'s format (14 digits), like the app\'s', is_int($p->id) && strlen((string)$p->id) === 14 && is_int($appP->id) && strlen((string)$appP->id) === 14
	&& is_int($ourDs[0]->id) && strlen((string)$ourDs[0]->id) === 14 && $p->id !== $ourDs[0]->id, array($p->id, $ourDs[0]->id));
check('the dataset is keyed by its id, active and the target', isset($d->projectDb->datasets->{(string)$ourDs[0]->id}) && $d->projectDb->activeDatasetsIds === array($ourDs[0]->id) && $d->projectDb->targetDatasetId === $ourDs[0]->id);
check('dates are written as the app writes them', preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $p->date) === 1 && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $appP->date) === 1
	&& preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $ourDs[0]->date) === 1 && $p->description->start_date === '2027-06-01T12:00:00.000Z' && $p->description->end_date === '2027-06-20T12:00:00.000Z', array($p->date, $p->description));
check('modified_timestamp is now, in milliseconds', strlen((string)$p->modified_timestamp) === 13 && abs($p->modified_timestamp / 1000 - $before) < 60 && strlen((string)$appP->modified_timestamp) === 13);
check('project name and notes', $p->description->project_name === 'KU Field Camp 2027 (test)' && strpos($p->description->notes, 'Package Camp 2027') === 0 && strpos($p->description->notes, 'CC-BY 4.0') !== false, $p->description->notes);
check('no spots, no offline maps: empty objects, not lists', $json !== false && strpos($json, '"spotsDb":{}') !== false && strpos($json, '"mapNamesDb":{}') !== false && strpos($json, '"mapTilesDb":{}') !== false
	&& strpos($json, '"templates":{}') !== false && strpos($json, '"preferences":{}') !== false && strpos($json, '"spotIds":[]') !== false);

check('14 geologic unit tags, the ones FieldCampUnits makes', count($p->tags) === 14 && json_decode(json_encode($p->tags), true) == FieldCampUnits::tagsForCamp($db, $campid));
$appBare = null; foreach ($appP->tags as $t) { if ($t->name === 'Test Unit Barren') $appBare = $t; }
$missing = array();
foreach ($p->tags as $t) { foreach (array_keys(get_object_vars($appBare)) as $k) { if (!isset($t->$k)) $missing[] = "$t->name: $k"; elseif (kind($t->$k) !== kind($appBare->$k)) $missing[] = "$t->name: $k kind"; } }
check('every tag has what the app\'s barest geologic unit tag has, same kinds', $appBare && !$missing, $missing);
check('the custom map is in the project and in otherMapsDb, the same object', count($p->other_maps) === 1 && $p->other_maps[0]->id === 'fcpkgtest0001'
	&& isset($d->otherMapsDb->fcpkgtest0001) && $d->otherMapsDb->fcpkgtest0001 == $p->other_maps[0] && shape_diff($p->other_maps[0], $appMap) === array(), shape_diff($p->other_maps[0], $appMap));

$again = json_decode(json_encode(FieldCampPackage::buildData($db, FieldCamp::getCamp($db, $campid))));
check('built again: same project id, dataset id and tag ids', $again->projectDb->project->id === $p->id && array_keys(get_object_vars($again->projectDb->datasets)) === array_keys(get_object_vars($d->projectDb->datasets))
	&& array_column(json_decode(json_encode($again->projectDb->project->tags), true), 'id') === array_column(json_decode(json_encode($p->tags), true), 'id'));
$row = $db->get_row_prepared("select project_id, dataset_id from fieldcamp where pkey = $1", array($campid));
check('the ids are stored with the camp', (int)$row->project_id === $p->id && (int)$row->dataset_id === $ourDs[0]->id);

list($clean2) = FieldCamp::validateBasics(array('camp_name' => 'Bare Camp', 'camp_year' => '2027', 'project_name' => 'Bare'));
$bareid = FieldCamp::createCamp($db, $INSTR, $clean2);
$bare = json_decode(json_encode(FieldCampPackage::buildData($db, FieldCamp::getCamp($db, $bareid))));
check('a camp with nothing chosen still makes a whole project', shape_diff($bare->projectDb->project, $appP, array()) === array() && $bare->projectDb->project->tags === array() && $bare->projectDb->project->other_maps === array()
	&& get_object_vars($bare->otherMapsDb) === array() && !isset($bare->projectDb->project->description->end_date) && strpos($bare->projectDb->project->description->notes, 'Macrostrat') === false,
	shape_diff($bare->projectDb->project, $appP));
check('another camp has other ids', $bare->projectDb->project->id !== $p->id);

// ===========================================================================
section('Package: read the way the app\'s import reads a backup');
// useImport.js loadProjectData: spotsDb, projectDb.project || projectDb, projectDb.datasets, first dataset made active.
$loaded = $d->projectDb->project ?? $d->projectDb;
$firstDs = array_values(get_object_vars($d->projectDb->datasets))[0] ?? null;
check('the project, its datasets and (no) spots are where the import looks', is_object($loaded) && $loaded->description->project_name === 'KU Field Camp 2027 (test)' && $firstDs && $firstDs->id > 0 && is_object($d->spotsDb));
// checkForMaps: custom maps come from otherMapsDb; tiles are looked for only when mapNamesDb is not empty.
check('custom maps come from otherMapsDb; no tile step because mapNamesDb is empty', count(get_object_vars($d->otherMapsDb)) === 1 && count(get_object_vars($d->mapNamesDb)) === 0);
check('the app tells geologic unit tags by type', count(array_filter($p->tags, function ($t) { return $t->type === 'geologic_unit'; })) === 14);

// ===========================================================================
section('Package: the zip');
$tmp = tempnam(sys_get_temp_dir(), 'fcpkgtest');
$built = FieldCampPackage::buildZip($db, FieldCamp::getCamp($db, $campid), $tmp);
check('built', $built['error'] === '' && $built['tags'] === 14 && $built['maps'] === 1 && $built['bytes'] > 1000, $built);
$zip = new ZipArchive(); $zip->open($tmp);
$entries = array(); for ($i = 0; $i < $zip->numFiles; $i++) $entries[] = $zip->getNameIndex($i);
sort($entries);
check('files at the root, as "Save & Zip" writes them (no maps folder without tiles)', $entries === array('data.json', 'images/', 'other_maps.json'), $entries);
$zd = json_decode($zip->getFromName('data.json'));
check('data.json is the project', is_object($zd) && $zd->projectDb->project->id === $p->id && count($zd->projectDb->project->tags) === 14);
$zo = json_decode($zip->getFromName('other_maps.json'));
check('other_maps.json holds the custom maps keyed by id, as the app\'s does', is_object($zo) && $zo == $zd->otherMapsDb && is_object($appOther) && $appOther == $appData->otherMapsDb
	&& shape_diff($zo->fcpkgtest0001, $appOther->{'62b11568865a7'}) === array());
$zip->close(); unlink($tmp);
$c = FieldCamp::getCamp($db, $campid);
check('the zip is named by date and project name', FieldCampPackage::fileName($c, mktime(15, 52, 0, 11, 10, 2026)) === '2026-11-10_KU_Field_Camp_2027_test', FieldCampPackage::fileName($c, mktime(15, 52, 0, 11, 10, 2026)));
check('a project name with nothing usable still gives a name', (function () { $o = (object)array('project_name' => '!!!'); return preg_match('/_Field_Camp$/', FieldCampPackage::fileName($o)) === 1; })());

// ===========================================================================
section('HTTP: Maps and Package steps, the download');
$sidI = forge($INSTR, $FIX[$INSTR]);
$sidO = forge($OUTSIDER, $FIX[$OUTSIDER]);
list($code, $body) = http('GET', "/field_camp?id=$campid&step=maps", $sidI);
check('the owner opens the Maps step', $code === 200 && !php_error($body) && strpos($body, 'Custom maps') !== false, $code);
check('the owner\'s maps in the area can be selected; the saved one is checked with its title', strpos($body, 'Your maps in the study area (2)') !== false
	&& strpos($body, 'data-hash="fcpkgtest0001"') !== false && strpos($body, 'data-hash="fcpkgtest0004"') !== false
	&& preg_match('~id="fcMfcpkgtest0001" checked~', $body) === 1 && strpos($body, 'value="Canon City Map"') !== false && strpos($body, 'value="USGS B-1221-D"') !== false);
check('public maps over the area have their own list and can be selected', preg_match('~Public maps in the study area \(\d+\)~', $body) === 1
	&& strpos($body, 'data-hash="fcpkgtest0006"') !== false && strpos($body, 'public_here.tif') !== false && preg_match('~id="fcMfcpkgtest0006" checked~', $body) === 0);
check('the owner\'s maps elsewhere are listed but cannot be selected', strpos($body, 'Your maps not in the study area (2)') !== false && strpos($body, 'Arizona_Illustrated.tif') !== false && strpos($body, 'No location could be read from this map') !== false
	&& strpos($body, 'data-hash="fcpkgtest0002"') === false);
check('someone else\'s private map and a public map elsewhere are not shown', strpos($body, 'someone_elses.tif') === false && strpos($body, 'public_elsewhere.tif') === false);
check('the steps run Basics, Study area, Maps, Offline maps, Rock units, Accounts, Package', preg_match('~step=basics".*step=area".*step=maps".*step=tiles".*step=units".*step=accounts".*step=package"~s', $body) === 1);
list($code, $body) = http('GET', "/field_camp?id=$campid&step=area", $sidI);
check('Study area leads on to Maps', strpos($body, 'Next: Maps') !== false);
list($code, $body) = http('GET', "/field_camp?id=$bareid&step=maps", $sidI);
check('without a study area every map of the owner is listed and selectable, and no public map', $code === 200 && !php_error($body) && strpos($body, 'No study area is saved yet') !== false
	&& substr_count($body, 'data-hash=') === 4 && strpos($body, 'Public maps in the study area') === false);

list($code, $body) = post_follow($sidI, array('action' => 'maps_save', 'id' => $campid, 'maps_json' => json_encode(array(
	array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => 'Canon City Map'), array('hash' => 'fcpkgtest0004', 'chosen' => true, 'title' => 'USGS Bulletin 1221-D')))));
check('saving the step works', $code === 200 && strpos($body, 'Saved. 2 custom maps are in the project.') !== false && count(FieldCampPackage::listCampMaps($db, $campid)) === 2);
list($code, $body) = post_follow($sidI, array('action' => 'maps_save', 'id' => $campid, 'maps_json' => json_encode(array(
	array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => ''), array('hash' => 'fcpkgtest0004', 'chosen' => true, 'title' => 'Typed "title"')))));
check('a refused save names the problem and keeps what was typed', strpos($body, 'needs a title') !== false && strpos($body, 'value="Typed &quot;title&quot;"') !== false && count(FieldCampPackage::listCampMaps($db, $campid)) === 2);
// Next saves and moves on; nothing needs a separate save.
FieldCampPackage::saveMaps($db, FieldCamp::getCamp($db, $campid), array());
list($code, $body, $loc) = post_follow($sidI, array('action' => 'maps_save', 'id' => $campid, 'goto' => 'tiles', 'maps_json' => json_encode(array(
	array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => 'Canon City Map'), array('hash' => 'fcpkgtest0006', 'chosen' => true, 'title' => 'Public geology')))));
check('Next on the Maps step saves the selection and opens Offline maps', strpos($loc, 'step=tiles') !== false && count(FieldCampPackage::listCampMaps($db, $campid)) === 2, $loc);
check('and the maps just selected, a public one included, are offered there', $code === 200 && !php_error($body) && strpos($body, 'data-layer="fcpkgtest0001"') !== false && strpos($body, 'data-layer="fcpkgtest0006"') !== false
	&& strpos($body, 'Public geology') !== false && strpos($body, 'Saved.') === false);
list($code, $body, $loc) = post_follow($sidI, array('action' => 'maps_save', 'id' => $campid, 'goto' => 'tiles', 'maps_json' => json_encode(array(
	array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => '')))));
check('when the step cannot be saved, Next stays on it and says why', strpos($loc, 'step=maps') !== false && strpos($body, 'needs a title') !== false && count(FieldCampPackage::listCampMaps($db, $campid)) === 2, $loc);
list($code, $body, $loc) = post_follow($sidI, array('action' => 'maps_save', 'id' => $campid, 'goto' => 'https://example.org/x', 'maps_json' => json_encode(array(
	array('hash' => 'fcpkgtest0001', 'chosen' => true, 'title' => 'Canon City Map'), array('hash' => 'fcpkgtest0004', 'chosen' => true, 'title' => 'USGS Bulletin 1221-D')))));
check('a "go to" that is not a step is ignored', strpos($loc, 'step=maps') !== false && strpos($loc, 'example.org') === false && strpos($body, 'Saved. 2 custom maps') !== false, $loc);
list($code, $body) = http('GET', "/field_camp?id=$campid&step=maps", $sidI);
check('the Maps step has one button, Next, and no separate save', preg_match('~<button type="submit" class="button primary small" data-fc-next="tiles">Next: Offline maps</button>~', $body) === 1 && strpos($body, 'Save maps') === false
	&& strpos($body, 'data-fc-save="maps_save"') !== false);
list($code, $body, $loc) = post_follow($sidI, array('action' => 'basics', 'id' => $campid, 'goto' => 'area', 'camp_name' => 'Package Camp', 'camp_year' => '2027', 'project_name' => 'KU Field Camp 2027 (test)', 'start_date' => '2027-06-01', 'end_date' => '2027-06-20'));
check('Next on Basics saves and opens Study area', strpos($loc, 'step=area') !== false && $code === 200, $loc);

list($code, $body) = post_follow($sidI, array('action' => 'maps_save', 'id' => $campid, 'maps_json' => '{'));
check('an unreadable list is refused', strpos($body, 'could not be read') !== false);
list($code) = http('POST', '/field_camp_action', $sidO, array('action' => 'maps_save', 'id' => $campid, 'maps_json' => '[]', 'csrf' => $TOKEN));
check('an outsider cannot save maps', $code === 404 && count(FieldCampPackage::listCampMaps($db, $campid)) === 2, $code);

list($code, $body) = http('GET', "/field_camp?id=$campid&step=package", $sidI);
check('the Package step sums up the project', $code === 200 && !php_error($body) && strpos($body, 'KU Field Camp 2027 (test)') !== false && preg_match('~Geologic unit tags</td><td>14~', $body) === 1
	&& strpos($body, '2: Canon City Map, USGS Bulletin 1221-D') !== false && strpos($body, "/field_camp_package?id=$campid") !== false);
check('it explains how to get the project onto the devices today, and how the app is meant to offer it', strpos($body, 'id="fcPackageHowTo"') !== false && strpos($body, 'Today: send the package to each device') !== false
	&& strpos($body, 'AirDrop') !== false && strpos($body, 'Unzip and Save') !== false && strpos($body, 'How it is meant to work: StraboField offers the project at sign-in') !== false
	&& strpos($body, 'Download it now?') !== false && strpos($body, 'StraboField does not ask for it yet') !== false && substr_count($body, '<ol class="fc-steps-list">') === 2);
list($code, $body) = http('GET', "/field_camp?id=$campid&step=accounts", $sidI);
check('Accounts leads on to Package', strpos($body, 'Next: Package') !== false);

list($code, $body, , $headers) = http('GET', "/field_camp_package?id=$campid", $sidI);
check('the owner downloads a zip', $code === 200 && stripos($headers, 'Content-Type: application/zip') !== false && substr($body, 0, 2) === 'PK', array($code, substr($body, 0, 80)));
check('named after the date and the project', preg_match('~filename="\d{4}-\d\d-\d\d_KU_Field_Camp_2027_test\.zip"~', $headers) === 1, $headers);
$tmp = tempnam(sys_get_temp_dir(), 'fcpkgdl'); file_put_contents($tmp, $body);
$zip = new ZipArchive(); $ok = $zip->open($tmp) === true;
$zd = $ok ? json_decode($zip->getFromName('data.json')) : null;
check('and it holds the project with 14 tags and the 2 maps', $zd && $zd->projectDb->project->id === $p->id && count($zd->projectDb->project->tags) === 14 && count($zd->projectDb->project->other_maps) === 2
	&& $zd->projectDb->project->other_maps[1]->title === 'USGS Bulletin 1221-D' && $zip->numFiles === 3);
if ($ok) $zip->close(); unlink($tmp);
list($code) = http('GET', "/field_camp_package?id=$campid", $sidO);
check('an outsider gets 404', $code === 404, $code);
list($code, $body) = http('GET', "/field_camp_package?id=$campid", null);
check('not signed in: no zip', substr($body, 0, 2) !== 'PK');
list($code) = http('GET', "/field_camp_package?id=99999999", $sidI);
check('an unknown camp gets 404', $code === 404, $code);

// ===========================================================================
cleanup();
foreach ($sids as $sid) @unlink("$SESS/sess_$sid");

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail === 0 ? 0 : 1);
