<?php
/**
 * File: tests/fieldcamp/api_package_test.php
 * Description: Suite for the app side of the Field Camp Wizard (stage 7):
 *              GET /db/profile carries "field_camp" for a camp account
 *              (FieldCampPackage::profileKey), GET / HEAD /db/fieldCampPackage
 *              serves the camp's package (cached per version, byte ranges),
 *              POST /db/fieldCampPackage/installed records what the app
 *              loaded, POST /db/profile never stores the key.
 *
 *              Two camps of one instructor, each with accounts; offline maps
 *              are switched off or faked so no tile server is needed.
 *
 *              Fixture user 94811 and camp usernames starting "fctest99_";
 *              everything the suite makes is removed again.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/api_package_test.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'db/strabospotclass.php';
require_once 'includes/fieldcamp/FieldCamp.php';
require_once 'includes/fieldcamp/FieldCampPackage.php';
require_once 'includes/fieldcamp/FieldCampRoster.php';
require_once 'includes/fieldcamp/FieldCampTiles.php';

$INSTR = 94811;
$FIX = array($INSTR => 'fctest99-instructor@example.org');
$PW = 'camp-pass-99';
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 700) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function fixture_pkeys() {
	global $db, $FIX;
	$pks = array_keys($FIX);
	$rows = $db->get_results_prepared("select pkey from users where email like 'fctest99\\_%'", array());
	if ($rows) foreach ($rows as $r) $pks[] = (int)$r->pkey;
	return $pks;
}
function cleanup() {
	global $db, $neodb, $FIX;
	$in = implode(',', fixture_pkeys());
	$neodb->query("MATCH (n) WHERE n.userpkey IN [$in] DETACH DELETE n");
	$arr = "{" . $in . "}";
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE item_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE image_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = ANY($1)", array($arr));
	$db->query("delete from fieldcamp_copy where target_user_pkey in ($in) or source_user_pkey in ($in)");
	$rows = $db->get_results_prepared("select uuid from versions where userpkey in ($in)", array());
	if ($rows) foreach ($rows as $r) { if (preg_match('/^[0-9a-f-]{36}$/i', (string)$r->uuid)) @unlink("/srv/app/www/versions/" . $r->uuid); }
	$db->query("delete from versions where userpkey in ($in)");
	$fix = implode(',', array_keys($FIX));
	$rows = $db->get_results_prepared("select pkey from fieldcamp where owner_user_pkey in ($fix)", array());
	if ($rows) {
		$cin = implode(',', array_map(function ($r) { return (int)$r->pkey; }, $rows));
		foreach (array('fieldcamp_account', 'fieldcamp_viewer', 'fieldcamp_map', 'fieldcamp_unit', 'fieldcamp_unit_source') as $t) $db->query("delete from $t where fieldcamp_pkey in ($cin)");
		$db->query("delete from fieldcamp where pkey in ($cin)");
	}
	$db->query("delete from fieldcamp_account where user_pkey in ($in)");
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fix)");
	$db->query("delete from apptokens where email like 'fctest99\\_%'");
	$db->query("delete from users where pkey in ($in)");
}
function api($method, $path, $username, $json = null) {
	global $BASE, $PW;
	$ch = curl_init("$BASE/db/$path");
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "$username:$PW", CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120));
	if ($json !== null) { curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); }
	$body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return array($code, json_decode((string)$body, true), (string)$body);
}
$sids = array();
function forge($pkey, $email) {
	global $SESS, $sids;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	file_put_contents("$SESS/sess_$sid", 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';');
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
function web($path, $sid) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $sid ? array('Cookie: PHPSESSID=' . $sid) : array()));
	$body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return array($code, (string)$body);
}
/** An API call with every detail: [code, body, headers]. $user null = the instructor's own account. */
function call($method, $path, $user, $json = null, $headers = array()) {
	global $BASE, $PW, $FIX, $INSTR;
	$ch = curl_init("$BASE/db/$path");
	$cred = $user === null ? $FIX[$INSTR] . ':fixture-pw' : "$user:$PW";
	$opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $cred, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true);
	if ($method === 'HEAD') $opts[CURLOPT_NOBODY] = true;
	if ($json !== null) { $headers[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = is_string($json) ? $json : json_encode($json); }
	if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
	curl_setopt_array($ch, $opts);
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	return array($code, substr((string)$raw, $hs), substr((string)$raw, 0, $hs));
}
function hdr($headers, $name) { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : ''; }
function profile($user) { list($code, $body) = call('GET', 'profile', $user); return array($code, json_decode($body, true)); }
function new_camp($name, $accounts, $template) {
	global $db, $neodb, $INSTR, $PW;
	list($clean) = FieldCamp::validateBasics(array('camp_name' => $name, 'camp_year' => '2027', 'project_name' => "$name Project"));
	$id = FieldCamp::createCamp($db, $INSTR, $clean);
	FieldCamp::saveArea($db, $id, array('west' => -105.30, 'south' => 38.50, 'east' => -105.20, 'north' => 38.58));
	$camp = FieldCamp::getCamp($db, $id);
	FieldCampTiles::saveChoice($db, $camp, 0, array());   // offline maps switched off: the package is ready without a build
	if ($accounts > 0) FieldCamp::createAccounts($db, $neodb, $camp, $template, $accounts, $PW);
	return $id;
}
/** Open a downloaded package: [ZipArchive ok, project id, project name, has tile zip]. */
function unpack_zip($bytes) {
	$tmp = tempnam(sys_get_temp_dir(), 'fcapi'); file_put_contents($tmp, $bytes);
	$zip = new ZipArchive(); $ok = $zip->open($tmp) === true;
	$out = array($ok, null, null, false);
	if ($ok) {
		$d = json_decode((string)$zip->getFromName('data.json'), true);
		$out = array(true, (string)($d['projectDb']['project']['id'] ?? ''), (string)($d['projectDb']['project']['description']['project_name'] ?? ''), $zip->locateName('maps/OfflineTiles.zip') !== false);
		$zip->close();
	}
	unlink($tmp);
	return $out;
}
function rm_camp_files() {
	global $db, $INSTR;
	$rows = $db->get_results_prepared("select files_token from fieldcamp where owner_user_pkey = $1", array($INSTR));
	foreach (($rows ? $rows : array()) as $r) {
		$t = preg_replace('/[^a-f0-9]/', '', (string)$r->files_token);
		if (strlen($t) === 32 && is_dir("/srv/app/www/fieldcamp_files/$t")) { foreach (glob("/srv/app/www/fieldcamp_files/$t/*") as $f) @unlink($f); @rmdir("/srv/app/www/fieldcamp_files/$t"); }
	}
}

rm_camp_files();
cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Api', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fctest99hash' . $pk, $email));
	$neodb->query("CREATE (:User {userpkey: $pk, email: '$email', firstname: 'FC', lastname: 'Api'})");
}
FieldCamp::addInstructor($db, $INSTR, 'api package test', 3);
$campid = new_camp('Api Camp', 2, 'fctest99_a{n}');
$otherid = new_camp('Other Api Camp', 1, 'fctest99_b{n}');
$camp = FieldCamp::getCamp($db, $campid);
check('this server records what the app loaded (sql/fieldcamp.sql applied)', FieldCampPackage::canRecordInstall($db));
$acc = FieldCamp::listAccounts($db, $campid);
$A1 = (int)$acc[0]->user_pkey; $A2 = (int)$acc[1]->user_pkey;

// ===========================================================================
section('GET /db/profile');
list($code, $p) = profile(null);
check('an ordinary account: the profile as always, without the key', $code === 200 && is_array($p) && !array_key_exists('field_camp', $p) && (int)$p['straboUserId'] === $INSTR, $p);
list($code, $p) = profile('fctest99_a1');
$fc = $p['field_camp'] ?? null;
check('a camp account: the usual profile plus field_camp', $code === 200 && is_array($fc) && (int)$p['straboUserId'] === $A1, $p);
$camp = FieldCamp::getCamp($db, $campid);
check('it names the camp and the project', $fc && $fc['camp_name'] === 'Api Camp' && $fc['camp_year'] === 2027 && $fc['project_name'] === 'Api Camp Project' && $fc['project_id'] === (string)$camp->project_id && $fc['project_id'] !== '', $fc);
$pk = $fc['package'] ?? array();
check('the package is ready, with where to get it, its version, date and size', ($pk['status'] ?? '') === 'ready' && $pk['url'] === 'http://localhost/db/fieldCampPackage' && preg_match('/^[a-f0-9]{12}$/', $pk['version']) === 1
	&& preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $pk['updated']) === 1 && $pk['size'] > 500 && $pk['offline_maps'] === 0 && $pk['tags'] === 0 && array_key_exists('installed_version', $pk) && $pk['installed_version'] === null, $pk);
$V = $pk['version'];
list($code, $p2) = profile('fctest99_a2');
check('the version is the same for a classmate and from call to call', $p2['field_camp']['package']['version'] === $V && profile('fctest99_a1')[1]['field_camp']['package']['version'] === $V);
list($code, $pb) = profile('fctest99_b1');
check('an account of another camp is told about its own camp', $pb['field_camp']['camp_name'] === 'Other Api Camp' && $pb['field_camp']['project_id'] !== $fc['project_id'] && $pb['field_camp']['package']['version'] !== $V);
check('on the production host the address is https', (function () { $_SERVER['HTTP_HOST'] = 'strabospot.org'; require_once 'db/controllers/MyController.php'; require_once 'db/controllers/FieldCampPackageController.php';
	$a = FieldCampPackageController::serverBase(); $_SERVER['HTTP_HOST'] = 'localhost'; $b = FieldCampPackageController::serverBase(); unset($_SERVER['HTTP_HOST']); return $a === 'https://strabospot.org' && $b === 'http://localhost'; })());

// ===========================================================================
section('GET /db/fieldCampPackage');
list($code, $body) = call('GET', 'fieldCampPackage', null);
check('an ordinary account has no package: 404', $code === 404 && strpos($body, 'no field camp package') !== false, "$code $body");
list($code, $zipBytes, $h) = call('GET', 'fieldCampPackage', 'fctest99_a1');
list($ok, $pid, $pname, $hasTiles) = unpack_zip($zipBytes);
check('a camp account gets the zip', $code === 200 && hdr($h, 'Content-Type') === 'application/zip' && substr($zipBytes, 0, 2) === 'PK' && $ok, "$code " . substr($zipBytes, 0, 200));
check('with its exact size, its version and a file name', (int)hdr($h, 'Content-Length') === strlen($zipBytes) && hdr($h, 'ETag') === '"' . $V . '"' && hdr($h, 'X-Field-Camp-Package-Version') === $V
	&& hdr($h, 'Accept-Ranges') === 'bytes' && preg_match('/filename="\d{4}-\d\d-\d\d_Api_Camp_Project\.zip"/', hdr($h, 'Content-Disposition')) === 1, $h);
check('it is the camp\'s project, the one the profile named', $pid === $fc['project_id'] && $pname === 'Api Camp Project' && !$hasTiles, array($pid, $pname));
$camp = FieldCamp::getCamp($db, $campid);
$dir = FieldCampTiles::dir($camp);
$file = "$dir/package_$V.zip";
check('the file is kept in the camp\'s folder under its version', $dir !== null && is_file($file) && filesize($file) === strlen($zipBytes), $file);
$mtime = filemtime($file); clearstatcache();
list($code, $again) = call('GET', 'fieldCampPackage', 'fctest99_a2');
clearstatcache();
check('a classmate gets the very same file, not a new build', $code === 200 && $again === $zipBytes && filemtime($file) === $mtime);
list($code, $bodyB) = call('GET', 'fieldCampPackage', 'fctest99_b1');
check('the other camp\'s account gets the other camp\'s project', $code === 200 && unpack_zip($bodyB)[1] === $pb['field_camp']['project_id'] && unpack_zip($bodyB)[2] === 'Other Api Camp Project');
list($code, , $token) = call('GET', "../fieldcamp_files/" . basename($dir) . "/package_$V.zip", 'fctest99_a1');
check('the kept file cannot be fetched from the web by its address', $code === 403, $code);

list($code, $part, $h) = call('GET', 'fieldCampPackage', 'fctest99_a1', null, array('Range: bytes=0-99'));
check('a byte range: 206 with just those bytes', $code === 206 && $part === substr($zipBytes, 0, 100) && hdr($h, 'Content-Range') === 'bytes 0-99/' . strlen($zipBytes) && (int)hdr($h, 'Content-Length') === 100, "$code " . hdr($h, 'Content-Range'));
list($code, $rest, $h) = call('GET', 'fieldCampPackage', 'fctest99_a1', null, array('Range: bytes=100-', 'If-Range: "' . $V . '"'));
check('the rest of a broken download, for the version it started on', $code === 206 && $part . $rest === $zipBytes, $code);
list($code, $whole) = call('GET', 'fieldCampPackage', 'fctest99_a1', null, array('Range: bytes=100-', 'If-Range: "000000000000"'));
check('a range for another version gets the whole new file', $code === 200 && $whole === $zipBytes, $code);
list($code, , $h) = call('GET', 'fieldCampPackage', 'fctest99_a1', null, array('Range: bytes=' . (strlen($zipBytes) + 10) . '-'));
check('a range past the end: 416', $code === 416 && hdr($h, 'Content-Range') === 'bytes */' . strlen($zipBytes), $code);
list($code, $none, $h) = call('HEAD', 'fieldCampPackage', 'fctest99_a1');
check('HEAD: the headers without the file', $code === 200 && $none === '' && (int)hdr($h, 'Content-Length') === strlen($zipBytes) && hdr($h, 'ETag') === '"' . $V . '"', "$code " . strlen($none));

// ===========================================================================
section('A changed package');
FieldCamp::updateBasics($db, $campid, FieldCamp::validateBasics(array('camp_name' => 'Api Camp', 'camp_year' => '2027', 'project_name' => 'Api Camp Renamed'))[0]);
list($code, $p) = profile('fctest99_a1');
$V2 = $p['field_camp']['package']['version'];
check('a change to the project gives a new version', $V2 !== $V && preg_match('/^[a-f0-9]{12}$/', $V2) === 1 && $p['field_camp']['project_name'] === 'Api Camp Renamed' && $p['field_camp']['project_id'] === $fc['project_id']);
list($code, $new, $h) = call('GET', 'fieldCampPackage', 'fctest99_a1');
clearstatcache();
check('the download is the new one, and the old file is gone', $code === 200 && hdr($h, 'ETag') === '"' . $V2 . '"' && unpack_zip($new)[2] === 'Api Camp Renamed' && is_file("$dir/package_$V2.zip") && !is_file($file) && count(glob("$dir/package_*")) === 1, glob("$dir/*"));

// ===========================================================================
section('POST /db/fieldCampPackage/installed');
list($code, $body) = call('POST', 'fieldCampPackage/installed', 'fctest99_a1', array('version' => $V));
check('the app reports the version it loaded', $code === 200 && json_decode($body, true) === array('installed_version' => $V), "$code $body");
list($code, $p) = profile('fctest99_a1');
check('the profile gives it back, so the app sees a newer package is offered', $p['field_camp']['package']['installed_version'] === $V && $p['field_camp']['package']['version'] === $V2);
check('only that account is marked', profile('fctest99_a2')[1]['field_camp']['package']['installed_version'] === null);
call('POST', 'fieldCampPackage/installed', 'fctest99_a1', array('version' => $V2));
check('reporting the new version brings the two together', profile('fctest99_a1')[1]['field_camp']['package']['installed_version'] === $V2);
list($code, $body) = call('POST', 'fieldCampPackage/installed', 'fctest99_a1', array('version' => "x'; drop table users; --"));
check('a value that is not a version: 400, nothing recorded', $code === 400 && profile('fctest99_a1')[1]['field_camp']['package']['installed_version'] === $V2, $code);
list($code) = call('POST', 'fieldCampPackage/installed', 'fctest99_a1', array());
check('no version: 400', $code === 400, $code);
list($code) = call('POST', 'fieldCampPackage', 'fctest99_a1', array('version' => $V2));
check('POST without /installed: 400', $code === 400, $code);
list($code) = call('POST', 'fieldCampPackage/installed', null, array('version' => $V2));
check('an ordinary account: 404', $code === 404, $code);
list($code) = call('DELETE', 'fieldCampPackage', 'fctest99_a1');
check('DELETE: 400', $code === 400, $code);
$sid = forge($INSTR, $FIX[$INSTR]);
list($code, $page) = web("/field_camp?id=$campid&step=roster", $sid);
check('Student work says who has loaded the package', $code === 200 && preg_match('~<b>(Warning|Notice|Deprecated|Fatal error)</b>~', $page) === 0 && preg_match('~fctest99_a1 <span class="fc-small">\(package loaded \d{4}-\d\d-\d\d\)~', $page) === 1
	&& strpos($page, 'fctest99_a2 <span class="fc-small">(package not loaded yet)') !== false);
list($code, $page) = web("/field_camp?id=$otherid&step=roster", $sid);
check('and says nothing about packages while no app has reported', strpos($page, 'package not loaded yet') === false && strpos($page, 'package loaded') === false);

// ===========================================================================
section('POST /db/profile never stores the key');
list($code, $p) = profile('fctest99_a1');
$send = $p; $send['field_camp']['camp_name'] = 'Tampered'; $send['notes_marker'] = 'kept';
list($code, $body) = call('POST', 'profile', 'fctest99_a1', $send);
check('the app posts the whole profile back, key included: accepted', $code === 201, "$code " . substr($body, 0, 200));
$stored = $neodb->get_results("MATCH (u:User {userpkey: $A1})-[:HAS_PROFILE]->(p:Profile) RETURN p");
$props = $stored ? $stored[0]->get('p')->values() : array();
check('the stored profile has the app\'s values but no field_camp', ($props['notes_marker'] ?? '') === 'kept' && !array_key_exists('field_camp', $props), array_keys($props));
list($code, $p) = profile('fctest99_a1');
check('and the next profile still tells the truth about the camp', $p['field_camp']['camp_name'] === 'Api Camp' && $p['notes_marker'] === 'kept');

// ===========================================================================
section('When no package is on offer');
$camp = FieldCamp::getCamp($db, $campid);
FieldCampTiles::saveChoice($db, $camp, 14, array('macrostrat'));   // offline maps wanted, never built
list($code, $p) = profile('fctest99_a1');
check('offline maps wanted but not built: status none, nothing to download named', $p['field_camp']['package']['status'] === 'none' && !isset($p['field_camp']['package']['url']) && !isset($p['field_camp']['package']['version'])
	&& $p['field_camp']['package']['installed_version'] === $V2 && $p['field_camp']['project_name'] === 'Api Camp Renamed', $p['field_camp']);
list($code, $body) = call('GET', 'fieldCampPackage', 'fctest99_a1');
check('the download answers 404 "not ready yet"', $code === 404 && json_decode($body, true)['status'] === 'none', "$code $body");
$db->prepare_query("update fieldcamp set build_status = 'building', build_started = now() where pkey = $1", array($campid));
list($code, $p) = profile('fctest99_a1');
check('while the offline maps are built: status building', $p['field_camp']['package']['status'] === 'building' && !isset($p['field_camp']['package']['url']));
list($code, $body, $h) = call('GET', 'fieldCampPackage', 'fctest99_a1');
check('the download answers 409 with when to ask again', $code === 409 && hdr($h, 'Retry-After') === '30' && json_decode($body, true)['status'] === 'building', "$code $body");
$db->prepare_query("update fieldcamp set build_status = '' where pkey = $1", array($campid));
FieldCampTiles::saveChoice($db, $camp, 0, array());
check('switched off again: ready', profile('fctest99_a1')[1]['field_camp']['package']['status'] === 'ready');
$db->prepare_query("update fieldcamp set bbox_west = null, bbox_south = null, bbox_east = null, bbox_north = null where pkey = $1", array($otherid));
check('a camp without a study area: status none', profile('fctest99_b1')[1]['field_camp']['package']['status'] === 'none');

check('a deactivated account has no package (and cannot sign in anyway)', (function () use ($db, $campid, $A2) {
	FieldCamp::setAccountsActive($db, FieldCamp::getCamp($db, $campid), false, array($A2));
	$gone = FieldCampPackage::accountCamp($db, $A2) === null && FieldCampPackage::profileKey($db, $A2, 'http://localhost') === null;
	list($code) = call('GET', 'fieldCampPackage', 'fctest99_a2');
	return $gone && $code !== 200; })());
FieldCamp::deleteCamp($db, $neodb, FieldCamp::getCamp($db, $campid), $INSTR);
clearstatcache();
check('a deleted camp: no key, no package, and its kept file is gone', FieldCampPackage::profileKey($db, $A1, 'http://localhost') === null && !is_dir($dir), is_dir($dir));
list($code) = call('GET', 'fieldCampPackage', 'fctest99_a1');
check('its accounts are turned away', $code !== 200, $code);

// ===========================================================================
rm_camp_files();
cleanup();
foreach ($sids as $s) @unlink("$SESS/sess_$s");
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
