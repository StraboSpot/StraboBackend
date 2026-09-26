<?php
/**
 * File: includes/sesar/SesarOrcid.php
 * Description: The ORCID half of "Connect SESAR" (D1): the authorize URL for
 *              the popup and the server-side code exchange that yields the
 *              ORCID id_token SESAR accepts (POST auth/token/{connection}/).
 *
 *              Uses the WEB ORCID client ($sesar_orcid_client_*), never the
 *              Field app's client or orcid_callback.php. The id_token comes
 *              straight from ORCID's token endpoint over TLS, so its claims
 *              are checked (issuer, audience, expiry, subject) but its
 *              signature is not re-verified here; SESAR verifies it again.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarClient.php';

class SesarOrcid
{
	const AUTHORIZE_URL = 'https://orcid.org/oauth/authorize';
	const TOKEN_URL = 'https://orcid.org/oauth/token';
	const ISSUER = 'https://orcid.org';

	/** Session key holding the popup's one-time state nonce. */
	const STATE_KEY = 'sesar_orcid_state';
	/** A popup left open longer than this must start over. */
	const STATE_TTL = 900;

	private $transport;

	public function __construct(SesarTransport $transport = null)
	{
		$this->transport = ($transport === null) ? new SesarCurlTransport() : $transport;
	}

	public static function authorizeUrl($state)
	{
		return self::AUTHORIZE_URL . '?' . http_build_query(array(
			'client_id'     => SesarAccess::orcidClientId(),
			'response_type' => 'code',
			'scope'         => 'openid',
			'redirect_uri'  => SesarAccess::ORCID_REDIRECT_URI,
			'state'         => $state,
		));
	}

	/** New nonce bound to this PHP session (session must be started). */
	public static function newState()
	{
		$state = bin2hex(random_bytes(16));
		$_SESSION[self::STATE_KEY] = array('value' => $state, 'at' => time());
		return $state;
	}

	/** One-time check: the nonce is consumed whether or not it matches. */
	public static function consumeState($state)
	{
		$saved = isset($_SESSION[self::STATE_KEY]) ? $_SESSION[self::STATE_KEY] : null;
		unset($_SESSION[self::STATE_KEY]);
		if (!is_array($saved) || !is_string($state) || $state === '') return false;
		if (time() - (int)$saved['at'] > self::STATE_TTL) return false;
		return hash_equals((string)$saved['value'], $state);
	}

	/**
	 * Authorization code -> verified identity.
	 * @return array {id_token, orcid, name, expires_at (unix)}
	 * @throws SesarError kind 'auth' when ORCID refuses or the token is wrong
	 */
	public function exchangeCode($code)
	{
		$code = trim((string)$code);
		if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)) {
			throw new SesarError(401, 'That ORCID sign-in code is not valid.');
		}
		list($status, $raw) = $this->transport->send('POST', self::TOKEN_URL,
			array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'),
			http_build_query(array(
				'client_id'     => SesarAccess::orcidClientId(),
				'client_secret' => SesarAccess::orcidClientSecret(),
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => SesarAccess::ORCID_REDIRECT_URI,
			)));
		if ($status === 0) throw new SesarError(0, 'ORCID did not respond. Please try again.');
		$j = json_decode($raw, true);
		if ($status < 200 || $status >= 300 || !is_array($j) || empty($j['id_token'])) {
			// Codes are single-use and short-lived: the usual cause is a reused or stale code.
			throw new SesarError(401, 'ORCID sign-in did not complete (the sign-in code may have expired). Please try again.');
		}
		$claims = self::claims($j['id_token']);
		if ($claims === null
			|| !isset($claims['iss'], $claims['aud'], $claims['sub'], $claims['exp'])
			|| $claims['iss'] !== self::ISSUER
			|| !self::audienceMatches($claims['aud'])
			|| (int)$claims['exp'] <= time()
			|| (isset($j['orcid']) && $j['orcid'] !== $claims['sub'])) {
			throw new SesarError(401, 'ORCID returned an unexpected sign-in token. Please try again.');
		}
		return array(
			'id_token'   => (string)$j['id_token'],
			'orcid'      => (string)$claims['sub'],
			'name'       => isset($j['name']) ? (string)$j['name'] : null,
			'expires_at' => (int)$claims['exp'],
		);
	}

	private static function audienceMatches($aud)
	{
		$want = SesarAccess::orcidClientId();
		if ($want === '') return false;
		return is_array($aud) ? in_array($want, $aud, true) : ($aud === $want);
	}

	public static function claims($jwt)
	{
		$parts = explode('.', (string)$jwt);
		if (count($parts) !== 3) return null;
		$c = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
		return is_array($c) ? $c : null;
	}
}
