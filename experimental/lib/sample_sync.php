<?php
/**
 * File: sample_sync.php
 * Description: Normalize a StraboExperimental sample JSON object into
 *              straboexp.sample + sample_composition + sample_parameter +
 *              document rows. Single source of truth for the JSON-to-rows
 *              projection used by save_experiment.php and the backfill tool.
 *
 * The experiment-to-sample relationship is 1:1 (one sample per experiment),
 * which makes idempotency anchor-able on experiment_pkey alone: on update,
 * an existing sample row is preserved (including its strabo_id) and the
 * children are replaced.
 *
 * @package    StraboExperimental
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/../../samplesdb/services/StraboSamplesService.php';

if (!function_exists('exp_sample_sync')) {

/**
 * Synchronize the normalized sample rows for an experiment.
 *
 * Writes the projected sample data to:
 *   1. straboexp.sample + sample_composition + sample_parameter + document
 *      (the existing Thread 2 normalization — unchanged behavior)
 *   2. strabosamples.* via StraboSamplesService::upsertSample (new in
 *      samples/exp-integration). The strabo_id minted/preserved by step
 *      1 is the cross-system sample id; spine values are projected from
 *      the same source fields the §10 migration uses.
 *
 * Phase-1-cutover note: step 2 writes to a schema that doesn't exist on
 * production until the StraboSamples Phase 1 cutover runs. This branch
 * MUST NOT ship to develop/PROD ahead of cutover; see memory
 * project_strabosamples_phase1_cutover_deferred + the branch isolation
 * rule. On dev (post-Phase-1-schema-apply), step 2 writes cleanly.
 *
 * @param object   $db              StraboDbPostgreSQL handle
 * @param object   $uuid_gen        UUID class with v4() method
 * @param int      $experiment_pkey Owning experiment row's pkey
 * @param int      $userpkey        Owning user pkey
 * @param mixed    $sample          Sample object from experiment JSON (stdClass or null/empty)
 * @param object   $neodb           Optional Neo4j handle (unused for Experimental — kept for signature parity with the other subsystems' integrations). Defaults to null.
 * @return string|null              The strabo_id of the synced sample, or null if no sample
 */
function exp_sample_sync($db, $uuid_gen, $experiment_pkey, $userpkey, $sample, $neodb = null) {

    // Empty-sample case: ensure no normalized rows linger.
    // FK cascades handle composition / parameter / document children.
    if (empty($sample) || !is_object($sample)) {
        // Grab the strabo_id before deletion so we can mirror the
        // removal into strabosamples.*.
        $existing = $db->get_row_prepared(
            "SELECT strabo_id FROM straboexp.sample WHERE experiment_pkey = $1",
            array($experiment_pkey)
        );
        $strabo_id_to_remove = ($existing && !empty($existing->strabo_id)) ? $existing->strabo_id : null;

        $db->prepare_query(
            "DELETE FROM straboexp.sample WHERE experiment_pkey = $1",
            array($experiment_pkey)
        );

        if ($strabo_id_to_remove !== null) {
            $svc = new StraboSamplesService($db, $neodb);
            $svc->setUserpkey($userpkey);
            $svc->removeSubsystemSample('experimental', $strabo_id_to_remove, $userpkey);
        }
        return null;
    }

    // Look up the existing sample row (1:1 with experiment).
    $existing = $db->get_row_prepared(
        "SELECT pkey, strabo_id FROM straboexp.sample WHERE experiment_pkey = $1",
        array($experiment_pkey)
    );

    // Pull the spine fields from the JSON. Missing nodes resolve to empty strings,
    // mirroring the legacy insertExperiment() behavior.
    $parent     = isset($sample->parent)             ? $sample->parent             : null;
    $material   = isset($sample->material)           ? $sample->material           : null;
    $matmat     = ($material && isset($material->material))   ? $material->material   : null;
    $provenance = ($material && isset($material->provenance)) ? $material->provenance : null;
    $location   = ($provenance && isset($provenance->location)) ? $provenance->location : null;
    $texture    = ($material && isset($material->texture))    ? $material->texture    : null;

    $name        = isset($sample->name)        ? (string)$sample->name        : '';
    $igsn        = isset($sample->igsn)        ? (string)$sample->igsn        : '';
    $id_str      = isset($sample->id)          ? (string)$sample->id          : '';
    $description = isset($sample->description) ? (string)$sample->description : '';

    $parent_name        = $parent && isset($parent->name)        ? (string)$parent->name        : '';
    $parent_igsn        = $parent && isset($parent->igsn)        ? (string)$parent->igsn        : '';
    $parent_id          = $parent && isset($parent->id)          ? (string)$parent->id          : '';
    $parent_description = $parent && isset($parent->description) ? (string)$parent->description : '';

    $material_type  = $matmat && isset($matmat->type)  ? (string)$matmat->type  : '';
    $material_name  = $matmat && isset($matmat->name)  ? (string)$matmat->name  : '';
    $material_state = $matmat && isset($matmat->state) ? (string)$matmat->state : '';
    $material_note  = $matmat && isset($matmat->note)  ? (string)$matmat->note  : '';

    $prov_formation = $provenance && isset($provenance->formation) ? (string)$provenance->formation : '';
    $prov_member    = $provenance && isset($provenance->member)    ? (string)$provenance->member    : '';
    $prov_submember = $provenance && isset($provenance->submember) ? (string)$provenance->submember : '';
    $prov_source    = $provenance && isset($provenance->source)    ? (string)$provenance->source    : '';

    $loc_street    = $location && isset($location->street)    ? (string)$location->street    : '';
    $loc_building  = $location && isset($location->building)  ? (string)$location->building  : '';
    $loc_postcode  = $location && isset($location->postcode)  ? (string)$location->postcode  : '';
    $loc_city      = $location && isset($location->city)      ? (string)$location->city      : '';
    $loc_state     = $location && isset($location->state)     ? (string)$location->state     : '';
    $loc_country   = $location && isset($location->country)   ? (string)$location->country   : '';
    $loc_latitude  = $location && isset($location->latitude)  ? (string)$location->latitude  : '';
    $loc_longitude = $location && isset($location->longitude) ? (string)$location->longitude : '';

    $tex_bedding   = $texture && isset($texture->bedding)   ? (string)$texture->bedding   : '';
    $tex_lineation = $texture && isset($texture->lineation) ? (string)$texture->lineation : '';
    $tex_foliation = $texture && isset($texture->foliation) ? (string)$texture->foliation : '';
    $tex_fault     = $texture && isset($texture->fault)     ? (string)$texture->fault     : '';

    $sample_json = json_encode($sample, JSON_PRETTY_PRINT);

    if ($existing && !empty($existing->pkey)) {
        $sample_pkey = (int)$existing->pkey;
        $strabo_id   = $existing->strabo_id;

        $db->prepare_query("
            UPDATE straboexp.sample SET
                userpkey = $1,
                name = $2,
                igsn = $3,
                id = $4,
                description = $5,
                parent_name = $6,
                parent_igsn = $7,
                parent_id = $8,
                parent_description = $9,
                material_type = $10,
                material_name = $11,
                material_state = $12,
                material_note = $13,
                provenance_formation = $14,
                provenance_member = $15,
                provenance_submember = $16,
                provenance_source = $17,
                provenance_loc_street = $18,
                provenance_loc_building = $19,
                provenance_loc_postcode = $20,
                provenance_loc_city = $21,
                provenance_loc_state = $22,
                provenance_loc_country = $23,
                provenance_loc_latitude = $24,
                provenance_loc_longitude = $25,
                texture_bedding = $26,
                texture_lineation = $27,
                texture_foliation = $28,
                texture_fault = $29,
                json = $30
            WHERE pkey = $31
        ", array(
            $userpkey,
            $name, $igsn, $id_str, $description,
            $parent_name, $parent_igsn, $parent_id, $parent_description,
            $material_type, $material_name, $material_state, $material_note,
            $prov_formation, $prov_member, $prov_submember, $prov_source,
            $loc_street, $loc_building, $loc_postcode, $loc_city,
            $loc_state, $loc_country, $loc_latitude, $loc_longitude,
            $tex_bedding, $tex_lineation, $tex_foliation, $tex_fault,
            $sample_json,
            $sample_pkey
        ));

        // Replace children. FK cascade isn't sufficient — we need
        // explicit deletes since the parent row stays.
        $db->prepare_query("DELETE FROM straboexp.sample_composition WHERE sample_pkey = $1", array($sample_pkey));
        $db->prepare_query("DELETE FROM straboexp.sample_parameter   WHERE sample_pkey = $1", array($sample_pkey));
        $db->prepare_query("DELETE FROM straboexp.document            WHERE sample_pkey = $1", array($sample_pkey));

    } else {
        $strabo_id   = $uuid_gen->v4();
        $sample_pkey = (int)$db->get_var("SELECT nextval('straboexp.sample_pkey_seq')");

        $db->prepare_query("
            INSERT INTO straboexp.sample (
                pkey, experiment_pkey, userpkey, name, igsn, id, description,
                parent_name, parent_igsn, parent_id, parent_description,
                material_type, material_name, material_state, material_note,
                provenance_formation, provenance_member, provenance_submember, provenance_source,
                provenance_loc_street, provenance_loc_building, provenance_loc_postcode,
                provenance_loc_city, provenance_loc_state, provenance_loc_country,
                provenance_loc_latitude, provenance_loc_longitude,
                texture_bedding, texture_lineation, texture_foliation, texture_fault,
                json, strabo_id
            ) VALUES (
                $1, $2, $3, $4, $5, $6, $7,
                $8, $9, $10, $11,
                $12, $13, $14, $15,
                $16, $17, $18, $19,
                $20, $21, $22, $23, $24, $25, $26, $27,
                $28, $29, $30, $31,
                $32, $33
            )
        ", array(
            $sample_pkey, $experiment_pkey, $userpkey, $name, $igsn, $id_str, $description,
            $parent_name, $parent_igsn, $parent_id, $parent_description,
            $material_type, $material_name, $material_state, $material_note,
            $prov_formation, $prov_member, $prov_submember, $prov_source,
            $loc_street, $loc_building, $loc_postcode,
            $loc_city, $loc_state, $loc_country,
            $loc_latitude, $loc_longitude,
            $tex_bedding, $tex_lineation, $tex_foliation, $tex_fault,
            $sample_json, $strabo_id
        ));
    }

    // Composition rows (from $sample->material->composition[])
    if ($material && !empty($material->composition) && is_array($material->composition)) {
        foreach ($material->composition as $comp) {
            $mineral   = isset($comp->mineral)   ? (string)$comp->mineral   : '';
            $fraction  = isset($comp->fraction)  ? (string)$comp->fraction  : '';
            $unit      = isset($comp->unit)      ? (string)$comp->unit      : '';
            $grainsize = isset($comp->grainsize) ? (string)$comp->grainsize : '';
            $db->prepare_query("
                INSERT INTO straboexp.sample_composition
                    (sample_pkey, userpkey, mineral, fraction, unit, grainsize)
                VALUES ($1, $2, $3, $4, $5, $6)
            ", array($sample_pkey, $userpkey, $mineral, $fraction, $unit, $grainsize));
        }
    }

    // Parameter rows (from $sample->parameters[])
    if (!empty($sample->parameters) && is_array($sample->parameters)) {
        foreach ($sample->parameters as $param) {
            $control = isset($param->control) ? (string)$param->control : '';
            $value   = isset($param->value)   ? (string)$param->value   : '';
            $unit    = isset($param->unit)    ? (string)$param->unit    : '';
            $prefix  = isset($param->prefix)  ? (string)$param->prefix  : '';
            $note    = isset($param->note)    ? (string)$param->note    : '';
            $db->prepare_query("
                INSERT INTO straboexp.sample_parameter
                    (sample_pkey, userpkey, control, value, unit, prefix, note)
                VALUES ($1, $2, $3, $4, $5, $6, $7)
            ", array($sample_pkey, $userpkey, $control, $value, $unit, $prefix, $note));
        }
    }

    // Document rows (from $sample->documents[]) — bridges file_holdings entries
    // into the normalized document table with sample_pkey set.
    if (!empty($sample->documents) && is_array($sample->documents)) {
        foreach ($sample->documents as $doc) {
            $d_type         = isset($doc->type)              ? (string)$doc->type              : '';
            $d_other_type   = isset($doc->other_type)        ? (string)$doc->other_type        : '';
            $d_format       = isset($doc->format)            ? (string)$doc->format            : '';
            $d_other_format = isset($doc->other_format)      ? (string)$doc->other_format      : '';
            $d_id           = isset($doc->id)                ? (string)$doc->id                : '';
            $d_path         = isset($doc->path)              ? (string)$doc->path              : '';
            $d_description  = isset($doc->description)       ? (string)$doc->description       : '';
            $d_uuid         = isset($doc->uuid)              ? (string)$doc->uuid              : '';
            $d_original     = isset($doc->original_filename) ? (string)$doc->original_filename : $d_path;
            $db->prepare_query("
                INSERT INTO straboexp.document
                    (sample_pkey, userpkey, type, other_type, format, other_format,
                     id, path, description, uuid, original_filename)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)
            ", array(
                $sample_pkey, $userpkey,
                $d_type, $d_other_type, $d_format, $d_other_format,
                $d_id, $d_path, $d_description, $d_uuid, $d_original
            ));
        }
    }

    // ----------------------------------------------------------------------
    // Mirror into strabosamples.* via StraboSamplesService. Projects the
    // same shape the §10 migration produces so a re-upload converges on the
    // same row state.
    // ----------------------------------------------------------------------
    $spineLat = ($loc_latitude  !== '' && is_numeric($loc_latitude))  ? (float)$loc_latitude  : null;
    $spineLng = ($loc_longitude !== '' && is_numeric($loc_longitude)) ? (float)$loc_longitude : null;
    $spineName = $name !== '' ? $name : ($id_str !== '' ? $id_str : null);

    $spine = array(
        'name'                   => $spineName,
        'igsn'                   => ($igsn !== '' ? $igsn : null),
        'description'            => ($description !== '' ? $description : null),
        'notes'                  => null,
        'latitude'               => $spineLat,
        'longitude'              => $spineLng,
        'display_sample_type'    => ($material_type !== '' ? $material_type : null),
        'display_sample_purpose' => null,
    );

    // experimental_data JSONB: snapshot of the projected source columns +
    // raw sample blob for round-trip (mirrors the migration's
    // _migration_exp_row_to_jsonb shape).
    $subsystemData = array(
        'id'                       => $id_str,
        'name'                     => $name,
        'igsn'                     => $igsn,
        'description'              => $description,
        'parent_name'              => $parent_name,
        'parent_igsn'              => $parent_igsn,
        'parent_id'                => $parent_id,
        'parent_description'       => $parent_description,
        'material_type'            => $material_type,
        'material_name'            => $material_name,
        'material_state'           => $material_state,
        'material_note'            => $material_note,
        'provenance_formation'     => $prov_formation,
        'provenance_member'        => $prov_member,
        'provenance_submember'     => $prov_submember,
        'provenance_source'        => $prov_source,
        'provenance_loc_street'    => $loc_street,
        'provenance_loc_building'  => $loc_building,
        'provenance_loc_postcode'  => $loc_postcode,
        'provenance_loc_city'      => $loc_city,
        'provenance_loc_state'     => $loc_state,
        'provenance_loc_country'   => $loc_country,
        'provenance_loc_latitude'  => $loc_latitude,
        'provenance_loc_longitude' => $loc_longitude,
        'texture_bedding'          => $tex_bedding,
        'texture_lineation'        => $tex_lineation,
        'texture_foliation'        => $tex_foliation,
        'texture_fault'            => $tex_fault,
        '_sample_json'             => $sample_json,
    );

    // Reference info — points at the owning experiment. Project / experiment
    // metadata goes into reference_metadata (mirrors migration).
    $expRow = $db->get_row_prepared(
        "SELECT id AS experiment_human_id, uuid AS experiment_uuid, project_pkey
           FROM straboexp.experiment WHERE pkey = $1",
        array($experiment_pkey)
    );
    $projUuid = null;
    if ($expRow && !empty($expRow->project_pkey)) {
        $projUuid = $db->get_var_prepared(
            "SELECT uuid FROM straboexp.project WHERE pkey = $1",
            array((int)$expRow->project_pkey)
        );
    }
    $reference = array(
        'reference_id'         => (string)$experiment_pkey,
        'reference_userpkey'   => $userpkey,
        'reference_metadata'   => array(
            'experiment_pkey' => (int)$experiment_pkey,
            'experiment_id'   => $expRow ? $expRow->experiment_human_id : null,
            'experiment_uuid' => $expRow ? $expRow->experiment_uuid : null,
            'project_pkey'    => ($expRow && $expRow->project_pkey !== null) ? (int)$expRow->project_pkey : null,
            'project_uuid'    => $projUuid,
        ),
    );

    // Project the children straight from the input shape — same projection
    // the migration applies (other_mineral/other_control = null since
    // straboexp source schema doesn't carry them).
    $compOut = array();
    if ($material && !empty($material->composition) && is_array($material->composition)) {
        $i = 0;
        foreach ($material->composition as $c) {
            $mineral = isset($c->mineral) ? (string)$c->mineral : '';
            if ($mineral === '') continue;
            $compOut[] = array(
                'mineral'       => $mineral,
                'other_mineral' => null,
                'fraction'      => isset($c->fraction)  ? $c->fraction  : null,
                'unit'          => isset($c->unit)      ? $c->unit      : null,
                'grainsize'     => isset($c->grainsize) ? $c->grainsize : null,
                'ordering'      => $i++,
            );
        }
    }
    $paramOut = array();
    if (!empty($sample->parameters) && is_array($sample->parameters)) {
        $i = 0;
        foreach ($sample->parameters as $p) {
            $control = isset($p->control) ? (string)$p->control : '';
            if ($control === '') continue;
            $paramOut[] = array(
                'control'       => $control,
                'other_control' => null,
                'value'         => isset($p->value)  ? $p->value  : null,
                'unit'          => isset($p->unit)   ? $p->unit   : null,
                'prefix'        => isset($p->prefix) ? $p->prefix : null,
                'note'          => isset($p->note)   ? $p->note   : null,
                'ordering'      => $i++,
            );
        }
    }
    $docOut = array();
    if (!empty($sample->documents) && is_array($sample->documents)) {
        $i = 0;
        foreach ($sample->documents as $d) {
            $u = isset($d->uuid) ? (string)$d->uuid : '';
            if ($u === '' && isset($d->path)) $u = (string)$d->path;
            if ($u === '') continue;
            $docOut[] = array(
                'uuid'              => $u,
                'type'              => isset($d->type)              ? $d->type              : null,
                'other_type'        => isset($d->other_type)        ? $d->other_type        : null,
                'format'            => isset($d->format)            ? $d->format            : null,
                'other_format'      => isset($d->other_format)      ? $d->other_format      : null,
                'path'              => isset($d->path)              ? $d->path              : null,
                'document_id'       => isset($d->id)                ? $d->id                : null,
                'original_filename' => isset($d->original_filename) ? $d->original_filename : null,
                'description'      => isset($d->description)        ? $d->description       : null,
                'ordering'          => $i++,
            );
        }
    }

    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey($userpkey);
    $svc->upsertSample(
        'experimental',
        $strabo_id,
        $userpkey,
        $spine,
        $subsystemData,
        $reference,
        array(
            'composition' => $compOut,
            'parameters'  => $paramOut,
            'documents'   => $docOut,
        )
    );

    return $strabo_id;
}

}
