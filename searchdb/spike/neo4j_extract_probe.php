<?php
/**
 * StraboSearch Phase 0.3 spike — Neo4j extraction timing probe.
 *
 * The census disqualified the PG mirror as the real index source, so the
 * Phase 2 backfill must pull full Spot nodes (all properties, including
 * json_* blob strings) from Neo4j. This probe measures that pull rate on a
 * bounded sample and extrapolates the full-graph envelope for the engine
 * evaluation + eventual cutover runbook.
 *
 * Batched by userpkey (the proven OOM-safe pattern). Stops after ~TARGET
 * spots. READ-ONLY.
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/spike/neo4j_extract_probe.php
 *
 * @package StraboSearch Spike
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('neodb.php');
require_once(__DIR__ . '/../census/_census_lib.php');

$TARGET = 50000;
$PAGE = 5000; // spots per query — full-node pulls must stay small; a single
              // user can hold 189k spots and the Bolt client materializes
              // the whole result (1GB OOM at batch=200 userpkeys)

line('NEO4J EXTRACTION PROBE — ' . date('Y-m-d H:i:s'));

$rows = $neodb->query("MATCH (s:Spot) RETURN count(s) AS c, max(s.userpkey) AS hi");
$totalSpots = (int)$rows[0]->get('c');
$maxUser = (int)$rows[0]->get('hi');
line('  total spots: ' . number_format($totalSpots) . ', max userpkey: ' . $maxUser);

$t0 = microtime(true);
$pulled = 0;
$bytes = 0;
$decoded = 0;

for ($upk = 0; $upk <= $maxUser && $pulled < $TARGET; $upk++) {
	$skip = 0;
	while ($pulled < $TARGET) {
		$rows = $neodb->query("
			MATCH (s:Spot)
			WHERE s.userpkey = $upk
			RETURN s.id AS id, s.userpkey AS upk, s.name AS name,
			       s.json_orientation_data AS jod, s.orientation_data AS od_legacy,
			       s.json_rock_unit AS jru, s.json_samples AS jsm,
			       s.json_trace AS jtr, s.notes AS notes, s.wkt AS wkt,
			       s.modified_timestamp AS mt
			SKIP $skip LIMIT $PAGE
		");
		if (!$rows || count($rows) == 0) break;
		foreach ($rows as $r) {
			$pulled++;
			// Decode the blob fields exactly as a real extractor would.
			foreach (array('jod', 'od_legacy', 'jru', 'jsm', 'jtr') as $f) {
				$v = $r->get($f);
				if (is_string($v) && $v !== '') {
					$bytes += strlen($v);
					if (json_decode($v) !== null) $decoded++;
				}
			}
		}
		$skip += $PAGE;
		if (count($rows) < $PAGE) break;
	}
}

$elapsed = microtime(true) - $t0;
$rate = $pulled / max($elapsed, 0.001);

line(sprintf('  pulled %s spots in %.1fs  (%s spots/s)', number_format($pulled), $elapsed, number_format(round($rate))));
line(sprintf('  blob payload decoded: %s fields, %s MB', number_format($decoded), number_format($bytes / 1048576, 1)));
line(sprintf('  full-graph extrapolation (%s spots): ~%s minutes', number_format($totalSpots), number_format($totalSpots / $rate / 60, 1)));
line();
line('Done.');
?>
