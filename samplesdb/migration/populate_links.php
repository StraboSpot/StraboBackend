<?php
/**
 * File: populate_links.php
 * Description: Insert one strabosamples.sample_subsystem_links row per
 *              source row. Idempotent via the table's UNIQUE
 *              (sample_id, sample_userpkey, subsystem, reference_id,
 *               reference_userpkey) constraint — re-runs are no-ops.
 *
 *              One row per source row, NOT deduped per subsystem: a sample
 *              may be referenced by multiple Micro/Experimental projects,
 *              and each reference earns its own link (§10.2 Phase D).
 *
 * @package    StraboSamples Migration
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (!function_exists('migration_upsert_link')) {

/**
 * Insert (or no-op) a sample_subsystem_links row for one source row.
 *
 * @param  object $db          StraboDbPostgreSQL handle
 * @param  array  $row         normalized source row from extract_*
 * @param  bool   $dryRun      if true, no DB writes
 * @return string  'inserted' | 'exists' | 'dry_run'
 */
function migration_upsert_link($db, $row, $dryRun = false) {

    if ($dryRun) {
        $existing = $db->get_var_prepared("
            SELECT 1
            FROM strabosamples.sample_subsystem_links
            WHERE sample_id = $1
              AND sample_userpkey = $2
              AND subsystem = $3
              AND reference_id = $4
              AND reference_userpkey = $5
        ", array(
            $row['sample_id'],
            $row['sample_userpkey'],
            $row['source'],
            $row['reference_id'],
            $row['reference_userpkey'],
        ));
        return $existing ? 'exists' : 'dry_run';
    }

    // Use ON CONFLICT DO NOTHING + RETURNING to distinguish insert vs no-op
    // in one round-trip.
    $result = $db->get_var_prepared("
        INSERT INTO strabosamples.sample_subsystem_links
            (sample_id, sample_userpkey, subsystem,
             reference_id, reference_userpkey, reference_metadata,
             created_at, modified_at)
        VALUES ($1, $2, $3, $4, $5, $6::jsonb, NOW(), NOW())
        ON CONFLICT (sample_id, sample_userpkey, subsystem,
                     reference_id, reference_userpkey)
        DO NOTHING
        RETURNING 1
    ", array(
        $row['sample_id'],
        $row['sample_userpkey'],
        $row['source'],
        $row['reference_id'],
        $row['reference_userpkey'],
        json_encode($row['reference_metadata']),
    ));

    return $result ? 'inserted' : 'exists';
}

}
