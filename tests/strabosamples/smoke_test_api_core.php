<?php
/**
 * File: smoke_test_api_core.php
 * Description: Quick service-layer smoke test for samples/api-core. Runs
 *              StraboSamplesService against the migrated dev DB without
 *              going through HTTP/auth. Verifies the SQL works end-to-end
 *              and the response shape is sane.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_api_core.php
 *
 *              Exits non-zero on any failure. Picks the top-sample-count
 *              user from the dev DB (~3,790 samples on the prod restore)
 *              and exercises listMySamples + getSample on one of their
 *              samples. Also probes a couple of negative paths.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

// Pick the user with the most migrated samples.
$row = $db->get_row_prepared(
    "SELECT userpkey, count(*) AS n FROM strabosamples.samples GROUP BY userpkey ORDER BY n DESC LIMIT 1",
    array()
);
$testUser = (int)$row->userpkey;
$expectedCount = (int)$row->n;
echo "Test user: pkey=$testUser ($expectedCount own samples in migrated set)\n\n";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($testUser);

// --- listMySamples ---
echo "=== listMySamples ===\n";
$list = $svc->listMySamples();
check("returns an array", is_array($list));
check("count >= expected owned samples ($expectedCount)", count($list) >= $expectedCount);
if (!empty($list)) {
    $first = $list[0];
    check("entry has 'id'",                     isset($first['id']));
    check("entry has 'userpkey' (int)",          isset($first['userpkey']) && is_int($first['userpkey']));
    check("entry has 'name'",                    array_key_exists('name', $first));
    check("entry has 'display_sample_type'",     array_key_exists('display_sample_type', $first));
    check("entry has 'modified_at'",             isset($first['modified_at']));
}

// --- getSample on an owned sample ---
echo "\n=== getSample (owned) ===\n";
$pickRow = $db->get_row_prepared(
    "SELECT id FROM strabosamples.samples WHERE userpkey=$1 ORDER BY modified_at DESC LIMIT 1",
    array($testUser)
);
$pickId = $pickRow ? $pickRow->id : null;
echo "  pickId: $pickId\n";
$sample = $svc->getSample($pickId);
check("returns an array",                        is_array($sample));
check("id matches",                              isset($sample['id']) && $sample['id'] === $pickId);
check("userpkey matches",                        isset($sample['userpkey']) && $sample['userpkey'] === $testUser);
check("has composition[] (array)",               isset($sample['composition']) && is_array($sample['composition']));
check("has parameters[] (array)",                isset($sample['parameters']) && is_array($sample['parameters']));
check("has documents[] (array)",                 isset($sample['documents']) && is_array($sample['documents']));
check("has subsystem_links[] (array)",           isset($sample['subsystem_links']) && is_array($sample['subsystem_links']));
check("has collaborators[] (array)",             isset($sample['collaborators']) && is_array($sample['collaborators']));
check("has parent key (null or array)",          array_key_exists('parent', $sample));
check("has children[] (array)",                  isset($sample['children']) && is_array($sample['children']));
check("has at least one subsystem_link",         count($sample['subsystem_links']) >= 1);

// --- getSample negative: unknown id ---
echo "\n=== getSample (unknown id) ===\n";
$missing = $svc->getSample('definitely-not-a-real-sample-id-' . time());
check("returns null for unknown id",             $missing === null);

// --- getSample negative: stranger trying to read ---
echo "\n=== getSample (stranger access) ===\n";
$strangerRow = $db->get_row_prepared(
    "SELECT id, userpkey FROM strabosamples.samples WHERE userpkey != $1 LIMIT 1",
    array($testUser)
);
if ($strangerRow) {
    $strangerSample = $svc->getSample($strangerRow->id, (int)$strangerRow->userpkey);
    check("stranger access returns null",        $strangerSample === null);
} else {
    echo "  SKIP — no other-user samples to test against\n";
}

// --- canRead direct ---
echo "\n=== canRead ===\n";
check("owner can read own sample",               $svc->canRead($pickId, $testUser) === true);
check("nobody can read unknown sample",          $svc->canRead('nope-' . time(), $testUser) === false);

echo "\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
