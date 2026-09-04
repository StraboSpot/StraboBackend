<?php
/**
 * File: fieldbook_fetch.php
 * Description: Serves a finished Field Book (?k=build key) inline to the
 *              signed-in user who built it (docs/Fieldbook_Design.md §14 M6):
 *              Content-Length, Accept-Ranges and single-range 206 replies, so
 *              the browser's PDF viewer can show the first pages while the
 *              rest of a large book is still downloading and can seek through
 *              it afterwards. Anything else (unknown key, another user, build
 *              not done, file swept) is a plain 404.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
include("logincheck.php");
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();
require_once("includes/fieldbook/FieldbookBuild.php");

$key = (string)($_GET['k'] ?? '');
$state = FieldbookBuild::load($key);
$file = $state ? FieldbookBuild::pdfPath($key) : '';
if (!$state || (int)$state['userpkey'] !== $userpkey || $state['state'] !== 'done' || !is_file($file)) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	echo "This Field Book is no longer available. Please build it again from My StraboField Data.\n";
	exit();
}
$size = filesize($file);
$start = 0; $end = $size - 1; $status = 200;
$range = isset($_SERVER['HTTP_RANGE']) ? (string)$_SERVER['HTTP_RANGE'] : '';
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
	if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
	else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($size - 1, (int)$m[2]); }
	if ($start > $end || $start >= $size) {
		http_response_code(416);
		header("Content-Range: bytes */$size");
		exit();
	}
	$status = 206;
}
$name = $state['filename'] !== '' ? $state['filename'] : 'fieldbook.pdf';
http_response_code($status);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace(array('"', "\r", "\n"), '', $name) . '"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
if ($status === 206) header("Content-Range: bytes $start-$end/$size");
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
if (ob_get_level()) ob_end_clean();
$fh = fopen($file, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
	$chunk = fread($fh, min(1048576, $left));
	if ($chunk === false || $chunk === '') break;
	echo $chunk; $left -= strlen($chunk);
	flush();
}
fclose($fh);
