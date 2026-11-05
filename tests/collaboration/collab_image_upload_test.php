<?php
/**
 * Collaborator image upload lands on the project owner's Image node (2026-11-05, Jessica).
 *
 * StraboField saves the spot first (POST /db/datasetspots): in a collaborative project the spot and its
 * Image node are stored under the project OWNER, with no filename yet. The app then asks verifyImages,
 * gets "missing", and uploads the file (POST /db/image). The old insertImage only looked for an Image
 * owned by the uploader, so it made a second node (uploader's, linked to no spot) and the owner's node
 * never got its file: verifyImages kept answering "missing" and the app uploaded forever.
 *
 * Drives the real endpoints with Basic Auth. Hermetic: seeds throwaway users, project, datasets and
 * collaborators rows, removes everything (nodes, rows, image files) at the end.
 *
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/collab_image_upload_test.php
 */

include "/srv/app/www/includes/config.inc.php";
include "/srv/app/www/db.php";
include "/srv/app/www/neodb.php";
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

$BASE = "http://localhost/db";
$PASS = "testpass123";
$rand = substr(bin2hex(random_bytes(4)), 0, 8);
$pass = 0; $fail = 0; $fails = array();

function ok($c, $m){ global $pass, $fail, $fails; if($c){ $pass++; echo "  PASS  $m\n"; } else { $fail++; $fails[] = $m; echo "  FAIL  $m\n"; } }
function section($t){ echo "\n== $t ==\n"; }

$users = array();
function seedUser($tag){
	global $db, $neodb, $PASS, $rand, $users;
	$email = "collabimg-$tag-$rand@test.strabospot.org";
	$db->prepare_query("INSERT INTO users (firstname,lastname,password,hash,email,active,deleted) VALUES ($1,$2,crypt($3,gen_salt('md5')),$4,$5,true,false)",
		array("CI", $tag, $PASS, substr(md5($email), 0, 21), $email));
	$pk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1", array($email));
	$neodb->query("CREATE (:User {userpkey: $pk, email: '$email'})");
	$users[$tag] = array('pk' => $pk, 'email' => $email);
	return $pk;
}

function api($method, $path, $who, $json = null, $multipart = null){
	global $BASE, $PASS, $users;
	$ch = curl_init("$BASE/$path");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_USERPWD, $users[$who]['email'] . ":$PASS");
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	if($json !== null){
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
	}
	if($multipart !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array($code, json_decode($body, true), $body);
}

$tmpimg = tempnam(sys_get_temp_dir(), 'ci') . '.jpg';
$im = imagecreatetruecolor(8, 8); imagejpeg($im, $tmpimg); imagedestroy($im);

function upload($who, $imageId){
	global $tmpimg;
	return api('POST', 'image', $who, null, array(
		'id' => (string)$imageId, 'modified_timestamp' => (string)(time() * 1000),
		'image_file' => new CURLFile($tmpimg, 'image/jpeg', "photo_$imageId.jpg")));
}

function saveSpot($who, $datasetId, $spotId, $imageId){
	return api('POST', "datasetspots/$datasetId", $who, array('type' => 'FeatureCollection', 'features' => array(array(
		'type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array(-111.5, 35.2)),
		'properties' => array('id' => $spotId, 'name' => "spot $spotId", 'date' => '2026-11-05T12:00:00.000Z',
			'modified_timestamp' => time() * 1000, 'images' => array(array('id' => $imageId, 'image_type' => 'photo')))))));
}

// Image nodes with this id: list of (userpkey, filename, linked-to-spot, file on disk)
function nodes($imageId){
	global $neodb;
	$out = array();
	foreach($neodb->get_results("MATCH (i:Image) WHERE i.id = $imageId OPTIONAL MATCH (s:Spot)-[:HAS_IMAGE]->(i)
		RETURN i.userpkey AS pk, i.filename AS f, i.created_by AS cb, count(s) AS links") ?: array() as $r){
		$f = (string)$r->get('f');
		$out[] = array('pk' => (int)$r->get('pk'), 'f' => $f, 'cb' => (int)$r->get('cb'), 'linked' => $r->get('links') > 0,
			'file' => $f !== '' && file_exists("/srv/app/www/dbimages/$f"));
	}
	return $out;
}
function nodeOf($imageId, $pk){ foreach(nodes($imageId) as $n) if($n['pk'] == $pk) return $n; return null; }
function verify($who, $imageId){ list($c, $j) = api('POST', 'verifyImages', $who, array($imageId)); return $j; }

// ---- seed -------------------------------------------------------------------------------------
$owner = seedUser('owner'); $editor = seedUser('editor'); $ro = seedUser('readonly'); $out = seedUser('outsider');
$base = 97796000000000 + mt_rand(0, 99999) * 1000;
$P = $base + 1; $DO = $base + 2; $DE = $base + 3; $PS = $base + 4; $DS = $base + 5;
$ts = time() * 1000;
$neodb->query("CREATE (p:Project {id: $P, desc_project_name: 'CI collab', userpkey: $owner, modified_timestamp: $ts})
	CREATE (p)-[:HAS_DATASET]->(:Dataset {id: $DO, name: 'owner ds', userpkey: $owner, created_by: $owner, modified_timestamp: $ts})
	CREATE (p)-[:HAS_DATASET]->(:Dataset {id: $DE, name: 'editor ds', userpkey: $owner, created_by: $editor, modified_timestamp: $ts})");
$neodb->query("CREATE (p:Project {id: $PS, desc_project_name: 'CI solo', userpkey: $owner, modified_timestamp: $ts})
	CREATE (p)-[:HAS_DATASET]->(:Dataset {id: $DS, name: 'solo ds', userpkey: $owner, created_by: $owner, modified_timestamp: $ts})");
foreach(array(array($editor, 'edit'), array($ro, 'readonly')) as $c){
	$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, uuid, disabled)
		VALUES ($1,$2,$3,$4,true,$5,false)", array((string)$P, $owner, $c[0], $c[1], "ci-$rand-" . $c[0]));
}
$I = function($n) use ($base){ return $base + 100 + $n; };   // image ids
$S = function($n) use ($base){ return $base + 200 + $n; };   // spot ids

try {

section("1. editor: spot first, then image (Jessica's sequence)");
list($c) = saveSpot('editor', $DE, $S(1), $I(1));
ok($c == 200 || $c == 201, "editor saves spot with image in own dataset (HTTP $c)");
$n = nodeOf($I(1), $owner);
ok($n && $n['linked'] && $n['f'] === '', "spot upload stored the image under the owner, no file yet");
ok(verify('editor', $I(1)) == array((string)$I(1)), "verifyImages says missing before upload");
list($c, $j) = upload('editor', $I(1));
ok($c == 201, "editor uploads the image (HTTP $c)");
$n = nodeOf($I(1), $owner);
ok($n && $n['f'] !== '' && $n['file'], "owner's node now has the file");
ok($n && $n['cb'] == $editor, "created_by stays the editor");
ok(nodeOf($I(1), $editor) === null, "no Image node of the editor's own was made");
ok(count(nodes($I(1))) == 1, "exactly one Image node with this id");
ok(verify('editor', $I(1)) === array(), "verifyImages now says present (no re-upload)");
$f1 = $n['f'];
list($c) = upload('editor', $I(1));
ok($c == 201 && count(nodes($I(1))) == 1 && nodeOf($I(1), $owner)['f'] === $f1, "second upload reuses the same node and filename");
list($c, $j) = api('GET', "image/" . $I(1), 'owner');
ok($c == 200, "owner can fetch the image (HTTP $c)");

section("2. editor already has an orphan node from the old code");
saveSpot('editor', $DE, $S(2), $I(2));
$neodb->query("CREATE (:Image {id: " . $I(2) . ", userpkey: $editor, created_by: $editor, filename: 'ci-orphan-$rand'})");
upload('editor', $I(2));
$n = nodeOf($I(2), $owner);
ok($n && $n['file'], "owner's node gets the file even with an orphan present");
ok(verify('editor', $I(2)) === array(), "verifyImages says present");
$o = nodeOf($I(2), $editor);
ok($o && !$o['linked'] && $o['f'] === "ci-orphan-$rand", "orphan left as it was (repair is separate)");

section("3. readonly collaborator and outsider cannot fill the owner's node");
saveSpot('editor', $DE, $S(3), $I(3));
upload('readonly', $I(3));
ok(nodeOf($I(3), $owner)['f'] === '', "readonly upload leaves the owner's node alone");
upload('outsider', $I(3));
ok(nodeOf($I(3), $owner)['f'] === '', "outsider upload leaves the owner's node alone");

section("4. editor cannot fill an image in a dataset the editor did not create");
list($c) = saveSpot('owner', $DO, $S(4), $I(4));
ok($c == 200 || $c == 201, "owner saves spot in own dataset (HTTP $c)");
upload('editor', $I(4));
ok(nodeOf($I(4), $owner)['f'] === '', "editor upload leaves the owner's dataset image alone");
upload('owner', $I(4));
ok(nodeOf($I(4), $owner)['file'] && count(nodes($I(4))) == 2, "owner's own upload fills it (editor's stray node is the 2nd)");

section("5. owner, no collaboration (unchanged path)");
saveSpot('owner', $DS, $S(5), $I(5));
list($c) = upload('owner', $I(5));
$n = nodeOf($I(5), $owner);
ok($c == 201 && $n && $n['file'] && count(nodes($I(5))) == 1, "owner upload fills own node, one node");
ok(verify('owner', $I(5)) === array(), "verifyImages says present");
list($c) = upload('owner', $I(6));
$n = nodeOf($I(6), $owner);
ok($c == 201 && $n && $n['file'] && !$n['linked'], "image uploaded before its spot: own unlinked node, as before");
saveSpot('owner', $DS, $S(6), $I(6));
$n = nodeOf($I(6), $owner);
ok($n && $n['linked'] && $n['file'] && count(nodes($I(6))) == 1, "then the spot links that node");

section("6. halted collaboration");
saveSpot('editor', $DE, $S(7), $I(7));
$db->prepare_query("UPDATE collaborators SET disabled = true WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array((string)$P, $owner));
upload('editor', $I(7));
ok(nodeOf($I(7), $owner)['f'] === '', "halted editor upload leaves the owner's node alone");

} finally {
	section("cleanup");
	$pks = implode(',', array_map(function($u){ return (int)$u['pk']; }, $users));
	$files = array();
	foreach($neodb->get_results("MATCH (i:Image) WHERE i.userpkey IN [$pks] RETURN i.filename AS f") ?: array() as $r){
		if($r->get('f') != '') $files[] = (string)$r->get('f');
	}
	foreach(array_unique($files) as $f){ if(preg_match('/^\d+$/', $f) && file_exists("/srv/app/www/dbimages/$f")) unlink("/srv/app/www/dbimages/$f"); }
	$neodb->query("MATCH (n) WHERE n.userpkey IN [$pks] DETACH DELETE n");
	// spot uploads also write the PG mirror (project, dataset cascades) and the StraboSearch index
	$arr = "{" . $pks . "}";
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE item_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE image_userpkey = ANY($1) OR project_userpkey = ANY($1)", array($arr));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = ANY($1)", array($arr));
	foreach($users as $u){
		$db->prepare_query("DELETE FROM collaborators WHERE project_owner_user_pkey = $1 OR collaborator_user_pkey = $1", array($u['pk']));
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u['pk']));
	}
	@unlink($tmpimg);
	$left = $neodb->get_var("MATCH (n) WHERE n.userpkey IN [$pks] RETURN count(n)");
	echo "  residue nodes: $left, image files removed: " . count(array_unique($files)) . "\n";
}

echo "\n" . ($fail ? "FAILED $fail / " . ($pass + $fail) . ":\n  - " . implode("\n  - ", $fails) : "ALL PASS ($pass)") . "\n";
exit($fail ? 1 : 0);
