<?php
/**
 * File: extract_experimental.php
 * Description: Read StraboExperimental sample rows and produce the normalized
 *              source-row stream. Canonical id is the minted
 *              straboexp.sample.strabo_id UUID — the human straboexp.sample.id
 *              demotes to `name` / `experimental_data.id` per §9.3 / §10.2.
 *
 *              Sub-arrays come from straboexp.sample_composition,
 *              straboexp.sample_parameter, and the sample_pkey IS NOT NULL
 *              rows of straboexp.document.
 *
 *              Timestamps come from the parent straboexp.experiment row
 *              (created_timestamp / modified_timestamp); the sample table
 *              itself has none.
 *
 * @package    StraboSamples Migration
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (!function_exists('migration_extract_experimental')) {

/**
 * @param  object $db    StraboDbPostgreSQL handle
 * @param  array  $opts  { 'limit' => int|null }
 * @return array  list of source rows
 */
function migration_extract_experimental($db, $opts = array()) {

    $sql = "
        SELECT
            s.pkey                         AS sample_pkey,
            s.strabo_id                    AS sample_id,
            s.userpkey                     AS sample_userpkey,
            s.experiment_pkey              AS experiment_pkey,
            s.name, s.igsn, s.id           AS human_id,
            s.description,
            s.parent_name, s.parent_igsn, s.parent_id, s.parent_description,
            s.material_type, s.material_name, s.material_state, s.material_note,
            s.provenance_formation, s.provenance_member,
            s.provenance_submember, s.provenance_source,
            s.provenance_loc_street, s.provenance_loc_building,
            s.provenance_loc_postcode, s.provenance_loc_city,
            s.provenance_loc_state, s.provenance_loc_country,
            s.provenance_loc_latitude, s.provenance_loc_longitude,
            s.texture_bedding, s.texture_lineation,
            s.texture_foliation, s.texture_fault,
            s.json                         AS sample_json,
            e.project_pkey                 AS project_pkey,
            e.id                           AS experiment_human_id,
            e.uuid                         AS experiment_uuid,
            e.created_timestamp            AS created_at,
            e.modified_timestamp           AS modified_at,
            p.uuid                         AS project_uuid
        FROM straboexp.sample s
        LEFT JOIN straboexp.experiment e ON e.pkey = s.experiment_pkey
        LEFT JOIN straboexp.project    p ON p.pkey = e.project_pkey
        WHERE s.strabo_id IS NOT NULL
        ORDER BY s.pkey
    ";
    if (!empty($opts['limit'])) {
        $sql .= " LIMIT " . (int)$opts['limit'];
    }

    $rows = $db->get_results($sql);
    if (!$rows) return array();

    $out = array();
    foreach ($rows as $r) {
        $sampleId = (string)$r->sample_id;
        $userpkey = (int)$r->sample_userpkey;
        $samplePkey = (int)$r->sample_pkey;

        // Spine columns. The human id demotes to NAME if name is empty.
        $name        = !empty($r->name)        ? (string)$r->name        : null;
        if ($name === null && !empty($r->human_id)) {
            $name = (string)$r->human_id;
        }
        $igsn        = !empty($r->igsn)        ? (string)$r->igsn        : null;
        $description = !empty($r->description) ? (string)$r->description : null;
        $notes       = null;  // Experimental schema has no sample-level notes column

        // Provenance lat/lng are stored as varchar in straboexp.sample.
        $lat = (isset($r->provenance_loc_latitude)  && $r->provenance_loc_latitude  !== '' && is_numeric($r->provenance_loc_latitude))
            ? (float)$r->provenance_loc_latitude  : null;
        $lng = (isset($r->provenance_loc_longitude) && $r->provenance_loc_longitude !== '' && is_numeric($r->provenance_loc_longitude))
            ? (float)$r->provenance_loc_longitude : null;

        $displayType    = !empty($r->material_type) ? (string)$r->material_type : null;
        $displayPurpose = null;  // Experimental has no equivalent (§11.2)

        $createdAt  = !empty($r->created_at)  ? strtotime((string)$r->created_at)  : null;
        $modifiedAt = !empty($r->modified_at) ? strtotime((string)$r->modified_at) : null;

        $expData = _migration_exp_row_to_jsonb($r);

        // Children — one DB hit per parent sample. Cheap at this scale (~83 rows).
        $composition = _migration_exp_composition($db, $samplePkey);
        $parameters  = _migration_exp_parameters($db, $samplePkey);
        $documents   = _migration_exp_documents($db, $samplePkey);

        $out[] = array(
            'source'                 => 'experimental',
            'sample_id'              => $sampleId,
            'sample_userpkey'        => $userpkey,
            'name'                   => $name,
            'igsn'                   => $igsn,
            'description'            => $description,
            'notes'                  => $notes,
            'latitude'               => $lat,
            'longitude'              => $lng,
            'display_sample_type'    => $displayType,
            'display_sample_purpose' => $displayPurpose,
            'created_at'             => $createdAt,
            'modified_at'            => $modifiedAt,
            'created_by'             => $userpkey,
            'subsystem_data'         => $expData,
            'reference_id'           => (string)$r->experiment_pkey,
            'reference_userpkey'     => $userpkey,
            'reference_metadata'     => array(
                'experiment_pkey'    => (int)$r->experiment_pkey,
                'experiment_id'      => $r->experiment_human_id,
                'experiment_uuid'    => $r->experiment_uuid,
                'project_pkey'       => $r->project_pkey !== null ? (int)$r->project_pkey : null,
                'project_uuid'       => $r->project_uuid,
            ),
            'composition'            => $composition,
            'parameters'             => $parameters,
            'documents'              => $documents,
            'source_label'           => 'straboexp.sample.pkey=' . $samplePkey,
        );
    }
    return $out;
}

/**
 * @internal — pack the source columns into the experimental_data JSONB shape.
 * The original sample_json blob is preserved as `_sample_json` for round-trip.
 */
function _migration_exp_row_to_jsonb($r) {
    return array(
        'id'                       => $r->human_id,
        'name'                     => $r->name,
        'igsn'                     => $r->igsn,
        'description'              => $r->description,
        'parent_name'              => $r->parent_name,
        'parent_igsn'              => $r->parent_igsn,
        'parent_id'                => $r->parent_id,
        'parent_description'       => $r->parent_description,
        'material_type'            => $r->material_type,
        'material_name'            => $r->material_name,
        'material_state'           => $r->material_state,
        'material_note'            => $r->material_note,
        'provenance_formation'     => $r->provenance_formation,
        'provenance_member'        => $r->provenance_member,
        'provenance_submember'     => $r->provenance_submember,
        'provenance_source'        => $r->provenance_source,
        'provenance_loc_street'    => $r->provenance_loc_street,
        'provenance_loc_building'  => $r->provenance_loc_building,
        'provenance_loc_postcode'  => $r->provenance_loc_postcode,
        'provenance_loc_city'      => $r->provenance_loc_city,
        'provenance_loc_state'     => $r->provenance_loc_state,
        'provenance_loc_country'   => $r->provenance_loc_country,
        'provenance_loc_latitude'  => $r->provenance_loc_latitude,
        'provenance_loc_longitude' => $r->provenance_loc_longitude,
        'texture_bedding'          => $r->texture_bedding,
        'texture_lineation'        => $r->texture_lineation,
        'texture_foliation'        => $r->texture_foliation,
        'texture_fault'            => $r->texture_fault,
        '_sample_json'             => $r->sample_json,
    );
}

/**
 * @internal — composition rows for one parent sample, preserving insert
 * order via the pkey sort.
 */
function _migration_exp_composition($db, $samplePkey) {
    $rows = $db->get_results_prepared("
        SELECT mineral, fraction, unit, grainsize
        FROM straboexp.sample_composition
        WHERE sample_pkey = $1
        ORDER BY pkey
    ", array($samplePkey));
    if (!$rows) return array();
    $out = array();
    $i = 0;
    foreach ($rows as $r) {
        $out[] = array(
            'mineral'       => (string)$r->mineral,
            'other_mineral' => null,            // not in source schema
            'fraction'      => $r->fraction,
            'unit'          => $r->unit,
            'grainsize'     => $r->grainsize,
            'ordering'      => $i++,
        );
    }
    return $out;
}

/**
 * @internal
 */
function _migration_exp_parameters($db, $samplePkey) {
    $rows = $db->get_results_prepared("
        SELECT control, value, unit, prefix, note
        FROM straboexp.sample_parameter
        WHERE sample_pkey = $1
        ORDER BY pkey
    ", array($samplePkey));
    if (!$rows) return array();
    $out = array();
    $i = 0;
    foreach ($rows as $r) {
        $out[] = array(
            'control'       => (string)$r->control,
            'other_control' => null,            // not in source schema
            'value'         => $r->value,
            'unit'          => $r->unit,
            'prefix'        => $r->prefix,
            'note'          => $r->note,
            'ordering'      => $i++,
        );
    }
    return $out;
}

/**
 * @internal — documents come from the polymorphic straboexp.document; only
 * rows with sample_pkey set belong to a sample (§3.3).
 */
function _migration_exp_documents($db, $samplePkey) {
    $rows = $db->get_results_prepared("
        SELECT uuid, type, other_type, format, other_format,
               id AS document_id, path, original_filename, description
        FROM straboexp.document
        WHERE sample_pkey = $1
        ORDER BY pkey
    ", array($samplePkey));
    if (!$rows) return array();
    $out = array();
    $i = 0;
    foreach ($rows as $r) {
        // sample_documents.uuid is NOT NULL — backstop to the path if a doc
        // row never got one (shouldn't happen post-2025, but be defensive).
        $uuid = !empty($r->uuid) ? (string)$r->uuid
              : (!empty($r->path) ? (string)$r->path : '');
        if ($uuid === '') {
            continue;  // unrecoverable — skip rather than violate NOT NULL
        }
        $out[] = array(
            'uuid'              => $uuid,
            'type'              => $r->type,
            'other_type'        => $r->other_type,
            'format'            => $r->format,
            'other_format'      => $r->other_format,
            'path'              => $r->path,
            'document_id'       => $r->document_id,
            'original_filename' => $r->original_filename,
            'description'       => $r->description,
            'ordering'          => $i++,
        );
    }
    return $out;
}

}
