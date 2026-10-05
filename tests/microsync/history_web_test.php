<?php
/**
 * File: history_web_test.php
 * Description: The website history page of a synced StraboMicro project
 *              (stage 5d-2; v3 §12b 17ae; micro_history.php,
 *              microdb/lib/micro_history_web.php): who may open it, the
 *              lines (worded and grouped like the app's Activity panel),
 *              each change's details, the person and date filters, Show
 *              Older, the downloads (as of a date, before a change) and the
 *              History option on My StraboMicro Data.
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config; run
 *              as root: it writes PHP session files):
 *                docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/history_web_test.php
 *
 *              Fixture users from tests/collaboration/setup_test_data.php.
 *              Projects use the mstest-hweb- straboId prefix and are removed
 *              before and after.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
$BASE = 'http://localhost/microsync/v1';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microdb/lib/micro_history_web.php';

$PREFIX = 'mstest-hweb-';
$failures = array();
$sessionFiles = array();
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
function cleanup() {
	global $db, $PREFIX;
	foreach ((array)$db->get_results_prepared(
		"SELECT strabo_id, userpkey FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%')) as $r) {
		delete_synced($db, (int)$r->userpkey, $r->strabo_id);
	}
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
/** Website GET with a session cookie ($sid null: logged out). */
function page($path, $sid) {
	$ch = curl_init('http://localhost' . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => $sid === null ? array() : array('Cookie: PHPSESSID=' . $sid)));
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	$headers = substr($raw, 0, $hs);
	return array('code' => $code, 'body' => substr($raw, $hs), 'headers' => $headers,
		'location' => preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '');
}
function push($pid, $tok, $changes) {
	$r = req('POST', "/projects/$pid/push", $tok, array('pushId' => uuid(), 'clientId' => 'hweb-test', 'changes' => $changes));
	$ok = isset($r['body']['results']);
	foreach ($ok ? $r['body']['results'] : array() as $x) {
		$ok = $ok && $x['status'] === 'accepted';
	}
	return $ok;
}
function version($pid, $type, $id) {
	global $db;
	return (int)$db->get_var_prepared("SELECT version FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3",
		array($pid, $type, $id));
}
function upd($pid, $type, $id, $extra) {
	return array_merge(array('op' => 'update', 'type' => $type, 'id' => $id, 'baseVersion' => version($pid, $type, $id)), $extra);
}
/** The visible lines of the list, in order. */
function lines($body) {
	preg_match_all('#<span class="mh-line">(.*?)</span>#s', $body, $m);
	return array_map('html_entity_decode', $m[1]);
}
function has($body, $text) {
	return strpos($body, $text) !== false;
}

$db->get_var('SELECT 1');
$U = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org',
               'outsider' => 'outsider@test.strabospot.org', 'dan' => 'dan.okafor@test.strabospot.org') as $k => $email) {
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	$U[$k] = array('pkey' => $pkey, 'tok' => token($pkey), 'sid' => forgeSession($pkey), 'email' => $email);
}
$names = MsStore::users(micro_members_db($db), array($U['editor']['pkey'], $U['owner']['pkey']));
$EDITOR_NAME = $names[$U['editor']['pkey']]['name'];
$OWN = $U['owner']['tok'];
$EDT = $U['editor']['tok'];

cleanup();

try {
	section('Setup: a shared project with a history');
	$SID = $PREFIX . substr(uuid(), 0, 8);
	$r = req('POST', '/projects', $OWN, array('straboId' => $SID, 'name' => 'Web History'));
	$PID = (int)$r['body']['pid'];
	check('created', push($PID, $OWN, array(
		array('op' => 'create', 'type' => 'project', 'id' => $SID, 'body' => array('name' => 'Web History')),
		array('op' => 'create', 'type' => 'dataset', 'id' => 'D1', 'parentType' => 'project', 'parentId' => $SID, 'body' => array('name' => 'D1')),
		array('op' => 'create', 'type' => 'sample', 'id' => 'S1', 'parentType' => 'dataset', 'parentId' => 'D1', 'body' => array('name' => 'S1')),
		array('op' => 'create', 'type' => 'sample', 'id' => 'S2', 'parentType' => 'dataset', 'parentId' => 'D1', 'body' => array('name' => 'S2')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'M1', 'parentType' => 'sample', 'parentId' => 'S1', 'body' => array('name' => 'M1')),
	)));
	check('ready', req('POST', "/projects/$PID/ready", $OWN)['code'] === 200);
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['editor']['email'], 'role' => 'editor'));
	check('editor joins', req('POST', "/invites/$PID/accept", $EDT)['code'] === 200);
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['dan']['email'], 'role' => 'viewer'));
	check('viewer joins', req('POST', "/invites/$PID/accept", $U['dan']['tok'])['code'] === 200);
	// A change of another kind ends the first upload's line (the spots below are their own line)
	check('project notes', push($PID, $OWN, array(upd($PID, 'project', $SID, array('fields' => array('notes' => 'Thin sections'))))));
	$beforeSpots = (int)$db->get_var_prepared("SELECT max(seq) FROM strabomicro.micro_changes WHERE project_id = $1", array($PID));
	sleep(2);
	check('two spots, one with markup in its name', push($PID, $OWN, array(
		array('op' => 'create', 'type' => 'spot', 'id' => 'P1', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('name' => 'P1')),
		array('op' => 'create', 'type' => 'spot', 'id' => 'P2', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('name' => '<b>x</b>')),
	)));
	$afterSpots = time();
	sleep(2);
	check('rename + new shape', push($PID, $OWN, array(upd($PID, 'spot', 'P1', array('fields' => array('name' => 'P1 renamed',
		'points' => array(array('X' => 7123.25, 'Y' => 2.5), array('X' => 3, 'Y' => 4))))))));
	$shaA = upload_blob($PID, $OWN, 'image A bytes', 'image');
	check('image', set_ref($PID, $OWN, 'micrograph', 'M1', 'image', $shaA)['code'] === 200);
	check('move', push($PID, $OWN, array(upd($PID, 'micrograph', 'M1', array('parentType' => 'sample', 'parentId' => 'S2')))));
	check('delete with cascade', push($PID, $OWN, array(array('op' => 'delete', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => version($PID, 'micrograph', 'M1')))));
	check('editor renames a sample', push($PID, $EDT, array(upd($PID, 'sample', 'S1', array('fields' => array('name' => 'S1 by editor'))))));

	section('Who may open it');
	$url = "/micro_history?project_id=$PID";
	$r = page($url, null);
	check('logged out -> login', $r['code'] === 302 && strpos($r['location'], 'login') !== false, $r['code'] . ' ' . $r['location']);
	$own = page($url, $U['owner']['sid']);
	check('owner 200, project name', $own['code'] === 200 && has($own['body'], 'Project History') && has($own['body'], 'Web History'));
	$edPage = page($url, $U['editor']['sid']);
	check('editor 200', $edPage['code'] === 200 && count(lines($edPage['body'])) > 0);
	check('viewer 200', count(lines(page($url, $U['dan']['sid'])['body'])) > 0);
	$out = page($url, $U['outsider']['sid']);
	check('outsider: not available, no lines', has($out['body'], 'This project is not available.') && count(lines($out['body'])) === 0);
	check('unknown id: not available', has(page('/micro_history?project_id=999999999', $U['owner']['sid'])['body'], 'not available'));

	section('Lines (newest first, worded like the Activity panel)');
	$L = lines($own['body']);
	// M1's image, set soon after M1 was added, is part of the first upload's line
	$expect = array(
		"$EDITOR_NAME changed Name of sample 'S1 by editor'",
		"You deleted micrograph 'M1' (2 spots)",
		"You moved micrograph 'M1' to sample 'S2'",
		"You changed Name, Shape of spot 'P1 renamed'",
		"You added 2 spots to micrograph 'M1'",
		"You changed the project settings (Notes)",
		"You put the project on StraboSpot (1 dataset, 1 micrograph, 2 samples)",
	);
	check('every line', $L === $expect, json_encode($L));
	check('the editor sees the owner by name', strpos(lines($edPage['body'])[1], 'You deleted') === false
		&& strpos(lines($edPage['body'])[0], 'You changed Name of sample') === 0, json_encode(array_slice(lines($edPage['body']), 0, 2)));
	check('markup in names is escaped', !has($own['body'], '<b>x</b>') && has($own['body'], '&lt;b&gt;x&lt;/b&gt;'));

	section('Details');
	check('rename: Name: P1 -> P1 renamed', has($own['body'], 'Name: <span class="mh-old">P1</span> &rarr; P1 renamed'));
	check('file: Image: Added', has($own['body'], 'Image: Added'));
	check('shape: Shape: Changed, no coordinates', has($own['body'], 'Shape: Changed') && !has($own['body'], '7123.25'));
	// Parents are named as they are today (like the app's Activity panel): S1 was renamed later
	check("move: Location: sample 'S1 by editor' -> sample 'S2'", has($own['body'], "Location: <span class=\"mh-old\">sample 'S1 by editor'</span> &rarr; sample 'S2'"));
	check('cascade: the deleted spots listed under the micrograph', substr_count($own['body'], '>Deleted</li>') === 3);
	$downloads = substr_count($own['body'], 'value="Download as It Was Before This"');
	check('a "before this" download on every line but the first change', $downloads === count($L) - 1, "$downloads for " . count($L));
	check('History option on My StraboMicro Data', has(page('/my_micro_data', $U['owner']['sid'])['body'], '<option value="history">History</option>'));

	section('Filters');
	$f = lines(page("$url&user=" . $U['editor']['pkey'], $U['owner']['sid'])['body']);
	check('user=editor: only the editor', count($f) === 1 && strpos($f[0], $EDITOR_NAME) === 0, json_encode($f));
	$f = lines(page("$url&user=" . $U['owner']['pkey'], $U['owner']['sid'])['body']);
	check('user=owner: all but the editor', count($f) === count($L) - 1);
	$f = page("$url&until=$afterSpots", $U['owner']['sid']);
	check('until: newest line is the spots', lines($f['body'])[0] === "You added 2 spots to micrograph 'M1'", json_encode(lines($f['body'])));
	check('until: says what it shows', has($f['body'], 'Showing up to'));
	check('person list names everyone', has($own['body'], htmlspecialchars($EDITOR_NAME) . '</option>') && has($own['body'], '(you)</option>'));

	section('Downloads');
	$d = page("$url&download=1&seq=$beforeSpots&tz=America/Chicago", $U['dan']['sid']);
	check('viewer: before the spots -> a .smz', $d['code'] === 200 && preg_match('/filename="web_history_as_of_\d{4}-\d{2}-\d{2}_\d{4}\.smz"/', $d['headers']) === 1,
		$d['code'] . ' ' . substr($d['headers'], 0, 300));
	$tmp = tempnam('/tmp', 'hweb');
	file_put_contents($tmp, $d['body']);
	$za = new ZipArchive();
	$okZip = $za->open($tmp) === true;
	$root = $okZip ? explode('/', $za->getNameIndex(0))[0] : '';
	$pj = $okZip ? json_decode($za->getFromName("$root/project.json")) : null;
	check('new id, "(as of" name, no spots yet', $okZip && $root !== $SID && is_object($pj) && $pj->id === $root
		&& strpos($pj->name, 'Web History (as of ') === 0 && count($pj->datasets[0]->samples[0]->micrographs[0]->spots) === 0,
		is_object($pj) ? $pj->name : 'no project.json');
	@unlink($tmp);
	$d = page("$url&download=1&at=" . ($afterSpots + 1), $U['owner']['sid']);
	check('as of a date -> a .smz', $d['code'] === 200 && strpos($d['headers'], 'application/zip') !== false);
	$d = page("$url&download=1&at=978307200", $U['owner']['sid']);
	check('before the history: back to the page', $d['code'] === 302 && strpos($d['location'], "/micro_history?project_id=$PID") === 0, $d['location']);
	check('... with the explanation, once', has(page($url, $U['owner']['sid'])['body'], 'before this project started syncing')
		&& !has(page($url, $U['owner']['sid'])['body'], 'before this project started syncing'));
	$d = page("$url&download=1&seq=$beforeSpots", $U['outsider']['sid']);
	check('outsider: no download', strpos($d['headers'], 'application/zip') === false && has($d['body'], 'not available'));

	section('Show Older');
	$many = array();
	for ($i = 0; $i < 210; $i++) {
		$many[] = array('op' => 'create', 'type' => 'spot', 'id' => "Q$i", 'parentType' => 'micrograph', 'parentId' => 'M9', 'body' => array('name' => "Q$i"));
	}
	array_unshift($many, array('op' => 'create', 'type' => 'micrograph', 'id' => 'M9', 'parentType' => 'sample', 'parentId' => 'S2', 'body' => array('name' => 'M9')));
	check('211 more changes', push($PID, $OWN, $many));
	$p1 = page($url, $U['owner']['sid']);
	check('first page: no older lines yet', !in_array("You deleted micrograph 'M1' (2 spots)", lines($p1['body']), true));
	check('Show Older button', preg_match('#href="(/micro_history\?project_id=' . $PID . '&amp;before=\d+)" class="button small">Show Older#', $p1['body'], $m) === 1);
	$p2 = page(html_entity_decode($m[1]), $U['owner']['sid']);
	check('older page has the earlier lines', in_array("You deleted micrograph 'M1' (2 spots)", lines($p2['body']), true), json_encode(lines($p2['body'])));

	section("A new micrograph's image is part of adding it");
	check('a micrograph', push($PID, $OWN, array(
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'MI', 'parentType' => 'sample', 'parentId' => 'S2', 'body' => array('name' => 'MI')))));
	check('its image', set_ref($PID, $OWN, 'micrograph', 'MI', 'image', upload_blob($PID, $OWN, 'image MI bytes', 'image'))['code'] === 200);
	$b = page($url, $U['owner']['sid'])['body'];
	check("one line: added micrograph 'MI'", lines($b)[0] === "You added micrograph 'MI' to sample 'S2'", json_encode(array_slice(lines($b), 0, 2)));
	check('the image listed in its details', has($b, 'Image: Added'));

	section('Markup in a line');
	check('a micrograph named with markup', push($PID, $OWN, array(
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'MX', 'parentType' => 'sample', 'parentId' => 'S1', 'body' => array('name' => '<i>y</i>')))));
	$b = page($url, $U['owner']['sid'])['body'];
	check('the line names it, escaped', strpos($b, "<span class=\"mh-line\">You added micrograph '&lt;i&gt;y&lt;/i&gt;' to sample 'S1 by editor'</span>") !== false
		&& strpos($b, '<i>y</i>') === false, json_encode(array_slice(lines($b), 0, 1)));

	section('Removed member');
	req('DELETE', "/projects/$PID/members/" . $U['dan']['pkey'], $OWN);
	check('removed viewer: no longer has access', has(page($url, $U['dan']['sid'])['body'], 'You no longer have access to this project.'));
} catch (Throwable $e) {
	check('unexpected error: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getLine(), false);
} finally {
	cleanup();
	foreach ($sessionFiles as $f) {
		@unlink($f);
	}
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
