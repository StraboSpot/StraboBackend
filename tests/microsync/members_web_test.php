<?php
/**
 * File: members_web_test.php
 * Description: Website side of StraboMicro collaboration invitations
 *              (Phase 2 stage 1): the invitations table on My StraboMicro
 *              Data, accept and decline through micro_invitation.php (token
 *              required), the one-time result banner, the Collaborators
 *              option for synced projects, and micro_collaborators.php (open
 *              to active members only).
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

} finally {
	cleanup();
	foreach ($sessionFiles as $f) {
		@unlink($f);
	}
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
