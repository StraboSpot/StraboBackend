<?php
/**
 * File: Ota.php
 * Description: Shared pieces of the StraboField over-the-air update server
 *              (/ota/, Expo Updates protocol v1): settings, a small
 *              exception-throwing database layer and JSON helpers.
 *              Design: docs/StraboField_Update_Server/DESIGN.md.
 *
 *              Settings (includes/config.inc.php, all optional except where noted):
 *                OTA_ENABLED              true to switch the server on. Off: the
 *                                         manifest answers 204 (no update) and the
 *                                         publish API answers 503.
 *                OTA_PUBLIC_BASE          required: the address phones use, e.g.
 *                                         'https://strabospot.org'. Asset URLs in a
 *                                         manifest must start with it + '/ota/assets/'.
 *                OTA_PUBLISH_TOKEN_SHA256 required for publishing: lowercase hex
 *                                         SHA-256 of the publish token (the token
 *                                         itself is never stored on the server).
 *                OTA_CODE_SIGNING_CERT    path to the PEM certificate built into the
 *                                         app. When set, every release must carry a
 *                                         signature that verifies against it. The
 *                                         private key never comes to this server.
 *                OTA_CODE_SIGNING_KEYID   keyid in expo-signature (default 'main').
 *                OTA_DATA_DIR             where files are kept (default <web root>/ota_data).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class OtaError extends Exception {
	public $status;
	public $errorCode;

	public function __construct($status, $errorCode, $message) {
		parent::__construct($message);
		$this->status = $status;
		$this->errorCode = $errorCode;
	}
}

class Ota {

	const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
	const RUNTIME_RE = '/^[A-Za-z0-9._+-]{1,128}$/';
	const CHANNEL_RE = '/^[a-z0-9][a-z0-9_-]{0,31}$/';
	const DEFAULT_CHANNEL = 'production';

	public static function enabled() {
		return defined('OTA_ENABLED') && OTA_ENABLED === true;
	}

	public static function publicBase() {
		return defined('OTA_PUBLIC_BASE') ? rtrim(OTA_PUBLIC_BASE, '/') : '';
	}

	public static function dataDir() {
		if (defined('OTA_DATA_DIR')) {
			return rtrim(OTA_DATA_DIR, '/');
		}
		return dirname(__DIR__, 2) . '/ota_data';
	}

	public static function assetPath($hex) {
		return self::dataDir() . '/assets/' . substr($hex, 0, 2) . '/' . $hex;
	}

	public static function keyId() {
		return defined('OTA_CODE_SIGNING_KEYID') ? OTA_CODE_SIGNING_KEYID : 'main';
	}

	/** The public key releases are checked against, or null when signing is off. */
	public static function signingKey() {
		if (!defined('OTA_CODE_SIGNING_CERT') || OTA_CODE_SIGNING_CERT === '' || OTA_CODE_SIGNING_CERT === null) {
			return null;
		}
		$pem = @file_get_contents(OTA_CODE_SIGNING_CERT);
		$key = $pem === false ? false : openssl_pkey_get_public($pem);
		if ($key === false) {
			throw new OtaError(500, 'signing_misconfigured', 'The code signing certificate could not be read');
		}
		return $key;
	}

	/** base64url (no padding) of raw bytes. */
	public static function b64url($raw) {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	/** Header lookup: Ota::header('expo-platform'). */
	public static function header($name) {
		$k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		return isset($_SERVER[$k]) ? trim($_SERVER[$k]) : null;
	}

	public static function clientIp() {
		$xff = self::header('x-forwarded-for');
		if ($xff !== null && $xff !== '') {
			$parts = explode(',', $xff);
			return substr(trim($parts[0]), 0, 64);
		}
		return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 64) : null;
	}

	public static function json($status, $data) {
		http_response_code($status);
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	public static function error($status, $code, $message) {
		self::json($status, array('error' => $code, 'message' => $message));
	}

	/** Decode a JSON object request body (arrays, not stdClass). */
	public static function readJson($maxBytes) {
		$len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
		if ($len > $maxBytes) {
			throw new OtaError(413, 'too_large', "Request body is larger than $maxBytes bytes");
		}
		$raw = stream_get_contents(fopen('php://input', 'rb'), $maxBytes + 1);
		if ($raw === false || $raw === '') {
			throw new OtaError(400, 'bad_request', 'A JSON body is required');
		}
		if (strlen($raw) > $maxBytes) {
			throw new OtaError(413, 'too_large', "Request body is larger than $maxBytes bytes");
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
			throw new OtaError(400, 'bad_request', 'The body must be a JSON object');
		}
		return $data;
	}
}

class OtaDb {

	private $conn;

	public function __construct($strabodb) {
		if (!isset($strabodb->dbh) || !$strabodb->dbh) {
			$strabodb->get_var('SELECT 1'); // the wrapper connects lazily
		}
		$this->conn = $strabodb->dbh;
		if (!$this->conn) {
			throw new OtaError(500, 'no_database', 'No database connection');
		}
	}

	public function q($sql, $params = array()) {
		$res = @pg_query_params($this->conn, $sql, $params);
		if ($res === false) {
			throw new Exception('ota db: ' . pg_last_error($this->conn));
		}
		return $res;
	}

	public function rows($sql, $params = array()) {
		$res = $this->q($sql, $params);
		$out = pg_fetch_all($res);
		pg_free_result($res);
		return $out === false ? array() : $out;
	}

	public function row($sql, $params = array()) {
		$res = $this->q($sql, $params);
		$out = pg_fetch_assoc($res);
		pg_free_result($res);
		return $out === false ? null : $out;
	}

	public function val($sql, $params = array()) {
		$row = $this->row($sql, $params);
		return $row === null ? null : reset($row);
	}

	public function begin() { $this->q('BEGIN'); }
	public function commit() { $this->q('COMMIT'); }
	public function rollback() { @pg_query($this->conn, 'ROLLBACK'); }

	/** SQL expression: timestamptz as ISO 8601 UTC with milliseconds. */
	public static function iso($col) {
		return "to_char($col AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"')";
	}
}
