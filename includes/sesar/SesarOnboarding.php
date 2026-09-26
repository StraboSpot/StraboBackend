<?php
/**
 * File: includes/sesar/SesarOnboarding.php
 * Description: Gets a user from "never used SESAR" to "connected, with a SESAR
 *              code", with as little friction as SESAR allows (D1 addition,
 *              2026-09-26). SESAR's answers decide the step:
 *
 *                no_account     the ORCID has no SESAR account: sign in to
 *                               SESAR once with ORCID, then Check again
 *                no_permission  the account lacks API permission, which SESAR
 *                               staff grant BY HAND: we file the request for
 *                               the user (POST api-access-request/), then
 *                               Check again once SESAR approves
 *                no_code        connected, but no SESAR code to register
 *                               under: we create one (POST sesar-codes/)
 *                connected      ready to mint
 *
 *              The ORCID id_token our callback verified is stored ENCRYPTED
 *              for its own 24 h life, so "Check again" works without another
 *              ORCID popup. Once the SESAR connection exists it is dropped.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarAccess.php';
require_once __DIR__ . '/SesarCrypto.php';
require_once __DIR__ . '/SesarClient.php';
require_once __DIR__ . '/SesarConnection.php';

class SesarOnboarding
{
	/** Pre-written, editable message for SESAR's API access request. */
	const REQUEST_MESSAGE = "I would like to use the SESAR API to register IGSNs for my samples through StraboSpot (https://strabospot.org), which connects to SESAR on my behalf.";

	/** Refuse a repeat access request inside this window (SESAR staff read every one). */
	const REQUEST_REPEAT_AFTER = 86400;

	const MAX_FIELD = 255;
	const MAX_NAME = 100;
	const MAX_MESSAGE = 5000;

	private $db;
	private $client;
	private $conn;
	private $env;
	private $key;

	public function __construct($db, SesarClient $client, SesarConnection $conn, $key = null)
	{
		$this->db = $db;
		$this->client = $client;
		$this->conn = $conn;
		$this->env = $client->environment();
		$this->key = ($key === null) ? SesarAccess::tokenKey() : $key;
	}

	/**
	 * The callback verified an ORCID sign-in: remember it, then ask SESAR.
	 * @param array $identity SesarOrcid::exchangeCode() result
	 */
	public function acceptOrcid($userpkey, array $identity)
	{
		if ($this->key === null) throw new SesarError(500, 'SESAR token storage is not configured.');
		$this->db->prepare_query(
			"INSERT INTO strabosamples.sesar_onboarding (userpkey, environment, orcid, id_token_enc, id_token_expires_at, updated_at)
			 VALUES ($1, $2, $3, $4, $5, now())
			 ON CONFLICT (userpkey, environment) DO UPDATE SET
			     orcid = EXCLUDED.orcid, id_token_enc = EXCLUDED.id_token_enc,
			     id_token_expires_at = EXCLUDED.id_token_expires_at, updated_at = now()",
			array((int)$userpkey, $this->env, (string)$identity['orcid'],
			      SesarCrypto::seal((string)$identity['id_token'], $this->key), gmdate('c', (int)$identity['expires_at']))
		);
		return $this->check($userpkey);
	}

	/**
	 * Ask SESAR where the user stands now ("Check again"). Never throws for
	 * SESAR's answers; an unreachable SESAR is reported in last_error and
	 * leaves the step as it was.
	 */
	public function check($userpkey)
	{
		$summary = $this->conn->summary($userpkey);
		if ($summary['connected']) {
			try {
				$codes = SesarConnection::codeList($this->conn->refreshCodes($userpkey));
				$this->saveStage($userpkey, empty($codes) ? 'no_code' : 'connected', null, true);
				return $this->status($userpkey);
			} catch (SesarError $e) {
				if (!in_array($e->kind, array('auth', 'no_permission', 'no_account'), true)) {
					$this->saveError($userpkey, $e->getMessage());
					return $this->status($userpkey);
				}
				// The stored connection just died (accessToken marked it); fall through
				// and reconnect from the ORCID sign-in if we still hold one.
			}
		}

		$row = $this->row($userpkey);
		$idToken = $this->idToken($row);
		if ($idToken === null) return $this->status($userpkey);   // page offers the ORCID popup

		try {
			$after = $this->conn->connectWithOrcid($userpkey, $idToken, $row->orcid);
			$this->saveStage($userpkey, empty($after['sesar_codes']) ? 'no_code' : 'connected', null, true);
		} catch (SesarError $e) {
			if ($e->kind === 'no_permission' || $e->kind === 'no_account') {
				$this->saveStage($userpkey, $e->kind, null, false);
			} elseif ($e->kind === 'auth') {
				// SESAR refused the ORCID token itself: start over with a fresh sign-in.
				$this->dropIdToken($userpkey);
				$this->saveError($userpkey, $e->getMessage());
			} else {
				$this->saveError($userpkey, $e->getMessage());
			}
		}
		return $this->status($userpkey);
	}

	/**
	 * File SESAR's API access request for the user. $f: first_name,
	 * last_name, email, institution, position_role, message. The ORCID is
	 * the one our callback verified, never typed.
	 * @return array status(); ['request_result'] = 'sent' | 'failed'
	 * @throws SesarError validation (400) for bad input or wrong step
	 */
	public function requestAccess($userpkey, array $f)
	{
		$row = $this->row($userpkey);
		if ($row === null || $row->stage !== 'no_permission' || empty($row->orcid)) {
			throw new SesarError(400, 'API access can only be requested after SESAR reports that it is missing.');
		}
		if ($row->access_requested_at !== null && time() - strtotime($row->access_requested_at) < self::REQUEST_REPEAT_AFTER) {
			throw new SesarError(400, 'Your request was already sent to SESAR. Their staff review requests by hand; please allow them time to respond.');
		}
		$clean = array();
		$limits = array('first_name' => self::MAX_NAME, 'last_name' => self::MAX_NAME, 'email' => self::MAX_FIELD,
			'institution' => self::MAX_FIELD, 'position_role' => self::MAX_FIELD, 'message' => self::MAX_MESSAGE);
		foreach ($limits as $k => $max) {
			$v = isset($f[$k]) ? trim((string)$f[$k]) : '';
			if ($v === '') throw new SesarError(400, 'Please fill in every field.', array($k => array('required')));
			if (mb_strlen($v) > $max) throw new SesarError(400, 'That entry is too long.', array($k => array('too long')));
			$clean[$k] = $v;
		}
		if (!filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
			throw new SesarError(400, 'Please enter a valid email address.', array('email' => array('invalid')));
		}
		$clean['orcid'] = (string)$row->orcid;

		// Remember institution + role whatever SESAR says (typed once).
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_onboarding SET institution = $1, position_role = $2, updated_at = now()
			  WHERE userpkey = $3 AND environment = $4",
			array($clean['institution'], $clean['position_role'], (int)$userpkey, $this->env)
		);
		try {
			$this->client->requestApiAccess($clean);
		} catch (SesarError $e) {
			$this->db->prepare_query(
				"UPDATE strabosamples.sesar_onboarding SET access_request_error = $1, updated_at = now()
				  WHERE userpkey = $2 AND environment = $3",
				array(mb_substr($e->getMessage(), 0, 500), (int)$userpkey, $this->env)
			);
			$st = $this->status($userpkey);
			$st['request_result'] = 'failed';
			return $st;
		}
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_onboarding SET access_requested_at = now(), access_request_error = NULL, updated_at = now()
			  WHERE userpkey = $1 AND environment = $2",
			array((int)$userpkey, $this->env)
		);
		$st = $this->status($userpkey);
		$st['request_result'] = 'sent';
		return $st;
	}

	/**
	 * Create a personal SESAR code "IE" + $suffix (3 letters/digits).
	 * @throws SesarError validation with SESAR's reason (taken, malformed)
	 */
	public function createCode($userpkey, $suffix)
	{
		$suffix = strtoupper(trim((string)$suffix));
		if (strpos($suffix, 'IE') === 0 && strlen($suffix) === 5) $suffix = substr($suffix, 2);   // pasted the whole code
		if (!preg_match('/^[A-Z0-9]{3}$/', $suffix)) {
			throw new SesarError(400, 'Use exactly 3 letters or digits after "IE".', array('sesar_code' => array('format')));
		}
		$client = $this->client;
		$this->conn->withAccess($userpkey, function ($access) use ($client, $suffix) { return $client->createCode($access, 'IE' . $suffix); });
		return $this->check($userpkey);
	}

	/** Forget the SESAR connection and the ORCID sign-in (remembered form fields stay). */
	public function disconnect($userpkey)
	{
		$this->conn->disconnect($userpkey);
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_onboarding SET id_token_enc = NULL, id_token_expires_at = NULL, stage = NULL,
			        last_error = NULL, updated_at = now()
			  WHERE userpkey = $1 AND environment = $2",
			array((int)$userpkey, $this->env)
		);
		return $this->status($userpkey);
	}

	/**
	 * Everything the page needs, never a token.
	 *   step: not_connected | reconnect | no_account | no_permission | no_code | connected
	 *   can_check: "Check again" can ask SESAR without a new ORCID popup
	 */
	public function status($userpkey)
	{
		$c = $this->conn->summary($userpkey);
		$row = $this->row($userpkey);
		$hasToken = ($this->idToken($row) !== null);
		$stage = $row !== null ? $row->stage : null;

		if ($c['connected']) {
			$step = empty($c['sesar_codes']) ? 'no_code' : 'connected';
		} elseif ($stage === 'no_account' || $stage === 'no_permission') {
			$step = $stage;
		} elseif ($c['status'] === 'needs_reconnect') {
			$step = 'reconnect';
		} else {
			$step = 'not_connected';
		}

		$user = $this->db->get_row_prepared("SELECT firstname, lastname, email FROM users WHERE pkey = $1", array((int)$userpkey));
		$remembered = $this->db->get_row_prepared(
			"SELECT institution, position_role FROM strabosamples.sesar_onboarding
			  WHERE userpkey = $1 AND institution IS NOT NULL ORDER BY (environment = $2) DESC, updated_at DESC LIMIT 1",
			array((int)$userpkey, $this->env)
		);
		$sesarUser = isset($c['sesar_user']['user']) && is_array($c['sesar_user']['user']) ? $c['sesar_user']['user'] : null;
		$app = SesarAccess::appBase($this->env);

		return array(
			'environment'          => $this->env,
			'step'                 => $step,
			'can_check'            => $c['connected'] || $hasToken,
			'orcid'                => $row !== null && $row->orcid !== null ? $row->orcid : (isset($c['orcid']) ? $c['orcid'] : null),
			'orcid_valid_until'    => $hasToken ? self::iso($row->id_token_expires_at) : null,
			'checked_at'           => $row !== null ? self::iso($row->checked_at) : null,
			'last_error'           => $row !== null ? $row->last_error : null,
			'access_requested_at'  => $row !== null ? self::iso($row->access_requested_at) : null,
			'access_request_error' => $row !== null ? $row->access_request_error : null,
			'sesar_codes'          => $c['connected'] ? $c['sesar_codes'] : array(),
			'last_sesar_code'      => isset($c['last_sesar_code']) ? $c['last_sesar_code'] : null,
			'sesar_account'        => $sesarUser === null ? null : array(
				'name'  => isset($sesarUser['individual']['label']) ? $sesarUser['individual']['label'] : null,
				'email' => isset($sesarUser['email']) ? $sesarUser['email'] : null,
			),
			'request_form'         => array(
				'first_name'    => $user ? (string)$user->firstname : '',
				'last_name'     => $user ? (string)$user->lastname : '',
				'email'         => $user ? (string)$user->email : '',
				'institution'   => $remembered ? (string)$remembered->institution : '',
				'position_role' => $remembered ? (string)$remembered->position_role : '',
				'message'       => self::REQUEST_MESSAGE,
			),
			'links'                => array(
				'sesar'              => $app,
				'developer_settings' => $app . 'profile/developer-settings',
				'code_help'          => 'https://docs.geosamples.org/for-researchers/accounts-and-access/choose-a-sesar-code',
			),
		);
	}

	// -----------------------------------------------------------------------

	private function row($userpkey)
	{
		return $this->db->get_row_prepared(
			"SELECT * FROM strabosamples.sesar_onboarding WHERE userpkey = $1 AND environment = $2",
			array((int)$userpkey, $this->env)
		);
	}

	/** The stored ORCID id_token while it is still valid (1 min margin), else null. */
	private function idToken($row)
	{
		if ($row === null || $row->id_token_enc === null || $row->id_token_expires_at === null) return null;
		if (strtotime($row->id_token_expires_at) - time() < 60) return null;
		return $this->key === null ? null : SesarCrypto::open($row->id_token_enc, $this->key);
	}

	/** Record SESAR's answer; once connected the ORCID sign-in is no longer needed. */
	private function saveStage($userpkey, $stage, $error, $dropToken)
	{
		$this->db->prepare_query(
			"INSERT INTO strabosamples.sesar_onboarding (userpkey, environment, stage, checked_at, last_error, updated_at)
			 VALUES ($1, $2, $3, now(), $4, now())
			 ON CONFLICT (userpkey, environment) DO UPDATE SET
			     stage = EXCLUDED.stage, checked_at = now(), last_error = EXCLUDED.last_error, updated_at = now()"
			. ($dropToken ? ", id_token_enc = NULL, id_token_expires_at = NULL" : ""),
			array((int)$userpkey, $this->env, $stage, $error)
		);
	}

	private function saveError($userpkey, $message)
	{
		$this->db->prepare_query(
			"INSERT INTO strabosamples.sesar_onboarding (userpkey, environment, checked_at, last_error, updated_at)
			 VALUES ($1, $2, now(), $3, now())
			 ON CONFLICT (userpkey, environment) DO UPDATE SET checked_at = now(), last_error = EXCLUDED.last_error, updated_at = now()",
			array((int)$userpkey, $this->env, mb_substr((string)$message, 0, 500))
		);
	}

	private function dropIdToken($userpkey)
	{
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_onboarding SET id_token_enc = NULL, id_token_expires_at = NULL, stage = NULL, updated_at = now()
			  WHERE userpkey = $1 AND environment = $2",
			array((int)$userpkey, $this->env)
		);
	}

	private static function iso($ts)
	{
		return $ts === null ? null : gmdate('c', strtotime($ts));
	}
}
