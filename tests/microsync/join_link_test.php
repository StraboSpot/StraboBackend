<?php
/**
 * File: join_link_test.php
 * Description: StraboMicro join links on the website: making, revoking and
 *              expiring a link, the join page logged out and in, the owner's
 *              waiting list, Approve / Deny / Approve All, the printable PDF.
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config):
 *                docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/join_link_test.php
 *
 *              Logs in by writing PHP session files (removed afterwards).
 *              Projects use the mstest-mjoin- straboId prefix and are deleted
 *              before and after the run. Exits non-zero on any failure.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
$BASE = 'http://localhost/microsync/v1';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microdb/lib/micro_join_web.php';

$PREFIX = 'mstest-mjoin-';
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
		"SELECT id FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%')) as $r) {
		$dir = '/srv/app/www/straboMicroFiles/' . (int)$r->id;
		if ((int)$r->id > 0 && is_dir($dir)) {
			exec('rm -rf ' . escapeshellarg($dir));
		}
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
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
/** Website request with a session cookie; $form = POST fields. Returns code, body, location. */
function page($method, $path, $sid, $form = null) {
	$ch = curl_init('http://localhost' . $path);
	$o = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => array('Cookie: PHPSESSID=' . $sid));
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
function readyProject($tok, $sid, $name) {
	$r = req('POST', '/projects', $tok, array('straboId' => $sid, 'name' => $name));
	$pid = (int)$r['body']['pid'];
	req('POST', "/projects/$pid/push", $tok, array('pushId' => uuid(), 'clientId' => 'mweb-test',
		'changes' => array(array('op' => 'create', 'type' => 'project', 'id' => $sid, 'body' => array('name' => $name)))));
	req('POST', "/projects/$pid/ready", $tok);
	return $pid;
}
function memberState($db, $pid, $pkey) {
	return $db->get_var_prepared("SELECT state FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($pid, $pkey));
}


function stateOf($db, $pid, $pkey) {
	return $db->get_var_prepared("SELECT state FROM strabomicro.micro_join_requests WHERE project_id = $1 AND user_pkey = $2", array($pid, $pkey));
}
function csrfOf($sid, $pid) {
	$b = page('GET', "/micro_collaborators?project_id=$pid", $sid)['body'];
	return preg_match('/name="csrf" value="([0-9a-f]+)"/', $b, $m) ? $m[1] : '';
}
function joinCsrf($sid, $code) {
	$b = page('GET', "/join/$code", $sid)['body'];
	return preg_match('/name="csrf" value="([0-9a-f]+)"/', $b, $m) ? $m[1] : '';
}
function ask($sid, $code) {
	return page('POST', "/join/$code", $sid, array('csrf' => joinCsrf($sid, $code)));
}
function codeOf($db, $pid) {
	return $db->get_var_prepared("SELECT code FROM strabomicro.micro_join_links WHERE project_id = $1 AND revoked_at IS NULL", array($pid));
}

$db->get_var('SELECT 1');
$U = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org',
               'outsider' => 'outsider@test.strabospot.org', 'readonly' => 'readonly@test.strabospot.org') as $k => $email) {
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	$U[$k] = array('pkey' => $pkey, 'tok' => token($pkey), 'email' => $email, 'sid' => forgeSession($pkey));
}
$action = '/micro_collaborators_action';

cleanup();
$run = substr(uuid(), 0, 8);

try {
	$name = 'Join <b>Test</b> ' . $run;
	$C = readyProject($U['owner']['tok'], $PREFIX . 'c-' . $run, $name);
	$url = "/micro_collaborators?project_id=$C";
	$OWNS = $U['owner']['sid'];
	$CS = csrfOf($OWNS, $C);

	section('Making the link');
	$body = page('GET', $url, $OWNS)['body'];
	check('owner is offered Create Join Link, Contributor and 7 days preselected', strpos($body, 'Create Join Link') !== false
		&& strpos($body, '<option value="contributor" selected>') !== false && strpos($body, '<option value="7" selected>') !== false);
	page('POST', $action, $OWNS, array('pid' => $C, 'action' => 'create_link', 'role' => 'contributor', 'days' => 7));
	check('no secret -> no link', codeOf($db, $C) === null || codeOf($db, $C) === '');
	page('GET', $url, $OWNS);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'create_link', 'role' => 'owner', 'days' => 7));
	check('the owner role cannot be given', (string)codeOf($db, $C) === '' && strpos(page('GET', $url, $OWNS)['body'], 'role must be') !== false);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'create_link', 'role' => 'contributor', 'days' => 5));
	check('only the offered expiries', (string)codeOf($db, $C) === '');
	page('GET', $url, $OWNS);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'create_link', 'role' => 'contributor', 'days' => 7));
	$code = codeOf($db, $C);
	check('link made, 8 letters from the plain alphabet', preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{8}$/', (string)$code) === 1, (string)$code);
	page('GET', $url, $OWNS);
	$body = page('GET', $url, $OWNS)['body'];
	check('the page shows the QR code, address and buttons', strpos($body, '<svg') !== false && strpos($body, "/join/$code") !== false
		&& strpos($body, 'Show Full Screen') !== false && strpos($body, 'Revoke Link') !== false && strpos($body, 'Download PDF') !== false);
	check('the QR code shows the Strabo logo', strpos($body, 'strabo_icon_web.png') !== false);

	section('Join page, logged out');
	$r = page('GET', "/join/$code", 'loggedoutsession00000000000');
	check('open without logging in', $r['code'] === 200 && strpos($r['body'], 'Log In') !== false && strpos($r['body'], 'Create Account') !== false);
	check('the project name stays hidden', strpos($r['body'], 'Test</b>') === false && strpos($r['body'], $run) === false);
	check('Create Account carries the code', strpos($r['body'], "/register?join=$code") !== false);
	$r = page('GET', "/register?join=$code", 'loggedoutsession00000000001');
	check('the sign-up form keeps the code', strpos($r['body'], 'name="join" value="' . $code . '"') !== false);
	$r = page('GET', '/register?join=<script>', 'loggedoutsession00000000002');
	check('and only letters and digits of it', strpos($r['body'], '<script>"') === false && strpos($r['body'], 'name="join" value="script"') !== false);
	check('an unknown code says so', strpos(page('GET', '/join/NOSUCHCODE', 'loggedoutsession00000000003')['body'], 'not valid') !== false);

	section('Asking');
	$E = $U['editor'];
	$body = page('GET', "/join/$code", $E['sid'])['body'];
	check('logged in: name escaped, role, Ask to Join', strpos($body, 'Join &lt;b&gt;Test&lt;/b&gt; ' . $run) !== false && strpos($body, 'join as Contributor') !== false && strpos($body, 'Ask to Join') !== false);
	check('a lowercase code works', strpos(page('GET', '/join/' . strtolower($code), $E['sid'])['body'], 'Ask to Join') !== false);
	page('POST', "/join/$code", $E['sid'], array('csrf' => 'wrong'));
	check('wrong secret -> no request', stateOf($db, $C, $E['pkey']) === null);
	page('GET', "/join/$code", $E['sid']);
	ask($E['sid'], $code);
	check('asking makes a request, not a member', stateOf($db, $C, $E['pkey']) === 'requested' && memberState($db, $C, $E['pkey']) === null);
	check('the page says waiting', strpos(page('GET', "/join/$code", $E['sid'])['body'], 'Your request was sent') !== false);
	check('status answers requested', page('GET', "/join/$code?status=1", $E['sid'])['body'] === 'requested');
	check('still no access through the app API', req('GET', "/projects/$C/members", $E['tok'])['code'] === 404);
	check('asking twice is one request', ask($E['sid'], $code)['code'] === 302 && (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_join_requests WHERE project_id = $1", array($C)) === 1);
	$list = req('GET', "/projects/$C/members", $U['owner']['tok'])['body']['members'];
	check('waiting people are not in the member list', count($list) === 1);

	section('Owner decides');
	$frag = page('GET', "$url&waiting=1", $OWNS)['body'];
	check('waiting list shows name, email, Approve, Approve All', strpos($frag, $E['email']) !== false && strpos($frag, 'Approve All') !== false && strpos($frag, 'value="approve"') !== false);
	check('the list alone, no page around it', strpos($frag, '<html') === false);
	check('non-members cannot read it', strpos(page('GET', "$url&waiting=1", $E['sid'])['body'], $E['email']) === false);
	file_put_contents('/var/lib/php/sessions/sess_' . $E['sid'], 'micro_collab_token|s:4:"abcd";', FILE_APPEND);
	page('POST', $action, $E['sid'], array('pid' => $C, 'csrf' => 'abcd', 'action' => 'approve', 'pkey' => $E['pkey']));
	check('nobody approves themselves', memberState($db, $C, $E['pkey']) === null);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'approve', 'pkey' => $E['pkey']));
	check('approve -> active member with the link role', memberState($db, $C, $E['pkey']) === 'active' && req('GET', "/projects/$C/members", $E['tok'])['body']['myRole'] === 'contributor');
	check('the joiner sees You\'re in', page('GET', "/join/$code?status=1", $E['sid'])['body'] === 'member' && strpos(page('GET', "/join/$code", $E['sid'])['body'], 'Open Remote Project') !== false);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'role', 'pkey' => $E['pkey'], 'role' => 'editor'));
	check('the owner changes the role afterwards', req('GET', "/projects/$C/members", $E['tok'])['body']['myRole'] === 'editor');
	page('GET', $url, $OWNS);

	$X = $U['outsider'];
	ask($X['sid'], $code);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'deny', 'pkey' => $X['pkey']));
	check('deny -> no member', stateOf($db, $C, $X['pkey']) === 'denied' && memberState($db, $C, $X['pkey']) === null);
	check('the person is told', strpos(page('GET', "/join/$code", $X['sid'])['body'], 'did not approve') !== false);
	ask($X['sid'], $code);
	check('cannot ask again with the same link', stateOf($db, $C, $X['pkey']) === 'denied');
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'approve', 'pkey' => $X['pkey']));
	check('a denied request cannot be approved later', memberState($db, $C, $X['pkey']) === null);
	page('GET', $url, $OWNS);

	section('A new link, Approve All, removal');
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'create_link', 'role' => 'viewer', 'days' => 1));
	$code2 = codeOf($db, $C);
	check('a new link replaces the old', $code2 !== $code && strpos(page('GET', "/join/$code", $X['sid'])['body'], 'not valid') !== false);
	$R = $U['readonly'];
	ask($X['sid'], $code2);
	ask($R['sid'], $code2);
	check('denied on the old link may ask with the new', stateOf($db, $C, $X['pkey']) === 'requested');
	page('GET', $url, $OWNS);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'approve_all'));
	check('Approve All -> both active as Viewer', memberState($db, $C, $X['pkey']) === 'active' && memberState($db, $C, $R['pkey']) === 'active'
		&& req('GET', "/projects/$C/members", $R['tok'])['body']['myRole'] === 'viewer');
	check('Approve All reports the count', strpos(page('GET', $url, $OWNS)['body'], 'Approved 2 people.') !== false);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'remove', 'pkey' => $R['pkey']));
	page('GET', $url, $OWNS);
	check('a removed person can only ask, not walk back in', ask($R['sid'], $code2)['code'] === 302 && stateOf($db, $C, $R['pkey']) === 'requested' && memberState($db, $C, $R['pkey']) === 'removed');

	section('Revoke and expiry');
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'revoke_link'));
	check('revoked: the code stops', strpos(page('GET', "/join/$code2", $X['sid'])['body'], 'not valid') !== false);
	check('people who joined stay', memberState($db, $C, $X['pkey']) === 'active');
	check('a request already waiting stays on the list', strpos(page('GET', "$url&waiting=1", $OWNS)['body'], $R['email']) !== false);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'approve', 'pkey' => $R['pkey']));
	check('and can still be approved', memberState($db, $C, $R['pkey']) === 'active');
	page('GET', $url, $OWNS);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'remove', 'pkey' => $R['pkey']));
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'create_link', 'role' => 'contributor', 'days' => 1));
	$code3 = codeOf($db, $C);
	$db->prepare_query("UPDATE strabomicro.micro_join_links SET expires_at = now() - interval '1 minute' WHERE code = $1", array($code3));
	$db->prepare_query("DELETE FROM strabomicro.micro_join_requests WHERE project_id = $1 AND user_pkey = $2", array($C, $R['pkey']));
	check('expired: the page says so', strpos(page('GET', "/join/$code3", $R['sid'])['body'], 'has expired') !== false);
	ask($R['sid'], $code3);
	$expiredPost = page('POST', "/join/$code3", $R['sid'], array('csrf' => 'x'));
	check('expired: no request', stateOf($db, $C, $R['pkey']) === null);
	page('GET', $url, $OWNS);
	check('expired: the owner sees it and can make a new one', strpos(page('GET', $url, $OWNS)['body'], 'The last join link expired') !== false && strpos(page('GET', $url, $OWNS)['body'], 'Create Join Link') !== false);

	section('Own copy with the same id');
	$sameId = $PREFIX . 'same-' . $run;
	$D = readyProject($U['owner']['tok'], $sameId, 'Same A ' . $run);
	$CSD = csrfOf($OWNS, $D);
	page('POST', $action, $OWNS, array('pid' => $D, 'csrf' => $CSD, 'action' => 'create_link', 'role' => 'viewer', 'days' => 1));
	$codeD = codeOf($db, $D);
	ask($R['sid'], $codeD);
	$db->prepare_query("INSERT INTO strabomicro.micro_projectmetadata (userpkey, strabo_id, name) VALUES ($1, $2, $3)", array($R['pkey'], $sameId, 'copy'));
	page('POST', $action, $OWNS, array('pid' => $D, 'csrf' => $CSD, 'action' => 'approve_all'));
	check('Approve All reports who could not join', memberState($db, $D, $R['pkey']) === null && strpos(page('GET', "/micro_collaborators?project_id=$D", $OWNS)['body'], 'Could not approve') !== false);
	page('POST', $action, $OWNS, array('pid' => $D, 'csrf' => $CSD, 'action' => 'deny', 'pkey' => $R['pkey']));
	$db->prepare_query("UPDATE strabomicro.micro_join_links SET revoked_at = now() WHERE project_id = $1", array($D));
	page('GET', "/micro_collaborators?project_id=$D", $OWNS);
	page('POST', $action, $OWNS, array('pid' => $D, 'csrf' => $CSD, 'action' => 'create_link', 'role' => 'viewer', 'days' => 1));
	check('the person is told before asking', strpos(page('GET', '/join/' . codeOf($db, $D), $R['sid'])['body'], 'your own project with the same id') !== false);

	section('Printable page');
	$E2 = readyProject($U['owner']['tok'], $PREFIX . 'e-' . $run, 'PDF ' . $run);
	$CSE = csrfOf($OWNS, $E2);
	page('POST', $action, $OWNS, array('pid' => $E2, 'csrf' => $CSE, 'action' => 'create_link', 'role' => 'contributor', 'days' => 7));
	page('GET', "/micro_collaborators?project_id=$E2", $OWNS);
	$pages = function ($pdf) {
		return preg_match_all('#/Type /Page\b(?!s)#', $pdf);
	};
	check('no secret -> no PDF', page('POST', '/micro_join_pdf', $OWNS, array('pid' => $E2))['location'] !== '');
	$r = page('POST', '/micro_join_pdf', $OWNS, array('pid' => $E2, 'csrf' => $CSE, 'title' => '', 'details' => ''));
	check('a PDF of one page, blank fields', strpos($r['body'], '%PDF') === 0 && $pages($r['body']) === 1, substr($r['body'], 0, 40));
	$long = str_repeat("A long line of details about the workshop, its room and its hours.\n", 40);
	$r = page('POST', '/micro_join_pdf', $OWNS, array('pid' => $E2, 'csrf' => $CSE, 'title' => str_repeat('Long title ', 30), 'details' => $long));
	check('still one page with far too much text', strpos($r['body'], '%PDF') === 0 && $pages($r['body']) === 1);
	$title = 'Κλάση <script>alert(1)</script> Ünï';
	page('POST', '/micro_join_pdf', $OWNS, array('pid' => $E2, 'csrf' => $CSE, 'title' => $title, 'details' => "Room 2\nFriday"));
	$body = page('GET', "/micro_collaborators?project_id=$E2", $OWNS)['body'];
	check('fields are kept and escaped on the page', strpos($body, 'Κλάση &lt;script&gt;') !== false && strpos($body, '<script>alert(1)') === false && strpos($body, "Room 2\nFriday") !== false);
	check('a member cannot make the PDF', strpos(page('POST', '/micro_join_pdf', $E['sid'], array('pid' => $C, 'csrf' => 'abcd', 'title' => 'x'))['body'], '%PDF') !== 0);

	section('Addresses');
	check('the local dev address stays local; real hosts use the site', micro_join_url('ABC') === 'https://strabospot.org/join/ABC');
	$_SERVER['HTTP_HOST'] = 'localhost';
	check('localhost', micro_join_url('ABC') === 'http://localhost/join/ABC');
	unset($_SERVER['HTTP_HOST']);
	$m = micro_join_matrix('https://strabospot.org/join/ABCDEFGH');
	check('the logo area stays inside the code, about 5 percent', $m['logoSize'] * $m['logoSize'] / ($m['size'] * $m['size']) < 0.07 && $m['logoFrom'] > 8);

} finally {
	cleanup();
	foreach ($sessionFiles as $f) {
		@unlink($f);
	}
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
