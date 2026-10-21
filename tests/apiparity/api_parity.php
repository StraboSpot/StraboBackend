<?php
/**
 * File: api_parity.php
 * Description: API parity suite for the PHP 8.4 image. Runs the SAME request
 *              sequence (reads, realistic writes, read-backs, deletes, error
 *              paths) against two servers that share the dev databases, one
 *              after the other, and compares every response:
 *                A = dev strabo-php  (PHP 7.4)  PARITY_A, default http://localhost
 *                B = strabo-php8     (PHP 8.4)  PARITY_B, default http://strabo-php8
 *              Status code, content type and a normalized body are compared
 *              (JSON: keys sorted, volatile values masked; binary: magic bytes
 *              + size class; text/html: digits stripped, hashed).
 *
 *              APIs covered: /db + /jwtdb (StraboField), /microdb + /jwtmicrodb,
 *              /expdb, /samplesdb + /samplesjwtdb, /versionsdb, /searchdb,
 *              /microsync/v1, /voiceworker/v1, /db/voice*, /jwtauth, REST/,
 *              /exportjobs/api.php and the routed public JSON pages.
 *
 *              Writes use dedicated dev test accounts only (never real
 *              data): apiparity@test.strabospot.org (created on first run),
 *              maya.chen@test.strabospot.org for the voice upload, and
 *              outsider@test.strabospot.org for access-denied checks. All
 *              objects use the 977950... id range and are removed after each
 *              side runs (also before, in case an earlier run died).
 *
 *              Usage (dev only):
 *                docker exec strabo-php php /srv/app/www/tests/apiparity/api_parity.php [--only=field,micro,...] [-v]
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('memory_limit', '2G');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';

$BASES = array('A' => getenv('PARITY_A') ?: 'http://localhost', 'B' => getenv('PARITY_B') ?: 'http://strabo-php8');
$opts = getopt('v', array('only:', 'side:', 'compare'));
$VERBOSE = isset($opts['v']);
$ONLY = isset($opts['only']) ? explode(',', $opts['only']) : null;

$TEST_EMAIL = 'apiparity@test.strabospot.org';
$TEST_PASS = 'parity-pass-123';
$TEST = "$TEST_EMAIL:$TEST_PASS";
$MAYA_EMAIL = 'maya.chen@test.strabospot.org';
$MAYA = "$MAYA_EMAIL:demopass123";
$OUTSIDER = 'outsider@test.strabospot.org:testpass123';

// Client-chosen ids (identical on both sides; each side cleans up before the other runs)
define('P_MIN', 97795000000000);
define('P_MAX', 97795999999999);
$ID = array(
	'project' => 97795000000001, 'ds1' => 97795000000002, 'ds2' => 97795000000003,
	's1' => 97795000000011, 's2' => 97795000000012, 's3' => 97795000000013, 's4' => 97795000000014,
	's5' => 97795000000015, 's6' => 97795000000016,
	'image' => 97795000000021, 'tag1' => 97795000000031, 'tag2' => 97795000000032, 'tag3' => 97795000000033,
	'sample' => 97795000000041,
);
$MICRO_FIXTURE_SRC = '/srv/app/www/straboMicroFiles/787/project.zip';
$MICRO_SID = '97795000000787';   // numeric like the legacy app's ids (the DELETE route casts the id to int)
$SAMPLE_ID = '97795000000051';
$TS = 1761000000000;

/* ------------------------------------------------------------------ setup */

function ensure_test_user() {
	global $db, $neodb, $TEST_EMAIL, $TEST_PASS;
	$pk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($TEST_EMAIL));
	if ($pk <= 0) {
		$hash = crypt($TEST_PASS, '$1$' . substr(md5(uniqid(rand(), true)), 0, 8));
		$db->prepare_query("INSERT INTO users (email, password, firstname, lastname, hash, active, deleted) VALUES ($1, $2, 'Api', 'Parity', $3, true, false)",
			array($TEST_EMAIL, $hash, md5(uniqid('', true))));
		$pk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($TEST_EMAIL));
	}
	if ((int)$neodb->get_var("MATCH (u:User {userpkey: $pk}) RETURN count(u)") === 0) {
		$neodb->createNode(json_encode(array('userpkey' => $pk, 'email' => $TEST_EMAIL, 'firstname' => 'Api', 'lastname' => 'Parity')), 'User');
	}
	return $pk;
}

/* ------------------------------------------------------------------- http */

/**
 * $o: auth ('user:pass' Basic), bearer (token), jar (cookie file), json (array/object body),
 *     raw (string body), multipart (array, CURLFile values allowed), headers (array), timeout
 */
function req($base, $method, $path, $o = array()) {
	if (getenv('PARITY_TRACE')) fwrite(STDERR, "REQ $method $path\n");
	$ch = curl_init($base . $path);
	$h = isset($o['headers']) ? $o['headers'] : array();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_TIMEOUT, isset($o['timeout']) ? $o['timeout'] : 180);
	if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
	if (!empty($o['auth'])) curl_setopt($ch, CURLOPT_USERPWD, $o['auth']);
	if (!empty($o['bearer'])) $h[] = 'Authorization: Bearer ' . $o['bearer'];
	if (!empty($o['jar'])) { curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']); curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']); }
	if (array_key_exists('json', $o)) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json'])); }
	if (isset($o['raw'])) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, $o['raw']); }
	if (isset($o['multipart'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $o['multipart']);
	if (isset($o['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($o['form']));
	if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
	$resp = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	curl_close($ch);
	$resp = (string)$resp;
	$body = substr($resp, $hs);
	return array('code' => $code, 'ctype' => strtolower(trim(explode(';', $ctype)[0])), 'body' => $body, 'json' => json_decode($body, true));
}

/* -------------------------------------------------------------- normalize */

$VOLATILE = '/(time|date|stamp|uuid|token|hash|_at$|^at$|pkey|^pid$|seq|nonce|expires|elapsed|duration|secs|etag|filesize|bytes|size$|^key$|sharekey|signature|checksum|sha|^n_|count_ms|took|^ms$|_ms$|version_id|^created|^modified|^updated|^last_|access_token|refresh|^jti$|^iat$|^exp$|^sub$|^filename$|^deleted$|^facility_id$)/i';

function mask($v, $k = '') {
	global $VOLATILE;
	if (is_array($v)) {
		$out = array();
		foreach ($v as $kk => $vv) $out[$kk] = mask($vv, is_string($kk) ? $kk : $k);
		if (array_keys($v) !== range(0, count($v) - 1)) { ksort($out); return $out; }
		// lists of records come back in no fixed order from Neo4j: sort them by their id
		$key = function ($e) { return (is_array($e) ? (isset($e['properties']['id']) ? $e['properties']['id'] : (isset($e['id']) ? $e['id'] : '')) : '') . '|' . json_encode($e); };
		if (count(array_filter($out, 'is_array')) === count($out)) usort($out, function ($x, $y) use ($key) { return strcmp((string)$key($x), (string)$key($y)); });
		return $out;
	}
	if ($k !== '' && preg_match($VOLATILE, $k)) return '<v>';
	if (is_string($v)) {
		// server-generated values embedded in strings
		$v = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<uuid>', $v);
		$v = preg_replace('/\b20\d\d-\d\d-\d\d[T ]\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:?\d\d)?\b/', '<ts>', $v);
		$v = preg_replace('/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', '<jwt>', $v);
		$v = preg_replace('#straboMicroFiles/\d+/#', 'straboMicroFiles/<id>/', $v);
		// GEOS 3.9 (7.4 image) and 3.13 (8.4 image) write WKT with a different number of digits:
		// compare coordinates rounded to 9 decimals
		$v = preg_replace_callback('/-?\d+\.\d{10,}/', function ($m) { return rtrim(rtrim(sprintf('%.9f', (float)$m[0]), '0'), '.'); }, $v);
	}
	return $v;
}

function sizeclass($n) { return $n === 0 ? '0' : (string)(int)floor(log($n, 2)); }

function norm($r) {
	$b = $r['body'];
	if ($r['json'] !== null || trim($b) === 'null') return 'JSON:' . json_encode(mask($r['json']));
	if ($b === '') return 'EMPTY';
	$c = $r['ctype'];
	if (preg_match('#^(image/|application/(zip|pdf|octet-stream|x-zip|vnd\.|x-sqlite|gzip|x-gzip|geopackage)|audio/)#', $c)
		|| preg_match('/[\x00-\x08\x0E-\x1F]/', substr($b, 0, 512))) {
		return 'BIN:' . bin2hex(substr($b, 0, 4)) . ':' . sizeclass(strlen($b));
	}
	// a PHP fatal: compare the message, not the stack trace (frame format differs between versions)
	if (preg_match('/<b>Fatal error<\/b>:\s*(.*?)(Stack trace|in \/srv)/s', $b, $fm)) return 'FATAL:' . md5(preg_replace('/\s+/', ' ', strip_tags($fm[1])));
	$b = preg_replace('#<address>.*?</address>#s', '', $b);   // Apache version banner
	$t = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '', $b);
	$t = preg_replace('/\d+/', '', $t);
	$t = preg_replace('/\s+/', ' ', $t);
	return 'TEXT:' . md5($t) . ':' . sizeclass(strlen($b)) . ':' . substr(trim(strip_tags($t)), 0, 80);
}

/* ---------------------------------------------------------------- record */

$RUN = array();
$SIDE = '';
function rec($label, $r, $maskKeys = array()) {
	global $RUN, $SIDE, $VERBOSE;
	if ($maskKeys && is_array($r['json'])) { foreach ($maskKeys as $k) if (array_key_exists($k, $r['json'])) $r['json'][$k] = '<v>'; }
	$n = norm($r);
	$RUN[$SIDE][$label] = array('code' => $r['code'], 'ctype' => $r['ctype'], 'norm' => $n, 'raw' => substr($r['body'], 0, 600),
		'm' => $r['json'] !== null ? mask($r['json']) : null);
	if ($VERBOSE) echo "  [$SIDE] {$r['code']} $label " . substr($n, 0, 100) . "\n";
	return $r;
}
function want($s) { global $ONLY; return $ONLY === null || in_array($s, $ONLY); }

/* -------------------------------------------------------------- fixtures */

function point($x, $y) { return array('type' => 'Point', 'coordinates' => array($x, $y)); }
function feature($id, $name, $geom, $extra = array()) {
	global $TS;
	return array('type' => 'Feature', 'geometry' => $geom, 'properties' => array_merge(array(
		'id' => $id, 'name' => $name, 'date' => '2026-10-21T15:00:00.000Z', 'time' => '2026-10-21T15:00:00.000Z',
		'modified_timestamp' => $TS, 'notes' => "parity $name: quotes ' \" unicode Grüße"), $extra));
}
function spots_fc($which) {
	global $ID;
	$all = array(
		's1' => feature($ID['s1'], 'P1 planar', point(-105.1, 39.6), array(
			'orientation_data' => array(array('id' => $ID['s1'] + 100, 'type' => 'planar_orientation', 'strike' => 120, 'dip_direction' => 210, 'dip' => 35, 'feature_type' => 'bedding',
				'associated_orientation' => array(array('id' => $ID['s1'] + 101, 'type' => 'linear_orientation', 'trend' => 200, 'plunge' => 20, 'feature_type' => 'stretching')))),
			'images' => array(array('id' => $ID['image'], 'title' => 'parity image', 'image_type' => 'photo', 'width' => 64, 'height' => 48)),
			'samples' => array(array('id' => $ID['sample'], 'sample_id_name' => 'PAR-1', 'label' => 'PAR-1', 'sample_description' => 'parity sample', 'main_sampling_purpose' => 'petrology')))),
		's2' => feature($ID['s2'], 'P2 line', array('type' => 'LineString', 'coordinates' => array(array(-105.1, 39.6), array(-105.2, 39.7))), array(
			'trace' => array('trace_feature' => true, 'trace_type' => 'contact', 'contact_type' => 'depositional'))),
		's3' => feature($ID['s3'], 'P3 polygon', array('type' => 'Polygon', 'coordinates' => array(array(array(-105.1, 39.6), array(-105.2, 39.6), array(-105.2, 39.7), array(-105.1, 39.6)))), array(
			'rock_unit' => array('unit_label_abbreviation' => 'Kp'), 'other_features' => array(array('id' => $ID['s3'] + 100, 'type' => 'other', 'name' => 'vein')))),
		's4' => feature($ID['s4'], 'P4 single', point(-105.3, 39.5)),
		's5' => feature($ID['s5'], 'P5 pds', point(-105.4, 39.4)),
		's6' => feature($ID['s6'], 'P6 pdss', point(-105.5, 39.3), array('orientation_data' => array(array('id' => $ID['s6'] + 100, 'type' => 'linear_orientation', 'trend' => 10, 'plunge' => 5)))),
	);
	$f = array();
	foreach ($which as $w) $f[] = $all[$w];
	return array('type' => 'FeatureCollection', 'features' => $f);
}
function project_body($extraTags = true) {
	global $ID, $TS;
	$p = array('id' => $ID['project'], 'modified_timestamp' => $TS, 'date' => '2026-10-21',
		'description' => array('project_name' => 'API parity project', 'purpose_of_study' => 'PHP 8.4 parity'),
		'preferences' => array(), 'other_features' => array('vein'));
	if ($extraTags) $p['tags'] = array(
		array('id' => $ID['tag1'], 'name' => 'Parity unit', 'type' => 'geologic_unit', 'rock_type' => 'igneous', 'spots' => array($ID['s1'], $ID['s3'])),
		array('id' => $ID['tag2'], 'name' => 'Parity rosetta', 'type' => 'rosetta', 'spots' => array($ID['s2'])),
		array('id' => $ID['tag3'], 'name' => 'Parity empty tag', 'type' => 'concept'),   // a tag with no spots key
	);
	return $p;
}
function dataset_body($id, $name) { global $TS; return array('id' => $id, 'name' => $name, 'modified_timestamp' => $TS, 'date' => '2026-10-21'); }
function jpeg_file() {
	$f = sys_get_temp_dir() . '/parity_' . getmypid() . '.jpg';
	$im = imagecreatetruecolor(64, 48); imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 40, 120, 200)); imagejpeg($im, $f, 80); imagedestroy($im);
	return $f;
}

/* ---------------------------------------------------------------- cleanup */

function cleanup_field($pk) {
	global $neodb, $db, $ID;
	foreach ($neodb->query("MATCH (i:Image) WHERE i.userpkey = $pk AND i.id >= " . P_MIN . " AND i.id <= " . P_MAX . " RETURN i.filename AS fn") ?: array() as $r) {
		$fn = (string)$r->get('fn');
		if ($fn !== '' && ctype_digit($fn)) @unlink("/srv/app/www/dbimages/$fn");
	}
	// the account exists only for this suite: every node it owns except the User node is ours
	// (spot sub-nodes such as RockUnit / Trace carry no id)
	$neodb->query("MATCH (n) WHERE n.userpkey = $pk AND NOT n:User DETACH DELETE n");
	foreach ($db->get_results_prepared("SELECT uuid FROM versions WHERE projectid = $1", array((string)$ID['project'])) ?: array() as $r) {
		$u = is_object($r) ? $r->uuid : $r['uuid'];
		if (preg_match('/^[0-9a-f-]{36}$/', $u)) @unlink("/srv/app/www/versions/$u");
	}
	$db->prepare_query("DELETE FROM versions WHERE projectid = $1", array((string)$ID['project']));
	$db->prepare_query("DELETE FROM verlog WHERE projectid = $1", array((string)$ID['project']));
	$db->query("DELETE FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND project_id = '{$ID['project']}'");
	$db->query("DELETE FROM strabosearch.image_hit WHERE project_subsystem = 'field' AND project_id = '{$ID['project']}'");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey = $pk");
	$db->query("DELETE FROM sample WHERE user_pkey = $pk");
	$db->query("DELETE FROM spot WHERE user_pkey = $pk");
	$db->query("DELETE FROM dataset WHERE user_pkey = $pk");
	$db->query("DELETE FROM project WHERE user_pkey = $pk");
	foreach (glob('/srv/app/www/versions/images/97795*') ?: array() as $f) @unlink($f);
}
function cleanup_micro($pk) {
	global $db, $neodb, $MICRO_SID;
	require_once '/srv/app/www/microdb/strabomicroclass.php';
	foreach ($db->get_results_prepared("SELECT id FROM strabomicro.micro_projectmetadata WHERE userpkey = $1", array($pk)) ?: array() as $r) {
		$sm = new StraboMicro($neodb, $pk, $db);
		@$sm->deleteProject($MICRO_SID);
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE userpkey = $1", array($pk));
	@unlink("/StraboData/bigDriveData/tempFiles/micro_$MICRO_SID.zip");
}
function cleanup_exp($pk, $fac = 0, $app = 0) {
	global $db;
	foreach ($db->get_results("SELECT pkey FROM apprepo.facility WHERE pkey IN (SELECT facility_pkey FROM apprepo.facility_users WHERE users_pkey = $pk)") ?: array() as $r) {
		$f = (int)$r->pkey;
		if ((int)$db->get_var("SELECT count(*) FROM apprepo.facility_users WHERE facility_pkey = $f AND users_pkey <> $pk") > 0) continue;   // never touch a shared facility
		$db->query("DELETE FROM apprepo.apparatus_parameter WHERE apparatus_pkey IN (SELECT pkey FROM apprepo.apparatus WHERE facility_pkey = $f)");
		$db->query("DELETE FROM apprepo.apparatus_document WHERE apparatus_pkey IN (SELECT pkey FROM apprepo.apparatus WHERE facility_pkey = $f)");
		$db->query("DELETE FROM apprepo.apparatus WHERE facility_pkey = $f");
		$db->query("DELETE FROM apprepo.facility_users WHERE facility_pkey = $f");
		$db->query("DELETE FROM apprepo.facility WHERE pkey = $f");
	}
	foreach ($db->get_results("SELECT uuid FROM straboexp.file_holdings WHERE userpkey = $pk") ?: array() as $r) {
		if (preg_match('/^[0-9a-f-]{36}$/', $r->uuid)) @unlink("/srv/app/www/experimental/expimages/{$r->uuid}");
	}
	$db->query("DELETE FROM straboexp.file_holdings WHERE userpkey = $pk");
	foreach (array('sample_composition', 'sample_parameter') as $t) {
		$db->query("DELETE FROM straboexp.$t WHERE sample_pkey IN (SELECT s.pkey FROM straboexp.sample s JOIN straboexp.experiment e ON e.pkey = s.experiment_pkey WHERE e.userpkey = $pk)");
	}
	$db->query("DELETE FROM straboexp.sample WHERE experiment_pkey IN (SELECT pkey FROM straboexp.experiment WHERE userpkey = $pk)");
	$db->query("DELETE FROM straboexp.versions WHERE userpkey = $pk");
	$db->query("DELETE FROM straboexp.experiment WHERE userpkey = $pk");
	$db->query("DELETE FROM straboexp.project WHERE userpkey = $pk");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey = $pk");
}
function cleanup_other($pk) {
	global $db, $SAMPLE_ID;
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey = $pk");
	$db->query("DELETE FROM strabosearch.saved_search WHERE user_pkey = $pk");
	$db->query("DELETE FROM refresh_tokens WHERE userpkey = $pk");
	foreach ($db->get_results("SELECT pkey FROM micro_logs WHERE email = 'apiparity@test.strabospot.org'") ?: array() as $r) @unlink('/srv/app/www/microLogs/' . (int)$r->pkey . '.txt');
	$db->query("DELETE FROM micro_logs WHERE email = 'apiparity@test.strabospot.org'");
}

/* ===================================================================== RUN */

function run_side($side, $base, $pk) {
	global $SIDE, $TEST, $TEST_EMAIL, $TEST_PASS, $MAYA, $MAYA_EMAIL, $OUTSIDER, $ID, $TS, $db, $neodb,
		$MICRO_FIXTURE_SRC, $MICRO_SID, $SAMPLE_ID, $RUN;
	$SIDE = $side;
	$RUN[$side] = array();
	echo "\n== side $side: $base\n";

	cleanup_field($pk); cleanup_micro($pk); cleanup_other($pk); cleanup_exp($pk);

	// ---------------------------------------------------------- auth tokens
	$tok = rec('jwtauth login', req($base, 'POST', '/jwtauth/login.php', array('json' => array('email' => $TEST_EMAIL, 'password' => $TEST_PASS))));
	$JWT = isset($tok['json']['access_token']) ? $tok['json']['access_token'] : '';
	rec('jwtauth login bad password', req($base, 'POST', '/jwtauth/login.php', array('json' => array('email' => $TEST_EMAIL, 'password' => 'nope'))));
	rec('jwtauth login GET 405', req($base, 'GET', '/jwtauth/login.php'));
	if (isset($tok['json']['refresh_token'])) rec('jwtauth refresh', req($base, 'POST', '/jwtauth/refresh.php', array('json' => array('refresh_token' => $tok['json']['refresh_token']))));
	$jar = sys_get_temp_dir() . "/parity_jar_$side";
	@unlink($jar);
	rec('web login', req($base, 'POST', '/login', array('jar' => $jar, 'form' => array('email' => $TEST_EMAIL, 'password' => $TEST_PASS, 'submit_login' => 'Login'))));

	// ------------------------------------------------------------ StraboField
	if (want('field')) {
		foreach (array(array('db', array('auth' => $TEST)), array('jwtdb', array('bearer' => $JWT))) as $api) {
			list($a, $AU) = $api;
			$P = $ID['project']; $D1 = $ID['ds1']; $D2 = $ID['ds2'];
			$q = function ($m, $path, $o = array()) use ($base, $AU) { return req($base, $m, $path, array_merge($AU, $o)); };
			$L = function ($s) use ($a) { return "$a $s"; };

			rec($L('GET userAuthenticate'), $q('GET', "/$a/userAuthenticate"));
			rec($L('POST userAuthenticate'), $q('POST', "/$a/userAuthenticate", array('json' => new stdClass())));
			rec($L('POST profile'), $q('POST', "/$a/profile", array('json' => array('name' => 'Api Parity', 'location' => 'Lawrence', 'bio' => 'parity'))));
			rec($L('GET profile'), $q('GET', "/$a/profile"));
			$jpg = jpeg_file();
			rec($L('POST profileimage'), $q('POST', "/$a/profileimage", array('multipart' => array('image_file' => new CURLFile($jpg, 'image/jpeg', 'p.jpg')))));
			rec($L('GET profileimage'), $q('GET', "/$a/profileimage"));
			rec($L('POST project'), $q('POST', "/$a/project", array('json' => project_body())));
			rec($L('GET project'), $q('GET', "/$a/project/$P"));
			rec($L('GET myProjects'), $q('GET', "/$a/myProjects"));
			rec($L('POST dataset ds1'), $q('POST', "/$a/dataset", array('json' => dataset_body($D1, 'Parity DS1'))));
			rec($L('POST dataset ds2'), $q('POST', "/$a/dataset", array('json' => dataset_body($D2, 'Parity DS2'))));
			rec($L('POST projectDatasets ds1'), $q('POST', "/$a/projectDatasets/$P", array('json' => array('id' => $D1))));
			rec($L('POST projectDatasets ds2'), $q('POST', "/$a/projectDatasets/$P", array('json' => array('id' => $D2))));
			rec($L('GET projectDatasets'), $q('GET', "/$a/projectDatasets/$P"));
			rec($L('GET dataset'), $q('GET', "/$a/dataset/$D1"));
			rec($L('GET myDatasets'), $q('GET', "/$a/myDatasets"));
			rec($L('POST datasetspots ds1 (3 spots)'), $q('POST', "/$a/datasetspots/$D1", array('json' => spots_fc(array('s1', 's2', 's3')))));
			rec($L('GET datasetspots ds1'), $q('GET', "/$a/datasetspots/$D1"));
			rec($L('GET feature s1'), $q('GET', "/$a/feature/{$ID['s1']}"));
			rec($L('GET myFeatures'), $q('GET', "/$a/myFeatures"));
			rec($L('GET datasetFields point'), $q('GET', "/$a/datasetFields/$D1/point"));
			rec($L('GET datasetSpotsArc'), $q('GET', "/$a/datasetSpotsArc/$D1"));
			rec($L('POST image'), $q('POST', "/$a/image", array('multipart' => array('id' => (string)$ID['image'], 'image_file' => new CURLFile($jpg, 'image/jpeg', 'i.jpg')))));
			rec($L('GET image'), $q('GET', "/$a/image/{$ID['image']}"));
			rec($L('GET verifyImage'), $q('GET', "/$a/verifyImage/{$ID['image']}"));
			rec($L('POST verifyImages'), $q('POST', "/$a/verifyImages", array('json' => array($ID['image'], 97795000000099))));
			rec($L('GET projectImages'), $q('GET', "/$a/projectImages/$P"));
			rec($L('GET featureImages'), $q('GET', "/$a/featureImages"));
			rec($L('POST datasetSingleSpot s4'), $q('POST', "/$a/datasetSingleSpot/$D1", array('json' => spots_fc(array('s4'))['features'][0])));
			rec($L('GET updateDatasetSpots'), $q('GET', "/$a/updateDatasetSpots/$D1"));
			rec($L('POST updateDatasetSpots'), $q('POST', "/$a/updateDatasetSpots/$D1", array('json' => spots_fc(array('s1', 's2', 's3', 's4')))));
			rec($L('POST datasetTimestamp'), $q('POST', "/$a/datasetTimestamp/$D1", array('json' => array('timestamp' => $TS + 5))));
			rec($L('POST projectTimestamp'), $q('POST', "/$a/projectTimestamp/$P", array('json' => array('timestamp' => $TS + 5))));
			rec($L('POST projectDatasetSpot s5'), $q('POST', "/$a/projectDatasetSpot", array('json' => array('project' => project_body(), 'dataset' => dataset_body($D1, 'Parity DS1'), 'spot' => spots_fc(array('s5'))['features'][0]))));
			$pb = project_body();
			$pb['datasets'] = array(array_merge(dataset_body($D2, 'Parity DS2'), array('spots' => spots_fc(array('s6')))));
			rec($L('POST projectDatasetsSpots s6'), $q('POST', "/$a/projectDatasetsSpots", array('json' => array('project' => $pb))));
			rec($L('GET datasetspots ds2'), $q('GET', "/$a/datasetspots/$D2"));
			rec($L('POST moveSpotToDataset s5->ds2'), $q('POST', "/$a/moveSpotToDataset", array('json' => array('spot_id' => $ID['s5'], 'dataset_id' => $D2, 'modified_timestamp' => $TS + 9))));
			rec($L('GET datasetspots ds2 after move'), $q('GET', "/$a/datasetspots/$D2"));
			rec($L('POST projectDatasetDeleteSpot s6'), $q('POST', "/$a/projectDatasetDeleteSpot", array('json' => array('project' => project_body(), 'datasets' => array(dataset_body($D2, 'Parity DS2')), 'spotId' => $ID['s6']))));
			rec($L('GET project after writes'), $q('GET', "/$a/project/$P"));
			rec($L('POST search BBOX'), $q('POST', "/$a/search", array('json' => array('BBOX' => '-106,39,-104,40'))));
			rec($L('GET search'), $q('GET', "/$a/search"));
			rec($L('POST macroJWT'), $q('POST', "/$a/macroJWT", array('json' => array('jwt' => 'parity.test.token'))));
			rec($L('GET macroJWT'), $q('GET', "/$a/macroJWT"));
			rec($L('DELETE macroJWT'), $q('DELETE', "/$a/macroJWT"));
			rec($L('GET myStraboTools'), $q('GET', "/$a/myStraboTools"));
			rec($L('GET straboTools'), $q('GET', "/$a/straboTools"));
			rec($L('GET straboTools bad id'), $q('GET', "/$a/straboTools/999999999"));
			$log = sys_get_temp_dir() . '/parity_log.zip';
			$z = new ZipArchive(); $z->open($log, ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString('log.txt', "parity log\n"); $z->close();
			rec($L('POST logs'), $q('POST', "/$a/logs", array('multipart' => array('notes' => 'parity', 'appversion' => '9.9.9', 'log_file' => new CURLFile($log, 'application/zip', 'logs.zip')))));
			rec($L('POST logs no file'), $q('POST', "/$a/logs", array('multipart' => array('notes' => 'parity'))));
			rec($L('GET mirror'), $q('GET', "/$a/mirror"));
			rec($L('GET test'), $q('GET', "/$a/test"));
			rec($L('GET account'), $q('GET', "/$a/account"));
			rec($L('GET projectTimestamp'), $q('GET', "/$a/projectTimestamp/$P"));
			rec($L('GET datasetTimestamp'), $q('GET', "/$a/datasetTimestamp/$D1"));
			// access by an unrelated user
			rec($L('outsider GET project'), req($base, 'GET', "/$a/project/$P", $a === 'db' ? array('auth' => $OUTSIDER) : array('bearer' => 'x.y.z')));
			rec($L('outsider GET datasetspots'), req($base, 'GET', "/$a/datasetspots/$D1", array('auth' => $OUTSIDER)));
			// error paths
			rec($L('no auth myProjects'), req($base, 'GET', "/$a/myProjects"));
			rec($L('bad password myProjects'), req($base, 'GET', "/$a/myProjects", array('auth' => "$TEST_EMAIL:wrong")));
			rec($L('GET unknown project'), $q('GET', "/$a/project/1"));
			rec($L('GET unknown feature'), $q('GET', "/$a/feature/1"));
			rec($L('POST malformed JSON project'), $q('POST', "/$a/project", array('raw' => '{"id": 123, bad')));
			rec($L('POST project no id'), $q('POST', "/$a/project", array('json' => array('description' => array()))));
			rec($L('PUT project (no PUT)'), $q('PUT', "/$a/project/$P", array('json' => array())));
			rec($L('GET unknown resource'), $q('GET', "/$a/nosuchthing"));
			rec($L('POST datasetspots unknown ds'), $q('POST', "/$a/datasetspots/1", array('json' => spots_fc(array('s4')))));
			// deletes
			rec($L('DELETE feature s1'), $q('DELETE', "/$a/feature/{$ID['s1']}"));
			rec($L('POST projectDeleteDataset ds2'), $q('POST', "/$a/projectDeleteDataset", array('json' => array('project' => project_body(), 'datasetId' => $D2))));
			rec($L('DELETE image'), $q('DELETE', "/$a/image/{$ID['image']}"));
			rec($L('DELETE dataset ds1'), $q('DELETE', "/$a/dataset/$D1"));
			rec($L('DELETE project'), $q('DELETE', "/$a/project/$P"));
			rec($L('GET project after delete'), $q('GET', "/$a/project/$P"));
			rec($L('DELETE profileimage'), $q('DELETE', "/$a/profileimage"));
			rec($L('DELETE profile'), $q('DELETE', "/$a/profile"));
			@unlink($jpg);
			cleanup_field($pk);
		}
	}

	// ---------------------------------------------------- versionsdb (needs versions)
	if (want('versions')) {
		$q = function ($m, $p) use ($base, $TEST) { return req($base, $m, $p, array('auth' => $TEST)); };
		// make one version through the combined upload, which snapshots the project first
		req($base, 'POST', '/db/project', array('auth' => $TEST, 'json' => project_body()));
		req($base, 'POST', '/db/dataset', array('auth' => $TEST, 'json' => dataset_body($ID['ds1'], 'V DS1')));
		req($base, 'POST', "/db/projectDatasets/{$ID['project']}", array('auth' => $TEST, 'json' => array('id' => $ID['ds1'])));
		$pb = project_body(); $pb['datasets'] = array(array_merge(dataset_body($ID['ds1'], 'V DS1'), array('spots' => spots_fc(array('s4')))));
		req($base, 'POST', '/db/projectDatasetsSpots', array('auth' => $TEST, 'json' => array('project' => $pb)));
		req($base, 'POST', '/db/projectDatasetsSpots', array('auth' => $TEST, 'json' => array('project' => $pb)));
		rec('versionsdb myprojects', $q('GET', '/versionsdb/myprojects'));
		rec('versionsdb projectversions', $q('GET', "/versionsdb/projectversions/{$ID['project']}"));
		$u = $db->get_var_prepared("SELECT uuid FROM versions WHERE projectid = $1 ORDER BY pkey LIMIT 1", array((string)$ID['project']));
		rec('versionsdb version', $q('GET', '/versionsdb/version/' . ($u ?: 'none')));
		rec('versionsdb version bad uuid', $q('GET', '/versionsdb/version/00000000-0000-4000-8000-000000000000'));
		rec('versionsdb POST 405', $q('POST', '/versionsdb/myprojects'));
		rec('versionsdb no auth', req($base, 'GET', '/versionsdb/myprojects'));
		rec('versionsdb outsider', req($base, 'GET', "/versionsdb/projectversions/{$ID['project']}", array('auth' => $OUTSIDER)));
		cleanup_field($pk);
	}

	// ------------------------------------------------------------------ Micro
	if (want('micro')) {
		$fix = sys_get_temp_dir() . '/parity_micro.zip';
		// re-root the fixture under our own strabo id so it never collides with the real project
		if (!file_exists($fix)) {
			$in = new ZipArchive(); $in->open($MICRO_FIXTURE_SRC);
			$old = explode('/', $in->getNameIndex(0))[0];
			$out = new ZipArchive(); $out->open($fix, ZipArchive::CREATE | ZipArchive::OVERWRITE);
			for ($i = 0; $i < $in->numFiles; $i++) {
				$n = $in->getNameIndex($i);
				$c = $in->getFromIndex($i);
				if (substr($n, -5) === '.json') $c = str_replace($old, $MICRO_SID, $c);
				$out->addFromString(str_replace($old, $MICRO_SID, $n), $c);
			}
			$out->close(); $in->close();
		}
		foreach (array(array('microdb', array('auth' => $TEST)), array('jwtmicrodb', array('bearer' => $JWT))) as $api) {
			list($a, $AU) = $api;
			$q = function ($m, $path, $o = array()) use ($base, $AU) { return req($base, $m, $path, array_merge($AU, $o)); };
			$L = function ($s) use ($a) { return "$a $s"; };
			$S = $MICRO_SID;
			rec($L('GET myProjects (empty)'), $q('GET', "/$a/myProjects"));
			rec($L('GET projectCount'), $q('GET', "/$a/projectCount"));
			rec($L('POST project (smz upload)'), $q('POST', "/$a/project/$S", array('timeout' => 600, 'multipart' => array('project_id' => $S, 'overwrite' => 'yes', 'microProject' => new CURLFile($fix, 'application/zip', 'project.zip')))));
			rec($L('GET myProjects'), $q('GET', "/$a/myProjects"));
			rec($L('GET project'), $q('GET', "/$a/project/$S"));
			rec($L('GET webProject'), $q('GET', "/$a/webProject/$S"));
			rec($L('GET projectURL'), $q('GET', "/$a/projectURL/$S"));
			$mid = (int)$db->get_var_prepared("SELECT id FROM strabomicro.micro_projectmetadata WHERE userpkey = $1 AND strabo_id = $2", array($pk, $S));
			rec($L('GET sharedURL'), $q('GET', "/$a/sharedURL/$mid"));
			$pj = $q('GET', "/$a/project/$S")['json'];
			$img = isset($pj['projectDetails']['datasets'][0]['samples'][0]['micrographs'][0]['id']) ? $pj['projectDetails']['datasets'][0]['samples'][0]['micrographs'][0]['id'] : '0';
			rec($L('GET image'), $q('GET', "/$a/image/$img"));
			rec($L('GET image unknown'), $q('GET', "/$a/image/1"));
			rec($L('GET projectPDF'), $q('GET', "/$a/projectPDF/$S", array('timeout' => 600)));
			// legacy two-step flow: the app first posts the zip to microupload/upload.php (tempFiles),
			// then calls projectWithoutFile to load it
			copy($fix, "/StraboData/bigDriveData/tempFiles/micro_$S.zip");
			@chmod("/StraboData/bigDriveData/tempFiles/micro_$S.zip", 0666);
			rec($L('POST projectWithoutFile'), $q('POST', "/$a/projectWithoutFile/$S", array('timeout' => 600, 'form' => array('project_id' => $S, 'overwrite' => 'yes'))));
			rec($L('GET project after reload'), $q('GET', "/$a/project/$S"));
			if ($a === 'jwtmicrodb') {
				$log = sys_get_temp_dir() . '/parity_mlog.txt'; file_put_contents($log, "micro log\n");
				rec($L('POST logs'), $q('POST', "/$a/logs", array('multipart' => array('notes' => 'parity', 'log_file' => new CURLFile($log, 'text/plain', 'log.txt')))));
			}
			rec($L('outsider GET project'), req($base, 'GET', "/$a/project/$S", $a === 'microdb' ? array('auth' => $OUTSIDER) : array('bearer' => 'x.y.z')));
			rec($L('no auth myProjects'), req($base, 'GET', "/$a/myProjects"));
			rec($L('GET project unknown'), $q('GET', "/$a/project/00000000-0000-4000-8000-000000000000"));
			rec($L('DELETE project'), $q('DELETE', "/$a/project/$S"));
			rec($L('GET project after delete'), $q('GET', "/$a/project/$S"));
			cleanup_micro($pk);
		}
	}

	// -------------------------------------------------------------------- Exp
	if (want('exp')) {
		$q = function ($m, $path, $o = array()) use ($base, $TEST) { return req($base, $m, $path, array_merge(array('auth' => $TEST), $o)); };
		$fac = $db->get_row("SELECT pkey FROM apprepo.facility ORDER BY pkey LIMIT 1");
		$app = $db->get_row("SELECT pkey, uuid FROM apprepo.apparatus ORDER BY pkey LIMIT 1");
		$exp = $db->get_var("SELECT pkey FROM straboexp.experiment ORDER BY pkey LIMIT 1");
		$prj = $db->get_var("SELECT pkey FROM straboexp.project ORDER BY pkey LIMIT 1");
		rec('expdb GET myFacilities', $q('GET', '/expdb/myFacilities'));
		rec('expdb GET facility', $q('GET', '/expdb/facility/' . ($fac ? $fac->pkey : 0)));
		rec('expdb GET apparatus', $q('GET', '/expdb/apparatus/' . ($app ? $app->uuid : 'x')));
		rec('expdb GET apparatus none', $q('GET', '/expdb/apparatus'));
		rec('expdb GET experiment', $q('GET', '/expdb/experiment/' . ($exp ?: 0)));
		rec('expdb GET project', $q('GET', '/expdb/project/' . ($prj ?: 0)));
		rec('expdb GET experiment bad', $q('GET', '/expdb/experiment/abc'));
		rec('expdb POST experiment (read only)', $q('POST', '/expdb/experiment/1', array('json' => array())));
		rec('expdb POST apparatus bad body', $q('POST', '/expdb/apparatus', array('json' => array('x' => 1))));
		rec('expdb POST facility bad body', $q('POST', '/expdb/facility', array('json' => array('x' => 1))));
		rec('expdb DELETE apparatus', $q('DELETE', '/expdb/apparatus/1'));
		rec('expdb no auth', req($base, 'GET', '/expdb/myFacilities'));

		// The StraboExperimental app writes through /experimental/api/ (session auth)
		$w = function ($m, $path, $o = array()) use ($base, $jar) { return req($base, $m, "/experimental/api/$path", array_merge(array('jar' => $jar), $o)); };
		rec('expapi get_projects', $w('GET', 'get_projects.php'));
		rec('expapi get_facilities', $w('GET', 'get_facilities.php'));
		rec('expapi get_apparatus_list', $w('GET', 'get_apparatus_list.php'));
		rec('expapi get_my_experiments', $w('GET', 'get_my_experiments.php'));
		rec('expapi get_my_samples', $w('GET', 'get_my_samples.php'));
		$fr = rec('expapi save_facility new', $w('POST', 'save_facility.php', array('json' => array('name' => 'Parity facility', 'type' => 'Rock Mechanics',
			'institute' => 'Parity U', 'department' => 'Geology', 'city' => 'Lawrence', 'country' => 'USA', 'firstname' => 'Api', 'lastname' => 'Parity', 'email' => 'apiparity@test.strabospot.org'))));
		$fac = isset($fr['json']['pkey']) ? (int)$fr['json']['pkey'] : 0;
		rec('expapi save_facility missing fields', $w('POST', 'save_facility.php', array('json' => array('name' => 'x'))));
		rec('expapi save_facility update', $w('POST', 'save_facility.php', array('json' => array('pkey' => $fac, 'name' => 'Parity facility 2', 'type' => 'Rock Mechanics', 'institute' => 'Parity U'))));
		rec('expapi get_facility', $w('GET', "get_facility.php?id=$fac"), array('id'));
		rec('expapi get_apprepo_facility', $w('GET', "get_apprepo_facility.php?id=$fac"));
		$ar = rec('expapi save_apparatus new', $w('POST', 'save_apparatus.php', array('json' => array('name' => 'Parity rig', 'type' => 'Paterson Apparatus', 'facility_pkey' => $fac,
			'location' => 'Lab 1', 'description' => 'parity', 'features' => array('Confining Pressure', 'Temperature'),
			'parameters' => array(array('type' => 'Confining Pressure', 'min' => '0', 'max' => '500', 'unit' => 'MPa', 'prefix' => '', 'note' => ''))))));
		$app = isset($ar['json']['pkey']) ? (int)$ar['json']['pkey'] : 0;
		rec('expapi save_apparatus update', $w('POST', 'save_apparatus.php', array('json' => array('pkey' => $app, 'name' => 'Parity rig 2', 'type' => 'Paterson Apparatus', 'features' => array()))));
		rec('expapi get_apparatus', $w('GET', "get_apparatus.php?id=$app"));
		rec('expapi get_apprepo_apparatus', $w('GET', "get_apprepo_apparatus.php?id=$app"));
		$pr = rec('expapi save_project new', $w('POST', 'save_project.php', array('json' => array('name' => 'Parity exp project', 'description' => 'parity'))));
		$prj = isset($pr['json']['pkey']) ? (int)$pr['json']['pkey'] : 0;
		rec('expapi save_project update', $w('POST', 'save_project.php', array('json' => array('pkey' => $prj, 'name' => 'Parity exp project 2', 'description' => 'edited'))));
		rec('expapi save_project no name', $w('POST', 'save_project.php', array('json' => array('description' => 'x'))));
		rec('expapi save_project GET 405', $w('GET', 'save_project.php'));
		rec('expapi get_project', $w('GET', "get_project.php?id=$prj"));
		$data = array('facility' => array('name' => 'Parity facility'), 'apparatus' => array('type' => 'Paterson Apparatus'), 'daq' => new stdClass(),
			'sample' => array('name' => 'Parity exp sample', 'igsn' => '', 'id' => 'PAR-EXP-1', 'description' => 'parity',
				'material' => array('material' => array('type' => 'Sedimentary Rock', 'name' => 'Sandstone', 'state' => 'Solid', 'note' => ''),
					'composition' => array(array('mineral' => 'Quartz', 'fraction' => '60', 'unit' => 'Wt%', 'grainsize' => ''))),
				'parameters' => array(array('control' => 'Mass', 'value' => '12.3', 'unit' => 'g', 'prefix' => '', 'note' => ''))),
			'experiment' => array('id' => 'PAR-EXP-1'), 'data' => new stdClass());
		$er = rec('expapi save_experiment new', $w('POST', 'save_experiment.php', array('json' => array('project_pkey' => $prj, 'experiment_id' => 'PAR-EXP-1', 'data' => $data))));
		$exp = isset($er['json']['pkey']) ? (int)$er['json']['pkey'] : 0;
		$data['sample']['name'] = 'Parity exp sample edited';
		rec('expapi save_experiment update', $w('POST', 'save_experiment.php', array('json' => array('pkey' => $exp, 'experiment_id' => 'PAR-EXP-1', 'data' => $data))));
		rec('expapi save_experiment no project', $w('POST', 'save_experiment.php', array('json' => array('experiment_id' => 'X', 'data' => $data))));
		rec('expapi get_experiment', $w('GET', "get_experiment.php?id=$exp"));
		rec('expapi update_keywords', $w('POST', 'update_keywords.php', array('json' => array('project_pkey' => $prj))));
		$doc = sys_get_temp_dir() . '/parity_doc.txt'; file_put_contents($doc, "parity document\n");
		rec('expapi upload_document', $w('POST', 'upload_document.php', array('multipart' => array('uuid' => '97795000-0000-4000-8000-0000000000d1', 'infile' => new CURLFile($doc, 'text/plain', 'parity_doc.txt')))));
		rec('expapi view_document', $w('GET', 'view_document.php?uuid=97795000-0000-4000-8000-0000000000d1'));
		rec('expapi download_experiment', $w('GET', "download_experiment.php?id=$exp"));
		rec('expapi download_pdf', $w('GET', "download_pdf.php?id=$exp", array('timeout' => 300)));
		rec('expapi download_project', $w('GET', "download_project.php?id=$prj"));
		rec('expapi download_project_pdf', $w('GET', "download_project_pdf.php?id=$prj", array('timeout' => 300)));
		rec('expapi project_public', $w('GET', "project_public.php?projectid=$prj&state=public"));
		rec('expapi project_public private', $w('GET', "project_public.php?projectid=$prj&state=private"));
		rec('expapi no session', req($base, 'GET', '/experimental/api/get_projects.php'));
		rec('expapi delete_experiment', $w('GET', "delete_experiment.php?id=$exp"));
		rec('expapi get_experiment after delete', $w('GET', "get_experiment.php?id=$exp"));
		rec('expapi delete_project', $w('GET', "delete_project.php?id=$prj"));
		rec('expapi get_project after delete', $w('GET', "get_project.php?id=$prj"));
		cleanup_exp($pk, $fac, $app);
	}

	// ---------------------------------------------------------------- Samples
	if (want('samples')) {
		foreach (array(array('samplesdb', array('auth' => $TEST)), array('samplesjwtdb', array('bearer' => $JWT))) as $api) {
			list($a, $AU) = $api;
			$q = function ($m, $path, $o = array()) use ($base, $AU) { return req($base, $m, $path, array_merge($AU, $o)); };
			$L = function ($s) use ($a) { return "$a $s"; };
			$id = $SAMPLE_ID;
			rec($L('GET mysamples'), $q('GET', "/$a/mysamples"));
			rec($L('GET invitations'), $q('GET', "/$a/invitations"));
			rec($L('POST sample'), $q('POST', "/$a/sample", array('json' => array('id' => $id, 'name' => 'Parity sample', 'display_sample_type' => 'intact_rock', 'display_sample_purpose' => 'petrology', 'latitude' => 39.6, 'longitude' => -105.1))));
			rec($L('GET sample'), $q('GET', "/$a/sample/$id"));
			rec($L('PUT sample'), $q('PUT', "/$a/sample/$id", array('json' => array('name' => 'Parity sample renamed', 'description' => 'edited'))));
			rec($L('PUT composition'), $q('PUT', "/$a/sample/$id/composition", array('json' => array('items' => array(array('mineral' => 'quartz', 'fraction' => 40, 'unit' => 'vol%'))))));
			rec($L('GET composition'), $q('GET', "/$a/sample/$id/composition"));
			rec($L('GET family'), $q('GET', "/$a/sample/$id/family"));
			rec($L('GET changelog'), $q('GET', "/$a/sample/$id/changelog?limit=5"));
			rec($L('GET collaborators'), $q('GET', "/$a/sample/$id/collaborators"));
			rec($L('outsider GET sample'), req($base, 'GET', "/$a/sample/$id", $a === 'samplesdb' ? array('auth' => $OUTSIDER) : array('bearer' => 'x.y.z')));
			rec($L('POST sample duplicate'), $q('POST', "/$a/sample", array('json' => array('id' => $id, 'name' => 'dup'))));
			rec($L('POST sample malformed'), $q('POST', "/$a/sample", array('raw' => '{bad')));
			rec($L('DELETE sample'), $q('DELETE', "/$a/sample/$id"));
			rec($L('GET sample after delete'), $q('GET', "/$a/sample/$id"));
			cleanup_other($pk);
		}
	}

	// ----------------------------------------------------------------- Search
	if (want('search')) {
		$q = function ($m, $path, $o = array()) use ($base, $TEST) { return req($base, $m, $path, array_merge(array('auth' => $TEST), $o)); };
		rec('searchdb GET facets', req($base, 'GET', '/searchdb/facets'));
		rec('searchdb GET vocab', req($base, 'GET', '/searchdb/vocab'));
		rec('searchdb GET vocab rock_type', req($base, 'GET', '/searchdb/vocab/rock_type'));
		rec('searchdb GET vocab tag_type', req($base, 'GET', '/searchdb/vocab/tag_type'));
		rec('searchdb POST search anon', req($base, 'POST', '/searchdb/search', array('json' => array('pathway' => 'projects', 'criteria' => array()))));
		rec('searchdb POST search authed', $q('POST', '/searchdb/search', array('json' => array('pathway' => 'projects', 'criteria' => array(array('id' => 'U1', 'value' => 'granite'))))));
		$s = $q('POST', '/searchdb/saved', array('json' => array('search_name' => 'parity', 'dsl' => array('pathway' => 'projects', 'criteria' => array(array('id' => 'U1', 'value' => 'parity'))))));
		rec('searchdb POST saved', $s);
		$spk = isset($s['json']['saved_search_pkey']) ? (int)$s['json']['saved_search_pkey'] : 0;
		rec('searchdb GET saved', $q('GET', '/searchdb/saved'));
		rec('searchdb PUT saved', $q('PUT', "/searchdb/saved/$spk", array('json' => array('search_name' => 'parity 2'))));
		rec('searchdb DELETE saved', $q('DELETE', "/searchdb/saved/$spk"));
		rec('searchdb DELETE saved again', $q('DELETE', "/searchdb/saved/$spk"));
		rec('searchdb POST search bad dsl', $q('POST', '/searchdb/search', array('json' => array('criteria' => array(array('id' => 'ZZ'))))));
		cleanup_other($pk);
	}

	// -------------------------------------------------------------- microsync
	if (want('microsync')) {
		$q = function ($m, $path, $o = array()) use ($base, $JWT) { return req($base, $m, $path, array_merge(array('bearer' => $JWT), $o)); };
		rec('microsync ping', req($base, 'GET', '/microsync/v1/ping'));
		rec('microsync no auth', req($base, 'GET', '/microsync/v1/projects'));
		rec('microsync GET projects', $q('GET', '/microsync/v1/projects'));
		rec('microsync GET invites', $q('GET', '/microsync/v1/invites'));
		$c = $q('POST', '/microsync/v1/projects', array('json' => array('straboId' => '97795000-0000-4000-8000-0000000000aa', 'name' => 'Parity sync')));
		rec('microsync POST projects', $c);
		$mp = isset($c['json']['pid']) ? (int)$c['json']['pid'] : (isset($c['json']['project']['pid']) ? (int)$c['json']['project']['pid'] : 0);
		if (!$mp) $mp = (int)$db->get_var_prepared("SELECT id FROM strabomicro.micro_projectmetadata WHERE userpkey = $1 AND strabo_id = $2", array($pk, '97795000-0000-4000-8000-0000000000aa'));
		rec('microsync GET project', $q('GET', "/microsync/v1/projects/$mp"));
		rec('microsync GET members', $q('GET', "/microsync/v1/projects/$mp/members"));
		rec('microsync GET history', $q('GET', "/microsync/v1/projects/$mp/history"));
		rec('microsync GET changes', $q('GET', "/microsync/v1/projects/$mp/changes?since=0"));
		rec('microsync POST duplicate 409', $q('POST', '/microsync/v1/projects', array('json' => array('straboId' => '97795000-0000-4000-8000-0000000000aa', 'name' => 'dup'))));
		rec('microsync POST bad body', $q('POST', '/microsync/v1/projects', array('raw' => '{bad')));
		rec('microsync wrong method', $q('PUT', '/microsync/v1/projects'));
		rec('microsync unknown route', $q('GET', '/microsync/v1/nope'));
		rec('microsync DELETE project', $q('DELETE', "/microsync/v1/projects/$mp"));
		rec('microsync GET project after delete', $q('GET', "/microsync/v1/projects/$mp"));
		cleanup_micro($pk);
	}

	// ------------------------------------------------------------- voice (maya)
	if (want('voice')) {
		$q = function ($m, $path, $o = array()) use ($base, $MAYA) { return req($base, $m, $path, array_merge(array('auth' => $MAYA), $o)); };
		$mk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($MAYA_EMAIL));
		$hadConsent = (int)$db->get_var("SELECT count(*) FROM voicestations.consents WHERE userpkey = $mk");
		$prow = $neodb->query("MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset) WHERE p.userpkey = $mk RETURN p.id AS p, d.id AS d ORDER BY p.id, d.id LIMIT 1");
		$mp = $prow ? $prow[0]->get('p') : 0; $md = $prow ? $prow[0]->get('d') : 0;
		rec('voice GET consent', $q('GET', '/db/voiceconsent'));
		rec('voice POST consent', $q('POST', '/db/voiceconsent', array('json' => array('agree' => true, 'version' => 1))));
		rec('voice GET station list', $q('GET', '/db/voicestation'));
		$st = '97795000-0000-4000-8000-0000000000b1'; $ba = '97795000-0000-4000-8000-0000000000b2';
		$t0 = time() - 600;
		$det = array('station_uuid' => $st, 'batch_uuid' => $ba, 'batch_station_uuids' => array($st), 'project_id' => (int)$mp, 'dataset_id' => $md,
			'target' => array('kind' => 'new'), 'started_at' => gmdate('Y-m-d\TH:i:s\Z', $t0), 'ended_at' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 60), 'tz_offset_minutes' => -300,
			'gps_fixes' => array(array('lat' => 38.95, 'lon' => -95.25, 'alt' => 300.0, 'accuracy' => 5.0, 'time' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 5))),
			'photos' => array(), 'strike_convention' => 'rhr', 'app_version' => '0.1.0 (1)', 'device_model' => 'iPhone15,2',
			'audio' => array('mime' => 'audio/mp4', 'seconds' => 3.0));
		rec('voice POST station', $q('POST', '/db/voicestation', array('multipart' => array('details' => json_encode($det), 'audio' => new CURLFile('/srv/app/www/tests/voicestations/fixture_tone.m4a', 'audio/mp4', 'station.m4a')))));
		rec('voice POST station bad details', $q('POST', '/db/voicestation', array('multipart' => array('details' => '{bad'))));
		rec('voice GET batch', $q('GET', "/db/voicebatch/$ba"));
		rec('voice GET audio', $q('GET', "/db/voiceaudio/$st"));
		rec('voice POST retry', $q('POST', "/db/voiceretry/$ba", array('json' => new stdClass())));
		rec('voice POST confirm (not ready)', $q('POST', "/db/voiceconfirm/$st", array('json' => new stdClass())));
		rec('voice outsider GET batch', req($base, 'GET', "/db/voicebatch/$ba", array('auth' => $OUTSIDER)));
		rec('voiceworker ping', req($base, 'GET', '/voiceworker/v1/ping'));
		rec('voiceworker no token', req($base, 'POST', '/voiceworker/v1/jobs/claim', array('json' => new stdClass())));
		$db->query("DELETE FROM voicestations.batches WHERE batch_uuid = '$ba'");
		$db->query("DELETE FROM voicestations.stations WHERE station_uuid = '$st'");
		if (!$hadConsent) $db->query("DELETE FROM voicestations.consents WHERE userpkey = $mk");
		foreach (glob("/srv/app/www/voicestations_data/audio/$mk/$st*") ?: array() as $f) @unlink($f);
	}

	// --------------------------------------------- REST, export jobs, public JSON
	if (want('web')) {
		rec('REST notableSpots', req($base, 'GET', '/REST/notableSpots.php?xmin=-106&xmax=-104&ymin=39&ymax=40'));
		rec('REST publicStratSections', req($base, 'GET', '/REST/publicStratSections.php?xmin=-180&xmax=180&ymin=-90&ymax=90'));
		// a small Field project of our own so the export job and session JSON pages have data
		req($base, 'POST', '/db/project', array('auth' => $TEST, 'json' => project_body()));
		req($base, 'POST', '/db/dataset', array('auth' => $TEST, 'json' => dataset_body($ID['ds1'], 'Web DS1')));
		req($base, 'POST', "/db/projectDatasets/{$ID['project']}", array('auth' => $TEST, 'json' => array('id' => $ID['ds1'])));
		req($base, 'POST', "/db/datasetspots/{$ID['ds1']}", array('auth' => $TEST, 'json' => spots_fc(array('s2', 's3', 's4'))));
		$scope = array('projects' => array(array('id' => (string)$ID['project'], 'owner' => $pk)));
		rec('exportjobs count (session)', req($base, 'POST', '/exportjobs/api.php?action=count', array('jar' => $jar, 'json' => array('recipe' => array('scope' => $scope)))));
		rec('exportjobs count no projects', req($base, 'POST', '/exportjobs/api.php?action=count', array('jar' => $jar, 'json' => array('recipe' => array('scope' => array('projects' => array()))))));
		$jr = rec('exportjobs create', req($base, 'POST', '/exportjobs/api.php?action=create', array('jar' => $jar, 'json' => array('recipe' => array('scope' => $scope, 'formats' => array('geojson'))))));
		$ju = isset($jr['json']['uuid']) ? $jr['json']['uuid'] : 'none';
		rec('exportjobs cancel', req($base, 'POST', '/exportjobs/api.php?action=cancel', array('jar' => $jar, 'json' => array('uuid' => $ju))));
		rec('exportjobs detail', req($base, 'GET', "/exportjobs/api.php?action=detail&uuid=$ju", array('jar' => $jar)));
		rec('exportjobs status (session)', req($base, 'GET', '/exportjobs/api.php?action=status', array('jar' => $jar)));
		rec('exportjobs clear', req($base, 'POST', '/exportjobs/api.php?action=clear', array('jar' => $jar, 'json' => array('which' => 'finished'))));
		rec('exportjobs no session', req($base, 'GET', '/exportjobs/api.php?action=status'));
		$db->query("DELETE FROM export_jobs WHERE userpkey = $pk");
		rec('spotjson (session)', req($base, 'GET', "/spot/{$ID['s2']}", array('jar' => $jar)));
		rec('projectjson (session)', req($base, 'GET', "/projectjson/{$ID['project']}", array('jar' => $jar)));
		rec('projectgeojson (session)', req($base, 'GET', "/projectgeojson/{$ID['project']}", array('jar' => $jar)));
		rec('searchdownload geojson-ish xls (session)', req($base, 'GET', "/searchdownload?type=xls&userpkey=$pk&dsids={$ID['ds1']}", array('jar' => $jar)));
		rec('searchdownload kml (session)', req($base, 'GET', "/searchdownload?type=kml&userpkey=$pk&dsids={$ID['ds1']}", array('jar' => $jar)));
		rec('searchdownload shapefile (session)', req($base, 'GET', "/searchdownload?type=shapefile&userpkey=$pk&dsids={$ID['ds1']}", array('jar' => $jar)));
		cleanup_field($pk);
		rec('instrument_list.js', req($base, 'GET', '/instrument_list.js'));
		rec('search data_model.js', req($base, 'GET', '/search/includes/data_model.js'));
	}

	cleanup_field($pk); cleanup_micro($pk); cleanup_other($pk); cleanup_exp($pk);
	@unlink($jar);
}

/** First path where two masked JSON values differ, for the report. */
function first_diff($a, $b, $path = '') {
	if (is_array($a) && is_array($b)) {
		foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
			if (!array_key_exists($k, $a)) return "$path/$k only in B: " . json_encode($b[$k]);
			if (!array_key_exists($k, $b)) return "$path/$k only in A: " . json_encode($a[$k]);
			$d = first_diff($a[$k], $b[$k], "$path/$k");
			if ($d !== null) return $d;
		}
		return null;
	}
	return $a === $b ? null : "$path: A=" . json_encode($a) . ' B=' . json_encode($b);
}

/* ================================================================== main */

if (getenv('PARITY_LIB')) return;   // helpers only (debugging)

// Each side should run INSIDE its own container (base http://localhost) so files the
// legacy flows stage under /StraboData (container-local on dev) land where that server
// reads them. run_parity.sh does: --side=A in strabo-php, --side=B in strabo-php8,
// then --compare. Without --side/--compare both sides run from here (PARITY_A/B).
$RESDIR = __DIR__ . '/results';
if (!is_dir($RESDIR)) @mkdir($RESDIR, 0777, true);
$sideOpt = getopt('', array('side:', 'compare'));
$pk = ensure_test_user();
$t = microtime(true);
if (isset($sideOpt['side'])) {
	$side = $sideOpt['side'];
	$base = getenv('PARITY_BASE') ?: 'http://localhost';
	echo "API parity side $side ($base, PHP " . PHP_VERSION . "), test user pkey $pk\n";
	run_side($side, $base, $pk);
	@unlink(sys_get_temp_dir() . '/parity_micro.zip');
	file_put_contents("$RESDIR/side_$side.json", json_encode(array('php' => PHP_VERSION, 'run' => $RUN[$side]), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
	printf("side %s: %d responses recorded (%.0f s)\n", $side, count($RUN[$side]), microtime(true) - $t);
	exit(0);
}
if (isset($sideOpt['compare'])) {
	foreach (array('A', 'B') as $sd) {
		$j = json_decode((string)@file_get_contents("$RESDIR/side_$sd.json"), true);
		if (!$j) { echo "missing $RESDIR/side_$sd.json: run --side=$sd first\n"; exit(2); }
		$RUN[$sd] = $j['run'];
		echo "side $sd: PHP {$j['php']}\n";
	}
} else {
	echo "API parity: A={$BASES['A']}  B={$BASES['B']}  test user pkey $pk\n";
	foreach ($BASES as $side => $base) run_side($side, $base, $pk);
	@unlink(sys_get_temp_dir() . '/parity_micro.zip');
}

echo "\n== compare A vs B\n";
$pass = 0; $fail = 0; $failures = array();
$labels = array_unique(array_merge(array_keys($RUN['A']), array_keys($RUN['B'])));
foreach ($labels as $l) {
	$a = isset($RUN['A'][$l]) ? $RUN['A'][$l] : null; $b = isset($RUN['B'][$l]) ? $RUN['B'][$l] : null;
	$ok = $a && $b && $a['code'] === $b['code'] && $a['ctype'] === $b['ctype'] && $a['norm'] === $b['norm'];
	if ($ok) { $pass++; echo "  PASS  [{$a['code']}] $l\n"; continue; }
	$fail++; $failures[] = $l;
	echo "  FAIL  $l\n";
	if ($a && $b && $a['m'] !== null && $b['m'] !== null) echo '        diff ' . substr((string)first_diff($a['m'], $b['m']), 0, 400) . "\n";
	foreach (array('A' => $a, 'B' => $b) as $s => $x) {
		if (!$x) { echo "        $s: (missing)\n"; continue; }
		echo "        $s: {$x['code']} {$x['ctype']} " . substr($x['norm'], 0, 300) . "\n";
		echo "           raw: " . str_replace("\n", ' ', substr($x['raw'], 0, 300)) . "\n";
	}
}
printf("\n%d PASS, %d FAIL of %d compared (%.0f s)\n", $pass, $fail, count($labels), microtime(true) - $t);
if ($failures) { echo "FAILURES:\n  - " . implode("\n  - ", $failures) . "\n"; exit(1); }
echo "ALL PASS\n";
