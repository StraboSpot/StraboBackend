<?php
/**
 * File: jsdatamodel.php
 * Description: Serves data_model.js (rewritten from includes/data_model.js
 *              in doi/, search/, fieldland/, publicmaps/, spotdetails/, the
 *              site root) for the legacy spot viewers' tab builders:
 *
 *                var <group>_vars = {field: "Field Label", ...}   which fields a
 *                    section shows, in the app's form order with its field labels
 *                var controlledVocab = {"<group>_<field>": {name: "label", ...}}
 *                    read by cvFixVal() for choice values
 *
 *              Built from the app's current forms (includes/fieldvocab map,
 *              layout + choices) for every group straboModelClass::GROUP_FORMS
 *              maps to a form, plus the legacy fields and choices the forms no
 *              longer have (straboModelClass: frozen 2019 model), so older data
 *              still shows. Decision D4, docs/edine_bug/TRANSLATION_SURFACE_AUDIT.md.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once("includes/straboClasses/straboModelClass.php");

$sm = new straboModelClass();

// Groups the tab builders look up beyond the legacy model's own ('fault' 3D structures).
$groupForms = straboModelClass::GROUP_FORMS;
$groupForms['fault'] = array('_3d_structures.fault');

/** Form layout rows ([name, label, type]); the repo baseline fills in for a live map synced before layouts existed. */
function jsdm_layout($fk) {
	static $baseline = null;
	$map = FieldVocab::map();
	if (isset($map['forms'][$fk]['layout'])) return $map['forms'][$fk]['layout'];
	if ($baseline === null) $baseline = FieldVocab::readMapFile(FieldVocab::baselinePath());
	return isset($baseline['forms'][$fk]['layout']) ? $baseline['forms'][$fk]['layout'] : array();
}

$vars = array();    // group => field => label (insertion order = display order)
$vocab = array();   // "group_field" => name => label

foreach ($groupForms as $group => $forms) {
	$vars[$group] = array();
	foreach ($forms as $fk) {
		foreach (jsdm_layout($fk) as $row) {
			if (!isset($vars[$group][$row[0]])) $vars[$group][$row[0]] = $row[1];
			$choices = $sm->mapChoices($forms, $row[0]);
			if ($choices && !isset($vocab[$group . '_' . $row[0]])) $vocab[$group . '_' . $row[0]] = $choices;
		}
	}
}

// Legacy fields and choices: after the app's, never overriding its labels
foreach ($sm->fields as $f) {
	$g = $f['group'];
	if (!isset($vars[$g][$f['name']])) $vars[$g][$f['name']] = trim($f['label']);
	if (empty($sm->controlledlist[$f['num']])) continue;
	$key = $g . '_' . $f['name'];
	if (!isset($vocab[$key])) $vocab[$key] = array();
	foreach ($sm->controlledlist[$f['num']] as $c) {
		if (!isset($vocab[$key][(string)$c['name']])) $vocab[$key][(string)$c['name']] = $c['label'];
	}
}

$js = function ($v) { return json_encode((string)$v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); };

header("Content-type: text/javascript; charset=utf-8");

foreach ($vars as $group => $fields) {
	$rows = array();
	foreach ($fields as $name => $label) $rows[] = "\t" . $js($name) . ': ' . $js($label);
	echo "var {$group}_vars = {\n" . implode(",\n", $rows) . "\n}\n\n";
}

$groups = array();
foreach ($vocab as $key => $choices) {
	$rows = array();
	foreach ($choices as $name => $label) $rows[] = "\t\t" . $js($name) . ': ' . $js($label);
	$groups[] = "\t" . $js($key) . ": {\n" . implode(",\n", $rows) . "\n\t}";
}
echo "var controlledVocab = {\n" . implode(",\n", $groups) . "\n}\n";
