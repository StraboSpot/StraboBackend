<?php
/**
 * File: includes/sesar/SesarEndpoint.php
 * Description: What every sesar_*.php endpoint does around its own work:
 *              the logged-in user of a POST request, the JSON body, the
 *              pilot gate, and the answer for a SesarError. One copy, so the
 *              endpoints can never drift apart.
 *
 *              config.inc.php, db.php and neodb.php are NOT included here:
 *              they set plain variables ($db, $neodb, the credentials), so
 *              each endpoint includes them itself, at global scope, between
 *              json() / user() and gate().
 *
 *              An endpoint reads:
 *                $userpkey = SesarEndpoint::user();
 *                $input = SesarEndpoint::json();
 *                (config.inc.php, db.php, neodb.php, its own class)
 *                SesarEndpoint::gate($userpkey);
 *                try { ... SesarEndpoint::out(200, ...); }
 *                catch (SesarError $e) { SesarEndpoint::fail($e); }
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarClient.php';   // SesarError, SesarAccess

class SesarEndpoint
{
	/** Error kinds that mean "connect (again) or finish the SESAR setup": answered 409. */
	const SETUP_KINDS = array('auth', 'no_permission', 'no_account');

	/** A JSON answer; the request ends here. */
	public static function out($code, array $payload)
	{
		http_response_code((int)$code);
		header('Content-Type: application/json');
		echo json_encode($payload);
		exit;
	}

	/**
	 * The logged-in user of a POST request (401 / 405 otherwise). The session
	 * lock is released before returning: SESAR calls can take seconds.
	 * @return int userpkey
	 */
	public static function user()
	{
		include_once __DIR__ . '/../session_config.php';
		session_start();
		header('Cache-Control: no-store');
		if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT)) {
			$_SESSION['loggedin'] = 'no';
		}
		if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey'])) {
			self::out(401, array('ok' => false, 'error' => 'not_authenticated', 'message' => 'Your session has ended. Please log in again.'));
		}
		$_SESSION['LAST_ACTIVITY'] = time();
		$userpkey = (int)$_SESSION['userpkey'];
		session_write_close();

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			self::out(405, array('ok' => false, 'error' => 'method', 'message' => 'POST only.'));
		}
		return $userpkey;
	}

	/**
	 * The JSON body {action, ...}. JSON only (415 otherwise): a form on
	 * another site cannot send this content type, so it cannot act for a
	 * logged-in user (the page scripts always send it).
	 * @return array
	 */
	public static function json()
	{
		$ctype = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim(explode(';', (string)$_SERVER['CONTENT_TYPE'])[0])) : '';
		if ($ctype !== 'application/json') {
			self::out(415, array('ok' => false, 'error' => 'content_type', 'message' => 'Bad request.'));
		}
		$input = json_decode(file_get_contents('php://input'), true);
		if (!is_array($input) || !isset($input['action']) || !is_string($input['action'])) {
			self::out(400, array('ok' => false, 'error' => 'invalid_json', 'message' => 'Bad request.'));
		}
		return $input;
	}

	/**
	 * Soft-launch gate (403) and, unless the endpoint never talks to SESAR,
	 * the server's SESAR settings (503). Call after config.inc.php.
	 */
	public static function gate($userpkey, $message = 'SESAR features are not available for this account.', $needsSesar = true)
	{
		if (!SesarAccess::canUse($userpkey)) {
			self::out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => $message));
		}
		if ($needsSesar && !SesarAccess::isConfigured()) {
			self::out(503, array('ok' => false, 'error' => 'not_configured', 'message' => 'SESAR is not configured on this server yet.'));
		}
	}

	/** fn($key) -> the body's scalar value as a string, '' when missing or not scalar. */
	public static function reader(array $input)
	{
		return function ($k) use ($input) { return isset($input[$k]) && is_scalar($input[$k]) ? (string)$input[$k] : ''; };
	}

	/**
	 * HTTP status for a SesarError. $passThrough: statuses the endpoint
	 * answers as they are (its own 404 / 409 ..., SESAR's 403 / 410).
	 */
	public static function status(SesarError $e, array $passThrough = array())
	{
		if (in_array($e->kind, self::SETUP_KINDS, true)) return 409;
		if ($e->kind === 'forbidden') return 403;
		if ($e->kind === 'validation') return 400;
		if ($e->kind === 'network') return 504;
		return in_array($e->status, $passThrough, true) ? $e->status : 502;
	}

	/**
	 * SESAR did not answer (no response, 503, 504): one plain message,
	 * unless the error already says what to do (its text holds $unless).
	 */
	public static function timeout(SesarError $e, $unless = null)
	{
		if (!in_array($e->status, array(0, 503, 504), true)) return $e;
		if ($unless !== null && stripos($e->getMessage(), $unless) !== false) return $e;
		return new SesarError($e->status, 'SESAR did not answer in time. Please try again in a moment.', $e->errors);
	}

	/** The answer for a SesarError: {ok:false, error, message, fields} plus $extra. */
	public static function fail(SesarError $e, array $passThrough = array(), array $extra = array())
	{
		self::out(self::status($e, $passThrough),
			array('ok' => false, 'error' => $e->kind, 'message' => $e->getMessage(), 'fields' => $e->errors) + $extra);
	}
}
