<?php
/**
 * File: smoke_test_spine_cleanup.php
 * Description: Smoke test for samplesdb/cleanup/null_garbage_spine.php.
 *              Inserts known-garbage spine rows under a test id prefix,
 *              runs the cleanup script as a subprocess (matches the way
 *              it'll be invoked operationally), and asserts:
 *
 *                - dry-run mode does NOT write
 *                - --apply mode NULLs every matching value in the test
 *                  fixtures (regex for type, exact-list for purpose)
 *                - a legitimate control row is untouched
 *                - re-running --apply is a no-op against the same rows
 *                  (idempotency — script can be re-applied safely)
 *
 *              Hermetic: all writes scoped to a test id prefix; tears
 *              down in finally{}. Real dev-DB garbage rows will also be
 *              touched by --apply (that's the script's job and expected
 *              operational behavior), but the assertions are scoped to
 *              this test's own rows.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_spine_cleanup.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array()
);
if (!is_array($users) || count($users) < 1) { echo "Need 1 user\n"; exit(1); }
$ownerPkey = (int)$users[0]->pkey;

$stamp        = time();
$idNumeric    = "smoke-cleanup-numeric-$stamp";
$idJunkPurp   = "smoke-cleanup-junkpurp-$stamp";
$idControl    = "smoke-cleanup-control-$stamp";

$scriptPath = '/srv/app/www/samplesdb/cleanup/null_garbage_spine.php';

/** Run the cleanup script as a subprocess; return [$exitCode, $output]. */
function runCleanup($scriptPath, $apply) {
    $cmd = 'php ' . escapeshellarg($scriptPath) . ($apply ? ' --apply' : '') . ' 2>&1';
    $output = array();
    $exit   = 0;
    exec($cmd, $output, $exit);
    return array($exit, implode("\n", $output));
}

try {
    // Seed: numeric type, junk purpose, and a legit control row.
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, created_by, modified_by, display_sample_type)
         VALUES ($1, $2, $2, $2, '42.42')",
        array($idNumeric, $ownerPkey)
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, created_by, modified_by, display_sample_purpose)
         VALUES ($1, $2, $2, $2, 'bar')",
        array($idJunkPurp, $ownerPkey)
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, created_by, modified_by, display_sample_type, display_sample_purpose)
         VALUES ($1, $2, $2, $2, 'intact_rock', 'petrology')",
        array($idControl, $ownerPkey)
    );
    check('fixture rows inserted', true);

    // ----- Dry-run -----
    echo "\n[a] Dry-run mode\n";
    list($exit, $out) = runCleanup($scriptPath, false);
    check('a1 dry-run exits 0', $exit === 0);
    check('a1 dry-run output mentions DRY-RUN',
        strpos($out, 'DRY-RUN') !== false);

    $check1 = $db->get_var_prepared(
        "SELECT display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($idNumeric, $ownerPkey)
    );
    $check2 = $db->get_var_prepared(
        "SELECT display_sample_purpose FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($idJunkPurp, $ownerPkey)
    );
    check('a2 numeric type still 42.42 (dry-run did NOT write)', $check1 === '42.42');
    check('a2 junk purpose still bar (dry-run did NOT write)',   $check2 === 'bar');

    // ----- Apply -----
    echo "\n[b] --apply mode\n";
    list($exit, $out) = runCleanup($scriptPath, true);
    check('b1 apply exits 0', $exit === 0);
    check('b1 apply output mentions Done', strpos($out, 'Done.') !== false);

    $check1 = $db->get_var_prepared(
        "SELECT display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($idNumeric, $ownerPkey)
    );
    $check2 = $db->get_var_prepared(
        "SELECT display_sample_purpose FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($idJunkPurp, $ownerPkey)
    );
    check('b2 numeric type now NULL',  $check1 === null);
    check('b2 junk purpose now NULL',  $check2 === null);

    $ctrlType = $db->get_var_prepared(
        "SELECT display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($idControl, $ownerPkey)
    );
    $ctrlPurp = $db->get_var_prepared(
        "SELECT display_sample_purpose FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($idControl, $ownerPkey)
    );
    check('b3 control row type STILL intact_rock',  $ctrlType === 'intact_rock');
    check('b3 control row purpose STILL petrology', $ctrlPurp === 'petrology');

    // ----- Idempotent re-apply -----
    echo "\n[c] Idempotent re-apply\n";
    // After the first apply, our own fixtures are already NULL. Re-running
    // should report 0 matches and exit cleanly (the script's "Nothing to
    // clean" path), regardless of whether the dev DB had pre-existing
    // garbage that's also been cleaned by the first run.
    list($exit, $out) = runCleanup($scriptPath, true);
    check('c1 re-apply exits 0', $exit === 0);

    // Snapshot remaining matches; expect 0 across the board after the
    // first --apply because the script targets ALL matching rows.
    $remainType = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE display_sample_type ~ '^[0-9]+(\\.[0-9]+)?$'",
        array()
    );
    $remainPurp = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE display_sample_purpose IN ('bar','asdfbefgvb','test sample purpose')",
        array()
    );
    check('c2 zero numeric-type rows remain after apply',  $remainType === 0);
    check('c2 zero junk-purpose rows remain after apply',  $remainPurp === 0);
    check('c2 re-apply output reports Nothing to clean',
        strpos($out, 'Nothing to clean') !== false);

} finally {
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id IN ($1, $2, $3) AND userpkey=$4",
        array($idNumeric, $idJunkPurp, $idControl, $ownerPkey)
    );
}

echo "\n----------------------------------------\n";
if (empty($failures)) {
    echo "All checks PASS\n";
    exit(0);
}
echo count($failures) . " FAILED:\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
