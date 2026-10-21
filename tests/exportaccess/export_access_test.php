<?php
/**
 * File: tests/exportaccess/export_access_test.php
 * Description: Field export access check (docs/Export_Access_Fix.md, 2026-10-21).
 *              The download links carry dsids + userpkey in the URL and the exports
 *              used to trust that userpkey. Checks, through the real web endpoints with
 *              forged sessions, that only the owner, an accepted collaborator, or anyone
 *              for a public project gets data, whatever userpkey the URL claims; that
 *              a mixed dsids list keeps only the permitted datasets; and that
 *              getPublicDatasetSpots() (strat section pages) follows the same rules.
 *
 *              Fixtures are discovered from the dev data (a private and a public
 *              dataset with spots); collaborator rows are inserted for made-up
 *              userpkeys and removed at the end. Run as root (writes session files):
 *                docker exec strabo-php php /srv/app/www/tests/exportaccess/export_access_test.php
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/db/strabospotclass.php';
include_once '/srv/app/www/includes/geophp/geoPHP.inc';

$STRANGER = 990001;      // no rows anywhere
$COLLAB = 990002;        // accepted collaborator on the private project
$PENDING = 990003;       // invited, not accepted
$DISABLED = 990004;      // accepted, then disabled
$UUIDPREFIX = 'exptest-';

$failures = array();
$sessionFiles = array();
function check($label, $cond, $detail = null) {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== null ? "  -> $detail" : '') . "\n";
	if (!$cond) {
		$failures[] = $label;
	}
}
function section($name) {
	echo "\n== $name\n";
}
function forgeSession($pkey) {
	global $sessionFiles;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = '/var/lib/php/sessions/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600); @chown($path, 'www-data'); @chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}
function page($path, $sid, $post = null) {
	$ch = curl_init('http://localhost' . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
		CURLOPT_HTTPHEADER => $sid === null ? array() : array('Cookie: PHPSESSID=' . $sid)));
	if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	$body = curl_exec($ch);
	$out = array('code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'body' => (string)$body);
	curl_close($ch);
	return $out;
}
// A real file came back (not the "no data" text page).
function gotFile($r) {
	return $r['code'] == 200 && stripos($r['type'], 'text/html') === false && strlen($r['body']) > 200;
}
function geojsonCount($r) {
	$j = json_decode($r['body'], true);
	return is_array($j) && isset($j['features']) ? count($j['features']) : -1;
}
function cleanup() {
	global $db, $UUIDPREFIX, $sessionFiles;
	$db->prepare_query("DELETE FROM collaborators WHERE uuid LIKE $1", array($UUIDPREFIX . '%'));
	foreach ($sessionFiles as $f) @unlink($f);
}

// ---- fixtures from dev data
$candidates = $neodb->query("MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = d.userpkey WITH p, d, count(s) AS c WHERE c >= 2 AND c <= 400 RETURN p.id AS pid, d.id AS dsid, d.userpkey AS owner, c ORDER BY c LIMIT 400");
$private = null;
$public = null;
foreach ($candidates as $r) {
	$owner = (int)$r->get('owner');
	if ($owner >= 990000) continue;
	$row = array('pid' => (string)$r->get('pid'), 'dsid' => (string)$r->get('dsid'), 'owner' => $owner, 'count' => (int)$r->get('c'));
	$isPublic = $db->get_var_prepared("SELECT 1 FROM project WHERE strabo_project_id = $1 AND user_pkey = $2 AND ispublic = TRUE LIMIT 1", array($row['pid'], $owner));
	// a dataset id must be unique on dev for the counts below to be exact
	$dup = $neodb->get_var("MATCH (d:Dataset) WHERE d.id = " . $row['dsid'] . " RETURN count(d)");
	if ((int)$dup != 1) continue;
	if ($isPublic && !$public) $public = $row;
	if (!$isPublic && !$private) $private = $row;
	if ($private && $public && $public['owner'] != $private['owner']) break;
}
if (!$private || !$public) {
	echo "SKIP: dev data has no private + public dataset pair with spots\n";
	exit(1);
}
echo "private dataset {$private['dsid']} (owner {$private['owner']}, project {$private['pid']}, {$private['count']} spots)\n";
echo "public dataset  {$public['dsid']} (owner {$public['owner']}, project {$public['pid']}, {$public['count']} spots)\n";

cleanup();
foreach (array(array($COLLAB, true, false), array($PENDING, false, false), array($DISABLED, true, true)) as $c) {
	$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid) VALUES ($1, $2, $3, 'readonly', $4, $5, $6)",
		array($private['pid'], $private['owner'], $c[0], $c[1] ? 't' : 'f', $c[2] ? 't' : 'f', $UUIDPREFIX . $c[0]));
}

$owner = $private['owner'];
$P = $private['dsid'];
$U = $public['dsid'];
$sid = array(
	'owner' => forgeSession($owner),
	'stranger' => forgeSession($STRANGER),
	'collab' => forgeSession($COLLAB),
	'pending' => forgeSession($PENDING),
	'disabled' => forgeSession($DISABLED),
);

// ---- file exports through searchdownload
$types = array('xls', 'kml', 'shapefile', 'gpkg', 'expandedShapefile', 'fieldbook');
section('searchdownload file exports of the PRIVATE dataset (URL claims the owner)');
foreach ($types as $t) {
	$url = "/searchdownload?type=$t&userpkey=$owner&dsids=$P";
	check("owner gets $t", gotFile(page($url, $sid['owner'])));
	check("stranger gets no $t", !gotFile(page($url, $sid['stranger'])));
	check("accepted collaborator gets $t", gotFile(page($url, $sid['collab'])));
}
foreach (array('pending', 'disabled') as $who) {
	check("$who collaborator gets no xls", !gotFile(page("/searchdownload?type=xls&userpkey=$owner&dsids=$P", $sid[$who])));
}
check('stranger gets no xls without a userpkey in the URL', !gotFile(page("/searchdownload?type=xls&dsids=$P", $sid['stranger'])));
check('stranger gets no xls when the URL claims the stranger', !gotFile(page("/searchdownload?type=xls&userpkey=$STRANGER&dsids=$P", $sid['stranger'])));
check('stranger cannot reach it through POST', !gotFile(page("/searchdownload", $sid['stranger'], array('type' => 'xls', 'userpkey' => $owner, 'dsids' => $P))));
check('owner can still export through POST', gotFile(page("/searchdownload", $sid['owner'], array('type' => 'xls', 'userpkey' => $owner, 'dsids' => $P))));

section('geojson counts');
$r = page("/searchdownload?type=geojson&userpkey=$owner&dsids=$P", $sid['owner']);
check("owner geojson has all {$private['count']} spots", geojsonCount($r) == $private['count'], geojsonCount($r));
$r = page("/searchdownload?type=geojson&userpkey=$owner&dsids=$P", $sid['stranger']);
check('stranger geojson is empty', geojsonCount($r) == 0, geojsonCount($r));
$r = page("/searchdownload?type=geojson&userpkey=$owner&dsids=$P", $sid['collab']);
check('collaborator geojson has all spots', geojsonCount($r) == $private['count'], geojsonCount($r));

section('public project dataset');
$r = page("/searchdownload?type=geojson&userpkey={$public['owner']}&dsids=$U", $sid['stranger']);
check("stranger gets the public dataset ({$public['count']} spots)", geojsonCount($r) == $public['count'], geojsonCount($r));
check('stranger gets the public dataset as xls', gotFile(page("/searchdownload?type=xls&userpkey={$public['owner']}&dsids=$U", $sid['stranger'])));
$r = page("/searchdownload?type=geojson&userpkey=$owner&dsids=$U", $sid['stranger']);
check('wrong userpkey in the URL does not matter for a public dataset', geojsonCount($r) == $public['count'], geojsonCount($r));

section('mixed dsids lists');
$r = page("/searchdownload?type=geojson&userpkey=$owner&dsids=$P,$U", $sid['stranger']);
check('stranger asking for private + public gets only the public spots', geojsonCount($r) == $public['count'], geojsonCount($r));
$r = page("/searchdownload?type=geojson&userpkey=$owner&dsids=$U,$P", $sid['owner']);
check('owner asking for public + own private gets the own dataset first', geojsonCount($r) == $private['count'], geojsonCount($r));
$r = page("/searchdownload?type=geojson&userpkey=$owner&dsids=$P,abc,0,-5", $sid['owner']);
check('junk ids are dropped', geojsonCount($r) == $private['count'], geojsonCount($r));

section('Export Builder internals cannot be passed in');
$r = page("/searchdownload?type=geojson&dsids=$U&scope_groups[0][userpkey]=$owner&scope_groups[0][dsids][]=$P", $sid['stranger']);
check('scope_groups from the URL is ignored', geojsonCount($r) == $public['count'], geojsonCount($r));

section('fieldbook_build and gems_export pages');
$r = page("/fieldbook_build?userpkey=$owner&dsids=$P", $sid['owner']);
check('owner fieldbook_build has data', strpos($r['body'], 'id="fb-nodata"') === false && $r['code'] == 200);
$r = page("/fieldbook_build?userpkey=$owner&dsids=$P", $sid['stranger']);
check('stranger fieldbook_build shows no data', strpos($r['body'], 'id="fb-nodata"') !== false);
$r = page("/fieldbook_build?userpkey=$owner&dsids=$P", $sid['collab']);
check('collaborator fieldbook_build has data', strpos($r['body'], 'id="fb-nodata"') === false && $r['code'] == 200);
$r = page("/gems_export?userpkey=$owner&dsids=$P", $sid['owner']);
check('owner gems_export scans the dataset', strpos($r['body'], 'name="dsids" value="' . $P . '"') !== false);
$r = page("/gems_export?userpkey=$owner&dsids=$P", $sid['stranger']);
check('stranger gems_export gets no dataset', strpos($r['body'], 'name="dsids" value="0"') !== false);

section('getPublicDatasetSpots (strat section pages)');
function spotCount($pkey, $dsid) {
	global $neodb, $db;
	$s = new StraboSpot($neodb, $pkey, $db);
	$j = $s->getPublicDatasetSpots($dsid);
	return is_array($j) && isset($j['features']) ? count($j['features']) : 0;
}
check('signed out: private dataset has no spots', spotCount(99999, $P) == 0);
check('signed out: public dataset has its spots', spotCount(99999, $U) == $public['count'], spotCount(99999, $U));
check('owner: private dataset has its spots', spotCount($owner, $P) == $private['count']);
check('collaborator: private dataset has its spots', spotCount($COLLAB, $P) == $private['count']);
check('stranger: private dataset has no spots', spotCount($STRANGER, $P) == 0);
check('injection text is not a dataset id', spotCount(99999, "$U) RETURN 1 //") == 0);
$r = page("/pdataset_strat_sections.php?dataset_ids=$P", null);
check('pdataset_strat_sections answers signed out', $r['code'] == 200);
$r = page("/REST/doPublicStratSectionDetail?spot_id=x%20or%201=1", null);
check('REST strat detail with a non-numeric spot id stops cleanly', $r['code'] == 200 && trim($r['body']) === '', substr($r['body'], 0, 80));

section('getPublicSingleSpot (strat_section.php)');
function oneSpot($dsid, $owner) {
	global $neodb;
	return (int)$neodb->get_var("MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE d.id = $dsid AND d.userpkey = $owner AND s.userpkey = $owner RETURN s.id ORDER BY s.id LIMIT 1");
}
function spotFound($pkey, $spotid) {
	global $neodb, $db;
	$s = new StraboSpot($neodb, $pkey, $db);
	$j = $s->getPublicSingleSpot($spotid);
	return is_object($j) && !isset($j->Error);
}
$PS = oneSpot($P, $owner);
$US = oneSpot($U, $public['owner']);
check('signed out: private spot not found', !spotFound(99999, $PS));
check('signed out: public spot found', spotFound(99999, $US));
check('owner: private spot found', spotFound($owner, $PS));
check('collaborator: private spot found', spotFound($COLLAB, $PS));
check('pending collaborator: private spot not found', !spotFound($PENDING, $PS));
check('stranger: private spot not found', !spotFound($STRANGER, $PS));
check('injection text is not a spot id', !spotFound(99999, "x}) MATCH (s:Spot) RETURN s //"));
check('leading digits are only that spot id (int cast)', spotFound(99999, "$US}) RETURN 1 //") == spotFound(99999, $US));

section('getTagsFromDatasetIds owner pin');
function tagCount($dsids, $owner = null) {
	global $neodb, $db;
	$s = new StraboSpot($neodb, 99999, $db);
	$t = $s->getTagsFromDatasetIds($dsids, $owner);
	return is_array($t) ? count($t) : 0;
}
$ownTags = tagCount($P);
check('no owner: same tags as the owner pin', tagCount($P, $owner) == $ownTags, "$ownTags vs " . tagCount($P, $owner));
check('wrong owner: no tags', tagCount($P, $STRANGER) == 0);
check('mixed dsids pinned to one owner: only that owner\'s project tags', tagCount("$P,$U", $public['owner']) == tagCount($U));

cleanup();
echo "\n" . (count($failures) ? count($failures) . " FAILED" : "ALL PASS") . "\n";
exit(count($failures) ? 1 : 0);
