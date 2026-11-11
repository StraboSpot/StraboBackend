<?php
/**
 * File: tests/fieldcamp/copy_test.php
 * Description: Suite for includes/fieldcamp/FieldCampCopy.php: copying the
 *              projects of deactivated camp accounts into the camp owner's
 *              account under new project, dataset and tag ids.
 *
 *              Two camp accounts upload through the real Field API (Basic
 *              auth, as the app does) a project with the SAME project,
 *              dataset and tag ids, the situation a shared field camp package
 *              creates: project, two datasets, spots (point with a photo,
 *              polygon, line), a geologic unit tag that lists spots. Student 1
 *              uploads the photo file, student 2 never does.
 *
 *              Checked: nothing is copied while an account is active; each
 *              copy has new, distinct project / dataset / tag ids and the
 *              student's name in the project name; every spot of the copy
 *              equals the student's spot (geometry and properties); the tag
 *              still lists the same spots; the photo file is a byte-identical
 *              second file; the student's own data is unchanged; a second run
 *              copies nothing; a failure leaves nothing behind; the HTTP
 *              action and its access rules.
 *
 *              Fixture: users 94711-94712, camp usernames "fctest95_N".
 *              Everything is removed again at the start and the end.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/copy_test.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'db/strabospotclass.php';
require_once 'includes/UUID.php';
require_once 'includes/geophp/geoPHP.inc';
require_once 'includes/fieldcamp/FieldCamp.php';
require_once 'includes/fieldcamp/FieldCampCopy.php';

$INSTR = 94711; $INSTR2 = 94712;
$FIX = array($INSTR => 'fctest95-instructor@example.org', $INSTR2 => 'fctest95-instructor2@example.org');
$PW = 'camp-pass-95';
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$TOKEN = str_repeat('cd34', 8);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

function fixture_pkeys() {
	global $db, $FIX;
	$pks = array_keys($FIX);
	$rows = $db->get_results_prepared("select pkey from users where email like 'fctest95\\_%'", array());
	if ($rows) foreach ($rows as $r) $pks[] = (int)$r->pkey;
	return $pks;
}
function cleanup() {
	global $db, $neodb, $FIX;
	$pks = fixture_pkeys();
	$in = implode(',', $pks);
	$files = array();
	foreach ($neodb->get_results("MATCH (i:Image) WHERE i.userpkey IN [$in] RETURN i.filename AS f") ?: array() as $r) {
		if ($r->get('f') != '') $files[] = (string)$r->get('f');
	}
	foreach (array_unique($files) as $f) { if (preg_match('/^\d+$/', $f) && file_exists("/srv/app/www/dbimages/$f")) unlink("/srv/app/www/dbimages/$f"); }
	$neodb->query("MATCH (n) WHERE n.userpkey IN [$in] DETACH DELETE n");
	$arr = "{" . $in . "}";
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE item_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE image_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = ANY($1)", array($arr));
	$db->query("delete from fieldcamp_copy where target_user_pkey in ($in) or source_user_pkey in ($in)");
	$fix = implode(',', array_keys($FIX));
	$camps = array();
	$rows = $db->get_results_prepared("select pkey from fieldcamp where owner_user_pkey in ($fix)", array());
	if ($rows) foreach ($rows as $r) $camps[] = (int)$r->pkey;
	if ($camps) {
		$cin = implode(',', $camps);
		$db->query("delete from fieldcamp_copy where fieldcamp_pkey in ($cin)");
		$db->query("delete from fieldcamp_account where fieldcamp_pkey in ($cin)");
		$db->query("delete from fieldcamp_viewer where fieldcamp_pkey in ($cin)");
		$db->query("delete from fieldcamp where pkey in ($cin)");
	}
	$db->query("delete from fieldcamp_account where user_pkey in ($in)");
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fix)");
	$rows = $db->get_results_prepared("select uuid from versions where userpkey in ($in)", array());
	if ($rows) foreach ($rows as $r) { if (preg_match('/^[0-9a-f-]{36}$/i', (string)$r->uuid)) @unlink("/srv/app/www/versions/" . $r->uuid); }
	$db->query("delete from versions where userpkey in ($in)");
	$db->query("delete from users where pkey in ($in)");
}

function api($method, $path, $username, $json = null, $multipart = null) {
	global $BASE, $PW;
	$ch = curl_init("$BASE/db/$path");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_USERPWD, "$username:$PW");
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	if ($json !== null) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
	}
	if ($multipart !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array($code, json_decode($body, true), $body);
}
$sids = array();
function forge($pkey, $email) {
	global $SESS, $sids, $TOKEN;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	file_put_contents("$SESS/sess_$sid", 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';fieldcamp_csrf|s:' . strlen($TOKEN) . ':"' . $TOKEN . '";');
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
function web($method, $path, $sid, $post = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 300, CURLOPT_HTTPHEADER => array('Cookie: PHPSESSID=' . $sid)));
	if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	$body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
	return array($code, (string)$body);
}

/** Drop keys that legitimately differ between two accounts' views of the same data. */
function strip($v) {
	if (is_array($v)) {
		$out = array();
		foreach ($v as $k => $x) {
			if ($k === 'self' || $k === 'modified_on_server' || $k === 'uploaddate') continue;
			$out[$k] = strip($x);
		}
		if ($out && array_keys($out) !== range(0, count($out) - 1)) ksort($out);
		return $out;
	}
	return $v;
}
/** Everything the server holds for one project of one account: project, datasets (by name), spots (by id). */
function snapshot($userpkey, $projectId) {
	global $db, $neodb;
	$s = new StraboSpot($neodb, (int)$userpkey, $db);
	$s->setuuid(new UUID());
	$project = json_decode(json_encode($s->getProject($projectId)), true);
	$ds = $s->getProjectDatasets($projectId);
	$out = array('project' => $project, 'datasets' => array());
	foreach (($ds['datasets'] ?? array()) as $d) {
		$spots = $s->getDatasetSpots($d['id']);
		$spots = json_decode(json_encode($spots), true);
		$byId = array();
		foreach (($spots['features'] ?? array()) as $f) $byId[(string)$f['properties']['id']] = strip($f);
		ksort($byId);
		$out['datasets'][$d['name']] = array('id' => (string)$d['id'], 'spots' => $byId);
	}
	ksort($out['datasets']);
	return $out;
}
function image_node($imageId, $pk) {
	global $neodb;
	$r = $neodb->getRecord("MATCH (i:Image) WHERE i.id = $imageId AND i.userpkey = $pk RETURN i.filename AS f LIMIT 1");
	return $r ? (string)$r->get('f') : null;
}

// ---------------------------------------------------------------------------
cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Copy', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fctest95hash' . $pk, $email));
	$neodb->query("CREATE (:User {userpkey: $pk, email: '$email'})");
}
FieldCamp::addInstructor($db, $INSTR, 'test', 3);
FieldCamp::addInstructor($db, $INSTR2, 'test', 3);
list($clean) = FieldCamp::validateBasics(array('camp_name' => 'Copy Camp', 'camp_year' => '2027', 'project_name' => 'Copy Project'));
$campid = FieldCamp::createCamp($db, $INSTR, $clean);
$camp = FieldCamp::getCamp($db, $campid);
$r = FieldCamp::createAccounts($db, $neodb, $camp, 'fctest95_{n}', 3, $PW);
$accounts = FieldCamp::listAccounts($db, $campid);
$S1 = (int)$accounts[0]->user_pkey; $S2 = (int)$accounts[1]->user_pkey; $S3 = (int)$accounts[2]->user_pkey;

// The ids every student shares (what a shared package gives them).
$base = 97950000000000 + mt_rand(0, 9999) * 10000;
$P = $base + 1; $D1 = $base + 2; $D2 = $base + 3; $T1 = $base + 4; $T2 = $base + 5;
$ts = time() * 1000;

$tmpimg = tempnam(sys_get_temp_dir(), 'fc') . '.jpg';
$im = imagecreatetruecolor(12, 12); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 60)); imagejpeg($im, $tmpimg); imagedestroy($im);

/** Upload one student's work: n = 1 or 2 gives that student its own spot and image ids. */
function student_upload($username, $n) {
	global $P, $D1, $D2, $T1, $T2, $ts, $base, $tmpimg;
	$spot = function($k) use ($base, $n) { return $base + 1000 * $n + $k; };
	$img = $base + 1000 * $n + 500;
	$codes = array();
	list($codes[]) = api('POST', 'project', $username, array(
		'id' => $P, 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts,
		'description' => array('project_name' => 'Copy Project', 'notes' => "notes of student $n"),
		'preferences' => array('active_dataset_id' => $D1, 'spot_prefix' => "S$n-"),
		'tags' => array(
			array('id' => (string)$T1, 'name' => 'Morrison Formation', 'type' => 'geologic_unit', 'unit_label_abbreviation' => 'Jm', 'spots' => array($spot(1), $spot(2))),
			array('id' => (string)$T2, 'name' => 'Dakota Sandstone', 'type' => 'geologic_unit', 'spots' => array($spot(3))),
		)));
	list($codes[]) = api('POST', 'dataset', $username, array('id' => $D1, 'name' => 'Default', 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts));
	list($codes[]) = api('POST', "projectDatasets/$P", $username, array('id' => $D1));
	list($codes[]) = api('POST', 'dataset', $username, array('id' => $D2, 'name' => 'Day 2', 'date' => '2027-06-02T12:00:00.000Z', 'modified_timestamp' => $ts));
	list($codes[]) = api('POST', "projectDatasets/$P", $username, array('id' => $D2));
	list($codes[]) = api('POST', "datasetspots/$D1", $username, array('type' => 'FeatureCollection', 'features' => array(
		array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.27 - $n / 100, 39.92)),
			'properties' => array('id' => $spot(1), 'name' => "S$n-1", 'date' => '2027-06-01T15:00:00.000Z', 'modified_timestamp' => $ts,
				'notes' => "outcrop of student $n",
				'orientation_data' => array(array('id' => $spot(11), 'type' => 'planar_orientation', 'strike' => 120 + $n, 'dip' => 35, 'feature_type' => 'bedding')),
				'images' => array(array('id' => $img, 'image_type' => 'photo', 'title' => "photo $n")))),
		array('type' => 'Feature', 'geometry' => array('type' => 'Polygon', 'coordinates' => array(array(array(-105.28, 39.91), array(-105.27, 39.91), array(-105.27, 39.92), array(-105.28, 39.91)))),
			'properties' => array('id' => $spot(2), 'name' => "S$n-2", 'date' => '2027-06-01T16:00:00.000Z', 'modified_timestamp' => $ts)),
	)));
	list($codes[]) = api('POST', "datasetspots/$D2", $username, array('type' => 'FeatureCollection', 'features' => array(
		array('type' => 'Feature', 'geometry' => array('type' => 'LineString', 'coordinates' => array(array(-105.30, 39.90), array(-105.29, 39.91), array(-105.28 + $n / 1000, 39.93))),
			'properties' => array('id' => $spot(3), 'name' => "S$n-3", 'date' => '2027-06-02T15:00:00.000Z', 'modified_timestamp' => $ts,
				'trace' => array('trace_feature' => true, 'trace_type' => 'contact'))),
	)));
	if ($n === 1) {
		list($codes[]) = api('POST', 'image', $username, null, array('id' => (string)$img, 'modified_timestamp' => (string)$ts,
			'image_file' => new CURLFile($tmpimg, 'image/jpeg', "photo_$img.jpg")));
	}
	return $codes;
}

try {

section('Fixture: two students upload a project with the same ids');
$codes = student_upload('fctest95_1', 1);
check('student 1 uploads through the API', !array_diff($codes, array(200, 201)), $codes);
$codes = student_upload('fctest95_2', 2);
check('student 2 uploads through the API', !array_diff($codes, array(200, 201)), $codes);
$before1 = snapshot($S1, $P); $before2 = snapshot($S2, $P);
check('student 1 holds 2 datasets and 3 spots', count($before1['datasets']) === 2 && count($before1['datasets']['Default']['spots']) === 2 && count($before1['datasets']['Day 2']['spots']) === 1, array_keys($before1['datasets']));
check('both students hold the same project and dataset ids', (string)$before1['project']['id'] === (string)$P && (string)$before2['project']['id'] === (string)$P && $before1['datasets']['Default']['id'] === $before2['datasets']['Default']['id']);
$IMG1 = $base + 1500; $IMG2 = $base + 2500;
$srcFile = image_node($IMG1, $S1);
check('student 1 photo is on the server', $srcFile !== null && $srcFile !== '' && is_file("/srv/app/www/dbimages/$srcFile"), $srcFile);

$copier = new FieldCampCopy($db, $neodb);
check('roster counts 1 project for each uploader, none for the third', $copier->projectCounts(array($S1, $S2, $S3)) == array($S1 => 1, $S2 => 1));

// The after-camp order first: accounts deactivated, then copied. (Copying an active account: "Refreshing" below.)
FieldCamp::setAccountsActive($db, $camp, false);
$accounts = FieldCamp::listAccounts($db, $campid);

section('Copy student 1 (service)');
$res = $copier->copyAccount($camp, $accounts[0]);
check('one project copied, no error', $res['error'] === '' && count($res['copied']) === 1 && $res['skipped'] === 0, $res);
$c1 = $res['copied'][0] ?? array();
check('counts: 2 datasets, 3 spots, 1 photo, 0 missing', ($c1['datasets'] ?? 0) === 2 && ($c1['spots'] ?? 0) === 3 && ($c1['images'] ?? 0) === 1 && ($c1['missing_images'] ?? 1) === 0, $c1);
check('the copy is named after the student', ($c1['name'] ?? '') === 'Copy Project (fctest95_1)', $c1);
$NP1 = $c1['project_id'] ?? 0;
check('the copy has a new project id', $NP1 && (string)$NP1 !== (string)$P);

section('Copy student 2 (HTTP action)');
$sInstr = forge($INSTR, $FIX[$INSTR]);
$sInstr2 = forge($INSTR2, $FIX[$INSTR2]);
list($code) = web('POST', '/field_camp_action', $sInstr2, array('csrf' => $TOKEN, 'action' => 'copy_account', 'id' => $campid, 'user' => $S2));
check('another instructor cannot copy from this camp', $code === 404 && count($copier->projectsOf($INSTR2)) === 0, $code);
list($code, $body) = web('POST', '/field_camp_action', $sInstr, array('csrf' => $TOKEN, 'action' => 'copy_account', 'id' => $campid, 'user' => $INSTR2));
$j = json_decode($body, true);
check('an account outside the camp is refused', is_array($j) && $j['error'] !== '' && !$j['copied'], $body);
list($code, $body) = web('GET', "/field_camp?id=$campid&step=accounts", $sInstr);
check('the accounts page offers the copy for what is left', strpos($body, 'fcCopyBtn') !== false && strpos($body, '"username":"fctest95_2"') !== false && strpos($body, '"username":"fctest95_1"') === false && !preg_match('~<b>(Warning|Notice|Deprecated|Fatal error)</b>~', $body));
list($code, $body) = web('POST', '/field_camp_action', $sInstr, array('csrf' => $TOKEN, 'action' => 'copy_account', 'id' => $campid, 'user' => $S2));
$j = json_decode($body, true);
check('HTTP copy answers JSON with one project', $code === 200 && is_array($j) && $j['error'] === '' && count($j['copied']) === 1, "HTTP $code " . substr($body, 0, 300));
$c2 = $j['copied'][0] ?? array();
check('student 2: 3 spots, 0 photos copied, 1 never uploaded', ($c2['spots'] ?? 0) === 3 && ($c2['images'] ?? 1) === 0 && ($c2['missing_images'] ?? 0) === 1, $c2);
$NP2 = $c2['project_id'] ?? 0;

section('The copies in the owner\'s account');
$mine = $copier->projectsOf($INSTR);
check('the owner now holds 2 projects with different ids', count($mine) === 2 && $mine[0]['id'] !== $mine[1]['id'], $mine);
$copy1 = snapshot($INSTR, $NP1); $copy2 = snapshot($INSTR, $NP2);
foreach (array(1 => array($before1, $copy1), 2 => array($before2, $copy2)) as $n => $pair) {
	list($orig, $copy) = $pair;
	check("student $n: same dataset names", array_keys($orig['datasets']) === array_keys($copy['datasets']), array_keys($copy['datasets']));
	foreach ($orig['datasets'] as $dname => $d) {
		$cd = $copy['datasets'][$dname] ?? array('id' => '', 'spots' => array());
		check("student $n, $dname: dataset id is new", $cd['id'] !== '' && $cd['id'] !== $d['id']);
		check("student $n, $dname: every spot equals the student's spot", $cd['spots'] == $d['spots'],
			json_encode(array('orig' => $d['spots'], 'copy' => $cd['spots'])));
	}
	$ot = $orig['project']['tags']; $ct = $copy['project']['tags'] ?? array();
	check("student $n: 2 tags, same names and spot lists", count($ct) === 2 && $ct[0]['name'] === $ot[0]['name'] && $ct[0]['spots'] == $ot[0]['spots'] && $ct[1]['spots'] == $ot[1]['spots'] && ($ct[0]['unit_label_abbreviation'] ?? '') === 'Jm', $ct);
	check("student $n: tag ids are new", (string)$ct[0]['id'] !== (string)$ot[0]['id'] && (string)$ct[1]['id'] !== (string)$ot[1]['id'] && (string)$ct[0]['id'] !== (string)$ct[1]['id']);
	check("student $n: tag ids keep their type (string)", is_string($ct[0]['id']));
	check("student $n: notes and other project fields kept", ($copy['project']['description']['notes'] ?? '') === "notes of student $n" && ($copy['project']['preferences']['spot_prefix'] ?? '') === "S$n-", $copy['project']['description']);
	check("student $n: a preference naming the old dataset now names the new one", (string)($copy['project']['preferences']['active_dataset_id'] ?? '') === $copy['datasets']['Default']['id'], $copy['project']['preferences']);
}
$allIds = array($copy1['datasets']['Default']['id'], $copy1['datasets']['Day 2']['id'], $copy2['datasets']['Default']['id'], $copy2['datasets']['Day 2']['id'],
	(string)$copy1['project']['tags'][0]['id'], (string)$copy1['project']['tags'][1]['id'], (string)$copy2['project']['tags'][0]['id'], (string)$copy2['project']['tags'][1]['id'], (string)$NP1, (string)$NP2);
check('all 10 new ids are different from each other', count(array_unique($allIds)) === 10, $allIds);
check('new ids are 14 digits like the app\'s', !array_filter($allIds, function($i){ return !preg_match('/^\d{14}$/', $i); }), $allIds);

$dstFile = image_node($IMG1, $INSTR);
check('the photo has its own file in the owner\'s account', $dstFile !== null && $dstFile !== '' && $dstFile !== $srcFile && is_file("/srv/app/www/dbimages/$dstFile"), $dstFile);
check('the photo copy is byte-identical', $dstFile && sha1_file("/srv/app/www/dbimages/$dstFile") === sha1_file("/srv/app/www/dbimages/$srcFile"));
$f2 = image_node($IMG2, $INSTR);
check('the never-uploaded photo is a record without a file', $f2 !== null && $f2 === '', var_export($f2, true));

$pg = (int)$db->get_var_prepared("select count(*) from project where user_pkey = $1", array($INSTR));
$pgspots = (int)$db->get_var_prepared("select count(*) from spot s join dataset d on d.dataset_pkey = s.dataset_pkey join project p on p.project_pkey = d.project_pkey where p.user_pkey = $1", array($INSTR));
check('PG mirror: 2 projects, 6 spots for the owner', $pg === 2 && $pgspots === 6, "$pg projects, $pgspots spots");

section('The students\' own data');
check('student 1 is unchanged', snapshot($S1, $P) == $before1);
check('student 2 is unchanged', snapshot($S2, $P) == $before2);
check('student 1 photo file is still there', is_file("/srv/app/www/dbimages/$srcFile") && image_node($IMG1, $S1) === $srcFile);

section('Running it again');
$accounts = FieldCamp::listAccounts($db, $campid);
$res = $copier->copyAccount($camp, $accounts[0]);
check('a second run copies nothing and says so', $res['error'] === '' && !$res['copied'] && $res['skipped'] === 1, $res);
$res = $copier->copyAccount($camp, $accounts[2]);
check('an account with no project copies nothing', $res['error'] === '' && !$res['copied'] && $res['skipped'] === 0, $res);
check('the owner still holds exactly 2 projects', count($copier->projectsOf($INSTR)) === 2);
check('2 copy records', (int)$db->get_var_prepared("select count(*) from fieldcamp_copy where fieldcamp_pkey = $1", array($campid)) === 2);
check('copied counts per account', $copier->copiedCounts($campid) == array($S1 => 1, $S2 => 1));
list($code, $body) = web('GET', "/field_camp?id=$campid&step=accounts", $sInstr);
check('the accounts page no longer offers the copy', $code === 200 && strpos($body, 'fcCopyBtn') === false);

section('Refreshing a copy while the camp runs');
// Student 1 is active again, adds a spot to Day 2 and uploads, the way the app sends a dataset: all its spots.
FieldCamp::setAccountsActive($db, $camp, true, array($S1));
list($code, $cur) = api('GET', "datasetspots/$D2", 'fctest95_1');
$features = $cur['features'] ?? array();
$NEWSPOT = $base + 1000 + 9;
$features[] = array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.25, 39.95)),
	'properties' => array('id' => $NEWSPOT, 'name' => 'S1-9 added later', 'date' => '2027-06-03T15:00:00.000Z', 'modified_timestamp' => $ts + 5000));
list($code) = api('POST', "datasetspots/$D2", 'fctest95_1', array('type' => 'FeatureCollection', 'features' => $features));
$after1 = snapshot($S1, $P);
check('fixture: student 1 now has 2 spots in Day 2', $code >= 200 && $code < 300 && count($after1['datasets']['Day 2']['spots']) === 2, $code);
$stampRow = $db->get_row_prepared("select * from fieldcamp_copy where source_user_pkey = $1", array($S1));
check('the copy is known to be behind', $copier->sourceStamp($S1, $P) !== (string)$stampRow->source_stamp && (string)$stampRow->source_stamp !== '');
check('student 2 has not changed, and the copy knows', $copier->sourceStamp($S2, $P) === (string)$db->get_var_prepared("select source_stamp from fieldcamp_copy where source_user_pkey = $1", array($S2)));
$seqBefore = (int)$db->get_var("select last_value from image_seq");
$accounts = FieldCamp::listAccounts($db, $campid);
$res = $copier->copyAccount($camp, $accounts[0]);
check('an active account is copied: one project, marked as an update', $res['error'] === '' && count($res['copied']) === 1 && $res['copied'][0]['updated'] === true && $res['skipped'] === 0, $res);
check('the refreshed copy is the same project in the owner\'s account', (string)$res['copied'][0]['project_id'] === (string)$NP1 && $res['copied'][0]['spots'] === 4, $res['copied'][0]);
$fresh = snapshot($INSTR, $NP1);
check('with the same dataset ids as before', $fresh['datasets']['Default']['id'] === $copy1['datasets']['Default']['id'] && $fresh['datasets']['Day 2']['id'] === $copy1['datasets']['Day 2']['id']);
check('and the same tag ids', (string)$fresh['project']['tags'][0]['id'] === (string)$copy1['project']['tags'][0]['id'] && (string)$fresh['project']['tags'][1]['id'] === (string)$copy1['project']['tags'][1]['id']);
check('it now holds the new spot, and every spot equals the student\'s', isset($fresh['datasets']['Day 2']['spots'][(string)$NEWSPOT])
	&& $fresh['datasets']['Day 2']['spots'] == $after1['datasets']['Day 2']['spots'] && $fresh['datasets']['Default']['spots'] == $after1['datasets']['Default']['spots']);
check('the photo file was not copied a second time', image_node($IMG1, $INSTR) === $dstFile && is_file("/srv/app/www/dbimages/$dstFile") && (int)$db->get_var("select last_value from image_seq") === $seqBefore);
check('the owner still holds exactly 2 projects', count($copier->projectsOf($INSTR)) === 2);
check('refreshing leaves no version of the replaced copy behind', (int)$db->get_var_prepared("select count(*) from versions where userpkey = $1 and projectid = $2", array($INSTR, (string)$NP1)) === 0);
$rec = $db->get_row_prepared("select * from fieldcamp_copy where source_user_pkey = $1", array($S1));
check('one record per student project, brought up to date', (int)$db->get_var_prepared("select count(*) from fieldcamp_copy where fieldcamp_pkey = $1", array($campid)) === 2
	&& (int)$rec->spot_count === 4 && (string)$rec->updated_date !== '' && (string)$rec->source_stamp === $copier->sourceStamp($S1, $P) && is_array(json_decode($rec->id_map, true)));
check('the student\'s own project is untouched by the refresh', snapshot($S1, $P) == $after1);
$res = $copier->copyAccount($camp, $accounts[0]);
check('nothing new: the next run leaves the copy alone', $res['error'] === '' && !$res['copied'] && $res['skipped'] === 1, $res);
// The student deletes a spot: the refreshed copy loses it too.
array_pop($features);
api('POST', "datasetspots/$D2", 'fctest95_1', array('type' => 'FeatureCollection', 'features' => $features));
$res = $copier->copyAccount($camp, $accounts[0]);
$fresh = snapshot($INSTR, $NP1);
check('a spot the student removed is gone from the refreshed copy', $res['error'] === '' && count($res['copied']) === 1 && !isset($fresh['datasets']['Day 2']['spots'][(string)$NEWSPOT]) && count($fresh['datasets']['Day 2']['spots']) === 1, $res);
// The instructor deletes the copy from their own account: the next run makes it again, under the same id.
$own = new StraboSpot($neodb, $INSTR, $db); $own->setuuid(new UUID());
$own->deleteProject($NP1);
@unlink("/srv/app/www/dbimages/$dstFile");   // the site's project delete leaves photo files on disk
$res = $copier->copyAccount($camp, $accounts[0]);
check('a copy the instructor deleted is made again, with the id it had', $res['error'] === '' && count($res['copied']) === 1 && (string)$res['copied'][0]['project_id'] === (string)$NP1
	&& count($copier->projectsOf($INSTR)) === 2, $res);
$dstFile = image_node($IMG1, $INSTR);
check('with its photo', $dstFile !== null && $dstFile !== '' && is_file("/srv/app/www/dbimages/$dstFile"));
list($code, $body) = web('POST', '/field_camp_action', $sInstr, array('csrf' => $TOKEN, 'action' => 'copy_account', 'id' => $campid, 'user' => $S1));
$j = json_decode($body, true);
check('over HTTP an active account is accepted too (nothing new this time)', $code === 200 && is_array($j) && $j['error'] === '' && $j['skipped'] === 1, substr($body, 0, 200));

section('A failed copy leaves nothing behind');
class BoomCopy extends FieldCampCopy {
	public $seen = 0;
	protected function afterDataset($datasetId) {
		// Fail once both datasets (all spots and the photo) are loaded, like an error near the end.
		$this->seen++;
		if ($this->seen === 2) throw new Exception('boom');
	}
}
$db->query("delete from fieldcamp_copy where source_user_pkey = $S1");
// Take student 1's copy out of the owner's account again (the site's own project delete), to copy it afresh.
$own = new StraboSpot($neodb, $INSTR, $db); $own->setuuid(new UUID());
$own->deleteProject($NP1);
@unlink("/srv/app/www/dbimages/$dstFile");
check('fixture: the owner is back to 1 project', count($copier->projectsOf($INSTR)) === 1);
$nodesBefore = (int)$neodb->get_var("MATCH (n) WHERE n.userpkey = $INSTR RETURN count(n)");
$pgBefore = (int)$db->get_var_prepared("select count(*) from project where user_pkey = $1", array($INSTR));
$seqBefore = (int)$db->get_var("select last_value from image_seq");
$boom = new BoomCopy($db, $neodb);
$res = $boom->copyAccount($camp, $accounts[0]);
check('the failure happened after both datasets were loaded', $boom->seen === 2);
check('a failure is reported and not recorded', $res['error'] !== '' && !$res['copied'] && $copier->copiedCounts($campid) == array($S2 => 1), $res);
check('the message names the student and hides the internal error', strpos($res['error'], 'fctest95_1') !== false && strpos($res['error'], 'boom') === false, $res['error']);
check('no nodes of the half copy are left in the owner\'s account', (int)$neodb->get_var("MATCH (n) WHERE n.userpkey = $INSTR RETURN count(n)") === $nodesBefore);
check('no PG mirror rows of the half copy are left', (int)$db->get_var_prepared("select count(*) from project where user_pkey = $1", array($INSTR)) === $pgBefore);
$seqAfter = (int)$db->get_var("select last_value from image_seq");
$leftFiles = 0; for ($f = $seqBefore + 1; $f <= $seqAfter; $f++) { if (is_file("/srv/app/www/dbimages/$f")) $leftFiles++; }
check('the half copy left no version behind', (int)$db->get_var_prepared("select count(*) from versions where userpkey = $1 and projectid not in ($2, $3)", array($INSTR, (string)$NP1, (string)$NP2)) === 0);
check('no photo file of the half copy is left', $seqAfter > $seqBefore && $leftFiles === 0, "$seqBefore..$seqAfter, $leftFiles left");
check('the student is still unchanged', snapshot($S1, $P) == $before1);
$res = $copier->copyAccount($camp, $accounts[0]);
check('the copy works on the next try', $res['error'] === '' && count($res['copied']) === 1 && $res['copied'][0]['spots'] === 3 && $res['copied'][0]['images'] === 1, $res);
check('the owner holds exactly 2 projects again', count($copier->projectsOf($INSTR)) === 2);

} finally {
	section('cleanup');
	foreach ($sids as $sid) @unlink("$SESS/sess_$sid");
	@unlink($tmpimg);
	$pks = implode(',', fixture_pkeys());
	cleanup();
	$left = (int)$neodb->get_var("MATCH (n) WHERE n.userpkey IN [$pks] RETURN count(n)");
	check('cleanup leaves no nodes', $left === 0, $left);
}

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES: $fail") . "  ($pass passed, $fail failed)\n";
exit($fail === 0 ? 0 : 1);
