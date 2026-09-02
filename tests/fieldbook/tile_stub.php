<?php
/**
 * File: tests/fieldbook/tile_stub.php
 * Description: Router for PHP's built-in server: a stub tile proxy for the
 *              fieldbook map suite. Serves /v5/<set>/z/x/y.png as generated
 *              256 px PNGs (set-specific colour, coordinates printed),
 *              answers 404 for every tile whose x is odd while the file
 *              <log dir>/fail_odd exists, and appends one line per request
 *              to <log dir>/requests.log so the suite can count fetches.
 *              Started by smoke_test_maps.php:
 *                php -S 127.0.0.1:<port> tests/fieldbook/tile_stub.php
 *              with TILE_STUB_LOG in the environment.
 */
$log = getenv('TILE_STUB_LOG') ?: sys_get_temp_dir();
$uri = $_SERVER['REQUEST_URI'];
@file_put_contents($log . '/requests.log', $uri . "\n", FILE_APPEND);
if (!preg_match('#^/v5/([a-z.]+)/(\d+)/(\d+)/(\d+)\.png$#', $uri, $m)) { http_response_code(404); echo 'no'; return true; }
list(, $set, $z, $x, $y) = $m;
if (is_file($log . '/fail_odd') && ((int)$x % 2) === 1) { http_response_code(404); echo 'missing'; return true; }
if (is_file($log . '/slow')) usleep(1500000);
$im = imagecreatetruecolor(256, 256);
$bg = $set === 'macrostrat' ? imagecolorallocate($im, 220, 120, 120) : ($set === 'mapbox.satellite' ? imagecolorallocate($im, 60, 70, 60) : imagecolorallocate($im, 225, 235, 215));
imagefilledrectangle($im, 0, 0, 255, 255, $bg);
$line = imagecolorallocate($im, 160, 160, 160);
imagerectangle($im, 0, 0, 255, 255, $line);
imagestring($im, 3, 8, 8, "$set", $line);
imagestring($im, 3, 8, 24, "$z/$x/$y", $line);
header('Content-Type: image/png');
imagepng($im);
imagedestroy($im);
return true;
