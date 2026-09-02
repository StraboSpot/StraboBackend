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
