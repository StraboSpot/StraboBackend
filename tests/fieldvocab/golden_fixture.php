<?php
/**
 * File: tests/fieldvocab/golden_fixture.php
 * Description: The Field project the golden-file guard uploads
 *              (golden_guard.php). Every form family carries choice NAMES
 *              that differ from their labels (option_13 = "joint",
 *              truncations like basaltic_andes, per-field collisions like
 *              thin, planar vs tabular quality, duplicate names, retired
 *              deprecated pet, select_multiple arrays), so any label that
 *              leaks into a round-trip output changes a golden file.
 *
 *              All ids and timestamps are fixed (goldens must not move).
 *              Id range 977910000000-977919999999 is reserved for this suite.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

const GF_MIN = 977910000000;
const GF_MAX = 977919999999;
const GF_PROJECT = 977910000001;
const GF_DS_A = 977910000011;
const GF_DS_B = 977910000012;
const GF_TS = 1758700000000;   // 2025-09-24 07:46:40 UTC, every modified_timestamp
const GF_DATE = '2025-09-24T07:46:40.000Z';

/** Spot ids by role. */
function gf_spots()
{
	return array(
		'structure' => 977910000101,   // orientations (planar + associated linear, tabular), 3D structures, sample, image
		'petrology' => 977910000102,   // current pet (igneous volcanic + plutonic, metamorphic, alteration, minerals, reactions), fabrics
		'legacy'    => 977910000103,   // flat deprecated pet (string AND array values)
		'trace'     => 977910000104,   // line: trace, other features
		'sedbase'   => 977910000105,   // strat section root
		'interval'  => 977910000106,   // sed interval in the section: lithologies, structures, interpretations, diagenesis, fossils, bedding
		'surface'   => 977910000107,   // polygon: surface feature, tephra, earthquake, outcrop summary, site safety
	);
}

/** POST /db/project body. */
function gf_project()
{
	$s = gf_spots();
	return array(
		'id' => GF_PROJECT,
		'modified_timestamp' => GF_TS,
		'date' => GF_DATE,
		'description' => array(
			'project_name' => 'Field vocab golden fixture',
			'purpose_of_study' => 'golden-file guard for choice translation',
		),
		'preferences' => array(),
		'tags' => array(
			array('id' => 977910000501, 'name' => 'Fixture concept', 'type' => 'concept', 'concept_type' => 'geological_structure',
				'spots' => array($s['structure'], $s['trace'])),
			array('id' => 977910000502, 'name' => 'Fixture documentation', 'type' => 'documentation', 'documentation_type' => 'observation_timing',
				'spots' => array($s['petrology'])),
			array('id' => 977910000503, 'name' => 'Fixture Granite', 'type' => 'geologic_unit', 'unit_label_abbreviation' => 'Kfg',
				'rock_type' => 'igneous', 'igneous_rock_class' => 'plutonic', 'plutonic_rock_types' => 'alkali_granite',
				'eon' => array('phanerozoic'), 'era_phanerozoic' => array('mesozoic'),
				'spots' => array($s['petrology'], $s['legacy'])),
			array('id' => 977910000504, 'name' => 'Fixture Schist', 'type' => 'geologic_unit', 'rock_type' => 'metamorphic',
				'metamorphic_grade' => 'greenschist_fa', 'eon' => array('proterozoic'),
				'spots' => array($s['legacy'])),
		),
	);
}

/** [dataset id => name]. */
function gf_datasets()
{
	return array(GF_DS_A => 'Golden A structures and rocks', GF_DS_B => 'Golden B sed and surface');
}

function gf_feature($id, $name, array $geom, array $props)
{
	return array(
		'type' => 'Feature',
		'geometry' => $geom,
		'properties' => array_merge(array('id' => $id, 'name' => $name, 'date' => GF_DATE, 'time' => GF_DATE,
			'modified_timestamp' => GF_TS, 'notes' => "golden fixture $name"), $props),
	);
}

function gf_point($lng, $lat) { return array('type' => 'Point', 'coordinates' => array($lng, $lat)); }

/** [dataset id => features[]]. */
function gf_features()
{
	$s = gf_spots();
	$A = array(
		gf_feature($s['structure'], 'GF structure', gf_point(-105.10, 39.60), array(
			'orientation_data' => array(
				array('id' => 977910000201, 'type' => 'planar_orientation', 'strike' => 120, 'dip_direction' => 210, 'dip' => 35,
					'feature_type' => 'option_13', 'quality' => '5', 'facing' => 'upright',
					'movement_justification' => array('sedimentary_fe', 'geomorphic_fea'), 'bedding_type' => 'sedimentary_fe',
					'associated_orientation' => array(
						array('id' => 977910000202, 'type' => 'linear_orientation', 'trend' => 210, 'plunge' => 20,
							'feature_type' => 'flow_transport', 'quality' => '1'),
					)),
				array('id' => 977910000203, 'type' => 'planar_orientation', 'strike' => 40, 'dip' => 80,
					'feature_type' => 'fracture', 'fracture_type' => 'option_7', 'quality' => 4),
				array('id' => 977910000204, 'type' => 'planar_orientation', 'strike' => 45, 'dip' => 82,
					'feature_type' => 'fracture', 'fracture_type' => 'option_8'),
				array('id' => 977910000205, 'type' => 'tabular_orientation', 'strike' => 300, 'dip' => 60,
					'feature_type' => 'zone_fracturin', 'quality' => '5'),
			),
			'_3d_structures' => array(
				array('id' => 977910000211, 'type' => 'fold', 'label' => 'fold', 'feature_type' => 's_fold', 'tightness' => 'gentle',
					'fold_shape' => 'class_1b__para', 'vergence' => 'ne', 'Linearity' => '5___straight'),
				array('id' => 977910000212, 'type' => 'fault', 'label' => 'fault', 'fault_or_sz_type' => 'dextral', 'movement' => 'ne_side_up',
					'movement_justification' => array('sedimentary_fe'), 'directional_indicators' => array('crescentic_fra', 'oblique_gouge'), 'quality' => '5'),
				array('id' => 977910000213, 'type' => 'fabric', 'label' => 'tectonite', 'feature_type' => 'tectonite',
					'tectonite_type' => 'ls_tectonite', 'tectonite_character' => 'l___s_1'),
			),
			'samples' => array(
				array('id' => 977910000301, 'label' => 'GF-S1', 'sample_id_name' => 'GF-S1', 'material_type' => 'fragmented_roc',
					'main_sampling_purpose' => 'fabric___micro', 'inplaceness_of_sample' => '5___definitely',
					'degree_of_weathering' => '1___highly_wea', 'oriented_sample' => 'yes', 'sample_notes' => 'golden sample'),
			),
			'images' => array(
				array('id' => 977910000401, 'image_type' => 'geological_cs', 'title' => 'GF cross section', 'caption' => 'golden image',
					'units_of_image_view' => '_m', 'width' => 64, 'height' => 48),
			),
		)),
		gf_feature($s['petrology'], 'GF petrology', gf_point(-105.11, 39.61), array(
			'pet' => array(
				'igneous' => array(
					array('id' => 977910000221, 'igneous_rock_class' => 'volcanic', 'volcanic_rock_type' => 'basaltic_andes'),
					array('id' => 977910000222, 'igneous_rock_class' => 'plutonic', 'plutonic_rock_type' => 'quartz_monz'),
				),
				'metamorphic' => array(array('id' => 977910000223, 'metamorphic_rock_type' => 'calc_silicate', 'protolith' => 'pelite',
					'facies' => array('prehnite_pumpy'), 'zone' => array('garnet_corider'))),
				'alteration_or' => array(array('id' => 977910000224, 'ore_type' => 'dolorite_mica_', 'hydrothermal_alteration' => array('albite_epidote'))),
				'minerals' => array(array('id' => 977910000225, 'igneous_or_metamorphic' => 'ig_min', 'habit' => 'bladed',
					'textural_setting_igneous' => array('ig_matrix'))),
				'reactions' => array(array('id' => 977910000226, 'based_on' => array('mineral_degrad'))),
			),
			'fabrics' => array(
				array('id' => 977910000231, 'type' => 'fault_rock', 'label' => 'fault rock fabric', 'tectonite_type' => 's',
					'type_of_fracture' => array('frac_joint'), 'structural_fabric' => array('fractures')),
				array('id' => 977910000232, 'type' => 'metamorphic_rock', 'label' => 'metamorphic rock fabric',
					'planar_fabric' => array('gneissic_band'), 'mullion_type' => 'fold_bed', 'relict_sed_fab' => array('bed')),
				array('id' => 977910000233, 'type' => 'igneous_rock', 'label' => 'igneous rock fabric', 'planar_fab' => array('foliation')),
			),
		)),
		gf_feature($s['legacy'], 'GF legacy pet', gf_point(-105.12, 39.62), array(
			'pet' => array(
				'rock_type' => array('igneous', 'metamorphic'),
				'igneous_rock_class' => array('volcanic'),
				'volcanic_rock_type' => 'basaltic_andes',
				'metamorphic_rock_type' => array('calc_silicate', 'meta_ultramafi'),
				'facies' => array('ultra_high_pre'),
			),
		)),
		gf_feature($s['trace'], 'GF trace', array('type' => 'LineString', 'coordinates' => array(array(-105.13, 39.63), array(-105.12, 39.64))), array(
			'trace' => array('trace_feature' => true, 'trace_type' => 'geologic_struc', 'geologic_structure_type' => 'fold_axial_tra'),
			'other_features' => array(array('id' => 977910000241, 'type' => 'other', 'label' => 'GF other feature', 'description' => 'golden')),
		)),
	);
	$B = array(
		gf_feature($s['sedbase'], 'GF section', gf_point(-105.20, 39.70), array(
			'sed' => array('strat_section' => array('strat_section_id' => 977910000601, 'column_profile' => 'mixed_clastic',
				'purpose' => array('facies_arch', 'res_char'), 'scale_of_interest' => array('multi_outcrop'),
				'how_is_section_georeferenced' => 'point_at_secti', 'column_y_axis_units' => 'm')),
		)),
		gf_feature($s['interval'], 'GF interval', array('type' => 'Polygon', 'coordinates' => array(array(array(0, 0), array(10, 0), array(10, 1.5), array(0, 1.5), array(0, 0)))), array(
			'strat_section_id' => 977910000601,
			'image_basemap' => null,
			'sed' => array(
				'character' => 'interbedded',
				'interval' => array('interval_thickness' => 1.5, 'thickness_units' => 'm'),
				'lithologies' => array(
					array('primary_lithology' => 'volcaniclastic', 'volcaniclastic_type' => array('volcanic_mudst', 'glass'),
						'bedding_thickness' => 'thin', 'laminae_thickness_i_select_more_than_one' => array('thin')),
					array('primary_lithology' => 'organic_coal', 'organic_coal_lithologies' => array('coal_ball')),
				),
				'structures' => array(array('cross_bedding_type' => array('cross_bedding'), 'graded_bedding_type' => 'normally_grade',
					'lag_type' => array('rip_up_clasts'))),
				'interpretations' => array(array('carbonate' => array('tidal_flat', 'subaerial_expo'), 'clastic' => array('tidal_flat', 'alluvial_fan'))),
				'diagenesis' => array(array('nodules_concretions_shape' => array('other', 'pods'), 'non_selective' => array('cavern'),
					'recrystallization_type' => 'recrystallized')),
				'fossils' => array(array('invertebrate' => array('porifera_spong'), 'shape' => 'bioherm_lens')),
				'bedding' => array('interbed_proportion' => 40, 'lithology_at_bottom_contact' => 'lithology_1',
					'package_geometry' => array('tabular_parall'),
					'beds' => array(array('avg_thickness' => 0.2, 'character_of_lower_contacts' => array('flat'),
						'character_of_upper_contacts' => array('well_defined')))),
			),
		)),
		gf_feature($s['surface'], 'GF surface', array('type' => 'Polygon', 'coordinates' => array(array(array(-105.30, 39.80), array(-105.29, 39.80), array(-105.29, 39.81), array(-105.30, 39.81), array(-105.30, 39.80)))), array(
			'surface_feature' => array('surface_feature_type' => 'rock_unit', 'surface_feature_quality' => 'approximate(?)'),
			'tephra' => array(array('id' => 977910000251, 'label' => 'GF tephra', 'layer_type' => 'package', 'grading' => 'none_or_massive',
				'grainsize_bottom' => 'clay', 'thickness_units' => 'cm')),
		)),
	);
	return array(GF_DS_A => $A, GF_DS_B => $B);
}
