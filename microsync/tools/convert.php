<?php
/**
 * File: convert.php
 * Description: Rollout step 4: convert stored legacy StraboMicro projects
 *              into the sync store (microsync/lib/MsConvert.php, design
 *              §4.8 in the StraboMicro2 repo's collaboration-phase0-design.md).
 *
 *              Default is a DRY RUN: every project is read and checked
 *              (stream vs project.zip, push rules, round trip inside a
 *              transaction that is rolled back). Nothing is kept.
 *
 *              Usage (as www-data inside strabo-php):
 *                php microsync/tools/convert.php [--apply] [--only=12,34] [--limit=N]
 *              --revert --only=<ids>: back to legacy from the archived zip,
 *              only while nobody changed the project since its conversion.
 *              --restore-pdf --only=<ids>: put the app's project.pdf back from
 *              the archive (projects converted before builds kept it).
 *              --json-only: dry run of the JSON side alone (normalize, push
 *              rules, round trip), for dev where most folders are missing.
 *              Report: microsync_data/convert/report-<mode>-<time>.json
 *              (small; the prod root disk is tight, nothing large goes there).
 *
 *              Works whether or not MICROSYNC_ENABLED is set: converted
 *              projects are served by the streamed .smz and the worker,
 *              neither of which needs the API.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit();
}
set_time_limit(0);
chdir(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);

include_once "../../includes/config.inc.php";
include_once "../../db.php";
include_once "../../jwtmicrodb/strabomicroclass.php";
include_once "../lib/MsConvert.php";

$apply = false;
$revert = false;
$restorePdf = false;
$jsonOnly = false;
$only = null;
$limit = 0;
foreach (array_slice($argv, 1) as $a) {
	if ($a === '--apply') {
		$apply = true;
	} elseif ($a === '--dry-run') {
		$apply = false;
	} elseif ($a === '--revert') {
		$revert = true;
	} elseif ($a === '--restore-pdf') {
		$restorePdf = true;
	} elseif ($a === '--json-only') {
		$jsonOnly = true;
	} elseif (preg_match('/^--only=([0-9,]+)$/', $a, $m)) {
		$only = array_map('intval', array_filter(explode(',', $m[1]), 'strlen'));
	} elseif (preg_match('/^--limit=([0-9]+)$/', $a, $m)) {
		$limit = (int)$m[1];
	} else {
		fwrite(STDERR, "usage: php convert.php [--apply | --json-only] [--only=12,34] [--limit=N]\n");
		exit(2);
	}
}
if ($revert && ($apply || $jsonOnly || $only === null)) {
	fwrite(STDERR, "--revert needs --only=<ids> and nothing else\n");
	exit(2);
}
if ($apply && $jsonOnly) {
	fwrite(STDERR, "--json-only is a dry run (no files are checked)\n");
	exit(2);
}

$ms = new MsDb($db);
if ($ms->val("SELECT pg_try_advisory_lock(hashtext('microsync-convert'), 0)") !== 't') {
	fwrite(STDERR, "another conversion is running\n");
	exit(1);
}

if ($restorePdf) {
	if ($only === null || $apply || $revert || $jsonOnly) {
		fwrite(STDERR, "--restore-pdf needs --only=<ids> and nothing else\n");
		exit(2);
	}
	$conv = new MsConvert($db);
	$fail = 0;
	foreach ($only as $pid) {
		$why = $conv->restorePdf($pid);
		echo "#$pid " . ($why === null ? "app PDF restored from the archive" : "NOT restored: $why") . "\n";
		$fail += $why === null ? 0 : 1;
	}
	$ms->q("SELECT pg_advisory_unlock(hashtext('microsync-convert'), 0)");
	exit($fail ? 1 : 0);
}
if ($revert) {
	$conv = new MsConvert($db, function ($m) { echo "$m\n"; });
	$fail = 0;
	foreach ($only as $pid) {
		$why = $conv->revert($pid);
		echo "#$pid " . ($why === null ? 'reverted to legacy' : "NOT reverted: $why") . "\n";
		$fail += $why === null ? 0 : 1;
	}
	$ms->q("SELECT pg_advisory_unlock(hashtext('microsync-convert'), 0)");
	exit($fail ? 1 : 0);
}

$mode = $apply ? 'apply' : ($jsonOnly ? 'json-only' : 'dry-run');
$t0 = microtime(true);
$say = function ($msg) use ($t0) {
	printf("[%7.1f s] %s\n", microtime(true) - $t0, $msg);
};
$say("conversion $mode" . ($only !== null ? ' --only=' . implode(',', $only) : '') . ($limit ? " --limit=$limit" : ''));

if ($only !== null) {
	$ids = $only;
} else {
	$ids = array_map(function ($r) { return (int)$r['id']; }, $ms->rows(
		"SELECT id FROM strabomicro.micro_projectmetadata WHERE sync_format = 'legacy' ORDER BY id"));
}
if ($limit > 0) {
	$ids = array_slice($ids, 0, $limit);
}

// Folders with no row: listed for the record, never touched.
$orphans = array();
if ($only === null) {
	$rows = array_flip(array_map(function ($r) { return (int)$r['id']; },
		$ms->rows("SELECT id FROM strabomicro.micro_projectmetadata")));
	foreach ((array)@scandir(MsStore::filesRoot()) as $f) {
		if (preg_match('/^[0-9]+$/', $f) && is_dir(MsStore::filesRoot() . "/$f") && !isset($rows[(int)$f])) {
			$orphans[] = (int)$f;
		}
	}
	sort($orphans);
}

$conv = new MsConvert($db, $say);
$conv->jsonOnly = $jsonOnly;
$reports = array();
$counts = array();
foreach ($ids as $i => $pid) {
	$r = $conv->convert($pid, $apply);
	$reports[] = $r;
	$key = $r['status'] . ($r['reason'] !== null ? ':' . $r['reason'] : '');
	$counts[$key] = (isset($counts[$key]) ? $counts[$key] : 0) + 1;
	$line = sprintf('%d/%d  #%d  %s', $i + 1, count($ids), $pid, strtoupper($r['status']));
	if ($r['reason'] !== null) {
		$line .= " ({$r['reason']}: {$r['message']})";
	}
	if (isset($r['stats']['entities'])) {
		$line .= sprintf('  %d entities, %d files', $r['stats']['entities'], isset($r['stats']['files']) ? $r['stats']['files'] : 0);
	}
	$line .= sprintf('  %.1f s', $r['stats']['seconds']);
	$say($line);
	foreach ($r['warnings'] as $w) {
		$say("      warning: $w");
	}
}

ksort($counts);
$say('summary:');
foreach ($counts as $k => $n) {
	$say(sprintf('  %4d  %s', $n, $k));
}
if ($orphans) {
	$say('  folders with no project row (left alone): ' . implode(' ', $orphans));
}

$dir = MsWorker::dataDir() . '/convert';
@mkdir($dir, 0775, true);
$file = "$dir/report-$mode-" . gmdate('Ymd\THis\Z') . '.json';
file_put_contents($file, json_encode(array(
	'mode' => $mode, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'seconds' => round(microtime(true) - $t0, 1),
	'counts' => $counts, 'orphanFolders' => $orphans, 'projects' => $reports,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$say("report: $file");
$ms->q("SELECT pg_advisory_unlock(hashtext('microsync-convert'), 0)");
