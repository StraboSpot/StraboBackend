<?php
/**
 * File: tests/exportjobs/smoke_test_jobs.php
 * Description: M1 smoke suite for the export job system
 *              (docs/ExportBuilder_Design.md §12 M1). Exercises the queue
 *              through the REAL worker binary (spawned as separate
 *              processes) plus the service API: create + per-user queue
 *              limit, atomic claim under two concurrent workers, full
 *              echo run (row, zip, README, manifest, workspace cleanup,
 *              expiry), progress writes, failure path, cancel, re-run,
 *              clear, stale-run recovery (re-queue then fail), disk guard
 *              (wait then timeout), concurrency cap, retention expiry,
 *              per-user cap, Apache denial of the results/work/log dirs.
 *
 * Run (inside the container; needs the DDL applied):
 *   docker exec strabo-php php /srv/app/www/tests/exportjobs/smoke_test_jobs.php
 *
 * Fixture user 94540 (no real account needed). Zero residue: every row and
 * file for that user is removed at the end (and at the start, defensively).
 */

chdir('/srv/app/www');
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'exportjobs/lib/export_config.php';
require_once 'exportjobs/lib/ExportJobService.php';

$UPK = 94540;
$WORKER = 'php /srv/app/www/exportjobs/worker.php';
$cfg = export_config();
$svc = new ExportJobService($db);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else       { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function q($sql, $params = array()) { global $db; return $db->get_row_prepared($sql, $params); }
function qv($sql, $params = array()) { global $db; return $db->get_var_prepared($sql, $params); }
function x($sql, $params = array()) { global $db; return $db->prepare_query($sql, $params); }
function run_worker($args, $envOverride = null) {
	global $WORKER;
	$env = $envOverride === null ? '' : 'EXPORTJOBS_CONFIG_JSON=' . escapeshellarg(json_encode($envOverride)) . ' ';
	exec($env . $WORKER . ' ' . $args . ' 2>&1', $out, $rc);
	return array($rc, implode("\n", $out));
}
function cleanup($UPK, $cfg) {
	x("DELETE FROM export_jobs WHERE userpkey = $1", array($UPK));
	$dir = rtrim($cfg['results_root'], '/') . "/$UPK";
	if (is_dir($dir)) { foreach (glob("$dir/*") as $f) @unlink($f); @rmdir($dir); }
	foreach (glob(rtrim($cfg['work_root'], '/') . '/*', GLOB_ONLYDIR) as $d) {
		// only our own stray workspaces: match by job uuid ownership is gone after DELETE, so
		// remove any workspace dir older than 1 minute (tests never leave one intentionally)
		if (filemtime($d) < time() - 60) { exec('rm -rf ' . escapeshellarg($d)); }
	}
}
function result_abs($row, $cfg) { return rtrim($cfg['results_root'], '/') . '/' . $row['result_path']; }

echo "Export jobs M1 smoke suite\n";
cleanup($UPK, $cfg);

// ---------------------------------------------------------------- 0. DDL
check('export_jobs table present', qv("SELECT to_regclass('public.export_jobs')::text") === 'export_jobs');

// ---------------------------------------------------------------- 1. create
$j1 = $svc->create($UPK, array('plugin' => 'echo', 'echo_files' => 2, 'echo_bytes' => 512, 'v' => 1),
	array('summary' => 'suite job 1', 'origin' => 'test'));
check('create returns queued row with uuid', $j1 && $j1['status'] === 'queued' && UUID::is_valid($j1['uuid']));
check('recipe round-trips as array', is_array($j1['recipe']) && $j1['recipe']['echo_files'] === 2);
check('create rejects missing plugin', (function () use ($svc, $UPK) {
	try { $svc->create($UPK, array('v' => 1)); return false; } catch (ExportJobError $e) { return true; } })());
check('create rejects anonymous', (function () use ($svc) {
	try { $svc->create(0, array('plugin' => 'echo')); return false; } catch (ExportJobError $e) { return true; } })());

// per-user queue limit
$limitSvc = new ExportJobService($db, array_merge($cfg, array('max_queued_per_user' => 1)));
check('per-user queue limit enforced', (function () use ($limitSvc, $UPK) {
	try { $limitSvc->create($UPK, array('plugin' => 'echo')); return false; } catch (ExportJobError $e) { return strpos($e->getMessage(), 'queued or running') !== false; } })());

// ---------------------------------------------------------------- 2. claim atomicity (two real workers, same uuid)
$j2 = $svc->create($UPK, array('plugin' => 'echo', 'echo_files' => 3, 'echo_bytes' => 100, 'echo_sleep_ms' => 300),
	array('summary' => 'suite job 2 (race)', 'origin' => 'test'));
$cmd = 'sh -c ' . escapeshellarg(
	"$WORKER --job={$j2['uuid']} > /tmp/ej_a.log 2>&1 & $WORKER --job={$j2['uuid']} > /tmp/ej_b.log 2>&1 & wait");
exec($cmd);
$r2 = $svc->get($j2['uuid']);
$starts = substr_count(@file_get_contents('/tmp/ej_a.log') . @file_get_contents('/tmp/ej_b.log'), ': start (');
check('two concurrent workers: exactly one claims', $starts === 1, "starts=$starts");
check('race job finished once (attempt=1, done)', $r2 && $r2['status'] === 'done' && $r2['attempt'] === 1,
	($r2 ? $r2['status'] . '/' . $r2['attempt'] : 'null'));

// ---------------------------------------------------------------- 3. full run of job 1 (kicked mode)
list($rc, $out) = run_worker("--job={$j1['uuid']}");
$r1 = $svc->get($j1['uuid']);
check('worker exit 0', $rc === 0, "rc=$rc");
check('job 1 done', $r1['status'] === 'done' && $r1['phase'] === 'done', $r1['status']);
check('item_count from plugin', $r1['item_count'] === 2);
check('result_path is <userpkey>/<uuid>.zip', $r1['result_path'] === "$UPK/{$j1['uuid']}.zip", $r1['result_path']);
$abs = result_abs($r1, $cfg);
check('result zip exists', is_file($abs));
check('result_bytes matches file', $r1['result_bytes'] === filesize($abs));
check('sha256 matches file', $r1['result_sha256'] === hash_file('sha256', $abs));
$ttl = qv("SELECT extract(epoch from (expires_at - finished_at)) FROM export_jobs WHERE pkey = $1", array($r1['pkey']));
check('expires_at = finished_at + retention_days', abs((int)$ttl - $cfg['retention_days'] * 86400) < 5, "ttl=$ttl");
exec('unzip -l ' . escapeshellarg($abs), $zl); $zl = implode("\n", $zl);
check('zip holds README, manifest, recipe, payload',
	strpos($zl, 'README.txt') !== false && strpos($zl, 'manifest.json') !== false
	&& strpos($zl, 'recipe.json') !== false && strpos($zl, 'echo_2.txt') !== false);
exec('unzip -p ' . escapeshellarg($abs) . ' README.txt', $rl); $readme = implode("\n", $rl);
check('README carries summary + export id', strpos($readme, 'suite job 1') !== false && strpos($readme, $j1['uuid']) !== false);
exec('unzip -p ' . escapeshellarg($abs) . ' manifest.json', $ml); $man = json_decode(implode("\n", $ml), true);
check('manifest lists files with bytes', is_array($man) && count($man["files"]) === 3 && $man["item_count"] === 2);
check('workspace removed after run', !is_dir(rtrim($cfg['work_root'], '/') . '/' . $j1['uuid']));
check('worker log written', is_file(rtrim($cfg['log_root'], '/') . '/worker.log'));

// ---------------------------------------------------------------- 4. progress writes observed mid-run
$j3 = $svc->create($UPK, array('plugin' => 'echo', 'echo_files' => 6, 'echo_bytes' => 10, 'echo_sleep_ms' => 250),
	array('summary' => 'suite job 3 (progress)', 'origin' => 'test'));
exec("sh -c " . escapeshellarg("$WORKER --job={$j3['uuid']} > /tmp/ej_c.log 2>&1 &"));
$seen = array(); $t0 = microtime(true);
while (microtime(true) - $t0 < 8) {
	usleep(120000);
	$p = q("SELECT status, phase, progress_done, progress_total FROM export_jobs WHERE pkey = $1", array($j3['pkey']));
	if ($p && $p->phase) $seen[$p->phase . ':' . $p->progress_done] = true;
	if ($p && $p->status !== 'running' && $p->status !== 'queued') break;
}
$r3 = $svc->get($j3['uuid']);
$midway = false; foreach (array_keys($seen) as $k) if (preg_match('/^format:echo:[1-5]$/', $k)) $midway = true;
check('progress phases visible while running', $midway, implode(',', array_keys($seen)));
check('progress job finished done', $r3['status'] === 'done');

// ---------------------------------------------------------------- 5. failure path
$j4 = $svc->create($UPK, array('plugin' => 'echo', 'echo_fail' => 'simulated gather failure'), array('origin' => 'test'));
run_worker("--job={$j4['uuid']}");
$r4 = $svc->get($j4['uuid']);
check('plugin exception -> failed with error_text', $r4['status'] === 'failed' && strpos($r4['error_text'], 'simulated') !== false, $r4['status']);
check('failed job leaves no workspace', !is_dir(rtrim($cfg['work_root'], '/') . '/' . $j4['uuid']));
$j5 = $svc->create($UPK, array('plugin' => 'nosuchplugin'), array('origin' => 'test'));
run_worker("--job={$j5['uuid']}");
check('unknown plugin -> failed', $svc->get($j5['uuid'])['status'] === 'failed');

// ---------------------------------------------------------------- 6. cancel / rerun / clear
$j6 = $svc->create($UPK, array('plugin' => 'echo'), array('origin' => 'test'));
check('cancel queued job', $svc->cancel($j6['uuid'], $UPK) && $svc->get($j6['uuid'])['status'] === 'cancelled');
check('cancel by another user refused', !$svc->cancel($j1['uuid'], $UPK + 1));
check('claim skips cancelled', $svc->claim($j6['uuid'], 1) === null);
$j7 = $svc->rerun($j1['uuid'], $UPK);
check('rerun clones recipe with origin=rerun + rerun_of', $j7 && $j7['origin'] === 'rerun' && $j7['rerun_of'] === $j1['pkey']
	&& $j7['recipe'] == $j1['recipe'] && $j7['status'] === 'queued');
check('rerun by another user refused', (function () use ($svc, $j1, $UPK) {
	try { $svc->rerun($j1['uuid'], $UPK + 1); return false; } catch (ExportJobError $e) { return true; } })());
$svc->cancel($j7['uuid'], $UPK);
$before = count($svc->listForUser($UPK));
$n = $svc->clear($UPK, 'finished');
$after = $svc->listForUser($UPK);
check('clear finished hides terminal rows only', $n >= 5 && count($after) < $before
	&& count(array_filter($after, function ($r) { return $r['status'] !== 'queued' && $r['status'] !== 'running'; })) === 0,
	"cleared=$n before=$before after=" . count($after));
check('cleared rows still exist (audit)', (int)qv("SELECT count(*) FROM export_jobs WHERE userpkey = $1 AND deleted_at IS NOT NULL", array($UPK)) === $n);
check('get() by uuid + wrong user is null', $svc->get($j1['uuid'], $UPK + 1) === null && $svc->get($j1['uuid'], $UPK) !== null);

// ---------------------------------------------------------------- 7. stale-run recovery (sweep)
$j8 = $svc->create($UPK, array('plugin' => 'echo', 'echo_files' => 1), array('summary' => 'stale-1', 'origin' => 'test'));
x("UPDATE export_jobs SET status='running', attempt=1, heartbeat_at = now() - interval '30 minutes' WHERE pkey = $1", array($j8['pkey']));
$j9 = $svc->create($UPK, array('plugin' => 'echo', 'echo_files' => 1), array('summary' => 'stale-2', 'origin' => 'test'));
x("UPDATE export_jobs SET status='running', attempt=2, heartbeat_at = now() - interval '30 minutes' WHERE pkey = $1", array($j9['pkey']));
list($rc, $out) = run_worker('--sweep');
$r8 = $svc->get($j8['uuid']); $r9 = $svc->get($j9['uuid']);
check('sweep exit 0', $rc === 0, "rc=$rc");
check('stale attempt-1 run re-queued and completed by sweep (attempt=2, done)', $r8['status'] === 'done' && $r8['attempt'] === 2, $r8['status'] . '/' . $r8['attempt']);
check('stale attempt-2 run failed for good', $r9['status'] === 'failed' && strpos($r9['error_text'], 'stopped responding') !== false, $r9['status']);

check('sweep log names both', strpos($out, "re-queued: {$j8['uuid']}") !== false && strpos($out, "failed for good: {$j9['uuid']}") !== false);
// ---------------------------------------------------------------- 7b. crash containment (2026-09-01: PHPExcel recoverable fatal left a job RUNNING)
$jr = $svc->create($UPK, array('plugin' => 'echo', 'echo_recoverable_fatal' => 1), array('summary' => 'recoverable-fatal', 'origin' => 'test'));
list($rc, $out) = run_worker("--job={$jr['uuid']}");
$rr = $svc->get($jr['uuid']);
check('recoverable fatal -> job failed at once with the real message (error handler -> ErrorException -> runner catch)', $rr['status'] === 'failed' && strpos($rr['error_text'], 'stdClass') !== false, $rr['status'] . ' ' . $rr['error_text']);
$jo = $svc->create($UPK, array('plugin' => 'echo', 'echo_oom' => 1), array('summary' => 'oom', 'origin' => 'test'));
list($rc, $out) = run_worker("--job={$jo['uuid']}");
$ro = $svc->get($jo['uuid']);
check('true fatal (memory) -> shutdown hook fails the claimed job with "Internal error"', $ro['status'] === 'failed' && strpos($ro['error_text'], 'Internal error') !== false && stripos($ro['error_text'], 'memory') !== false, $ro['status'] . ' ' . $ro['error_text']);

// ---------------------------------------------------------------- 8. concurrency cap
$j10 = $svc->create($UPK, array('plugin' => 'echo'), array('summary' => 'cap-holder', 'origin' => 'test'));
x("UPDATE export_jobs SET status='running', attempt=1, heartbeat_at = now() WHERE pkey = $1", array($j10['pkey']));
$j11 = $svc->create($UPK, array('plugin' => 'echo'), array('summary' => 'cap-waiter', 'origin' => 'test'));
list($rc, $out) = run_worker("--job={$j11['uuid']}", array('max_concurrent' => 1));
check('cap reached: job left queued', $svc->get($j11['uuid'])['status'] === 'queued' && strpos($out, 'cap reached') !== false);
x("UPDATE export_jobs SET status='cancelled' WHERE pkey = $1", array($j10['pkey']));
list($rc, $out) = run_worker("--job={$j11['uuid']}", array('max_concurrent' => 1));
check('cap freed: job runs', $svc->get($j11['uuid'])['status'] === 'done');

// ---------------------------------------------------------------- 9. disk guard
$j12 = $svc->create($UPK, array('plugin' => 'echo'), array('summary' => 'disk-waiter', 'origin' => 'test'));
list($rc, $out) = run_worker("--job={$j12['uuid']}", array('min_free_bytes' => 1e18));
$r12 = $svc->get($j12['uuid']);
check('low disk: job stays queued, marked waiting', $r12['status'] === 'queued' && $r12['progress_note'] === 'waiting for disk space', $r12['status'] . '/' . $r12['progress_note']);
list($rc, $out) = run_worker('--sweep', array('min_free_bytes' => 1e18, 'disk_wait_seconds' => 0));
$r12 = $svc->get($j12['uuid']);
check('disk wait timeout: job failed with disk message', $r12['status'] === 'failed' && strpos($r12['error_text'], 'disk space') !== false, $r12['status']);

// ---------------------------------------------------------------- 10. retention
$j13 = $svc->create($UPK, array('plugin' => 'echo', 'echo_files' => 1), array('summary' => 'retention', 'origin' => 'test'));
run_worker("--job={$j13['uuid']}");
$r13 = $svc->get($j13['uuid']); $abs13 = result_abs($r13, $cfg);
check('retention fixture done with file', $r13['status'] === 'done' && is_file($abs13));
x("UPDATE export_jobs SET expires_at = now() - interval '1 hour' WHERE pkey = $1", array($r13['pkey']));
list($rc, $out) = run_worker('--sweep');
$r13 = $svc->get($j13['uuid']);
clearstatcache(true, $abs13);   // the worker unlinked it in another process; PHP caches stat() per process
check('past expires_at -> status expired, file removed, row kept', $r13 && $r13['status'] === 'expired' && !is_file($abs13) && $r13['expired_at'] !== null,
	"rc=$rc status=" . ($r13 ? $r13['status'] : 'null') . " file=" . (is_file($abs13) ? 'yes' : 'no') . " out=" . str_replace("\n", ' | ', $out));
check('expired row keeps recipe for re-run', is_array($r13['recipe']) && $r13['recipe']['plugin'] === 'echo');
$j14 = $svc->rerun($j13['uuid'], $UPK);
check('re-run of expired job queues a clone', $j14['status'] === 'queued' && $j14['rerun_of'] === $r13['pkey']);
$svc->cancel($j14['uuid'], $UPK);

// ---------------------------------------------------------------- 11. per-user cap
$done = array();
for ($i = 0; $i < 3; $i++) {
	$j = $svc->create($UPK, array('plugin' => 'echo', 'echo_files' => 1, 'echo_bytes' => 4096), array('summary' => "cap-$i", 'origin' => 'test'));
	run_worker("--job={$j['uuid']}");
	$done[] = $svc->get($j['uuid']);
	usleep(20000);
}
check('three done results for cap test', count(array_filter($done, function ($r) { return $r['status'] === 'done'; })) === 3);
$keep = (int)$done[2]['result_bytes'] + 10;          // cap admits exactly the newest
list($rc, $out) = run_worker('--sweep', array('user_cap_bytes' => $keep, 'caps_userpkey' => $UPK));   // confined to the fixture user: a global tiny cap expired Jason's real export on dev (09-01)
clearstatcache();
$st = array_map(function ($r) use ($svc) { return $svc->get($r['uuid'])['status']; }, $done);
check('user cap expires OLDEST first, keeps newest', $st === array('expired', 'expired', 'done'), implode(',', $st));
check('cap-expired files removed', !is_file(result_abs($done[0], $cfg)) && !is_file(result_abs($done[1], $cfg)) && is_file(result_abs($done[2], $cfg)));

// ---------------------------------------------------------------- 12. Apache never serves the dirs
check('data folder carries its own deny-all .htaccess', trim((string)@file_get_contents(rtrim($cfg['data_root'], '/') . '/.htaccess')) !== '' && strpos((string)@file_get_contents(rtrim($cfg['data_root'], '/') . '/.htaccess'), 'Require all denied') !== false);
foreach (array("exportjobs_data/results/$UPK/{$done[2]['uuid']}.zip", 'exportjobs_data/', 'exportjobs_data/.htaccess', 'exportjobs_data/log/worker.log', 'exportjobs/worker.php') as $path) {
	$code = (int)trim(shell_exec('curl -s -o /dev/null -w "%{http_code}" ' . escapeshellarg("http://localhost/$path")));
	check("HTTP 403 for /$path", $code === 403, "got $code");
}

// ---------------------------------------------------------------- cleanup
cleanup($UPK, $cfg);
check('zero residue: rows', (int)qv("SELECT count(*) FROM export_jobs WHERE userpkey = $1", array($UPK)) === 0);
check('zero residue: result dir', !is_dir(rtrim($cfg['results_root'], '/') . "/$UPK"));
@unlink('/tmp/ej_a.log'); @unlink('/tmp/ej_b.log'); @unlink('/tmp/ej_c.log');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
