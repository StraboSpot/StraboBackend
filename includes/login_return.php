<?php
/**
 * File: includes/login_return.php
 * Description: Return-path sanitizer for login.php's ?uri= parameter. Pages
 *              behind logincheck.php store their own REQUEST_URI in the
 *              session before bouncing to login; a public page that wants
 *              the same round trip (StraboSearch's Export… for anonymous
 *              visitors, 2026-09-02) links to /login.php?uri=<path> instead.
 *              Only a same-site relative path is accepted, so the parameter
 *              can never become an open redirect.
 *
 * @package    StraboSpot Web Site
 */

/**
 * @param mixed $raw  the ?uri= value
 * @return string|null  the path to return to after login, or null to ignore
 */
function login_return_path($raw)
{
	if (!is_string($raw) || $raw === '' || strlen($raw) > 4096) return null;
	if ($raw[0] !== '/') return null;                       // relative to this site only
	if (isset($raw[1]) && ($raw[1] === '/' || $raw[1] === '\\')) return null;   // "//host" and "/\host" are scheme-relative
	if (preg_match('/[\x00-\x1f\x7f]/', $raw)) return null;   // no control chars (header injection)
	if (strpos($raw, '/login') === 0) return null;          // never loop back onto the login page
	return $raw;
}

/**
 * The StraboMicro join page to send this person to, once, because they signed
 * up from its link (strabomicro.micro_join_signups); null for none. A page
 * they were already heading to wins, and the saved code waits for a later
 * login, unless that page is a join page: then it is used up.
 */
function login_saved_join($db, $pkey, $uri)
{
	if ($uri !== '' && $uri !== '/' && strpos($uri, '/join/') !== 0) return null;
	$code = $db->get_var_prepared("SELECT code FROM strabomicro.micro_join_signups WHERE user_pkey = $1", array($pkey));
	if ($code !== null) {
		$db->prepare_query("DELETE FROM strabomicro.micro_join_signups WHERE user_pkey = $1", array($pkey));
	}
	if ($uri !== '' && $uri !== '/') return null;
	return is_string($code) && preg_match('/^[A-Z0-9]+$/', $code) ? '/join/' . $code : null;
}

