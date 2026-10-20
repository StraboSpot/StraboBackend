<?php
/**
 * File: RegisterEmail.php
 * Description: Helpers that keep a mistyped address from becoming a dead
 *              account at registration (register.php):
 *              - suggest(): "Did you mean ...@gmail.com?" for misspelled
 *                common mail providers and .com/.edu endings.
 *              - fixToken() / readFixToken(): the "Wrong address? Fix it"
 *                form on the "link has been emailed" page. The token names
 *                the pending account and is signed over its current
 *                validation hash, so it stops working once the address is
 *                changed (new hash) or the account is activated.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class RegisterEmail {

	// How many times one registration may change its address.
	const MAX_FIXES = 5;

	// How long after signing up the address may still be changed.
	const FIX_WINDOW_HOURS = 24;

	// Providers people misspell. Domain name before the first dot => usual full domain.
	private static $providers = array(
		'gmail'      => 'gmail.com',
		'googlemail' => 'googlemail.com',
		'yahoo'      => 'yahoo.com',
		'hotmail'    => 'hotmail.com',
		'outlook'    => 'outlook.com',
		'icloud'     => 'icloud.com',
		'protonmail' => 'protonmail.com',
		'comcast'    => 'comcast.net',
	);

	// Real providers one typo away from the names above; never "corrected".
	private static $realNames = array('ymail', 'email', 'mail');

	// Mistyped endings => what was meant.
	private static $tldTypos = array(
		'con' => 'com', 'cmo' => 'com', 'cm' => 'com', 'om' => 'com', 'comm' => 'com',
		'vom' => 'com', 'xom' => 'com', 'ocm' => 'com', 'coom' => 'com', 'c0m' => 'com',
		'edi' => 'edu', 'eud' => 'edu', 'deu' => 'edu', 'rdu' => 'edu', 'wdu' => 'edu', 'eduu' => 'edu',
	);

	/**
	 * The address the user probably meant, or null when nothing looks wrong.
	 */
	public static function suggest($email) {
		$email = strtolower(trim($email));
		$at = strrpos($email, '@');
		if ($at === false) return null;
		$local = substr($email, 0, $at);
		$domain = substr($email, $at + 1);
		$labels = explode('.', $domain);
		if ($local === '' || count($labels) < 2) return null;

		$fixed = $labels;
		$last = count($fixed) - 1;
		if (isset(self::$tldTypos[$fixed[$last]])) $fixed[$last] = self::$tldTypos[$fixed[$last]];

		$name = $fixed[0];
		if (!isset(self::$providers[$name]) && !in_array($name, self::$realNames, true)) {
			foreach (self::$providers as $provider => $full) {
				if (self::oneTypo($name, $provider)) {
					// gnail.com -> gmail.com; gnail.co.uk -> gmail.co.uk
					$fixed[0] = $provider;
					if (count($labels) == 2 && in_array($fixed[1], array('co', 'cm', 'om'), true)) $fixed = explode('.', $full);
					break;
				}
			}
		}
		// gmail.co is a real (squatted) domain, but nobody's mailbox.
		if (isset(self::$providers[$fixed[0]]) && count($fixed) == 2 && $fixed[1] === 'co') $fixed = explode('.', self::$providers[$fixed[0]]);

		$suggested = implode('.', $fixed);
		return $suggested === $domain ? null : $local . '@' . $suggested;
	}

	// One letter wrong, missing or extra, or two neighbors swapped.
	private static function oneTypo($a, $b) {
		if ($a === $b) return false;
		if (levenshtein($a, $b) == 1) return true;
		if (strlen($a) != strlen($b)) return false;
		for ($i = 0; $i < strlen($a) - 1; $i++) {
			if ($a[$i] !== $b[$i]) {
				return $a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i] && substr($a, $i + 2) === substr($b, $i + 2);
			}
		}
		return false;
	}

	/**
	 * Token for the "Wrong address? Fix it" form: pkey.fixes.signature, signed
	 * over the account's current validation hash.
	 */
	public static function fixToken($pkey, $hash, $fixes, $secret) {
		$pkey = (int)$pkey; $fixes = (int)$fixes;
		return $pkey . '.' . $fixes . '.' . hash_hmac('sha256', "register-fix|$pkey|$fixes|$hash", $secret);
	}

	/**
	 * The pending account a fix token names, or null when the token is bad,
	 * spent (address already changed), the account is active or deleted, the
	 * window has passed, or the change limit is reached.
	 * Returns array(row, fixes).
	 */
	public static function readFixToken($token, $db, $secret) {
		if (!preg_match('/^(\d+)\.(\d+)\.([0-9a-f]{64})$/', (string)$token, $m)) return null;
		$pkey = (int)$m[1]; $fixes = (int)$m[2];
		if ($fixes >= self::MAX_FIXES) return null;
		$row = $db->get_row_prepared("SELECT pkey, firstname, lastname, email, hash FROM users WHERE pkey = $1 AND active = false AND deleted IS NOT TRUE AND signup_date > now() - $2::interval", array($pkey, self::FIX_WINDOW_HOURS . ' hours'));
		if (!$row || $row->pkey == '') return null;
		if (!hash_equals(self::fixToken($pkey, $row->hash, $fixes, $secret), $token)) return null;
		return array($row, $fixes);
	}

	/**
	 * A new 21-character validation hash (same alphabet as before).
	 */
	public static function newHash() {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$out = '';
		for ($x = 0; $x < 21; $x++) $out .= $chars[random_int(0, 61)];
		return $out;
	}
}
