<?php
/**
 * File: exportjobs/plugins/EchoExportPlugin.php
 * Description: Test/diagnostic plugin: writes the recipe back out as a JSON
 *              file plus an optional payload of N filler files, walking the
 *              same phases the Field plugin will, so the queue, progress,
 *              packaging, retention, and cap paths can be exercised without
 *              any Field data. Recipe keys it honors:
 *                 echo_files:int      filler files to write (default 3)
 *                 echo_bytes:int      size of each filler file (default 1024)
 *                 echo_sleep_ms:int   pause per file (lets tests observe progress)
 *                 echo_fail:string    throw this message during 'gather'
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 */

require_once __DIR__ . '/../lib/ExportPlugin.php';

class EchoExportPlugin implements ExportPlugin
{
	public function key() { return 'echo'; }

	public function run(array $job, array $recipe, $bundleDir, $progress)
	{
		$n     = isset($recipe['echo_files']) ? max(0, (int)$recipe['echo_files']) : 3;
		$bytes = isset($recipe['echo_bytes']) ? max(1, (int)$recipe['echo_bytes']) : 1024;
		$sleep = isset($recipe['echo_sleep_ms']) ? max(0, (int)$recipe['echo_sleep_ms']) : 0;

		$progress('resolve', 0, 0, 'echo: resolving');
		$progress('gather', 0, $n, 'echo: gathering');
		if (!empty($recipe['echo_fail'])) {
			throw new ExportJobError((string)$recipe['echo_fail']);
		}
		if (!empty($recipe['echo_recoverable_fatal'])) {
			$o = new stdClass();
			$s = (string)$o;                                   // E_RECOVERABLE_ERROR (the PHPExcel crash shape)
		}
		if (!empty($recipe['echo_oom'])) {
			$s = str_repeat('x', 8 * 1024 * 1024 * 1024);      // E_ERROR: memory limit (the shutdown-hook path)
		}
		file_put_contents($bundleDir . '/recipe.json',
			json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

		$progress('format:echo', 0, $n, 'echo: writing files');
		$chunk = str_repeat('x', min($bytes, 65536));
		for ($i = 1; $i <= $n; $i++) {
			$fh = fopen($bundleDir . "/echo_$i.txt", 'wb');
			$left = $bytes;
			while ($left > 0) {
				$w = min($left, strlen($chunk));
				fwrite($fh, substr($chunk, 0, $w));
				$left -= $w;
			}
			fclose($fh);
			if ($sleep) usleep($sleep * 1000);
			$progress('format:echo', $i, $n, "echo: wrote $i of $n files");
		}

		return array(
			'item_count'  => $n,
			'child_count' => 0,
			'readme'      => array("Echo plugin: $n filler file(s) of $bytes bytes."),
			'warnings'    => array(),
		);
	}
}
