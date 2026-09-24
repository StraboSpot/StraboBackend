<?php
/**
 * File: tests/fieldvocab/smoke_test_samples_vocab.php
 * Description: Field choice translation Phase 7 (decision D8). The curated
 *              StraboSamples list (samplesdb/lib/vocab.php, "L4", Title Case)
 *              stays the cross-system label source for Material Type and
 *              Sampling Purpose; this test is its drift alarm against the
 *              synced StraboField form, and checks the samples pages carry
 *              the display maps.
 *
 *                1. Drift: every Field material_type / main_sampling_purpose
 *                   name (except "other", the free-text sentinel) is in L4
 *                   with the same label ignoring case; every L4 Field/Micro
 *                   name is still a Field name (current or retired); a
 *                   doctored map proves the alarm fires.
 *                2. samples_vocab_display_maps(): JSON objects, raw values
 *                   untouched, resolver still takes names and labels.
 *                3. Pages over HTTP (forged owner session, fixture sample
 *                   with a Field link): My Samples ships ms-vocab-data, the
 *                   Sample Overview ships sd-vocab-data incl. the Field
 *                   in-place-ness labels; stored values stay raw in the page
 *                   data (edit selects pre-fill from them).
 *
 *              Flags: --keep leaves the fixture sample and prints its URL
 *              (for a browser probe); --cleanup removes a kept fixture.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/fieldvocab/smoke_test_samples_vocab.php
 */

chdir('/srv/app/www');
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/fieldvocab/FieldVocab.php';
require_once 'samplesdb/lib/vocab.php';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? '  [' . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . ']' : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

$SAMPLE_ID = 'fvsamplesvocab-fixture';
$keep = in_array('--keep', $argv, true);
$owner = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND active = TRUE AND deleted = FALSE",
	array('owner@test.strabospot.org'));
if (!$owner) { echo "Test user owner@test.strabospot.org not found. Run tests/collaboration/setup_test_data.php first.\n"; exit(1); }

function cleanup_fixture() {
	global $db, $owner, $SAMPLE_ID;
	$db->get_var_prepared("DELETE FROM strabosamples.sample_subsystem_links WHERE sample_id = $1 AND sample_userpkey = $2 RETURNING sample_id", array($SAMPLE_ID, $owner));
	$db->get_var_prepared("DELETE FROM strabosamples.samples WHERE id = $1 AND userpkey = $2 RETURNING id", array($SAMPLE_ID, $owner));
}
if (in_array('--cleanup', $argv, true)) { cleanup_fixture(); echo "fixture removed\n"; exit(0); }

/* ------------------------------------------------------------------ 1 */
section('1. L4 vs the synced StraboField form (' . json_encode(FieldVocab::source()) . ')');
/** Drift issues between L4 and one map: [field => [missing, differ, gone]]. */
function l4_drift(array $m) {
	$pairs = array(
		'material_type'         => samples_vocab_material_types()['Common (Field / Micro)'],
		'main_sampling_purpose' => samples_vocab_sample_purposes(),
	);
	$out = array();
	foreach ($pairs as $field => $l4) {
		$f = isset($m['forms']['general.samples']['fields'][$field]) ? $m['forms']['general.samples']['fields'][$field] : null;
		if ($f === null) { $out[$field] = null; continue; }
		$missing = array(); $differ = array(); $gone = array(); $known = array();
		foreach ($f['choices'] as $name => $label) {
			$name = (string)$name;
			$known[$name] = true;
			if ($name === 'other') continue;   // free-text sentinel in StraboSamples (VOCAB_OTHER_SENTINEL)
			if (!array_key_exists($name, $l4)) { $missing[] = $name; continue; }
			if (mb_strtolower(trim($l4[$name])) !== mb_strtolower(trim($label))) $differ[] = "$name: L4 \"{$l4[$name]}\" vs form \"$label\"";
		}
		foreach (isset($f['retired_choices']) ? $f['retired_choices'] : array() as $n => $r) $known[(string)$n] = true;
		foreach ($l4 as $name => $label) if (!isset($known[(string)$name])) $gone[] = $name;
		$out[$field] = array($missing, $differ, $gone);
	}
	return $out;
}
$m = FieldVocab::map();
foreach (l4_drift($m) as $field => $d) {
	check("map has general.samples $field", $d !== null);
	if ($d === null) continue;
	check("$field: every form name is in L4", !$d[0], $d[0]);
	check("$field: L4 labels match the form ignoring case", !$d[1], $d[1]);
	check("$field: every L4 name is still a Field name", !$d[2], $d[2]);
}
// the alarm must fire: a relabel, a new name and a dropped name
$bad = $m;
$bad['forms']['general.samples']['fields']['material_type']['choices']['sediment'] = 'loose sediment';
$bad['forms']['general.samples']['fields']['material_type']['choices']['ice_core'] = 'ice core';
unset($bad['forms']['general.samples']['fields']['main_sampling_purpose']['choices']['cryptotephra']);
$bd = l4_drift($bad);
check('self-test: a relabel, a new form name and a dropped name are all reported',
	$bd['material_type'][1] && $bd['material_type'][0] === array('ice_core') && $bd['main_sampling_purpose'][2] === array('cryptotephra'), $bd);

/* ------------------------------------------------------------------ 2 */
section('2. display maps');
$maps = samples_vocab_display_maps();
$enc = json_decode(json_encode($maps));
check('material + purpose encode as JSON objects', is_object($enc->material) && is_object($enc->purpose));
check('intact_rock -> Intact Rock, fabric___micro -> Fabric / Microstructure',
	$enc->material->intact_rock === 'Intact Rock' && $enc->purpose->fabric___micro === 'Fabric / Microstructure');
check('Experimental values map to themselves', $enc->material->{'Igneous Rock'} === 'Igneous Rock');
check('resolver still takes names and labels',
	samples_vocab_resolve('INTACT ROCK', samples_vocab_material_flat()) === 'intact_rock'
	&& samples_vocab_resolve('fragmented_roc', samples_vocab_material_flat()) === 'fragmented_roc');

/* ------------------------------------------------------------------ 3 */
section('3. samples pages over HTTP');
cleanup_fixture();
$db->prepare_query("INSERT INTO strabosamples.samples (id, userpkey, name, display_sample_type, display_sample_purpose, field_data,
		created_at, created_by, modified_at, modified_by)
	VALUES ($1, $2, $3, $4, $5, $6::jsonb, now(), $2, now(), $2)",
	array($SAMPLE_ID, $owner, 'FV Vocab Fixture', 'intact_rock', 'fabric___micro',
		json_encode(array('material_type' => 'fragmented_roc', 'inplaceness_of_sample' => '5___definitely',
			'sample_notes' => 'fixture notes', 'sample_id_name' => 'FV Vocab Fixture'))));
$db->prepare_query("INSERT INTO strabosamples.sample_subsystem_links (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey,
		reference_metadata, created_at, modified_at)
	VALUES ($1, $2, 'field', $3, $2, '{}'::jsonb, now(), now())", array($SAMPLE_ID, $owner, '96669777'));

$sid = substr(bin2hex(random_bytes(16)), 0, 26);
$sessFile = "/var/lib/php/sessions/sess_$sid";
file_put_contents($sessFile, 'loggedin|s:3:"yes";userpkey|i:' . $owner . ';LAST_ACTIVITY|i:' . time() . ';');
chmod($sessFile, 0600); @chown($sessFile, 'www-data'); @chgrp($sessFile, 'www-data');
function page($path) {
	global $sid;
	$ctx = stream_context_create(array('http' => array('timeout' => 60, 'ignore_errors' => true,
		'header' => "Cookie: PHPSESSID=$sid\r\n")));
	return (string)@file_get_contents('http://localhost' . $path, false, $ctx);
}
function json_block($html, $id) {
	if (!preg_match('#<script type="application/json" id="' . preg_quote($id, '#') . '">(.*?)</script>#s', $html, $mm)) return null;
	return json_decode($mm[1], true);
}

try {
	$ms = page('/my_samples.php');
	$msVocab = json_block($ms, 'ms-vocab-data');
	check('My Samples ships ms-vocab-data', is_array($msVocab) && $msVocab['material']['intact_rock'] === 'Intact Rock'
		&& $msVocab['purpose']['fabric___micro'] === 'Fabric / Microstructure');
	$msData = json_block($ms, 'ms-data');
	$row = null;
	foreach ((array)$msData as $s) if (isset($s['id']) && $s['id'] === $SAMPLE_ID) $row = $s;
	check('My Samples data keeps the stored values raw', $row !== null && $row['display_sample_type'] === 'intact_rock'
		&& $row['display_sample_purpose'] === 'fabric___micro');
	check('My Samples page has the label helper', strpos($ms, "vocabLabel('material', sample.display_sample_type)") !== false);
	check('My Samples: no PHP warnings in the page', !preg_match('/(Warning|Notice|Fatal error)<\/b>:/', $ms));

	$sd = page('/samples_detail.php?owner=' . $owner . '&id=' . rawurlencode($SAMPLE_ID));
	$sdVocab = json_block($sd, 'sd-vocab-data');
	check('Sample Overview ships sd-vocab-data (material, purpose, Field in-place-ness)', is_array($sdVocab)
		&& $sdVocab['material']['fragmented_roc'] === 'Fragmented Rock'
		&& $sdVocab['inplaceness']['5___definitely'] === '5 - definitely in place');
	$sdData = json_block($sd, 'sd-data');
	check('Sample Overview data keeps the stored values raw (edit selects pre-fill from them)',
		isset($sdData['sample']) && $sdData['sample']['display_sample_type'] === 'intact_rock'
		&& $sdData['sample']['field_data']['inplaceness_of_sample'] === '5___definitely');
	check('Sample Overview link card reads the Field keys',
		strpos($sd, 'subData.inplaceness_of_sample') !== false && strpos($sd, 'subData.sample_notes') !== false);
	check('Sample Overview: no PHP warnings in the page', !preg_match('/(Warning|Notice|Fatal error)<\/b>:/', $sd));
	if ($keep) echo "\nKEPT fixture: http://localhost/samples_detail.php?owner=$owner&id=" . rawurlencode($SAMPLE_ID) . "  (owner pkey $owner)\n";
} finally {
	@unlink($sessFile);
	if (!$keep) cleanup_fixture();
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
