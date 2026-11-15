<?php
/**
 * File: tests/spotdelete/shared_nodes_test.php
 * Description: Suite for StraboSpot::deleteSingleSpot: deleting a spot (which
 *              every dataset upload does to each spot before inserting the
 *              uploaded copy) must remove that spot and what belongs to it
 *              alone, and nothing another spot still uses.
 *
 *              The old query deleted everything reachable below the spot,
 *              however many links away. That removed a photo record shared
 *              with a spot in another dataset, and through a leftover
 *              IS_RELATED_TO link it removed the other spot itself.
 *
 *              Everything goes through the real Field API (Basic auth, as
 *              the app does): a project with two datasets; spot A (dataset 1)
 *              and spot B (dataset 2) list the same photo; spot C (dataset 1)
 *              has a photo of its own and a measurement with an associated
 *              measurement.
 *
 *              Checked: uploading dataset 1 alone leaves B's photo in place;
 *              a spot's own records are still removed with it (no orphans, no
 *              doubles on a second upload); a shared photo goes when its last
 *              spot goes; a leftover spot to spot link or tag link no longer
 *              takes another spot along; DELETE /db/feature behaves the same.
 *
 *              Fixture: user 94741. Everything is removed again at the start
 *              and the end.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/spotdelete/shared_nodes_test.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';

$PK = 94741;
$EMAIL = 'spotdelete94741@example.org';
$PW = 'spotdelete-pass-94741';
$BASE = 'http://localhost';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

function cleanup() {
	global $db, $neodb, $PK;
	$neodb->query("MATCH (n) WHERE n.userpkey = $PK DETACH DELETE n");
	$arr = '{' . $PK . '}';
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE item_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE image_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = ANY($1)", array($arr));
	$rows = $db->get_results_prepared("select uuid from versions where userpkey = $1", array($PK));
	if ($rows) foreach ($rows as $r) { if (preg_match('/^[0-9a-f-]{36}$/i', (string)$r->uuid)) @unlink('/srv/app/www/versions/' . $r->uuid); }
	$db->prepare_query("delete from versions where userpkey = $1", array($PK));
	$db->prepare_query("delete from users where pkey = $1", array($PK));
}

function api($method, $path, $json = null) {
	global $BASE, $EMAIL, $PW;
	$ch = curl_init("$BASE/db/$path");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_USERPWD, "$EMAIL:$PW");
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_TIMEOUT, 120);
	if ($json !== null) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
	}
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array($code, json_decode($body, true), $body);
}

/** Count nodes of one label owned by the fixture user. */
function nodes($label, $where = '') {
	global $neodb, $PK;
	return (int)$neodb->get_var("MATCH (n:$label) WHERE n.userpkey = $PK $where RETURN count(n)");
}
/** The spots of a dataset as the app downloads them, keyed by spot id. */
function spots($datasetId) {
	list($code, $json) = api('GET', "datasetSpots/$datasetId");
	$out = array();
	if (is_array($json) && isset($json['features'])) foreach ($json['features'] as $f) $out[(string)$f['properties']['id']] = $f['properties'];
	return $out;
}
function image_ids($props) {
	$ids = array();
	if (isset($props['images']) && is_array($props['images'])) foreach ($props['images'] as $i) $ids[] = (string)$i['id'];
	sort($ids);
	return $ids;
}

$base = 97410000000000 + mt_rand(0, 9999) * 10000;
$P = $base + 1; $D1 = $base + 2; $D2 = $base + 3;
$A = $base + 101; $B = $base + 102; $C = $base + 103;
$SHARED = $base + 501; $OWN = $base + 502;
$ts = time() * 1000;

function point($id, $name, $extra, $ts) {
	return array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-105.27, 39.92)),
		'properties' => array_merge(array('id' => $id, 'name' => $name, 'date' => '2027-06-01T15:00:00.000Z', 'modified_timestamp' => $ts), $extra));
}
function spotA($ts) { global $A, $SHARED, $base; return point($A, 'A', array(
	'images' => array(array('id' => $SHARED, 'image_type' => 'photo', 'title' => 'shared photo')),
	'orientation_data' => array(array('id' => $base + 601, 'type' => 'planar_orientation', 'strike' => 120, 'dip' => 35))), $ts); }
function spotB($ts) { global $B, $SHARED; return point($B, 'B', array(
	'notes' => 'spot B keeps its photo',
	'images' => array(array('id' => $SHARED, 'image_type' => 'photo', 'title' => 'shared photo'))), $ts); }
function spotC($ts) { global $C, $OWN, $base; return point($C, 'C', array(
	'images' => array(array('id' => $OWN, 'image_type' => 'photo', 'title' => 'own photo')),
	'orientation_data' => array(array('id' => $base + 602, 'type' => 'planar_orientation', 'strike' => 200, 'dip' => 60,
		'associated_orientation' => array(array('id' => $base + 603, 'type' => 'linear_orientation', 'trend' => 110, 'plunge' => 20)))),
	'samples' => array(array('id' => $base + 604, 'sample_id_name' => 'SD-1', 'label' => 'SD-1'))), $ts); }

/** Upload one dataset as the app does. Returns the HTTP codes. */
function upload_dataset($datasetId, $name, $features, $ts) {
	global $P;
	$codes = array();
	list($codes[]) = api('POST', 'dataset', array('id' => $datasetId, 'name' => $name, 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts));
	list($codes[]) = api('POST', "projectDatasets/$P", array('id' => $datasetId));
	list($codes[]) = api('POST', "datasetspots/$datasetId", array('type' => 'FeatureCollection', 'features' => $features));
	return $codes;
}
function upload_project($ts) {
	global $P, $D1;
	list($code) = api('POST', 'project', array('id' => $P, 'date' => '2027-06-01T12:00:00.000Z', 'modified_timestamp' => $ts,
		'description' => array('project_name' => 'Spot delete test'), 'preferences' => array('active_dataset_id' => $D1)));
	return $code;
}

cleanup();
$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'Spot', 'Delete', crypt($2, gen_salt('md5')), $3, $4, true)",
	array($PK, $PW, 'spotdeletehash' . $PK, $EMAIL));
$neodb->query("CREATE (:User {userpkey: $PK, email: '$EMAIL'})");

try {

section('Fixture: two datasets, spots A and B share a photo, spot C has its own');
$codes = array(upload_project($ts));
$codes = array_merge($codes, upload_dataset($D1, 'One', array(spotA($ts), spotC($ts)), $ts));
$codes = array_merge($codes, upload_dataset($D2, 'Two', array(spotB($ts)), $ts));
check('the project uploads through the API', !array_diff($codes, array(200, 201)), $codes);
$s1 = spots($D1); $s2 = spots($D2);
check('dataset 1 holds A and C, dataset 2 holds B', isset($s1[(string)$A], $s1[(string)$C], $s2[(string)$B]) && count($s1) === 2 && count($s2) === 1, array(array_keys($s1), array_keys($s2)));
check('A and B both list the shared photo', image_ids($s1[(string)$A]) === array((string)$SHARED) && image_ids($s2[(string)$B]) === array((string)$SHARED), array(image_ids($s1[(string)$A]), image_ids($s2[(string)$B])));
check('the shared photo is one record', nodes('Image', "AND n.id = $SHARED") === 1);
$ori0 = nodes('Orientation'); $smp0 = nodes('Sample'); $img0 = nodes('Image');
check('C has two measurements and a sample on record', $ori0 === 3 && $smp0 === 1 && $img0 === 2, array($ori0, $smp0, $img0));

section('Upload dataset 1 alone (dataset 2 is not part of the upload)');
$ts2 = $ts + 60000;
$codes = array(upload_project($ts2));
$codes = array_merge($codes, upload_dataset($D1, 'One', array(spotA($ts2), spotC($ts2)), $ts2));
check('the upload is accepted', !array_diff($codes, array(200, 201)), $codes);
$s1 = spots($D1); $s2 = spots($D2);
check('spot B is still there', isset($s2[(string)$B]) && $s2[(string)$B]['notes'] === 'spot B keeps its photo', array_keys($s2));
check('spot B still lists the shared photo', isset($s2[(string)$B]) && image_ids($s2[(string)$B]) === array((string)$SHARED), isset($s2[(string)$B]) ? image_ids($s2[(string)$B]) : 'no B');
check('spot A still lists the shared photo', isset($s1[(string)$A]) && image_ids($s1[(string)$A]) === array((string)$SHARED));
check('the shared photo is still one record', nodes('Image', "AND n.id = $SHARED") === 1, nodes('Image', "AND n.id = $SHARED"));
check('a second upload does not double a spot\'s own records', nodes('Orientation') === $ori0 && nodes('Sample') === $smp0 && nodes('Image') === $img0, array(nodes('Orientation'), nodes('Sample'), nodes('Image')));
check('C keeps its measurement with the associated one', isset($s1[(string)$C]['orientation_data'][0]['associated_orientation'][0]['trend']) && (int)$s1[(string)$C]['orientation_data'][0]['associated_orientation'][0]['trend'] === 110);

section('A spot left out of an upload goes, with what was its own');
$ts3 = $ts2 + 60000;
upload_project($ts3);
$codes = upload_dataset($D1, 'One', array(spotA($ts3)), $ts3);   // C is left out
check('the upload is accepted', !array_diff($codes, array(200, 201)), $codes);
$s1 = spots($D1); $s2 = spots($D2);
check('C is gone, A stays', !isset($s1[(string)$C]) && isset($s1[(string)$A]), array_keys($s1));
check('C\'s own photo record is gone', nodes('Image', "AND n.id = $OWN") === 0);
check('C\'s measurements and sample are gone (no orphans)', nodes('Orientation') === 1 && nodes('Sample') === 0, array(nodes('Orientation'), nodes('Sample')));
check('spot B still lists the shared photo', isset($s2[(string)$B]) && image_ids($s2[(string)$B]) === array((string)$SHARED));

section('Leftover links from older data: spot to spot, and tag');
// Old projects still hold these links; nothing creates them today.
$neodb->query("MATCH (a:Spot {id: $A, userpkey: $PK}), (b:Spot {id: $B, userpkey: $PK}) CREATE (a)-[:IS_RELATED_TO {userpkey: $PK}]->(b)");
$neodb->query("MATCH (a:Spot {id: $A, userpkey: $PK}), (b:Spot {id: $B, userpkey: $PK}) CREATE (t:Tag {id: " . ($base + 701) . ", userpkey: $PK, name: 'old tag'}), (a)-[:IS_TAGGED {userpkey: $PK}]->(t), (b)-[:IS_TAGGED {userpkey: $PK}]->(t)");
check('the leftover links are in place', (int)$neodb->get_var("MATCH (a:Spot {id: $A, userpkey: $PK})-[r:IS_RELATED_TO|IS_TAGGED]->() RETURN count(r)") === 2);
$ts4 = $ts3 + 60000;
upload_project($ts4);
$codes = upload_dataset($D1, 'One', array(spotA($ts4)), $ts4);
check('the upload is accepted', !array_diff($codes, array(200, 201)), $codes);
$s2 = spots($D2);
check('spot B survives a link from A', isset($s2[(string)$B]) && $s2[(string)$B]['notes'] === 'spot B keeps its photo', array_keys($s2));
check('spot B still lists the shared photo', isset($s2[(string)$B]) && image_ids($s2[(string)$B]) === array((string)$SHARED));
check('dataset 2 still holds exactly one spot', count($s2) === 1, array_keys($s2));

section('Deleting one spot through DELETE /db/feature');
list($code) = api('DELETE', "feature/$A");
check('the delete is accepted', $code >= 200 && $code < 300, $code);
$s1 = spots($D1); $s2 = spots($D2);
check('A is gone', !isset($s1[(string)$A]), array_keys($s1));
check('A\'s measurement is gone', nodes('Orientation') === 0, nodes('Orientation'));
check('spot B still lists the shared photo', isset($s2[(string)$B]) && image_ids($s2[(string)$B]) === array((string)$SHARED));
check('the shared photo record remains while B uses it', nodes('Image', "AND n.id = $SHARED") === 1);

section('The shared photo goes with its last spot');
list($code) = api('DELETE', "feature/$B");
check('the delete is accepted', $code >= 200 && $code < 300, $code);
check('B is gone', nodes('Spot') === 0, nodes('Spot'));
check('the photo record is gone with it', nodes('Image') === 0, nodes('Image'));

} catch (Throwable $e) {
	$fail++;
	echo "  FAIL  exception: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
} finally {
	cleanup();
}

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES: $fail") . "  ($pass passed, $fail failed)\n";
exit($fail === 0 ? 0 : 1);
