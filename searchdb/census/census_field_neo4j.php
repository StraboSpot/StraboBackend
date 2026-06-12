<?php
/**
 * StraboSearch Phase 0.2 census — Field subsystem, Neo4j source side.
 *
 * Profiles the Neo4j Spot nodes that are the Field SOURCE OF TRUTH:
 *   - exact property-key universe across all spots (which json_* blobs and
 *     legacy un-prefixed properties exist, and on how many nodes)
 *   - node counts per label vs the PG mirror counts (mirror-staleness gap)
 *   - per-userpkey reconciliation: which users' spots are missing from the
 *     PG mirror that today's /fullsearch (and any future index backfill
 *     sourced from the mirror) would silently skip
 *
 * Scans are BATCHED BY USERPKEY — whole-graph aggregations over 1.7M spots
 * are the known heap-OOM class (see tests/collaboration/scan_duplicate_spots.php).
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/census/census_field_neo4j.php
 * READ-ONLY. Progress goes to stderr; report to stdout.
 *
 * @package StraboSearch Census
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
require_once(__DIR__ . '/_census_lib.php');

$t0 = microtime(true);
line('FIELD SUBSYSTEM CENSUS (Neo4j source) — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
section('1. Node inventory vs PG mirror');

$labels = array('Spot', 'Dataset', 'Project', 'Image', 'Tag');
$neoCounts = array();
foreach ($labels as $label) {
	$rows = $neodb->query("MATCH (n:$label) RETURN count(n) AS c");
	$neoCounts[$label] = (int)$rows[0]->get('c');
}
$pgCounts = array(
	'Spot'    => (int)$db->get_var("select count(*) from spot"),
	'Dataset' => (int)$db->get_var("select count(*) from dataset"),
	'Project' => (int)$db->get_var("select count(*) from project"),
	'Image'   => (int)$db->get_var("select count(*) from image"),
	'Tag'     => null,
);
line(sprintf('  %-10s %12s %12s %12s', 'label', 'neo4j', 'pg mirror', 'gap'));
foreach ($labels as $label) {
	$pg = $pgCounts[$label];
	line(sprintf('  %-10s %12s %12s %12s',
		$label,
		number_format($neoCounts[$label]),
		$pg === null ? '-' : number_format($pg),
		$pg === null ? '-' : number_format($neoCounts[$label] - $pg)));
}

// ---------------------------------------------------------------------------
section('2. Spot property universe (exact, batched by userpkey)');

$rows = $neodb->query("MATCH (s:Spot) RETURN max(s.userpkey) AS hi");
$maxUser = (int)$rows[0]->get('hi');
progress("max spot userpkey: $maxUser");

$keys = new Tally(2000);
$neoByUser = array();   // userpkey => spot count (reused for §3)
$BATCH = 500;           // userpkeys per batch

for ($lo = 0; $lo <= $maxUser; $lo += $BATCH) {
	$hi = $lo + $BATCH;
	$rows = $neodb->query("
		MATCH (s:Spot)
		WHERE s.userpkey >= $lo AND s.userpkey < $hi
		WITH s.userpkey AS upk, keys(s) AS ks
		UNWIND ks AS k
		RETURN upk, k, count(*) AS n
	");
	foreach ($rows as $r) {
		$keys->add($r->get('k'), (int)$r->get('n'));
		// every node has userpkey, so its key-count doubles as the spot count
		if ($r->get('k') === 'userpkey') {
			$upk = (int)$r->get('upk');
			$neoByUser[$upk] = (isset($neoByUser[$upk]) ? $neoByUser[$upk] : 0) + (int)$r->get('n');
		}
	}
	if (($lo / $BATCH) % 4 == 0) progress("property scan: userpkey < $hi");
}

// Spots with NULL userpkey would be missed by the range loop — count them.
$rows = $neodb->query("MATCH (s:Spot) WHERE s.userpkey IS NULL RETURN count(s) AS c");
$nullUserSpots = (int)$rows[0]->get('c');
$totalSpots = array_sum($neoByUser) + $nullUserSpots;
progress("property scan complete: " . number_format($totalSpots) . " spots in " . round(microtime(true) - $t0) . "s");

covRow('spots scanned (by userpkey ranges)', $totalSpots, $neoCounts['Spot']);
covRow('spots with NULL userpkey (excluded)', $nullUserSpots, $neoCounts['Spot']);

subsection('property key counts (all ' . $keys->distinct() . ' distinct keys, top 45)');
$keys->printTop(45, $totalSpots - $nullUserSpots);

subsection('json_* blob properties vs legacy un-prefixed twins');
$pairs = array(
	array('json_orientation_data', 'orientation_data'),
	array('json_rock_unit', 'rock_unit'),
	array('json_samples', 'samples'),
	array('json_trace', 'trace'),
	array('json_other_features', 'other_features'),
	array('json__3d_structures', '_3d_structures'),
	array('json_inferences', 'inferences'),
	array('json_sed', 'sed'),
	array('json_pet', 'pet'),
);
line(sprintf('  %-26s %12s %12s', 'field', 'json_* form', 'legacy form'));
foreach ($pairs as $pair) {
	$a = isset($keys->counts[$pair[0]]) ? $keys->counts[$pair[0]] : 0;
	$b = isset($keys->counts[$pair[1]]) ? $keys->counts[$pair[1]] : 0;
	line(sprintf('  %-26s %12s %12s', $pair[1], number_format($a), number_format($b)));
}

// ---------------------------------------------------------------------------
section('3. Per-user mirror reconciliation (Neo4j vs PG spot counts)');

$pgByUser = array();
$rows = $db->get_results("select user_pkey, count(*) as n from spot group by user_pkey");
foreach ($rows as $r) $pgByUser[(int)$r->user_pkey] = (int)$r->n;

$gaps = array();
$totalGap = 0;
$allUsers = array_unique(array_merge(array_keys($neoByUser), array_keys($pgByUser)));
foreach ($allUsers as $u) {
	$neo = isset($neoByUser[$u]) ? $neoByUser[$u] : 0;
	$pg  = isset($pgByUser[$u]) ? $pgByUser[$u] : 0;
	if ($neo != $pg) {
		$gaps[$u] = $neo - $pg;
		$totalGap += abs($neo - $pg);
	}
}
covRow('users with a count mismatch', count($gaps), count($allUsers));
line('  total absolute spot-count gap: ' . number_format($totalGap));

uasort($gaps, function ($a, $b) { return abs($b) - abs($a); });
subsection('top 15 users by absolute gap (positive = in Neo4j, missing from mirror)');
line(sprintf('  %-10s %12s %12s %12s', 'userpkey', 'neo4j', 'pg mirror', 'gap'));
$i = 0;
foreach ($gaps as $u => $gap) {
	if (++$i > 15) break;
	$neo = isset($neoByUser[$u]) ? $neoByUser[$u] : 0;
	$pg  = isset($pgByUser[$u]) ? $pgByUser[$u] : 0;
	line(sprintf('  %-10s %12s %12s %12s', $u, number_format($neo), number_format($pg), number_format($gap)));
}

line();
line('Done in ' . round(microtime(true) - $t0) . 's.');
?>
