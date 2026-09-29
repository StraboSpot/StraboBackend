<?php
/**
 * File: includes/sesar/SesarClient.php
 * Description: Thin client for the SESAR REST API (https://docs.geosamples.org/api).
 *              Stateless: the caller passes the access token (see
 *              SesarConnection for storage and refresh). Every SESAR call is
 *              server-side; SESAR's CORS allowlist excludes strabospot.org.
 *
 *              Verified against the sandbox 2026-09-25 (Phase 1 probes):
 *              - POST auth/token/{connection}/ takes a FORM-encoded `token`
 *                holding the ORCID id_token (JSON bodies and the ORCID access
 *                token are rejected). 401 errors.permissions = the SESAR
 *                account lacks API upload permission.
 *              - List responses: {count,next,previous,data,page_size,...}
 *                (the OpenAPI spec says `results`; the server sends `data`).
 *              - Numbers come back as strings; object_type comes back as the
 *                hierarchical path but is written as the leaf label.
 *
 *              The HTTP layer is a SesarTransport so tests swap in a fake
 *              SESAR (tests/sesar/FakeSesar.php) and never touch the network.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarAccess.php';

/** Any non-2xx SESAR answer or transport failure, normalized. */
class SesarError extends Exception
{
	public $status;   // HTTP status; 0 = no response (network / timeout)
	public $errors;   // SESAR's errors object, field => [messages]
	public $kind;     // see kindFor()

	public function __construct($status, $message, $errors = array())
	{
		parent::__construct($message);
		$this->status = (int)$status;
		$this->errors = is_array($errors) ? $errors : array();
		$this->kind = self::kindFor($this->status, $this->errors);
	}

	/**
	 * Coarse category the UI turns into guidance (D1 addition):
	 *   no_account     ORCID id_token rejected: with a token our callback
	 *                  just verified, the ORCID has no SESAR account
	 *   no_permission  SESAR account lacks API upload permission
	 *   auth           token invalid / expired / revoked (401): reconnect
	 *   forbidden      the token is fine, but this account may not do that
	 *                  (403: another account's private or read-only
	 *                  record). Never a reason to refresh or reconnect.
	 *   not_found      no such sample (or not visible to this account)
	 *   gone           deactivated IGSN (410 tombstone)
	 *   validation     SESAR rejected the payload (field errors)
	 *   network        no response / timeout: outcome unknown for writes
	 *   server         anything else
	 */
	public static function kindFor($status, $errors)
	{
		if ($status === 0) return 'network';
		if ($status === 401 || $status === 403) {
			if (isset($errors['permissions'])) return 'no_permission';
			if (isset($errors['token'])) return 'no_account';
			return $status === 403 ? 'forbidden' : 'auth';
		}
		if ($status === 404) return 'not_found';
		if ($status === 410) return 'gone';
		if ($status === 400 || $status === 422) return 'validation';
		return 'server';
	}
}

interface SesarTransport
{
	/**
	 * @param string   $method  GET|POST|PATCH
	 * @param string   $url     absolute
	 * @param string[] $headers
	 * @param string|null $body
	 * @return array [int status (0 = no response), string body]
	 */
	public function send($method, $url, array $headers, $body);
}

/** A transport that can run several independent GETs at once (public IGSN lookups). */
interface SesarParallelTransport extends SesarTransport
{
	/**
	 * @param array $requests key => [method, url, headers[], body]
	 * @return array key => [int status, string body]
	 */
	public function sendMany(array $requests);
}

class SesarCurlTransport implements SesarParallelTransport
{
	const TIMEOUT = 60;
	const PARALLEL = 8;

	public function send($method, $url, array $headers, $body)
	{
		$ch = curl_init($url);
		$opts = array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_FOLLOWLOCATION => false,
		);
		if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
		curl_setopt_array($ch, $opts);
		$resp = curl_exec($ch);
		$status = ($resp === false) ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return array($status, $resp === false ? '' : (string)$resp);
	}

	public function sendMany(array $requests)
	{
		$out = array();
		foreach (array_chunk($requests, self::PARALLEL, true) as $chunk) {
			$mh = curl_multi_init();
			$handles = array();
			foreach ($chunk as $k => $r) {
				list($method, $url, $headers, $body) = $r;
				$ch = curl_init($url);
				curl_setopt_array($ch, array(
					CURLOPT_CUSTOMREQUEST  => $method,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CONNECTTIMEOUT => 15,
					CURLOPT_TIMEOUT        => self::TIMEOUT,
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_FOLLOWLOCATION => false,
				));
				if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
				curl_multi_add_handle($mh, $ch);
				$handles[$k] = $ch;
			}
			do {
				$st = curl_multi_exec($mh, $running);
				if ($running) curl_multi_select($mh, 1.0);
			} while ($running && $st === CURLM_OK);
			foreach ($handles as $k => $ch) {
				$resp = curl_multi_getcontent($ch);
				$status = (curl_errno($ch) !== 0 || $resp === null) ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$out[$k] = array($status, $status === 0 ? '' : (string)$resp);
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);
			}
			curl_multi_close($mh);
		}
		return $out;
	}
}

class SesarClient
{
	private $env;
	private $transport;

	public function __construct($env = null, SesarTransport $transport = null)
	{
		$this->env = ($env === null) ? SesarAccess::environment() : $env;
		$this->transport = ($transport === null) ? new SesarCurlTransport() : $transport;
	}

	public function environment() { return $this->env; }

	// -----------------------------------------------------------------------
	// Auth
	// -----------------------------------------------------------------------

	/** ORCID id_token -> SESAR JWT pair {refresh, access} carrying the connection claim. */
	public function tokenFromOrcid($orcidIdToken, $connection = SesarAccess::CONNECTION_NAME)
	{
		$d = $this->call('POST', 'auth/token/' . rawurlencode($connection) . '/', null,
			array('token' => $orcidIdToken), 'form');
		return $this->pair($d);
	}

	/** Rotating refresh: the old refresh token is blacklisted by SESAR once this returns. */
	public function refresh($refreshToken)
	{
		$d = $this->call('POST', 'auth/token/refresh/', null, array('refresh' => $refreshToken), 'form');
		return $this->pair($d);
	}

	public function currentUser($access)
	{
		return $this->unwrap($this->call('GET', 'auth/user/', $access));
	}

	/**
	 * Files SESAR's "Request permission for using the API" form for a user
	 * (D1 addition). Anonymous endpoint; emails SESAR staff, who approve by
	 * hand. The ORCID must already have a SESAR account. $fields: message,
	 * first_name, last_name, email, orcid, institution, position_role.
	 */
	public function requestApiAccess(array $fields)
	{
		$this->call('POST', 'api-access-request/', null, $fields, 'json');
	}

	/** Creates a PERSONAL SESAR code ("IE" + 3 alphanumerics). 400 = taken or malformed. */
	public function createCode($access, $code)
	{
		return $this->unwrap($this->call('POST', 'sesar-codes/', $access, array('sesar_code' => (string)$code), 'json'));
	}

	/** SESAR codes this account may register under (D2 dropdown). */
	public function codesForCreate($access)
	{
		$d = $this->call('GET', 'sesar-codes/by-permission/', $access, array('permission' => 'create_sample'));
		return $this->listData($d);
	}

	// -----------------------------------------------------------------------
	// Samples
	// -----------------------------------------------------------------------

	public function registerSample($access, array $payload)
	{
		return $this->unwrap($this->call('POST', 'samples/', $access, $payload, 'json'));
	}

	/** Partial update (PATCH). Lists sent are REPLACED at SESAR, not merged (D6). */
	public function updateSample($access, $igsn, array $patch)
	{
		return $this->unwrap($this->call('PATCH', 'samples/' . self::igsnPath($igsn) . '/', $access, $patch, 'json'));
	}

	/** $access may be null for published samples. */
	public function getSample($access, $igsn)
	{
		return $this->unwrap($this->call('GET', 'samples/' . self::igsnPath($igsn) . '/', $access));
	}

	/**
	 * One page of samples. With a token the default scope is 'personal'
	 * (own samples incl. drafts). Returns {count, next, data[]}.
	 */
	public function listSamples($access, array $query = array())
	{
		$d = $this->call('GET', 'samples/', $access, $query);
		return array(
			'count' => isset($d['count']) ? (int)$d['count'] : 0,
			'next'  => isset($d['next']) ? $d['next'] : null,
			'data'  => $this->listData($d),
		);
	}

	/** D3 duplicate guard: our samples are registered with external_sample_id = our id. */
	public function findByExternalId($access, $externalId, $sesarCode = null)
	{
		$q = array('external_sample_id' => $externalId, 'page_size' => 10);
		if ($sesarCode !== null) $q['sesar_code'] = $sesarCode;
		$page = $this->listSamples($access, $q);
		return $page['data'];
	}

	/**
	 * This account's list row for one IGSN, or null when the IGSN is not
	 * among the account's own samples. The detail GET lacks sample_id,
	 * external_sample_id and last_update_date; the personal list filtered by
	 * ?igsn= has all three (sandbox 09-27, answers in well under a second).
	 */
	public function findOwnByIgsn($access, $igsn)
	{
		$page = $this->listSamples($access, array('igsn' => (string)$igsn, 'page_size' => 5));
		foreach ($page['data'] as $r) {
			if (is_array($r) && isset($r['igsn']) && strcasecmp((string)$r['igsn'], (string)$igsn) === 0) return $r;
		}
		return null;
	}

	/**
	 * Public existence check for stored IGSN values (D3 revised): anonymous
	 * GET samples/by-igsn/ for each normalized IGSN, several at once when the
	 * transport can. Never throws; per IGSN:
	 *   found      published record (record = SESAR's landing payload)
	 *   private    exists at SESAR but is not public (403)
	 *   not_found  SESAR has no such IGSN (404)
	 *   gone       deactivated (410 tombstone)
	 *   error      no answer / server error: existence UNKNOWN (callers
	 *              must treat it as "cannot decide", never as not_found)
	 *
	 * @param string[] $igsns normalized ("10.58052/IEJMA0002")
	 * @return array igsn => {status, record|null, message|null}
	 */
	public function lookupIgsns(array $igsns)
	{
		$reqs = array();
		foreach (array_values(array_unique($igsns)) as $i => $igsn) {
			$url = SesarAccess::apiBase($this->env) . 'samples/by-igsn/?' . http_build_query(array('igsn' => $igsn));
			$reqs[$igsn] = array('GET', $url, array('Accept: application/json'), null);
		}
		if (empty($reqs)) return array();
		if ($this->transport instanceof SesarParallelTransport) {
			$answers = $this->transport->sendMany($reqs);
		} else {
			$answers = array();
			foreach ($reqs as $k => $r) $answers[$k] = $this->transport->send($r[0], $r[1], $r[2], $r[3]);
		}
		$out = array();
		foreach ($reqs as $igsn => $r) {
			list($status, $raw) = isset($answers[$igsn]) ? $answers[$igsn] : array(0, '');
			$json = json_decode($raw, true);
			if ($status >= 200 && $status < 300) {
				$out[$igsn] = array('status' => 'found', 'record' => is_array($json) ? $this->unwrap($json) : array(), 'message' => null);
			} elseif ($status === 403) {
				$out[$igsn] = array('status' => 'private', 'record' => null, 'message' => null);
			} elseif ($status === 404) {
				$out[$igsn] = array('status' => 'not_found', 'record' => null, 'message' => null);
			} elseif ($status === 410) {
				$out[$igsn] = array('status' => 'gone', 'record' => is_array($json) ? $this->unwrap($json) : null, 'message' => null);
			} else {
				$out[$igsn] = array('status' => 'error', 'record' => null,
					'message' => $status === 0 ? 'SESAR did not respond.' : 'SESAR returned an error (HTTP ' . $status . ').');
			}
		}
		return $out;
	}

	/**
	 * uri_type of a web link. The API takes any string, but SESAR's sample
	 * page lists only "DOI", "LOCAL" (uploaded files) and "regular URL"
	 * (case-insensitive) under Linked Resources; anything else (we sent
	 * "URL" until 09-29) is stored but never shown.
	 */
	const LINK_URI_TYPE = 'regular URL';

	/**
	 * A related resource (D2: link back to the sample's StraboSpot page).
	 * Returns SESAR's id; link it at mint time via related_resources:[id].
	 */
	public function createRelatedResource($access, $label, $uri, $description = null)
	{
		$body = array('label' => (string)$label, 'related_resource_type' => 'PhysicalObject',
			'uri' => (string)$uri, 'uri_type' => self::LINK_URI_TYPE);
		if ($description !== null && $description !== '') $body['description'] = (string)$description;
		$d = $this->unwrap($this->call('POST', 'related-resources/', $access, $body, 'json'));
		if (!isset($d['id']) || !is_numeric($d['id'])) {
			throw new SesarError(502, 'SESAR did not return an id for the related resource.');
		}
		return (int)$d['id'];
	}

	/**
	 * Changes fields of one of the user's related resources (e.g. uri_type).
	 * Returns the resource as SESAR now holds it.
	 */
	public function updateRelatedResource($access, $resourceId, array $patch)
	{
		return $this->unwrap($this->call('PATCH', 'related-resources/' . (int)$resourceId . '/', $access, $patch, 'json'));
	}

	/**
	 * One of the user's related resources with exactly this URI, or null.
	 * SESAR refuses a second resource with the same URI ("A resource with
	 * this filename/URI already exists.", sandbox 09-27) and its `search`
	 * matches LABELS only, so $labelTerm (a token our labels carry) narrows
	 * it first; failing that, up to $maxPages pages of the list are scanned.
	 */
	public function findRelatedResourceByUri($access, $uri, $labelTerm = null, $maxPages = 5)
	{
		$queries = array();
		if ($labelTerm !== null && $labelTerm !== '') $queries[] = array('search' => (string)$labelTerm, 'page_size' => 100);
		for ($p = 1; $p <= $maxPages; $p++) $queries[] = array('page' => $p, 'page_size' => 100, 'ordering' => '-id');
		foreach ($queries as $q) {
			$d = $this->call('GET', 'related-resources/', $access, $q);
			$rows = $this->listData($d);
			foreach ($rows as $r) {
				if (is_array($r) && isset($r['uri'], $r['id']) && (string)$r['uri'] === (string)$uri) return (int)$r['id'];
			}
			if (!isset($q['search']) && (empty($rows) || empty($d['next']))) break;
		}
		return null;
	}

	/**
	 * Links samples (SESAR's integer sample ids) to an existing related
	 * resource. Used instead of PATCH related_resources, which REPLACES a
	 * sample's non-image links (sandbox spec).
	 */
	public function linkSamplesToResource($access, $resourceId, array $sesarSampleIds)
	{
		$ids = array_values(array_map('intval', $sesarSampleIds));
		return $this->call('POST', 'related-resources/' . (int)$resourceId . '/link-samples/', $access, array('sample_ids' => $ids), 'json');
	}

	/** SESAR DeactivateReasonEnum, verbatim. */
	const DEACTIVATE_REASONS = array('this was a test sample', 'this sample does not exist', 'duplicate igsn', 'other');

	/**
	 * D7: asks a SESAR curator to deactivate. $detail is other_reason (max 250)
	 * for 'other', or the duplicate IGSN(s) (max 1000) for 'duplicate igsn'.
	 */
	public function requestDeactivation($access, $igsn, $reason, $detail = null)
	{
		if (!in_array($reason, self::DEACTIVATE_REASONS, true)) {
			throw new SesarError(400, 'Unknown deactivation reason.', array('deactivate_reason' => array('invalid')));
		}
		$body = array('deactivate_reason' => $reason);
		if ($detail !== null && $detail !== '') {
			if ($reason === 'other') $body['other_reason'] = mb_substr((string)$detail, 0, 250);
			if ($reason === 'duplicate igsn') $body['duplicate_igsns'] = mb_substr((string)$detail, 0, 1000);
		}
		return $this->unwrap($this->call('POST', 'samples/' . self::igsnPath($igsn) . '/deactivate/', $access, $body, 'json'));
	}

	// -----------------------------------------------------------------------
	// Vocabularies (public)
	// -----------------------------------------------------------------------

	public function vocab($name)
	{
		return $this->listData($this->call('GET', 'vocab/' . rawurlencode($name) . '/', null));
	}

	// -----------------------------------------------------------------------
	// Plumbing
	// -----------------------------------------------------------------------

	/** "10.58052/IEJMA0001" keeps its slash: SESAR's route is samples/10.58052/IEJMA0001/. */
	public static function igsnPath($igsn)
	{
		return implode('/', array_map('rawurlencode', explode('/', trim((string)$igsn))));
	}

	/**
	 * @param string      $method
	 * @param string      $path    relative to the environment's API base
	 * @param string|null $access  bearer token
	 * @param array|null  $data    query (GET) or body (POST/PATCH)
	 * @param string      $enc     'json' | 'form' for bodies
	 * @return array decoded JSON
	 * @throws SesarError
	 */
	private function call($method, $path, $access, $data = null, $enc = 'json')
	{
		$url = SesarAccess::apiBase($this->env) . $path;
		$headers = array('Accept: application/json');
		if ($access !== null && $access !== '') $headers[] = 'Authorization: Bearer ' . $access;
		$body = null;
		if ($method === 'GET') {
			if (!empty($data)) $url .= '?' . http_build_query($data);
		} elseif ($data !== null) {
			if ($enc === 'form') {
				$headers[] = 'Content-Type: application/x-www-form-urlencoded';
				$body = http_build_query($data);
			} else {
				$headers[] = 'Content-Type: application/json';
				$body = json_encode($data);
			}
		}
		list($status, $raw) = $this->transport->send($method, $url, $headers, $body);
		$json = json_decode($raw, true);
		if ($status >= 200 && $status < 300) {
			return is_array($json) ? $json : array();
		}
		if ($status === 0) {
			throw new SesarError(0, 'SESAR did not respond. Please try again.');
		}
		$errors = (is_array($json) && isset($json['errors']) && is_array($json['errors'])) ? $json['errors'] : array();
		// Some endpoints (sesar-codes/) answer 400 with a BARE field dict:
		// {"sesar_code": ["...already exists."]}. Treat it as the errors object.
		if (empty($errors) && is_array($json) && !isset($json['message']) && !isset($json['detail'])) {
			foreach ($json as $f => $v) {
				if (is_array($v)) $errors[$f] = $v;
			}
		}
		$msg = (is_array($json) && isset($json['message']) && is_string($json['message'])) ? $json['message']
			: ((is_array($json) && isset($json['detail']) && is_string($json['detail'])) ? $json['detail']
			: (self::firstMessage($errors) !== null ? self::firstMessage($errors)
			: 'SESAR returned an error (HTTP ' . $status . ').'));
		throw new SesarError($status, $msg, $errors);
	}

	private static function firstMessage(array $errors)
	{
		foreach ($errors as $v) {
			if (is_string($v)) return $v;
			if (is_array($v) && isset($v[0]) && is_string($v[0])) return $v[0];
		}
		return null;
	}

	/** Most SESAR detail/create responses wrap the object in {"data": {...}}. */
	private function unwrap($d)
	{
		return (isset($d['data']) && is_array($d['data'])) ? $d['data'] : $d;
	}

	private function listData($d)
	{
		if (isset($d['data']) && is_array($d['data'])) return $d['data'];
		if (isset($d['results']) && is_array($d['results'])) return $d['results'];
		return (array_values($d) === $d) ? $d : array();
	}

	private function pair($d)
	{
		$p = $this->unwrap($d);
		if (empty($p['access']) || empty($p['refresh'])) {
			throw new SesarError(502, 'SESAR returned an incomplete token pair.');
		}
		return array('access' => (string)$p['access'], 'refresh' => (string)$p['refresh']);
	}

	/** Expiry of a JWT from its exp claim (unix time), or null. Payload only; SESAR verifies signatures. */
	public static function jwtExpiry($jwt)
	{
		$parts = explode('.', (string)$jwt);
		if (count($parts) < 2) return null;
		$claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
		return (is_array($claims) && isset($claims['exp'])) ? (int)$claims['exp'] : null;
	}
}
