<?php
/**
 * File: tests/transfer/smoke_test_transfer_admin.php
 * Description: Smoke suite for admin_transfers.php, the userpkey-3 page over
 *              StraboField project transfers (docs/ProjectTransfer_Design.md
 *              §9, D6): gate, list + filters, detail panel, Verify recount,
 *              Retry of a failed transfer (failure injected in-process with a
 *              BoomTransfer subclass, retried over HTTP), Reverse, Retry of a
 *              failed reversal (stamps the original), lazy expiry mail.
 *
 *              Runs inside the strabo-php container against the real Apache
 *              with forged PHP sessions over curl, like
 *              smoke_test_transfer_pages.php. Fixture ids under the 9462
 *              prefix (disjoint from 9459 service / 9460 pages / 9461 FF).
 *              Needs users.pkey = 3 to exist (the admin session is forged
 *              with that account's email); the suite stops if it does not.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/transfer/smoke_test_transfer_admin.php
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

$OWNER = 94621; $RECIP = 94622; $THIRD = 94623;
$ALL = array($OWNER, $RECIP, $THIRD);
$P = 946211001; $DS1 = 946212001; $S1 = 946213001; $S2 = 946213002; $IMG1 = 946214001;
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else {
		$fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 500) . "]" : '') . "\n";
		if (is_string($detail) && strlen($detail) > 500) file_put_contents("/tmp/tadmin_fail_$fail.html", $detail);
	}
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
/** GET the redirect target of a PRG response (Location is relative). */
function follow($loc, $sid) { return http('GET', $loc, $sid); }
function clean($body) { return stripos($body, 'Warning:') === false && stripos($body, 'Fatal error') === false && stripos($body, 'Notice:') === false; }
function hasId($body, $id) { return strpos($body, 'id="' . $id . '"') !== false; }
$mailLog = StraboMail::logFile();
$mailLen = function () use ($mailLog) { clearstatcache(true, $mailLog); return is_file($mailLog) ? filesize($mailLog) : 0; };
$mailSince = function ($from) use ($mailLog) { clearstatcache(true, $mailLog); return is_file($mailLog) ? (string)substr(file_get_contents($mailLog), $from) : ''; };

/** Failure injection: throws once at step 2 (before the Neo4j rewrite), so the row is failed + applied (tombstone on) with nothing moved yet. */
class BoomTransfer extends ProjectTransfer {
	public $boom = true;
	protected function stepNeo($pid, $from, $to, array $nids, array &$summary) {
		if ($this->boom) { $this->boom = false; throw new RuntimeException('injected failure before the Neo4j rewrite'); }
		parent::stepNeo($pid, $from, $to, $nids, $summary);
	}
}

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
	$db->query("DELETE FROM apptokens WHERE email LIKE '%-9462_@test.strabospot.org'");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
	foreach ($sids as $s) @unlink("$SESS/sess_$s");
	$sids = array();
}

echo "Project transfer admin page smoke suite\n";
$adminEmail = $db->get_var_prepared("SELECT email FROM users WHERE pkey = 3", array());
if (!$adminEmail) { echo "users.pkey = 3 does not exist here; cannot forge the admin session. Stopping.\n"; exit(2); }
cleanup();

// ---------------------------------------------------------------- fixtures
$emails = array();
foreach (array($OWNER => 'Oona', $RECIP => 'Ravi', $THIRD => 'Theo') as $u => $fn) {
	$emails[$u] = strtolower($fn) . "-$u@test.strabospot.org";
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, $2, 'Admin', $3, 'x', 'x', TRUE, FALSE)", array($u, $fn, $emails[$u]));
	$neodb->query("CREATE (u:User {userpkey: $u, email: '{$emails[$u]}'})");
}
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P, userpkey: $OWNER, desc_project_name: 'Admin Fixture Project', uploaddate: 1725000000, modified_timestamp: 1725000000000}) CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("MATCH (p:Project {id: $P, userpkey: $OWNER}) CREATE (d:Dataset {id: $DS1, userpkey: $OWNER, name: 'Admin DS', created_by: $OWNER}) CREATE (p)-[:HAS_DATASET]->(d)");
$neodb->query("MATCH (d:Dataset {id: $DS1, userpkey: $OWNER}) CREATE (s:Spot {id: $S1, userpkey: $OWNER, name: 'Admin Spot 1', wkt: 'POINT (-118.25 34.05)', modified_timestamp: 1725000001000}) CREATE (d)-[:HAS_SPOT]->(s) CREATE (s2:Spot {id: $S2, userpkey: $OWNER, name: 'Admin Spot 2', wkt: 'POINT (-118.26 34.06)', modified_timestamp: 1725000002000}) CREATE (d)-[:HAS_SPOT]->(s2)");
$neodb->query("MATCH (s:Spot {id: $S1, userpkey: $OWNER}) CREATE (i:Image {id: $IMG1, userpkey: $OWNER, created_by: $OWNER, image_type: 'photo', title: 'Admin photo', filename: '$IMG1'}) CREATE (s)-[:HAS_IMAGE]->(i)");
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Admin Fixture Project', $2, FALSE)", array($OWNER, (string)$P));
$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid, created_date, accepted_date) VALUES ($1, $2, $3, 'readonly', TRUE, FALSE, 'tp-admin-recip', now(), now())", array((string)$P, $OWNER, $RECIP));

$adm = forge(3, $adminEmail);
$thd = forge($THIRD, $emails[$THIRD]);
$svc = new ProjectTransfer($db, $neodb);
$rowCount = function () use ($db, $ALL) { $in = implode(',', $ALL); return (int)$db->get_var("SELECT count(*) FROM project_transfers WHERE from_user_pkey IN ($in) OR to_user_pkey IN ($in)"); };

// ---------------------------------------------------------------- 1. gate
section('1. gate');
list($code, $body, $loc) = http('GET', '/admin_transfers', null);
check('anonymous visitor is redirected to login', $code === 302 && strpos($loc, '/login.php') !== false, "$code $loc");
list($code, $body) = http('GET', '/admin_transfers', $thd);
check('signed-in non-admin gets "Not authorized." and no page', trim($body) === 'Not authorized.', substr($body, 0, 120));
list($code, $body) = http('GET', '/admin_transfers', $adm);
check('userpkey 3 renders the page, clean, with the shell + filter form + list', $code === 200 && clean($body) && strpos($body, 'class="wrapper style1"') !== false && hasId($body, 'at-filterform') && hasId($body, 'at-list'), "$code");
check('nav shows the Project Transfers entry for userpkey 3', strpos($body, '<a href="/admin_transfers">Project Transfers</a>') !== false);
list($code, $body) = http('GET', "/admin_transfers?email=" . urlencode('-9462'), $adm);
check('no fixture rows yet: "No transfers match these filters"', hasId($body, 'at-empty') && strpos($body, 'match these filters') !== false);

// ---------------------------------------------------------------- 2. list + filters + detail (pending)
section('2. list, filters, detail');
$r = $svc->request($P, $OWNER, $emails[$RECIP], true, $OWNER);
$pending = $r['row'];
check('fixture request pending', $r['ok'] && $pending && $pending->status === 'pending');
list($code, $body) = http('GET', '/admin_transfers', $adm);
check('unfiltered list shows the row: both emails, project name + id, status pending, keep yes, step 0/5', hasId($body, 'at-row-' . $pending->pkey) && strpos($body, $emails[$OWNER]) !== false && strpos($body, $emails[$RECIP]) !== false
	&& strpos($body, 'Admin Fixture Project') !== false && strpos($body, "($P)") !== false && strpos($body, 'at-status-pending') !== false && preg_match('/<td>yes<\/td>\s*<td>0\/5<\/td>/', $body) === 1 && clean($body));
list($code, $body) = http('GET', '/admin_transfers?status=declined&email=' . urlencode('-9462'), $adm);
check('status filter excludes it (declined)', !hasId($body, 'at-row-' . $pending->pkey) && hasId($body, 'at-empty'));
list($code, $body) = http('GET', '/admin_transfers?status=pending&email=' . urlencode('-9462'), $adm);
check('status filter includes it (pending), filter state kept in the form', hasId($body, 'at-row-' . $pending->pkey) && strpos($body, '<option value="pending" selected>') !== false && strpos($body, 'value="-9462"') !== false);
list($code, $body) = http('GET', '/admin_transfers?email=' . urlencode(strtoupper('ravi-')), $adm);
check('email filter matches the recipient side, case-insensitively', hasId($body, 'at-row-' . $pending->pkey));
list($code, $body) = http('GET', "/admin_transfers?project=$P", $adm);
check('project id filter matches', hasId($body, 'at-row-' . $pending->pkey) && strpos($body, '1 matching') !== false);
list($code, $body) = http('GET', "/admin_transfers?project=" . ($P + 1), $adm);
check('other project id: empty', !hasId($body, 'at-row-' . $pending->pkey) && hasId($body, 'at-empty'));
list($code, $body) = http('GET', "/admin_transfers?sel={$pending->pkey}", $adm);
check('detail panel for the pending row: parties, project, keep, token, counts at request, no actions but Close', hasId($body, 'at-detail') && strpos($body, 'Transfer #' . $pending->pkey) !== false
	&& strpos($body, 'Oona Admin &lt;' . $emails[$OWNER] . '&gt; (' . $OWNER . ')') !== false && strpos($body, 'Ravi Admin &lt;' . $emails[$RECIP] . '&gt; (' . $RECIP . ')') !== false
	&& strpos($body, $pending->uuid) !== false && preg_match('/<td>Counts at request<\/td><td>[^<]*datasets 1/', $body) === 1 && preg_match('/<td>Counts at request<\/td><td>[^<]*spots 2/', $body) === 1 && preg_match('/<td>Counts at request<\/td><td>[^<]*images 1/', $body) === 1
	&& !hasId($body, 'at-retry') && !hasId($body, 'at-reverse') && !hasId($body, 'at-verify') && clean($body), $body);
check('selected row highlighted in the list', preg_match('/<tr class="at-selrow" id="at-row-' . $pending->pkey . '"/', $body) === 1);

// ---------------------------------------------------------------- 3. refused row
section('3. refused row');
$svc->cancel($pending->uuid, $OWNER);
$r = $svc->request($P, $OWNER, 'nobody-94620@test.strabospot.org', true, $OWNER);
$refused = $r['row'];
check('request to an unknown address leaves a refused audit row', $refused && $refused->status === 'refused');
list($code, $body) = http('GET', "/admin_transfers?sel={$refused->pkey}", $adm);
check('detail shows the plain refusal reason (the initiator only ever saw the neutral text)', hasId($body, 'at-reason') && strpos($body, 'No active StraboSpot account') !== false && strpos($body, 'at-status-refused') !== false);

// ---------------------------------------------------------------- 4. failed transfer: verify, retry
section('4. failed transfer -> Verify + Retry');
$boom = new BoomTransfer($db, $neodb);
$r = $boom->request($P, $OWNER, $emails[$RECIP], false, $OWNER);
$r = $boom->accept($r['row']->uuid, $RECIP);
$failed = $r['row'];
check('injected failure at step 2: row failed, step 1, applied (tombstone on, nothing moved)', !$r['ok'] && $failed->status === 'failed' && (int)$failed->step === 1 && $failed->applied === 't' && $svc->projectNodeIds($P, $OWNER), json_encode($r));
list($code, $body) = http('GET', "/admin_transfers?status=failed", $adm);
check('list: failed row with the tombstone note', hasId($body, 'at-row-' . $failed->pkey) && preg_match('/id="at-row-' . $failed->pkey . '".*?at-tomb">tombstone<.*?<\/tr>/s', $body) === 1);
list($code, $body) = http('GET', "/admin_transfers?sel={$failed->pkey}", $adm);
check('detail: error text, "failed at step 2", Retry from step 2, Verify link, no Reverse', hasId($body, 'at-error') && strpos($body, 'injected failure before the Neo4j rewrite') !== false
	&& strpos($body, '1 of 5 completed (data rewrite started), failed at step 2') !== false && hasId($body, 'at-retry') && strpos($body, 'Retry from step 2') !== false && hasId($body, 'at-verify') && !hasId($body, 'at-reverse'), substr($body, 0, 200));
check('detail: tombstone flagged, keep no, moved counts from the summary', strpos($body, 'tombstone active for the old owner') !== false && strpos($body, '<td>Keep old owner as admin</td><td>no</td>') !== false && strpos($body, '1 project node') !== false && strpos($body, 'collaborator row') === false);
check('detail: Retry form wired to the progress card + status polling by pkey', hasId($body, 'at-progress') && strpos($body, "atWork(") !== false && strpos($body, "/transfer_status?pkey={$failed->pkey}") !== false && substr_count($body, '<li data-step="') === 5);
list($code, $body) = http('GET', "/transfer_status?pkey={$failed->pkey}", $adm);
$st = json_decode($body, true);
check('status endpoint by pkey (admin): failed at step 2, step 1 done, applied', is_array($st) && $st['found'] === true && $st['status'] === 'failed' && $st['step'] === 1 && $st['failed_step'] === 2 && $st['applied'] === true, $body);
list($code, $body) = http('GET', "/transfer_status?pkey={$failed->pkey}", $thd);
check('status endpoint by pkey: non-admin gets found:false', json_decode($body, true) === array('found' => false), $body);
list($code, $body) = http('GET', "/admin_transfers?sel={$failed->pkey}&verify=1", $adm);
check('Verify on the failed row: recount table, NOT CLEAN verdict, Neo4j + PG rows red (old owner still holds everything)', hasId($body, 'at-verify-result') && hasId($body, 'at-verify-verdict') && strpos($body, 'NOT CLEAN') !== false
	&& preg_match('/<tr class="at-bad"><td>neo4j_nodes<\/td><td>5<\/td><td>0<\/td>/', $body) === 1 && preg_match('/<tr class="at-bad"><td>pg_project<\/td><td>1<\/td><td>0<\/td>/', $body) === 1
	&& preg_match('/<tr class="at-bad"><td>HAS_PROJECT edge owner\(s\)<\/td><td colspan="3">' . $OWNER . '</', $body) === 1 && clean($body), substr($body, 0, 200));
check('...counts before the rewrite (step 1 snapshot) shown too', strpos($body, 'Counts before the rewrite') !== false);
$mark = $mailLen();
list($code, $body) = http('POST', '/admin_transfers', $thd, array('action' => 'retry', 'pkey' => $failed->pkey));
check('non-admin POST retry: refused, row untouched, no mail', trim($body) === 'Not authorized.' && $svc->getByPkey($failed->pkey)->status === 'failed' && $mailSince($mark) === '');
list($code, $body, $loc) = http('POST', '/admin_transfers', $adm, array('action' => 'retry', 'pkey' => 0));
check('retry with no row: redirected with an error', $code === 302 && strpos($loc, 'err=') !== false && strpos(urldecode($loc), 'No such transfer row') !== false, "$code $loc");
$mark = $mailLen();
$t0 = microtime(true);
list($code, $body, $loc) = http('POST', '/admin_transfers', $adm, array('action' => 'retry', 'pkey' => $failed->pkey));
$dt = round(microtime(true) - $t0, 1);
$done = $svc->getByPkey($failed->pkey);
check("admin Retry ({$dt}s): redirect to the row with the success message, row accepted at step 5", $code === 302 && strpos($loc, "sel={$failed->pkey}") !== false && strpos(urldecode($loc), "Transfer #{$failed->pkey} retried and completed") !== false && $done->status === 'accepted' && (int)$done->step === 5, "$code $loc " . json_encode($done));
list($code, $body) = follow($loc, $adm);
check('landing page shows the flash + detail with Reverse now, Retry gone, no error line', hasId($body, 'at-flash') && hasId($body, 'at-reverse') && !hasId($body, 'at-retry') && !hasId($body, 'at-error') && clean($body));
check('detail: step timings row with a total + the edge rewrite count in Moved, Reverse wired to reverse_of polling', hasId($body, 'at-timings') && preg_match('/neo4j \d+ ms/', $body) === 1 && strpos($body, '(total ') !== false && strpos($body, 'tag / relationship edge') !== false && strpos($body, "/transfer_status?reverse_of={$failed->pkey}") !== false, substr($body, 0, 100));
$mail = $mailSince($mark);
check('retry success mails "accepted" to each party once', substr_count($mail, "To: {$emails[$RECIP]}") === 1 && substr_count($mail, "To: {$emails[$OWNER]}") === 1 && strpos($mail, 'Subject: Transfer complete: you now own "Admin Fixture Project"') !== false, substr($mail, 0, 300));
list($code, $body) = http('GET', "/admin_transfers?sel={$failed->pkey}&verify=1", $adm);
check('Verify after the retry: CLEAN, no red rows, collaborator row removal now in the summary', strpos($body, 'id="at-verify-verdict">CLEAN') !== false && strpos($body, 'class="at-bad"') === false && strpos($body, '1 recipient collaborator row removed') !== false);
check('service agrees', $svc->verify($done)['clean'] === true);
check('project now with the recipient', $svc->projectNodeIds($P, $RECIP) && !$svc->projectNodeIds($P, $OWNER));
list($code, $body, $loc) = http('POST', '/admin_transfers', $adm, array('action' => 'retry', 'pkey' => $failed->pkey));
check('Retry on an accepted row: refused with a message', $code === 302 && strpos(urldecode($loc), 'only a failed row can be retried') !== false, $loc);

// ---------------------------------------------------------------- 5. reverse
section('5. Reverse');
$mark = $mailLen();
list($code, $body, $loc) = http('POST', '/admin_transfers', $thd, array('action' => 'reverse', 'pkey' => $failed->pkey));
check('non-admin cannot reverse', trim($body) === 'Not authorized.' && $rowCount() === 3 && $mailSince($mark) === '');
$t0 = microtime(true);
list($code, $body, $loc) = http('POST', '/admin_transfers', $adm, array('action' => 'reverse', 'pkey' => $failed->pkey));
$dt = round(microtime(true) - $t0, 1);
$rev = $db->get_row_prepared("SELECT * FROM project_transfers WHERE kind = 'reversal' AND summary->>'reverses_pkey' = $1 ORDER BY pkey DESC LIMIT 1", array((string)$failed->pkey));
check("admin Reverse ({$dt}s): new reversal row accepted, parties swapped, redirect lands on it", $rev && $rev->status === 'accepted' && (int)$rev->from_user_pkey === $RECIP && (int)$rev->to_user_pkey === $OWNER
	&& $code === 302 && strpos($loc, "sel={$rev->pkey}") !== false && strpos(urldecode($loc), "Transfer #{$failed->pkey} reversed as #{$rev->pkey}") !== false, "$code $loc " . json_encode($rev));
$orig = $svc->getByPkey($failed->pkey);
check('original row stamped: reversed_date + reversed_by 3 + tombstone cleared', $orig->reversed_date !== null && (int)$orig->reversed_by_pkey === 3 && $orig->tombstone_cleared_date !== null, json_encode($orig));
$mail = $mailSince($mark);
check('reversed mails to both parties', substr_count($mail, "To: {$emails[$OWNER]}") === 1 && substr_count($mail, "To: {$emails[$RECIP]}") === 1 && strpos($mail, 'Subject: Transfer reversed: "Admin Fixture Project" is back in your account') !== false && strpos($mail, 'Subject: Transfer reversed: "Admin Fixture Project" has been returned') !== false, substr($mail, 0, 400));
check('project back with the owner', $svc->projectNodeIds($P, $OWNER) && !$svc->projectNodeIds($P, $RECIP));
list($code, $body) = http('GET', "/transfer_status?reverse_of={$failed->pkey}", $adm);
$st = json_decode($body, true);
check('status endpoint by reverse_of: the reversal row, accepted at step 5', is_array($st) && $st['found'] === true && $st['status'] === 'accepted' && $st['step'] === 5, $body);
list($code, $body) = follow($loc, $adm);
check('reversal detail: "reversal" badge, Reverses link to the original, Verify offered', hasId($body, 'at-detail') && strpos($body, 'at-kind-reversal') !== false && strpos($body, "sel={$failed->pkey}\">transfer #{$failed->pkey}</a>") !== false && hasId($body, 'at-verify') && clean($body));
list($code, $body) = http('GET', "/admin_transfers?sel={$rev->pkey}&verify=1", $adm);
check('Verify on the reversal row: CLEAN (old owner = recipient holds nothing)', strpos($body, 'id="at-verify-verdict">CLEAN') !== false);
list($code, $body) = http('GET', "/admin_transfers?sel={$failed->pkey}", $adm);
check('original detail: Reversed line, no Reverse button any more', strpos($body, '<td>Reversed</td>') !== false && !hasId($body, 'at-reverse') && hasId($body, 'at-verify'));
list($code, $body) = http('GET', "/admin_transfers?project=$P", $adm);
check('list notes: reversal badge on the new row, "reversed" on the original, no tombstone on the original', preg_match('/id="at-row-' . $rev->pkey . '".*?at-kind-reversal">reversal<.*?<\/tr>/s', $body) === 1
	&& preg_match('/id="at-row-' . $failed->pkey . '".*?reversed \d{4}-\d\d-\d\d.*?<\/tr>/s', $body) === 1 && preg_match('/id="at-row-' . $failed->pkey . '"(?:(?!<\/tr>).)*at-tomb/s', $body) === 0);
list($code, $body, $loc) = http('POST', '/admin_transfers', $adm, array('action' => 'reverse', 'pkey' => $failed->pkey));
check('reversing an already reversed row: refused with the reason', $code === 302 && strpos(urldecode($loc), 'was not reversed: Only a completed, not yet reversed transfer') !== false, $loc);
list($code, $body, $loc) = http('POST', '/admin_transfers', $adm, array('action' => 'reverse', 'pkey' => $refused->pkey));
check('reversing a refused row: refused', $code === 302 && strpos(urldecode($loc), 'was not reversed') !== false, $loc);

// ---------------------------------------------------------------- 6. failed reversal -> Retry stamps the original
section('6. failed reversal -> Retry');
$r = $svc->request($P, $OWNER, $emails[$RECIP], true, $OWNER);
$r = $svc->accept($r['row']->uuid, $RECIP);
$second = $r['row'];
check('second transfer accepted in-process', $r['ok'] && $second->status === 'accepted');
$boom2 = new BoomTransfer($db, $neodb);
$r = $boom2->reverse($second->pkey, 3);
$revFailed = $r['row'];
check('reverse with an injected failure: reversal row failed at step 2, original NOT yet stamped', !$r['ok'] && $revFailed && $revFailed->kind === 'reversal' && $revFailed->status === 'failed' && (int)$revFailed->step === 1 && $svc->getByPkey($second->pkey)->reversed_date === null, json_encode($r));
list($code, $body) = http('GET', "/admin_transfers?sel={$revFailed->pkey}", $adm);
check('failed reversal detail: Retry from step 2 offered', hasId($body, 'at-retry') && strpos($body, 'Retry from step 2') !== false && strpos($body, 'at-kind-reversal') !== false);
$mark = $mailLen();
list($code, $body, $loc) = http('POST', '/admin_transfers', $adm, array('action' => 'retry', 'pkey' => $revFailed->pkey));
$revDone = $svc->getByPkey($revFailed->pkey);
$second = $svc->getByPkey($second->pkey);
check('Retry completes the reversal and stamps the original (reversed_date, reversed_by 3, tombstone cleared)', $code === 302 && $revDone->status === 'accepted' && $second->reversed_date !== null && (int)$second->reversed_by_pkey === 3 && $second->tombstone_cleared_date !== null, json_encode($second));
$mail = $mailSince($mark);
check('a retried reversal mails "reversed" (not "accepted")', strpos($mail, 'Subject: Transfer reversed:') !== false && strpos($mail, 'Subject: Transfer complete') === false && substr_count($mail, "To: {$emails[$OWNER]}") === 1 && substr_count($mail, "To: {$emails[$RECIP]}") === 1, substr($mail, 0, 300));
check('project with the owner again, verify clean', $svc->projectNodeIds($P, $OWNER) && $svc->verify($revDone)['clean'] === true);

// ---------------------------------------------------------------- 7. lazy expiry on the admin page
section('7. lazy expiry');
$r = $svc->request($P, $OWNER, $emails[$THIRD], true, $OWNER);
$stale = $r['row'];
$db->prepare_query("UPDATE project_transfers SET expires_date = now() - interval '1 hour' WHERE pkey = $1", array((int)$stale->pkey));
$mark = $mailLen();
list($code, $body) = http('GET', "/admin_transfers?project=$P", $adm);
$mail = $mailSince($mark);
check('loading the admin page expires it, lists it as expired, mails the owner once', $svc->getByPkey($stale->pkey)->status === 'expired' && preg_match('/id="at-row-' . $stale->pkey . '".*?at-status-expired.*?<\/tr>/s', $body) === 1
	&& substr_count($mail, "To: {$emails[$OWNER]}") === 1 && strpos($mail, 'Subject: Transfer request expired: "Admin Fixture Project"') !== false, substr($mail, 0, 300));
$mark = $mailLen();
list($code, $body) = http('GET', "/admin_transfers?project=$P", $adm);
check('no second expiry mail; fixture rows all listed (7) and clean', $mailSince($mark) === '' && strpos($body, '7 matching') !== false && clean($body), preg_match('/id="at-count">([^<]*)/', $body, $m) ? $m[1] : '');

// ---------------------------------------------------------------- done
cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
