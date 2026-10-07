<?php
/**
 * File: VsHttp.php
 * Description: Errors and response helpers for the Voice Stations endpoints
 *              under /db/ (docs/AlternateStraboFieldIdea/Phase1_Plan.md).
 *              Errors follow the /db/ convention plus a machine-readable
 *              code: {"Error": text, "code": code, "field": name (400 only)}.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class VsHttpError extends Exception {
	public $status;
	public $errorCode;
	public $field;

	public function __construct($status, $errorCode, $message, $field = null) {
		parent::__construct($message);
		$this->status = $status;
		$this->errorCode = $errorCode;
		$this->field = $field;
	}
}

class VsHttp {

	const JSON_OUT = 1344; // JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION

	public static function bad($field, $message) {
		return new VsHttpError(400, 'bad_request', $message, $field);
	}

	public static function notFound($message = 'Not found.') {
		return new VsHttpError(404, 'not_found', $message);
	}

	public static function conflict($message) {
		return new VsHttpError(409, 'conflict', $message);
	}

	/** Send a JSON body with a status and end the request. */
	public static function send($status, $data) {
		http_response_code($status);
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		echo json_encode($data, self::JSON_OUT);
		exit;
	}

	/**
	 * Run one endpoint body: its return value is sent as JSON with the status
	 * it names, errors become the error envelope, anything unexpected is
	 * logged and answered with a plain 500.
	 *
	 * $fn returns array($status, $data).
	 */
	public static function run($fn) {
		try {
			list($status, $data) = $fn();
			self::send($status, $data);
		} catch (VsHttpError $e) {
			$body = array('Error' => $e->getMessage(), 'code' => $e->errorCode);
			if ($e->field !== null) {
				$body['field'] = $e->field;
			}
			self::send($e->status, $body);
		} catch (Exception $e) {
			VsLog::error('unexpected: ' . get_class($e) . ': ' . $e->getMessage());
			self::send(500, array('Error' => 'Something went wrong on the server.', 'code' => 'server_error'));
		}
	}

	/** Read a JSON object body of at most $maxBytes. Objects stay stdClass. */
	public static function readJson($maxBytes) {
		$len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
		if ($len > $maxBytes) {
			throw new VsHttpError(413, 'too_large', "The request body is larger than $maxBytes bytes.");
		}
		$in = fopen('php://input', 'rb');
		$raw = stream_get_contents($in, $maxBytes + 1);
		fclose($in);
		if (strlen($raw) > $maxBytes) {
			throw new VsHttpError(413, 'too_large', "The request body is larger than $maxBytes bytes.");
		}
		return self::decodeObject($raw, null);
	}

	/** Decode a JSON object (big integers kept as strings). */
	public static function decodeObject($raw, $field) {
		if (!is_string($raw) || $raw === '') {
			throw self::bad($field, 'A JSON object is required.');
		}
		$data = json_decode($raw, false, 512, JSON_BIGINT_AS_STRING);
		if (json_last_error() !== JSON_ERROR_NONE || !is_object($data)) {
			throw self::bad($field, 'This must be a JSON object.');
		}
		return $data;
	}

	/** Property of a decoded JSON object, or $default when absent. */
	public static function prop($obj, $key, $default = null) {
		return (is_object($obj) && property_exists($obj, $key)) ? $obj->$key : $default;
	}

	public static function isUuid($s) {
		return is_string($s) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1;
	}

	/** The UUID segment after /db/<route>/, lower case, or a 404. */
	public static function uuidFromUrl($request) {
		$u = isset($request->url_elements[2]) ? strtolower(trim($request->url_elements[2])) : '';
		if (!self::isUuid($u)) {
			throw self::notFound();
		}
		return $u;
	}
}

class VsLog {

	/** Append to voicestations_data/log/errors.log (and PHP's error log). */
	public static function error($message) {
		error_log('voicestations: ' . $message);
		$dir = VsConfig::dataRoot() . '/log';
		if (is_dir($dir)) {
			@file_put_contents($dir . '/errors.log', gmdate('c') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
		}
	}
}
