<?php
/**
 * File: VsWorker.php
 * Description: The worker side of Voice Stations (build step 2): the
 *              /voiceworker/v1/ API that transcription and extraction
 *              workers pull jobs from. Workers never hold database
 *              credentials or a user login; each has its own token
 *              (VOICESTATIONS_WORKERS in config.inc.php).
 *
 *              Design (Jason, 10-07, Phase1_Plan.md build step 2):
 *                - one job per claim, oldest batch first, then recording
 *                  order; FOR UPDATE SKIP LOCKED so two workers never share
 *                  a station
 *                - transcription: station uploaded, not discarded or
 *                  confirmed, audio present
 *                - extraction: station transcribed, batch complete, and no
 *                  other live station of the batch still waiting for or in
 *                  transcription
 *                - a worker's delay (prod fallback: 120 s) holds it back
 *                  until a station has waited that long; for extraction the
 *                  wait counts from the batch's latest stage change, so the
 *                  fallback never races a busy main worker
 *                - lease 2 min, extended by heartbeats; an attempt is
 *                  counted at claim, 3 per stage; the run row is made at
 *                  claim and every attempt leaves one
 *                - expired leases are swept at each claim and each batch
 *                  status read (no cron): the station goes back to the
 *                  queue, or fails after its last attempt
 *                - a result is accepted only while the worker's run is still
 *                  the live one: 410 when the station was discarded or
 *                  confirmed meanwhile, 409 lease_lost otherwise; nothing is
 *                  stored either way
 *
 *              Config:
 *                define('VOICESTATIONS_WORKERS', array(
 *                  'gpubox' => array('sha256' => '<sha256 hex of the token>', 'delay' => 0),
 *                  'prod'   => array('sha256' => '<...>', 'delay' => 120),
 *                ));
 *              A plain string value is the hash with delay 0. Not defined =
 *              no worker gets in.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class VsWorker {

	const LEASE_SECONDS    = 120;
	const MAX_ATTEMPTS     = 3;
	const MAX_RESULT_BYTES = 8388608;   // whole result body, 8 MB
	const MAX_RAW_BYTES    = 1048576;   // raw_output, 1 MB
	const MAX_WORDS        = 20000;
	const MAX_SEGMENTS     = 5000;
	const MAX_ERROR_CHARS  = 2000;
	const KINDS            = array('transcribe', 'extract');

	private $db;      // MsDb
	private $name;    // worker name, e.g. 'gpubox'
	private $delay;   // seconds a station must wait before this worker may claim it

	public function __construct($strabodb, $name, $delay) {
		$this->db = new MsDb($strabodb);
		$this->name = $name;
		$this->delay = (int)$delay;
	}

	// ---------------------------------------------------------------- auth

	/**
	 * Which configured worker does this Bearer token belong to?
	 * Returns array(name, delay) or null.
	 */
	public static function identify($authHeader, $workers) {
		if (!is_string($authHeader) || !preg_match('/^Bearer\s+(\S+)$/i', trim($authHeader), $m)) {
			return null;
		}
		if (!is_array($workers)) {
			return null;
		}
		$hash = hash('sha256', $m[1]);
		$found = null;
		foreach ($workers as $name => $w) {
			$want = is_array($w) ? (isset($w['sha256']) ? $w['sha256'] : null) : $w;
			if (!is_string($name) || !preg_match('/^[a-z0-9_-]{1,32}$/', $name)
					|| !is_string($want) || !preg_match('/^[0-9a-f]{64}$/', $want)) {
				continue;
			}
			// compare against every entry, so timing does not tell which matched
			if (hash_equals($want, $hash) && $found === null) {
				$delay = (is_array($w) && isset($w['delay'])) ? max(0, (int)$w['delay']) : 0;
				$found = array($name, $delay);
			}
		}
		return $found;
	}

	// ---------------------------------------------------------------- sweep

	/**
	 * Expired leases: the run fails ("lease expired"), the station goes back
	 * to the queue keeping its stage_since (so a fallback worker whose delay
	 * has already passed takes over at once), or fails after its last
	 * attempt. $batchId limits the sweep to one batch (batch status reads).
	 */
	public static function expireLeases($db, $batchId = null) {
		$own = !$db->inTransaction();
		if ($own) {
			$db->begin();
		}
		try {
			$rows = $db->rows(
				"WITH due AS (
				   SELECT id, stage, attempts FROM voicestations.stations
				    WHERE stage IN ('transcribing', 'extracting')
				      AND lease_until < now()
				      AND ($1::bigint IS NULL OR batch_id = $1::bigint)
				    FOR UPDATE SKIP LOCKED)
				 UPDATE voicestations.stations s
				    SET stage = CASE WHEN due.attempts >= $2 THEN 'failed'
				                     WHEN due.stage = 'transcribing' THEN 'uploaded'
				                     ELSE 'transcribed' END,
				        stage_since = CASE WHEN due.attempts >= $2 THEN now() ELSE s.stage_since END,
				        error_text = CASE WHEN due.attempts >= $2
				                          THEN 'The processing server stopped responding ' || due.attempts || ' times.'
				                          ELSE s.error_text END,
				        lease_until = NULL, leased_by = NULL, updated_at = now()
				   FROM due WHERE s.id = due.id
				 RETURNING s.id",
				array($batchId, self::MAX_ATTEMPTS));
			if (count($rows) > 0) {
				$ids = '{' . implode(',', array_map(function ($r) { return (int)$r['id']; }, $rows)) . '}';
				$db->q(
					"UPDATE voicestations.runs
					    SET status = 'failed', error_text = 'lease expired (the worker stopped responding)',
					        finished_at = now()
					  WHERE station_id = ANY ($1::bigint[]) AND status = 'running'",
					array($ids));
			}
			if ($own) {
				$db->commit();
			}
		} catch (Exception $e) {
			if ($own) {
				$db->rollback();
			}
			throw $e;
		}
		return count($rows);
	}

	// ---------------------------------------------------------------- claim

	/**
	 * POST /voiceworker/v1/claim
	 * Body: {"kinds": ["transcribe", "extract"],
	 *        "transcribe": {"engine": "whisper.cpp", "model": "large-v3-turbo"},
	 *        "extract": {"engine": "anthropic", "model": "...", "prompt_version": "v1"}}
	 * Each claimed kind names what will run it (stored on the run row; the
	 * result may restate it, e.g. after a fallback to another engine).
	 * 200 with one job, or 204 when there is nothing to do.
	 */
	public function claim() {
		$body = VsHttp::readJson(65536);
		$kinds = VsHttp::prop($body, 'kinds');
		if (!is_array($kinds) || count($kinds) === 0) {
			throw VsHttp::bad('kinds', 'kinds must list "transcribe" and/or "extract".');
		}
		$engines = array();
		foreach ($kinds as $k) {
			if (!in_array($k, self::KINDS, true)) {
				throw VsHttp::bad('kinds', 'kinds must list "transcribe" and/or "extract".');
			}
			$e = VsHttp::prop($body, $k);
			$engines[$k] = array(
				'engine' => self::shortString($e, 'engine', "$k.engine", true),
				'model' => self::shortString($e, 'model', "$k.model", true),
				'prompt_version' => $k === 'extract' ? self::shortString($e, 'prompt_version', "$k.prompt_version", true) : null,
			);
		}

		self::expireLeases($this->db);

		$this->db->begin();
		try {
			$s = $this->db->row(
				"SELECT s.id, s.station_uuid, s.stage, s.batch_id, s.attempts, s.current_transcript_run
				   FROM voicestations.stations s
				   JOIN voicestations.batches b ON b.id = s.batch_id
				  WHERE s.discarded_at IS NULL AND s.confirmed_at IS NULL
				    AND (
				      ($1 AND s.stage = 'uploaded' AND s.audio_path IS NOT NULL AND s.audio_deleted_at IS NULL
				          AND s.stage_since <= now() - make_interval(secs => $3))
				      OR
				      ($2 AND s.stage = 'transcribed' AND s.current_transcript_run IS NOT NULL
				          AND b.complete_at IS NOT NULL
				          AND NOT EXISTS (SELECT 1 FROM voicestations.stations o
				                           WHERE o.batch_id = s.batch_id AND o.discarded_at IS NULL
				                             AND o.stage IN ('uploaded', 'transcribing'))
				          AND greatest(s.stage_since, b.complete_at,
				                       (SELECT max(o.stage_since) FROM voicestations.stations o
				                         WHERE o.batch_id = s.batch_id AND o.discarded_at IS NULL))
				              <= now() - make_interval(secs => $3))
				    )
				  ORDER BY b.created_at, b.id, s.started_at, s.id
				  LIMIT 1
				  FOR UPDATE OF s SKIP LOCKED",
				array(isset($engines['transcribe']) ? 'true' : 'false',
					isset($engines['extract']) ? 'true' : 'false', $this->delay));
			if ($s === null) {
				$this->db->commit();
				return array(204, null);
			}

			$kind = $s['stage'] === 'uploaded' ? 'transcribe' : 'extract';
			$eng = $engines[$kind];
			$run = $this->db->row(
				"INSERT INTO voicestations.runs
				   (station_id, kind, transcript_run_id, status, worker, engine, model, prompt_version)
				 VALUES ($1, $2, $3, 'running', $4, $5, $6, $7)
				 RETURNING id",
				array($s['id'], $kind, $kind === 'extract' ? $s['current_transcript_run'] : null,
					$this->name, $eng['engine'], $eng['model'], $eng['prompt_version']));
			$lease = $this->db->row(
				"UPDATE voicestations.stations
				    SET stage = $2, stage_since = now(), attempts = attempts + 1,
				        lease_until = now() + make_interval(secs => $3), leased_by = $4, updated_at = now()
				  WHERE id = $1
				 RETURNING attempts, " . MsDb::iso('lease_until') . " AS lease_until",
				array($s['id'], $kind === 'transcribe' ? 'transcribing' : 'extracting',
					self::LEASE_SECONDS, $this->name));
			$job = $kind === 'transcribe'
				? $this->transcribeJob($s['id'])
				: $this->extractJob($s['id'], $s['batch_id'], $s['current_transcript_run']);
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		return array(200, array('job' => array_merge(array(
			'kind' => $kind,
			'station_uuid' => $s['station_uuid'],
			'run_id' => (int)$run['id'],
			'attempt' => (int)$lease['attempts'],
			'max_attempts' => self::MAX_ATTEMPTS,
			'lease_until' => $lease['lease_until'],
			'lease_seconds' => self::LEASE_SECONDS,
		), $job)));
	}

	private function transcribeJob($stationId) {
		$a = $this->db->row(
			"SELECT station_uuid, audio_mime, audio_bytes, audio_sha256, audio_seconds
			   FROM voicestations.stations WHERE id = $1", array($stationId));
		return array('audio' => array(
			'path' => 'jobs/' . $a['station_uuid'] . '/audio',
			'mime' => $a['audio_mime'],
			'bytes' => (int)$a['audio_bytes'],
			'sha256' => $a['audio_sha256'],
			'seconds_reported' => $a['audio_seconds'] === null ? null : (float)$a['audio_seconds'],
		));
	}

	/**
	 * What extraction reads: this station's transcript, what it is about
	 * (new or existing Spot, time, best fix, strike convention, photos) and
	 * the transcripts of the batch's earlier live stations as read-only
	 * context (P6.6).
	 */
	private function extractJob($stationId, $batchId, $transcriptRunId) {
		$s = $this->db->row(
			"SELECT s.target_kind, s.target_spot_id, s.target_spot::text AS target_spot,
			        " . MsDb::iso('s.started_at') . " AS started_at,
			        " . MsDb::iso('s.ended_at') . " AS ended_at,
			        s.tz_offset_minutes, s.strike_convention, s.photos::text AS photos,
			        s.best_lat, s.best_lon, s.best_alt, s.best_accuracy,
			        " . MsDb::iso('s.best_fix_at') . " AS best_fix_at,
			        s.audio_seconds, t.output::text AS transcript
			   FROM voicestations.stations s
			   JOIN voicestations.runs t ON t.id = $2
			  WHERE s.id = $1", array($stationId, $transcriptRunId));
		$earlier = $this->db->rows(
			"SELECT o.station_uuid, " . MsDb::iso('o.started_at') . " AS started_at,
			        t.output->>'text' AS text
			   FROM voicestations.stations o
			   JOIN voicestations.stations me ON me.id = $2
			   JOIN voicestations.runs t ON t.id = o.current_transcript_run
			  WHERE o.batch_id = $1 AND o.id <> me.id AND o.discarded_at IS NULL
			    AND (o.started_at, o.id) < (me.started_at, me.id)
			  ORDER BY o.started_at, o.id", array($batchId, $stationId));
		$context = array();
		foreach ($earlier as $i => $e) {
			$context[] = array('station_uuid' => $e['station_uuid'], 'started_at' => $e['started_at'],
				'transcript' => $e['text']);
		}
		return array(
			'transcript_run_id' => (int)$transcriptRunId,
			'transcript' => json_decode($s['transcript']),
			'station' => array(
				'target_kind' => $s['target_kind'],
				'target_spot_id' => $s['target_spot_id'],
				'target_spot' => $s['target_spot'] === null ? null : json_decode($s['target_spot']),
				'started_at' => $s['started_at'],
				'ended_at' => $s['ended_at'],
				'tz_offset_minutes' => $s['tz_offset_minutes'] === null ? null : (int)$s['tz_offset_minutes'],
				'audio_seconds' => $s['audio_seconds'] === null ? null : (float)$s['audio_seconds'],
				'strike_convention' => $s['strike_convention'],
				'photos' => json_decode($s['photos']),
				'best_fix' => $s['best_accuracy'] === null ? null : array(
					'lat' => (float)$s['best_lat'], 'lon' => (float)$s['best_lon'],
					'alt' => $s['best_alt'] === null ? null : (float)$s['best_alt'],
					'accuracy' => (float)$s['best_accuracy'], 'time' => $s['best_fix_at'],
				),
			),
			'earlier_stations' => $context,
		);
	}

	// ---------------------------------------------------------------- live run check

	/**
	 * Lock the station and check $runId is still this worker's live run on
	 * it. 404 unknown station, 410 discarded/confirmed (the run is closed
	 * as failed and committed), 409 lease_lost otherwise. Call inside a
	 * transaction.
	 */
	private function lockLive($stationUuid, $runId, $closeIfGone = true) {
		$s = $this->db->row(
			"SELECT id, stage, leased_by, attempts, current_transcript_run, audio_path, audio_mime,
			        audio_bytes, audio_deleted_at,
			        discarded_at IS NOT NULL OR confirmed_at IS NOT NULL AS settled
			   FROM voicestations.stations WHERE station_uuid = $1 FOR UPDATE",
			array($stationUuid));
		if ($s === null) {
			throw VsHttp::notFound();
		}
		$run = null;
		if ($runId !== null) {
			$run = $this->db->row(
				"SELECT id, kind, status, worker, transcript_run_id FROM voicestations.runs
				  WHERE id = $1 AND station_id = $2", array($runId, $s['id']));
		}
		$live = $run !== null && $run['status'] === 'running' && $run['worker'] === $this->name
			&& $s['leased_by'] === $this->name
			&& $s['stage'] === ($run['kind'] === 'transcribe' ? 'transcribing' : 'extracting');
		if (MsDb::bool($s['settled'])) {
			if ($live && $closeIfGone) {
				$this->closeRun($run['id'], 'station discarded or confirmed while running');
				$this->db->q(
					"UPDATE voicestations.stations SET stage = $2, lease_until = NULL, leased_by = NULL,
					        updated_at = now() WHERE id = $1",
					array($s['id'], $run['kind'] === 'transcribe' ? 'uploaded' : 'transcribed'));
			}
			$this->db->commit(); // keep the closed run; the caller's rollback is then a no-op
			throw new VsHttpError(410, 'gone', 'This station was discarded or confirmed; drop the job.');
		}
		if (!$live) {
			throw new VsHttpError(409, 'lease_lost', 'This job is no longer yours; drop it.');
		}
		return array($s, $run);
	}

	private function closeRun($runId, $error) {
		$this->db->q(
			"UPDATE voicestations.runs SET status = 'failed', error_text = $2, finished_at = now()
			  WHERE id = $1", array($runId, $error));
	}

	private static function runIdFrom($body) {
		$id = VsHttp::prop($body, 'run_id');
		if (!is_int($id) || $id <= 0) {
			throw VsHttp::bad('run_id', 'run_id must be the run id from the claim.');
		}
		return $id;
	}

	// ---------------------------------------------------------------- audio

	/** GET /voiceworker/v1/jobs/{uuid}/audio: lease holder of a transcription job only. */
	public function audio($stationUuid) {
		$s = $this->db->row(
			"SELECT stage, leased_by, lease_until > now() AS leased, audio_path, audio_mime,
			        audio_deleted_at IS NOT NULL AS deleted,
			        discarded_at IS NOT NULL OR confirmed_at IS NOT NULL AS settled
			   FROM voicestations.stations WHERE station_uuid = $1", array($stationUuid));
		if ($s === null) {
			throw VsHttp::notFound();
		}
		if (MsDb::bool($s['settled']) || MsDb::bool($s['deleted'])) {
			throw new VsHttpError(410, 'gone', 'This station was discarded or confirmed; drop the job.');
		}
		if ($s['stage'] !== 'transcribing' || $s['leased_by'] !== $this->name || !MsDb::bool($s['leased'])) {
			throw new VsHttpError(409, 'lease_lost', 'This job is no longer yours; drop it.');
		}
		$path = VsConfig::dataRoot() . '/' . $s['audio_path'];
		if ($s['audio_path'] === null || !is_file($path)) {
			VsLog::error("worker {$this->name}: audio file missing for station $stationUuid: $path");
			throw VsHttp::notFound('The audio file is missing on the server.');
		}
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		http_response_code(200);
		header('Content-Type: ' . ($s['audio_mime'] ?: 'audio/mp4'));
		header('Cache-Control: no-store');
		header('Content-Length: ' . filesize($path));
		readfile($path);
		exit;
	}

	// ---------------------------------------------------------------- heartbeat

	/** POST /voiceworker/v1/jobs/{uuid}/heartbeat {"run_id": n}: extend the lease. */
	public function heartbeat($stationUuid) {
		$body = VsHttp::readJson(4096);
		$runId = self::runIdFrom($body);
		$this->db->begin();
		try {
			list($s) = $this->lockLive($stationUuid, $runId);
			$r = $this->db->row(
				"UPDATE voicestations.stations
				    SET lease_until = now() + make_interval(secs => $2), updated_at = now()
				  WHERE id = $1
				 RETURNING " . MsDb::iso('lease_until') . " AS lease_until",
				array($s['id'], self::LEASE_SECONDS));
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		return array(200, array('lease_until' => $r['lease_until'], 'lease_seconds' => self::LEASE_SECONDS));
	}

	// ---------------------------------------------------------------- result

	/**
	 * POST /voiceworker/v1/jobs/{uuid}/result
	 * transcribe: {run_id, engine?, model?, settings?, audio_seconds, raw_output,
	 *              output: {text, segments: [{start, end, text}], words: [{w, start, end, p}]}}
	 * extract:    {run_id, engine?, model?, prompt_version?, settings?, raw_output,
	 *              output: {...proposal}, validation, input_tokens?, output_tokens?}
	 */
	public function result($stationUuid) {
		$body = VsHttp::readJson(self::MAX_RESULT_BYTES);
		$runId = self::runIdFrom($body);

		$this->db->begin();
		try {
			list($s, $run) = $this->lockLive($stationUuid, $runId);
			$kind = $run['kind'];
			$v = $kind === 'transcribe' ? self::checkTranscript($body) : self::checkProposal($body);

			if ($kind === 'extract' && (int)$run['transcript_run_id'] !== (int)$s['current_transcript_run']) {
				$this->closeRun($run['id'], 'stale transcript');
				$this->db->q(
					"UPDATE voicestations.stations SET stage = 'transcribed', stage_since = now(), attempts = 0,
					        lease_until = NULL, leased_by = NULL, updated_at = now() WHERE id = $1",
					array($s['id']));
				$this->db->commit();
				throw new VsHttpError(409, 'stale_transcript', 'The transcript changed while this ran; drop the result.');
			}

			$this->db->q(
				"UPDATE voicestations.runs
				    SET status = 'done', finished_at = now(),
				        engine = coalesce($2, engine), model = coalesce($3, model),
				        prompt_version = coalesce($4, prompt_version),
				        settings = $5::jsonb, raw_output = $6, output = $7::jsonb, validation = $8::jsonb,
				        input_tokens = $9, output_tokens = $10
				  WHERE id = $1",
				array($run['id'], $v['engine'], $v['model'], $v['prompt_version'], $v['settings'],
					$v['raw_output'], $v['output'], $v['validation'], $v['input_tokens'], $v['output_tokens']));
			if ($kind === 'transcribe') {
				$this->db->q(
					"UPDATE voicestations.stations
					    SET stage = 'transcribed', stage_since = now(), attempts = 0, error_text = NULL,
					        lease_until = NULL, leased_by = NULL, current_transcript_run = $2,
					        audio_seconds = $3, updated_at = now()
					  WHERE id = $1", array($s['id'], $run['id'], $v['audio_seconds']));
				$stage = 'transcribed';
			} else {
				$this->db->q(
					"UPDATE voicestations.stations
					    SET stage = 'ready', stage_since = now(), attempts = 0, error_text = NULL,
					        lease_until = NULL, leased_by = NULL, current_proposal_run = $2, updated_at = now()
					  WHERE id = $1", array($s['id'], $run['id']));
				$stage = 'ready';
			}
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		return array(200, array('station_uuid' => $stationUuid, 'stage' => $stage));
	}

	private static function common($body) {
		$raw = VsHttp::prop($body, 'raw_output');
		if (!is_string($raw)) {
			throw VsHttp::bad('raw_output', 'raw_output must be the engine output as text.');
		}
		if (strlen($raw) > self::MAX_RAW_BYTES) {
			throw VsHttp::bad('raw_output', 'raw_output is larger than 1 MB.');
		}
		$settings = VsHttp::prop($body, 'settings', new stdClass());
		if (!is_object($settings)) {
			throw VsHttp::bad('settings', 'settings must be a JSON object.');
		}
		$out = VsHttp::prop($body, 'output');
		if (!is_object($out)) {
			throw VsHttp::bad('output', 'output must be a JSON object.');
		}
		return array(
			'engine' => self::shortString($body, 'engine', 'engine', false),
			'model' => self::shortString($body, 'model', 'model', false),
			'prompt_version' => null,
			'settings' => json_encode($settings, VsHttp::JSON_OUT),
			'raw_output' => $raw,
			'output' => $out,
			'validation' => null,
			'input_tokens' => null,
			'output_tokens' => null,
			'audio_seconds' => null,
		);
	}

	private static function checkTranscript($body) {
		$v = self::common($body);
		$out = $v['output'];
		$secs = VsHttp::prop($body, 'audio_seconds');
		if (!self::isNum($secs) || $secs < 0 || $secs > VsConfig::MAX_SECONDS + 60) {
			throw VsHttp::bad('audio_seconds', 'audio_seconds must be the measured audio length in seconds.');
		}
		$v['audio_seconds'] = $secs;
		if (!is_string(VsHttp::prop($out, 'text'))) {
			throw VsHttp::bad('output.text', 'output.text must be the transcript text (may be empty).');
		}
		$segs = VsHttp::prop($out, 'segments');
		if (!is_array($segs) || count($segs) > self::MAX_SEGMENTS) {
			throw VsHttp::bad('output.segments', 'output.segments must be a list.');
		}
		foreach ($segs as $i => $g) {
			if (!self::isNum(VsHttp::prop($g, 'start')) || !self::isNum(VsHttp::prop($g, 'end'))
					|| !is_string(VsHttp::prop($g, 'text'))) {
				throw VsHttp::bad("output.segments[$i]", 'Each segment needs start, end (seconds) and text.');
			}
		}
		$words = VsHttp::prop($out, 'words');
		if (!is_array($words) || count($words) > self::MAX_WORDS) {
			throw VsHttp::bad('output.words', 'output.words must be a list.');
		}
		foreach ($words as $i => $w) {
			$p = VsHttp::prop($w, 'p');
			if (!is_string(VsHttp::prop($w, 'w')) || !self::isNum(VsHttp::prop($w, 'start'))
					|| !self::isNum(VsHttp::prop($w, 'end')) || !($p === null || self::isNum($p))) {
				throw VsHttp::bad("output.words[$i]", 'Each word needs w, start, end (seconds) and p (or null).');
			}
		}
		$v['output'] = json_encode($out, VsHttp::JSON_OUT);
		return $v;
	}

	private static function checkProposal($body) {
		$v = self::common($body);
		$v['output'] = json_encode($v['output'], VsHttp::JSON_OUT);
		$v['prompt_version'] = self::shortString($body, 'prompt_version', 'prompt_version', false);
		$val = VsHttp::prop($body, 'validation');
		if (!is_object($val) && !is_array($val)) {
			throw VsHttp::bad('validation', 'validation must be the check results (JSON object or list).');
		}
		$v['validation'] = json_encode($val, VsHttp::JSON_OUT);
		foreach (array('input_tokens', 'output_tokens') as $k) {
			$n = VsHttp::prop($body, $k);
			if ($n !== null && (!is_int($n) || $n < 0)) {
				throw VsHttp::bad($k, "$k must be a whole number.");
			}
			$v[$k] = $n;
		}
		return $v;
	}

	// ---------------------------------------------------------------- fail

	/**
	 * POST /voiceworker/v1/jobs/{uuid}/fail {"run_id": n, "error": "...", "retry": true|false}
	 * retry true and attempts left: back to the queue; otherwise the station
	 * fails with the error, which the app shows, so write it for people.
	 */
	public function fail($stationUuid) {
		$body = VsHttp::readJson(65536);
		$runId = self::runIdFrom($body);
		$error = VsHttp::prop($body, 'error');
		if (!is_string($error) || trim($error) === '') {
			throw VsHttp::bad('error', 'error must say what went wrong.');
		}
		$error = mb_substr(trim($error), 0, self::MAX_ERROR_CHARS);
		$retry = VsHttp::prop($body, 'retry');
		if (!is_bool($retry)) {
			throw VsHttp::bad('retry', 'retry must be true or false.');
		}

		$this->db->begin();
		try {
			list($s, $run) = $this->lockLive($stationUuid, $runId);
			$this->closeRun($run['id'], $error);
			$requeue = $retry && (int)$s['attempts'] < self::MAX_ATTEMPTS;
			if ($requeue) {
				// stage_since kept: the claim set it, so a fallback worker's delay already runs
				$stage = $run['kind'] === 'transcribe' ? 'uploaded' : 'transcribed';
				$this->db->q(
					"UPDATE voicestations.stations SET stage = $2, lease_until = NULL, leased_by = NULL,
					        updated_at = now() WHERE id = $1", array($s['id'], $stage));
			} else {
				$stage = 'failed';
				$this->db->q(
					"UPDATE voicestations.stations SET stage = 'failed', stage_since = now(), error_text = $2,
					        lease_until = NULL, leased_by = NULL, updated_at = now() WHERE id = $1",
					array($s['id'], $error));
			}
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		return array(200, array('station_uuid' => $stationUuid, 'stage' => $stage,
			'attempts' => (int)$s['attempts'], 'max_attempts' => self::MAX_ATTEMPTS));
	}

	// ---------------------------------------------------------------- helpers

	private static function isNum($x) {
		return (is_int($x) || is_float($x)) && is_finite((float)$x);
	}

	private static function shortString($obj, $key, $field, $required) {
		$v = VsHttp::prop($obj, $key);
		if ($v === null && !$required) {
			return null;
		}
		if (!is_string($v) || trim($v) === '' || strlen($v) > 100) {
			throw VsHttp::bad($field, "$field must be a short name.");
		}
		return trim($v);
	}
}
