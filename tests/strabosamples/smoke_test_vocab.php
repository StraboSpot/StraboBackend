<?php
/**
 * File: smoke_test_vocab.php
 * Description: Unit-shaped smoke test for samplesdb/lib/vocab.php. Asserts
 *              the controlled vocabularies + render helpers used by the
 *              create-sample modal (samples/vocab-union-modal) and (next
 *              sub-branch) the writeback translator.
 *
 *              Hermetic: no DB / no HTTP. Pure function tests against the
 *              vocab module.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_vocab.php
 */

require_once '/srv/app/www/samplesdb/lib/vocab.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

echo "\n[vocab] Sentinel + module shape\n";
check('VOCAB_OTHER_SENTINEL is defined', defined('VOCAB_OTHER_SENTINEL'));
check('VOCAB_OTHER_SENTINEL is __other__', VOCAB_OTHER_SENTINEL === '__other__');
check('samples_vocab_material_types is callable', function_exists('samples_vocab_material_types'));
check('samples_vocab_sample_purposes is callable', function_exists('samples_vocab_sample_purposes'));
check('samples_vocab_render_material_select is callable', function_exists('samples_vocab_render_material_select'));
check('samples_vocab_render_purpose_select is callable', function_exists('samples_vocab_render_purpose_select'));

echo "\n[vocab] Material Type vocab content\n";
$mats = samples_vocab_material_types();
check('material returns 2 optgroups', is_array($mats) && count($mats) === 2);
$common = isset($mats['Common (Field / Micro)']) ? $mats['Common (Field / Micro)'] : array();
$exp    = isset($mats['Experimental'])             ? $mats['Experimental']             : array();
check('Common group has 5 entries (Field/Micro 6 minus collapsed "other")',
    count($common) === 5);
check('Common contains intact_rock',      array_key_exists('intact_rock', $common));
check('Common contains fragmented_roc',   array_key_exists('fragmented_roc', $common));
check('Common contains sediment',         array_key_exists('sediment', $common));
check('Common contains tephra',           array_key_exists('tephra', $common));
check('Common contains carbon_or_animal', array_key_exists('carbon_or_animal', $common));
check('Common does NOT contain bare "other" (collapsed into Other sentinel)',
    !array_key_exists('other', $common));
check('Experimental has 13 entries', count($exp) === 13);
check('Experimental contains Igneous Rock',      array_key_exists('Igneous Rock', $exp));
check('Experimental contains Sedimentary Rock',  array_key_exists('Sedimentary Rock', $exp));
check('Experimental contains Metamorphic Rock',  array_key_exists('Metamorphic Rock', $exp));
check('Experimental contains Glass',             array_key_exists('Glass', $exp));
check('Experimental contains Standards',         array_key_exists('Standards', $exp));

echo "\n[vocab] Sampling Purpose vocab content\n";
$purp = samples_vocab_sample_purposes();
check('purposes returns 11 entries (Field/Micro 12 minus collapsed "other")',
    is_array($purp) && count($purp) === 11);
check('purposes contains geochemistry',      array_key_exists('geochemistry', $purp));
check('purposes contains geochronology',     array_key_exists('geochronology', $purp));
check('purposes contains fabric___micro',    array_key_exists('fabric___micro', $purp));
check('purposes contains petrology',         array_key_exists('petrology', $purp));
check('purposes contains active_eruptio',    array_key_exists('active_eruptio', $purp));
check('purposes does NOT contain bare "other"', !array_key_exists('other', $purp));

echo "\n[vocab] No value collisions across material / purpose vocab\n";
$matValues = array();
foreach ($mats as $opts) { $matValues = array_merge($matValues, array_keys($opts)); }
check('material vocab has no duplicate keys across groups',
    count($matValues) === count(array_unique($matValues)));
check('material vocab does not contain sentinel value',
    !in_array(VOCAB_OTHER_SENTINEL, $matValues, true));
check('purpose vocab does not contain sentinel value',
    !in_array(VOCAB_OTHER_SENTINEL, array_keys($purp), true));

echo "\n[vocab] render_material_select HTML\n";
$html = samples_vocab_render_material_select('test-mat');
check('opens <select id="test-mat">',           strpos($html, '<select id="test-mat">') === 0);
check('closes </select>',                       substr($html, -strlen('</select>')) === '</select>');
check('has placeholder option',                 strpos($html, '<option value="">') !== false);
check('contains optgroup Common (escaped)',     strpos($html, '<optgroup label="Common (Field / Micro)">') !== false);
check('contains optgroup Experimental',         strpos($html, '<optgroup label="Experimental">') !== false);
check('contains intact_rock option',            strpos($html, '<option value="intact_rock">Intact Rock</option>') !== false);
check('contains Igneous Rock option',           strpos($html, '<option value="Igneous Rock">Igneous Rock</option>') !== false);
check('contains the Other sentinel option',     strpos($html, 'value="' . VOCAB_OTHER_SENTINEL . '"') !== false);
$selectedHtml = samples_vocab_render_material_select('test-mat', 'tephra');
check('pre-selects current value',              strpos($selectedHtml, '<option value="tephra" selected>Tephra</option>') !== false);
$noSelHtml = samples_vocab_render_material_select('test-mat', 'no-match-value');
check('non-matching current does not break render',
    substr_count($noSelHtml, ' selected') === 0);

echo "\n[vocab] render_purpose_select HTML\n";
$phtml = samples_vocab_render_purpose_select('test-p');
check('purpose opens <select id="test-p">',     strpos($phtml, '<select id="test-p">') === 0);
check('purpose contains geochemistry option',   strpos($phtml, '<option value="geochemistry">Geochemistry</option>') !== false);
check('purpose contains Other sentinel option', strpos($phtml, 'value="' . VOCAB_OTHER_SENTINEL . '"') !== false);
check('purpose has NO optgroup tags (flat)',    strpos($phtml, '<optgroup') === false);
$pSel = samples_vocab_render_purpose_select('test-p', 'fabric___micro');
check('purpose pre-selects current value',
    strpos($pSel, '<option value="fabric___micro" selected>Fabric / Microstructure</option>') !== false);

echo "\n----------------------------------------\n";
if (empty($failures)) {
    echo "All checks PASS\n";
    exit(0);
}
echo count($failures) . " FAILED:\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
