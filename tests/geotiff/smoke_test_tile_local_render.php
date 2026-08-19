<?php
/**
 * File: smoke_test_tile_local_render.php
 * Description: Smoke test for geotiff/tile.php after the local-mapserv
 *              change (custom "My Maps" tiles render with the mapserv
 *              binary in THIS container instead of looping back through
 *              the public host Apache -- 2026-08-18 wedge hardening).
 *
 *              Covers:
 *                - in-extent tile renders real raster content (this is the
 *                  natural negative control: OLD code on dev fetched prod's
 *                  mapserv with a dev-only hash and echoed the error bytes)
 *                - out-of-extent world tile still returns a valid blank PNG
 *                - unknown hash returns 404 plus the error PNG
 *                - input validation: traversal-style hash and non-numeric
 *                  z/x/y are rejected before touching the filesystem
 *                - renders are deterministic (two fetches byte-identical)
 *
 *              Read-only: the fixture map is DISCOVERED from
 *              geotiff/upload/ (any .map with a matching .tif). Zero residue.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/geotiff/smoke_test_tile_local_render.php
 *
 * @package    StraboSpot Web Site
 */

chdir('/srv/app/www');

$BASE = 'http://localhost';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}

function http_get($url) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	curl_close($ch);
	return array($code, $ctype, $body);
}

function is_png($body) {
	return substr($body, 0, 8) == "\x89PNG\r\n\x1a\n";
}

echo "=== geotiff tile.php local-mapserv smoke ===\n";

// --- fixture discovery: any uploaded .map with a matching .tif ---
$hash = '';
foreach (glob('/srv/app/www/geotiff/upload/maps/*.map') as $mapfile) {
	$cand = basename($mapfile, '.map');
	if (file_exists("/srv/app/www/geotiff/upload/files/$cand.tif")) { $hash = $cand; break; }
}
if ($hash == '') {
	echo "  SKIP  no uploaded .map/.tif pair found in geotiff/upload/ -- cannot run\n";
	exit(1);
}
echo "  fixture hash: $hash\n";

// center of the geotiff, from gdalinfo
$info = shell_exec('gdalinfo /srv/app/www/geotiff/upload/files/' . $hash . '.tif 2>/dev/null');
$okUL = preg_match('/Upper Left\s*\(\s*(-?[\d.]+),\s*(-?[\d.]+)/', $info, $ul);
$okLR = preg_match('/Lower Right\s*\(\s*(-?[\d.]+),\s*(-?[\d.]+)/', $info, $lr);
check('fixture geotiff has parseable bounds', $okUL && $okLR);
if (!$okUL || !$okLR) { exit(1); }

$lon = ($ul[1] + $lr[1]) / 2;
$lat = ($ul[2] + $lr[2]) / 2;
$z = 10;
$tx = floor(($lon + 180) / 360 * pow(2, $z));
$ty = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) / 2 * pow(2, $z));
echo "  center tile: $z/$tx/$ty (lon $lon lat $lat)\n";

// --- 1. in-extent tile renders real raster content ---
list($code, $ctype, $body) = http_get("$BASE/geotiff/tiles/$hash/$z/$tx/$ty.png");
check('in-extent tile: HTTP 200', $code == 200, "got $code");
check('in-extent tile: image/png content type', strpos((string)$ctype, 'image/png') !== false, (string)$ctype);
check('in-extent tile: body is a real PNG', is_png($body));
check('in-extent tile: has raster content (not blank)', strlen($body) > 500, strlen($body) . 'b');

// --- 2. renders are deterministic ---
list($code2, $ctype2, $body2) = http_get("$BASE/geotiff/tiles/$hash/$z/$tx/$ty.png");
check('repeat render is byte-identical', $body2 === $body);

// --- 3. out-of-extent world tile: valid blank PNG ---
list($code, $ctype, $body) = http_get("$BASE/geotiff/tiles/$hash/0/0/0.png");
check('z0 world tile: HTTP 200 PNG', $code == 200 && is_png($body), "got $code, " . strlen($body) . 'b');

// --- 4. unknown hash: 404 + error PNG ---
list($code, $ctype, $body) = http_get("$BASE/geotiff/tiles/deadbeef12345/5/5/5.png");
check('unknown hash: HTTP 404', $code == 404, "got $code");
check('unknown hash: error image is a PNG', is_png($body));

// --- 5. input validation ---
list($code, $ctype, $body) = http_get("$BASE/geotiff/tile.php?hash=..%2F..%2Fetc%2Fpasswd&z=5&x=5&y=5");
check('traversal hash rejected', !is_png($body) && strpos($body, 'Invalid Request') !== false, substr($body, 0, 40));
list($code, $ctype, $body) = http_get("$BASE/geotiff/tile.php?hash=$hash&z=abc&x=5&y=5");
check('non-numeric zoom rejected', !is_png($body) && strpos($body, 'Invalid Request') !== false, substr($body, 0, 40));

echo "\n" . (count($failures) ? 'FAILURES: ' . count($failures) : 'ALL CHECKS PASSED') . "\n";
exit(count($failures) ? 1 : 0);
