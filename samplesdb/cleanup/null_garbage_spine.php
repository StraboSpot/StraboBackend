<?php
/**
 * File: samplesdb/cleanup/null_garbage_spine.php
 * Description: One-off operational cleanup script for the strabosamples
 *              spine columns display_sample_type and display_sample_purpose.
 *
 *              The 2026-06-05 audit (PROJECT_STATUS, samples/vocab-union-
 *              modal) surfaced ~108 rows where display_sample_type holds
 *              a purely-numeric value ('1', '2', '6.728709', …) and 3
 *              rows where display_sample_purpose holds keyboard mash
 *              ('bar', 'asdfbefgvb', 'test sample purpose'). These came
 *              in via the old free-text modal path and Experimental's
 *              pre-normalized-write era; with vocab-union-modal landed
 *              the front door is now closed, but the existing rows still
 *              pollute the dropdown's "what values can appear" set and
 *              would surface as nonsense values on Sample Overview.
 *
 *              Cleanup is CONSERVATIVE on purpose:
 *                - type: NULL ONLY purely-numeric values (regex
 *                  `^[0-9]+(\.[0-9]+)?$`). Mixed-case Experimental
 *                  values like 'Soil', 'Gneiss', 'Lava' are real domain
 *                  terms and stay.
 *                - purpose: NULL ONLY the three hand-picked garbage
 *                  strings. 'reference' (1 row) is a real word a user
 *                  typed intentionally and stays.
 *
 *              Idempotent: re-run after --apply writes zero new NULLs.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/samplesdb/cleanup/null_garbage_spine.php
 *                docker exec strabo-php php /srv/app/www/samplesdb/cleanup/null_garbage_spine.php --apply
 *
 *              Same script runs on prod at cutover.
 */

require_once __DIR__ . '/../../includes/config.inc.php';
require_once __DIR__ . '/../../db.php';

$apply = in_array('--apply', $argv ?? array(), true);

// Pattern for type cleanup: any value that's purely a number (int or
// decimal). These can never be a valid material type.
$NUMERIC_PATTERN = '^[0-9]+(\.[0-9]+)?$';

// Pattern for empty/whitespace-only values. Applies to both columns —
// no semantic content, only takes up index space. Captured here because
// the 2026-06-08 verify_extended.php caught a row with display_sample_purpose=''
// that the prior cleanup's exact-match junk list had missed.
$EMPTY_PATTERN = '^\s*$';

// Hand-picked junk purposes. NOT a regex pattern — exact-match list so
// any future drift in the column doesn't accidentally null real data.
$PURPOSE_JUNK = array('bar', 'asdfbefgvb', 'test sample purpose');

function fmt($n) { return number_format($n); }

echo "===== StraboSamples spine garbage cleanup =====\n";
echo "Mode: " . ($apply ? 'APPLY (rows will be NULLed)' : 'DRY-RUN (no writes)') . "\n\n";

// ----- Pre-flight: count what we'd touch -----

$preflightType = $db->get_row_prepared(
    "SELECT count(*) AS rows, count(DISTINCT display_sample_type) AS distinct_values
       FROM strabosamples.samples
      WHERE display_sample_type ~ $1 OR display_sample_type ~ $2",
    array($NUMERIC_PATTERN, $EMPTY_PATTERN)
);

// Build a placeholder list for the IN clause. Param numbering picks up
// after $1 = $EMPTY_PATTERN.
$placeholders = array();
for ($i = 2; $i <= count($PURPOSE_JUNK) + 1; $i++) { $placeholders[] = '$' . $i; }
$inList = implode(', ', $placeholders);
$preflightPurpose = $db->get_row_prepared(
    "SELECT count(*) AS rows, count(DISTINCT display_sample_purpose) AS distinct_values
       FROM strabosamples.samples
      WHERE display_sample_purpose ~ $1 OR display_sample_purpose IN ($inList)",
    array_merge(array($EMPTY_PATTERN), $PURPOSE_JUNK)
);

echo "display_sample_type   — purely-numeric rows: "
   . fmt($preflightType->rows) . " across " . fmt($preflightType->distinct_values) . " distinct values\n";
echo "display_sample_purpose — junk-list rows:      "
   . fmt($preflightPurpose->rows) . " across " . fmt($preflightPurpose->distinct_values) . " distinct values\n";

if ((int)$preflightType->rows === 0 && (int)$preflightPurpose->rows === 0) {
    echo "\nNothing to clean. Exiting.\n";
    exit(0);
}

// Show a sample of what would be touched (top 10 by count per column).
echo "\n--- Top type values to be NULLed ---\n";
$sampleType = $db->get_results_prepared(
    "SELECT display_sample_type AS value, count(*) AS cnt
       FROM strabosamples.samples
      WHERE display_sample_type ~ $1 OR display_sample_type ~ $2
      GROUP BY display_sample_type
      ORDER BY cnt DESC, value LIMIT 10",
    array($NUMERIC_PATTERN, $EMPTY_PATTERN)
);
foreach ($sampleType as $r) printf("  %6d  %s\n", (int)$r->cnt, $r->value === '' ? '(empty)' : $r->value);

echo "\n--- Purpose values to be NULLed ---\n";
$samplePurpose = $db->get_results_prepared(
    "SELECT display_sample_purpose AS value, count(*) AS cnt
       FROM strabosamples.samples
      WHERE display_sample_purpose ~ $1 OR display_sample_purpose IN ($inList)
      GROUP BY display_sample_purpose
      ORDER BY cnt DESC, value",
    array_merge(array($EMPTY_PATTERN), $PURPOSE_JUNK)
);
foreach ($samplePurpose as $r) printf("  %6d  %s\n", (int)$r->cnt, $r->value === '' ? '(empty)' : $r->value);

if (!$apply) {
    echo "\nDRY-RUN complete. Re-run with --apply to perform the NULL updates.\n";
    exit(0);
}

// ----- Apply -----

echo "\n--- Applying ---\n";
$db->query("BEGIN");

$beforeType = (int)$db->get_var_prepared(
    "SELECT count(*) FROM strabosamples.samples
      WHERE display_sample_type ~ $1 OR display_sample_type ~ $2",
    array($NUMERIC_PATTERN, $EMPTY_PATTERN)
);
$db->prepare_query(
    "UPDATE strabosamples.samples SET display_sample_type = NULL, modified_at = now()
      WHERE display_sample_type ~ $1 OR display_sample_type ~ $2",
    array($NUMERIC_PATTERN, $EMPTY_PATTERN)
);
$afterType = (int)$db->get_var_prepared(
    "SELECT count(*) FROM strabosamples.samples
      WHERE display_sample_type ~ $1 OR display_sample_type ~ $2",
    array($NUMERIC_PATTERN, $EMPTY_PATTERN)
);

$beforePurpose = (int)$db->get_var_prepared(
    "SELECT count(*) FROM strabosamples.samples
      WHERE display_sample_purpose ~ $1 OR display_sample_purpose IN ($inList)",
    array_merge(array($EMPTY_PATTERN), $PURPOSE_JUNK)
);
$db->prepare_query(
    "UPDATE strabosamples.samples SET display_sample_purpose = NULL, modified_at = now()
      WHERE display_sample_purpose ~ $1 OR display_sample_purpose IN ($inList)",
    array_merge(array($EMPTY_PATTERN), $PURPOSE_JUNK)
);
$afterPurpose = (int)$db->get_var_prepared(
    "SELECT count(*) FROM strabosamples.samples
      WHERE display_sample_purpose ~ $1 OR display_sample_purpose IN ($inList)",
    array_merge(array($EMPTY_PATTERN), $PURPOSE_JUNK)
);

$db->query("COMMIT");

echo "display_sample_type   NULLed: " . fmt($beforeType - $afterType)    . " (remaining match: " . fmt($afterType)    . ")\n";
echo "display_sample_purpose NULLed: " . fmt($beforePurpose - $afterPurpose) . " (remaining match: " . fmt($afterPurpose) . ")\n";
echo "\nDone.\n";
