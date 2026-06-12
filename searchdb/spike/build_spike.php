<?php
/**
 * StraboSearch Phase 0.3 spike — loader.
 *
 * Populates strabosearch_spike.* (apply spike_schema.sql first):
 *   1. spots + rock_types via pure SQL INSERT...SELECT from the mirror
 *   2. orientations via a PHP streaming pass over spot.spotjson (the same
 *      decode loop the census proved at ~100s), flattening every
 *      orientation_data element to one typed row
 *   3. index build + ANALYZE, timed separately (= the index-maintenance
 *      cost the engine evaluation needs)
 *
 * Prints a timing envelope for every stage.
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/spike/build_spike.php
 * Writes ONLY to strabosearch_spike.*.
 *
 * @package StraboSearch Spike
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/../census/_census_lib.php');

$t0 = microtime(true);
line('SPIKE LOADER — ' . date('Y-m-d H:i:s'));

function timed($label, $fn) {
	$t = microtime(true);
	$result = $fn();
	line(sprintf('  %-52s %6.1fs', $label, microtime(true) - $t));
	return $result;
}

// ---------------------------------------------------------------------------
section('1. SQL bulk loads (spots, rock_types)');

timed('spots (INSERT...SELECT from mirror + project)', function () use ($db) {
	$db->query("
		INSERT INTO strabosearch_spike.spots
			(spot_pkey, strabo_spot_id, userpkey, project_pkey, ispublic, name,
			 date_created, location, keywords, has_orientation, has_samples, has_images)
		SELECT s.spot_pkey, s.strabo_spot_id, s.user_pkey, s.project_pkey,
			coalesce(p.ispublic, false),
			s.spotjson::jsonb->'properties'->>'name',
			s.date_created, s.location, s.keywords,
			s.orientation_exists, s.sample_exists,
			exists (select 1 from image i where i.spot_pkey = s.spot_pkey)
		FROM spot s
		LEFT JOIN project p ON p.project_pkey = s.project_pkey
	");
});
$nSpots = (int)$db->get_var("select count(*) from strabosearch_spike.spots");
line('  spots loaded: ' . number_format($nSpots));

timed('rock_types (INSERT...SELECT from tag table)', function () use ($db) {
	$db->query("
		INSERT INTO strabosearch_spike.rock_types
			(spot_pkey, userpkey, ispublic, rock_type, metamorphic_facies)
		SELECT rt.spot_pkey, rt.user_pkey, coalesce(p.ispublic, false),
			rt.strabo_rock_type, nullif(rt.metamorphic_facies, '')
		FROM rock_type rt
		LEFT JOIN project p ON p.project_pkey = rt.project_pkey
	");
});
line('  rock_type rows loaded: ' . number_format((int)$db->get_var("select count(*) from strabosearch_spike.rock_types")));

// ---------------------------------------------------------------------------
section('2. Orientation flatten (PHP streaming pass over spotjson)');

function numOrNull($v) {
	if (is_int($v) || is_float($v)) return (string)(float)$v;
	if (is_string($v) && is_numeric($v)) return (string)(float)$v;
	return 'NULL';
}
function strOrNull($v) {
	if (!is_string($v) || $v === '') return 'NULL';
	return "'" . pg_escape_string(mb_substr($v, 0, 100)) . "'";
}

$BATCH = 5000;       // spots per fetch
$INSERT_CHUNK = 1000; // rows per INSERT statement
$lastPkey = 0;
$seen = 0;
$rowsOut = 0;
$buffer = array();

$flush = function () use (&$buffer, &$rowsOut, $db) {
	if (!$buffer) return;
	$db->query("INSERT INTO strabosearch_spike.orientations
		(spot_pkey, userpkey, ispublic, otype, feature_type, strike, dip, trend, plunge, quality)
		VALUES " . implode(",\n", $buffer));
	$rowsOut += count($buffer);
	$buffer = array();
};

$tFlatten = microtime(true);
while (true) {
	$rows = $db->get_results("
		select s.spot_pkey, s.user_pkey, coalesce(p.ispublic, false) as ispublic, s.spotjson
		from spot s left join project p on p.project_pkey = s.project_pkey
		where s.spot_pkey > $lastPkey and s.orientation_exists
		order by s.spot_pkey limit $BATCH");
	if (!$rows) break;
	foreach ($rows as $row) {
		$lastPkey = (int)$row->spot_pkey;
		$seen++;
		$j = json_decode($row->spotjson);
		if ($j === null || !isset($j->properties->orientation_data) || !is_array($j->properties->orientation_data)) continue;
		$pub = ($row->ispublic === 't' || $row->ispublic === true) ? 'true' : 'false';
		foreach ($j->properties->orientation_data as $el) {
			if (!is_object($el)) continue;
			$buffer[] = sprintf('(%d,%d,%s,%s,%s,%s,%s,%s,%s,%s)',
				(int)$row->spot_pkey, (int)$row->user_pkey, $pub,
				strOrNull($el->type ?? null), strOrNull($el->feature_type ?? null),
				numOrNull($el->strike ?? null), numOrNull($el->dip ?? null),
				numOrNull($el->trend ?? null), numOrNull($el->plunge ?? null),
				strOrNull($el->quality ?? null));
			if (count($buffer) >= $INSERT_CHUNK) $flush();
		}
	}
	if ($seen % 100000 < $BATCH) progress("flatten: " . number_format($seen) . " orientation spots, " . number_format($rowsOut) . " rows");
}
$flush();
line(sprintf('  %-52s %6.1fs', 'flatten (' . number_format($seen) . ' spots -> ' . number_format($rowsOut) . ' rows)', microtime(true) - $tFlatten));

// ---------------------------------------------------------------------------
section('3. Index build + ANALYZE (the index-maintenance cost)');

$indexes = array(
	"CREATE INDEX spike_spots_location ON strabosearch_spike.spots USING gist (location)",
	"CREATE INDEX spike_spots_keywords ON strabosearch_spike.spots USING gin (keywords)",
	"CREATE INDEX spike_spots_date ON strabosearch_spike.spots (date_created)",
	"CREATE INDEX spike_spots_project ON strabosearch_spike.spots (project_pkey)",
	"CREATE INDEX spike_ori_dip ON strabosearch_spike.orientations (dip)",
	"CREATE INDEX spike_ori_strike ON strabosearch_spike.orientations (strike)",
	"CREATE INDEX spike_ori_spot ON strabosearch_spike.orientations (spot_pkey)",
	"CREATE INDEX spike_rt_type ON strabosearch_spike.rock_types (rock_type text_pattern_ops)",
	"CREATE INDEX spike_rt_spot ON strabosearch_spike.rock_types (spot_pkey)",
);
foreach ($indexes as $ddl) {
	timed(preg_replace('/^CREATE INDEX (\S+).*/', '$1', $ddl), function () use ($db, $ddl) { $db->query($ddl); });
}
timed('ANALYZE', function () use ($db) {
	$db->query("ANALYZE strabosearch_spike.spots");
	$db->query("ANALYZE strabosearch_spike.orientations");
	$db->query("ANALYZE strabosearch_spike.rock_types");
});

subsection('Footprint');
$rows = $db->get_results("
	select relname, pg_size_pretty(pg_total_relation_size('strabosearch_spike.'||relname)) as sz
	from pg_class c join pg_namespace n on n.oid = c.relnamespace
	where n.nspname = 'strabosearch_spike' and c.relkind = 'r'");
foreach ($rows as $r) line(sprintf('  %-20s %s', $r->relname, $r->sz));

line();
line('Total build: ' . round(microtime(true) - $t0) . 's');
?>
