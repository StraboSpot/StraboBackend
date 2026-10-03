<?php
/**
 * File: delete_web_test.php
 * Description: Website side of deleting a synced StraboMicro project (stage
 *              6c; v3 17ac, 17ad): Delete on My StraboMicro Data goes to
 *              micro_delete.php (owner only, names the members, type the name,
 *              form token), the one-time result banner, the Deleted projects
 *              section with Restore, the "This project was deleted" page of
 *              the viewers and permalinks (also after the purge), and the
 *              Collaborators option.
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config; run
 *              as root: it writes PHP session files and runs the purge tool):
 *                docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/delete_web_test.php
 *
 *              The newer viewer (/microview/index.php) is a copy of the
 *              StraboMicro2 repo's web-viewer/public/index.php; its check is
 *              skipped when the copy here predates stage 6c.
 *              Projects use the mstest-dweb- straboId prefix and are removed
 *              (rows, tombstones, permalinks, folders) before and after.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
$BASE = 'http://localhost/microsync/v1';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';
require_once '/srv/app/www/microdb/lib/permalink.php';

$PREFIX = 'mstest-dweb-';
$FILES = '/srv/app/www/straboMicroFiles';
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
	global $db, $PREFIX, $FILES;
	$ids = array();
	foreach ((array)$db->get_results_prepared(
		"SELECT project_id AS id FROM strabomicro.micro_deleted_projects WHERE strabo_id LIKE $1
		 UNION SELECT id FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1",
		array($PREFIX . '%')) as $r) {
		$ids[] = (int)$r->id;
	}
	foreach ((array)$db->get_results_prepared(
		"SELECT strabo_id, userpkey FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%')) as $r) {
		(new StraboMicro(null, (int)$r->userpkey, $db))->deleteProjectRows($r->strabo_id);
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	$db->prepare_query("DELETE FROM strabomicro.micro_deleted_projects WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	$db->prepare_query("DELETE FROM micro_permalinks WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	foreach ($ids as $id) {
		foreach (array("$FILES/$id", "$FILES/_deleted/$id") as $dir) {
			if ($id > 0 && is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
		}
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
/** Website request with a session cookie ($sid null: logged out); $form = POST fields. */
function page($method, $path, $sid, $form = null) {
	$ch = curl_init('http://localhost' . $path);
	$o = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => $sid === null ? array() : array('Cookie: PHPSESSID=' . $sid));
	if ($form !== null) {
		$o[CURLOPT_POSTFIELDS] = http_build_query($form);
	}
	curl_setopt_array($ch, $o);
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	$headers = substr($raw, 0, $hs);
	return array('code' => $code, 'body' => substr($raw, $hs),
		'location' => preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '');
}
/** The session's form token (micro_delete.php makes it on first use). */
function formToken($sid) {
	$data = (string)@file_get_contents('/var/lib/php/sessions/sess_' . $sid);
	return preg_match('/micro_delete_token\|s:32:"([0-9a-f]{32})"/', $data, $m) ? $m[1] : '';
}
function readyProject($tok, $sid, $name) {
	$r = req('POST', '/projects', $tok, array('straboId' => $sid, 'name' => $name));
	$pid = (int)$r['body']['pid'];
	req('POST', "/projects/$pid/push", $tok, array('pushId' => uuid(), 'clientId' => 'dweb-test',
		'changes' => array(array('op' => 'create', 'type' => 'project', 'id' => $sid, 'body' => array('name' => $name)))));
	req('POST', "/projects/$pid/ready", $tok);
	build_now($pid);
	return $pid;
}
/** The "deleted" page, by HTTP status and text. */
function isDeletedPage($r) {
	return $r['code'] === 410 && strpos($r['body'], 'This project was deleted') !== false;
}

$db->get_var('SELECT 1');
$U = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org',
               'dan' => 'dan.okafor@test.strabospot.org') as $k => $email) {
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	$U[$k] = array('pkey' => $pkey, 'tok' => token($pkey), 'email' => $email);
}

cleanup();
$run = substr(uuid(), 0, 8);

try {
	section('Setup: a shared synced project with a pending invitation and a permalink');
	$SID = $PREFIX . $run;
	$NAME = 'Delete web ' . $run;
	$PID = readyProject($U['owner']['tok'], $SID, $NAME);
	req('POST', "/projects/$PID/members", $U['owner']['tok'], array('email' => $U['editor']['email'], 'role' => 'editor'));
	req('POST', "/invites/$PID/accept", $U['editor']['tok']);
	req('POST', "/projects/$PID/members", $U['owner']['tok'], array('email' => $U['dan']['email'], 'role' => 'viewer'));
	$SLUG = micro_permalink_get_or_create($db, $SID, $U['owner']['pkey']);
	check('project ready with a permalink', $PID > 0 && $SLUG !== null);
	$OWNS = forgeSession($U['owner']['pkey']);
	$EDIT = forgeSession($U['editor']['pkey']);

	section('My StraboMicro Data');
	$r = page('GET', '/my_micro_data', $OWNS);
	check('Delete of a synced project goes to the confirmation page', strpos($r['body'], 'value="deletesynced"') !== false
		&& strpos($r['body'], "window.location='/micro_delete?project_id='") !== false);
	check('the Collaborators option opens the list', strpos($r['body'], "window.location='/micro_collaborators?project_id='") !== false);

	section('Confirmation page');
	$r = page('GET', "/micro_delete?project_id=$PID", $OWNS);
	check('owner: names the project, the shared copy, the member, the pending invitation, 30 days', $r['code'] === 200
		&& strpos($r['body'], $NAME) !== false && strpos($r['body'], 'This project is shared with one other person') !== false
		&& strpos($r['body'], $U['editor']['email']) !== false && strpos($r['body'], 'pending invitation waits') !== false
		&& strpos($r['body'], '30 days') !== false && strpos($r['body'], 'id="micro-delete-go" class="button primary" value="Delete from StraboSpot" disabled') !== false,
		substr(strip_tags($r['body']), 0, 300));
	$r = page('GET', "/micro_delete?project_id=$PID", $EDIT);
	check('member: not their page (back to My StraboMicro Data)', $r['code'] === 302 && strpos($r['location'], 'my_micro_data') !== false);
	$TOKEN = formToken($OWNS);
	check('form token in the session', strlen($TOKEN) === 32);

	section('Refused deletes');
	$r = page('POST', '/micro_delete', $OWNS, array('action' => 'delete', 'pid' => $PID, 'token' => $TOKEN, 'name' => 'wrong'));
	$my = page('GET', '/my_micro_data', $OWNS)['body'];
	check('wrong name: not deleted, says why', $r['code'] === 302 && strpos($my, 'did not match') !== false
		&& req('GET', "/projects/$PID", $U['editor']['tok'])['code'] === 200);
	page('POST', '/micro_delete', $OWNS, array('action' => 'delete', 'pid' => $PID, 'token' => 'x', 'name' => $NAME));
	$my = page('GET', '/my_micro_data', $OWNS)['body'];
	check('bad form token: not deleted', strpos($my, 'This form has expired') !== false && req('GET', "/projects/$PID", $U['editor']['tok'])['code'] === 200);
	page('POST', '/micro_delete', $EDIT, array('action' => 'delete', 'pid' => $PID, 'token' => formToken($EDIT) ?: 'x', 'name' => $NAME));
	check('member: cannot delete it', req('GET', "/projects/$PID", $U['editor']['tok'])['code'] === 200);

	section('Delete');
	$r = page('POST', '/micro_delete', $OWNS, array('action' => 'delete', 'pid' => $PID, 'token' => $TOKEN, 'name' => $NAME));
	$my = page('GET', '/my_micro_data', $OWNS)['body'];
	check('deleted, with the restore date in the banner', $r['code'] === 302 && strpos($my, 'was deleted from StraboSpot. You can restore it below until') !== false);
	$r = req('GET', "/projects/$PID", $U['editor']['tok']);
	check('member: 410 project_deleted', $r['code'] === 410 && $r['body']['error'] === 'project_deleted', $r['raw']);
	check('gone from the project list, in Deleted projects with Restore and days left',
		strpos($my, "id=\"mdl-$PID\"") === false && strpos($my, 'Deleted projects') !== false
		&& strpos($my, 'value="restore"') !== false && strpos($my, '30 days left') !== false, '');
	$banner = page('GET', '/my_micro_data', $OWNS)['body'];
	check('the banner shows once', strpos($banner, 'was deleted from StraboSpot. You can restore') === false);

	section('Viewers and permalinks say deleted');
	check('microproject?id= (owner)', isDeletedPage(page('GET', "/microproject?id=$PID", $OWNS)));
	check('microproject?id= (logged out)', isDeletedPage(page('GET', "/microproject?id=$PID", null)));
	check('microproject?m= permalink', isDeletedPage(page('GET', "/microproject?m=$SLUG", null)));
	check('straboMicroView/view?p=', isDeletedPage(page('GET', "/straboMicroView/view?p=$PID", $OWNS)));
	check('micro_project_landing_page?p=<straboId>', isDeletedPage(page('GET', "/micro_project_landing_page?p=$SID", null)));
	if (strpos((string)@file_get_contents('/srv/app/www/microview/index.php'), 'micro_sync_viewer_deleted') !== false) {
		check('microview/?p= (newer viewer)', isDeletedPage(page('GET', "/microview/?p=$PID", $OWNS)));
	} else {
		echo "  SKIP  microview/?p=: the copy in www/microview predates stage 6c\n";
	}

	section('Restore');
	page('POST', '/micro_delete', $OWNS, array('action' => 'restore', 'pid' => $PID, 'token' => 'x'));
	check('bad form token: not restored', req('GET', "/projects/$PID", $U['editor']['tok'])['code'] === 410);
	page('POST', '/micro_delete', $EDIT, array('action' => 'restore', 'pid' => $PID, 'token' => formToken($EDIT) ?: 'x'));
	check('member: cannot restore it', req('GET', "/projects/$PID", $U['editor']['tok'])['code'] === 410);
	$r = page('POST', '/micro_delete', $OWNS, array('action' => 'restore', 'pid' => $PID, 'token' => $TOKEN));
	$my = page('GET', '/my_micro_data', $OWNS)['body'];
	check('restored, banner says members see it again', $r['code'] === 302 && strpos($my, 'was restored') !== false);
	check('member sees it again', req('GET', "/projects/$PID", $U['editor']['tok'])['code'] === 200);
	check('Deleted projects section gone', strpos($my, 'Deleted projects') === false);
	check('viewer no longer says deleted', !isDeletedPage(page('GET', "/microproject?id=$PID", $OWNS)));
	build_now($PID);

	section('After the purge');
	page('POST', '/micro_delete', $OWNS, array('action' => 'delete', 'pid' => $PID, 'token' => $TOKEN, 'name' => $NAME));
	$db->prepare_query("UPDATE strabomicro.micro_deleted_projects SET deleted_at = now() - interval '31 days' WHERE project_id = $1", array($PID));
	exec('php /srv/app/www/microsync/tools/deleted.php --purge-due 2>&1', $out);
	check('purged', $db->get_var_prepared("SELECT 1 FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID)) === null, implode("\n", $out));
	check('permalink still says deleted', isDeletedPage(page('GET', "/microproject?m=$SLUG", null)));
	check('microproject?id= still says deleted', isDeletedPage(page('GET', "/microproject?id=$PID", null)));
	check('landing page by straboId still says deleted', isDeletedPage(page('GET', "/micro_project_landing_page?p=$SID", null)));
	check('not offered for restore any more', strpos(page('GET', '/my_micro_data', $OWNS)['body'], 'Deleted projects') === false);
	$r = page('POST', '/micro_delete', $OWNS, array('action' => 'restore', 'pid' => $PID, 'token' => $TOKEN));
	check('restore after the purge: refused', strpos(page('GET', '/my_micro_data', $OWNS)['body'], 'can no longer be restored') !== false);
	check('an unrelated id is still plain not found', !isDeletedPage(page('GET', '/microproject?id=999999999', null)));
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
