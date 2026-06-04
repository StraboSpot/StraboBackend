<?php
/**
 * Backfill: populate straboexp.sample (+ children) from straboexp.experiment.json
 * -------------------------------------------------------------------------
 * The /experimental/ Vue API (save_experiment.php) was missing writes to the
 * normalized straboexp.sample table since the December 2025 rewrite — all
 * sample data has been living inside straboexp.experiment.json. This script
 * closes the gap: for every experiment whose JSON contains a non-empty
 * "sample" object but has no row in straboexp.sample, mint a fresh strabo_id
 * and write the normalized row + children (composition, parameters, sample
 * documents).
 *
 * The Vue API patch in this same branch fixes the going-forward write so
 * future saves keep the two stores in sync; this script handles the
 * pre-existing gap.
 *
 * Idempotent: re-running is safe — the WHERE NOT EXISTS guard means already-
 * normalized experiments are skipped.
 *
 * SAFETY:
 *   - DRY-RUN by default. Pass --apply to mutate.
 *   - Take a PostgreSQL backup of the straboexp schema before running with --apply.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/samplesdb/backfill/backfill_exp_samples.php
 *   docker exec strabo-php php /srv/app/www/samplesdb/backfill/backfill_exp_samples.php --apply
 *
 * @package StraboSamples
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('includes/UUID.php');
require_once('experimental/lib/sample_sync.php');

$APPLY = in_array('--apply', $argv, true);

function line($s = '') { echo $s . "\n"; }
function hr() { echo str_repeat('=', 72) . "\n"; }

hr();
line($APPLY ? "MODE: APPLY (will mutate straboexp.sample and children)"
            : "MODE: DRY-RUN (no changes; pass --apply to mutate)");
hr();

$uuid_gen = new UUID();

// Find experiments whose JSON has a non-empty sample object AND have no
// matching straboexp.sample row. The Postgres JSON cast happens once per row
// here so we can also pull the sample object directly.
$candidates = $db->get_results("
    SELECT e.pkey, e.userpkey, e.id, e.project_pkey, e.json
    FROM straboexp.experiment e
    LEFT JOIN straboexp.sample s ON s.experiment_pkey = e.pkey
    WHERE s.pkey IS NULL
      AND e.json IS NOT NULL
      AND e.json <> ''
      AND e.json::jsonb ? 'sample'
      AND (e.json::jsonb -> 'sample') IS NOT NULL
      AND jsonb_typeof(e.json::jsonb -> 'sample') = 'object'
      AND (e.json::jsonb -> 'sample') <> '{}'::jsonb
    ORDER BY e.pkey
");

$total = is_array($candidates) ? count($candidates) : 0;
line("Found $total experiment(s) with sample data in JSON but no normalized row.");
line('');

if ($total === 0) {
    line("Nothing to backfill.");
    exit(0);
}

$skipped = 0;
$created = 0;
$errors  = 0;

foreach ($candidates as $exp) {
    $exp_pkey = (int)$exp->pkey;
    $userpkey = (int)$exp->userpkey;
    $exp_id   = $exp->id;

    $parsed = json_decode($exp->json);
    if (!is_object($parsed) || !isset($parsed->sample)) {
        line("[skip] exp_pkey=$exp_pkey id=\"$exp_id\" — sample missing after parse");
        $skipped++;
        continue;
    }

    $sample = $parsed->sample;
    if (!is_object($sample)) {
        line("[skip] exp_pkey=$exp_pkey id=\"$exp_id\" — sample is not an object");
        $skipped++;
        continue;
    }

    $sample_name = isset($sample->name) ? (string)$sample->name : '';
    $sample_id   = isset($sample->id)   ? (string)$sample->id   : '';

    if ($APPLY) {
        try {
            $strabo_id = exp_sample_sync($db, $uuid_gen, $exp_pkey, $userpkey, $sample);

            // Embed strabo_id back into the JSON so the two stores stay in sync.
            if ($strabo_id !== null) {
                $parsed->sample->strabo_id = $strabo_id;
                $rewritten = json_encode($parsed);
                $db->prepare_query(
                    "UPDATE straboexp.experiment SET json = $1 WHERE pkey = $2",
                    array($rewritten, $exp_pkey)
                );
            }

            line("[ok]   exp_pkey=$exp_pkey id=\"$exp_id\" name=\"$sample_name\" sample_id=\"$sample_id\" strabo_id=$strabo_id");
            $created++;
        } catch (Exception $e) {
            line("[err]  exp_pkey=$exp_pkey id=\"$exp_id\" — " . $e->getMessage());
            $errors++;
        }
    } else {
        line("[plan] exp_pkey=$exp_pkey id=\"$exp_id\" name=\"$sample_name\" sample_id=\"$sample_id\" — would mint + insert");
        $created++;
    }
}

line('');
hr();
line($APPLY
    ? "RESULT: created=$created, skipped=$skipped, errors=$errors"
    : "PLAN:   would-create=$created, would-skip=$skipped");
hr();

exit($errors > 0 ? 1 : 0);
