<?php
/**
 * File: tests/fieldvocab/smoke_test_search_labels.php
 * Description: Field choice translation Phase 5a: StraboSearch shows form
 *              LABELS on facet typeaheads, criterion chips and saved-search
 *              summaries while the index, the DSL, saved searches and URLs
 *              keep the stored names (decision D5).
 *
 *                1. SearchVocabLabels pins: F5 (option_13 -> joint), F7 rock
 *                   paths and the tree leaf, F9 trace paths, F8 facies, I1,
 *                   U7 type / purpose (StraboSamples list first, D8), unknown
 *                   names and other facets unchanged.
 *                2. GET ?action=vocab over HTTP: values exactly as before (raw,
 *                   counts kept), plus an additive labels map; rock_type
 *                   entries carry the leaf label.
 *                3. GET ?action=vocab_labels: labels only where they differ,
 *                   junk input tolerated.
 *                4. The search itself still matches raw names (F5 option_13
 *                   finds projects; the label "joint" is not a stored value).
 *
 *              The chips / summaries (catalog.js, builder.js, saved.js,
 *              export_builder.js) were checked in real Firefox (Playwright)
 *              on 2026-09-24; this file covers the server side.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/fieldvocab/smoke_test_search_labels.php
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'searchdb/services/StraboSearchService.php';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? '  [' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . ']' : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function get_json($qs) {
	$ctx = stream_context_create(array('http' => array('timeout' => 60, 'ignore_errors' => true)));
	$body = @file_get_contents('http://localhost/strabosearch/api.php?' . $qs, false, $ctx);
	return json_decode((string)$body, true);
}
function post_json($qs, $body) {
	$ctx = stream_context_create(array('http' => array('method' => 'POST', 'timeout' => 120, 'ignore_errors' => true,
		'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body))));
	return json_decode((string)@file_get_contents('http://localhost/strabosearch/api.php?' . $qs, false, $ctx), true);
}

$L = function ($f, $v) { return SearchVocabLabels::label($f, $v); };
$S = SearchVocabLabels::PATH_SEP;

section('1. SearchVocabLabels pins');
check('F5 option_13 -> joint', $L('feature_type', 'option_13') === 'joint');
check('F5 shear_zone -> shear zone', $L('feature_type', 'shear_zone') === 'shear zone');
check('F5 unknown stays raw', $L('feature_type', 'qc?') === 'qc?');
check('F5 legacy spaced "fold axis" stays', $L('feature_type', 'fold axis') === 'fold axis');
check('F7 rock path segment by segment', $L('rock_type', 'igneous:plutonic:alkali_granite') === "igneous{$S}plutonic{$S}alkali feldspar granite", $L('rock_type', 'igneous:plutonic:alkali_granite'));
check('F7 leaf label', SearchVocabLabels::segmentLabel('rock_type', array('igneous', 'plutonic', 'alkali_granite'), 2) === 'alkali feldspar granite');
check('F7 stray "Sedimentary" top keeps its own text', strpos($L('rock_type', 'Sedimentary'), 'Sedimentary') === 0);
check('F7 unknown top verbatim', $L('rock_type', 'moonrock:cheese') === "moonrock{$S}cheese");
check('F9 trace path', $L('trace_type', 'geologic_struc:fold_axial_tra') === "geologic structure{$S}fold axial trace", $L('trace_type', 'geologic_struc:fold_axial_tra'));
check('F9 top only', $L('trace_type', 'anthropenic_fe') === 'anthropogenic feature');
check('F8 facies', $L('met_facies', 'prehnite-pumpe') === 'prehnite-pumpellyite facies');
check('I1 thin_section', $L('image_type', 'thin_section') === 'thin section');
check('U7T StraboSamples list first (D8)', $L('sample_type', 'fragmented_roc') === 'Fragmented Rock');
check('U7T web-entered value unchanged', $L('sample_type', 'Pixy dust') === 'Pixy dust');
check('U7P fabric___micro', $L('sample_purpose', 'fabric___micro') === 'Fabric / Microstructure');
check('other facets untouched', $L('mineral', 'option_13') === 'option_13' && !SearchVocabLabels::handles('owner'));
check('labels(): only differing values', SearchVocabLabels::labels('feature_type', array('bedding', 'option_13', null, array('x'))) === array('option_13' => 'joint'));

section('2. vocab feed over HTTP');
$svc = new StraboSearchService($db, 0);
foreach (array('feature_type', 'met_facies', 'trace_type', 'sample_type', 'sample_purpose') as $f) {
	$j = get_json('action=vocab&facet=' . $f);
	$rows = $db->get_results_prepared("SELECT value, count FROM strabosearch.vocab_facet_counts WHERE criterion_id = $1 ORDER BY count DESC, value",
		array(array('feature_type' => 'F5', 'met_facies' => 'F8', 'trace_type' => 'F9', 'sample_type' => 'U7T', 'sample_purpose' => 'U7P')[$f]));
	$want = array();
	foreach ((array)$rows as $r) $want[] = array('value' => $r->value, 'count' => (int)$r->count);
	check("$f values unchanged (raw + counts)", is_array($j) && $j['values'] === $want, is_array($j) ? count($j['values']) . ' vs ' . count($want) : 'no json');
	check("$f has labels map", is_array($j) && isset($j['labels']) && is_array($j['labels']) && count($j['labels']) > 0);
}
$j = get_json('action=vocab&facet=feature_type');
if (isset($j['values'][0])) {
	$has13 = false; foreach ($j['values'] as $v) if ($v['value'] === 'option_13') $has13 = true;
	if ($has13) check('F5 labels option_13 = joint', isset($j['labels']['option_13']) && $j['labels']['option_13'] === 'joint');
}
$j = get_json('action=vocab&facet=rock_type');
$ok = is_array($j) && count($j['values']) > 0;
foreach ((array)$j['values'] as $v) {
	$segs = explode(':', $v['path']);
	if (!isset($v['label'], $v['depth']) || !array_key_exists('parent_path', $v)) $ok = false;
	if ($v['label'] !== SearchVocabLabels::segmentLabel('rock_type', $segs, count($segs) - 1)) $ok = false;
}
check('rock_type entries keep path/parent/depth + leaf label', $ok);
$j = get_json('action=vocab&facet=owner');
check('owner feed has no labels key', is_array($j) && !isset($j['labels']));
$j = get_json('action=vocab&facet=image_type');
check('image_type values still plain strings', is_array($j) && isset($j['values'][0]) && is_string($j['values'][0]));

section('3. vocab_labels');
$j = get_json('action=vocab_labels&facet=feature_type&values=' . urlencode(json_encode(array('option_13', 'bedding', 'zzz'))));
check('labels for option_13 only', is_array($j) && $j['labels'] === array('option_13' => 'joint'), $j);
$j = get_json('action=vocab_labels&facet=rock_type&values=' . urlencode(json_encode(array('metamorphic:calc_silicate'))));
check('rock path label', is_array($j) && isset($j['labels']['metamorphic:calc_silicate']) && strpos($j['labels']['metamorphic:calc_silicate'], "metamorphic{$S}") === 0, $j);
$j = get_json('action=vocab_labels&facet=feature_type&values=notjson');
check('junk values -> empty labels object', is_array($j) && $j['labels'] === array(), $j);
$j = get_json('action=vocab_labels&facet=mineral&values=' . urlencode('["Quartz"]'));
check('unlabeled facet -> empty labels', is_array($j) && $j['labels'] === array(), $j);

section('4. search still matches stored names');
$raw = post_json('action=search', array('criteria' => array(array('id' => 'F5', 'value' => array('option_13'))), 'pathway' => 'projects', 'page' => 0, 'page_size' => 5));
$lab = post_json('action=search', array('criteria' => array(array('id' => 'F5', 'value' => array('joint'))), 'pathway' => 'projects', 'page' => 0, 'page_size' => 5));
$n = function ($r) { return is_array($r) && isset($r['total']) ? (int)$r['total'] : (is_array($r) && isset($r['results']) ? count($r['results']) : -1); };
check('F5 option_13 finds projects', $n($raw) > 0, $raw);
check('F5 "joint" (a label) is not a stored value', $n($lab) === 0, $lab);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
