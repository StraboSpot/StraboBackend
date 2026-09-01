<?php
/**
 * File: exportjobs/lib/export_config.php
 * Description: Export job system configuration (docs/ExportBuilder_Design.md
 *              §14). Defaults live here, in the repo, so every environment
 *              runs the same code; an environment may override any key by
 *              defining `$export_config = array(...)` in
 *              includes/config.inc.php (not in git). Tests override through
 *              the EXPORTJOBS_CONFIG_JSON environment variable, which wins
 *              over both (it is read by the worker process too, so a test can
 *              drive the cap / disk guards of a spawned worker).
 *
 *              Paths are absolute container paths. The results and work
 *              directories are inside the web root on purpose (no compose
 *              change needed on prod) and are .htaccess-denied; only
 *              exportjobs/download.php ever streams a result.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (!function_exists('export_config')) {
/**
 * @return array  merged configuration (defaults < config.inc.php < env JSON)
 */
function export_config()
{
	static $cfg = null;
	if ($cfg !== null) return $cfg;

	$root = realpath(__DIR__ . '/..');           // .../www/exportjobs
	$defaults = array(
		'web_root'             => realpath($root . '/..'),
		'results_root'         => $root . '/results',
		'work_root'            => $root . '/work',
		'log_root'             => $root . '/log',
		'max_concurrent'       => 2,              // running jobs with a fresh heartbeat
		'min_free_bytes'       => 10737418240,    // 10 GB: refuse to START a build below this
		'disk_wait_seconds'    => 21600,          // 6 h queued-for-disk before the job fails
		'user_cap_bytes'       => 2147483648,     // 2 GB live results per user; oldest expire first
		'retention_days'       => 7,
		'max_items'            => 250000,         // FIND ceiling per build
		'max_queued_per_user'  => 5,              // queued + running
		'stale_seconds'        => 600,            // running + no heartbeat for 10 min = crashed
		'max_attempts'         => 2,              // one automatic retry after a crash
		'php_binary'           => 'php',
		'zip_binary'           => 'zip',
	);

	$cfg = $defaults;
	if (isset($GLOBALS['export_config']) && is_array($GLOBALS['export_config'])) {
		$cfg = array_merge($cfg, $GLOBALS['export_config']);
	}
	$env = getenv('EXPORTJOBS_CONFIG_JSON');
	if ($env !== false && $env !== '') {
		$over = json_decode($env, true);
		if (is_array($over)) $cfg = array_merge($cfg, $over);
	}
	return $cfg;
}
}
