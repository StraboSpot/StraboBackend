<?php
/**
 * File: sample_sync.php
 * Description: Mirror a StraboMicro sample into strabosamples.* via
 *              StraboSamplesService. Called by strabomicroclass after the
 *              normal micro_samplemetadata write so the cross-system
 *              spine + subsystem_link is kept in sync.
 *
 * The Micro source canonical id is `micro_samplemetadata.strabo_id`, NOT
 * the internal int `id` (per design §3.4 and the prod audit). The sample
 * owner is resolved upstream via the containing project's userpkey
 * (Micro samples have no userpkey column of their own).
 *
 * Phase-1-cutover note: this writes to a schema that doesn't exist on
 * production until the StraboSamples Phase 1 cutover runs. See memory
 * project_strabosamples_phase1_cutover_deferred + the branch isolation
 * rule. On dev (post-Phase-1-schema-apply), the writes land cleanly.
 *
 * @package    StraboMicro
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

require_once __DIR__ . '/../../samplesdb/services/StraboSamplesService.php';

if (!function_exists('micro_coord_or_null')) {
/**
 * Normalize one Micro sample coordinate for BOTH the micro_samplemetadata
 * insert and the strabosamples mirror: null / '' / non-numeric / zero all
 * mean "no coordinate" and become null; anything else becomes a float.
 *
 * Why zero counts as unset: the Micro app sends latitude/longitude as
 * numeric 0 for samples that were never located. The source insert
 * historically skipped those because PHP 7's loose `0 != ""` is false,
 * while the spine mirror kept 0.0 - so every un-located Micro sample
 * carried a (0, 0) point in strabosamples (Null Island on the globe) and
 * the cross-system drift auditor flagged latitude/longitude on each one
 * (prod 2026-08-23). One rule, applied explicitly on both sides, keeps
 * them identical regardless of PHP version.
 *
 * @param  mixed      $v
 * @return float|null
 */
function micro_coord_or_null($v) {
    if ($v === null || $v === '' || $v === false || !is_numeric($v)) return null;
    $f = (float)$v;
    return ($f == 0.0) ? null : $f;
}
}

if (!function_exists('micro_sample_sync')) {

/**
 * Project one Micro sample (as a stdClass from the project JSON) into
 * strabosamples.* via StraboSamplesService::upsertSample.
 *
 * The reference_id / reference_metadata shape matches the migration's
 * extract_micro.php so a re-upload converges on the same row state:
 *   reference_id          = project's internal int pkey  (string-cast)
 *   reference_userpkey    = the project owner            (sample owner too)
 *   reference_metadata    = { dataset_id, project_strabo_id, micrograph_count }
 *
 * Auto-seed: the public.collaborators table is keyed on
 * (strabo_project_id, project_owner_user_pkey) — same table used by
 * /db/. Accepted, non-disabled collaborators are picked up and seeded
 * as pre-accepted sample collaborators per §7.3.1 on first creation.
 *
 * @param object   $db                       StraboDbPostgreSQL handle
 * @param object   $sample                   Sample object from the project JSON
 *                                           (loadProjectJSON-style camelCase shape).
 * @param string   $project_strabo_id        The project's strabo_id (for collab lookup)
 * @param int      $project_internal_id      The project's PG int pkey (reference_id)
 * @param int      $project_owner_pkey       The project owner (= sample owner)
 * @param int      $dataset_internal_id      The dataset's PG int pkey (reference_metadata)
 * @param int      $micrograph_count         How many micrographs this sample has
 * @return string|null                       The strabo_id of the synced sample, or null when input was empty
 */
function micro_sample_sync($db, $sample, $project_strabo_id, $project_internal_id, $project_owner_pkey, $dataset_internal_id, $micrograph_count = 0) {

    if (empty($sample) || !is_object($sample)) {
        return null;
    }
    $strabo_id = isset($sample->id) ? (string)$sample->id : '';
    if ($strabo_id === '') {
        // Samples without a strabo_id can't bridge to strabosamples (PK is
        // (id, userpkey)). Skip silently — the source-side row is still
        // written by the caller.
        return null;
    }

    // Spine projection (mirrors extract_micro.php §11.1).
    // sampleID is the human label preferred for display; fall back to label.
    $name = !empty($sample->sampleID) ? (string)$sample->sampleID
          : (!empty($sample->label) ? (string)$sample->label : null);
    $description = !empty($sample->sampleDescription) ? (string)$sample->sampleDescription : null;
    $notes       = !empty($sample->sampleNotes)       ? (string)$sample->sampleNotes       : null;
    // Same rule as the micro_samplemetadata insert (micro_coord_or_null):
    // zero / blank = no coordinate.
    $lat = micro_coord_or_null(isset($sample->latitude)  ? $sample->latitude  : null);
    $lng = micro_coord_or_null(isset($sample->longitude) ? $sample->longitude : null);

    $displayType = !empty($sample->materialType) ? (string)$sample->materialType : null;
    if ($displayType === 'other' && !empty($sample->otherMaterialType)) {
        $displayType = (string)$sample->otherMaterialType;
    }
    $displayPurpose = !empty($sample->mainSamplingPurpose) ? (string)$sample->mainSamplingPurpose : null;

    $spine = array(
        'name'                   => $name,
        'igsn'                   => null,    // Micro has no IGSN
        'description'            => $description,
        'notes'                  => $notes,
        'latitude'               => $lat,
        'longitude'              => $lng,
        'display_sample_type'    => $displayType,
        'display_sample_purpose' => $displayPurpose,
    );

    // micro_data JSONB — preserve all source columns verbatim.
    // Keys mirror the migration's lowercase DB-column names so a Micro
    // upload + later re-extraction round-trip cleanly.
    $microData = array(
        'strabo_id'              => $strabo_id,
        'dataset_id'             => $dataset_internal_id,
        'project_internal_id'    => $project_internal_id,
        'project_strabo_id'      => $project_strabo_id,
        'label'                  => isset($sample->label)                  ? $sample->label                  : null,
        'sampleid'               => isset($sample->sampleID)               ? $sample->sampleID               : null,
        'mainsamplingpurpose'    => isset($sample->mainSamplingPurpose)    ? $sample->mainSamplingPurpose    : null,
        'sampledescription'      => isset($sample->sampleDescription)      ? $sample->sampleDescription      : null,
        'materialtype'           => isset($sample->materialType)           ? $sample->materialType           : null,
        'othermaterialtype'      => isset($sample->otherMaterialType)      ? $sample->otherMaterialType      : null,
        'inplacenessofsample'    => isset($sample->inplacenessOfSample)    ? $sample->inplacenessOfSample    : null,
        'orientedsample'         => isset($sample->orientedSample)         ? $sample->orientedSample         : null,
        'samplesize'             => isset($sample->sampleSize)             ? $sample->sampleSize             : null,
        'degreeofweathering'     => isset($sample->degreeOfWeathering)     ? $sample->degreeOfWeathering     : null,
        'samplenotes'            => isset($sample->sampleNotes)            ? $sample->sampleNotes            : null,
        'sampletype'             => isset($sample->sampleType)             ? $sample->sampleType             : null,
        'color'                  => isset($sample->color)                  ? $sample->color                  : null,
        'lithology'              => isset($sample->lithology)              ? $sample->lithology              : null,
        'sampleunit'             => isset($sample->sampleUnit)             ? $sample->sampleUnit             : null,
        'sampleorientationnotes' => isset($sample->sampleOrientationNotes) ? $sample->sampleOrientationNotes : null,
        'othersamplingpurpose'   => isset($sample->otherSamplingPurpose)   ? $sample->otherSamplingPurpose   : null,
        // Normalized like the source columns (zero/blank = null) so the
        // drift auditor's micro_data-vs-source comparison holds.
        'longitude'              => $lng,
        'latitude'               => $lat,
    );

    $reference = array(
        'reference_id'         => (string)$project_internal_id,
        'reference_userpkey'   => (int)$project_owner_pkey,
        'reference_metadata'   => array(
            'dataset_id'        => (int)$dataset_internal_id,
            'project_strabo_id' => (string)$project_strabo_id,
            'micrograph_count'  => (int)$micrograph_count,
        ),
    );

    // Auto-seed: pre-accepted sample collaborators from the project's
    // accepted, non-disabled collaborators. Same `collaborators` table
    // /db/ uses (keyed on strabo_project_id, project_owner_user_pkey).
    $seedRows = $db->get_results_prepared(
        "SELECT collaborator_user_pkey, collaboration_level
           FROM collaborators
          WHERE strabo_project_id = $1
            AND project_owner_user_pkey = $2
            AND accepted = TRUE
            AND disabled = FALSE",
        array($project_strabo_id, (int)$project_owner_pkey)
    );
    // pkey => level map — each seeded grant mirrors that collaborator's
    // OWN project level (§7.3.1 "one-time copy"), matching the Field path.
    $seedMap = array();
    if (is_array($seedRows)) {
        foreach ($seedRows as $row) {
            $pkey = (int)$row->collaborator_user_pkey;
            if ($pkey <= 0) continue;
            $seedMap[$pkey] = (isset($row->collaboration_level) && $row->collaboration_level === 'readonly')
                ? 'readonly' : 'edit';
        }
    }

    $svc = new StraboSamplesService($db, null);
    $svc->setUserpkey((int)$project_owner_pkey);
    $svc->upsertSample(
        'micro',
        $strabo_id,
        (int)$project_owner_pkey,
        $spine,
        $microData,
        $reference,
        array(),  // Micro has no composition/parameters/documents children today
        array(
            'autoSeedCollaborators' => $seedMap,
        )
    );

    return $strabo_id;
}

/**
 * Mirror the removal of all Micro samples in one project. Called from
 * strabomicroclass::deleteProject BEFORE the cascade DELETE runs against
 * the source tables, so we can still resolve the strabo_ids.
 *
 * @param object $db                  StraboDbPostgreSQL handle
 * @param string $project_strabo_id   The project's strabo_id
 * @param int    $project_owner_pkey  The project owner
 * @return int   Number of strabosamples rows that the helper attempted
 *               to remove (passes through removeSubsystemSample's logic).
 */
function micro_sample_sync_remove_project($db, $project_strabo_id, $project_owner_pkey) {

    $rows = $db->get_results_prepared(
        "SELECT s.strabo_id
           FROM micro_samplemetadata s
           JOIN micro_datasetmetadata d ON d.id = s.dataset_id
           JOIN micro_projectmetadata p ON p.id = d.project_id
          WHERE p.userpkey = $1 AND p.strabo_id = $2
            AND s.strabo_id IS NOT NULL AND s.strabo_id <> ''",
        array((int)$project_owner_pkey, $project_strabo_id)
    );
    if (!is_array($rows) || empty($rows)) {
        return 0;
    }
    $svc = new StraboSamplesService($db, null);
    $svc->setUserpkey((int)$project_owner_pkey);
    $count = 0;
    foreach ($rows as $r) {
        $svc->removeSubsystemSample('micro', $r->strabo_id, (int)$project_owner_pkey);
        $count++;
    }
    return $count;
}

}
