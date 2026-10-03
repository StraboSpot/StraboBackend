<?php
/**
 * File: members_test.php
 * Description: HTTP test suite for the membership endpoints of the
 *              StraboMicro sync API (collaboration Phase 2, MsMembers):
 *              invite (validation, no account, resend, re-invite, P0-14
 *              copy owner), my invitations, accept and decline, member list
 *              per role, role change, remove and leave (incl. parking of a
 *              removed member's next push), and ownership transfer (offer,
 *              decline, withdraw; accepting is held until the StraboSamples
 *              re-key stage).
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config):
 *                docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/members_test.php
 *
 *              Fixture users from tests/collaboration/setup_test_data.php
 *              (@test.strabospot.org; their mail is filed to mail.log).
 *              Hermetic: projects use the mstest-members- straboId prefix
 *              and are deleted before and after the run. Exits non-zero on
 *              any failure.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/includes/StraboMail.php';
$BASE = 'http://localhost/microsync/v1';
require_once '/srv/app/www/tests/lib/microsync_client.php';

$PREFIX = 'mstest-members-';
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
	global $db, $PREFIX;
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
}
function push1($pid, $tok, $change) {
	$r = req('POST', "/projects/$pid/push", $tok, array('pushId' => uuid(), 'clientId' => 'members-test', 'changes' => array($change)));
	if (isset($r['body']['results'][0])) {
		$x = $r['body']['results'][0];
		return $x['status'] . (isset($x['reason']) ? '/' . $x['reason'] : '');
	}
	return 'http_' . $r['code'] . (isset($r['body']['error']) ? '/' . $r['body']['error'] : '');
}
function dataset($sid, $id) {
	return array('op' => 'create', 'type' => 'dataset', 'id' => $id, 'parentType' => 'project', 'parentId' => $sid, 'body' => array('name' => $id));
}
function memberOf($list, $pkey) {
	foreach ($list['members'] as $m) {
		if ($m['user']['pkey'] === $pkey) return $m;
	}
	return null;
}
function mailCount($subjectPart) {
	$f = StraboMail::logFile();
	return is_file($f) ? substr_count(file_get_contents($f), $subjectPart) : 0;
}

$db->get_var('SELECT 1');
$U = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org',
               'viewer' => 'readonly@test.strabospot.org', 'outsider' => 'outsider@test.strabospot.org',
               'maya' => 'maya.chen@test.strabospot.org', 'dan' => 'dan.okafor@test.strabospot.org') as $k => $email) {
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
$DAN = $U['dan']['tok'];

cleanup();
$SID = $PREFIX . substr(uuid(), 0, 8);

try {
	section('Setup');
	$r = req('POST', '/projects', $OWN, array('straboId' => $SID, 'name' => 'Members test'));
	check('create project', $r['code'] === 201, $r['raw']);
	$PID = (int)$r['body']['pid'];
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => $U['editor']['email'], 'role' => 'editor'));
	check('invite before the first upload is done -> 409 not_ready', $r['code'] === 409 && $r['body']['error'] === 'not_ready', $r['raw']);
	check('push project entity', push1($PID, $OWN, array('op' => 'create', 'type' => 'project', 'id' => $SID, 'body' => array('name' => 'Members test'))) === 'accepted');
	check('ready', req('POST', "/projects/$PID/ready", $OWN)['code'] === 200);

	section('Invite: validation');
	check('outsider cannot invite (404, not a member)', req('POST', "/projects/$PID/members", $OUT, array('email' => $U['dan']['email']))['code'] === 404);
	check('bad email -> 400', req('POST', "/projects/$PID/members", $OWN, array('email' => 'nope'))['code'] === 400);
	check('role owner -> 400', req('POST', "/projects/$PID/members", $OWN, array('email' => $U['dan']['email'], 'role' => 'owner'))['code'] === 400);
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => 'nobody-' . uuid() . '@test.strabospot.org'));
	check('unknown email -> 404 no_account with the agreed wording', $r['code'] === 404 && $r['body']['error'] === 'no_account'
		&& strpos($r['body']['message'], 'Ask them to create one') !== false, $r['raw']);
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => $U['owner']['email']));
	check('inviting myself -> 400 self', $r['code'] === 400 && $r['body']['error'] === 'self', $r['raw']);

	section('Invite, resend, accept');
	$before = mailCount("collaborate on \"Members test\" in StraboMicro");
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => strtoupper($U['editor']['email']), 'role' => 'contributor'));
	check('invite (email case ignored) -> 201 invited, emailed', $r['code'] === 201 && $r['body']['status'] === 'invited'
		&& $r['body']['emailed'] === true && $r['body']['member']['user']['pkey'] === $U['editor']['pkey'], $r['raw']);
	check('invitation email filed with the project name', mailCount("collaborate on \"Members test\" in StraboMicro") === $before + 1);
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => $U['editor']['email'], 'role' => 'editor'));
	check('invite again while pending -> 200 reinvited with the new role', $r['code'] === 200 && $r['body']['status'] === 'reinvited'
		&& $r['body']['member']['role'] === 'editor', $r['raw']);
	check('invited user cannot see the project yet', req('GET', "/projects/$PID", $EDT)['code'] === 404);
	check('invited user cannot push', push1($PID, $EDT, dataset($SID, 'D-early')) === 'http_404/not_found');
	$r = req('GET', '/invites', $EDT);
	$inv = array_values(array_filter($r['body']['invitations'], function ($i) use ($PID) { return $i['pid'] === $PID; }));
	check('GET invites lists it (role, name, invitedBy, owner)', count($inv) === 1 && $inv[0]['role'] === 'editor'
		&& $inv[0]['name'] === 'Members test' && $inv[0]['invitedBy']['pkey'] === $U['owner']['pkey']
		&& $inv[0]['owner']['pkey'] === $U['owner']['pkey'], $r['raw']);
	$r = req('GET', "/projects/$PID/members", $OWN);
	$m = memberOf($r['body'], $U['editor']['pkey']);
	check('owner list: owner first, editor invited', $r['body']['members'][0]['role'] === 'owner' && $m !== null && $m['state'] === 'invited');
	$r = req('POST', "/invites/$PID/accept", $EDT);
	check('accept -> 200 with what the app needs to download', $r['code'] === 200 && $r['body']['role'] === 'editor'
		&& $r['body']['straboId'] === $SID && $r['body']['name'] === 'Members test', $r['raw']);
	check('accept again -> 404', req('POST', "/invites/$PID/accept", $EDT)['code'] === 404);
	$r = req('GET', '/projects', $EDT);
	$mine = array_values(array_filter($r['body'], function ($p) use ($PID) { return $p['pid'] === $PID; }));
	check('accepted project is in GET projects as editor', count($mine) === 1 && $mine[0]['role'] === 'editor');
	check('editor can push', push1($PID, $EDT, dataset($SID, 'D-editor')) === 'accepted');
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => $U['editor']['email'], 'role' => 'viewer'));
	check('invite an active member -> 409 already_member', $r['code'] === 409 && $r['body']['error'] === 'already_member', $r['raw']);

	section('Decline and re-invite');
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['dan']['email'], 'role' => 'viewer'));
	$r = req('POST', "/invites/$PID/decline", $DAN);
	check('decline -> 200', $r['code'] === 200 && $r['body']['status'] === 'declined', $r['raw']);
	check('declined invitation leaves GET invites', count(array_filter(req('GET', '/invites', $DAN)['body']['invitations'],
		function ($i) use ($PID) { return $i['pid'] === $PID; })) === 0);
	$m = memberOf(req('GET', "/projects/$PID/members", $OWN)['body'], $U['dan']['pkey']);
	check('owner sees the declined invitation', $m !== null && $m['state'] === 'declined');
	check('editor does not see declined invitations', memberOf(req('GET', "/projects/$PID/members", $EDT)['body'], $U['dan']['pkey']) === null);
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => $U['dan']['email'], 'role' => 'contributor'));
	check('re-invite after decline -> 200 invited', $r['code'] === 200 && $r['body']['status'] === 'invited', $r['raw']);
	check('decline by someone not invited -> 404', req('POST', "/invites/$PID/decline", $OUT)['code'] === 404);

	section('P0-14: invitee owns another project with this straboId');
	$db->prepare_query("INSERT INTO strabomicro.micro_projectmetadata (strabo_id, userpkey, name, ispublic) VALUES ($1, $2, 'copy', false)",
		array($SID, $U['outsider']['pkey']));
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => $U['outsider']['email']));
	check('invite -> 409 invitee_has_copy', $r['code'] === 409 && $r['body']['error'] === 'invitee_has_copy', $r['raw']);
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id = $1 AND userpkey = $2", array($SID, $U['outsider']['pkey']));

	section('Role change');
	check('editor cannot change roles (403)', req('PATCH', "/projects/$PID/members/" . $U['editor']['pkey'], $EDT, array('role' => 'viewer'))['code'] === 403);
	check('role owner -> 400', req('PATCH', "/projects/$PID/members/" . $U['editor']['pkey'], $OWN, array('role' => 'owner'))['code'] === 400);
	$r = req('PATCH', "/projects/$PID/members/" . $U['owner']['pkey'], $OWN, array('role' => 'editor'));
	check('changing the owner -> 409 owner', $r['code'] === 409 && $r['body']['error'] === 'owner', $r['raw']);
	check('non-member -> 404', req('PATCH', "/projects/$PID/members/" . $U['outsider']['pkey'], $OWN, array('role' => 'viewer'))['code'] === 404);
	$r = req('PATCH', "/projects/$PID/members/" . $U['editor']['pkey'], $OWN, array('role' => 'viewer'));
	check('owner makes editor a viewer', $r['code'] === 200 && $r['body']['member']['role'] === 'viewer', $r['raw']);
	check('role change recorded for an active member', $db->get_var_prepared(
		"SELECT role_changed_at IS NOT NULL FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2",
		array($PID, $U['editor']['pkey'])) === 't');
	$r = req('POST', "/projects/$PID/push", $EDT, array('pushId' => uuid(), 'clientId' => 'members-test',
		'changes' => array(dataset($SID, 'D-viewer'), array('op' => 'update', 'type' => 'dataset', 'id' => 'D-editor',
			'baseVersion' => 1, 'fields' => array('name' => 'renamed by a viewer')))));
	$res = isset($r['body']['results']) ? $r['body']['results'] : array();
	check('viewer push is refused, and parked after the downgrade (17k)', count($res) === 2
		&& $res[0]['status'] === 'forbidden' && $res[0]['reason'] === 'viewer' && $res[0]['parked'] === true
		&& $res[1]['status'] === 'forbidden' && $res[1]['parked'] === true, $r['raw']);
	$parked = $db->get_var_prepared(
		"SELECT payload::text FROM strabomicro.micro_parked_pushes WHERE project_id = $1 AND user_pkey = $2 AND status = 'pending'",
		array($PID, $U['editor']['pkey']));
	$pj = json_decode((string)$parked, true);
	check('one parked push with both changes, reason role_changed', is_array($pj) && $pj['reason'] === 'role_changed'
		&& $pj['role'] === 'viewer' && count($pj['changes']) === 2 && $pj['changes'][1]['id'] === 'D-editor', (string)$parked);
	check('parked changes were not applied', $db->get_var_prepared(
		"SELECT body->>'name' FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'dataset' AND entity_id = 'D-editor'",
		array($PID)) === 'D-editor');
	check('pending invitation role can change', req('PATCH', "/projects/$PID/members/" . $U['dan']['pkey'], $OWN, array('role' => 'editor'))['code'] === 200);
	req('PATCH', "/projects/$PID/members/" . $U['editor']['pkey'], $OWN, array('role' => 'editor'));

	section('Remove and leave');
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['maya']['email'], 'role' => 'contributor'));
	req('POST', "/invites/$PID/accept", $MAY);
	check('contributor can push', push1($PID, $MAY, dataset($SID, 'D-maya')) === 'accepted');
	$r = req('POST', "/projects/$PID/push", $MAY, array('pushId' => uuid(), 'clientId' => 'members-test',
		'changes' => array(array('op' => 'update', 'type' => 'dataset', 'id' => 'D-editor', 'baseVersion' => 1,
			'fields' => array('name' => 'not mine')))));
	$x = isset($r['body']['results'][0]) ? $r['body']['results'][0] : array();
	check('role never changed: a refusal is not parked', isset($x['reason']) && $x['reason'] === 'contributor_not_creator'
		&& !isset($x['parked']) && $db->get_var_prepared(
			"SELECT count(*) FROM strabomicro.micro_parked_pushes WHERE project_id = $1 AND user_pkey = $2",
			array($PID, $U['maya']['pkey'])) === '0', $r['raw']);
	check('editor cannot remove others (403)', req('DELETE', "/projects/$PID/members/" . $U['maya']['pkey'], $EDT)['code'] === 403);
	$r = req('DELETE', "/projects/$PID/members/" . $U['owner']['pkey'], $OWN);
	check('owner cannot leave -> 409 owner_must_transfer', $r['code'] === 409 && $r['body']['error'] === 'owner_must_transfer', $r['raw']);
	$r = req('DELETE', "/projects/$PID/members/" . $U['maya']['pkey'], $OWN);
	check('owner removes the contributor', $r['code'] === 200 && $r['body']['status'] === 'removed', $r['raw']);
	$r = req('GET', "/projects/$PID", $MAY);
	check('removed member cannot read: 403 access_removed, removed by the owner (17k)', $r['code'] === 403
		&& $r['body']['error'] === 'access_removed' && $r['body']['left'] === false
		&& $r['body']['removedBy']['pkey'] === $U['owner']['pkey'] && $r['body']['removedBy']['name'] !== ''
		&& $r['body']['project']['pid'] === $PID && $r['body']['project']['name'] === 'Members test', $r['raw']);
	$r = req('POST', "/projects/$PID/activity", $MAY, array('since' => 0, 'clientId' => 'members-test'));
	check('the activity poll says so too', $r['code'] === 403 && $r['body']['error'] === 'access_removed', $r['raw']);
	check('removed member\'s next push is parked', push1($PID, $MAY, dataset($SID, 'D-maya2')) === 'http_403/access_changed');
	check('and the one after is refused', push1($PID, $MAY, dataset($SID, 'D-maya3')) === 'http_403/access_removed');
	check('removing again -> 404', req('DELETE', "/projects/$PID/members/" . $U['maya']['pkey'], $OWN)['code'] === 404);
	$r = req('DELETE', "/projects/$PID/members/" . $U['dan']['pkey'], $OWN);
	check('owner withdraws a pending invitation', $r['code'] === 200);
	check('withdrawn invitation leaves GET invites', count(array_filter(req('GET', '/invites', $DAN)['body']['invitations'],
		function ($i) use ($PID) { return $i['pid'] === $PID; })) === 0);
	check('withdrawn invitation cannot be accepted', req('POST', "/invites/$PID/accept", $DAN)['code'] === 404);
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['viewer']['email'], 'role' => 'viewer'));
	req('POST', "/invites/$PID/accept", $VIE);
	$r = req('DELETE', "/projects/$PID/members/" . $U['viewer']['pkey'], $VIE);
	check('a member leaves', $r['code'] === 200 && $r['body']['status'] === 'left', $r['raw']);
	$r = req('GET', "/projects/$PID", $VIE);
	check('after leaving: 403 access_removed, left, nobody named', $r['code'] === 403 && $r['body']['error'] === 'access_removed'
		&& $r['body']['left'] === true && $r['body']['removedBy'] === null, $r['raw']);
	check('an outsider still gets 404', req('GET', "/projects/$PID", $OUT)['code'] === 404);
	$r = req('POST', "/projects/$PID/members", $OWN, array('email' => $U['maya']['email'], 'role' => 'viewer'));
	check('a removed member can be invited again', $r['code'] === 200 && $r['body']['status'] === 'invited', $r['raw']);
	check('re-invite after removal clears removed_by and role_changed_at', $db->get_var_prepared(
		"SELECT (removed_by IS NULL AND role_changed_at IS NULL AND state = 'invited')::text FROM strabomicro.micro_members
		  WHERE project_id = $1 AND user_pkey = $2", array($PID, $U['maya']['pkey'])) === 'true');

	section('Ownership transfer');
	check('offer to a non-member -> 400', req('POST', "/projects/$PID/transfer", $OWN, array('pkey' => $U['outsider']['pkey']))['code'] === 400);
	check('offer to an invited person -> 400', req('POST', "/projects/$PID/transfer", $OWN, array('pkey' => $U['maya']['pkey']))['code'] === 400);
	check('editor cannot offer (403)', req('POST', "/projects/$PID/transfer", $EDT, array('pkey' => $U['editor']['pkey']))['code'] === 403);
	$before = mailCount('offered you ownership of "Members test"');
	$r = req('POST', "/projects/$PID/transfer", $OWN, array('pkey' => $U['editor']['pkey']));
	check('owner offers ownership to the editor', $r['code'] === 200 && $r['body']['transferTo']['pkey'] === $U['editor']['pkey'], $r['raw']);
	check('transfer email filed', mailCount('offered you ownership of "Members test"') === $before + 1);
	$tr = array_values(array_filter(req('GET', '/invites', $EDT)['body']['transfers'], function ($t) use ($PID) { return $t['pid'] === $PID; }));
	check('GET invites lists the transfer', count($tr) === 1 && $tr[0]['from']['pkey'] === $U['owner']['pkey']);
	check('member list shows the pending transfer', req('GET', "/projects/$PID/members", $EDT)['body']['transferTo']['pkey'] === $U['editor']['pkey']);
	check('accept by someone else -> 404', req('POST', "/projects/$PID/transfer/accept", $OWN)['code'] === 404);
	check('editor declines', req('POST', "/projects/$PID/transfer/decline", $EDT)['code'] === 200
		&& req('GET', "/projects/$PID/members", $OWN)['body']['transferTo'] === null);
	req('POST', "/projects/$PID/transfer", $OWN, array('pkey' => $U['editor']['pkey']));
	check('owner withdraws the offer', req('DELETE', "/projects/$PID/transfer", $OWN)['code'] === 200
		&& req('GET', "/projects/$PID/members", $OWN)['body']['transferTo'] === null);
	check('accept after withdrawal -> 404', req('POST', "/projects/$PID/transfer/accept", $EDT)['code'] === 404);
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['viewer']['email'], 'role' => 'editor'));
	req('POST', "/invites/$PID/accept", $VIE);
	req('POST', "/projects/$PID/transfer", $OWN, array('pkey' => $U['viewer']['pkey']));
	req('DELETE', "/projects/$PID/members/" . $U['viewer']['pkey'], $OWN);
	check('removing the person offered ownership ends the offer', req('GET', "/projects/$PID/members", $OWN)['body']['transferTo'] === null);
	req('POST', "/projects/$PID/transfer", $OWN, array('pkey' => $U['editor']['pkey']));
	$r = req('POST', "/projects/$PID/transfer/accept", $EDT);
	check('accepting is held: 409 transfer_unavailable', $r['code'] === 409 && $r['body']['error'] === 'transfer_unavailable', $r['raw']);
	$list = req('GET', "/projects/$PID/members", $OWN)['body'];
	check('nothing changed: same owner, offer still pending', $list['myRole'] === 'owner'
		&& memberOf($list, $U['editor']['pkey'])['role'] === 'editor' && $list['transferTo']['pkey'] === $U['editor']['pkey']);
	check('legacy owner column unchanged', (int)$db->get_var_prepared("SELECT userpkey FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID)) === $U['owner']['pkey']);

} finally {
	cleanup();
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
