<?php
/**
 * StraboSearch Phase 0.3 spike — hard-query harness.
 *
 * Runs the representative multi-criteria queries the master plan names as
 * the engine-evaluation spike (and which Phase 5.D later inherits as its
 * benchmark set). Each query runs 3 times; all timings reported (first run
 * doubles as the cold-ish number). EXPLAIN ANALYZE summary printed once.
 *
 * Q1  dip range + rock-type prefix + bbox + access control -> project rollup
 * Q2  keyword + access control -> rock-type facet counts
 * Q3  dense-region bbox + date range -> paginated project hits + total count
 * Q4  pure orientation range at scale (worst-case single facet)
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/spike/run_queries.php
 * READ-ONLY against strabosearch_spike.*.
 *
 * @package StraboSearch Spike
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/../census/_census_lib.php');

line('SPIKE QUERY HARNESS — ' . date('Y-m-d H:i:s'));

// Anonymous-user access model, same as today's fullsearch: userpkey 0.
$ME = 0;

$queries = array(

'Q1 dip 30-60 + rock sedimentary:limestone% + western-US bbox -> project rollup' => "
	SELECT s.project_pkey, count(distinct s.spot_pkey) AS spots
	FROM strabosearch_spike.orientations o
	JOIN strabosearch_spike.spots s ON s.spot_pkey = o.spot_pkey
	JOIN strabosearch_spike.rock_types rt ON rt.spot_pkey = s.spot_pkey
	WHERE o.dip BETWEEN 30 AND 60
	  AND rt.rock_type LIKE 'sedimentary:limestone%'
	  AND s.location && ST_MakeEnvelope(-125, 31, -102, 49, 4326)
	  AND (s.ispublic OR s.userpkey = $ME)
	GROUP BY s.project_pkey
	ORDER BY spots DESC
	LIMIT 10",

'Q2 keyword granite -> rock-type facet counts' => "
	SELECT rt.rock_type, count(distinct s.spot_pkey) AS spots
	FROM strabosearch_spike.spots s
	JOIN strabosearch_spike.rock_types rt ON rt.spot_pkey = s.spot_pkey
	WHERE s.keywords @@ to_tsquery('granite')
	  AND (s.ispublic OR s.userpkey = $ME)
	GROUP BY rt.rock_type
	ORDER BY spots DESC
	LIMIT 15",

'Q3 dense bbox + 2020+ date -> paginated project hits (page 1) ' => "
	SELECT s.project_pkey, count(*) AS spots,
	       min(s.date_created) AS first_spot, max(s.date_created) AS last_spot
	FROM strabosearch_spike.spots s
	WHERE s.location && ST_MakeEnvelope(-120, 33, -115, 38, 4326)
	  AND s.date_created >= '2020-01-01'
	  AND (s.ispublic OR s.userpkey = $ME)
	GROUP BY s.project_pkey
	ORDER BY spots DESC
	LIMIT 10 OFFSET 0",

'Q3b same predicate -> total hit count (pagination denominator)' => "
	SELECT count(distinct s.project_pkey)
	FROM strabosearch_spike.spots s
	WHERE s.location && ST_MakeEnvelope(-120, 33, -115, 38, 4326)
	  AND s.date_created >= '2020-01-01'
	  AND (s.ispublic OR s.userpkey = $ME)",

'Q4 worst case: all public planar orientations dip 30-60 (no other filter)' => "
	SELECT count(*)
	FROM strabosearch_spike.orientations o
	WHERE o.dip BETWEEN 30 AND 60
	  AND (o.ispublic OR o.userpkey = $ME)",
);

foreach ($queries as $label => $sql) {
	section($label);
	$timings = array();
	$n = null;
	for ($i = 0; $i < 3; $i++) {
		$t = microtime(true);
		$rows = $db->get_results($sql);
		$timings[] = round((microtime(true) - $t) * 1000, 1);
		$n = is_array($rows) ? count($rows) : 0;
	}
	line('  runs (ms): ' . implode(' / ', $timings) . '   result rows: ' . $n);
	if ($rows) {
		$i = 0;
		foreach ($rows as $r) {
			if (++$i > 5) { line('  ...'); break; }
			line('  ' . json_encode(get_object_vars($r)));
		}
	}
	$plan = $db->get_results("EXPLAIN (ANALYZE, BUFFERS, TIMING OFF) " . $sql);
	subsection('plan (condensed)');
	$shown = 0;
	foreach ($plan as $p) {
		$pline = array_values(get_object_vars($p))[0];
		if (preg_match('/(Scan|Join|Aggregate|Sort|Execution Time|Planning Time)/', $pline) && $shown < 14) {
			line('  ' . trim($pline));
			$shown++;
		}
	}
}

line();
line('Done.');
?>
