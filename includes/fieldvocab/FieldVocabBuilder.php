<?php
/**
 * File: includes/fieldvocab/FieldVocabBuilder.php
 * Description: Builds the StraboField choice map (stored choice NAME -> form
 *              LABEL, keyed by form and field) from the app's own KoBo form
 *              JSON (src/assets/forms/ in the StraboSpot/StraboField repo).
 *              Phase 1 of the Field choice translation work, see
 *              docs/edine_bug/TRANSLATION_SURFACE_AUDIT.md §2 and §10.
 *
 *              Input: the forms folder as an array of relative path =>
 *              file text (index.js + every *.json), so the same code runs on
 *              a local checkout (sync.php --from-dir) and on files fetched
 *              from GitHub (sync.php, nightly).
 *
 *              Map shape (schema 1):
 *                {
 *                  "schema": 1,
 *                  "generated_at": ISO 8601,
 *                  "source": {repo, tag, sha, registry_sha256, files: {path: sha256}},
 *                  "forms": {
 *                    "<category>.<formKey>": {          // registry key, forms/index.js
 *                      "file": "measurement/planar-orientation.json",
 *                      "fields": {
 *                        "<field>": {
 *                          "type": "select_one" | "select_multiple",
 *                          "list": "<KoBo list_name>",
 *                          "choices": {name: label},                 // current release
 *                          "ambiguous": {name: [label, ...]},        // same name twice in one list: never translated
 *                          "unlabeled": [name, ...],                 // empty label: never translated
 *                          "retired_choices": {name: {label, last_seen}},
 *                          "former_labels": {name: [label, ...]},    // labels a name used to have
 *                          "retired": {last_seen}                    // field gone from the form
 *                        }
 *                      },
 *                      "layout": [[name, label, type], ...],         // every data row in form order (additive,
 *                                                                    // 2026-09-24: legacy viewers' field lists)
 *                      "retired": {last_seen}                        // form gone from the registry
 *                    }
 *                  }
 *                }
 *
 *              Rules:
 *                - Only select_one / select_multiple survey rows (the other_
 *                  prefix is not a signal). Labels are trimmed.
 *                - The map only GROWS: merge() keeps every name, field and
 *                  form a previous map knew, marked retired with the last
 *                  tag that had it, so data written by older app builds
 *                  still translates.
 *                - validate() refuses a map that lost a core form or has too
 *                  few select fields (the sync then keeps the last good map);
 *                  warnings() lists oddities that do not block a sync (two
 *                  names with one label, a label equal to another name,
 *                  duplicate names, empty labels).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class FieldVocabBuilder
{
	const SCHEMA = 1;
	const REPO = 'StraboSpot/StraboField';
	const FORMS_DIR = 'src/assets/forms';

	/** Registry categories that are not spot/project data forms (duplicates or app settings). */
	const SKIP_CATEGORIES = array('measurement_bulk');

	/** Forms every build must contain; a missing one fails validation. */
	const CORE_FORMS = array(
		'measurement.planar_orientation', 'measurement.linear_orientation', 'measurement.tabular_orientation',
		'_3d_structures.fabric', '_3d_structures.fault', '_3d_structures.fold', '_3d_structures.other', '_3d_structures.tensor',
		'fabrics.fault_rock', 'fabrics.igneous_rock', 'fabrics.metamorphic_rock',
		'pet.plutonic', 'pet.volcanic', 'pet.metamorphic', 'pet.alteration_or', 'pet.fault', 'pet.minerals', 'pet.reactions',
		'pet_deprecated.igneous', 'pet_deprecated.metamorphic', 'pet_deprecated.alteration_or',
		'sed.lithology', 'sed.composition', 'sed.texture', 'sed.stratification',
		'sed.physical', 'sed.bedding_plane', 'sed.bioturbation', 'sed.pedogenic',
		'sed.process', 'sed.environment', 'sed.surfaces', 'sed.architecture',
		'sed.diagenesis', 'sed.fossils', 'sed.interval', 'sed.bedding', 'sed.strat_section',
		'tephra.basic', 'tephra.additional',
		'general.samples', 'general.images', 'general.trace', 'general.surface_feature', 'general.earthquakes',
		'project.tags', 'project.geologic_unit',
	);

	/** Sanity floor: v2.31.3 has 457 select fields. */
	const MIN_SELECT_FIELDS = 300;

	/**
	 * Parse forms/index.js into registry key => form file path.
	 *
	 * Reads the `import x from './a/b.json';` lines and the `key: ident,`
	 * entries inside each `category: { ... }` block of the `forms` object.
	 * Entries that are not a plain identifier (measurement_bulk objects,
	 * sed.lithologies = getSedimentaryRockForm(), which only relabels group
	 * headings of sed.lithology) are skipped.
	 *
	 * @param string $js  index.js text
	 * @return array  "category.key" => "relative/path.json"
	 * @throws Exception when the forms object cannot be found
	 */
	public static function parseRegistry($js)
	{
		$imports = array();
		if (preg_match_all('#^\s*import\s+(\w+)\s+from\s+[\'"]\./([^\'"]+\.json)[\'"]\s*;#m', $js, $m, PREG_SET_ORDER)) {
			foreach ($m as $row) $imports[$row[1]] = $row[2];
		}
		if (!$imports) throw new Exception('index.js: no form imports found');

		$start = strpos($js, 'const forms = {');
		if ($start === false) throw new Exception('index.js: "const forms = {" not found');
		$body = substr($js, $start + strlen('const forms = {'));

		$registry = array();
		$category = null;
		$depth = 0;
		foreach (preg_split('/\R/', $body) as $line) {
			$t = trim($line);
			if ($depth === 0 && preg_match('/^(\w+)\s*:\s*\{\s*$/', $t, $c)) {
				$category = $c[1];
				$depth = 1;
				continue;
			}
			if ($depth === 0 && preg_match('/^\}\s*;?\s*$/', $t)) break;   // end of forms object
			if ($depth >= 1) {
				if ($depth === 1 && preg_match('/^(\w+)\s*:\s*(\w+)\s*,?\s*$/', $t, $e)
					&& !in_array($category, self::SKIP_CATEGORIES, true) && isset($imports[$e[2]])) {
					$registry[$category . '.' . $e[1]] = $imports[$e[2]];
				}
				$depth += substr_count($t, '{') - substr_count($t, '}');
				if ($depth <= 0) { $depth = 0; $category = null; }
			}
		}
		if (!$registry) throw new Exception('index.js: forms object parsed to nothing');
		return $registry;
	}

	/**
	 * Extract the select fields of one KoBo form.
	 *
	 * @param string $json  form file text ({survey, choices, settings})
	 * @return array  field => {type, list, choices, [ambiguous]}
	 * @throws Exception on unparseable JSON or a select row whose list is missing
	 */
	public static function formFields($json)
	{
		$form = json_decode($json, true);
		if (!is_array($form) || !isset($form['survey']) || !is_array($form['survey'])) {
			throw new Exception('not a KoBo form (no survey array)');
		}
		$lists = array();   // list_name => [[name, label], ...] in file order
		foreach (isset($form['choices']) && is_array($form['choices']) ? $form['choices'] : array() as $c) {
			if (!isset($c['list_name'], $c['name'])) continue;
			$lists[(string)$c['list_name']][] = array((string)$c['name'], trim(isset($c['label']) ? (string)$c['label'] : ''));
		}

		$fields = array();
		foreach ($form['survey'] as $row) {
			if (!isset($row['type'], $row['name'])) continue;
			$parts = preg_split('/\s+/', trim((string)$row['type']));
			if (!in_array($parts[0], array('select_one', 'select_multiple'), true)) continue;
			if (!isset($parts[1]) || !isset($lists[$parts[1]])) {
				throw new Exception('select field "' . $row['name'] . '" names a missing choice list');
			}
			$choices = array();
			$seen = array();   // name => [labels]
			foreach ($lists[$parts[1]] as $pair) $seen[$pair[0]][] = $pair[1];
			$ambiguous = array();
			$unlabeled = array();
			foreach ($seen as $name => $labels) {
				$name = (string)$name;   // PHP int-coerces numeric keys ("5" -> 5)
				$labels = array_values(array_unique($labels));
				if (count($labels) > 1) $ambiguous[$name] = $labels;
				elseif ($labels[0] === '') $unlabeled[] = $name;
				else $choices[$name] = $labels[0];
			}
			$f = array('type' => $parts[0], 'list' => $parts[1], 'choices' => $choices);
			if ($ambiguous) $f['ambiguous'] = $ambiguous;
			if ($unlabeled) $f['unlabeled'] = $unlabeled;
			$fields[(string)$row['name']] = $f;
		}
		return $fields;
	}

	/** Survey row types that hold no stored value (groups, metadata, UI rows). */
	const NON_DATA_TYPES = array('begin_group', 'end_group', 'begin_repeat', 'end_repeat', 'begin', 'end',
		'calculate', 'start', 'acknowledge', 'note', 'deviceid', 'today', 'hidden');

	/**
	 * Every data row of one KoBo form in form order: [[name, label, type], ...].
	 * type is the first word of the survey type (text, select_one, decimal, ...);
	 * an empty label becomes the name.
	 *
	 * @param string $json  form file text
	 * @return array
	 * @throws Exception on unparseable JSON
	 */
	public static function formLayout($json)
	{
		$form = json_decode($json, true);
		if (!is_array($form) || !isset($form['survey']) || !is_array($form['survey'])) {
			throw new Exception('not a KoBo form (no survey array)');
		}
		$out = array();
		$seen = array();
		foreach ($form['survey'] as $row) {
			if (!isset($row['type'], $row['name']) || (string)$row['name'] === '') continue;
			$type = preg_split('/\s+/', trim((string)$row['type']))[0];
			if (in_array($type, self::NON_DATA_TYPES, true)) continue;
			$name = (string)$row['name'];
			if (isset($seen[$name])) continue;
			$seen[$name] = true;
			$label = trim(isset($row['label']) ? (string)$row['label'] : '');
			$out[] = array($name, $label === '' ? $name : $label, $type);
		}
		return $out;
	}

	/**
	 * Build a fresh map from the forms folder of one release.
	 *
	 * @param array  $files  relative path (under src/assets/forms/) => text; must include index.js
	 * @param string $tag    release tag, e.g. v2.31.3
	 * @param string $sha    commit SHA of the tag
	 * @return array map (see file header)
	 * @throws Exception when index.js or a registered form is missing or unparseable
	 */
	public static function build(array $files, $tag, $sha)
	{
		if (!isset($files['index.js'])) throw new Exception('forms folder has no index.js');
		$registry = self::parseRegistry($files['index.js']);
		ksort($registry);

		$hashes = array();
		foreach ($files as $path => $text) {
			if ($path !== 'index.js') $hashes[$path] = hash('sha256', $text);
		}
		ksort($hashes);

		$forms = array();
		foreach ($registry as $key => $path) {
			if (!isset($files[$path])) throw new Exception("registry form $key -> $path is missing from the forms folder");
			try {
				$fields = self::formFields($files[$path]);
			} catch (Exception $e) {
				throw new Exception("$path: " . $e->getMessage());
			}
			ksort($fields);
			$forms[$key] = array('file' => $path, 'fields' => $fields, 'layout' => self::formLayout($files[$path]));
		}

		return array(
			'schema'       => self::SCHEMA,
			'generated_at' => date('c'),
			'source'       => array(
				'repo'            => self::REPO,
				'tag'             => (string)$tag,
				'sha'             => (string)$sha,
				'registry_sha256' => hash('sha256', $files['index.js']),
				'files'           => $hashes,
			),
			'forms'        => $forms,
		);
	}

	/**
	 * Carry forward everything an older map knew (the map only grows).
	 *
	 * @param array      $new   fresh build()
	 * @param array|null $prev  previous map, or null on a first build
	 * @return array merged map
	 */
	public static function merge(array $new, $prev)
	{
		if (!is_array($prev) || !isset($prev['forms']) || !is_array($prev['forms'])) return $new;
		$prevTag = isset($prev['source']['tag']) ? (string)$prev['source']['tag'] : '';

		foreach ($prev['forms'] as $fkey => $pform) {
			$fkey = (string)$fkey;
			if (!isset($new['forms'][$fkey])) {
				$new['forms'][$fkey] = $pform;
				foreach ($new['forms'][$fkey]['fields'] as $fname => $pf) {
					$new['forms'][$fkey]['fields'][$fname] = self::retireField($pf, $prevTag);
				}
				if (!isset($pform['retired'])) $new['forms'][$fkey]['retired'] = array('last_seen' => $prevTag);
				continue;
			}
			foreach (isset($pform['fields']) ? $pform['fields'] : array() as $fname => $pf) {
				$fname = (string)$fname;
				if (!isset($new['forms'][$fkey]['fields'][$fname])) {
					$new['forms'][$fkey]['fields'][$fname] = self::retireField($pf, $prevTag);
					continue;
				}
				$new['forms'][$fkey]['fields'][$fname] = self::mergeField($new['forms'][$fkey]['fields'][$fname], $pf, $prevTag);
			}
			ksort($new['forms'][$fkey]['fields']);
		}
		ksort($new['forms']);
		return $new;
	}

	/** A field the new release dropped: every current choice becomes retired. */
	private static function retireField(array $pf, $prevTag)
	{
		if (isset($pf['retired'])) return $pf;   // already retired earlier: keep its last_seen
		$retired = isset($pf['retired_choices']) ? $pf['retired_choices'] : array();
		foreach ($pf['choices'] as $name => $label) {
			$retired[(string)$name] = array('label' => $label, 'last_seen' => $prevTag);
		}
		$pf['choices'] = array();
		if ($retired) $pf['retired_choices'] = $retired;
		$pf['retired'] = array('last_seen' => $prevTag);
		return $pf;
	}

	/** Same field in both maps: keep names the new release dropped, remember relabels. */
	private static function mergeField(array $nf, array $pf, $prevTag)
	{
		$retired = isset($pf['retired_choices']) ? $pf['retired_choices'] : array();
		$former = isset($pf['former_labels']) ? $pf['former_labels'] : array();
		$ambig = isset($nf['ambiguous']) ? $nf['ambiguous'] : array();

		foreach ($pf['choices'] as $name => $label) {
			$name = (string)$name;
			if (isset($nf['choices'][$name])) {
				if ($nf['choices'][$name] !== $label) self::addFormer($former, $name, $label);
			} elseif (!isset($ambig[$name])) {
				$retired[$name] = array('label' => $label, 'last_seen' => $prevTag);
			}
		}
		// A retired name that is back in the release is current again.
		foreach (array_keys($retired) as $name) {
			$name = (string)$name;
			if (isset($nf['choices'][$name])) {
				if ($retired[$name]['label'] !== $nf['choices'][$name]) self::addFormer($former, $name, $retired[$name]['label']);
				unset($retired[$name]);
			}
		}
		foreach (isset($pf['former_labels']) ? $pf['former_labels'] : array() as $name => $labels) {
			foreach ($labels as $l) self::addFormer($former, (string)$name, $l);
		}
		foreach ($former as $name => $labels) {
			$cur = isset($nf['choices'][$name]) ? $nf['choices'][$name] : null;
			$former[$name] = array_values(array_filter($labels, function ($l) use ($cur) { return $l !== $cur; }));
			if (!$former[$name]) unset($former[$name]);
		}

		if ($retired) { ksort($retired, SORT_STRING); $nf['retired_choices'] = $retired; }
		if ($former) { ksort($former, SORT_STRING); $nf['former_labels'] = $former; }
		return $nf;
	}

	private static function addFormer(array &$former, $name, $label)
	{
		if (!isset($former[$name])) $former[$name] = array();
		if (!in_array($label, $former[$name], true)) $former[$name][] = $label;
	}

	/**
	 * Check a map before it replaces the live one. Only problems that would
	 * make the map unusable fail it; oddities that still translate correctly
	 * name -> label are warnings() instead, so one app-side quirk never
	 * freezes every later update.
	 *
	 * @param array $map
	 * @return string[] problems (empty = valid)
	 */
	public static function validate($map)
	{
		$errors = array();
		if (!is_array($map) || (isset($map['schema']) ? $map['schema'] : null) !== self::SCHEMA || !isset($map['forms']) || !is_array($map['forms'])) {
			return array('not a schema ' . self::SCHEMA . ' field vocab map');
		}
		foreach (self::CORE_FORMS as $k) {
			if (!isset($map['forms'][$k])) $errors[] = "core form $k is missing";
			elseif (isset($map['forms'][$k]['retired'])) $errors[] = "core form $k is not in this release";
		}
		$selects = 0;
		foreach ($map['forms'] as $form) {
			if (isset($form['retired'])) continue;
			foreach ($form['fields'] as $f) {
				if (!isset($f['retired'])) $selects++;
			}
		}
		if ($selects < self::MIN_SELECT_FIELDS) $errors[] = "only $selects select fields (expected at least " . self::MIN_SELECT_FIELDS . ')';
		return $errors;
	}

	/**
	 * Oddities in the current (non-retired) choice lists that do not block a
	 * sync but matter for the reverse direction (label -> name, Template
	 * Wizard import) and are worth a look:
	 *   - two names with one label in a field (both display the same text)
	 *   - a label equal to a different choice's name in the same field
	 *   - the same name listed twice with different labels (never translated)
	 *   - a choice with an empty label (never translated)
	 *
	 * @param array $map
	 * @return string[]
	 */
	public static function warnings(array $map)
	{
		$out = array();
		foreach ($map['forms'] as $fkey => $form) {
			if (isset($form['retired'])) continue;
			foreach ($form['fields'] as $fname => $f) {
				if (isset($f['retired'])) continue;
				$byLabel = array();
				foreach ($f['choices'] as $name => $label) $byLabel[$label][] = (string)$name;
				foreach ($byLabel as $label => $names) {
					if (count($names) > 1) $out[] = "$fkey.$fname: label \"$label\" is shared by names " . implode(', ', $names);
				}
				foreach ($f['choices'] as $name => $label) {
					if ((string)$label !== (string)$name && isset($f['choices'][$label])) $out[] = "$fkey.$fname: label \"$label\" (of $name) is also the name of another choice";
				}
				foreach (isset($f['ambiguous']) ? $f['ambiguous'] : array() as $name => $labels) {
					$out[] = "$fkey.$fname: name $name is listed " . count($labels) . ' times (' . implode(' | ', $labels) . '), shown raw';
				}
				foreach (isset($f['unlabeled']) ? $f['unlabeled'] : array() as $name) {
					$out[] = "$fkey.$fname: choice $name has no label, shown raw";
				}
			}
		}
		return $out;
	}

	/**
	 * Human-readable difference between two maps (for the sync log / mail).
	 * Compares current (non-retired) content only.
	 *
	 * @param array|null $old
	 * @param array      $new
	 * @return string[] one line per change (empty = no label change)
	 */
	public static function diff($old, array $new)
	{
		$lines = array();
		$o = is_array($old) && isset($old['forms']) ? $old['forms'] : array();
		foreach ($new['forms'] as $fkey => $form) {
			$live = !isset($form['retired']);
			$was = isset($o[$fkey]) && !isset($o[$fkey]['retired']);
			if ($live && !$was) { $lines[] = "form added: $fkey"; continue; }
			if (!$live && $was) { $lines[] = "form retired: $fkey"; continue; }
			if (!$live) continue;
			foreach ($form['fields'] as $fname => $f) {
				$pf = isset($o[$fkey]['fields'][$fname]) && !isset($o[$fkey]['fields'][$fname]['retired']) ? $o[$fkey]['fields'][$fname] : null;
				if (isset($f['retired'])) {
					if ($pf) $lines[] = "field retired: $fkey.$fname";
					continue;
				}
				if (!$pf) { $lines[] = "field added: $fkey.$fname (" . count($f['choices']) . ' choices)'; continue; }
				foreach ($f['choices'] as $name => $label) {
					if (!isset($pf['choices'][$name])) $lines[] = "choice added: $fkey.$fname $name = \"$label\"";
					elseif ($pf['choices'][$name] !== $label) $lines[] = "label changed: $fkey.$fname $name \"{$pf['choices'][$name]}\" -> \"$label\"";
				}
				foreach ($pf['choices'] as $name => $label) {
					if (!isset($f['choices'][$name])) $lines[] = "choice retired: $fkey.$fname $name (\"$label\")";
				}
			}
		}
		return $lines;
	}

	/**
	 * Stable JSON for the map file (readable diffs of the repo baseline).
	 * Name-keyed maps are forced to JSON objects: PHP int-coerces numeric
	 * names, so a list named "0","1","2" (or an empty one) would otherwise
	 * encode as a JSON array.
	 */
	public static function encode(array $map)
	{
		$obj = function ($a) { return (object)$a; };
		foreach ($map['forms'] as $fkey => $form) {
			foreach ($form['fields'] as $fname => $f) {
				foreach (array('choices', 'ambiguous', 'retired_choices', 'former_labels') as $k) {
					if (isset($f[$k])) $f[$k] = $obj($f[$k]);
				}
				$form['fields'][$fname] = $f;
			}
			$form['fields'] = $obj($form['fields']);
			$map['forms'][$fkey] = $form;
		}
		$map['forms'] = $obj($map['forms']);
		if (isset($map['source']['files'])) $map['source']['files'] = $obj($map['source']['files']);
		$out = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($out === false) throw new Exception('map JSON encode failed: ' . json_last_error_msg());
		return $out . "\n";
	}
}
