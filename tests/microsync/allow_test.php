<?php
/**
 * File: allow_test.php
 * Description: Tests for the staged switch-on list MICROSYNC_ALLOW
 *              (microsync/lib/MsAccess.php, pre-release gap 5).
 *
 *              Part 1 calls MsAccess::allowedBy with the fixture users.
 *              Part 2 goes through Apache: it puts a MICROSYNC_ALLOW line
 *              into the dev includes/config.inc.php for the run (restored
 *              byte for byte at the end, also after a failure) and checks
 *              that a listed account syncs, an unlisted one gets 503
 *              sync_disabled, ping stays open, and removing the line lets
 *              everyone in again.
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config):
 *                docker exec strabo-php php /srv/app/www/tests/microsync/allow_test.php
 *
 *              Uses the fixture users owner / editor @test.strabospot.org.
 *              Read only: creates no projects. Exits non-zero on any failure.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/includes/jwt/quick-jwt.php';
require_once '/srv/app/www/microsync/lib/MsDb.php';
require_once '/srv/app/www/microsync/lib/MsAccess.php';

$BASE = 'http://localhost/microsync/v1';
$CONFIG = '/srv/app/www/includes/config.inc.php';

$failures = array();
function check($label, $cond, $detail = null) {
	global $failures;
	$detail = $detail !== null && strlen($detail) > 200 ? substr($detail, 0, 200) . '...' : $detail;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== null ? "  -> $detail" : '') . "\n";
	if (!$cond) {
		$failures[] = $label;
	}
}
function section($name) {
	echo "\n== $name\n";
}

function token($pkey, $email) {
	$qjt = new QuickJWT();
	return $qjt->sign(array(
		'iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE, 'iat' => time(), 'exp' => time() + 3600,
		'sub' => (string)$pkey, 'email' => $email, 'name' => $email,
	), JWT_SECRET);
}

function req($method, $path, $tok) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	if ($tok !== null) {
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $tok));
	}
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('code' => $code, 'body' => json_decode($raw, true), 'raw' => $raw);
}

/** 503 with the same error the app reads as "sync is off" */
function isDisabled($r) {
	return $r['code'] === 503 && is_array($r['body']) && $r['body']['error'] === 'sync_disabled';
}

$db->get_var('SELECT 1');
$msdb = new MsDb($db);
$U = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org') as $k => $email) {
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	$U[$k] = array('pkey' => $pkey, 'tok' => token($pkey, $email), 'email' => $email);
}
$owner = $U['owner'];
$editor = $U['editor'];

section('Part 1: MsAccess::allowedBy');
check('no list: everyone', MsAccess::allowedBy($msdb, $editor['pkey'], null) === true);
check('listed', MsAccess::allowedBy($msdb, $owner['pkey'], array($owner['email'])) === true);
check('listed in other case, with spaces', MsAccess::allowedBy($msdb, $owner['pkey'], array('  OWNER@Test.StraboSpot.org ')) === true);
check('not listed', MsAccess::allowedBy($msdb, $editor['pkey'], array($owner['email'])) === false);
check('empty list: nobody', MsAccess::allowedBy($msdb, $owner['pkey'], array()) === false);
check('not a list (config typo): nobody', MsAccess::allowedBy($msdb, $owner['pkey'], $owner['email']) === false);
check('unknown account: no', MsAccess::allowedBy($msdb, 999999999, array($owner['email'])) === false);
check('non-string entries ignored', MsAccess::allowedBy($msdb, $owner['pkey'], array(null, 5, $owner['email'])) === true);
check('allowed() without MICROSYNC_ALLOW in the dev config: everyone',
	defined('MICROSYNC_ALLOW') || MsAccess::allowed($msdb, $editor['pkey']) === true,
	'the dev config defines MICROSYNC_ALLOW; remove it before running this test');

section('Part 2: through Apache');
$original = file_get_contents($CONFIG);
if ($original === false || strpos($original, 'MICROSYNC_ALLOW') !== false) {
	check('dev config readable and without MICROSYNC_ALLOW', false);
} else {
	$restored = false;
	$restore = function () use ($CONFIG, $original, &$restored) {
		if (!$restored) {
			file_put_contents($CONFIG, $original);
			$restored = true;
		}
	};
	register_shutdown_function($restore);

	$anchor = "define('MICROSYNC_ENABLED', true);";
	check('dev config has the MICROSYNC_ENABLED line', strpos($original, $anchor) !== false);
	$line = "\ndefine('MICROSYNC_ALLOW', array('" . strtoupper($owner['email']) . "')); // allow_test.php, removed after the run\n";
	file_put_contents($CONFIG, str_replace($anchor, $anchor . $line, $original));
	sleep(4); // opcache revalidates every 2 s on dev

	$r = req('GET', '/projects', $owner['tok']);
	check('listed account: GET projects 200', $r['code'] === 200, $r['code'] . ' ' . $r['raw']);
	$r = req('GET', '/projects', $editor['tok']);
	check('unlisted account: 503 sync_disabled', isDisabled($r), $r['code'] . ' ' . $r['raw']);
	$r = req('POST', '/projects', $editor['tok']);
	check('unlisted account: create refused the same way', isDisabled($r), $r['code'] . ' ' . $r['raw']);
	$r = req('GET', '/invites', $editor['tok']);
	check('unlisted account: invites refused the same way', isDisabled($r), $r['code'] . ' ' . $r['raw']);
	$r = req('GET', '/ping', null);
	check('ping stays open', $r['code'] === 200 && $r['body']['ok'] === true, $r['code'] . ' ' . $r['raw']);
	$r = req('GET', '/projects', null);
	check('no token: still 401 (not 503)', $r['code'] === 401, $r['code'] . ' ' . $r['raw']);

	$restore();
	check('dev config restored byte for byte', file_get_contents($CONFIG) === $original);
	sleep(4);
	$r = req('GET', '/projects', $editor['tok']);
	check('line removed: the unlisted account syncs again', $r['code'] === 200, $r['code'] . ' ' . $r['raw']);
}

echo "\n" . (count($failures) ? count($failures) . ' FAILED' : 'ALL PASSED') . "\n";
exit(count($failures) ? 1 : 0);
