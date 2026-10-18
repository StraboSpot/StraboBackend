<?php
/**
 * File: tests/jwtauth/refresh_sliding_test.php
 * Description: The refresh token slides: each /jwtauth/refresh moves its
 *              expires_at to now + REFRESH_TOKEN_EXPIRY, so a session ends
 *              only after that long without a refresh (before, it ended that
 *              long after the login, and apps in daily use were logged out
 *              every 7 days). Drives the real endpoints over HTTP; expiry is
 *              tested by back-dating expires_at instead of waiting.
 *
 *              Covers:
 *                - login stores expires_at ~ now + REFRESH_TOKEN_EXPIRY
 *                - a refresh close to expiry moves it forward to the same
 *                - an expired refresh token is refused (401) and deleted
 *                - a revoked refresh token is refused (401) and NOT extended
 *
 *              Run inside the app container:
 *                docker exec strabo-php php /srv/app/www/tests/jwtauth/refresh_sliding_test.php
 *
 *              Hermetic: makes its own user (jwtrefresh_%@test.strabospot.org)
 *              and removes it and its tokens on exit. Safe to re-run.
 *
 * @package    StraboSpot Tests
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');

$baseUrl = 'http://localhost';
$email = 'jwtrefresh_user@test.strabospot.org';
$password = 'testpass123';
// Allowed difference between the expected and the stored expiry (clock steps, slow requests)
$slackSec = 120;

$passed = 0;
$failed = 0;

function test($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name" . ($details !== '' ? " ($details)" : '') . "\n";
    }
}

function post($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($response, true)];
}

/** Seconds from now until the token's expires_at, or null when the row is gone */
function secondsLeft($token) {
    global $db;
    $at = $db->get_var_prepared("SELECT expires_at FROM refresh_tokens WHERE token = $1", [$token]);
    return $at ? strtotime($at) - time() : null;
}

function backdate($token, $secondsLeft) {
    global $db;
    $db->prepare_query("UPDATE refresh_tokens SET expires_at = $2 WHERE token = $1",
        [$token, date('Y-m-d H:i:s', time() + $secondsLeft)]);
}

function cleanup() {
    global $db, $email;
    $db->prepare_query("DELETE FROM refresh_tokens WHERE userpkey IN (SELECT pkey FROM users WHERE email = $1)", [$email]);
    $db->prepare_query("DELETE FROM users WHERE email = $1", [$email]);
}

cleanup();
$db->prepare_query(
    "INSERT INTO users (email, password, firstname, lastname, hash, active, deleted)
     VALUES ($1, crypt($2, gen_salt('md5')), $3, $4, $5, TRUE, FALSE)",
    [$email, $password, 'JWT', 'Refresh', md5(uniqid('', true))]
);

echo "Refresh token sliding expiry (REFRESH_TOKEN_EXPIRY = " . REFRESH_TOKEN_EXPIRY . " s)\n";

$login = post("$baseUrl/jwtauth/login.php", ['email' => $email, 'password' => $password]);
$token = $login['body']['refresh_token'] ?? null;
test('login returns a refresh token', $login['code'] === 200 && $token !== null, "HTTP {$login['code']}");

if ($token !== null) {
    $left = secondsLeft($token);
    test('login: expires REFRESH_TOKEN_EXPIRY from now', $left !== null && abs($left - REFRESH_TOKEN_EXPIRY) <= $slackSec, "left=$left");

    // An hour before expiry (a session that would end soon under the old rule)
    backdate($token, 3600);
    $r = post("$baseUrl/jwtauth/refresh.php", ['refresh_token' => $token]);
    test('refresh near expiry succeeds', $r['code'] === 200 && !empty($r['body']['access_token']), "HTTP {$r['code']}");
    $left = secondsLeft($token);
    test('refresh: expiry moved to REFRESH_TOKEN_EXPIRY from now', $left !== null && abs($left - REFRESH_TOKEN_EXPIRY) <= $slackSec, "left=$left");

    // Expired: refused and removed (unchanged behavior)
    backdate($token, -60);
    $r = post("$baseUrl/jwtauth/refresh.php", ['refresh_token' => $token]);
    test('expired token refused with 401', $r['code'] === 401, "HTTP {$r['code']}");
    test('expired token deleted', secondsLeft($token) === null);
}

// Revoked (logout): refused, and the refusal does not extend it
$login = post("$baseUrl/jwtauth/login.php", ['email' => $email, 'password' => $password]);
$token = $login['body']['refresh_token'] ?? null;
test('second login returns a refresh token', $token !== null, "HTTP {$login['code']}");
if ($token !== null) {
    backdate($token, 3600);
    $db->prepare_query("UPDATE refresh_tokens SET revoked = TRUE WHERE token = $1", [$token]);
    $r = post("$baseUrl/jwtauth/refresh.php", ['refresh_token' => $token]);
    test('revoked token refused with 401', $r['code'] === 401, "HTTP {$r['code']}");
    $left = secondsLeft($token);
    test('revoked token not extended', $left !== null && $left <= 3600 + $slackSec, "left=$left");
}

cleanup();

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
