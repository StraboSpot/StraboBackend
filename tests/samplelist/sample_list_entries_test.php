<?php
/**
 * File: tests/samplelist/sample_list_entries_test.php
 * Description: Rules of sample_list_entries() (Sample List exports): parent-spot stubs
 *              and stale copies of sample spots are dropped, legacy inline samples and
 *              sample spots are kept, order follows the features (2026-11-03).
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/samplelist/sample_list_entries_test.php
 */

require_once dirname(__DIR__, 2) . '/includes/straboClasses/sample_list_entries.php';

$pass = 0; $fail = 0;
function check($name, $cond) {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}
function feat($props, $x) {
	return array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array($x, 1)), 'properties' => $props);
}
function samp($a) { return (object)$a; }

$features = array(
	// parent: legacy sample, stub of sample spot 900, stale full copy of sample spot 901,
	// stub with only the linking id, empty-valued entry
	feat(array('id' => 1, 'name' => 'P', 'samples' => array(
		samp(array('id' => 500, 'sample_id_name' => 'LEG')),
		samp(array('id' => 900)),
		samp(array('id' => 901, 'sample_id_name' => 'OLD COPY')),
		samp(array('id' => 902, 'strabosamples_id' => 'abc')),
		samp(array('id' => 903, 'sample_id_name' => '', 'notes' => null, 'tags' => array())),
	)), 10),
	feat(array('id' => 900, 'isSample' => 1, 'samples' => array(samp(array('id' => 900, 'sample_id_name' => 'S900')))), 20),
	feat(array('id' => 901, 'isSample' => 1, 'samples' => array(samp(array('id' => 901, 'sample_id_name' => 'S901')))), 30),
	// full copy whose sample spot is NOT in this export: kept
	feat(array('id' => 2, 'samples' => array(samp(array('id' => 777, 'sample_id_name' => 'ELSEWHERE')))), 40),
	feat(array('id' => 3, 'name' => 'no samples'), 50),
);

$got = sample_list_entries($features);
$names = array_map(function ($e) { return $e['sample']->sample_id_name; }, $got);
check('kept rows in order: LEG, S900, S901, ELSEWHERE', $names === array('LEG', 'S900', 'S901', 'ELSEWHERE'));
check('geometry comes from the holding feature', $got[1]['feature']['geometry']['coordinates'][0] === 20 && $got[0]['feature']['geometry']['coordinates'][0] === 10);
$spots = array_map(function ($e) { return $e['spot_name']; }, $got);
check('Spot Name: inline = holding spot, sample spots 900/901 = parent P, unnamed spot 2 = ""', $spots === array('P', 'P', 'P', ''));
$noName = feat(array('id' => 5, 'samples' => array(samp(array('id' => 6, 'label' => 'x')))), 0);
check('Spot Name: legacy row of a spot with no name is "" not null', sample_list_entries(array($noName))[0]['spot_name'] === '');
$alone = sample_list_entries(array($features[1]));
check('Spot Name: sample spot with its parent outside the export -> null (caller looks it up)', $alone[0]['spot_name'] === null);
$arrFeat = feat(array('samples' => array(array('id' => 1), array('id' => 2, 'label' => 'x'))), 0);
check('array-shaped samples work too', count(sample_list_entries(array($arrFeat))) === 1);
check('no features -> empty', sample_list_entries(array()) === array());

echo "\n$pass passed, $fail failed\n";
echo $fail === 0 ? "ALL PASS\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
