<?php
/**
 * File: exportjobs/lib/ExportRunner.php
 * Description: Runs ONE claimed export job end to end (design §4, §6.6,
 *              §8.4): workspace, plugin dispatch, README + manifest,
 *              zip into the results root, row finish/fail, cleanup.
 *              The runner never decides WHETHER to run (caps, disk,
 *              claiming); that is worker.php's job.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/export_config.php';
require_once __DIR__ . '/ExportPlugin.php';
require_once __DIR__ . '/ExportJobService.php';

class ExportRunner
{
	private $svc;
	private $cfg;
	private $plugins = array();
	private $log;

	/** @var callable|null  function(array $finishedRow) after done/failed (email, design §9.6) */
	private $notify;

	public function __construct(ExportJobService $svc, array $plugins, $logFn = null, $notifyFn = null)
	{
		$this->svc = $svc;
		$this->cfg = $svc->config();
		foreach ($plugins as $p) $this->plugins[$p->key()] = $p;
		$this->log = $logFn ? $logFn : function ($m) {};
		$this->notify = $notifyFn;
	}

	/** Notification failures are logged and never change the job outcome. */
	private function notifyOutcome($pkey)
	{
		if (!$this->notify) return;
		$log = $this->log;
		try {
			$row = $this->svc->getByPkey($pkey);
			if ($row) call_user_func($this->notify, $row);
		} catch (Exception $e) {
			$log("notify failed for job $pkey: " . $e->getMessage());
		} catch (Error $e) {
			$log("notify failed for job $pkey: " . $e->getMessage());
		}
	}

	/**
	 * @param array $job a row already claimed (status=running)
	 * @return bool true = done, false = failed/cancelled
	 */
	public function run(array $job)
	{
		$svc  = $this->svc;
		$pkey = $job['pkey'];
		$uuid = $job['uuid'];
		$log  = $this->log;

		$workDir   = rtrim($this->cfg['work_root'], '/') . '/' . $uuid;
		$bundleDir = $workDir . '/bundle';
		$resultRel = $job['userpkey'] . '/' . $uuid . '.zip';
		$resultAbs = rtrim($this->cfg['results_root'], '/') . '/' . $resultRel;

		// Generators in the output class use paths relative to the web root.
		chdir($this->cfg['web_root']);

		$progress = function ($phase, $done = null, $total = null, $note = null) use ($svc, $pkey) {
			if ($svc->isCancelled($pkey)) throw new ExportCancelled('cancelled by user');
			$svc->progress($pkey, $phase, $done, $total, $note);
		};

		try {
			$recipe = is_array($job['recipe']) ? $job['recipe'] : array();
			$key = isset($recipe['plugin']) ? $recipe['plugin'] : '';
			if (!isset($this->plugins[$key])) {
				throw new ExportJobError("No export plugin registered for '$key'.");
			}
			$plugin = $this->plugins[$key];

			$this->rmrf($workDir);
			if (!mkdir($bundleDir, 0775, true)) throw new ExportJobError("Could not create workspace $bundleDir");

			$log("job $uuid: start (plugin $key, attempt {$job['attempt']})");
			$progress('resolve', 0, 0, 'starting');
			$out = $plugin->run($job, $recipe, $bundleDir, $progress);
			if (!is_array($out)) $out = array();
			$itemCount  = isset($out['item_count'])  ? (int)$out['item_count']  : 0;
			$childCount = isset($out['child_count']) ? (int)$out['child_count'] : 0;

			$progress('package', 0, 0, 'writing README and manifest');
			$manifest = $this->writeReadmeAndManifest($job, $recipe, $bundleDir, $out);

			$progress('zip', 0, 0, 'zipping');
			$this->zip($bundleDir, $workDir . '/result.zip');

			$resultDir = dirname($resultAbs);
			if (!is_dir($resultDir) && !mkdir($resultDir, 0775, true)) {
				throw new ExportJobError("Could not create results dir $resultDir");
			}
			if (!rename($workDir . '/result.zip', $resultAbs)) {
				throw new ExportJobError("Could not move result into place");
			}
			$bytes = filesize($resultAbs);
			$sha   = hash_file('sha256', $resultAbs);

			$svc->finish($pkey, $resultRel, $bytes, $sha, $itemCount, $childCount);
			$log("job $uuid: done ($bytes bytes, $itemCount items)");
			$this->rmrf($workDir);
			$this->notifyOutcome($pkey);
			return true;

		} catch (ExportCancelled $e) {
			$log("job $uuid: cancelled");
			$this->rmrf($workDir);
			return false;
		} catch (Exception $e) {
			$log("job $uuid: FAILED " . $e->getMessage());
			$svc->fail($pkey, $e->getMessage());
			$this->rmrf($workDir);
			$this->notifyOutcome($pkey);
			return false;
		} catch (Error $e) {                       // PHP 7 engine errors
			$log("job $uuid: FAILED (engine) " . $e->getMessage());
			$svc->fail($pkey, 'Internal error: ' . $e->getMessage());
			$this->rmrf($workDir);
			$this->notifyOutcome($pkey);
			return false;
		}
	}

	private function writeReadmeAndManifest(array $job, array $recipe, $bundleDir, array $out)
	{
		$files = array();
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bundleDir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			$rel = substr($f->getPathname(), strlen($bundleDir) + 1);
			$files[] = array('path' => $rel, 'bytes' => $f->getSize());
		}
		sort($files);

		$lines = array();
		$lines[] = 'StraboSpot export';
		$lines[] = 'Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC';
		$lines[] = 'Export id: ' . $job['uuid'];
		if (!empty($job['recipe_summary'])) $lines[] = 'Selection: ' . $job['recipe_summary'];
		if (isset($out['item_count']))  $lines[] = 'Items: ' . (int)$out['item_count'];
		if (!empty($out['child_count'])) $lines[] = 'Nested child spots included (parents matched the area filter): ' . (int)$out['child_count'];
		if (!empty($recipe['notes'])) $lines[] = 'Notes: ' . $recipe['notes'];
		if (!empty($out['readme']) && is_array($out['readme'])) {
			$lines[] = '';
			foreach ($out['readme'] as $l) $lines[] = $l;
		}
		if (!empty($out['warnings']) && is_array($out['warnings'])) {
			$lines[] = '';
			$lines[] = 'Warnings:';
			foreach ($out['warnings'] as $w) $lines[] = '  - ' . $w;
		}
		$lines[] = '';
		$lines[] = 'Files:';
		foreach ($files as $f) $lines[] = '  ' . $f['path'] . ' (' . $f['bytes'] . ' bytes)';
		$lines[] = '';
		$lines[] = 'https://strabospot.org';
		file_put_contents($bundleDir . '/README.txt', implode("\n", $lines) . "\n");

		$manifest = array(
			'export_id'    => $job['uuid'],
			'generated_at' => gmdate('c'),
			'summary'      => $job['recipe_summary'],
			'recipe'       => $recipe,
			'item_count'   => isset($out['item_count']) ? (int)$out['item_count'] : null,
			'child_count'  => isset($out['child_count']) ? (int)$out['child_count'] : null,
			'warnings'     => isset($out['warnings']) ? $out['warnings'] : array(),
			'files'        => $files,
		);
		file_put_contents($bundleDir . '/manifest.json',
			json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		return $manifest;
	}

	private function zip($bundleDir, $zipAbs)
	{
		$cmd = 'cd ' . escapeshellarg($bundleDir) . ' && ' . escapeshellcmd($this->cfg['zip_binary'])
			. ' -r -q ' . escapeshellarg($zipAbs) . ' . 2>&1';
		exec($cmd, $outLines, $rc);
		if ($rc !== 0 || !is_file($zipAbs)) {
			throw new ExportJobError('zip failed (rc ' . $rc . '): ' . implode(' ', $outLines));
		}
	}

	private function rmrf($dir)
	{
		if (!is_dir($dir)) return;
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		}
		@rmdir($dir);
	}
}
