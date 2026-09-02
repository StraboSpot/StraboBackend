<?php
/**
 * File: tests/fieldbook/smoke_test_maps.php
 * Description: M2 smoke suite for the fieldbook map figures
 *              (docs/Fieldbook_Design.md §6, §12 M2). Pure math (Web
 *              Mercator projection, metres per pixel, extent padding and
 *              minimum span, zoom selection, marker range labels), then
 *              FieldbookMaps::render against a local stub tile server
 *              (tests/fieldbook/tile_stub.php on PHP's built-in server):
 *              tile counts within the window budget, disk cache (second
 *              render fetches nothing), the geology overlay doubling the
 *              tile count, missing tiles (gray) vs the > 30 % graticule
 *              fallback, the per-book budget skipping optional figures,
 *              an unreachable proxy degrading fast and without exceptions,
 *              and the renderer embedding the figures (cover + day
 *              locator) or none with map=none.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/fieldbook/smoke_test_maps.php
 */
chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('memory_limit', '1G');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
require_once 'includes/fieldbook/FieldbookMaps.php';
require_once 'includes/fieldbook/FieldbookModel.php';
require_once 'includes/fieldbook/FieldbookRenderer.php';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function rmrf($d) { if (is_dir($d)) exec('rm -rf ' . escapeshellarg($d)); }
$TMP = '/tmp/fb_maps_' . getmypid();
rmrf($TMP); mkdir($TMP, 0775, true); mkdir("$TMP/cache"); mkdir("$TMP/log");
$PORT = 8090 + (getmypid() % 100);

echo "Enhanced fieldbook M2 maps smoke suite\n";

// ------------------------------------------------------------------ 1. math
list($x, $y) = FieldbookMaps::project(0, 0, 0);
check('project: lon 0 lat 0 at z0 = tile centre (128, 128)', abs($x - 128) < 1e-6 && abs($y - 128) < 1e-6, "$x,$y");
list($x, $y) = FieldbookMaps::project(180, 85.05112878, 1);
check('project: (180, 85.05) at z1 = (512, 0)', abs($x - 512) < 1e-6 && abs($y) < 1e-3, "$x,$y");
list($x, $y) = FieldbookMaps::project(-118.25, 34.05, 12);
check('project: Los Angeles at z12 lands in tile 702/1635', (int)floor($x / 256) === 702 && (int)floor($y / 256) === 1635, floor($x / 256) . '/' . floor($y / 256));
check('metersPerPixel: equator z0 ~ 156543 m, z17 at lat 60 ~ 0.6 m', abs(FieldbookMaps::metersPerPixel(0, 0) - 156543) < 1 && abs(FieldbookMaps::metersPerPixel(60, 17) - 0.597) < 0.01);
$e = FieldbookMaps::extent(array(array(10, 50)));
check('extent: single point gets the minimum span (~250 m) padded', $e && ($e[2] - $e[0]) > 0.003 && ($e[3] - $e[1]) > 0.0025 && abs(($e[0] + $e[2]) / 2 - 10) < 1e-9, json_encode($e));
$e = FieldbookMaps::extent(array(array(10, 50), array(11, 51)), 0.15);
check('extent: bbox padded 15% each side', abs(($e[2] - $e[0]) - 1.3) < 1e-9 && abs(($e[3] - $e[1]) - 1.3) < 1e-9, json_encode($e));
$z = FieldbookMaps::zoomFor(array(-118.3, 34.0, -118.2, 34.1), 720, 500);
$zBig = FieldbookMaps::zoomFor(array(-120, 30, -110, 40), 720, 500);
check('zoomFor: deepest zoom that fits; wider extent = shallower', $z >= 10 && $z <= 12 && $zBig < $z && $zBig >= 2, "$z / $zBig");
list($x0, $y0) = FieldbookMaps::project(-118.3, 34.1, $z); list($x1, $y1) = FieldbookMaps::project(-118.2, 34.0, $z);
check('zoomFor: extent fits 720 x 500 px at the chosen zoom, not at the next', ($x1 - $x0) <= 720 && ($y1 - $y0) <= 500 && (($x1 - $x0) * 2 > 720 || ($y1 - $y0) * 2 > 500), ($x1 - $x0) . ' x ' . ($y1 - $y0));
check('rangeLabel: consecutive runs and singles', FieldbookMaps::rangeLabel(array('1', '2', '3')) === '1-3' && FieldbookMaps::rangeLabel(array('3', '1')) === '1, 3' && FieldbookMaps::rangeLabel(array('4')) === '4' && FieldbookMaps::rangeLabel(array('1', '2')) === '1, 2', FieldbookMaps::rangeLabel(array('3', '1')));
check('rangeLabel: long clusters are abbreviated', FieldbookMaps::rangeLabel(array('1', '2', '3', '5', '7', '9')) === '1-3, 5, +4', FieldbookMaps::rangeLabel(array('1', '2', '3', '5', '7', '9')));

// ------------------------------------------------------------------ 2. stub tile server
$desc = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', "$TMP/server.out", 'w'), 2 => array('file', "$TMP/server.out", 'w'));
$proc = proc_open("php -S 127.0.0.1:$PORT " . escapeshellarg('/srv/app/www/tests/fieldbook/tile_stub.php'), $desc, $pipes, '/srv/app/www', array('TILE_STUB_LOG' => "$TMP/log", 'PATH' => getenv('PATH')));
$up = false;
for ($i = 0; $i < 40 && !$up; $i++) { usleep(100000); $c = @fsockopen('127.0.0.1', $PORT, $en, $es, 0.5); if ($c) { $up = true; fclose($c); } }
check('stub tile server up', $up, file_exists("$TMP/server.out") ? file_get_contents("$TMP/server.out") : '');
function requests($TMP) { return is_file("$TMP/log/requests.log") ? count(array_filter(explode("\n", file_get_contents("$TMP/log/requests.log")))) : 0; }
function reset_log($TMP) { @unlink("$TMP/log/requests.log"); }
$base = "http://127.0.0.1:$PORT/v5/";
$pts = array(array(-118.25, 34.05, '1'), array(-118.24, 34.06, '2'), array(-118.2502, 34.0501, '3'));
$shapes = array(array('type' => 'LineString', 'coordinates' => array(array(-118.26, 34.04), array(-118.24, 34.06))));

$maps = new FieldbookMaps(array('set' => 'outdoors', 'tile_base' => $base, 'cache_dir' => "$TMP/cache", 'budget' => 300, 'ttl_days' => 90, 'connect_timeout' => 1, 'total_timeout' => 2));
check('maps enabled + attribution', $maps->enabled() && strpos($maps->attribution(), 'OpenStreetMap') !== false);
reset_log($TMP);
$fig = $maps->render($pts, $shapes, 768, 400, false);
$n1 = requests($TMP);
check('render: figure returned at the window size, no fallback', $fig && $fig['w'] === 768 && $fig['h'] === 400 && !$fig['fallback'] && $fig['missing'] === 0, json_encode($fig ? array($fig['w'], $fig['h'], $fig['zoom'], $fig['fallback']) : null));
check('render: tiles within the window budget (<= 4 x 3 for 768 x 400) and all fetched once', $n1 >= 4 && $n1 <= 12 && $maps->fetched === $n1 && $maps->tilesUsed === $n1, "$n1 / fetched {$maps->fetched} / used {$maps->tilesUsed}");
check('render: scale label is a round number', preg_match('/^\d+ (m|km)$/', $fig['scaleLabel']), $fig['scaleLabel']);
$cached = glob("$TMP/cache/mapbox.outdoors/*/*/*.png");
check('cache: every fetched tile written under set/z/x/y.png', count($cached) === $n1, count($cached));
imagedestroy($fig['im']);
reset_log($TMP);
$fig = $maps->render($pts, $shapes, 768, 400, false);
check('cache: second identical render fetches nothing', requests($TMP) === 0 && $maps->cacheHits === $n1, requests($TMP) . ' / hits ' . $maps->cacheHits);
imagedestroy($fig['im']);
// marker pixels: dark pill drawn near the projected point
$fig = $maps->render($pts, array(), 512, 384, false);
list($px, $py) = FieldbookMaps::project(-118.24, 34.06, $fig['zoom']);
$dark = 0;
for ($dx = -12; $dx <= 12; $dx++) for ($dy = -12; $dy <= 12; $dy++) {
	$c = imagecolorat($fig['im'], (int)($px - ($fig['w'] / 2 - (FieldbookMaps::project(-118.245, 34.055, $fig['zoom'])[0] - 0)) + $dx) % 1, 0); break 2;
}
// simpler: count dark pixels anywhere (markers are the only near-black paint on a stub tile)
$darkCount = 0;
for ($yy = 0; $yy < $fig['h']; $yy += 2) for ($xx = 0; $xx < $fig['w']; $xx += 2) { $c = imagecolorat($fig['im'], $xx, $yy); if ((($c >> 16) & 0xFF) < 60 && (($c >> 8) & 0xFF) < 60 && ($c & 0xFF) < 60) $darkCount++; }
check('markers: dark pills painted (points 1 and 3 cluster, 2 separate)', $darkCount > 60, $darkCount);
imagedestroy($fig['im']);

// geology overlay doubles the tiles
$geo = new FieldbookMaps(array('set' => 'geology', 'tile_base' => $base, 'cache_dir' => "$TMP/cache_geo", 'budget' => 300, 'connect_timeout' => 1, 'total_timeout' => 2));
reset_log($TMP);
$fig = $geo->render($pts, array(), 512, 384, false);
$reqs = file_get_contents("$TMP/log/requests.log");
check('geology: outdoors + macrostrat tiles, overlay counted in the budget', $fig && substr_count($reqs, '/macrostrat/') > 0 && substr_count($reqs, '/macrostrat/') === substr_count($reqs, '/mapbox.outdoors/') && $geo->tilesUsed === substr_count($reqs, '/v5/'), $geo->tilesUsed . ' ' . substr_count($reqs, '/macrostrat/'));
check('geology: attribution names Macrostrat', strpos($geo->attribution(), 'Macrostrat') !== false);
imagedestroy($fig['im']);

// missing tiles: odd x columns 404 -> about half missing -> graticule fallback
touch("$TMP/log/fail_odd");
$m2 = new FieldbookMaps(array('set' => 'outdoors', 'tile_base' => $base, 'cache_dir' => "$TMP/cache2", 'budget' => 300, 'connect_timeout' => 1, 'total_timeout' => 2));
reset_log($TMP);
$fig = $m2->render($pts, array(), 768, 400, false);
check('missing tiles > 30%: graticule fallback, missing counted, retried once', $fig && $fig['fallback'] && $fig['missing'] > 0 && $m2->tilesMissing === $fig['missing'] && $m2->fallbacks === 1 && requests($TMP) > $m2->fetched, json_encode(array($fig['fallback'], $fig['missing'], $m2->tilesMissing, requests($TMP), $m2->fetched)));
imagedestroy($fig['im']);
unlink("$TMP/log/fail_odd");
check('notes mention the fallback', count(array_filter($m2->notes(), function ($n) { return strpos($n, 'without a basemap') !== false; })) === 1, json_encode($m2->notes()));

// budget: optional figures skipped once spent, required ones still drawn
$m3 = new FieldbookMaps(array('set' => 'outdoors', 'tile_base' => $base, 'cache_dir' => "$TMP/cache", 'budget' => 3, 'connect_timeout' => 1, 'total_timeout' => 2));
$fig = $m3->render($pts, array(), 512, 384, true);
check('budget: an optional figure over budget is skipped and flagged', $fig === null && $m3->budgetHit && $m3->figures === 0);
$fig = $m3->render($pts, array(), 512, 384, false);
check('budget: a required figure still renders over budget', $fig !== null && $m3->figures === 1);
if ($fig) imagedestroy($fig['im']);
check('budget: note in the colophon lines', count(array_filter($m3->notes(), function ($n) { return strpos($n, 'budget') !== false; })) === 1);

// unreachable proxy: no exception, fast, graticule
$dead = new FieldbookMaps(array('set' => 'outdoors', 'tile_base' => 'http://127.0.0.1:1/v5/', 'cache_dir' => "$TMP/cache3", 'budget' => 300, 'connect_timeout' => 1, 'total_timeout' => 2));
$t0 = microtime(true);
$fig = $dead->render($pts, array(), 768, 400, false);
$dt = microtime(true) - $t0;
check('unreachable proxy: graticule figure within a few seconds, no exception', $fig && $fig['fallback'] && $dt < 8, sprintf('%.1fs', $dt));
if ($fig) imagedestroy($fig['im']);
$none = new FieldbookMaps(array('set' => 'none'));
check('map=none: disabled, render returns null', !$none->enabled() && $none->render($pts, array(), 768, 400, false) === null);

// ------------------------------------------------------------------ 3. renderer integration (synthetic model, no database)
function feature($id, $name, $lon, $lat) {
	return array('type' => 'Feature', 'geometry' => (object)array('type' => 'Point', 'coordinates' => array($lon, $lat)),
		'properties' => array('id' => $id, 'name' => $name, 'geometrytype' => 'Point', 'modified_timestamp' => '1722400000000', 'notes' => "notes $name"));
}
$features = array(feature('1721030400101', 'A', -118.25, 34.05), feature('1721034000102', 'B', -118.24, 34.06), feature('1721120400105', 'C', -118.26, 34.04));
$tree = array(array('owner' => 1, 'project_id' => 'p', 'project_name' => 'Map Project', 'dsids' => array('d'), 'dataset_names' => array('d' => 'Map Dataset'), 'spot_map' => array('1721030400101' => array('ds' => 'd'), '1721034000102' => array('ds' => 'd'), '1721120400105' => array('ds' => 'd'))));
$meta = array('title' => 'Map Dataset', 'subtitle' => '', 'owner' => 'Tester', 'generated' => 'today', 'doi' => '', 'options' => array('map' => 'outdoors', 'photos' => 'sheets', 'nets' => 'on', 'page' => 'letter'));
$model = FieldbookModel::build($features, array(), array(), $tree, $meta);
check('model keeps geometry + point for located top-level spots', $model->projects[0]['datasets'][0]['days'][0]['spots'][0]['point'] !== null && $model->projects[0]['datasets'][0]['days'][0]['spots'][0]['geometry']['type'] === 'Point');
$mapsR = new FieldbookMaps(array('set' => 'outdoors', 'tile_base' => $base, 'cache_dir' => "$TMP/cache", 'budget' => 300, 'connect_timeout' => 1, 'total_timeout' => 2));
$r = new FieldbookRenderer($model, null, $mapsR);
$pdf = $r->render();
$bytes = $pdf->Output('', 'S');
$images = preg_match_all('#/Subtype /Image#', $bytes);
$r2 = new FieldbookRenderer($model, null, new FieldbookMaps(array('set' => 'none')));
$pdf2 = $r2->render();
$images2 = preg_match_all('#/Subtype /Image#', $pdf2->Output('', 'S'));
// the cover logo (PNG with alpha = image + soft mask) is in both books; the maps are the difference
check('renderer: cover map + two day locators embedded (3 more image objects than map=none), 3 figures counted', $images - $images2 === 3 && $mapsR->figures === 3, "$images - $images2 / {$mapsR->figures}");
check('renderer: map=none embeds only the cover logo', $images2 <= 2, $images2);

proc_terminate($proc); proc_close($proc);
rmrf($TMP);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
