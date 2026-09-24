<?php
/**
 * File: SearchVocabLabels.php
 * Description: Display labels for StraboSearch vocab values (facet
 *              typeahead, criterion chips, saved-search summaries). The
 *              index, the DSL, saved searches and URLs keep the stored
 *              names (decision D5); only what the user reads is a label.
 *
 *              Field facets resolve through FieldVocab (the app's own
 *              forms, keyed by form + field). Sample type / purpose (U7)
 *              use the StraboSamples cross-system list first (decision
 *              D8), then the Field samples form. Anything unresolved keeps
 *              its raw value, exactly as the facet showed it before.
 *
 *              Path facets (F7 rock type "igneous:plutonic:granite", F9
 *              trace "contact:depositional") translate segment by segment,
 *              each segment through the field that produced it
 *              (buildRockTypePath / buildTracePath). Their chip label joins
 *              the segments with PATH_SEP; the rock-type tree shows only
 *              the last segment (see segmentLabel()).
 *
 *              See docs/edine_bug/TRANSLATION_SURFACE_AUDIT.md §4.3 and §7.
 *
 * @package    StraboSpot Web Site — StraboSearch
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once(__DIR__ . '/../../includes/fieldvocab/FieldVocab.php');
require_once(__DIR__ . '/../../samplesdb/lib/vocab.php');

class SearchVocabLabels
{
	const PATH_SEP = ' › ';

	/** Facets this class labels (others are returned unchanged). */
	private static $FACETS = array(
		'feature_type', 'rock_type', 'met_facies', 'trace_type',
		'image_type', 'sample_type', 'sample_purpose',
	);

	public static function handles($facet)
	{
		return in_array($facet, self::$FACETS, true);
	}

	/**
	 * Display label of one facet value; the value itself when unresolved.
	 *
	 * @param string $facet  vocab facet name (feature_type, rock_type, ...)
	 * @param mixed  $value  stored value as the index holds it
	 * @return string
	 */
	public static function label($facet, $value)
	{
		if (!is_string($value) && !is_int($value) && !is_float($value)) return $value;
		$value = (string)$value;
		switch ($facet) {
			case 'feature_type':
				// F5 mixes the planar, linear and tabular lists in one array;
				// a name resolves only where the lists agree (they all do today)
				return FieldVocab::label(array('measurement.planar_orientation',
					'measurement.linear_orientation', 'measurement.tabular_orientation'),
					'feature_type', $value);
			case 'met_facies':
				return FieldVocab::label(self::unitForms(), 'metamorphic_grade', $value);
			case 'image_type':
				return FieldVocab::label(array('general.images'), 'image_type', $value);
			case 'rock_type':
			case 'trace_type':
				$segs = explode(':', $value);
				$out = array();
				foreach ($segs as $i => $s) $out[] = self::segmentLabel($facet, $segs, $i);
				return implode(self::PATH_SEP, $out);
			case 'sample_type':
				$l4 = samples_vocab_material_flat();
				if (isset($l4[$value])) return $l4[$value];
				return FieldVocab::label(array('general.samples'), 'material_type', $value);
			case 'sample_purpose':
				$l4 = samples_vocab_sample_purposes();
				if (isset($l4[$value])) return $l4[$value];
				return FieldVocab::label(array('general.samples'), 'main_sampling_purpose', $value);
		}
		return $value;
	}

	/**
	 * Label of segment $i of a split rock-type / trace path.
	 *
	 * @param string   $facet 'rock_type' | 'trace_type'
	 * @param string[] $segs  the path split on ':'
	 * @param int      $i
	 * @return string
	 */
	public static function segmentLabel($facet, array $segs, $i)
	{
		$field = null;
		$forms = array('general.trace');
		if ($facet === 'rock_type') {
			$forms = self::unitForms();
			if ($i === 0) {
				$field = 'rock_type';
			} else {
				$top = strtolower($segs[0]);   // buildRockTypePath accepts one stray "Sedimentary"
				$byTop = array(
					'igneous'     => array(1 => 'igneous_rock_class', 2 => 'plutonic_rock_types'),
					'metamorphic' => array(1 => 'metamorphic_rock_types'),
					'sedimentary' => array(1 => 'sedimentary_rock_type'),
					'sediment'    => array(1 => 'sediment_type'),
				);
				if (isset($byTop[$top][$i])) $field = $byTop[$top][$i];
			}
		} elseif ($facet === 'trace_type') {
			if ($i === 0) $field = 'trace_type';
			elseif ($i === 1 && $segs[0] === 'contact') $field = 'contact_type';
			elseif ($i === 1 && $segs[0] === 'geologic_struc') $field = 'geologic_structure_type';
		}
		if ($field === null) return $segs[$i];
		return FieldVocab::label($forms, $field, $segs[$i]);
	}

	/**
	 * Labels for a list of values: value => label, only where they differ.
	 *
	 * @param string $facet
	 * @param array  $values
	 * @return array
	 */
	public static function labels($facet, array $values)
	{
		$out = array();
		if (!self::handles($facet)) return $out;
		foreach ($values as $v) {
			if (!is_string($v) && !is_int($v) && !is_float($v)) continue;
			$l = self::label($facet, $v);
			if ((string)$l !== (string)$v) $out[(string)$v] = $l;
		}
		return $out;
	}

	/** Geologic unit tags (rock type, metamorphic grade live there). */
	private static function unitForms()
	{
		return FieldVocab::formsFor('tags', array('type' => 'geologic_unit'));
	}
}
