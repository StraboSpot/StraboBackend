<?php
/**
 * File: VsDetails.php
 * Description: Checks the "details" JSON of POST /db/voicestation (the
 *              contract decided 10-07, Phase1_Plan.md P6.3) and turns it
 *              into the values the stations row stores. Every failure is a
 *              400 naming the field; nothing is stored.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class VsDetails {

	/**
	 * $d = decoded details (stdClass). Returns an array of checked values;
	 * project_id / dataset_id still need the Neo4j ownership check.
	 */
	public static function check($d) {
		$out = array();

		$out['station_uuid'] = self::uuid($d, 'station_uuid');
		$out['batch_uuid'] = self::uuid($d, 'batch_uuid');

		$list = VsHttp::prop($d, 'batch_station_uuids');
		if (!is_array($list) || count($list) === 0) {
			throw VsHttp::bad('batch_station_uuids', 'A non-empty list of the batch\'s station UUIDs is required.');
		}
		if (count($list) > VsConfig::MAX_BATCH_STATIONS) {
			throw VsHttp::bad('batch_station_uuids', 'A batch may hold at most ' . VsConfig::MAX_BATCH_STATIONS . ' stations.');
		}
		$uuids = array();
		foreach ($list as $u) {
			if (!VsHttp::isUuid($u)) {
				throw VsHttp::bad('batch_station_uuids', 'Every entry must be a UUID.');
			}
			$uuids[strtolower($u)] = true;
		}
		$out['batch_station_uuids'] = array_keys($uuids);
		if (!isset($uuids[$out['station_uuid']])) {
			throw VsHttp::bad('batch_station_uuids', 'The list must include this station\'s UUID.');
		}

		$out['project_id'] = self::fieldId($d, 'project_id');
		$out['dataset_id'] = self::fieldId($d, 'dataset_id');

		// target: a new Spot, or additions to an existing Spot (P3 aside)
		$t = VsHttp::prop($d, 'target');
		$kind = VsHttp::prop($t, 'kind');
		if ($kind === 'new') {
			$out['target_kind'] = 'new';
			$out['target_spot_id'] = null;
			$out['target_spot'] = null;
		} elseif ($kind === 'existing') {
			$sid = VsHttp::prop($t, 'spot_id');
			if (!(is_string($sid) || is_int($sid)) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$sid)) {
				throw VsHttp::bad('target.spot_id', 'An existing-Spot target needs its Spot id.');
			}
			$spot = VsHttp::prop($t, 'spot');
			if (!is_object($spot)) {
				throw VsHttp::bad('target.spot', 'An existing-Spot target needs the whole Spot JSON.');
			}
			$spotJson = json_encode($spot, VsHttp::JSON_OUT);
			if (strlen($spotJson) > VsConfig::MAX_SPOT_BYTES) {
				throw VsHttp::bad('target.spot', 'The Spot JSON is larger than 1 MB.');
			}
			$out['target_kind'] = 'existing';
			$out['target_spot_id'] = (string)$sid;
			$out['target_spot'] = $spotJson;
		} else {
			throw VsHttp::bad('target.kind', 'target.kind must be "new" or "existing".');
		}

		// times
		$start = self::time($d, 'started_at');
		$end = self::time($d, 'ended_at');
		if ($end < $start) {
			throw VsHttp::bad('ended_at', 'The recording ends before it starts.');
		}
		if ($end - $start > VsConfig::MAX_SECONDS) {
			throw VsHttp::bad('ended_at', 'A station may be at most 10 minutes long.');
		}
		$out['started_at'] = VsHttp::prop($d, 'started_at');
		$out['ended_at'] = VsHttp::prop($d, 'ended_at');

		$tz = VsHttp::prop($d, 'tz_offset_minutes');
		if ($tz !== null && !(is_int($tz) && $tz >= -840 && $tz <= 840)) {
			throw VsHttp::bad('tz_offset_minutes', 'tz_offset_minutes must be a whole number of minutes (-840 to 840).');
		}
		$out['tz_offset_minutes'] = $tz;

		// GPS fixes: all kept; the most accurate one is the station location (P3)
		$fixes = VsHttp::prop($d, 'gps_fixes', array());
		if (!is_array($fixes)) {
			throw VsHttp::bad('gps_fixes', 'gps_fixes must be a list.');
		}
		if (count($fixes) > VsConfig::MAX_FIXES) {
			throw VsHttp::bad('gps_fixes', 'At most ' . VsConfig::MAX_FIXES . ' GPS fixes per station.');
		}
		$best = null;
		foreach ($fixes as $i => $f) {
			$field = "gps_fixes[$i]";
			$lat = VsHttp::prop($f, 'lat');
			$lon = VsHttp::prop($f, 'lon');
			$alt = VsHttp::prop($f, 'alt');
			$acc = VsHttp::prop($f, 'accuracy');
			if (!self::num($lat) || $lat < -90 || $lat > 90) {
				throw VsHttp::bad("$field.lat", 'lat must be a number from -90 to 90.');
			}
			if (!self::num($lon) || $lon < -180 || $lon > 180) {
				throw VsHttp::bad("$field.lon", 'lon must be a number from -180 to 180.');
			}
			if ($alt !== null && !self::num($alt)) {
				throw VsHttp::bad("$field.alt", 'alt must be a number or null.');
			}
			if ($acc !== null && (!self::num($acc) || $acc < 0)) {
				throw VsHttp::bad("$field.accuracy", 'accuracy must be a number of metres (0 or more) or null.');
			}
			$at = self::time($f, 'time', "$field.time");
			// smallest accuracy wins, ties go to the earliest; no accuracy = never picked
			if ($acc !== null && ($best === null || $acc < $best['accuracy']
					|| ($acc == $best['accuracy'] && $at < $best['t']))) {
				$best = array('lat' => $lat, 'lon' => $lon, 'alt' => $alt, 'accuracy' => $acc,
					'time' => VsHttp::prop($f, 'time'), 't' => $at);
			}
		}
		$out['gps_fixes'] = json_encode($fixes, VsHttp::JSON_OUT);
		$out['best'] = $best;

		// photos: id + timestamp only, never the image (P6.3)
		$photos = VsHttp::prop($d, 'photos', array());
		if (!is_array($photos)) {
			throw VsHttp::bad('photos', 'photos must be a list.');
		}
		if (count($photos) > VsConfig::MAX_PHOTOS) {
			throw VsHttp::bad('photos', 'At most ' . VsConfig::MAX_PHOTOS . ' photos per station.');
		}
		foreach ($photos as $i => $p) {
			$pid = VsHttp::prop($p, 'id');
			if (!(is_string($pid) || is_int($pid)) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$pid)) {
				throw VsHttp::bad("photos[$i].id", 'Each photo needs its id.');
			}
			self::time($p, 'taken_at', "photos[$i].taken_at");
			if (count(get_object_vars($p)) !== 2) {
				throw VsHttp::bad("photos[$i]", 'A photo is sent as id + taken_at only.');
			}
		}
		$out['photos'] = json_encode($photos, VsHttp::JSON_OUT);

		$sc = VsHttp::prop($d, 'strike_convention');
		if (!in_array($sc, VsConfig::STRIKE_CONVENTIONS, true)) {
			throw VsHttp::bad('strike_convention', 'strike_convention must be "rhr" or "dip_direction".');
		}
		$out['strike_convention'] = $sc;

		$out['app_version'] = self::shortText($d, 'app_version');
		$out['device_model'] = self::shortText($d, 'device_model');

		$audio = VsHttp::prop($d, 'audio');
		$mime = VsHttp::prop($audio, 'mime');
		if (!in_array($mime, VsConfig::AUDIO_MIMES, true)) {
			throw VsHttp::bad('audio.mime', 'audio.mime must be audio/mp4 or audio/x-m4a.');
		}
		$secs = VsHttp::prop($audio, 'seconds');
		if ($secs !== null && (!self::num($secs) || $secs < 0 || $secs > VsConfig::MAX_SECONDS + 5)) {
			throw VsHttp::bad('audio.seconds', 'audio.seconds must be a number of seconds up to 10 minutes.');
		}
		$out['audio_mime'] = $mime;
		$out['audio_seconds'] = $secs;

		return $out;
	}

	private static function uuid($d, $key) {
		$v = VsHttp::prop($d, $key);
		if (!VsHttp::isUuid($v)) {
			throw VsHttp::bad($key, "$key must be a UUID.");
		}
		return strtolower($v);
	}

	/** Field project / dataset ids: digits, sent as a number or a string. */
	private static function fieldId($d, $key) {
		$v = VsHttp::prop($d, $key);
		if (!(is_int($v) || is_string($v)) || !preg_match('/^[0-9]{1,20}$/', (string)$v)) {
			throw VsHttp::bad($key, "$key must be a StraboField id (digits).");
		}
		return (string)$v;
	}

	/** ISO 8601 with a time zone; returns epoch seconds (float). */
	private static function time($obj, $key, $field = null) {
		$field = $field === null ? $key : $field;
		$v = VsHttp::prop($obj, $key);
		if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $v)) {
			throw VsHttp::bad($field, "$field must be an ISO 8601 time with a time zone.");
		}
		try {
			$dt = new DateTime($v);
		} catch (Exception $e) {
			throw VsHttp::bad($field, "$field is not a valid time.");
		}
		return (float)$dt->format('U.u');
	}

	private static function num($v) {
		return (is_int($v) || is_float($v)) && is_finite((float)$v);
	}

	private static function shortText($d, $key) {
		$v = VsHttp::prop($d, $key);
		if ($v !== null && (!is_string($v) || strlen($v) > 100)) {
			throw VsHttp::bad($key, "$key must be text of at most 100 characters.");
		}
		return $v;
	}
}
