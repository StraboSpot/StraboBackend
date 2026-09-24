<?php
/**
 * File: tests/fieldvocab/smoke_test_data_model_js.php
 * Description: Field choice translation Phase 5c (decision D4): data_model.js
 *              (jsdatamodel.php) for the legacy viewers and straboModelClass
 *              (shapefile import) are built from the app's forms plus the
 *              frozen 2019 model (kobofiles/legacy_model.json), not the xls.
 *
 *                1. data_model.js over HTTP at every rewrite (doi, search,
 *                   fieldland, root mapsearch): same body, parses (vars +
 *                   controlledVocab), no NUL bytes.
 *                2. Nothing lost: every legacy field is in its <group>_vars and
 *                   every legacy choice in controlledVocab.
 *                3. App order + labels first: each group's vars start with its
 *                   forms' layout; choices carry the app labels (option_13 =
 *                   joint), fold tightness repaired, fault_vars present.
 *                4. A live map synced before layouts existed still gives the
 *                   full field lists (repo baseline layouts).
 *                5. straboModelClass: the import catalog is exactly the legacy
 *                   model; fitsControlled takes names, current app labels and
 *                   the 2019 labels.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/fieldvocab/smoke_test_data_model_js.php
 */

chdir('/srv/app/www');
require_once 'includes/straboClasses/straboModelClass.php';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? '  [' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . ']' : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

/** data_model.js text -> {name: value} (the generator writes JSON-encoded keys and strings). */
function parse_dm($js) {
	$body = preg_replace('/^var (\w+) = /m', '"$1": ', trim($js));
	$body = preg_replace('/\}\s*\n\s*\n"/', "},\n\"", $body);
	return json_decode('{' . $body . '}', true);
}

$legacy = json_decode(file_get_contents('includes/straboClasses/kobofiles/legacy_model.json'), true);

section('1. served at every rewrite');
$bodies = array();
foreach (array('/search/includes/data_model.js', '/doi/includes/data_model.js', '/fieldland/includes/data_model.js', '/includes/mapsearch/data_model.js') as $u) {
	$ctx = stream_context_create(array('http' => array('timeout' => 60, 'ignore_errors' => true)));
	$bodies[$u] = (string)@file_get_contents('http://localhost' . $u, false, $ctx);
	$ct = '';
	foreach ((array)$http_response_header as $h) if (stripos($h, 'Content-type:') === 0) $ct = $h;
	check("$u is javascript", stripos($ct, 'javascript') !== false, $ct);
}
check('same body everywhere', count(array_unique($bodies)) === 1);
$js = reset($bodies);
check('no NUL bytes', strpos($js, "\0") === false);
$dm = parse_dm($js);
check('parses: vars + controlledVocab', is_array($dm) && isset($dm['controlledVocab'], $dm['planar_orientation_vars']), json_last_error_msg());
if (!is_array($dm)) { echo "\n$pass passed, $fail failed\n"; exit(1); }

section('2. nothing of the legacy model lost');
$missF = array(); $missC = array();
foreach ($legacy['fields'] as $f) {
	if (in_array($f['name'], array('latitude_and_longitude', 'start', 'end'), true)) continue;   // straboModelClass ignorelist
	if (!isset($dm[$f['group'] . '_vars'][$f['name']])) $missF[] = $f['group'] . '.' . $f['name'];
}
foreach ($legacy['vocab'] as $key => $list) foreach ($list as $c) {
	if (!isset($dm['controlledVocab'][$key][$c[0]])) $missC[] = "$key:{$c[0]}";
}
check('every legacy field in its group vars', !$missF, $missF);
check('every legacy choice in controlledVocab', !$missC, $missC);

section('3. app order and labels first');
$map = FieldVocab::map();
$orderOk = array();
foreach (straboModelClass::GROUP_FORMS + array('fault' => array('_3d_structures.fault')) as $g => $forms) {
	if (!$forms) continue;
	$want = array();
	foreach ($forms as $fk) foreach ($map['forms'][$fk]['layout'] as $row) if (!isset($want[$row[0]])) $want[$row[0]] = $row[1];
	$got = array_slice($dm[$g . '_vars'], 0, count($want), true);
	if ($got !== $want) $orderOk[] = $g;
}
check('each group starts with its forms\' layout (order + app field labels)', !$orderOk, $orderOk);
check('planar feature_type option_13 = joint', $dm['controlledVocab']['planar_orientation_feature_type']['option_13'] === 'joint');
check('planar feature_type keeps legacy names', isset($dm['controlledVocab']['planar_orientation_feature_type']['bedding']));
check('fold tightness repaired', strpos($dm['controlledVocab']['fold_tightness']['gentle'], 'gentle') === 0, $dm['controlledVocab']['fold_tightness']['gentle']);
check('fault_vars (3D fault form) present', isset($dm['fault_vars']) && count($dm['fault_vars']) > 5);
check('tabular_zone_orientation_vars present', isset($dm['tabular_zone_orientation_vars']['strike']));
check('sample vocab uses app labels', $dm['controlledVocab']['sample_material_type']['fragmented_roc'] === FieldVocab::label(array('general.samples'), 'material_type', 'fragmented_roc'));

section('4. live map without layouts (synced before this change)');
$tmp = sys_get_temp_dir() . '/fvdm_' . getmypid();
@mkdir($tmp, 0777, true);
$old = $map;
foreach ($old['forms'] as $k => $f) unset($old['forms'][$k]['layout']);
file_put_contents("$tmp/field_vocab_map.json", json_encode($old));
$out = shell_exec('cd /srv/app/www && FIELDVOCAB_DATA_DIR=' . escapeshellarg($tmp) . ' php jsdatamodel.php 2>&1');
@unlink("$tmp/field_vocab_map.json"); @rmdir($tmp);
$dm2 = parse_dm((string)$out);
check('same field lists from the repo baseline layouts', is_array($dm2) && $dm2['rock_unit_vars'] === $dm['rock_unit_vars'] && $dm2['sample_vars'] === $dm['sample_vars']);

section('5. straboModelClass (shapefile import)');
$sm = new straboModelClass();
$cat = array();
foreach ($sm->fields as $f) $cat[] = array($f['group'], $f['name'], $f['type']);
$want = array();
foreach ($legacy['fields'] as $f) if (!in_array($f['name'], array('latitude_and_longitude', 'start', 'end'), true)) $want[] = array($f['group'], $f['name'], $f['type']);
check('import catalog == frozen legacy model (' . count($want) . ' fields)', $cat === $want);
$sm->usercolumns = array(array('usercol' => 'FT', 'strabocol' => 'planar_orientation_feature_type'), array('usercol' => 'Q', 'strabocol' => 'planar_orientation_quality'));
$sm->setFileControlledVocabulary();
check('fitsControlled: name', $sm->fitsControlled('planar_orientation_feature_type', 'bedding') === 'bedding');
check('fitsControlled: app label "joint" -> option_13', $sm->fitsControlled('planar_orientation_feature_type', 'Joint') === 'option_13');
check('fitsControlled: app label "5 - excellent" -> 5', $sm->fitsControlled('planar_orientation_quality', '5 - excellent') === '5');
check('fitsControlled: 2019 label "5 - best - accurate" -> 5', $sm->fitsControlled('planar_orientation_quality', '5 - best - accurate') === '5');
check('fitsControlled: unknown -> false', $sm->fitsControlled('planar_orientation_feature_type', 'no such thing') === false);
check('isNumericTyped unchanged (strike)', $sm->isNumericTyped('planar_orientation_strike') === true);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
