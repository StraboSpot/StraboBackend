<?php
/**
 * File: smoke_test_search_api.php
 * Description: Permanent smoke suite for the Phase 3 /searchdb/ API core
 *              (searchdb/services/SearchQueryBuilder.php +
 *              StraboSearchService.php) at the SERVICE level — the DSL →
 *              SQL translation, §5.5 ACL, §5.4.1 response assembly, and
 *              §5.4.2/5.4.3 pagination/sort/facet behavior. The HTTP
 *              surface (auth gates, routing, CLI denial) has its own
 *              suite: smoke_test_search_api_http.php.
 *
 *              Hermetic: fixtures are rows written STRAIGHT into
 *              strabosearch.item_hit / image_hit (the API reads only the
 *              index — no Neo4j / source-store seeding needed) under
 *              isolated userpkeys 94550–94553 with the 'spsapi' prefix,
 *              disjoint from every other suite. Cleanup sweeps
 *              everything, including the fictional vocab_rock_type chain
 *              used to prove F7 prefix expansion.
 *
 *              Coverage:
 *                ACL      owner / accepted collaborator / stranger /
 *                         anonymous × private + public projects, both
 *                         pathways
 *                CRITERIA every §4.2 family: U1 (incl. phrase), U2 bbox,
 *                         U3, U4, U5 prefix+exact, U6, U7, U9, U10,
 *                         F1 wraparound, F2, F5, F6, F7 (expansion via
 *                         vocab), F8, F9, F11, M1, M3, E1, I1, I2, I3,
 *                         U8 subsystems incl. "samples" semantics
 *                COMPOSE  AND-across-rows is same-item (dip∧rock on one
 *                         spot), NOT complement semantics
 *                SHAPE    match_counts (incl. dataset_ids rollup),
 *                         subsystem_summary, counterpart_total, both-
 *                         pathway envelope, facet self-exclusion recount
 *                PAGING   page/page_size windows, total stability, sort
 *                         name_asc + smart default
 *                ROBUST   injection-shaped values in every family, DSL
 *                         validation rejects, page_size clamp
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_search_api.php
 *
 * @package    StraboSearch API
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/searchdb/services/StraboSearchService.php';

$OWNER    = 94550;   // owns P1 (private field) + P2 (public field)
$COLLAB   = 94551;   // accepted collaborator on P1
$STRANGER = 94552;
$OWNER2   = 94553;   // owns P3 (public micro) + P4 (private exp)
$PFX      = 'spsapi';

$P1 = $PFX . '_proj_priv';
$P2 = $PFX . '_proj_pub';
$P3 = $PFX . '_proj_micro';
$P4 = $PFX . '_proj_exp';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

/** Project ids from a projects-pathway response. */
function pids($resp) {
	$out = array();
	foreach ($resp['results'] as $r) $out[] = $r['project_id'];
	sort($out);
	return $out;
}
function svc($db, $upk) { return new StraboSearchService($db, $upk); }
function sameSet($a, $b) { sort($a); sort($b); return $a === $b; }

// ---------------------------------------------------------------------------
section('0. Cleanup any prior residue + seed fixtures');

function sweep($db, $PFX, $OWNER, $COLLAB, $STRANGER, $OWNER2) {
	$db->prepare_query("DELETE FROM strabosearch.item_hit  WHERE project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM strabosearch.vocab_rock_type WHERE path LIKE $1", array($PFX . 'rock%'));
	$db->prepare_query("DELETE FROM strabosearch.saved_search WHERE user_pkey IN ($1,$2,$3,$4)",
		array($OWNER, $COLLAB, $STRANGER, $OWNER2));
	$db->prepare_query("DELETE FROM collaborators WHERE strabo_project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM users WHERE pkey IN ($1,$2,$3,$4)",
		array($OWNER, $COLLAB, $STRANGER, $OWNER2));
}
sweep($db, $PFX, $OWNER, $COLLAB, $STRANGER, $OWNER2);

// Fixture users (names feed owner_name + owner_asc sort).
foreach (array(
	array($OWNER,    'Alice', 'Apiowner'),
	array($COLLAB,   'Bob',   'Apicollab'),
	array($STRANGER, 'Carol', 'Apistranger'),
	array($OWNER2,   'Dave',  'Apimicro'),
) as $u) {
	$db->prepare_query(
		"INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted)
		 VALUES ($1, $2, $3, $4, 'x', 'x', TRUE, FALSE)",
		array($u[0], $u[1], $u[2], strtolower($PFX . $u[0] . '@example.com')));
}

// Accepted collaborator on P1.
$db->prepare_query(
	"INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
	                            collaboration_level, accepted, disabled, uuid)
	 VALUES ($1, $2, $3, 'readonly', TRUE, FALSE, $4)",
	array($P1, $OWNER, $COLLAB, $PFX . '-collab-uuid'));

// Fictional rock-type chain proves F7 parent→descendants expansion.
foreach (array(
	array($PFX . 'rock', null, 1),
	array($PFX . 'rock:mid', $PFX . 'rock', 2),
	array($PFX . 'rock:mid:leaf', $PFX . 'rock:mid', 3),
) as $v) {
	$db->prepare_query(
		"INSERT INTO strabosearch.vocab_rock_type (path, parent_path, depth) VALUES ($1, $2, $3)
		 ON CONFLICT (path) DO NOTHING", array($v[0], $v[1], $v[2]));
}

/** item_hit fixture insert with defaults. */
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
		'minerals' => null, 'mineral_methods' => null,
		'instrument_type' => null, 'detector_type' => null,
		'apparatus_type' => null, 'daq_sensor_type' => null, 'measurement_type' => null,
		'tag_names' => null, 'tag_types' => null, 'tag_text' => null,
		'dataset_ids' => null, 'source_modified' => '2024-01-01 00:00:00+00',
	), $over);

	$lit = function ($v) { return $v === null ? 'NULL' : "'" . pg_escape_string((string)$v) . "'"; };
	$arr = function ($v) use ($lit) {
		if ($v === null) return 'NULL';
		$parts = array();
		foreach ($v as $x) $parts[] = "'" . pg_escape_string((string)$x) . "'";
		return 'ARRAY[' . implode(',', $parts) . ']::text[]';
	};
	$narr = function ($v) {
		if ($v === null) return 'NULL';
		return 'ARRAY[' . implode(',', array_map('floatval', $v)) . ']::numeric[]';
	};
	$barr = function ($v) {
		if ($v === null) return 'NULL';
		$parts = array();
		foreach ($v as $x) $parts[] = $x ? 'TRUE' : 'FALSE';
		return 'ARRAY[' . implode(',', $parts) . ']::boolean[]';
	};
	$loc = ($d['lng'] === null) ? 'NULL'
		: 'ST_SetSRID(ST_MakePoint(' . (float)$d['lng'] . ',' . (float)$d['lat'] . '), 4326)';

	$sql = "INSERT INTO strabosearch.item_hit
		(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
		 project_name, project_ispublic, location, date_value, searchtext_tsv,
		 sample_id, sample_name, igsn, display_sample_type, display_sample_purpose,
		 has_orientation, has_samples, has_images, has_microstructure, has_strat,
		 orientation_strike, orientation_dip, orientation_trend, orientation_plunge,
		 orientation_features, orientation_planar, rock_types, met_facies, trace_types,
		 minerals, mineral_methods, instrument_type, detector_type,
		 apparatus_type, daq_sensor_type, measurement_type,
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
		 {$arr($d['minerals'])}, {$arr($d['mineral_methods'])},
		 {$lit($d['instrument_type'])}, {$lit($d['detector_type'])},
		 {$lit($d['apparatus_type'])}, {$lit($d['daq_sensor_type'])}, {$lit($d['measurement_type'])},
		 {$arr($d['tag_names'])}, {$arr($d['tag_types'])},
		 to_tsvector('english', {$lit((string)$d['tag_text'])}),
		 {$arr($d['dataset_ids'])}, {$lit($d['source_modified'])})";
	$ok = $db->query($sql);
	if ($ok === false) { echo "  SEED FAILED: " . $db->last_error . PHP_EOL; exit(1); }
}

/** image_hit fixture insert with defaults. */
function seedImage($db, $over) {
	$d = array_merge(array(
		'image_id' => '', 'image_subsystem' => 'field', 'image_userpkey' => 0,
		'image_type' => null, 'annotated' => 'NULL', 'title' => null, 'caption' => null,
		'imagetext' => '', 'filename' => 'f.jpg',
		'parent_spot_id' => null, 'parent_sample_id' => null,
		'project_id' => '', 'project_userpkey' => 0, 'project_subsystem' => 'field',
		'project_ispublic' => 'FALSE',
		'lng' => null, 'lat' => null, 'date_value' => null,
		'orientation_dip' => null, 'rock_types' => null,
		'minerals' => null, 'instrument_type' => null,
		'tag_names' => null, 'tag_types' => null, 'tag_text' => null,
		'source_modified' => '2024-01-01 00:00:00+00',
	), $over);
	$lit = function ($v) { return $v === null ? 'NULL' : "'" . pg_escape_string((string)$v) . "'"; };
	$arr = function ($v) use ($lit) {
		if ($v === null) return 'NULL';
		$parts = array();
		foreach ($v as $x) $parts[] = "'" . pg_escape_string((string)$x) . "'";
		return 'ARRAY[' . implode(',', $parts) . ']::text[]';
	};
	$narr = function ($v) {
		if ($v === null) return 'NULL';
		return 'ARRAY[' . implode(',', array_map('floatval', $v)) . ']::numeric[]';
	};
	$loc = ($d['lng'] === null) ? 'NULL'
		: 'ST_SetSRID(ST_MakePoint(' . (float)$d['lng'] . ',' . (float)$d['lat'] . '), 4326)';
	$sql = "INSERT INTO strabosearch.image_hit
		(image_id, image_subsystem, image_userpkey, image_type, annotated, title, caption,
		 imagetext_tsv, filename, parent_spot_id, parent_sample_id,
		 project_id, project_userpkey, project_subsystem, project_ispublic,
		 location, date_value, orientation_dip, rock_types, minerals, instrument_type,
		 tag_names, tag_types, tag_text_tsv, source_modified)
		VALUES (
		 {$lit($d['image_id'])}, {$lit($d['image_subsystem'])}, {$d['image_userpkey']},
		 {$lit($d['image_type'])}, {$d['annotated']}, {$lit($d['title'])}, {$lit($d['caption'])},
		 to_tsvector('english', {$lit($d['imagetext'])}), {$lit($d['filename'])},
		 {$lit($d['parent_spot_id'])}, {$lit($d['parent_sample_id'])},
		 {$lit($d['project_id'])}, {$d['project_userpkey']}, {$lit($d['project_subsystem'])},
		 {$d['project_ispublic']}, $loc, {$lit($d['date_value'])},
		 {$narr($d['orientation_dip'])}, {$arr($d['rock_types'])}, {$arr($d['minerals'])},
		 {$lit($d['instrument_type'])},
		 {$arr($d['tag_names'])}, {$arr($d['tag_types'])},
		 to_tsvector('english', {$lit((string)$d['tag_text'])}), {$lit($d['source_modified'])})";
	$ok = $db->query($sql);
	if ($ok === false) { echo "  SEED IMAGE FAILED: " . $db->last_error . PHP_EOL; exit(1); }
}

// ---- P1: private field project of OWNER ---------------------------------
seedItem($db, array(
	'item_id' => $PFX . '_S1', 'item_userpkey' => $OWNER,
	'project_id' => $P1, 'project_userpkey' => $OWNER, 'project_name' => 'spsapi Private Alpha',
	'lng' => -118.5, 'lat' => 34.2, 'date_value' => '2024-06-15',
	'searchtext' => 'UNIQAPI_alpha granitefix spot one spsapiTagOne',
	'has_orientation' => 'TRUE', 'has_images' => 'TRUE',
	'orientation_strike' => array(355), 'orientation_dip' => array(45),
	'orientation_features' => array('bedding'), 'orientation_planar' => array(true),
	'rock_types' => array($PFX . 'rock:mid:leaf'),
	'met_facies' => array('greenschist'), 'trace_types' => array('fault'),
	'tag_names' => array('spsapiTagOne'), 'tag_types' => array('geologic_unit'),
	'tag_text' => 'spsapiTagOne', 'dataset_ids' => array($PFX . '_D1', $PFX . '_D2'),
	'source_modified' => '2024-06-15 10:00:00+00',
));
seedItem($db, array(
	'item_id' => $PFX . '_S2', 'item_userpkey' => $OWNER,
	'project_id' => $P1, 'project_userpkey' => $OWNER, 'project_name' => 'spsapi Private Alpha',
	'lng' => -100.0, 'lat' => 40.0, 'date_value' => '2023-05-01',
	'searchtext' => 'UNIQAPI_alpha spot two',
	'has_samples' => 'TRUE',
	'orientation_dip' => array(80),
	'rock_types' => array('sedimentary', 'sedimentary:limestone'),
	'dataset_ids' => array($PFX . '_D1'),
	'source_modified' => '2023-05-01 10:00:00+00',
));
seedItem($db, array(
	'item_type' => 'sample', 'item_id' => $PFX . '_SAMP1', 'item_userpkey' => $OWNER,
	'project_id' => $P1, 'project_userpkey' => $OWNER, 'project_name' => 'spsapi Private Alpha',
	'searchtext' => 'UNIQAPI_alpha sample one',
	'sample_id' => 'SPSAPI-001', 'sample_name' => 'spsapi Sample One',
	'igsn' => 'SPSIGSN001', 'display_sample_type' => 'intact_rock',
	'display_sample_purpose' => 'petrology',
	'source_modified' => '2024-02-01 10:00:00+00',
));
seedImage($db, array(
	'image_id' => $PFX . '_IMG1', 'image_userpkey' => $OWNER,
	'image_type' => 'photo', 'annotated' => 'TRUE',
	'title' => 'UNIQAPI_img alpha photo', 'imagetext' => 'UNIQAPI_img alpha photo',
	'parent_spot_id' => $PFX . '_S1', 'parent_sample_id' => 'SPSAPI-001',
	'project_id' => $P1, 'project_userpkey' => $OWNER,
	'lng' => -118.5, 'lat' => 34.2, 'date_value' => '2024-06-15',
	'orientation_dip' => array(45), 'rock_types' => array($PFX . 'rock:mid:leaf'),
	'tag_names' => array('spsapiTagOne'), 'tag_types' => array('geologic_unit'),
	'tag_text' => 'spsapiTagOne',
));

// ---- P2: public field project of OWNER -----------------------------------
seedItem($db, array(
	'item_id' => $PFX . '_S3', 'item_userpkey' => $OWNER,
	'project_id' => $P2, 'project_userpkey' => $OWNER, 'project_name' => 'spsapi Public Beta',
	'project_ispublic' => 'TRUE',
	'lng' => -117.0, 'lat' => 35.5, 'date_value' => '2024-01-10',
	'searchtext' => 'UNIQAPI_beta granitefix spot three',
	'has_strat' => 'TRUE',
	'orientation_dip' => array(30), 'orientation_trend' => array(120),
	'rock_types' => array('metamorphic', 'metamorphic:schist'),
	'dataset_ids' => array($PFX . '_D3'),
	'source_modified' => '2024-01-10 10:00:00+00',
));
seedImage($db, array(
	'image_id' => $PFX . '_IMG2', 'image_userpkey' => $OWNER,
	'image_type' => 'sketch', 'annotated' => 'FALSE',
	'title' => 'UNIQAPI_img beta sketch', 'imagetext' => 'UNIQAPI_img beta sketch',
	'parent_spot_id' => $PFX . '_S3',
	'project_id' => $P2, 'project_userpkey' => $OWNER, 'project_ispublic' => 'TRUE',
	'lng' => -117.0, 'lat' => 35.5, 'date_value' => '2024-01-10',
	'orientation_dip' => array(30),
));

// ---- P3: public micro project of OWNER2 ----------------------------------
seedItem($db, array(
	'item_type' => 'micrograph', 'item_id' => $PFX . '_MG1', 'item_userpkey' => $OWNER2,
	'project_id' => $P3, 'project_userpkey' => $OWNER2, 'project_subsystem' => 'micro',
	'project_name' => 'spsapi Micro Gamma', 'project_ispublic' => 'TRUE',
	'searchtext' => 'UNIQAPI_gamma micrograph',
	'minerals' => array('quartz', 'feldspar'), 'mineral_methods' => array('EDS'),
	'instrument_type' => 'SEM', 'detector_type' => 'BSE',
	'source_modified' => '2024-03-01 10:00:00+00',
));
seedItem($db, array(
	'item_type' => 'sample', 'item_id' => $PFX . '_SAMP2', 'item_userpkey' => $OWNER2,
	'project_id' => $P3, 'project_userpkey' => $OWNER2, 'project_subsystem' => 'micro',
	'project_name' => 'spsapi Micro Gamma', 'project_ispublic' => 'TRUE',
	'searchtext' => 'UNIQAPI_gamma sample two',
	'sample_id' => 'SPSAPI-002', 'sample_name' => 'spsapi Sample Two',
	'display_sample_type' => 'tephra',
	'source_modified' => '2024-03-02 10:00:00+00',
));
seedImage($db, array(
	'image_id' => $PFX . '_MG1', 'image_subsystem' => 'micro', 'image_userpkey' => $OWNER2,
	'image_type' => 'micrograph_sem',
	'title' => 'UNIQAPI_img gamma micrograph', 'imagetext' => 'UNIQAPI_img gamma micrograph',
	'parent_sample_id' => 'SPSAPI-002',
	'project_id' => $P3, 'project_userpkey' => $OWNER2, 'project_subsystem' => 'micro',
	'project_ispublic' => 'TRUE',
	'minerals' => array('quartz', 'feldspar'), 'instrument_type' => 'SEM',
));

// ---- P4: private exp project of OWNER2 -----------------------------------
seedItem($db, array(
	'item_type' => 'experiment', 'item_id' => $PFX . '_E1', 'item_userpkey' => $OWNER2,
	'project_id' => $P4, 'project_userpkey' => $OWNER2, 'project_subsystem' => 'exp',
	'project_name' => 'spsapi Exp Delta',
	'searchtext' => 'UNIQAPI_delta experiment',
	'apparatus_type' => 'spsapi Rig', 'measurement_type' => 'Axial Stress',
	'source_modified' => '2024-04-01 10:00:00+00',
));

echo "  fixtures seeded" . PHP_EOL;

$U1ALL = array('id' => 'U1', 'value' => 'UNIQAPI_alpha OR UNIQAPI_beta OR UNIQAPI_gamma OR UNIQAPI_delta');
// websearch OR syntax matches any fixture row; simpler scoping criterion:
$KEY = function ($tok) { return array('id' => 'U1', 'value' => $tok); };
$D = function ($crit, $over = array()) {
	return array_merge(array('pathway' => 'projects', 'criteria' => $crit, 'page_size' => 50), $over);
};

// ---------------------------------------------------------------------------
section('1. ACL matrix — projects pathway');

$anon = svc($db, 0);
$r = $anon->runSearch($D(array($KEY('UNIQAPI_alpha'))));
check('anon: private P1 invisible', $r['total'] === 0, 'got ' . $r['total']);
$r = $anon->runSearch($D(array($KEY('UNIQAPI_beta'))));
check('anon: public P2 visible', pids($r) === array($P2));
$r = $anon->runSearch($D(array($KEY('UNIQAPI_gamma'))));
check('anon: public micro P3 visible', pids($r) === array($P3));
$r = $anon->runSearch($D(array($KEY('UNIQAPI_delta'))));
check('anon: private exp P4 invisible', $r['total'] === 0);

$own = svc($db, $OWNER);
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'))));
check('owner: private P1 visible', pids($r) === array($P1));
check('owner: P1 owner_name joined', $r['results'][0]['owner_name'] === 'Alice Apiowner',
	$r['results'][0]['owner_name']);

$col = svc($db, $COLLAB);
$r = $col->runSearch($D(array($KEY('UNIQAPI_alpha'))));
check('collaborator: P1 visible via collaborators JOIN', pids($r) === array($P1));

$str = svc($db, $STRANGER);
$r = $str->runSearch($D(array($KEY('UNIQAPI_alpha'))));
check('stranger: P1 invisible', $r['total'] === 0);

// disabled collab loses access
$db->prepare_query("UPDATE collaborators SET disabled = TRUE WHERE strabo_project_id = $1", array($P1));
$r = $col->runSearch($D(array($KEY('UNIQAPI_alpha'))));
check('disabled collaborator: P1 invisible', $r['total'] === 0);
$db->prepare_query("UPDATE collaborators SET disabled = FALSE WHERE strabo_project_id = $1", array($P1));

// ---------------------------------------------------------------------------
section('2. ACL matrix — images pathway');

$r = $anon->runSearch($D(array($KEY('UNIQAPI_img')), array('pathway' => 'images')));
$ids = array(); foreach ($r['results'] as $x) $ids[] = $x['image_id']; sort($ids);
check('anon images: only public IMG2 + micro MG1', $ids === array($PFX . '_IMG2', $PFX . '_MG1'),
	json_encode($ids));
$r = $own->runSearch($D(array($KEY('UNIQAPI_img')), array('pathway' => 'images')));
check('owner images: sees all three', $r['total'] === 3, 'got ' . $r['total']);
check('image project_name resolved', $r['results'][0]['project_name'] !== null);

// ---------------------------------------------------------------------------
section('3. Criteria — universal core');

$r = $own->runSearch($D(array(array('id' => 'U1', 'value' => '"granitefix spot one"'))));
check('U1 phrase matches S1 only → P1', pids($r) === array($P1), json_encode(pids($r)));

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'),
	array('id' => 'U2', 'value' => array('bbox' => array(-119, 34, -118, 35))))));
check('U2 bbox: S1 in box → P1', pids($r) === array($P1));
check('U2 bbox: match_counts spot=1 (S2 outside box)', $r['results'][0]['match_counts']['spot'] === 1,
	json_encode($r['results'][0]['match_counts']));

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'U3', 'value' => array('year' => 2023)))));
check('U3 year 2023: only S2 → P1 spot=1', $r['results'] && $r['results'][0]['match_counts']['spot'] === 1);
$r = $own->runSearch($D(array(array('id' => 'U3', 'value' => array('min' => '2024-06-01', 'max' => '2024-06-30')), $KEY('UNIQAPI_alpha'))));
check('U3 min/max window: S1 only', $r['results'] && $r['results'][0]['match_counts']['spot'] === 1);

$r = $str->runSearch($D(array(array('id' => 'U4', 'value' => $OWNER2), $KEY('UNIQAPI_gamma'))));
check('U4 owner filter', pids($r) === array($P3));

$r = $own->runSearch($D(array(array('id' => 'U5', 'value' => 'SPSAPI-0'))));
check('U5 prefix: both samples → P1 + P3', sameSet(pids($r), array($P1, $P3)), json_encode(pids($r)));
$r = $own->runSearch($D(array(array('id' => 'U5', 'value' => array('text' => 'spsapi sample one', 'exact' => true)))));
check('U5 exact (case-insensitive name)', pids($r) === array($P1));
$r = $own->runSearch($D(array(array('id' => 'U6', 'value' => 'SPSIGSN'))));
check('U6 IGSN prefix', pids($r) === array($P1));

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'U7', 'value' => array('sample_type' => array('intact_rock'))))));
check('U7 sample_type', pids($r) === array($P1));
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'U7', 'value' => array('sample_purpose' => array('petrology'))))));
check('U7 sample_purpose', pids($r) === array($P1));

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha OR UNIQAPI_beta'), array('id' => 'U9', 'value' => array('orientation')))));
check('U9 orientation flag → P1 (S1)', pids($r) === array($P1));
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha OR UNIQAPI_beta'), array('id' => 'U9', 'value' => array('strat')))));
check('U9 strat flag → P2 (S3)', pids($r) === array($P2));

$r = $own->runSearch($D(array(array('id' => 'U10', 'value' => 'spsapiTagOne'))));
check('U10 tag name', pids($r) === array($P1));
$r = $own->runSearch($D(array(array('id' => 'U10', 'value' => 'spsapiTagO'))));
check('U10 tag prefix (typeahead)', pids($r) === array($P1));

// U8 subsystems
$r = $own->runSearch($D(array($KEY('UNIQAPI_gamma OR UNIQAPI_alpha')), array('subsystems' => array('micro'))));
check('U8 micro only', pids($r) === array($P3));
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha OR UNIQAPI_gamma')), array('subsystems' => array('samples'))));
check('U8 samples semantics: projects hosting sample rows', sameSet(pids($r), array($P1, $P3)),
	json_encode(pids($r)));

// ---------------------------------------------------------------------------
section('4. Criteria — Field extensions');

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'F1', 'value' => array('min' => 350, 'max' => 10)))));
check('F1 strike wraparound 350–10 catches 355', pids($r) === array($P1));
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'F1', 'value' => array('min' => 20, 'max' => 340)))));
check('F1 non-wrap window 20–340 misses 355', $r['total'] === 0);
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha OR UNIQAPI_beta'), array('id' => 'F2', 'value' => array('min' => 40, 'max' => 50)))));
check('F2 dip 40–50 → S1 only', pids($r) === array($P1));
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'F5', 'value' => array('bedding')))));
check('F5 feature type', pids($r) === array($P1));
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'F6', 'value' => array('planar')))));
check('F6 planar', pids($r) === array($P1));
$r = $own->runSearch($D(array(array('id' => 'F7', 'value' => array($PFX . 'rock')))));
check('F7 parent path expands to leaf (vocab expansion)', pids($r) === array($P1));
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'F8', 'value' => array('greenschist')))));
check('F8 met facies', pids($r) === array($P1));
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'), array('id' => 'F9', 'value' => array('fault')))));
check('F9 trace type', pids($r) === array($P1));
$r = $own->runSearch($D(array(array('id' => 'F11', 'value' => array('geologic_unit')), $KEY('UNIQAPI_alpha'))));
check('F11 tag type', pids($r) === array($P1));

// ---------------------------------------------------------------------------
section('5. Criteria — Micro / Exp extensions');

$r = $str->runSearch($D(array($KEY('UNIQAPI_gamma'), array('id' => 'M1', 'value' => array('quartz')))));
check('M1 mineral', pids($r) === array($P3));
$r = $str->runSearch($D(array(array('id' => 'M3', 'value' => array('SEM')), $KEY('UNIQAPI_gamma'))));
check('M3 instrument', pids($r) === array($P3));
$o2 = svc($db, $OWNER2);
$r = $o2->runSearch($D(array(array('id' => 'E1', 'value' => array('spsapi Rig')))));
check('E1 apparatus (owner)', pids($r) === array($P4));
$r = $str->runSearch($D(array(array('id' => 'E1', 'value' => array('spsapi Rig')))));
check('E1 apparatus (stranger, private) → 0', $r['total'] === 0);

// ---------------------------------------------------------------------------
section('6. Criteria — Image pathway + I-criteria on Projects pathway');

$r = $own->runSearch($D(array(array('id' => 'I1', 'value' => array('photo')), $KEY('UNIQAPI_alpha OR UNIQAPI_beta'))));
check('I1 on projects: only P1 has a photo', pids($r) === array($P1));
$r = $own->runSearch($D(array(array('id' => 'I2', 'value' => true), $KEY('UNIQAPI_alpha OR UNIQAPI_beta'))));
check('I2 annotated on projects → P1', pids($r) === array($P1));
$r = $own->runSearch($D(array(array('id' => 'I3', 'value' => 'UNIQAPI_img sketch')), array('pathway' => 'images')));
check('I3 imagetext keyword → IMG2', $r['total'] === 1 && $r['results'][0]['image_id'] === $PFX . '_IMG2');
$r = $own->runSearch($D(array(array('id' => 'F2', 'value' => array('min' => 40, 'max' => 50)), $KEY('UNIQAPI_img')), array('pathway' => 'images')));
check('images inherit F2 dip via stamped columns → IMG1', $r['total'] === 1
	&& $r['results'][0]['image_id'] === $PFX . '_IMG1', 'got ' . $r['total']);
$r = $own->runSearch($D(array(array('id' => 'U5', 'value' => 'SPSAPI-002')), array('pathway' => 'images')));
check('images U5 via parent sample spine → micro MG1', $r['total'] === 1
	&& $r['results'][0]['image_id'] === $PFX . '_MG1');
$r = $own->runSearch($D(array(array('id' => 'U9', 'value' => array('orientation')), $KEY('UNIQAPI_img')), array('pathway' => 'images')));
check('images U9 via parent item → IMG1', $r['total'] === 1 && $r['results'][0]['image_id'] === $PFX . '_IMG1');
$r = $o2->runSearch($D(array(array('id' => 'E1', 'value' => array('spsapi Rig'))), array('pathway' => 'images')));
check('images + E-criterion → 0 (not expressible on images)', $r['total'] === 0);

// ---------------------------------------------------------------------------
section('7. Composition — same-item AND, NOT complement');

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'),
	array('id' => 'F2', 'value' => array('min' => 40, 'max' => 50)),
	array('id' => 'F7', 'value' => array('sedimentary')))));
check('AND is same-item: dip 40–50 ∧ sedimentary → 0 (different spots of P1)',
	$r['total'] === 0, 'got ' . $r['total']);

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'),
	array('id' => 'F7', 'value' => array('sedimentary'), 'not' => true))));
check('NOT sedimentary keeps P1 (S1 + NULL-facet sample row match)', pids($r) === array($P1));
$m = $r['results'][0]['match_counts'];
check('NOT complement: S2 excluded, S1 + sample remain', $m['spot'] === 1 && $m['sample'] === 1,
	json_encode($m));

// ---------------------------------------------------------------------------
section('8. Response shape — match_counts, summary, counterpart, both');

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'))));
$m = $r['results'][0]['match_counts'];
check('match_counts: spot=2 sample=1', $m['spot'] === 2 && $m['sample'] === 1, json_encode($m));
check('match_counts: dataset=2 from dataset_ids union', $m['dataset'] === 2, json_encode($m));
check('match_counts: image=1 (IMG1)', $m['image'] === 1, json_encode($m));
check('counterpart_total = 1 image', $r['counterpart_total'] === 1);
check('summary field block: project=1 spot=2 sample=1 dataset=2 image=1',
	isset($r['subsystem_summary']['field'])
	&& $r['subsystem_summary']['field']['project'] === 1
	&& $r['subsystem_summary']['field']['spot'] === 2
	&& $r['subsystem_summary']['field']['sample'] === 1
	&& $r['subsystem_summary']['field']['dataset'] === 2
	&& $r['subsystem_summary']['field']['image'] === 1,
	json_encode($r['subsystem_summary']));
check('date_range min/max', $r['results'][0]['date_range'] === array('2023-05-01', '2024-06-15'),
	json_encode($r['results'][0]['date_range']));
check('centroid present', is_array($r['results'][0]['location_centroid']));

$r = $own->runSearch($D(array($KEY('UNIQAPI_img')), array('pathway' => 'both')));
check('both: envelope with projects + images blocks',
	$r['pathway'] === 'both' && isset($r['projects']['total']) && isset($r['images']['total']));

// ---------------------------------------------------------------------------
section('9. Facet counts — self-exclusion recount');

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'),
	array('id' => 'F7', 'value' => array($PFX . 'rock')))));
check('facet_counts.F7 present', isset($r['facet_counts']['F7']));
check('facet recount excludes own filter: sedimentary values still counted',
	isset($r['facet_counts']['F7']['sedimentary']) && $r['facet_counts']['F7']['sedimentary'] === 1,
	json_encode($r['facet_counts']));

// ---------------------------------------------------------------------------
section('10. Pagination + sort');

$r1 = $own->runSearch($D(array($KEY('UNIQAPI_alpha OR UNIQAPI_beta OR UNIQAPI_gamma')),
	array('page_size' => 1, 'page' => 0, 'sort' => 'name_asc')));
$r2 = $own->runSearch($D(array($KEY('UNIQAPI_alpha OR UNIQAPI_beta OR UNIQAPI_gamma')),
	array('page_size' => 1, 'page' => 1, 'sort' => 'name_asc')));
$r3 = $own->runSearch($D(array($KEY('UNIQAPI_alpha OR UNIQAPI_beta OR UNIQAPI_gamma')),
	array('page_size' => 1, 'page' => 2, 'sort' => 'name_asc')));
check('paging: 3 totals stable', $r1['total'] === 3 && $r2['total'] === 3 && $r3['total'] === 3);
check('name_asc order: Micro Gamma, Private Alpha, Public Beta',
	$r1['results'][0]['project_name'] === 'spsapi Micro Gamma'
	&& $r2['results'][0]['project_name'] === 'spsapi Private Alpha'
	&& $r3['results'][0]['project_name'] === 'spsapi Public Beta',
	$r1['results'][0]['project_name'] . ' | ' . $r2['results'][0]['project_name'] . ' | ' . $r3['results'][0]['project_name']);
check('smart default: text query → relevance', $r1['sort'] === 'name_asc');
$r = $own->runSearch($D(array(array('id' => 'F5', 'value' => array('bedding')))));
check('smart default: pure facet → modified_desc', $r['sort'] === 'modified_desc');
$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha'))));
check('smart default: text → relevance', $r['sort'] === 'relevance');

// ---------------------------------------------------------------------------
section('11. Robustness — injection shapes + validation rejects');

$evil = array(
	$D(array(array('id' => 'U1', 'value' => "x'; DROP TABLE strabosearch.item_hit; --"))),
	$D(array(array('id' => 'U5', 'value' => "%' OR '1'='1"))),
	$D(array(array('id' => 'F7', 'value' => array("x','y); DELETE FROM users; --")))),
	$D(array(array('id' => 'M3', 'value' => array('"; DROP TABLE users; --')))),
	$D(array(array('id' => 'U10', 'value' => 'x\\%_"foo"'))),
);
$ok = true;
foreach ($evil as $i => $dsl) {
	try { svc($db, 0)->runSearch($dsl); } catch (SearchDslError $e) { /* clean reject is fine */ }
	catch (Throwable $e) { $ok = false; echo "  evil[$i] threw " . get_class($e) . PHP_EOL; }
}
check('injection-shaped values: no unhandled errors', $ok);
$n = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE '" . $PFX . "_%'");
check('fixture rows intact after injection attempts', $n === 7, "got $n");

$rejects = array(
	array('criteria' => array(array('id' => 'ZZ', 'value' => 1))),
	array('criteria' => array(array('id' => 'U2', 'value' => array('bbox' => array(1, 2))))),
	array('criteria' => array(array('id' => 'U3', 'value' => array('min' => 'junk')))),
	array('pathway' => 'sideways'),
	array('subsystems' => array('mainframe')),
	array('criteria' => 'notanarray'),
);
$ok = true;
foreach ($rejects as $i => $dsl) {
	try { svc($db, 0)->runSearch($dsl); $ok = false; echo "  reject[$i] NOT rejected" . PHP_EOL; }
	catch (SearchDslError $e) { /* expected */ }
}
check('malformed DSLs rejected with SearchDslError', $ok);

$r = $own->runSearch($D(array($KEY('UNIQAPI_alpha')), array('page_size' => 5000)));
check('page_size clamped to 100', $r['page_size'] === 100);

// ---------------------------------------------------------------------------
section('12. Cleanup');

sweep($db, $PFX, $OWNER, $COLLAB, $STRANGER, $OWNER2);
$n = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE '" . $PFX . "_%'")
   + (int)$db->get_var("SELECT count(*) FROM strabosearch.image_hit WHERE project_id LIKE '" . $PFX . "_%'")
   + (int)$db->get_var("SELECT count(*) FROM users WHERE pkey IN ($OWNER,$COLLAB,$STRANGER,$OWNER2)")
   + (int)$db->get_var("SELECT count(*) FROM collaborators WHERE strabo_project_id LIKE '" . $PFX . "_%'")
   + (int)$db->get_var("SELECT count(*) FROM strabosearch.vocab_rock_type WHERE path LIKE '" . $PFX . "rock%'");
check('zero residue', $n === 0, "got $n");

echo PHP_EOL;
if ($failures) {
	echo count($failures) . " FAILURE(S):" . PHP_EOL;
	foreach ($failures as $f) echo "  - $f" . PHP_EOL;
	exit(1);
}
echo "ALL CHECKS PASSED." . PHP_EOL;
exit(0);
