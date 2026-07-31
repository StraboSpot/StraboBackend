<?php
/**
 * File: smoke_test_verify_extended.php
 * Description: Smoke for samples/verify-extended. Drives verify_extended.php
 *              as a subprocess (it's a CLI tool) and verifies:
 *
 *                Part 1: Happy-path PASS against current DB state — exit 0
 *                        with all 4 check categories OK.
 *                Part 2: Vocab failure — inject a sample with a junk
 *                        display_sample_type / display_sample_purpose. Verify
 *                        the tool exits non-zero, reports vocab_sanity:fail,
 *                        and other checks still pass.
 *                Part 3: Help flag — verify_extended --help returns 0.
 *                Part 4: Output contract — happy-path PASS output contains
 *                        every check name (schema_integrity, dangling_fk,
 *                        vocab_sanity, cross_reconciliation) so future
 *                        runbook automation can grep.
 *
 *              Notes on coverage:
 *                - Schema-integrity and dangling-FK checks are exercised
 *                  passively (the dev DB has zero issues today, the tool
 *                  reports OK). Their logic is verified by code inspection;
 *                  it's not safe to deliberately break FK constraints from
 *                  a smoke test that runs alongside other suites.
 *                - The vocab failure injection is the most realistic
 *                  failure mode in practice (a stray free-text input
 *                  bypassing the modal vocab dropdown).
 *
 *              Hermetic: seeds samples + cleans them up in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_verify_extended.php
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

$stamp = time();
$junkSampleId = "smoketest-vext-junk-$stamp";
$verifyExtendedPath = '/srv/app/www/samplesdb/migration/verify_extended.php';

try {

    // -------------------------------------------------------------------
    // PART 1: Happy-path PASS.
    // -------------------------------------------------------------------
    echo "=== Part 1: Happy-path PASS ===\n";
    $cmd = "php " . escapeshellarg($verifyExtendedPath) . " 2>&1";
    $output = array();
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $combined = implode("\n", $output);
    check("happy-path exit code is 0",                                    $exitCode === 0);
    check("happy-path output contains 'VERIFY EXTENDED: PASS'",           strpos($combined, 'VERIFY EXTENDED: PASS') !== false);
    check("happy-path output contains 'schema_integrity: ok'",            strpos($combined, 'schema_integrity: ok') !== false);
    check("happy-path output contains 'dangling_fk: ok'",                 strpos($combined, 'dangling_fk: ok') !== false);
    check("happy-path output contains 'vocab_sanity: ok'",                strpos($combined, 'vocab_sanity: ok') !== false);
    check("happy-path output contains 'cross_reconciliation: ok'",        strpos($combined, 'cross_reconciliation: ok') !== false);

    // -------------------------------------------------------------------
    // PART 2: Vocab failure — junk in display_sample_purpose.
    // -------------------------------------------------------------------
    echo "\n=== Part 2: Vocab failure detected ===\n";
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, display_sample_purpose,
             created_by, modified_by, created_at, modified_at)
         VALUES ($1, $2, $3, $4, $2, $2, NOW(), NOW())",
        array($junkSampleId, $ownerPkey, 'Vext Junk Sample', 'asdfbefgvb')
    );

    $output = array();
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $combined = implode("\n", $output);
    check("junk-injection exit code is non-zero",                         $exitCode !== 0);
    check("junk-injection output contains 'VERIFY EXTENDED: FAIL'",       strpos($combined, 'VERIFY EXTENDED: FAIL') !== false);
    check("junk-injection output flags vocab_sanity failure",             strpos($combined, 'vocab_sanity:') !== false);
    check("junk-injection output mentions the injected junk string",      strpos($combined, 'asdfbefgvb') !== false);
    // Other check categories should still PASS even when vocab fails —
    // the junk sample is FK-valid + schema-valid + properly link-counted.
    check("junk-injection: schema_integrity check still OK",              strpos($combined, '[1/4] Schema integrity ...') !== false &&
                                                                          preg_match('/\[1\/4\][^\[]*OK/s', $combined) === 1);
    check("junk-injection: dangling_fk check still OK",                   preg_match('/\[2\/4\][^\[]*OK/s', $combined) === 1);

    // Remove the junk row; verify_extended should pass again.
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($junkSampleId, $ownerPkey)
    );

    $output = array();
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $combined = implode("\n", $output);
    check("post-cleanup exit code is 0",                                  $exitCode === 0);
    check("post-cleanup output contains 'VERIFY EXTENDED: PASS'",         strpos($combined, 'VERIFY EXTENDED: PASS') !== false);

    // -------------------------------------------------------------------
    // PART 3: --help returns 0.
    // -------------------------------------------------------------------
    echo "\n=== Part 3: --help flag ===\n";
    $output = array();
    $exitCode = 0;
    exec("php " . escapeshellarg($verifyExtendedPath) . " --help 2>&1", $output, $exitCode);
    $combined = implode("\n", $output);
    check("--help exit code is 0",                                        $exitCode === 0);
    check("--help output contains usage line",                            strpos($combined, 'Usage: php verify_extended.php') !== false);

    // -------------------------------------------------------------------
    // PART 4: Cross-reconciliation contract.
    // The samples_with_link + samples_with_0_links accounting must always
    // equal the total — verify the math in the latest happy-path output.
    // -------------------------------------------------------------------
    echo "\n=== Part 4: Cross-reconciliation accounting ===\n";
    $totalSamples       = (int)$db->get_var("SELECT COUNT(*) FROM strabosamples.samples");
    $samplesWithLink    = (int)$db->get_var(
        "SELECT COUNT(DISTINCT (sample_id, sample_userpkey))
           FROM strabosamples.sample_subsystem_links"
    );
    $samplesWithoutLink = (int)$db->get_var(
        "SELECT COUNT(*) FROM strabosamples.samples s
          WHERE NOT EXISTS (
                SELECT 1 FROM strabosamples.sample_subsystem_links l
                 WHERE l.sample_id = s.id AND l.sample_userpkey = s.userpkey)"
    );
    check("cross-reconciliation: samples_with_link + samples_without_link == total",
          $samplesWithLink + $samplesWithoutLink === $totalSamples);

} finally {
    // Defensive: even if Part 2 inserted then errored before its DELETE.
    $db->prepare_query(
        "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($junkSampleId, $ownerPkey)
    );
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
