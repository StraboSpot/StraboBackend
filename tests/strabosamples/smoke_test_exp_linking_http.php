<?php
/**
 * File: smoke_test_exp_linking_http.php
 * Description: HTTP suite for the Exp picker endpoints
 *              (Exp_StraboSamples_Linking.md §5.4 / §8 item 9):
 *                /experimental/api/get_my_samples.php
 *                /experimental/api/get_sample.php
 *              Auth gates (401 logged-out, 405 wrong verb), picker flags,
 *              existence-hiding 404s (foreign sample invisible with and
 *              without ?owner=), and the accepted-collaborator read path.
 *
 *              Hermetic: direct spine fixtures torn down in finally.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_exp_linking_http.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

function httpGet($path, $sid = null) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'explink_body_');
    $cookie = $sid !== null ? "-H " . escapeshellarg('Cookie: PHPSESSID=' . $sid) . " " : "";
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' $cookie"
         . escapeshellarg("http://localhost$path");
    $status = (int)shell_exec($cmd);
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body));
}

function httpPost($path, $sid) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'explink_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' "
         . "-H " . escapeshellarg('Cookie: PHPSESSID=' . $sid) . " -X POST -d '{}' "
         . escapeshellarg("http://localhost$path");
    $status = (int)shell_exec($cmd);
    @unlink($bodyFile);
    return $status;
}

// Two non-deleted users: owner (session user) + stranger.
$rows = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 2", array());
$ownerPkey    = (int)$rows[0]->pkey;
$strangerPkey = (int)$rows[1]->pkey;
$stamp = time();

// Session for the owner (same forged-session pattern as the other proxies).
$sessionDir = '/var/lib/php/sessions';
$sid = substr(bin2hex(random_bytes(16)), 0, 26);
$sessFile = $sessionDir . '/sess_' . $sid;
file_put_contents($sessFile, 'loggedin|s:3:"yes";userpkey|i:' . $ownerPkey . ';LAST_ACTIVITY|i:' . time() . ';');
chmod($sessFile, 0600);
@chown($sessFile, 'www-data');
@chgrp($sessFile, 'www-data');

// Spine fixtures: one owned, one foreign, one foreign-but-collaborated.
$ownId     = sprintf('%08x-aaaa-4aaa-8aaa-%012x', $stamp, $stamp);
$foreignId = sprintf('%08x-bbbb-4bbb-8bbb-%012x', $stamp, $stamp);
$collabId  = sprintf('%08x-cccc-4ccc-8ccc-%012x', $stamp, $stamp);

$db->prepare_query(
    "INSERT INTO strabosamples.samples (id, userpkey, name, description, created_by, modified_by)
     VALUES ($1, $2, $3, 'own fixture', $2, $2)",
    array($ownId, $ownerPkey, "HTTP Own Rock $stamp"));
$db->prepare_query(
    "INSERT INTO strabosamples.samples (id, userpkey, name, description, created_by, modified_by)
     VALUES ($1, $2, $3, 'foreign fixture', $2, $2)",
    array($foreignId, $strangerPkey, "HTTP Foreign Rock $stamp"));
$db->prepare_query(
    "INSERT INTO strabosamples.samples (id, userpkey, name, description, created_by, modified_by)
     VALUES ($1, $2, $3, 'collab fixture', $2, $2)",
    array($collabId, $strangerPkey, "HTTP Collab Rock $stamp"));
$db->prepare_query(
    "INSERT INTO strabosamples.sample_collaborators
        (sample_id, sample_userpkey, collaborator_pkey, permission_level, uuid, accepted, accepted_at, added_by)
     VALUES ($1, $2, $3, 'readonly', $4, TRUE, now(), $2)",
    array($collabId, $strangerPkey, $ownerPkey, "collabuuid-$stamp"));

echo "owner=$ownerPkey stranger=$strangerPkey\n\n";

try {
    echo "== auth gates ==\n";
    $r = httpGet('/experimental/api/get_my_samples.php');
    check('get_my_samples logged-out -> 401',            $r['status'] === 401);
    $r = httpGet('/experimental/api/get_sample.php?id=' . $ownId);
    check('get_sample logged-out -> 401',                $r['status'] === 401);
    check('get_my_samples POST -> 405',                  httpPost('/experimental/api/get_my_samples.php', $sid) === 405);

    echo "\n== picker list ==\n";
    $r = httpGet('/experimental/api/get_my_samples.php', $sid);
    check('get_my_samples -> 200',                       $r['status'] === 200);
    $list = is_object($r['json']) && isset($r['json']->samples) ? $r['json']->samples : null;
    check('response has samples array + count',          is_array($list) && isset($r['json']->count));
    $mine = null; $collab = null; $foreign = null;
    if (is_array($list)) {
        foreach ($list as $s) {
            if ($s->id === $ownId)     $mine = $s;
            if ($s->id === $collabId)  $collab = $s;
            if ($s->id === $foreignId) $foreign = $s;
        }
    }
    check('own sample listed',                           $mine !== null);
    check('collaborated sample listed (greyable)',       $collab !== null && (int)$collab->userpkey === $strangerPkey);
    check('non-collaborated foreign sample NOT listed',  $foreign === null);
    check('picker flags present (has_field_data)',       $mine !== null && property_exists($mine, 'has_field_data') && $mine->has_field_data === false);
    check('picker flags present (has_micro_data)',       $mine !== null && property_exists($mine, 'has_micro_data') && $mine->has_micro_data === false);
    check('picker flags present (experimental_link_count)', $mine !== null && isset($mine->experimental_link_count) && (int)$mine->experimental_link_count === 0);

    echo "\n== full record ==\n";
    $r = httpGet('/experimental/api/get_sample.php?id=' . $ownId, $sid);
    check('get_sample own -> 200',                       $r['status'] === 200);
    $s = is_object($r['json']) && isset($r['json']->sample) ? $r['json']->sample : null;
    check('record carries spine name',                   $s !== null && $s->name === "HTTP Own Rock $stamp");
    check('record carries subsystem_links',              $s !== null && property_exists($s, 'subsystem_links'));
    check('record carries lock-rule slices',             $s !== null && property_exists($s, 'field_data') && property_exists($s, 'micro_data'));
    check('record carries parent (null ok)',             $s !== null && property_exists($s, 'parent'));

    echo "\n== existence hiding ==\n";
    $r = httpGet('/experimental/api/get_sample.php?id=' . $foreignId, $sid);
    check('foreign id without owner -> 404',             $r['status'] === 404);
    $r = httpGet('/experimental/api/get_sample.php?id=' . $foreignId . '&owner=' . $strangerPkey, $sid);
    check('foreign id with owner -> still 404',          $r['status'] === 404);
    $r = httpGet('/experimental/api/get_sample.php?id=00000000-dead-4bad-8bad-000000000000', $sid);
    check('nonexistent id -> 404',                       $r['status'] === 404);
    $r = httpGet('/experimental/api/get_sample.php', $sid);
    check('missing id -> 400',                           $r['status'] === 400);

    echo "\n== accepted collaborator read ==\n";
    $r = httpGet('/experimental/api/get_sample.php?id=' . $collabId . '&owner=' . $strangerPkey, $sid);
    check('collaborated sample with owner -> 200',       $r['status'] === 200);
    $s = is_object($r['json']) && isset($r['json']->sample) ? $r['json']->sample : null;
    check('collaborated record name matches',            $s !== null && $s->name === "HTTP Collab Rock $stamp");

} finally {
    @unlink($sessFile);
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($ownId, $ownerPkey));
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($foreignId, $strangerPkey));
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($collabId, $strangerPkey));
}

echo "\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
