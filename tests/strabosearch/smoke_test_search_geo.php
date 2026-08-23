<?php
/**
 * File: smoke_test_search_geo.php
 * Description: Permanent smoke suite for the Globe View M2 geo pathway
 *              (SearchQueryBuilder::runGeoQuery + validateGeo,
 *              StraboSearchService::runGeoSearch) at the SERVICE level.
 *              The session proxy routing (api.php?action=search_geo) gets
 *              two checks in smoke_test_search_api_http.php.
 *
 *              Hermetic: fixtures written straight into
 *              strabosearch.item_hit under isolated userpkeys 94610-94611
 *              with the 'spsgeo' prefix, disjoint from every other suite.
 *              All fixture projects belong to ONE owner so a U4 owner
 *              criterion fences every assertion off from real dev data.
 *
 *              Coverage:
 *                MODES    bins over the point threshold, points under it,
 *                         empty viewport = empty points
 *                ACL      anonymous / owner / accepted collaborator via
 *                         the shared itemWhere (private rows fenced)
 *                GRID     cell-size derivation (world clamp 7.5°, zoomed
 *                         width/48), centroid falls inside its cell,
 *                         bin counts sum to in_view_located
 *                VIEWPORT envelope filtering, antimeridian split (w > e)
 *                COUNTER  include_counter totals; located counts ONLY
 *                         renderable coordinates (junk UTM-ish rows and
 *                         NULL locations excluded)
 *                CRITERIA DSL reuse through the geo path: U1, U4, U9,
 *                         per-row NOT, U8 subsystems 'samples'
 *                SHAPE    point feature fields incl. dataset_ids text[]
 *                         parse + owner_name join
 *                ROBUST   geo validation rejects, injection-shaped text,
 *                         empty criteria legal (globe browse contract)
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_search_geo.php
 *
 * @package    StraboSearch API
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/searchdb/services/StraboSearchService.php';

$OWNER  = 94610;   // owns every fixture project
$COLLAB = 94611;   // accepted collaborator on the private project
$PFX    = 'spsgeo';

$P_PRIV  = $PFX . '_priv';    // private field
$P_PUB   = $PFX . '_pub';     // public field (+ one sample-spine row)
$P_SEAM  = $PFX . '_seam';    // public micro, straddles the antimeridian
$P_DENSE = $PFX . '_dense';   // public field, 1600 rows (forces bin mode)

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }
function svc($db, $upk) { return new StraboSearchService($db, $upk); }

/** Geo request helper: criteria rows + geo block → response array. */
function geo($svc, $criteria, $geoBlock, $extra = array()) {
	$body = array_merge(array('criteria' => $criteria, 'geo' => $geoBlock), $extra);
	return $svc->runGeoSearch($body);
}
function u4($OWNER) { return array('id' => 'U4', 'value' => array($OWNER)); }

// ---------------------------------------------------------------------------
section('0. Cleanup any prior residue + seed fixtures');

function sweep($db, $PFX, $OWNER, $COLLAB) {
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM collaborators WHERE strabo_project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM users WHERE pkey IN ($1,$2)", array($OWNER, $COLLAB));
}
sweep($db, $PFX, $OWNER, $COLLAB);

foreach (array(
	array($OWNER,  'Geo', 'Owner'),
	array($COLLAB, 'Geo', 'Collab'),
) as $u) {
	$db->prepare_query(
		"INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted)
		 VALUES ($1, $2, $3, $4, 'x', 'x', TRUE, FALSE)",
		array($u[0], $u[1], $u[2], strtolower($PFX . $u[0] . '@example.com')));
}

$db->prepare_query(
	"INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
	                            collaboration_level, accepted, disabled, uuid)
	 VALUES ($1, $2, $3, 'readonly', TRUE, FALSE, $4)",
	array($P_PRIV, $OWNER, $COLLAB, $PFX . '-collab-uuid'));

/** Slim item_hit seeder — only the columns the geo pathway reads. */
function seedGeoItem($db, $over) {
	$d = array_merge(array(
		'item_type' => 'spot', 'item_id' => '', 'item_userpkey' => 0,
		'project_id' => '', 'project_userpkey' => 0, 'project_subsystem' => 'field',
		'project_name' => '', 'project_ispublic' => 'FALSE',
		'lng' => null, 'lat' => null, 'searchtext' => '',
		'sample_id' => null, 'sample_name' => null,
		'has_orientation' => 'FALSE', 'dataset_ids' => null,
	), $over);
	$lit = function ($v) { return $v === null ? 'NULL' : "'" . pg_escape_string((string)$v) . "'"; };
	$arr = function ($v) use ($lit) {
		if ($v === null) return 'NULL';
		$parts = array();
		foreach ($v as $x) $parts[] = "'" . pg_escape_string((string)$x) . "'";
		return 'ARRAY[' . implode(',', $parts) . ']::text[]';
	};
	$loc = ($d['lng'] === null) ? 'NULL'
		: 'ST_SetSRID(ST_MakePoint(' . (float)$d['lng'] . ',' . (float)$d['lat'] . '), 4326)';
	$ok = $db->query("INSERT INTO strabosearch.item_hit
		(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
		 project_name, project_ispublic, location, searchtext_tsv,
		 sample_id, sample_name, has_orientation, dataset_ids, source_modified)
		VALUES (
		 {$lit($d['item_type'])}, {$lit($d['item_id'])}, {$d['item_userpkey']},
		 {$lit($d['project_id'])}, {$d['project_userpkey']}, {$lit($d['project_subsystem'])},
		 {$lit($d['project_name'])}, {$d['project_ispublic']}, $loc,
		 to_tsvector('english', {$lit($d['searchtext'])}),
		 {$lit($d['sample_id'])}, {$lit($d['sample_name'])},
		 {$d['has_orientation']}, {$arr($d['dataset_ids'])}, '2024-01-01 00:00:00+00')");
	if ($ok === false) { echo "  SEED FAILED: " . $db->last_error . PHP_EOL; exit(1); }
}

// ---- P_PRIV: private field, cluster near (-118.5, 34.2) ------------------
// 4 renderable spots (2 with orientation) + 1 NULL location.
seedGeoItem($db, array('item_id' => $PFX . '_s1', 'item_userpkey' => $OWNER,
	'project_id' => $P_PRIV, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Private',
	'lng' => -118.50, 'lat' => 34.20, 'has_orientation' => 'TRUE', 'searchtext' => 'UNIQGEO_priv one'));
seedGeoItem($db, array('item_id' => $PFX . '_s2', 'item_userpkey' => $OWNER,
	'project_id' => $P_PRIV, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Private',
	'lng' => -118.49, 'lat' => 34.21, 'has_orientation' => 'TRUE', 'searchtext' => 'UNIQGEO_priv two'));
seedGeoItem($db, array('item_id' => $PFX . '_s3', 'item_userpkey' => $OWNER,
	'project_id' => $P_PRIV, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Private',
	'lng' => -118.48, 'lat' => 34.22, 'searchtext' => 'UNIQGEO_priv three'));
seedGeoItem($db, array('item_id' => $PFX . '_s4', 'item_userpkey' => $OWNER,
	'project_id' => $P_PRIV, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Private',
	'lng' => -118.47, 'lat' => 34.23, 'searchtext' => 'UNIQGEO_priv four'));
seedGeoItem($db, array('item_id' => $PFX . '_s5', 'item_userpkey' => $OWNER,
	'project_id' => $P_PRIV, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Private',
	'searchtext' => 'UNIQGEO_priv unlocated'));

// ---- P_PUB: public field, cluster near (10, 45) --------------------------
// 5 spots + 1 sample = 6 renderable, 1 junk-coordinate row (the real-data
// UTM-in-lon/lat residue, 2026-08-23), 1 NULL location.
seedGeoItem($db, array('item_id' => $PFX . '_t1', 'item_userpkey' => $OWNER,
	'project_id' => $P_PUB, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Public',
	'project_ispublic' => 'TRUE', 'lng' => 10.00, 'lat' => 45.00,
	'searchtext' => 'UNIQGEO_pub granite', 'dataset_ids' => array($PFX . '_D1', $PFX . '_D2')));
foreach (array(array('t2', 10.01, 45.01), array('t3', 10.02, 45.02),
               array('t4', 10.03, 45.03), array('t5', 10.04, 45.04)) as $t) {
	seedGeoItem($db, array('item_id' => $PFX . '_' . $t[0], 'item_userpkey' => $OWNER,
		'project_id' => $P_PUB, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Public',
		'project_ispublic' => 'TRUE', 'lng' => $t[1], 'lat' => $t[2],
		'searchtext' => 'UNIQGEO_pub spot'));
}
seedGeoItem($db, array('item_type' => 'sample', 'item_id' => $PFX . '_smp1',
	'item_userpkey' => $OWNER, 'project_id' => $P_PUB, 'project_userpkey' => $OWNER,
	'project_name' => 'spsgeo Public', 'project_ispublic' => 'TRUE',
	'lng' => 10.05, 'lat' => 45.05, 'sample_id' => $PFX . '_smp1',
	'sample_name' => 'spsgeo Sample X', 'searchtext' => 'UNIQGEO_pub sample'));
seedGeoItem($db, array('item_id' => $PFX . '_junk', 'item_userpkey' => $OWNER,
	'project_id' => $P_PUB, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Public',
	'project_ispublic' => 'TRUE', 'lng' => 500.0, 'lat' => 9000.0,
	'searchtext' => 'UNIQGEO_pub junkcoords'));
seedGeoItem($db, array('item_id' => $PFX . '_noloc', 'item_userpkey' => $OWNER,
	'project_id' => $P_PUB, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Public',
	'project_ispublic' => 'TRUE', 'searchtext' => 'UNIQGEO_pub unlocated'));

// ---- P_SEAM: public micro, both sides of the antimeridian -----------------
seedGeoItem($db, array('item_type' => 'micrograph', 'item_id' => $PFX . '_m1',
	'item_userpkey' => $OWNER, 'project_id' => $P_SEAM, 'project_userpkey' => $OWNER,
	'project_subsystem' => 'micro', 'project_name' => 'spsgeo Seam',
	'project_ispublic' => 'TRUE', 'lng' => 179.9, 'lat' => -40.0));
seedGeoItem($db, array('item_type' => 'micrograph', 'item_id' => $PFX . '_m2',
	'item_userpkey' => $OWNER, 'project_id' => $P_SEAM, 'project_userpkey' => $OWNER,
	'project_subsystem' => 'micro', 'project_name' => 'spsgeo Seam',
	'project_ispublic' => 'TRUE', 'lng' => -179.9, 'lat' => -40.1));

// ---- P_DENSE: public field, 1600 rows over ~2°x2° near (-50, 20) ----------
// One INSERT..SELECT — forces bin mode (GEO_POINT_LIMIT is 1500).
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
	 project_name, project_ispublic, location, searchtext_tsv, source_modified)
	SELECT 'spot', 'spsgeo_d' || i, $OWNER, '$P_DENSE', $OWNER, 'field',
	       'spsgeo Dense', TRUE,
	       ST_SetSRID(ST_MakePoint(-51.0 + (i % 40) * 0.05, 19.0 + (i / 40) * 0.05), 4326),
	       to_tsvector('english', 'UNIQGEO_dense'), '2024-01-01 00:00:00+00'
	FROM generate_series(0, 1599) AS i");
if ($db->last_error) { echo "  DENSE SEED FAILED: " . $db->last_error . PHP_EOL; exit(1); }

$seeded = (int)$db->get_var_prepared(
	"SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_%'));
check('fixtures seeded (5+8+2+1600 rows)', $seeded === 1615, "got $seeded");

$anon  = svc($db, 0);
$own   = svc($db, $OWNER);
$col   = svc($db, $COLLAB);

$WORLD = array(-180, -90, 180, 90);
$BB_PRIV  = array(-119, 34, -118, 35);
$BB_PUB   = array(5, 40, 15, 50);
$BB_DENSE = array(-51.5, 18.5, -48.5, 21.5);

// ---------------------------------------------------------------------------
section('1. Modes: bins over threshold, points under, empty viewport');

$r = geo($anon, array(u4($OWNER)), array('bbox' => $WORLD));
check('world/anon: bin mode (1608 located > 1500)', $r['mode'] === 'bins', $r['mode']);
check('world/anon: in_view_located = 1608 (public renderable only)',
	$r['in_view_located'] === 1608, $r['in_view_located']);
$sum = 0; foreach ($r['features'] as $b) $sum += $b['n'];
check('world/anon: bin counts sum to in_view_located', $sum === 1608, $sum);
check('world/anon: cell clamped to 7.5°', abs($r['cell_deg'] - 7.5) < 1e-9, $r['cell_deg']);
check('world/anon: capped flag false', $r['capped'] === false);
check('world/anon: pathway tag = geo', $r['pathway'] === 'geo');

// Every bin centroid must land inside its own cell.
$inCell = true;
foreach ($r['features'] as $b) {
	$c = $r['cell_deg'];
	if ($b['lng'] < $b['cx'] * $c - 1e-9 || $b['lng'] >= ($b['cx'] + 1) * $c + 1e-9 ||
	    $b['lat'] < $b['cy'] * $c - 1e-9 || $b['lat'] >= ($b['cy'] + 1) * $c + 1e-9) {
		$inCell = false; break;
	}
}
check('world/anon: every bin centroid inside its cell', $inCell);

$r = geo($anon, array(u4($OWNER)), array('bbox' => $BB_PUB));
check('pub bbox/anon: point mode (6 ≤ 1500)', $r['mode'] === 'points', $r['mode']);
check('pub bbox/anon: 6 point features', count($r['features']) === 6, count($r['features']));
check('pub bbox/anon: in_view_located 6', $r['in_view_located'] === 6, $r['in_view_located']);

$r = geo($anon, array(u4($OWNER)), array('bbox' => array(60, 60, 61, 61)));
check('empty viewport: points mode with no features',
	$r['mode'] === 'points' && $r['features'] === array());

$r = geo($anon, array(u4($OWNER)), array('bbox' => $BB_DENSE));
check('dense bbox/anon: still bins (1600 > 1500)', $r['mode'] === 'bins', $r['mode']);
// Ladder quantization: raw 3/48 = 0.0625 snaps DOWN to 7.5/2^7.
check('dense bbox/anon: cell = 7.5/128 (ladder)', abs($r['cell_deg'] - 7.5 / 128) < 1e-9, $r['cell_deg']);
$sum = 0; foreach ($r['features'] as $b) $sum += $b['n'];
check('dense bbox/anon: bins sum to 1600', $sum === 1600, $sum);

// ---------------------------------------------------------------------------
section('2. ACL through the shared itemWhere');

$r = geo($anon, array(u4($OWNER)), array('bbox' => $BB_PRIV));
check('private cluster invisible to anonymous',
	$r['mode'] === 'points' && count($r['features']) === 0, count($r['features']));

$r = geo($own, array(u4($OWNER)), array('bbox' => $BB_PRIV));
check('owner sees 4 private points', count($r['features']) === 4, count($r['features']));

$r = geo($col, array(u4($OWNER)), array('bbox' => $BB_PRIV));
check('accepted collaborator sees 4 private points', count($r['features']) === 4, count($r['features']));

$r = geo($own, array(u4($OWNER)), array('bbox' => $WORLD, 'include_counter' => true));
check('world/owner: in_view_located 1612 (private included)',
	$r['in_view_located'] === 1612, $r['in_view_located']);

// ---------------------------------------------------------------------------
section('3. Counter: totals vs renderable locations');

$r = geo($anon, array(u4($OWNER)), array('bbox' => array(60, 60, 61, 61), 'include_counter' => true));
check('anon counter total = 1610 (junk + NULL rows counted as results)',
	$r['counter']['total'] === 1610, $r['counter']['total']);
check('anon counter located = 1608 (junk coords + NULL excluded)',
	$r['counter']['located'] === 1608, $r['counter']['located']);

$r = geo($own, array(u4($OWNER)), array('bbox' => array(60, 60, 61, 61), 'include_counter' => true));
check('owner counter total = 1615', $r['counter']['total'] === 1615, $r['counter']['total']);
check('owner counter located = 1612', $r['counter']['located'] === 1612, $r['counter']['located']);

$r = geo($anon, array(u4($OWNER)), array('bbox' => $BB_PUB));
check('counter absent when not requested', !isset($r['counter']));

// ---------------------------------------------------------------------------
section('4. Viewport envelope + antimeridian split');

$r = geo($anon, array(u4($OWNER)), array('bbox' => array(179, -45, -179, -35)));
check('antimeridian viewport (w > e): both seam points',
	$r['mode'] === 'points' && count($r['features']) === 2, count($r['features']));
$ids = array();
foreach ($r['features'] as $f) $ids[] = $f['item_id'];
sort($ids);
check('antimeridian viewport: the two micrographs',
	$ids === array($PFX . '_m1', $PFX . '_m2'), implode(',', $ids));

$r = geo($anon, array(u4($OWNER)), array('bbox' => array(179, -45, 179.95, -35)));
check('west half only: one seam point', count($r['features']) === 1
	&& $r['features'][0]['item_id'] === $PFX . '_m1');

// ---------------------------------------------------------------------------
section('5. Criteria ride the geo path unchanged');

$r = geo($own, array(u4($OWNER), array('id' => 'U9', 'value' => array('orientation'))),
	array('bbox' => $BB_PRIV));
check('U9 orientation: 2 of the 4 private points', count($r['features']) === 2, count($r['features']));

$r = geo($own, array(u4($OWNER),
	array('id' => 'U9', 'value' => array('orientation'), 'not' => true)),
	array('bbox' => $BB_PRIV));
check('NOT U9: the complementary 2 points', count($r['features']) === 2, count($r['features']));

$r = geo($anon, array(u4($OWNER), array('id' => 'U1', 'value' => 'UNIQGEO_pub granite')),
	array('bbox' => $WORLD));
check('U1 phrase-ish text: exactly t1', count($r['features']) === 1
	&& $r['features'][0]['item_id'] === $PFX . '_t1');

$r = geo($anon, array(u4($OWNER)), array('bbox' => $WORLD),
	array('subsystems' => array('samples')));
check('U8 samples: only the sample-spine row', count($r['features']) === 1
	&& $r['features'][0]['item_type'] === 'sample');

// ---------------------------------------------------------------------------
section('6. Point feature shape');

$r = geo($anon, array(u4($OWNER), array('id' => 'U1', 'value' => 'UNIQGEO_pub granite')),
	array('bbox' => $BB_PUB));
$f = $r['features'][0];
check('point: project routing fields', $f['project_id'] === $P_PUB
	&& $f['project_userpkey'] === $OWNER && $f['project_subsystem'] === 'field');
check('point: project name + public flag',
	$f['project_name'] === 'spsgeo Public' && $f['project_ispublic'] === true);
check('point: owner_name joined', $f['owner_name'] === 'Geo Owner', $f['owner_name']);
check('point: dataset_ids parsed to a 2-element list',
	$f['dataset_ids'] === array($PFX . '_D1', $PFX . '_D2'), json_encode($f['dataset_ids']));
check('point: coordinates round-trip', abs($f['lng'] - 10.0) < 1e-6 && abs($f['lat'] - 45.0) < 1e-6);

$r = geo($anon, array(u4($OWNER),
	array('id' => 'U5', 'value' => array('text' => 'spsgeo Sample X', 'exact' => true))),
	array('bbox' => $BB_PUB));
check('sample point: sample_name carried', count($r['features']) === 1
	&& $r['features'][0]['sample_name'] === 'spsgeo Sample X');

// ---------------------------------------------------------------------------
section('7. Validation + robustness');

$threw = false;
try { geo($anon, array(), array('bbox' => array(1, 2, 3))); }
catch (SearchDslError $e) { $threw = true; }
check('bbox with 3 elements rejected', $threw);

$threw = false;
try { geo($anon, array(), array('bbox' => array('a', 'b', 'c', 'd'))); }
catch (SearchDslError $e) { $threw = true; }
check('non-numeric bbox rejected', $threw);

$r = geo($anon, array(u4($OWNER)), null);
check('missing geo block defaults to world', $r['in_view_located'] === 1608, $r['in_view_located']);

$r = geo($anon, array(u4($OWNER),
	array('id' => 'U1', 'value' => "'; DROP TABLE strabosearch.item_hit;--")),
	array('bbox' => $WORLD));
check('injection-shaped U1 value runs clean, matches nothing',
	$r['mode'] === 'points' && count($r['features']) === 0);
$still = (int)$db->get_var_prepared(
	"SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_%'));
check('fixtures intact after injection attempt', $still === 1615, $still);

// Empty criteria are legal on the geo path (globe browse contract) —
// no assertion on counts (real dev data underneath), just no throw.
$r = geo($anon, array(), array('bbox' => $WORLD));
check('empty criteria legal: response has a mode', in_array($r['mode'], array('bins', 'points'), true));

// Out-of-range bbox values clamp instead of erroring.
$r = geo($anon, array(u4($OWNER)), array('bbox' => array(-999, -999, 999, 999)));
check('oversized bbox clamps to world', $r['in_view_located'] === 1608, $r['in_view_located']);

// ---------------------------------------------------------------------------
section('8. Cleanup');

sweep($db, $PFX, $OWNER, $COLLAB);
$left = (int)$db->get_var_prepared(
	"SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_%'));
$leftU = (int)$db->get_var_prepared(
	"SELECT count(*) FROM users WHERE pkey IN ($1,$2)", array($OWNER, $COLLAB));
check('zero fixture rows left', $left === 0 && $leftU === 0, "items=$left users=$leftU");

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('=', 70) . PHP_EOL;
if ($failures) {
	echo 'FAILURES (' . count($failures) . '):' . PHP_EOL;
	foreach ($failures as $f) echo '  - ' . $f . PHP_EOL;
	exit(1);
}
echo 'ALL CHECKS PASSED' . PHP_EOL;
exit(0);
