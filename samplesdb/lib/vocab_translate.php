<?php
/**
 * File: samplesdb/lib/vocab_translate.php
 * Description: Cross-vocab translator for spine values being pushed back
 *              into StraboField via writeBackFieldSpot() (samples/field-
 *              writeback). The Field spine columns display_sample_type and
 *              display_sample_purpose can hold values from any of three
 *              vocabs:
 *
 *                - Field/Micro shared enum (intact_rock, sediment, …) →
 *                  Field's own values; pass through verbatim.
 *                - Experimental enum (Igneous Rock, Glass, …) → some have a
 *                  natural Field bucket (rocks → intact_rock; soil →
 *                  sediment); the rest have no Field equivalent.
 *                - Free text (the "Other (free text)" escape, or pre-
 *                  existing pollution from before vocab-union-modal) → no
 *                  way to map safely.
 *
 *              Without translation, writeBackFieldSpot would happily push
 *              "Igneous Rock" / "MyCustomThing" / "Glass" straight into
 *              Neo4j sample.material_type, where the Field client would
 *              not recognize the value (its form is a controlled <select>
 *              over the enum). The translator either maps the value to a
 *              Field enum value, or signals "skip" so the writeback drops
 *              that key entirely and logs a translation note.
 *
 *              See also: samplesdb/lib/vocab.php (the modal's union vocab,
 *              which feeds this translator's source enums).
 */

require_once __DIR__ . '/vocab.php';

/**
 * Translate a spine column value to the form Field expects, or return a
 * skip directive. Non-enum spine columns (name, igsn, description, notes)
 * always pass through unchanged.
 *
 * @param string $spineColumn  e.g. 'display_sample_type', 'name'
 * @param mixed  $value        the new spine value (string|null)
 * @return array  status ∈ {'passthrough','translated','no_mapping','free_text'}
 *                'passthrough'  ⇒ ['value' => $value]            (write as-is)
 *                'translated'   ⇒ ['value' => mapped, 'from' => $value]
 *                'no_mapping'   ⇒ ['reason' => 'experimental_value_no_field_bucket']
 *                'free_text'    ⇒ (no extra keys)
 *
 *                The caller drops the key from the Field write on
 *                'no_mapping' and 'free_text' (and should log the skip).
 */
function samples_vocab_translate_to_field($spineColumn, $value)
{
    // Only the two enum-bound spine columns need translation; everything
    // else (name, igsn, description, notes) is free text on both sides.
    if (!in_array($spineColumn, array('display_sample_type', 'display_sample_purpose'), true)) {
        return array('status' => 'passthrough', 'value' => $value);
    }
    // Null/empty: passthrough so the existing writeback "drop key on empty"
    // logic handles it consistently with non-enum fields.
    if ($value === null || $value === '') {
        return array('status' => 'passthrough', 'value' => $value);
    }
    if ($spineColumn === 'display_sample_type') {
        return _samples_vocab_translate_material_type($value);
    }
    return _samples_vocab_translate_sample_purpose($value);
}

/**
 * Field's material_type enum (matches Micro's materialType byte-for-byte
 * and is the "Common" group in vocab.php). 'other' is the Field/Micro
 * indirection trigger and is kept here as valid passthrough — if the
 * spine carries literal 'other', Field knows what to do (it'll display
 * the existing other_material_type free-text field unchanged).
 */
function _samples_vocab_field_material_enum()
{
    return array('intact_rock', 'fragmented_roc', 'sediment', 'tephra', 'carbon_or_animal', 'other');
}

/**
 * Field's main_sampling_purpose enum.
 */
function _samples_vocab_field_purpose_enum()
{
    return array(
        'fabric___micro', 'petrology', 'geochronology', 'geochemistry',
        'active_eruptio', 'micro_beam', 'particle_size', 'dre_density',
        'clast_density', 'particle_shape', 'cryptotephra', 'other',
    );
}

/**
 * Best-effort Experimental → Field bucket map for material types.
 * Conservative: only map where there's a clear semantic bucket. The
 * rest stay 'no_mapping' so writeback skips the field rather than
 * miscategorizing.
 *
 * Rocks bucket: any of the three rock-classification Experimental
 * values bucket to intact_rock (intact, in-place rock material). Field
 * has no separate igneous/sedimentary/metamorphic distinction at the
 * material_type level — that's a sub-classification for petrology.
 */
function _samples_vocab_exp_to_field_material_map()
{
    return array(
        'Igneous Rock'     => 'intact_rock',
        'Sedimentary Rock' => 'intact_rock',
        'Metamorphic Rock' => 'intact_rock',
        'Soil'             => 'sediment',
        'Mineral'          => 'intact_rock',
        // 'Glass', 'Ice', 'Ceramic', 'Plastic', 'Metal', 'Epos
        // Lithologies', 'Standards', 'Commodity' all map to no_mapping —
        // these are laboratory / data-infrastructure values without a
        // Field-side equivalent.
    );
}

function _samples_vocab_translate_material_type($value)
{
    if (in_array($value, _samples_vocab_field_material_enum(), true)) {
        return array('status' => 'passthrough', 'value' => $value);
    }
    $map = _samples_vocab_exp_to_field_material_map();
    if (isset($map[$value])) {
        return array('status' => 'translated', 'value' => $map[$value], 'from' => $value);
    }
    // Distinguish "known Experimental vocab without a Field bucket" from
    // "not in any vocab at all" so the changelog note carries the right
    // reason for future audits.
    $expGroup = samples_vocab_material_types()['Experimental'];
    if (array_key_exists($value, $expGroup)) {
        return array('status' => 'no_mapping', 'reason' => 'experimental_value_no_field_bucket');
    }
    return array('status' => 'free_text');
}

function _samples_vocab_translate_sample_purpose($value)
{
    if (in_array($value, _samples_vocab_field_purpose_enum(), true)) {
        return array('status' => 'passthrough', 'value' => $value);
    }
    // Experimental has no purpose concept, so anything outside Field's
    // enum is by definition free-text (the modal's "Other" escape or
    // pre-existing pollution).
    return array('status' => 'free_text');
}
