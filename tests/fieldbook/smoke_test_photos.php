<?php
/**
 * File: tests/fieldbook/smoke_test_photos.php
 * Description: M4 smoke suite for the fieldbook photo figures
 *              (docs/Fieldbook_Design.md §8, §12 M4). Synthetic JPEGs in a
 *              temp image dir (no database, no real dbimages): file lookup
 *              (bare id, .jpg fallback, missing), thumbnails (longest side
 *              bound, aspect kept, portrait, no upscale, disk cache hit on
 *              the second call, TTL), EXIF orientation transforms, the
 *              basemap overlay (child point / line / polygon drawn in the
 *              app's pixel space with y from the bottom, strike-and-dip
 *              symbol, missing pixel geometry tolerated), the model
 *              keeping pixel geometry for children, and the renderer on a
 *              synthetic model: contact sheet + promoted basemap with
 *              children + sketch promotion, image object counts per photos
 *              option (sheets / full / none), photo index entries with
 *              pages in book order, placeholders for missing files,
 *              colophon counts.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/fieldbook/smoke_test_photos.php
 */
chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('memory_limit', '1G');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
require_once 'includes/fieldbook/FieldbookPhotos.php';
require_once 'includes/fieldbook/FieldbookModel.php';
require_once 'includes/fieldbook/FieldbookRenderer.php';
require_once 'includes/fieldbook/FieldbookMaps.php';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function rmrf($d) { if (is_dir($d)) exec('rm -rf ' . escapeshellarg($d)); }
$TMP = '/tmp/fb_photos_' . getmypid();
rmrf($TMP); mkdir("$TMP/img", 0775, true); mkdir("$TMP/cache");
/** Solid JPEG $w x $h with a distinct colour, saved under $name (bare id unless told otherwise). */
function jpeg($path, $w, $h, $rgb = array(200, 60, 60)) {
	$im = imagecreatetruecolor($w, $h);
	imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
	imagejpeg($im, $path, 85); imagedestroy($im);
}
jpeg("$TMP/img/1001", 1600, 1200);                     // landscape
jpeg("$TMP/img/1002", 900, 1600, array(60, 60, 200));  // portrait
jpeg("$TMP/img/1003.jpg", 300, 200);                   // .jpg fallback, smaller than the sheet size
jpeg("$TMP/img/1004", 3264, 2448, array(230, 230, 230)); // basemap (app dims 3264 x 2448)
jpeg("$TMP/img/1005", 800, 600, array(60, 200, 60));   // sketch

echo "Enhanced fieldbook M4 photos smoke suite\n";

// ------------------------------------------------------------------ 1. lookup + thumbnails + cache
$ph = new FieldbookPhotos(array('mode' => 'sheets', 'image_dir' => "$TMP/img", 'cache_dir' => "$TMP/cache", 'ttl_days' => 90));
check('enabled with GD and mode sheets', $ph->enabled());
check('path: bare id, .jpg fallback, missing => null, unsafe id sanitised', $ph->path('1001') === "$TMP/img/1001" && $ph->path('1003') === "$TMP/img/1003.jpg" && $ph->path('9999') === null && $ph->path('../etc/passwd') === null);
$t = $ph->thumb('1001');
check('thumb: landscape 1600x1200 => longest side 640, aspect kept, jpeg bytes', $t && $t['w'] === 640 && $t['h'] === 480 && substr($t['data'], 0, 2) === "\xFF\xD8", $t ? "{$t['w']}x{$t['h']}" : 'null');
$t2 = $ph->thumb('1002');
check('thumb: portrait 900x1600 => 360x640', $t2 && $t2['w'] === 360 && $t2['h'] === 640, $t2 ? "{$t2['w']}x{$t2['h']}" : 'null');
$t3 = $ph->thumb('1003');
check('thumb: small original is not upscaled (300x200)', $t3 && $t3['w'] === 300 && $t3['h'] === 200);
check('counters: 3 generated, 0 cache hits, 0 missing', $ph->generated === 3 && $ph->cacheHits === 0 && $ph->missing === 0 && $ph->images === 3);
check('cache files written as <id>_<px>.jpg', is_file("$TMP/cache/1001_640.jpg") && is_file("$TMP/cache/1003_640.jpg") && !glob("$TMP/cache/*.tmp"));
$t = $ph->thumb('1001');
check('second call served from the cache', $ph->cacheHits === 1 && $ph->generated === 3 && $t['w'] === 640);
check('missing file => null, counted', $ph->thumb('9999') === null && $ph->missing === 1);
$full = $ph->full('1001');
check('full: longest side 1400, counted as promoted, not cached', $full && $full['w'] === 1400 && $full['h'] === 1050 && $ph->promoted === 1 && !is_file("$TMP/cache/1001_1400.jpg"));
touch("$TMP/cache/1002_640.jpg", time() - 100 * 86400);
$ph2 = new FieldbookPhotos(array('mode' => 'sheets', 'image_dir' => "$TMP/img", 'cache_dir' => "$TMP/cache", 'ttl_days' => 90));
$ph2->thumb('1002');
check('stale cache file (older than the TTL) is regenerated', $ph2->generated === 1 && $ph2->cacheHits === 0 && filemtime("$TMP/cache/1002_640.jpg") > time() - 60);
$none = new FieldbookPhotos(array('mode' => 'none', 'image_dir' => "$TMP/img"));
check('photos=none: disabled, notes say listed without figures', !$none->enabled() && strpos($none->notes()[0], 'without figures') !== false);
file_put_contents("$TMP/img/1009", 'not an image');
check('undecodable file => null, skipped counted', $ph->thumb('1009') === null && $ph->skipped === 1);

// ------------------------------------------------------------------ 2. EXIF orientation transforms
$im = imagecreatetruecolor(40, 20);
$white = imagecolorallocate($im, 255, 255, 255); $red = imagecolorallocate($im, 255, 0, 0);
imagefilledrectangle($im, 0, 0, 40, 20, $white); imagefilledrectangle($im, 0, 0, 9, 9, $red);   // red block top-left
$r = FieldbookPhotos::applyExif($im, 6);
check('EXIF 6 (rotate 90 CW): 40x20 => 20x40, top-left block moves to the top-right', imagesx($r) === 20 && imagesy($r) === 40 && (imagecolorat($r, 17, 2) & 0xFF0000) === 0xFF0000 && (imagecolorat($r, 2, 2) & 0xFFFFFF) === 0xFFFFFF, imagesx($r) . 'x' . imagesy($r));
imagedestroy($r);
$im = imagecreatetruecolor(40, 20); $white = imagecolorallocate($im, 255, 255, 255); $red = imagecolorallocate($im, 255, 0, 0);
imagefilledrectangle($im, 0, 0, 40, 20, $white); imagefilledrectangle($im, 0, 0, 9, 9, $red);
$r = FieldbookPhotos::applyExif($im, 3);
check('EXIF 3 (rotate 180): block moves to the bottom-right', imagesx($r) === 40 && (imagecolorat($r, 37, 17) & 0xFF0000) === 0xFF0000 && (imagecolorat($r, 2, 2) & 0xFFFFFF) === 0xFFFFFF);
imagedestroy($r);
$im = imagecreatetruecolor(40, 20); $white = imagecolorallocate($im, 255, 255, 255); $red = imagecolorallocate($im, 255, 0, 0);
imagefilledrectangle($im, 0, 0, 40, 20, $white); imagefilledrectangle($im, 0, 0, 9, 9, $red);
$r = FieldbookPhotos::applyExif($im, 1);
check('EXIF 1: unchanged', imagesx($r) === 40 && (imagecolorat($r, 2, 2) & 0xFF0000) === 0xFF0000);
imagedestroy($r);

// ------------------------------------------------------------------ 3. basemap overlay
function child($name, $pixel, $ori = array()) {
	return array('name' => $name, 'pixel' => $pixel, 'orientations' => $ori);
}
$kids = array(
	child('P1', array('type' => 'Point', 'coordinates' => array(816, 612))),                               // app pixels: 25 % from the left, 25 % up from the bottom
	child('SD', array('type' => 'Point', 'coordinates' => array(2448, 1836)), array(array('planar' => true, 'a' => '90', 'b' => '45', 'dipdir' => ''))),
	child('L1', array('type' => 'LineString', 'coordinates' => array(array(200, 2200), array(1400, 2200)))),
	child('A1', array('type' => 'Polygon', 'coordinates' => array(array(array(2600, 200), array(3100, 200), array(3100, 700), array(2600, 700), array(2600, 200))))),
	child('NoPos', null),
);
$ov = $ph->overlay('1004', 3264, 2448, $kids);
check('overlay: full-size figure, 4 children drawn, counted as promoted + overlay', $ov && $ov['w'] === 1400 && $ov['h'] === 1050 && $ov['drawn'] === 4 && $ph->overlays === 1 && $ph->promoted === 2, json_encode(array($ov ? $ov['w'] : null, $ov ? $ov['drawn'] : null)));
$im = imagecreatefromstring($ov['data']);
$sx = 1400 / 3264;
function dark($im, $x, $y) { $c = imagecolorat($im, (int)$x, (int)$y); return (($c >> 16) & 0xFF) < 80; }
function darkNear($im, $x, $y, $r = 6) { for ($i = -$r; $i <= $r; $i += 2) for ($j = -$r; $j <= $r; $j += 2) if (dark($im, $x + $i, $y + $j)) return true; return false; }
check('overlay: point P1 lands at 25 % from the left and 25 % up from the bottom (y flipped)', darkNear($im, 816 * $sx, 1050 - 612 * $sx, 4) && !darkNear($im, 816 * $sx, 612 * $sx, 2), '');
$cx = 2448 * $sx; $cy = 1050 - 1836 * $sx;
check('overlay: strike 090 bar runs east-west through the spot, dip tick points south', darkNear($im, $cx + 12, $cy, 2) && darkNear($im, $cx - 12, $cy, 2) && darkNear($im, $cx, $cy + 8, 2) && !darkNear($im, $cx, $cy - 26, 1));
check('overlay: line L1 drawn along its y', darkNear($im, 800 * $sx, 1050 - 2200 * $sx, 2));
$pc = imagecolorat($im, (int)(2850 * $sx), (int)(1050 - 450 * $sx));
check('overlay: polygon A1 tinted (not the plain light-gray basemap)', abs((($pc >> 16) & 0xFF) - 230) > 20 || abs(($pc & 0xFF) - 230) > 20, dechex($pc));
imagedestroy($im);
$ov2 = $ph->overlay('1004', 0, 0, array(child('X', array('type' => 'Point', 'coordinates' => array(100, 100)))));
check('overlay without app dimensions falls back to the file width', $ov2 && $ov2['drawn'] === 1);
check('overlay of a missing file => null', $ph->overlay('9999', 100, 100, $kids) === null);
$fo = FieldbookPhotos::firstOrientation(array('orientations' => array(array('planar' => true, 'a' => '', 'b' => '30', 'dipdir' => '120'), array('planar' => false, 'a' => '10', 'b' => '5'))));
check('firstOrientation: strike from dip direction (120 => 30)', $fo && $fo['planar'] && abs($fo['a'] - 30) < 1e-9 && $fo['b'] == 30);

// ------------------------------------------------------------------ 4. model: pixel geometry for children
$f = array('type' => 'Feature', 'original_geometry' => (object)array('type' => 'Point', 'coordinates' => array(1945.2, 2349.5)), 'geometry' => (object)array('type' => 'Point', 'coordinates' => array(149.25, -37.25)),
	'properties' => array('id' => '1721030400201', 'name' => 'child', 'image_basemap' => '1004'));
$b = FieldbookModel::spotBlock($f, array());
check('model: child keeps original_geometry pixels + real lon/lat coords line', $b['pixel'] && $b['pixel']['coordinates'][0] == 1945.2 && $b['point'] === null && $b['coords'][1][0] === 'Longitude');
$f2 = array('type' => 'Feature', 'geometry' => (object)array('type' => 'Point', 'coordinates' => array(1500, 900)), 'properties' => array('id' => '1721030400202', 'name' => 'old child', 'image_basemap' => '1004'));
$b2 = FieldbookModel::spotBlock($f2, array());
check('model: older child with pixels in geometry => pixel from geometry, image position line', $b2['pixel'] && $b2['pixel']['coordinates'][0] == 1500 && $b2['coords'][1][0] === 'Image position');
$f3 = array('type' => 'Feature', 'geometry' => (object)array('type' => 'Point', 'coordinates' => array(-118.2, 34.1)), 'properties' => array('id' => '1721030400203', 'name' => 'top'));
check('model: top-level spot has no pixel geometry', FieldbookModel::spotBlock($f3, array())['pixel'] === null);

// ------------------------------------------------------------------ 5. renderer integration (synthetic model)
function feature($id, $name, array $extra = array()) {
	$p = array('id' => $id, 'name' => $name, 'geometrytype' => 'Point', 'modified_timestamp' => '1722400000000', 'notes' => "notes $name") + $extra;
	$f = array('type' => 'Feature', 'geometry' => (object)array('type' => 'Point', 'coordinates' => array(-118.25, 34.05)), 'properties' => $p);
	if (isset($extra['image_basemap'])) { $f['original_geometry'] = (object)array('type' => 'Point', 'coordinates' => array(1000, 800)); }
	return $f;
}
$img = function ($id, $title, $type = 'photo', $caption = '', $w = 1600, $h = 1200) { return array('id' => $id, 'title' => $title, 'image_type' => $type, 'caption' => $caption, 'width' => $w, 'height' => $h, 'annotated' => false, 'modified_timestamp' => 1722400000000); };
$features = array(
	feature('1721030400101', 'Station A', array('images' => array($img('1001', 'Outcrop'), $img('1002', 'Portrait', 'photo', 'A long caption that will need to wrap onto a second line and then some more words to force the ellipsis at the end of it'), $img('1003', 'Small'), $img('9999', 'Lost')))),
	feature('1721034000102', 'Station B', array('images' => array($img('1004', 'Basemap', 'photo', 'Spots drawn on', 3264, 2448), $img('1005', 'Sketch of the face', 'sketch')))),
	feature('1721034000103', 'Detail on basemap', array('image_basemap' => '1004', 'orientation_data' => array(array('type' => 'planar_orientation', 'strike' => 30, 'dip' => 60, 'feature_type' => 'bedding', 'id' => 7)))),
	feature('1721120400105', 'Station C'),
);
$tree = array(array('owner' => 1, 'project_id' => 'p', 'project_name' => 'Photo Project', 'dsids' => array('d'), 'dataset_names' => array('d' => 'Photo Dataset'),
	'spot_map' => array('1721030400101' => array('ds' => 'd'), '1721034000102' => array('ds' => 'd'), '1721034000103' => array('ds' => 'd'), '1721120400105' => array('ds' => 'd'))));
function render($features, $tree, $mode, $TMP) {
	$meta = array('title' => 'Photo Dataset', 'subtitle' => '', 'owner' => 'Tester', 'generated' => 'today', 'doi' => '', 'options' => array('map' => 'none', 'photos' => $mode, 'nets' => 'on', 'page' => 'letter'));
	$model = FieldbookModel::build($features, array(), array(), $tree, $meta);
	$ph = new FieldbookPhotos(array('mode' => $mode, 'image_dir' => "$TMP/img", 'cache_dir' => "$TMP/cache", 'ttl_days' => 90));
	$r = new FieldbookRenderer($model, null, new FieldbookMaps(array('set' => 'none')), $ph);
	$pdf = $r->render();
	return array($r, $ph, $pdf->Output('', 'S'), $model);
}
list($r, $ph, $bytes, $model) = render($features, $tree, 'sheets', $TMP);
$images = preg_match_all('#/Subtype /Image#', $bytes);
check('model: the basemap child nests under image 1004', count($model->projects[0]['datasets'][0]['days'][0]['spots'][1]['images'][0]['children']) === 1);
check('sheets: 5 figures embedded (+ cover logo <= 2): 3 sheet thumbs, overlay, sketch', $images >= 5 && $images <= 7, $images);
check('sheets: counters 5 figures, 1 missing, 2 promoted (basemap overlay + sketch), 1 overlay', $ph->images === 5 && $ph->missing === 1 && $ph->promoted === 2 && $ph->overlays === 1, json_encode(array($ph->images, $ph->missing, $ph->promoted, $ph->overlays)));
$idx = $r->photoIndex;
$nos = array(); foreach ($idx as $e) $nos[] = $e['no'];
check('index: 6 images numbered 1..6 in book order (sheet before promoted), spots named, pages set', count($idx) === 6 && $nos === array(1, 2, 3, 4, 5, 6) && $idx[0]['spot'] === 'Station A' && $idx[4]['spot'] === 'Station B' && $idx[4]['title'] === 'Basemap' && $idx[0]['page'] >= 2 && $idx[5]['page'] >= $idx[0]['page'], json_encode($nos));
check('index: missing image still listed with its details', $idx[3]['title'] === 'Lost' && strpos($idx[3]['details'], 'id 9999') !== false);
check('index: basemap details mention the spot drawn on it', strpos($idx[4]['details'], '1 spot drawn on it') !== false, $idx[4]['details']);
check('colophon lines: figures + missing', count(array_filter($ph->notes(), function ($n) { return strpos($n, 'not found') !== false; })) === 1 && strpos($ph->notes()[0], '5 figures') !== false, json_encode($ph->notes()));

list($rf, $phf, $bytesF) = render($features, $tree, 'full', $TMP);
$imagesF = preg_match_all('#/Subtype /Image#', $bytesF);
check('full: every found image full width (5 promoted), same 5 figures', $phf->promoted === 5 && $phf->images === 5 && $imagesF === $images, json_encode(array($phf->promoted, $imagesF)));

list($rn, $phn, $bytesN) = render($features, $tree, 'none', $TMP);
$imagesN = preg_match_all('#/Subtype /Image#', $bytesN);
check('none: no figures, only the cover logo; index still has 6 entries with pages', $rn->photos === null && $imagesN <= 2 && count($rn->photoIndex) === 6 && $rn->photoIndex[5]['page'] >= 2, "$imagesN / " . count($rn->photoIndex));
check('none is smaller than sheets is smaller than full', strlen($bytesN) < strlen($bytes) && strlen($bytes) < strlen($bytesF), strlen($bytesN) . ' < ' . strlen($bytes) . ' < ' . strlen($bytesF));

// narrow block (child spot at indent) => 2-column sheet still renders; many images => rows paginate
$many = array(); for ($i = 0; $i < 20; $i++) $many[] = $img('100' . (1 + $i % 3), 'Photo ' . $i, 'photo', 'caption ' . $i);
$features2 = array(feature('1721030400101', 'Station A', array('images' => $many)));
$tree2 = array(array('owner' => 1, 'project_id' => 'p', 'project_name' => 'Photo Project', 'dsids' => array('d'), 'dataset_names' => array('d' => 'Photo Dataset'), 'spot_map' => array('1721030400101' => array('ds' => 'd'))));
list($r2, $ph2, $bytes2) = render($features2, $tree2, 'sheets', $TMP);
$pages2 = preg_match_all('#/Type /Page\b#', $bytes2);
check('20 photos on one spot: 20 figures over several pages, all indexed', $ph2->images === 20 && count($r2->photoIndex) === 20 && $pages2 >= 4 && $pages2 <= 12, "$pages2 pages");

rmrf($TMP);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
