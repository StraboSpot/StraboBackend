<?php
/**
 * File: includes/sesar/SesarConnection.php
 * Description: A user's SESAR connection for the current environment (D1):
 *              stores the SESAR JWT pair encrypted (SesarCrypto) in
 *              strabosamples.sesar_connections and hands out a valid access
 *              token, refreshing when needed.
 *
 *              Refresh is the delicate part. SESAR ROTATES refresh tokens:
 *              using one blacklists it and returns a new pair. Two requests
 *              refreshing at once (two tabs, a batch loop) would each present
 *              the same refresh token, the loser gets a 401, and the user is
 *              logged out. So refresh runs inside a transaction holding the
 *              connection row FOR UPDATE, and re-reads the row after taking
 *              the lock: whoever waited finds a fresh access token and uses it.
 *
 *              Tokens never leave the server; summary() is what pages see.
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

class SesarConnection
{
	/** Refresh when the cached access token has less than this left (seconds). */
	const ACCESS_MARGIN = 120;

	private $db;
	private $client;
	private $env;
	private $key;

	public function __construct($db, SesarClient $client, $key = null)
	{
		$this->db = $db;
		$this->client = $client;
		$this->env = $client->environment();
		$this->key = ($key === null) ? SesarAccess::tokenKey() : $key;
	}

	/**
	 * Exchange a verified ORCID id_token for a SESAR pair and store it.
	 * Throws SesarError (kind no_permission = the SESAR account lacks API
	 * upload permission; no_account = the ORCID has no SESAR account;
	 * SesarOnboarding turns both into guidance).
	 */
	public function connectWithOrcid($userpkey, $orcidIdToken, $orcidId)
	{
		if ($this->key === null) throw new SesarError(500, 'SESAR token storage is not configured.');
		$this->assertOrcidFree($userpkey, $orcidId);
		$pair = $this->client->tokenFromOrcid($orcidIdToken);
		$user = null;
		$codes = null;
		try { $user = $this->client->currentUser($pair['access']); } catch (SesarError $e) { /* identity is cosmetic */ }
		try { $codes = $this->client->codesForCreate($pair['access']); } catch (SesarError $e) { /* refreshed later */ }

		$params = array(
			(int)$userpkey, $this->env, SesarAccess::CONNECTION_NAME, (string)$orcidId,
			$user === null ? null : json_encode($user),
			SesarCrypto::seal($pair['refresh'], $this->key), self::ts(SesarClient::jwtExpiry($pair['refresh'])),
			SesarCrypto::seal($pair['access'], $this->key), self::ts(SesarClient::jwtExpiry($pair['access'])),
			$codes === null ? null : json_encode(array_values($codes)),
		);
		$ok = $this->db->prepare_query(
			"INSERT INTO strabosamples.sesar_connections
			        (userpkey, environment, connection_name, orcid, sesar_user,
			         refresh_token_enc, refresh_expires_at, access_token_enc, access_expires_at,
			         sesar_codes, codes_fetched_at, status, last_error, connected_at, updated_at)
			 VALUES ($1, $2, $3, $4, $5::jsonb, $6, $7, $8, $9, $10::jsonb,
			         CASE WHEN $10::jsonb IS NULL THEN NULL ELSE now() END, 'connected', NULL, now(), now())
			 ON CONFLICT (userpkey, environment) DO UPDATE SET
			         connection_name = EXCLUDED.connection_name, orcid = EXCLUDED.orcid,
			         sesar_user = COALESCE(EXCLUDED.sesar_user, strabosamples.sesar_connections.sesar_user),
			         refresh_token_enc = EXCLUDED.refresh_token_enc, refresh_expires_at = EXCLUDED.refresh_expires_at,
			         access_token_enc = EXCLUDED.access_token_enc, access_expires_at = EXCLUDED.access_expires_at,
			         sesar_codes = COALESCE(EXCLUDED.sesar_codes, strabosamples.sesar_connections.sesar_codes),
			         codes_fetched_at = COALESCE(EXCLUDED.codes_fetched_at, strabosamples.sesar_connections.codes_fetched_at),
			         status = 'connected', last_error = NULL, connected_at = now(), updated_at = now()",
			$params
		);
		if ($ok === false) throw new SesarError(500, 'Could not save the SESAR connection.');
		return $this->summary($userpkey);
	}

	/**
	 * A usable access token, refreshing under a row lock when needed.
	 * @throws SesarError kind 'auth' when the user must reconnect.
	 */
	public function accessToken($userpkey)
	{
		$row = $this->row($userpkey, false);
		$tok = $this->usableAccess($row);
		if ($tok !== null) return $tok;
		if ($row === null || $row->status !== 'connected' || $row->refresh_token_enc === null) {
			throw new SesarError(401, 'Connect your SESAR account first.');
		}

		$this->db->query("BEGIN");
		try {
			$row = $this->row($userpkey, true);   // FOR UPDATE: serializes refreshers
			$tok = $this->usableAccess($row);
			if ($tok !== null) { $this->db->query("COMMIT"); return $tok; }   // someone refreshed while we waited

			$refresh = ($row !== null) ? SesarCrypto::open($row->refresh_token_enc, $this->key) : null;
			if ($refresh === null) {
				$this->markNeedsReconnect($userpkey, 'Stored SESAR token could not be read.');
				$this->db->query("COMMIT");
				throw new SesarError(401, 'Please reconnect your SESAR account.');
			}
			try {
				$pair = $this->client->refresh($refresh);
			} catch (SesarError $e) {
				if ($e->kind === 'auth' || $e->kind === 'no_permission' || $e->kind === 'no_account') {
					$this->markNeedsReconnect($userpkey, $e->getMessage());
					$this->db->query("COMMIT");
					throw new SesarError(401, 'Your SESAR connection has expired. Please reconnect.');
				}
				$this->db->query("ROLLBACK");
				throw $e;   // network / server: keep the stored pair, try again later
			}
			$this->db->prepare_query(
				"UPDATE strabosamples.sesar_connections
				    SET refresh_token_enc = $1, refresh_expires_at = $2,
				        access_token_enc = $3, access_expires_at = $4,
				        status = 'connected', last_error = NULL, updated_at = now()
				  WHERE userpkey = $5 AND environment = $6",
				array(SesarCrypto::seal($pair['refresh'], $this->key), self::ts(SesarClient::jwtExpiry($pair['refresh'])),
				      SesarCrypto::seal($pair['access'], $this->key), self::ts(SesarClient::jwtExpiry($pair['access'])),
				      (int)$userpkey, $this->env)
			);
			$this->db->query("COMMIT");
			return $pair['access'];
		} catch (SesarError $e) {
			throw $e;
		} catch (Exception $e) {
			$this->db->query("ROLLBACK");
			throw $e;
		}
	}

	/**
	 * Run $fn($accessToken). If SESAR rejects a token we still thought valid
	 * (revoked at SESAR, clock skew), drop the cached access token and retry
	 * ONCE through a refresh; a failed refresh marks the connection
	 * needs_reconnect (accessToken()). Every SESAR call made on a user's
	 * behalf goes through here. A 403 (kind 'forbidden') is SESAR refusing
	 * the action, not the token: passed on as is, no refresh, no second call.
	 */
	public function withAccess($userpkey, callable $fn)
	{
		try {
			return $fn($this->accessToken($userpkey));
		} catch (SesarError $e) {
			if ($e->kind !== 'auth') throw $e;
			$this->db->prepare_query(
				"UPDATE strabosamples.sesar_connections SET access_token_enc = NULL, access_expires_at = NULL, updated_at = now()
				  WHERE userpkey = $1 AND environment = $2",
				array((int)$userpkey, $this->env)
			);
			return $fn($this->accessToken($userpkey));
		}
	}

	/** Re-read the SESAR codes the user may register under (D2 dropdown). */
	public function refreshCodes($userpkey)
	{
		$client = $this->client;
		$codes = $this->withAccess($userpkey, function ($access) use ($client) { return $client->codesForCreate($access); });
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_connections SET sesar_codes = $1::jsonb, codes_fetched_at = now(), updated_at = now()
			  WHERE userpkey = $2 AND environment = $3",
			array(json_encode(array_values($codes)), (int)$userpkey, $this->env)
		);
		return $codes;
	}

	/** D2: remember the SESAR code last used. */
	public function setLastCode($userpkey, $code)
	{
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_connections SET last_sesar_code = $1, updated_at = now()
			  WHERE userpkey = $2 AND environment = $3",
			array((string)$code, (int)$userpkey, $this->env)
		);
	}

	/** Forget the tokens locally. (SESAR-side revocation is the user's Developer Settings.) */
	public function disconnect($userpkey)
	{
		// Revoke the refresh token at SESAR too (10-26), not just our copy. Best effort: an
		// unreachable SESAR never blocks a disconnect; the token then simply expires there.
		$row = $this->db->get_row_prepared("SELECT refresh_token_enc FROM strabosamples.sesar_connections WHERE userpkey = $1 AND environment = $2",
			array((int)$userpkey, $this->env));
		$refresh = ($row !== null && $this->key !== null) ? SesarCrypto::open($row->refresh_token_enc, $this->key) : null;
		if ($refresh !== null) {
			try { $this->client->blacklist($refresh); } catch (Throwable $e) { /* best effort */ }
		}
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_connections
			    SET refresh_token_enc = NULL, refresh_expires_at = NULL, access_token_enc = NULL, access_expires_at = NULL,
			        status = 'disconnected', updated_at = now()
			  WHERE userpkey = $1 AND environment = $2",
			array((int)$userpkey, $this->env)
		);
	}

	/**
	 * One ORCID iD, one StraboSpot account (per environment). ORCID approves at once when the
	 * browser is still signed in to ORCID as someone else, so without this a shared browser tied
	 * user B's StraboSpot account to user A's SESAR account (10-26, found on prod: A had even
	 * disconnected first, which does not sign the browser out of ORCID). So ANY row of another
	 * account with this ORCID iD holds it, disconnected or not; Disconnect does not release it,
	 * an admin does (delete that account's sesar_connections + sesar_onboarding rows).
	 * @throws SesarError 409 when another account holds this ORCID iD
	 */
	public function assertOrcidFree($userpkey, $orcidId)
	{
		$held = $this->db->get_var_prepared(
			"SELECT 1 FROM strabosamples.sesar_connections
			  WHERE environment = $1 AND orcid = $2 AND userpkey <> $3
			 UNION ALL
			 SELECT 1 FROM strabosamples.sesar_onboarding
			  WHERE environment = $1 AND orcid = $2 AND userpkey <> $3
			 LIMIT 1",
			array($this->env, (string)$orcidId, (int)$userpkey)
		);
		if ($held) {
			throw new SesarError(409, 'The ORCID iD you signed in with (' . $orcidId . ') is already connected to SESAR from another '
				. 'StraboSpot account. Each ORCID iD can be connected to one StraboSpot account. If this is not your ORCID iD, '
				. 'sign out at orcid.org (or use a private browser window) and connect again with your own. If it is yours, '
				. 'please contact StraboSpot to move it to this account.',
				array('orcid' => array('in_use')));
		}
	}

	/** What pages may show. Never includes tokens. */
	public function summary($userpkey)
	{
		$r = $this->row($userpkey, false);
		if ($r === null) {
			// Same keys as a stored connection: callers read them without checking (a missing
			// sesar_codes reached the mint dialog as null and stopped it on "Checking...").
			return array('environment' => $this->env, 'connected' => false, 'status' => 'none', 'orcid' => null,
				'sesar_user' => null, 'sesar_codes' => array(), 'last_sesar_code' => null, 'refresh_expires_at' => null,
				'last_error' => null, 'connected_at' => null);
		}
		$user = json_decode((string)$r->sesar_user, true);
		$codes = json_decode((string)$r->sesar_codes, true);
		return array(
			'environment'        => $this->env,
			'connected'          => ($r->status === 'connected' && $r->refresh_token_enc !== null),
			'status'             => $r->status,
			'orcid'              => $r->orcid,
			'sesar_user'         => is_array($user) ? $user : null,
			'sesar_codes'        => is_array($codes) ? self::codeList($codes) : array(),
			'last_sesar_code'    => $r->last_sesar_code,
			'refresh_expires_at' => $r->refresh_expires_at,
			'last_error'         => $r->last_error,
			'connected_at'       => $r->connected_at,
		);
	}

	/** SESAR code objects -> plain code strings (the list shape varies: {code}, {sesar_code}, or strings). */
	public static function codeList(array $codes)
	{
		$out = array();
		foreach ($codes as $c) {
			if (is_string($c)) $out[] = $c;
			elseif (is_array($c) && isset($c['code'])) $out[] = (string)$c['code'];
			elseif (is_array($c) && isset($c['sesar_code'])) $out[] = (string)$c['sesar_code'];
		}
		return array_values(array_unique($out));
	}

	// -----------------------------------------------------------------------

	private function row($userpkey, $forUpdate)
	{
		return $this->db->get_row_prepared(
			"SELECT * FROM strabosamples.sesar_connections WHERE userpkey = $1 AND environment = $2"
			. ($forUpdate ? " FOR UPDATE" : ""),
			array((int)$userpkey, $this->env)
		);
	}

	private function usableAccess($row)
	{
		if ($row === null || $row->status !== 'connected' || $row->access_token_enc === null || $row->access_expires_at === null) return null;
		if (strtotime($row->access_expires_at) - time() < self::ACCESS_MARGIN) return null;
		return SesarCrypto::open($row->access_token_enc, $this->key);
	}

	private function markNeedsReconnect($userpkey, $why)
	{
		$this->db->prepare_query(
			"UPDATE strabosamples.sesar_connections
			    SET status = 'needs_reconnect', refresh_token_enc = NULL, access_token_enc = NULL,
			        last_error = $1, updated_at = now()
			  WHERE userpkey = $2 AND environment = $3",
			array(mb_substr((string)$why, 0, 500), (int)$userpkey, $this->env)
		);
	}

	private static function ts($unix)
	{
		return $unix === null ? null : gmdate('c', (int)$unix);
	}
}
