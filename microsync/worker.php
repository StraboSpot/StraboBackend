<?php
/**
 * File: worker.php
 * Description: Derived-view worker for synced StraboMicro projects (CLI only).
 *
 *              --project=N   Kicked by the API after a push, refs change, or
 *                            ready. Only one waits per project: it waits until
 *                            the project has been quiet for the quiet period
 *                            (MICROSYNC_QUIET_SECONDS, default 45), rebuilds,
 *                            and repeats while pushes keep arriving (up to 30
 *                            minutes). Extra kicks exit at once.
 *              --now         With --project: skip the quiet period (tests, manual runs).
 *              --sweep       Cron, every minute: rebuild every dirty ready
 *                            project that is quiet and not being built, then
 *                            housekeeping (stale uploads, presence).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/microsync/worker.php --sweep
 *                docker exec strabo-php php /srv/app/www/microsync/worker.php --project=123 --now
 *              Prod crontab (host). Run it as www-data, the user Apache kicks
 *              the worker as, so both can replace each other's files:
 *                * * * * * sudo docker exec -u www-data strabo-php php /srv/app/www/microsync/worker.php --sweep >> /var/log/microsync_sweep.log 2>&1
 *
 *              The sweep also renders project PDFs that are out of date
 *              (pdf_dirty) for StraboMicro2-format projects, synced or not,
 *              through the strabo-node container (microdb/lib/micro_pdf_node.php).
 *              One sweep at a time does this; JavaFX-format projects are left
 *              to the download path's tFPDF generator, as before.
 *
 *              Works whether or not MICROSYNC_ENABLED is set: builds only
 *              ever touch projects the API created.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit;
}
set_time_limit(0);
chdir(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);

include_once "../includes/config.inc.php";
include_once "../db.php";
include_once "../jwtmicrodb/strabomicroclass.php";
include_once "./lib/MsHttp.php";
include_once "./lib/MsDb.php";
include_once "./lib/MsModel.php";
include_once "./lib/MsStore.php";
include_once "./lib/MsWorker.php";
include_once "../microdb/lib/micro_pdf_node.php";

// Seconds the sweep waits for one project's PDF render.
define('MICRO_PDF_SWEEP_WAIT', 1800);

$opts = getopt('', array('project:', 'now', 'sweep'));
$db->get_var('SELECT 1');
$ms = new MsDb($db);

/** Build one project under its build lock; returns the build() result or 'busy'. */
function ms_build_locked($db, $ms, $pid) {
	if (!MsWorker::tryLock($ms, 'build', $pid)) {
		return 'busy';
	}
	try {
		$r = MsWorker::build($db, $ms, $pid);
	} catch (Throwable $e) {
		$r = get_class($e) . ': ' . $e->getMessage();
		$ms->rollback();
	}
	MsWorker::unlock($ms, 'build', $pid);
	if ($r !== 'built' && $r !== 'skipped') {
		MsWorker::log("project $pid: build failed: $r");
	}
	return $r;
}

if (isset($opts['sweep'])) {
	$quiet = MsWorker::quietSeconds();
	$built = 0;
	foreach ($ms->rows(
		"SELECT p.id FROM strabomicro.micro_projectmetadata p
		  WHERE p.sync_format = 'entity' AND p.sync_state = 'ready' AND p.views_dirty_since IS NOT NULL
		    AND COALESCE((SELECT c.at FROM strabomicro.micro_changes c WHERE c.project_id = p.id ORDER BY c.seq DESC LIMIT 1),
		                 p.views_dirty_since) < now() - make_interval(secs => $1)
		  ORDER BY p.views_dirty_since",
		array($quiet)) as $r) {
		if (ms_build_locked($db, $ms, (int)$r['id']) === 'built') {
			$built++;
		}
	}

	// Out-of-date PDFs (synced projects once their views are built). One
	// sweep renders at a time; a later sweep skips this while one runs. A
	// project whose render failed waits an hour before the sweep tries it
	// again (downloads still try at once), so a render that always fails
	// (a project too big for the container's memory, say) is not retried
	// every minute.
	$pdfs = 0;
	if (MsWorker::tryLock($ms, 'pdfsweep', 0)) {
		$failFile = MsWorker::dataDir() . '/pdf_failures.json';
		$failedAt = json_decode((string)@file_get_contents($failFile), true) ?: array();
		foreach ($ms->rows(
			"SELECT id, userpkey FROM strabomicro.micro_projectmetadata
			  WHERE pdf_dirty
			    AND (sync_format IS DISTINCT FROM 'entity' OR (sync_state = 'ready' AND views_dirty_since IS NULL))
			  ORDER BY id",
			array()) as $r) {
			$id = (int)$r['id'];
			if (isset($failedAt[$id]) && $failedAt[$id] > time() - 3600) {
				continue;
			}
			if (!micro_pdf_uses_node($db, $id)) {
				continue;
			}
			$res = micro_pdf_render_node($db, $id, (int)$r['userpkey'], MICRO_PDF_SWEEP_WAIT);
			if ($res === 'rendered') {
				$pdfs++;
				unset($failedAt[$id]);
			} elseif ($res !== 'clean') {
				$failedAt[$id] = time();
				MsWorker::log("project $id: pdf $res (sweep retries in an hour)");
			}
		}
		// Forget failures a day old (their projects may be gone or fixed).
		$failedAt = array_filter($failedAt, function ($t) { return $t > time() - 86400; });
		@file_put_contents($failFile, json_encode($failedAt));
		MsWorker::unlock($ms, 'pdfsweep', 0);
	}

	$cleaned = MsWorker::housekeeping($ms);
	if ($built > 0 || $pdfs > 0 || $cleaned > 0) {
		MsWorker::log("sweep: built $built, rendered $pdfs pdfs, removed $cleaned stale uploads");
	}
	exit(0);
}

if (!isset($opts['project']) || !preg_match('/^[1-9][0-9]{0,8}$/', (string)$opts['project'])) {
	fwrite(STDERR, "usage: worker.php --project=N [--now] | --sweep\n");
	exit(2);
}
$pid = (int)$opts['project'];

if (isset($opts['now'])) {
	$r = ms_build_locked($db, $ms, $pid);
	echo "$r\n";
	exit($r === 'built' || $r === 'skipped' ? 0 : 1);
}

// Kicked: one waiter per project; later kicks leave it to that one.
if (!MsWorker::tryLock($ms, 'wait', $pid)) {
	exit(0);
}
$quiet = MsWorker::quietSeconds();
$deadline = time() + 1800;
while (time() < $deadline) {
	$s = MsWorker::state($ms, $pid);
	if ($s === null || $s['sync_format'] !== 'entity' || $s['sync_state'] !== 'ready' || $s['dirty'] !== 't') {
		break;
	}
	$idle = (float)$s['idle'];
	if ($idle < $quiet) {
		sleep(max(1, (int)ceil($quiet - $idle)));
		continue;
	}
	$r = ms_build_locked($db, $ms, $pid);
	if ($r === 'busy') {
		sleep(5);
	} elseif ($r !== 'built') {
		break; // failed or not buildable: the cron sweep retries
	}
}
MsWorker::unlock($ms, 'wait', $pid);
