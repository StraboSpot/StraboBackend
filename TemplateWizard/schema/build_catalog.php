<?php
/**
 * File: TemplateWizard/schema/build_catalog.php
 * Description: Compiles the StraboField Spot schema + XLSForm survey JSONs into
 *              the Template Wizard column catalog (catalog.json). Run from CLI:
 *                php TemplateWizard/schema/build_catalog.php
 *              Re-run whenever the schema/forms copies are refreshed from the
 *              StraboField app source.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (php_sapi_name() !== 'cli') {
	die("CLI only.\n");
}

$base = __DIR__;

/* ---------------------------------------------------------------- helpers */

function tw_load_json($path) {
	$raw = file_get_contents($path);
	if ($raw === false) {
		fwrite(STDERR, "Cannot read $path\n");
		exit(1);
	}
	$data = json_decode($raw, true);
	if ($data === null) {
		fwrite(STDERR, "Cannot parse $path\n");
		exit(1);
	}
	return $data;
}

// Parse an XLSForm constraint like ". >= 0 and . <= 360" into [min, max].
function tw_parse_constraint($constraint) {
	$out = array();
	if (preg_match('/\.\s*>=\s*(-?[\d.]+)/', $constraint, $m)) {
		$out['min'] = $m[1] + 0;
	}
	if (preg_match('/\.\s*<=\s*(-?[\d.]+)/', $constraint, $m)) {
		$out['max'] = $m[1] + 0;
	}
	return $out;
}

// Build list_name => [{value,label}] from an XLSForm choices array.
function tw_choice_lists($choices) {
	$lists = array();
	foreach ($choices as $row) {
		if (!isset($row['list_name'], $row['name'])) {
			continue;
		}
		$lists[$row['list_name']][] = array(
			'value' => (string)$row['name'],
			'label' => isset($row['label']) ? (string)$row['label'] : (string)$row['name'],
		);
	}
	return $lists;
}

// Extract usable fields from one XLSForm survey.
// Returns name => field spec. Skips structural rows and $skip names.
function tw_survey_fields($form, $skip = array()) {
	$lists  = tw_choice_lists(isset($form['choices']) ? $form['choices'] : array());
	$fields = array();
	foreach ($form['survey'] as $row) {
		if (!isset($row['type'], $row['name'])) {
			continue;
		}
		$parts = explode(' ', trim($row['type']));
		$type  = $parts[0];
		if (in_array($type, array('start', 'end', 'calculate', 'acknowledge', 'begin_group', 'end_group', 'note'))) {
			continue;
		}
		$name = $row['name'];
		if (in_array($name, $skip) || $name === 'id') {
			continue;
		}
		$field = array(
			'name'  => $name,
			'label' => isset($row['label']) ? $row['label'] : $name,
			'type'  => in_array($type, array('select_one', 'select_multiple', 'integer', 'decimal')) ? $type : 'text',
		);
		if (isset($row['hint']) && $row['hint'] !== '') {
			$field['hint'] = $row['hint'];
		}
		if (isset($row['constraint'])) {
			$c = tw_parse_constraint($row['constraint']);
			if ($c) {
				$field['constraint'] = $c;
			}
		}
		if (($type === 'select_one' || $type === 'select_multiple') && isset($parts[1], $lists[$parts[1]])) {
			$field['vocab'] = $lists[$parts[1]];
		}
		$fields[$name] = $field;
	}
	return $fields;
}

/* ------------------------------------------------------- spot core group */

// Spot-level fields. Geometry-ish fields are structural (flagged) — the
// pipeline owns their storage; they are not plain properties writes.
$spotFields = array(
	array('name' => 'name', 'label' => 'Spot Name', 'type' => 'text', 'required' => true),
	array('name' => 'latitude', 'label' => 'Latitude', 'type' => 'decimal', 'geometry' => true,
		'constraint' => array('min' => -90, 'max' => 90)),
	array('name' => 'longitude', 'label' => 'Longitude', 'type' => 'decimal', 'geometry' => true,
		'constraint' => array('min' => -180, 'max' => 180)),
	array('name' => 'altitude', 'label' => 'Altitude (m)', 'type' => 'decimal'),
	array('name' => 'date', 'label' => 'Date', 'type' => 'text',
		'hint' => 'ISO 8601 (YYYY-MM-DD); defaults to import date when blank on create'),
	array('name' => 'notes', 'label' => 'Notes', 'type' => 'text'),
	array('name' => 'gps_accuracy', 'label' => 'GPS Accuracy (m)', 'type' => 'decimal'),
	array('name' => 'spot_radius', 'label' => 'Spot Radius (m)', 'type' => 'decimal'),
);

/* ------------------------------------------------- orientation union group */

// Union the three measurement forms; track which orientation types each
// field applies to. Type keys match the app's orientation_data 'type'
// values minus the '_orientation' suffix handled by the pipeline.
$orientationSources = array(
	'planar'       => 'forms/planar-orientation.json',
	'linear'       => 'forms/linear-orientation.json',
	'tabular_zone' => 'forms/tabular-zone-orientation.json',
);

$orientationFields = array();
foreach ($orientationSources as $otype => $file) {
	$form = tw_load_json("$base/$file");
	foreach (tw_survey_fields($form) as $name => $field) {
		if (!isset($orientationFields[$name])) {
			$field['applies_to'] = array();
			$orientationFields[$name] = $field;
		}
		$orientationFields[$name]['applies_to'][] = $otype;
		if (isset($field['vocab'])) {
			// Per-type vocab so plan() can validate against the row's
			// orientation type, not just the union.
			$orientationFields[$name]['vocab_by_type'][$otype] = $field['vocab'];
		}
		// Prefer the planar form's label/vocab when fields collide; merge
		// vocab entries that only exist for other types.
		if (isset($field['vocab'])) {
			$existing = isset($orientationFields[$name]['vocab']) ? $orientationFields[$name]['vocab'] : array();
			$have = array();
			foreach ($existing as $v) {
				$have[$v['value']] = true;
			}
			foreach ($field['vocab'] as $v) {
				if (!isset($have[$v['value']])) {
					$existing[] = $v;
				}
			}
			$orientationFields[$name]['vocab'] = $existing;
		}
	}
}

/* -------------------------------------------------------- simple groups */

$geoUnitFields = array_values(tw_survey_fields(tw_load_json("$base/forms/geologic_unit.json")));
$traceFields   = array_values(tw_survey_fields(tw_load_json("$base/forms/trace.json")));
$sampleFields  = array_values(tw_survey_fields(tw_load_json("$base/forms/sample.json")));

$otherFeatureFields = array(
	array('name' => 'label', 'label' => 'Feature Label', 'type' => 'text'),
	array('name' => 'name', 'label' => 'Feature Name', 'type' => 'text'),
	array('name' => 'type', 'label' => 'Feature Type', 'type' => 'text'),
	array('name' => 'description', 'label' => 'Feature Description', 'type' => 'text'),
);

/* ------------------------------------------------------------- assemble */

$catalog = array(
	'catalog_version' => 1,
	'generated'       => date('c'),
	'sources'         => array(
		'schema' => 'strabofield_spot.schema.json (title: StraboField Spot, generated from StraboField app forms Apr 2026)',
		'forms'  => array_map('basename', glob("$base/forms/*.json")),
	),
	'groups' => array(
		'spot' => array(
			'label'  => 'Spot',
			'multi'  => false,
			'target' => 'properties',
			'fields' => $spotFields,
		),
		'orientation' => array(
			'label'  => 'Orientation',
			'multi'  => true,
			'target' => 'orientation_data',
			'types'  => array('planar', 'linear', 'tabular_zone'),
			'fields' => array_values($orientationFields),
		),
		'geologic_unit' => array(
			'label'  => 'Geologic Unit',
			'multi'  => false,
			'target' => 'geologic_unit',
			'fields' => $geoUnitFields,
		),
		'trace' => array(
			'label'  => 'Trace',
			'multi'  => false,
			'target' => 'trace',
			'fields' => $traceFields,
		),
		'other_features' => array(
			'label'  => 'Other Features',
			'multi'  => true,
			'target' => 'other_features',
			'fields' => $otherFeatureFields,
		),
		'sample' => array(
			'label'  => 'Samples',
			'multi'  => true,
			'target' => 'samples',
			'fields' => $sampleFields,
		),
	),
);

$out = "$base/catalog.json";
file_put_contents($out, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$counts = array();
foreach ($catalog['groups'] as $key => $g) {
	$counts[] = "$key=" . count($g['fields']);
}
echo "Wrote $out (" . implode(', ', $counts) . ")\n";
