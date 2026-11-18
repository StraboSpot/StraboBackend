<?php
/**
 * File: tests/stats/stats_check.php
 * Description: Proves the newest /stats snapshot against independent queries.
 *
 * Each headline number is recomputed with a differently written query, and the
 * snapshot's own parts are checked to add up (daily series + undated = total,
 * map cells = located spots, rose bins = measurements, ...). Read-only.
 *
 * USAGE: php tests/stats/stats_check.php   (prod: sudo docker exec strabo-php php /srv/app/www/tests/stats/stats_check.php)
 * Run it right after a build: records added in between show up as small FAILs.
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? $root;
require_once($root . '/includes/config.inc.php');
require_once($root . '/db.php');
require_once($root . '/neodb.php');
require_once($root . '/microdb/lib/sync_guard.php');

$db->get_var("SELECT 1");
$pg = $db->dbh;
$row = pg_fetch_assoc(pg_query($pg, "SELECT pkey, built_at, data FROM stats_snapshots ORDER BY built_at DESC LIMIT 1"));
if (!$row) { echo "FAIL no snapshot\n"; exit(1); }
$S = json_decode($row['data'], true);
echo "Snapshot {$row['pkey']} built {$row['built_at']}\n";

$pass = 0; $fail = 0;
function check($name, $got, $want) {
	global $pass, $fail;
	$ok = ((string) $got === (string) $want);
	$ok ? $pass++ : $fail++;
	printf("%s  %-58s snapshot=%s check=%s\n", $ok ? 'PASS' : 'FAIL', $name, $got, $want);
}
function pv($sql) { global $pg; $r = pg_query($pg, $sql); if (!$r) { return 'ERR ' . pg_last_error($pg); } return pg_fetch_row($r)[0]; }
function nv($cy) { global $neodb; $r = $neodb->query($cy); $rec = $r[0]; return $rec->value($rec->keys()[0]); }

// --- accounts
check('confirmed accounts', $S['accounts']['confirmed'],
	pv("SELECT count(*) FROM users WHERE active = true AND coalesce(deleted, false) = false"));
check('unconfirmed + confirmed + deleted = all sign-ups',
	$S['accounts']['confirmed'] + $S['accounts']['unconfirmed'] + $S['accounts']['deleted'], pv("SELECT count(*) FROM users"));
check('daily sign-ups + undated = confirmed',
	array_sum($S['accounts']['dailyConfirmedSignups']) + $S['accounts']['confirmedWithoutSignupDate'], $S['accounts']['confirmed']);

// --- field (Neo4j, written with WITH DISTINCT instead of count(DISTINCT))
check('distinct spots', $S['field']['spots'], nv("MATCH (s:Spot) WITH DISTINCT s.id AS id RETURN count(id)"));
check('distinct projects', $S['field']['projects'], nv("MATCH (p:Project) WITH DISTINCT p.id AS id RETURN count(id)"));
check('distinct datasets', $S['field']['datasets'], nv("MATCH (d:Dataset) WITH DISTINCT d.id AS id RETURN count(id)"));
check('distinct images', $S['field']['images'], nv("MATCH (i:Image) WITH DISTINCT i.id AS id RETURN count(id)"));
check('distinct samples', $S['field']['samples'], nv("MATCH (x:Sample) WITH DISTINCT x.id AS id RETURN count(id)"));
check('public projects', $S['field']['publicProjects'],
	nv("MATCH (p:Project) WHERE p.public = true OR p.public = 1 OR p.public = '1' OR p.public = 'true' WITH DISTINCT p.id AS id RETURN count(id)"));
check('daily spots + undated = distinct spots',
	array_sum($S['fieldSeries']['spotsDaily']) + $S['fieldSeries']['undatedSpots'], $S['field']['spots']);
check('owners with spots >= contributors with projects',
	$S['contributors']['usersWithAnySpot'] >= $S['field']['usersWithSpots'] ? 'yes' : 'no', 'yes');
$binSum = 0; foreach ($S['contributors']['bins'] as $b) { $binSum += $b[1]; }
check('contributor bins add up', $binSum, $S['contributors']['usersWithAnySpot']);
$top = $S['contributors']['topUsers'][0];
check('top collector spot count', $top['spots'], nv("MATCH (s:Spot) WHERE s.userpkey = {$top['userpkey']} WITH DISTINCT s.id AS id RETURN count(id)"));

// --- search index (independent: GROUP BY instead of DISTINCT ON)
// newest indexed copy per spot (the builder's rule), written with row_number() instead of DISTINCT ON
$idx = "(SELECT * FROM (SELECT item_id, cardinality(orientation_planar) AS o,
	(location IS NOT NULL AND st_x(location) BETWEEN -180 AND 180 AND st_y(location) BETWEEN -90 AND 90) AS located,
	row_number() OVER (PARTITION BY item_id ORDER BY last_synced DESC NULLS LAST, item_hit_pkey DESC) AS rn
	FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND item_type = 'spot') y WHERE rn = 1) z";
check('indexed spots', $S['searchIndex']['spots'], pv("SELECT count(*) FROM $idx"));
check('orientations', $S['searchIndex']['orientations'], pv("SELECT coalesce(sum(o), 0) FROM $idx"));
check('located spots', $S['searchIndex']['located'], pv("SELECT count(*) FILTER (WHERE located) FROM $idx"));
$cellSum = 0; foreach ($S['searchIndex']['mapCells'] as $c) { $cellSum += $c[2]; }
check('map cells add up to located spots', $cellSum, $S['searchIndex']['located']);
check('hemispheres add up to located spots', $S['searchIndex']['hemisphere']['north'] + $S['searchIndex']['hemisphere']['south'], $S['searchIndex']['located']);
check('strike bins = planar strikes', array_sum($S['searchIndex']['strikeBins']), $S['searchIndex']['planarWithStrike']);
check('trend bins = linear trends', array_sum($S['searchIndex']['trendBins']), $S['searchIndex']['linearWithTrend']);
$tp = $S['searchIndex']['topProjects'][0];
check('biggest project spot count', $tp['spots'],
	pv("SELECT count(DISTINCT item_id) FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND item_type = 'spot' AND project_id = '" . pg_escape_string($pg, $tp['projectId']) . "'"));

// --- other apps
check('micro projects (My Micro Data rule)', $S['micro']['projects'], pv("SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE " . micro_sync_visible_sql()));
check('micrographs', $S['micro']['micrographs'],
	pv("SELECT count(*) FROM (SELECT DISTINCT item_id FROM strabosearch.item_hit WHERE project_subsystem = 'micro' AND item_type = 'micrograph') z"));
check('experimental projects', $S['experimental']['projects'], pv("SELECT count(pkey) FROM straboexp.project"));
check('samples', $S['samples']['samples'], pv("SELECT count(id) FROM strabosamples.samples"));
check('IGSNs', $S['samples']['igsns'], pv("SELECT count(*) FROM (SELECT DISTINCT igsn FROM strabosamples.samples WHERE coalesce(igsn, '') <> '') z"));
$sc = 0; foreach ($S['samples']['mapCells'] as $c) { $sc += $c[2]; }
check('sample map cells = located samples', $sc, $S['samples']['located']);
check('DOIs', $S['platform']['dois'], pv("SELECT count(pkey) FROM dois"));

echo "\n$pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
