<?php
/**
 * File: tests/microimage/micro_image_access_test.php
 * Description: StraboMicro landing image access (docs/Micro_Image_Access_Fix.md, 2026-10-21).
 *              getMicroImage and microLandingScaleBar took pkey and id from the URL
 *              straight into a file path, with no login or project check. Checks,
 *              through the real endpoints with forged sessions, that they follow the
 *              landing pages' rule (public project, or the owner), answer 404 for
 *              everything else, and reject ids that carry path pieces. Fixtures are
 *              discovered from the dev data (projects with images on disk); one is made
 *              public for the run and restored at the end. Run as root (writes session files):
 *                docker exec strabo-php php /srv/app/www/tests/microimage/micro_image_access_test.php
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$STRANGER = 990001;      // owns nothing

$failures = array();
$sessionFiles = array();
$restorePublic = array();
function check($label, $cond, $detail = null) {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== null ? "  -> $detail" : '') . "\n";
	if (!$cond) {
		$failures[] = $label;
	}
}
function section($name) {
	echo "\n== $name\n";
}
function forgeSession($pkey) {
	global $sessionFiles;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = '/var/lib/php/sessions/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600); @chown($path, 'www-data'); @chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}
function page($path, $sid) {
	$ch = curl_init('http://localhost' . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
		CURLOPT_HTTPHEADER => $sid === null ? array() : array('Cookie: PHPSESSID=' . $sid)));
	$body = curl_exec($ch);
	$out = array('code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'body' => (string)$body);
	curl_close($ch);
	return $out;
}
// A JPEG came back.
function gotJpeg($r) {
	return $r['code'] == 200 && stripos($r['type'], 'image/jpeg') !== false && substr($r['body'], 0, 3) === "\xFF\xD8\xFF";
}
function gotPng($r) {
	return $r['code'] == 200 && substr($r['body'], 0, 8) === "\x89PNG\r\n\x1a\n";
}
function notFound($r) {
	return $r['code'] == 404 && strlen($r['body']) == 0;
}
function cleanup() {
	global $db, $sessionFiles, $restorePublic;
	foreach ($restorePublic as $pkey => $was) $db->prepare_query("UPDATE micro_projectmetadata SET ispublic = $1 WHERE id = $2", array($was ? 't' : 'f', $pkey));
	foreach ($sessionFiles as $f) @unlink($f);
}

// ---- fixtures from dev data: two projects with a micrograph JPEG on disk and a scale bar entry
$root = '/srv/app/www/straboMicroFiles';
$found = array();
$rows = $db->get_results("SELECT id, userpkey, ispublic FROM micro_projectmetadata ORDER BY id");
foreach ((array)$rows as $row) {
	$dir = "$root/{$row->id}/images";
	if (!is_dir($dir) || !is_file("$root/{$row->id}/project.json")) continue;
	$jpgs = glob("$dir/*.jpg");
	if (!$jpgs) continue;
	$img = basename($jpgs[0], '.jpg');
	$found[] = array('pkey' => (int)$row->id, 'owner' => (int)$row->userpkey, 'public' => ($row->ispublic === 't' || $row->ispublic === true), 'img' => $img);
	if (count($found) == 2) break;
}
if (count($found) < 2) {
	echo "SKIP: dev data has fewer than two StraboMicro projects with images on disk\n";
	exit(1);
}
$priv = $found[0];
$pub = $found[1];
$restorePublic[$priv['pkey']] = $priv['public'];
$restorePublic[$pub['pkey']] = $pub['public'];
$db->prepare_query("UPDATE micro_projectmetadata SET ispublic = FALSE WHERE id = $1", array($priv['pkey']));
$db->prepare_query("UPDATE micro_projectmetadata SET ispublic = TRUE WHERE id = $1", array($pub['pkey']));
echo "private project {$priv['pkey']} (owner {$priv['owner']}, image {$priv['img']})\n";
echo "public project  {$pub['pkey']} (owner {$pub['owner']}, image {$pub['img']})\n";

$sid = array('owner' => forgeSession($priv['owner']), 'stranger' => forgeSession($STRANGER));
$P = $priv['pkey']; $PI = $priv['img'];
$U = $pub['pkey']; $UI = $pub['img'];

section('getMicroImage: who sees what');
check('owner: private image', gotJpeg(page("/getMicroImage?pkey=$P&id=$PI", $sid['owner'])));
check('signed out: private image is 404', notFound(page("/getMicroImage?pkey=$P&id=$PI", null)));
check('stranger: private image is 404', notFound(page("/getMicroImage?pkey=$P&id=$PI", $sid['stranger'])));
check('signed out: public image', gotJpeg(page("/getMicroImage?pkey=$U&id=$UI", null)));
check('stranger: public image', gotJpeg(page("/getMicroImage?pkey=$U&id=$UI", $sid['stranger'])));
check('missing project is 404 like a private one', notFound(page("/getMicroImage?pkey=999999999&id=$PI", null)));
check('owner: missing image file is 404', notFound(page("/getMicroImage?pkey=$P&id=00000000-0000-0000-0000-000000000000", $sid['owner'])));

section('getMicroImage: ids cannot carry paths');
$escape = rawurlencode("../../$P/images/$PI");
check('public pkey + ../ id into the private project is 404', notFound(page("/getMicroImage?pkey=$U&id=$escape", null)));
check('double-encoded ../ id is 404', notFound(page("/getMicroImage?pkey=$U&id=" . rawurlencode(rawurlencode("../../$P/images/$PI")), null)));
check('pkey with a path is 404', notFound(page("/getMicroImage?pkey=" . rawurlencode("$U/../$P") . "&id=$PI", null)));
check('absolute path id is 404', notFound(page("/getMicroImage?pkey=$U&id=" . rawurlencode("/etc/passwd"), null)));
check('non-numeric pkey is 404', notFound(page("/getMicroImage?pkey=abc&id=$UI", null)));
check('pkey with trailing junk is 404', notFound(page("/getMicroImage?pkey={$U}x&id=$UI", null)));
check('id with .jpg suffix is 404', notFound(page("/getMicroImage?pkey=$U&id=$UI.jpg", null)));
check('no parameters is 404', notFound(page("/getMicroImage", null)));

section('microLandingScaleBar: same gate');
check('owner: private scale bar', gotPng(page("/microLandingScaleBar?pkey=$P&id=$PI", $sid['owner'])) || page("/microLandingScaleBar?pkey=$P&id=$PI", $sid['owner'])['code'] == 200);
check('signed out: private scale bar is 404', notFound(page("/microLandingScaleBar?pkey=$P&id=$PI", null)));
check('stranger: private scale bar is 404', notFound(page("/microLandingScaleBar?pkey=$P&id=$PI", $sid['stranger'])));
check('signed out: public scale bar answers', page("/microLandingScaleBar?pkey=$U&id=$UI", null)['code'] == 200);
check('scale bar pkey with a path is 404', notFound(page("/microLandingScaleBar?pkey=" . rawurlencode("$U/../$P") . "&id=$PI", null)));

section('landing pages still show their images');
$r = page("/micrographBigWindow?pkey=$P&micrograph_id=$PI", $sid['owner']);
check('owner: micrographBigWindow renders the image tag', $r['code'] == 200 && strpos($r['body'], "/getMicroImage?pkey=$P&id=") !== false, substr(strip_tags($r['body']), 0, 120));
$r = page("/micrographBigWindow?pkey=$U&micrograph_id=$UI", null);
check('signed out: public micrographBigWindow renders the image tag', $r['code'] == 200 && strpos($r['body'], "/getMicroImage?pkey=$U&id=") !== false, substr(strip_tags($r['body']), 0, 120));
if (preg_match('#/getMicroImage\?pkey=' . $U . '&id=([^"&]+)#', $r['body'], $m)) {
	check('signed out: the public page\'s image URL serves', gotJpeg(page("/getMicroImage?pkey=$U&id=" . $m[1], null)));
}

cleanup();
echo "\n" . (count($failures) ? count($failures) . " FAILED" : "ALL PASS") . "\n";
exit(count($failures) ? 1 : 0);
