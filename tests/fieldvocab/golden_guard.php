<?php
/**
 * File: tests/fieldvocab/golden_guard.php
 * Description: Golden-file guard for the Field choice translation work
 *              (Phase 2, docs/edine_bug/TRANSLATION_SURFACE_AUDIT.md §4.1).
 *              Every ROUND-TRIP output (data the app, GIS tools, version
 *              restore or the search index read back) must keep the stored
 *              choice NAMES. This suite uploads golden_fixture.php over HTTP
 *              exactly like the StraboField app, captures each round-trip
 *              surface and compares it with the recorded golden file in
 *              tests/fieldvocab/golden/. Any label that leaks into one of
 *              these outputs changes a golden and fails the run.
 *
 *              Surfaces:
 *                rest_*        GET /db/project, projectDatasets, dataset,
 *                              datasetspots (x2), feature (x2)
 *                doidata       straboOutputClass::doiDataOut (app project
 *                              export data.json, /debugproject, DOI data.json)
 *                projectgeojson straboOutputClass::projectGeoJSONOut
 *                projectjson   /projectjson/{id} (session)
 *                spotjson_*    /spot/{id} (session, PG spotjson)
 *                geojson       straboOutputClass::geoJSONOut (decision D2: raw)
 *                version       createVersion snapshot file (+ /versionsdb/version
 *                              must serve the same JSON)
 *                search_*      strabosearch item_hit / image_hit rows
 *                js_*          StraboFieldDatasetDetail/api/spots.php,
 *                              stratSectionDetail/getData.php, doi/geoJSON.php,
 *                              doi/doiproject.php + doi/doisearch.php (read a
 *                              data.json written the way build_doi.php does)
 *                sample        GET /samplesdb/sample/{id} (field_data verbatim)
 *              Plus: switchVersion restore round trip (datasetspots after a
 *              restore == before), and a label-leak scan (no distinctive
 *              label of a fixture name may appear as a value anywhere).
 *
 *              Comparison is on a CANONICAL form, because several surfaces
 *              are nondeterministic by design: lists of objects with ids are
 *              sorted by id (datasets, features, images, tags come back
 *              unordered), request-time values (backupFileName, uploaddate,
 *              created_at, ...) become <VOLATILE>, and the fixture user's
 *              pkey (recreated by setup_test_data.php) becomes <OWNER>. Choice
 *              values are compared exactly.
 *
 *              Needs the test users from tests/collaboration/setup_test_data.php.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/fieldvocab/golden_guard.php            # compare
 *                docker exec strabo-php php /srv/app/www/tests/fieldvocab/golden_guard.php --record   # (re)write goldens
 *                  --keep   leave the fixture in place afterwards (debugging)
 *                  --simulate-leak  self-test: rewrite option_13 -> "joint" in the
 *                           captured doidata; the golden compare AND the leak scan
 *                           must both fail (exit 1)
 *
 *              Record ONLY on a tree whose outputs are known good (before any
 *              translation consumer is wired, or after reviewing the diff).
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
require_once __DIR__ . '/golden_fixture.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

$RECORD = in_array('--record', $argv, true);
$KEEP = in_array('--keep', $argv, true);
$SIMULATE_LEAK = in_array('--simulate-leak', $argv, true);   // self-test: plant a label in one surface, the run must FAIL
$GOLDEN = __DIR__ . '/golden';
$EMAIL = 'owner@test.strabospot.org';
$UP = "$EMAIL:testpass123";
$DOI_UUID = '97791000-0000-4000-8000-000000000001';
$SESSDIR = '/var/lib/php/sessions';
$SID = 'fvgolden' . getmypid();

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

/* ------------------------------------------------------------- canonical */

$VOLATILE = array('backupFileName', 'uploaddate', 'datecreated', 'created_at', 'modified_at', 'last_synced',
	'selectedDatasetId', 'item_hit_pkey', 'image_hit_pkey', 'uuid');
$SORT_SCALARS = array('activeDatasetsIds', 'dataset_ids');

function canon_walk($v, $key = null) {
	global $VOLATILE, $SORT_SCALARS, $OWNER;
	if (is_array($v)) {
		if ($key !== null && in_array($key, $SORT_SCALARS, true)) {
			$v = array_map('canon_walk', $v);
			sort($v);
			return $v;
		}
		$isList = $v === array() || array_keys($v) === range(0, count($v) - 1);
		if (!$isList) ksort($v, SORT_STRING);   // object key order is not data (Neo4j returns properties in insertion order)
		$out = array();
		foreach ($v as $k => $x) {
			if (!$isList && in_array((string)$k, $VOLATILE, true) && $x !== null && $x !== '') { $out[$k] = '<VOLATILE>'; continue; }
			$out[$k] = canon_walk($x, $isList ? $key : (string)$k);
		}
		if ($isList && $out && count(array_filter($out, 'canon_sort_key')) === count($out)) {
			usort($out, function ($a, $b) { return strcmp(canon_sort_key($a), canon_sort_key($b)); });
		}
		return $out;
	}
	if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (string)$v === (string)$OWNER) return '<OWNER>';
	if (is_string($v) && strpos($v, $OWNER . '-') === 0) return '<OWNER>-' . substr($v, strlen($OWNER . '-'));
	return $v;
}

/** Sort key for a list element: its id, or properties.id for a feature; '' = not sortable. */
function canon_sort_key($x) {
	if (!is_array($x)) return '';
	if (isset($x['id']) && is_scalar($x['id'])) return sprintf('%020s', (string)$x['id']);
	if (isset($x['properties']['id']) && is_scalar($x['properties']['id'])) return sprintf('%020s', (string)$x['properties']['id']);
	if (isset($x['item_type'], $x['item_id'])) return $x['item_type'] . ':' . sprintf('%020s', (string)$x['item_id']);
	if (isset($x['image_id'])) return sprintf('%020s', (string)$x['image_id']);
	return '';
}

/** Canonical JSON text of decoded data. */
function canon($data) {
	return json_encode(canon_walk($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/**
 * Canonical text of a JSON document; null when it does not parse. PHP
 * warnings printed before the JSON (doi/doiproject.php has a pre-existing
 * one) are skipped: the guard is about the data, not that noise.
 */
function canon_text($text) {
	$d = json_decode($text, true);
	if ($d === null && preg_match('/^\s*<br \/>.*?(?=[\[{])/s', $text, $m)) $d = json_decode(substr($text, strlen($m[0])), true);
	return $d === null ? null : canon($d);
}

/** Remove object keys whose value is "" (recursively). */
function drop_empty($v) {
	if (!is_array($v)) return $v;
	$out = array();
	foreach ($v as $k => $x) { if ($x === '' && !is_int($k)) continue; $out[$k] = drop_empty($x); }
	return $out;
}

/** Unified diff of two texts (first 40 lines), for failure details. */
function text_diff($want, $got) {
	$f1 = tempnam(sys_get_temp_dir(), 'fvw_'); $f2 = tempnam(sys_get_temp_dir(), 'fvg_');
	file_put_contents($f1, (string)$want); file_put_contents($f2, (string)$got);
	$d = trim((string)shell_exec('diff -u ' . escapeshellarg($f1) . ' ' . escapeshellarg($f2) . ' | head -40'));
	unlink($f1); unlink($f2);
	return str_replace("\n", "\n        ", $d);
}

/* --------------------------------------------------------------- cleanup */

function cleanup() {
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
	@unlink("$SESSDIR/sess_$SID");
	if (!empty($GLOBALS['CREATED_USER_NODE'])) $neodb->query("MATCH (u:User {userpkey: $OWNER}) WHERE NOT (u)-[:HAS_PROJECT]->() DETACH DELETE u");
	foreach (glob('/srv/app/www/versions/images/97791*') as $f) @unlink($f);
}

/* ----------------------------------------------------------------- main */

$captured = array();   // surface => canonical text
function capture($name, $canonText, $what = '') {
	global $captured;
	$ok = $canonText !== null && trim($canonText) !== '' && trim($canonText) !== 'null';
	check("capture $name" . ($what !== '' ? " ($what)" : ''), $ok);
	if ($ok) $captured[$name] = $canonText;
}

echo "Field vocab golden guard (" . ($RECORD ? 'RECORD' : 'compare') . ")\n";
cleanup();

try {
	section('Fixture upload (the app sequence, HTTP Basic)');
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

	$S = gf_spots();
	section('REST (/db/)');
	list($st, $tx) = http('GET', '/db/project/' . GF_PROJECT);           capture('rest_project', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/db/projectDatasets/' . GF_PROJECT);   capture('rest_project_datasets', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/db/dataset/' . GF_DS_A);             capture('rest_dataset_a', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/db/datasetspots/' . GF_DS_A);        capture('rest_datasetspots_a', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/db/datasetspots/' . GF_DS_B);        capture('rest_datasetspots_b', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/db/feature/' . $S['structure']);     capture('rest_feature_structure', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/db/feature/' . $S['interval']);      capture('rest_feature_interval', $st === 200 ? canon_text($tx) : null, "HTTP $st");

	section('Output class (app export, project geojson, geojson download)');
	$o = new straboOutputClass(fresh_strabo(), array());
	$doi = $o->doiDataOut(GF_PROJECT);
	capture('doidata', canon(json_decode(json_encode($doi), true)));
	$o = new straboOutputClass(fresh_strabo(), array());
	capture('projectgeojson', canon(json_decode(json_encode($o->projectGeoJSONOut(GF_PROJECT)), true)));
	$o = new straboOutputClass(fresh_strabo(), array('dsids' => GF_DS_A . ',' . GF_DS_B, 'userpkey' => $OWNER));
	ob_start(); $o->geoJSONOut(); $gj = ob_get_clean();
	capture('geojson', canon_text($gj));

	section('Session pages');
	list($st, $tx) = http('GET', '/projectjson/' . GF_PROJECT, null, 'session');   capture('projectjson', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/spot/' . $S['structure'], null, 'session');     capture('spotjson_structure', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/spot/' . $S['interval'], null, 'session');      capture('spotjson_interval', $st === 200 ? canon_text($tx) : null, "HTTP $st");

	section('JS feeds');
	list($st, $tx) = http('GET', '/StraboFieldDatasetDetail/api/spots.php?dataset_id=' . GF_DS_A, null, 'none');   capture('js_datasetdetail_spots_a', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/stratSectionDetail/getData.php?spot_id=' . $S['sedbase'], null, 'none');        capture('js_stratsection', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', '/doi/geoJSON.php?datasetid=' . GF_DS_A, null, 'none');                          capture('js_doi_geojson_a', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	$doiDir = "/srv/app/www/doi/doiFiles/$DOI_UUID";
	@mkdir($doiDir, 0775, true);
	file_put_contents("$doiDir/data.json", json_encode($doi, JSON_PRETTY_PRINT));   // exactly how build_doi.php writes it
	list($st, $tx) = http('GET', "/doi/doiproject.php?u=$DOI_UUID", null, 'none');                                capture('js_doiproject', $st === 200 ? canon_text($tx) : null, "HTTP $st");
	list($st, $tx) = http('GET', "/doi/doisearch.php?u=$DOI_UUID-" . GF_DS_A, null, 'none');                      capture('js_doisearch_a', $st === 200 ? canon_text($tx) : null, "HTTP $st");

	section('StraboSamples');
	list($st, $tx) = http('GET', "/samplesdb/sample/977910000301?owner=$OWNER");
	capture('sample', $st === 200 ? canon_text($tx) : null, "HTTP $st");

	section('Search index');
	$rows = $db->get_results_prepared("SELECT item_type,item_id,project_id,project_name,project_ispublic,ST_AsText(location) AS location,date_value,
		searchtext_tsv::text AS searchtext,has_orientation,has_samples,has_images,has_strat,orientation_strike,orientation_dip,
		orientation_trend,orientation_plunge,orientation_features,orientation_planar,rock_types,met_facies,trace_types,
		tag_names,tag_types,dataset_ids,sample_id,sample_name,igsn,display_sample_type,display_sample_purpose
		FROM strabosearch.item_hit WHERE project_subsystem='field' AND project_id=$1 AND project_userpkey=$2
		ORDER BY item_type,item_id", array((string)GF_PROJECT, $OWNER));
	capture('search_item_hit', canon(json_decode(json_encode($rows ?: array()), true)), count($rows ?: array()) . ' rows');
	$rows = $db->get_results_prepared("SELECT image_id,image_type,annotated,title,caption,parent_spot_id,parent_sample_id,orientation_features,
		rock_types,met_facies,trace_types,tag_names FROM strabosearch.image_hit
		WHERE project_subsystem='field' AND project_id=$1 AND project_userpkey=$2 ORDER BY image_id", array((string)GF_PROJECT, $OWNER));
	capture('search_image_hit', canon(json_decode(json_encode($rows ?: array()), true)), count($rows ?: array()) . ' rows');

	section('Version snapshot + restore round trip');
	$uuid = fresh_strabo()->createVersion(GF_PROJECT);
	$vfile = "/srv/app/www/versions/$uuid";
	$snap = is_file($vfile) ? gzdecode(file_get_contents($vfile)) : null;
	capture('version', $snap !== null ? canon_text($snap) : null, 'createVersion file');
	list($st, $tx) = http('GET', "/versionsdb/version/$uuid");
	check('/versionsdb/version serves the snapshot JSON', $st === 200 && $snap !== null && canon_text($tx) === canon_text($snap), "HTTP $st");
	$before = array($captured['rest_datasetspots_a'] ?? null, $captured['rest_datasetspots_b'] ?? null, $captured['rest_project'] ?? null);
	$GLOBALS['IMAGE_FILES'][] = (string)$neodb->get_var("MATCH (i:Image {id: 977910000401}) WHERE i.userpkey = $OWNER RETURN i.filename");   // the restore orphans it
	fresh_strabo()->switchVersion($uuid);
	list(, $a) = http('GET', '/db/datasetspots/' . GF_DS_A);
	list(, $b) = http('GET', '/db/datasetspots/' . GF_DS_B);
	// A restore drops empty-string properties (an image's modified_timestamp ""): an old quirk
	// unrelated to choice values, so the round trip compares with those keys removed.
	$noEmpty = function ($t) { return canon(drop_empty(json_decode((string)$t, true))); };
	check('restore: dataset A spots unchanged', $noEmpty($a) === $noEmpty($before[0]), text_diff($noEmpty($before[0]), $noEmpty($a)));
	check('restore: dataset B spots unchanged', $noEmpty($b) === $noEmpty($before[1]), text_diff($noEmpty($before[1]), $noEmpty($b)));

	if ($SIMULATE_LEAK && isset($captured['doidata'])) {
		$captured['doidata'] = str_replace('"option_13"', '"joint"', $captured['doidata']);
		echo "\n  (--simulate-leak: planted \"joint\" for option_13 in doidata)\n";
	}

	section('Label-leak scan (no distinctive label of a fixture name in any round-trip output)');
	$names = array();
	$fx = array(gf_project(), gf_features());
	array_walk_recursive($fx, function ($v) use (&$names) { if (is_string($v)) $names[$v] = true; });
	$labels = array();   // distinctive label => the name it belongs to
	foreach (FieldVocab::map()['forms'] as $fk => $form) {
		foreach ($form['fields'] as $fn => $f) {
			foreach ($f['choices'] as $n => $l) {
				$n = (string)$n;
				if (!isset($names[$n]) || $l === $n || isset($names[$l])) continue;
				if ($l === str_replace('_', ' ', $n)) continue;   // the app fallback text is not a distinctive label
				$labels[$l] = "$fk.$fn $n";
			}
		}
	}
	$leaks = array();
	foreach ($captured as $surface => $text) {
		$d = json_decode($text, true);
		array_walk_recursive($d, function ($v) use (&$leaks, $labels, $surface) {
			if (is_string($v) && isset($labels[$v])) $leaks[] = "$surface: \"$v\" (label of {$labels[$v]})";
		});
	}
	check('scanned ' . count($labels) . ' distinctive labels over ' . count($captured) . ' surfaces: no leaks', !$leaks, implode("\n        ", array_slice($leaks, 0, 20)));

	section($RECORD ? 'Record goldens' : 'Compare with goldens');
	if ($RECORD && $SIMULATE_LEAK) {
		check('--record and --simulate-leak together', false, 'refusing to record a planted leak');
	} elseif ($RECORD) {
		if (!is_dir($GOLDEN)) mkdir($GOLDEN, 0775, true);
		foreach (glob("$GOLDEN/*.json") as $f) unlink($f);
		foreach ($captured as $name => $text) file_put_contents("$GOLDEN/$name.json", $text);
		echo '  wrote ' . count($captured) . " golden files to $GOLDEN\n";
	} else {
		$want = array_map(function ($f) { return basename($f, '.json'); }, glob("$GOLDEN/*.json"));
		check('golden set present', count($want) > 0, 'run with --record first');
		foreach (array_diff($want, array_keys($captured)) as $missing) check("surface $missing captured", false);
		foreach ($captured as $name => $text) {
			$gf = "$GOLDEN/$name.json";
			if (!is_file($gf)) { check("golden $name exists", false, 'new surface: review, then --record'); continue; }
			$same = file_get_contents($gf) === $text;
			$diff = '';
			if (!$same) {
				$tmp = sys_get_temp_dir() . "/fvg_actual_$name.json";
				file_put_contents($tmp, $text);
				$diff = trim(shell_exec('diff -u ' . escapeshellarg($gf) . ' ' . escapeshellarg($tmp) . ' | head -40'));
				$diff .= "\n        (actual kept at $tmp)";
			}
			check("$name matches golden", $same, str_replace("\n", "\n        ", $diff));
		}
	}
} catch (Throwable $e) {
	check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
	if ($KEEP) echo "\n(--keep: fixture left in place, project " . GF_PROJECT . ")\n";
	else cleanup();
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
