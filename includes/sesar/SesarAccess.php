<?php
/**
 * File: includes/sesar/SesarAccess.php
 * Description: Soft-launch gate and configuration for the StraboSamples IGSN
 *              (SESAR) integration (docs/StraboSamples_IGSN_Feature_Request/
 *              IGSN_Design_Decisions.md D10).
 *
 *              canUse() is the ONE place the pilot allowlist lives. Every
 *              surface checks it: the IGSN page, the My Samples button, the
 *              Sample Overview actions and every endpoint (server-side
 *              refusal, not just hidden buttons). Full launch = make
 *              canUse() return true for any logged-in user.
 *
 *              Configuration comes from includes/config.inc.php globals:
 *                $sesar_env                  'sandbox' | 'production'
 *                $sesar_orcid_client_id      ORCID Public API client (web only)
 *                $sesar_orcid_client_secret
 *                $sesar_token_key            base64 32-byte secretbox key
 *                $sesar_dev_paste_code       true on DEV only: ORCID refuses a
 *                                            localhost redirect, so dev pastes
 *                                            the ?code= from the prod callback URL
 *              A missing or unknown $sesar_env means 'sandbox', so a config
 *              slip can never register real IGSNs.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class SesarAccess
{
	/** Soft-launch pilot users (D10): Jason, Claire (7217). */
	const PILOT_USERPKEYS = array(3, 7217);

	/** ORCID redirect URI registered on the web ORCID client (exact match required by ORCID). */
	const ORCID_REDIRECT_URI = 'https://strabospot.org/sesar_orcid_callback.php';

	/** SESAR JWT connection claim for the web (the Field app uses 'strabospot'; D1). */
	const CONNECTION_NAME = 'strabospot-web';

	const API_BASES = array(
		'sandbox'    => 'https://api-sandbox.geosamples.org/api/',
		'production' => 'https://api.geosamples.org/api/',
	);

	const APP_BASES = array(
		'sandbox'    => 'https://app-sandbox.geosamples.org/',
		'production' => 'https://app.geosamples.org/',
	);

	/** Test hook: tests pin the environment without touching config.inc.php. */
	private static $envOverride = null;

	public static function canUse($userpkey)
	{
		return in_array((int)$userpkey, self::PILOT_USERPKEYS, true);
	}

	public static function environment()
	{
		if (self::$envOverride !== null) return self::$envOverride;
		$env = isset($GLOBALS['sesar_env']) ? $GLOBALS['sesar_env'] : 'sandbox';
		return $env === 'production' ? 'production' : 'sandbox';
	}

	public static function setEnvironmentForTests($env)
	{
		self::$envOverride = ($env === null) ? null : ($env === 'production' ? 'production' : 'sandbox');
	}

	public static function apiBase($env = null)
	{
		return self::API_BASES[$env === null ? self::environment() : $env];
	}

	public static function appBase($env = null)
	{
		return self::APP_BASES[$env === null ? self::environment() : $env];
	}

	/** Public SESAR landing page for an IGSN ("10.58052/IEJMA0001"). */
	public static function landingUrl($igsn, $env = null)
	{
		return self::appBase($env) . 'sample/igsn/' . $igsn;
	}

	public static function orcidClientId()
	{
		return isset($GLOBALS['sesar_orcid_client_id']) ? (string)$GLOBALS['sesar_orcid_client_id'] : '';
	}

	public static function orcidClientSecret()
	{
		return isset($GLOBALS['sesar_orcid_client_secret']) ? (string)$GLOBALS['sesar_orcid_client_secret'] : '';
	}

	/** Raw 32-byte key, or null when unset / malformed (callers refuse to store tokens). */
	public static function tokenKey()
	{
		if (!isset($GLOBALS['sesar_token_key'])) return null;
		$raw = base64_decode((string)$GLOBALS['sesar_token_key'], true);
		return ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) ? $raw : null;
	}

	/** Dev-only "paste the ORCID code" box (never set on prod). */
	public static function devCodePaste()
	{
		return isset($GLOBALS['sesar_dev_paste_code']) && $GLOBALS['sesar_dev_paste_code'] === true;
	}

	/** True when everything the connect flow needs is configured. */
	public static function isConfigured()
	{
		return self::orcidClientId() !== '' && self::orcidClientSecret() !== '' && self::tokenKey() !== null;
	}
}
