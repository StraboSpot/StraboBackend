<?php
/**
 * File: MsAccess.php
 * Description: Who may sync, for the staged switch-on (pre-release gap 5).
 *              With MICROSYNC_ENABLED true, config.inc.php may also define
 *              MICROSYNC_ALLOW as a list of account emails:
 *                define('MICROSYNC_ALLOW', array('a@example.org', 'b@example.org'));
 *              Then only those accounts may use /microsync/v1/; everyone
 *              else gets the same 503 sync_disabled as when sync is off, so
 *              the app shows "Sync unavailable". Without MICROSYNC_ALLOW
 *              every account may sync (release day = remove the line).
 *              Matched on the account's current email (not the one in the
 *              token), ignoring case. The strabo-live service applies the
 *              same list (livesvc/config.js).
 *
 *              Old apps upload through jwtmicrodb/ and microdb/, which never
 *              reach this check.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsAccess {

	/** May this account sync on this server (MICROSYNC_ALLOW)? */
	public static function allowed($db, $userpkey) {
		return self::allowedBy($db, $userpkey, defined('MICROSYNC_ALLOW') ? MICROSYNC_ALLOW : null);
	}

	/**
	 * $list: null = everyone; an array of emails = only those accounts;
	 * anything else (a typo in the config) = nobody.
	 */
	public static function allowedBy($db, $userpkey, $list) {
		if ($list === null) {
			return true;
		}
		if (!is_array($list)) {
			return false;
		}
		$email = $db->val(
			"SELECT lower(trim(email)) FROM public.users WHERE pkey = $1 AND deleted = false",
			array((int)$userpkey));
		if ($email === null || $email === '') {
			return false;
		}
		foreach ($list as $e) {
			if (is_string($e) && strtolower(trim($e)) === $email) {
				return true;
			}
		}
		return false;
	}
}
