<?php
/**
 * File: tests/exportjobs/smoke_test_pages.php
 * Description: M5 smoke suite for the Export Builder pages + endpoints
 *              (docs/ExportBuilder_Design.md §9, §12 M5). Runs INSIDE the
 *              container and drives the real Apache with forged PHP sessions
 *              (session files chown'd www-data) over curl:
 *
 *              PAGES   anonymous 302s (builder, My Exports, download);
 *                      builder embeds own + collaborated projects with
 *                      dataset spot counts, honors ?p=&owner=&d= and
 *                      ?from=<uuid> (owner-scoped); My Exports shell + notice.
 *              API     401 anon; count (fast path, index path, polygon
 *                      approximate, stranger 400, collaborator ok, dataset
 *                      outside project 400); create (bad format 400, kick,
 *                      poll to done, summary, per-user cap); detail (README,
 *                      404s); rerun; cancel; clear keeps queued rows.
 *              DOWNLOAD owner 200 zip with headers, stranger 404, not-done
 *                      303 notready, done-but-missing -> expired + 303.
 *              EMAIL   file transport: done + failed messages.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/exportjobs/smoke_test_pages.php
 *      ... smoke_test_pages.php setup      -> fixtures + forged owner session, prints JSON
 *      ... smoke_test_pages.php teardown <sid> -> removes fixtures + that session
 *      (the two modes drive tests/exportjobs/ui_test_export_builder_ff.js)
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/UUID.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once 'exportjobs/lib/export_config.php';
require_once 'exportjobs/lib/ExportJobService.php';

$OWNER = 94571; $COLLAB = 94572; $STRANGER = 94573;
$P1 = 945711001; $P2 = 945711002;
$DS_A = 945712001; $DS_B = 945712002; $DS_X = 945712003;
$S = array(945713001, 945713002, 945713003, 945713004, 945713005);
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$cfg = export_config();
$svc = new ExportJobService($db, $cfg);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr($detail, 0, 400) . "]" : '') . "\n"; }
}
function rmrf($d) { if (is_dir($d)) exec('rm -rf ' . escapeshellarg($d)); }
$sids = array();
function forge($pkey) {
	global $SESS, $sids;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	file_put_contents("$SESS/sess_$sid", 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
/** curl -> [code, headers(assoc lower), body] */
function http($method, $path, $sid = null, $json = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60));
	$hdr = array();
	if ($sid) $hdr[] = 'Cookie: PHPSESSID=' . $sid;
	if ($json !== null) { $hdr[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); }
	if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
	$raw = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	$headers = array();
	foreach (explode("\r\n", substr($raw, 0, $hsize)) as $l) { if (strpos($l, ':') !== false) { list($k, $v) = explode(':', $l, 2); $headers[strtolower(trim($k))] = trim($v); } }
	return array($code, $headers, substr($raw, $hsize));
}
function httpForm($path, $sid, array $fields) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_TIMEOUT => 60));
	if ($sid) curl_setopt($ch, CURLOPT_HTTPHEADER, array('Cookie: PHPSESSID=' . $sid));
	$raw = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	return array($code, substr($raw, $hsize));
}
function api($action, $sid, $json = null, $method = null) {
	list($code, , $body) = http($method ? $method : ($json !== null ? 'POST' : 'GET'), '/exportjobs/api.php?action=' . $action, $sid, $json);
	$j = json_decode($body, true);
	return array($code, is_array($j) ? $j : array('_raw' => $body));
}
function wait_done($svc, $uuid, $secs = 90) {
	$t = time();
	while (time() - $t < $secs) {
		$r = $svc->get($uuid);
		if ($r && !in_array($r['status'], array('queued', 'running'), true)) return $r;
		usleep(500000);
	}
	return $svc->get($uuid);
}
function embedded($body, $var) {
	if (!preg_match('/window\.' . $var . ' = (\{.*?\});\s*<\/script>/s', $body, $m)) return null;
	return json_decode($m[1], true);
}
function cleanup() {
	global $db, $neodb, $OWNER, $COLLAB, $STRANGER, $cfg, $sids, $SESS;
	foreach (array($OWNER, $COLLAB, $STRANGER) as $u) {
		$neodb->query("MATCH (u:User {userpkey: $u})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) DETACH DELETE s");
		$neodb->query("MATCH (u:User {userpkey: $u})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) DETACH DELETE d, p");
		$neodb->query("MATCH (u:User {userpkey: $u}) DETACH DELETE u");
		$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_userpkey = $1", array($u));
		$db->prepare_query("DELETE FROM export_jobs WHERE userpkey = $1", array($u));
		$db->prepare_query("DELETE FROM collaborators WHERE project_owner_user_pkey = $1 OR collaborator_user_pkey = $1", array($u));
		$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($u));
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u));
		rmrf(rtrim($cfg['results_root'], '/') . "/$u");
	}
	foreach ($sids as $s) @unlink("$SESS/sess_$s");
	$sids = array();
}

$MODE = isset($argv[1]) ? $argv[1] : 'suite';
if ($MODE === 'teardown') {
	if (isset($argv[2]) && preg_match('/^[0-9a-f]{26}$/', $argv[2])) $sids[] = $argv[2];
	cleanup();
	echo "teardown ok\n";
	exit(0);
}
if ($MODE === 'suite') echo "Export Builder M5 pages + endpoints smoke suite\n";
cleanup();

// ------------------------------------------------------------------ fixtures
foreach (array($OWNER => 'pg', $COLLAB => 'pgc', $STRANGER => 'pgs') as $u => $fn) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, $2, 'Fixture', $3, 'x', 'x', false, false)", array($u, ucfirst($fn), "$fn-$u@test.strabospot.org"));
}
$neodb->query("CREATE (u:User {userpkey: $OWNER, email: 'pg-$OWNER@test.strabospot.org'})");
$neodb->query("CREATE (u:User {userpkey: $STRANGER, email: 'pgs-$STRANGER@test.strabospot.org'})");
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P1, userpkey: $OWNER, desc_project_name: 'Pages Project', uploaddate: 1722400000}) CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("MATCH (u:User {userpkey: $STRANGER}) CREATE (p:Project {id: $P2, userpkey: $STRANGER, desc_project_name: 'Stranger Project', uploaddate: 1722400000}) CREATE (u)-[:HAS_PROJECT]->(p)");
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Pages Project', $2, FALSE)", array($OWNER, (string)$P1));
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Stranger Project', $2, FALSE)", array($STRANGER, (string)$P2));
$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid) VALUES ($1, $2, $3, 'edit', TRUE, FALSE, $4)", array((string)$P1, $OWNER, $COLLAB, 'pg-' . $OWNER . '-collab'));
foreach (array($DS_A => array($P1, $OWNER, 'Alpha Dataset'), $DS_B => array($P1, $OWNER, 'Beta Dataset'), $DS_X => array($P2, $STRANGER, 'Stranger Dataset')) as $did => $pp) {
	$neodb->query("MATCH (p:Project {id: {$pp[0]}, userpkey: {$pp[1]}}) CREATE (d:Dataset {id: $did, userpkey: {$pp[1]}, name: '{$pp[2]}'}) CREATE (p)-[:HAS_DATASET]->(d)");
}
function spot($did, $owner, $id, $name, $wkt) {
	global $neodb;
	$neodb->query("MATCH (d:Dataset {id: $did, userpkey: $owner}) CREATE (s:Spot {id: $id, userpkey: $owner, name: '$name', wkt: '$wkt', origwkt: '$wkt',
		modified_timestamp: 1722400000000, date: '2026-07-15T10:00:00Z', time: '2026-07-15T10:00:00Z'}) CREATE (d)-[:HAS_SPOT]->(s)");
}
spot($DS_A, $OWNER, $S[0], 'Granite Knob', 'POINT (-118.25 34.05)');
spot($DS_A, $OWNER, $S[1], 'Spot Two', 'POINT (-118.24 34.05)');
spot($DS_A, $OWNER, $S[2], 'Spot Three', 'POINT (-118.26 34.04)');
spot($DS_B, $OWNER, $S[3], 'Spot Four', 'POINT (-118.10 34.05)');
spot($DS_B, $OWNER, $S[4], 'Spot Five', 'POINT (-118.11 34.06)');
spot($DS_X, $STRANGER, 945713099, 'Stranger Spot', 'POINT (-118.25 34.05)');
foreach ($S as $sid) StraboSearchSync::touchSpot($db, $neodb, $sid, $OWNER);
StraboSearchSync::touchSpot($db, $neodb, 945713099, $STRANGER);
check('fixtures indexed', (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_userpkey = $1 AND item_type='spot'", array($OWNER)) === 5);

$own = forge($OWNER); $col = forge($COLLAB); $str = forge($STRANGER);
$scopeP1 = array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)));
if ($MODE === 'setup') {
	echo json_encode(array('sid' => $own, 'owner' => $OWNER, 'project' => (string)$P1, 'ds_a' => (string)$DS_A, 'ds_b' => (string)$DS_B, 'spots' => $S)) . "\n";
	exit(0);
}

// ------------------------------------------------------------------ 1. pages
list($c, $h) = http('GET', '/export_builder', null);
check('anonymous /export_builder -> 302 login', $c === 302 && strpos($h['location'], 'login') !== false, "$c");
list($c, $h) = http('GET', '/my_exports', null);
check('anonymous /my_exports -> 302 login', $c === 302 && strpos($h['location'], 'login') !== false, "$c");
list($c, $h) = http('GET', '/exportjobs/download.php?j=' . UUID::v4(), null);
check('anonymous download -> 302 login', $c === 302 && strpos($h['location'], 'login') !== false, "$c");

list($c, , $b) = http('GET', '/export_builder', $own);
$eb = embedded($b, 'EXPORT_BUILDER');
check('builder page renders for the owner with the shell + scripts', $c === 200 && strpos($b, '<h2>Export Builder</h2>') !== false && strpos($b, 'strabosearch/js/builder.js') !== false && strpos($b, 'leaflet.js') !== false && strpos($b, 'export_builder.js') !== false, "$c");
$p1 = null; if ($eb) foreach ($eb['projects'] as $p) if ($p['id'] === (string)$P1) $p1 = $p;
check('builder embeds the owned project with dataset spot counts', $p1 && $p1['access'] === 'owner' && $p1['spots'] === 5 && count($p1['datasets']) === 2 && $p1['datasets'][0]['name'] === 'Alpha Dataset' && $p1['datasets'][0]['spots'] === 3, json_encode($p1));
check('builder does not list the stranger project', $eb && !array_filter($eb['projects'], function ($p) use ($P2) { return $p['id'] === (string)$P2; }));
list($c, , $b) = http('GET', '/export_builder', $col);
$eb = embedded($b, 'EXPORT_BUILDER'); $p1 = null; if ($eb) foreach ($eb['projects'] as $p) if ($p['id'] === (string)$P1) $p1 = $p;
check('collaborator sees the shared project as collaborator with the owner name', $c === 200 && $p1 && $p1['access'] === 'collaborator' && $p1['owner'] === $OWNER && strpos($p1['owner_name'], 'Pg') === 0, json_encode($p1));
list($c, , $b) = http('GET', '/export_builder?p=' . $P1 . '&owner=' . $OWNER . '&d=' . $DS_B, $own);
$eb = embedded($b, 'EXPORT_BUILDER');
check('?p=&owner=&d= door embeds the preselect', $eb && $eb['preselect'] === array('project_id' => (string)$P1, 'owner' => $OWNER, 'dataset_id' => (string)$DS_B), json_encode($eb ? $eb['preselect'] : null));
// M6 doors: StraboSearch POST handoff + menu + My Field Data toolbar
$dslAll = array('subsystems' => array('field', 'micro', 'exp'), 'pathway' => 'projects');
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U1', 'value' => 'Granite'))))));
$eb = embedded($b, 'EXPORT_BUILDER'); $sc = $eb ? $eb['initial']['scope']['projects'] : null;
$mine = array('id' => (string)$P1, 'owner' => $OWNER);
check('search door: matching keyword preselects the owner project + carries the U1 row + banner', $c === 200 && is_array($sc) && in_array($mine, $sc, true) && $eb['initial']['criteria'] === array(array('id' => 'U1', 'value' => 'Granite')) && $eb['initial']['formats'] === array('geojson') && strpos($b, 'From StraboSearch.') !== false && preg_match('/\\d+ StraboField projects? (has|have) spots matching these filters and (is|are) preselected/', $b) === 1, "$c " . json_encode($sc));
// Search-door mode (Jason 2026-09-02): the picker holds ONLY the matched projects, all preselected;
// the filters are read-only chips (no criteria builder mounted); copy is per door.
// Dev holds ~50 public "Granite" projects, so the picker may exceed the 50-tick cap: every ticked
// project must be listed, and any listed-but-unticked project is only allowed past the cap.
$listed = array(); foreach ($eb ? $eb['projects'] : array() as $pp) $listed[] = array('id' => $pp['id'], 'owner' => $pp['owner']);
$allTickedListed = true; foreach ($sc as $m) if (!in_array($m, $listed, true)) $allTickedListed = false;
$capOk = count($listed) === count($sc) || (count($sc) === 50 && strpos($b, 'Only the first 50 of ' . count($listed) . ' matching projects were preselected') !== false);
check('search door MODE: mode=search, picker = matched projects only (ticked up to the 50 cap), read-only chips, no criteria builder', $eb && $eb['mode'] === 'search' && $allTickedListed && $capOk && strpos($b, 'id="eb-criteria-summary"') !== false && strpos($b, 'id="criteriaBuilder"') === false && strpos($b, 'data-mode="search"') !== false && strpos($b, 'Projects with spots matching your search') !== false && strpos($b, 'Export the StraboField projects your search found') !== false && strpos($b, '(from your search)') !== false, json_encode(array('mode' => $eb ? $eb['mode'] : null, 'n' => $eb ? count($eb['projects']) : null, 'sc' => $sc)));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U1', 'value' => 'Nonesuchzzz'))))));
$eb = embedded($b, 'EXPORT_BUILDER');
check('search door: no matches -> nothing preselected, "None of your" note', $c === 200 && $eb && $eb['initial']['scope']['projects'] === array() && strpos($b, 'No StraboField project you can see has spots') !== false, "$c");
check('search door MODE: zero matches falls back to general mode (full picker, criteria builder mounted, U1 row carried)', $eb && $eb['mode'] === 'general' && count($eb['projects']) >= 1 && strpos($b, 'id="criteriaBuilder"') !== false && strpos($b, 'id="eb-criteria-summary"') === false && $eb['initial']['criteria'] === array(array('id' => 'U1', 'value' => 'Nonesuchzzz')), json_encode($eb ? $eb['mode'] : null));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode(array('subsystems' => array('micro'), 'criteria' => array(array('id' => 'U1', 'value' => 'Granite'))))));
$eb = embedded($b, 'EXPORT_BUILDER');
check('search door: Field excluded by U8 -> nothing preselected, note says so', $c === 200 && $eb && $eb['initial']['scope']['projects'] === array() && strpos($b, 'left out StraboField') !== false, "$c");
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'M1', 'value' => 'x'), array('id' => 'U4', 'value' => 5))))));
$eb = embedded($b, 'EXPORT_BUILDER');
// Before 2026-09-02 this preselected every project of the caller's; the UI no longer offers Export…
// for Field-less results, and the server door now preselects nothing rather than "all of mine".
check('search door: only non-Field rows -> rows dropped (noted), NOTHING preselected, general mode', $c === 200 && $eb && $eb['initial']['criteria'] === array() && $eb['initial']['scope']['projects'] === array() && $eb['mode'] === 'general' && strpos($b, '2 filter rows that cannot apply') !== false && strpos($b, 'None of the search filters apply to StraboField exports') !== false, "$c " . json_encode($eb ? $eb['initial']['scope'] : null));
list($c, $b) = httpForm('/export_builder', $col, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U1', 'value' => 'Granite'))))));
$eb = embedded($b, 'EXPORT_BUILDER');
check('search door: collaborator gets the shared project preselected under its owner', $c === 200 && $eb && in_array($mine, $eb['initial']['scope']['projects'], true), "$c");
$db->prepare_query("UPDATE project SET ispublic = TRUE WHERE strabo_project_id = $1 AND user_pkey = $2", array((string)$P2, $STRANGER));
$db->prepare_query("UPDATE strabosearch.item_hit SET project_ispublic = TRUE WHERE project_id = $1 AND project_userpkey = $2", array((string)$P2, $STRANGER));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U1', 'value' => 'Spot'))))));
$eb = embedded($b, 'EXPORT_BUILDER'); $pub = null; foreach ($eb ? $eb['projects'] : array() as $pp) if ($pp['id'] === (string)$P2) $pub = $pp;
check('search door: a PUBLIC stranger project in the result set joins the picker as access=public and is preselected', $c === 200 && $pub && $pub['access'] === 'public' && $pub['owner'] === $STRANGER && strpos($pub['owner_name'], 'Pgs') === 0 && count($pub['datasets']) === 1 && $pub['spots'] === 1 && in_array(array('id' => (string)$P2, 'owner' => $STRANGER), $eb['initial']['scope']['projects'], true) && in_array($mine, $eb['initial']['scope']['projects'], true) && preg_match('/\\(1 of yours, \\d+ public\\)/', $b) === 1, "$c " . json_encode($pub));
$doorPoly = array('type' => 'Polygon', 'coordinates' => array(array(array(-118.30, 34.00), array(-118.20, 34.00), array(-118.20, 34.10), array(-118.30, 34.10), array(-118.30, 34.00))));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U2', 'value' => $doorPoly))))));
$eb = embedded($b, 'EXPORT_BUILDER'); $sc = $eb ? $eb['initial']['scope']['projects'] : null;
check('search door (alignment, 09-01): polygon-only search preselects the owner project + the public stranger project (both have a spot inside)', $c === 200 && is_array($sc) && in_array($mine, $sc, true) && in_array(array('id' => (string)$P2, 'owner' => $STRANGER), $sc, true) && preg_match('/\\(1 of yours, 1 public\\)/', $b) === 1, "$c " . json_encode($sc));
// Open South Pacific: membership-based because dev holds real public projects almost anywhere on land
$farPoly = array('type' => 'Polygon', 'coordinates' => array(array(array(-140.0, -30.0), array(-139.0, -30.0), array(-139.0, -29.0), array(-140.0, -29.0), array(-140.0, -30.0))));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U2', 'value' => $farPoly))))));
$eb = embedded($b, 'EXPORT_BUILDER'); $sc = $eb ? $eb['initial']['scope']['projects'] : null; $ids = array_map(function ($pp) { return $pp['id']; }, $eb ? $eb['projects'] : array());
check('search door (alignment, 09-01): a polygon elsewhere preselects neither the owner project nor the public stranger project (search would list neither)', $c === 200 && is_array($sc) && !in_array($mine, $sc, true) && !in_array((string)$P2, $ids, true), "$c " . json_encode($sc));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U2', 'value' => $doorPoly), array('id' => 'U1', 'value' => 'Granite'))))));
$eb = embedded($b, 'EXPORT_BUILDER'); $sc = $eb ? $eb['initial']['scope']['projects'] : null;
check('search door (alignment, 09-01): polygon + keyword preselects only the owner project (the stranger spot is not named Granite)', $c === 200 && $sc === array($mine), "$c " . json_encode($sc));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U1', 'value' => 'Granite'))))));
$eb = embedded($b, 'EXPORT_BUILDER'); $ids = array_map(function ($pp) { return $pp['id']; }, $eb ? $eb['projects'] : array());
check('search door: a public project NOT in the result set is not offered', $c === 200 && !in_array((string)$P2, $ids, true), json_encode($ids));
list($c, , $b) = http('GET', '/export_builder', $own);
$eb = embedded($b, 'EXPORT_BUILDER'); $ids = array_map(function ($pp) { return $pp['id']; }, $eb ? $eb['projects'] : array());
check('account-menu door: picker stays own + collaborated (public project absent)', $c === 200 && !in_array((string)$P2, $ids, true), json_encode($ids));
$db->prepare_query("UPDATE project SET ispublic = FALSE WHERE strabo_project_id = $1 AND user_pkey = $2", array((string)$P2, $STRANGER));
$db->prepare_query("UPDATE strabosearch.item_hit SET project_ispublic = FALSE WHERE project_id = $1 AND project_userpkey = $2", array((string)$P2, $STRANGER));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array(array('id' => 'U1', 'value' => 'Spot'))))));
$eb = embedded($b, 'EXPORT_BUILDER'); $ids = array_map(function ($pp) { return $pp['id']; }, $eb ? $eb['projects'] : array());
check('search door: the same project, private again, is invisible to the door', $c === 200 && !in_array((string)$P2, $ids, true), json_encode($ids));
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => 'not json'));
$eb = embedded($b, 'EXPORT_BUILDER');
check('search door: garbage payload -> plain builder (no initial, no banner)', $c === 200 && $eb && $eb['initial'] === null && strpos($b, 'From StraboSearch.') === false, "$c");
// Globe browse run (empty criteria, Jason 2026-09-02): the page keeps Export… off for it;
// if the POST arrives anyway the door preselects nothing and says so (no "all of mine").
list($c, $b) = httpForm('/export_builder', $own, array('search_dsl' => json_encode($dslAll + array('criteria' => array()))));
$eb = embedded($b, 'EXPORT_BUILDER');
check('search door: empty-criteria (browse) DSL -> nothing preselected + "no filters" banner, general mode', $c === 200 && $eb && $eb['initial'] && $eb['initial']['scope']['projects'] === array() && $eb['initial']['criteria'] === array() && $eb['mode'] === 'general' && strpos($b, 'Your search had no filters, so no projects were preselected') !== false, "$c");
list($c, , $b) = http('GET', '/export_builder', $own);
$eb = embedded($b, 'EXPORT_BUILDER');
check('account-menu door: general mode (mode flag, criteria builder, general copy)', $c === 200 && $eb && $eb['mode'] === 'general' && strpos($b, 'id="criteriaBuilder"') !== false && strpos($b, 'Your own projects and the ones you collaborate on') !== false && strpos($b, 'Pick StraboField projects or datasets') !== false, "$c");
list($c, $b) = httpForm('/export_builder', null, array('search_dsl' => '{}'));
check('search door: anonymous POST -> 302 login', $c === 302, "$c");
// HOLD 0d8555a (Jason 2026-09-02, "hide export system until ready for public testing"): the
// menu entries and the My Field Data "Custom export…" button are commented out. FULL LAUNCH =
// revert 0d8555a and flip these two checks back to asserting the links are present.
list($c, , $b) = http('GET', '/my_exports', $own);
check('HOLD 0d8555a: account menu hides Export Builder + My Exports (flip at full launch)', strpos($b, '<li><a href="/export_builder">') === false && strpos($b, '<li><a href="/my_exports">') === false && strpos($b, 'my_samples">My Samples</a></li>') !== false);
list($c, , $b) = http('GET', '/my_field_data', $own);
check('HOLD 0d8555a: My Field Data toolbar has + New Project, Custom export… hidden, floating (Add Project) gone', $c === 200 && strpos($b, 'class="mfd-toolbar"') !== false && strpos($b, '/new_project" class="button primary small">+ New Project</a>') !== false && strpos($b, '/export_builder" class="button small"') === false && strpos($b, '(Add Project)') === false, "$c");
list($c, , $b) = http('GET', '/my_exports?new=' . UUID::v4(), $own);
$me = embedded($b, 'MY_EXPORTS');
check('My Exports renders with the shell + notice payload', $c === 200 && strpos($b, '<h2>My Exports</h2>') !== false && strpos($b, 'my_exports.js') !== false && $me && $me['notice']['kind'] === 'new', "$c");

// ------------------------------------------------------------------ 2. api: auth + count
list($c, $j) = api('status', null);
check('api without a session -> 401 JSON', $c === 401 && isset($j['ok']) && $j['ok'] === false, "$c " . json_encode($j));
list($c, $j) = api('nope', $own);
check('unknown action -> 400', $c === 400);
list($c, $j) = api('count', $own, array('recipe' => array('scope' => array('projects' => array()))));
check('count with no scope -> 400 with a message', $c === 400 && strpos($j['error'], 'at least one project') !== false, json_encode($j));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => $scopeP1)));
check('count: whole project fast path = 5, not approximate', $c === 200 && $j['count'] === 5 && $j['approximate'] === false && $j['used_index'] === false, json_encode($j));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => array('projects' => $scopeP1['projects'], 'datasets' => array(array('id' => (string)$DS_B, 'project_id' => (string)$P1, 'owner' => $OWNER))))));
check('count: one dataset = 2', $c === 200 && $j['count'] === 2, json_encode($j));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U1', 'value' => 'Granite')))));
check('count: keyword filter through the index = 1', $c === 200 && $j['count'] === 1 && $j['used_index'] === true, json_encode($j));
$poly = array('type' => 'Polygon', 'coordinates' => array(array(array(-118.30, 34.00), array(-118.20, 34.00), array(-118.20, 34.10), array(-118.30, 34.10), array(-118.30, 34.00))));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U2', 'value' => $poly)))));
check('count: area filter alone -> approximate, polygon applied to the indexed centroid like the search (3 of 5 inside)', $c === 200 && $j['approximate'] === true && $j['used_index'] === true && $j['count'] === 3, json_encode($j));
$farPoly = array('type' => 'Polygon', 'coordinates' => array(array(array(-116.0, 36.0), array(-115.0, 36.0), array(-115.0, 37.0), array(-116.0, 37.0), array(-116.0, 36.0))));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U2', 'value' => $farPoly)))));
check('count: area filter elsewhere -> 0 (was the whole scope before the 09-01 alignment fix)', $c === 200 && $j['approximate'] === true && $j['count'] === 0, json_encode($j));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U2', 'value' => $poly), array('id' => 'U1', 'value' => 'Granite')))));
check('count: area + keyword -> 1 (both applied in one index query)', $c === 200 && $j['approximate'] === true && $j['count'] === 1, json_encode($j));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => array('projects' => array(array('id' => (string)$P2, 'owner' => $STRANGER))))));
check('count: stranger project -> 400 access denied', $c === 400 && strpos($j['error'], 'do not have access') !== false, json_encode($j));
list($c, $j) = api('count', $col, array('recipe' => array('scope' => $scopeP1)));
check('count: collaborator session counts the shared project', $c === 200 && $j['count'] === 5, json_encode($j));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => array('projects' => $scopeP1['projects'], 'datasets' => array(array('id' => (string)$DS_X, 'project_id' => (string)$P1, 'owner' => $OWNER))))));
check('count: dataset from another project -> 400', $c === 400, json_encode($j));
list($c, $j) = api('count', $own, array('recipe' => array('scope' => $scopeP1, 'criteria' => array(array('id' => 'ZZ', 'value' => 'x')))));
check('count: bad criterion id -> 400 filter error', $c === 400 && stripos($j['error'], 'filter') !== false, json_encode($j));

// ------------------------------------------------------------------ 3. api: create + poll + detail
list($c, $j) = api('create', $own, array('recipe' => array('scope' => $scopeP1, 'formats' => array('gems'))));
check('create: unknown format -> 400', $c === 400 && strpos($j['error'], 'Unknown format') !== false, json_encode($j));
list($c, $j) = api('create', $own, array('recipe' => array('scope' => $scopeP1, 'formats' => array())));
check('create: no format -> 400', $c === 400, json_encode($j));
list($c, $j) = api('create', $str, array('recipe' => array('scope' => $scopeP1, 'formats' => array('geojson'))));
check('create: stranger cannot export the private project', $c === 400 && strpos($j['error'], 'do not have access') !== false, json_encode($j));
list($c, $j) = api('create', $own, array('recipe' => array('scope' => $scopeP1, 'formats' => array('geojson', 'xls'), 'layout' => 'merged', 'notes' => "  <b>keep</b> these notes  ", 'criteria' => array(array('id' => 'U1', 'value' => 'Spot'))),
	'summary' => 'Pages Project', 'email_on_done' => false, 'origin' => 'builder'));
check('create: accepted + kicked', $c === 200 && $j['ok'] && UUID::is_valid($j['uuid']) && $j['kicked'] === true, json_encode($j));
$U1 = $j['uuid'];
$row = $svc->get($U1);
check('create: stored recipe normalized (plugin, validated criteria, stripped notes)', $row && $row['recipe']['plugin'] === 'field' && $row['recipe']['v'] === 1 && $row['recipe']['criteria'][0]['id'] === 'U1' && $row['recipe']['notes'] === 'keep these notes' && $row['recipe']['children'] === 'matched_parents', json_encode($row ? $row['recipe'] : null));
check('create: summary = hint + formats + filter count', $row['recipe_summary'] === 'Pages Project · geojson, xls · 1 filter', $row['recipe_summary']);
$row = wait_done($svc, $U1);
check('kicked job ran to done through Apache exec (4 spots named Spot)', $row['status'] === 'done' && $row['item_count'] === 4 && $row['result_bytes'] > 0, $row['status'] . ' ' . $row['error_text'] . ' ' . $row['item_count']);
list($c, $j) = api('status', $own);
$mine = null; foreach ($j['jobs'] as $x) if ($x['uuid'] === $U1) $mine = $x;
check('status lists the job with light columns', $c === 200 && $mine && $mine['status'] === 'done' && $mine['formats'] === array('geojson', 'xls') && $mine['filter_count'] === 1 && !isset($mine['recipe']), json_encode($mine));
list($c, $j) = api('detail&uuid=' . $U1, $own);
check('detail returns recipe + README text', $c === 200 && isset($j['job']['recipe']['scope']) && strpos($j['job']['readme'], 'Projects:') !== false && strpos($j['job']['readme'], 'Pages Project') !== false, json_encode($j));
list($c, $j) = api('detail&uuid=' . $U1, $str);
check('detail for another user\'s uuid -> 404', $c === 404, json_encode($j));
list($c, $j) = api('detail&uuid=not-a-uuid', $own);
check('detail malformed uuid -> 404', $c === 404);
list($c, , $b) = http('GET', '/export_builder?from=' . $U1, $own);
$eb = embedded($b, 'EXPORT_BUILDER');
check('?from=<uuid> pre-fills the builder from the recipe', $eb && $eb['initial'] && $eb['initial']['formats'] === array('geojson', 'xls') && $eb['initial']['criteria'][0]['id'] === 'U1', json_encode($eb ? $eb['initial'] : null));
list($c, , $b) = http('GET', '/export_builder?from=' . $U1, $str);
$eb = embedded($b, 'EXPORT_BUILDER');
check('?from= with someone else\'s uuid is ignored', $eb && $eb['initial'] === null);

// ------------------------------------------------------------------ 4. download
list($c, $h, $b) = http('GET', '/exportjobs/download.php?j=' . $U1, $own);
check('owner download: 200 zip with attachment headers + full length', $c === 200 && strpos($h['content-type'], 'application/zip') !== false && strpos($h['content-disposition'], 'strabospot_export_') !== false && (int)$h['content-length'] === strlen($b) && substr($b, 0, 2) === 'PK', "$c " . json_encode($h));
check('download logged', strpos((string)@file_get_contents(rtrim($cfg['log_root'], '/') . '/downloads.log'), $U1) !== false);
list($c) = http('GET', '/exportjobs/download.php?j=' . $U1, $str);
check('stranger download -> 404', $c === 404, "$c");
list($c) = http('GET', '/exportjobs/download.php?j=' . UUID::v4(), $own);
check('unknown uuid download -> 404', $c === 404, "$c");
$queued = $svc->create($OWNER, array('v' => 1, 'plugin' => 'field', 'scope' => $scopeP1, 'formats' => array('geojson')), array('summary' => 'queued only', 'origin' => 'test'));
list($c, $h) = http('GET', '/exportjobs/download.php?j=' . $queued['uuid'], $own);
check('not-done download -> 303 to My Exports notready', $c === 303 && strpos($h['location'], '/my_exports?notice=notready') === 0, "$c " . json_encode($h));
$abs = $svc->resultPath($svc->get($U1));
rename($abs, $abs . '.hidden');
list($c, $h) = http('GET', '/exportjobs/download.php?j=' . $U1, $own);
$row = $svc->get($U1);
check('done-but-missing file -> row expired + 303 expired notice', $c === 303 && strpos($h['location'], '/my_exports?notice=expired') === 0 && $row['status'] === 'expired', "$c {$row['status']}");
@unlink($abs . '.hidden');

// ------------------------------------------------------------------ 5. rerun / cancel / clear / cap
list($c, $j) = api('create', $own, array('recipe' => array('scope' => $scopeP1, 'formats' => array('geojson'), 'criteria' => array(array('id' => 'U2', 'value' => $poly))), 'summary' => 'Poly'));
$UP = $j['uuid'];
$row = $svc->get($UP);
check('create with a polygon stores U2 as GeoJSON (builder-loadable)', $c === 200 && $row['recipe']['criteria'][0]['value']['type'] === 'Polygon', json_encode($row ? $row['recipe']['criteria'] : $j));
$row = wait_done($svc, $UP);
check('polygon job builds (3 of 5 spots inside)', $row['status'] === 'done' && $row['item_count'] === 3, $row['status'] . ' ' . $row['error_text'] . ' ' . $row['item_count']);
list($c, $j) = api('rerun', $own, array('uuid' => $UP));
$row = wait_done($svc, $j['uuid']);
check('polygon job re-runs from the stored recipe', $c === 200 && $row['status'] === 'done' && $row['item_count'] === 3, $row['status'] . ' ' . $row['error_text']);
list($c, $j) = api('rerun', $own, array('uuid' => $U1));
check('rerun of the expired job -> new queued job, kicked', $c === 200 && $j['ok'] && $j['uuid'] !== $U1 && $j['job']['origin'] === 'rerun', json_encode($j));
$U2 = $j['uuid'];
$row = wait_done($svc, $U2);
check('rerun ran to done with the same recipe', $row['status'] === 'done' && $row['item_count'] === 4 && $row['recipe_summary'] === 'Pages Project · geojson, xls · 1 filter', $row['status'] . ' ' . $row['error_text']);
list($c, $j) = api('rerun', $str, array('uuid' => $U1));
check('rerun of someone else\'s job -> 404', $c === 404);
list($c, $j) = api('cancel', $own, array('uuid' => $queued['uuid']));
check('cancel a queued job', $c === 200 && $j['cancelled'] === true && $svc->get($queued['uuid'])['status'] === 'cancelled', json_encode($j));
list($c, $j) = api('cancel', $own, array('uuid' => $queued['uuid']));
check('cancel again -> false (already terminal)', $c === 200 && $j['cancelled'] === false);
$queued2 = $svc->create($OWNER, array('v' => 1, 'plugin' => 'field', 'scope' => $scopeP1, 'formats' => array('geojson')), array('summary' => 'stays queued', 'origin' => 'test'));
list($c, $j) = api('clear', $own, array('which' => 'expired'));
check('clear expired -> 1', $c === 200 && $j['cleared'] === 1, json_encode($j));
list($c, $j) = api('clear', $own, array('which' => 'finished'));
check('clear finished -> done + cancelled rows (4), queued untouched', $c === 200 && $j['cleared'] === 4, json_encode($j));
list($c, $j) = api('status', $own);
$left = array_map(function ($x) { return $x['status']; }, $j['jobs']);
check('status after clear shows only the queued row', $left === array('queued'), json_encode($left));
for ($i = 0; $i < (int)$cfg['max_queued_per_user'] - 1; $i++) {
	$svc->create($OWNER, array('v' => 1, 'plugin' => 'field', 'scope' => $scopeP1, 'formats' => array('geojson')), array('summary' => "filler $i", 'origin' => 'test'));
}
list($c, $j) = api('create', $own, array('recipe' => array('scope' => $scopeP1, 'formats' => array('geojson'))));
check('per-user cap enforced at create', $c === 400 && strpos($j['error'], 'queued or running') !== false, json_encode($j));
$db->prepare_query("UPDATE export_jobs SET status = 'cancelled', finished_at = now() WHERE userpkey = $1 AND status = 'queued'", array($OWNER));

// ------------------------------------------------------------------ 6. email (file transport, worker run directly)
$mailLog = rtrim($cfg['log_root'], '/') . '/mail.log';
@unlink($mailLog);
$env = 'EXPORTJOBS_CONFIG_JSON=' . escapeshellarg(json_encode(array('mail_transport' => 'file', 'site_url' => 'https://dev.example')));
$em = $svc->create($OWNER, array('v' => 1, 'plugin' => 'field', 'scope' => $scopeP1, 'formats' => array('geojson')), array('summary' => 'Mail me', 'origin' => 'test', 'email_on_done' => true));
exec("$env php /srv/app/www/exportjobs/worker.php --job={$em['uuid']} 2>&1", $o);
$row = $svc->get($em['uuid']); $mail = (string)@file_get_contents($mailLog);
check('done email filed: address, subject, summary, counts, expiry, My Exports link', $row['status'] === 'done' && strpos($mail, "To: pg-$OWNER@test.strabospot.org") !== false && strpos($mail, 'Subject: Your StraboSpot export is ready') !== false
	&& strpos($mail, 'Export: Mail me') !== false && strpos($mail, 'Spots: 5') !== false && strpos($mail, 'Available until:') !== false && strpos($mail, 'https://dev.example/my_exports') !== false, $row['status'] . ' ' . substr($mail, 0, 300));
$bad = $svc->create($OWNER, array('v' => 1, 'plugin' => 'field', 'scope' => $scopeP1, 'formats' => array('geojson'), 'criteria' => array(array('id' => 'U1', 'value' => 'zzz_nothing_zzz'))), array('summary' => 'Doomed', 'origin' => 'test', 'email_on_done' => true));
exec("$env php /srv/app/www/exportjobs/worker.php --job={$bad['uuid']} 2>&1", $o);
$row = $svc->get($bad['uuid']); $mail = (string)@file_get_contents($mailLog);
check('failed email filed with the problem text', $row['status'] === 'failed' && strpos($mail, 'Subject: Your StraboSpot export could not be built') !== false && strpos($mail, 'Problem: No spots matched') !== false, $row['status'] . ' ' . substr($mail, -300));
$quiet = $svc->create($OWNER, array('v' => 1, 'plugin' => 'field', 'scope' => $scopeP1, 'formats' => array('geojson')), array('summary' => 'No mail', 'origin' => 'test', 'email_on_done' => false));
$before = strlen($mail);
exec("$env php /srv/app/www/exportjobs/worker.php --job={$quiet['uuid']} 2>&1", $o);
check('no email without the flag', $svc->get($quiet['uuid'])['status'] === 'done' && strlen((string)@file_get_contents($mailLog)) === $before);
check('mail failures never change the outcome (transport none)', (function () use ($svc, $OWNER, $scopeP1) {
	$j = $svc->create($OWNER, array('v' => 1, 'plugin' => 'field', 'scope' => $scopeP1, 'formats' => array('geojson')), array('summary' => 'none', 'origin' => 'test', 'email_on_done' => true));
	exec('EXPORTJOBS_CONFIG_JSON=' . escapeshellarg('{"mail_transport":"none"}') . " php /srv/app/www/exportjobs/worker.php --job={$j['uuid']} 2>&1", $o);
	return $svc->get($j['uuid'])['status'] === 'done';
})());
@unlink($mailLog);
check('worker left no workspaces', count(glob(rtrim($cfg['work_root'], '/') . '/*', GLOB_ONLYDIR)) === 0);

// ------------------------------------------------------------------ cleanup
cleanup();
check('zero residue', (int)$neodb->query("MATCH (u:User) WHERE u.userpkey IN [$OWNER, $STRANGER] RETURN count(u) AS c")[0]->value('c') === 0
	&& (int)$db->get_var_prepared("SELECT count(*) FROM export_jobs WHERE userpkey IN ($1, $2, $3)", array($OWNER, $COLLAB, $STRANGER)) === 0
	&& !is_dir(rtrim($cfg['results_root'], '/') . "/$OWNER"));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
