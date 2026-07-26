<?php
/**
 * File: extract_micro.php
 * Description: Read StraboMicro sample metadata and produce the normalized
 *              source-row stream. Canonical id is micro_samplemetadata.strabo_id
 *              (NOT the internal int id, which only anchors the micrograph
 *              FK per §9.2). Ownership is resolved via:
 *                  dataset_id → micro_datasetmetadata.project_id
 *                             → micro_projectmetadata.userpkey
 *              because micro_samplemetadata has no userpkey column.
 *
 *              display_sample_type derives from materialtype, falling
 *              through to othermaterialtype when materialtype = 'other'
 *              (prod audit §11.1).
 *
 * @package    StraboSamples Migration
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (!function_exists('migration_extract_micro')) {

/**
 * @param  object $db    StraboDbPostgreSQL handle
 * @param  array  $opts  { 'limit' => int|null }
 * @return array  list of source rows
 */
function migration_extract_micro($db, $opts = array()) {

    $sql = "
        SELECT
            sm.id              AS micro_internal_id,
            sm.strabo_id       AS sample_id,
            sm.dataset_id      AS dataset_id,
            sm.label,
            sm.sampleid,
            sm.mainsamplingpurpose,
            sm.sampledescription,
            sm.materialtype,
            sm.othermaterialtype,
            sm.inplacenessofsample,
            sm.orientedsample,
            sm.samplesize,
            sm.degreeofweathering,
            sm.samplenotes,
            sm.sampletype,
            sm.color,
            sm.lithology,
            sm.sampleunit,
            sm.sampleorientationnotes,
            sm.othersamplingpurpose,
            sm.longitude,
            sm.latitude,
            pm.userpkey        AS project_userpkey,
            pm.id              AS project_internal_id,
            pm.strabo_id       AS project_strabo_id,
            (SELECT count(*) FROM strabomicro.micro_micrographmetadata mm
              WHERE mm.sample_id = sm.id) AS micrograph_count
        FROM strabomicro.micro_samplemetadata sm
        LEFT JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
        LEFT JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
        WHERE sm.strabo_id IS NOT NULL
          AND sm.strabo_id <> ''
        ORDER BY sm.id
    ";
    if (!empty($opts['limit'])) {
        $sql .= " LIMIT " . (int)$opts['limit'];
    }

    $rows = $db->get_results($sql);
    if (!$rows) return array();

    $out = array();
    foreach ($rows as $r) {
        if (empty($r->project_userpkey)) {
            // Unresolvable owner — skip. Prod audit reported 0 such rows;
            // logging happens at the orchestrator (skipped_orphan).
            continue;
        }

        $sampleId = (string)$r->sample_id;
        $userpkey = (int)$r->project_userpkey;

        // Display normalization (§11.1): materialtype, fall through to
        // othermaterialtype when materialtype == 'other'.
        $displayType = $r->materialtype !== null ? (string)$r->materialtype : null;
        if ($displayType === 'other' && !empty($r->othermaterialtype)) {
            $displayType = (string)$r->othermaterialtype;
        }
        $displayPurpose = $r->mainsamplingpurpose !== null
            ? (string)$r->mainsamplingpurpose
            : null;

        // Micro's preferred public name is `sampleid` (the human label per
        // the source schema); fall back to `label` if sampleid is empty.
        $name = !empty($r->sampleid) ? (string)$r->sampleid
              : (!empty($r->label) ? (string)$r->label : null);

        $description = !empty($r->sampledescription) ? (string)$r->sampledescription : null;
        $notes       = !empty($r->samplenotes)       ? (string)$r->samplenotes       : null;
        $lat = $r->latitude  !== null && $r->latitude  !== '' ? (float)$r->latitude  : null;
        $lng = $r->longitude !== null && $r->longitude !== '' ? (float)$r->longitude : null;

        // micro_data JSONB — preserve all source columns verbatim
        $microData = array(
            'micro_internal_id'      => (int)$r->micro_internal_id,
            'strabo_id'              => $sampleId,
            'dataset_id'             => $r->dataset_id !== null ? (int)$r->dataset_id : null,
            'project_internal_id'    => $r->project_internal_id !== null ? (int)$r->project_internal_id : null,
            'project_strabo_id'      => (string)$r->project_strabo_id,
            'label'                  => $r->label,
            'sampleid'               => $r->sampleid,
            'mainsamplingpurpose'    => $r->mainsamplingpurpose,
            'sampledescription'      => $r->sampledescription,
            'materialtype'           => $r->materialtype,
            'othermaterialtype'      => $r->othermaterialtype,
            'inplacenessofsample'    => $r->inplacenessofsample,
            'orientedsample'         => $r->orientedsample,
            'samplesize'             => $r->samplesize,
            'degreeofweathering'     => $r->degreeofweathering,
            'samplenotes'            => $r->samplenotes,
            'sampletype'             => $r->sampletype,
            'color'                  => $r->color,
            'lithology'              => $r->lithology,
            'sampleunit'             => $r->sampleunit,
            'sampleorientationnotes' => $r->sampleorientationnotes,
            'othersamplingpurpose'   => $r->othersamplingpurpose,
            'longitude'              => $r->longitude,
            'latitude'               => $r->latitude,
        );

        // The Micro table doesn't carry sample-level timestamps — leave the
        // ts fields null so spine-priority MIN/MAX picks them up from Field
        // (which does carry timestamps) when collapse happens.
        $out[] = array(
            'source'                 => 'micro',
            'sample_id'              => $sampleId,
            'sample_userpkey'        => $userpkey,
            'name'                   => $name,
            'igsn'                   => null,  // Micro schema has no IGSN column
            'description'            => $description,
            'notes'                  => $notes,
            'latitude'               => $lat,
            'longitude'              => $lng,
            'display_sample_type'    => $displayType,
            'display_sample_purpose' => $displayPurpose,
            'created_at'             => null,
            'modified_at'            => null,
            'created_by'             => $userpkey,
            'subsystem_data'         => $microData,
            'reference_id'           => (string)$r->project_internal_id,
            'reference_userpkey'     => $userpkey,
            'reference_metadata'     => array(
                'dataset_id'       => $r->dataset_id !== null ? (int)$r->dataset_id : null,
                'project_strabo_id'=> (string)$r->project_strabo_id,
                'micrograph_count' => (int)$r->micrograph_count,
            ),
            'composition'            => array(),
            'parameters'             => array(),
            'documents'              => array(),
            'source_label'           => 'micro_samplemetadata.id=' . (int)$r->micro_internal_id,
        );
    }
    return $out;
}

}
