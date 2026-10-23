<?php
/**
 * File: vector_import_lib.php
 * Description: Helpers for the vector import tests: forged session, curl posts, form field
 *              scraping, a normalized dump of an imported project, cleanup.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/db/strabospotclass.php';
require_once '/srv/app/www/includes/geophp/geoPHP.inc';

$ownerPkey = (int)$db->get_var_prepared(
	"SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE",
	array('owner@test.strabospot.org'));
if (!$ownerPkey) { fwrite(STDERR, "Test user owner@test.strabospot.org not found. Run setup_test_data.php first.\n"); exit(1); }

$viSessionFiles = array();
$viRandnums = array();

function viForgeSession($pkey) {
	global $viSessionFiles;
	$sid  = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = '/var/lib/php/sessions/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600);
	@chown($path, 'www-data');
	@chgrp($path, 'www-data');
	$viSessionFiles[] = $path;
	return $sid;
}

function viPost($sid, $url, $fields) {
	$cmd = "curl -s -b " . escapeshellarg("PHPSESSID=$sid");
	foreach ($fields as $k => $v) {
		$cmd .= " --data-urlencode " . escapeshellarg("$k=$v");
	}
	return (string)shell_exec($cmd . " " . escapeshellarg($url));
}

function viPostFile($sid, $url, $fields, $fileField, $path, $name, $type) {
	$cmd = "curl -s -b " . escapeshellarg("PHPSESSID=$sid");
	foreach ($fields as $k => $v) {
		$cmd .= " -F " . escapeshellarg("$k=$v");
	}
	$cmd .= " -F " . escapeshellarg("$fileField=@$path;filename=$name;type=$type");
	return (string)shell_exec($cmd . " " . escapeshellarg($url));
}

function viGrab($html, $name) {
	if (preg_match('/name="' . preg_quote($name, '/') . '"\s+value\s*="([^"]*)"/', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES);
	return null;
}

// Drop generated ids and timestamps so two imports of the same file compare equal.
function viNormalize($v) {
	if (is_object($v)) $v = json_decode(json_encode($v), true);
	if (!is_array($v)) return $v;
	$out = array();
	foreach ($v as $k => $x) {
		if (in_array($k, array('id', 'time', 'date', 'modified_timestamp', 'datecreated', 'folder', 'spots', 'self', 'uploaddate'), true)) continue;
		$out[$k] = viNormalize($x);
	}
	if (array_keys($out) !== range(0, count($out) - 1)) ksort($out);
	return $out;
}

function viDumpProject($projId, $ownerPkey) {
	global $db, $neodb;
	$strabo = new StraboSpot($neodb, $ownerPkey, $db);
	$dump = array();
	$rows = $neodb->get_results("MATCH (p:Project {id:$projId})-[:HAS_DATASET]->(d:Dataset) RETURN d.id AS id, d.name AS name ORDER BY d.name");
	foreach ($rows ?: array() as $row) {
		$dsid = $row->get('id');
		$ds = $neodb->getNode("MATCH (d:Dataset {id:$dsid}) RETURN d");
		$spots = $strabo->getDatasetSpots($dsid);
		$features = $spots['features'] ?? array();
		usort($features, function ($a, $b) { return strcmp((string)$a['properties']['name'], (string)$b['properties']['name']); });
		$dump[$row->get('name')] = array('dataset' => viNormalize((array)$ds), 'spots' => viNormalize($features));
	}
	$proj = $neodb->getNode("MATCH (p:Project {id:$projId}) RETURN p");
	$tags = json_decode($proj['json_tags'] ?? '[]', true);
	$dump['_project_tags'] = viNormalize($tags ?: array());
	return $dump;
}

function viCleanupProject($projId) {
	global $neodb;
	$neodb->query("MATCH (p:Project {id:$projId})
	               OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset)
	               OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot)
	               OPTIONAL MATCH (s)-[*1..3]->(c)
	               DETACH DELETE c, s, d, p");
}

function viCleanup() {
	global $viSessionFiles, $viRandnums;
	foreach ($viRandnums as $r) {
		if ((int)$r > 0) shell_exec("rm -rf " . escapeshellarg("/srv/app/www/ziptemp/" . (int)$r) . " " . escapeshellarg("/srv/app/www/ogrtemp/" . (int)$r));
	}
	foreach ($viSessionFiles as $f) @unlink($f);
}
