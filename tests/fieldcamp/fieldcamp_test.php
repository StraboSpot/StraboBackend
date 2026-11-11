<?php
/**
 * File: tests/fieldcamp/fieldcamp_test.php
 * Description: Suite for the Field Camp Wizard, stages 1 and 2
 *              (includes/fieldcamp/FieldCamp.php, field_camp_wizard.php,
 *              field_camp.php, field_camp_action.php,
 *              admin_field_camp_instructors.php and the camp account locks in
 *              change_password.php, change_email.php, passwordreset.php and
 *              DELETE /db/account).
 *
 *              Part 1 calls the service directly: the gate, camp records,
 *              username templates, account creation (PostgreSQL row, Neo4j
 *              :User node, sign-in query), the all-or-nothing rule, the cap,
 *              deactivate / reactivate / remove, instructors of a camp.
 *              Part 2 goes through the real Apache with forged PHP sessions
 *              over curl: who may open which page, the form token, the whole
 *              create flow, and every lock on a camp account.
 *
 *              Fixture users 94701-94704 and camp usernames starting
 *              "fctest94"; everything the suite makes is removed again at the
 *              start and at the end.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/fieldcamp_test.php
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

$INSTR = 94701; $OUTSIDER = 94702; $VIEWER = 94703; $INSTR2 = 94704;
$FIX = array($INSTR => 'fctest-instructor@example.org', $OUTSIDER => 'fctest-outsider@example.org',
	$VIEWER => 'fctest-viewer@example.org', $INSTR2 => 'fctest-instructor2@example.org');
$PW = 'camp-pass-94';
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$TOKEN = str_repeat('ab12', 8);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

function cleanup() {
	global $db, $neodb, $FIX;
	$fixpkeys = implode(',', array_keys($FIX));
	$campusers = array();
	$rows = $db->get_results_prepared("select pkey from users where email like 'fctest94%'", array());
	if ($rows) foreach ($rows as $r) $campusers[] = (int)$r->pkey;
	$camps = array();
	$rows = $db->get_results_prepared("select pkey from fieldcamp where owner_user_pkey in ($fixpkeys)", array());
	if ($rows) foreach ($rows as $r) $camps[] = (int)$r->pkey;
	if ($camps) {
		$in = implode(',', $camps);
		$db->query("delete from fieldcamp_account where fieldcamp_pkey in ($in)");
		$db->query("delete from fieldcamp_viewer where fieldcamp_pkey in ($in)");
		$db->query("delete from fieldcamp where pkey in ($in)");
	}
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fixpkeys)");
	$all = array_merge($campusers, array_keys($FIX));
	$in = implode(',', $all);
	$db->query("delete from fieldcamp_account where user_pkey in ($in)");
	$db->query("delete from apptokens where email like 'fctest94%'");
	$db->query("delete from users where pkey in ($in)");
	$neodb->query("match (u:User) where u.userpkey in [$in] detach delete u");
}

$sids = array();
function forge($pkey, $email, $withToken = true) {
	global $SESS, $sids, $TOKEN;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$data = 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';';
	if ($withToken) $data .= 'fieldcamp_csrf|s:' . strlen($TOKEN) . ':"' . $TOKEN . '";';
	file_put_contents("$SESS/sess_$sid", $data);
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
function http($method, $path, $sid, $post = null, $basic = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	$hdr = array();
	if ($sid !== null) $hdr[] = 'Cookie: PHPSESSID=' . $sid;
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $hdr));
	if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	if ($basic !== null) curl_setopt($ch, CURLOPT_USERPWD, $basic);
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
	$headers = substr((string)$raw, 0, $hs); $body = substr((string)$raw, $hs);
	$loc = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
	return array($code, (string)$body, $loc);
}
/** POST a wizard form, then GET the page it redirects to. */
function post_follow($sid, $fields) {
	global $TOKEN;
	list($code, $body, $loc) = http('POST', '/field_camp_action', $sid, $fields + array('csrf' => $TOKEN));
	if ($loc === '') return array($code, $body, $loc);
	list($c2, $b2) = http('GET', $loc, $sid);
	return array($c2, $b2, $loc);
}
function can_sign_in($username, $password) {
	global $db;
	// The query extauth.php (the Field API's password check) runs.
	return (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE email=$1 AND crypt($2, password) = password AND active = TRUE AND deleted = FALSE", array($username, $password)) === 1;
}
/** PHP prints its own errors as "<b>Warning</b>: ..." (display_errors is on). */
function php_error($body) {
	return (bool)preg_match('~<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>~', $body);
}
function user_nodes($pkeys) {
	global $neodb;
	if (!$pkeys) return 0;
	return (int)$neodb->get_var("match (u:User) where u.userpkey in [" . implode(',', array_map('intval', $pkeys)) . "] return count(u)");
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Test', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fctesthash' . $pk, $email));
}

// ===========================================================================
section('Service: gate');
check('tables installed', FieldCamp::installed($db));
check('outsider cannot use the wizard', !FieldCamp::canUse($db, $OUTSIDER));
check('not logged in cannot use the wizard', !FieldCamp::canUse($db, 0));
check('admin can use the wizard', FieldCamp::canUse($db, FieldCamp::ADMIN_USERPKEY));
check('add instructor', FieldCamp::addInstructor($db, $INSTR, 'test', 3) === '');
check('add second instructor', FieldCamp::addInstructor($db, $INSTR2, 'test', 3) === '');
check('instructor can use the wizard', FieldCamp::canUse($db, $INSTR));
check('adding the same instructor twice is refused', FieldCamp::addInstructor($db, $INSTR, '', 3) !== '');
check('adding an unknown account is refused', FieldCamp::addInstructor($db, 947099, '', 3) !== '');

// ===========================================================================
section('Service: basics and area');
list($clean, $errs) = FieldCamp::validateBasics(array('camp_name' => ' Test Camp ', 'camp_year' => '2027', 'project_name' => 'Test Project', 'start_date' => '2027-06-01', 'end_date' => '2027-06-20'));
check('good basics pass', !$errs && $clean['camp_name'] === 'Test Camp' && $clean['camp_year'] === 2027, $errs);
list(, $errs) = FieldCamp::validateBasics(array('camp_name' => '', 'camp_year' => '27', 'project_name' => ''));
check('empty name, short year, empty project are 3 problems', count($errs) === 3, $errs);
list(, $errs) = FieldCamp::validateBasics(array('camp_name' => 'a', 'camp_year' => '2027', 'project_name' => 'b', 'start_date' => '2027-06-20', 'end_date' => '2027-06-01'));
check('end before start is refused', count($errs) === 1, $errs);
list(, $errs) = FieldCamp::validateBasics(array('camp_name' => 'a', 'camp_year' => '2027', 'project_name' => 'b', 'start_date' => '2027-02-30'));
check('impossible date is refused', count($errs) === 1, $errs);
list($clean2) = FieldCamp::validateBasics(array('camp_name' => 'a', 'camp_year' => '2027', 'project_name' => 'b'));
check('dates are optional', $clean2['start_date'] === null && $clean2['end_date'] === null);

$campid = FieldCamp::createCamp($db, $INSTR, $clean);
$camp = FieldCamp::getCamp($db, $campid);
check('camp saved', $camp && $camp->camp_name === 'Test Camp' && (int)$camp->owner_user_pkey === $INSTR && $camp->status === 'draft' && $camp->delivery === 'shared');
check('owner can manage', FieldCamp::canManage($camp, $INSTR));
check('admin can manage', FieldCamp::canManage($camp, 3));
check('another instructor cannot manage', !FieldCamp::canManage($camp, $INSTR2));
check('no area yet', FieldCamp::areaSizeKm($camp) === null);

list($v, $errs) = FieldCamp::validateArea(array('west' => '-105.30', 'south' => '39.90', 'east' => '-105.24', 'north' => '39.94'));
check('good area passes', !$errs, $errs);
FieldCamp::saveArea($db, $campid, $v);
$camp = FieldCamp::getCamp($db, $campid);
$size = FieldCamp::areaSizeKm($camp);
check('area saved, about 5.1 km x 4.5 km', $size && abs($size['width'] - 5.12) < 0.1 && abs($size['height'] - 4.45) < 0.1, $size);
list(, $errs) = FieldCamp::validateArea(array('west' => '-105', 'south' => '40', 'east' => '-106', 'north' => '41'));
check('west past east is refused', count($errs) === 1);
list(, $errs) = FieldCamp::validateArea(array('west' => 'x', 'south' => '40', 'east' => '-106', 'north' => '41'));
check('non-numeric area is refused', count($errs) === 1);
list(, $errs) = FieldCamp::validateArea(array('west' => '-190', 'south' => '40', 'east' => '-106', 'north' => '41'));
check('area past the map edge is refused', count($errs) === 1);

// ===========================================================================
section('Service: username templates');
$cases = array(
	'fctest94_{n}' => true, ' FCTest94_{N} ' => true, 'ku.camp-27_{n}x' => true,
	'' => false, 'fctest94' => false, 'fctest94_{n}_{n}' => false, 'ab{n}' => false,
	'fc test_{n}' => false, 'fctest@ku.edu_{n}' => false, '{n}fctest94' => false,
	str_repeat('a', 60) . '{n}' => false,
);
foreach ($cases as $tpl => $ok) {
	list($norm, $problem) = FieldCamp::validateTemplate($tpl);
	check("template '" . substr($tpl, 0, 30) . "' " . ($ok ? 'accepted' : 'refused'), ($problem === '') === $ok, $problem);
}
list($norm) = FieldCamp::validateTemplate(' FCTest94_{N} ');
check('template is stored lower case, trimmed', $norm === 'fctest94_{n}', $norm);
check('usernameFor', FieldCamp::usernameFor('fctest94_{n}', 7) === 'fctest94_7');

// ===========================================================================
section('Service: create accounts');
$plan = FieldCamp::planAccounts($db, $camp, 'fctest94_{n}', 3);
check('plan lists 3 free names', $plan['error'] === '' && count($plan['names']) === 3 && $plan['taken'] === 0 && $plan['names'][0]['username'] === 'fctest94_1', $plan);
check('plan refuses a count of 0', FieldCamp::planAccounts($db, $camp, 'fctest94_{n}', 0)['error'] !== '');
check('plan refuses more than the cap', FieldCamp::planAccounts($db, $camp, 'fctest94_{n}', FieldCamp::MAX_ACCOUNTS + 1)['error'] !== '');

$r = FieldCamp::createAccounts($db, $neodb, $camp, 'fctest94_{n}', 3, 'abc');
check('a password of three characters is refused: the minimum is four', $r['error'] === 'The password needs at least 4 characters.' && !$r['created'] && FieldCamp::MIN_PASSWORD_LENGTH === 4, $r);
$r = FieldCamp::createAccounts($db, $neodb, $camp, 'fctest94_{n}', 0, 'abcd');
check('four characters pass the length rule (this try stops at the count instead)', $r['error'] !== '' && strpos($r['error'], 'password') === false && !$r['created'], $r);
$r = FieldCamp::createAccounts($db, $neodb, $camp, 'fctest94_{n}', 3, $PW);
check('3 accounts created', $r['error'] === '' && $r['created'] === array('fctest94_1', 'fctest94_2', 'fctest94_3'), $r);
$accounts = FieldCamp::listAccounts($db, $campid);
$pkeys = array(); foreach ($accounts as $a) $pkeys[] = (int)$a->user_pkey;
check('3 account rows, numbered 1 to 3', count($accounts) === 3 && (int)$accounts[2]->seq === 3);
check('3 Neo4j :User nodes', user_nodes($pkeys) === 3);
check('each account can sign in with the camp password', can_sign_in('fctest94_1', $PW) && can_sign_in('fctest94_3', $PW));
check('a wrong password cannot sign in', !can_sign_in('fctest94_1', 'wrong-pass'));
check('accounts are camp accounts', FieldCamp::isCampAccount($db, $pkeys[0]));

// The camp password is kept so the instructor can look it up; it is never stored as it is.
$kept = FieldCamp::getCamp($db, $campid);
check('the password is on record for the camp', FieldCamp::campPassword($kept) === $PW);
check('what the database holds is not the password, and differs each time', strpos((string)$kept->account_password_enc, $PW) === false && strlen((string)$kept->account_password_enc) > 40
	&& FieldCamp::sealPassword($PW) !== FieldCamp::sealPassword($PW) && FieldCamp::openPassword(FieldCamp::sealPassword($PW)) === $PW);
check('odd characters survive', FieldCamp::openPassword(FieldCamp::sealPassword("p\u{e4}ss w\u{f6}rd \"'<>&")) === "p\u{e4}ss w\u{f6}rd \"'<>&");
$tampered = base64_decode((string)$kept->account_password_enc); $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
check('a damaged or foreign value reads as "not on record", not as garbage', FieldCamp::openPassword(base64_encode($tampered)) === null && FieldCamp::openPassword('nonsense') === null && FieldCamp::openPassword('') === null);
check('a camp without accounts has no password on record', FieldCamp::campPassword(FieldCamp::getCamp($db, FieldCamp::createCamp($db, $INSTR, $clean))) === null);
check('the instructor is not a camp account', !FieldCamp::isCampAccount($db, $INSTR));
$camp = FieldCamp::getCamp($db, $campid);
check('template stored on the camp', $camp->username_template === 'fctest94_{n}');

$r = FieldCamp::createAccounts($db, $neodb, $camp, 'something_else_{n}', 2, $PW);
check('adding more keeps the template and the numbering', $r['created'] === array('fctest94_4', 'fctest94_5'), $r);
$pkeys = array(); foreach (FieldCamp::listAccounts($db, $campid) as $a) $pkeys[] = (int)$a->user_pkey;
check('5 accounts now', count($pkeys) === 5);

// All or nothing: fctest94_7 is taken, so a batch of 6..8 creates none.
$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values (nextval('users_pkey_seq'), 'x', 'x', 'x', 'x', 'fctest94_7', true)", array());
$before = (int)$db->get_var_prepared("select count(*) from users where email like 'fctest94%'", array());
$plan = FieldCamp::planAccounts($db, $camp, '', 3);
check('plan flags the taken name', $plan['taken'] === 1 && $plan['names'][1]['taken'] === true, $plan);
$r = FieldCamp::createAccounts($db, $neodb, $camp, '', 3, $PW);
$after = (int)$db->get_var_prepared("select count(*) from users where email like 'fctest94%'", array());
check('a taken name refuses the whole batch', $r['error'] !== '' && !$r['created'] && $before === $after, $r);
check('the message names the taken username', strpos($r['error'], 'fctest94_7') !== false, $r['error']);
$db->query("delete from users where email = 'fctest94_7'");

check('a camp account cannot become an instructor', FieldCamp::addInstructor($db, $pkeys[0], '', 3) !== '');

// ===========================================================================
section('Service: instructors of a camp');
check('unknown address is refused', FieldCamp::addViewer($db, $camp, 'nobody-fctest@example.org') !== '');
check('a camp account is refused', FieldCamp::addViewer($db, $camp, 'fctest94_1') !== '');
check('the owner is refused (already has access)', FieldCamp::addViewer($db, $camp, $FIX[$INSTR]) !== '');
check('an existing account is added', FieldCamp::addViewer($db, $camp, ' ' . strtoupper($FIX[$VIEWER]) . ' ') === '');
check('adding it twice is refused', FieldCamp::addViewer($db, $camp, $FIX[$VIEWER]) !== '');
$viewers = FieldCamp::listViewers($db, $campid);
check('one instructor listed', count($viewers) === 1 && (int)$viewers[0]->user_pkey === $VIEWER);
FieldCamp::removeViewer($db, $camp, (int)$viewers[0]->pkey);
check('instructor removed', count(FieldCamp::listViewers($db, $campid)) === 0);

// ===========================================================================
section('Service: deactivate, reactivate, remove');
$db->prepare_query("insert into apptokens (uuid, email) values ('fctest-token', 'fctest94_1')", array());
$n = FieldCamp::setAccountsActive($db, $camp, false, array($pkeys[0], $pkeys[1]));
check('2 chosen accounts deactivated', $n === 2);
check('a deactivated account cannot sign in', !can_sign_in('fctest94_1', $PW));
check('the others still can', can_sign_in('fctest94_3', $PW));
check('its app token is gone', (int)$db->get_var_prepared("select count(*) from apptokens where email = 'fctest94_1'", array()) === 0);
$n = FieldCamp::setAccountsActive($db, $camp, true);
check('reactivate brings both back', $n === 2 && can_sign_in('fctest94_1', $PW));
check('removing while active removes nothing', FieldCamp::removeAccounts($db, $neodb, $camp) === 0 && count(FieldCamp::listAccounts($db, $campid)) === 5);

FieldCamp::setAccountsActive($db, $camp, false, array($pkeys[4]));
$n = FieldCamp::removeAccounts($db, $neodb, $camp);
check('only the deactivated account is removed', $n === 1 && count(FieldCamp::listAccounts($db, $campid)) === 4);
$row = $db->get_row_prepared("select active, deleted from users where pkey = $1", array($pkeys[4]));
check('the removed account is marked deleted', FieldCamp::truthy($row->deleted) && !FieldCamp::truthy($row->active));
check('a removed account stays closed on reactivate', FieldCamp::setAccountsActive($db, $camp, true) === 0 && !can_sign_in('fctest94_5', $PW));
check('a removed account still counts as a camp account', FieldCamp::isCampAccount($db, $pkeys[4]));
$r = FieldCamp::createAccounts($db, $neodb, $camp, '', 1, $PW);
check('numbering does not reuse a removed number', $r['created'] === array('fctest94_6'), $r);

// ===========================================================================
section('HTTP: who may open what');
$sInstr = forge($INSTR, $FIX[$INSTR]);
$sInstr2 = forge($INSTR2, $FIX[$INSTR2]);
$sOut = forge($OUTSIDER, $FIX[$OUTSIDER]);
$sCamp = forge($pkeys[0], 'fctest94_1');

list($code, $body) = http('GET', '/field_camp_wizard', $sInstr);
check('instructor opens the wizard', $code === 200 && strpos($body, 'Test Camp') !== false && strpos($body, 'New field camp') !== false && !php_error($body), $code);
check('instructor has the menu entry', strpos($body, 'href="/field_camp_wizard"') !== false);
check('instructor does not see the admin link', strpos($body, 'admin_field_camp_instructors') === false);
list($code, $body) = http('GET', '/field_camp_wizard', $sOut);
check('outsider gets 404 for the wizard', $code === 404 && strpos($body, 'Test Camp') === false, $code);
list($code, $body) = http('GET', '/my_exports', $sOut);
check('outsider has no menu entry', $code === 200 && strpos($body, 'field_camp_wizard') === false, $code);
list($code) = http('GET', '/field_camp_wizard', null);
check('not signed in is sent to login', $code === 302);
list($code) = http('GET', "/field_camp?id=$campid", $sOut);
check('outsider gets 404 for a camp', $code === 404);
list($code) = http('GET', "/field_camp?id=$campid", $sInstr2);
check('another instructor gets 404 for the camp', $code === 404);
list($code) = http('GET', "/field_camp?id=$campid&ajax=plan&template=x_{n}&count=2", $sInstr2);
check('another instructor gets 404 for the preview', $code === 404);
list($code, $body) = http('GET', '/admin_field_camp_instructors', $sInstr);
check('instructor cannot open the admin page', strpos($body, 'Not authorized') !== false && strpos($body, 'Current instructors') === false);
list($code, $body) = http('POST', '/admin_field_camp_instructors', $sInstr, array('action' => 'add', 'user_pkey' => $OUTSIDER, 'csrf' => $TOKEN));
check('instructor cannot add instructors', !FieldCamp::canUse($db, $OUTSIDER));
list($code) = http('POST', '/field_camp_action', $sOut, array('action' => 'create', 'csrf' => $TOKEN, 'camp_name' => 'Sneaky', 'camp_year' => '2027', 'project_name' => 'p'));
check('outsider cannot create a camp', $code === 404 && (int)$db->get_var_prepared("select count(*) from fieldcamp where camp_name = 'Sneaky'", array()) === 0);
list($code) = http('POST', '/field_camp_action', $sInstr2, array('action' => 'accounts_deactivate', 'csrf' => $TOKEN, 'id' => $campid));
check('another instructor cannot deactivate the camp accounts', $code === 404 && can_sign_in('fctest94_1', $PW));

// ===========================================================================
section('HTTP: admin instructor list');
$adminEmail = $db->get_var_prepared("select email from users where pkey = $1", array(FieldCamp::ADMIN_USERPKEY));
if ($adminEmail == '') {
	check('users.pkey = 3 exists (needed for the admin page checks)', false);
} else {
	$sAdmin = forge(FieldCamp::ADMIN_USERPKEY, $adminEmail);
	list($code, $body) = http('GET', '/admin_field_camp_instructors', $sAdmin);
	check('admin opens the instructor list', $code === 200 && strpos($body, 'Current instructors') !== false && strpos($body, $FIX[$INSTR]) !== false && !php_error($body), $code);
	list($code, $body) = http('GET', '/admin_field_camp_instructors?ajax=usersearch&q=fctest', $sAdmin);
	$found = json_decode($body, true); $emails = array();
	foreach ((array)$found as $f) $emails[] = $f['email'];
	check('search offers normal accounts only (no camp accounts, no current instructors)',
		in_array($FIX[$OUTSIDER], $emails) && !in_array($FIX[$INSTR], $emails) && !in_array('fctest94_1', $emails), $body);
	http('POST', '/admin_field_camp_instructors', $sAdmin, array('action' => 'add', 'user_pkey' => $OUTSIDER, 'note' => 'n', 'csrf' => 'wrong'));
	check('admin add with a wrong form token changes nothing', !FieldCamp::canUse($db, $OUTSIDER));
	http('POST', '/admin_field_camp_instructors', $sAdmin, array('action' => 'add', 'user_pkey' => $OUTSIDER, 'note' => 'n', 'csrf' => $TOKEN));
	check('admin adds an instructor', FieldCamp::canUse($db, $OUTSIDER));
	$ipk = (int)$db->get_var_prepared("select pkey from fieldcamp_instructors where user_pkey = $1", array($OUTSIDER));
	http('POST', '/admin_field_camp_instructors', $sAdmin, array('action' => 'remove', 'pkey' => $ipk, 'csrf' => $TOKEN));
	check('admin removes an instructor', !FieldCamp::canUse($db, $OUTSIDER));
	list($code, $body) = http('GET', '/field_camp_wizard', $sAdmin);
	check('admin sees every camp and the instructor list link', strpos($body, 'Test Camp') !== false && strpos($body, 'admin_field_camp_instructors') !== false);
	list($code, $body) = http('GET', "/field_camp?id=$campid&step=accounts", $sAdmin);
	check('admin can open an instructor\'s camp', $code === 200 && strpos($body, 'fctest94_1') !== false);
}

// ===========================================================================
section('HTTP: pages');
foreach (array('basics' => 'Field camp name', 'area' => 'fcMap', 'accounts' => 'fctest94_1') as $step => $needle) {
	list($code, $body) = http('GET', "/field_camp?id=$campid&step=$step", $sInstr);
	check("$step step renders", $code === 200 && strpos($body, $needle) !== false && !php_error($body), $code);
}
list($code, $body) = http('GET', "/field_camp?id=$campid&step=accounts", $sInstr);
check('the accounts page does not carry the password until it is asked for', strpos($body, $PW) === false && strpos($body, 'id="fcPwEye"') !== false);
list($code, $pwBody) = http('GET', "/field_camp?id=$campid&ajax=password", $sInstr);
check('the eye button gets it for the owner', $code === 200 && json_decode($pwBody, true) === array('password' => $PW), $pwBody);
list($code, $pwBody) = http('GET', "/field_camp?id=$campid&ajax=password", $sOut);
check('not for someone who is not an instructor', $code === 404 && strpos($pwBody, $PW) === false, $code);
list($code, $pwBody) = http('GET', "/field_camp?id=$campid&ajax=password", $sInstr2);
check('not for another instructor', $code === 404 && strpos($pwBody, $PW) === false, $code);
list($code, $pwBody) = http('GET', "/field_camp?id=$campid&ajax=password", null);
check('not without signing in', strpos($pwBody, $PW) === false);
check('accounts step does not list the removed account', strpos($body, 'fctest94_5') === false);
list($code, $body) = http('GET', '/field_camp?new=1', $sInstr);
check('new camp form renders', $code === 200 && strpos($body, 'Create field camp') !== false && !php_error($body));
list($code, $body) = http('GET', "/field_camp?id=$campid&ajax=plan&template=ignored_{n}&count=2", $sInstr);
$plan = json_decode($body, true);
check('preview answers JSON with the next two names', is_array($plan) && $plan['error'] === '' && $plan['names'][0]['username'] === 'fctest94_7' && $plan['names'][1]['username'] === 'fctest94_8', $body);

// ===========================================================================
section('HTTP: the flow');
list($code, $body, $loc) = http('POST', '/field_camp_action', $sInstr, array('action' => 'create', 'csrf' => 'wrong', 'camp_name' => 'No Token Camp', 'camp_year' => '2027', 'project_name' => 'p'));
check('a wrong form token creates nothing', (int)$db->get_var_prepared("select count(*) from fieldcamp where camp_name = 'No Token Camp'", array()) === 0);

list($code, $body, $loc) = post_follow($sInstr, array('action' => 'create', 'camp_name' => '', 'camp_year' => '2027', 'project_name' => 'Kept Project'));
check('create with a missing name returns to the form with the problem', strpos($loc, 'new=1') !== false && strpos($body, 'Enter a name for the field camp') !== false, $loc);
check('the form keeps what was typed', strpos($body, 'value="Kept Project"') !== false);

list($code, $body, $loc) = post_follow($sInstr, array('action' => 'create', 'camp_name' => 'HTTP Camp', 'camp_year' => '2028', 'project_name' => 'HTTP Project'));
$http = $db->get_row_prepared("select * from fieldcamp where camp_name = 'HTTP Camp' and owner_user_pkey = $1", array($INSTR));
check('create saves the camp and moves on to the study area', $http && strpos($loc, 'step=area') !== false && strpos($body, 'fcMap') !== false, $loc);
$hid = $http ? (int)$http->pkey : 0;
check('the study area is drawn as a box, and Next saves it (no separate save)', strpos($body, 'id="fcDrawBtn"') !== false && strpos($body, 'not drawn yet') !== false
	&& strpos($body, 'id="fcAreaForm" data-fc-save="area"') !== false && strpos($body, 'data-fc-next="maps">Next: Maps') !== false && strpos($body, 'this view') === false);

post_follow($sInstr, array('action' => 'basics', 'id' => $hid, 'camp_name' => 'HTTP Camp 2', 'camp_year' => '2028', 'project_name' => 'HTTP Project'));
check('basics are updated', FieldCamp::getCamp($db, $hid)->camp_name === 'HTTP Camp 2');

list($code, $body) = post_follow($sInstr, array('action' => 'area', 'id' => $hid, 'west' => '-110.5', 'south' => '38.2', 'east' => '-110.3', 'north' => '38.4'));
$h = FieldCamp::getCamp($db, $hid);
check('area is saved', abs((float)$h->bbox_west + 110.5) < 1e-6 && abs((float)$h->bbox_north - 38.4) < 1e-6 && strpos($body, 'Study area saved') !== false);
list($code, $body) = post_follow($sInstr, array('action' => 'area', 'id' => $hid, 'west' => '', 'south' => '', 'east' => '', 'north' => ''));
check('an empty area is refused and the saved one stays', strpos($body, 'Draw the study area on the map first') !== false && abs((float)FieldCamp::getCamp($db, $hid)->bbox_west + 110.5) < 1e-6);

list($code, $body) = post_follow($sInstr, array('action' => 'accounts_create', 'id' => $hid, 'username_template' => 'fctest94h_{n}', 'count' => '2', 'password' => $PW));
check('accounts are created over HTTP', strpos($body, '2 accounts created') !== false && can_sign_in('fctest94h_1', $PW) && can_sign_in('fctest94h_2', $PW), substr($body, 0, 200));
check('the password typed over HTTP is on record too', FieldCamp::campPassword(FieldCamp::getCamp($db, $hid)) === $PW);
list($code, $body) = http('GET', "/field_camp?id=$hid&step=accounts", $sInstr);
check('adding accounts no longer asks for the password', strpos($body, 'name="password"') === false && strpos($body, 'get the password the camp') !== false);
list($code, $body) = post_follow($sInstr, array('action' => 'accounts_create', 'id' => $hid, 'count' => '1'));
check('and the new account gets the camp password', count(FieldCamp::listAccounts($db, $hid)) === 3 && can_sign_in('fctest94h_3', $PW));
list($code, $body) = post_follow($sInstr, array('action' => 'accounts_create', 'id' => $hid, 'count' => '1', 'password' => 'another-pass'));
check('a password posted anyway cannot make an account with a different one', count(FieldCamp::listAccounts($db, $hid)) === 4 && can_sign_in('fctest94h_4', $PW) && !can_sign_in('fctest94h_4', 'another-pass'));
// A camp from before passwords were kept: nothing on record.
$db->prepare_query("update fieldcamp set account_password_enc = null where pkey = $1", array($hid));
list($code, $body) = http('GET', "/field_camp?id=$hid&step=accounts", $sInstr);
check('an older camp says the password is not on record and asks for it when adding', strpos($body, 'is not on record') !== false && strpos($body, 'id="fcPwEye"') === false && strpos($body, 'name="password"') !== false);
list($code, $pwBody) = http('GET', "/field_camp?id=$hid&ajax=password", $sInstr);
check('the eye has nothing to show for it', json_decode($pwBody, true) === array('password' => null), $pwBody);
list($code, $body) = post_follow($sInstr, array('action' => 'accounts_create', 'id' => $hid, 'count' => '1', 'password' => 'another-pass'));
check('adding accounts with a different password is refused', strpos($body, 'not the password') !== false && count(FieldCamp::listAccounts($db, $hid)) === 4 && FieldCamp::campPassword(FieldCamp::getCamp($db, $hid)) === null);
list($code, $body) = post_follow($sInstr, array('action' => 'accounts_create', 'id' => $hid, 'count' => '1', 'password' => $PW));
check('adding accounts with the camp password works, and puts it on record', count(FieldCamp::listAccounts($db, $hid)) === 5 && can_sign_in('fctest94h_5', $PW) && FieldCamp::campPassword(FieldCamp::getCamp($db, $hid)) === $PW);

list($code, $body) = post_follow($sInstr, array('action' => 'viewer_add', 'id' => $hid, 'email' => $FIX[$VIEWER]));
check('instructor of the camp added over HTTP', strpos($body, 'Instructor added') !== false && count(FieldCamp::listViewers($db, $hid)) === 1);

list($code, $body) = post_follow($sInstr, array('action' => 'accounts_deactivate', 'id' => $hid));
check('deactivate all over HTTP', strpos($body, '5 accounts deactivated') !== false && !can_sign_in('fctest94h_1', $PW));
list($code, $body) = post_follow($sInstr, array('action' => 'accounts_remove', 'id' => $hid, 'confirm' => 'wrong name'));
check('remove with the wrong camp name removes nothing', strpos($body, 'does not match') !== false && count(FieldCamp::listAccounts($db, $hid)) === 5);
list($code, $body) = post_follow($sInstr, array('action' => 'accounts_reactivate', 'id' => $hid));
check('reactivate over HTTP', strpos($body, '5 accounts reactivated') !== false && can_sign_in('fctest94h_1', $PW));
post_follow($sInstr, array('action' => 'accounts_deactivate', 'id' => $hid));
list($code, $body) = post_follow($sInstr, array('action' => 'accounts_remove', 'id' => $hid, 'confirm' => 'HTTP Camp 2'));
check('remove with the camp name removes the deactivated accounts', strpos($body, '5 accounts removed') !== false && count(FieldCamp::listAccounts($db, $hid)) === 0);

// ===========================================================================
section('Locks on a camp account');
$hashBefore = $db->get_var_prepared("select password from users where pkey = $1", array($pkeys[0]));
list($code, $body) = http('GET', '/change_password', $sCamp);
check('change password page refuses a camp account', $code === 200 && strpos($body, 'field camp account') !== false && strpos($body, 'name="passwordconfirm"') === false, $code);
check('camp account menu has no password / email / delete entries', strpos($body, 'href="/change_password"') === false && strpos($body, 'href="/change_email"') === false && strpos($body, 'href="/delete_account"') === false);
check('camp account menu has no wizard entry', strpos($body, 'field_camp_wizard') === false);
http('POST', '/change_password', $sCamp, array('submit' => 'Submit', 'currentpassword' => $PW, 'password' => 'new-pass-1', 'passwordconfirm' => 'new-pass-1'));
check('posting a new password changes nothing', $db->get_var_prepared("select password from users where pkey = $1", array($pkeys[0])) === $hashBefore && can_sign_in('fctest94_1', $PW));
list($code, $body) = http('GET', '/change_email', $sCamp);
check('change email page refuses a camp account', strpos($body, 'field camp account') !== false && strpos($body, 'name="newemail"') === false);
$hash = $db->get_var_prepared("select hash from users where pkey = $1", array($pkeys[0]));
list($code, $body) = http('POST', '/passwordreset.php', null, array('hash' => $hash, 'submit' => 'Submit', 'password' => 'new-pass-2', 'passwordconfirm' => 'new-pass-2'));
check('a reset link cannot change a camp password', strpos($body, 'field camp account') !== false && $db->get_var_prepared("select password from users where pkey = $1", array($pkeys[0])) === $hashBefore);
$fixHash = 'fctesthash' . $OUTSIDER;
list($code, $body) = http('GET', '/passwordreset.php?hash=' . $fixHash, null);
check('a normal account still gets the reset form', strpos($body, 'field camp account') === false && strpos($body, 'passwordconfirm') !== false, substr($body, 0, 200));
list($code, $body) = http('GET', '/change_password', $sOut);
check('a normal account still gets the change password form', strpos($body, 'field camp account') === false && strpos($body, 'passwordconfirm') !== false);

list($code, $body) = http('GET', '/db/profile', null, null, 'fctest94_1:' . $PW);
check('the Field API accepts a camp account (GET /db/profile)', $code === 200, "$code " . substr($body, 0, 200));
list($code, $body) = http('GET', '/db/profile', null, null, 'fctest94_1:wrong-pass');
check('the Field API refuses a wrong password', $code === 401, $code);
list($code, $body) = http('DELETE', '/db/account', null, null, 'fctest94_1:' . $PW);
$row = $db->get_row_prepared("select active, deleted from users where pkey = $1", array($pkeys[0]));
check('DELETE /db/account is refused for a camp account', $code === 403 && FieldCamp::truthy($row->active) && !FieldCamp::truthy($row->deleted), "$code " . substr($body, 0, 200));

// ===========================================================================
foreach ($sids as $sid) @unlink("$SESS/sess_$sid");
cleanup();
$left = (int)$db->get_var_prepared("select count(*) from users where email like 'fctest%'", array());
check('cleanup leaves no test accounts', $left === 0, $left);

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES: $fail") . "  ($pass passed, $fail failed)\n";
exit($fail === 0 ? 0 : 1);
