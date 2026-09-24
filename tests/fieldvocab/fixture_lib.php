<?php
/**
 * File: tests/fieldvocab/fixture_lib.php
 * Description: Shared harness for the Field choice translation suites: bootstrap,
 *              check()/section(), HTTP (Basic / forged session / none), the golden fixture
 *              upload exactly like the StraboField app (gf_upload) and a full cleanup
 *              (gf_cleanup). Used by golden_guard.php and the per-phase label tests.
 *
 *              Needs the test users from tests/collaboration/setup_test_data.php.
 *              Set $SID_PREFIX before including to name the forged session file.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('memory_limit', '1G');
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/geophp/geoPHP.inc';
require_once 'includes/UUID.php';
require_once 'db/strabospotclass.php';
require_once 'includes/straboClasses/straboOutputClass.php';
require_once 'includes/fieldvocab/FieldVocab.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require_once __DIR__ . '/golden_fixture.php';

$EMAIL = 'owner@test.strabospot.org';
$UP = "$EMAIL:testpass123";
$DOI_UUID = '97791000-0000-4000-8000-000000000001';
$SESSDIR = '/var/lib/php/sessions';
$SID = (isset($SID_PREFIX) ? $SID_PREFIX : 'fvgolden') . getmypid();

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        $detail" : '') . "\n";
	if (!$cond) $failures[] = $label;
}
function section($t) { echo "\n== $t\n"; }

$OWNER = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($EMAIL));
if ($OWNER <= 0) { echo "Test user $EMAIL not found. Run tests/collaboration/setup_test_data.php first.\n"; exit(1); }

/* ------------------------------------------------------------------ http */

function http($method, $path, $body = null, $auth = 'basic') {
	global $UP, $SID;
	$out = tempnam(sys_get_temp_dir(), 'fvg_');
	$cmd = 'curl -s -o ' . escapeshellarg($out) . " -w '%{http_code}' -X " . escapeshellarg($method) . ' ';
	if ($auth === 'basic') $cmd .= '-u ' . escapeshellarg($UP) . ' ';
	if ($auth === 'session') $cmd .= '-H ' . escapeshellarg("Cookie: PHPSESSID=$SID") . ' ';
	if ($body !== null) $cmd .= '-H ' . escapeshellarg('Content-Type: application/json') . ' --data-binary ' . escapeshellarg(json_encode($body)) . ' ';
	$cmd .= escapeshellarg('http://localhost' . $path);
	$status = (int)shell_exec($cmd);
	$text = (string)file_get_contents($out);
	@unlink($out);
	return array($status, $text);
}

function forge_session() {
	global $SESSDIR, $SID, $OWNER, $EMAIL;
	$p = 'loggedin|' . serialize('yes') . 'LAST_ACTIVITY|' . serialize(time()) . 'userpkey|' . serialize((string)$OWNER)
		. 'username|' . serialize($EMAIL) . 'loggedin_username|' . serialize($EMAIL) . 'firstname|' . serialize('Golden')
		. 'lastname|' . serialize('Guard') . 'userlevel|' . serialize('user');
	$f = "$SESSDIR/sess_$SID";
	file_put_contents($f, $p);
	@chown($f, 'www-data');
	@chmod($f, 0600);
}

function fresh_strabo() {
	global $neodb, $db, $OWNER;
	$s = new StraboSpot($neodb, $OWNER, $db);   // fresh per surface: getDatasetSpotsSearch makes isarc sticky
	$s->setuuid(new UUID());
	return $s;
}

/* --------------------------------------------------------------- cleanup */

function gf_cleanup() {
	global $neodb, $db, $OWNER, $DOI_UUID, $SESSDIR, $SID;
	// Uploaded image binaries live under their sequence filename (a restore makes another copy).
	foreach ($neodb->query("MATCH (i:Image) WHERE i.userpkey = $OWNER AND i.id >= " . GF_MIN . " AND i.id <= " . GF_MAX . " RETURN i.filename AS fn") ?: array() as $r) {
		$fn = (string)$r->get('fn');
		if ($fn !== '' && ctype_digit($fn)) @unlink("/srv/app/www/dbimages/$fn");
	}
	foreach (isset($GLOBALS['IMAGE_FILES']) ? $GLOBALS['IMAGE_FILES'] : array() as $fn) {
		if ($fn !== '' && ctype_digit($fn)) @unlink("/srv/app/www/dbimages/$fn");
	}
	foreach (array('Image', 'Sample', 'Spot', 'Dataset', 'Project') as $label) {
		$neodb->query("MATCH (n:$label) WHERE n.userpkey = $OWNER AND n.id >= " . GF_MIN . " AND n.id <= " . GF_MAX . " DETACH DELETE n");
	}
	foreach ($db->get_results_prepared("SELECT uuid FROM versions WHERE projectid = $1", array((string)GF_PROJECT)) ?: array() as $r) {
		$u = is_object($r) ? $r->uuid : $r['uuid'];
		if (preg_match('/^[0-9a-f-]{36}$/', $u)) @unlink("/srv/app/www/versions/$u");
	}
	$db->prepare_query("DELETE FROM versions WHERE projectid = $1", array((string)GF_PROJECT));
	$db->prepare_query("DELETE FROM verlog WHERE projectid = $1 AND userpkey = $2", array((string)GF_PROJECT, $OWNER));
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND project_id = $1", array((string)GF_PROJECT));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_subsystem = 'field' AND project_id = $1", array((string)GF_PROJECT));
	$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1 AND id LIKE '97791%'", array($OWNER));
	$db->query("DELETE FROM sample  WHERE user_pkey = $OWNER AND strabo_sample_id LIKE '97791%'");
	$db->query("DELETE FROM spot    WHERE user_pkey = $OWNER AND strabo_spot_id LIKE '97791%'");
	$db->query("DELETE FROM dataset WHERE user_pkey = $OWNER AND strabo_dataset_id LIKE '97791%'");
	$db->query("DELETE FROM project WHERE user_pkey = $OWNER AND strabo_project_id LIKE '97791%'");
	$doiDir = "/srv/app/www/doi/doiFiles/$DOI_UUID";
	if (is_dir($doiDir)) exec('rm -rf ' . escapeshellarg($doiDir));
	// forged sessions of any fieldvocab suite run (killed runs included): the prefixes are ours
	foreach (array_merge(glob("$SESSDIR/sess_fvgolden*") ?: array(), glob("$SESSDIR/sess_fvfbook*") ?: array(), glob("$SESSDIR/sess_$SID") ?: array()) as $f) @unlink($f);
	// the :User node gf_upload creates carries a marker name; remove it once it owns nothing (a killed run loses the flag)
	$neodb->query("MATCH (u:User {userpkey: $OWNER}) WHERE u.firstname = 'Golden' AND u.lastname = 'Guard' AND NOT (u)-[:HAS_PROJECT]->() DETACH DELETE u");
	foreach (glob('/srv/app/www/versions/images/97791*') as $f) @unlink($f);
}

/** Upload the golden fixture the way the app does (project, datasets, spots, image binary) + forge the owner session. */
function gf_upload() {
	global $neodb, $OWNER, $EMAIL, $UP;
	// Real accounts get a :User node at registration; the fixture user may not have one, and
	// without it insertProject cannot link HAS_PROJECT (the search sync walks that edge).
	$GLOBALS['CREATED_USER_NODE'] = false;
	if ((int)$neodb->get_var("MATCH (u:User {userpkey: $OWNER}) RETURN count(u)") === 0) {
		$neodb->createNode(json_encode(array('userpkey' => $OWNER, 'email' => $EMAIL, 'firstname' => 'Golden', 'lastname' => 'Guard')), 'User');
		$GLOBALS['CREATED_USER_NODE'] = true;
	}
	list($st, $tx) = http('POST', '/db/project', gf_project());
	check('POST /db/project', $st >= 200 && $st < 300, "$st $tx");
	check('project owned via HAS_PROJECT', (int)$neodb->get_var("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project {id: " . GF_PROJECT . "}) RETURN count(p)") === 1);
	foreach (gf_datasets() as $dsid => $name) {
		list($st, $tx) = http('POST', '/db/dataset', array('id' => $dsid, 'name' => $name, 'modified_timestamp' => GF_TS, 'date' => GF_DATE));
		check("POST /db/dataset $dsid", $st >= 200 && $st < 300, "$st $tx");
		list($st, $tx) = http('POST', '/db/projectDatasets/' . GF_PROJECT, array('id' => $dsid));
		check("POST /db/projectDatasets $dsid", $st >= 200 && $st < 300, "$st $tx");
	}
	foreach (gf_features() as $dsid => $features) {
		list($st, $tx) = http('POST', "/db/datasetspots/$dsid", array('type' => 'FeatureCollection', 'features' => $features));
		check("POST /db/datasetspots $dsid (" . count($features) . ' spots)', $st >= 200 && $st < 300, "$st " . substr($tx, 0, 300));
	}
	// The app uploads each image binary after its spot (POST /db/image, multipart); that sets
	// the filename the search indexer and createVersion need.
	$jpg = sys_get_temp_dir() . '/fvg_image_' . getmypid() . '.jpg';
	$im = imagecreatetruecolor(64, 48); imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 40, 120, 200)); imagejpeg($im, $jpg, 80); imagedestroy($im);
	$out = array(); exec('curl -s -o /dev/null -w "%{http_code}" -u ' . escapeshellarg($UP) . ' -F id=977910000401 -F ' . escapeshellarg("image_file=@$jpg;type=image/jpeg") . ' http://localhost/db/image', $out);
	@unlink($jpg);
	check('POST /db/image (binary for the fixture image)', (int)($out[0] ?? 0) === 201, 'HTTP ' . ($out[0] ?? '?'));
	forge_session();
}
