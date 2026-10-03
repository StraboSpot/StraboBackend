<?php
/**
 * File: delete_test.php
 * Description: HTTP test suite for deleting a synced StraboMicro project
 *              (v3 §12b 17p, 17ac, 17ad; microsync/lib/MsDelete.php): the
 *              owner's DELETE, the 410 project_deleted answer (members only,
 *              also after the purge), hiding (projects list, invitations,
 *              legacy lists, downloads, worker), create of the same straboId,
 *              restore (tools/deleted.php), the purge after 30 days, and the
 *              legacy delete door (30-day delete when the owner is alone,
 *              refused when shared).
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config; run
 *              as root, the worker and tool write project folders):
 *                docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/delete_test.php
 *
 *              Fixture users from tests/collaboration/setup_test_data.php.
 *              Hermetic: projects use the mstest-delete- straboId prefix and
 *              are removed (rows, tombstones, folders) before and after the run.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
$BASE = 'http://localhost/microsync/v1';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microdb/lib/sync_guard.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';

$PREFIX = 'mstest-delete-';
$FILES = '/srv/app/www/straboMicroFiles';
$failures = array();
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
	// Built projects have legacy rows that block a plain row delete
	foreach ((array)$db->get_results_prepared(
		"SELECT strabo_id, userpkey FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%')) as $r) {
		(new StraboMicro(null, (int)$r->userpkey, $db))->deleteProjectRows($r->strabo_id);
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	$db->prepare_query("DELETE FROM strabomicro.micro_deleted_projects WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	foreach ($ids as $id) {
		foreach (array("$FILES/$id", "$FILES/_deleted/$id") as $dir) {
			if ($id > 0 && is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
		}
	}
}
function push1($pid, $tok, $change) {
	$r = req('POST', "/projects/$pid/push", $tok, array('pushId' => uuid(), 'clientId' => 'delete-test', 'changes' => array($change)));
	if (isset($r['body']['results'][0])) {
		$x = $r['body']['results'][0];
		return $x['status'] . (isset($x['reason']) ? '/' . $x['reason'] : '');
	}
	return 'http_' . $r['code'] . (isset($r['body']['error']) ? '/' . $r['body']['error'] : '');
}
function dataset($sid, $id) {
	return array('op' => 'create', 'type' => 'dataset', 'id' => $id, 'parentType' => 'project', 'parentId' => $sid, 'body' => array('name' => $id));
}
function pids($list) {
	return array_map(function ($p) { return $p['pid']; }, $list);
}
function tool($args) {
	$out = array();
	exec('php /srv/app/www/microsync/tools/deleted.php ' . $args . ' 2>&1', $out, $rc);
	return array('rc' => $rc, 'out' => implode("\n", $out));
}
/** Is the project (by id) in the legacy lists (website, old apps)? */
function legacyVisible($pid) {
	global $db;
	return (int)$db->get_var_prepared(
		"SELECT count(*) FROM micro_projectmetadata WHERE id = $1 AND " . micro_sync_visible_sql(), array($pid)) === 1;
}
/** A synced project owned by $tok with one dataset, ready and built. */
function makeProject($tok, $sid, $name) {
	$r = req('POST', '/projects', $tok, array('straboId' => $sid, 'name' => $name));
	$pid = (int)$r['body']['pid'];
	push1($pid, $tok, array('op' => 'create', 'type' => 'project', 'id' => $sid, 'body' => array('name' => $name)));
	push1($pid, $tok, dataset($sid, 'D1'));
	req('POST', "/projects/$pid/ready", $tok);
	return $pid;
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
	$U[$k] = array('pkey' => $pkey, 'tok' => token($pkey), 'email' => $email);
}
$OWN = $U['owner']['tok'];
$EDT = $U['editor']['tok'];
$OUT = $U['outsider']['tok'];
$DAN = $U['dan']['tok'];

cleanup();
$SID = $PREFIX . substr(uuid(), 0, 8);

try {
	section('Setup: shared project, one member, one pending invitation, built');
	$PID = makeProject($OWN, $SID, 'Delete test');
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['editor']['email'], 'role' => 'editor'));
	check('editor joins', req('POST', "/invites/$PID/accept", $EDT)['code'] === 200);
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['dan']['email'], 'role' => 'viewer'));
	check('built', build_now($PID) === 'built');
	check('folder exists', is_dir("$FILES/$PID"));
	check('legacy lists show it', legacyVisible($PID));

	section('Who may delete');
	$r = req('DELETE', "/projects/$PID", $EDT);
	check('editor -> 403 forbidden', $r['code'] === 403 && $r['body']['error'] === 'forbidden', $r['raw']);
	check('outsider -> 404', req('DELETE', "/projects/$PID", $OUT)['code'] === 404);

	section('Owner deletes');
	$r = req('DELETE', "/projects/$PID", $OWN);
	$until = isset($r['body']['restorableUntil']) ? strtotime($r['body']['restorableUntil']) : 0;
	check('owner -> 200 deleted, restorable for 30 days', $r['code'] === 200 && $r['body']['deleted'] === true
		&& abs($until - (time() + 30 * 86400)) < 120, $r['raw']);
	$r = req('GET', "/projects/$PID", $EDT);
	check('member: 410 project_deleted, by the owner, with the name', $r['code'] === 410 && $r['body']['error'] === 'project_deleted'
		&& $r['body']['byMe'] === false && $r['body']['deletedBy']['pkey'] === $U['owner']['pkey'] && $r['body']['deletedBy']['name'] !== ''
		&& $r['body']['project']['pid'] === $PID && $r['body']['project']['name'] === 'Delete test'
		&& $r['body']['restorableUntil'] !== null, $r['raw']);
	$r = req('GET', "/projects/$PID", $OWN);
	check('owner (another computer): 410 with byMe', $r['code'] === 410 && $r['body']['byMe'] === true, $r['raw']);
	check('outsider: 404, learns nothing', req('GET', "/projects/$PID", $OUT)['code'] === 404);
	check('member push -> 410', push1($PID, $EDT, dataset($SID, 'D-late')) === 'http_410/project_deleted');
	check('member activity poll -> 410', req('POST', "/projects/$PID/activity", $EDT, array('clientId' => 'delete-test'))['code'] === 410);
	check('member changes -> 410', req('GET', "/projects/$PID/changes?since=0", $EDT)['code'] === 410);
	check('delete again -> 410', req('DELETE', "/projects/$PID", $OWN)['code'] === 410);
	check('nothing was added after the delete',
		(int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_id = 'D-late'", array($PID)) === 0);

	section('Hidden while deleted');
	check('not in GET projects (owner)', !in_array($PID, pids(req('GET', '/projects', $OWN)['body']), true));
	check('not in GET projects (member)', !in_array($PID, pids(req('GET', '/projects', $EDT)['body']), true));
	$inv = req('GET', '/invites', $DAN)['body']['invitations'];
	check('the pending invitation is not listed', !in_array($PID, pids($inv), true));
	$r = req('POST', "/invites/$PID/accept", $DAN);
	check('accepting it -> 410 project_deleted', $r['code'] === 410 && $r['body']['error'] === 'project_deleted', $r['raw']);
	check('not in the legacy lists', !legacyVisible($PID));
	clearstatcache();
	check('folder moved to _deleted', !is_dir("$FILES/$PID") && is_dir("$FILES/_deleted/$PID"));
	$r = http_req('GET', "http://localhost/download_micro_file.php?project_id=$PID");
	check('download -> 404', $r['code'] === 404, $r['code']);
	check('_deleted is not served', http_req('GET', "http://localhost/straboMicroFiles/_deleted/$PID/project.json")['code'] === 403);
	check('worker skips it', build_now($PID) === 'skipped');
	$r = req('POST', '/projects', $OWN, array('straboId' => $SID, 'name' => 'Again'));
	check('turning sync on for the same project id -> 410 (restore it first)', $r['code'] === 410 && $r['body']['error'] === 'project_deleted', $r['raw']);
	check('legacy upload of it is refused', is_string(micro_sync_upload_plan($db, $U['owner']['pkey'], $SID))
		&& micro_sync_upload_refusal($db, $U['owner']['pkey'], $SID) !== null);
	$t = tool('--list');
	check('tools/deleted.php --list shows it, restorable', strpos($t['out'], "$PID  Delete test") !== false && strpos($t['out'], 'restorable until') !== false, $t['out']);

	section('Restore');
	$t = tool("--purge=$PID");
	check('early purge without --force is refused', $t['rc'] !== 0 && strpos($t['out'], '--force') !== false, $t['out']);
	$t = tool("--restore=$PID");
	check('restore', $t['rc'] === 0, $t['out']);
	$r = req('GET', "/projects/$PID", $EDT);
	check('member sees it again (still editor)', $r['code'] === 200 && $r['body']['role'] === 'editor', $r['raw']);
	check('back in GET projects', in_array($PID, pids(req('GET', '/projects', $EDT)['body']), true));
	check('the invitation is back', in_array($PID, pids(req('GET', '/invites', $DAN)['body']['invitations']), true));
	clearstatcache();
	check('folder back', is_dir("$FILES/$PID") && !is_dir("$FILES/_deleted/$PID"));
	check('member can push again', push1($PID, $EDT, dataset($SID, 'D2')) === 'accepted');
	check('views rebuilt', build_now($PID) === 'built' && legacyVisible($PID));
	check('restore again -> not deleted', tool("--restore=$PID")['rc'] !== 0);

	section('Purge after 30 days');
	check('delete again', req('DELETE', "/projects/$PID", $OWN)['code'] === 200);
	$db->prepare_query("UPDATE strabomicro.micro_deleted_projects SET deleted_at = now() - interval '31 days' WHERE project_id = $1", array($PID));
	$t = tool("--restore=$PID");
	check('restore after 30 days -> refused', $t['rc'] !== 0 && strpos($t['out'], 'not_restorable') !== false, $t['out']);
	$t = tool('--purge-due');
	check('purge-due purges it', $t['rc'] === 0 && strpos($t['out'], (string)$PID) !== false, $t['out']);
	check('project row and its store are gone',
		$db->get_var_prepared("SELECT 1 FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID)) === null
		&& (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1", array($PID)) === 0
		&& (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_members WHERE project_id = $1", array($PID)) === 0);
	check('legacy rows are gone', (int)$db->get_var_prepared(
		"SELECT count(*) FROM micro_datasetmetadata d JOIN micro_projectmetadata p ON p.id = d.project_id WHERE p.strabo_id = $1",
		array($SID)) === 0);
	clearstatcache();
	check('folders are gone', !is_dir("$FILES/$PID") && !is_dir("$FILES/_deleted/$PID"));
	$r = req('GET', "/projects/$PID", $EDT);
	check('member: still 410 project_deleted after the purge, no restore date', $r['code'] === 410
		&& $r['body']['error'] === 'project_deleted' && $r['body']['restorableUntil'] === null, $r['raw']);
	check('outsider: 404', req('GET', "/projects/$PID", $OUT)['code'] === 404);
	check('purge-due again: nothing', strpos(tool('--purge-due')['out'], 'Purged 0') !== false);
	$r = req('POST', '/projects', $OWN, array('straboId' => $SID, 'name' => 'Fresh'));
	check('after the purge the project id can be synced again (new pid)', $r['code'] === 201 && (int)$r['body']['pid'] !== $PID, $r['raw']);

	section('Legacy delete door (website, old apps)');
	$SID2 = $PREFIX . substr(uuid(), 0, 8);
	$PID2 = makeProject($OWN, $SID2, 'Delete test solo');
	req('POST', "/projects/$PID2/members", $OWN, array('email' => $U['editor']['email'], 'role' => 'editor'));
	req('POST', "/invites/$PID2/accept", $EDT);
	$res = micro_sync_delete($db, $U['owner']['pkey'], $SID2);
	check('shared: refused', is_string($res) && strpos($res, 'shared with other people') !== false, var_export($res, true));
	req('DELETE', "/projects/$PID2/members/" . $U['editor']['pkey'], $OWN);
	$res = micro_sync_delete($db, $U['owner']['pkey'], $SID2);
	check('owner alone: the 30-day delete, not a permanent one', $res === true
		&& $db->get_var_prepared("SELECT 1 FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID2)) !== null
		&& req('GET', "/projects/$PID2", $OWN)['code'] === 410);
	check('deleting it again there: handled, nothing changes', micro_sync_delete($db, $U['owner']['pkey'], $SID2) === true);
	check('a legacy project goes the old way', micro_sync_delete($db, $U['owner']['pkey'], $PREFIX . 'not-synced') === null);
} catch (Throwable $e) {
	check('unexpected error: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getLine(), false);
}

cleanup();
echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED') . "\n";
exit(count($failures) === 0 ? 0 : 1);
