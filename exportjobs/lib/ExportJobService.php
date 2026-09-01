<?php
/**
 * File: exportjobs/lib/ExportJobService.php
 * Description: All SQL for the export_jobs table (design §5, §6). Shared by
 *              the web endpoints (create / list / cancel / rerun / clear /
 *              download) and the CLI worker (claim / progress / finish /
 *              fail / stale re-queue / retention / caps).
 *
 *              Wrapper landmines this class is written around
 *              (see reference_db_wrapper_lastval_landmine):
 *                - prepare_query() discards result rows for any statement
 *                  starting with INSERT/UPDATE/DELETE, so RETURNING is
 *                  unreadable on those. Every write that needs its row back
 *                  is wrapped as `WITH x AS (UPDATE ... RETURNING *) SELECT
 *                  * FROM x`, which the wrapper treats as a SELECT.
 *                - Every statement goes through the prepared path; user
 *                  values never touch the SQL text.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/export_config.php';
require_once __DIR__ . '/ExportPlugin.php';   // ExportJobError / ExportCancelled
require_once dirname(__DIR__, 2) . '/includes/UUID.php';

class ExportJobService
{
	const STATUSES = array('queued', 'running', 'done', 'failed', 'expired', 'cancelled');

	private $db;
	private $cfg;

	public function __construct($db, array $cfg = null)
	{
		$this->db  = $db;
		$this->cfg = $cfg === null ? export_config() : $cfg;
	}

	public function config() { return $this->cfg; }

	// ------------------------------------------------------------------
	// Row helpers
	// ------------------------------------------------------------------

	/** Row object -> assoc array with decoded recipe + typed ints. */
	private function row($o)
	{
		if (!$o) return null;
		$r = (array)$o;
		$r['pkey']     = (int)$r['pkey'];
		$r['userpkey'] = (int)$r['userpkey'];
		$r['attempt']  = (int)$r['attempt'];
		foreach (array('progress_done', 'progress_total', 'item_count', 'child_count', 'worker_pid', 'rerun_of') as $k) {
			$r[$k] = ($r[$k] === null || $r[$k] === '') ? null : (int)$r[$k];
		}
		$r['result_bytes']  = ($r['result_bytes'] === null || $r['result_bytes'] === '') ? null : (int)$r['result_bytes'];
		$r['email_on_done'] = ($r['email_on_done'] === 't' || $r['email_on_done'] === true);
		$r['recipe']        = is_string($r['recipe']) ? json_decode($r['recipe'], true) : $r['recipe'];
		return $r;
	}

	private function rows($list)
	{
		$out = array();
		if (is_array($list)) foreach ($list as $o) $out[] = $this->row($o);
		return $out;
	}

	private function one($sql, array $params)
	{
		return $this->row($this->db->get_row_prepared($sql, $params));
	}

	private function many($sql, array $params)
	{
		return $this->rows($this->db->get_results_prepared($sql, $params));
	}

	/** Execute a write; throws on wrapper failure instead of returning false. */
	private function exec($sql, array $params)
	{
		$r = $this->db->prepare_query($sql, $params);
		if ($r === false) {
			throw new ExportJobError('export_jobs write failed: ' . $this->db->last_error);
		}
		return $r;
	}

	// ------------------------------------------------------------------
	// Create / read
	// ------------------------------------------------------------------

	/**
	 * Insert a queued job. Enforces max_queued_per_user.
	 * @return array the new row
	 */
	public function create($userpkey, array $recipe, array $opts = array())
	{
		$userpkey = (int)$userpkey;
		if ($userpkey <= 0) throw new ExportJobError('A logged-in user is required.');
		if (empty($recipe['plugin']) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $recipe['plugin'])) {
			throw new ExportJobError('Recipe is missing a valid plugin key.');
		}
		$active = (int)$this->db->get_var_prepared(
			"SELECT count(*) FROM export_jobs WHERE userpkey = $1 AND status IN ('queued','running')",
			array($userpkey));
		if ($active >= (int)$this->cfg['max_queued_per_user']) {
			throw new ExportJobError('You already have ' . $active . ' exports queued or running. Wait for one to finish.');
		}
		$uuid = UUID::v4();
		$this->exec(
			"INSERT INTO export_jobs (uuid, userpkey, status, recipe, recipe_summary, origin, rerun_of, email_on_done)
			 VALUES ($1, $2, 'queued', $3::jsonb, $4, $5, $6, $7)",
			array(
				$uuid, $userpkey,
				json_encode($recipe, JSON_UNESCAPED_SLASHES),
				isset($opts['summary']) ? (string)$opts['summary'] : null,
				isset($opts['origin']) ? (string)$opts['origin'] : 'builder',
				isset($opts['rerun_of']) ? (int)$opts['rerun_of'] : null,
				!empty($opts['email_on_done']) ? 't' : 'f',
			));
		return $this->get($uuid);
	}

	/** @return array|null  by uuid; optionally scoped to an owner */
	public function get($uuid, $userpkey = null)
	{
		if (!UUID::is_valid($uuid)) return null;
		if ($userpkey === null) {
			return $this->one("SELECT * FROM export_jobs WHERE uuid = $1", array($uuid));
		}
		return $this->one("SELECT * FROM export_jobs WHERE uuid = $1 AND userpkey = $2", array($uuid, (int)$userpkey));
	}

	public function getByPkey($pkey)
	{
		return $this->one("SELECT * FROM export_jobs WHERE pkey = $1", array((int)$pkey));
	}

	/** Visible (not cleared) jobs for My Exports, newest first. */
	public function listForUser($userpkey, $limit = 200)
	{
		return $this->many(
			"SELECT * FROM export_jobs WHERE userpkey = $1 AND deleted_at IS NULL
			 ORDER BY created_at DESC LIMIT $2",
			array((int)$userpkey, (int)$limit));
	}

	// ------------------------------------------------------------------
	// Worker side
	// ------------------------------------------------------------------

	/**
	 * Atomically claim ONE queued job (a specific uuid, or the oldest).
	 * Two workers can never claim the same row: FOR UPDATE SKIP LOCKED.
	 * @return array|null the claimed row (status=running) or null
	 */
	public function claim($uuid = null, $workerPid = null)
	{
		if ($uuid !== null && !UUID::is_valid($uuid)) return null;
		return $this->one(
			"WITH c AS (
			   UPDATE export_jobs SET status = 'running', started_at = now(), heartbeat_at = now(),
			          worker_pid = $2, attempt = attempt + 1, error_text = NULL
			    WHERE pkey = (SELECT pkey FROM export_jobs
			                   WHERE status = 'queued' AND ($1::uuid IS NULL OR uuid = $1::uuid)
			                   ORDER BY created_at
			                   FOR UPDATE SKIP LOCKED LIMIT 1)
			   RETURNING *)
			 SELECT * FROM c",
			array($uuid, $workerPid === null ? null : (int)$workerPid));
	}

	public function progress($pkey, $phase, $done = null, $total = null, $note = null)
	{
		$this->exec(
			"UPDATE export_jobs SET phase = $2, progress_done = $3, progress_total = $4,
			        progress_note = $5, heartbeat_at = now()
			  WHERE pkey = $1",
			array((int)$pkey, $phase === null ? null : substr((string)$phase, 0, 32),
			      $done === null ? null : (int)$done, $total === null ? null : (int)$total,
			      $note === null ? null : substr((string)$note, 0, 500)));
	}

	public function heartbeat($pkey)
	{
		$this->exec("UPDATE export_jobs SET heartbeat_at = now() WHERE pkey = $1", array((int)$pkey));
	}

	public function isCancelled($pkey)
	{
		return $this->db->get_var_prepared(
			"SELECT status FROM export_jobs WHERE pkey = $1", array((int)$pkey)) === 'cancelled';
	}

	public function finish($pkey, $resultPath, $bytes, $sha256, $itemCount, $childCount)
	{
		$this->exec(
			"UPDATE export_jobs SET status = 'done', phase = 'done', progress_note = NULL,
			        finished_at = now(), expires_at = now() + ($7 || ' days')::interval,
			        result_path = $2, result_bytes = $3, result_sha256 = $4,
			        item_count = $5, child_count = $6, heartbeat_at = now()
			  WHERE pkey = $1 AND status = 'running'",
			array((int)$pkey, $resultPath, (int)$bytes, $sha256, (int)$itemCount, (int)$childCount,
			      (int)$this->cfg['retention_days']));
	}

	public function fail($pkey, $error)
	{
		$this->exec(
			"UPDATE export_jobs SET status = 'failed', finished_at = now(), error_text = $2, heartbeat_at = now()
			  WHERE pkey = $1 AND status IN ('running','queued')",
			array((int)$pkey, substr((string)$error, 0, 4000)));
	}

	/** Running jobs whose heartbeat is fresh (the concurrency cap counts these). */
	public function countActive()
	{
		return (int)$this->db->get_var_prepared(
			"SELECT count(*) FROM export_jobs
			  WHERE status = 'running' AND heartbeat_at > now() - ($1 || ' seconds')::interval",
			array((int)$this->cfg['stale_seconds']));
	}

	/**
	 * Crashed-worker recovery: running rows with a stale heartbeat go back to
	 * queued (attempt < max_attempts) or to failed.
	 * @return array {requeued: uuids[], failed: uuids[]}
	 */
	public function requeueStale()
	{
		$stale = $this->many(
			"SELECT * FROM export_jobs
			  WHERE status = 'running' AND heartbeat_at < now() - ($1 || ' seconds')::interval",
			array((int)$this->cfg['stale_seconds']));
		$out = array('requeued' => array(), 'failed' => array());
		foreach ($stale as $j) {
			if ($j['attempt'] < (int)$this->cfg['max_attempts']) {
				$this->exec(
					"UPDATE export_jobs SET status = 'queued', phase = NULL, progress_done = NULL,
					        progress_total = NULL, progress_note = 'retrying after a worker crash',
					        worker_pid = NULL, heartbeat_at = NULL
					  WHERE pkey = $1 AND status = 'running'",
					array($j['pkey']));
				$out['requeued'][] = $j['uuid'];
			} else {
				$this->exec(
					"UPDATE export_jobs SET status = 'failed', finished_at = now(),
					        error_text = 'The export worker stopped responding twice. Please re-run the export.'
					  WHERE pkey = $1 AND status = 'running'",
					array($j['pkey']));
				$out['failed'][] = $j['uuid'];
			}
		}
		return $out;
	}

	/** Queued jobs held for disk space longer than disk_wait_seconds fail. */
	public function failDiskWaiters()
	{
		$rows = $this->many(
			"SELECT * FROM export_jobs
			  WHERE status = 'queued' AND progress_note = 'waiting for disk space'
			    AND created_at < now() - ($1 || ' seconds')::interval",
			array((int)$this->cfg['disk_wait_seconds']));
		foreach ($rows as $j) {
			$this->exec(
				"UPDATE export_jobs SET status = 'failed', finished_at = now(),
				        error_text = 'The server did not have enough free disk space to build this export. Please try again later.'
				  WHERE pkey = $1 AND status = 'queued'",
				array($j['pkey']));
		}
		return $rows;
	}

	/** @return int[] pkeys of every queued job (disk-wait marking). */
	public function listQueuedPkeys()
	{
		$rows = $this->db->get_results_prepared("SELECT pkey FROM export_jobs WHERE status = 'queued'", array());
		$out = array();
		if (is_array($rows)) foreach ($rows as $r) $out[] = (int)$r->pkey;
		return $out;
	}

	public function markWaitingForDisk($pkey)
	{
		$this->exec(
			"UPDATE export_jobs SET progress_note = 'waiting for disk space' WHERE pkey = $1 AND status = 'queued'",
			array((int)$pkey));
	}

	// ------------------------------------------------------------------
	// Retention
	// ------------------------------------------------------------------

	private function resultAbsPath($row)
	{
		if (empty($row['result_path'])) return null;
		// result_path is relative to results_root and was generated by us;
		// refuse anything that tries to escape.
		if (strpos($row['result_path'], '..') !== false) return null;
		return rtrim($this->cfg['results_root'], '/') . '/' . ltrim($row['result_path'], '/');
	}

	/** Absolute path of a row's result zip (null when none / malformed). */
	public function resultPath($row) { return $this->resultAbsPath($row); }

	/** Flip one done row to expired and remove its file. Row survives. */
	public function expire($row, $why = 'retention')
	{
		$abs = $this->resultAbsPath($row);
		if ($abs && is_file($abs)) @unlink($abs);
		$this->exec(
			"UPDATE export_jobs SET status = 'expired', expired_at = now(), progress_note = $2
			  WHERE pkey = $1 AND status = 'done'",
			array($row['pkey'], 'expired: ' . $why));
	}

	/** Retention: every done row past expires_at. @return uuids */
	public function expireDue()
	{
		$due = $this->many(
			"SELECT * FROM export_jobs WHERE status = 'done' AND expires_at IS NOT NULL AND expires_at < now()",
			array());
		foreach ($due as $j) $this->expire($j, 'retention');
		return array_map(function ($j) { return $j['uuid']; }, $due);
	}

	/**
	 * Per-user cap on live result bytes: oldest results expire first until
	 * the user is under user_cap_bytes. @return uuids expired
	 */
	public function enforceUserCaps()
	{
		$cap = (int)$this->cfg['user_cap_bytes'];
		$over = $this->db->get_results_prepared(
			"SELECT userpkey, sum(result_bytes) AS total FROM export_jobs
			  WHERE status = 'done' GROUP BY userpkey HAVING sum(result_bytes) > $1",
			array($cap));
		$expired = array();
		if (!is_array($over)) return $expired;
		foreach ($over as $u) {
			$total = (int)$u->total;
			$rows = $this->many(
				"SELECT * FROM export_jobs WHERE userpkey = $1 AND status = 'done' ORDER BY finished_at ASC",
				array((int)$u->userpkey));
			foreach ($rows as $j) {
				if ($total <= $cap) break;
				$this->expire($j, 'per-user storage cap');
				$total -= (int)$j['result_bytes'];
				$expired[] = $j['uuid'];
			}
		}
		return $expired;
	}

	// ------------------------------------------------------------------
	// User actions
	// ------------------------------------------------------------------

	/** queued -> cancelled (running jobs notice at their next phase boundary). */
	public function cancel($uuid, $userpkey)
	{
		$n = $this->exec(
			"UPDATE export_jobs SET status = 'cancelled', finished_at = now()
			  WHERE uuid = $1 AND userpkey = $2 AND status IN ('queued','running')",
			array($uuid, (int)$userpkey));
		return $n > 0;
	}

	/** Clone a job's recipe into a new queued job. */
	public function rerun($uuid, $userpkey)
	{
		$src = $this->get($uuid, $userpkey);
		if (!$src) throw new ExportJobError('Export not found.');
		return $this->create($userpkey, $src['recipe'], array(
			'summary'       => $src['recipe_summary'],
			'origin'        => 'rerun',
			'rerun_of'      => $src['pkey'],
			'email_on_done' => $src['email_on_done'],
		));
	}

	/**
	 * Hide rows from My Exports. $which: 'finished' (done+failed+expired+
	 * cancelled) or 'expired'. Never touches queued/running.
	 */
	public function clear($userpkey, $which)
	{
		$set = $which === 'expired'
			? array('expired')
			: array('done', 'failed', 'expired', 'cancelled');
		return (int)$this->exec(
			"UPDATE export_jobs SET deleted_at = now()
			  WHERE userpkey = $1 AND deleted_at IS NULL AND status = ANY($2::text[])",
			array((int)$userpkey, '{' . implode(',', $set) . '}'));
	}
}
