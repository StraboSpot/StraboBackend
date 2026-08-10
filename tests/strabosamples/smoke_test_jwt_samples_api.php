<?php
/**
 * File: smoke_test_jwt_samples_api.php
 * Description: HTTP smoke test for the JWT samples REST surface
 *              (/samplesjwtdb/). The business logic is shared with
 *              /samplesdb/ (same controllers + StraboSamplesService),
 *              so this suite deliberately focuses on what is UNIQUE to
 *              the JWT bootstrap: the jwtauth/middleware.php gate (all
 *              six 401 branches), the token-sub -> userpkey wiring, the
 *              jwt copy of Request.php (JSON body parsing), routing, and
 *              a representative CRUD + cross-user pass through the gate.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_jwt_samples_api.php
 *
 *              Requires the collaboration fixture users
 *              (owner@test.strabospot.org / outsider@test.strabospot.org,
 *              password testpass123) — run
 *              tests/collaboration/setup_test_data.php first if missing.
 *
 *              Hermetic: all sample rows use the jwtsmk- id prefix and are
 *              removed in the finally block. Exits non-zero on any failure.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/includes/jwt/quick-jwt.php';

$BASE      = 'http://localhost/samplesjwtdb';
$LOGIN_URL = 'http://localhost/jwtauth/login.php';
$PASSWORD  = 'testpass123';
$PREFIX    = 'jwtsmk-';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

/** Exchange email+password for a bearer token via the real login endpoint. */
function jwtLogin($email) {
    global $LOGIN_URL, $PASSWORD;
    $ch = curl_init($LOGIN_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('email' => $email, 'password' => $PASSWORD)));
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return isset($data['access_token']) ? $data['access_token'] : null;
}

/**
 * Request against /samplesjwtdb/. $auth: null = no header, or the full
 * Authorization header value ("Bearer x" / "Basic x").
 */
function req($method, $path, $auth, $body = null) {
    global $BASE;
    $ch = curl_init($BASE . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = array('Content-Type: application/json');
    if ($auth !== null) $headers[] = 'Authorization: ' . $auth;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => json_decode($raw, true), 'raw' => $raw);
}

function bearer($token) { return 'Bearer ' . $token; }

// ---------------------------------------------------------------------------
// Preflight: fixture users + tokens
// ---------------------------------------------------------------------------
$ownerRow = $db->get_row_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND deleted=FALSE", array('owner@test.strabospot.org'));
$outsiderRow = $db->get_row_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND deleted=FALSE", array('outsider@test.strabospot.org'));
if (!$ownerRow || !$ownerRow->pkey || !$outsiderRow || !$outsiderRow->pkey) {
    echo "ABORT: collaboration fixture users missing.\n";
    echo "Run: docker exec strabo-php php /srv/app/www/tests/collaboration/setup_test_data.php\n";
    exit(2);
}
$ownerPkey = (int)$ownerRow->pkey;
$outsiderPkey = (int)$outsiderRow->pkey;

$ownerToken = jwtLogin('owner@test.strabospot.org');
$outsiderToken = jwtLogin('outsider@test.strabospot.org');
check("preflight: owner JWT acquired via /jwtauth/login.php", $ownerToken !== null);
check("preflight: outsider JWT acquired", $outsiderToken !== null);
if ($ownerToken === null || $outsiderToken === null) {
    echo "ABORT: token acquisition failed — cannot continue.\n";
    exit(2);
}

$idA = $PREFIX . 'crud-1';
$cleanupIds = array($idA);

try {
    // -----------------------------------------------------------------------
    // 1. Auth gate — every distinct 401 branch in jwtauth/middleware.php
    // -----------------------------------------------------------------------
    echo "\n=== 1. JWT auth gate (middleware branches) ===\n";

    $r = req('GET', '/mysamples', null);
    check("no Authorization header -> 401 missing_auth",
        $r['code'] === 401 && isset($r['body']['error']) && $r['body']['error'] === 'missing_auth');

    $r = req('GET', '/mysamples', 'Basic ' . base64_encode('owner@test.strabospot.org:' . $PASSWORD));
    check("Basic auth on the JWT surface -> 401 invalid_auth (JWT-only, no Basic fallback)",
        $r['code'] === 401 && isset($r['body']['error']) && $r['body']['error'] === 'invalid_auth');

    $r = req('GET', '/mysamples', bearer('garbage.not.ajwt'));
    check("garbage bearer token -> 401 invalid_token_signature",
        $r['code'] === 401 && isset($r['body']['error']) && $r['body']['error'] === 'invalid_token_signature');

    $qjt = new QuickJWT();
    $now = time();

    $expired = QuickJWT::sign(array(
        'iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE,
        'iat' => $now - 7200, 'exp' => $now - 3600,
        'sub' => (string)$ownerPkey,
    ), JWT_SECRET);
    $r = req('GET', '/mysamples', bearer($expired));
    check("validly-signed but expired token -> 401 token_expired",
        $r['code'] === 401 && isset($r['body']['error']) && $r['body']['error'] === 'token_expired');

    $wrongAud = QuickJWT::sign(array(
        'iss' => JWT_ISSUER, 'aud' => 'some-other-audience',
        'iat' => $now, 'exp' => $now + 3600,
        'sub' => (string)$ownerPkey,
    ), JWT_SECRET);
    $r = req('GET', '/mysamples', bearer($wrongAud));
    check("wrong-audience token -> 401 incorrect_audience",
        $r['code'] === 401 && isset($r['body']['error']) && $r['body']['error'] === 'incorrect_audience');

    $ghostUser = QuickJWT::sign(array(
        'iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE,
        'iat' => $now, 'exp' => $now + 3600,
        'sub' => '999999999',
    ), JWT_SECRET);
    $r = req('GET', '/mysamples', bearer($ghostUser));
    check("valid token for nonexistent user -> 401 user_not_found",
        $r['code'] === 401 && isset($r['body']['error']) && $r['body']['error'] === 'user_not_found');

    // -----------------------------------------------------------------------
    // 2. Owner CRUD through the gate (sub -> userpkey wiring + Request.php)
    // -----------------------------------------------------------------------
    echo "\n=== 2. Owner CRUD via Bearer token ===\n";

    $r = req('POST', '/sample', bearer($ownerToken), array(
        'id' => $idA, 'name' => 'JWT Smoke A', 'igsn' => 'JWTSMK0001',
        'display_sample_type' => 'intact_rock', 'latitude' => 39.05, 'longitude' => -95.7,
    ));
    check("POST /sample (supplied id) -> 200 ok", $r['code'] === 200 && !empty($r['body']['ok']));
    check("created sample echoes id", isset($r['body']['sample']['id']) && $r['body']['sample']['id'] === $idA);
    check("token sub became userpkey (owner pkey on the row)",
        isset($r['body']['sample']['userpkey']) && (int)$r['body']['sample']['userpkey'] === $ownerPkey);

    // Auto-mint: no id supplied (closes the create-without-id HTTP-layer hole).
    $r = req('POST', '/sample', bearer($ownerToken), array('name' => 'JWT Smoke Minted'));
    $mintedId = isset($r['body']['sample']['id']) ? $r['body']['sample']['id'] : '';
    if ($mintedId !== '') $cleanupIds[] = $mintedId;
    check("POST /sample without id -> 200 with minted id", $r['code'] === 200 && $mintedId !== '');
    check("minted id is UUID-shaped",
        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $mintedId) === 1);

    $r = req('GET', "/sample/$idA", bearer($ownerToken));
    check("GET /sample/{id} -> 200 with matching id",
        $r['code'] === 200 && isset($r['body']['id']) && $r['body']['id'] === $idA);

    $r = req('GET', '/mysamples', bearer($ownerToken));
    $ids = array();
    if (isset($r['body']['samples']) && is_array($r['body']['samples'])) {
        foreach ($r['body']['samples'] as $s) $ids[] = $s['id'];
    }
    check("GET /mysamples -> 200 {samples, count} containing both new ids",
        $r['code'] === 200 && isset($r['body']['count'])
        && in_array($idA, $ids, true) && in_array($mintedId, $ids, true));

    $r = req('PUT', "/sample/$idA", bearer($ownerToken), array('notes' => 'updated via jwt'));
    check("PUT /sample/{id} -> 200 ok (JSON body parsed by jwt Request.php)",
        $r['code'] === 200 && !empty($r['body']['ok']));
    $dbNotes = $db->get_var_prepared(
        "SELECT notes FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($idA, $ownerPkey));
    check("PUT persisted to spine (DB notes match)", $dbNotes === 'updated via jwt');

    $r = req('PUT', "/sample/$idA/composition", bearer($ownerToken), array(
        'items' => array(
            array('mineral' => 'quartz', 'percent' => 40),
            array('mineral' => 'feldspar', 'percent' => 60),
        ),
    ));
    check("PUT /sample/{id}/composition -> 200 ok", $r['code'] === 200 && !empty($r['body']['ok']));
    $r = req('GET', "/sample/$idA/composition", bearer($ownerToken));
    check("GET /sample/{id}/composition -> 200 count=2",
        $r['code'] === 200 && isset($r['body']['count']) && (int)$r['body']['count'] === 2);

    $r = req('GET', "/sample/$idA/changelog", bearer($ownerToken));
    $types = array();
    if (isset($r['body']['changelog']) && is_array($r['body']['changelog'])) {
        foreach ($r['body']['changelog'] as $c) $types[] = $c['change_type'];
    }
    check("GET /sample/{id}/changelog -> 200 with create + update rows",
        $r['code'] === 200 && in_array('create', $types, true) && in_array('update', $types, true));

    $r = req('GET', '/invitations', bearer($ownerToken));
    check("GET /invitations -> 200 {invitations, count}",
        $r['code'] === 200 && isset($r['body']['invitations']) && isset($r['body']['count']));

    // -----------------------------------------------------------------------
    // 3. Cross-user isolation through the JWT gate
    // -----------------------------------------------------------------------
    echo "\n=== 3. Cross-user isolation (outsider token) ===\n";

    // The composite key means the ?owner= param must target the owner's
    // namespace; without it the lookup resolves against the caller's own
    // rows and everything is a plain 404 miss (also asserted below).
    $r = req('GET', "/sample/$idA?owner=$ownerPkey", bearer($outsiderToken));
    check("outsider GET owner's sample -> 404 (existence hidden)", $r['code'] === 404);

    $r = req('PUT', "/sample/$idA?owner=$ownerPkey", bearer($outsiderToken), array('name' => 'HIJACKED'));
    check("outsider PUT owner's sample -> 403 forbidden",
        $r['code'] === 403 && isset($r['body']['Error']) && $r['body']['Error'] === 'forbidden');

    $r = req('DELETE', "/sample/$idA?owner=$ownerPkey", bearer($outsiderToken));
    check("outsider DELETE owner's sample -> 403 forbidden", $r['code'] === 403);

    $r = req('PUT', "/sample/$idA", bearer($outsiderToken), array('name' => 'HIJACKED'));
    check("outsider PUT without ?owner resolves in own namespace -> 404 not_found", $r['code'] === 404);

    $dbName = $db->get_var_prepared(
        "SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($idA, $ownerPkey));
    check("denied mutations wrote nothing (DB name unchanged)", $dbName === 'JWT Smoke A');

    // -----------------------------------------------------------------------
    // 4. Router edges
    // -----------------------------------------------------------------------
    echo "\n=== 4. Router ===\n";

    $r = req('GET', '/nosuchthing', bearer($ownerToken));
    check("unknown controller -> 404 'No such function'",
        $r['code'] === 404 && isset($r['body']['Error']) && strpos($r['body']['Error'], 'No such function') === 0);

    // -----------------------------------------------------------------------
    // 5. Delete through the gate
    // -----------------------------------------------------------------------
    echo "\n=== 5. Owner delete ===\n";

    $r = req('DELETE', "/sample/$idA", bearer($ownerToken));
    check("owner DELETE -> 200 ok", $r['code'] === 200 && !empty($r['body']['ok']));
    $r = req('GET', "/sample/$idA", bearer($ownerToken));
    check("GET after delete -> 404", $r['code'] === 404);
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($idA, $ownerPkey));
    check("spine row gone from DB", $n === 0);

} finally {
    foreach ($cleanupIds as $cid) {
        $db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($cid, $ownerPkey));
    }
    $left = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id LIKE $1", array($PREFIX . '%'));
    echo "\nCleanup: $left fixture rows remaining (expect 0).\n";
}

echo "\n" . (count($failures) === 0
    ? "RESULT: ALL PASS\n"
    : "RESULT: " . count($failures) . " FAILURE(S)\n");
exit(count($failures) === 0 ? 0 : 1);
