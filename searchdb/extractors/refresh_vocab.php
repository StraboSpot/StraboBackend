<?php
/**
 * File: refresh_vocab.php
 * Description: StraboSearch vocab derivation pass — rebuilds the two
 *              §5.1.4 tables the criteria-builder UI feeds from, straight
 *              out of the live index (fully re-derivable per §5.1.1):
 *
 *                vocab_rock_type    — the materialized F7 hierarchy: every
 *                                     observed rock_types path PLUS all
 *                                     colon-ancestors, with parent_path +
 *                                     depth. Also what the API's F7
 *                                     "strategy (c)" prefix expansion
 *                                     resolves against.
 *                vocab_facet_counts — the §4.3 "(54,891)"-style dropdown
 *                                     count hints, one row per
 *                                     (criterion, value). Serves the
 *                                     §5.4.3 empty-search initial state.
 *                                     GLOBAL counts (not ACL-scoped) per
 *                                     the signed-off §4.3 — aggregate
 *                                     hints only, no per-item data.
 *
 *              Phase 2 shipped these tables empty (extractors never
 *              populated them — found at Phase 3 build). Run me after any
 *              full extract, and alongside the nightly verify cron:
 *
 *                docker exec strabo-php php /srv/app/www/searchdb/extractors/refresh_vocab.php
 *
 *              Criterion-id rows written (the API's vocab() map):
 *                U7T/U7P (sample type / purpose), F5, F7, F8, F9, F11,
 *                M1, M2, M3, M4, E1, E2, E3, I1.
 *
 * @package StraboSearch
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('CLI only.');
}

chdir('/srv/app/www');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/../census/_census_lib.php');  // line()/section()

$t0 = microtime(true);
line('STRABOSEARCH VOCAB REFRESH — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
section('1. vocab_rock_type — observed paths + ancestor closure');

// public rows only — the F7 tree is served to ANONYMOUS users, and rock
// types include free-typed user content (Phase 5.C: same leakage class as
// the facet counts). Private-only paths still match when typed directly
// (array overlap uses the typed path itself; expansion only adds known
// vocab descendants).
$rows = $db->get_results(
	"SELECT DISTINCT v AS path FROM strabosearch.item_hit
	 CROSS JOIN LATERAL unnest(rock_types) AS v
	 WHERE project_ispublic = TRUE AND v IS NOT NULL AND v <> ''");
$paths = array();
foreach ((array)$rows as $r) {
	// Ancestor closure: igneous:plutonic:granite also yields
	// igneous:plutonic and igneous.
	$parts = explode(':', $r->path);
	for ($i = 1; $i <= count($parts); $i++) {
		$paths[implode(':', array_slice($parts, 0, $i))] = true;
	}
}
ksort($paths);
line('  observed distinct paths (incl. ancestors): ' . count($paths));

$db->query('BEGIN');
$db->query('TRUNCATE strabosearch.vocab_rock_type');
$vals = array();
foreach (array_keys($paths) as $p) {
	$parts = explode(':', $p);
	$depth = count($parts);
	$parent = ($depth > 1) ? implode(':', array_slice($parts, 0, $depth - 1)) : null;
	$vals[] = "('" . pg_escape_string($p) . "', "
		. ($parent === null ? 'NULL' : "'" . pg_escape_string($parent) . "'")
		. ", $depth)";
}
if ($vals) {
	// prepare_query, NOT query(): the wrapper's query() chases every INSERT
	// with a bare `SELECT lastval()` — on a table with NO serial column, in
	// a session that has never touched a sequence, that errors invisibly
	// and ABORTS the surrounding transaction (the COMMIT below would
	// silently roll back). prepare_query skips the lastval dance.
	$db->last_error = '';
	$db->prepare_query("INSERT INTO strabosearch.vocab_rock_type (path, parent_path, depth) VALUES "
		. implode(',', $vals), array());
	if ($db->last_error) { line('  INSERT FAILED: ' . $db->last_error); $db->query('ROLLBACK'); exit(1); }
}
$db->query('COMMIT');
$live = (int)$db->get_var('SELECT count(*) FROM strabosearch.vocab_rock_type');
if ($live !== count($vals)) { line("  COMMIT VERIFY FAILED: table has $live rows, expected " . count($vals)); exit(1); }
line('  vocab_rock_type rebuilt: ' . $live . ' rows');

// ---------------------------------------------------------------------------
section('2. vocab_facet_counts — per-criterion value counts');

// criterion id → [table, expression, is_array]
$facetSources = array(
	'U7T' => array('item_hit',  'display_sample_type',      false),
	'U7P' => array('item_hit',  'display_sample_purpose',   false),
	'F5'  => array('item_hit',  'orientation_features',     true),
	'F7'  => array('item_hit',  'rock_types',               true),
	'F8'  => array('item_hit',  'met_facies',               true),
	'F9'  => array('item_hit',  'trace_types',              true),
	'F11' => array('item_hit',  'tag_types',                true),
	'M1'  => array('item_hit',  'minerals',                 true),
	'M2'  => array('item_hit',  'mineral_methods',          true),
	'M3'  => array('item_hit',  'instrument_type',          false),
	'M4'  => array('item_hit',  'detector_type',            false),
	'E1'  => array('item_hit',  'apparatus_type',           false),
	'E2'  => array('item_hit',  'daq_sensor_type',          false),
	'E3'  => array('item_hit',  'measurement_type',         false),
	'I1'  => array('image_hit', 'image_type',               false),
);

$db->query('BEGIN');
$db->query('TRUNCATE strabosearch.vocab_facet_counts');
$total = 0;
foreach ($facetSources as $cid => $spec) {
	list($table, $col, $isArray) = $spec;
	// project_ispublic = TRUE: this table feeds the ANONYMOUS initial
	// facet state (§5.4.3) — counting private rows leaked private vocab
	// values + counts to everyone (Phase 5.C finding). Per-user values
	// still surface through the ACL'd live recounts once criteria are
	// active; the U4 owner vocab was already ACL'd the same way.
	if ($isArray) {
		$sql = "INSERT INTO strabosearch.vocab_facet_counts (criterion_id, value, count)
			SELECT '$cid', v, count(*) FROM strabosearch.$table
			CROSS JOIN LATERAL unnest($col) AS v
			WHERE project_ispublic = TRUE AND v IS NOT NULL AND v <> '' GROUP BY 2";
	} else {
		$sql = "INSERT INTO strabosearch.vocab_facet_counts (criterion_id, value, count)
			SELECT '$cid', $col, count(*) FROM strabosearch.$table
			WHERE project_ispublic = TRUE AND $col IS NOT NULL AND $col <> '' GROUP BY 2";
	}
	// prepare_query — see the vocab_rock_type INSERT note (lastval landmine).
	$db->last_error = '';
	$db->prepare_query($sql, array());
	if ($db->last_error) {
		line("  $cid FAILED: " . $db->last_error);
		$db->query('ROLLBACK');
		exit(1);
	}
	$n = (int)$db->get_var("SELECT count(*) FROM strabosearch.vocab_facet_counts WHERE criterion_id = '$cid'");
	line(sprintf('  %-4s %-24s %6d values', $cid, $table . '.' . $col, $n));
	$total += $n;
}
$db->query('COMMIT');
line('  vocab_facet_counts rebuilt: ' . $total . ' rows');

line(sprintf('DONE in %.1fs', microtime(true) - $t0));
exit(0);
