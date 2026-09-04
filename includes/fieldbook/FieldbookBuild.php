<?php
/**
 * File: includes/fieldbook/FieldbookBuild.php
 * Description: State of one interactive Field Book build (docs/Fieldbook_Design.md
 *              §14 M6, 2026-09-04). The My Field Data "Field Book" entry opens
 *              fieldbook_build.php, which fires fieldbook_run.php in the
 *              background and polls fieldbook_status.php once a second until
 *              the book is written, then sends the browser to fieldbook_fetch.php
 *              (Content-Length + byte ranges, so the viewer shows page one while
 *              the rest is still arriving). Every piece of shared state is one
 *              JSON file per build under exportjobs_data/fieldbook/<key>.json,
 *              the PDF beside it as <key>.pdf; the key is a hash of the signed-in
 *              user, the dataset ids and the options, so a second click on the
 *              same book attaches to the running build (or reuses a book finished
 *              in the last REUSE_SECONDS) instead of starting another. Every
 *              endpoint checks the state's userpkey against the session before
 *              answering. Files are swept by exportjobs/cleanup_data.sh rule 5.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/Fieldbook.php';
if (!function_exists('export_config')) require_once dirname(__DIR__, 2) . '/exportjobs/lib/export_config.php';

class FieldbookBuild
{
	const STALE_SECONDS = 180;     // running with no state write for this long = the request died; the next visitor rebuilds
	const REUSE_SECONDS = 600;     // a book finished this recently is served again instead of rebuilt
	const WRITE_INTERVAL = 0.3;    // seconds between progress writes (a stage change always writes)

	/** Stage keys in order, with the labels the page lists. */
	public static $stages = array(
		'gather'  => 'Gathering spots and photos',
		'build'   => 'Building the day pages',
		'summary' => 'Summary: stereonets, geologic units, tags, samples, photo index',
		'write'   => 'Writing the PDF',
	);

	/** TEST ONLY: directory override. */
	public static $dirOverride = null;

	public static function dir()
	{
		$d = self::$dirOverride !== null ? self::$dirOverride : rtrim(export_config()['data_root'], '/') . '/fieldbook';
		if (!is_dir($d)) { @mkdir($d, 0775, true); if (function_exists('export_ensure_dirs')) export_ensure_dirs(export_config()); }
		return $d;
	}

	/** The request parameters a build is defined by (the legacy door's own set), normalised. */
	public static function params(array $get)
	{
		$dsids = isset($get['dsids']) ? (string)$get['dsids'] : '';
		$list = array_values(array_unique(array_filter(array_map('intval', explode(',', $dsids)))));
		sort($list);
		$p = array('dsids' => implode(',', $list), 'userpkey' => isset($get['userpkey']) ? (int)$get['userpkey'] : 0);
		foreach (Fieldbook::options($get) as $k => $v) $p['fb_' . $k] = $v;
		return $p;
	}

	/** Build key: the signed-in user + the normalised parameters. */
	public static function key($sessionUser, array $params)
	{
		return sha1((int)$sessionUser . '|' . json_encode($params));
	}

	public static function statePath($key) { return self::dir() . '/' . preg_replace('/[^a-f0-9]/', '', $key) . '.json'; }
	public static function pdfPath($key) { return self::dir() . '/' . preg_replace('/[^a-f0-9]/', '', $key) . '.pdf'; }

	public static function load($key)
	{
		if (!preg_match('/^[a-f0-9]{40}$/', (string)$key)) return null;
		$p = self::statePath($key);
		if (!is_file($p)) return null;
		$s = json_decode((string)file_get_contents($p), true);
		return is_array($s) ? $s : null;
	}

	public static function save($key, array $state)
	{
		$state['updated'] = microtime(true);
		$p = self::statePath($key);
		$tmp = $p . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, json_encode($state)) === false) { @unlink($tmp); return false; }
		return @rename($tmp, $p);
	}

	/** A fresh queued state for a page visit that starts a build. */
	public static function newState($sessionUser, array $params, $title)
	{
		return array(
			'key' => self::key($sessionUser, $params),
			'userpkey' => (int)$sessionUser, 'params' => $params, 'title' => (string)$title,
			'state' => 'queued', 'stage' => 'gather', 'done' => 0, 'total' => 0, 'note' => 'Waiting to start',
			'stages_seen' => array(), 'created' => microtime(true), 'started' => null, 'finished' => null,
			'filename' => '', 'bytes' => 0, 'error' => '',
		);
	}

	public static function isRunning(array $s) { return $s['state'] === 'running' && (microtime(true) - (float)$s['updated']) < self::STALE_SECONDS; }
	public static function isReusable(array $s, $key) { return $s['state'] === 'done' && $s['finished'] && (microtime(true) - (float)$s['finished']) < self::REUSE_SECONDS && is_file(self::pdfPath($key)); }

	/** What the page should do for an existing state: attach (poll), fetch (done, fresh), or run. */
	public static function modeFor($state, $key)
	{
		if (!$state) return 'run';
		if (self::isRunning($state)) return 'attach';
		if (self::isReusable($state, $key)) return 'fetch';
		return 'run';
	}

	/** The callable(stage, done, total, note) handed to the renderer; throttled JSON writes. */
	public static function progressWriter($key, array &$state)
	{
		$last = 0.0;
		return function ($stage, $done = null, $total = null, $note = null) use ($key, &$state, &$last) {
			$stage = (string)$stage;
			if (strpos($stage, 'format:') === 0) $stage = 'build';   // the renderer's per-spot stage name (shared with the export worker)
			$changed = $stage !== $state['stage'];
			$state['stage'] = $stage;
			if ($done !== null) $state['done'] = (int)$done;
			if ($total !== null) $state['total'] = (int)$total;
			if ($note !== null) $state['note'] = (string)$note;
			if ($changed && !in_array($stage, $state['stages_seen'], true)) $state['stages_seen'][] = $stage;
			$now = microtime(true);
			if ($changed || $now - $last >= self::WRITE_INTERVAL) { self::save($key, $state); $last = $now; }
		};
	}

	/** Public view of a state for the status endpoint. */
	public static function view($key, array $s)
	{
		$idx = array_search($s['stage'], array_keys(self::$stages), true);
		$start = $s['started'] ? (float)$s['started'] : (float)$s['created'];
		$end = (($s['state'] === 'done' || $s['state'] === 'failed') && $s['finished']) ? (float)$s['finished'] : microtime(true);
		return array(
			'found' => true, 'state' => $s['state'], 'stage' => $s['stage'], 'stage_index' => $idx === false ? 0 : (int)$idx,
			'stage_label' => isset(self::$stages[$s['stage']]) ? self::$stages[$s['stage']] : '',
			'done' => (int)$s['done'], 'total' => (int)$s['total'], 'note' => (string)$s['note'],
			'elapsed' => (int)round($end - $start), 'bytes' => (int)$s['bytes'], 'error' => (string)$s['error'],
			'title' => (string)$s['title'], 'fetch_url' => $s['state'] === 'done' ? '/fieldbook_fetch?k=' . $key : '',
		);
	}

	/** Remove a build's files (tests, and the run endpoint before a rebuild). */
	public static function remove($key)
	{
		@unlink(self::pdfPath($key)); @unlink(self::statePath($key));
		$tmp = self::dir() . '/' . preg_replace('/[^a-f0-9]/', '', $key) . '.tmp';
		if (is_dir($tmp)) { foreach (glob("$tmp/*") as $f) @unlink($f); @rmdir($tmp); }
	}

	/**
	 * Run the build in this request: capture the PDF into <key>.tmp/, move it to <key>.pdf, record the outcome.
	 * $strabo is the signed-in user's StraboSpot instance (prepare_connections). Returns the final state.
	 */
	public static function run($key, array $state, $strabo)
	{
		require_once dirname(__DIR__) . '/straboClasses/straboOutputClass.php';
		$state['key'] = $key;
		$state['state'] = 'running'; $state['started'] = microtime(true); $state['finished'] = null; $state['error'] = '';
		$state['stage'] = ''; $state['done'] = 0; $state['total'] = 0; $state['note'] = 'Starting'; $state['stages_seen'] = array();   // stage '' so the first report counts as a change
		$state['pid'] = getmypid();
		self::save($key, $state);
		$tmp = self::dir() . '/' . preg_replace('/[^a-f0-9]/', '', $key) . '.tmp';
		if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
		foreach (glob("$tmp/*") as $f) @unlink($f);
		$get = $state['params'];
		$out = new straboOutputClass($strabo, $get);
		$out->captureDir = $tmp;
		$out->progress = self::progressWriter($key, $state);
		try {
			ob_start();
			$out->fieldbookOut();
			$stray = ob_get_clean();
			$pdf = null;
			foreach ($out->captured as $c) if (substr($c['name'], -4) === '.pdf') $pdf = $c;
			if (!$pdf) throw new Exception('The book was not written' . ($stray !== '' ? ': ' . trim(strip_tags($stray)) : '.'));
			if (!@rename($pdf['path'], self::pdfPath($key))) throw new Exception('Could not store the book.');
			$state['filename'] = $pdf['name']; $state['bytes'] = (int)filesize(self::pdfPath($key));
			$state['state'] = 'done'; $state['stage'] = 'write'; $state['done'] = 1; $state['total'] = 1; $state['note'] = 'Done';
		} catch (ExportNoDataException $e) {
			if (ob_get_level()) ob_end_clean();
			$state['state'] = 'failed'; $state['error'] = $e->getMessage();
		} catch (Throwable $e) {
			if (ob_get_level()) ob_end_clean();
			$state['state'] = 'failed'; $state['error'] = 'The book could not be built: ' . $e->getMessage();
			error_log('fieldbook_run ' . $key . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		}
		$state['finished'] = microtime(true);
		self::save($key, $state);
		@rmdir($tmp);
		return $state;
	}
}
