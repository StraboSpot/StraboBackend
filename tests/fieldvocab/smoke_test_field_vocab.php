<?php
/**
 * File: smoke_test_field_vocab.php
 * Description: Smoke test for the StraboField choice map (Field choice
 *              translation Phase 1): FieldVocabBuilder, FieldVocab and
 *              includes/fieldvocab/sync.php.
 *
 *              Coverage:
 *                1. Baseline map: loads with no synced map, validates, source
 *                2. Pins: option_13 / option_7 / option_8, truncation,
 *                   per-field collision (thin), quality per orientation type
 *                3. Values: arrays, deprecated pet string OR array, numeric
 *                   names, null / bool untouched
 *                4. Fallbacks: unknown name, non-choice field, 'app', callable,
 *                   duplicate names in one list (tidal_flat, other) left as-is
 *                5. Resolver (formsFor) per family + multi-form agreement
 *                6. Builder units: registry parse, trim, merge growth
 *                   (retired choice / field / form, relabel, comeback),
 *                   validate, encode numeric names as objects
 *                7. sync.php end to end, offline: a forms folder rebuilt from
 *                   the baseline -> first run, no-op diff, relabel with .prev
 *                   copy, invalid release keeps the live map (exit 1)
 *                8. (--network) sync.php --dry-run against GitHub
 *
 *              Hermetic: all files under a temp dir (FIELDVOCAB_DATA_DIR),
 *              removed at the end. No database.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/fieldvocab/smoke_test_field_vocab.php [--network]
 */

require_once '/srv/app/www/includes/fieldvocab/FieldVocabBuilder.php';
require_once '/srv/app/www/includes/fieldvocab/FieldVocab.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) { $failures[] = $label; }
}
function section($t) { echo "\n== $t\n"; }
function rrmdir($d) {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

$network = in_array('--network', $argv, true);
$tmp = sys_get_temp_dir() . '/fieldvocab_smoke_' . getmypid();
mkdir($tmp, 0775, true);
$dataDir = "$tmp/data";
putenv("FIELDVOCAB_DATA_DIR=$dataDir");
$sync = '/srv/app/www/includes/fieldvocab/sync.php';

try {
    // ------------------------------------------------------------------ 1
    section('1. Baseline map');
    FieldVocab::setMap(null);
    $src = FieldVocab::source();
    check('no synced map -> origin baseline', $src['origin'] === 'baseline');
    check('baseline has a release tag + 40-char sha', preg_match('/^v\d+\.\d+\.\d+$/', (string)$src['tag']) && strlen((string)$src['sha']) === 40);
    $base = FieldVocab::map();
    check('baseline validates', FieldVocabBuilder::validate($base) === array());
    check('baseline has the 3 orientation forms', isset($base['forms']['measurement.planar_orientation'], $base['forms']['measurement.linear_orientation'], $base['forms']['measurement.tabular_orientation']));
    check('measurement_bulk is not in the map', !isset($base['forms']['measurement_bulk.planar_orientation']));

    // ------------------------------------------------------------------ 2
    section('2. Pins');
    $planar = FieldVocab::formsFor('orientation', array('type' => 'planar_orientation'));
    $tabular = FieldVocab::formsFor('orientation', array('type' => 'tabular_orientation'));
    check('option_13 -> joint (planar feature_type)', FieldVocab::label($planar, 'feature_type', 'option_13') === 'joint');
    check("option_7 -> R'-fracture (fracture_type)", FieldVocab::label($planar, 'fracture_type', 'option_7') === "R'-fracture");
    check('option_8 -> T-fracture (fracture_type)', FieldVocab::label($planar, 'fracture_type', 'option_8') === 'T-fracture');
    check('truncation basaltic_andes -> basaltic-andesite (pet.volcanic)', FieldVocab::label(FieldVocab::formsFor('pet.igneous', array('igneous_rock_class' => 'volcanic')), 'volcanic_rock_type', 'basaltic_andes') === 'basaltic-andesite');
    check('truncation sedimentary_fe -> sedimentary feature', FieldVocab::label($planar, 'movement_justification', 'sedimentary_fe') === 'sedimentary feature');
    $lith = FieldVocab::formsFor('sed.lithologies');
    check('collision: thin in bedding_thickness -> thin (3-10 cm)', FieldVocab::label($lith, 'bedding_thickness', 'thin') === 'thin (3-10 cm)');
    check('collision: thin in laminae_thickness -> thin (0.1-0.3 cm)', FieldVocab::label($lith, 'laminae_thickness_i_select_more_than_one', 'thin') === 'thin (0.1-0.3 cm)');
    check('quality 5 planar -> 5 - excellent', FieldVocab::label($planar, 'quality', '5') === '5 - excellent');
    check('quality 5 tabular -> 5 - best - accurate', FieldVocab::label($tabular, 'quality', '5') === '5 - best - accurate');
    $padFound = false;
    foreach ($base['forms'] as $f) foreach ($f['fields'] as $fld) foreach ($fld['choices'] as $l) { if ($l !== trim($l)) $padFound = true; }
    check('no label in the map has leading/trailing space', !$padFound);

    // ------------------------------------------------------------------ 3
    section('3. Values');
    check('int name 5 == string "5"', FieldVocab::label($planar, 'quality', 5) === '5 - excellent');
    check('array keeps shape + order', FieldVocab::label($planar, 'fracture_type', array('option_8', 'option_7')) === array('T-fracture', "R'-fracture"));
    check('labelText joins with ", "', FieldVocab::labelText($planar, 'fracture_type', array('option_8', 'option_7')) === "T-fracture, R'-fracture");
    check('labelText custom separator', FieldVocab::labelText($planar, 'fracture_type', array('option_8', 'option_7'), '; ') === "T-fracture; R'-fracture");
    $pet = FieldVocab::formsFor('pet');
    check('deprecated pet: string value', FieldVocab::label($pet, 'volcanic_rock_type', 'basaltic_andes') === 'basaltic-andesite');
    check('deprecated pet: array value', FieldVocab::label($pet, 'volcanic_rock_type', array('basaltic_andes')) === array('basaltic-andesite'));
    check('null untouched', FieldVocab::label($planar, 'feature_type', null) === null);
    check('bool untouched', FieldVocab::label($planar, 'feature_type', true) === true);
    check('empty array -> empty array', FieldVocab::label($planar, 'fracture_type', array()) === array());

    // ------------------------------------------------------------------ 4
    section('4. Fallbacks');
    check('unknown name -> raw by default', FieldVocab::label($planar, 'feature_type', 'no_such_thing') === 'no_such_thing');
    check("unknown name -> 'app' rule", FieldVocab::label($planar, 'feature_type', 'no_such_thing', 'app') === 'no such thing');
    check('unknown name -> callable', FieldVocab::label($planar, 'feature_type', 'no_such_thing', 'strtoupper') === 'NO_SUCH_THING');
    check('non-choice field (strike) -> raw', FieldVocab::label($planar, 'strike', 'option_13') === 'option_13');
    check('empty form list -> raw', FieldVocab::label(array(), 'feature_type', 'option_13') === 'option_13');
    check('unknown orientation type -> no forms', FieldVocab::formsFor('orientation', array('type' => 'bogus')) === array());
    check('isChoiceField feature_type', FieldVocab::isChoiceField($planar, 'feature_type'));
    check('isChoiceField strike is false', !FieldVocab::isChoiceField($planar, 'strike'));
    check('isMultiple movement_justification', FieldVocab::isMultiple($planar, 'movement_justification'));
    $interp = FieldVocab::formsFor('sed.interpretations');
    check('duplicate name tidal_flat in carbonate -> raw', FieldVocab::label($interp, 'carbonate', 'tidal_flat') === 'tidal_flat');
    check('tidal_flat in clastic (unique there) -> Tidal flat', FieldVocab::label($interp, 'clastic', 'tidal_flat') === 'Tidal flat');
    $dia = FieldVocab::formsFor('sed.diagenesis');
    $shapeField = null;
    foreach ($base['forms']['sed.diagenesis']['fields'] as $fn => $fld) { if (isset($fld['ambiguous']['other'])) $shapeField = $fn; }
    check('diagenesis duplicate "other" recorded as ambiguous', $shapeField !== null);
    check('diagenesis duplicate "other" -> raw', $shapeField !== null && FieldVocab::label($dia, $shapeField, 'other') === 'other');

    // ------------------------------------------------------------------ 5
    section('5. Resolver');
    check('_3d_structures fault', FieldVocab::formsFor('_3d_structures', array('type' => 'fault')) === array('_3d_structures.fault'));
    check('fabrics metamorphic_rock', FieldVocab::formsFor('fabrics', array('type' => 'metamorphic_rock')) === array('fabrics.metamorphic_rock'));
    check('pet.igneous plutonic', FieldVocab::formsFor('pet.igneous', array('igneous_rock_class' => 'plutonic')) === array('pet.plutonic'));
    check('pet.igneous no class -> both', FieldVocab::formsFor('pet.igneous', array()) === array('pet.plutonic', 'pet.volcanic'));
    check('sed.bedding interbedded', FieldVocab::formsFor('sed.bedding', null, 'interbedded') === array('sed.bedding_shared_interbedded'));
    check('sed.bedding package', FieldVocab::formsFor('sed.bedding', null, 'package_succe') === array('sed.bedding_shared_package'));
    check('tags geologic unit', FieldVocab::formsFor('tags', array('type' => 'geologic_unit')) === array('project.geologic_unit', 'project.tags'));
    check('tags concept', FieldVocab::formsFor('tags', array('type' => 'concept')) === array('project.tags'));
    check('unknown family -> none', FieldVocab::formsFor('nope') === array());
    $missing = array();
    foreach (array('pet.metamorphic', 'pet.alteration_or', 'pet.fault', 'pet.minerals', 'pet.reactions', 'pet', 'sed', 'sed.lithologies', 'sed.structures',
        'sed.interpretations', 'sed.diagenesis', 'sed.fossils', 'sed.interval', 'sed.strat_section', 'sed.bedding.beds', 'tephra', 'samples', 'images',
        'earthquakes', 'outcrop_summaries', 'site_safety', 'trace', 'surface_feature', 'reports', 'project_description', 'geography') as $fam) {
        foreach (FieldVocab::formsFor($fam) as $fk) { if (!isset($base['forms'][$fk])) $missing[] = "$fam -> $fk"; }
    }
    check('every fixed family names forms that exist in the map' . ($missing ? ' (' . implode(', ', $missing) . ')' : ''), !$missing);
    check('sed character (interval form)', FieldVocab::label(FieldVocab::formsFor('sed'), 'character', 'package_succe') === 'package (succession of beds)');
    check('multi-form: glass only in composition tab -> glass', FieldVocab::label($lith, 'volcaniclastic_type', 'glass') === 'glass');
    check('multi-form: shared name, labels agree -> label', FieldVocab::label($lith, 'volcaniclastic_type', 'volcanic_mudst') === 'volcanic mudstone');
    // Disagreeing forms fall back.
    FieldVocab::setMap(array('schema' => 1, 'forms' => array(
        'a.x' => array('file' => 'a', 'fields' => array('f' => array('type' => 'select_one', 'list' => 'l', 'choices' => array('n' => 'one')))),
        'a.y' => array('file' => 'b', 'fields' => array('f' => array('type' => 'select_one', 'list' => 'l', 'choices' => array('n' => 'two')))),
    )));
    check('multi-form: labels disagree -> raw', FieldVocab::label(array('a.x', 'a.y'), 'f', 'n') === 'n');
    check('multi-form: single form -> its label', FieldVocab::label(array('a.y'), 'f', 'n') === 'two');
    FieldVocab::setMap(null);

    // ------------------------------------------------------------------ 6
    section('6. Builder units');
    $js = "import a from './one.json';\nimport b from './dir/two.json';\nconst x = () => 1;\n"
        . "const forms = {\n  cat: {\n    one: a,\n    two: b,\n    fn: makeIt(),\n  },\n  measurement_bulk: {\n    one: {\n      survey: a.survey,\n    },\n  },\n  other: {\n    again: a,\n  },\n};\nexport default forms;\n";
    $reg = FieldVocabBuilder::parseRegistry($js);
    check('registry: identifiers mapped, calls + measurement_bulk skipped', $reg === array('cat.one' => 'one.json', 'cat.two' => 'dir/two.json', 'other.again' => 'one.json'));
    $form = json_encode(array('survey' => array(
        array('type' => 'text', 'name' => 'note'),
        array('type' => 'select_one L1', 'name' => 'kind'),
        array('type' => 'select_multiple L2', 'name' => 'many'),
        array('type' => 'select_one L3', 'name' => 'nums'),
    ), 'choices' => array(
        array('list_name' => 'L1', 'name' => 'a', 'label' => ' Alpha '),
        array('list_name' => 'L1', 'name' => 'b', 'label' => 'Beta'),
        array('list_name' => 'L2', 'name' => 'x', 'label' => 'ex'),
        array('list_name' => 'L2', 'name' => 'x', 'label' => 'ecks'),
        array('list_name' => 'L2', 'name' => 'y', 'label' => 'why'),
        array('list_name' => 'L3', 'name' => '0', 'label' => 'zero'),
        array('list_name' => 'L3', 'name' => '1', 'label' => 'one'),
        array('list_name' => 'L3', 'name' => '2', 'label' => '  '),
    ), 'settings' => array(array('id_string' => 'x'))));
    $ff = FieldVocabBuilder::formFields($form);
    check('formFields: only selects', array_keys($ff) === array('kind', 'many', 'nums'));
    check('formFields: label trimmed', $ff['kind']['choices']['a'] === 'Alpha');
    check('formFields: type + list', $ff['many']['type'] === 'select_multiple' && $ff['many']['list'] === 'L2');
    check('formFields: duplicate name -> ambiguous, not a choice', !isset($ff['many']['choices']['x']) && $ff['many']['ambiguous']['x'] === array('ex', 'ecks'));
    check('formFields: empty label -> unlabeled, not a choice', !isset($ff['nums']['choices']['2']) && $ff['nums']['unlabeled'] === array('2'));
    $threw = false;
    try { FieldVocabBuilder::formFields(json_encode(array('survey' => array(array('type' => 'select_one NOPE', 'name' => 'z')), 'choices' => array()))); } catch (Exception $e) { $threw = true; }
    check('formFields: missing list throws', $threw);

    $files1 = array('index.js' => "import f from './f.json';\nconst forms = {\n  c: {\n    f: f,\n  },\n};\n", 'f.json' => $form);
    $m1 = FieldVocabBuilder::build($files1, 'v1.0.0', str_repeat('a', 40));
    $enc = FieldVocabBuilder::encode($m1);
    check('encode: "0","1" names stay a JSON object', strpos($enc, '"0": "zero"') !== false && json_decode($enc, true)['forms']['c.f']['fields']['nums']['choices'] === array(0 => 'zero', 1 => 'one'));
    check('encode round trip readable by FieldVocab', FieldVocab::readMapFile(file_put_contents("$tmp/m1.json", $enc) ? "$tmp/m1.json" : '') !== null);
    FieldVocab::setMap(json_decode($enc, true));
    check('numeric name 0 translates', FieldVocab::label('c.f', 'nums', 0) === 'zero' && FieldVocab::label('c.f', 'nums', '1') === 'one');
    FieldVocab::setMap(null);

    // v2: b relabeled, a dropped, y dropped (many), field nums dropped, new field
    $form2 = json_encode(array('survey' => array(
        array('type' => 'select_one L1', 'name' => 'kind'),
        array('type' => 'select_multiple L2', 'name' => 'many'),
        array('type' => 'select_one L4', 'name' => 'fresh'),
    ), 'choices' => array(
        array('list_name' => 'L1', 'name' => 'b', 'label' => 'Beta two'),
        array('list_name' => 'L1', 'name' => 'c', 'label' => 'Gamma'),
        array('list_name' => 'L2', 'name' => 'x', 'label' => 'ex'),
        array('list_name' => 'L2', 'name' => 'x', 'label' => 'ecks'),
        array('list_name' => 'L4', 'name' => 'q', 'label' => 'queue'),
    )));
    $files2 = array('index.js' => "import f from './f.json';\nimport g from './g.json';\nconst forms = {\n  c: {\n    f: f,\n    g: g,\n  },\n};\n", 'f.json' => $form2, 'g.json' => $form2);
    $m2 = FieldVocabBuilder::merge(FieldVocabBuilder::build($files2, 'v1.1.0', str_repeat('b', 40)), json_decode($enc, true));
    $k = $m2['forms']['c.f']['fields'];
    check('merge: relabel -> current label wins', $k['kind']['choices']['b'] === 'Beta two');
    check('merge: relabel -> former label kept', $k['kind']['former_labels']['b'] === array('Beta'));
    check('merge: dropped name -> retired with last_seen', $k['kind']['retired_choices']['a'] === array('label' => 'Alpha', 'last_seen' => 'v1.0.0'));
    check('merge: dropped name in multi -> retired', isset($k['many']['retired_choices']['y']));
    check('merge: ambiguous name not retired', !isset($k['many']['retired_choices']['x']));
    check('merge: dropped field -> retired, choices moved', isset($k['nums']['retired']) && $k['nums']['choices'] === array() && $k['nums']['retired_choices']['0']['label'] === 'zero');
    check('merge: new field present', $k['fresh']['choices'] === array('q' => 'queue'));
    FieldVocab::setMap($m2);
    check('retired name still translates', FieldVocab::label('c.f', 'kind', 'a') === 'Alpha');
    check('retired field still translates', FieldVocab::label('c.f', 'nums', '0') === 'zero');
    FieldVocab::setMap(null);
    $d = FieldVocabBuilder::diff(json_decode($enc, true), $m2);
    check('diff: label change line', in_array('label changed: c.f.kind b "Beta" -> "Beta two"', $d, true));
    check('diff: choice added / retired / field retired / form added', in_array('choice added: c.f.kind c = "Gamma"', $d, true) && in_array('choice retired: c.f.kind a ("Alpha")', $d, true) && in_array('field retired: c.f.nums', $d, true) && in_array('form added: c.g', $d, true));

    // v3: form f gone entirely, a comes back in g? no: f returns later with a relabeled
    $m3 = FieldVocabBuilder::merge(FieldVocabBuilder::build(array('index.js' => "import g from './g.json';\nconst forms = {\n  c: {\n    g: g,\n  },\n};\n", 'g.json' => $form2), 'v1.2.0', str_repeat('c', 40)), $m2);
    check('merge: dropped form -> retired form', isset($m3['forms']['c.f']['retired']) && $m3['forms']['c.f']['retired']['last_seen'] === 'v1.1.0');
    check('merge: dropped form keeps old retired last_seen', $m3['forms']['c.f']['fields']['nums']['retired']['last_seen'] === 'v1.0.0');
    check('merge: dropped form fields retired', $m3['forms']['c.f']['fields']['kind']['retired_choices']['b']['label'] === 'Beta two');
    $m4 = FieldVocabBuilder::merge(FieldVocabBuilder::build(array('index.js' => "import f from './f.json';\nconst forms = {\n  c: {\n    f: f,\n  },\n};\n", 'f.json' => $form), 'v1.3.0', str_repeat('d', 40)), $m3);
    check('merge: comeback form is live again', !isset($m4['forms']['c.f']['retired']) && !isset($m4['forms']['c.f']['fields']['kind']['retired']));
    check('merge: comeback names current, relabel remembered', $m4['forms']['c.f']['fields']['kind']['choices']['b'] === 'Beta' && in_array('Beta two', $m4['forms']['c.f']['fields']['kind']['former_labels']['b'], true));
    check('merge: comeback name no longer retired', !isset($m4['forms']['c.f']['fields']['kind']['retired_choices']['a']));
    check('merge: c.g retired in m4', isset($m4['forms']['c.g']['retired']));

    $v = FieldVocabBuilder::validate($m1);
    check('validate: tiny map fails (core forms, field floor)', count($v) > 5);
    $bad = $base;
    $bad['forms']['measurement.planar_orientation']['fields']['feature_type']['choices']['zzz'] = 'joint';
    check('two names with one label: still valid (display is fine)', FieldVocabBuilder::validate($bad) === array());
    check('two names with one label: warning', (bool)preg_grep('/^measurement\.planar_orientation\.feature_type: label "joint" is shared by names /', FieldVocabBuilder::warnings($bad)));
    $bw = FieldVocabBuilder::warnings($base);
    check('baseline warnings: label equal to another name (sed.surfaces other)', (bool)preg_grep('/^sed\.surfaces\.type: label "other" \(of other_surf_typ\) is also the name of another choice$/', $bw));
    check('baseline warnings: duplicate names (tidal_flat, other)', count(preg_grep('/is listed 2 times/', $bw)) === 2);
    check('baseline warnings: no shared labels, no empty labels', !preg_grep('/is shared by|has no label/', $bw));
    $bad = $base; unset($bad['forms']['sed.lithology']);
    check('validate: missing core form fails', in_array('core form sed.lithology is missing', FieldVocabBuilder::validate($bad), true));

    // ------------------------------------------------------------------ 7
    section('7. sync.php end to end (offline)');
    // Rebuild an app-shaped forms folder from the baseline (current choices only).
    $formsDir = "$tmp/forms";
    $writeForms = function ($map, $dir, $mutate = null) {
        rrmdir($dir);
        mkdir($dir, 0775, true);
        $imports = ''; $cats = array(); $i = 0; $byFile = array();
        foreach ($map['forms'] as $fk => $f) {
            if (isset($f['retired'])) continue;
            list($cat, $key) = explode('.', $fk, 2);
            if (!isset($byFile[$f['file']])) {
                $var = 'f' . $i++;
                $byFile[$f['file']] = $var;
                $survey = array(array('type' => 'text', 'name' => 'label'));
                $choices = array();
                foreach ($f['fields'] as $fn => $fld) {
                    if (isset($fld['retired'])) continue;
                    $list = 'L_' . $fn;
                    $survey[] = array('type' => $fld['type'] . ' ' . $list, 'name' => (string)$fn);
                    foreach ($fld['choices'] as $n => $l) $choices[] = array('list_name' => $list, 'name' => (string)$n, 'label' => $l);
                    foreach (isset($fld['ambiguous']) ? $fld['ambiguous'] : array() as $n => $ls) foreach ($ls as $l) $choices[] = array('list_name' => $list, 'name' => (string)$n, 'label' => $l);
                }
                $doc = array('survey' => $survey, 'choices' => $choices, 'settings' => array(array('id_string' => $key)));
                if ($mutate) $doc = $mutate($f['file'], $doc);
                if (!is_dir(dirname("$dir/{$f['file']}"))) mkdir(dirname("$dir/{$f['file']}"), 0775, true);
                file_put_contents("$dir/{$f['file']}", json_encode($doc, JSON_PRETTY_PRINT));
                $imports .= "import {$byFile[$f['file']]} from './{$f['file']}';\n";
            }
            $cats[$cat][] = "    $key: {$byFile[$f['file']]},";
        }
        $js = $imports . "\nconst forms = {\n";
        foreach ($cats as $c => $rows) $js .= "  $c: {\n" . implode("\n", $rows) . "\n  },\n";
        file_put_contents("$dir/index.js", $js . "};\n\nexport default forms;\n");
    };
    $writeForms($base, $formsDir);
    $tag = $base['source']['tag']; $sha = $base['source']['sha'];
    $run = function ($args) use ($sync, $dataDir) {
        $out = array(); $rc = 0;
        // Fixture-domain recipient: StraboMail always files it to mail.log, never sends.
        exec('FIELDVOCAB_NOTIFY=fieldvocab-smoke@test.strabospot.org FIELDVOCAB_DATA_DIR=' . escapeshellarg($dataDir) . ' php ' . escapeshellarg($sync) . ' ' . $args . ' 2>&1', $out, $rc);
        return array($rc, implode("\n", $out));
    };
    $live = "$dataDir/field_vocab_map.json";

    require_once '/srv/app/www/includes/StraboMail.php';
    $mailLog = StraboMail::logFile();
    $mailCount = function ($subject) use ($mailLog) {
        return is_file($mailLog) ? substr_count(file_get_contents($mailLog), "To: fieldvocab-smoke@test.strabospot.org\nSubject: $subject") : 0;
    };
    $changeMails0 = $mailCount('StraboField choice labels updated to v9.9.9');
    $failMails0 = $mailCount('StraboField choice map sync FAILED');

    list($rc, $out) = $run("--from-dir=$formsDir --tag=$tag --sha=$sha --dry-run");
    check('dry run: exit 0, no changes vs baseline', $rc === 0 && strpos($out, 'no changes vs repo baseline') !== false);
    check('dry run: nothing written', !is_file($live));

    list($rc, $out) = $run("--from-dir=$formsDir --tag=$tag --sha=$sha");
    check('first run: exit 0 + live map written', $rc === 0 && is_file($live));
    check('first run: .htaccess deny-all in data dir', strpos((string)@file_get_contents("$dataDir/.htaccess"), 'Require all denied') !== false);
    check('first run: sync.log written', is_file("$dataDir/sync.log"));
    $liveMap = FieldVocab::readMapFile($live);
    $noList = function ($forms) { foreach ($forms as $k => $f) foreach ($f['fields'] as $n => $x) unset($forms[$k]['fields'][$n]['list']); return $forms; };   // synthetic lists are named L_<field>
    check('live map == baseline forms (list names aside)', $liveMap !== null && $noList($liveMap['forms']) == $noList($base['forms']));
    FieldVocab::setMap(null);
    check('FieldVocab now loads the synced map', FieldVocab::source()['origin'] === 'data');

    // Relabel option_13 in the next "release".
    $writeForms($base, $formsDir, function ($file, $doc) {
        if ($file !== 'measurement/planar-orientation.json') return $doc;
        foreach ($doc['choices'] as $i => $c) if ($c['list_name'] === 'L_feature_type' && $c['name'] === 'option_13') $doc['choices'][$i]['label'] = 'joint (fracture)';
        return $doc;
    });
    list($rc, $out) = $run("--from-dir=$formsDir --tag=v9.9.9 --sha=" . str_repeat('9', 40));
    check('relabel run: exit 0 + change reported', $rc === 0 && strpos($out, 'label changed: measurement.planar_orientation.feature_type option_13 "joint" -> "joint (fracture)"') !== false);
    check('relabel run: change mail filed', $mailCount('StraboField choice labels updated to v9.9.9') > $changeMails0);
    check('relabel run: .prev copy of the old live map', is_file("$dataDir/field_vocab_map.prev.json") && FieldVocab::readMapFile("$dataDir/field_vocab_map.prev.json")['source']['tag'] === $tag);
    FieldVocab::setMap(null);
    check('relabel run: new label live', FieldVocab::label($planar, 'feature_type', 'option_13') === 'joint (fracture)' && FieldVocab::source()['tag'] === 'v9.9.9');
    check('relabel run: former label kept', in_array('joint', FieldVocab::map()['forms']['measurement.planar_orientation']['fields']['feature_type']['former_labels']['option_13'], true));

    // A release that adds a shared label: synced (not blocked), warning reported once.
    $writeForms($base, $formsDir, function ($file, $doc) {
        if ($file !== 'measurement/planar-orientation.json') return $doc;
        $doc['choices'][] = array('list_name' => 'L_feature_type', 'name' => 'option_99', 'label' => 'bedding');
        return $doc;
    });
    list($rc, $out) = $run("--from-dir=$formsDir --tag=v9.9.9a --sha=" . str_repeat('7', 40));
    check('shared-label release: exit 0 (not blocked)', $rc === 0);
    check('shared-label release: new warning reported', strpos($out, 'new warning(s)') !== false && strpos($out, 'label "bedding" is shared by names') !== false);
    FieldVocab::setMap(null);
    check('shared-label release: both names show the label', FieldVocab::label($planar, 'feature_type', 'option_99') === 'bedding' && FieldVocab::label($planar, 'feature_type', 'bedding') === 'bedding');
    list($rc, $out) = $run("--from-dir=$formsDir --tag=v9.9.9b --sha=" . str_repeat('6', 40));
    check('same warning next release: not reported again', $rc === 0 && strpos($out, 'new warning(s)') === false);

    // A broken release: planar orientation form gone -> fails, live map untouched.
    $before = file_get_contents($live);
    $writeForms($base, $formsDir);
    file_put_contents("$formsDir/index.js", str_replace('planar_orientation:', 'planar_orientation_x:', file_get_contents("$formsDir/index.js")));
    list($rc, $out) = $run("--from-dir=$formsDir --tag=v9.9.10 --sha=" . str_repeat('8', 40));
    check('broken release: exit 1 + reason', $rc === 1 && strpos($out, 'core form measurement.planar_orientation is not in this release') !== false);
    check('broken release: failure mail filed', $mailCount('StraboField choice map sync FAILED') === $failMails0 + 1);
    check('broken release: live map byte-identical', file_get_contents($live) === $before);
    check('broken release: no temp files left', count(glob("$dataDir/*.tmp.*")) === 0);
    check('broken release: failure in sync.log', strpos(file_get_contents("$dataDir/sync.log"), 'FAILED') !== false);

    list($rc, $out) = $run('--from-dir=' . $formsDir);
    check('usage error: --from-dir without tag/sha -> exit 2', $rc === 2);

    // ------------------------------------------------------------------ 8
    if ($network) {
        section('8. sync.php --dry-run against GitHub');
        list($rc, $out) = $run('--dry-run --force');
        echo preg_replace('/^/m', '      ', $out) . "\n";
        check('github dry run: exit 0', $rc === 0);
        check('github dry run: fetched a release', (bool)preg_match('/fetched v\d+\.\d+\.\d+ \([0-9a-f]{40}\), \d+ files/', $out));
    } else {
        echo "\n== 8. GitHub dry run skipped (pass --network)\n";
    }
} catch (Throwable $e) {
    check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    rrmdir($tmp);
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
