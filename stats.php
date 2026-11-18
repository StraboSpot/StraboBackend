<?php
/**
 * File: stats.php
 * Description: StraboSpot Mission Control, the admin statistics dashboard.
 *
 * The page never queries the holdings itself: it shows the newest row of
 * stats_snapshots, built by scripts/stats/build_stats.php (host cron on prod,
 * or the Refresh button, which starts the same script in the background).
 *
 *   GET  /stats                 the dashboard
 *   GET  /stats?status=1        {pkey, builtAt, building} for the refresh poll
 *   POST /stats  action=refresh start a build (token required)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");

include("prepare_connections.php");

$admin_pkeys = array(3,9,7217);
if(!in_array($userpkey, $admin_pkeys)) {
	echo "unauthorized";
	exit();
}

if (empty($_SESSION['stats_token'])) {
	$_SESSION['stats_token'] = bin2hex(random_bytes(16));
}

$db->get_var("SELECT 1");
$pg = $db->dbh;

function stats_building($pg) {
	// The builder holds this advisory lock while it runs.
	$r = @pg_query($pg, "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND granted
		AND objid = (hashtext('strabo_stats_build')::bigint & 4294967295)::oid");
	$row = $r ? pg_fetch_row($r) : array(0);
	return ((int) $row[0]) > 0;
}

function stats_latest_meta($pg) {
	$r = @pg_query($pg, "SELECT pkey, built_at FROM stats_snapshots ORDER BY built_at DESC LIMIT 1");
	$row = $r ? pg_fetch_assoc($r) : false;
	return $row ? array('pkey' => (int) $row['pkey'], 'builtAt' => $row['built_at']) : array('pkey' => 0, 'builtAt' => null);
}

if (isset($_GET['status'])) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(stats_latest_meta($pg) + array('building' => stats_building($pg)));
	exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh') {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	if (!hash_equals($_SESSION['stats_token'], (string) ($_POST['token'] ?? ''))) {
		http_response_code(403);
		echo json_encode(array('ok' => false, 'message' => 'bad token'));
		exit();
	}
	if (stats_building($pg)) {
		echo json_encode(array('ok' => true, 'message' => 'already building'));
		exit();
	}
	$script = escapeshellarg(__DIR__ . '/scripts/stats/build_stats.php');
	exec("nohup php $script > /tmp/strabo_stats_build.log 2>&1 &");
	echo json_encode(array('ok' => true, 'message' => 'started'));
	exit();
}

$r = @pg_query($pg, "SELECT pkey, data FROM stats_snapshots ORDER BY built_at DESC LIMIT 1");
$row = $r ? pg_fetch_assoc($r) : false;
$snapshotJson = $row ? $row['data'] : 'null';
$snapshotPkey = $row ? (int) $row['pkey'] : 0;
$jsVersion = @filemtime(__DIR__ . '/assets/js/stats/stats.js');
$cssVersion = @filemtime(__DIR__ . '/assets/js/stats/stats.css');

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>StraboSpot Mission Control</title>
<link rel="icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/js/stats/stats.css?v=<?php echo (int) $cssVersion; ?>">
</head>
<body>
<header class="topbar">
	<a class="brand" href="/" title="Back to strabospot.org">
		<span class="brand-mark" aria-hidden="true"></span>
		<span class="brand-text">STRABOSPOT <span class="brand-sep">//</span> MISSION CONTROL</span>
	</a>
	<div class="topbar-status">
		<span class="status-dot" id="statusDot" aria-hidden="true"></span>
		<span id="statusText">LOADING</span>
	</div>
	<div class="topbar-clocks">
		<div><span class="clock-label">UTC</span> <span id="clockUtc">--:--:--</span></div>
		<div><span class="clock-label">LAWRENCE</span> <span id="clockLocal">--:--:--</span></div>
	</div>
	<div class="topbar-actions">
		<span class="snapshot-age" id="snapshotAge"></span>
		<button type="button" class="btn" id="refreshBtn">Refresh now</button>
		<a class="btn btn-ghost" href="/">Back to site</a>
	</div>
</header>

<main id="app" class="grid" aria-live="polite"></main>

<noscript><p class="noscript">This dashboard needs JavaScript.</p></noscript>

<script id="snapshot" type="application/json"><?php
	echo str_replace(array('<', '>', '&'), array('<', '>', '&'), $snapshotJson);
?></script>
<script>
window.STATS_CONFIG = {
	pkey: <?php echo $snapshotPkey; ?>,
	token: <?php echo json_encode($_SESSION['stats_token']); ?>
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.9.0/d3.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/topojson/3.0.2/topojson.min.js"></script>
<script src="/assets/js/stats/stats.js?v=<?php echo (int) $jsVersion; ?>"></script>
</body>
</html>
