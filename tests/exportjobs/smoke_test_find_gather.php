<?php
/**
 * File: tests/exportjobs/smoke_test_find_gather.php
 * Description: M2 smoke suite for the Export Builder FIND + GATHER stages
 *              (docs/ExportBuilder_Design.md §7, §12 M2). Seeds a Field
 *              graph for a fixture owner (2 projects x datasets, located
 *              spots, image-basemap children + a grandchild, a strat child,
 *              a boundary-crossing line, an overlapping polygon spot),
 *              indexes it through the real sync hooks, then checks:
 *              access levels (owner / collaborator / stranger / anonymous /
 *              public), scope validation, the no-criteria Neo4j fast path,
 *              dataset scoping, index-driven criteria (has-flag, keyword),
 *              GEOS polygon intersects incl. the centroid-outside line,
 *              D12 children-of-matched-parents (recursive + strat),
 *              chunked gather, max_items overflow, fetcher regression, and
 *              zero residue.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/exportjobs/smoke_test_find_gather.php
 */

chdir('/srv/app/www');
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/geophp/geoPHP.inc';
require_once 'db/strabospotclass.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once 'exportjobs/lib/export_config.php';
require_once 'exportjobs/lib/ExportAccess.php';
require_once 'exportjobs/lib/ExportFinder.php';
require_once 'exportjobs/lib/ExportGatherer.php';

$OWNER = 94541; $COLLAB = 94542; $STRANGER = 94543;
$P1 = 945411001; $P2 = 945411002;               // P1 private, P2 public
$DS1 = 945412001; $DS2 = 945412002; $DS3 = 945412003;
$S_IN_A = 945413001; $S_IN_B = 945413002; $S_OUT = 945413003; $S_LINE = 945413004; $S_POLY = 945413005;
$C_IN = 945413006; $C_OUT = 945413007; $GC = 945413008; $SC = 945413009; $S_DS2 = 945413010; $S_P2 = 945413011;
$IMG_A = 945414001; $IMG_OUT = 945414002; $IMG_C = 945414003; $STRAT = 945415001;
$POLY = array('type' => 'Polygon', 'coordinates' => array(array(
	array(-118.30, 34.00), array(-118.20, 34.00), array(-118.20, 34.10), array(-118.30, 34.10), array(-118.30, 34.00))));

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function ids($features) { $o = array(); foreach ($features as $f) $o[] = (int)ExportGatherer::spotId($f); sort($o); return $o; }
function throws($fn, $needle = null) {
	try { $fn(); return false; } catch (ExportJobError $e) { return $needle === null || strpos($e->getMessage(), $needle) !== false; }
}
function cleanup() {
	global $db, $neodb, $OWNER, $COLLAB, $STRANGER, $P1, $P2;
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
		OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image) DETACH DELETE i, s");
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) DETACH DELETE d, p");
	$neodb->query("MATCH (u:User {userpkey: $OWNER}) DETACH DELETE u");
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_userpkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_userpkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM collaborators WHERE project_owner_user_pkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($OWNER));
}

echo "Export Builder M2 find/gather smoke suite\n";
cleanup();

// ------------------------------------------------------------------ fixtures
// PG project rows carry a FK to users; the Neo4j User node anchors every graph walk.
$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active) VALUES ($1, 'fg', 'fixture', $2, 'x', 'x', false)",
	array($OWNER, "fg-$OWNER@test.strabospot.org"));
$neodb->query("CREATE (u:User {userpkey: $OWNER, email: 'fg-$OWNER@test.strabospot.org'})");
foreach (array($P1 => array('FG private project', 'FALSE'), $P2 => array('FG public project', 'TRUE')) as $pid => $pp) {
	$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $pid, userpkey: $OWNER, desc_project_name: '{$pp[0]}'}) CREATE (u)-[:HAS_PROJECT]->(p)");
	$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, $2, $3, {$pp[1]})", array($OWNER, $pp[0], (string)$pid));
}
foreach (array($DS1 => $P1, $DS2 => $P1, $DS3 => $P2) as $did => $pid) {
	$neodb->query("MATCH (p:Project {id: $pid, userpkey: $OWNER}) CREATE (d:Dataset {id: $did, userpkey: $OWNER, name: 'FG DS $did'}) CREATE (p)-[:HAS_DATASET]->(d)");
}
$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid)
	VALUES ($1, $2, $3, 'edit', TRUE, FALSE, $4)", array((string)$P1, $OWNER, $COLLAB, 'fg-' . $OWNER . '-collab'));
$ORI = '[{\"strike\": 120, \"dip\": 30, \"type\": \"planar_orientation\", \"feature_type\": \"bedding\"}]';
function spot($did, $id, $name, $wkt, $extra = '') {
	global $neodb, $OWNER;
	$neodb->query("MATCH (d:Dataset {id: $did, userpkey: $OWNER}) CREATE (s:Spot {id: $id, userpkey: $OWNER, name: '$name', wkt: '$wkt', origwkt: '$wkt',
		modified_timestamp: 1722400000000, date: '2026-07-15' $extra}) CREATE (d)-[:HAS_SPOT]->(s)");
}
spot($DS1, $S_IN_A, 'FGTOK_alpha inside A', 'POINT (-118.25 34.05)', ", json_orientation_data: '$ORI'");
spot($DS1, $S_IN_B, 'inside B strat host', 'POINT (-118.22 34.08)', ", json_sed: '{\"strat_section\": {\"strat_section_id\": $STRAT}}'");
spot($DS1, $S_OUT, 'outside', 'POINT (-118.10 34.05)', ", json_orientation_data: '$ORI'");
spot($DS1, $S_LINE, 'crossing line', 'LINESTRING (-118.21 34.05, -118.05 34.05)');
spot($DS1, $S_POLY, 'overlapping polygon', 'POLYGON ((-118.22 34.09, -118.15 34.09, -118.15 34.15, -118.22 34.15, -118.22 34.09))');
spot($DS1, $C_IN, 'child of inside A', 'POINT (100 200)', ", image_basemap: $IMG_A");
spot($DS1, $C_OUT, 'child of outside', 'POINT (100 200)', ", image_basemap: $IMG_OUT");
spot($DS1, $GC, 'grandchild via child', 'POINT (10 20)', ", image_basemap: $IMG_C");
spot($DS1, $SC, 'strat child of inside B', 'POINT (5 40)', ", strat_section_id: $STRAT");
spot($DS2, $S_DS2, 'ds2 inside', 'POINT (-118.28 34.02)', ", json_orientation_data: '$ORI'");
spot($DS3, $S_P2, 'p2 inside', 'POINT (-118.26 34.03)', ", json_orientation_data: '$ORI'");
foreach (array($S_IN_A => $IMG_A, $S_OUT => $IMG_OUT, $C_IN => $IMG_C) as $sid => $iid) {
	$neodb->query("MATCH (s:Spot {id: $sid, userpkey: $OWNER}) CREATE (i:Image {id: $iid, userpkey: $OWNER, image_type: 'photo', filename: '$iid', modified_timestamp: 1722400001000}) CREATE (s)-[:HAS_IMAGE]->(i)");
}
$ALL_DS1 = array($S_IN_A, $S_IN_B, $S_OUT, $S_LINE, $S_POLY, $C_IN, $C_OUT, $GC, $SC);
foreach (array_merge($ALL_DS1, array($S_DS2, $S_P2)) as $sid) StraboSearchSync::touchSpot($db, $neodb, $sid, $OWNER);
$idx = (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_userpkey = $1 AND item_type = 'spot'", array($OWNER));
check('fixtures indexed (11 spot rows)', $idx === 11, "got $idx");
$unloc = (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_userpkey = $1 AND item_type = 'spot' AND location IS NULL", array($OWNER));
check('nested children index as unlocated (4)', $unloc === 4, "got $unloc");

$strabo = new StraboSpot($neodb, $OWNER, $db);
$cfg = export_config();
$scopeP1 = array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)));
$scopeDS1 = array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)),
	'datasets' => array(array('id' => (string)$DS1, 'project_id' => (string)$P1, 'owner' => $OWNER)));

// ------------------------------------------------------------------ A. access
check('owner -> owner', ExportAccess::level($db, $OWNER, $P1, $OWNER) === 'owner');
check('collaborator -> collaborator on P1', ExportAccess::level($db, $COLLAB, $P1, $OWNER) === 'collaborator');
check('stranger -> null on private P1', ExportAccess::level($db, $STRANGER, $P1, $OWNER) === null);
check('stranger -> public on P2', ExportAccess::level($db, $STRANGER, $P2, $OWNER) === 'public');
check('anonymous -> public on P2 only', ExportAccess::level($db, 0, $P2, $OWNER) === 'public' && ExportAccess::level($db, 0, $P1, $OWNER) === null);
check('wrong owner pkey -> null (ids not unique)', ExportAccess::level($db, $OWNER, $P1, $OWNER + 1) === null);
$db->prepare_query("UPDATE collaborators SET disabled = TRUE WHERE project_owner_user_pkey = $1", array($OWNER));
check('disabled collaborator -> null', ExportAccess::level($db, $COLLAB, $P1, $OWNER) === null);
$db->prepare_query("UPDATE collaborators SET disabled = FALSE WHERE project_owner_user_pkey = $1", array($OWNER));

// ------------------------------------------------------------------ B. scope validation
$fOwner = new ExportFinder($db, $neodb, $OWNER);
$fStranger = new ExportFinder($db, $neodb, $STRANGER);
$fCollab = new ExportFinder($db, $neodb, $COLLAB);
check('empty scope rejected', throws(function () use ($fOwner) { $fOwner->resolveScope(array('scope' => array())); }, 'at least one'));
check('stranger on private project rejected', throws(function () use ($fStranger, $scopeP1) { $fStranger->resolveScope(array('scope' => $scopeP1)); }, 'do not have access'));
check('dataset outside selected project rejected', throws(function () use ($fOwner, $P1, $P2, $DS3, $OWNER) {
	$fOwner->resolveScope(array('scope' => array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)),
		'datasets' => array(array('id' => (string)$DS3, 'project_id' => (string)$P2, 'owner' => $OWNER))))); }, 'not in a selected project'));
check('dataset that is not in the graph under that project rejected', throws(function () use ($fOwner, $P1, $DS3, $OWNER) {
	$fOwner->find(array('scope' => array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)),
		'datasets' => array(array('id' => (string)$DS3, 'project_id' => (string)$P1, 'owner' => $OWNER))))); }, 'does not belong'));
check('malformed project id rejected', throws(function () use ($fOwner, $OWNER) {
	$fOwner->resolveScope(array('scope' => array('projects' => array(array('id' => "1 OR 1=1", 'owner' => $OWNER))))); }, 'Malformed'));
check('bad criterion rejected via DSL validator', throws(function () use ($fOwner, $scopeP1) {
	$fOwner->resolveCriteria(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'ZZ9')))); }, 'Filter error'));

// ------------------------------------------------------------------ C. fast path (no criteria)
$g = new ExportGatherer($strabo);
$r = $fOwner->find(array('scope' => $scopeP1));
check('no criteria: index not used', $r['used_index'] === false && $r['index_synced_at'] === null);
check('no criteria: P1 -> 2 datasets, 10 candidates', count($r['projects']) === 1 && count($r['projects'][0]['dataset_ids']) === 2 && $r['candidate_count'] === 10, json_encode($r['projects'][0]['dataset_ids']) . '/' . $r['candidate_count']);
$gr = $g->gather($r['projects'][0], null);
check('gather (whole datasets) -> 10 features', $gr['item_count'] === 10 && count($gr['features']) === 10, $gr['item_count']);
$exp = $ALL_DS1; $exp[] = $S_DS2; sort($exp);
check('gather ids = graph membership', ids($gr['features']) === $exp);

// ------------------------------------------------------------------ D. dataset scoping
$r = $fOwner->find(array('scope' => $scopeDS1));
check('DS1 only -> 9 candidates', $r['candidate_count'] === 9 && $r['projects'][0]['dataset_ids'] === array((string)$DS1));

// ------------------------------------------------------------------ E. index-driven criteria
$r = $fOwner->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U9', 'value' => array('orientation')))));
check('U9 orientation: index used, synced_at set', $r['used_index'] === true && $r['index_synced_at'] !== null);
$got = $r['projects'][0]['spot_ids']; sort($got);
check('U9 orientation -> S_IN_A, S_OUT, S_DS2', $got === array($S_IN_A, $S_OUT, $S_DS2), json_encode($got));
$gSmall = new ExportGatherer($strabo, array_merge($cfg, array('gather_chunk' => 2)));
$prog = array();
$gr = $gSmall->gather($r['projects'][0], null, function ($d, $t, $n) use (&$prog) { $prog[] = "$d/$t"; });
check('chunked gather (2 per chunk) -> 3 features', ids($gr['features']) === array($S_IN_A, $S_OUT, $S_DS2) && count($prog) === 2, json_encode($prog));
$r = $fOwner->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U1', 'value' => 'FGTOK_alpha'))));
check('U1 keyword -> exactly S_IN_A', $r['projects'][0]['spot_ids'] === array($S_IN_A), json_encode($r['projects'][0]['spot_ids']));
$r = $fOwner->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U9', 'value' => array('orientation'), 'not' => true))));
$got = $r['projects'][0]['spot_ids']; sort($got);
check('NOT orientation -> the other 7', count($got) === 7 && !in_array($S_IN_A, $got) && !in_array($S_OUT, $got), json_encode($got));

// ------------------------------------------------------------------ F. polygon only (fast path + GEOS)
$r = $fOwner->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U2', 'value' => $POLY))));
check('polygon-only: fast path (spatial is not an index criterion)', $r['used_index'] === false && $r['polygon'] !== null);
$gr = $g->gather($r['projects'][0], $r['polygon']);
$exp = array($S_IN_A, $S_IN_B, $S_LINE, $S_POLY, $C_IN, $GC, $SC, $S_DS2); sort($exp);
check('polygon keeps inside points + crossing line (centroid outside) + overlapping polygon', ids($gr['features']) === $exp, json_encode(ids($gr['features'])));
check('children: image child, grandchild, strat child follow kept parents (child_count 3)', $gr['child_count'] === 3, $gr['child_count']);
check('dropped: outside point + its child', $gr['dropped_spatial'] === 2, $gr['dropped_spatial']);
$bbox = $fOwner->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U2', 'value' => array('bbox' => array(-118.30, 34.00, -118.20, 34.10))))));
$gr2 = $g->gather($bbox['projects'][0], $bbox['polygon']);
check('bbox value form gives the same result', ids($gr2['features']) === $exp);
$cnt = $fOwner->count(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U2', 'value' => $POLY))));
check('count (alignment, 09-01): polygon-only = 3 centroids inside via the index (S_IN_A, S_IN_B, S_DS2), approximate, not the 10-spot scope', $cnt['count'] === 3 && $cnt['approximate'] === true && $cnt['used_index'] === true, json_encode($cnt));
$cnt = $fOwner->count(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U2', 'value' => $POLY), array('id' => 'U9', 'value' => array('orientation')))));
check('count (alignment, 09-01): polygon + orientation = 2 (S_IN_A, S_DS2)', $cnt['count'] === 2 && $cnt['approximate'] === true, json_encode($cnt));
$cnt = $fOwner->count(array('scope' => $scopeP1));
check('count: no criteria stays on the graph fast path (10, exact)', $cnt['count'] === 10 && $cnt['approximate'] === false && $cnt['used_index'] === false, json_encode($cnt));

// ------------------------------------------------------------------ G. polygon + non-spatial
$r = $fOwner->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U2', 'value' => $POLY), array('id' => 'U9', 'value' => array('orientation')))));
$gr = $g->gather($r['projects'][0], $r['polygon']);
check('polygon + orientation: index candidates then GEOS -> S_IN_A, S_DS2; child not a candidate so excluded', ids($gr['features']) === array($S_IN_A, $S_DS2) && $gr['child_count'] === 0, json_encode(ids($gr['features'])));

// ------------------------------------------------------------------ H. collaborator + stranger paths through the index ACL
$r = $fCollab->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U9', 'value' => array('orientation')))));
check('collaborator: index ACL admits P1 rows', count($r['projects'][0]['spot_ids']) === 3);
$scopeP2 = array('projects' => array(array('id' => (string)$P2, 'owner' => $OWNER)));
$r = $fStranger->find(array('scope' => $scopeP2, 'criteria' => array(array('id' => 'U9', 'value' => array('orientation')))));
check('stranger on public P2: allowed, index ACL admits public rows', $r['projects'][0]['spot_ids'] === array($S_P2));
$gr = $g->gather($r['projects'][0], null);
check('stranger gather anchored on recipe owner returns the public spot', ids($gr['features']) === array($S_P2));

// ------------------------------------------------------------------ I. max_items
$fTiny = new ExportFinder($db, $neodb, $OWNER, array_merge($cfg, array('max_items' => 2)));
check('max_items overflow on fast path', throws(function () use ($fTiny, $scopeP1) { $fTiny->find(array('scope' => $scopeP1)); }, 'more than'));
check('max_items overflow on index path', throws(function () use ($fTiny, $scopeP1) {
	$fTiny->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U9', 'value' => array('orientation'))))); }, 'more than'));
check('max_items not tripped at the limit', $fTiny->find(array('scope' => $scopeP1, 'criteria' => array(array('id' => 'U1', 'value' => 'FGTOK_alpha'))))['candidate_count'] === 1);

// ------------------------------------------------------------------ J. fetcher regression
$fc = $strabo->getDatasetSpotsSearch(null, array('dsids' => (string)$DS1, 'userpkey' => $OWNER));
check('fetcher without spot_ids unchanged (9 features)', count(ExportGatherer::featuresOf($fc)) === 9);
$fc = $strabo->getDatasetSpotsSearch(null, array('dsids' => (string)$DS1, 'userpkey' => $OWNER, 'spot_ids' => array()));
check('fetcher with EMPTY spot_ids matches nothing', count(ExportGatherer::featuresOf($fc)) === 0);
$fc = $strabo->getDatasetSpotsSearch(null, array('dsids' => (string)$DS1, 'userpkey' => $OWNER, 'spot_ids' => "$S_OUT, 999999999999, abc"));
check('fetcher with comma-list spot_ids (junk ignored)', ids(ExportGatherer::featuresOf($fc)) === array($S_OUT));
$fc = $strabo->getDatasetSpotsSearch(null, array('dsids' => (string)$DS1, 'userpkey' => $OWNER + 1, 'spot_ids' => array($S_OUT)));
check('fetcher still anchors on the dataset owner', count(ExportGatherer::featuresOf($fc)) === 0);

// ------------------------------------------------------------------ cleanup
cleanup();
check('zero residue: graph', (int)$neodb->query("MATCH (u:User {userpkey: $OWNER}) RETURN count(u) AS c")[0]->value('c') === 0);
check('zero residue: index + PG', (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_userpkey = $1", array($OWNER)) === 0
	&& (int)$db->get_var_prepared("SELECT count(*) FROM project WHERE user_pkey = $1", array($OWNER)) === 0
	&& (int)$db->get_var_prepared("SELECT count(*) FROM collaborators WHERE project_owner_user_pkey = $1", array($OWNER)) === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
