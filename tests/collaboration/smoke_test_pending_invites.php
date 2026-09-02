<?php
/**
 * File: tests/collaboration/smoke_test_pending_invites.php
 * Description: Pending collaboration invitations render ONCE per invitation on
 *              My Field Data, and the invite path never creates a duplicate
 *              collaborators row for the same (project, owner, invitee).
 *
 *   Background (Jason on prod 2026-09-02): the PG project mirror holds one row
 *   per user who has a copy of a project (owner + accepted collaborators) and
 *   Field project ids are not unique across users, so the old query, which
 *   joined project on strabo_project_id alone, listed one invitation 4 times.
 *
 *   Runs inside the strabo-php container against the real Apache with forged
 *   PHP sessions (session files chown'd www-data) over curl:
 *      docker exec strabo-php php /srv/app/www/tests/collaboration/smoke_test_pending_invites.php
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';

$OWNER = 94581; $INVITEE = 94582; $COPY1 = 94583; $COPY2 = 94584;
$P1 = 945811001;                 // invited project (owner + 2 collaborator copies + a duplicate owner row in the mirror)
$P2 = 945811002;                 // second invitation, same owner, to prove ordering / independence
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr($detail, 0, 400) . "]" : '') . "\n"; }
}
$sids = array();
function forge($pkey, $email) {
	global $SESS, $sids;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	file_put_contents("$SESS/sess_$sid", 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';');
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
function http($method, $path, $sid, $post = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => array('Cookie: PHPSESSID=' . $sid)));
	if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	$body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
	return array($code, (string)$body);
}
function cleanup() {
	global $db, $neodb, $OWNER, $INVITEE, $COPY1, $COPY2, $sids, $SESS;
	foreach (array($OWNER, $INVITEE, $COPY1, $COPY2) as $u) {
		$neodb->query("MATCH (u:User {userpkey: $u})-[:HAS_PROJECT]->(p:Project) DETACH DELETE p");
		$neodb->query("MATCH (u:User {userpkey: $u}) DETACH DELETE u");
		$db->prepare_query("DELETE FROM collaborators WHERE project_owner_user_pkey = $1 OR collaborator_user_pkey = $1", array($u));
		$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($u));
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u));
	}
	foreach ($sids as $s) @unlink("$SESS/sess_$s");
	$sids = array();
}
function rows($pid) {
	global $db, $OWNER, $INVITEE;
	return $db->get_results_prepared("SELECT pkey, collaboration_level, accepted, disabled FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3 ORDER BY pkey", array((string)$pid, $OWNER, $INVITEE)) ?: array();
}

echo "Pending collaboration invitations smoke suite\n";
cleanup();

// ------------------------------------------------------------------ fixtures
$emails = array();
foreach (array($OWNER => 'pio', $INVITEE => 'pii', $COPY1 => 'pic1', $COPY2 => 'pic2') as $u => $fn) {
	$emails[$u] = "$fn-$u@test.strabospot.org";
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, $2, 'Fixture', $3, 'x', 'x', false, false)", array($u, ucfirst($fn), $emails[$u]));
}
$neodb->query("CREATE (u:User {userpkey: $OWNER, email: '{$emails[$OWNER]}'})");
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P1, userpkey: $OWNER, desc_project_name: 'Fanout Project', uploaddate: 1722400000}) CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P2, userpkey: $OWNER, desc_project_name: 'Second Project', uploaddate: 1722400000}) CREATE (u)-[:HAS_PROJECT]->(p)");
// Mirror rows: owner (twice, a real prod shape), two collaborator copies with a stale name.
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic, last_modified) VALUES ($1, 'Fanout Project (old name)', $2, FALSE, now() - interval '10 days')", array($OWNER, (string)$P1));
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic, last_modified) VALUES ($1, 'Fanout Project', $2, FALSE, now())", array($OWNER, (string)$P1));
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Copy name 1', $2, FALSE)", array($COPY1, (string)$P1));
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Copy name 2', $2, FALSE)", array($COPY2, (string)$P1));
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Second Project', $2, FALSE)", array($OWNER, (string)$P2));
// Accepted collaborators (the copies) + ONE pending invitation for the invitee on each project.
foreach (array($COPY1, $COPY2) as $u) {
	$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid) VALUES ($1, $2, $3, 'edit', TRUE, FALSE, $4)", array((string)$P1, $OWNER, $u, "pi-$u-acc"));
}
$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid) VALUES ($1, $2, $3, 'edit', FALSE, FALSE, $4)", array((string)$P1, $OWNER, $INVITEE, 'pi-inv-1'));
$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid) VALUES ($1, $2, $3, 'readonly', FALSE, FALSE, $4)", array((string)$P2, $OWNER, $INVITEE, 'pi-inv-2'));

// ------------------------------------------------------------------ rendering
$inv = forge($INVITEE, $emails[$INVITEE]);
list($code, $body) = http('GET', '/my_field_data.php', $inv);
check('my_field_data renders for the invitee', $code === 200 && strpos($body, 'invited to collaborate') !== false, "$code");
check('exactly one Accept link per invitation (2 invitations, 4 mirror rows for P1)', substr_count($body, 'accept_collaboration?p=') === 2, (string)substr_count($body, 'accept_collaboration?p='));
check('P1 invitation appears once', substr_count($body, "accept_collaboration?p=$P1&") === 1);
check('P2 invitation appears once', substr_count($body, "accept_collaboration?p=$P2&") === 1);
check('project name comes from the owner\'s newest mirror row', strpos($body, '<td>Fanout Project</td>') !== false && strpos($body, 'Copy name') === false && strpos($body, '(old name)') === false);
check('owner shown by name + email', strpos($body, "Pio Fixture ({$emails[$OWNER]})") !== false);
check('P1 listed before P2 (creation order)', strpos($body, "accept_collaboration?p=$P1&") < strpos($body, "accept_collaboration?p=$P2&"));
check('no PHP warnings in the page', stripos($body, 'Warning:') === false && stripos($body, 'Fatal error') === false);

// Denied invitation disappears; accepted collaborator copies never show as invitations.
$db->prepare_query("UPDATE collaborators SET disabled = TRUE WHERE uuid = 'pi-inv-2'");
list($code, $body) = http('GET', '/my_field_data.php', $inv);
check('denied invitation no longer listed', substr_count($body, 'accept_collaboration?p=') === 1 && strpos($body, "accept_collaboration?p=$P2&") === false);
$c1 = forge($COPY1, $emails[$COPY1]);
list($code, $body) = http('GET', '/my_field_data.php', $c1);
check('an accepted collaborator sees no invitation for that project', strpos($body, "accept_collaboration?p=$P1&") === false);

// ------------------------------------------------------------------ invite path: no duplicate rows
$own = forge($OWNER, $emails[$OWNER]);
$db->prepare_query("DELETE FROM collaborators WHERE uuid IN ('pi-inv-1', 'pi-inv-2')");
$post = array('addresses' => $emails[$INVITEE] . "\n" . $emails[$INVITEE] . "\n " . $emails[$INVITEE] . " \n", 'collaborationlevel' => 'readonly');
list($code, $body) = http('POST', "/invite_collaborators.php?p=$P1", $own, $post);
$r = rows($P1);
check('invite POST redirects back to the collaborate page', $code === 302 || $code === 200, "$code");
check('same address listed 3 times in one POST -> one row', count($r) === 1 && $r[0]->collaboration_level === 'readonly', json_encode($r));
list($code, $body) = http('POST', "/invite_collaborators.php?p=$P1", $own, array('addresses' => $emails[$INVITEE], 'collaborationlevel' => 'edit'));
$r = rows($P1);
check('re-POST (double submit) updates the row instead of inserting', count($r) === 1 && $r[0]->collaboration_level === 'edit', json_encode($r));
$db->prepare_query("UPDATE collaborators SET disabled = TRUE WHERE strabo_project_id = $1 AND collaborator_user_pkey = $2", array((string)$P1, $INVITEE));
list($code, $body) = http('POST', "/invite_collaborators.php?p=$P1", $own, array('addresses' => $emails[$INVITEE], 'collaborationlevel' => 'readonly'));
$r = rows($P1);
check('re-invite after deny re-enables the same row', count($r) === 1 && $r[0]->disabled === 'f' && $r[0]->collaboration_level === 'readonly', json_encode($r));
list($code, $body) = http('POST', "/invite_collaborators.php?p=$P1", $own, array('addresses' => $emails[$OWNER], 'collaborationlevel' => 'edit'));
check('owner cannot invite themself', (int)$db->get_var_prepared("SELECT count(*) FROM collaborators WHERE strabo_project_id = $1 AND collaborator_user_pkey = $2", array((string)$P1, $OWNER)) === 0);
list($code, $body) = http('GET', '/my_field_data.php', $inv);
check('invitee sees the re-invite exactly once', substr_count($body, "accept_collaboration?p=$P1&") === 1);

// ------------------------------------------------------------------ DB guard (sql/collaborators_unique_invite.sql), if applied
$idx = $db->get_var_prepared("SELECT count(*) FROM pg_indexes WHERE tablename = 'collaborators' AND indexname = 'collaborators_unique_invite_idx'", array());
if ((int)$idx === 1) {
	$db->show_errors = false;
	$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid) VALUES ($1, $2, $3, 'edit', FALSE, FALSE, 'pi-dupe')", array((string)$P1, $OWNER, $INVITEE));
	check('unique index refuses a duplicate (project, owner, invitee) row', count(rows($P1)) === 1);
} else {
	echo "  SKIP  unique index collaborators_unique_invite_idx not applied on this database\n";
}

// ------------------------------------------------------------------ cleanup
cleanup();
check('zero residue', (int)$db->get_var_prepared("SELECT count(*) FROM collaborators WHERE project_owner_user_pkey = $1 OR collaborator_user_pkey = $1", array($OWNER)) === 0
	&& (int)$neodb->query("MATCH (u:User) WHERE u.userpkey IN [$OWNER] RETURN count(u) AS c")[0]->value('c') === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
