<?php
/**
 * File: VsScore.php
 * Description: Voice Stations scoring support (step 6, scoring page points
 *              1-6): the read-only export the Python scorer reads and the
 *              store for its results. Reached only through /voiceworker/v1/
 *              score/* with the scorer token, a separate token that cannot
 *              claim jobs (worker tokens cannot reach score/*):
 *                define('VOICESTATIONS_SCORER', '<sha256 of the token>');
 *              Not defined = scoring closed (401).
 *
 *              The scorer does all the scoring math (one copy of the rules,
 *              Python); this class only hands out data and keeps results.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
class VsScore {

	const STOPWATCH_DATASET = 'stopwatch spots';   // the baseline dataset name (any case)
	const MAX_RESULTS_BYTES = 8388608;            // 8 MB

	private $db;      // MsDb
	private $strabodb;
	private $neodb;

	public function __construct($strabodb, $neodb) {
		$this->strabodb = $strabodb;
		$this->db = new MsDb($strabodb);
		$this->neodb = $neodb;
	}

	/** Does this Authorization header carry the scorer token? */
	public static function authorized($authHeader, $sha256) {
		if (!is_string($sha256) || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
			return false;
		}
		if (!preg_match('/^Bearer\s+(\S+)$/', (string)$authHeader, $m)) {
			return false;
		}
		return hash_equals($sha256, hash('sha256', $m[1]));
	}

	/**
	 * GET score/export: every settled recording (confirmed or discarded) with
	 * what scoring needs, the uploaded Spots behind each confirm (hand-off
	 * check), the testers' Stopwatch Spots (timing baseline) and the form
	 * choices (answer sheet dropdowns).
	 */
	public function export() {
		$rows = $this->db->rows(
			"SELECT s.station_uuid, s.userpkey, u.email, u.firstname, u.lastname,
			        b.batch_uuid, b.project_id, b.dataset_id,
			        " . MsDb::iso('s.started_at') . " AS started_at,
			        " . MsDb::iso('s.ended_at') . " AS ended_at,
			        s.tz_offset_minutes, s.audio_seconds, s.best_accuracy,
			        COALESCE(s.recorded_on, 'phone') AS recorded_on, s.watch_model,
			        s.details->>'spot_name' AS spot_name,
			        s.details->'audio'->'recorded_seconds' AS recorded_seconds,
			        s.details->'audio'->'pauses' AS pauses,
			        s.details->>'strike_convention' AS strike_convention,
			        s.audio_path IS NOT NULL AND s.audio_deleted_at IS NULL AS has_audio,
			        c.outcome, " . MsDb::iso('c.created_at') . " AS settled_at,
			        c.review_seconds, c.n_proposed, c.n_unchanged, c.n_edited, c.n_removed,
			        c.n_added, c.n_flags, c.record, array_to_json(c.spot_ids) AS spot_ids,
			        c.proposal_run_id,
			        t.output AS transcript, p.output AS proposal
			   FROM voicestations.stations s
			   JOIN voicestations.batches b ON b.id = s.batch_id
			   JOIN voicestations.confirms c ON c.station_id = s.id
			   LEFT JOIN public.users u ON u.pkey = s.userpkey
			   LEFT JOIN voicestations.runs t ON t.id = s.current_transcript_run
			   LEFT JOIN voicestations.runs p ON p.id = c.proposal_run_id
			  ORDER BY s.userpkey, s.started_at");

		$testers = array();
		$stations = array();
		$projects = array();   // userpkey => project ids (for the Stopwatch Spots)
		foreach ($rows as $r) {
			$upk = (int)$r['userpkey'];
			if (!isset($testers[$upk])) {
				$testers[$upk] = array('userpkey' => $upk, 'email' => $r['email'],
					'name' => trim($r['firstname'] . ' ' . $r['lastname']));
			}
			$projects[$upk][$r['project_id']] = true;
			$spotIds = json_decode($r['spot_ids'], true);
			$stations[] = array(
				'station_uuid' => $r['station_uuid'],
				'userpkey' => $upk,
				'batch_uuid' => $r['batch_uuid'],
				'project_id' => $r['project_id'],
				'dataset_id' => $r['dataset_id'],
				'spot_name' => $r['spot_name'],
				'started_at' => $r['started_at'],
				'ended_at' => $r['ended_at'],
				'tz_offset_minutes' => $r['tz_offset_minutes'] === null ? null : (int)$r['tz_offset_minutes'],
				'recorded_on' => $r['recorded_on'],
				'watch_model' => $r['watch_model'],
				'audio_seconds' => $r['audio_seconds'] === null ? null : (float)$r['audio_seconds'],
				'recorded_seconds' => $r['recorded_seconds'] === null ? null : (float)$r['recorded_seconds'],
				'pauses' => $r['pauses'] === null ? array() : json_decode($r['pauses']),
				'strike_convention' => $r['strike_convention'],
				'best_accuracy' => $r['best_accuracy'] === null ? null : (float)$r['best_accuracy'],
				'has_audio' => MsDb::bool($r['has_audio']),
				'outcome' => $r['outcome'],
				'settled_at' => $r['settled_at'],
				'review_seconds' => $r['review_seconds'] === null ? null : (float)$r['review_seconds'],
				'counts' => array(
					'proposed' => (int)$r['n_proposed'], 'unchanged' => (int)$r['n_unchanged'],
					'edited' => (int)$r['n_edited'], 'removed' => (int)$r['n_removed'],
					'added' => (int)$r['n_added'], 'flags' => (int)$r['n_flags'],
				),
				'record' => json_decode($r['record']),
				'spot_ids' => $spotIds,
				'proposal_run' => $r['proposal_run_id'] === null ? null : (int)$r['proposal_run_id'],
				'transcript' => $r['transcript'] === null ? null : json_decode($r['transcript']),
				'proposal' => $r['proposal'] === null ? null : json_decode($r['proposal']),
				'spots' => $r['outcome'] === 'confirmed' ? $this->spots($upk, $spotIds) : new stdClass(),
			);
		}

		$stopwatch = array();
		foreach ($projects as $upk => $pids) {
			foreach (array_keys($pids) as $pid) {
				foreach ($this->stopwatchDatasets($upk, $pid) as $did) {
					$stopwatch[] = array('userpkey' => $upk, 'project_id' => (string)$pid,
						'dataset_id' => (string)$did, 'spots' => $this->datasetSpots($upk, $did));
				}
			}
		}

		return array(200, array(
			'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'consent_version' => VsConfig::CONSENT_VERSION,
			'testers' => array_values($testers),
			'stations' => $stations,
			'stopwatch' => $stopwatch,
			'vocab' => VsWorker::vocab(),
		));
	}

	/** The uploaded Spots behind a confirm, by id (null = not on the server). */
	private function spots($upk, $ids) {
		$out = new stdClass();
		if (!is_array($ids) || count($ids) === 0) {
			return $out;
		}
		$strabo = new StraboSpot($this->neodb, $upk, $this->strabodb);   // (neodb, userpkey, db)
		foreach ($ids as $id) {
			$id = (string)$id;
			if (!preg_match('/^[0-9]{1,20}$/', $id)) {
				$out->$id = null;
				continue;
			}
			try {
				$s = $strabo->getSingleSpot($id);
			} catch (Exception $e) {
				$this->reconnect();
				$s = null;
			}
			$out->$id = (is_object($s) && !isset($s->Error)) ? $s : null;
		}
		return $out;
	}

	/** Ids of this tester's datasets named "Stopwatch Spots" in the project. */
	private function stopwatchDatasets($upk, $projectId) {
		if (!preg_match('/^[0-9]{1,20}$/', (string)$projectId)) {
			return array();
		}
		try {
			$rows = $this->neodb->get_results(
				"MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)
				  WHERE (p.id = $projectId OR p.id = '$projectId') AND toLower(trim(d.name)) = '" . self::STOPWATCH_DATASET . "'
				 RETURN d.id AS id");
		} catch (Exception $e) {
			$this->reconnect();
			return array();
		}
		$ids = array();
		foreach ($rows as $r) {
			$ids[] = $r->value('id');
		}
		return $ids;
	}

	/** Baseline Spots: id, name, date, and how many measurements each holds. */
	private function datasetSpots($upk, $datasetId) {
		try {
			$rows = $this->neodb->get_results(
				"MATCH (d:Dataset {userpkey: $upk})-[:HAS_SPOT]->(s:Spot)
				  WHERE d.id = $datasetId OR d.id = '$datasetId'
				 RETURN s.id AS id, s.name AS name, s.date AS date, s.time AS time,
				        s.json_orientation_data AS od");
		} catch (Exception $e) {
			$this->reconnect();
			return array();
		}
		$out = array();
		foreach ($rows as $r) {
			$od = json_decode((string)$r->value('od'));
			$out[] = array(
				'id' => (string)$r->value('id'),
				'name' => $r->value('name'),
				'date' => $r->value('date'),
				'time' => $r->value('time'),
				'n_measurements' => self::countMeasurements((object)array('orientation_data' => is_array($od) ? $od : array())),
			);
		}
		return $out;
	}

	/** Orientation measurements in a Spot, nested lines on planes included. */
	public static function countMeasurements($spot) {
		$p = is_object($spot) && isset($spot->properties) ? $spot->properties : $spot;
		$n = 0;
		if (is_object($p) && isset($p->orientation_data) && is_array($p->orientation_data)) {
			foreach ($p->orientation_data as $o) {
				$n++;
				if (is_object($o) && isset($o->associated_orientation) && is_array($o->associated_orientation)) {
					$n += count($o->associated_orientation);
				}
			}
		}
		return $n;
	}

	/**
	 * POST score/results {"scorer_version", "key_sha256", "results": {...}}:
	 * one scorer run, kept with the key hash so before and after a key
	 * correction both stay visible.
	 */
	public function saveResults() {
		$body = VsHttp::readJson(self::MAX_RESULTS_BYTES);
		$version = VsHttp::prop($body, 'scorer_version');
		if (!is_string($version) || !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $version)) {
			throw VsHttp::bad('scorer_version', 'scorer_version must be a short version name.');
		}
		$key = VsHttp::prop($body, 'key_sha256');
		if (!is_string($key) || !preg_match('/^[0-9a-f]{64}$/', $key)) {
			throw VsHttp::bad('key_sha256', 'key_sha256 must be the sha256 of the answer key (64 hex).');
		}
		$results = VsHttp::prop($body, 'results');
		if (!is_object($results)) {
			throw VsHttp::bad('results', 'results must be a JSON object.');
		}
		$id = $this->db->val(
			"INSERT INTO voicestations.scores (scorer_version, key_sha256, results)
			 VALUES ($1, $2, $3::jsonb) RETURNING id",
			array($version, $key, json_encode($results, VsHttp::JSON_OUT)));
		return array(201, array('id' => (int)$id));
	}

	private function reconnect() {
		if (method_exists($this->neodb, 'reconnect')) {
			$this->neodb->reconnect();
		}
	}
}
