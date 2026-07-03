<?php
/**
 * File: e2e_tabular_io.php
 * Description: Curl-level END-TO-END coverage of the StraboSamples tabular
 *              import/export web surface — the real session-gated endpoints
 *              over HTTP, complementing smoke_test_tabular_io.php (which
 *              exercises the service layer with 0 HTTP).
 *
 *                A. Auth gates: anonymous GET /samples_import.php and
 *                   /samples_tabular_download.php → 302 /login.php
 *                B. Template download: XLSX magic + CSV header shape
 *                C. Export: seeded samples + custom column round out via CSV
 *                D. Full import flow over HTTP: multipart upload → review
 *                   screen (counts, token, vocab + custom clarifications) →
 *                   confirm with resolutions → success page → DB assertions
 *                   (spine row, vocab mapped, custom stored, changelog)
 *                E. All-or-nothing over HTTP: file with one bad row commits
 *                   nothing even when confirm is forced
 *                F. Update-by-internal-id over HTTP: rename lands + changelog
 *                G. Cancel discards the import state (token dies)
 *                H. Stranger isolation: export never carries another user's rows
 *
 *              Hermetic: sentinel ids prefixed e2etab-<stamp>; cleans DB rows
 *              + forged session files + temp uploads. Uses the
 *              setup_test_data.php users. Exits non-zero on any failure.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_tabular_io.php
 *
 * @package    StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

// -------------------------------------------------------------------------
// Users (from tests/collaboration/setup_test_data.php)
// -------------------------------------------------------------------------
$users = array();
foreach (array('owner', 'outsider') as $who) {
    $email = "$who@test.strabospot.org";
    $pkey  = (int)$db->get_var_prepared(
        "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
    if (!$pkey) { echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n"; exit(1); }
    $users[$who] = $pkey;
}
$ownerPkey    = $users['owner'];
$strangerPkey = $users['outsider'];
echo "owner=$ownerPkey stranger=$strangerPkey\n";

// -------------------------------------------------------------------------
// Forged sessions + HTTP helpers
// -------------------------------------------------------------------------
$sessionDir   = '/var/lib/php/sessions';
$sessionFiles = array();
$tmpFiles     = array();

function forgeSession($pkey) {
    global $sessionDir, $sessionFiles;
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = $sessionDir . '/sess_' . $sid;
    file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    $sessionFiles[] = $path;
    return $sid;
}

function curlRun($args) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2etab_body_');
    $hdrFile  = tempnam(sys_get_temp_dir(), 'e2etab_hdr_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -D " . escapeshellarg($hdrFile)
         . " -w '%{http_code}' $args";
    $status = (int)shell_exec($cmd);
    $body   = file_get_contents($bodyFile);
    $location = '';
    foreach (explode("\n", (string)file_get_contents($hdrFile)) as $line) {
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    @unlink($bodyFile); @unlink($hdrFile);
    return array('status' => $status, 'body' => $body, 'location' => $location);
}

function httpGet($path, $sid) {
    $args = ($sid !== null) ? ("-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ") : '';
    return curlRun($args . escapeshellarg("http://localhost$path"));
}

/** Multipart POST (file upload). $fields = name=>value, $file = local path. */
function httpPostFile($path, $sid, $fields, $fileField, $file, $uploadName) {
    $args = "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    foreach ($fields as $k => $v) {
        $args .= "-F " . escapeshellarg("$k=$v") . " ";
    }
    $args .= "-F " . escapeshellarg("$fileField=@$file;filename=$uploadName") . " ";
    return curlRun($args . escapeshellarg("http://localhost$path"));
}

/** URL-encoded POST. $data nests like PHP expects (http_build_query). */
function httpPostForm($path, $sid, $data) {
    $body = http_build_query($data);
    $args = "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " "
          . "-H 'Content-Type: application/x-www-form-urlencoded' "
          . "--data-binary " . escapeshellarg($body) . " ";
    return curlRun($args . escapeshellarg("http://localhost$path"));
}

function extractToken($html) {
    if (preg_match('/name="token" value="([0-9a-f]{32})"/', $html, $m)) { return $m[1]; }
    return null;
}

// -------------------------------------------------------------------------
// Fixtures
// -------------------------------------------------------------------------
$stamp = time();
$pfx   = "e2etab-$stamp";
$ids   = array();

$sidOwner    = forgeSession($ownerPkey);
$sidStranger = forgeSession($strangerPkey);

try {

    // ----------------------------------------------------------------
    echo "\n=== A. anonymous auth gates ===\n";
    // ----------------------------------------------------------------
    $r = httpGet('/samples_import.php', null);
    check("anon import page → 302",       $r['status'] === 302);
    check("anon import redirects to login", strpos($r['location'], '/login.php') !== false);
    $r = httpGet('/samples_tabular_download.php?what=template', null);
    check("anon download → 302",          $r['status'] === 302);

    // ----------------------------------------------------------------
    echo "\n=== B. template download ===\n";
    // ----------------------------------------------------------------
    $r = httpGet('/samples_tabular_download.php?what=template', $sidOwner);
    check("template xlsx 200",            $r['status'] === 200);
    check("template is a real xlsx (PK magic)", substr($r['body'], 0, 2) === 'PK');
    check("template is non-trivial",      strlen($r['body']) > 5000);

    $r = httpGet('/samples_tabular_download.php?what=template&format=csv', $sidOwner);
    check("template csv 200",             $r['status'] === 200);
    $firstLine = strtok(preg_replace('/^\xEF\xBB\xBF/', '', $r['body']), "\n");
    check("csv header starts with strabo_internal_id,sample_id",
          strpos($firstLine, 'strabo_internal_id,sample_id') === 0);

    // ----------------------------------------------------------------
    echo "\n=== C. export carries seeded data + custom columns ===\n";
    // ----------------------------------------------------------------
    $sSeed = "$pfx-seeded";
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, description, display_sample_type, custom_data, created_by, modified_by)
         VALUES ($1,$2,$3,$4,$5,$6,$2,$2)",
        array($sSeed, $ownerPkey, 'E2E-SEED-01', 'seeded for export', 'intact_rock',
              json_encode(array('field_season' => '2024'))));
    $ids[] = array($sSeed, $ownerPkey);

    $r = httpGet('/samples_tabular_download.php?what=export&format=csv', $sidOwner);
    check("export csv 200",               $r['status'] === 200);
    check("export contains seeded internal id", strpos($r['body'], $sSeed) !== false);
    check("export contains seeded name",        strpos($r['body'], 'E2E-SEED-01') !== false);
    check("export vocab shows display label",   strpos($r['body'], 'Intact Rock') !== false);
    $headerLine = strtok(preg_replace('/^\xEF\xBB\xBF/', '', $r['body']), "\n");
    check("export header includes custom column field_season", strpos($headerLine, 'field_season') !== false);

    $r = httpGet('/samples_tabular_download.php?what=export', $sidOwner);
    check("export xlsx 200 + PK magic",   $r['status'] === 200 && substr($r['body'], 0, 2) === 'PK');

    // ----------------------------------------------------------------
    echo "\n=== D. full import flow over HTTP ===\n";
    // ----------------------------------------------------------------
    $csv = "sample_id,material_type,sampling_purpose,latitude,longitude,collector\n"
         . "$pfx-HTTP-A,Weird Vocab Value,Petrology,34.5,-105.25,J. Ash\n";
    $up = tempnam(sys_get_temp_dir(), 'e2etab_up_') . '.csv';
    $tmpFiles[] = $up;
    file_put_contents($up, $csv);

    $r = httpPostFile('/samples_import.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $up, 'legacy_import.csv');
    check("upload → 200 review screen",   $r['status'] === 200);
    check("review shows 1 new",           preg_match('/<strong>1<\/strong> new/', $r['body']) === 1);
    check("review surfaces unknown vocab value", strpos($r['body'], 'Weird Vocab Value') !== false);
    check("review surfaces custom column",       strpos($r['body'], 'collector') !== false);
    $token = extractToken($r['body']);
    check("review carries a state token", $token !== null);

    // Confirm with resolutions: map the vocab value to sediment; import collector.
    $r = httpPostForm('/samples_import.php', $sidOwner, array(
        'action' => 'confirm',
        'token'  => $token,
        'res_vocab' => array('material_type' => array(base64_encode('Weird Vocab Value') => 'sediment')),
        'res_custom' => array(base64_encode('collector') => 'import'),
    ));
    check("confirm → 200 success page",   $r['status'] === 200);
    check("success page reports 1 created", strpos($r['body'], '1 sample created') !== false);

    $row = $db->get_row_prepared(
        "SELECT id, display_sample_type, display_sample_purpose, latitude,
                custom_data::text AS custom_data
           FROM strabosamples.samples WHERE userpkey=$1 AND name=$2",
        array($ownerPkey, "$pfx-HTTP-A"));
    check("sample landed in spine",       $row !== null);
    if ($row) {
        $ids[] = array($row->id, $ownerPkey);
        check("vocab resolution applied (sediment)", $row->display_sample_type === 'sediment');
        check("purpose normalized (petrology)",      $row->display_sample_purpose === 'petrology');
        check("coords landed",                        abs((float)$row->latitude - 34.5) < 1e-9);
        $cd = json_decode($row->custom_data, true);
        check("custom field landed",                  is_array($cd) && $cd['collector'] === 'J. Ash');
        $clog = $db->get_var_prepared(
            "SELECT change_type FROM strabosamples.sample_changelog
              WHERE sample_id=$1 AND sample_userpkey=$2 ORDER BY pkey DESC LIMIT 1",
            array($row->id, $ownerPkey));
        check("changelog 'create' written",           $clog === 'create');
    }

    // ----------------------------------------------------------------
    echo "\n=== E. all-or-nothing over HTTP ===\n";
    // ----------------------------------------------------------------
    $csv = "sample_id,latitude,longitude\n"
         . "$pfx-GOOD,34.0,-100.0\n"
         . "$pfx-BAD,999,-100.0\n";
    file_put_contents($up, $csv);
    $r = httpPostFile('/samples_import.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $up, 'mixed.csv');
    check("mixed upload → review",        $r['status'] === 200);
    check("review lists the bad row",     strpos($r['body'], 'between -90 and 90') !== false);
    check("review blocks confirm on hard errors", strpos($r['body'], 'Confirm import') === false);
    $token = extractToken($r['body']);

    // Force the confirm anyway (hostile client) — server must re-plan and refuse.
    $r = httpPostForm('/samples_import.php', $sidOwner, array(
        'action' => 'confirm', 'token' => $token));
    check("forced confirm re-renders review, not success", strpos($r['body'], 'Import complete') === false);
    $n = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples WHERE userpkey=$1 AND name LIKE $2",
        array($ownerPkey, "$pfx-GOOD%"));
    check("good row did NOT commit (all-or-nothing)", $n === 0);

    // ----------------------------------------------------------------
    echo "\n=== F. update by internal id over HTTP ===\n";
    // ----------------------------------------------------------------
    $csv = "strabo_internal_id,sample_id,description\n"
         . "$sSeed,E2E-SEED-01-renamed,updated via http\n";
    file_put_contents($up, $csv);
    $r = httpPostFile('/samples_import.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $up, 'update.csv');
    check("update upload → review",       $r['status'] === 200);
    check("review shows 1 updated",       preg_match('/<strong>1<\/strong> updated/', $r['body']) === 1);
    $token = extractToken($r['body']);
    $r = httpPostForm('/samples_import.php', $sidOwner, array('action' => 'confirm', 'token' => $token));
    check("update confirm → success",     strpos($r['body'], 'Import complete') !== false);
    $row = $db->get_row_prepared(
        "SELECT name, description FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sSeed, $ownerPkey));
    check("rename landed",                $row && $row->name === 'E2E-SEED-01-renamed');
    check("description landed",           $row && $row->description === 'updated via http');

    // ----------------------------------------------------------------
    echo "\n=== F2. custom-only change previews as an update on review ===\n";
    // ----------------------------------------------------------------
    // Regression (Jason 2026-07-03): with a new custom column still
    // undecided, the review chips said "0 updated" but confirming
    // committed 1 update. The counts must preview the default (import).
    $csv = "strabo_internal_id,sample_id,FooFoo Stuff\n"
         . "$sSeed,E2E-SEED-01-renamed,bogus info\n";
    file_put_contents($up, $csv);
    $r = httpPostFile('/samples_import.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $up, 'customonly.csv');
    check("custom-only upload → review",  $r['status'] === 200);
    check("review chip previews 1 updated", preg_match('/<strong>1<\/strong> updated/', $r['body']) === 1);
    check("custom column decision still shown", strpos($r['body'], 'FooFoo Stuff') !== false);
    $token = extractToken($r['body']);
    // Ignore-resolution must drop it back to a no-op commit.
    $r = httpPostForm('/samples_import.php', $sidOwner, array(
        'action' => 'confirm', 'token' => $token,
        'res_custom' => array(base64_encode('FooFoo Stuff') => 'ignore'),
    ));
    check("ignore → success with 0 updated",
          strpos($r['body'], 'Import complete') !== false
          && preg_match('/0 samples created,\s*0 updated/', $r['body']) === 1);
    $cd = $db->get_var_prepared(
        "SELECT custom_data::text FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sSeed, $ownerPkey));
    check("ignored custom column not stored", strpos((string)$cd, 'FooFoo') === false);

    // ----------------------------------------------------------------
    echo "\n=== G. cancel discards state ===\n";
    // ----------------------------------------------------------------
    $csv = "sample_id\n$pfx-CANCELME\n";
    file_put_contents($up, $csv);
    $r = httpPostFile('/samples_import.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $up, 'cancel.csv');
    $token = extractToken($r['body']);
    check("cancel fixture has token",     $token !== null);
    $r = httpPostForm('/samples_import.php', $sidOwner, array('action' => 'cancel', 'token' => $token));
    check("cancel → 302 back to My Samples", $r['status'] === 302 && strpos($r['location'], 'my_samples') !== false);
    $r = httpPostForm('/samples_import.php', $sidOwner, array('action' => 'confirm', 'token' => $token));
    check("stale token → expired message", strpos($r['body'], 'expired') !== false);
    $n = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples WHERE userpkey=$1 AND name=$2",
        array($ownerPkey, "$pfx-CANCELME"));
    check("cancelled import wrote nothing", $n === 0);

    // ----------------------------------------------------------------
    echo "\n=== H. stranger isolation ===\n";
    // ----------------------------------------------------------------
    $r = httpGet('/samples_tabular_download.php?what=export&format=csv', $sidStranger);
    check("stranger export 200",          $r['status'] === 200);
    check("stranger export excludes owner's samples", strpos($r['body'], $sSeed) === false);

    // Stranger cannot update the owner's sample by id (reads as unknown id).
    $csv = "strabo_internal_id,sample_id\n$sSeed,HIJACKED\n";
    file_put_contents($up, $csv);
    $r = httpPostFile('/samples_import.php', $sidStranger, array('action' => 'upload'),
                      'tabfile', $up, 'hijack.csv');
    check("stranger update attempt is a hard error", strpos($r['body'], 'does not match any of your samples') !== false);
    $name = $db->get_var_prepared(
        "SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sSeed, $ownerPkey));
    check("owner's sample untouched",     $name === 'E2E-SEED-01-renamed');

} finally {
    foreach ($ids as $pair) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                           array($pair[0], $pair[1]));
    }
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE name LIKE $1", array("$pfx-%"));
    foreach ($sessionFiles as $f) { @unlink($f); }
    foreach ($tmpFiles as $f) { @unlink($f); }
}

echo "\n==========================================\n";
if (empty($failures)) { echo "ALL CHECKS PASSED\n"; exit(0); }
echo count($failures) . " FAILURE(S):\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
