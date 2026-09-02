<?php
/**
 * File: exportjobs/worker.php
 * Description: Export job worker (design §6). CLI only.
 *
 *   php exportjobs/worker.php --job=<uuid>   run one specific queued job
 *                                            (kicked by the create endpoint)
 *   php exportjobs/worker.php --sweep        cron mode: recover crashed runs,
 *                                            retention + caps, then run queued
 *                                            jobs up to the concurrency cap
 *   php exportjobs/worker.php --retention-only
 *
 *   Prod host crontab (install by hand, like the search heal):
 *   * * * * * sudo docker exec -u www-data strabo-php php /srv/app/www/exportjobs/worker.php --sweep >> /var/log/strabo_exportjobs.log 2>&1
 *
 * Guards before any claim: free disk above min_free_bytes (else the job is
 * marked "waiting for disk space" and left queued), running-with-fresh-
 * heartbeat count below max_concurrent (else left queued for the sweeper).
 * Exit 0 = ok (including "nothing to do"), 2 = an error outside a job.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit("CLI only.\n");
}

chdir(__DIR__ . '/..');
// Legacy generators and their libraries resolve includes through the web
// server's document root (e.g. includes/PDF_LabBook.php); the CLI has none.
if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = getcwd();
ini_set('memory_limit', '4G');
ini_set('max_execution_time', 0);

// Crash containment (2026-09-01: a recoverable fatal inside PHPExcel killed
// the worker mid-job and the row sat in "running" until the stale sweep).
// 1) Recoverable / user errors become exceptions so ExportRunner's catch
//    marks the job failed with the real message, instantly.
// 2) A true fatal (memory, parse, timeout) still ends the process, so the
//    shutdown hook fails the job this process had claimed.
$EJ_CURRENT = null;      // {pkey, uuid} of the job claimed by this process
set_error_handler(function ($no, $str, $file, $line) {
	if ($no === E_RECOVERABLE_ERROR || $no === E_USER_ERROR) {
		throw new ErrorException($str, 0, $no, $file, $line);
	}
	return false;          // everything else: PHP's normal handling (legacy generators are noisy)
});
register_shutdown_function(function () {
	global $EJ_CURRENT, $svc;
	$e = error_get_last();
	if (!$EJ_CURRENT || !$e || !in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) return;
	try {
		$row = $svc->getByPkey($EJ_CURRENT['pkey']);
		if ($row && $row['status'] === 'running' && (int)$row['worker_pid'] === getmypid()) {
			$msg = 'Internal error: ' . trim(preg_replace('/\s+/', ' ', $e['message'])) . ' (' . basename($e['file']) . ':' . $e['line'] . ')';
			$svc->fail($EJ_CURRENT['pkey'], $msg);
			$cfgS = export_config();
			@file_put_contents(rtrim($cfgS['log_root'], '/') . '/worker.log',
				'[' . date('Y-m-d H:i:s') . "] job {$EJ_CURRENT['uuid']}: FAILED (fatal) $msg\n", FILE_APPEND);
			$wd = rtrim($cfgS['work_root'], '/') . '/' . $EJ_CURRENT['uuid'];
			if (is_dir($wd) && strlen($EJ_CURRENT['uuid']) === 36) @exec('rm -rf ' . escapeshellarg($wd));
		}
	} catch (Exception $x) {
	}
});
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once __DIR__ . '/lib/export_config.php';
require_once __DIR__ . '/lib/ExportJobService.php';
require_once __DIR__ . '/lib/ExportRunner.php';
require_once __DIR__ . '/lib/ExportMailer.php';
require_once __DIR__ . '/plugins/EchoExportPlugin.php';
require_once __DIR__ . '/plugins/FieldExportPlugin.php';

$MODE = null; $JOB = null;
foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--sweep')          $MODE = 'sweep';
	if ($arg === '--retention-only') $MODE = 'retention';
	if (preg_match('/^--job=([0-9a-fA-F-]{36})$/', $arg, $m)) { $MODE = 'job'; $JOB = strtolower($m[1]); }
	if ($arg === '--help' || $arg === '-h') $MODE = 'help';
}
if ($MODE === null || $MODE === 'help') {
	echo "Usage: php exportjobs/worker.php --job=<uuid> | --sweep | --retention-only\n";
	exit($MODE === 'help' ? 0 : 2);
}

$cfg = export_config();
$svc = new ExportJobService($db, $cfg);
// Kicked runs have stdout redirected INTO worker.log (so PHP's own fatal
// text lands there too); echoing our lines as well doubled every entry.
// Echo unless stdout IS the log file.
$EJ_LOGFILE = rtrim($cfg['log_root'], '/') . '/worker.log';
$EJ_ECHO = true;
if (defined('STDOUT') && is_file($EJ_LOGFILE)) {
	$so = @fstat(STDOUT); $lf = @stat($EJ_LOGFILE);
	if ($so && $lf && $so['dev'] === $lf['dev'] && $so['ino'] === $lf['ino']) $EJ_ECHO = false;
}
$log = function ($m) use ($EJ_LOGFILE, $EJ_ECHO) {
	$line = '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
	if ($EJ_ECHO) echo $line;
	@file_put_contents($EJ_LOGFILE, $line, FILE_APPEND);
};
$plugins = array(new EchoExportPlugin(), new FieldExportPlugin($db, $neodb, $cfg));
$mailer = new ExportMailer($db, $cfg, $log);
$runner = new ExportRunner($svc, $plugins, $log, function ($row) use ($mailer) { $mailer->notify($row); });

foreach (array($cfg['results_root'], $cfg['work_root'], $cfg['log_root']) as $d) {
	if (!is_dir($d)) @mkdir($d, 0775, true);
}

function ej_disk_ok(array $cfg)
{
	$free = @disk_free_space($cfg['results_root']);
	return ($free === false) ? true : ($free >= (int)$cfg['min_free_bytes']);
}

/** Try to run one job now. Returns 'ran' | 'skipped' | 'none'. */
function ej_try_run(ExportJobService $svc, ExportRunner $runner, array $cfg, $uuid, $log)
{
	if ($svc->countActive() >= (int)$cfg['max_concurrent']) {
		$log('cap reached (' . $cfg['max_concurrent'] . ' running); leaving ' . ($uuid ? $uuid : 'queue') . ' for the sweeper');
		return 'skipped';
	}
	if (!ej_disk_ok($cfg)) {
		$log('low disk space; not starting ' . ($uuid ? $uuid : 'any job'));
		if ($uuid) { $row = $svc->get($uuid); if ($row) $svc->markWaitingForDisk($row['pkey']); }
		else {
			foreach ($svc->listQueuedPkeys() as $pk) $svc->markWaitingForDisk($pk);
		}
		return 'skipped';
	}
	$job = $svc->claim($uuid, getmypid());
	if (!$job) return 'none';
	global $EJ_CURRENT;
	$EJ_CURRENT = array('pkey' => $job['pkey'], 'uuid' => $job['uuid']);
	$runner->run($job);
	$EJ_CURRENT = null;
	return 'ran';
}

try {
	if ($MODE === 'job') {
		ej_try_run($svc, $runner, $cfg, $JOB, $log);
		exit(0);
	}

	// sweep / retention
	$r = $svc->requeueStale();
	foreach ($r['requeued'] as $u) $log("stale run re-queued: $u");
	foreach ($r['failed'] as $u)   $log("stale run failed for good: $u");
	foreach ($svc->failDiskWaiters() as $j) $log("disk-wait timeout, failed: {$j['uuid']}");
	foreach ($svc->expireDue() as $u)       $log("expired (retention): $u");
	foreach ($svc->enforceUserCaps() as $u) $log("expired (user cap): $u");

	if ($MODE === 'sweep') {
		$n = 0;
		while (true) {
			$res = ej_try_run($svc, $runner, $cfg, null, $log);
			if ($res !== 'ran') break;
			$n++;
		}
		if ($n) $log("sweep ran $n job(s)");
	}
	exit(0);
} catch (Exception $e) {
	$log('worker error: ' . $e->getMessage());
	exit(2);
}
