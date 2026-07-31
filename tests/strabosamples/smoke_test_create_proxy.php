<?php
/**
 * File: smoke_test_create_proxy.php
 * Description: HTTP-layer smoke test for the session-authed
 *              /samples_create.php proxy. The service-level createSample
 *              behavior is already covered by smoke_test_api_core_writes;
 *              this file specifically exercises the proxy's auth check,
 *              JSON-body parsing, and HTTP-status mapping of service
 *              error codes.
 *
 *              Drives the endpoint via curl with a manually-crafted PHP
 *              session file. Hermetic: writes one session file under
 *              /var/lib/php/sessions, cleans it up at the end along with
 *              the test sample rows.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/strabosamples/smoke_test_create_proxy.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

// Need two distinct users: owner + stranger. Stranger owns the parent
// we'll test parent_not_accessible against.
$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 2", array()
);
if (!is_array($users) || count($users) < 2) {
    echo "Cannot find 2 distinct test users.\n";
    exit(1);
}
$ownerPkey    = (int)$users[0]->pkey;
$strangerPkey = (int)$users[1]->pkey;
echo "owner=$ownerPkey  stranger=$strangerPkey\n\n";

$stamp = time();
$createdIds = array();  // [(id, userpkey), ...] for cleanup

// ---- Forge a session for ownerPkey ----
$sessionDir = '/var/lib/php/sessions';

// Apache runs as www-data and reads sessions mode 600 — files written
// here by root CLI need to be chowned so Apache can read them. SIDs
// must match PHP's session.sid_bits_per_character=5 alphabet (0-9a-v) —
// hex is a valid subset, plain bin2hex satisfies it; 26 chars matches
// session.sid_length.
function writeSession($dir, $pkey) {
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = $dir . '/sess_' . $sid;
    $body = 'loggedin|s:3:"yes";userpkey|i:' . $pkey . ';LAST_ACTIVITY|i:' . time() . ';';
    file_put_contents($path, $body);
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    return array($sid, $path);
}

list($sid, $sessionFile)                   = writeSession($sessionDir, $ownerPkey);
list($strangerSid, $strangerSessionFile)   = writeSession($sessionDir, $strangerPkey);

function hit($sid, $jsonBody) {
    $headers = array('Content-Type: application/json');
    if ($sid !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $sid;
    }
    $hdr = '';
    foreach ($headers as $h) { $hdr .= '-H ' . escapeshellarg($h) . ' '; }
    $payload = $jsonBody === null ? '' : ('-d ' . escapeshellarg($jsonBody));
    $bodyFile = tempnam(sys_get_temp_dir(), 'proxy_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' " . $hdr . " -X POST $payload http://localhost/samples_create.php";
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    $json = json_decode($body, true);
    return array('status' => $status, 'body' => $body, 'json' => $json);
}

try {

    echo "=== Unauthenticated request ===\n";
    $r = hit(null, '{"name":"x"}');
    check('status = 401',                              $r['status'] === 401);
    check('json.ok = false',                            isset($r['json']['ok']) && $r['json']['ok'] === false);
    check('json.error = not_authenticated',             isset($r['json']['error']) && $r['json']['error'] === 'not_authenticated');

    echo "\n=== Invalid JSON body ===\n";
    $r = hit($sid, 'this is not json');
    check('status = 400',                              $r['status'] === 400);
    check('json.error = invalid_json',                  isset($r['json']['error']) && $r['json']['error'] === 'invalid_json');

    echo "\n=== Empty body (treated as invalid JSON) ===\n";
    $r = hit($sid, '');
    check('status = 400',                              $r['status'] === 400);
    check('json.error = invalid_json',                  isset($r['json']['error']) && $r['json']['error'] === 'invalid_json');

    echo "\n=== Successful create (auto-minted id) ===\n";
    $suppliedId = 'smoke-proxy-' . $stamp . '-a';
    $payload = json_encode(array(
        'id'                     => $suppliedId,
        'name'                   => 'proxy smoke A',
        'igsn'                   => 'IGSN-SMOKE-A',
        'description'            => 'created via samples_create.php',
        'display_sample_type'    => 'rock',
        'display_sample_purpose' => 'reference',
        'latitude'               => 34.5,
        'longitude'              => -118.25,
    ));
    $r = hit($sid, $payload);
    $createdIds[] = array($suppliedId, $ownerPkey);
    check('status = 201',                              $r['status'] === 201);
    check('json.ok = true',                             isset($r['json']['ok']) && $r['json']['ok'] === true);
    check('json.sample.id matches',                     isset($r['json']['sample']['id']) && $r['json']['sample']['id'] === $suppliedId);
    check('json.sample.userpkey = owner',               isset($r['json']['sample']['userpkey']) && (int)$r['json']['sample']['userpkey'] === $ownerPkey);
    check('json.sample.name preserved',                 isset($r['json']['sample']['name']) && $r['json']['sample']['name'] === 'proxy smoke A');
    check('json.sample.igsn preserved',                 isset($r['json']['sample']['igsn']) && $r['json']['sample']['igsn'] === 'IGSN-SMOKE-A');
    check('json.sample.display_sample_type preserved',  isset($r['json']['sample']['display_sample_type']) && $r['json']['sample']['display_sample_type'] === 'rock');
    check('json.sample.latitude preserved',             isset($r['json']['sample']['latitude']) && (float)$r['json']['sample']['latitude'] === 34.5);

    echo "\n=== Duplicate id ===\n";
    $r = hit($sid, json_encode(array('id' => $suppliedId, 'name' => 'dup')));
    check('status = 409',                              $r['status'] === 409);
    check('json.error = duplicate_id',                  isset($r['json']['error']) && $r['json']['error'] === 'duplicate_id');

    echo "\n=== Parent half-pair (id without userpkey) ===\n";
    $r = hit($sid, json_encode(array('name' => 'half pair', 'parent_sample_id' => 'whatever')));
    check('status = 400',                              $r['status'] === 400);
    check('json.error = parent_pair_required',          isset($r['json']['error']) && $r['json']['error'] === 'parent_pair_required');

    echo "\n=== Parent not accessible (stranger's sample) ===\n";
    // Stranger creates a sample so it exists. Then owner tries to set it as parent.
    $strangerId = 'smoke-proxy-' . $stamp . '-s';
    $rS = hit($strangerSid, json_encode(array('id' => $strangerId, 'name' => 'stranger sample')));
    $createdIds[] = array($strangerId, $strangerPkey);
    check('stranger create OK',                        $rS['status'] === 201);
    $r = hit($sid, json_encode(array(
        'name'             => 'child of stranger',
        'parent_sample_id' => $strangerId,
        'parent_userpkey'  => $strangerPkey,
    )));
    check('status = 403',                              $r['status'] === 403);
    check('json.error = parent_not_accessible',         isset($r['json']['error']) && $r['json']['error'] === 'parent_not_accessible');

    echo "\n=== Successful create with own parent ===\n";
    $childId = 'smoke-proxy-' . $stamp . '-c';
    $r = hit($sid, json_encode(array(
        'id'               => $childId,
        'name'             => 'child of A',
        'parent_sample_id' => $suppliedId,
        'parent_userpkey'  => $ownerPkey,
    )));
    $createdIds[] = array($childId, $ownerPkey);
    check('status = 201',                              $r['status'] === 201);
    check('parent_sample_id set',                       isset($r['json']['sample']['parent_sample_id']) && $r['json']['sample']['parent_sample_id'] === $suppliedId);
    check('parent_userpkey set',                        isset($r['json']['sample']['parent_userpkey']) && (int)$r['json']['sample']['parent_userpkey'] === $ownerPkey);

    echo "\n=== Client-supplied userpkey is ignored ===\n";
    $r = hit($sid, json_encode(array(
        'id'       => 'smoke-proxy-' . $stamp . '-u',
        'name'     => 'try to spoof',
        'userpkey' => $strangerPkey,  // attempt to create under stranger
    )));
    $createdIds[] = array('smoke-proxy-' . $stamp . '-u', $ownerPkey);
    check('status = 201',                              $r['status'] === 201);
    check('sample.userpkey = session owner (not body)', isset($r['json']['sample']['userpkey']) && (int)$r['json']['sample']['userpkey'] === $ownerPkey);

} finally {
    // Cleanup test samples
    foreach ($createdIds as $pair) {
        list($id, $uk) = $pair;
        $db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($id, $uk)
        );
    }
    @unlink($sessionFile);
    @unlink($strangerSessionFile);
}

echo "\n";
if (count($failures) === 0) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " FAIL(S)\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
