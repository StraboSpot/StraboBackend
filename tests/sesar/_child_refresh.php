<?php
/**
 * File: tests/sesar/_child_refresh.php
 * Description: Helper process for smoke_test_sesar_foundation.php's
 *              concurrent-refresh check: opens its OWN database connection
 *              and asks SesarConnection for an access token against the
 *              shared FakeSesar state file. Prints one JSON line.
 *
 *              The children must really overlap: each opens its database
 *              connection FIRST (dev DNS can stall a connect for ~1 s), then
 *              sleeps until the shared <startAt> instant, then asks for a
 *              token. A child that is still not ready at startAt reports
 *              late=true and the suite treats the run as inconclusive.
 *
 *              Usage (the suite spawns it): php _child_refresh.php <stateFile> <userpkey> <keyB64> <startAt>
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarConnection.php';
require_once __DIR__ . '/FakeSesar.php';

list(, $stateFile, $userpkey, $keyB64, $startAt) = $argv;
$db->get_var("SELECT 1");   // connect now, not inside the race
$late = microtime(true) > (float)$startAt;
if (!$late) time_sleep_until((float)$startAt);
$t0 = microtime(true);
$conn = new SesarConnection($db, new SesarClient('sandbox', new FakeSesar($stateFile)), base64_decode($keyB64));
try {
	$tok = $conn->accessToken((int)$userpkey);
	echo json_encode(array('ok' => true, 'token' => hash('sha256', $tok), 'late' => $late, 'start' => $t0, 'end' => microtime(true))), "\n";
} catch (SesarError $e) {
	echo json_encode(array('ok' => false, 'kind' => $e->kind, 'msg' => $e->getMessage(), 'late' => $late, 'start' => $t0, 'end' => microtime(true))), "\n";
}
