<?php
/**
 * File: tests/fieldbook/smoke_test_build_page.php
 * Description: Smoke suite of the interactive Field Book build (docs/Fieldbook_Design.md
 *              §14 M6): fieldbook_build.php (page: run / attach / fetch modes),
 *              fieldbook_run.php (build + lock), fieldbook_status.php (poll,
 *              gating, dead-build detection), fieldbook_fetch.php (Content-Length,
 *              byte ranges, gating), the My Field Data dropdown, cleanup rule.
 *              Real web door over curl with forged PHP sessions (session files
 *              chown'd www-data), userpkey 3's read-only fixture dataset.
 *
 *              docker exec strabo-php php /srv/app/www/tests/fieldbook/smoke_test_build_page.php
 */
chdir('/srv/app/www'); $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('display_errors', 1); error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
require_once 'includes/fieldbook/FieldbookBuild.php';

$BASE = 'http://localhost'; $SESS = '/var/lib/php/sessions';
$DS = '17743978188553';   // Fiddlers Green / Geology (25 spots, 57 photos: dev has no files, so "Image unavailable" cells)
$pass = 0; $fail = 0; $sids = array(); $keys = array();
function check($name, $cond, $detail = '') { global $pass, $fail; if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? '  [' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . ']' : '') . "\n"; } }
function section($t) { echo "\n== $t\n"; }
function forge($pkey) {
	global $SESS, $sids;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	file_put_contents("$SESS/sess_$sid", 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:5:"probe";LAST_ACTIVITY|i:' . time() . ';');
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid; return $sid;
}
function http($path, $sid, array $headers = array(), $timeout = 300) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	$hdr = $headers; if ($sid !== null) $hdr[] = 'Cookie: PHPSESSID=' . $sid;
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $hdr));
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
	$h = substr((string)$raw, 0, $hs); $b = substr((string)$raw, $hs);
	$loc = preg_match('/^Location:\s*(.+)$/mi', $h, $m) ? trim($m[1]) : '';
	return array($code, $b, $loc, $h);
}
function hdr($headers, $name) { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : ''; }
function clean($body) { return stripos($body, 'Warning:') === false && stripos($body, 'Fatal error') === false && stripos($body, 'Notice:') === false; }
function keyOf($body) { return preg_match('/var fbKey = "([a-f0-9]{40})"/', $body, $m) ? $m[1] : ''; }
function modeOf($body) { return preg_match('/var fbMode = "(\w+)"/', $body, $m) ? $m[1] : ''; }
function cleanup() { global $sids, $SESS, $keys; foreach ($sids as $s) @unlink("$SESS/sess_$s"); $sids = array(); foreach ($keys as $k) FieldbookBuild::remove($k); $keys = array(); }

echo "Field Book progress page smoke suite\n";
$me = forge(3); $other = forge(94698);
$pageUrl = "/fieldbook_build?userpkey=3&dsids=$DS";

section('page: gating and modes');
list($code, $body, $loc) = http($pageUrl, null);
check('anonymous visitor redirected to login', $code === 302 && strpos($loc, '/login.php') !== false, "$code $loc");
list($code, $body, $loc) = http('/fieldbook_build', $me);
check('no dsids: 200 with the no-data card and a link back', $code === 200 && strpos($body, 'id="fb-nodata"') !== false && strpos($body, '/my_field_data') !== false && clean($body), $code);
list($code, $body, $loc) = http($pageUrl, $me);
$key = keyOf($body); $keys[] = $key;
check('page renders: 200, progress card, book title, four stages, key + mode in the script, no PHP noise', $code === 200 && strpos($body, 'id="fb-progress"') !== false && strpos($body, 'Geology') !== false && substr_count($body, 'data-stage=') === 4 && $key !== '' && clean($body), $code . ' ' . substr($body, 0, 200));
check('mode run on a first visit', modeOf($body) === 'run', modeOf($body));
check('no-JS fallback links the direct door with the same parameters', preg_match('~<noscript>.*?/searchdownload\?type=fieldbook&amp;dsids=' . $DS . '&amp;userpkey=3&amp;fb_map=outdoors.*?</noscript>~s', $body) === 1);
check('options line shows the defaults', strpos($body, 'basemap outdoors, photos sheets, stereonets on, letter pages') !== false);
$st = FieldbookBuild::load($key);
check('state file written as queued for userpkey 3 with the normalised params', $st && $st['state'] === 'queued' && (int)$st['userpkey'] === 3 && $st['params']['dsids'] === $DS && $st['params']['fb_page'] === 'letter', $st);
list($code, $body2) = http("/fieldbook_build?userpkey=3&dsids=$DS&fb_page=a4&fb_map=none", $me);
$keyA4 = keyOf($body2); $keys[] = $keyA4;
check('different options => different key', $keyA4 !== '' && $keyA4 !== $key);
list($code, $body3) = http("/fieldbook_build?userpkey=3&dsids=$DS,$DS&fb_map=OUTDOORS", $me);
check('duplicate dsids and option case normalise to the same key', keyOf($body3) === $key, keyOf($body3));

section('status: gating before the run');
list($code, $body) = http("/fieldbook_status?k=$key", $me);
$d = json_decode($body, true);
check('status for the owner: found, queued, stage gather, elapsed 0', $d && $d['found'] && $d['state'] === 'queued' && $d['stage'] === 'gather' && $d['stage_index'] === 0 && $d['fetch_url'] === '', $body);
list($code, $body) = http("/fieldbook_status?k=$key", $other);
check('status for another user: found false', $code === 200 && json_decode($body, true) === array('found' => false), $body);
list($code, $body) = http("/fieldbook_status?k=" . str_repeat('0', 40), $me);
check('unknown key: found false', json_decode($body, true) === array('found' => false), $body);
list($code, $body) = http("/fieldbook_status?k=../etc/passwd", $me);
check('malformed key: found false', json_decode($body, true) === array('found' => false), $body);

section('run: builds the book');
list($code, $body) = http("/fieldbook_run?k=$key", $other);
check('run as another user: 404 found false', $code === 404 && json_decode($body, true) === array('found' => false), "$code $body");
$t0 = microtime(true);
list($code, $body) = http("/fieldbook_run?k=$key", $me);
$d = json_decode($body, true);
check('run as the owner: 200 JSON done with bytes and a fetch url', $code === 200 && $d && $d['state'] === 'done' && $d['bytes'] > 100000 && $d['fetch_url'] === "/fieldbook_fetch?k=$key", $body);
$st = FieldbookBuild::load($key);
check('state file: done, filename, bytes = file size, started/finished stamps', $st && $st['state'] === 'done' && substr($st['filename'], -4) === '.pdf' && $st['bytes'] === filesize(FieldbookBuild::pdfPath($key)) && $st['started'] && $st['finished'] >= $st['started'], $st);
check('every stage was reported in order (gather, build, summary, write)', $st && $st['stages_seen'] === array('gather', 'build', 'summary', 'write'), $st ? $st['stages_seen'] : null);
check('spot progress reached the total', $st && $st['done'] === 1 && $st['total'] === 1 && $st['note'] === 'Done', $st);
check('scratch dir removed', !is_dir(FieldbookBuild::dir() . "/$key.tmp") && !file_exists(FieldbookBuild::statePath($key) . '.lock'));
$finished1 = $st['finished'];
list($code, $body) = http("/fieldbook_status?k=$key", $me);
$d = json_decode($body, true);
check('status after the run: done, stage write index 3, fetch url, title', $d && $d['state'] === 'done' && $d['stage_index'] === 3 && $d['fetch_url'] !== '' && $d['title'] === 'Geology', $body);
list($code, $body) = http("/fieldbook_run?k=$key", $me);
$st2 = FieldbookBuild::load($key);
check('run again within the reuse window: answers done without rebuilding', $code === 200 && json_decode($body, true)['state'] === 'done' && $st2['finished'] === $finished1, $body);

section('fetch: Content-Length + ranges');
list($code, $body, $loc, $h) = http("/fieldbook_fetch?k=$key", $me);
$size = filesize(FieldbookBuild::pdfPath($key));
check('fetch: 200 application/pdf inline, Content-Length = file, Accept-Ranges, body is the PDF', $code === 200 && hdr($h, 'Content-Type') === 'application/pdf' && strpos(hdr($h, 'Content-Disposition'), 'inline; filename="Geology_fieldbook_') === 0 && (int)hdr($h, 'Content-Length') === $size && hdr($h, 'Accept-Ranges') === 'bytes' && strlen($body) === $size && substr($body, 0, 5) === '%PDF-' && trim(substr($body, -6)) === '%%EOF', "$code " . substr($h, 0, 400));
list($code, $body, $loc, $h) = http("/fieldbook_fetch?k=$key", $me, array('Range: bytes=0-99'));
check('range 0-99: 206, 100 bytes, Content-Range', $code === 206 && strlen($body) === 100 && hdr($h, 'Content-Range') === "bytes 0-99/$size" && (int)hdr($h, 'Content-Length') === 100 && substr($body, 0, 5) === '%PDF-', "$code " . hdr($h, 'Content-Range'));
list($code, $body, $loc, $h) = http("/fieldbook_fetch?k=$key", $me, array('Range: bytes=' . ($size - 10) . '-'));
check('open-ended tail range: 206, last 10 bytes', $code === 206 && strlen($body) === 10 && hdr($h, 'Content-Range') === 'bytes ' . ($size - 10) . '-' . ($size - 1) . "/$size", "$code " . hdr($h, 'Content-Range'));
list($code, $body, $loc, $h) = http("/fieldbook_fetch?k=$key", $me, array('Range: bytes=-6'));
check('suffix range -6: the %%EOF tail', $code === 206 && trim($body) === '%%EOF', "$code [$body]");
list($code, $body, $loc, $h) = http("/fieldbook_fetch?k=$key", $me, array('Range: bytes=' . ($size + 5) . '-'));
check('range past the end: 416 with Content-Range */size', $code === 416 && hdr($h, 'Content-Range') === "bytes */$size", "$code");
list($code, $body) = http("/fieldbook_fetch?k=$key", $other);
check('fetch as another user: 404', $code === 404 && strpos($body, 'no longer available') !== false, $code);
list($code, $body) = http("/fieldbook_fetch?k=$key", null);
check('fetch anonymous: login redirect', $code === 302);

section('page: fetch and attach modes');
list($code, $body, $loc) = http($pageUrl, $me);
check('page while a fresh book exists: 302 straight to fetch', $code === 302 && $loc === "/fieldbook_fetch?k=$key", "$code $loc");
$st = FieldbookBuild::load($key); $st['finished'] = microtime(true) - FieldbookBuild::REUSE_SECONDS - 5; FieldbookBuild::save($key, $st);
list($code, $body, $loc) = http($pageUrl, $me);
check('page after the reuse window: mode run again (state re-queued)', $code === 200 && modeOf($body) === 'run' && FieldbookBuild::load($key)['state'] === 'queued', "$code " . modeOf($body));
$st = FieldbookBuild::load($key); $st['state'] = 'running'; $st['stage'] = 'build'; $st['done'] = 7; $st['total'] = 25; $st['note'] = 'Day 2 of 4: Friday, photo 3 of 57'; FieldbookBuild::save($key, $st);
list($code, $body, $loc) = http($pageUrl, $me);
check('page while a build is running: mode attach, joining headline', $code === 200 && modeOf($body) === 'attach' && strpos($body, 'already being built') !== false, "$code " . modeOf($body));
list($code, $body) = http("/fieldbook_status?k=$key", $me);
$d = json_decode($body, true);
check('status of a running build: stage build index 1, 7 of 25, the note', $d && $d['state'] === 'running' && $d['stage_index'] === 1 && $d['done'] === 7 && $d['total'] === 25 && $d['note'] === 'Day 2 of 4: Friday, photo 3 of 57', $body);
list($code, $body) = http("/fieldbook_run?k=$key", $me);
$d = json_decode($body, true);
check('run while a fresh build is running: attaches, does not rebuild', $d && !empty($d['attached']) && $d['state'] === 'running' && FieldbookBuild::load($key)['state'] === 'running', $body);
$st = FieldbookBuild::load($key); $st['updated'] = microtime(true) - FieldbookBuild::STALE_SECONDS - 5; file_put_contents(FieldbookBuild::statePath($key), json_encode($st));
list($code, $body) = http("/fieldbook_status?k=$key", $me);
$d = json_decode($body, true);
check('status of a dead build (no write for STALE_SECONDS): reported failed with a retry message', $d && $d['state'] === 'failed' && strpos($d['error'], 'try again') !== false, $body);
list($code, $body, $loc) = http($pageUrl, $me);
check('page after a dead build: mode run', $code === 200 && modeOf($body) === 'run', modeOf($body));

section('lock: a second run request while the first holds the lock');
// flock does NOT hold across processes on the dev Mac bind mount (Docker Desktop virtiofs); it does on
// container-local disk and on prod's volume. Probe first; the state-file guard above covers the common case.
$lockPath = FieldbookBuild::statePath($key) . '.lock';
$probe = FieldbookBuild::dir() . '/flockprobe'; $pf = fopen($probe, 'c'); flock($pf, LOCK_EX);
$cross = trim((string)shell_exec('php -r ' . escapeshellarg('$f = fopen(' . var_export($probe, true) . ', "c"); echo flock($f, LOCK_EX | LOCK_NB) ? "free" : "held";')));
flock($pf, LOCK_UN); fclose($pf); @unlink($probe);
if ($cross === 'held') {
	$lock = fopen($lockPath, 'c'); flock($lock, LOCK_EX);
	list($code, $body) = http("/fieldbook_run?k=$key", $me);
	$d = json_decode($body, true);
	check('locked key: run answers attached without building', $d && !empty($d['attached']) && FieldbookBuild::load($key)['state'] === 'queued', $body);
	flock($lock, LOCK_UN); fclose($lock); @unlink($lockPath);
} else {
	echo "  SKIP  locked key (flock is not shared across processes on this filesystem: $cross)\n";
}

section('failure: a dataset with no spots');
list($code, $body) = http("/fieldbook_build?userpkey=3&dsids=1", $me);
$keyNo = keyOf($body); $keys[] = $keyNo;
check('page for an empty dataset still renders in run mode', $code === 200 && modeOf($body) === 'run' && $keyNo !== '', $code);
list($code, $body) = http("/fieldbook_run?k=$keyNo", $me);
$d = json_decode($body, true);
check('run: failed with the no-spots message, no PDF', $d && $d['state'] === 'failed' && strpos($d['error'], 'No spots found') !== false && !is_file(FieldbookBuild::pdfPath($keyNo)), $body);
list($code, $body) = http("/fieldbook_status?k=$keyNo", $me);
check('status: failed + error', json_decode($body, true)['state'] === 'failed');
list($code, $body) = http("/fieldbook_fetch?k=$keyNo", $me);
check('fetch of a failed build: 404', $code === 404);
list($code, $body, $loc) = http("/fieldbook_build?userpkey=3&dsids=1", $me);
check('page after a failure: mode run (retry = reload)', $code === 200 && modeOf($body) === 'run');

section('doors');
$mfd = file_get_contents('my_field_data.php');
check('My Field Data dropdown opens the progress page', strpos($mfd, "window.open('/fieldbook_build?userpkey=<?php echo \$userpkey?>&dsids='+id)") !== false && strpos($mfd, "window.open('/searchdownload?type=fieldbook") === false);
check('direct door still serves the PDF with Content-Length', (function () use ($DS, $me) { list($code, $body, $loc, $h) = http("/searchdownload?type=fieldbook&userpkey=3&dsids=$DS", $me); return $code === 200 && (int)hdr($h, 'Content-Length') === strlen($body) && substr($body, 0, 5) === '%PDF-'; })());
$cl = file_get_contents('exportjobs/cleanup_data.sh');
check('cleanup rule 5 sweeps the fieldbook folder by FIELDBOOK_HOURS', strpos($cl, 'FIELDBOOK_HOURS="${FIELDBOOK_HOURS:-24}"') !== false && strpos($cl, '"$DATA_DIR/fieldbook" -mindepth 1 -maxdepth 1 -mmin +$((FIELDBOOK_HOURS * 60))') !== false);
check('build folder is under the export data root (denied to Apache)', strpos(FieldbookBuild::dir(), export_config()['data_root'] . '/fieldbook') === 0 && (function () use ($key) { list($code) = http('/exportjobs_data/fieldbook/' . $key . '.pdf', null); return $code === 403; })());

cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
