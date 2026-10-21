<?php
/**
 * File: tests/noeval/no_eval_test.php
 * Description: The eval() based assignments are gone (docs/Remove_Eval_Fix.md, 2026-10-21).
 *              Several functions copied JSON / node keys into arrays, objects or local variables by
 *              building PHP code with eval(). They now assign directly. Checks:
 *                1. no eval in first-party PHP (tokenizer, vendored libraries and tests skipped);
 *                2. the dataset update path stores every incoming key and value as sent (keys with
 *                   spaces, dashes, unicode; quotes and backslashes in values), still reads the
 *                   existing modified_timestamp / datecreated from the node and keeps the
 *                   "only a newer copy overwrites" rule;
 *                3. the profile insert stores the sent keys and the strabousername;
 *                4. spot, dataset and profile JSON builders return the stored keys.
 *              Writes only to the maya.chen demo account (DEV ONLY) and removes what it creates;
 *              maya's profile is restored at the end.
 *              --digest prints one md5 per read-path case instead (spot / feature collection /
 *              dataset / profile JSON for a sample of existing data), so two checkouts can be
 *              compared line by line.
 *                docker exec strabo-php php /srv/app/www/tests/noeval/no_eval_test.php [--digest]
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/db/strabospotclass.php';
include_once '/srv/app/www/includes/geophp/geoPHP.inc';

$DIGEST = in_array('--digest', $argv);
$MAYA_EMAIL = 'maya.chen@test.strabospot.org';

$failures = array();
function check($label, $cond, $detail = null) {
	global $failures;
	echo ($cond ? "  PASS  " : "  FAIL  ") . $label . (!$cond && $detail !== null ? "  [" . (is_string($detail) ? $detail : json_encode($detail)) . "]" : "") . "\n";
	if (!$cond) $failures[] = $label;
}
function section($t) { echo "\n== $t\n"; }
function strabo($pkey) { global $neodb, $db; return new StraboSpot($neodb, $pkey, $db); }
function digest($v) { return md5(json_encode($v)); }

// ---------------------------------------------------------------- read-path digests (old vs new)
if ($DIGEST) {
	$s = strabo(99999);
	// spots: a spread of owners, oldest first, with their images
	$spotIds = array();
	foreach ($neodb->query("MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE d.userpkey = s.userpkey WITH d.userpkey AS u, collect(s.id)[0..6] AS ids RETURN u, ids ORDER BY u LIMIT 120") as $r) {
		foreach ($r->get('ids') as $id) $spotIds[] = array((int)$r->get('u'), (int)$id);
	}
	foreach ($spotIds as $p) {
		list($u, $id) = $p;
		$recs = $neodb->query("MATCH (s:Spot {id:$id}) WHERE s.userpkey = $u OPTIONAL MATCH (s)-[:HAS_IMAGE]-(i:Image) WITH s, collect(i) AS i RETURN s, i");
		try { $out = count($recs) ? digest(strabo($u)->singleSpotJSON($recs[0])) : 'none'; } catch (Throwable $e) { $out = 'ERR ' . get_class($e); }
		echo "spot\t$u\t$id\t$out\n";
	}
	// feature collections (both builders) for a spread of datasets
	foreach ($neodb->query("MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE d.userpkey = s.userpkey WITH d, count(s) AS c WHERE c <= 300 RETURN d.id AS id, d.userpkey AS u ORDER BY d.userpkey, d.id LIMIT 80") as $r) {
		$id = (int)$r->get('id'); $u = (int)$r->get('u');
		$q = "MATCH (a:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE a.id = $id AND a.userpkey = $u OPTIONAL MATCH (s)-[:HAS_IMAGE]-(i:Image) WITH s, collect(i) AS i RETURN s, i ORDER BY s.id";
		foreach (array('getFeatureCollection', 'getPGFeatureCollection') as $fn) {
			try { $out = digest(strabo($u)->$fn($q)); } catch (Throwable $e) { $out = 'ERR ' . get_class($e); }
			echo "$fn\t$u\t$id\t$out\n";
		}
		$node = $neodb->query("MATCH (a:Dataset) WHERE a.id = $id AND a.userpkey = $u RETURN a");
		try { $out = count($node) ? digest(strabo($u)->singleDatasetJson($node[0]->get('a')->values())) : 'none'; } catch (Throwable $e) { $out = 'ERR ' . get_class($e); }
		echo "singleDatasetJson\t$u\t$id\t$out\n";
	}
	// profiles
	foreach ($neodb->query("MATCH (u:User)-[:HAS_PROFILE]->(p:Profile) RETURN DISTINCT u.userpkey AS u ORDER BY u LIMIT 60") as $r) {
		$u = (int)$r->get('u');
		try { $out = digest(strabo($u)->getProfile()); } catch (Throwable $e) { $out = 'ERR ' . get_class($e); }
		echo "getProfile\t$u\t-\t$out\n";
	}
	exit(0);
}

// ---------------------------------------------------------------- 1. no eval left
section('no eval() in first-party PHP');
$skip = '#^/srv/app/www/(PHPExcel|includes/classes/vendor|vendor|eratosthenes/admin/vendor|includes/geophp|includes/jwt|includes/tfpdf|experimental/lib/tfpdf|tests|devexperimental|bkup|docs|node_modules|dbimages|straboMicroFiles|ziptemp|ogrtemp|svgfiles|versions|exportjobs_data|geotiff/upload|voicestations_data|arcfiles|profileimages)(/|$)|/(Parsedown|ShapeFile\.inc)\.php$#';
$found = array();
$scanned = 0;
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
	new RecursiveDirectoryIterator('/srv/app/www', FilesystemIterator::SKIP_DOTS),
	function ($f) use ($skip) { return !preg_match($skip, $f->getPathname()); }
));
foreach ($it as $f) {
	if ($f->isLink() || substr($f->getFilename(), -4) !== '.php') continue;
	$scanned++;
	foreach (token_get_all(file_get_contents($f->getPathname())) as $t) {
		if (is_array($t) && $t[0] === T_EVAL) $found[] = substr($f->getPathname(), 13) . ':' . $t[2];
	}
}
check("no eval in $scanned first-party PHP files", !$found, implode(', ', array_slice($found, 0, 10)));

// ---------------------------------------------------------------- fixtures (maya, dev only)
$maya = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($MAYA_EMAIL));
if (!$maya) { echo "SKIP: dev demo account $MAYA_EMAIL not found (docs/StraboSamples/summary_doc/seed_demo.php)\n"; exit(1); }
$sm = strabo($maya);

// Keys Neo4j accepts as map keys (the node writer does not quote keys, so a key with a space or a
// dash cannot be stored through it, before or after this change); unusual values.
$odd = array(
	'FieldNotes' => 'He said "it\'s fine" \\ then left',
	'rock_type' => "granite 'pink'",
	'üñíçødé' => 'Grüße 東京',
	'weird_value' => 'a\\b\\\\c $x {$y} ${z}',
	'count' => 42,
	'ratio' => 3.25,
);
// Keys that never reach Neo4j here: builders called in memory.
$oddMem = array_merge($odd, array('Field Notes' => 'spaced key', 'rock-type' => 'dashed key', '2nd' => 'leading digit'));

// ---------------------------------------------------------------- 2. dataset update path
section('insertDataset update stores the sent keys and values');
$dsid = (int)(time() . '7351');
$created = $sm->insertDataset(json_encode(array('id' => $dsid, 'name' => 'noeval test dataset', 'modified_timestamp' => 1000, 'date' => '2026-10-21T00:00:00Z')));
$node = $neodb->getNode("MATCH (d:Dataset) WHERE d.id = $dsid AND d.userpkey = $maya RETURN d");
check('dataset created for maya', is_array($node) && ($node['name'] ?? '') === 'noeval test dataset');
$firstDatecreated = $node['datecreated'] ?? null;

$upd = array_merge(array('id' => $dsid, 'name' => 'noeval updated', 'modified_timestamp' => 2000), $odd);
$res = $sm->insertDataset(json_encode($upd));
$node = $neodb->getNode("MATCH (d:Dataset) WHERE d.id = $dsid AND d.userpkey = $maya RETURN d");
check('newer copy overwrites (modified_on_server)', isset($res->modified_on_server) && $res->modified_on_server === true);
foreach ($odd as $k => $v) check("stored key '$k' with its value", array_key_exists($k, $node) && $node[$k] == $v, isset($node[$k]) ? $node[$k] : 'missing');
check('name updated', ($node['name'] ?? '') === 'noeval updated');
check('id kept as the dataset id', (string)($node['id'] ?? '') === (string)$dsid);
check('datecreated kept from the existing node', $firstDatecreated !== null && (string)$node['datecreated'] === (string)$firstDatecreated, array($firstDatecreated, $node['datecreated'] ?? null));

$res = $sm->insertDataset(json_encode(array('id' => $dsid, 'name' => 'noeval older copy', 'modified_timestamp' => 1500)));
$node = $neodb->getNode("MATCH (d:Dataset) WHERE d.id = $dsid AND d.userpkey = $maya RETURN d");
check('older copy does not overwrite (existing modified_timestamp read)', isset($res->modified_on_server) && $res->modified_on_server === false && ($node['name'] ?? '') === 'noeval updated');

section('singleDatasetJson returns the keys it is given (in memory, spaces / dashes / leading digit too)');
$dj = $sm->singleDatasetJson(array_merge($node, $oddMem));
foreach ($oddMem as $k => $v) check("singleDatasetJson has '$k'", is_array($dj) ? (array_key_exists($k, $dj) && $dj[$k] == $v) : (property_exists($dj, $k) && $dj->$k == $v));

// ---------------------------------------------------------------- 4. spot JSON builders
section('spot JSON builders return the stored keys');
$spotid = (int)(time() . '7352');
$spotProps = array_merge(array('id' => $spotid, 'name' => 'noeval spot', 'userpkey' => $maya, 'geometry' => 'POINT (-111.5 34.2)', 'wkt' => 'POINT (-111.5 34.2)', 'origwkt' => 'POINT (-111.5 34.2)'), $odd);
$sn = $neodb->createNode(json_encode($spotProps), 'Spot');
$did = $neodb->get_var("MATCH (d:Dataset) WHERE d.id = $dsid AND d.userpkey = $maya RETURN id(d)");
$neodb->addRelationship($did, $sn, 'HAS_SPOT', 'Dataset', 'Spot');
$recs = $neodb->query("MATCH (s:Spot {id:$spotid}) WHERE s.userpkey = $maya OPTIONAL MATCH (s)-[:HAS_IMAGE]-(i:Image) WITH s, collect(i) AS i RETURN s, i");
$sj = null;
try { $sj = $sm->singleSpotJSON($recs[0]); } catch (Throwable $e) { $sj = $e; }
check('singleSpotJSON runs', !($sj instanceof Throwable), $sj instanceof Throwable ? $sj->getMessage() : null);
if (!($sj instanceof Throwable)) foreach ($odd as $k => $v) check("singleSpotJSON properties has '$k'", isset($sj->properties) && property_exists($sj->properties, $k) && $sj->properties->$k == $v);
$q = "MATCH (a:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE a.id = $dsid AND a.userpkey = $maya OPTIONAL MATCH (s)-[:HAS_IMAGE]-(i:Image) WITH s, collect(i) AS i RETURN s, i";
foreach (array('getFeatureCollection', 'getPGFeatureCollection') as $fn) {
	$fc = $sm->$fn($q);
	$p = $fc['features'][0]['properties'] ?? array();
	$ok = true;
	foreach ($odd as $k => $v) if (!array_key_exists($k, $p) || $p[$k] != $v) $ok = false;
	check("$fn feature has every stored key", $ok, array_keys($p));
}

// ---------------------------------------------------------------- 3. profile
section('insertProfile stores the sent keys and the strabousername');
$saved = $neodb->getNode("MATCH (u:User)-[:HAS_PROFILE]->(p:Profile) WHERE u.userpkey = $maya RETURN p");
$hasUser = (int)$neodb->get_var("MATCH (u:User) WHERE u.userpkey = $maya RETURN count(u)");
if (!$hasUser) $neodb->createNode(json_encode(array('userpkey' => $maya)), 'User');
$prof = array_merge(array('strabousername' => 'noeval maya', 'firstName' => 'Maya'), $odd);
$sm->insertProfile(json_encode($prof));
$pn = $neodb->getNode("MATCH (u:User)-[:HAS_PROFILE]->(p:Profile) WHERE u.userpkey = $maya RETURN p");
foreach ($prof as $k => $v) check("profile stored '$k'", is_array($pn) && array_key_exists($k, $pn) && $pn[$k] == $v, isset($pn[$k]) ? $pn[$k] : 'missing');
$gp = $sm->getProfile();
check('getProfile returns a sent key', is_object($gp) && property_exists($gp, 'rock_type'), is_object($gp) ? array_keys(get_object_vars($gp)) : $gp);

// ---------------------------------------------------------------- cleanup
$neodb->query("MATCH (s:Spot {id:$spotid}) WHERE s.userpkey = $maya DETACH DELETE s");
$neodb->query("MATCH (d:Dataset) WHERE d.id = $dsid AND d.userpkey = $maya DETACH DELETE d");
if (is_array($saved)) {
	unset($saved['userpkey'], $saved['datecreated'], $saved['profiletype']);
	$sm->insertProfile(json_encode($saved));
} else {
	$neodb->query("MATCH (u:User)-[r:HAS_PROFILE]->(p:Profile) WHERE u.userpkey = $maya DELETE r, p");
}
if (!$hasUser) $neodb->query("MATCH (u:User) WHERE u.userpkey = $maya AND NOT (u)--() DELETE u");
check('cleanup: test dataset and spot removed', (int)$neodb->get_var("MATCH (n) WHERE (n:Dataset AND n.id = $dsid) OR (n:Spot AND n.id = $spotid) RETURN count(n)") === 0);

echo "\n" . (count($failures) ? count($failures) . " FAILED" : "ALL PASS") . "\n";
exit(count($failures) ? 1 : 0);
