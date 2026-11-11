<?php
/**
 * File: tests/fieldcamp/book_test.php
 * Description: Suite for the Field Camp Wizard's Field Book links (Student
 *              work step): fieldbook_build.php?camp=&account=&project= builds
 *              the Field Book of one student's project straight from the
 *              student's camp account (FieldCampRoster::book, FieldbookBuild
 *              run_as), without a copy.
 *
 *              A camp with three accounts. Students 1 and 2 upload the camp
 *              project with the SAME project, dataset and spot ids (what a
 *              shared package gives them) but different spot names, daily
 *              notes and a private memo each; student 3 uploads a dataset
 *              without spots. Each book must hold that student's work only,
 *              and only whoever may manage the camp may build one.
 *
 *              Fixture users 94795-94796 and camp usernames starting
 *              "fctest97_"; everything the suite makes is removed again.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/book_test.php
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
require_once 'includes/fieldbook/FieldbookBuild.php';

$INSTR = 94795; $OUTSIDER = 94796;
$FIX = array($INSTR => 'fctest97-instructor@example.org', $OUTSIDER => 'fctest97-outsider@example.org');
$PW = 'camp-pass-97';
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
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
	$rows = $db->get_results_prepared("select pkey from users where email like 'fctest97\\_%'", array());
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
	$db->query("delete from apptokens where email like 'fctest97\\_%'");
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
	file_put_contents("$SESS/sess_$sid", 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';');
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
function noisy($body) { return preg_match('~<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>~', $body) === 1; }
/** Every page stream of a PDF, inflated: the text the book prints (its strings are UTF-16BE, so the zero bytes go). */
function pdfText($pdf) {
	$out = '';
	if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) foreach ($m[1] as $s) { $u = @gzuncompress($s); if ($u !== false) $out .= $u . "\n"; }
	return str_replace("\0", '', $out);
}
/**
 * Upload the camp project the way the app does: one dataset, the given spot names, a daily note and a private memo.
 * The book files a spot under the day in its id (the first ten digits, a unix time), so the daily note gets that day.
 */
function uploadWork($username, $projectId, $dsId, array $spotNames, $word) {
	$ts = (int)floor(microtime(true) * 1000);
	$codes = array();
	list($codes[]) = api('POST', 'project', $username, array('id' => $projectId, 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts,
		'description' => array('project_name' => 'Book Project', 'daily_setup' => array(array('date' => date('Y-m-d', (int)substr((string)$dsId, 0, 10)) . 'T15:00:00.000Z', 'notes' => $word . 'DAILY'))),
		'reports' => array(array('id' => $ts, 'subject' => $word . 'MEMO', 'report_type' => 'general', 'privacy' => 'only_me', 'notes' => 'for my eyes', 'created_timestamp' => $ts, 'updated_timestamp' => $ts))));
	list($codes[]) = api('POST', 'dataset', $username, array('id' => $dsId, 'name' => 'Default', 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts));
	list($codes[]) = api('POST', "projectDatasets/$projectId", $username, array('id' => $dsId));
	$features = array();
	foreach ($spotNames as $j => $name) {
		$features[] = array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.27 - $j / 100, 38.55)),
			'properties' => array('id' => $dsId * 100 + $j + 1, 'name' => $name, 'date' => '2027-06-01T15:00:00.000Z', 'time' => '2027-06-01T15:00:00.000Z', 'modified_timestamp' => $ts));
	}
	if ($features) list($codes[]) = api('POST', "datasetspots/$dsId", $username, array('type' => 'FeatureCollection', 'features' => $features));
	return $codes;
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Book', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fctest97hash' . $pk, $email));
	$neodb->query("CREATE (:User {userpkey: $pk, email: '$email'})");
}
FieldCamp::addInstructor($db, $INSTR, 'book test', 3);
FieldCamp::addInstructor($db, $OUTSIDER, 'book test', 3);
list($clean) = FieldCamp::validateBasics(array('camp_name' => 'Book Camp', 'camp_year' => '2027', 'project_name' => 'Book Project'));
$campid = FieldCamp::createCamp($db, $INSTR, $clean);
$camp = FieldCamp::getCamp($db, $campid);
$made = FieldCamp::createAccounts($db, $neodb, $camp, 'fctest97_{n}', 3, $PW);
check('three accounts created', $made['error'] === '' && count($made['created']) === 3, $made);
list($P) = FieldCampPackage::ensureIds($db, $camp);
$camp = FieldCamp::getCamp($db, $campid);
$accounts = FieldCamp::listAccounts($db, $campid);
$A1 = (int)$accounts[0]->user_pkey; $A2 = (int)$accounts[1]->user_pkey; $A3 = (int)$accounts[2]->user_pkey;

$DS = (int)floor(microtime(true) * 10000) + 10;
$c1 = uploadWork('fctest97_1', $P, $DS, array('ALPHAONE', 'ALPHATWO', 'ALPHATHREE'), 'ALPHA');
$c2 = uploadWork('fctest97_2', $P, $DS, array('BRAVOONE'), 'BRAVO');
$c3 = uploadWork('fctest97_3', $P, $DS, array(), 'CHARLIE');
$bad = array_filter(array_merge($c1, $c2, $c3), function ($c) { return $c < 200 || $c >= 300; });
check('the uploads were accepted (same project, dataset and spot ids in three accounts)', !$bad, array($c1, $c2, $c3));

// ===========================================================================
section('FieldCampRoster::book: who may have which book');
$b = FieldCampRoster::book($db, $neodb, $INSTR, $campid, $A1, $P);
check('the owner, student 1: the dataset, run as the student, labelled with the username', $b && $b['dsids'] === array((string)$DS) && $b['run_as'] === $A1 && $b['label'] === 'fctest97_1' && $b['camp'] === (int)$campid && $b['project'] === (string)$P, $b);
check('the admin may too', FieldCampRoster::book($db, $neodb, FieldCamp::ADMIN_USERPKEY, $campid, $A2, $P) !== null);
check('another instructor (not the owner): no', FieldCampRoster::book($db, $neodb, $OUTSIDER, $campid, $A1, $P) === null);
check('a student about a fellow student: no', FieldCampRoster::book($db, $neodb, $A2, $campid, $A1, $P) === null);
check('nobody signed in (99999 / 0): no', FieldCampRoster::book($db, $neodb, 99999, $campid, $A1, $P) === null && FieldCampRoster::book($db, $neodb, 0, $campid, $A1, $P) === null);
check('an account that is not of this camp: no', FieldCampRoster::book($db, $neodb, $INSTR, $campid, $OUTSIDER, $P) === null && FieldCampRoster::book($db, $neodb, $INSTR, $campid, 3, $P) === null);
check('a project id that is not a number: no', FieldCampRoster::book($db, $neodb, $INSTR, $campid, $A1, '1 or 1=1') === null && FieldCampRoster::book($db, $neodb, $INSTR, $campid, $A1, '') === null);
$b = FieldCampRoster::book($db, $neodb, $INSTR, $campid, $A1, '123');
check('a project the student does not have: allowed but empty', $b !== null && $b['dsids'] === array(), $b);
check('an unknown camp: no', FieldCampRoster::book($db, $neodb, $INSTR, 0, $A1, $P) === null);
$db->prepare_query("update fieldcamp_account set removed_date = now() where user_pkey = $1", array($A2));
check('an account removed from the camp: no', FieldCampRoster::book($db, $neodb, $INSTR, $campid, $A2, $P) === null);
$db->prepare_query("update fieldcamp_account set removed_date = null where user_pkey = $1", array($A2));

// ===========================================================================
section('Student work step: the links');
$sidI = forge($INSTR, $FIX[$INSTR]);
$sidO = forge($OUTSIDER, $FIX[$OUTSIDER]);
list($code, $body) = web("/field_camp?id=$campid&step=roster", $sidI);
check('the step has a Field Book column and says what it is', $code === 200 && !noisy($body) && strpos($body, '<td>Field Book</td>') !== false && strpos($body, 'For a quick look without a copy') !== false, $code);
check('a link for each student with spots, naming camp, account and project', strpos($body, 'href="/fieldbook_build?camp=' . $campid . '&amp;account=' . $A1 . '&amp;project=' . $P . '" target="_blank"') !== false
	&& strpos($body, '/fieldbook_build?camp=' . $campid . '&amp;account=' . $A2 . '&amp;project=' . $P . '"') !== false);
check('no link for the project without spots', strpos($body, '&amp;account=' . $A3 . '&amp;') === false && substr_count($body, 'No spots yet') === 1 && substr_count($body, 'class="fc-book"') === 2);

// ===========================================================================
section('The build page through the camp door');
$url1 = "/fieldbook_build?camp=$campid&account=$A1&project=$P";
$url2 = "/fieldbook_build?camp=$campid&account=$A2&project=$P";
list($code, $body, $loc) = http($url1, null);
check('not signed in: sent to the login page', $code === 302 && strpos($loc, 'login') !== false, "$code $loc");
list($code, $body) = http($url1, $sidO);
check('another instructor: the no-work page, no build', $code === 200 && strpos($body, 'id="fb-nodata"') !== false && keyOf($body) === '' && strpos($body, 'fctest97_1') === false && !noisy($body), $code);
list($code, $body) = http("/fieldbook_build?camp=$campid&account=$OUTSIDER&project=$P", $sidI);
check('the owner asking for an account outside the camp: no build', $code === 200 && strpos($body, 'id="fb-nodata"') !== false && keyOf($body) === '');
list($code, $body) = http("/fieldbook_build?userpkey=$A1&dsids=$DS", $sidI);
check('the usual door still refuses the student dataset to the instructor', $code === 200 && strpos($body, 'id="fb-nodata"') !== false && keyOf($body) === '');
list($code, $body) = http("/fieldbook_build?camp=$campid&account=$A1&project=$P&userpkey=$A2&dsids=999", $sidI);
$kx = keyOf($body);
list($code, $body) = http($url1, $sidI);
$k1 = keyOf($body); $keys[] = $k1;
check('the owner: the progress page in run mode, titled with the username', $code === 200 && modeOf($body) === 'run' && $k1 !== '' && strpos($body, '(fctest97_1)') !== false && !noisy($body), $code . ' ' . modeOf($body));
check('userpkey / dsids in the URL change nothing', $kx === $k1, $kx);
check('no direct download is offered, and the way back is the Student work step', strpos($body, '/searchdownload') === false && strpos($body, '/field_camp?id=' . $campid . '&amp;step=roster') !== false);
$st = FieldbookBuild::load($k1);
check('the queued build belongs to the instructor and is set to run as the student', $st && $st['state'] === 'queued' && (int)$st['userpkey'] === $INSTR && (int)$st['run_as'] === $A1 && (int)$st['camp'] === (int)$campid
	&& (string)$st['project'] === (string)$P && $st['label'] === 'fctest97_1' && $st['params']['dsids'] === (string)$DS && (int)$st['params']['userpkey'] === $A1, $st);
list($code, $body) = http($url2, $sidI);
$k2 = keyOf($body); $keys[] = $k2;
check('student 2 (same project and dataset ids): a build of its own', $k2 !== '' && $k2 !== $k1 && (int)FieldbookBuild::load($k2)['run_as'] === $A2);

// ===========================================================================
section('Building and reading the books');
list($code, $body) = http("/fieldbook_run?k=$k1", $sidO);
check('another user cannot run the build', $code === 404 && json_decode($body, true) === array('found' => false), "$code $body");
$db->prepare_query("delete from fieldcamp_instructors where user_pkey = $1", array($INSTR));
list($code, $body) = http("/fieldbook_run?k=$k1", $sidI);
check('access is checked again at build time: off the instructor list, the queued build is refused', $code === 404 && FieldbookBuild::load($k1)['state'] === 'queued', "$code $body");
FieldCamp::addInstructor($db, $INSTR, 'book test', 3);
$nodes = function () use ($neodb, $A1, $A2) { return (int)$neodb->get_var("MATCH (n) WHERE n.userpkey IN [$A1, $A2] RETURN count(n)"); };
$nodesBefore = $nodes();
list($code, $body) = http("/fieldbook_run?k=$k1", $sidI);
$d = json_decode($body, true);
check('the owner builds student 1\'s book', $code === 200 && $d && $d['state'] === 'done' && $d['bytes'] > 1000, $body);
list($code, $pdf1, $loc, $h) = http("/fieldbook_fetch?k=$k1", $sidI);
check('it is a PDF named after the student', $code === 200 && substr($pdf1, 0, 5) === '%PDF-' && preg_match('/filename="fctest97_1_[^"]*_fieldbook_[0-9-]+\.pdf"/', $h) === 1, "$code " . substr($h, 0, 300));
$t1 = pdfText($pdf1);
check('student 1\'s three spots are in it', strpos($t1, 'ALPHAONE') !== false && strpos($t1, 'ALPHATWO') !== false && strpos($t1, 'ALPHATHREE') !== false, strlen($t1));
check('nothing of student 2 is in it', strpos($t1, 'BRAVO') === false);
check('the title and owner line carry the username', strpos($t1, 'fctest97_1') !== false);
check('the student\'s daily note is in it (read as the student)', strpos($t1, 'ALPHADAILY') !== false);
check('the student\'s own memo is in it', strpos($t1, 'ALPHAMEMO') !== false);
list($code, $body) = http("/fieldbook_fetch?k=$k1", $sidO);
check('nobody else can fetch it', $code === 404, $code);
list($code, $body) = http("/fieldbook_run?k=$k2", $sidI);
$d = json_decode($body, true);
list($code, $pdf2, $loc, $h) = http("/fieldbook_fetch?k=$k2", $sidI);
$t2 = pdfText($pdf2);
check('student 2\'s book: their one spot, their note, nothing of student 1', $d && $d['state'] === 'done' && strpos($t2, 'BRAVOONE') !== false && strpos($t2, 'BRAVODAILY') !== false && strpos($t2, 'ALPHA') === false
	&& preg_match('/filename="fctest97_2_/', $h) === 1, substr((string)$body, 0, 200));
check('building books changed nothing in the students\' accounts', $nodes() === $nodesBefore, array($nodesBefore, $nodes()));
list($code, $body) = http("/fieldbook_build?camp=$campid&account=$A3&project=$P", $sidI);
$k3 = keyOf($body); $keys[] = $k3;
list($code, $body) = http("/fieldbook_run?k=$k3", $sidI);
$d = json_decode($body, true);
check('a project without spots (asked for by hand): the build says so', $d && $d['state'] === 'failed' && strpos($d['error'], 'No spots') !== false, $body);
list($code, $body) = http("/fieldbook_build?camp=$campid&account=$A1&project=123", $sidI);
check('a project the student does not have: the no-work page with the way back', strpos($body, 'id="fb-nodata"') !== false && strpos($body, 'Back to Student work') !== false && keyOf($body) === '');

// ===========================================================================
foreach ($keys as $k) if ($k !== '') FieldbookBuild::remove($k);
cleanup();
foreach ($sids as $sid) @unlink("$SESS/sess_$sid");
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
