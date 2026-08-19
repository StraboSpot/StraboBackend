<?php
/**
 * File: heal_empty_exp_junk_samples.php
 * Description: CLI healer for junk samples created by the empty-sample-
 *              section bug (fixed 2026-07-31, develop @ d015793). Companion
 *              to find_empty_exp_junk_samples.php — heals the same hit set
 *              server-side, so experiments owned by OTHER users don't need
 *              a UI re-save under their login.
 *
 *              Per affected experiment (sample JSON holds no keys besides
 *              strabo_id) it applies exactly what a no-op "Save Changes"
 *              through the fixed endpoint would do:
 *                1. exp_sample_sync() with the contentless sample — the
 *                   empty-case deletes the straboexp.sample row (children
 *                   cascade) and removes the strabosamples spine entry via
 *                   StraboSamplesService::removeSubsystemSample().
 *                2. Strips the stale strabo_id from the stored experiment
 *                   JSON (facility/apparatus/etc. untouched; the row's
 *                   modified_timestamp is deliberately NOT bumped).
 *
 *              Re-verifies contentlessness with exp_sample_has_data() right
 *              before acting, so an experiment the owner edited between
 *              detect and heal is skipped, never clobbered.
 *
 *              Usage (dry-run default; nothing written without --apply):
 *                docker exec strabo-php php \
 *                  /srv/app/www/samplesdb/audit/heal_empty_exp_junk_samples.php
 *                docker exec strabo-php php \
 *                  /srv/app/www/samplesdb/audit/heal_empty_exp_junk_samples.php --apply
 */

require_once __DIR__ . '/../../includes/config.inc.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/UUID.php';
require_once __DIR__ . '/../../experimental/lib/sample_sync.php';

$apply = in_array('--apply', $argv, true);
echo $apply ? "MODE: APPLY\n" : "MODE: dry-run (pass --apply to heal)\n";

// Union of both detector views: experiments whose stored sample JSON is
// contentless AND that still have an empty sample row and/or a nameless
// spine entry hanging off them.
$hits = $db->get_results("
    SELECT DISTINCT e.pkey AS experiment_pkey, e.id AS experiment_id, e.userpkey, e.json
      FROM straboexp.experiment e
      LEFT JOIN straboexp.sample sm ON sm.experiment_pkey = e.pkey
      LEFT JOIN strabosamples.sample_subsystem_links l
        ON l.subsystem = 'experimental' AND l.reference_id = e.pkey::text
      LEFT JOIN strabosamples.samples s
        ON s.id = l.sample_id AND s.userpkey = l.sample_userpkey
     WHERE jsonb_typeof(e.json::jsonb -> 'sample') = 'object'
       AND (SELECT count(*)
              FROM jsonb_object_keys(e.json::jsonb -> 'sample') k
             WHERE k <> 'strabo_id') = 0
       AND (sm.pkey IS NOT NULL OR (s.id IS NOT NULL AND s.name IS NULL))
     ORDER BY e.pkey
");

if (!is_array($hits) || count($hits) === 0) {
    echo "CLEAN — nothing to heal.\n";
    exit(0);
}

$uuid_gen = new UUID();
$healed = 0;
$skipped = 0;

foreach ($hits as $hit) {
    $pkey = (int)$hit->experiment_pkey;

    // Race guard: re-fetch at act time and never touch a sample that has
    // grown real content since detection (exp_sample_has_data ignores
    // strabo_id).
    $fresh = $db->get_var_prepared(
        "SELECT json FROM straboexp.experiment WHERE pkey = $1", array($pkey));
    $json = json_decode((string)$fresh);
    $sample = (is_object($json) && isset($json->sample)) ? $json->sample : null;

    if ($json === null || exp_sample_has_data($sample)) {
        echo "  SKIP  exp pkey=$pkey id=\"{$hit->experiment_id}\" — sample no longer contentless\n";
        $skipped++;
        continue;
    }

    echo "  " . ($apply ? "HEAL" : "would heal") . "  exp pkey=$pkey id=\"{$hit->experiment_id}\" user={$hit->userpkey}\n";
    if (!$apply) continue;

    // Same call the fixed save path makes: empty-case removes the sample
    // row + spine entry (idempotent if either is already gone).
    exp_sample_sync($db, $uuid_gen, $pkey, (int)$hit->userpkey, $sample);

    // Strip the stale strabo_id from the stored JSON, preserving all other
    // sections and the row's timestamps.
    if (is_object($sample) && isset($sample->strabo_id)) {
        unset($sample->strabo_id);
        $json->sample = $sample;
        $db->prepare_query(
            "UPDATE straboexp.experiment SET json = $1 WHERE pkey = $2",
            array(json_encode($json), $pkey)
        );
    }
    $healed++;
}

echo "\n" . ($apply ? "healed=$healed skipped=$skipped" : count($hits) - $skipped . " to heal, skipped=$skipped") . "\n";
if ($apply) {
    echo "Re-run find_empty_exp_junk_samples.php to confirm CLEAN.\n";
}
exit(0);
