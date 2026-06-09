<?php
/**
 * File: tests/strabosamples/perf_benchmarks.php
 * Description: Phase D of the pre-cutover test plan — performance sanity
 *              benchmarks D.1–D.4. Times the real HTTP surfaces a browser
 *              hits (Apache + PHP + PG in the dev docker stack) and
 *              PASS/FAILs each against the test plan's thresholds:
 *
 *                D.1  /my_samples.php power-user render          < 3.0 s
 *                D.2  Sample Overview (detail page) render       < 2.0 s
 *                       a) the real max-link sample on dev
 *                       b) a synthetic 25-link fixture (headroom —
 *                          real dev data tops out at ~4 links)
 *                D.3  family fetch for the deepest descendant of
 *                     a 5-deep chain with 10 children             < 0.5 s
 *                D.4  changelog "Load more" page on a 550-row
 *                     hot sample (limit=50 offset=50; also the
 *                     deepest page offset=500)                    < 1.0 s
 *
 *              Method: per benchmark, 1 discarded warm-up + 5 timed
 *              iterations via curl's %{time_total}; the MEDIAN is judged
 *              against the threshold (min/max reported). Thresholds are
 *              for the dev docker stack on a workstation — prod hardware
 *              is faster; treat these as conservative upper bounds.
 *
 *              D.1/D.2a borrow real dev rows read-only (forged sessions,
 *              GET-only). D.2b/D.3/D.4 build hermetic fixtures under the
 *              'perfmx-' id prefix, owned by the test users from
 *              tests/collaboration/setup_test_data.php; cleanup runs at
 *              start + in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/perf_benchmarks.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

// -------------------------------------------------------------------------
// Framework
// -------------------------------------------------------------------------

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

/**
 * Time an HTTP call: 1 warm-up + $iters timed runs. Returns
 * ['median'=>s, 'min'=>s, 'max'=>s, 'status'=>int, 'bytes'=>int].
 */
function timeHttp($method, $path, $jsonBody, $sid, $iters = 5) {
    $times = array();
    $status = 0; $bytes = 0;
    for ($i = 0; $i <= $iters; $i++) {
        $args = "-s -o /dev/null -w '%{http_code} %{time_total} %{size_download}' ";
        if ($sid !== null) $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
        $args .= "-X " . escapeshellarg($method) . " ";
        if ($jsonBody !== null) {
            $args .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
            $args .= "-d " . escapeshellarg($jsonBody) . " ";
        }
        $out = trim((string)shell_exec("curl $args " . escapeshellarg("http://localhost" . $path)));
        $parts = explode(' ', $out);
        if ($i === 0) continue;   // discard warm-up
        $status = (int)$parts[0];
        $times[] = (float)$parts[1];
        $bytes = (int)$parts[2];
    }
    sort($times);
    $n = count($times);
    return array(
        'median' => $times[(int)floor(($n - 1) / 2)],
        'min'    => $times[0],
        'max'    => $times[$n - 1],
        'status' => $status,
        'bytes'  => $bytes,
    );
}

function fmtBench($label, $r, $threshold) {
    return sprintf("%s — median %.3fs (min %.3f / max %.3f, %d bytes, HTTP %d) vs %.1fs threshold",
        $label, $r['median'], $r['min'], $r['max'], $r['bytes'], $r['status'], $threshold);
}

// -------------------------------------------------------------------------
// Sessions + fixtures
// -------------------------------------------------------------------------

$sessionFiles = array();
function forgeSession($pkey) {
    global $sessionFiles;
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = '/var/lib/php/sessions/sess_' . $sid;
    file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . $pkey . ';LAST_ACTIVITY|i:' . time() . ';');
    chmod($path, 0600);
    @chown($path, 'www-data'); @chgrp($path, 'www-data');
    $sessionFiles[] = $path;
    return $sid;
}

function perfmxCleanup($db) {
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id LIKE 'perfmx-%'", array());
    $db->prepare_query("DELETE FROM micro_projectmetadata WHERE strabo_id LIKE 'perfmx-%'", array());
    $db->prepare_query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'perfmx-%'", array());
    $db->prepare_query("DELETE FROM straboexp.project WHERE uuid LIKE 'perfmx-%'", array());
}

$ownerPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE",
    array('owner@test.strabospot.org')
);
if (!$ownerPkey) {
    echo "Test user owner@test.strabospot.org not found. Run tests/collaboration/setup_test_data.php first.\n";
    exit(1);
}

perfmxCleanup($db);

try {

// -------------------------------------------------------------------------
// D.1 — /my_samples.php power-user render
// -------------------------------------------------------------------------

echo "--- D.1: my_samples.php power user ---\n";

$pu = $db->get_row_prepared(
    "SELECT userpkey, COUNT(*)::int AS n FROM strabosamples.samples
      GROUP BY userpkey ORDER BY n DESC LIMIT 1", array());
$puPkey = (int)$pu->userpkey;
echo "  power user: pkey=$puPkey with {$pu->n} samples\n";

$sidPu = forgeSession($puPkey);
$r = timeHttp('GET', '/my_samples.php', null, $sidPu);
echo "  " . fmtBench("D.1 my_samples.php", $r, 3.0) . "\n";
check("D.1 my_samples render ({$pu->n} samples) under 3.0s", $r['status'] === 200 && $r['median'] < 3.0);

// -------------------------------------------------------------------------
// D.2a — Sample Overview: real max-link sample
// -------------------------------------------------------------------------

echo "\n--- D.2a: detail page, real max-link sample ---\n";

$ml = $db->get_row_prepared(
    "SELECT sample_id, sample_userpkey, COUNT(*)::int AS n
       FROM strabosamples.sample_subsystem_links
      GROUP BY 1,2 ORDER BY n DESC LIMIT 1", array());
echo "  sample: {$ml->sample_id} (owner {$ml->sample_userpkey}) with {$ml->n} links\n";

$sidMl = forgeSession((int)$ml->sample_userpkey);
$r = timeHttp('GET', "/samples_detail.php?owner={$ml->sample_userpkey}&id=" . rawurlencode($ml->sample_id), null, $sidMl);
echo "  " . fmtBench("D.2a detail page (real, {$ml->n} links)", $r, 2.0) . "\n";
check("D.2a real max-link sample under 2.0s", $r['status'] === 200 && $r['median'] < 2.0);

// -------------------------------------------------------------------------
// D.2b — Sample Overview: synthetic 25-link fixture
// -------------------------------------------------------------------------

echo "\n--- D.2b: detail page, synthetic 25-link fixture ---\n";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);
$idHeavy = 'perfmx-heavy';
$svc->createSample(array('id' => $idHeavy, 'name' => 'Perf Heavy Sample',
    'latitude' => 39.0, 'longitude' => -95.0, 'display_sample_type' => 'Intact Sample'));

// 10 micro host projects (5 public / 5 private) so the page's batched
// (strabo_id|userpkey) index + per-card gate does real work.
for ($i = 1; $i <= 10; $i++) {
    $pub = ($i % 2 === 0) ? 'TRUE' : 'FALSE';
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata (strabo_id, userpkey, name, ispublic)
         VALUES ($1, $2, $3, $pub)",
        array("perfmx-mp$i", $ownerPkey, "Perf Micro $i"));
    $mid = (int)$db->get_var_prepared(
        "SELECT id FROM micro_projectmetadata WHERE strabo_id=$1 AND userpkey=$2",
        array("perfmx-mp$i", $ownerPkey));
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
           (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
         VALUES ($1, $2, 'micro', $3, $2, $4)",
        array($idHeavy, $ownerPkey, (string)$mid, json_encode(array('project_strabo_id' => "perfmx-mp$i"))));
}
// 10 experimental links (5 public / 5 private projects).
for ($i = 1; $i <= 10; $i++) {
    $pub = ($i % 2 === 0) ? 'TRUE' : 'FALSE';
    $db->prepare_query(
        "INSERT INTO straboexp.project (userpkey, uuid, name, ispublic)
         VALUES ($1, $2, $3, $pub)",
        array($ownerPkey, "perfmx-ep$i", "Perf Exp $i"));
    $ppk = (int)$db->get_var_prepared(
        "SELECT pkey FROM straboexp.project WHERE uuid=$1", array("perfmx-ep$i"));
    $db->prepare_query(
        "INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid)
         VALUES ($1, $2, 'perfmx', $3)",
        array($ppk, $ownerPkey, "perfmx-ex$i"));
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
           (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
         VALUES ($1, $2, 'experimental', $3, $2, $4)",
        array($idHeavy, $ownerPkey, "perfmx-exref$i", json_encode(array('experiment_uuid' => "perfmx-ex$i"))));
}
// 5 field links.
for ($i = 1; $i <= 5; $i++) {
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
           (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
         VALUES ($1, $2, 'field', $3, $2, $4)",
        array($idHeavy, $ownerPkey, "perfmx-spot$i", json_encode(array('dataset_id' => "perfmx-ds$i"))));
}
$n = (int)$db->get_var_prepared(
    "SELECT COUNT(*) FROM strabosamples.sample_subsystem_links WHERE sample_id=$1", array($idHeavy));
check("D.2b fixture: 25 links seeded", $n === 25);

$sidOwner = forgeSession($ownerPkey);
$r = timeHttp('GET', "/samples_detail.php?owner=$ownerPkey&id=$idHeavy", null, $sidOwner);
echo "  " . fmtBench("D.2b detail page (synthetic, 25 links)", $r, 2.0) . "\n";
check("D.2b synthetic 25-link sample under 2.0s", $r['status'] === 200 && $r['median'] < 2.0);

// -------------------------------------------------------------------------
// D.3 — family fetch: deepest descendant of a 5-deep chain + 10 children
// -------------------------------------------------------------------------

echo "\n--- D.3: family explorer chain ---\n";

$prev = null;
for ($d = 0; $d <= 5; $d++) {
    $in = array('id' => "perfmx-chain$d", 'name' => "Perf Chain depth $d");
    if ($prev !== null) {
        $in['parent_sample_id'] = $prev;
        $in['parent_userpkey']  = $ownerPkey;
    }
    $svc->createSample($in);
    $prev = "perfmx-chain$d";
}
for ($c = 1; $c <= 10; $c++) {
    $svc->createSample(array('id' => "perfmx-leaf$c", 'name' => "Perf Leaf $c",
        'parent_sample_id' => 'perfmx-chain5', 'parent_userpkey' => $ownerPkey));
}

$r = timeHttp('POST', '/samples_family.php',
    json_encode(array('sample_id' => 'perfmx-chain5', 'owner_pkey' => $ownerPkey)),
    $sidOwner);
echo "  " . fmtBench("D.3 family fetch (deepest, 10 children)", $r, 0.5) . "\n";
check("D.3 family fetch under 0.5s", $r['status'] === 200 && $r['median'] < 0.5);

// -------------------------------------------------------------------------
// D.4 — changelog pagination on a hot sample (550 rows)
// -------------------------------------------------------------------------

echo "\n--- D.4: hot-sample changelog pagination ---\n";

$idHot = 'perfmx-hot';
$svc->createSample(array('id' => $idHot, 'name' => 'Perf Hot Sample'));
$db->prepare_query(
    "INSERT INTO strabosamples.sample_changelog
       (sample_id, sample_userpkey, changed_by, changed_at, change_type, source_subsystem, changes)
     SELECT $1, $2, $2, now() - (g || ' seconds')::interval, 'update', 'samples',
            jsonb_build_object('notes', jsonb_build_object('old', 'rev ' || g, 'new', 'rev ' || (g+1)))
       FROM generate_series(1, 550) AS g",
    array($idHot, $ownerPkey));
$n = (int)$db->get_var_prepared(
    "SELECT COUNT(*) FROM strabosamples.sample_changelog WHERE sample_id=$1 AND sample_userpkey=$2",
    array($idHot, $ownerPkey));
check("D.4 fixture: 550+ changelog rows seeded", $n >= 550);

$r = timeHttp('POST', '/samples_changelog.php',
    json_encode(array('action' => 'list', 'sample_id' => $idHot, 'owner_pkey' => $ownerPkey,
                      'limit' => 50, 'offset' => 50)),
    $sidOwner);
echo "  " . fmtBench("D.4 changelog Load-more (offset 50)", $r, 1.0) . "\n";
check("D.4 changelog first Load-more under 1.0s", $r['status'] === 200 && $r['median'] < 1.0);

$r = timeHttp('POST', '/samples_changelog.php',
    json_encode(array('action' => 'list', 'sample_id' => $idHot, 'owner_pkey' => $ownerPkey,
                      'limit' => 50, 'offset' => 500)),
    $sidOwner);
echo "  " . fmtBench("D.4 changelog deepest page (offset 500)", $r, 1.0) . "\n";
check("D.4 changelog deepest page under 1.0s", $r['status'] === 200 && $r['median'] < 1.0);

} finally {
    perfmxCleanup($db);
    foreach ($sessionFiles as $f) @unlink($f);
}

// -------------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------------

echo "\n=================================================\n";
if (empty($failures)) {
    echo "PERF BENCHMARKS: ALL CHECKS PASSED\n";
    exit(0);
}
echo "PERF BENCHMARKS: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
