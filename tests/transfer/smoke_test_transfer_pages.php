<?php
/**
 * File: tests/transfer/smoke_test_transfer_pages.php
 * Description: Page-level smoke suite for the StraboField project transfer
 *              handshake (docs/ProjectTransfer_Design.md §4 + §8):
 *              transfer_project.php (owner form), transfer_respond.php
 *              (recipient accept / decline), cancel_transfer.php, the My
 *              Field Data blocks + gated dropdown entry, and the six mails
 *              on the StraboMail template (fixture addresses under
 *              @test.strabospot.org are filed to mail.log, never sent).
 *
 *              Runs inside the strabo-php container against the real Apache
 *              with forged PHP sessions (session files chown'd www-data) over
 *              curl, in the style of tests/collaboration/
 *              smoke_test_pending_invites.php. Fixture ids under the 9460
 *              prefix (disjoint from the service suite's 9459).
 *
 *              Coverage:
 *                1. gate: the dropdown entry shows for userpkey 3 only
 *                2. owner form: renders facts; non-owner refused; anonymous
 *                   redirected to login (round trip stored)
 *                3. POST: missing confirm; unknown address -> neutral text, no
 *                   mail, refused row; real recipient -> neutral text, request
 *                   mail with the review link; pending view with Cancel
 *                4. My Field Data: outgoing / incoming blocks, third party
 *                   sees neither
 *                5. respond page: third party / unknown token -> not found;
 *                   recipient sees facts; decline -> mail to owner
 *                6. cancel -> mail to recipient + one-shot notice
 *                7. accept -> outcome page, both mails, row accepted, verify
 *                   clean, dropdown entry now on the recipient's page
 *                8. expiry -> owner mail on next My Field Data load
 *                9. no PHP warnings anywhere
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/transfer/smoke_test_transfer_pages.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/StraboMail.php';
require_once 'includes/transfer/ProjectTransfer.php';
require_once 'includes/transfer/ProjectTransferMail.php';

$OWNER = 94601; $RECIP = 94602; $THIRD = 94603;
$ALL = array($OWNER, $RECIP, $THIRD);
$P = 946011001; $DS1 = 946012001; $S1 = 946013001; $S2 = 946013002; $IMG1 = 946014001;
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 500) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
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
	$hdr = array();
	if ($sid !== null) $hdr[] = 'Cookie: PHPSESSID=' . $sid;
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $hdr));
	if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
	$headers = substr((string)$raw, 0, $hs); $body = substr((string)$raw, $hs);
	$loc = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
	return array($code, (string)$body, $loc);
}
function clean($body) { return stripos($body, 'Warning:') === false && stripos($body, 'Fatal error') === false && stripos($body, 'Notice:') === false; }
$mailLog = StraboMail::logFile();
$mailLen = function () use ($mailLog) { clearstatcache(true, $mailLog); return is_file($mailLog) ? filesize($mailLog) : 0; };
$mailSince = function ($from) use ($mailLog) { clearstatcache(true, $mailLog); return is_file($mailLog) ? (string)substr(file_get_contents($mailLog), $from) : ''; };

function cleanup() {
	global $db, $neodb, $ALL, $sids, $SESS;
	foreach ($ALL as $u) {
		$neodb->query("MATCH (u:User {userpkey: $u})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot) OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image) DETACH DELETE i, s, d, p");
		$neodb->query("MATCH (p:Project {userpkey: $u}) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot) DETACH DELETE s, d, p");
		$neodb->query("MATCH (u:User {userpkey: $u}) DETACH DELETE u");
	}
	$in = implode(',', $ALL);
	$db->query("DELETE FROM project_transfers WHERE from_user_pkey IN ($in) OR to_user_pkey IN ($in)");
	$db->query("DELETE FROM collaborators WHERE project_owner_user_pkey IN ($in) OR collaborator_user_pkey IN ($in)");
	$db->query("DELETE FROM project WHERE user_pkey IN ($in)");
	$db->query("DELETE FROM strabosearch.item_hit WHERE item_userpkey IN ($in) OR project_userpkey IN ($in)");
	$db->query("DELETE FROM strabosearch.image_hit WHERE image_userpkey IN ($in) OR project_userpkey IN ($in)");
	$db->query("DELETE FROM apptokens WHERE email LIKE '%-946__@test.strabospot.org'");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
	foreach ($sids as $s) @unlink("$SESS/sess_$s");
	$sids = array();
}

echo "Project transfer page smoke suite\n";
cleanup();

// ---------------------------------------------------------------- fixtures
$emails = array();
foreach (array($OWNER => 'Olga', $RECIP => 'Rory', $THIRD => 'Tess') as $u => $fn) {
	$emails[$u] = strtolower($fn) . "-$u@test.strabospot.org";
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, $2, 'Pages', $3, 'x', 'x', TRUE, FALSE)", array($u, $fn, $emails[$u]));
	$neodb->query("CREATE (u:User {userpkey: $u, email: '{$emails[$u]}'})");
}
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P, userpkey: $OWNER, desc_project_name: 'Pages Fixture Project', uploaddate: 1725000000, modified_timestamp: 1725000000000}) CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("MATCH (p:Project {id: $P, userpkey: $OWNER}) CREATE (d:Dataset {id: $DS1, userpkey: $OWNER, name: 'Pages DS', created_by: $OWNER}) CREATE (p)-[:HAS_DATASET]->(d)");
$neodb->query("MATCH (d:Dataset {id: $DS1, userpkey: $OWNER}) CREATE (s:Spot {id: $S1, userpkey: $OWNER, name: 'Pages Spot 1', wkt: 'POINT (-118.25 34.05)', modified_timestamp: 1725000001000}) CREATE (d)-[:HAS_SPOT]->(s) CREATE (s2:Spot {id: $S2, userpkey: $OWNER, name: 'Pages Spot 2', wkt: 'POINT (-118.26 34.06)', modified_timestamp: 1725000002000}) CREATE (d)-[:HAS_SPOT]->(s2)");
$neodb->query("MATCH (s:Spot {id: $S1, userpkey: $OWNER}) CREATE (i:Image {id: $IMG1, userpkey: $OWNER, created_by: $OWNER, image_type: 'photo', title: 'Pages photo', filename: '$IMG1'}) CREATE (s)-[:HAS_IMAGE]->(i)");
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Pages Fixture Project', $2, FALSE)", array($OWNER, (string)$P));
$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid, created_date, accepted_date) VALUES ($1, $2, $3, 'readonly', TRUE, FALSE, 'tp-pages-recip', now(), now())", array((string)$P, $OWNER, $RECIP));

$own = forge($OWNER, $emails[$OWNER]);
$rec = forge($RECIP, $emails[$RECIP]);
$thd = forge($THIRD, $emails[$THIRD]);
$svc = new ProjectTransfer($db, $neodb);

// ---------------------------------------------------------------- 1. gate
section('1. dropdown gate');
list($code, $body) = http('GET', '/my_field_data.php', $own);
check('owner page renders, clean', $code === 200 && clean($body) && strpos($body, 'Pages Fixture Project') !== false, "$code");
check('transfer entry hidden for a normal owner (soft launch, userpkey 3 only)', strpos($body, 'Transfer to Other Account') === false);
check('no transfer blocks when nothing is pending', strpos($body, 'transfers-incoming') === false && strpos($body, 'transfers-outgoing') === false);
$adminEmail = $db->get_var_prepared("SELECT email FROM users WHERE pkey = 3", array());
if ($adminEmail) {
	$adm = forge(3, $adminEmail);
	list($code, $body) = http('GET', '/my_field_data.php', $adm);
	check('userpkey 3 sees the "Transfer to Other Account" entry + the JS case', $code === 200 && strpos($body, '<option value="transfer">Transfer to Other Account</option>') !== false && strpos($body, "case \"transfer\":") !== false, "$code");
}

// ---------------------------------------------------------------- 2. owner form
section('2. owner form');
list($code, $body, $loc) = http('GET', "/transfer_project?p=$P", null);
check('anonymous visitor is redirected to login', $code === 302 && strpos($loc, '/login.php') !== false, "$code $loc");
list($code, $body) = http('GET', "/transfer_project?p=$P", $thd);
check('non-owner gets "Project not found"', strpos($body, 'Project not found') !== false && strpos($body, 'tp-form') === false);
list($code, $body) = http('GET', "/transfer_project?p=$P", $own);
check('owner form renders with facts (name, 1 dataset, 2 spots, 1 photo, 1 collaborator)', $code === 200 && clean($body) && strpos($body, 'Pages Fixture Project') !== false
	&& preg_match('/<strong>Datasets<\/strong> 1</', $body) && preg_match('/<strong>Spots<\/strong> 2</', $body) && preg_match('/<strong>Photos<\/strong> 1</', $body) && preg_match('/<strong>Collaborators<\/strong> 1/', $body), substr($body, 0, 200));
check('form has email input, keep checkbox (checked) with adjacent label, confirm checkbox, submit', strpos($body, 'type="email" name="email"') !== false
	&& preg_match('/<input type="checkbox" name="keep" id="keep" value="1" checked>\s*<label for="keep">/', $body) && preg_match('/<input type="checkbox" name="confirm" id="confirm" value="1">\s*<label for="confirm">/', $body)
	&& strpos($body, 'name="submit_transfer"') !== false);
check('page shell: wrapper style1 + header.major + irreversibility notice', strpos($body, 'class="wrapper style1"') !== false && strpos($body, 'class="major"') !== false && strpos($body, 'cannot be undone') !== false);

// ---------------------------------------------------------------- 3. POST
section('3. request POST');
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_project', $own, array('p' => $P, 'email' => $emails[$RECIP], 'keep' => '1', 'submit_transfer' => '1'));
check('missing confirm: error shown, no row, no mail', strpos($body, 'tp-error') !== false && strpos($body, 'confirm that you understand') !== false && $svc->pendingRow($P, $OWNER) === null && $mailSince($mark) === '');
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_project', $own, array('p' => $P, 'email' => 'nobody-94600@test.strabospot.org', 'keep' => '1', 'confirm' => '1', 'submit_transfer' => '1'));
check('unknown address: neutral message, no mail', strpos($body, 'tp-result') !== false && strpos($body, 'If a StraboSpot account exists at that address') !== false && $mailSince($mark) === '' && clean($body), substr($body, 0, 200));
check('unknown address: refused audit row with the reason', (int)$db->get_var_prepared("SELECT count(*) FROM project_transfers WHERE strabo_project_id = $1 AND from_user_pkey = $2 AND status = 'refused' AND summary->>'reason' LIKE 'No active StraboSpot account%'", array((string)$P, $OWNER)) === 1);
check('neutral page never names the recipient nor an error', strpos($body, 'nobody-94600') === false && strpos($body, 'tp-error') === false);
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_project', $own, array('p' => $P, 'email' => ' ' . strtoupper($emails[$RECIP]) . ' ', 'keep' => '1', 'confirm' => '1', 'submit_transfer' => '1'));
$pending = $svc->pendingRow($P, $OWNER);
check('real recipient: SAME neutral message', strpos($body, 'tp-result') !== false && strpos($body, 'If a StraboSpot account exists at that address') !== false && clean($body));
check('pending row created (keep = true, expires in 7 days)', $pending && $pending->keep_as_collaborator === 't' && (int)$pending->to_user_pkey === $RECIP && abs(strtotime($pending->expires_date) - time() - 7 * 86400) < 300, json_encode($pending));
$mail = $mailSince($mark);
check('request mail filed to the recipient only', substr_count($mail, "To: {$emails[$RECIP]}") === 1 && strpos($mail, "To: {$emails[$OWNER]}") === false, substr($mail, 0, 300));
check('request mail: subject, greeting, facts, review link with the token, expiry', strpos($mail, 'Subject: Olga Pages (' . $emails[$OWNER] . ') wants to transfer the StraboField project "Pages Fixture Project" to you') !== false
	&& strpos($mail, 'Hi Rory,') !== false && strpos($mail, 'Project: Pages Fixture Project') !== false && strpos($mail, 'Spots: 2') !== false && strpos($mail, 'Photos: 1') !== false
	&& strpos($mail, 'After the transfer: Olga stays on the project as an admin collaborator') !== false
	&& strpos($mail, 'https://strabospot.org/transfer_respond?t=' . $pending->uuid) !== false && strpos($mail, 'Expires: ' . date('F j, Y', strtotime($pending->expires_date))) !== false, substr($mail, 0, 1200));
list($code, $body) = http('GET', "/transfer_project?p=$P", $own);
check('owner form now shows the pending request with a Cancel link instead of the form', strpos($body, 'id="tp-pending"') !== false && strpos($body, 'id="tp-form"') === false && strpos($body, "cancel_transfer?t={$pending->uuid}&amp;back=project") !== false && strpos($body, 'Offered to</strong> ' . $emails[$RECIP]) !== false, preg_match_all('/id="tp-[a-z]+"|Offered to<\/strong>[^<]*|cancel_transfer[^"]*/', $body, $mm) ? json_encode($mm[0]) : 'no tp- markers');
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_project', $own, array('p' => $P, 'email' => $emails[$THIRD], 'keep' => '1', 'confirm' => '1', 'submit_transfer' => '1'));
check('POST while pending is ignored (no second row, no mail)', (int)$db->get_var_prepared("SELECT count(*) FROM project_transfers WHERE strabo_project_id = $1 AND from_user_pkey = $2 AND status = 'pending'", array((string)$P, $OWNER)) === 1 && $mailSince($mark) === '');

// ---------------------------------------------------------------- 4. My Field Data blocks
section('4. My Field Data blocks');
list($code, $body) = http('GET', '/my_field_data.php', $own);
check('owner sees the outgoing block with recipient + Cancel', strpos($body, 'transfers-outgoing') !== false && strpos($body, $emails[$RECIP]) !== false && strpos($body, "cancel_transfer?t={$pending->uuid}") !== false && strpos($body, 'transfers-incoming') === false && clean($body));
list($code, $body) = http('GET', '/my_field_data.php', $rec);
check('recipient sees the incoming block with owner + Review', strpos($body, 'transfers-incoming') !== false && strpos($body, 'Olga Pages (' . $emails[$OWNER] . ')') !== false && strpos($body, "transfer_respond?t={$pending->uuid}") !== false && strpos($body, 'transfers-outgoing') === false && clean($body));
list($code, $body) = http('GET', '/my_field_data.php', $thd);
check('third party sees neither block', strpos($body, 'transfers-incoming') === false && strpos($body, 'transfers-outgoing') === false);

// ---------------------------------------------------------------- 5. respond page + decline
section('5. respond page');
list($code, $body, $loc) = http('GET', "/transfer_respond?t={$pending->uuid}", null);
check('anonymous visitor redirected to login (round trip via session uri)', $code === 302 && strpos($loc, '/login.php') !== false, "$code $loc");
list($code, $body) = http('GET', "/transfer_respond?t={$pending->uuid}", $thd);
check('third party: "not found", no details, no buttons', strpos($body, 'tp-notfound') !== false && strpos($body, 'tp-details') === false && strpos($body, 'tp-accept') === false);
list($code, $body) = http('GET', "/transfer_respond?t=deadbeef-0000-4000-8000-000000000000", $rec);
check('unknown token: same "not found"', strpos($body, 'tp-notfound') !== false);
list($code, $body) = http('GET', "/transfer_respond?t={$pending->uuid}", $rec);
check('recipient sees facts: owner, project, counts, keep note, expiry, Accept + Decline', $code === 200 && clean($body) && strpos($body, 'tp-details') !== false
	&& strpos($body, '<strong>Olga Pages</strong> (' . $emails[$OWNER] . ')') !== false && preg_match('/<strong>Spots<\/strong> 2</', $body) && preg_match('/<strong>Photos<\/strong> 1</', $body)
	&& strpos($body, 'Olga stays on the project as an admin collaborator') !== false && strpos($body, 'id="tp-accept"') !== false && strpos($body, 'id="tp-decline"') !== false, substr($body, 0, 200));
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_respond', $thd, array('t' => $pending->uuid, 'action' => 'decline'));
check('third party cannot decline', strpos($body, 'tp-notfound') !== false && $svc->getByUuid($pending->uuid)->status === 'pending' && $mailSince($mark) === '');
list($code, $body) = http('POST', '/transfer_respond', $rec, array('t' => $pending->uuid, 'action' => 'decline'));
$mail = $mailSince($mark);
check('recipient declines: outcome page, row declined', strpos($body, 'tp-outcome') !== false && strpos($body, 'Transfer declined') !== false && $svc->getByUuid($pending->uuid)->status === 'declined' && clean($body));
check('declined mail to the owner names the recipient', substr_count($mail, "To: {$emails[$OWNER]}") === 1 && strpos($mail, 'Subject: Transfer declined: "Pages Fixture Project"') !== false && strpos($mail, 'Declined by: Rory Pages (' . $emails[$RECIP] . ')') !== false, substr($mail, 0, 600));
list($code, $body) = http('GET', "/transfer_respond?t={$pending->uuid}", $rec);
check('revisiting a decided request shows its status, no buttons', strpos($body, 'tp-status') !== false && strpos($body, 'You declined this transfer request') !== false && strpos($body, 'tp-accept') === false);
check('project still with the owner', $svc->projectNodeIds($P, $OWNER) && !$svc->projectNodeIds($P, $RECIP));

// ---------------------------------------------------------------- 6. cancel
section('6. cancel');
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_project', $own, array('p' => $P, 'email' => $emails[$RECIP], 'keep' => '', 'confirm' => '1', 'submit_transfer' => '1'));
$pending2 = $svc->pendingRow($P, $OWNER);
check('second request (keep = false) pending + mail says the owner will no longer have access', $pending2 && $pending2->keep_as_collaborator === 'f' && strpos($mailSince($mark), 'After the transfer: Olga will no longer have access to the project') !== false);
$mark = $mailLen();
list($code, $body, $loc) = http('GET', "/cancel_transfer?t={$pending2->uuid}", $thd);
check('third party cannot cancel (redirect with a notice, row still pending)', $code === 302 && $svc->getByUuid($pending2->uuid)->status === 'pending' && $mailSince($mark) === '', "$code");
list($code, $body, $loc) = http('GET', "/cancel_transfer?t={$pending2->uuid}&back=project", $own);
check('owner cancels: redirected back to the project page, row cancelled', $code === 302 && strpos($loc, "/transfer_project?p=$P") !== false && $svc->getByUuid($pending2->uuid)->status === 'cancelled', "$code $loc");
$mail = $mailSince($mark);
check('cancelled mail to the recipient', substr_count($mail, "To: {$emails[$RECIP]}") === 1 && strpos($mail, 'Subject: Transfer request withdrawn: "Pages Fixture Project"') !== false, substr($mail, 0, 400));
list($code, $body) = http('GET', '/my_field_data.php', $own);
check('one-shot notice shown on My Field Data once', strpos($body, 'transfer-notice') !== false && strpos($body, 'has been withdrawn') !== false);
list($code, $body) = http('GET', '/my_field_data.php', $own);
check('...and gone on the next load', strpos($body, 'transfer-notice') === false);
list($code, $body) = http('GET', "/transfer_project?p=$P", $own);
check('owner form is back after the cancel', strpos($body, 'tp-form') !== false && strpos($body, 'tp-pending') === false);

// ---------------------------------------------------------------- 7. accept
section('7. accept');
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_project', $own, array('p' => $P, 'email' => $emails[$RECIP], 'keep' => '1', 'confirm' => '1', 'submit_transfer' => '1'));
$pending3 = $svc->pendingRow($P, $OWNER);
check('third request pending', $pending3 && $pending3->status === 'pending');
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_respond', $own, array('t' => $pending3->uuid, 'action' => 'accept'));
check('the OWNER cannot accept their own request', strpos($body, 'tp-notfound') !== false && $svc->getByUuid($pending3->uuid)->status === 'pending');
$t0 = microtime(true);
list($code, $body) = http('POST', '/transfer_respond', $rec, array('t' => $pending3->uuid, 'action' => 'accept'));
$dt = round(microtime(true) - $t0, 1);
$done = $svc->getByUuid($pending3->uuid);
check("recipient accepts: outcome page ({$dt}s), row accepted", strpos($body, 'tp-outcome') !== false && strpos($body, 'The project is now yours') !== false && $done->status === 'accepted' && clean($body), substr($body, 0, 300));
$v = $svc->verify($done);
check('verify(): clean after the page-driven transfer', $v['clean'] === true, json_encode($v));
$mail = $mailSince($mark);
check('accepted mails: one to each party', substr_count($mail, "To: {$emails[$RECIP]}") === 1 && substr_count($mail, "To: {$emails[$OWNER]}") === 1, substr($mail, 0, 300));
check('recipient mail: subject + device instruction + collaborator note', strpos($mail, 'Subject: Transfer complete: you now own "Pages Fixture Project"') !== false && strpos($mail, 'download the project from the server') !== false && strpos($mail, 'Olga stays on as an admin collaborator') !== false, substr($mail, 0, 1500));
check('owner mail: subject names the recipient + admin-collaborator device note + DOI note', strpos($mail, 'Subject: Your project "Pages Fixture Project" has been transferred to Rory Pages (' . $emails[$RECIP] . ')') !== false && strpos($mail, 'You stay on the project as an admin collaborator') !== false && strpos($mail, 'DOI') !== false, substr($mail, 0, 3000));
check('mails carry the moved counts', strpos($mail, 'Spots: 2') !== false && strpos($mail, 'Datasets: 1') !== false);
list($code, $body) = http('GET', '/my_field_data.php', $rec);
check('recipient now lists the project as owned, no pending blocks', strpos($body, 'Pages Fixture Project') !== false && strpos($body, 'transfers-incoming') === false && clean($body));
list($code, $body) = http('GET', '/my_field_data.php', $own);
check('old owner sees it as a collaboration (admin), not owned', strpos($body, 'Collaboration Level: Admin') !== false && strpos($body, 'transfers-outgoing') === false, substr($body, 0, 100));
list($code, $body) = http('GET', "/transfer_project?p=$P", $own);
check('old owner can no longer open the transfer form for it', strpos($body, 'Project not found') !== false);
list($code, $body) = http('GET', "/transfer_respond?t={$pending3->uuid}", $rec);
check('revisiting the accepted request shows "already been completed"', strpos($body, 'already been completed') !== false);

// ---------------------------------------------------------------- 8. expiry mail
section('8. expiry');
$mark = $mailLen();
list($code, $body) = http('POST', '/transfer_project', $rec, array('p' => $P, 'email' => $emails[$THIRD], 'keep' => '1', 'confirm' => '1', 'submit_transfer' => '1'));
$pending4 = $svc->pendingRow($P, $RECIP);
check('new owner can offer it onward', $pending4 && $pending4->status === 'pending' && substr_count($mailSince($mark), "To: {$emails[$THIRD]}") === 1);
$db->prepare_query("UPDATE project_transfers SET expires_date = now() - interval '1 hour' WHERE uuid = $1", array($pending4->uuid));
$mark = $mailLen();
list($code, $body) = http('GET', '/my_field_data.php', $thd);
$mail = $mailSince($mark);
check('loading My Field Data expires it and mails the owner ONCE', $svc->getByUuid($pending4->uuid)->status === 'expired' && substr_count($mail, "To: {$emails[$RECIP]}") === 1 && strpos($mail, 'Subject: Transfer request expired: "Pages Fixture Project"') !== false && strpos($mail, 'Offered to: ' . $emails[$THIRD]) !== false, substr($mail, 0, 500));
$mark = $mailLen();
list($code, $body) = http('GET', '/my_field_data.php', $thd);
check('no second expiry mail', $mailSince($mark) === '' && strpos($body, 'transfers-incoming') === false);
list($code, $body) = http('GET', "/transfer_respond?t={$pending4->uuid}", $thd);
check('respond page on the expired request says so', strpos($body, 'has expired') !== false && strpos($body, 'tp-accept') === false);

// ---------------------------------------------------------------- done
cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
