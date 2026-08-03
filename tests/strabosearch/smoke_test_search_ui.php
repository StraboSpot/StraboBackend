<?php
/**
 * File: smoke_test_search_ui.php
 * Description: Permanent smoke suite for the Phase 4 /strabosearch/ UI
 *              surface — the session proxy (api.php), the thumbnail
 *              service (thumb.php), and the page shell, over real HTTP
 *              with forged PHP sessions (same harness as the Template
 *              Wizard E2E). Covers what the /searchdb/ suites can't:
 *              session-identity resolution (anonymous = 0, NOT
 *              prepare_connections' 99999), the 401 gates on saved/legacy
 *              actions, legacy fullsearches listing, thumbnail ACL +
 *              lazy disk cache, and the new province vocab.
 *
 *              Hermetic: fixture user upk 94560 ('spsui'), index rows,
 *              one servable field image (fixture JPEG in /dbimages),
 *              one legacy fullsearches row. Zero residue on exit.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_search_ui.php
 *
 * @package    StraboSearch UI
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$UPK  = 94560;
$PFX  = 'spsui';
$BASE = 'http://localhost';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

// ---------------------------------------------------------------------------
// Forged sessions + HTTP helpers (Template Wizard E2E harness pattern)
// ---------------------------------------------------------------------------
$sessionDir   = '/var/lib/php/sessions';
$sessionFiles = array();

function forgeSession($pkey) {
	global $sessionDir, $sessionFiles;
	$sid  = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = $sessionDir . '/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600);
	@chown($path, 'www-data');
	@chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}

/** Returns array(status, headers-string, body). */
function http_raw($method, $url, $sid = null, $jsonBody = null) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	$headers = array();
	if ($sid !== null) $headers[] = 'Cookie: PHPSESSID=' . $sid;
	if ($jsonBody !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
		$headers[] = 'Content-Type: application/json';
	}
	if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	$raw = (string)curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	return array($status, substr($raw, 0, $hsize), substr($raw, $hsize));
}

function api($action, $sid = null, $body = null) {
	global $BASE;
	list($status, $hdrs, $raw) = http_raw($body !== null ? 'POST' : 'GET',
		$BASE . '/strabosearch/api.php?action=' . $action, $sid, $body);
	return array($status, json_decode($raw, true), $raw);
}

function contentType($hdrs) {
	foreach (explode("\n", $hdrs) as $line) {
		if (stripos($line, 'Content-Type:') === 0) return trim(substr($line, 13));
	}
	return '';
}

// ---------------------------------------------------------------------------
section('0. Fixtures');

$IMG_FILE  = '/srv/app/www/dbimages/' . $PFX . '_fixture_img';
$THUMB_DIR = '/var/www/searchthumbs';

// -- sweep any prior residue
$db->prepare_query("DELETE FROM strabosearch.item_hit  WHERE project_id LIKE $1", array($PFX . '_%'));
$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_id LIKE $1", array($PFX . '_%'));
$db->prepare_query("DELETE FROM strabosearch.saved_search WHERE user_pkey = $1", array($UPK));
$db->prepare_query("DELETE FROM fullsearches WHERE user_pkey = $1", array($UPK));
$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($UPK));
@unlink($IMG_FILE);

$db->prepare_query(
	"INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted)
	 VALUES ($1, 'Uma', 'Searchui', $2, 'x', 'x', TRUE, FALSE)",
	array($UPK, $PFX . '@example.com'));

// index rows: one public project, one private
foreach (array(
	array('proj' => $PFX . '_proj_pub',  'item' => $PFX . '_spot_pub',  'pub' => 'TRUE',  'text' => 'UNIQSSUI_pub granite'),
	array('proj' => $PFX . '_proj_priv', 'item' => $PFX . '_spot_priv', 'pub' => 'FALSE', 'text' => 'UNIQSSUI_priv gneiss'),
) as $s) {
	$db->query("INSERT INTO strabosearch.item_hit
		(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
		 project_name, project_ispublic, searchtext_tsv, source_modified)
		VALUES ('spot', '{$s['item']}', $UPK, '{$s['proj']}', $UPK, 'field',
		 'UI suite {$s['proj']}', {$s['pub']}, to_tsvector('english', '{$s['text']}'), now())");
}

// servable field image (public project) + one private image
$gd = imagecreatetruecolor(320, 240);
imagefilledrectangle($gd, 0, 0, 319, 239, imagecolorallocate($gd, 200, 60, 60));
imagejpeg($gd, $IMG_FILE, 90);
imagedestroy($gd);
check('fixture JPEG written', is_file($IMG_FILE));

foreach (array(
	array('img' => $PFX . '_img_pub',  'proj' => $PFX . '_proj_pub',  'pub' => 'TRUE'),
	array('img' => $PFX . '_img_priv', 'proj' => $PFX . '_proj_priv', 'pub' => 'FALSE'),
) as $im) {
	$db->query("INSERT INTO strabosearch.image_hit
		(image_id, image_subsystem, image_userpkey, image_type, title, filename,
		 project_id, project_userpkey, project_subsystem, project_ispublic,
		 imagetext_tsv, source_modified)
		VALUES ('{$im['img']}', 'field', $UPK, 'photo', 'UI suite image', '{$PFX}_fixture_img',
		 '{$im['proj']}', $UPK, 'field', {$im['pub']},
		 to_tsvector('english', 'UNIQSSUI image'), now())");
}
$pubImgPkey = (int)$db->get_var_prepared(
	"SELECT image_hit_pkey FROM strabosearch.image_hit WHERE image_id = $1", array($PFX . '_img_pub'));
$privImgPkey = (int)$db->get_var_prepared(
	"SELECT image_hit_pkey FROM strabosearch.image_hit WHERE image_id = $1", array($PFX . '_img_priv'));
check('image_hit fixtures inserted', $pubImgPkey > 0 && $privImgPkey > 0);

// legacy saved search
$db->prepare_query(
	"INSERT INTO fullsearches (user_pkey, search_name, search_json) VALUES ($1, $2, $3)",
	array($UPK, 'ui suite legacy', json_encode(array(
		'searchType' => 'results',
		'params' => array(array('num' => 0, 'qualifier' => 'and', 'constraints' => array(
			array('constraintType' => 'keyword', 'constraintValue' => 'zircon')))),
		'searchName' => 'ui suite legacy'))));

$sid = forgeSession($UPK);

// ---------------------------------------------------------------------------
section('1. Page shell');

list($st, $h, $body) = http_raw('GET', $BASE . '/strabosearch/', null);
check('anonymous page 200', $st === 200, "got $st");
check('page has title', strpos($body, 'StraboSpot Search') !== false);
check('page loads catalog.js', strpos($body, '/strabosearch/js/catalog.js') !== false);
check('anonymous: no Save current button', strpos($body, 'ssSaveBtn') === false);
check('anonymous: loggedIn=false bootstrap', strpos($body, 'loggedIn: false') !== false);

list($st, $h, $body) = http_raw('GET', $BASE . '/strabosearch/', $sid);
check('logged-in page 200', $st === 200, "got $st");
check('logged-in: Save current present', strpos($body, 'ssSaveBtn') !== false);
check('logged-in: My searches present', strpos($body, 'ssMySearchesBtn') !== false);

// ---------------------------------------------------------------------------
section('2. Proxy search — anonymous vs session identity');

$dsl = array(
	'subsystems' => array('field', 'micro', 'exp', 'samples'),
	'pathway' => 'projects',
	'criteria' => array(array('id' => 'U1', 'value' => 'UNIQSSUI')),
	'page' => 0, 'page_size' => 25);

list($st, $j) = api('search', null, $dsl);
check('anon search 200', $st === 200, "got $st");
check('anon sees ONLY public project', $j && $j['total'] === 1
	&& $j['results'][0]['project_id'] === $PFX . '_proj_pub',
	'total=' . ($j ? $j['total'] : '?'));
check('response has counterpart_total', $j && array_key_exists('counterpart_total', $j));
check('response has subsystem_summary', $j && isset($j['subsystem_summary']));
check('response reflects sort', $j && isset($j['sort']));

list($st, $j) = api('search', $sid, $dsl);
check('owner search 200', $st === 200, "got $st");
check('owner sees both projects', $j && $j['total'] === 2, 'total=' . ($j ? $j['total'] : '?'));

$imgDsl = $dsl;
$imgDsl['pathway'] = 'images';
list($st, $j) = api('search', null, $imgDsl);
check('anon image search: 1 public image', $j && $j['total'] === 1, 'total=' . ($j ? $j['total'] : '?'));
check('image row carries image_hit_pkey', $j && isset($j['results'][0]['image_hit_pkey'])
	&& (int)$j['results'][0]['image_hit_pkey'] === $pubImgPkey);

list($st, $j) = api('search', $sid, $imgDsl);
check('owner image search: both images', $j && $j['total'] === 2, 'total=' . ($j ? $j['total'] : '?'));

// ---------------------------------------------------------------------------
section('3. Facets + vocab (incl. new province)');

list($st, $j) = api('facets');
check('facets 200 with facets key', $st === 200 && isset($j['facets']));

list($st, $j) = api('vocab&facet=province');
check('province vocab 200', $st === 200, "got $st");
check('province values have gid+name', $j && isset($j['values'][0]['gid'])
	&& isset($j['values'][0]['name']), 'n=' . ($j ? count($j['values']) : 0));

list($st, $j) = api('vocab&facet=rock_type');
check('rock_type vocab has path+depth', $j && isset($j['values'][0]['path'])
	&& array_key_exists('depth', $j['values'][0]));

list($st, $j) = api('vocab&facet=bogus');
check('bogus vocab 400', $st === 400, "got $st");

// ---------------------------------------------------------------------------
section('4. Saved-search CRUD through the proxy');

foreach (array('saved_list', 'legacy_list') as $a) {
	list($st) = api($a);
	check("anonymous $a 401", $st === 401, "got $st");
}
list($st) = api('saved_create', null, array('search_name' => 'x', 'dsl' => $dsl));
check('anonymous saved_create 401', $st === 401, "got $st");

list($st, $j) = api('saved_create', $sid, array('search_name' => 'ui suite search', 'dsl' => $dsl));
check('create 200 + pkey', $st === 200 && (int)$j['saved_search_pkey'] > 0);
$savedPkey = (int)$j['saved_search_pkey'];

list($st, $j) = api('saved_list', $sid);
check('list contains created row', $j && count($j['saved']) === 1
	&& $j['saved'][0]['search_name'] === 'ui suite search');
check('listed dsl round-trips criteria', $j && $j['saved'][0]['dsl']['criteria'][0]['id'] === 'U1');

list($st, $j) = api('saved_update', $sid, array('pkey' => $savedPkey, 'search_name' => 'ui suite renamed'));
check('rename 200', $st === 200 && isset($j['updated']));

list($st, $j) = api('saved_delete', $sid, array('pkey' => $savedPkey));
check('delete 200', $st === 200 && isset($j['deleted']));

list($st) = api('saved_delete', $sid, array('pkey' => $savedPkey));
check('re-delete 404', $st === 404, "got $st");

list($st, $j) = api('legacy_list', $sid);
check('legacy_list returns row w/ parsed json', $j && count($j['legacy']) === 1
	&& $j['legacy'][0]['search_json']['params'][0]['constraints'][0]['constraintValue'] === 'zircon');

// ---------------------------------------------------------------------------
section('5. Validation surface');

list($st) = api('nonsense');
check('unknown action 400', $st === 400, "got $st");

list($st, $j, $raw) = api('search', null, array('criteria' => 'not-an-array'));
check('invalid DSL 400 w/ Error', $st === 400 && isset($j['Error']), "got $st");

$ch = curl_init($BASE . '/strabosearch/api.php?action=search');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'this is not json');
curl_exec($ch);
$st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('non-JSON body 400', $st === 400, "got $st");

// ---------------------------------------------------------------------------
section('6. Thumbnails — ACL + lazy cache');

// sweep any cached fixture thumbs from prior runs
foreach (glob($THUMB_DIR . '/' . $pubImgPkey . '_*.jpg') as $f) @unlink($f);
foreach (glob($THUMB_DIR . '/' . $privImgPkey . '_*.jpg') as $f) @unlink($f);

list($st, $h) = http_raw('GET', $BASE . '/strabosearch/thumb.php?id=0');
check('id=0 serves not-found PNG', strpos(contentType($h), 'image/png') !== false);

list($st, $h) = http_raw('GET', $BASE . '/strabosearch/thumb.php?id=999999999');
check('bogus id serves not-found PNG', strpos(contentType($h), 'image/png') !== false);

list($st, $h, $body) = http_raw('GET', $BASE . '/strabosearch/thumb.php?id=' . $pubImgPkey . '&size=150');
check('public image thumb is JPEG', strpos(contentType($h), 'image/jpeg') !== false,
	contentType($h));
check('thumb bytes decode as image', @imagecreatefromstring($body) !== false);
$cacheFile = $THUMB_DIR . '/' . $pubImgPkey . '_150.jpg';
check('lazy cache file created', is_file($cacheFile));

list($st, $h, $body2) = http_raw('GET', $BASE . '/strabosearch/thumb.php?id=' . $pubImgPkey . '&size=150');
check('cache hit serves same bytes', $body2 === (string)file_get_contents($cacheFile));

list($st, $h) = http_raw('GET', $BASE . '/strabosearch/thumb.php?id=' . $privImgPkey . '&size=150');
check('private image anonymous -> not-found PNG', strpos(contentType($h), 'image/png') !== false);

list($st, $h) = http_raw('GET', $BASE . '/strabosearch/thumb.php?id=' . $privImgPkey . '&size=150', $sid);
check('private image owner -> JPEG', strpos(contentType($h), 'image/jpeg') !== false);

// ---------------------------------------------------------------------------
section('7. Cleanup');

$db->prepare_query("DELETE FROM strabosearch.item_hit  WHERE project_id LIKE $1", array($PFX . '_%'));
$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_id LIKE $1", array($PFX . '_%'));
$db->prepare_query("DELETE FROM strabosearch.saved_search WHERE user_pkey = $1", array($UPK));
$db->prepare_query("DELETE FROM fullsearches WHERE user_pkey = $1", array($UPK));
$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($UPK));
@unlink($IMG_FILE);
foreach (glob($THUMB_DIR . '/' . $pubImgPkey . '_*.jpg') as $f) @unlink($f);
foreach (glob($THUMB_DIR . '/' . $privImgPkey . '_*.jpg') as $f) @unlink($f);
foreach ($sessionFiles as $f) @unlink($f);

$residue = 0;
$residue += (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_%'));
$residue += (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.image_hit WHERE project_id LIKE $1", array($PFX . '_%'));
$residue += (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.saved_search WHERE user_pkey = $1", array($UPK));
$residue += (int)$db->get_var_prepared("SELECT count(*) FROM fullsearches WHERE user_pkey = $1", array($UPK));
$residue += (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE pkey = $1", array($UPK));
$residue += is_file($IMG_FILE) ? 1 : 0;
check('zero residue', $residue === 0, "got $residue");

echo PHP_EOL . ($failures
	? 'FAILURES (' . count($failures) . '): ' . implode(' | ', $failures)
	: 'ALL CHECKS PASSED.') . PHP_EOL;
exit($failures ? 1 : 0);
