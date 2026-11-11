<?php
/**
 * File: tests/fieldcamp/delete_test.php
 * Description: Suite for removing one camp account, deleting a whole field
 *              camp, and the admin's restore of both (FieldCamp::removeAccount,
 *              deleteCamp, restoreAccount, restoreCamp; FieldCampCopy::holdings;
 *              actions account_remove, accounts_remove, camp_delete,
 *              account_restore, camp_restore; the confirmation panels of
 *              field_camp.php and the deleted list of field_camp_wizard.php).
 *
 *              Everything is a soft delete: nothing leaves the server. The
 *              confirmations are strict: the exact name typed, and work that
 *              the owner has no current copy of blocks the removal unless
 *              "I do not need this work" is sent.
 *
 *              Fixture users 94797-94798 and camp usernames starting
 *              "fctest98_"; everything the suite makes is removed again.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/delete_test.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'db/strabospotclass.php';
require_once 'includes/fieldcamp/FieldCamp.php';
require_once 'includes/fieldcamp/FieldCampPackage.php';
require_once 'includes/fieldcamp/FieldCampRoster.php';
require_once 'includes/fieldcamp/FieldCampCopy.php';
require_once 'includes/fieldcamp/FieldCampTiles.php';

$INSTR = 94797; $OUTSIDER = 94798; $ADMIN = FieldCamp::ADMIN_USERPKEY;
$FIX = array($INSTR => 'fctest98-instructor@example.org', $OUTSIDER => 'fctest98-outsider@example.org');
$PW = 'camp-pass-98';
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$TOKEN = str_repeat('7c2d', 8);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 700) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function fixture_pkeys() {
	global $db, $FIX;
	$pks = array_keys($FIX);
	$rows = $db->get_results_prepared("select pkey from users where email like 'fctest98\\_%'", array());
	if ($rows) foreach ($rows as $r) $pks[] = (int)$r->pkey;
	return $pks;
}
function cleanup() {
	global $db, $neodb, $FIX;
	$in = implode(',', fixture_pkeys());
	$neodb->query("MATCH (n) WHERE n.userpkey IN [$in] DETACH DELETE n");
	$arr = "{" . $in . "}";
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE item_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE image_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = ANY($1)", array($arr));
	$db->query("delete from fieldcamp_copy where target_user_pkey in ($in) or source_user_pkey in ($in)");
	$rows = $db->get_results_prepared("select uuid from versions where userpkey in ($in)", array());
	if ($rows) foreach ($rows as $r) { if (preg_match('/^[0-9a-f-]{36}$/i', (string)$r->uuid)) @unlink("/srv/app/www/versions/" . $r->uuid); }
	$db->query("delete from versions where userpkey in ($in)");
	$fix = implode(',', array_keys($FIX));
	$rows = $db->get_results_prepared("select pkey from fieldcamp where owner_user_pkey in ($fix)", array());
	if ($rows) {
		$cin = implode(',', array_map(function ($r) { return (int)$r->pkey; }, $rows));
		foreach (array('fieldcamp_account', 'fieldcamp_viewer', 'fieldcamp_map', 'fieldcamp_unit', 'fieldcamp_unit_source') as $t) $db->query("delete from $t where fieldcamp_pkey in ($cin)");
		$db->query("delete from fieldcamp where pkey in ($cin)");
	}
	$db->query("delete from fieldcamp_account where user_pkey in ($in)");
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fix)");
	$db->query("delete from apptokens where email like 'fctest98\\_%'");
	$db->query("delete from users where pkey in ($in)");
}
function api($method, $path, $username, $json = null) {
	global $BASE, $PW;
	$ch = curl_init("$BASE/db/$path");
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "$username:$PW", CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120));
	if ($json !== null) { curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); }
	$body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return array($code, json_decode((string)$body, true), (string)$body);
}
$sids = array();
function forge($pkey, $email) {
	global $SESS, $sids;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	global $TOKEN;
	file_put_contents("$SESS/sess_$sid", 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';'
		. 'fieldcamp_csrf|s:' . strlen($TOKEN) . ':"' . $TOKEN . '";');
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
function web($path, $sid) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $sid ? array('Cookie: PHPSESSID=' . $sid) : array()));
	$body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return array($code, (string)$body);
}
function post($sid, $fields) {
	global $BASE, $TOKEN;
	$ch = curl_init("$BASE/field_camp_action");
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true, CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($fields + array('csrf' => $TOKEN)), CURLOPT_HTTPHEADER => $sid ? array('Cookie: PHPSESSID=' . $sid) : array()));
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$loc = preg_match('/^Location:\s*(.+)$/mi', substr((string)$raw, 0, $hs), $m) ? trim($m[1]) : '';
	if ($loc === '') return array($code, substr((string)$raw, $hs), '');
	list($c2, $b2) = web($loc, $sid);
	return array($c2, $b2, $loc);
}
function noisy($body) { return preg_match('~<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>~', $body) === 1; }
function user($pk) { global $db; return $db->get_row_prepared("select active, deleted from users where pkey = $1", array((int)$pk)); }
function t($v) { return $v === true || $v === 't'; }
function spots_of($pk) { global $neodb; return (int)$neodb->get_var("MATCH (s:Spot) WHERE s.userpkey = " . (int)$pk . " RETURN count(s)"); }
function uploadWork($username, $projectId, $dsId, $n) {
	$ts = (int)floor(microtime(true) * 1000);
	$codes = array();
	list($codes[]) = api('POST', 'project', $username, array('id' => $projectId, 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts, 'description' => array('project_name' => 'Delete Project')));
	list($codes[]) = api('POST', 'dataset', $username, array('id' => $dsId, 'name' => 'Default', 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts));
	list($codes[]) = api('POST', "projectDatasets/$projectId", $username, array('id' => $dsId));
	$features = array();
	for ($j = 0; $j < $n; $j++) $features[] = array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.27 - $j / 100, 38.55)),
		'properties' => array('id' => $dsId * 100 + $j + 1, 'name' => "spot $j", 'date' => '2027-06-01T15:00:00.000Z', 'modified_timestamp' => $ts));
	if ($features) list($codes[]) = api('POST', "datasetspots/$dsId", $username, array('type' => 'FeatureCollection', 'features' => $features));
	return $codes;
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Delete', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fctest98hash' . $pk, $email));
	$neodb->query("CREATE (:User {userpkey: $pk, email: '$email'})");
}
FieldCamp::addInstructor($db, $INSTR, 'delete test', 3);
FieldCamp::addInstructor($db, $OUTSIDER, 'delete test', 3);
list($clean) = FieldCamp::validateBasics(array('camp_name' => 'Delete Camp', 'camp_year' => '2027', 'project_name' => 'Delete Project'));
$campid = FieldCamp::createCamp($db, $INSTR, $clean);
$camp = FieldCamp::getCamp($db, $campid);
check('this server can delete camps (sql/fieldcamp.sql applied)', FieldCamp::canDeleteCamps($db));
$made = FieldCamp::createAccounts($db, $neodb, $camp, 'fctest98_{n}', 4, $PW);
check('four accounts created', $made['error'] === '' && count($made['created']) === 4, $made);
list($P) = FieldCampPackage::ensureIds($db, $camp);
$camp = FieldCamp::getCamp($db, $campid);
$accounts = FieldCamp::listAccounts($db, $campid);
$A = array(); foreach ($accounts as $a) $A[] = (int)$a->user_pkey;   // 0..3
$DS = (int)floor(microtime(true) * 10000) + 10;
$bad = array_filter(array_merge(uploadWork('fctest98_1', $P, $DS, 3), uploadWork('fctest98_2', $P, $DS, 2), uploadWork('fctest98_3', $P, $DS, 0)), function ($c) { return $c < 200 || $c >= 300; });
check('students 1 and 2 uploaded spots, student 3 an empty project, student 4 nothing', !$bad, $bad);
$copier = new FieldCampCopy($db, $neodb);
$sidI = forge($INSTR, $FIX[$INSTR]); $sidO = forge($OUTSIDER, $FIX[$OUTSIDER]); $sidA = forge($ADMIN, 'admin');

// ===========================================================================
section('What each account holds');
$h = $copier->holdings($camp, $accounts);
check('student 1: 1 project, 3 spots, not copied', $h[$A[0]]['projects'] === 1 && $h[$A[0]]['spots'] === 3 && $h[$A[0]]['uncopied'] === 1 && $h[$A[0]]['username'] === 'fctest98_1', $h[$A[0]]);
check('an empty project is not work to lose; nothing uploaded is nothing', $h[$A[2]]['projects'] === 1 && $h[$A[2]]['uncopied'] === 0 && $h[$A[3]]['projects'] === 0 && $h[$A[3]]['uncopied'] === 0, array($h[$A[2]], $h[$A[3]]));
$copier->copyAccount($camp, $accounts[0]);
$h = $copier->holdings($camp, $accounts);
check('after a copy student 1 is covered, student 2 is not', $h[$A[0]]['uncopied'] === 0 && $h[$A[1]]['uncopied'] === 1);
uploadWork('fctest98_1', $P, $DS, 4);
check('a new upload after the copy counts as not copied again', $copier->holdings($camp, $accounts)[$A[0]]['uncopied'] === 1);
$copier->copyAccount($camp, $accounts[0]);

// ===========================================================================
section('Removing one account');
list($code, $body) = web("/field_camp?id=$campid&step=accounts", $sidI);
check('each account row has a Remove link', $code === 200 && !noisy($body) && substr_count($body, '>Remove...</a>') === 4 && strpos($body, "step=accounts&amp;remove={$A[3]}#fcRemove") !== false && strpos($body, 'id="fcRemove"') === false);
list($code, $body) = web("/field_camp?id=$campid&step=accounts&remove={$A[3]}", $sidI);
check('the panel for an account without work: what happens, typed username, no copy block', !noisy($body) && strpos($body, 'Remove the account fctest98_4') !== false && strpos($body, 'It has not uploaded anything.') !== false
	&& strpos($body, 'can no longer be uploaded') !== false && strpos($body, 'can bring the account back') !== false && strpos($body, 'data-fc-confirm="fctest98_4"') !== false && strpos($body, 'fcRemNoCopy') === false);
list($code, $body) = web("/field_camp?id=$campid&step=accounts&remove={$A[1]}", $sidI);
check('the panel for an account with uncopied work: counts, copy first, and the override', strpos($body, '<strong>1 project</strong>: 2 spots and 0 photos.') !== false && strpos($body, 'holds work that has not been copied') !== false
	&& strpos($body, 'id="fcRemoveCopyBtn"') !== false && strpos($body, 'id="fcRemNoCopy"') !== false);
list($code, $body) = web("/field_camp?id=$campid&step=accounts&remove={$A[0]}", $sidI);
check('the panel for an account whose work is copied says so', strpos($body, 'an up-to-date copy') !== false && strpos($body, 'fcRemNoCopy') === false);
list($code, $body) = web("/field_camp?id=$campid&step=accounts&remove=$OUTSIDER", $sidI);
check('an account that is not of this camp gets no panel', strpos($body, 'id="fcRemove"') === false);

list($code, $body) = post($sidI, array('action' => 'account_remove', 'id' => $campid, 'user' => $A[3], 'confirm' => 'fctest98_44'));
check('a wrong username is refused', strpos($body, 'does not match. The account was not removed.') !== false && !t(user($A[3])->deleted));
list($code, $body) = post($sidI, array('action' => 'account_remove', 'id' => $campid, 'user' => $A[3], 'confirm' => ''));
check('an empty confirmation is refused', !t(user($A[3])->deleted));
list($code, $body) = post($sidI, array('action' => 'account_remove', 'id' => $campid, 'user' => $A[1], 'confirm' => 'fctest98_2'));
check('uncopied work blocks the removal', strpos($body, 'holds work that has not been copied') !== false && !t(user($A[1])->deleted) && t(user($A[1])->active));
list($code) = post($sidO, array('action' => 'account_remove', 'id' => $campid, 'user' => $A[3], 'confirm' => 'fctest98_4'));
check('another instructor cannot remove an account', $code === 404 && !t(user($A[3])->deleted), $code);
list($code, $body) = post($sidI, array('action' => 'account_remove', 'id' => $campid, 'user' => $OUTSIDER, 'confirm' => $FIX[$OUTSIDER]));
check('an account outside the camp cannot be removed through it', strpos($body, 'That account is not one of this camp') !== false && !t(user($OUTSIDER)->deleted) && t(user($OUTSIDER)->active));

list($codeApi) = api('GET', 'myProjects', 'fctest98_4');
list($code, $body, $loc) = post($sidI, array('action' => 'account_remove', 'id' => $campid, 'user' => $A[3], 'confirm' => 'fctest98_4'));
$u = user($A[3]);
check('the right username removes the (still active) account', strpos($loc, 'step=accounts') !== false && strpos($body, 'The account fctest98_4 was removed.') !== false && t($u->deleted) && !t($u->active), $loc);
list($codeApi2) = api('GET', 'myProjects', 'fctest98_4');
check('it could sign in before and cannot any more', $codeApi === 200 && $codeApi2 !== 200, array($codeApi, $codeApi2));
check('it is off the camp\'s list, the others are untouched', count(FieldCamp::listAccounts($db, $campid)) === 3 && t(user($A[0])->active) && !t(user($A[0])->deleted));
check('its username stays taken', FieldCamp::planAccounts($db, FieldCamp::getCamp($db, $campid), 'fctest98_{n}', 1)['names'][0]['username'] === 'fctest98_5');
$before = spots_of($A[1]);
list($code, $body) = post($sidI, array('action' => 'account_remove', 'id' => $campid, 'user' => $A[1], 'confirm' => 'fctest98_2', 'nocopy' => '1'));
check('"I do not need this work" lets an account with uncopied work go; its data stays on the server', t(user($A[1])->deleted) && $before === 2 && spots_of($A[1]) === 2, spots_of($A[1]));

// ===========================================================================
section('The admin restores an account');
list($code, $body) = web("/field_camp?id=$campid&step=accounts", $sidI);
check('the instructor sees no restore list', strpos($body, 'Removed accounts (admin)') === false);
list($code) = post($sidI, array('action' => 'account_restore', 'id' => $campid, 'user' => $A[1]));
check('and cannot restore', $code === 404 && t(user($A[1])->deleted), $code);
list($code, $body) = web("/field_camp?id=$campid&step=accounts", $sidA);
check('the admin sees the two removed accounts', !noisy($body) && strpos($body, 'Removed accounts (admin)') !== false && substr_count($body, 'value="account_restore"') === 2);
list($code, $body) = post($sidA, array('action' => 'account_restore', 'id' => $campid, 'user' => $A[1]));
$u = user($A[1]);
check('restore: back on the list, deactivated, with its work', strpos($body, 'Account restored.') !== false && !t($u->deleted) && !t($u->active) && count(FieldCamp::listAccounts($db, $campid)) === 3 && spots_of($A[1]) === 2);
FieldCamp::setAccountsActive($db, FieldCamp::getCamp($db, $campid), true, array($A[1]));
list($codeApi) = api('GET', 'myProjects', 'fctest98_2');
check('reactivated, the student signs in again', $codeApi === 200, $codeApi);

// ===========================================================================
section('Removing all deactivated accounts');
$camp = FieldCamp::getCamp($db, $campid);
FieldCamp::setAccountsActive($db, $camp, false, array($A[1], $A[2]));
list($code, $body) = web("/field_camp?id=$campid&step=accounts", $sidI);
check('the form states what goes and blocks on the uncopied work', !noisy($body) && strpos($body, 'Remove the 2 deactivated account(s)') !== false && strpos($body, '1 of them has uploaded work (2 spots, 0 photos).') !== false
	&& strpos($body, '1 of them still hold work that has not been copied.') !== false && strpos($body, 'id="fcAllNoCopy"') !== false && strpos($body, 'data-fc-confirm="Delete Camp"') !== false);
list($code, $body) = post($sidI, array('action' => 'accounts_remove', 'id' => $campid, 'confirm' => 'Delete Camp'));
check('refused while work is not copied', strpos($body, 'No accounts were removed.') !== false && !t(user($A[1])->deleted) && !t(user($A[2])->deleted));
$copier->copyAccount($camp, FieldCamp::listAccounts($db, $campid)[1]);
list($code, $body) = post($sidI, array('action' => 'accounts_remove', 'id' => $campid, 'confirm' => 'Delete Camp'));
check('after the copy the same request goes through; the active account stays', strpos($body, '2 accounts removed.') !== false && t(user($A[1])->deleted) && t(user($A[2])->deleted) && !t(user($A[0])->deleted) && t(user($A[0])->active));
foreach (array($A[1], $A[2]) as $pk) FieldCamp::restoreAccount($db, $camp, $pk);

// ===========================================================================
section('Deleting the camp: the confirmation');
list($code, $body) = web("/field_camp?id=$campid&step=basics", $sidI);
check('Basics offers to delete, without the form yet', $code === 200 && !noisy($body) && strpos($body, 'Delete this field camp...') !== false && strpos($body, 'step=basics&amp;delete=1#fcDelete') !== false && strpos($body, 'value="camp_delete"') === false);
$copier2 = new FieldCampCopy($db, $neodb);
FieldCamp::setAccountsActive($db, $camp, true, array($A[1]));
uploadWork('fctest98_2', $P, $DS, 5);
check('student 2 has uploaded again since the copy', $copier2->holdings($camp, FieldCamp::listAccounts($db, $campid))[$A[1]]['uncopied'] === 1);
list($code, $body) = web("/field_camp?id=$campid&step=basics&delete=1", $sidI);
check('the confirmation states what goes', !noisy($body) && strpos($body, '<strong>3 accounts</strong> are closed for good (2 can still sign in today)') !== false
	&& strpos($body, '<strong>2 accounts have uploaded work</strong>: 9 spots and 0 photos.') !== false && strpos($body, 'can no longer be uploaded') !== false
	&& strpos($body, 'are kept') !== false && strpos($body, 'can bring the camp and its accounts back') !== false, substr(strip_tags(substr($body, (int)strpos($body, 'id="fcDelete"'), 1500)), 0, 600));
check('it blocks on the uncopied work, with the way to copy and the override, and asks for the camp name', strpos($body, '<strong>1 account holds work that has not been copied to you</strong>') !== false && strpos($body, 'step=roster">go to Student work') !== false
	&& strpos($body, 'id="fcDelNoCopy"') !== false && strpos($body, 'data-fc-confirm="Delete Camp"') !== false && strpos($body, 'value="camp_delete"') !== false);
list($code, $body) = post($sidI, array('action' => 'camp_delete', 'id' => $campid, 'confirm' => 'delete camp'));
check('the name must be typed exactly', strpos($body, 'does not match this camp. Nothing was deleted.') !== false && FieldCamp::getCamp($db, $campid) !== null);
list($code, $body) = post($sidI, array('action' => 'camp_delete', 'id' => $campid, 'confirm' => 'Delete Camp'));
check('uncopied work blocks the delete', strpos($body, 'Nothing was deleted.') !== false && FieldCamp::getCamp($db, $campid) !== null && !t(user($A[0])->deleted));
list($code) = post($sidO, array('action' => 'camp_delete', 'id' => $campid, 'confirm' => 'Delete Camp', 'nocopy' => '1'));
check('another instructor cannot delete the camp', $code === 404 && FieldCamp::getCamp($db, $campid) !== null, $code);
$db->prepare_query("update fieldcamp set build_status = 'building', build_started = now() where pkey = $1", array($campid));
list($code, $body) = web("/field_camp?id=$campid&step=basics&delete=1", $sidI);
check('while the offline maps are built the page offers no delete form', strpos($body, 'can be deleted when the build is done') !== false && strpos($body, 'value="camp_delete"') === false);
list($code, $body) = post($sidI, array('action' => 'camp_delete', 'id' => $campid, 'confirm' => 'Delete Camp', 'nocopy' => '1'));
check('and the server refuses it', strpos($body, 'being built') !== false && FieldCamp::getCamp($db, $campid) !== null);
// a built tile set, to see it go
$tok = bin2hex(random_bytes(16));
$db->prepare_query("update fieldcamp set build_status = 'ready', files_token = $2 where pkey = $1", array($campid, $tok));
$tdir = "/srv/app/www/fieldcamp_files/$tok"; mkdir($tdir, 0775, true); file_put_contents("$tdir/OfflineTiles.zip", 'x'); file_put_contents("$tdir/tiles.json", '{}');
$copiesBefore = count($copier->projectsOf($INSTR));

// ===========================================================================
section('Deleting the camp');
list($code, $body, $loc) = post($sidI, array('action' => 'camp_delete', 'id' => $campid, 'confirm' => 'Delete Camp', 'nocopy' => '1'));
check('with the name and the override the camp is deleted and the list says so', strpos($loc, '/field_camp_wizard') !== false && strpos($body, 'The field camp &quot;Delete Camp&quot; was deleted.') !== false && !noisy($body), $loc);
check('the camp is gone for the instructor: list, page, actions', strpos($body, '>Open</a>') === false && FieldCamp::getCamp($db, $campid) === null && FieldCamp::listCamps($db, $INSTR) === array());
list($code) = web("/field_camp?id=$campid&step=basics", $sidI);
list($code2) = web("/field_camp_package?id=$campid", $sidI);
list($code3) = post($sidI, array('action' => 'basics', 'id' => $campid, 'camp_name' => 'Back', 'camp_year' => '2027', 'project_name' => 'x'));
check('its pages answer 404', $code === 404 && $code2 === 404 && $code3 === 404, array($code, $code2, $code3));
$gone = FieldCamp::getCamp($db, $campid, true);
check('the row is kept, marked with when and by whom', $gone && (string)$gone->deleted_date !== '' && (int)$gone->deleted_by === $INSTR && $gone->camp_name === 'Delete Camp');
check('every account is closed', t(user($A[0])->deleted) && t(user($A[1])->deleted) && t(user($A[2])->deleted) && !t(user($A[0])->active));
list($codeApi) = api('GET', 'myProjects', 'fctest98_1');
check('a student can no longer sign in', $codeApi !== 200, $codeApi);
check('the students\' data is still on the server', spots_of($A[0]) === 4 && spots_of($A[1]) === 5);
check('the built tiles are gone', !is_dir($tdir));
check('the copies in the instructor\'s account are kept', count($copier->projectsOf($INSTR)) === $copiesBefore && $copiesBefore >= 2, $copiesBefore);
check('the Field Book door is closed with the camp', FieldCampRoster::book($db, $neodb, $INSTR, $campid, $A[0], $P) === null);

// ===========================================================================
section('The admin restores the camp');
list($code, $body) = web("/field_camp_wizard", $sidI);
check('the instructor sees no deleted list and no button for it', strpos($body, 'Deleted field camps') === false && strpos($body, 'deleted=1') === false);
list($code, $body) = web("/field_camp_wizard?deleted=1", $sidI);
check('nor when asking for it by address', $code === 200 && strpos($body, 'Deleted field camps') === false && strpos($body, 'camp_restore') === false);
list($code) = post($sidI, array('action' => 'camp_restore', 'id' => $campid));
check('and cannot restore', $code === 404 && FieldCamp::getCamp($db, $campid) === null, $code);
list($code, $body) = web("/field_camp_wizard", $sidA);
$nDeleted = count(FieldCamp::listDeletedCamps($db));
check('the admin\'s front page has a button with the count, not the list', !noisy($body) && strpos($body, 'href="/field_camp_wizard?deleted=1#fcDeleted"') !== false && strpos($body, "Deleted field camps ($nDeleted)") !== false
	&& strpos($body, 'Deleted field camps (admin)') === false && strpos($body, 'camp_restore') === false && strpos($body, '3 accounts removed with it') === false);
list($code, $body) = web("/field_camp_wizard?deleted=1", $sidA);
check('the button opens the list: the deleted camp with its accounts, and a way to hide it', !noisy($body) && strpos($body, 'Deleted field camps (admin)') !== false && strpos($body, 'Hide deleted field camps') !== false && preg_match('~Delete Camp.*?3 accounts removed with it.*?fctest98-instructor@example\.org~s', $body) === 1);
list($code, $body) = post($sidA, array('action' => 'camp_restore', 'id' => $campid));
$back = FieldCamp::getCamp($db, $campid);
check('restore brings the camp back', strpos($body, 'Field camp restored, with 3 accounts') !== false && $back && (string)$back->deleted_date === '' && count(FieldCamp::listCamps($db, $INSTR)) === 1);
$u = user($A[0]);
check('with its three accounts, deactivated; the one removed earlier stays removed', count(FieldCamp::listAccounts($db, $campid)) === 3 && !t($u->deleted) && !t($u->active) && t(user($A[3])->deleted));
list($code, $body) = web("/field_camp?id=$campid&step=accounts", $sidI);
check('the instructor opens it again and can reactivate', $code === 200 && !noisy($body) && strpos($body, 'Reactivate 3 deactivated account(s)') !== false);
list($code, $body) = post($sidA, array('action' => 'camp_restore', 'id' => $campid));
check('restoring a camp that is not deleted is refused', strpos($body, 'not among the deleted ones') !== false);
list($clean2) = FieldCamp::validateBasics(array('camp_name' => 'Empty Delete Camp', 'camp_year' => '2027', 'project_name' => 'Empty'));
$emptyid = FieldCamp::createCamp($db, $INSTR, $clean2);
list($code, $body) = web("/field_camp?id=$emptyid&step=basics&delete=1", $sidI);
check('a camp without accounts: the confirmation says so and has no copy block', strpos($body, 'This camp has no accounts.') !== false && strpos($body, 'fcDelNoCopy') === false);
list($code, $body, $loc) = post($sidI, array('action' => 'camp_delete', 'id' => $emptyid, 'confirm' => ' Empty Delete Camp '));
check('and it is deleted on its name alone', strpos($loc, '/field_camp_wizard') !== false && FieldCamp::getCamp($db, $emptyid) === null);

// ===========================================================================
cleanup();
foreach ($sids as $sid) @unlink("$SESS/sess_$sid");
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
