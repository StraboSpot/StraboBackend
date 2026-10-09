<?php
/**
 * File: VsConfig.php
 * Description: Voice Stations limits, data folder and the tester gate.
 *
 *              Gate (decided 10-07): config.inc.php may define
 *                define('VOICESTATIONS_ALLOW', array('a@example.org', ...));
 *              Only those accounts may use the /db/ voice endpoints. It FAILS
 *              CLOSED: not defined, or anything but an array or the word
 *              'everyone' (a typo), lets nobody in. 'everyone' opens it to
 *              all accounts. Matched on the account's current email, ignoring
 *              case, deleted accounts never (same rule as MsAccess).
 *
 *              Audio lives in www/voicestations_data/audio/<userpkey>/
 *              <station_uuid>.m4a. On prod voicestations_data is a symlink to
 *              /StraboData/bigDriveData/voicestations_data. The code never
 *              creates the root or audio/ (a mkdir there would land on the
 *              container's overlay filesystem when the symlink target is
 *              missing); uploads answer 503 until it exists.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class VsConfig {

	const MAX_AUDIO_BYTES     = 20971520;   // 20 MB (P6.4)
	const MAX_SECONDS         = 600;        // 10 minutes of audio per station (P6.4)
	const MAX_WALL_SECONDS    = 7200;       // Record to Stop incl. pauses (a call mid-station)
	const MAX_FIXES           = 2000;
	const MAX_PHOTOS          = 50;
	const MAX_BATCH_STATIONS  = 500;
	const MAX_SPOT_BYTES      = 1048576;    // existing-Spot JSON, 1 MB
	const MAX_CONFIRM_BYTES   = 1048576;
	const MAX_SPOT_IDS        = 50;
	const AUDIO_MIMES         = array('audio/mp4', 'audio/x-m4a');
	const STRIKE_CONVENTIONS  = array('rhr', 'dip_direction');
	const RECORDED_ON         = array('phone', 'watch');   // missing = phone (builds before the watch app)

	// Consent the tester agrees to in the app (step 6). Its text is
	// voicestations/consent/consent_v<N>.json; changing the text = a new file
	// and a bump here, and every tester is asked again.
	const CONSENT_VERSION     = 1;

	public static function dataRoot() {
		return dirname(dirname(__DIR__)) . '/voicestations_data';
	}

	/** The current consent text (decoded JSON object). */
	public static function consentText() {
		$f = dirname(__DIR__) . '/consent/consent_v' . self::CONSENT_VERSION . '.json';
		$c = json_decode((string)@file_get_contents($f));
		if (!is_object($c) || !isset($c->version) || $c->version !== self::CONSENT_VERSION) {
			throw new Exception('consent text missing or wrong version: ' . $f);
		}
		return $c;
	}

	/** May this account use Voice Stations? */
	public static function allowed($msdb, $userpkey) {
		return self::allowedBy($msdb, $userpkey, defined('VOICESTATIONS_ALLOW') ? VOICESTATIONS_ALLOW : null);
	}

	public static function allowedBy($msdb, $userpkey, $list) {
		if ((int)$userpkey <= 0) {
			return false;
		}
		$email = $msdb->val(
			"SELECT lower(trim(email)) FROM public.users WHERE pkey = $1 AND deleted = false",
			array((int)$userpkey));
		if ($email === null || $email === '') {
			return false;
		}
		if ($list === 'everyone') {
			return true;
		}
		if (!is_array($list)) {
			return false;
		}
		foreach ($list as $e) {
			if (is_string($e) && strtolower(trim($e)) === $email) {
				return true;
			}
		}
		return false;
	}
}
