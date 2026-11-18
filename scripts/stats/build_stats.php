<?php
/**
 * File: scripts/stats/build_stats.php
 * Description: Builds one /stats snapshot and stores it in stats_snapshots.
 *
 * USAGE (CLI only):
 *   php scripts/stats/build_stats.php            build and store
 *   php scripts/stats/build_stats.php --print    build and print the JSON, store nothing
 *   prod: sudo docker exec strabo-php php /srv/app/www/scripts/stats/build_stats.php
 *
 * Only one build runs at a time (pg advisory lock); a second one exits with
 * "already running". Keeps the newest 400 snapshots (about 100 days at 4 a day).
 *
 * @package    StraboSpot Web Site
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit("CLI only.\n");
}

$root = dirname(__DIR__, 2);
chdir($root);
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? $root;
require_once($root . '/includes/config.inc.php');
require_once($root . '/db.php');
require_once($root . '/neodb.php');
require_once($root . '/includes/stats/StatsBuilder.php');

$print = in_array('--print', $argv, true);
set_time_limit(0);
ini_set('memory_limit', '1024M');

$db->get_var("SELECT 1");
$pg = $db->dbh;
$got = pg_fetch_row(pg_query($pg, "SELECT pg_try_advisory_lock(hashtext('strabo_stats_build'))"));
if ($got[0] !== 't') {
	fwrite(STDERR, "already running\n");
	exit(2);
}

$builder = new StatsBuilder($db, $neodb);
$data = $builder->build();
$json = json_encode($data, JSON_UNESCAPED_SLASHES);

if ($print) {
	echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
	$r = pg_query_params($pg,
		"INSERT INTO stats_snapshots (build_seconds, error_count, data) VALUES ($1, $2, $3::jsonb) RETURNING pkey",
		array($data['buildSeconds'], count($data['errors']), $json));
	if ($r === false) {
		fwrite(STDERR, "store failed: " . pg_last_error($pg) . "\n");
		exit(1);
	}
	$pkey = pg_fetch_row($r)[0];
	pg_query($pg, "DELETE FROM stats_snapshots WHERE pkey NOT IN (SELECT pkey FROM stats_snapshots ORDER BY built_at DESC LIMIT 400)");
	echo "stored snapshot $pkey in {$data['buildSeconds']} s, " . strlen($json) . " bytes, " . count($data['errors']) . " section errors\n";
}
foreach ($data['errors'] as $e) {
	fwrite(STDERR, "ERROR {$e['section']}: {$e['message']}\n");
}
pg_query($pg, "SELECT pg_advisory_unlock(hashtext('strabo_stats_build'))");
exit(count($data['errors']) ? 1 : 0);
