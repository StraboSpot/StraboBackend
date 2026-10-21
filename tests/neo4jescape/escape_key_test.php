<?php
/**
 * File: tests/neo4jescape/escape_key_test.php
 * Description: StraboDbNeo4j::escape() backtick-quotes map keys (2026-10-21). createNode / updateNode
 *              turn uploaded JSON into a Cypher map with escape(); keys used to go in bare, so a key
 *              could end the map early and add Cypher of its own, and a key with a space or dash
 *              broke the query. Checks:
 *                1. escape() output: every key quoted, a backtick inside a key doubled, values written
 *                   exactly as before (strings, quotes, backslashes, ints, floats, true, false, null);
 *                2. createNode with hostile keys stores exactly the sent keys and nothing else, and
 *                   no other node is touched;
 *                3. updateNode replaces the properties with the sent keys the same way;
 *                4. ordinary keys still store the same properties as the old unquoted form.
 *              Writes only nodes labelled EscKeyTest (DEV ONLY) and removes them at the end.
 *                docker exec strabo-php php /srv/app/www/tests/neo4jescape/escape_key_test.php
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/neodb.php';

$failures = array();
function check($label, $cond, $detail = null) {
	global $failures;
	echo ($cond ? "  PASS  " : "  FAIL  ") . $label . (!$cond && $detail !== null ? "  [" . (is_string($detail) ? $detail : json_encode($detail)) . "]" : "") . "\n";
	if (!$cond) $failures[] = $label;
}

function nodeProps($neodb, $id) {
	$r = $neodb->client->run("MATCH (n:EscKeyTest) WHERE id(n)=$id RETURN properties(n) AS p")->getRecord();
	$p = $r->value('p');
	ksort($p, SORT_STRING);
	return $p;
}

$neodb->client->run("MATCH (n:EscKeyTest) DETACH DELETE n");
$total_before = $neodb->get_var("MATCH (n) RETURN count(n)");

echo "== 1. escape() output\n";
check("plain keys quoted", $neodb->escape('{"id":5,"name":"x"}') === '{`id`:5,`name`:"x"}', $neodb->escape('{"id":5,"name":"x"}'));
check("backtick in key doubled", $neodb->escape('{"a`b":1}') === '{`a``b`:1}', $neodb->escape('{"a`b":1}'));
check("map-ending key stays inside quotes", $neodb->escape('{"a:1}) DETACH DELETE n //":1}') === '{`a:1}) DETACH DELETE n //`:1}');
check("string value with quotes and backslash", $neodb->escape('{"s":"he said \"hi\" \\\\ it\'s"}') === '{`s`:"he said \"hi\" \\\\ it\\\'s"}', $neodb->escape('{"s":"he said \"hi\" \\\\ it\'s"}'));
check("int, float, true as before", $neodb->escape('{"i":3,"f":1.5,"t":true}') === '{`i`:3,`f`:1.5,`t`:1}', $neodb->escape('{"i":3,"f":1.5,"t":true}'));
check("false, null, 0, empty string as before", $neodb->escape('{"b":false,"n":null,"z":0,"e":""}') === '{`b`:"",`n`:"",`z`:"0",`e`:""}', $neodb->escape('{"b":false,"n":null,"z":0,"e":""}'));
check("non-JSON passes through", $neodb->escape('{id:1}') === '{id:1}');

echo "== 2. createNode with hostile keys\n";
$sent = array(
	"plain" => "v1",
	"with space" => "v2",
	"with-dash" => "v3",
	"tick`key" => "v4",
	"0" => "v5",
	"x:1, injected:\"yes\"" => "v6",
	"y:1}) WITH n MATCH (m) SET m.pwned = 1 RETURN id(n) //" => "v7",
);
$id = $neodb->createNode(json_encode($sent), "EscKeyTest");
check("createNode returned an id", is_numeric($id), $id);
$p = nodeProps($neodb, $id);
$expect = $sent;
ksort($expect, SORT_STRING);
check("stored exactly the sent keys and values", $p === $expect, $p);
check("no injected property", !array_key_exists("injected", $p) && !array_key_exists("pwned", $p));
check("no node anywhere got pwned", (int)$neodb->get_var("MATCH (m) WHERE exists(m.pwned) RETURN count(m)") === 0);
$labels = $neodb->get_var("MATCH (n) WHERE id(n)=$id RETURN labels(n)");
sort($labels);
check("labels EscKeyTest + Strabo", $labels === array("EscKeyTest", "Strabo"), $labels);

echo "== 3. updateNode with hostile keys\n";
$sent2 = array(
	"after space" => "w1",
	"z:2, injected2:\"yes\"" => "w2",
	"num" => 7,
);
$uid = $neodb->updateNode($id, json_encode($sent2), "EscKeyTest");
check("updateNode returned the same id", $uid == $id, $uid);
$p = nodeProps($neodb, $id);
$expect = $sent2;
ksort($expect, SORT_STRING);
check("properties replaced by exactly the sent keys", $p === $expect, $p);

echo "== 4. ordinary keys: quoted form stores what the bare form did\n";
$ordinary = array("id" => 17000000000001, "name" => "Spot 1", "strike" => 0, "dip" => 45.5, "modified_timestamp" => 1761000000000, "notes" => "line1\nline2 \"q\"", "flag" => true, "gone" => null);
$newid = $neodb->createNode(json_encode($ordinary), "EscKeyTest");
$bare = str_replace("`", "", $neodb->escape(json_encode($ordinary)));
$oldid = $neodb->get_var("CREATE (n:EscKeyTest $bare) RETURN id(n)");
check("same properties as the old unquoted map", nodeProps($neodb, $newid) === nodeProps($neodb, $oldid), array(nodeProps($neodb, $newid), nodeProps($neodb, $oldid)));

$neodb->client->run("MATCH (n:EscKeyTest) DETACH DELETE n");
check("cleanup: no EscKeyTest nodes left", (int)$neodb->get_var("MATCH (n:EscKeyTest) RETURN count(n)") === 0);
check("cleanup: total node count unchanged", $neodb->get_var("MATCH (n) RETURN count(n)") == $total_before);

echo "\n" . (count($failures) ? count($failures) . " FAILED: " . implode("; ", $failures) : "ALL PASS") . "\n";
exit(count($failures) ? 1 : 0);
