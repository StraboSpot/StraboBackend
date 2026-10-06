<?php
/**
 * File: chat_test.php
 * Description: HTTP test suite for project chat (MsChat, collaboration spec
 *              v3 17bd-17bi): who may read and post (Viewers yes, outsiders
 *              and removed members no, deleted project 410), text and refs
 *              validation, resend with the same clientMsgId, newest page,
 *              older pages, since=rev (incl. deletions), delete rules (own,
 *              owner moderation), read markers and unread counts, the
 *              20-a-minute limit, the live notices (ids only, never text),
 *              and that chat never moves the project's head (not project
 *              data, 17bg g).
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config):
 *                docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/chat_test.php
 *
 *              Fixture users from tests/collaboration/setup_test_data.php.
 *              Hermetic: projects use the mstest-chat- straboId prefix and
 *              are removed (rows, tombstones, folders) before and after.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
$BASE = 'http://localhost/microsync/v1';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microdb/lib/sync_guard.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';

$PREFIX = 'mstest-chat-';
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
/** A synced, ready project of $tok's account; returns its pid. */
function makeProject($tok, $name) {
	global $PREFIX;
	$sid = $PREFIX . substr(uuid(), 0, 8);
	$r = req('POST', '/projects', $tok, array('straboId' => $sid, 'name' => $name));
	$pid = (int)$r['body']['pid'];
	req('POST', "/projects/$pid/push", $tok, array('pushId' => uuid(), 'clientId' => 'chat-test',
		'changes' => array(array('op' => 'create', 'type' => 'project', 'id' => $sid, 'body' => array('name' => $name)))));
	req('POST', "/projects/$pid/ready", $tok);
	return $pid;
}
function share($pid, $ownerTok, $email, $memberTok, $role) {
	req('POST', "/projects/$pid/members", $ownerTok, array('email' => $email, 'role' => $role));
	return req('POST', "/invites/$pid/accept", $memberTok)['code'] === 200;
}
function say($pid, $tok, $text, $refs = null, $cid = null) {
	$body = array('clientMsgId' => $cid === null ? uuid() : $cid, 'text' => $text);
	if ($refs !== null) $body['refs'] = $refs;
	return req('POST', "/projects/$pid/chat", $tok, $body);
}
/** Live notices of type $t for $pid (all that arrive within $wait s) */
function notices($listen, $pid, $t, $wait = 1.0) {
	$out = array();
	$end = microtime(true) + $wait;
	while (microtime(true) < $end) {
		$n = pg_get_notify($listen, PGSQL_ASSOC);
		if ($n !== false) {
			$m = json_decode($n['payload'], true);
			if (is_array($m) && isset($m['pid']) && (int)$m['pid'] === (int)$pid && $m['t'] === $t) {
				$out[] = array('msg' => $m, 'raw' => $n['payload']);
			}
			continue;
		}
		usleep(20000);
	}
	return $out;
}
function drain($listen) {
	while (pg_get_notify($listen, PGSQL_ASSOC) !== false) {
	}
}

$db->get_var('SELECT 1');
$U = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org',
               'viewer' => 'readonly@test.strabospot.org', 'outsider' => 'outsider@test.strabospot.org',
               'maya' => 'maya.chen@test.strabospot.org') as $k => $email) {
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	$U[$k] = array('pkey' => $pkey, 'tok' => token($pkey), 'email' => $email);
}
$OWN = $U['owner']['tok'];
$EDT = $U['editor']['tok'];
$VIE = $U['viewer']['tok'];
$OUT = $U['outsider']['tok'];
$MAY = $U['maya']['tok'];

cleanup();
$listen = pg_connect("host=$dbhost dbname=$dbname user=$dbusername password=$dbpassword");
pg_query($listen, 'LISTEN microsync_live');

try {
	section('Setup');
	$PID = makeProject($OWN, 'Chat test');
	check('project ready', $PID > 0);
	check('editor joins', share($PID, $OWN, $U['editor']['email'], $EDT, 'editor'));
	check('viewer joins', share($PID, $OWN, $U['viewer']['email'], $VIE, 'viewer'));
	check('maya joins (contributor)', share($PID, $OWN, $U['maya']['email'], $MAY, 'contributor'));
	$head0 = (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID));

	section('Who may chat');
	check('outsider cannot read (404)', req('GET', "/projects/$PID/chat", $OUT)['code'] === 404);
	check('outsider cannot post (404)', say($PID, $OUT, 'hello')['code'] === 404);
	$r = req('GET', "/projects/$PID/chat", $VIE);
	check('empty chat: no messages, rev 0, unread 0', $r['code'] === 200 && $r['body']['messages'] === array()
		&& $r['body']['rev'] === 0 && $r['body']['unread'] === 0 && $r['body']['hasMore'] === false, $r['raw']);
	drain($listen);
	$r = say($PID, $VIE, 'A question from the viewer');
	check('viewer can post (17bf a)', $r['code'] === 200 && $r['body']['message']['author']['pkey'] === $U['viewer']['pkey']
		&& $r['body']['message']['author']['name'] !== '', $r['raw']);
	$n = notices($listen, $PID, 'chat');
	check('send NOTIFYs chat {pid, rev} once', count($n) === 1 && $n[0]['msg']['rev'] === $r['body']['message']['rev'], json_encode($n));
	check('the notice carries no text (17ah)', count($n) === 1 && strpos($n[0]['raw'], 'question') === false
		&& array_keys($n[0]['msg']) === array('t', 'pid', 'rev'), json_encode($n));

	section('Validation');
	check('bad clientMsgId -> 400', req('POST', "/projects/$PID/chat", $OWN, array('clientMsgId' => 'x', 'text' => 'hi'))['code'] === 400);
	check('missing text -> 400', req('POST', "/projects/$PID/chat", $OWN, array('clientMsgId' => uuid()))['code'] === 400);
	check('only whitespace -> 400', say($PID, $OWN, "  \n\t ")['code'] === 400);
	check('4,000 characters -> 200', say($PID, $OWN, str_repeat('a', 4000))['code'] === 200);
	$r = say($PID, $OWN, str_repeat('a', 4001));
	check('4,001 characters -> 400 too_long', $r['code'] === 400 && $r['body']['error'] === 'too_long' && $r['body']['maxChars'] === 4000, $r['raw']);
	check('4,000 two-byte characters count as 4,000 (not bytes)', say($PID, $OWN, str_repeat("\u{00e9}", 4000))['code'] === 200);
	$r = say($PID, $OWN, "  line one\r\nline\x07 two\ttab  ");
	check('trimmed, \\r\\n -> \\n, control characters dropped, tab kept', $r['code'] === 200
		&& $r['body']['message']['text'] === "line one\nline two\ttab", $r['raw']);
	check('ref of another type -> 400', say($PID, $OWN, 'x', array(array('type' => 'sample', 'id' => 's1')))['code'] === 400);
	check('ref without id -> 400', say($PID, $OWN, 'x', array(array('type' => 'spot')))['code'] === 400);
	$eleven = array();
	for ($i = 0; $i < 11; $i++) $eleven[] = array('type' => 'spot', 'id' => "s$i");
	check('11 refs -> 400', say($PID, $OWN, 'x', $eleven)['code'] === 400);
	$r = say($PID, $OWN, 'Look at this', array(array('type' => 'spot', 'id' => 'sp-1'), array('type' => 'micrograph', 'id' => 'mg-1'),
		array('type' => 'spot', 'id' => 'sp-1')));
	check('refs kept in order, duplicates dropped', $r['code'] === 200 && $r['body']['message']['refs'] ===
		array(array('type' => 'spot', 'id' => 'sp-1'), array('type' => 'micrograph', 'id' => 'mg-1')), $r['raw']);

	section('Resend with the same clientMsgId');
	$cid = uuid();
	$a = say($PID, $EDT, 'Sent twice', null, $cid);
	$b = say($PID, $EDT, 'Sent twice', null, strtoupper($cid));
	check('second send returns the first message (duplicate)', $a['code'] === 200 && $b['code'] === 200
		&& $b['body']['message']['id'] === $a['body']['message']['id'] && $b['body']['duplicate'] === true, $b['raw']);
	check('only one row', (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_chat WHERE project_id = $1 AND client_msg_id = $2",
		array($PID, $cid)) === 1);
	$c = say($PID, $OWN, 'Same id, other author', null, $cid);
	check('another author may use the same clientMsgId', $c['code'] === 200 && $c['body']['message']['id'] !== $a['body']['message']['id'], $c['raw']);

	section('Reading and unread');
	$r = req('GET', "/projects/$PID/chat", $EDT);
	$msgs = $r['body']['messages'];
	$ids = array_column($msgs, 'id');
	$sorted = $ids;
	sort($sorted);
	check('newest page, oldest first', $r['code'] === 200 && count($msgs) === 7 && $ids === $sorted, $r['raw']);
	check('rev = newest rev', $r['body']['rev'] === max(array_column($msgs, 'rev')));
	check('editor unread = messages by others (6 of 7)', $r['body']['unread'] === 6 && $r['body']['lastRead'] === 0, json_encode($r['body']['unread']));
	check('viewer unread excludes own message', req('GET', "/projects/$PID/chat", $VIE)['body']['unread'] === 6);
	$rev = $r['body']['rev'];
	$r = req('GET', "/projects/$PID/chat?since=$rev", $EDT);
	check('since=newest rev -> nothing', $r['code'] === 200 && $r['body']['messages'] === array() && $r['body']['rev'] === $rev, $r['raw']);
	$m = say($PID, $MAY, 'After the rev');
	$r = req('GET', "/projects/$PID/chat?since=$rev", $EDT);
	check('since=rev -> only the new message', count($r['body']['messages']) === 1 && $r['body']['messages'][0]['id'] === $m['body']['message']['id'], $r['raw']);
	check('since and before together -> 400', req('GET', "/projects/$PID/chat?since=1&before=5", $EDT)['code'] === 400);
	check('head_seq unchanged by chat (not project data)',
		(int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID)) === $head0);
	check('no change log rows for chat', (int)$db->get_var_prepared(
		"SELECT count(*) FROM strabomicro.micro_changes WHERE project_id = $1 AND seq > $2", array($PID, $head0)) === 0);

	section('Read markers');
	drain($listen);
	$newest = $m['body']['message']['id'];
	$r = req('POST', "/projects/$PID/chat/read", $EDT, array('id' => $ids[2]));
	check('mark read up to the 3rd message', $r['code'] === 200 && $r['body']['lastRead'] === $ids[2], $r['raw']);
	$n = notices($listen, $PID, 'chatread');
	check('chatread notice names the user and id', count($n) === 1 && $n[0]['msg']['user'] === $U['editor']['pkey']
		&& $n[0]['msg']['id'] === $ids[2], json_encode($n));
	$r = req('POST', "/projects/$PID/chat/read", $EDT, array('id' => $ids[0]));
	check('never moves back', $r['body']['lastRead'] === $ids[2], $r['raw']);
	$r = req('POST', "/projects/$PID/chat/read", $EDT, array('id' => $newest + 1000));
	check('clamped to the newest message, unread 0', $r['body']['lastRead'] === $newest && $r['body']['unread'] === 0, $r['raw']);
	check('another member keeps their own count', req('GET', "/projects/$PID/chat", $VIE)['body']['unread'] === 7);
	check('bad id -> 400', req('POST', "/projects/$PID/chat/read", $EDT, array('id' => 'x'))['code'] === 400);

	section('Delete');
	$own = say($PID, $EDT, 'Oops, wrong project')['body']['message'];
	$ownerMsg = say($PID, $OWN, 'Owner says hi')['body']['message'];
	drain($listen);
	check('editor cannot delete the owner\'s message (403)', req('DELETE', "/projects/$PID/chat/" . $ownerMsg['id'], $EDT)['code'] === 403);
	$r = req('DELETE', "/projects/$PID/chat/" . $own['id'], $EDT);
	check('editor deletes own message', $r['code'] === 200 && $r['body']['deleted'] === true && $r['body']['rev'] > $own['rev'], $r['raw']);
	$n = notices($listen, $PID, 'chat');
	check('delete NOTIFYs chat with the new rev', count($n) === 1 && $n[0]['msg']['rev'] === $r['body']['rev'], json_encode($n));
	check('delete again -> already', req('DELETE', "/projects/$PID/chat/" . $own['id'], $EDT)['body']['already'] === true);
	$r = req('GET', "/projects/$PID/chat?since=" . $own['rev'], $VIE);
	$found = null;
	foreach ($r['body']['messages'] as $x) if ($x['id'] === $own['id']) $found = $x;
	check('since shows the deletion: empty text, deletedAt, deletedBy', $found !== null && $found['text'] === ''
		&& $found['deletedAt'] !== null && $found['deletedBy']['pkey'] === $U['editor']['pkey'], $r['raw']);
	check('deleted text is gone from the row', $db->get_var_prepared("SELECT body FROM strabomicro.micro_chat WHERE id = $1", array($own['id'])) === '');
	$vm = say($PID, $VIE, 'Viewer message to moderate')['body']['message'];
	check('owner deletes anyone\'s message (moderation)', req('DELETE', "/projects/$PID/chat/" . $vm['id'], $OWN)['code'] === 200);
	check('unknown message -> 404', req('DELETE', "/projects/$PID/chat/999999999", $OWN)['code'] === 404);
	$before = req('GET', "/projects/$PID/chat", $MAY)['body']['unread'];
	$tmp = say($PID, $EDT, 'counted then deleted')['body']['message'];
	req('DELETE', "/projects/$PID/chat/" . $tmp['id'], $EDT);
	check('a deleted message is not unread', req('GET', "/projects/$PID/chat", $MAY)['body']['unread'] === $before);

	section('Paging');
	$P2 = makeProject($OWN, 'Chat paging');
	share($P2, $OWN, $U['editor']['email'], $EDT, 'editor');
	// 230 messages straight into the table (the API would hit the rate limit)
	$db->prepare_query(
		"INSERT INTO strabomicro.micro_chat (project_id, author_pkey, client_msg_id, body)
		 SELECT $1::int, $2::int, md5(g::text || '-' || $1::int)::uuid, 'm' || g FROM generate_series(1, 230) g",
		array($P2, $U['owner']['pkey']));
	$r = req('GET', "/projects/$P2/chat", $EDT);
	check('newest page = 100, hasMore', count($r['body']['messages']) === 100 && $r['body']['hasMore'] === true
		&& $r['body']['messages'][99]['text'] === 'm230' && $r['body']['messages'][0]['text'] === 'm131', $r['raw']);
	check('unread counts all 230', $r['body']['unread'] === 230);
	$r2 = req('GET', "/projects/$P2/chat?before=" . $r['body']['messages'][0]['id'], $EDT);
	check('before -> the 100 older ones', count($r2['body']['messages']) === 100 && $r2['body']['messages'][0]['text'] === 'm31'
		&& $r2['body']['messages'][99]['text'] === 'm130' && $r2['body']['hasMore'] === true, $r2['raw']);
	$r3 = req('GET', "/projects/$P2/chat?before=" . $r2['body']['messages'][0]['id'], $EDT);
	check('last page: 30, no more', count($r3['body']['messages']) === 30 && $r3['body']['hasMore'] === false, $r3['raw']);
	$r4 = req('GET', "/projects/$P2/chat?since=0&limit=50", $EDT);
	check('since=0, limit 50: first 50 by rev, hasMore, rev = the 50th', count($r4['body']['messages']) === 50 && $r4['body']['hasMore'] === true
		&& $r4['body']['rev'] === $r4['body']['messages'][49]['rev'], $r4['raw']);

	section('Rate limit (20 a minute per person and project)');
	$P3 = makeProject($OWN, 'Chat rate');
	share($P3, $OWN, $U['maya']['email'], $MAY, 'viewer');
	$ok = 0;
	for ($i = 1; $i <= 20; $i++) {
		if (say($P3, $MAY, "m$i")['code'] === 200) $ok++;
	}
	check('20 messages in a minute are fine', $ok === 20);
	$r = say($P3, $MAY, 'm21');
	check('21st -> 429 slow_down with retryAfter', $r['code'] === 429 && $r['body']['error'] === 'slow_down'
		&& $r['body']['retryAfter'] >= 1 && $r['body']['retryAfter'] <= 60, $r['raw']);
	check('another person is not limited', say($P3, $OWN, 'owner is fine')['code'] === 200);
	check('the same person in another project is not limited', say($PID, $MAY, 'other project')['code'] === 200);

	section('Removed member, deleted project');
	check('remove the viewer', req('DELETE', "/projects/$PID/members/" . $U['viewer']['pkey'], $OWN)['code'] === 200);
	$r = req('GET', "/projects/$PID/chat", $VIE);
	check('removed member cannot read (403 access_removed)', $r['code'] === 403 && $r['body']['error'] === 'access_removed', $r['raw']);
	check('removed member cannot post (403)', say($PID, $VIE, 'still here?')['code'] === 403);
	check('their earlier message stays with their name', (int)$db->get_var_prepared(
		"SELECT count(*) FROM strabomicro.micro_chat WHERE project_id = $1 AND author_pkey = $2 AND deleted_at IS NULL",
		array($PID, $U['viewer']['pkey'])) === 1);
	check('owner deletes the project', req('DELETE', "/projects/$PID", $OWN)['code'] === 200);
	$r = req('GET', "/projects/$PID/chat", $EDT);
	check('chat of a deleted project -> 410 project_deleted', $r['code'] === 410 && $r['body']['error'] === 'project_deleted', $r['raw']);
	check('rows kept while restorable', (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_chat WHERE project_id = $1", array($PID)) > 0);
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P2));
	check('project row removed (purge) -> its chat and read markers cascade', (int)$db->get_var_prepared(
		"SELECT (SELECT count(*) FROM strabomicro.micro_chat WHERE project_id = $1) + (SELECT count(*) FROM strabomicro.micro_chat_reads WHERE project_id = $1)",
		array($P2)) === 0);
} finally {
	cleanup();
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
