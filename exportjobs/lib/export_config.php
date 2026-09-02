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
 *              Paths are absolute container paths. All job data (work,
 *              results, log) lives under ONE gitignored folder inside the
 *              web root, exportjobs_data/, so it sits inside the ./www bind
 *              mount and Jason's backup/mount strategy (2026-09-02); the
 *              code creates it on first use (export_ensure_dirs) and Apache
 *              is denied twice (root .htaccess RewriteRule [F] + a
 *              Require-all-denied .htaccess written into the folder); only
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
	$data = dirname($root) . '/exportjobs_data'; // .../www/exportjobs_data (gitignored, created on first use)
	$defaults = array(
		'web_root'             => realpath($root . '/..'),
		'data_root'            => $data,
		'results_root'         => $data . '/results',
		'work_root'            => $data . '/work',
		'log_root'             => $data . '/log',
		'max_concurrent'       => 2,              // running jobs with a fresh heartbeat
		'min_free_bytes'       => 0,              // free-disk floor to START a build; 0 = disabled (prod results live on a 20 TB volume, Jason 2026-09-02)
		'disk_wait_seconds'    => 21600,          // 6 h queued-for-disk before the job fails
		'user_cap_bytes'       => 2147483648,     // 2 GB live results per user; oldest expire first
		'caps_userpkey'        => null,           // TEST ONLY: confine enforceUserCaps to one user (suite override)
		'retention_days'       => 7,
		'max_items'            => 250000,         // FIND ceiling per build
		'max_queued_per_user'  => 5,              // queued + running
		'stale_seconds'        => 600,            // running + no heartbeat for 10 min = crashed
		'max_attempts'         => 2,              // one automatic retry after a crash
		'php_binary'           => 'php',
		'zip_binary'           => 'zip',
		'site_url'             => 'https://strabospot.org',   // links in notification emails
		'mail_transport'       => 'smtp',         // smtp (PHPMailer, forgotpassword.php pattern) | file (log_root/mail.log) | none
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

/**
 * Create the data folder and its three subdirectories if missing, and drop
 * a deny-all .htaccess into the data folder (belt and braces next to the
 * root .htaccess rule). Called by the worker, the api and download.php, so
 * whichever runs first (www-data on prod) owns the folder. Silent on
 * failure: the caller's own write then fails with a clear message.
 *
 * @param array $cfg  export_config()
 */
function export_ensure_dirs(array $cfg)
{
	foreach (array($cfg['data_root'], $cfg['results_root'], $cfg['work_root'], $cfg['log_root']) as $d) {
		if (!is_dir($d)) @mkdir($d, 0775, true);
	}
	$ht = rtrim($cfg['data_root'], '/') . '/.htaccess';
	if (is_dir($cfg['data_root']) && !is_file($ht)) {
		@file_put_contents($ht, "# Never served by Apache: results are streamed only by exportjobs/download.php\nRequire all denied\n");
	}
}
}
