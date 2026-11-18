<?php
/**
 * File: tests/ota/ota_test.php
 * Description: Suite for the StraboField over-the-air update server (/ota/):
 *              publish API (token, file upload + hash check, release checks:
 *              signature, asset URLs, missing files, duplicate ids, dates),
 *              manifest selection (platform, runtime version, channel,
 *              withdrawn, current update, directives), the protocol envelope
 *              (headers, multipart, JSON form, 406/400), asset serving
 *              (content type, caching, ETag, HEAD, unreferenced files) and the
 *              install / crash logging.
 *
 *              Needs the dev settings in includes/config.inc.php (token
 *              "ota-dev-test-token", OTA_CODE_SIGNING_CERT) and the dev key pair
 *              in ota_data/dev_keys/. Uses channel "phptest" and removes every
 *              release, log row and file it made.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/ota/ota_test.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/ota/Ota.php';

$BASE = 'http://localhost';
$TOKEN = 'ota-dev-test-token';
$CH = 'phptest';
$RT = 'phptest-' . bin2hex(random_bytes(4));
$KEY = openssl_pkey_get_private(file_get_contents('/srv/app/www/ota_data/dev_keys/private-key.pem'));
$odb = new OtaDb($db);
$madeAssets = array();

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 700) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

/** HTTP call; returns [status, headers (lowercase => value), body]. */
function http($method, $path, $headers = array(), $body = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	$h = array();
	foreach ($headers as $k => $v) $h[] = "$k: $v";
	curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_NOBODY => $method === 'HEAD'));
	if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	$raw = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$out = array();
	foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) {
		$i = strpos($line, ':');
		if ($i) $out[strtolower(trim(substr($line, 0, $i)))] = trim(substr($line, $i + 1));
	}
	return array($status, $out, substr($raw, $hs));
}
function pub($method, $path, $json = null, $token = null) {
	global $TOKEN;
	$h = array('Authorization' => 'Bearer ' . ($token === null ? $TOKEN : $token));
	$body = null;
	if ($json !== null) { $h['Content-Type'] = 'application/json'; $body = json_encode($json, JSON_UNESCAPED_SLASHES); }
	list($s, $hd, $b) = http($method, '/ota/publish/' . $path, $h, $body);
	return array($s, json_decode($b, true), $b);
}
function phone($platform, $runtime, $extra = array()) {
	global $CH;
	$h = array_merge(array('expo-protocol-version' => '1', 'expo-platform' => $platform,
		'expo-runtime-version' => $runtime, 'expo-channel-name' => $CH,
		'accept' => 'multipart/mixed,application/expo+json,application/json'), $extra);
	return http('GET', '/ota/manifest', $h);
}
function b64url($raw) { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function sign($body) { global $KEY; openssl_sign($body, $sig, $KEY, OPENSSL_ALGO_SHA256); return base64_encode($sig); }
function uuid() { $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }
function upload($bytes) {
	global $madeAssets;
	$hex = hash('sha256', $bytes);
	$madeAssets[] = $hex;
	list($s, $j) = array_slice(http('PUT', "/ota/publish/assets/$hex",
		array('Authorization' => 'Bearer ota-dev-test-token', 'Content-Type' => 'application/octet-stream'), $bytes), 0, 1)
		+ array(1 => null);
	return array($hex, $s);
}
function entry($bytes, $ext, $ct) {
	global $BASE;
	$raw = hash('sha256', $bytes, true);
	return array('hash' => b64url($raw), 'key' => md5($bytes), 'contentType' => $ct,
		'fileExtension' => $ext ?: null, 'url' => $BASE . '/ota/assets/' . bin2hex($raw) . $ext);
}
function manifest($runtime, $launch, $assets, $createdAt = null) {
	return array('id' => uuid(), 'createdAt' => $createdAt ?: gmdate('Y-m-d\TH:i:s.000\Z'),
		'runtimeVersion' => $runtime, 'launchAsset' => $launch, 'assets' => $assets,
		'metadata' => new stdClass(), 'extra' => array('expoClient' => array('name' => 'phptest')));
}
function release($platform, $runtime, $doc, $kind = 'update', $sign = true) {
	global $CH;
	$body = json_encode($doc, JSON_UNESCAPED_SLASHES);
	return pub('POST', 'releases', array('kind' => $kind, 'platform' => $platform, 'runtimeVersion' => $runtime,
		'channel' => $CH, 'body' => $body, 'signature' => $sign ? sign($body) : null, 'message' => 'phptest'));
}
function parts($headers, $body) {
	if (!preg_match('/boundary=(\S+)/', $headers['content-type'] ?? '', $m)) return array();
	$out = array();
	foreach (array_slice(explode('--' . $m[1], $body), 1) as $c) {
		if (strpos($c, '--') === 0) break;
		$c = preg_replace('/^\r\n|\r\n$/', '', $c);
		list($hs, $b) = explode("\r\n\r\n", $c, 2);
		$h = array();
		foreach (explode("\r\n", $hs) as $l) { $i = strpos($l, ':'); $h[strtolower(substr($l, 0, $i))] = trim(substr($l, $i + 1)); }
		preg_match('/name="([^"]+)"/', $h['content-disposition'], $n);
		$out[$n[1]] = array('headers' => $h, 'body' => $b);
	}
	return $out;
}
function cleanup() {
	global $odb, $madeAssets, $CH;
	$odb->q("DELETE FROM ota_releases WHERE channel = $1", array($CH));
	$odb->q("DELETE FROM ota_daily_clients WHERE channel = $1", array($CH));
	$odb->q("DELETE FROM ota_fatal_errors WHERE channel = $1", array($CH));
	foreach (array_unique($madeAssets) as $hex) {
		if ($odb->val("SELECT 1 FROM ota_release_assets WHERE sha256 = $1 LIMIT 1", array($hex)) === null) {
			$odb->q("DELETE FROM ota_assets WHERE sha256 = $1", array($hex));
			@unlink(Ota::assetPath($hex));
		}
	}
}

cleanup();
$tag = bin2hex(random_bytes(8));
$bundleI = "/* ios bundle $tag */" . str_repeat('x', 5000);
$bundleA = "/* android bundle $tag */" . str_repeat('y', 5000);
$png = "\x89PNG fake image $tag";

section('Publish API: token');
list($s, $j) = pub('GET', 'ping', null, 'wrong-token');
check('wrong token is 401', $s === 401, $s);
list($s, $j) = pub('GET', 'ping');
check('ping with the token', $s === 200 && $j['certificateInstalled'] === true && $j['assetUrlPrefix'] === $BASE . '/ota/assets/', $j);
list($s) = http('GET', '/ota/publish/releases');
check('no Authorization header is 401', $s === 401, $s);

section('Publish API: files');
list($s, $j) = pub('POST', 'assets/missing', array('sha256' => array(hash('sha256', $bundleI), hash('sha256', $png))));
check('missing lists both new files', $s === 200 && count($j['missing']) === 2, $j);
list($s, $hd, $b) = http('PUT', '/ota/publish/assets/' . hash('sha256', 'something else'),
	array('Authorization' => "Bearer $TOKEN"), $bundleI);
check('upload under the wrong hash is refused', $s === 400 && strpos($b, 'hash_mismatch') !== false, "$s $b");
check('refused upload leaves no file', !is_file(Ota::assetPath(hash('sha256', 'something else'))));
foreach (array($bundleI, $bundleA, $png) as $f) {
	list($hex, $s) = upload($f);
	check("upload " . substr($hex, 0, 8) . " is 201", $s === 201, $s);
}
list($s, $j) = pub('POST', 'assets/missing', array('sha256' => array(hash('sha256', $bundleI), hash('sha256', $png))));
check('missing is empty after upload', $s === 200 && $j['missing'] === array(), $j);
list($s, $j) = pub('POST', 'assets/missing', array('sha256' => array('NOTHEX')));
check('bad hash in missing is 400', $s === 400, $s);
list($s) = http('GET', '/ota/assets/' . hash('sha256', $png) . '.png');
check('uploaded file is not served before a release uses it', $s === 404, $s);

section('Publish API: release checks');
$launchI = entry($bundleI, '.hbc', 'application/javascript');
$pngE = entry($png, '.png', 'image/png');
$m = manifest($RT, $launchI, array($pngE));
list($s, $j) = release('ios', $RT, $m, 'update', false);
check('unsigned release refused', $s === 400 && $j['error'] === 'signature_required', $j);
$body = json_encode($m, JSON_UNESCAPED_SLASHES);
list($s, $j) = pub('POST', 'releases', array('kind' => 'update', 'platform' => 'ios', 'runtimeVersion' => $RT,
	'channel' => $CH, 'body' => $body . ' ', 'signature' => sign($body)));
check('body changed after signing refused', $s === 400 && $j['error'] === 'bad_signature', $j);
$bad = $m; $bad['launchAsset']['url'] = 'https://evil.example/x.hbc';
list($s, $j) = release('ios', $RT, $bad);
check('asset URL elsewhere refused', $s === 400 && $j['error'] === 'bad_asset_url', $j);
$bad = $m; $bad['assets'][0]['hash'] = b64url(hash('sha256', 'not uploaded', true));
list($s, $j) = release('ios', $RT, $bad);
check('hash not matching its URL refused', $s === 400 && $j['error'] === 'bad_asset_url', $j);
$bad = $m; $bad['assets'][] = entry('never uploaded ' . $tag, '.png', 'image/png');
list($s, $j) = release('ios', $RT, $bad);
check('file not uploaded refused (409)', $s === 409 && $j['error'] === 'assets_missing', $j);
$bad = $m; $bad['runtimeVersion'] = 'other';
list($s, $j) = release('ios', $RT, $bad);
check('runtimeVersion mismatch refused', $s === 400, $j);
$bad = $m; $bad['createdAt'] = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
list($s, $j) = release('ios', $RT, $bad);
check('date in the future refused', $s === 400 && $j['error'] === 'bad_time', $j);
$bad = $m; $bad['id'] = 'not-a-uuid';
list($s, $j) = release('ios', $RT, $bad);
check('bad id refused', $s === 400, $j);
list($s, $j) = pub('POST', 'releases', array('kind' => 'update', 'platform' => 'web', 'runtimeVersion' => $RT, 'body' => '{}'));
check('platform web refused', $s === 400 && $j['error'] === 'bad_platform', $j);

section('Manifest: before any release');
list($s, $hd) = phone('ios', $RT);
check('no release: 204', $s === 204, $s);
check('204 carries protocol headers', ($hd['expo-protocol-version'] ?? '') === '1' && ($hd['expo-sfv-version'] ?? '') === '0'
	&& ($hd['cache-control'] ?? '') === 'private, max-age=0', $hd);

section('Publish + serve an update');
$m1 = manifest($RT, $launchI, array($pngE), gmdate('Y-m-d\TH:i:s.000\Z', time() - 60));
list($s, $j) = release('ios', $RT, $m1);
check('signed release stored', $s === 201 && $j['updateId'] === $m1['id'] && $j['files'] === 2 && $j['signed'] === true, $j);
$rel1 = $j['releaseId'];
list($s, $j) = release('ios', $RT, $m1);
check('same id again refused', $s === 409, $j);
list($s, $hd, $b) = phone('ios', $RT, array('expo-expect-signature' => 'sig, keyid="main", alg="rsa-v1_5-sha256"',
	'eas-client-id' => "client-a-$tag"));
$p = parts($hd, $b);
check('update: 200 multipart', $s === 200 && strpos($hd['content-type'], 'multipart/mixed; boundary=') === 0, $hd);
check('manifest part bytes are exactly the published body', isset($p['manifest']) && $p['manifest']['body'] === json_encode($m1, JSON_UNESCAPED_SLASHES));
check('manifest part headers', isset($p['manifest']) && $p['manifest']['headers']['content-type'] === 'application/json; charset=utf-8'
	&& $p['manifest']['headers']['content-disposition'] === 'form-data; name="manifest"', $p['manifest']['headers'] ?? '');
check('extensions part present', isset($p['extensions']) && json_decode($p['extensions']['body'], true) === array('assetRequestHeaders' => array()));
$sigOk = false;
if (isset($p['manifest']['headers']['expo-signature']) && preg_match('/^sig="([^"]+)", keyid="main"$/', $p['manifest']['headers']['expo-signature'], $mm)) {
	$cert = openssl_pkey_get_public(file_get_contents(OTA_CODE_SIGNING_CERT));
	$sigOk = openssl_verify($p['manifest']['body'], base64_decode($mm[1]), $cert, OPENSSL_ALGO_SHA256) === 1;
}
check('expo-signature verifies against the certificate', $sigOk, $p['manifest']['headers'] ?? '');
list($s, $hd, $b) = phone('ios', $RT);
$p = parts($hd, $b);
check('no expo-expect-signature: no signature header', $s === 200 && !isset($p['manifest']['headers']['expo-signature']));
list($s) = phone('android', $RT);
check('other platform: 204', $s === 204, $s);
list($s) = phone('ios', $RT . 'x');
check('other runtime version: 204', $s === 204, $s);
list($s) = phone('ios', $RT, array('expo-channel-name' => 'production'));
check('other channel: 204', $s === 204, $s);
list($s) = phone('ios', $RT, array('expo-current-update-id' => strtoupper($m1['id'])));
check('phone already on it: 204', $s === 204, $s);
list($s, $hd, $b) = phone('ios', $RT, array('accept' => 'application/expo+json'));
check('JSON form for a manifest', $s === 200 && $hd['content-type'] === 'application/expo+json' && $b === json_encode($m1, JSON_UNESCAPED_SLASHES), $hd);
list($s) = phone('ios', $RT, array('accept' => 'text/html'));
check('unacceptable accept: 406', $s === 406, $s);
list($s) = phone('ios', $RT, array('expo-protocol-version' => '0'));
check('protocol 0: 406', $s === 406, $s);
list($s) = phone('web', $RT);
check('platform web: 400', $s === 400, $s);
list($s) = phone('ios', 'bad runtime!');
check('bad runtime version: 400', $s === 400, $s);

section('Asset serving');
$u = parse_url($pngE['url'], PHP_URL_PATH);
list($s, $hd, $b) = http('GET', $u);
check('png served', $s === 200 && $b === $png, $s);
check('png content type + immutable caching', ($hd['content-type'] ?? '') === 'image/png'
	&& ($hd['cache-control'] ?? '') === 'public, max-age=31536000, immutable', $hd);
list($s, $hd, $b) = http('GET', parse_url($launchI['url'], PHP_URL_PATH));
check('bundle served as application/javascript', $s === 200 && $b === $bundleI && strpos($hd['content-type'], 'application/javascript') === 0, $hd);
list($s, $hd, $b) = http('HEAD', $u);
check('HEAD: 200, length, no body', $s === 200 && (int)$hd['content-length'] === strlen($png) && $b === '', $hd);
list($s) = http('GET', $u, array('If-None-Match' => '"' . hash('sha256', $png) . '"'));
check('matching ETag: 304', $s === 304, $s);
list($s) = http('GET', '/ota/assets/' . hash('sha256', $png) . '.jpg');
check('same file under another extension: 404', $s === 404, $s);
list($s) = http('GET', '/ota_data/assets/' . substr(hash('sha256', $png), 0, 2) . '/' . hash('sha256', $png));
check('ota_data never served directly', $s === 403, $s);
list($s) = http('POST', $u);
check('POST to an asset: 405', $s === 405, $s);

section('Newer update, withdraw, republish');
$m2 = manifest($RT, $launchI, array());
list($s, $j) = release('ios', $RT, $m2);
$rel2 = $j['releaseId'] ?? 0;
check('second update stored', $s === 201, $j);
list($s, $hd, $b) = phone('ios', $RT);
$p = parts($hd, $b);
check('newest update served', isset($p['manifest']) && json_decode($p['manifest']['body'], true)['id'] === $m2['id']);
list($s, $j) = release('ios', $RT, manifest($RT, $launchI, array(), gmdate('Y-m-d\TH:i:s.000\Z', time() - 3600)));
check('release dated before the newest refused', $s === 409 && $j['error'] === 'not_newest', $j);
list($s, $j) = pub('POST', "releases/$rel2/withdraw", array('note' => 'phptest'));
check('withdraw', $s === 200 && $j['withdrawnAt'] !== null && $j['withdrawnNote'] === 'phptest', $j);
list($s, $hd, $b) = phone('ios', $RT);
$p = parts($hd, $b);
check('withdrawn: previous update served again', isset($p['manifest']) && json_decode($p['manifest']['body'], true)['id'] === $m1['id']);
list($s, $hd) = http('GET', parse_url($launchI['url'], PHP_URL_PATH));
check('files of a withdrawn update still served', $s === 200, $s);
list($s, $j) = pub('POST', "releases/$rel2/restore");
check('restore', $s === 200 && $j['withdrawnAt'] === null, $j);
list($s, $j) = pub('GET', "releases/$rel1");
check('get one release returns the exact body', $s === 200 && $j['body'] === json_encode($m1, JSON_UNESCAPED_SLASHES), $j);
list($s, $j) = pub('GET', "releases?channel=$CH&runtimeVersion=$RT");
check('list filtered', $s === 200 && count($j['releases']) === 2 && $j['releases'][0]['releaseId'] === $rel2, $j);
list($s) = pub('POST', 'releases/999999999/withdraw');
check('withdraw unknown: 404', $s === 404, $s);

section('Directive');
usleep(1100000); // dates are compared to the second here; a release must be strictly newer
$d = array('type' => 'rollBackToEmbedded', 'parameters' => array('commitTime' => gmdate('Y-m-d\TH:i:s.000\Z')));
list($s, $j) = release('ios', $RT, $d, 'update');
check('directive sent as kind update refused', $s === 400, $j);
list($s, $j) = release('ios', $RT, $d, 'rollBackToEmbedded');
check('rollBackToEmbedded stored', $s === 201 && $j['kind'] === 'rollBackToEmbedded' && $j['updateId'] === null, $j);
list($s, $hd, $b) = phone('ios', $RT, array('expo-expect-signature' => 'sig, keyid="main"'));
$p = parts($hd, $b);
check('directive part served, signed', $s === 200 && isset($p['directive']) && !isset($p['manifest'])
	&& $p['directive']['body'] === json_encode($d, JSON_UNESCAPED_SLASHES)
	&& isset($p['directive']['headers']['expo-signature']), $p);
list($s) = phone('ios', $RT, array('expo-current-update-id' => $m2['id']));
check('directive sent even to a phone on the last update', $s === 200);
list($s) = phone('ios', $RT, array('accept' => 'application/json'));
check('directive with a JSON-only accept: 406', $s === 406, $s);

section('Android, separate runtime');
list($s, $j) = release('android', $RT, manifest($RT, entry($bundleA, '.hbc', 'application/javascript'), array($pngE)));
check('android update stored', $s === 201, $j);
list($s, $hd, $b) = phone('android', $RT);
$p = parts($hd, $b);
check('android gets its own manifest', isset($p['manifest']) && strpos($p['manifest']['body'], hash('sha256', $bundleA)) !== false);

section('Logging');
phone('android', $RT, array('eas-client-id' => "client-a-$tag"));
phone('android', $RT, array('eas-client-id' => "client-a-$tag"));
phone('android', $RT, array('eas-client-id' => "client-b-$tag", 'expo-fatal-error' => 'TypeError: boom'));
phone('android', $RT, array('eas-client-id' => "client-b-$tag", 'expo-fatal-error' => 'TypeError: boom'));
$row = $odb->row("SELECT requests, platform FROM ota_daily_clients WHERE client_hash = $1 AND day = current_date",
	array(hash('sha256', "client-a-$tag")));
check('install counted once per day, requests add up', $row !== null && (int)$row['requests'] === 3 && $row['platform'] === 'android', $row);
check('raw client id never stored', $odb->val("SELECT count(*) FROM ota_daily_clients WHERE client_hash = $1",
	array("client-a-$tag")) === '0');
check('fatal error stored once', $odb->val("SELECT count(*) FROM ota_fatal_errors WHERE channel = $1 AND error = 'TypeError: boom'",
	array($CH)) === '1');
list($s, $j) = pub('GET', 'stats?days=1');
$found = false;
foreach ($j['builds'] ?? array() as $bld) if ($bld['runtimeVersion'] === $RT && $bld['platform'] === 'android' && $bld['installs'] === 2) $found = true;
check('stats: android build with 2 installs', $s === 200 && $found, $j['builds'] ?? $j);
$found = false;
foreach ($j['fatalErrors'] ?? array() as $e) if ($e['error'] === 'TypeError: boom') $found = true;
check('stats: crash report listed', $found);

section('Routing');
list($s) = http('GET', '/ota/nothing');
check('unknown path: 404', $s === 404, $s);
list($s) = http('POST', '/ota/manifest');
check('POST manifest: 405', $s === 405, $s);

cleanup();
section('Cleanup');
check('no phptest releases left', $odb->val("SELECT count(*) FROM ota_releases WHERE channel = $1", array($CH)) === '0');
check('test files removed', !is_file(Ota::assetPath(hash('sha256', $png))));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
