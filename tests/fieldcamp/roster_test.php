<?php
/**
 * File: tests/fieldcamp/roster_test.php
 * Description: Suite for the Field Camp Wizard, stage 5: the roster
 *              (includes/fieldcamp/FieldCampRoster.php and the Student work
 *              step of field_camp.php).
 *
 *              A camp with three accounts. Student 1 uploads the camp project
 *              (the id the package gives it) through the real Field API with
 *              two datasets, three spots and a photo that was recorded but
 *              never uploaded; student 2 uploads the camp project with one
 *              spot and also a project of their own; student 3 uploads
 *              nothing. The roster must say exactly that, and only the camp's
 *              owner may see it.
 *
 *              Fixture users 94741-94742 and camp usernames starting
 *              "fctest96_"; everything the suite makes is removed again.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/roster_test.php
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

$INSTR = 94741; $OUTSIDER = 94742;
$FIX = array($INSTR => 'fctest96-instructor@example.org', $OUTSIDER => 'fctest96-outsider@example.org');
$PW = 'camp-pass-96';
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';

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
	$rows = $db->get_results_prepared("select pkey from users where email like 'fctest96\\_%'", array());
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
	$fix = implode(',', array_keys($FIX));
	$rows = $db->get_results_prepared("select pkey from fieldcamp where owner_user_pkey in ($fix)", array());
	if ($rows) {
		$cin = implode(',', array_map(function ($r) { return (int)$r->pkey; }, $rows));
		foreach (array('fieldcamp_account', 'fieldcamp_viewer', 'fieldcamp_map', 'fieldcamp_unit', 'fieldcamp_unit_source') as $t) $db->query("delete from $t where fieldcamp_pkey in ($cin)");
		$db->query("delete from fieldcamp where pkey in ($cin)");
	}
	$db->query("delete from fieldcamp_account where user_pkey in ($in)");
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fix)");
	$db->query("delete from apptokens where email like 'fctest96\\_%'");
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
/** Upload a project with its datasets and spots the way the app does. $datasets: name => [spot arrays]. */
function upload($username, $projectId, $name, $datasets, $seed) {
	$ts = (int)floor(microtime(true) * 1000);
	$codes = array();
	list($codes[]) = api('POST', 'project', $username, array('id' => $projectId, 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts,
		'description' => array('project_name' => $name)));
	$k = 0;
	foreach ($datasets as $dsName => $spots) {
		$dsId = $seed + 10 * (++$k);
		list($codes[]) = api('POST', 'dataset', $username, array('id' => $dsId, 'name' => $dsName, 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts));
		list($codes[]) = api('POST', "projectDatasets/$projectId", $username, array('id' => $dsId));
		$features = array();
		foreach ($spots as $j => $extra) {
			$features[] = array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.27 - $j / 100, 38.55)),
				'properties' => array('id' => $dsId * 100 + $j + 1, 'name' => "spot $j", 'date' => '2027-06-01T15:00:00.000Z', 'modified_timestamp' => $ts) + $extra);
		}
		if ($features) list($codes[]) = api('POST', "datasetspots/$dsId", $username, array('type' => 'FeatureCollection', 'features' => $features));
	}
	return $codes;
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Roster', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fctest96hash' . $pk, $email));
}
FieldCamp::addInstructor($db, $INSTR, 'roster test', 3);
list($clean) = FieldCamp::validateBasics(array('camp_name' => 'Roster Camp', 'camp_year' => '2027', 'project_name' => 'Roster Project'));
$campid = FieldCamp::createCamp($db, $INSTR, $clean);
$camp = FieldCamp::getCamp($db, $campid);

// ===========================================================================
section('An empty camp');
$r = FieldCampRoster::forCamp($neodb, $camp, FieldCamp::listAccounts($db, $campid));
check('no accounts: an empty roster', $r['rows'] === array() && $r['total'] === 0 && $r['uploaded'] === 0);

$made = FieldCamp::createAccounts($db, $neodb, $camp, 'fctest96_{n}', 3, $PW);
check('three accounts created', $made['error'] === '' && count($made['created']) === 3, $made);
list($P) = FieldCampPackage::ensureIds($db, $camp);
$camp = FieldCamp::getCamp($db, $campid);
$accounts = FieldCamp::listAccounts($db, $campid);
$r = FieldCampRoster::forCamp($neodb, $camp, $accounts);
check('nobody has uploaded: three rows without projects', $r['total'] === 3 && $r['uploaded'] === 0 && count($r['rows']) === 3 && $r['rows'][0]['username'] === 'fctest96_1' && $r['rows'][0]['projects'] === array() && $r['spots'] === 0);

// ===========================================================================
section('After uploads through the Field API');
$seed = (int)floor(microtime(true) * 10000);
$before = time();
$imgId = $seed + 7;
$c1 = upload('fctest96_1', $P, 'Roster Project', array(
	'Default' => array(array(), array('images' => array(array('id' => $imgId, 'title' => 'outcrop', 'image_type' => 'photo')))),
	'Day 2' => array(array()),
), $seed + 1000);
$c2 = upload('fctest96_2', $P, 'Roster Project', array('Default' => array(array())), $seed + 2000);
$c2b = upload('fctest96_2', $seed + 5, 'My own side project', array('Mine' => array(array(), array())), $seed + 3000);
$bad = array_filter(array_merge($c1, $c2, $c2b), function ($c) { return $c < 200 || $c >= 300; });
check('the uploads were accepted (two students use the same project id)', !$bad, array($c1, $c2, $c2b));

$r = FieldCampRoster::forCamp($neodb, $camp, $accounts);
$rows = array(); foreach ($r['rows'] as $row) $rows[$row['username']] = $row;
check('two of three students have uploaded the camp project', $r['uploaded'] === 2 && $r['total'] === 3, $r['uploaded']);
$s1 = $rows['fctest96_1']['projects'];
check('student 1: the camp project, 2 datasets, 3 spots, 1 photo', count($s1) === 1 && $s1[0]['camp'] === true && $s1[0]['id'] === (string)$P && $s1[0]['name'] === 'Roster Project'
	&& $s1[0]['datasets'] === 2 && $s1[0]['spots'] === 3 && $s1[0]['photos'] === 1, $s1);
check('the photo that was recorded but never sent is counted as not uploaded', $s1[0]['photos_missing'] === 1, $s1[0]);
check('the last upload is just now', $s1[0]['uploaded'] !== null && abs($s1[0]['uploaded'] - $before) < 120, $s1[0]['uploaded']);
$s2 = $rows['fctest96_2']['projects'];
check('student 2: the camp project first (1 spot), then their own project (2 spots), told apart', count($s2) === 2 && $s2[0]['camp'] === true && $s2[0]['spots'] === 1
	&& $s2[1]['camp'] === false && $s2[1]['name'] === 'My own side project' && $s2[1]['spots'] === 2 && $s2[1]['datasets'] === 1, $s2);
check('the same project id in two accounts is counted per account, not together', $s1[0]['spots'] === 3 && $s2[0]['spots'] === 1);
check('student 3: nothing', $rows['fctest96_3']['projects'] === array());
check('totals: 6 spots, 1 photo', $r['spots'] === 6 && $r['photos'] === 1, array($r['spots'], $r['photos']));

FieldCamp::setAccountsActive($db, $camp, false, array((int)$accounts[2]->user_pkey));
$r = FieldCampRoster::forCamp($neodb, $camp, FieldCamp::listAccounts($db, $campid));
check('a deactivated account stays on the roster, marked', $r['rows'][2]['active'] === false && $r['rows'][0]['active'] === true && $r['total'] === 3);

$db->prepare_query("update fieldcamp set project_id = null, dataset_id = null where pkey = $1", array($campid));
$r = FieldCampRoster::forCamp($neodb, FieldCamp::getCamp($db, $campid), FieldCamp::listAccounts($db, $campid));
check('a camp whose package was never built counts any uploaded project', $r['uploaded'] === 2 && $r['rows'][0]['projects'][0]['camp'] === false);
$db->prepare_query("update fieldcamp set project_id = $2, dataset_id = $3 where pkey = $1", array($campid, $P, $P + 1));

// ===========================================================================
section('HTTP: the Student work step');
$sidI = forge($INSTR, $FIX[$INSTR]);
$sidO = forge($OUTSIDER, $FIX[$OUTSIDER]);
list($code, $body) = web("/field_camp?id=$campid&step=roster", $sidI);
check('the owner opens the step', $code === 200 && preg_match('~<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>~', $body) === 0 && strpos($body, 'Student work') !== false, $code);
check('it states how many have uploaded and the totals', preg_match('~<strong>2</strong> of 3 students have uploaded the camp project:\s*6 spots and\s*1 photo in all~', $body) === 1);
check('one line per student and project', strpos($body, 'fctest96_1') !== false && strpos($body, 'My own side project') !== false && strpos($body, '(not the camp project)') !== false
	&& strpos($body, 'Nothing uploaded yet') !== false && strpos($body, '(deactivated)') !== false && strpos($body, '(1 not uploaded yet)') !== false);
check('it is the last step of the wizard', preg_match('~step=package".*step=roster"~s', $body) === 1);
list($code) = web("/field_camp?id=$campid&step=roster", $sidO);
check('someone not on the instructor list gets 404', $code === 404, $code);
list($code, $body) = web("/field_camp?id=$campid&step=roster", null);
check('not signed in: no roster', strpos($body, 'fctest96_1') === false);
list($clean2) = FieldCamp::validateBasics(array('camp_name' => 'Empty Roster Camp', 'camp_year' => '2027', 'project_name' => 'Empty'));
$emptyid = FieldCamp::createCamp($db, $INSTR, $clean2);
list($code, $body) = web("/field_camp?id=$emptyid&step=roster", $sidI);
check('a camp without accounts says so', $code === 200 && strpos($body, 'This camp has no accounts yet') !== false);

// ===========================================================================
cleanup();
foreach ($sids as $sid) @unlink("$SESS/sess_$sid");
echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
