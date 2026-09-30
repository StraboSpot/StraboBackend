<?php
/**
 * File: MsHttp.php
 * Description: Request and response helpers for the /microsync/v1/ API.
 *              Errors are thrown as MsHttpError and rendered by the front
 *              controller as {"error": code, "message": text, ...extra}.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsHttpError extends Exception {
	public $status;
	public $errorCode;
	public $extra;

	public function __construct($status, $errorCode, $message, $extra = array()) {
		parent::__construct($message);
		$this->status = $status;
		$this->errorCode = $errorCode;
		$this->extra = $extra;
	}
}

class MsHttp {

	// JSON bodies are decoded as objects (not arrays) so that {} and [] in
	// entity bodies survive the round trip unchanged.
	const JSON_OUT = 1344; // JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION

	public static function json($status, $data) {
		http_response_code($status);
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		echo self::encode($data);
	}

	public static function encode($data) {
		$out = json_encode($data, self::JSON_OUT);
		if ($out === false) {
			throw new MsHttpError(500, 'encode_failed', 'Could not encode the response: ' . json_last_error_msg());
		}
		return $out;
	}

	public static function error($status, $code, $message, $extra = array()) {
		$body = array('error' => $code, 'message' => $message);
		foreach ($extra as $k => $v) {
			$body[$k] = $v;
		}
		self::json($status, $body);
	}

	/**
	 * Read and decode a JSON request body. Objects stay stdClass.
	 * Bodies over $maxBytes are refused with 413 without being buffered whole.
	 */
	public static function readJson($maxBytes) {
		$len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
		if ($len > $maxBytes) {
			throw new MsHttpError(413, 'too_large', "Request body is larger than $maxBytes bytes");
		}
		$in = fopen('php://input', 'rb');
		$raw = stream_get_contents($in, $maxBytes + 1);
		fclose($in);
		if (strlen($raw) > $maxBytes) {
			throw new MsHttpError(413, 'too_large', "Request body is larger than $maxBytes bytes");
		}
		if ($raw === '' || $raw === false) {
			throw new MsHttpError(400, 'bad_request', 'A JSON body is required');
		}
		$data = json_decode($raw);
		if (json_last_error() !== JSON_ERROR_NONE || !is_object($data)) {
			throw new MsHttpError(400, 'bad_request', 'The body must be a JSON object');
		}
		return $data;
	}

	/** Property of a decoded JSON object, or $default when absent. */
	public static function prop($obj, $key, $default = null) {
		return (is_object($obj) && property_exists($obj, $key)) ? $obj->$key : $default;
	}

	public static function queryInt($name, $default, $min, $max) {
		if (!isset($_GET[$name]) || $_GET[$name] === '') {
			return $default;
		}
		if (!preg_match('/^-?\d+$/', (string)$_GET[$name])) {
			throw new MsHttpError(400, 'bad_request', "Query parameter $name must be an integer");
		}
		$v = (int)$_GET[$name];
		return max($min, min($max, $v));
	}

	public static function uuid4() {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		$h = bin2hex($b);
		return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
	}

	public static function isUuid($s) {
		return is_string($s) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1;
	}

	public static function isSha256($s) {
		return is_string($s) && preg_match('/^[0-9a-f]{64}$/', $s) === 1;
	}
}
