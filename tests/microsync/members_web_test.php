<?php
/**
 * File: members_web_test.php
 * Description: Website side of StraboMicro collaboration invitations
 *              (Phase 2 stage 1): the invitations table on My StraboMicro
 *              Data, accept and decline through micro_invitation.php (token
 *              required), the one-time result banner, the Collaborators
 *              option for synced projects, and micro_collaborators.php (open
 *              to active members only; the owner's invite, role and remove
 *              forms through micro_collaborators_action.php).
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config):
 *                docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/members_web_test.php
 *
 *              Logs in by writing PHP session files (removed afterwards).
 *              Projects use the mstest-mweb- straboId prefix and are deleted
 *              before and after the run. Exits non-zero on any failure.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
$BASE = 'http://localhost/microsync/v1';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microdb/lib/micro_members_web.php';

$PREFIX = 'mstest-mweb-';
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

$db->get_var('SELECT 1');
$U = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org',
               'outsider' => 'outsider@test.strabospot.org') as $k => $email) {
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
	$A = readyProject($U['owner']['tok'], $PREFIX . 'a-' . $run, 'Web Invite A ' . $run);
	$B = readyProject($U['owner']['tok'], $PREFIX . 'b-' . $run, 'Web Invite B ' . $run);
	req('POST', "/projects/$A/members", $U['owner']['tok'], array('email' => $U['editor']['email'], 'role' => 'contributor'));
	req('POST', "/projects/$B/members", $U['owner']['tok'], array('email' => $U['editor']['email'], 'role' => 'viewer'));
	$tokA = $db->get_var_prepared("SELECT invite_token FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($A, $U['editor']['pkey']));
	$tokB = $db->get_var_prepared("SELECT invite_token FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($B, $U['editor']['pkey']));
	$EDIT = forgeSession($U['editor']['pkey']);
	$OWNS = forgeSession($U['owner']['pkey']);
	$OUTS = forgeSession($U['outsider']['pkey']);

	section('My StraboMicro Data lists the invitations');
	$r = page('GET', '/my_micro_data', $EDIT);
	check('page loads', $r['code'] === 200, 'HTTP ' . $r['code']);
	check('both invitations listed with role and inviter', strpos($r['body'], 'Web Invite A ' . $run) !== false
		&& strpos($r['body'], 'Web Invite B ' . $run) !== false && strpos($r['body'], 'Contributor') !== false
		&& strpos($r['body'], $U['owner']['email']) !== false);
	check('Accept and Decline are form buttons', substr_count($r['body'], 'action="/micro_invitation"') >= 4
		&& strpos($r['body'], 'value="accept"') !== false && strpos($r['body'], 'value="decline"') !== false);
	check('outsider sees no invitation table', strpos(page('GET', '/my_micro_data', $OUTS)['body'], 'invited to collaborate on the following StraboMicro') === false);

	section('Accept and decline');
	$r = page('POST', '/micro_invitation', $EDIT, array('pid' => $A, 'token' => 'wrong', 'action' => 'accept'));
	check('wrong token -> redirect, nothing changes', $r['location'] === '/my_micro_data' && memberState($db, $A, $U['editor']['pkey']) === 'invited');
	check('wrong token message shown once', strpos(page('GET', '/my_micro_data', $EDIT)['body'], 'This invitation is no longer open.') !== false);
	$r = page('POST', '/micro_invitation', $OUTS, array('pid' => $A, 'token' => $tokA, 'action' => 'accept'));
	check('someone else cannot use the token', memberState($db, $A, $U['outsider']['pkey']) === null && memberState($db, $A, $U['editor']['pkey']) === 'invited');
	check('GET of the handler does nothing', page('GET', '/micro_invitation', $EDIT)['location'] === '/my_micro_data'
		&& memberState($db, $A, $U['editor']['pkey']) === 'invited');
	$r = page('POST', '/micro_invitation', $EDIT, array('pid' => $A, 'token' => $tokA, 'action' => 'accept'));
	check('accept -> active member', $r['location'] === '/my_micro_data' && memberState($db, $A, $U['editor']['pkey']) === 'active');
	$body = page('GET', '/my_micro_data', $EDIT)['body'];
	check('banner says joined, as Contributor, and how to download', strpos($body, 'You joined') !== false
		&& strpos($body, 'as Contributor') !== false && strpos($body, 'Open Remote Project') !== false);
	check('banner shows once', strpos(page('GET', '/my_micro_data', $EDIT)['body'], 'You joined') === false);
	page('POST', '/micro_invitation', $EDIT, array('pid' => $B, 'token' => $tokB, 'action' => 'decline'));
	check('decline -> declined', memberState($db, $B, $U['editor']['pkey']) === 'declined');
	check('decline banner', strpos(page('GET', '/my_micro_data', $EDIT)['body'], 'You declined the invitation') !== false);
	check('no invitations left on the page', strpos(page('GET', '/my_micro_data', $EDIT)['body'], 'Web Invite B ' . $run) === false);
	page('POST', '/micro_invitation', $EDIT, array('pid' => $B, 'token' => $tokB, 'action' => 'accept'));
	check('a declined invitation cannot be accepted later', memberState($db, $B, $U['editor']['pkey']) === 'declined');

	section('Collaborators page');
	build_now($A); // My StraboMicro Data shows a synced project once its views are built
	check('owner sees the Collaborators option for synced projects', strpos(page('GET', '/my_micro_data', $OWNS)['body'], 'value="collaborators"') !== false);
	$r = page('GET', "/micro_collaborators?project_id=$A", $OWNS);
	check('owner sees the members with roles', $r['code'] === 200 && strpos($r['body'], 'Web Invite A ' . $run) !== false
		&& strpos($r['body'], 'Contributor') !== false && strpos($r['body'], 'Owner') !== false
		&& strpos($r['body'], 'File &gt; Collaborate...') !== false);
	check('owner sees the declined invitation on B', strpos(page('GET', "/micro_collaborators?project_id=$B", $OWNS)['body'], 'Declined') !== false);
	check('member can open the list', strpos(page('GET', "/micro_collaborators?project_id=$A", $EDIT)['body'], 'Owner') !== false);
	check('outsider cannot', strpos(page('GET', "/micro_collaborators?project_id=$A", $OUTS)['body'], 'Project not found.') !== false);
	check('declined invitee cannot', strpos(page('GET', "/micro_collaborators?project_id=$B", $EDIT)['body'], 'Project not found.') !== false);

	section('parseEmailList');
	$l = MsMembers::parseEmailList("Jane Doe <jane@x.edu>; sam@y.org, JANE@x.edu\n\tpat@z.org\tKansas\nbad@ \"quoted@q.org\"");
	check('names skipped, brackets and quotes stripped, one per address', $l['valid'] === array('jane@x.edu', 'sam@y.org', 'pat@z.org', 'quoted@q.org'), json_encode($l));
	check('a word with an @ that is no address is reported', $l['invalid'] === array('bad@'), json_encode($l));

	section('Owner tools on the Collaborators page');
	$C = readyProject($U['owner']['tok'], $PREFIX . 'c-' . $run, 'Web Manage C ' . $run);
	$url = "/micro_collaborators?project_id=$C";
	$action = '/micro_collaborators_action';
	$csrfOf = function ($sid, $pid) use ($db) {
		$b = page('GET', "/micro_collaborators?project_id=$pid", $sid)['body'];
		return preg_match('/name="csrf" value="([0-9a-f]+)"/', $b, $m) ? $m[1] : '';
	};
	$body = page('GET', $url, $OWNS)['body'];
	check('owner sees the invite form', strpos($body, 'name="emails"') !== false && strpos($body, 'Send Invitations') !== false);
	$CS = $csrfOf($OWNS, $C);
	check('the form has a secret', strlen($CS) === 64);

	$outsiderEmail = $U['outsider']['email'];
	$noneEmail = 'nobody-' . $run . '@test.strabospot.org';
	$r = page('POST', $action, $OWNS, array('pid' => $C, 'action' => 'invite', 'role' => 'viewer', 'emails' => $outsiderEmail));
	check('no secret -> refused', memberState($db, $C, $U['outsider']['pkey']) === null && $r['location'] === $url);
	check('no secret -> message shown once', strpos(page('GET', $url, $OWNS)['body'], 'Nothing was changed') !== false
		&& strpos(page('GET', $url, $OWNS)['body'], 'Nothing was changed') === false);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => 'wrong', 'action' => 'invite', 'role' => 'viewer', 'emails' => $outsiderEmail));
	check('wrong secret -> refused', memberState($db, $C, $U['outsider']['pkey']) === null);
	check('GET of the handler does nothing', page('GET', $action . "?pid=$C&action=remove&pkey=" . $U['outsider']['pkey'], $OWNS)['location'] === '/my_micro_data');

	$paste = 'Ed Itor <' . $U['editor']['email'] . ">; $outsiderEmail, " . strtoupper($outsiderEmail) . "\n$noneEmail bad@ <script>@x " . $U['owner']['email'];
	$r = page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'invite', 'role' => 'viewer', 'emails' => $paste));
	check('paste invites each account once, with the role', memberState($db, $C, $U['editor']['pkey']) === 'invited' && memberState($db, $C, $U['outsider']['pkey']) === 'invited'
		&& $db->get_var_prepared("SELECT role FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($C, $U['editor']['pkey'])) === 'viewer');
	check('nobody is invited twice', (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_members WHERE project_id = $1", array($C)) === 3);
	$body = page('GET', $url, $OWNS)['body'];
	check('report: invited 2', strpos($body, 'Invited (2)') !== false, substr($body, 0, 0));
	check('report: no account, in a box to copy', strpos($body, 'No StraboSpot account (1)') !== false && strpos($body, $noneEmail . '</textarea>') !== false);
	check('report: bad words and myself under could not invite', strpos($body, 'Could not invite (3)') !== false && strpos($body, 'already the owner') !== false);
	check('report escapes what was pasted', strpos($body, 'script>@x') === false && strpos($body, 'script&gt;@x') !== false);
	check('report shows once', strpos(page('GET', $url, $OWNS)['body'], 'Invited (2)') === false);

	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'invite', 'role' => 'viewer', 'emails' => $outsiderEmail));
	check('sending again is reported as such', strpos(page('GET', $url, $OWNS)['body'], 'Invitation sent again (1)') !== false);
	$many = array();
	for ($i = 0; $i < 101; $i++) {
		$many[] = "many$i-$run@test.strabospot.org";
	}
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'invite', 'role' => 'viewer', 'emails' => implode(',', $many)));
	check('over 100 refused whole', strpos(page('GET', $url, $OWNS)['body'], 'at most 100') !== false);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'invite', 'role' => 'owner', 'emails' => $outsiderEmail));
	check('the owner role cannot be given', strpos(page('GET', $url, $OWNS)['body'], 'role must be') !== false
		&& $db->get_var_prepared("SELECT role FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($C, $U['outsider']['pkey'])) === 'viewer');

	section('Roles and removal');
	$tokC = $db->get_var_prepared("SELECT invite_token FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($C, $U['editor']['pkey']));
	page('POST', '/micro_invitation', $EDIT, array('pid' => $C, 'token' => $tokC, 'action' => 'accept'));
	$roleOf = function ($pkey) use ($db, $C) {
		return $db->get_var_prepared("SELECT role FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($C, $pkey));
	};
	check('an active member appears in the app list too', count(array_filter(req('GET', "/projects/$C/members", $U['owner']['tok'])['body']['members'], function ($m) use ($U) {
		return (int)$m['user']['pkey'] === $U['editor']['pkey'] && $m['state'] === 'active';
	})) === 1);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'role', 'pkey' => $U['editor']['pkey'], 'role' => 'editor'));
	check('owner changes a role', $roleOf($U['editor']['pkey']) === 'editor');
	check('the app sees the new role', req('GET', "/projects/$C/members", $U['editor']['tok'])['body']['myRole'] === 'editor');
	check('role message', strpos(page('GET', $url, $OWNS)['body'], 'is now Editor') !== false);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'role', 'pkey' => $U['owner']['pkey'], 'role' => 'viewer'));
	check('the owner row cannot be changed', $roleOf($U['owner']['pkey']) === 'owner');

	$body = page('GET', $url, $EDIT)['body'];
	check('a member gets no owner tools', strpos($body, 'name="emails"') === false && strpos($body, '/micro_collaborators_action') === false
		&& strpos($body, 'Only the project owner') !== false);
	// A member's session has no form, but a forger could hold a valid secret: the rules still refuse
	$sessPath = '/var/lib/php/sessions/sess_' . $EDIT;
	file_put_contents($sessPath, 'micro_collab_token|s:4:"abcd";', FILE_APPEND);
	page('POST', $action, $EDIT, array('pid' => $C, 'csrf' => 'abcd', 'action' => 'role', 'pkey' => $U['outsider']['pkey'], 'role' => 'editor'));
	check('a member cannot change roles', $roleOf($U['outsider']['pkey']) === 'viewer');
	page('POST', $action, $EDIT, array('pid' => $C, 'csrf' => 'abcd', 'action' => 'remove', 'pkey' => $U['outsider']['pkey']));
	check('a member cannot remove someone', memberState($db, $C, $U['outsider']['pkey']) === 'invited');
	page('POST', $action, $EDIT, array('pid' => $C, 'csrf' => 'abcd', 'action' => 'invite', 'role' => 'viewer', 'emails' => $noneEmail));
	check('a member cannot invite', strpos(page('GET', $url, $EDIT)['body'], 'Only the project owner can manage') !== false);

	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'remove', 'pkey' => $U['outsider']['pkey']));
	check('withdraw an invitation', memberState($db, $C, $U['outsider']['pkey']) === 'removed');
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'remove', 'pkey' => $U['editor']['pkey']));
	check('remove an active member', memberState($db, $C, $U['editor']['pkey']) === 'removed');
	check('the removed member loses the page', strpos(page('GET', $url, $EDIT)['body'], 'Project not found.') !== false);
	page('GET', $url, $OWNS);
	page('POST', $action, $OWNS, array('pid' => $C, 'csrf' => $CS, 'action' => 'remove', 'pkey' => $U['owner']['pkey']));
	check('the owner cannot be removed here', memberState($db, $C, $U['owner']['pkey']) === 'active');

	$dec = $db->get_var_prepared("SELECT state FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($B, $U['editor']['pkey']));
	$CSB = $csrfOf($OWNS, $B);
	$body = page('GET', "/micro_collaborators?project_id=$B", $OWNS)['body'];
	check('a declined invitation offers Invite again', $dec === 'declined' && strpos($body, 'Invite again') !== false && strpos($body, '>Clear<') !== false);
	page('POST', $action, $OWNS, array('pid' => $B, 'csrf' => $CSB, 'action' => 'invite', 'role' => 'viewer', 'emails' => $U['editor']['email']));
	check('Invite again works', memberState($db, $B, $U['editor']['pkey']) === 'invited');

} finally {
	cleanup();
	foreach ($sessionFiles as $f) {
		@unlink($f);
	}
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
