<?php
/**
 * File: OtaServe.php
 * Description: The two endpoints phones call (no authentication):
 *                GET /ota/manifest        newest update or directive for this
 *                                         platform + runtime version + channel
 *                GET|HEAD /ota/assets/<sha256 hex><ext>   one stored file
 *
 *              The manifest/directive bytes are sent exactly as published (they
 *              were signed by the app developers); this server only chooses
 *              which release to send and wraps it in the protocol's envelope.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class OtaServe {

	/** The client's request, validated. Throws OtaError(400/406). */
	public static function readRequest() {
		$req = array();
		$proto = Ota::header('expo-protocol-version');
		if ($proto !== '1') {
			throw new OtaError(406, 'protocol_unsupported', 'This server speaks Expo Updates protocol version 1 only');
		}
		$req['platform'] = Ota::header('expo-platform');
		if ($req['platform'] !== 'ios' && $req['platform'] !== 'android') {
			throw new OtaError(400, 'bad_platform', 'expo-platform must be ios or android');
		}
		$req['runtime'] = Ota::header('expo-runtime-version');
		if ($req['runtime'] === null || !preg_match(Ota::RUNTIME_RE, $req['runtime'])) {
			throw new OtaError(400, 'bad_runtime_version', 'expo-runtime-version is missing or not valid');
		}
		$ch = Ota::header('expo-channel-name');
		$req['channel'] = ($ch === null || $ch === '') ? Ota::DEFAULT_CHANNEL : strtolower($ch);
		if (!preg_match(Ota::CHANNEL_RE, $req['channel'])) {
			throw new OtaError(400, 'bad_channel', 'expo-channel-name is not valid');
		}
		$req['current'] = self::uuidOrNull(Ota::header('expo-current-update-id'));
		$req['embedded'] = self::uuidOrNull(Ota::header('expo-embedded-update-id'));
		$req['client'] = Ota::header('eas-client-id');
		$req['fatal'] = Ota::header('expo-fatal-error');
		$req['wantsSignature'] = Ota::header('expo-expect-signature') !== null;

		// Accept: multipart unless the client asks only for a plain JSON manifest.
		$accept = strtolower((string)Ota::header('accept'));
		if ($accept === '' || strpos($accept, 'multipart/mixed') !== false || strpos($accept, '*/*') !== false) {
			$req['format'] = 'multipart';
		} elseif (strpos($accept, 'application/expo+json') !== false) {
			$req['format'] = 'application/expo+json';
		} elseif (strpos($accept, 'application/json') !== false) {
			$req['format'] = 'application/json';
		} else {
			throw new OtaError(406, 'not_acceptable', 'Accept must allow multipart/mixed or application/json');
		}
		return $req;
	}

	private static function uuidOrNull($v) {
		if ($v === null) {
			return null;
		}
		$v = strtolower($v);
		return preg_match(Ota::UUID_RE, $v) ? $v : null;
	}

	/** The release a phone should get, or null. */
	public static function pick(OtaDb $odb, $platform, $runtime, $channel) {
		return $odb->row(
			"SELECT pkey, kind, update_id, body, signature FROM ota_releases
			  WHERE platform = $1 AND runtime_version = $2 AND channel = $3 AND withdrawn_at IS NULL
			  ORDER BY created_at DESC, pkey DESC LIMIT 1",
			array($platform, $runtime, $channel));
	}

	public static function manifest(OtaDb $odb) {
		$req = self::readRequest();
		try {
			self::logRequest($odb, $req);
		} catch (Throwable $e) {
			error_log('ota log: ' . $e->getMessage()); // never fail a phone over a log line
		}

		$rel = self::pick($odb, $req['platform'], $req['runtime'], $req['channel']);
		if ($rel === null || ($rel['kind'] === 'update' && $rel['update_id'] === $req['current'])) {
			self::noUpdate();
			return;
		}
		$sigHeader = null;
		if ($req['wantsSignature']) {
			if ($rel['signature'] === null || $rel['signature'] === '') {
				throw new OtaError(500, 'unsigned_release', 'The newest release is not signed and this app requires signatures');
			}
			$sigHeader = 'sig="' . $rel['signature'] . '", keyid="' . Ota::keyId() . '"';
		}

		if ($req['format'] !== 'multipart') {
			if ($rel['kind'] !== 'update') {
				throw new OtaError(406, 'not_acceptable', 'A directive can only be sent as multipart/mixed');
			}
			self::commonHeaders();
			header('Content-Type: ' . $req['format']);
			if ($sigHeader !== null) {
				header('expo-signature: ' . $sigHeader);
			}
			http_response_code(200);
			echo $rel['body'];
			return;
		}

		$name = $rel['kind'] === 'update' ? 'manifest' : 'directive';
		$parts = array(array($name, $rel['body'], $sigHeader));
		if ($rel['kind'] === 'update') {
			$parts[] = array('extensions', '{"assetRequestHeaders":{}}', null);
		}
		$boundary = 'ota-' . bin2hex(random_bytes(12));
		$out = '';
		foreach ($parts as $p) {
			$out .= "--$boundary\r\n"
				. 'content-disposition: form-data; name="' . $p[0] . "\"\r\n"
				. "content-type: application/json; charset=utf-8\r\n"
				. ($p[2] !== null ? 'expo-signature: ' . $p[2] . "\r\n" : '')
				. "\r\n" . $p[1] . "\r\n";
		}
		$out .= "--$boundary--\r\n";
		self::commonHeaders();
		header('Content-Type: multipart/mixed; boundary=' . $boundary);
		header('Content-Length: ' . strlen($out));
		http_response_code(200);
		echo $out;
	}

	public static function noUpdate() {
		self::commonHeaders();
		http_response_code(204);
	}

	private static function commonHeaders() {
		header('expo-protocol-version: 1');
		header('expo-sfv-version: 0');
		header('Cache-Control: private, max-age=0');
	}

	/** One row per install per day; crash reports kept (one per install, error and day). */
	private static function logRequest(OtaDb $odb, $req) {
		$hash = ($req['client'] !== null && $req['client'] !== '')
			? hash('sha256', $req['client']) : null;
		if ($hash !== null) {
			$odb->q(
				"INSERT INTO ota_daily_clients (day, client_hash, platform, runtime_version, channel,
				     current_update_id, embedded_update_id)
				 VALUES (current_date, $1, $2, $3, $4, $5, $6)
				 ON CONFLICT (day, client_hash) DO UPDATE SET requests = ota_daily_clients.requests + 1,
				     last_seen = now(), platform = EXCLUDED.platform, runtime_version = EXCLUDED.runtime_version,
				     channel = EXCLUDED.channel, current_update_id = EXCLUDED.current_update_id,
				     embedded_update_id = EXCLUDED.embedded_update_id",
				array($hash, $req['platform'], $req['runtime'], $req['channel'], $req['current'], $req['embedded']));
		}
		if ($req['fatal'] !== null && $req['fatal'] !== '') {
			$err = mb_substr($req['fatal'], 0, 2000);
			$seen = $odb->val(
				"SELECT 1 FROM ota_fatal_errors WHERE client_hash IS NOT DISTINCT FROM $1 AND error = $2
				   AND received_at > now() - interval '1 day' LIMIT 1",
				array($hash, $err));
			if ($seen === null) {
				$odb->q(
					"INSERT INTO ota_fatal_errors (client_hash, platform, runtime_version, channel,
					     current_update_id, embedded_update_id, error) VALUES ($1, $2, $3, $4, $5, $6, $7)",
					array($hash, $req['platform'], $req['runtime'], $req['channel'], $req['current'],
						$req['embedded'], $err));
			}
		}
		if (mt_rand(1, 1000) === 1) {
			$odb->q("DELETE FROM ota_daily_clients WHERE day < current_date - 400");
		}
	}

	/** GET|HEAD /ota/assets/<hex><ext>: only files some release uses. */
	public static function asset(OtaDb $odb, $hex, $ext, $head) {
		$ct = $odb->val(
			"SELECT content_type FROM ota_release_assets WHERE sha256 = $1 AND ext = $2 LIMIT 1",
			array($hex, $ext));
		$path = Ota::assetPath($hex);
		if ($ct === null || !is_file($path)) {
			throw new OtaError(404, 'not_found', 'No such file');
		}
		$etag = '"' . $hex . '"';
		header('Content-Type: ' . $ct);
		header('Cache-Control: public, max-age=31536000, immutable');
		header('ETag: ' . $etag);
		$inm = Ota::header('if-none-match');
		if ($inm !== null && strpos($inm, $etag) !== false) {
			http_response_code(304);
			return;
		}
		header('Content-Length: ' . filesize($path));
		http_response_code(200);
		if ($head) {
			return;
		}
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		set_time_limit(0);
		readfile($path);
	}
}
