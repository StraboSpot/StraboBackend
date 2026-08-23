<?php
/**
 * File: smoke_test_search_geo.php
 * Description: Permanent smoke suite for the Globe View geo pathway
 *              (SearchQueryBuilder::runGeoQuery + validateGeo,
 *              StraboSearchService::runGeoSearch) at the SERVICE level,
 *              D7 project-level semantics (GlobeView_Design_Proposal.md
 *              §9): ONE marker per matching project, fetched once per
 *              search. The session proxy routing (api.php?action=
 *              search_geo) gets its checks in smoke_test_search_ui.php.
 *
 *              Hermetic: fixtures written straight into
 *              strabosearch.item_hit under isolated userpkeys 94610-94611
 *              with the 'spsgeo' prefix, disjoint from every other suite.
 *              All fixture projects belong to ONE owner so a U4 owner
 *              criterion fences every assertion off from real dev data.
 *
 *              Coverage:
 *                GROUPING one feature per project regardless of row
 *                         count (1600-row project collapses to one),
 *                         match_counts per item type, dataset count +
 *                         ids aggregated over MATCHED rows only
 *                CENTROID avg-x/avg-y over RENDERABLE rows only (junk
 *                         UTM-ish coords and NULL locations excluded);
 *                         the antimeridian ocean-centroid tradeoff is
 *                         pinned as documented behavior
 *                ACL      anonymous / owner / accepted collaborator via
 *                         the shared itemWhere (private projects fenced)
 *                COUNTER  include_counter counts PROJECTS: total vs
 *                         located (renderable centroid only); absent
 *                         when not requested
 *                CAP      GEO_PROJECT_CAP largest-first with capped flag
 *                CRITERIA DSL reuse through the geo path: U1, U4, U5,
 *                         U9, per-row NOT, U8 subsystems 'samples';
 *                         match_counts reflect the filtered rows
 *                SHAPE    feature fields incl. owner_name join,
 *                         date_range, last_modified, ispublic bool
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

$P_PRIV  = $PFX . '_priv';    // private field: 4 located spots + 1 NULL
$P_PUB   = $PFX . '_pub';     // public field: 6 renderable + junk + NULL
$P_SEAM  = $PFX . '_seam';    // public micro, straddles the antimeridian
$P_DENSE = $PFX . '_dense';   // public field, 1600 rows -> ONE marker
$P_NOLOC = $PFX . '_noloc';   // public exp, only an unlocated row
$P_JUNK  = $PFX . '_junkonly';// public field, only junk coordinates

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }
function svc($db, $upk) { return new StraboSearchService($db, $upk); }

/** Geo request helper: criteria rows + geo block -> response array. */
function geo($svc, $criteria, $geoBlock, $extra = array()) {
	$body = array_merge(array('criteria' => $criteria, 'geo' => $geoBlock), $extra);
	return $svc->runGeoSearch($body);
}
function u4($OWNER) { return array('id' => 'U4', 'value' => array($OWNER)); }
/** features keyed by project_id for order-independent assertions. */
function byProject($resp) {
	$out = array();
	foreach ($resp['features'] as $f) $out[$f['project_id']] = $f;
	return $out;
}

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

/** Slim item_hit seeder: only the columns the geo pathway reads. */
function seedGeoItem($db, $over) {
	$d = array_merge(array(
		'item_type' => 'spot', 'item_id' => '', 'item_userpkey' => 0,
		'project_id' => '', 'project_userpkey' => 0, 'project_subsystem' => 'field',
		'project_name' => '', 'project_ispublic' => 'FALSE',
		'lng' => null, 'lat' => null, 'searchtext' => '',
		'sample_id' => null, 'sample_name' => null, 'date_value' => null,
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
		 sample_id, sample_name, date_value, has_orientation, dataset_ids, source_modified)
		VALUES (
		 {$lit($d['item_type'])}, {$lit($d['item_id'])}, {$d['item_userpkey']},
		 {$lit($d['project_id'])}, {$d['project_userpkey']}, {$lit($d['project_subsystem'])},
		 {$lit($d['project_name'])}, {$d['project_ispublic']}, $loc,
		 to_tsvector('english', {$lit($d['searchtext'])}),
		 {$lit($d['sample_id'])}, {$lit($d['sample_name'])}, {$lit($d['date_value'])},
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
// 5 spots + 1 sample renderable, 1 junk-coordinate spot (the real-data
// UTM-in-lon/lat residue), 1 NULL-location spot. Renderable centroid must
// be the average of the 6 good rows: (10.025, 45.025).
seedGeoItem($db, array('item_id' => $PFX . '_t1', 'item_userpkey' => $OWNER,
	'project_id' => $P_PUB, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Public',
	'project_ispublic' => 'TRUE', 'lng' => 10.00, 'lat' => 45.00, 'date_value' => '2020-05-01',
	'searchtext' => 'UNIQGEO_pub granite', 'dataset_ids' => array($PFX . '_D1', $PFX . '_D2')));
foreach (array(array('t2', 10.01, 45.01), array('t3', 10.02, 45.02),
               array('t4', 10.03, 45.03), array('t5', 10.04, 45.04)) as $t) {
	seedGeoItem($db, array('item_id' => $PFX . '_' . $t[0], 'item_userpkey' => $OWNER,
		'project_id' => $P_PUB, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo Public',
		'project_ispublic' => 'TRUE', 'lng' => $t[1], 'lat' => $t[2],
		'date_value' => ($t[0] === 't2' ? '2021-06-01' : null),
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
// Documented D7 tradeoff: the raw-average centroid lands at (0, -40.05),
// i.e. in the ocean on the OTHER side of the planet. Pinned on purpose so
// a future fix shows up as a deliberate test change.
seedGeoItem($db, array('item_type' => 'micrograph', 'item_id' => $PFX . '_m1',
	'item_userpkey' => $OWNER, 'project_id' => $P_SEAM, 'project_userpkey' => $OWNER,
	'project_subsystem' => 'micro', 'project_name' => 'spsgeo Seam',
	'project_ispublic' => 'TRUE', 'lng' => 179.9, 'lat' => -40.0));
seedGeoItem($db, array('item_type' => 'micrograph', 'item_id' => $PFX . '_m2',
	'item_userpkey' => $OWNER, 'project_id' => $P_SEAM, 'project_userpkey' => $OWNER,
	'project_subsystem' => 'micro', 'project_name' => 'spsgeo Seam',
	'project_ispublic' => 'TRUE', 'lng' => -179.9, 'lat' => -40.1));

// ---- P_NOLOC: public exp project whose only row has no location ----------
seedGeoItem($db, array('item_type' => 'experiment', 'item_id' => $PFX . '_e1',
	'item_userpkey' => $OWNER, 'project_id' => $P_NOLOC, 'project_userpkey' => $OWNER,
	'project_subsystem' => 'exp', 'project_name' => 'spsgeo NoLoc',
	'project_ispublic' => 'TRUE', 'searchtext' => 'UNIQGEO_noloc'));

// ---- P_JUNK: public field project whose only row has junk coordinates ----
seedGeoItem($db, array('item_id' => $PFX . '_j1', 'item_userpkey' => $OWNER,
	'project_id' => $P_JUNK, 'project_userpkey' => $OWNER, 'project_name' => 'spsgeo JunkOnly',
	'project_ispublic' => 'TRUE', 'lng' => 400.0, 'lat' => 9500.0,
	'searchtext' => 'UNIQGEO_junkonly'));

// ---- P_DENSE: public field, 1600 rows over ~2°x2° near (-50, 20) ----------
// One INSERT..SELECT. Under D7 these collapse to ONE marker with
// c_spot 1600 and centroid (-50.025, 19.975) (integer division on i/40).
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
check('fixtures seeded (5+8+2+1+1+1600 rows)', $seeded === 1617, "got $seeded");

$anon  = svc($db, 0);
$own   = svc($db, $OWNER);
$col   = svc($db, $COLLAB);

// ---------------------------------------------------------------------------
section('1. Grouping: one marker per project, fetched without a viewport');

$r = geo($anon, array(u4($OWNER)), array());
check('anon: pathway tag = geo', $r['pathway'] === 'geo');
check('anon: 3 features (pub, seam, dense; noloc + junkonly unlocatable)',
	count($r['features']) === 3, count($r['features']));
$by = byProject($r);
check('anon: one marker each for pub/seam/dense',
	isset($by[$P_PUB]) && isset($by[$P_SEAM]) && isset($by[$P_DENSE]));
check('anon: capped flag false', $r['capped'] === false);

check('dense: 1600 rows collapse to one marker with c_spot 1600',
	isset($by[$P_DENSE]) && $by[$P_DENSE]['match_counts']['spot'] === 1600,
	isset($by[$P_DENSE]) ? $by[$P_DENSE]['match_counts']['spot'] : 'missing');
check('largest project sorts first (cap ordering)',
	$r['features'][0]['project_id'] === $P_DENSE, $r['features'][0]['project_id']);

check('pub: match_counts spot 7 / sample 1 (junk + NULL rows still match)',
	$by[$P_PUB]['match_counts']['spot'] === 7 && $by[$P_PUB]['match_counts']['sample'] === 1,
	json_encode($by[$P_PUB]['match_counts']));
check('pub: dataset count 2 + ids aggregated',
	$by[$P_PUB]['match_counts']['dataset'] === 2
	&& count($by[$P_PUB]['dataset_ids']) === 2
	&& in_array($PFX . '_D1', $by[$P_PUB]['dataset_ids'], true)
	&& in_array($PFX . '_D2', $by[$P_PUB]['dataset_ids'], true),
	json_encode($by[$P_PUB]['dataset_ids']));

// ---------------------------------------------------------------------------
section('2. Centroids: renderable rows only');

check('pub centroid = avg of the 6 renderable rows (junk + NULL excluded)',
	abs($by[$P_PUB]['lng'] - 10.025) < 1e-6 && abs($by[$P_PUB]['lat'] - 45.025) < 1e-6,
	$by[$P_PUB]['lng'] . ',' . $by[$P_PUB]['lat']);
check('dense centroid at the grid average',
	abs($by[$P_DENSE]['lng'] - (-50.025)) < 1e-6 && abs($by[$P_DENSE]['lat'] - 19.975) < 1e-6,
	$by[$P_DENSE]['lng'] . ',' . $by[$P_DENSE]['lat']);
check('seam centroid = documented ocean-average tradeoff (0, -40.05)',
	abs($by[$P_SEAM]['lng'] - 0.0) < 1e-6 && abs($by[$P_SEAM]['lat'] - (-40.05)) < 1e-6,
	$by[$P_SEAM]['lng'] . ',' . $by[$P_SEAM]['lat']);

// ---------------------------------------------------------------------------
section('3. ACL through the shared itemWhere');

check('private project invisible to anonymous', !isset($by[$P_PRIV]));

$r = geo($own, array(u4($OWNER)), array());
$byOwn = byProject($r);
check('owner sees 4 features (private included)', count($r['features']) === 4, count($r['features']));
check('owner: private marker with centroid avg of its 4 located spots',
	isset($byOwn[$P_PRIV])
	&& abs($byOwn[$P_PRIV]['lng'] - (-118.485)) < 1e-6
	&& abs($byOwn[$P_PRIV]['lat'] - 34.215) < 1e-6,
	isset($byOwn[$P_PRIV]) ? $byOwn[$P_PRIV]['lng'] . ',' . $byOwn[$P_PRIV]['lat'] : 'missing');
check('owner: private c_spot 5 (NULL-location row still matches)',
	isset($byOwn[$P_PRIV]) && $byOwn[$P_PRIV]['match_counts']['spot'] === 5);
check('owner: ispublic false on the private marker',
	isset($byOwn[$P_PRIV]) && $byOwn[$P_PRIV]['project_ispublic'] === false);

$r = geo($col, array(u4($OWNER)), array());
$byCol = byProject($r);
check('accepted collaborator sees the private marker too', isset($byCol[$P_PRIV]));

// ---------------------------------------------------------------------------
section('4. Counter: projects total vs located');

$r = geo($anon, array(u4($OWNER)), array('include_counter' => true));
check('anon counter total = 5 matching projects (noloc + junkonly counted)',
	$r['counter']['total'] === 5, $r['counter']['total']);
check('anon counter located = 3 (only renderable centroids)',
	$r['counter']['located'] === 3, $r['counter']['located']);

$r = geo($own, array(u4($OWNER)), array('include_counter' => true));
check('owner counter total = 6', $r['counter']['total'] === 6, $r['counter']['total']);
check('owner counter located = 4', $r['counter']['located'] === 4, $r['counter']['located']);

$r = geo($anon, array(u4($OWNER)), array());
check('counter absent when not requested', !isset($r['counter']));

// ---------------------------------------------------------------------------
section('5. Criteria ride the geo path unchanged');

$r = geo($own, array(u4($OWNER), array('id' => 'U9', 'value' => array('orientation'))),
	array());
$by9 = byProject($r);
check('U9 orientation: only the private project matches',
	count($r['features']) === 1 && isset($by9[$P_PRIV]), count($r['features']));
check('U9: match_counts reflect the FILTERED rows (2 spots)',
	isset($by9[$P_PRIV]) && $by9[$P_PRIV]['match_counts']['spot'] === 2);
check('U9: centroid over the filtered rows only',
	isset($by9[$P_PRIV])
	&& abs($by9[$P_PRIV]['lng'] - (-118.495)) < 1e-6
	&& abs($by9[$P_PRIV]['lat'] - 34.205) < 1e-6,
	isset($by9[$P_PRIV]) ? $by9[$P_PRIV]['lng'] . ',' . $by9[$P_PRIV]['lat'] : 'missing');

$r = geo($own, array(u4($OWNER),
	array('id' => 'U9', 'value' => array('orientation'), 'not' => true)),
	array());
$byN = byProject($r);
check('NOT U9: private marker over the complementary 3 rows',
	isset($byN[$P_PRIV]) && $byN[$P_PRIV]['match_counts']['spot'] === 3,
	isset($byN[$P_PRIV]) ? $byN[$P_PRIV]['match_counts']['spot'] : 'missing');

$r = geo($anon, array(u4($OWNER), array('id' => 'U1', 'value' => 'UNIQGEO_pub granite')),
	array());
check('U1 text: one marker (pub), counts + centroid from the single row',
	count($r['features']) === 1
	&& $r['features'][0]['project_id'] === $P_PUB
	&& $r['features'][0]['match_counts']['spot'] === 1
	&& abs($r['features'][0]['lng'] - 10.0) < 1e-6
	&& abs($r['features'][0]['lat'] - 45.0) < 1e-6);
check('U1 text: dataset ids from the matched row only',
	$r['features'][0]['match_counts']['dataset'] === 2);

$r = geo($anon, array(u4($OWNER)), array(), array('subsystems' => array('samples')));
check('U8 samples: one marker (pub) from the sample-spine row',
	count($r['features']) === 1
	&& $r['features'][0]['project_id'] === $P_PUB
	&& $r['features'][0]['match_counts']['sample'] === 1
	&& $r['features'][0]['match_counts']['spot'] === 0
	&& abs($r['features'][0]['lng'] - 10.05) < 1e-6);

$r = geo($anon, array(u4($OWNER),
	array('id' => 'U5', 'value' => array('text' => 'spsgeo Sample X', 'exact' => true))),
	array());
check('U5 exact sample ident: pub marker, c_sample 1',
	count($r['features']) === 1 && $r['features'][0]['match_counts']['sample'] === 1);

// ---------------------------------------------------------------------------
section('6. Feature shape');

$r = geo($anon, array(u4($OWNER)), array());
$f = byProject($r);
$f = $f[$P_PUB];
check('feature: project routing fields', $f['project_id'] === $P_PUB
	&& $f['project_userpkey'] === $OWNER && $f['project_subsystem'] === 'field');
check('feature: project name + public flag',
	$f['project_name'] === 'spsgeo Public' && $f['project_ispublic'] === true);
check('feature: owner_name joined', $f['owner_name'] === 'Geo Owner', $f['owner_name']);
check('feature: date_range min/max from date_value',
	$f['date_range'] === array('2020-05-01', '2021-06-01'), json_encode($f['date_range']));
// ::text renders in the session timezone (parity with runProjectsQuery's
// last_modified), so pin the shape rather than a UTC-prefixed literal.
check('feature: last_modified carried as a timestamp string',
	preg_match('/^\d{4}-\d{2}-\d{2} /', (string)$f['last_modified']) === 1, $f['last_modified']);
check('feature: lng/lat are floats', is_float($f['lng']) && is_float($f['lat']));
check('feature: match_counts are ints',
	is_int($f['match_counts']['spot']) && is_int($f['match_counts']['dataset']));

// ---------------------------------------------------------------------------
section('7. Cap: GEO_PROJECT_CAP largest-first + capped flag');

$CAP = SearchQueryBuilder::GEO_PROJECT_CAP;
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
	 project_name, project_ispublic, location, searchtext_tsv, source_modified)
	SELECT 'spot', 'spsgeo_c' || i, $OWNER, '{$PFX}_cap_' || lpad(i::text, 5, '0'), $OWNER, 'field',
	       'spsgeo Cap', TRUE,
	       ST_SetSRID(ST_MakePoint(((i % 300) - 150)::float8 + 0.5, ((i % 120) - 60)::float8 + 0.5), 4326),
	       to_tsvector('english', 'UNIQGEO_cap'), '2024-01-01 00:00:00+00'
	FROM generate_series(1, " . ($CAP + 1) . ") AS i");
if ($db->last_error) { echo "  CAP SEED FAILED: " . $db->last_error . PHP_EOL; exit(1); }

$r = geo($anon, array(u4($OWNER)), array('include_counter' => true));
check("cap: exactly $CAP features returned", count($r['features']) === $CAP, count($r['features']));
check('cap: capped flag true', $r['capped'] === true);
check('cap: counter located reports the uncapped project count',
	$r['counter']['located'] === $CAP + 4, $r['counter']['located']);
$byCap = byProject($r);
check('cap: largest projects survive (dense first, pub + seam kept)',
	$r['features'][0]['project_id'] === $P_DENSE
	&& isset($byCap[$P_PUB]) && isset($byCap[$P_SEAM]));

$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_id LIKE $1",
	array($PFX . '_cap_%'));
$left = (int)$db->get_var_prepared(
	"SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_cap_%'));
check('cap fixtures removed', $left === 0, $left);

// ---------------------------------------------------------------------------
section('8. Validation + robustness');

$threw = false;
try { geo($anon, array(), array('bbox' => array(1, 2, 3))); }
catch (SearchDslError $e) { $threw = true; }
check('bbox with 3 elements rejected (param still validated)', $threw);

$threw = false;
try { geo($anon, array(), array('bbox' => array('a', 'b', 'c', 'd'))); }
catch (SearchDslError $e) { $threw = true; }
check('non-numeric bbox rejected', $threw);

$r = geo($anon, array(u4($OWNER)), null);
check('missing geo block is fine (no viewport needed)',
	count($r['features']) === 3, count($r['features']));

$r = geo($anon, array(u4($OWNER),
	array('id' => 'U1', 'value' => "'; DROP TABLE strabosearch.item_hit;--")),
	array());
check('injection-shaped U1 value runs clean, matches nothing',
	$r['features'] === array());
$still = (int)$db->get_var_prepared(
	"SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_%'));
check('fixtures intact after injection attempt', $still === 1617, $still);

// Empty criteria are legal on the geo path (globe browse contract);
// no assertion on counts (real dev data underneath), just no throw.
$r = geo($anon, array(), array());
check('empty criteria legal: response has a features array', is_array($r['features']));

// ---------------------------------------------------------------------------
section('9. Cleanup');

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
