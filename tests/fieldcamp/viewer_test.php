<?php
/**
 * File: tests/fieldcamp/viewer_test.php
 * Description: Suite for co-instructor access in the Field Camp Wizard
 *              (2026-11-11): an account on a camp's Instructors list
 *              (fieldcamp_viewer) may open the wizard and sees that camp's
 *              Student work step, read-only, with the Field Book links
 *              (FieldCamp::canUse / canSetUp / canRun / canView / isViewer,
 *              field_camp_wizard.php, field_camp.php, FieldCampRoster::book).
 *              Everything else stays closed to them: the other steps, the
 *              camp password, the package, every action, starting a camp,
 *              other camps.
 *
 *              Fixture users 94785-94788 and camp usernames starting
 *              "fctest93_"; everything the suite makes is removed again.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/viewer_test.php
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
require_once 'includes/fieldbook/FieldbookBuild.php';

// The owner; a co-instructor who is NOT on the instructor list; a plain account; a second instructor with a camp of their own.
$INSTR = 94785; $CO = 94786; $OUTSIDER = 94787; $INSTR2 = 94788; $ADMIN = FieldCamp::ADMIN_USERPKEY;
$FIX = array($INSTR => 'fctest93-instructor@example.org', $CO => 'fctest93-co@example.org', $OUTSIDER => 'fctest93-outsider@example.org', $INSTR2 => 'fctest93-instructor2@example.org');
$PW = 'camp-pass-93';
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$TOKEN = str_repeat('3e9a', 8);
$keys = array();

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
	$rows = $db->get_results_prepared("select pkey from users where email like 'fctest93\\_%'", array());
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
	$db->query("delete from fieldcamp_viewer where user_pkey in ($fix)");
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fix)");
	$db->query("delete from apptokens where email like 'fctest93\\_%'");
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
/** Page / endpoint request with headers: [code, body, Location, headers]. */
function http($path, $sid, $timeout = 300) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $sid ? array('Cookie: PHPSESSID=' . $sid) : array()));
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$h = substr((string)$raw, 0, $hs); $b = substr((string)$raw, $hs);
	return array($code, $b, preg_match('/^Location:\s*(.+)$/mi', $h, $m) ? trim($m[1]) : '', $h);
}
function keyOf($body) { return preg_match('/var fbKey = "([a-f0-9]{40})"/', $body, $m) ? $m[1] : ''; }
function modeOf($body) { return preg_match('/var fbMode = "(\w+)"/', $body, $m) ? $m[1] : ''; }
/** Every page stream of a PDF, inflated: the text the book prints (its strings are UTF-16BE, so the zero bytes go). */
function pdfText($pdf) {
	$out = '';
	if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) foreach ($m[1] as $s) { $u = @gzuncompress($s); if ($u !== false) $out .= $u . "\n"; }
	return str_replace("\0", '', $out);
}
function uploadWork($username, $projectId, $dsId, array $spotNames) {
	$ts = (int)floor(microtime(true) * 1000);
	$codes = array();
	list($codes[]) = api('POST', 'project', $username, array('id' => $projectId, 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts, 'description' => array('project_name' => 'Viewer Project')));
	list($codes[]) = api('POST', 'dataset', $username, array('id' => $dsId, 'name' => 'Default', 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts));
	list($codes[]) = api('POST', "projectDatasets/$projectId", $username, array('id' => $dsId));
	$features = array();
	foreach ($spotNames as $j => $name) $features[] = array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.27 - $j / 100, 38.55)),
		'properties' => array('id' => $dsId * 100 + $j + 1, 'name' => $name, 'date' => '2027-06-01T15:00:00.000Z', 'time' => '2027-06-01T15:00:00.000Z', 'modified_timestamp' => $ts));
	if ($features) list($codes[]) = api('POST', "datasetspots/$dsId", $username, array('type' => 'FeatureCollection', 'features' => $features));
	return $codes;
}
/** What the wizard holds of a camp, to prove a refused request changed nothing. */
function campFacts($campid) {
	global $db;
	$c = FieldCamp::getCamp($db, $campid, true);
	return array($c ? $c->camp_name : null, $c ? (string)$c->deleted_date : null, count(FieldCamp::listAccounts($db, $campid)), count(FieldCamp::listViewers($db, $campid)),
		(int)$db->get_var_prepared("select count(*) from fieldcamp", array()), (int)$db->get_var_prepared("select count(*) from fieldcamp_copy where fieldcamp_pkey = $1", array((int)$campid)));
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Viewer', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fctest93hash' . $pk, $email));
	$neodb->query("CREATE (:User {userpkey: $pk, email: '$email'})");
}
FieldCamp::addInstructor($db, $INSTR, 'viewer test', 3);
FieldCamp::addInstructor($db, $INSTR2, 'viewer test', 3);
list($clean) = FieldCamp::validateBasics(array('camp_name' => 'Viewer Camp', 'camp_year' => '2027', 'project_name' => 'Viewer Project'));
$campid = FieldCamp::createCamp($db, $INSTR, $clean);
$camp = FieldCamp::getCamp($db, $campid);
$made = FieldCamp::createAccounts($db, $neodb, $camp, 'fctest93_{n}', 3, $PW);
check('three accounts created in the camp', $made['error'] === '' && count($made['created']) === 3, $made);
list($P) = FieldCampPackage::ensureIds($db, $camp);
$camp = FieldCamp::getCamp($db, $campid);
$A = array(); foreach (FieldCamp::listAccounts($db, $campid) as $a) $A[] = (int)$a->user_pkey;
list($clean2) = FieldCamp::validateBasics(array('camp_name' => 'Other Camp', 'camp_year' => '2027', 'project_name' => 'Other Project'));
$camp2id = FieldCamp::createCamp($db, $INSTR2, $clean2);
$camp2 = FieldCamp::getCamp($db, $camp2id);
$made2 = FieldCamp::createAccounts($db, $neodb, $camp2, 'fctest93_other{n}', 1, $PW);
list($P2) = FieldCampPackage::ensureIds($db, $camp2);
$B = (int)FieldCamp::listAccounts($db, $camp2id)[0]->user_pkey;
$DS = (int)floor(microtime(true) * 10000) + 10;
$bad = array_filter(array_merge(uploadWork('fctest93_1', $P, $DS, array('ALPHAONE', 'ALPHATWO')), uploadWork('fctest93_2', $P, $DS, array('BRAVOONE')),
	uploadWork('fctest93_other1', $P2, $DS + 5, array('OTHERONE'))), function ($c) { return $c < 200 || $c >= 300; });
check('students 1 and 2 uploaded spots, student 3 nothing; the other camp has a student with a spot', !$bad && count($made2['created']) === 1, $bad);
$sidI = forge($INSTR, $FIX[$INSTR]); $sidC = forge($CO, $FIX[$CO]); $sidO = forge($OUTSIDER, $FIX[$OUTSIDER]); $sidI2 = forge($INSTR2, $FIX[$INSTR2]);
$rosterUrl = "/field_camp?id=$campid&step=roster";
$bookUrl = "/fieldbook_build?camp=$campid&account={$A[0]}&project=$P";

// ===========================================================================
section('Before the owner adds them: nothing');
check('not on any list: cannot use the wizard, cannot view the camp', !FieldCamp::canUse($db, $CO) && !FieldCamp::canView($db, $camp, $CO) && !FieldCamp::isViewer($db, $camp, $CO));
list($code) = web('/field_camp_wizard', $sidC);
list($code2) = web($rosterUrl, $sidC);
check('the wizard and the camp answer 404', $code === 404 && $code2 === 404, array($code, $code2));
list($code, $body) = web('/my_field_data', $sidC);
check('no menu entry', $code === 200 && strpos($body, 'field_camp_wizard') === false, $code);
check('no Field Book door', FieldCampRoster::book($db, $neodb, $CO, $campid, $A[0], $P) === null);

// ===========================================================================
section('The owner adds a co-instructor');
list($code, $body) = post($sidI, array('action' => 'viewer_add', 'id' => $campid, 'email' => $FIX[$CO]));
check('added on the Accounts step, which says what they get', strpos($body, 'Instructor added') !== false && strpos($body, $FIX[$CO]) !== false && !noisy($body)
	&& strpos($body, 'see its Student work step') !== false && strpos($body, 'They cannot change the camp') !== false, $code);
check('the co-instructor may open the wizard and view this camp', FieldCamp::canUse($db, $CO) && FieldCamp::isViewer($db, $camp, $CO) && FieldCamp::canView($db, $camp, $CO));
check('but not set up camps, run this one, or manage it', !FieldCamp::canSetUp($db, $CO) && !FieldCamp::canRun($db, $camp, $CO) && !FieldCamp::canManage($camp, $CO));
check('and not view another camp', !FieldCamp::canView($db, $camp2, $CO) && !FieldCamp::isViewer($db, $camp2, $CO));
check('the owner and the admin run the camp as before', FieldCamp::canRun($db, $camp, $INSTR) && FieldCamp::canRun($db, $camp, $ADMIN) && FieldCamp::canView($db, $camp, $INSTR) && !FieldCamp::canRun($db, $camp, $INSTR2) && !FieldCamp::canView($db, $camp, $INSTR2));
$list = FieldCamp::listCamps($db, $CO);
check('their camp list holds this camp only', count($list) === 1 && (int)$list[0]->pkey === (int)$campid, count($list));

// ===========================================================================
section('The wizard\'s first page for a co-instructor');
list($code, $body) = web('/field_camp_wizard', $sidC);
check('it opens, with the menu entry', $code === 200 && !noisy($body) && strpos($body, '<li><a href="/field_camp_wizard">Field Camp Wizard</a></li>') !== false, $code);
check('the camp is listed as someone else\'s, with a Student work button', strpos($body, 'Viewer Camp') !== false && strpos($body, 'Set up by ' . $FIX[$INSTR] . '; you see the student work') !== false
	&& strpos($body, 'href="/field_camp?id=' . $campid . '&amp;step=roster" class="button small">Student work</a>') !== false);
check('no New field camp, no admin buttons, no other camp', strpos($body, 'New field camp') === false && strpos($body, 'field_camp?new=1') === false && strpos($body, 'Instructor list') === false
	&& strpos($body, 'Deleted field camps') === false && strpos($body, 'Other Camp') === false && strpos($body, 'The field camps you are an instructor of') !== false);
list($code, $body) = web('/field_camp_wizard', $sidI);
check('the owner\'s first page is as before', strpos($body, 'New field camp') !== false && strpos($body, 'href="/field_camp?id=' . $campid . '" class="button small">Open</a>') !== false && strpos($body, 'you see the student work') === false);

// ===========================================================================
section('The camp for a co-instructor: Student work, read-only');
list($code, $body) = web($rosterUrl, $sidC);
check('the step opens and says who set the camp up', $code === 200 && !noisy($body) && strpos($body, '<h4>Student work</h4>') !== false && strpos($body, 'You are an instructor of this field camp, set up by ' . $FIX[$INSTR]) !== false, $code);
check('the students and what they uploaded', strpos($body, 'fctest93_1') !== false && strpos($body, 'fctest93_2') !== false && strpos($body, 'fctest93_3') !== false
	&& strpos($body, '<strong>2</strong> of 3 students have uploaded the camp project') !== false && strpos($body, '3 spots') !== false && strpos($body, 'Nothing uploaded yet') !== false);
check('Field Book links for the two students with spots', substr_count($body, 'class="fc-book"') === 2 && strpos($body, 'href="/fieldbook_build?camp=' . $campid . '&amp;account=' . $A[0] . '&amp;project=' . $P . '"') !== false);
check('no copy column, button or text', strpos($body, 'Your copy') === false && strpos($body, "Owner's copy") === false && strpos($body, 'fcCopyBtn') === false && strpos($body, 'copy_account') === false
	&& strpos($body, 'Not copied') === false && strpos($body, 'copy it into') === false && strpos($body, 'My Field Data</a>') === false);
check('the only tabs are the camp list and Student work', strpos($body, 'step=basics') === false && strpos($body, 'step=accounts') === false && strpos($body, 'step=package') === false
	&& strpos($body, 'step=area') === false && strpos($body, 'step=tiles') === false && strpos($body, 'step=roster" class="fc-step-on">Student work</a>') !== false);
check('no form and no form token on the page', strpos($body, '<form') === false && strpos($body, 'name="csrf"') === false && strpos($body, $TOKEN) === false);
$ok = true; $seen = array();
foreach (array('', '&step=basics', '&step=area', '&step=maps', '&step=tiles', '&step=units', '&step=accounts', '&step=package', '&step=basics&delete=1', '&step=accounts&remove=' . $A[0]) as $q) {
	list($code, $b) = web("/field_camp?id=$campid$q", $sidC);
	$fine = $code === 200 && !noisy($b) && strpos($b, '<h4>Student work</h4>') !== false && strpos($b, '<form') === false && strpos($b, 'Password of every account') === false
		&& strpos($b, 'Delete this field camp') === false && strpos($b, 'field_camp_package') === false;
	if (!$fine) { $ok = false; $seen[] = $q . ' ' . $code; }
}
check('every other step asked for by address shows Student work instead', $ok, $seen);
list($code, $body) = web("/field_camp?id=$campid&ajax=password", $sidC);
list($codeO, $bodyO) = web("/field_camp?id=$campid&ajax=password", $sidI);
check('the camp password is not given to them (the owner gets it)', $code === 200 && trim($body) === '[]' && strpos($body, $PW) === false && (json_decode($bodyO, true)['password'] ?? '') === $PW, "$body / $bodyO");
list($code, $body) = web("/field_camp?id=$campid&ajax=plan&template=" . rawurlencode('fctest93_x{n}') . "&count=2", $sidC);
list($code2, $body2) = web("/field_camp?id=$campid&ajax=build", $sidC);
check('nor any other ajax answer', trim($body) === '[]' && trim($body2) === '[]', "$body / $body2");
list($code) = web('/field_camp?new=1', $sidC);
list($code2) = web("/field_camp?id=$camp2id&step=roster", $sidC);
list($code3) = web("/field_camp_package?id=$campid", $sidC);
list($code4) = web('/admin_field_camp_instructors', $sidC);
check('a new camp, another camp and the package answer 404; the admin page stays closed', $code === 404 && $code2 === 404 && $code3 === 404 && strpos((string)web('/admin_field_camp_instructors', $sidC)[1], 'Not authorized') !== false, array($code, $code2, $code3, $code4));

// ===========================================================================
section('No action is open to a co-instructor');
$before = campFacts($campid);
$codes = array();
foreach (array(
	array('action' => 'create', 'camp_name' => 'Sneaky Camp', 'camp_year' => '2027', 'project_name' => 'Sneaky'),
	array('action' => 'basics', 'id' => $campid, 'camp_name' => 'Renamed', 'camp_year' => '2027', 'project_name' => 'x'),
	array('action' => 'viewer_add', 'id' => $campid, 'email' => $FIX[$OUTSIDER]),
	array('action' => 'copy_account', 'id' => $campid, 'user' => $A[0]),
	array('action' => 'camp_delete', 'id' => $campid, 'confirm' => 'Viewer Camp', 'nocopy' => '1'),
	array('action' => 'account_remove', 'id' => $campid, 'user' => $A[0], 'confirm' => 'fctest93_1', 'nocopy' => '1'),
	array('action' => 'camp_restore', 'id' => $campid),
) as $f) { list($c) = post($sidC, $f); $codes[$f['action']] = $c; }
check('create, basics, viewer_add, copy_account, camp_delete, account_remove, camp_restore: all 404', array_unique(array_values($codes)) === array(404), $codes);
check('and nothing changed', campFacts($campid) === $before && (new FieldCampCopy($db, $neodb))->projectsOf($CO) === array(), array($before, campFacts($campid)));

// ===========================================================================
section('Field Book for a co-instructor');
$b = FieldCampRoster::book($db, $neodb, $CO, $campid, $A[0], $P);
check('the door is open for a student of the camp', $b && $b['dsids'] === array((string)$DS) && $b['run_as'] === $A[0] && $b['label'] === 'fctest93_1', $b);
check('not for a student of another camp, by either camp id', FieldCampRoster::book($db, $neodb, $CO, $camp2id, $B, $P2) === null && FieldCampRoster::book($db, $neodb, $CO, $campid, $B, $P2) === null);
list($code, $body) = http("/fieldbook_build?userpkey={$A[0]}&dsids=$DS", $sidC);
check('the usual door still refuses the student dataset', $code === 200 && strpos($body, 'id="fb-nodata"') !== false && keyOf($body) === '');
list($code, $body) = http($bookUrl, $sidC);
$k1 = keyOf($body); $keys[] = $k1;
check('the build page starts a build titled with the username', $code === 200 && modeOf($body) === 'run' && $k1 !== '' && strpos($body, '(fctest93_1)') !== false && !noisy($body), $code . ' ' . modeOf($body));
$nodes = function () use ($neodb, $A) { return (int)$neodb->get_var("MATCH (n) WHERE n.userpkey IN [" . implode(',', $A) . "] RETURN count(n)"); };
$nodesBefore = $nodes();
list($code, $body) = http("/fieldbook_run?k=$k1", $sidC);
$d = json_decode($body, true);
check('the book builds', $code === 200 && $d && $d['state'] === 'done' && $d['bytes'] > 1000, $body);
list($code, $pdf, $loc, $h) = http("/fieldbook_fetch?k=$k1", $sidC);
$t = pdfText($pdf);
check('it is the student\'s book: their spots, nobody else\'s', $code === 200 && substr($pdf, 0, 5) === '%PDF-' && strpos($t, 'ALPHAONE') !== false && strpos($t, 'ALPHATWO') !== false && strpos($t, 'BRAVO') === false
	&& preg_match('/filename="fctest93_1_/', $h) === 1, "$code " . strlen($t));
check('the students\' accounts are untouched', $nodes() === $nodesBefore, array($nodesBefore, $nodes()));
list($code, $body) = http("/fieldbook_build?camp=$camp2id&account=$B&project=$P2", $sidC);
check('no book of another camp\'s student over HTTP', $code === 200 && strpos($body, 'id="fb-nodata"') !== false && keyOf($body) === '' && strpos($body, 'fctest93_other1') === false);

// ===========================================================================
section('The owner still has everything');
list($code, $body) = web($rosterUrl, $sidI);
check('Student work with the copy column and button, all tabs', $code === 200 && !noisy($body) && strpos($body, '<td>Your copy</td>') !== false && strpos($body, 'fcCopyBtn') !== false && strpos($body, 'step=accounts') !== false
	&& strpos($body, 'For a quick look without a copy') !== false && strpos($body, 'You are an instructor of this field camp') === false);
list($code, $body) = web("/field_camp?id=$campid&step=accounts", $sidI);
check('the Accounts step lists the co-instructor with Remove', strpos($body, $FIX[$CO]) !== false && strpos($body, 'viewer_remove') !== false && !noisy($body));

// ===========================================================================
section('An instructor who is also a co-instructor of another camp');
check('the owner adds the second instructor', FieldCamp::addViewer($db, $camp, $FIX[$INSTR2]) === '');
list($code, $body) = web('/field_camp_wizard', $sidI2);
check('first page: own camp to open, the other as Student work, and New field camp', strpos($body, 'New field camp') !== false && strpos($body, 'href="/field_camp?id=' . $camp2id . '" class="button small">Open</a>') !== false
	&& strpos($body, 'href="/field_camp?id=' . $campid . '&amp;step=roster" class="button small">Student work</a>') !== false && !noisy($body));
list($code, $body) = web("/field_camp?id=$campid&step=accounts", $sidI2);
list($code2, $body2) = web("/field_camp?id=$camp2id&step=accounts", $sidI2);
check('the shared camp is read-only for them, their own camp is whole', strpos($body, '<h4>Student work</h4>') !== false && strpos($body, '<form') === false && strpos($body2, 'Add an instructor by email') !== false);
list($c) = post($sidI2, array('action' => 'basics', 'id' => $campid, 'camp_name' => 'Renamed', 'camp_year' => '2027', 'project_name' => 'x'));
check('and they cannot change the shared camp', $c === 404 && FieldCamp::getCamp($db, $campid)->camp_name === 'Viewer Camp', $c);
$db->prepare_query("delete from fieldcamp_instructors where user_pkey = $1", array($INSTR2));
list($code) = web("/field_camp?id=$camp2id", $sidI2);
list($code2, $body2) = web('/field_camp_wizard', $sidI2);
list($code3) = web($rosterUrl, $sidI2);
check('taken off the instructor list: their own camp is closed to them as before, the shared camp stays', !FieldCamp::canSetUp($db, $INSTR2) && FieldCamp::canUse($db, $INSTR2) && $code === 404 && $code3 === 200
	&& strpos($body2, 'Other Camp') === false && strpos($body2, 'Viewer Camp') !== false && strpos($body2, 'New field camp') === false, array($code, $code2, $code3));
list($c) = post($sidI2, array('action' => 'basics', 'id' => $camp2id, 'camp_name' => 'Renamed', 'camp_year' => '2027', 'project_name' => 'x'));
check('and no action on their own camp either', $c === 404 && FieldCamp::getCamp($db, $camp2id)->camp_name === 'Other Camp', $c);
FieldCamp::addInstructor($db, $INSTR2, 'viewer test', 3);
foreach (FieldCamp::listViewers($db, $campid) as $v) if ((int)$v->user_pkey === $INSTR2) FieldCamp::removeViewer($db, $camp, (int)$v->pkey);

// ===========================================================================
section('Access ends with the list entry, and with the camp');
list($code, $body) = http($bookUrl . '&again=1', $sidC);
$k2 = keyOf($body); $keys[] = $k2;
$vp = 0; foreach (FieldCamp::listViewers($db, $campid) as $v) if ((int)$v->user_pkey === $CO) $vp = (int)$v->pkey;
list($code, $body) = post($sidI, array('action' => 'viewer_remove', 'id' => $campid, 'viewer' => $vp));
check('the owner removes the co-instructor', $vp > 0 && count(FieldCamp::listViewers($db, $campid)) === 0 && !FieldCamp::canUse($db, $CO) && !FieldCamp::canView($db, $camp, $CO));
list($code) = web('/field_camp_wizard', $sidC);
list($code2) = web($rosterUrl, $sidC);
list($code3, $body3) = web('/my_field_data', $sidC);
check('the wizard, the camp and the menu entry are gone', $code === 404 && $code2 === 404 && strpos($body3, 'field_camp_wizard') === false, array($code, $code2));
list($code, $body) = http($bookUrl, $sidC);
check('no new Field Book', $code === 200 && strpos($body, 'id="fb-nodata"') !== false && keyOf($body) === '');
if ($k2 !== '' && $k2 !== $k1) {
	list($code, $body) = http("/fieldbook_run?k=$k2", $sidC);
	check('a build queued before the removal is refused when it runs', $code === 404 && FieldbookBuild::load($k2)['state'] === 'queued', "$code $body");
} else {
	check('a build queued before the removal is refused when it runs', FieldCampRoster::book($db, $neodb, $CO, $campid, $A[0], $P) === null);
}
check('re-added', FieldCamp::addViewer($db, $camp, $FIX[$CO]) === '' && FieldCamp::canUse($db, $CO));
list($code, $body, $loc) = post($sidI, array('action' => 'camp_delete', 'id' => $campid, 'confirm' => 'Viewer Camp', 'nocopy' => '1'));
check('the owner deletes the camp', FieldCamp::getCamp($db, $campid) === null, $loc);
list($code) = web('/field_camp_wizard', $sidC);
list($code2) = web($rosterUrl, $sidC);
check('a deleted camp gives its co-instructor nothing', !FieldCamp::canUse($db, $CO) && $code === 404 && $code2 === 404 && FieldCampRoster::book($db, $neodb, $CO, $campid, $A[0], $P) === null, array($code, $code2));
check('a plain account never had anything', !FieldCamp::canUse($db, $OUTSIDER) && web('/field_camp_wizard', $sidO)[0] === 404 && web($rosterUrl, $sidO)[0] === 404);

// ===========================================================================
foreach ($keys as $k) if ($k !== '') FieldbookBuild::remove($k);
cleanup();
foreach ($sids as $sid) @unlink("$SESS/sess_$sid");
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
