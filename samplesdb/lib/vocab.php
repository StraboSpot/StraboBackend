<?php
/**
 * File: samplesdb/lib/vocab.php
 * Description: Canonical controlled vocabularies for sample Material Type
 *              and Sampling Purpose, used by the StraboSamples UI dropdowns
 *              and (next sub-branch) the writeback translator.
 *
 * Audit findings (2026-06-05) — see PROJECT_STATUS:
 *   - StraboField material_type and StraboMicro materialType are identical
 *     6-value enums (intact_rock, fragmented_roc, sediment, tephra,
 *     carbon_or_animal, other).
 *   - StraboField main_sampling_purpose and StraboMicro mainSamplingPurpose
 *     are identical 12-value enums.
 *   - StraboExperimental Material Type is a disjoint 13-value lab-oriented
 *     vocab (Glass, Ice, Igneous Rock, …). Experimental has NO purpose
 *     concept; only Material Type appears in the Experimental dropdown.
 *
 * Storage values are kept verbatim from the canonical subsystem vocab even
 * when ugly (`fragmented_roc`, `active_eruptio`, `fabric___micro`) so that
 * spine values flow byte-equal through the existing upload paths and so
 * that the writeback translator can pattern-match against them. Display
 * labels are the human-readable form.
 *
 * `other` enum: Field/Micro both have an `other` value in their material
 * and purpose enums, which on those clients triggers a free-text
 * `othermaterialtype` / `other_sampling_purpose` fallback. In StraboSamples
 * we collapse that indirection: the modal's "Other (free text)" option
 * (sentinel VOCAB_OTHER_SENTINEL) lets the user type a value directly into
 * the spine column.
 */

define('VOCAB_OTHER_SENTINEL', '__other__');

/**
 * Material Type vocab, grouped for an <optgroup> dropdown. Order within
 * each group preserved from the source schemas.
 *
 * @return array<string, array<string, string>>   optgroup label => [value => display label]
 */
function samples_vocab_material_types()
{
    return array(
        'Common (Field / Micro)' => array(
            'intact_rock'      => 'Intact Rock',
            'fragmented_roc'   => 'Fragmented Rock',
            'sediment'         => 'Sediment',
            'tephra'           => 'Tephra',
            'carbon_or_animal' => 'Carbon or Animal',
        ),
        'Experimental' => array(
            'Glass'             => 'Glass',
            'Ice'               => 'Ice',
            'Ceramic'           => 'Ceramic',
            'Plastic'           => 'Plastic',
            'Metal'             => 'Metal',
            'Soil'              => 'Soil',
            'Mineral'           => 'Mineral',
            'Igneous Rock'      => 'Igneous Rock',
            'Sedimentary Rock'  => 'Sedimentary Rock',
            'Metamorphic Rock'  => 'Metamorphic Rock',
            'Epos Lithologies'  => 'Epos Lithologies',
            'Standards'         => 'Standards',
            'Commodity'         => 'Commodity',
        ),
    );
}

/**
 * Sampling Purpose vocab. Field/Micro share these values; Experimental has
 * no purpose concept. Flat list (no optgroup) since there's only one
 * source group.
 *
 * @return array<string, string>   value => display label
 */
function samples_vocab_sample_purposes()
{
    return array(
        'fabric___micro'  => 'Fabric / Microstructure',
        'petrology'       => 'Petrology',
        'geochronology'   => 'Geochronology',
        'geochemistry'    => 'Geochemistry',
        'active_eruptio'  => 'Active Eruption',
        'micro_beam'      => 'Microbeam',
        'particle_size'   => 'Particle Size',
        'dre_density'     => 'DRE Density',
        'clast_density'   => 'Clast Density',
        'particle_shape'  => 'Particle Shape',
        'cryptotephra'    => 'Cryptotephra',
    );
}

/**
 * Flat value => display-label map for Material Type (optgroup structure
 * collapsed). Used by the tabular import/export path, which needs plain
 * lookups rather than <optgroup> rendering.
 *
 * @return array<string, string>
 */
function samples_vocab_material_flat()
{
    $flat = array();
    foreach (samples_vocab_material_types() as $group => $opts) {
        foreach ($opts as $value => $label) {
            $flat[$value] = $label;
        }
    }
    return $flat;
}

/**
 * Case-insensitive resolver: accepts either a storage value or a display
 * label and returns the canonical storage value, or NULL when the input
 * matches neither. Shared by both vocab columns on the tabular import path.
 *
 * @param string $input     raw cell value (already trimmed)
 * @param array  $flatVocab value => label map
 * @return string|null      canonical storage value, or null
 */
function samples_vocab_resolve($input, array $flatVocab)
{
    $needle = mb_strtolower($input);
    foreach ($flatVocab as $value => $label) {
        if (mb_strtolower($value) === $needle || mb_strtolower($label) === $needle) {
            return $value;
        }
    }
    return null;
}

/**
 * Render a complete <select> for the material type dropdown, including
 * the "Other (free text)" escape sentinel. The companion text input is
 * NOT rendered here — the caller places it adjacent and toggles its
 * visibility from JS when the select value === VOCAB_OTHER_SENTINEL.
 *
 * @param string $id       <select> id attribute
 * @param string $current  current value to pre-select (empty for new)
 * @return string  HTML
 */
function samples_vocab_render_material_select($id, $current = '')
{
    $html = '<select id="' . htmlspecialchars($id, ENT_QUOTES) . '">';
    $html .= '<option value="">&mdash; Select &mdash;</option>';
    foreach (samples_vocab_material_types() as $group => $opts) {
        $html .= '<optgroup label="' . htmlspecialchars($group, ENT_QUOTES) . '">';
        foreach ($opts as $value => $label) {
            $selected = ($value === $current) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES) . '"' . $selected . '>'
                  .  htmlspecialchars($label) . '</option>';
        }
        $html .= '</optgroup>';
    }
    $html .= '<option value="' . VOCAB_OTHER_SENTINEL . '">Other (free text)&hellip;</option>';
    $html .= '</select>';
    return $html;
}

/**
 * Render the Sampling Purpose <select>. Flat list + Other sentinel.
 */
function samples_vocab_render_purpose_select($id, $current = '')
{
    $html = '<select id="' . htmlspecialchars($id, ENT_QUOTES) . '">';
    $html .= '<option value="">&mdash; Select &mdash;</option>';
    foreach (samples_vocab_sample_purposes() as $value => $label) {
        $selected = ($value === $current) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES) . '"' . $selected . '>'
              .  htmlspecialchars($label) . '</option>';
    }
    $html .= '<option value="' . VOCAB_OTHER_SENTINEL . '">Other (free text)&hellip;</option>';
    $html .= '</select>';
    return $html;
}
