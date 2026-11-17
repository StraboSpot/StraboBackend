<?php
/**
 * File: tests/fieldcamp/areas_test.php
 * Description: Several study areas per field camp (2026-11-17): checking and
 *              saving the areas, a camp saved with one box, tile counts with
 *              overlaps counted once (against the tiles a build really keeps),
 *              the offline maps going out of date, rock units looked up per
 *              area and merged, maps overlapping any area, and the Study area
 *              step over HTTP.
 *
 *              Run inside the web container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/areas_test.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'db/strabospotclass.php';
require_once 'includes/fieldcamp/FieldCamp.php';
require_once 'includes/fieldcamp/FieldCampUnits.php';
require_once 'includes/fieldcamp/FieldCampPackage.php';
require_once 'includes/fieldcamp/FieldCampTiles.php';

$INSTR = 94861; $OTHER = 94862;
$FIX = array($INSTR => 'fcareas-instructor@example.org', $OTHER => 'fcareas-other@example.org');
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$TOKEN = str_repeat('a7e3', 8);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function rmtree($dir) {
	if (!is_dir($dir)) return;
	foreach (scandir($dir) as $f) { if ($f === '.' || $f === '..') continue; $p = "$dir/$f"; if (is_dir($p)) rmtree($p); else @unlink($p); }
	@rmdir($dir);
}
function cleanup() {
	global $db, $FIX;
	$fixpkeys = implode(',', array_keys($FIX));
	$rows = $db->get_results_prepared("select pkey, files_token from fieldcamp where owner_user_pkey in ($fixpkeys)", array());
	if ($rows) {
		foreach ($rows as $r) {
			$token = preg_replace('/[^a-f0-9]/', '', (string)$r->files_token);
			if (strlen($token) === 32) rmtree("/srv/app/www/fieldcamp_files/$token");
		}
		$in = implode(',', array_map(function ($r) { return (int)$r->pkey; }, $rows));
		foreach (array('fieldcamp_map', 'fieldcamp_unit', 'fieldcamp_unit_source', 'fieldcamp_viewer') as $t) $db->query("delete from $t where fieldcamp_pkey in ($in)");
		$db->query("delete from fieldcamp where pkey in ($in)");
	}
	$db->query("delete from geotiffs where hash like 'fcareastest%'");
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fixpkeys)");
	$db->query("delete from users where pkey in ($fixpkeys)");
}
$sids = array();
function forge($pkey, $email) {
	global $SESS, $sids, $TOKEN;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	file_put_contents("$SESS/sess_$sid", 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';'
		. 'fieldcamp_csrf|s:' . strlen($TOKEN) . ':"' . $TOKEN . '";');
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
function http($method, $path, $sid, $post = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true,
		CURLOPT_HTTPHEADER => $sid ? array('Cookie: PHPSESSID=' . $sid) : array()));
	if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$loc = preg_match('/^Location:\s*(.+)$/mi', substr((string)$raw, 0, $hs), $m) ? trim($m[1]) : '';
	return array($code, substr((string)$raw, $hs), $loc);
}
function post_follow($sid, $fields) {
	global $TOKEN;
	list($code, $body, $loc) = http('POST', '/field_camp_action', $sid, $fields + array('csrf' => $TOKEN));
	if ($loc === '') return array($code, $body, $loc);
	list($c2, $b2) = http('GET', $loc, $sid);
	return array($c2, $b2, $loc);
}
function noisy($body) { return preg_match('~<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>~', $body) === 1; }
function new_camp($name) {
	global $db, $INSTR;
	list($clean) = FieldCamp::validateBasics(array('camp_name' => $name, 'camp_year' => '2027', 'project_name' => "$name Project"));
	return FieldCamp::createCamp($db, $INSTR, $clean);
}
function areas_json($list) { return json_encode($list); }

// Tile arithmetic of the tile server, written out again here so the counts are checked against tiles, not against themselves.
function lon2x($lon, $z) { return (int)floor(($lon + 180) / 360 * pow(2, $z)); }
function lat2y($lat, $z) { $y = (int)floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) / 2 * (1 << $z)); return max(0, min($y, (1 << $z) - 1)); }
/** Every tile name the tile server sends for one box down to $zoom (the levels above are the zoom-level range shifted). */
function tiles_of($box, $zoom) {
	$x0 = min(lon2x($box['west'], $zoom), lon2x($box['east'], $zoom)); $x1 = max(lon2x($box['west'], $zoom), lon2x($box['east'], $zoom));
	$y0 = min(lat2y($box['south'], $zoom), lat2y($box['north'], $zoom)); $y1 = max(lat2y($box['south'], $zoom), lat2y($box['north'], $zoom));
	$out = array('0_0_0' => true);
	for ($l = 1; $l <= $zoom; $l++) {
		$sh = $zoom - $l; $last = (1 << $l) - 1;
		for ($x = min($x0 >> $sh, $last); $x <= min($x1 >> $sh, $last); $x++)
			for ($y = min($y0 >> $sh, $last); $y <= min($y1 >> $sh, $last); $y++) $out["{$l}_{$x}_{$y}"] = true;
	}
	return $out;
}
function union_count($boxes, $zoom) { $all = array(); foreach ($boxes as $b) $all += tiles_of($b, $zoom); return count($all); }

/** Tile server stand-in: each zip holds the real tile names of the requested extent. $emptyFor: extents that get no tiles. */
$asked = array();
function tile_stub($emptyFor = array()) {
	global $asked;
	$asked = array(); $state = array();
	FieldCampTiles::$pollSeconds = 0;
	FieldCampTiles::$http = function ($url, $toFile) use (&$state, $emptyFor) {
		global $asked;
		$asked[] = $url;
		if (strpos($url, '/asynczip?') !== false) {
			parse_str(parse_url($url, PHP_URL_QUERY), $q);
			$uid = 'uid' . count($state) . 'x';
			$state[$uid] = array('extent' => $q['extent'], 'zoom' => (int)$q['zoom']);
			return array(200, json_encode(array('id' => $uid)));
		}
		if (preg_match('~/asyncstatus/(\w+)$~', $url, $m)) return array(200, json_encode(array('status' => 'Zip File Ready.', 'percent' => 100)));
		if (preg_match('~/ziptemp/(\w+)/\1\.zip$~', $url, $m)) {
			$s = $state[$m[1]];
			$e = array_map('floatval', explode(',', $s['extent']));
			$zip = new ZipArchive(); $zip->open($toFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
			$zip->addFromString($m[1] . '/details.json', '{}');
			if (!in_array($s['extent'], $emptyFor, true)) {
				foreach (array_keys(tiles_of(array('west' => $e[0], 'south' => $e[1], 'east' => $e[2], 'north' => $e[3]), $s['zoom'])) as $t)
					$zip->addFromString($m[1] . "/tiles/$t.png", "PNG $t");
			}
			$zip->close();
			return array(200, '');
		}
		return array(404, '');
	};
}
function build_now($db, $campid) {
	$db->prepare_query("update fieldcamp set build_status = 'building', build_started = now(), build_message = 'Starting...' where pkey = $1", array($campid));
	$camp = FieldCamp::getCamp($db, $campid);
	return FieldCampTiles::build($db, $camp, FieldCampTiles::layers(FieldCampPackage::customMapsForCamp($db, $camp)));
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Areas', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fcareashash' . $pk, $email));
}
FieldCamp::addInstructor($db, $INSTR, 'areas test', 3);

$A1 = array('west' => -105.40, 'south' => 38.50, 'east' => -105.30, 'north' => 38.56);   // the mapping area
$A2 = array('west' => -105.33, 'south' => 38.53, 'east' => -105.25, 'north' => 38.60);   // overlaps A1
$A3 = array('west' => -106.20, 'south' => 39.10, 'east' => -106.12, 'north' => 39.15);   // a day trip, apart

// ===========================================================================
section('Checking the areas the step sends');
list($v, $errs) = FieldCamp::validateAreas(array('areas_json' => areas_json(array(array('name' => '  Mapping   area ') + $A1, array('name' => '') + $A3))));
check('two areas pass; names are trimmed, an empty name becomes "Area N"', !$errs && count($v) === 2 && $v[0]['name'] === 'Mapping area' && $v[1]['name'] === 'Area 2'
	&& abs($v[1]['west'] + 106.2) < 1e-9, array($v, $errs));
list($v) = FieldCamp::validateAreas(array('areas_json' => areas_json(array(array('name' => str_repeat('x', 80)) + $A1))));
check('a long name is cut to ' . FieldCamp::AREA_NAME_MAX . ' characters', mb_strlen($v[0]['name']) === FieldCamp::AREA_NAME_MAX);
$eleven = array(); for ($i = 0; $i < 11; $i++) $eleven[] = array('name' => "A$i") + $A1;
list($v, $errs) = FieldCamp::validateAreas(array('areas_json' => areas_json($eleven)));
check('more than ' . FieldCamp::MAX_AREAS . ' areas are refused', count($errs) === 1 && strpos($errs[0], 'at most 10') !== false, $errs);
list($v, $errs) = FieldCamp::validateAreas(array('areas_json' => areas_json(array_slice($eleven, 0, 10))));
check('ten areas pass', !$errs && count($v) === 10);
list($v, $errs) = FieldCamp::validateAreas(array('areas_json' => '[]'));
check('no area at all is refused', count($errs) === 1 && strpos($errs[0], 'at least one') !== false);
list($v, $errs) = FieldCamp::validateAreas(array('areas_json' => '{"a":1}'));
check('something that is not a list is refused', count($errs) === 1 && strpos($errs[0], 'could not be read') !== false);
list($v, $errs) = FieldCamp::validateAreas(array('areas_json' => areas_json(array(array('name' => 'Good') + $A1, array('name' => 'Flipped', 'west' => -105, 'south' => 38, 'east' => -106, 'north' => 39)))));
check('a bad box is refused, and the message names it', count($errs) === 1 && strpos($errs[0], 'Flipped: ') === 0, $errs);
list($v, $errs) = FieldCamp::validateAreas(array('west' => '-105.4', 'south' => '38.5', 'east' => '-105.3', 'north' => '38.56'));
check('the old form of the post (one box, no areas_json) is one area', !$errs && count($v) === 1 && $v[0]['name'] === 'Area 1');

// ===========================================================================
section('Saving, and camps saved with one box');
$campid = new_camp('Areas Camp');
$camp = FieldCamp::getCamp($db, $campid);
check('a new camp has no areas', FieldCampUnits::campAreas($camp) === array());
FieldCamp::saveAreas($db, $campid, array(array('name' => 'Mapping area') + $A1, array('name' => 'Day trip') + $A3));
$camp = FieldCamp::getCamp($db, $campid);
$areas = FieldCampUnits::campAreas($camp);
check('the areas come back in order with their names', count($areas) === 2 && $areas[0]['name'] === 'Mapping area' && $areas[1]['name'] === 'Day trip' && abs($areas[1]['north'] - 39.15) < 1e-9, $areas);
check('bbox_* is the extent around all of them', abs((float)$camp->bbox_west + 106.20) < 1e-9 && abs((float)$camp->bbox_south - 38.50) < 1e-9
	&& abs((float)$camp->bbox_east + 105.30) < 1e-9 && abs((float)$camp->bbox_north - 39.15) < 1e-9);
$db->prepare_query("update fieldcamp set study_areas = null where pkey = $1", array($campid));
$areas = FieldCampUnits::campAreas(FieldCamp::getCamp($db, $campid));
check('a camp saved before (no study_areas) reads as one area, "Area 1", from bbox_*', count($areas) === 1 && $areas[0]['name'] === 'Area 1' && abs($areas[0]['west'] + 106.20) < 1e-9);
check('one area\'s string is what bboxString gave before (so built tiles and looked-up units stay current)',
	FieldCampUnits::areasString(array(array('name' => 'x') + $A1)) === FieldCampUnits::bboxString($A1));
FieldCamp::saveArea($db, $campid, $A1);
check('saveArea (one box) still works', count(FieldCampUnits::campAreas(FieldCamp::getCamp($db, $campid))) === 1);
check('overlapsAny', FieldCampUnits::overlapsAny(array('west' => -106.15, 'south' => 39.12, 'east' => -106.0, 'north' => 39.2), array($A1, $A3))
	&& !FieldCampUnits::overlapsAny(array('west' => -100, 'south' => 30, 'east' => -99, 'north' => 31), array($A1, $A3)));

// ===========================================================================
section('Tile counts: overlaps once');
foreach (array(10, 13, 15) as $z) {
	check("one area at zoom $z: as before", FieldCampTiles::tileCountAreas(array($A1), $z) === FieldCampTiles::tileCount($A1, $z) && FieldCampTiles::tileCount($A1, $z) === union_count(array($A1), $z));
}
check('two overlapping areas: fewer than the two counts added, exactly the tiles they cover', FieldCampTiles::tileCountAreas(array($A1, $A2), 14) === union_count(array($A1, $A2), 14)
	&& FieldCampTiles::tileCountAreas(array($A1, $A2), 14) < FieldCampTiles::tileCount($A1, 14) + FieldCampTiles::tileCount($A2, 14), array(FieldCampTiles::tileCountAreas(array($A1, $A2), 14), union_count(array($A1, $A2), 14)));
check('the same area twice counts once', FieldCampTiles::tileCountAreas(array($A1, $A1), 14) === FieldCampTiles::tileCount($A1, 14));
check('three areas, one apart', FieldCampTiles::tileCountAreas(array($A1, $A2, $A3), 15) === union_count(array($A1, $A2, $A3), 15));
mt_srand(7); $bad = array();
for ($i = 0; $i < 60; $i++) {
	$boxes = array();
	for ($j = 0, $n = mt_rand(1, 4); $j < $n; $j++) {
		$w = -105.6 + mt_rand(0, 400) / 1000; $s = 38.3 + mt_rand(0, 300) / 1000;
		$boxes[] = array('west' => $w, 'south' => $s, 'east' => $w + mt_rand(5, 150) / 1000, 'north' => $s + mt_rand(5, 120) / 1000);
	}
	$z = mt_rand(9, 14);
	if (FieldCampTiles::tileCountAreas($boxes, $z) !== union_count($boxes, $z)) $bad[] = array($boxes, $z);
}
check('60 random sets of up to four boxes: the count is the tiles they cover', !$bad, $bad);
$opts = FieldCampTiles::options(array($A1, $A3), array_values(FieldCampTiles::layers(array())));
$row14 = null; foreach ($opts as $o) if ($o['zoom'] === 14) $row14 = $o;
check('the zoom choices count every area', $row14 && $row14['per_map'] === union_count(array($A1, $A3), 14), $row14);
check('options() still takes one area as before', FieldCampTiles::options($A1, array_values(FieldCampTiles::layers(array())))[0]['per_map'] === FieldCampTiles::tileCount($A1, 11));

// ===========================================================================
section('Building offline maps for several areas');
FieldCamp::saveAreas($db, $campid, array(array('name' => 'Mapping area') + $A1, array('name' => 'North') + $A2, array('name' => 'Day trip') + $A3));
$camp = FieldCamp::getCamp($db, $campid);
$layers = FieldCampTiles::layers(array());
$firstId = array_keys($layers)[0];
FieldCampTiles::saveChoice($db, $camp, 12, array($firstId));
tile_stub();
$out = build_now($db, $campid);
$camp = FieldCamp::getCamp($db, $campid);
$manifest = FieldCampTiles::manifest($camp);
$zips = count(array_filter($asked, function ($u) { return strpos($u, '/asynczip?') !== false; }));
check('one request per area for the map', $out['error'] === '' && $zips === 3, array($out, $zips));
check('the built map holds each tile once: the count the step showed', $manifest && (int)$manifest['layers'][0]['count'] === union_count(array($A1, $A2, $A3), 12)
	&& (int)$manifest['layers'][0]['count'] === FieldCampTiles::tileCountAreas(array($A1, $A2, $A3), 12), $manifest['layers'][0] ?? null);
$z = new ZipArchive(); $z->open(FieldCampTiles::tilesZipPath($camp));
check('and so does OfflineTiles.zip', $z->numFiles === union_count(array($A1, $A2, $A3), 12), $z->numFiles); $z->close();
check('the manifest records every area', $manifest['bbox'] === FieldCampUnits::areasString(FieldCampUnits::campAreas($camp)) && substr_count($manifest['bbox'], ';') === 2);
check('the built tiles are current', FieldCampTiles::outdated($camp, $manifest, $layers) === '');
FieldCamp::saveAreas($db, $campid, array(array('name' => 'Mapping area') + $A1, array('name' => 'North') + $A2));
$camp = FieldCamp::getCamp($db, $campid);
check('deleting an area makes them out of date', FieldCampTiles::outdated($camp, $manifest, $layers) === "The study area has changed since the tiles were built.");
FieldCamp::saveAreas($db, $campid, array(array('name' => 'Renamed') + $A1, array('name' => 'Also renamed') + $A2, array('name' => 'Day trip') + $A3));
$camp = FieldCamp::getCamp($db, $campid);
check('renaming areas does not', FieldCampTiles::outdated($camp, $manifest, $layers) === '');
tile_stub(array(FieldCampUnits::bboxString($A3)));
$out = build_now($db, $campid);
$manifest = FieldCampTiles::manifest(FieldCamp::getCamp($db, $campid));
check('a map with no tiles in one area is still built, from the others', $out['error'] === '' && !$out['skipped'] && (int)$manifest['layers'][0]['count'] === union_count(array($A1, $A2), 12), $out);
tile_stub(array(FieldCampUnits::bboxString($A1), FieldCampUnits::bboxString($A2), FieldCampUnits::bboxString($A3)));
$out = build_now($db, $campid);
check('a map with no tiles anywhere is skipped as before', count($out['skipped']) === 1 && $out['skipped'][0]['reason'] === 'the map server sent no tiles', $out);
FieldCampTiles::$http = null; FieldCampTiles::$pollSeconds = 2;

// ===========================================================================
section('Rock units: one lookup per area, merged');
$calls = array();
FieldCampUnits::$fetcher = function ($url) use (&$calls, $A3) {
	$calls[] = $url;
	if (strpos($url, FieldCampUnits::SOURCE_URL) === 0) return array(200, json_encode(array('name' => 'Source ' . basename($url))));
	parse_str(parse_url($url, PHP_URL_QUERY), $q);
	$row = function ($legend, $source, $name) { return array('legend_id' => $legend, 'source_id' => $source, 'map_unit_name' => $name, 'age' => 'Cretaceous', 'lith' => 'shale', 'descrip' => ''); };
	if ($q['bounds'] === FieldCampUnits::bboxString($A3)) return array(200, json_encode(array($row(30, 2, 'Pierre Shale'), $row(31, 2, 'Niobrara'))));
	return array(200, json_encode(array($row(10, 1, 'Dakota'), $row(30, 2, 'Pierre Shale'))));
};
$lk = FieldCampUnits::lookupAreas(array(array('name' => 'Mapping area') + $A1, array('name' => 'Day trip') + $A3));
$names = array_column($lk['units'], 'map_unit_name');
check('each area asked once; a unit found in both is listed once', $lk['error'] === '' && count(array_filter($calls, function ($u) { return strpos($u, 'legend') !== false; })) === 2
	&& $names === array('Dakota', 'Pierre Shale', 'Niobrara'), $names);
check('both source maps are kept', count($lk['sources']) === 2 && isset($lk['sources'][1], $lk['sources'][2]));
check('the lookup records every area', $lk['bbox'] === FieldCampUnits::bboxString($A1) . ';' . FieldCampUnits::bboxString($A3));
$big = array('name' => 'Big', 'west' => -108, 'south' => 37, 'east' => -104, 'north' => 40);
$lk = FieldCampUnits::lookupAreas(array(array('name' => 'Small') + $A1, $big));
check('the zoom kept is the least detailed one used', $lk['zoom'] === FieldCampUnits::zoomFor($big) && $lk['zoom'] < FieldCampUnits::DETAIL_ZOOM && !$lk['detailed']);
FieldCampUnits::$fetcher = function ($url) use ($A3) {
	parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
	if (($q['bounds'] ?? '') === FieldCampUnits::bboxString($A3)) return array(500, 'down');
	return array(200, '[]');
};
$lk = FieldCampUnits::lookupAreas(array(array('name' => 'Mapping area') + $A1, array('name' => 'Day trip') + $A3));
check('a failed lookup names the area', strpos($lk['error'], 'Day trip: ') === 0, $lk['error']);
FieldCampUnits::$fetcher = null;
$db->prepare_query("update fieldcamp set units_bbox = $2 where pkey = $1", array($campid, FieldCampUnits::areasString(FieldCampUnits::campAreas(FieldCamp::getCamp($db, $campid)))));
check('units looked up for these areas are current', !FieldCampUnits::areaChanged(FieldCamp::getCamp($db, $campid)));
FieldCamp::saveAreas($db, $campid, array(array('name' => 'Mapping area') + $A1));
check('dropping an area makes them out of date', FieldCampUnits::areaChanged(FieldCamp::getCamp($db, $campid)));
check('units_bbox takes a long list of areas (TEXT)', (function () use ($db, $campid, $eleven) {
	$s = FieldCampUnits::areasString(array_slice($eleven, 0, 10));
	$db->prepare_query("update fieldcamp set units_bbox = $2 where pkey = $1", array($campid, $s));
	return (string)FieldCamp::getCamp($db, $campid)->units_bbox === $s && strlen($s) > 120;
})());

// ===========================================================================
section('Maps step: a map in any area is in the study areas');
$db->prepare_query("insert into geotiffs (userpkey, hash, name, gdalinfo) values ($1, 'fcareastest01', 'Day_Trip_Map.tif', $2)", array($INSTR,
	"Corner Coordinates:\nUpper Left  (-106.1800000,  39.1400000) (106d10'48.00\"W, 39d 8'24.00\"N)\nLower Left  (-106.1800000,  39.1100000) (106d10'48.00\"W, 39d 6'36.00\"N)\n"
	. "Upper Right (-106.1300000,  39.1400000) (106d 7'48.00\"W, 39d 8'24.00\"N)\nLower Right (-106.1300000,  39.1100000) (106d 7'48.00\"W, 39d 6'36.00\"N)\n"));
FieldCamp::saveAreas($db, $campid, array(array('name' => 'Mapping area') + $A1));
$m = array_values(array_filter(FieldCampPackage::listMyMaps($db, FieldCamp::getCamp($db, $campid)), function ($x) { return $x['hash'] === 'fcareastest01'; }));
check('with the mapping area only, the day-trip map is outside', $m && $m[0]['overlaps'] === false);
FieldCamp::saveAreas($db, $campid, array(array('name' => 'Mapping area') + $A1, array('name' => 'Day trip') + $A3));
$m = array_values(array_filter(FieldCampPackage::listMyMaps($db, FieldCamp::getCamp($db, $campid)), function ($x) { return $x['hash'] === 'fcareastest01'; }));
check('with the day trip added, it is inside', $m && $m[0]['overlaps'] === true);

// ===========================================================================
section('The Study area step over HTTP');
$sid = forge($INSTR, $FIX[$INSTR]);
$sidO = forge($OTHER, $FIX[$OTHER]);
$hid = new_camp('HTTP Areas Camp');
list($code, $body) = http('GET', "/field_camp?id=$hid&step=area", $sid);
check('a camp without areas: the step offers to add one, saves on Next', $code === 200 && !noisy($body) && strpos($body, 'id="fcDrawBtn">Add a study area</button>') !== false
	&& strpos($body, 'No study area yet.') !== false && strpos($body, 'name="areas_json"') !== false && strpos($body, 'id="fcAreaForm" data-fc-save="area"') !== false
	&& strpos($body, 'data-fc-next="maps">Next: Maps') !== false && strpos($body, 'var saved = [];') !== false);
list($code, $body, $loc) = post_follow($sid, array('action' => 'area', 'id' => $hid, 'goto' => 'area',
	'areas_json' => areas_json(array(array('name' => 'Mapping <area>') + $A1, array('name' => 'Day trip') + $A3))));
check('two areas are saved', strpos($body, '2 study areas saved.') !== false && count(FieldCampUnits::campAreas(FieldCamp::getCamp($db, $hid))) === 2, $loc);
check('the page holds them, names escaped for the script', strpos($body, '"name":"Mapping ' . "\\u003Carea\\u003E" . '"') !== false && strpos($body, '"name":"Day trip"') !== false && strpos($body, '<area>') === false);
list($code, $body) = post_follow($sid, array('action' => 'area', 'id' => $hid, 'areas_json' => '[]'));
check('saving no area is refused and the saved ones stay', strpos($body, 'Draw at least one study area on the map.') !== false && count(FieldCampUnits::campAreas(FieldCamp::getCamp($db, $hid))) === 2);
list($code, $body) = post_follow($sid, array('action' => 'area', 'id' => $hid, 'areas_json' => areas_json($eleven)));
check('eleven are refused', strpos($body, 'at most 10 study areas') !== false && count(FieldCampUnits::campAreas(FieldCamp::getCamp($db, $hid))) === 2);
list($code, $body) = post_follow($sid, array('action' => 'area', 'id' => $hid, 'west' => '-105.4', 'south' => '38.5', 'east' => '-105.3', 'north' => '38.56'));
check('the old post (one box) still saves one area', strpos($body, 'Study area saved.') !== false && count(FieldCampUnits::campAreas(FieldCamp::getCamp($db, $hid))) === 1);
list($code) = post_follow($sidO, array('action' => 'area', 'id' => $hid, 'areas_json' => areas_json(array(array('name' => 'x') + $A3))));
check('someone else cannot change the areas', $code === 404 && count(FieldCampUnits::campAreas(FieldCamp::getCamp($db, $hid))) === 1, $code);
post_follow($sid, array('action' => 'area', 'id' => $hid, 'areas_json' => areas_json(array(array('name' => 'Mapping area') + $A1, array('name' => 'Day trip') + $A3))));
foreach (array('maps' => 'Maps that cover the study areas', 'tiles' => 'The outlines are the study areas', 'units' => 'Find the rock units of the study areas') as $stp => $needle) {
	list($code, $body) = http('GET', "/field_camp?id=$hid&step=$stp", $sid);
	check("the $stp step speaks of the study areas", $code === 200 && !noisy($body) && strpos($body, $needle) !== false, $stp);
}
list($code, $body) = http('GET', "/field_camp?id=$hid&step=tiles", $sid);
check('the offline maps step has every area for its preview', strpos($body, '"areas":[{"name":"Mapping area"') !== false && strpos($body, '"name":"Day trip"') !== false);
list($code, $body) = http('GET', "/field_camp_wizard", $sid);
check('the camp list says how many areas', !noisy($body) && strpos($body, '2 study areas') !== false);

// ===========================================================================
cleanup();
foreach ($sids as $s) @unlink("$SESS/sess_$s");
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
