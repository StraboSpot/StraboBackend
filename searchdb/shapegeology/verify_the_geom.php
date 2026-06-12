<?php
/**
 * Verify the shapegeology.the_geom repair (searchdb/shapegeology/repair_the_geom.sql).
 *
 * READ-ONLY. Checks:
 *   1. the_geom is a real PostGIS geometry column (not the varchar stub)
 *   2. fill counts (980 of 1,023 rows; the 43 poly-less rows stay NULL)
 *   3. zero invalid geometries
 *   4. GiST index present
 *   5. SRID matches spot.location (the fullsearch ST_Contains pairing)
 *   6. containment self-test (each polygon contains its own surface point)
 *   7. end-to-end: the exact fullsearch constraint shape runs without error
 *      against real spot locations (catches the mixed-SRID failure class)
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/shapegeology/verify_the_geom.php
 * Exits non-zero on any failure.
 *
 * @package StraboSearch
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');

$pass = 0;
$fail = 0;

function check($label, $ok, $detail = '') {
	global $pass, $fail;
	if ($ok) { $pass++; echo "  PASS  $label" . ($detail !== '' ? " ($detail)" : '') . "\n"; }
	else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? " ($detail)" : '') . "\n"; }
}

echo "VERIFY shapegeology.the_geom — " . date('Y-m-d H:i:s') . "\n\n";

// 1. Column type
$udt = $db->get_var("select udt_name from information_schema.columns
	where table_schema='public' and table_name='shapegeology' and column_name='the_geom'");
check('the_geom column is PostGIS geometry', $udt === 'geometry', "udt_name=$udt");

// 2. Fill counts
$row = $db->get_row("select count(*) as total, count(the_geom) as with_geom, count(poly) as with_poly
	from shapegeology");
check('all poly rows gained a geometry', (int)$row->with_geom === (int)$row->with_poly,
	"{$row->with_geom} geom / {$row->with_poly} poly / {$row->total} total");
check('geometry count in expected range', (int)$row->with_geom >= 900, $row->with_geom . ' rows');

// 3. Validity
$invalid = (int)$db->get_var("select count(*) from shapegeology
	where the_geom is not null and not ST_IsValid(the_geom)");
check('zero invalid geometries', $invalid === 0, "$invalid invalid");

// 4. GiST index
$idx = $db->get_var("select indexname from pg_indexes
	where tablename='shapegeology' and indexdef like '%USING gist%the_geom%'");
check('GiST index on the_geom', !empty($idx), (string)$idx);

// 5. SRID parity with spot.location
$gSrid = $db->get_var("select distinct ST_SRID(the_geom) from shapegeology where the_geom is not null");
$sSrid = $db->get_var("select ST_SRID(location) from spot where location is not null limit 1");
check('SRID matches spot.location', (string)$gSrid === (string)$sSrid, "the_geom=$gSrid spot=$sSrid");

// 6. Containment self-test: every polygon must contain its own surface point.
$bad = (int)$db->get_var("select count(*) from shapegeology
	where the_geom is not null and not ST_Contains(the_geom, ST_PointOnSurface(the_geom))");
check('every province contains its own surface point', $bad === 0, "$bad failures");

// 7. End-to-end: run the exact constraint shape fullsearch builds
//    (ST_Contains((select the_geom ...), spot.location)) for a handful of
//    real spots. PASS = executes without error; province hits are
//    informational (WEP coverage is sparse, 0 hits for a spot is legal).
$spots = $db->get_results("select spot_pkey, ST_AsText(location) as loc from spot
	where location is not null limit 5");
$ok = true;
$hits = 0;
foreach ($spots as $s) {
	$r = $db->get_results("select gid, name from shapegeology
		where the_geom is not null
		  and ST_Contains(the_geom, (select location from spot where spot_pkey = " . (int)$s->spot_pkey . "))");
	if ($r === false) { $ok = false; break; }
	foreach ($r as $p) {
		$hits++;
		echo "        spot {$s->spot_pkey} -> province [{$p->gid}] {$p->name}\n";
	}
}
check('fullsearch-shaped ST_Contains runs clean against spot.location', $ok,
	count($spots) . " spots probed, $hits province hits");

echo "\n" . ($fail === 0 ? "VERIFY THE_GEOM: PASS" : "VERIFY THE_GEOM: FAIL") . " ($pass passed, $fail failed)\n";
exit($fail === 0 ? 0 : 1);
?>
