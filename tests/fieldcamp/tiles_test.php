<?php
/**
 * File: tests/fieldcamp/tiles_test.php
 * Description: Suite for the Field Camp Wizard, stage 6: offline map tiles
 *              (includes/fieldcamp/FieldCampTiles.php, build_tiles.php, the
 *              Offline maps step of field_camp.php, tiles_build in
 *              field_camp_action.php, and the tiles inside the package).
 *
 *              Part 1 is arithmetic: tile counts (the tile server's own
 *              rule), the scale, the zoom choices and limits.
 *              Part 2 builds with the tile server replaced by a stand-in that
 *              answers asynczip / asyncstatus / ziptemp the way StraboTiles
 *              does (zip of <uid>/tiles/<z>_<x>_<y>.png), including maps it
 *              refuses and a zip with files that must not get through.
 *              Part 3 checks the package: mapNamesDb against the app's own
 *              backup (fixtures/app_backup_data.json), and the tile zip read
 *              the way the app's import reads it (useImport.js moveFiles).
 *              Part 4 goes through the real Apache.
 *              Part 5 asks the real tile server (skipped when unreachable):
 *              its tile count against ours, and one small build started from
 *              the page.
 *
 *              Fixture users 94731-94732, My Maps hash "fctilestest01";
 *              everything the suite makes is removed again, the built files
 *              in fieldcamp_files/ included.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/tiles_test.php
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
require_once 'includes/fieldcamp/FieldCampTiles.php';

$INSTR = 94731; $OUTSIDER = 94732;
$FIX = array($INSTR => 'fctiles-instructor@example.org', $OUTSIDER => 'fctiles-outsider@example.org');
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$TOKEN = str_repeat('9a1b', 8);
$FIXDIR = __DIR__ . '/fixtures';

$pass = 0; $fail = 0; $skip = 0;
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
		foreach (array('fieldcamp_map', 'fieldcamp_unit', 'fieldcamp_unit_source') as $t) $db->query("delete from $t where fieldcamp_pkey in ($in)");
		$db->query("delete from fieldcamp where pkey in ($in)");
	}
	$db->query("delete from geotiffs where hash like 'fctilestest%'");
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
function kind($v) {
	if (is_object($v)) return 'object';
	if (is_array($v)) return 'list';
	if (is_string($v)) return 'string';
	if (is_int($v) || is_float($v)) return 'number';
	if (is_bool($v)) return 'boolean';
	return 'null';
}
function shape_diff($ours, $theirs) {
	$a = array_keys(get_object_vars($ours)); $b = array_keys(get_object_vars($theirs));
	$out = array();
	foreach (array_diff($b, $a) as $k) $out[] = "missing $k";
	foreach (array_diff($a, $b) as $k) $out[] = "extra $k";
	foreach (array_intersect($a, $b) as $k) { if (kind($ours->$k) !== kind($theirs->$k)) $out[] = "$k is " . kind($ours->$k) . ", the app writes " . kind($theirs->$k); }
	return $out;
}
function new_camp($name, $bbox) {
	global $db, $INSTR;
	list($clean) = FieldCamp::validateBasics(array('camp_name' => $name, 'camp_year' => '2027', 'project_name' => "$name Project"));
	$id = FieldCamp::createCamp($db, $INSTR, $clean);
	if ($bbox) FieldCamp::saveArea($db, $id, $bbox);
	return $id;
}

/**
 * Tile server stand-in. $behave[layer or map id] = 'ok' (default) | 'refuse' (asynczip answers 500) |
 * 'error' (status reports an error) | 'empty' (zip without tiles) | 'nodownload' (zip 404).
 * Each zip holds $tilesPerMap tile files plus files that must never be unpacked.
 */
$asked = array();
function tile_stub($behave = array(), $tilesPerMap = 5) {
	global $asked;
	$asked = array();
	$state = array();
	FieldCampTiles::$pollSeconds = 0;
	FieldCampTiles::$http = function ($url, $toFile) use (&$state, $behave, $tilesPerMap) {
		global $asked;
		$asked[] = $url;
		if (strpos($url, '/asynczip?') !== false) {
			parse_str(parse_url($url, PHP_URL_QUERY), $q);
			$key = $q['layer'] === 'strabomymaps' ? $q['id'] : $q['layer'];
			$mode = $behave[$key] ?? 'ok';
			if ($mode === 'refuse') return array(500, 'no');
			$uid = 'uid' . count($state) . 'x';
			$state[$uid] = array('mode' => $mode, 'polls' => 0, 'zoom' => (int)$q['zoom']);
			return array(200, json_encode(array('id' => $uid)));
		}
		if (preg_match('~/asyncstatus/(\w+)$~', $url, $m)) {
			$s = &$state[$m[1]];
			$s['polls']++;
			if ($s['mode'] === 'error') return array(200, json_encode(array('uid' => $m[1], 'status' => 'Error: Invalid zoom (99) provided.', 'percent' => 0)));
			if ($s['polls'] === 1) return array(200, json_encode(array('uid' => $m[1], 'status' => 'Gathering Tiles...', 'percent' => 40, 'tilecount' => 5)));
			return array(200, json_encode(array('uid' => $m[1], 'status' => 'Zip File Ready.', 'percent' => 100, 'tilecount' => 5)));
		}
		if (preg_match('~/ziptemp/(\w+)/\1\.zip$~', $url, $m)) {
			$s = $state[$m[1]];
			if ($s['mode'] === 'nodownload') return array(404, '');
			$zip = new ZipArchive(); $zip->open($toFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
			$zip->addFromString($m[1] . '/details.json', '{}');
			if ($s['mode'] !== 'empty') {
				for ($i = 0; $i < $tilesPerMap; $i++) $zip->addFromString($m[1] . '/tiles/' . $s['zoom'] . "_{$i}_7.png", "\x89PNG fake tile $i of " . $m[1]);
			}
			$zip->addFromString($m[1] . '/tiles/evil.php', '<?php echo 1;');
			$zip->addFromString($m[1] . '/tiles/../../escape.png', 'x');
			$zip->addFromString('../outside.png', 'x');
			$zip->addFromString($m[1] . '/tiles/sub/3_1_1.png', 'x');
			$zip->close();
			return array(200, '');
		}
		return array(404, '');
	};
}
function build_now($db, $campid) {
	// What startBuild does, without the background process: mark as building, then build here.
	$db->prepare_query("update fieldcamp set build_status = 'building', build_started = now(), build_message = 'Starting...' where pkey = $1", array($campid));
	$camp = FieldCamp::getCamp($db, $campid);
	return FieldCampTiles::build($db, $camp, FieldCampTiles::layers(FieldCampPackage::customMapsForCamp($db, $camp)));
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Tiles', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fctileshash' . $pk, $email));
}
FieldCamp::addInstructor($db, $INSTR, 'tiles test', 3);
$db->prepare_query("insert into geotiffs (userpkey, hash, name, gdalinfo) values ($1, 'fctilestest01', 'Canon_City_Map.tif', $2)", array($INSTR,
	"Corner Coordinates:\nUpper Left  (-105.3113443,  38.5058905) (105d18'40.84\"W, 38d30'21.21\"N)\nLower Left  (-105.3113443,  38.4893179) (105d18'40.84\"W, 38d29'21.54\"N)\nUpper Right (-105.2588832,  38.5058905) (105d15'31.98\"W, 38d30'21.21\"N)\nLower Right (-105.2588832,  38.4893179) (105d15'31.98\"W, 38d29'21.54\"N)\n"));

// Jason's dev camp near Canon City, Colorado: about 20 km by 8 km.
$AREA = array('west' => -105.389271, 'south' => 38.517549, 'east' => -105.159931, 'north' => 38.587088);

// ===========================================================================
section('Counting tiles');
check('columns installed', FieldCampTiles::installed($db));
check('zoom 0 is one tile', FieldCampTiles::tileCount($AREA, 0) === 1);
check('zoom 1 is the world tile and one more', FieldCampTiles::tileCount($AREA, 1) === 2);
check('the camp area at zoom 15: 303 tiles (the tile server\'s own answer, 11-2026)', FieldCampTiles::tileCount($AREA, 15) === 303, FieldCampTiles::tileCount($AREA, 15));
check('zoom 16: 1,077', FieldCampTiles::tileCount($AREA, 16) === 1077, FieldCampTiles::tileCount($AREA, 16));
$small = array('west' => -105.30, 'south' => 38.49, 'east' => -105.26, 'north' => 38.51);
check('a 3 km area at zoom 14: 23 tiles (a real build delivered 23 per map)', FieldCampTiles::tileCount($small, 14) === 23, FieldCampTiles::tileCount($small, 14));
check('each deeper zoom costs between 2 and 4.5 times the tiles', (function () use ($AREA) {
	for ($z = 13; $z < 18; $z++) { $r = FieldCampTiles::tileCount($AREA, $z + 1) / FieldCampTiles::tileCount($AREA, $z); if ($r < 2 || $r > 4.5) return false; }
	return true;
})());
check('the southern and eastern hemispheres count too', FieldCampTiles::tileCount(array('west' => 150.5, 'south' => -34.25, 'east' => 150.7, 'north' => -34.15), 14) > 20);
check('the scale is the one the app shows (zoom 15 at 38.55 N: 1:14,120)', FieldCampTiles::scale(38.5523185, 15) === 14120, FieldCampTiles::scale(38.5523185, 15));
check('at the equator, zoom 0, a 256 px world on a 96 dpi screen: about 1:591,658,700', abs(FieldCampTiles::scale(0, 0) - 591658700) < 50, FieldCampTiles::scale(0, 0));

// ===========================================================================
section('Maps and zoom choices');
$basemaps = FieldCampTiles::layers(array());
check('four default basemaps with the app\'s ids and names', array_keys($basemaps) === array('mapbox.outdoors', 'mapbox.satellite', 'macrostrat', 'usgs.hillshade')
	&& $basemaps['mapbox.outdoors']['name'] === 'Mapbox Topo' && $basemaps['macrostrat']['name'] === 'Geology from macrostrat' && $basemaps['usgs.hillshade']['custom'] === false);
$withMap = FieldCampTiles::layers(array(FieldCampPackage::customMap('fctilestest01', 'Canon City Map', '-105.31,38.49,-105.26,38.51')));
check('the camp\'s custom maps follow', array_keys($withMap)[4] === 'fctilestest01' && $withMap['fctilestest01']['name'] === 'Canon City Map' && $withMap['fctilestest01']['custom'] === true && $withMap['fctilestest01']['bbox'] === '-105.31,38.49,-105.26,38.51');

$opts = FieldCampTiles::options($AREA, array_values($basemaps));
check('six zoom choices, 11 to 16', array_column($opts, 'zoom') === array(11, 12, 13, 14, 15, 16));
check('tiles in all = tiles per map x maps', $opts[4]['per_map'] === 303 && $opts[4]['total'] === 1212 && $opts[5]['total'] === 4308);
check('the size is an estimate from typical tiles (zoom 15, four basemaps: 20 to 35 MB)', $opts[4]['bytes'] > 20e6 && $opts[4]['bytes'] < 35e6, $opts[4]['bytes']);
check('every zoom is allowed for a camp-sized area', count(array_filter($opts, function ($o) { return $o['allowed']; })) === 6);
check('the default is the deepest zoom that keeps a map at 1,500 tiles or fewer: 16 here', FieldCampTiles::defaultZoom($opts) === 16);
$big = array('west' => -106.0, 'south' => 38.0, 'east' => -105.0, 'north' => 39.0);   // about 87 km by 111 km
$bigOpts = FieldCampTiles::options($big, array_values($basemaps));
$byZoom = array(); foreach ($bigOpts as $o) $byZoom[$o['zoom']] = $o;
check('a large area: deep zooms are refused with the reason', $byZoom[16]['allowed'] === false && strpos($byZoom[16]['why'], 'tiles per map') !== false && $byZoom[11]['allowed'] === true, $byZoom[16]);
check('and its default zoom is shallower', FieldCampTiles::defaultZoom($bigOpts) < 16 && FieldCampTiles::defaultZoom($bigOpts) >= 11, FieldCampTiles::defaultZoom($bigOpts));
// The limit on all tiles together only bites with more than four maps (4 x 6,000 is under 25,000): look for an
// area whose zoom 16 takes between 5,001 and 6,000 tiles per map, then count it with five maps and with one.
$mid = null;
for ($w = 0.30; $w < 0.60 && $mid === null; $w += 0.005) {
	$try = array('west' => -105.6, 'south' => 38.3, 'east' => -105.6 + $w, 'north' => 38.3 + $w * 0.7);
	$n = FieldCampTiles::tileCount($try, 16);
	if ($n > 5000 && $n <= 6000) $mid = $try;
}
$five = $mid ? FieldCampTiles::options($mid, array_values($withMap)) : array();
$one = $mid ? FieldCampTiles::options($mid, array($basemaps['macrostrat'])) : array();
check('with five maps the limit on all tiles together refuses a zoom that one map may have', $mid !== null && $five[5]['allowed'] === false && strpos($five[5]['why'], 'tiles in all') !== false
	&& $five[4]['allowed'] === true && $one[5]['allowed'] === true, array($mid, $five ? $five[5] : null));

$campid = new_camp('Tiles Camp', $AREA);
$camp = FieldCamp::getCamp($db, $campid);
check('nothing saved yet: every map is selected', FieldCampTiles::chosenLayerIds($camp, $basemaps) === array_keys($basemaps));
list($z, $ids, $errs) = FieldCampTiles::validateChoice($camp, $basemaps, 15, array('mapbox.outdoors', 'macrostrat', 'no.such.map', 'macrostrat', 17));
check('a good choice passes; unknown and repeated maps are dropped', !$errs && $z === 15 && $ids === array('mapbox.outdoors', 'macrostrat'), $errs);
list(, , $errs) = FieldCampTiles::validateChoice($camp, $basemaps, 15, array());
check('no map selected is refused', count($errs) === 1 && strpos($errs[0], 'at least one map') !== false, $errs);
list(, , $errs) = FieldCampTiles::validateChoice($camp, $basemaps, 17, array('macrostrat'));
check('a zoom past the ceiling is refused', count($errs) === 1 && strpos($errs[0], 'zoom levels listed') !== false, $errs);
list(, , $errs) = FieldCampTiles::validateChoice($camp, $basemaps, 3, array('macrostrat'));
check('so is a zoom below the list', count($errs) === 1, $errs);
$bigid = new_camp('Big Tiles Camp', $big);
list(, , $errs) = FieldCampTiles::validateChoice(FieldCamp::getCamp($db, $bigid), $basemaps, 16, array('macrostrat'));
check('a zoom the area is too large for is refused, with the reason', count($errs) === 1 && strpos($errs[0], 'Zoom 16 is not available') !== false && strpos($errs[0], 'smaller study area') !== false, $errs);
$noareaid = new_camp('No Area Tiles Camp', null);
list(, , $errs) = FieldCampTiles::validateChoice(FieldCamp::getCamp($db, $noareaid), $basemaps, 15, array('macrostrat'));
check('no study area: refused', $errs === array('Choose the study area first.'), $errs);

// ===========================================================================
section('Building (tile server replaced by a stand-in)');
FieldCampPackage::saveMaps($db, $camp, array(array('hash' => 'fctilestest01', 'chosen' => true, 'title' => 'Canon City Map')));
$layers = FieldCampTiles::layers(FieldCampPackage::customMapsForCamp($db, $camp));
check('the camp has five maps to offer', count($layers) === 5);
FieldCampTiles::saveChoice($db, $camp, 15, array('mapbox.outdoors', 'macrostrat', 'fctilestest01'));
$camp = FieldCamp::getCamp($db, $campid);
check('the choice is saved', (int)$camp->tiles_zoom === 15 && FieldCampTiles::chosenLayerIds($camp, $layers) === array('mapbox.outdoors', 'macrostrat', 'fctilestest01'));
check('before a build there is nothing to put in a package', FieldCampTiles::manifest($camp) === null && FieldCampTiles::status($db, $camp)['status'] === '');

tile_stub();
$r = build_now($db, $campid);
check('built: 3 maps, 15 tiles', $r['error'] === '' && $r['maps'] === 3 && $r['tiles'] === 15 && $r['skipped'] === array(), $r);
$host = FieldCampTiles::tileHost();
check('a basemap is asked for by its layer id, with the study area and the zoom',
	$asked[0] === "$host/asynczip?layer=mapbox.outdoors&extent=-105.389271,38.517549,-105.159931,38.587088&zoom=15", $asked[0]);
$customAsk = array_values(array_filter($asked, function ($u) { return strpos($u, 'strabomymaps') !== false; }));
check('a custom map is asked for as strabomymaps with its id, as the app does', $customAsk === array("$host/asynczip?layer=strabomymaps&extent=-105.389271,38.517549,-105.159931,38.587088&zoom=15&id=fctilestest01"), $customAsk);
check('each map: one request, status polls until ready, one download', count($asked) === 12 && strpos($asked[1], '/asyncstatus/uid0x') !== false && strpos($asked[3], '/ziptemp/uid0x/uid0x.zip') !== false, $asked);
$camp = FieldCamp::getCamp($db, $campid);
check('the camp is marked ready, with a summary', $camp->build_status === 'ready' && $camp->build_message === '15 tiles in 3 maps.' && (string)$camp->build_finished !== '', $camp->build_message);
$dir = FieldCampTiles::dir($camp);
check('the files sit in a folder nobody can guess', $dir !== null && preg_match('~/fieldcamp_files/[a-f0-9]{32}$~', $dir) === 1 && is_file("$dir/OfflineTiles.zip") && is_file("$dir/tiles.json"), $dir);
check('nothing of the build\'s work is left behind', count(glob("$dir/*")) === 2, glob("$dir/*"));
$m = FieldCampTiles::manifest($camp);
check('the record of the build: zoom, area, maps and counts', $m && $m['zoom'] === 15 && $m['bbox'] === '-105.389271,38.517549,-105.159931,38.587088' && $m['tiles'] === 15
	&& array_column($m['layers'], 'id') === array('mapbox.outdoors', 'macrostrat', 'fctilestest01') && array_column($m['layers'], 'count') === array(5, 5, 5)
	&& $m['layers'][2]['customMapSource'] === 'strabospot_mymaps' && !isset($m['layers'][0]['customMapSource']) && $m['bytes'] === filesize("$dir/OfflineTiles.zip"), $m);
$zip = new ZipArchive(); $zip->open("$dir/OfflineTiles.zip");
$names = array(); for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
sort($names);
check('the tile zip is laid out as the app\'s tile cache: <map id>/tiles/<z>_<x>_<y>.png', count($names) === 15 && $names[0] === 'fctilestest01/tiles/15_0_7.png' && $names[14] === 'mapbox.outdoors/tiles/15_4_7.png'
	&& count(preg_grep('~^[A-Za-z0-9._-]+/tiles/\d+_\d+_\d+\.png$~', $names)) === 15, $names);
check('scripts, paths that climb out and sub-folders in a downloaded zip are not unpacked', !preg_grep('~evil|escape|outside|sub/~', $names) && !file_exists(dirname($dir) . '/escape.png') && !file_exists('/srv/app/www/outside.png'));
check('a tile comes through unchanged', $zip->getFromName('macrostrat/tiles/15_2_7.png') === "\x89PNG fake tile 2 of uid1x");
$zip->close();
$st = FieldCampTiles::status($db, $camp);
check('status for the page', $st['status'] === 'ready' && $st['tiles'] === 15 && $st['maps'] === 3 && $st['bytes'] > 0, $st);
check('the built tiles match the camp', FieldCampTiles::outdated($camp, $m, $layers) === '');

section('Building: when the tile server does not deliver');
tile_stub(array('macrostrat' => 'refuse'));
$r = build_now($db, $campid);
$camp = FieldCamp::getCamp($db, $campid);
check('one map refused: the others are built and the missing one is named', $r['error'] === '' && $r['maps'] === 2 && count($r['skipped']) === 1 && $r['skipped'][0]['name'] === 'Geology from macrostrat'
	&& $camp->build_status === 'ready' && strpos($camp->build_message, 'Left out: Geology from macrostrat') !== false, $camp->build_message);
check('a map left out by the server does not make the tiles "out of date"', FieldCampTiles::outdated($camp, FieldCampTiles::manifest($camp), $layers) === '');
foreach (array('error' => 'the map server reported: Error: Invalid zoom (99) provided.', 'empty' => 'the map server sent no tiles', 'nodownload' => 'the tiles could not be downloaded') as $mode => $reason) {
	tile_stub(array('mapbox.outdoors' => $mode));
	$r = build_now($db, $campid);
	check("a map that ends in \"$mode\": left out with the reason", $r['error'] === '' && $r['maps'] === 2 && $r['skipped'][0]['reason'] === $reason, $r['skipped']);
}
tile_stub(array('mapbox.outdoors' => 'refuse', 'macrostrat' => 'refuse', 'fctilestest01' => 'refuse'));
$r = build_now($db, $campid);
$camp = FieldCamp::getCamp($db, $campid);
check('no map delivered: the build fails with a plain message', $r['error'] !== '' && $camp->build_status === 'failed' && strpos($camp->build_message, 'No map tiles could be fetched') === 0, $camp->build_message);
check('a failed build offers nothing to the package', FieldCampTiles::manifest($camp) === null && FieldCampTiles::status($db, $camp)['status'] === 'failed');

$db->prepare_query("update fieldcamp set build_status = 'building', build_started = now() - interval '2 minutes', build_message = 'Mapbox Topo (1 of 3): Gathering Tiles 40%' where pkey = $1", array($campid));
$camp = FieldCamp::getCamp($db, $campid);
check('a build two minutes old counts as running', FieldCampTiles::isBuilding($camp) && FieldCampTiles::status($db, $camp)['status'] === 'building' && FieldCampTiles::status($db, $camp)['message'] === 'Mapbox Topo (1 of 3): Gathering Tiles 40%');
check('a second build cannot be started on top of it', FieldCampTiles::startBuild($db, $camp) === 'The tiles for this camp are being built already.');
$db->prepare_query("update fieldcamp set build_started = now() - interval '2 hours' where pkey = $1", array($campid));
$camp = FieldCamp::getCamp($db, $campid);
check('a build that never finished is reported as stopped, not as running', !FieldCampTiles::isBuilding($camp) && FieldCampTiles::status($db, $camp)['status'] === 'failed' && strpos(FieldCampTiles::status($db, $camp)['message'], 'stopped before it finished') !== false);
check('no zoom chosen: a build is not started', FieldCampTiles::startBuild($db, FieldCamp::getCamp($db, $bigid)) === 'Choose the maps and the zoom first.');
check('no study area: a build is not started', FieldCampTiles::startBuild($db, FieldCamp::getCamp($db, $noareaid)) === 'Choose the study area first.');

// ===========================================================================
section('The package with tiles, against the app\'s own backup');
tile_stub(array(), 7);
build_now($db, $campid);
$camp = FieldCamp::getCamp($db, $campid);
$appData = json_decode(file_get_contents("$FIXDIR/app_backup_data.json"));
$data = json_decode(json_encode(FieldCampPackage::buildData($db, $camp), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$mn = $data->mapNamesDb;
check('mapNamesDb lists the three built maps by id', array_keys(get_object_vars($mn)) === array('mapbox.outdoors', 'macrostrat', 'fctilestest01'));
check('a basemap entry has the app\'s keys and kinds', shape_diff($mn->{'mapbox.outdoors'}, $appData->mapNamesDb->{'mapbox.outdoors'}) === array(), shape_diff($mn->{'mapbox.outdoors'}, $appData->mapNamesDb->{'mapbox.outdoors'}));
check('a custom map entry has the app\'s keys and kinds', shape_diff($mn->fctilestest01, $appData->mapNamesDb->{'62b11568865a7'}) === array(), shape_diff($mn->fctilestest01, $appData->mapNamesDb->{'62b11568865a7'}));
$ours = $mn->macrostrat; $apps = $appData->mapNamesDb->macrostrat;
check('what does not depend on the device or the day is identical to the app\'s entry', $ours->id === $apps->id && $ours->name === $apps->name && $ours->source === $apps->source && $ours->overlay === $apps->overlay
	&& $ours->bbox === $apps->bbox && $ours->version === $apps->version && $ours->isOfflineMapVisible === $apps->isOfflineMapVisible && $ours->layers == $apps->layers
	&& $ours->sources->{'raster-tiles'}->tileSize === $apps->sources->{'raster-tiles'}->tileSize && $ours->sources->{'raster-tiles'}->type === 'raster', $ours);
check('the tile path ends the way the app\'s does', substr($ours->sources->{'raster-tiles'}->tiles[0], -strlen('/StraboSpotTiles/TileCache/macrostrat/tiles/{z}_{x}_{y}.png')) === '/StraboSpotTiles/TileCache/macrostrat/tiles/{z}_{x}_{y}.png'
	&& substr($apps->sources->{'raster-tiles'}->tiles[0], -strlen('/StraboSpotTiles/TileCache/macrostrat/tiles/{z}_{x}_{y}.png')) === '/StraboSpotTiles/TileCache/macrostrat/tiles/{z}_{x}_{y}.png'
	&& substr($ours->glyphs, -strlen('/StraboSpot/Glyphs/{fontstack}/{range}.pbf')) === '/StraboSpot/Glyphs/{fontstack}/{range}.pbf' && substr($apps->glyphs, -strlen('/StraboSpot/Glyphs/{fontstack}/{range}.pbf')) === '/StraboSpot/Glyphs/{fontstack}/{range}.pbf');
check('the date is stamped the way the app stamps it', preg_match('~^\d{1,2}/\d{1,2}/\d{4}, \d{1,2}:\d\d:\d\d\x{202F}[AP]M$~u', $ours->date) === 1 && preg_match('~^\d{1,2}/\d{1,2}/\d{4}, \d{1,2}:\d\d:\d\d\x{202F}[AP]M$~u', $apps->date) === 1, $ours->date);
check('the count is the number of tiles built', $ours->count === 7 && strlen($ours->mapId) === 13 && strlen($apps->mapId) === 13);
check('the custom map carries its title, source and bbox', $mn->fctilestest01->name === 'Canon City Map' && $mn->fctilestest01->customMapSource === 'strabospot_mymaps'
	&& $mn->fctilestest01->bbox === $data->projectDb->project->other_maps[0]->bbox && $mn->fctilestest01->bbox !== '');

$tmp = tempnam(sys_get_temp_dir(), 'fctilespkg');
$built = FieldCampPackage::buildZip($db, $camp, $tmp);
$zip = new ZipArchive(); $zip->open($tmp);
$entries = array(); for ($i = 0; $i < $zip->numFiles; $i++) $entries[] = $zip->getNameIndex($i);
sort($entries);
check('the package now has the app\'s four entries', $built['error'] === '' && $built['offline_maps'] === 3 && $entries === array('data.json', 'images/', 'maps/OfflineTiles.zip', 'other_maps.json'), $entries);
$inner = tempnam(sys_get_temp_dir(), 'fctilesin'); file_put_contents($inner, $zip->getFromName('maps/OfflineTiles.zip'));
check('the tile zip inside is the built one, byte for byte', md5_file($inner) === md5_file(FieldCampTiles::tilesZipPath($camp)));
// useImport.js moveFiles: for every map in mapNamesDb it looks for the folder <map id> in the unzipped
// OfflineTiles.zip and moves the files of <map id>/tiles into its tile cache.
$zd = json_decode($zip->getFromName('data.json'));
$tz = new ZipArchive(); $tz->open($inner);
$perMap = array();
for ($i = 0; $i < $tz->numFiles; $i++) { $parts = explode('/', $tz->getNameIndex($i)); if (count($parts) === 3 && $parts[1] === 'tiles') $perMap[$parts[0]] = ($perMap[$parts[0]] ?? 0) + 1; }
$mismatch = array();
foreach (get_object_vars($zd->mapNamesDb) as $id => $map) { if (($perMap[$id] ?? 0) !== $map->count) $mismatch[] = "$id: " . ($perMap[$id] ?? 0) . " files, count $map->count"; }
check('read as the app\'s import reads it: every listed map has its folder with as many tiles as its count', !$mismatch && count($perMap) === 3, $mismatch);
$tz->close(); $zip->close(); unlink($tmp); unlink($inner);

FieldCampPackage::saveMaps($db, $camp, array());
$camp = FieldCamp::getCamp($db, $campid);
$layersNow = FieldCampTiles::layers(FieldCampPackage::customMapsForCamp($db, $camp));
$data2 = json_decode(json_encode(FieldCampPackage::buildData($db, $camp)));
check('a custom map taken out of the project is not listed as an offline map either', array_keys(get_object_vars($data2->mapNamesDb)) === array('mapbox.outdoors', 'macrostrat'));
check('and the tiles are reported as out of date', FieldCampTiles::outdated($camp, FieldCampTiles::manifest($camp), $layersNow) === 'The selected maps have changed since the tiles were built.');
FieldCampPackage::saveMaps($db, $camp, array(array('hash' => 'fctilestest01', 'chosen' => true, 'title' => 'Canon City Map')));
$camp = FieldCamp::getCamp($db, $campid);
check('back in: up to date again', FieldCampTiles::outdated($camp, FieldCampTiles::manifest($camp), $layers) === '');
FieldCampTiles::saveChoice($db, $camp, 14, array('mapbox.outdoors', 'macrostrat', 'fctilestest01'));
check('another zoom: out of date', FieldCampTiles::outdated(FieldCamp::getCamp($db, $campid), FieldCampTiles::manifest($camp), $layers) === 'The zoom has changed since the tiles were built.');
FieldCampTiles::saveChoice($db, $camp, 15, array('mapbox.outdoors', 'macrostrat', 'fctilestest01'));
FieldCamp::saveArea($db, $campid, array('west' => -105.38, 'south' => 38.52, 'east' => -105.16, 'north' => 38.58));
check('another study area: out of date', FieldCampTiles::outdated(FieldCamp::getCamp($db, $campid), FieldCampTiles::manifest($camp), $layers) === 'The study area has changed since the tiles were built.');
FieldCamp::saveArea($db, $campid, $AREA);
$bare = json_decode(json_encode(FieldCampPackage::buildData($db, FieldCamp::getCamp($db, $bigid))));
check('a camp without built tiles: empty mapNamesDb, as before', get_object_vars($bare->mapNamesDb) === array());

// ===========================================================================
section('HTTP: the Offline maps step');
FieldCampTiles::$http = null; FieldCampTiles::$pollSeconds = 2;
$sidI = forge($INSTR, $FIX[$INSTR]);
$sidO = forge($OUTSIDER, $FIX[$OUTSIDER]);
list($code) = http('GET', "/field_camp?id=$campid&step=tiles", $sidO);
check('an outsider gets 404', $code === 404, $code);
list($code, $body) = http('GET', "/field_camp?id=$campid&step=tiles", $sidI);
check('the owner opens the step', $code === 200 && !php_error($body) && strpos($body, 'Offline maps') !== false, $code);
check('five maps, the saved three selected', substr_count($body, 'class="fc-tileline"') === 5 && preg_match('~id="fcTmapbox\.outdoors" checked~', $body) === 1 && preg_match('~id="fcTmapbox\.satellite" checked~', $body) === 0
	&& preg_match('~id="fcTfctilestest01" checked~', $body) === 1 && strpos($body, 'Canon City Map') !== false);
check('six zoom rows with scale and tiles per map; the saved zoom is selected', substr_count($body, 'class="fc-zoomline" data-zoom=') === 6 && preg_match('~id="fcZ15" value="15" checked~', $body) === 1
	&& strpos($body, '1:14,120') !== false && strpos($body, '>303<') !== false && strpos($body, '>1,077<') !== false);
check('the last build is reported', strpos($body, 'Offline tiles built') !== false && strpos($body, '21 tiles in 3 maps.') !== false);
check('a preview map and the provisional ceiling are on the page', strpos($body, 'id="fcTileMap"') !== false && strpos($body, 'Zoom 16 is the deepest level offered for now') !== false);
list($code, $body) = http('GET', "/field_camp?id=$bigid&step=tiles", $sidI);
check('a new camp starts on its default zoom with every map selected', $code === 200 && !php_error($body) && substr_count($body, ' checked') === 5
	&& preg_match('~id="fcZ' . FieldCampTiles::defaultZoom($bigOpts) . '" value="\d+" checked~', $body) === 1, substr_count($body, ' checked'));
list($code, $body) = http('GET', "/field_camp?id=$noareaid&step=tiles", $sidI);
check('without a study area the step says so', $code === 200 && !php_error($body) && strpos($body, 'Choose the study area first') !== false && strpos($body, 'fcTileForm') === false);
list($code, $body) = http('GET', "/field_camp?id=$campid&ajax=build", $sidI);
$st = json_decode($body, true);
check('the build state is answered as JSON', $code === 200 && is_array($st) && $st['status'] === 'ready' && $st['tiles'] === 21, $body);
list($code) = http('GET', "/field_camp?id=$campid&ajax=build", $sidO);
check('not to an outsider', $code === 404, $code);

list($code, $body) = post_follow($sidI, array('action' => 'tiles_build', 'id' => $campid, 'zoom' => '19', 'layers_json' => json_encode(array('macrostrat'))));
check('a zoom that is not offered is refused and nothing starts', strpos($body, 'Choose one of the zoom levels listed.') !== false && FieldCamp::getCamp($db, $campid)->build_status === 'ready' && (int)FieldCamp::getCamp($db, $campid)->tiles_zoom === 15);
list($code, $body) = post_follow($sidI, array('action' => 'tiles_build', 'id' => $campid, 'zoom' => '15', 'layers_json' => '[]'));
check('no map selected is refused', strpos($body, 'Select at least one map.') !== false && FieldCamp::getCamp($db, $campid)->build_status === 'ready');
list($code) = http('POST', '/field_camp_action', $sidO, array('action' => 'tiles_build', 'id' => $campid, 'zoom' => '12', 'layers_json' => '["macrostrat"]', 'csrf' => $TOKEN));
check('an outsider cannot start a build', $code === 404 && FieldCamp::getCamp($db, $campid)->build_status === 'ready', $code);
$db->prepare_query("update fieldcamp set build_status = 'building', build_started = now() where pkey = $1", array($campid));
list($code, $body) = post_follow($sidI, array('action' => 'tiles_build', 'id' => $campid, 'zoom' => '12', 'layers_json' => '["macrostrat"]'));
check('while a build runs a second one is refused and the page says it is building', strpos($body, 'being built already') !== false && strpos($body, 'Building the offline tiles...') !== false
	&& preg_match('~id="fcTileBuildBtn" disabled~', $body) === 1 && (int)FieldCamp::getCamp($db, $campid)->tiles_zoom === 15);
$db->prepare_query("update fieldcamp set build_status = 'ready' where pkey = $1", array($campid));

list($code, $body) = http('GET', "/field_camp?id=$campid&step=package", $sidI);
check('the Package step states the tiles', $code === 200 && !php_error($body) && preg_match('~Offline map tiles</td><td>21 tiles in 3 maps, ~', $body) === 1);
list($code, $body, , $headers) = http('GET', "/field_camp_package?id=$campid", $sidI);
$tmp = tempnam(sys_get_temp_dir(), 'fctilesdl'); file_put_contents($tmp, $body);
$zip = new ZipArchive(); $ok = $zip->open($tmp) === true;
check('the downloaded package holds the tile zip and lists the maps', $code === 200 && $ok && $zip->locateName('maps/OfflineTiles.zip') !== false
	&& count(get_object_vars(json_decode($zip->getFromName('data.json'))->mapNamesDb)) === 3 && strlen($zip->getFromName('maps/OfflineTiles.zip')) === filesize(FieldCampTiles::tilesZipPath(FieldCamp::getCamp($db, $campid))), $code);
if ($ok) $zip->close(); unlink($tmp);
$camp = FieldCamp::getCamp($db, $campid);
$token = basename(FieldCampTiles::dir($camp));
list($code) = http('GET', "/fieldcamp_files/$token/OfflineTiles.zip", $sidI);
check('the built files cannot be fetched from the web, even with the folder name', $code === 403, $code);

// ===========================================================================
section('Live: the real tile server');
$ch = curl_init(FieldCampTiles::tileHost() . '/zipcount?extent=' . FieldCampUnits::bboxString($AREA) . '&zoom=15');
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30));
$probe = json_decode((string)curl_exec($ch), true);
if (!is_array($probe) || !isset($probe['count'])) {
	$skip += 4;
	echo "  SKIP  the tile server did not answer from here; 4 live checks skipped\n";
} else {
	$same = array();
	foreach (array(array($AREA, 13), array($AREA, 15), array($AREA, 16), array($small, 14), array($big, 12)) as $case) {
		$ch = curl_init(FieldCampTiles::tileHost() . '/zipcount?extent=' . FieldCampUnits::bboxString($case[0]) . '&zoom=' . $case[1]);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30));
		$theirs = json_decode((string)curl_exec($ch), true);
		$mine = FieldCampTiles::tileCount($case[0], $case[1]);
		if (($theirs['count'] ?? null) !== $mine) $same[] = "zoom {$case[1]}: ours $mine, server " . json_encode($theirs);
	}
	check('our tile count is the tile server\'s count (five areas and zooms)', !$same, $same);

	// One small real build, started from the page: a 3 km area, one map, zoom 13 (12 tiles).
	$liveid = new_camp('Live Tiles Camp', $small);
	list($code, $body) = post_follow($sidI, array('action' => 'tiles_build', 'id' => $liveid, 'zoom' => '13', 'layers_json' => json_encode(array('macrostrat'))));
	check('the page starts the build in the background', $code === 200 && strpos($body, 'The build has started.') !== false && strpos($body, 'Building the offline tiles...') !== false, $code);
	$deadline = time() + 180; $live = null;
	while (time() < $deadline) { sleep(2); $live = FieldCamp::getCamp($db, $liveid); if ($live->build_status !== 'building') break; }
	$lm = FieldCampTiles::manifest($live);
	$expect = FieldCampTiles::tileCount($small, 13);
	check("and it finishes with the $expect tiles we said it would", $live->build_status === 'ready' && $lm && $lm['tiles'] === $expect && $lm['layers'][0]['id'] === 'macrostrat', array($live->build_status, $live->build_message));
	$ok = false;
	if ($lm) {
		$tz = new ZipArchive(); $tz->open(FieldCampTiles::tilesZipPath($live));
		$first = $tz->getFromIndex(0); $name = $tz->getNameIndex(0); $n = $tz->numFiles; $tz->close();
		$ok = $n === $expect && preg_match('~^macrostrat/tiles/\d+_\d+_\d+\.png$~', $name) === 1 && substr($first, 0, 4) === "\x89PNG";
	}
	check('the tiles are real PNG images in the app\'s layout', $ok);
}

// ===========================================================================
cleanup();
foreach ($sids as $sid) @unlink("$SESS/sess_$sid");

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail === 0 ? 0 : 1);
