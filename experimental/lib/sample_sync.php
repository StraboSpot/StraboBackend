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

if (!function_exists('exp_sample_sync')) {

/**
 * Synchronize the normalized sample rows for an experiment.
 *
 * @param object   $db             StraboDbPostgreSQL handle
 * @param object   $uuid_gen       UUID class with v4() method
 * @param int      $experiment_pkey Owning experiment row's pkey
 * @param int      $userpkey       Owning user pkey
 * @param mixed    $sample         Sample object from experiment JSON (stdClass or null/empty)
 * @return string|null             The strabo_id of the synced sample, or null if no sample
 */
function exp_sample_sync($db, $uuid_gen, $experiment_pkey, $userpkey, $sample) {

    // Empty-sample case: ensure no normalized rows linger.
    // FK cascades handle composition / parameter / document children.
    if (empty($sample) || !is_object($sample)) {
        $db->prepare_query(
            "DELETE FROM straboexp.sample WHERE experiment_pkey = $1",
            array($experiment_pkey)
        );
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
        // Reuse a strabo_id already embedded in the sample JSON: the create
        // path pre-mints it so the experiment JSON can carry it before this
        // row is written, and experiments saved while the FK-ordering bug
        // was live (create ran this sync BEFORE the experiment insert, so
        // this INSERT failed) have the embedded id but no row — reusing it
        // heals them onto the same id instead of minting a divergent one.
        // Never adopt an id another sample row already claims.
        $strabo_id = null;
        if (!empty($sample->strabo_id)) {
            $candidate = strtolower(trim((string)$sample->strabo_id));
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $candidate)) {
                $claimed = $db->get_var_prepared(
                    "SELECT 1 FROM straboexp.sample WHERE strabo_id = $1",
                    array($candidate)
                );
                if (!$claimed) {
                    $strabo_id = $candidate;
                }
            }
        }
        if ($strabo_id === null) {
            $strabo_id = $uuid_gen->v4();
        }
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

    return $strabo_id;
}

}
