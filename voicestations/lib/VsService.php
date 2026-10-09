<?php
/**
 * File: VsService.php
 * Description: The user side of Voice Stations (build step 1): station
 *              upload, batch status + results, audio for the owner, confirm
 *              or discard, retry of a failed station. Called by the /db/
 *              Voice* controllers after Apache's Basic Auth gate; every query
 *              is filtered on the login's userpkey.
 *
 *              The server proposes, the app writes: nothing here touches
 *              Neo4j except one read-only ownership check at upload.
 *
 *              Design: docs/AlternateStraboFieldIdea/Phase1_Plan.md (P5, P6,
 *              P8, P10 and the 10-07 step 1 decisions); DDL
 *              sql/voice_stations.sql.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class VsService {

	private $db;      // MsDb (throws on any SQL error)
	private $neodb;
	private $upk;

	public function __construct($strabodb, $neodb, $userpkey) {
		$this->db = new MsDb($strabodb);
		$this->neodb = $neodb;
		$this->upk = (int)$userpkey;
	}

	/** Gate every route: allowed testers only (VOICESTATIONS_ALLOW). */
	public function requireAccess() {
		if (!VsConfig::allowed($this->db, $this->upk)) {
			throw new VsHttpError(403, 'not_available', 'Strabo Voice is not available for this account.');
		}
	}

	/**
	 * GET /db/voicestation: the app's access check at login (step 4 point 3).
	 * Reaching here means the gate passed; the limits let the app warn
	 * before a recording runs past them.
	 */
	public function access() {
		return array(200, array(
			'available' => true,
			'max_seconds' => VsConfig::MAX_SECONDS,
			'max_bytes' => VsConfig::MAX_AUDIO_BYTES,
			'max_photos' => VsConfig::MAX_PHOTOS,
			'consent' => array(
				'version' => VsConfig::CONSENT_VERSION,
				'accepted' => $this->consentAcceptedAt() !== null,
			),
		));
	}

	// ---------------------------------------------------------------- consent

	/** When this account agreed to the current consent text (ISO), or null. */
	private function consentAcceptedAt() {
		return $this->db->val(
			"SELECT " . MsDb::iso('accepted_at') . " FROM voicestations.consents
			  WHERE userpkey = $1 AND version = $2",
			array($this->upk, VsConfig::CONSENT_VERSION));
	}

	/** Uploads need the current consent (step 6 consent point 2). */
	private function requireConsent() {
		if ($this->consentAcceptedAt() === null) {
			throw new VsHttpError(403, 'consent_required',
				'Read and agree to the Strabo Voice trial terms in the app before uploading recordings.');
		}
	}

	/** GET /db/voiceconsent: the current text and whether this account agreed. */
	public function getConsent() {
		return array(200, array(
			'version' => VsConfig::CONSENT_VERSION,
			'accepted_at' => $this->consentAcceptedAt(),
			'text' => VsConfig::consentText(),
		));
	}

	/**
	 * POST /db/voiceconsent {"version": N, "app_version", "device_model"}:
	 * the tester tapped I agree. 201 new, 200 already agreed; a version that
	 * is not the current one = 409 consent_outdated (the text changed while
	 * the app showed the old one).
	 */
	public function acceptConsent() {
		$body = VsHttp::readJson(4096);
		$version = VsHttp::prop($body, 'version');
		if (!is_int($version)) {
			throw VsHttp::bad('version', 'version must be the consent version the tester read.');
		}
		if ($version !== VsConfig::CONSENT_VERSION) {
			throw new VsHttpError(409, 'consent_outdated',
				'The Strabo Voice trial terms have changed. Read them again before agreeing.');
		}
		$meta = array();
		foreach (array('app_version', 'device_model') as $k) {
			$v = VsHttp::prop($body, $k);
			if ($v !== null && (!is_string($v) || strlen($v) > 100)) {
				throw VsHttp::bad($k, "$k must be a short text.");
			}
			$meta[$k] = $v;
		}
		$n = $this->db->val(
			"WITH ins AS (
			   INSERT INTO voicestations.consents (userpkey, version, app_version, device_model)
			   VALUES ($1, $2, $3, $4)
			   ON CONFLICT (userpkey, version) DO NOTHING RETURNING 1)
			 SELECT count(*) FROM ins",
			array($this->upk, $version, $meta['app_version'], $meta['device_model']));
		return array((int)$n === 1 ? 201 : 200, array(
			'version' => $version,
			'accepted_at' => $this->consentAcceptedAt(),
			'existing' => (int)$n !== 1,
		));
	}

	// ---------------------------------------------------------------- upload

	/**
	 * POST /db/voicestation: multipart "details" (JSON) + "audio" (.m4a).
	 * 201 for a new station, 200 for a resend of one already stored (the
	 * phone retries on bad signal; nothing is duplicated).
	 */
	public function upload() {
		if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
			// PHP drops the whole body when it exceeds post_max_size
			throw new VsHttpError(413, 'too_large', 'The upload is too large.');
		}
		$this->requireConsent();
		$d = VsHttp::decodeObject(isset($_POST['details']) ? $_POST['details'] : '', 'details');
		$v = VsDetails::check($d);

		$audio = isset($_FILES['audio']) ? $_FILES['audio'] : null;
		$this->checkAudio($audio);

		$root = VsConfig::dataRoot();
		if (!is_dir($root . '/audio') || !is_writable($root . '/audio')) {
			VsLog::error('audio folder missing or not writable: ' . $root . '/audio');
			throw new VsHttpError(503, 'unavailable', 'Strabo Voice cannot store recordings right now. Please try again later.');
		}

		// A resend of a station this user already stored needs no Neo4j check.
		$existing = $this->db->row(
			"SELECT s.userpkey, b.batch_uuid FROM voicestations.stations s
			   JOIN voicestations.batches b ON b.id = s.batch_id
			  WHERE s.station_uuid = $1", array($v['station_uuid']));
		if ($existing === null) {
			$this->checkOwnership($v['project_id'], $v['dataset_id']);
		}

		$finalPath = null;
		$this->db->begin();
		try {
			$batch = $this->lockBatch($v);

			$row = $this->db->row(
				"INSERT INTO voicestations.stations
				   (station_uuid, batch_id, userpkey, target_kind, target_spot_id, target_spot,
				    started_at, ended_at, tz_offset_minutes, gps_fixes,
				    best_lat, best_lon, best_alt, best_accuracy, best_fix_at,
				    photos, strike_convention, app_version, device_model, details,
				    audio_mime, audio_seconds, recorded_on, watch_model)
				 VALUES ($1, $2, $3, $4, $5, $6::jsonb, $7, $8, $9, $10::jsonb,
				         $11, $12, $13, $14, $15, $16::jsonb, $17, $18, $19, $20::jsonb, $21, $22, $23, $24)
				 ON CONFLICT (station_uuid) DO NOTHING
				 RETURNING id",
				array(
					$v['station_uuid'], $batch['id'], $this->upk, $v['target_kind'], $v['target_spot_id'], $v['target_spot'],
					$v['started_at'], $v['ended_at'], $v['tz_offset_minutes'], $v['gps_fixes'],
					$v['best'] ? $v['best']['lat'] : null, $v['best'] ? $v['best']['lon'] : null,
					$v['best'] ? $v['best']['alt'] : null, $v['best'] ? $v['best']['accuracy'] : null,
					$v['best'] ? $v['best']['time'] : null,
					$v['photos'], $v['strike_convention'], $v['app_version'], $v['device_model'],
					json_encode($d, VsHttp::JSON_OUT), $v['audio_mime'], $v['audio_seconds'],
					$v['recorded_on'], $v['watch_model'],
				));

			if ($row === null) {
				// Already stored (an earlier try, or a parallel one that just won).
				$old = $this->db->row(
					"SELECT userpkey, batch_id FROM voicestations.stations WHERE station_uuid = $1",
					array($v['station_uuid']));
				if ((int)$old['userpkey'] !== $this->upk) {
					throw VsHttp::conflict('This recording\'s UUID is already in use.');
				}
				if ((int)$old['batch_id'] !== (int)$batch['id']) {
					throw VsHttp::conflict('This recording was uploaded in another batch.');
				}
				$status = 200;
			} else {
				$finalPath = $this->storeAudio($audio, (int)$row['id'], $v['station_uuid']);
				$status = 201;
			}

			$progress = $this->updateCompleteness($batch['id']);
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			if ($finalPath !== null) {
				@unlink($finalPath);
			}
			throw $e;
		}

		$st = $this->db->row(
			"SELECT stage FROM voicestations.stations WHERE station_uuid = $1", array($v['station_uuid']));
		return array($status, array(
			'station_uuid' => $v['station_uuid'],
			'stage' => $st['stage'],
			'stored' => $status === 201 ? 'new' : 'already_stored',
			'batch' => $progress,
		));
	}

	private function checkAudio($audio) {
		if ($audio === null || !isset($audio['error'])) {
			throw VsHttp::bad('audio', 'The recording\'s audio file is required.');
		}
		if ($audio['error'] === UPLOAD_ERR_INI_SIZE || $audio['error'] === UPLOAD_ERR_FORM_SIZE) {
			throw new VsHttpError(413, 'too_large', 'The audio is larger than 20 MB.');
		}
		if ($audio['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($audio['tmp_name'])) {
			throw VsHttp::bad('audio', 'The audio file did not arrive whole. Please upload again.');
		}
		if ($audio['size'] > VsConfig::MAX_AUDIO_BYTES) {
			throw new VsHttpError(413, 'too_large', 'The audio is larger than 20 MB.');
		}
		if ($audio['size'] < 12) {
			throw VsHttp::bad('audio', 'The audio file is empty.');
		}
		// MPEG-4 audio (.m4a) starts with a box whose type is "ftyp"
		$h = @file_get_contents($audio['tmp_name'], false, null, 0, 12);
		if ($h === false || substr($h, 4, 4) !== 'ftyp') {
			throw VsHttp::bad('audio', 'The audio must be an .m4a (MPEG-4 audio) file.');
		}
	}

	/**
	 * Is this project + dataset the user's own? Anchored on the User node
	 * (Strabo ids are not unique across users). Ids are digits only
	 * (VsDetails), so they are safe to place in the Cypher; matched as a
	 * number or as a string, since older nodes may hold either.
	 */
	private function checkOwnership($projectId, $datasetId) {
		try {
			$n = $this->neodb->get_var(
				"MATCH (u:User {userpkey: {$this->upk}})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)
				 WHERE (p.id = $projectId OR p.id = '$projectId')
				   AND (d.id = $datasetId OR d.id = '$datasetId')
				 RETURN count(d) AS n");
		} catch (Exception $e) {
			if (method_exists($this->neodb, 'reconnect')) {
				$this->neodb->reconnect();
			}
			throw $e;
		}
		if ((int)$n === 0) {
			throw new VsHttpError(404, 'not_found', 'That project and dataset were not found in your StraboSpot account.', 'dataset_id');
		}
	}

	/** Create or lock the batch row; check it is this user's and dataset's. */
	private function lockBatch($v) {
		$list = '{' . implode(',', $v['batch_station_uuids']) . '}';
		$this->db->q(
			"INSERT INTO voicestations.batches (batch_uuid, userpkey, project_id, dataset_id, station_uuids)
			 VALUES ($1, $2, $3, $4, $5::uuid[])
			 ON CONFLICT (batch_uuid) DO NOTHING",
			array($v['batch_uuid'], $this->upk, $v['project_id'], $v['dataset_id'], $list));
		$b = $this->db->row(
			"SELECT id, userpkey, project_id, dataset_id FROM voicestations.batches
			  WHERE batch_uuid = $1 FOR UPDATE", array($v['batch_uuid']));
		if ((int)$b['userpkey'] !== $this->upk) {
			throw VsHttp::conflict('This batch UUID is already in use.');
		}
		if ($b['project_id'] !== $v['project_id'] || $b['dataset_id'] !== $v['dataset_id']) {
			throw VsHttp::conflict('This batch belongs to another project or dataset.');
		}
		// the latest list the phone sent wins
		$this->db->q(
			"UPDATE voicestations.batches SET station_uuids = $2::uuid[], updated_at = now()
			  WHERE id = $1 AND station_uuids IS DISTINCT FROM $2::uuid[]",
			array($b['id'], $list));
		return $b;
	}

	/** Move the upload into audio/<userpkey>/<station_uuid>.m4a. */
	private function storeAudio($audio, $stationId, $stationUuid) {
		$dir = VsConfig::dataRoot() . '/audio/' . $this->upk;
		if (!is_dir($dir) && !@mkdir($dir, 0775) && !is_dir($dir)) {
			throw new Exception("cannot create $dir");
		}
		$rel = 'audio/' . $this->upk . '/' . $stationUuid . '.m4a';
		$final = VsConfig::dataRoot() . '/' . $rel;
		$part = $final . '.part';
		if (!move_uploaded_file($audio['tmp_name'], $part)) {
			throw new Exception("cannot move the upload to $part");
		}
		if (!rename($part, $final)) {
			@unlink($part);
			throw new Exception("cannot rename $part");
		}
		$this->db->q(
			"UPDATE voicestations.stations
			    SET audio_path = $2, audio_bytes = $3, audio_sha256 = $4, updated_at = now()
			  WHERE id = $1",
			array($stationId, $rel, filesize($final), hash_file('sha256', $final)));
		return $final;
	}

	/** Set or clear complete_at; return received / expected / missing. */
	private function updateCompleteness($batchId) {
		$r = $this->db->row(
			"SELECT cardinality(b.station_uuids) AS expected,
			        (SELECT count(*) FROM voicestations.stations s
			          WHERE s.batch_id = b.id AND s.station_uuid = ANY (b.station_uuids)) AS received,
			        (SELECT coalesce(array_to_json(array_agg(u)), '[]')
			           FROM unnest(b.station_uuids) AS u
			          WHERE NOT EXISTS (SELECT 1 FROM voicestations.stations s
			                             WHERE s.batch_id = b.id AND s.station_uuid = u)) AS missing
			   FROM voicestations.batches b WHERE b.id = $1", array($batchId));
		$complete = (int)$r['received'] === (int)$r['expected'];
		$this->db->q(
			"UPDATE voicestations.batches
			    SET complete_at = CASE WHEN $2 THEN coalesce(complete_at, now()) ELSE NULL END
			  WHERE id = $1", array($batchId, $complete ? 'true' : 'false'));
		return array(
			'complete' => $complete,
			'expected' => (int)$r['expected'],
			'received' => (int)$r['received'],
			'missing' => json_decode($r['missing']),
		);
	}

	// ---------------------------------------------------------------- batch

	/**
	 * GET /db/voicebatch/{batch_uuid}: progress, and once done, every
	 * station's results together (P6.6). Done = complete and every station
	 * not discarded is ready or failed. Sweeps the batch's expired worker
	 * leases first.
	 */
	public function batch($batchUuid) {
		$b = $this->db->row(
			"SELECT id, batch_uuid, complete_at IS NOT NULL AS complete
			   FROM voicestations.batches WHERE batch_uuid = $1 AND userpkey = $2",
			array($batchUuid, $this->upk));
		if ($b === null) {
			throw VsHttp::notFound();
		}
		// no cron: a worker that died gives its stations back here too
		VsWorker::expireLeases($this->db, (int)$b['id']);
		$progress = $this->db->row(
			"SELECT cardinality(b.station_uuids) AS expected,
			        (SELECT count(*) FROM voicestations.stations s
			          WHERE s.batch_id = b.id AND s.station_uuid = ANY (b.station_uuids)) AS received,
			        (SELECT coalesce(array_to_json(array_agg(u)), '[]')
			           FROM unnest(b.station_uuids) AS u
			          WHERE NOT EXISTS (SELECT 1 FROM voicestations.stations s
			                             WHERE s.batch_id = b.id AND s.station_uuid = u)) AS missing
			   FROM voicestations.batches b WHERE b.id = $1", array($b['id']));

		$stages = array('uploaded' => 0, 'transcribing' => 0, 'transcribed' => 0,
			'extracting' => 0, 'ready' => 0, 'failed' => 0);
		foreach ($this->db->rows(
				"SELECT stage, count(*) AS n FROM voicestations.stations WHERE batch_id = $1 GROUP BY stage",
				array($b['id'])) as $r) {
			$stages[$r['stage']] = (int)$r['n'];
		}
		$total = array_sum($stages);
		$complete = MsDb::bool($b['complete']);
		// discarded stations are never worked on, so they do not hold the batch open
		$open = (int)$this->db->val(
			"SELECT count(*) FROM voicestations.stations
			  WHERE batch_id = $1 AND discarded_at IS NULL AND stage NOT IN ('ready', 'failed')",
			array($b['id']));
		$done = $complete && $total > 0 && $open === 0;

		$out = array(
			'batch_uuid' => $b['batch_uuid'],
			'complete' => $complete,
			'expected' => (int)$progress['expected'],
			'received' => (int)$progress['received'],
			'missing' => json_decode($progress['missing']),
			'stages' => $stages,
			'done' => $done,
		);
		if ($done) {
			$out['stations'] = $this->stationResults($b['id']);
			// the form choices review pickers offer (step 5 point 3); each
			// station's vocab_version says which list its extraction used
			$out['vocab'] = VsWorker::vocab();
		}
		return array(200, $out);
	}

	private function stationResults($batchId) {
		$rows = $this->db->rows(
			"SELECT s.station_uuid, s.stage, s.error_text,
			        " . MsDb::iso('s.started_at') . " AS started_at,
			        " . MsDb::iso('s.ended_at') . " AS ended_at,
			        s.best_lat, s.best_lon, s.best_alt, s.best_accuracy,
			        " . MsDb::iso('s.best_fix_at') . " AS best_fix_at,
			        t.output::text AS transcript,
			        s.current_proposal_run, p.settings->>'vocab_version' AS vocab_version,
			        p.output::text AS proposal, p.validation::text AS validation,
			        c.outcome, " . MsDb::iso('c.created_at') . " AS confirmed_at
			   FROM voicestations.stations s
			   LEFT JOIN voicestations.runs t ON t.id = s.current_transcript_run
			   LEFT JOIN voicestations.runs p ON p.id = s.current_proposal_run
			   LEFT JOIN voicestations.confirms c ON c.station_id = s.id
			  WHERE s.batch_id = $1
			  ORDER BY s.started_at, s.id", array($batchId));
		$out = array();
		foreach ($rows as $r) {
			$failed = $r['stage'] === 'failed';
			$out[] = array(
				'station_uuid' => $r['station_uuid'],
				'stage' => $r['stage'],
				'error' => $failed ? $r['error_text'] : null,
				'started_at' => $r['started_at'],
				'ended_at' => $r['ended_at'],
				'best_fix' => $r['best_accuracy'] === null ? null : array(
					'lat' => (float)$r['best_lat'],
					'lon' => (float)$r['best_lon'],
					'alt' => $r['best_alt'] === null ? null : (float)$r['best_alt'],
					'accuracy' => (float)$r['best_accuracy'],
					'time' => $r['best_fix_at'],
				),
				'transcript' => $r['transcript'] === null ? null : json_decode($r['transcript']),
				'proposal_run' => ($failed || $r['proposal'] === null) ? null : (int)$r['current_proposal_run'],
				'vocab_version' => ($failed || $r['proposal'] === null) ? null : $r['vocab_version'],
				'proposal' => ($failed || $r['proposal'] === null) ? null : json_decode($r['proposal']),
				'validation' => ($failed || $r['validation'] === null) ? null : json_decode($r['validation']),
				'confirmed' => $r['outcome'] === null ? null
					: array('outcome' => $r['outcome'], 'at' => $r['confirmed_at']),
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------- audio

	/**
	 * GET /db/voiceaudio/{station_uuid}: the original audio, owner only.
	 * Sends Content-Length and honours a single byte Range (iOS players
	 * seek with Range requests). Ends the request itself.
	 */
	public function streamAudio($stationUuid) {
		$s = $this->db->row(
			"SELECT audio_path, audio_mime, audio_bytes FROM voicestations.stations
			  WHERE station_uuid = $1 AND userpkey = $2 AND audio_deleted_at IS NULL",
			array($stationUuid, $this->upk));
		if ($s === null || $s['audio_path'] === null) {
			throw VsHttp::notFound();
		}
		$path = VsConfig::dataRoot() . '/' . $s['audio_path'];
		if (!is_file($path)) {
			VsLog::error("audio file missing for station $stationUuid: $path");
			throw VsHttp::notFound();
		}
		self::sendAudio($path, $s['audio_mime']);
	}

	/**
	 * Stream an audio file with Range support (seeking in a player), then
	 * exit. Shared by GET /db/voiceaudio (owner) and the scoring page.
	 */
	public static function sendAudio($path, $mime) {
		$size = filesize($path);
		$start = 0;
		$end = $size - 1;
		$status = 200;
		if (isset($_SERVER['HTTP_RANGE'])) {
			if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) || ($m[1] === '' && $m[2] === '')) {
				self::rangeNotSatisfiable($size);
			}
			if ($m[1] === '') {                 // last N bytes
				$start = max(0, $size - (int)$m[2]);
			} else {
				$start = (int)$m[1];
				if ($m[2] !== '') {
					$end = min((int)$m[2], $size - 1);
				}
			}
			if ($start > $end || $start >= $size) {
				self::rangeNotSatisfiable($size);
			}
			$status = 206;
		}
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		http_response_code($status);
		header('Content-Type: ' . ($mime ?: 'audio/mp4'));
		header('Accept-Ranges: bytes');
		header('Cache-Control: private, no-store');
		header('Content-Length: ' . ($end - $start + 1));
		if ($status === 206) {
			header("Content-Range: bytes $start-$end/$size");
		}
		$fh = fopen($path, 'rb');
		fseek($fh, $start);
		$left = $end - $start + 1;
		while ($left > 0 && !feof($fh)) {
			$chunk = fread($fh, min(65536, $left));
			echo $chunk;
			$left -= strlen($chunk);
			flush();
		}
		fclose($fh);
		exit;
	}

	private static function rangeNotSatisfiable($size) {
		http_response_code(416);
		header("Content-Range: bytes */$size");
		exit;
	}

	// ---------------------------------------------------------------- confirm

	/**
	 * POST /db/voiceconfirm/{station_uuid}: the confirm or discard record
	 * (P6.6, P10.2). One per station; a resend returns the stored one.
	 *
	 * Body: {"outcome": "confirmed" | "discarded",
	 *        "record": {"values": [{"action": "unchanged"|"edited"|"removed"|"added", ...}],
	 *                   "flags":  [{"action": ..., ...}]},
	 *        "review_seconds": 41.5, "spot_ids": ["..."], "proposal_run": 412}
	 * proposal_run (from the batch reply) is required to confirm a recording
	 * that has a proposal; a newer proposal = 409 stale_proposal.
	 * The record's inner detail follows the proposal format (build step 3);
	 * the server reads only the actions to compute the counts.
	 */
	public function confirm($stationUuid) {
		$body = VsHttp::readJson(VsConfig::MAX_CONFIRM_BYTES);

		$this->db->begin();
		try {
			$s = $this->db->row(
				"SELECT s.id, s.stage, s.current_proposal_run, b.project_id
				   FROM voicestations.stations s
				   JOIN voicestations.batches b ON b.id = s.batch_id
				  WHERE s.station_uuid = $1 AND s.userpkey = $2 FOR UPDATE OF s",
				array($stationUuid, $this->upk));
			if ($s === null) {
				throw VsHttp::notFound();
			}
			$old = $this->confirmRow($s['id']);
			if ($old !== null) {
				$this->db->commit();
				$old['existing'] = true;
				return array(200, $old);
			}

			$outcome = VsHttp::prop($body, 'outcome');
			if ($outcome !== 'confirmed' && $outcome !== 'discarded') {
				throw VsHttp::bad('outcome', 'outcome must be "confirmed" or "discarded".');
			}
			$record = VsHttp::prop($body, 'record', new stdClass());
			if (!is_object($record)) {
				throw VsHttp::bad('record', 'record must be a JSON object.');
			}
			$hasProposal = $s['current_proposal_run'] !== null;
			$n = $this->countRecord($record, $hasProposal);

			// the proposal the phone reviewed must still be the current one
			// (a Retry may have made a newer one); discards need no check
			$run = VsHttp::prop($body, 'proposal_run');
			if ($run !== null && !is_int($run)) {
				throw VsHttp::bad('proposal_run', 'proposal_run must be the id of the proposal reviewed.');
			}
			if ($outcome === 'confirmed') {
				if ($hasProposal && $run === null) {
					throw VsHttp::bad('proposal_run', 'A confirm names the proposal it reviewed (proposal_run).');
				}
				if ($run !== null && $run !== (int)$s['current_proposal_run']) {
					throw new VsHttpError(409, 'stale_proposal', 'This recording has a newer proposal. Reload it and review again.');
				}
			}

			$review = VsHttp::prop($body, 'review_seconds');
			if ($review !== null && (!(is_int($review) || is_float($review)) || $review < 0)) {
				throw VsHttp::bad('review_seconds', 'review_seconds must be a number of seconds.');
			}

			$spotIds = VsHttp::prop($body, 'spot_ids', array());
			if (!is_array($spotIds) || count($spotIds) > VsConfig::MAX_SPOT_IDS) {
				throw VsHttp::bad('spot_ids', 'spot_ids must be a list of at most ' . VsConfig::MAX_SPOT_IDS . ' ids.');
			}
			foreach ($spotIds as $sid) {
				if (!(is_string($sid) || is_int($sid)) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$sid)) {
					throw VsHttp::bad('spot_ids', 'Each Spot id must be a short id.');
				}
			}
			if ($outcome === 'confirmed') {
				if (count($spotIds) === 0) {
					throw VsHttp::bad('spot_ids', 'A confirmed recording names the Spot(s) the app created.');
				}
				if ($s['stage'] !== 'ready' && $s['stage'] !== 'failed') {
					throw VsHttp::conflict('This recording is still being processed.');
				}
			} elseif (count($spotIds) > 0) {
				throw VsHttp::bad('spot_ids', 'A discarded recording has no Spots.');
			}

			$ids = '{' . implode(',', array_map(function ($x) {
				return '"' . $x . '"';
			}, array_map('strval', $spotIds))) . '}';
			$this->db->q(
				"INSERT INTO voicestations.confirms
				   (station_id, userpkey, proposal_run_id, outcome, record,
				    n_proposed, n_unchanged, n_edited, n_removed, n_added, n_flags,
				    review_seconds, spot_ids)
				 VALUES ($1, $2, $3, $4, $5::jsonb, $6, $7, $8, $9, $10, $11, $12, $13::text[])",
				array($s['id'], $this->upk, $s['current_proposal_run'], $outcome,
					json_encode($record, VsHttp::JSON_OUT),
					$n['proposed'], $n['unchanged'], $n['edited'], $n['removed'], $n['added'], $n['flags'],
					$review, $ids));
			$this->db->q(
				"UPDATE voicestations.stations
				    SET " . ($outcome === 'confirmed' ? 'confirmed_at' : 'discarded_at') . " = now(), updated_at = now()
				  WHERE id = $1", array($s['id']));
			$row = $this->confirmRow($s['id']);
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		if ($outcome === 'confirmed') {
			$this->stampProjectUpload($s['project_id']);
		}
		$row['existing'] = false;
		return array(201, $row);
	}

	/**
	 * My Field Data "Last Uploaded" reads Project.uploaddate, which only a
	 * whole-project upload sets. The app uploads just the Voice Spots
	 * dataset, so a new confirm stamps it (Jason 10-09). Cosmetic: a failure
	 * is logged and never fails the confirm.
	 */
	private function stampProjectUpload($projectId) {
		if (!preg_match('/^[0-9]{1,20}$/', (string)$projectId)) {
			return;
		}
		try {
			$this->neodb->query(
				"MATCH (u:User {userpkey: {$this->upk}})-[:HAS_PROJECT]->(p:Project)
				  WHERE p.id = $projectId OR p.id = '$projectId'
				  SET p.uploaddate = " . time());
		} catch (Exception $e) {
			VsLog::error('Project.uploaddate stamp failed: ' . $e->getMessage());
			if (method_exists($this->neodb, 'reconnect')) {
				$this->neodb->reconnect();
			}
		}
	}

	/** Counts from the record's actions (never the phone's own numbers). */
	private function countRecord($record, $hasProposal) {
		$n = array('proposed' => 0, 'unchanged' => 0, 'edited' => 0, 'removed' => 0, 'added' => 0, 'flags' => 0);
		$values = VsHttp::prop($record, 'values', array());
		if (!is_array($values)) {
			throw VsHttp::bad('record.values', 'record.values must be a list.');
		}
		foreach ($values as $i => $item) {
			$a = VsHttp::prop($item, 'action');
			if (!in_array($a, array('unchanged', 'edited', 'removed', 'added'), true)) {
				throw VsHttp::bad("record.values[$i].action", 'action must be unchanged, edited, removed or added.');
			}
			if ($a !== 'added') {
				if (!$hasProposal) {
					throw VsHttp::bad("record.values[$i].action", 'This recording has no proposal; every value is added by hand.');
				}
				$n['proposed']++;
			}
			$n[$a]++;
		}
		$flags = VsHttp::prop($record, 'flags', array());
		if (!is_array($flags)) {
			throw VsHttp::bad('record.flags', 'record.flags must be a list.');
		}
		foreach ($flags as $i => $f) {
			if (!is_string(VsHttp::prop($f, 'action'))) {
				throw VsHttp::bad("record.flags[$i].action", 'Each flag needs the action taken on it.');
			}
		}
		$n['flags'] = count($flags);
		return $n;
	}

	private function confirmRow($stationId) {
		$r = $this->db->row(
			"SELECT outcome, n_proposed, n_unchanged, n_edited, n_removed, n_added, n_flags,
			        review_seconds, array_to_json(spot_ids) AS spot_ids,
			        " . MsDb::iso('created_at') . " AS at
			   FROM voicestations.confirms WHERE station_id = $1", array($stationId));
		if ($r === null) {
			return null;
		}
		return array(
			'outcome' => $r['outcome'],
			'at' => $r['at'],
			'counts' => array(
				'proposed' => (int)$r['n_proposed'], 'unchanged' => (int)$r['n_unchanged'],
				'edited' => (int)$r['n_edited'], 'removed' => (int)$r['n_removed'],
				'added' => (int)$r['n_added'], 'flags' => (int)$r['n_flags'],
			),
			'review_seconds' => $r['review_seconds'] === null ? null : (float)$r['review_seconds'],
			'spot_ids' => json_decode($r['spot_ids']),
		);
	}

	// ---------------------------------------------------------------- retry

	/**
	 * POST /db/voiceretry/{station_uuid}: a failed station goes back to the
	 * stage that failed (extraction if a transcript exists, else
	 * transcription), with a fresh retry count (P8.1).
	 */
	public function retry($stationUuid) {
		$this->db->begin();
		try {
			$s = $this->db->row(
				"SELECT s.id, s.stage, s.current_transcript_run, s.audio_deleted_at, s.discarded_at,
				        c.id AS confirm_id
				   FROM voicestations.stations s
				   LEFT JOIN voicestations.confirms c ON c.station_id = s.id
				  WHERE s.station_uuid = $1 AND s.userpkey = $2 FOR UPDATE OF s",
				array($stationUuid, $this->upk));
			if ($s === null) {
				throw VsHttp::notFound();
			}
			if ($s['stage'] !== 'failed') {
				throw VsHttp::conflict('Only a recording that failed can be retried.');
			}
			if ($s['confirm_id'] !== null) {
				throw VsHttp::conflict('This recording is already confirmed or discarded.');
			}
			if ($s['current_transcript_run'] === null && $s['audio_deleted_at'] !== null) {
				throw VsHttp::conflict('This recording\'s audio is gone, so it cannot be transcribed again.');
			}
			$stage = $s['current_transcript_run'] === null ? 'uploaded' : 'transcribed';
			$this->db->q(
				"UPDATE voicestations.stations
				    SET stage = $2, stage_since = now(), attempts = 0, error_text = NULL,
				        lease_until = NULL, leased_by = NULL, updated_at = now()
				  WHERE id = $1", array($s['id'], $stage));
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		return array(200, array('station_uuid' => $stationUuid, 'stage' => $stage));
	}
}
