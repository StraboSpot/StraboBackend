<?php
/**
 * File: tests/exportjobs/smoke_test_criteria_parity.php
 * Description: Search <-> Export criteria PARITY MATRIX (2026-09-01, after
 *              Jason's Nevada-polygon report: search listed 11 projects, the
 *              builder preselected 58, because one seam skipped U2). For every
 *              Field-applicable criterion (U1 U2 U3 U5 U6 U7 U9 U10, F1..F11),
 *              NOT variants and multi-row combos, ONE DSL is pushed through:
 *                a. the search results list  (SearchQueryBuilder::runProjectsQuery)
 *                b. the StraboSearch door    (runItemProjectCountsQuery)
 *                c. the builder live count   (ExportFinder::count)
 *                d. the FIND stage           (ExportFinder::find spot ids)
 *                e. the stored-recipe form   (count again with validate()'s output rows)
 *              and all of them must agree with a HAND-COMPUTED expected spot
 *              list. The fixture is 8 index rows (+ the Neo4j / PG skeleton the
 *              finder walks) across three projects: own private P1, a stranger's
 *              PUBLIC P2 (the door's public expansion), own private control P3.
 *              Index rows are seeded directly (as the search API suite does), so
 *              every column is under the test's control.
 *
 * Run (inside the container):
 *   docker exec strabo-php php /srv/app/www/tests/exportjobs/smoke_test_criteria_parity.php
 *
 * Fixture users 94581 (owner) / 94582 (stranger, public project). Zero residue.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

chdir('/srv/app/www');
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/geophp/geoPHP.inc';
require_once 'searchdb/services/SearchQueryBuilder.php';
require_once 'exportjobs/lib/export_config.php';
require_once 'exportjobs/lib/ExportAccess.php';
require_once 'exportjobs/lib/ExportFinder.php';

$OWNER = 94581; $STRANGER = 94582;
$P1 = 945811001; $P2 = 945811002; $P3 = 945811003;
$DS_A = 945812001; $DS_B = 945812002; $DS_C = 945812003; $DS_D = 945812004;
$S = array(); for ($i = 1; $i <= 8; $i++) $S[$i] = 945813000 + $i;
$VOCAB = array('zzp_rock', 'zzp_rock:mid', 'zzp_rock:mid:leaf');
$LA = array('bbox' => array(-118.30, 34.00, -118.20, 34.10));
$LA_POLY = array('type' => 'Polygon', 'coordinates' => array(array(array(-118.30, 34.00), array(-118.20, 34.00), array(-118.20, 34.10), array(-118.30, 34.10), array(-118.30, 34.00))));

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr($detail, 0, 500) . "]" : '') . "\n"; }
}
function cleanup() {
	global $db, $neodb, $OWNER, $STRANGER, $VOCAB;
	foreach (array($OWNER, $STRANGER) as $u) {
		$neodb->query("MATCH (u:User {userpkey: $u})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot) DETACH DELETE s, d, p");
		$neodb->query("MATCH (u:User {userpkey: $u}) DETACH DELETE u");
		$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_userpkey = $1", array($u));
		$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($u));
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u));
	}
	foreach ($VOCAB as $v) $db->prepare_query("DELETE FROM strabosearch.vocab_rock_type WHERE path = $1", array($v));
}

/** item_hit fixture insert with defaults (mirror of the search API suite's seedItem). */
function seedItem($db, $over) {
	$d = array_merge(array(
		'item_type' => 'spot', 'item_id' => '', 'item_userpkey' => 0,
		'project_id' => '', 'project_userpkey' => 0, 'project_subsystem' => 'field',
		'project_name' => '', 'project_ispublic' => 'FALSE',
		'lng' => null, 'lat' => null, 'date_value' => null, 'searchtext' => '',
		'sample_id' => null, 'sample_name' => null, 'igsn' => null,
		'display_sample_type' => null, 'display_sample_purpose' => null,
		'has_orientation' => 'FALSE', 'has_samples' => 'FALSE', 'has_images' => 'FALSE',
		'has_microstructure' => 'FALSE', 'has_strat' => 'FALSE',
		'orientation_strike' => null, 'orientation_dip' => null,
		'orientation_trend' => null, 'orientation_plunge' => null,
		'orientation_features' => null, 'orientation_planar' => null,
		'rock_types' => null, 'met_facies' => null, 'trace_types' => null,
		'tag_names' => null, 'tag_types' => null, 'tag_text' => null,
		'dataset_ids' => null, 'source_modified' => '2024-01-01 00:00:00+00',
	), $over);
	$lit = function ($v) { return $v === null ? 'NULL' : "'" . pg_escape_string((string)$v) . "'"; };
	$arr = function ($v) { if ($v === null) return 'NULL'; $p = array(); foreach ($v as $x) $p[] = "'" . pg_escape_string((string)$x) . "'"; return 'ARRAY[' . implode(',', $p) . ']::text[]'; };
	$narr = function ($v) { return $v === null ? 'NULL' : 'ARRAY[' . implode(',', array_map('floatval', $v)) . ']::numeric[]'; };
	$barr = function ($v) { if ($v === null) return 'NULL'; $p = array(); foreach ($v as $x) $p[] = $x ? 'TRUE' : 'FALSE'; return 'ARRAY[' . implode(',', $p) . ']::boolean[]'; };
	$loc = ($d['lng'] === null) ? 'NULL' : 'ST_SetSRID(ST_MakePoint(' . (float)$d['lng'] . ',' . (float)$d['lat'] . '), 4326)';
	$sql = "INSERT INTO strabosearch.item_hit
		(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
		 project_name, project_ispublic, location, date_value, searchtext_tsv,
		 sample_id, sample_name, igsn, display_sample_type, display_sample_purpose,
		 has_orientation, has_samples, has_images, has_microstructure, has_strat,
		 orientation_strike, orientation_dip, orientation_trend, orientation_plunge,
		 orientation_features, orientation_planar, rock_types, met_facies, trace_types,
		 tag_names, tag_types, tag_text_tsv, dataset_ids, source_modified)
		VALUES (
		 {$lit($d['item_type'])}, {$lit($d['item_id'])}, {$d['item_userpkey']},
		 {$lit($d['project_id'])}, {$d['project_userpkey']}, {$lit($d['project_subsystem'])},
		 {$lit($d['project_name'])}, {$d['project_ispublic']}, $loc, {$lit($d['date_value'])},
		 to_tsvector('english', {$lit($d['searchtext'])}),
		 {$lit($d['sample_id'])}, {$lit($d['sample_name'])}, {$lit($d['igsn'])},
		 {$lit($d['display_sample_type'])}, {$lit($d['display_sample_purpose'])},
		 {$d['has_orientation']}, {$d['has_samples']}, {$d['has_images']},
		 {$d['has_microstructure']}, {$d['has_strat']},
		 {$narr($d['orientation_strike'])}, {$narr($d['orientation_dip'])},
		 {$narr($d['orientation_trend'])}, {$narr($d['orientation_plunge'])},
		 {$arr($d['orientation_features'])}, {$barr($d['orientation_planar'])},
		 {$arr($d['rock_types'])}, {$arr($d['met_facies'])}, {$arr($d['trace_types'])},
		 {$arr($d['tag_names'])}, {$arr($d['tag_types'])},
		 to_tsvector('english', {$lit((string)$d['tag_text'])}),
		 {$arr($d['dataset_ids'])}, {$lit($d['source_modified'])})";
	$ok = $db->query($sql);
	if ($ok === false) { echo "  SEED FAILED: " . $db->last_error . PHP_EOL; exit(1); }
}

echo "Export Builder criteria parity matrix (search list = door = live count = find)\n";
cleanup();

// ------------------------------------------------------------------ fixtures: PG users/projects + Neo4j skeleton (the finder's authorization + dataset walks)
foreach (array($OWNER => 'parity', $STRANGER => 'paritystranger') as $u => $fn) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, $2, 'Fixture', $3, 'x', 'x', false, false)", array($u, ucfirst($fn), "$fn-$u@test.strabospot.org"));
	$neodb->query("CREATE (u:User {userpkey: $u, email: '$fn-$u@test.strabospot.org'})");
}
$projects = array(
	$P1 => array($OWNER,    'Parity Own Private',   'FALSE', array($DS_A, $DS_B)),
	$P2 => array($STRANGER, 'Parity Public Stranger', 'TRUE',  array($DS_C)),
	$P3 => array($OWNER,    'Parity Own Control',   'FALSE', array($DS_D)),
);
foreach ($projects as $pid => $pp) {
	$neodb->query("MATCH (u:User {userpkey: {$pp[0]}}) CREATE (p:Project {id: $pid, userpkey: {$pp[0]}, desc_project_name: '{$pp[1]}'}) CREATE (u)-[:HAS_PROJECT]->(p)");
	$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, $2, $3, {$pp[2]})", array($pp[0], $pp[1], (string)$pid));
	foreach ($pp[3] as $did) $neodb->query("MATCH (p:Project {id: $pid, userpkey: {$pp[0]}}) CREATE (d:Dataset {id: $did, userpkey: {$pp[0]}, name: 'Parity DS $did'}) CREATE (p)-[:HAS_DATASET]->(d)");
}
$db->prepare_query("INSERT INTO strabosearch.vocab_rock_type (path, parent_path, depth) VALUES ($1, NULL, 1) ON CONFLICT (path) DO NOTHING", array('zzp_rock'));
$db->prepare_query("INSERT INTO strabosearch.vocab_rock_type (path, parent_path, depth) VALUES ($1, $2, 2) ON CONFLICT (path) DO NOTHING", array('zzp_rock:mid', 'zzp_rock'));
$db->prepare_query("INSERT INTO strabosearch.vocab_rock_type (path, parent_path, depth) VALUES ($1, $2, 3) ON CONFLICT (path) DO NOTHING", array('zzp_rock:mid:leaf', 'zzp_rock:mid'));

// Province (F10): the first dev shapegeology polygon with a geometry; a spot sits on its point-on-surface.
$prov = $db->get_row("SELECT gid, ST_X(ST_PointOnSurface(ST_MakeValid(the_geom))) AS x, ST_Y(ST_PointOnSurface(ST_MakeValid(the_geom))) AS y FROM shapegeology WHERE the_geom IS NOT NULL ORDER BY gid LIMIT 1");
$PROV_GID = $prov ? (int)$prov->gid : null;

// ------------------------------------------------------------------ the 8 spots (hand-computed expectations below reference these)
$base = function ($sid, $pid, $did) use ($projects, $S) {
	$pp = $projects[$pid];
	return array('item_id' => (string)$sid, 'item_userpkey' => $pp[0], 'project_id' => (string)$pid, 'project_userpkey' => $pp[0],
		'project_name' => $pp[1], 'project_ispublic' => $pp[2], 'dataset_ids' => array((string)$did));
};
seedItem($db, $base($S[1], $P1, $DS_A) + array('searchtext' => 'parityword granite outcrop', 'date_value' => '2024-03-10', 'lng' => -118.25, 'lat' => 34.05,
	'orientation_strike' => array(120), 'orientation_dip' => array(30), 'orientation_features' => array('bedding'), 'orientation_planar' => array(true),
	'rock_types' => array('zzp_rock:mid:leaf'), 'met_facies' => array('greenschist'), 'trace_types' => array('fault'),
	'tag_names' => array('ParityTag'), 'tag_types' => array('geologic_unit'), 'tag_text' => 'ParityTag',
	'has_orientation' => 'TRUE', 'has_images' => 'TRUE', 'has_samples' => 'TRUE',
	'sample_id' => 'PS-001', 'sample_name' => 'Parity One', 'igsn' => 'PARITYIGSN1', 'display_sample_type' => 'intact_rock', 'display_sample_purpose' => 'petrology'));
seedItem($db, $base($S[2], $P1, $DS_A) + array('searchtext' => 'parityword sandstone', 'date_value' => '2023-06-01', 'lng' => -118.24, 'lat' => 34.06,
	'orientation_trend' => array(45), 'orientation_plunge' => array(10), 'orientation_features' => array('fold_hinge'), 'orientation_planar' => array(false),
	'rock_types' => array('zzp_rock'), 'tag_names' => array('OtherTag'), 'tag_types' => array('concept'), 'tag_text' => 'OtherTag', 'has_orientation' => 'TRUE'));
seedItem($db, $base($S[3], $P1, $DS_B) + array('searchtext' => 'basalt flow', 'date_value' => '2024-11-20', 'lng' => -118.26, 'lat' => 34.04,
	'orientation_strike' => array(350), 'orientation_dip' => array(60), 'orientation_planar' => array(true), 'met_facies' => array('amphibolite'),
	'has_orientation' => 'TRUE', 'has_strat' => 'TRUE'));
seedItem($db, $base($S[4], $P1, $DS_B) + array('searchtext' => 'parityword shale', 'lng' => -118.10, 'lat' => 34.05, 'has_microstructure' => 'TRUE', 'has_samples' => 'TRUE',
	'sample_id' => 'PS-002', 'sample_name' => 'Other Sample', 'igsn' => 'ZZZ', 'display_sample_type' => 'sediment', 'display_sample_purpose' => 'geochem'));
seedItem($db, $base($S[5], $P1, $DS_B));     // the all-NULL facets spot: matches only negations
seedItem($db, $base($S[6], $P1, $DS_A) + array('searchtext' => 'arctic slope', 'date_value' => '2024-01-15', 'trace_types' => array('joint'),
	'lng' => $prov ? (float)$prov->x : -148.79, 'lat' => $prov ? (float)$prov->y : 72.76));
seedItem($db, $base($S[7], $P2, $DS_C) + array('searchtext' => 'parityword public', 'date_value' => '2024-05-05', 'lng' => -118.23, 'lat' => 34.07,
	'orientation_strike' => array(100), 'orientation_dip' => array(20), 'orientation_planar' => array(true), 'rock_types' => array('zzp_rock:mid'),
	'tag_names' => array('ParityTag'), 'tag_text' => 'ParityTag', 'has_orientation' => 'TRUE'));
seedItem($db, $base($S[8], $P3, $DS_D) + array('searchtext' => 'control quartzite', 'date_value' => '2020-01-01', 'lng' => -95.0, 'lat' => 39.0,
	'orientation_dip' => array(80), 'orientation_planar' => array(true), 'has_orientation' => 'TRUE'));
check('8 fixture rows indexed', (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_userpkey IN ($1, $2)", array($OWNER, $STRANGER)) === 8);

$ALL = array(1, 2, 3, 4, 5, 6, 7, 8);
$projOf = function ($n) use ($P1, $P2, $P3) { return $n === 7 ? $P2 : ($n === 8 ? $P3 : $P1); };
$ownerOf = function ($pid) use ($projects) { return $projects[$pid][0]; };
$idsOf = function (array $ns) use ($S) { $o = array(); foreach ($ns as $n) $o[] = $S[$n]; sort($o); return $o; };
$not = function (array $ns) use ($ALL) { return array_values(array_diff($ALL, $ns)); };

// ------------------------------------------------------------------ the matrix: [label, criteria rows, expected spot numbers, find-expected (null = fast path; false = same as expected)]
$M = array();
$row = function ($id, $value, $notFlag = false, $op = null) { $r = array('id' => $id, 'value' => $value); if ($notFlag) $r['not'] = true; if ($op) $r['op'] = $op; return $r; };
$M[] = array('U1 keyword',                    array($row('U1', 'parityword')),                                   array(1, 2, 4, 7));
$M[] = array('U1 keyword NOT',                array($row('U1', 'parityword', true)),                             $not(array(1, 2, 4, 7)));
$M[] = array('U2 bbox (LA)',                  array($row('U2', $LA)),                                            array(1, 2, 3, 7), null);
$M[] = array('U2 polygon (LA)',               array($row('U2', $LA_POLY)),                                       array(1, 2, 3, 7), null);
$M[] = array('U3 date min/max 2024',          array($row('U3', array('min' => '2024-01-01', 'max' => '2024-12-31'))), array(1, 3, 6, 7));
$M[] = array('U3 year 2023',                  array($row('U3', array('year' => '2023'))),                        array(2));
$M[] = array('U3 date NOT 2024',              array($row('U3', array('min' => '2024-01-01', 'max' => '2024-12-31'), true)), $not(array(1, 3, 6, 7)));
$M[] = array('U5 sample id prefix',           array($row('U5', array('text' => 'PS-'))),                         array(1, 4));
$M[] = array('U5 sample name exact',          array($row('U5', array('text' => 'parity one', 'exact' => true))),  array(1));
$M[] = array('U6 IGSN prefix',                array($row('U6', array('text' => 'PARITYIGSN'))),                  array(1));
$M[] = array('U6 IGSN exact (case-insensitive)', array($row('U6', array('text' => 'zzz', 'exact' => true))),     array(4));
$M[] = array('U7 sample type',                array($row('U7', array('sample_type' => array('intact_rock')))),   array(1));
$M[] = array('U7 sample purpose',             array($row('U7', array('sample_purpose' => array('geochem')))),    array(4));
$M[] = array('U7 type AND purpose (none)',    array($row('U7', array('sample_type' => array('intact_rock'), 'sample_purpose' => array('geochem')))), array());
$M[] = array('U9 has orientation',            array($row('U9', array('orientation'))),                           array(1, 2, 3, 7, 8));
$M[] = array('U9 orientation AND images',     array($row('U9', array('orientation', 'images'))),                 array(1));
$M[] = array('U9 strat',                      array($row('U9', array('strat'))),                                 array(3));
$M[] = array('U9 microstructure',             array($row('U9', array('microstructure'))),                        array(4));
$M[] = array('U9 NOT orientation',            array($row('U9', array('orientation'), true)),                     array(4, 5, 6));
$M[] = array('U10 tag name',                  array($row('U10', 'ParityTag')),                                   array(1, 7));
$M[] = array('U10 tag name prefix',           array($row('U10', 'Parity')),                                      array(1, 7));
$M[] = array('U10 other tag',                 array($row('U10', 'OtherTag')),                                    array(2));
$M[] = array('U10 tag NOT',                   array($row('U10', 'ParityTag', true)),                             $not(array(1, 7)));
$M[] = array('F1 strike 100..130',            array($row('F1', array('min' => 100, 'max' => 130))),              array(1, 7));
$M[] = array('F1 strike wraparound 340..10',  array($row('F1', array('min' => 340, 'max' => 10))),               array(3));
$M[] = array('F2 dip 25..65',                 array($row('F2', array('min' => 25, 'max' => 65))),                array(1, 3));
$M[] = array('F2 dip max 25',                 array($row('F2', array('max' => 25))),                             array(7));
$M[] = array('F2 dip NOT 25..65',             array($row('F2', array('min' => 25, 'max' => 65), true)),          $not(array(1, 3)));
$M[] = array('F3 trend 40..50',               array($row('F3', array('min' => 40, 'max' => 50))),                array(2));
$M[] = array('F4 plunge max 15',              array($row('F4', array('max' => 15))),                             array(2));
$M[] = array('F5 orientation feature',        array($row('F5', array('bedding'))),                               array(1));
$M[] = array('F5 two features (any)',         array($row('F5', array('bedding', 'fold_hinge'))),                 array(1, 2));
$M[] = array('F6 linear',                     array($row('F6', array('linear'))),                                array(2));
$M[] = array('F6 planar',                     array($row('F6', array('planar'))),                                array(1, 3, 7, 8));
$M[] = array('F6 planar or linear',           array($row('F6', array('planar', 'linear'))),                      array(1, 2, 3, 7, 8));
$M[] = array('F7 rock type parent (expands to descendants)', array($row('F7', array('zzp_rock'))),               array(1, 2, 7));
$M[] = array('F7 rock type mid',              array($row('F7', array('zzp_rock:mid'))),                          array(1, 7));
$M[] = array('F7 rock type leaf',             array($row('F7', array('zzp_rock:mid:leaf'))),                     array(1));
$M[] = array('F7 rock type NOT parent',       array($row('F7', array('zzp_rock'), true)),                        $not(array(1, 2, 7)));
$M[] = array('F8 met facies',                 array($row('F8', array('greenschist'))),                           array(1));
$M[] = array('F8 two facies (any)',           array($row('F8', array('amphibolite', 'greenschist'))),            array(1, 3));
$M[] = array('F9 trace type fault',           array($row('F9', array('fault'))),                                 array(1));
$M[] = array('F9 trace type joint',           array($row('F9', array('joint'))),                                 array(6));
if ($PROV_GID !== null) $M[] = array("F10 province gid $PROV_GID", array($row('F10', $PROV_GID)),               array(6));
$M[] = array('F11 tag type geologic_unit',    array($row('F11', array('geologic_unit'))),                        array(1));
$M[] = array('F11 tag type concept',          array($row('F11', array('concept'))),                              array(2));
// combos
$M[] = array('U1 + F2',                       array($row('U1', 'parityword'), $row('F2', array('min' => 25, 'max' => 65))), array(1));
$M[] = array('U2 + F6 planar',                array($row('U2', $LA), $row('F6', array('planar'))),               array(1, 3, 7), array(1, 3, 7, 8));
$M[] = array('U2 + U1 + U9',                  array($row('U2', $LA_POLY), $row('U1', 'parityword'), $row('U9', array('orientation'))), array(1, 2, 7), array(1, 2, 7));
$M[] = array('U3 2024 + NOT U10',             array($row('U3', array('year' => '2024')), $row('U10', 'ParityTag', true)), array(3, 6));
$M[] = array('F7 parent + F8 NOT greenschist', array($row('F7', array('zzp_rock')), $row('F8', array('greenschist'), true)), array(2, 7));
$M[] = array('U5 prefix + U2 (LA)',           array($row('U5', array('text' => 'PS-')), $row('U2', $LA)),          array(1), array(1, 4));

// ------------------------------------------------------------------ run the matrix
$qb = new SearchQueryBuilder($db, $OWNER);
$finder = new ExportFinder($db, $neodb, $OWNER);
$scopeAll = array('projects' => array(
	array('id' => (string)$P1, 'owner' => $OWNER), array('id' => (string)$P2, 'owner' => $STRANGER), array('id' => (string)$P3, 'owner' => $OWNER)));
$candidates = array(
	array('project_id' => (string)$P1, 'owner' => $OWNER), array('project_id' => (string)$P2, 'owner' => $STRANGER), array('project_id' => (string)$P3, 'owner' => $OWNER));

foreach ($M as $case) {
	list($label, $rows, $exp) = $case;
	$findExp = array_key_exists(3, $case) ? $case[3] : false;
	$expIds = $idsOf($exp);
	$expByProj = array();
	foreach ($exp as $n) { $k = $projOf($n) . '|' . $ownerOf($projOf($n)); $expByProj[$k] = isset($expByProj[$k]) ? $expByProj[$k] + 1 : 1; }
	ksort($expByProj);

	try {
		$dsl = $qb->validate(array('subsystems' => array('field'), 'pathway' => 'projects', 'criteria' => $rows, 'page_size' => 100, 'page' => 0));
	} catch (SearchDslError $e) { check("$label: DSL validates", false, $e->getMessage()); continue; }

	// a. search results list. Dev holds real data and broad criteria (negations,
	// "has orientation") match hundreds of projects, more than one page: add
	// the owner row (U4, search-only; the door drops it) so the list is the
	// fixture owners' projects only. Same predicate, same ACL, one page.
	$searchDsl = $qb->validate(array('subsystems' => array('field'), 'pathway' => 'projects', 'page_size' => 100, 'page' => 0,
		'criteria' => array_merge($rows, array(array('id' => 'U4', 'value' => array($OWNER, $STRANGER))))));
	$r = $qb->runProjectsQuery($searchDsl);
	$searchByProj = array();
	foreach ($r['results'] as $pr) {
		if (!in_array((int)$pr['project_userpkey'], array($OWNER, $STRANGER), true)) continue;
		$searchByProj[$pr['project_id'] . '|' . (int)$pr['project_userpkey']] = (int)$pr['match_counts']['spot'];
	}
	ksort($searchByProj);
	check("$label: search list per-project spot counts", $searchByProj === $expByProj, 'search=' . json_encode($searchByProj) . ' expected=' . json_encode($expByProj));

	// b. door
	$door = $qb->runItemProjectCountsQuery($dsl, $candidates); ksort($door);
	check("$label: door project counts", $door === $expByProj, 'door=' . json_encode($door));

	// c. live count (raw rows)
	$c = $finder->count(array('scope' => $scopeAll, 'criteria' => $rows));
	check("$label: live count", $c['count'] === count($expIds), 'count=' . json_encode($c));

	// e. stored-recipe form: the validator's OUTPUT rows (U2 as {geojson}) must count the same
	$c2 = $finder->count(array('scope' => $scopeAll, 'criteria' => $dsl['criteria']));
	check("$label: live count from stored (validated) rows", $c2['count'] === count($expIds), 'count=' . json_encode($c2));

	// d. find
	$f = $finder->find(array('scope' => $scopeAll, 'criteria' => $rows));
	if ($findExp === null) {
		$nulls = 0; foreach ($f['projects'] as $fp) if ($fp['spot_ids'] === null) $nulls++;
		check("$label: find = graph fast path (spatial only, GEOS at gather)", $f['used_index'] === false && $nulls === count($f['projects']) && $f['polygon'] !== null);
	} else {
		$want = $findExp === false ? $expIds : $idsOf($findExp);
		$got = array(); foreach ($f['projects'] as $fp) foreach ((array)$fp['spot_ids'] as $id) $got[] = (int)$id; sort($got);
		check("$label: find spot ids", $got === $want && $f['used_index'] === true, 'got=' . json_encode($got) . ' want=' . json_encode($want));
	}
}

// ------------------------------------------------------------------ dataset-scoped export (no search analogue)
$scopeDsA = array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)), 'datasets' => array(array('id' => (string)$DS_A, 'project_id' => (string)$P1, 'owner' => $OWNER)));
$c = $finder->count(array('scope' => $scopeDsA, 'criteria' => array($row('U1', 'parityword'))));
$f = $finder->find(array('scope' => $scopeDsA, 'criteria' => array($row('U1', 'parityword'))));
check('dataset scope: U1 counts + finds only the dataset\'s spots (1, 2)', $c['count'] === 2 && $f['projects'][0]['spot_ids'] == array($S[1], $S[2]), json_encode($c) . ' ' . json_encode($f['projects'][0]['spot_ids']));
$c = $finder->count(array('scope' => $scopeDsA, 'criteria' => array($row('U2', $LA))));
check('dataset scope: U2 centroid count within the dataset (1, 2)', $c['count'] === 2 && $c['approximate'] === true, json_encode($c));

// ------------------------------------------------------------------ export-only rules
$threw = false; try { $finder->count(array('scope' => $scopeAll, 'criteria' => array($row('U2', $LA, true)))); } catch (ExportJobError $e) { $threw = strpos($e->getMessage(), 'negated area') !== false; }
check('NOT U2 is refused by the export (search allows it; documented difference)', $threw);
$threw = false; try { $finder->count(array('scope' => $scopeAll, 'criteria' => array($row('U2', $LA), $row('U2', $LA_POLY)))); } catch (ExportJobError $e) { $threw = strpos($e->getMessage(), 'Only one area') !== false; }
check('two U2 rows are refused by the export', $threw);

cleanup();
check('zero residue: index', (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_userpkey IN ($1, $2)", array($OWNER, $STRANGER)) === 0);
check('zero residue: graph', (int)$neodb->query("MATCH (u:User) WHERE u.userpkey IN [$OWNER, $STRANGER] RETURN count(u) AS c")[0]->value('c') === 0);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
