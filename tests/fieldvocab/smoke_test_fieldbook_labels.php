<?php
/**
 * File: tests/fieldvocab/smoke_test_fieldbook_labels.php
 * Description: Field choice translation Phase 3: both fieldbooks print form
 *              LABELS for the golden fixture (golden_fixture.php, uploaded like
 *              the app).
 *
 *              Legacy: straboOutputClass::addSpotToPDF against a recording stub.
 *              Enhanced: FieldbookModel::spotBlock + blockScalars.
 *              Checks, for both books:
 *                - every expected label is printed (orientation, 3D structure,
 *                  sample, pet current + legacy flat, fabrics, trace, sed,
 *                  tephra, geologic unit and tag values)
 *                - no raw choice name that has a distinct label is printed
 *                  (option_13, basaltic_andes, ...), neither raw nor prettified
 *                - legacy minerals still branch on the raw class: "(Igneous)"
 *              Enhanced only: D6 casing (cell values as written, item titles
 *              and legends with a leading capital), featureKey keeps the raw
 *              name for the stereonet symbol slots, image type raw + label.
 *              Both real PDFs build (fieldbookOut, legacyFieldbookOut).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/fieldvocab/smoke_test_fieldbook_labels.php
 */

$SID_PREFIX = 'fvfbook';
require_once __DIR__ . '/fixture_lib.php';
require_once 'includes/fieldbook/Fieldbook.php';

/** Records every (label, value) the legacy walker emits (tests/fieldbook/parity_test_keys.php). */
class FvRecordingPdf {
	public $rows = array();
	function valueRow($label, $value = null, $xpos = null) { $this->rows[] = array((string)$label, (string)$value); }
	function valueTitle($text, $xpos = null) { $this->rows[] = array('#title', rtrim((string)$text, ': ')); }
	function spotTitle($name, $xpos = 15) { $this->rows[] = array('Spot Name', (string)$name); }
	function notesRow($label, $value = null, $xpos = null) { $this->rows[] = array((string)$label, (string)$value); }
	function petNotesRow($label, $value = null, $xpos = null) { $this->rows[] = array((string)$label, (string)$value); }
	function imageCaptionRow($label, $value = null, $xpos = null) { $this->rows[] = array((string)$label, (string)$value); }
	function lowValueRow($label, $value = null, $xpos = null) { $this->rows[] = array((string)$label, (string)$value); }
	function dailyNotesRow($label, $value = null, $xpos = null) { $this->rows[] = array((string)$label, (string)$value); }
	function largeValue($val, $xpos = 15) { $this->rows[] = array('', (string)$val); }
	function httpLink($text, $xpos = null, $href = null) { $this->rows[] = array('', (string)$text); }
	function __call($m, $a) {}
}

/** Label the fixture must print => the stored name it comes from. */
$EXPECT = array(
	'joint' => 'option_13', "R'-fracture" => 'option_7', 'T-fracture' => 'option_8', '5 - excellent' => '5', '5 - best - accurate' => '5',
	'1 - poor' => '1', 'sedimentary feature' => 'sedimentary_fe', 'geomorphic feature' => 'geomorphic_fea',
	'flow/transport direction' => 'flow_transport', 'zone of fracturing' => 'zone_fracturin', 'gentle (180-120)' => 'gentle',
	'class 1B (parallel)' => 'class_1b__para', 'NE' => 'ne', '5 - straight' => '5___straight', 'dextral strike-slip' => 'dextral',
	'NE side up' => 'ne_side_up', 'crescentic fractures' => 'crescentic_fra', 'oblique gouge foliation' => 'oblique_gouge',
	'L >> S' => 'l___s_1', 'fragmented rock' => 'fragmented_roc', 'fabric / microstructure' => 'fabric___micro',
	'5 - definitely in place' => '5___definitely', '1 - highly weathered' => '1___highly_wea', 'basaltic-andesite' => 'basaltic_andes',
	'quartz monzonite' => 'quartz_monz', 'calc-silicate' => 'calc_silicate', 'mudrock/pelite' => 'pelite',
	'prehnite-pumpyellite' => 'prehnite_pumpy', 'garnet-cordierite' => 'garnet_corider', 'dolorite/mica replacement' => 'dolorite_mica_',
	'albite-epidote' => 'albite_epidote', 'mineral degradation (unhappiness)' => 'mineral_degrad', 'S only' => 's',
	'fracture' => 'fractures', 'gneissic banding' => 'gneissic_band', 'fold or bedding' => 'fold_bed', 'meta-ultramafic' => 'meta_ultramafi',
	'ultra-high pressure' => 'ultra_high_pre', 'geologic structure' => 'geologic_struc', 'fold axial trace' => 'fold_axial_tra',
	'mixed clastic-carbonate' => 'mixed_clastic', 'facies architecture' => 'facies_arch', 'reservoir characterization' => 'res_char',
	'multiple outcrop' => 'multi_outcrop', 'point at section base' => 'point_at_secti', 'volcanic mudstone' => 'volcanic_mudst',
	'thin (3-10 cm)' => 'thin', 'thin (0.1-0.3 cm)' => 'thin', 'organic/coal' => 'organic_coal', 'coal ball' => 'coal_ball',
	'cross bedding (general)' => 'cross_bedding', 'normally graded' => 'normally_grade', 'rip-up clasts' => 'rip_up_clasts',
	'Subaerial exposure' => 'subaerial_expo', 'Alluvial fan' => 'alluvial_fan', 'cavern/cavities' => 'cavern', 'porifera/sponge' => 'porifera_spong',
	'bioherm (lens-shaped)' => 'bioherm_lens', 'sharp' => 'flat', 'well-defined' => 'well_defined', 'tabular/parallel' => 'tabular_parall',
	'undifferentiated or undescribed' => 'package', 'None or massive' => 'none_or_massive', 'alkali feldspar granite' => 'alkali_granite',
	'Phanerozoic' => 'phanerozoic', 'Mesozoic' => 'mesozoic', 'greenschist facies' => 'greenschist_fa',
	'Geological Structure' => 'geological_structure', 'Observation Timing' => 'observation_timing',
);
/**
 * Fixture names the legacy walker printed BEFORE translation (captured 2026-09-24 by running
 * the unchanged straboOutputClass on this fixture). It never printed the rest: multi-selects in
 * the generic blocks come out as "Array", fabrics and alteration are not rendered, and
 * bedding_thickness hits implode() on a string. For these, the label must now be printed.
 */
$LEGACY_PRINTED = array_flip(explode(' ', 'concept geological_structure documentation observation_timing geologic_unit Kfg igneous plutonic '
	. 'alkali_granite phanerozoic mesozoic metamorphic greenschist_fa proterozoic planar_orientation option_13 5 upright sedimentary_fe '
	. 'linear_orientation flow_transport 1 fracture option_7 option_8 tabular_orientation zone_fracturin fold s_fold gentle ne fault dextral '
	. 'ne_side_up fabric tectonite ls_tectonite fragmented_roc yes _m volcanic basaltic_andes quartz_monz calc_silicate pelite prehnite_pumpy '
	. 'garnet_corider fractures geologic_struc fold_axial_tra other golden mixed_clastic facies_arch res_char multi_outcrop point_at_secti m '
	. 'volcaniclastic volcanic_mudst glass thin organic_coal coal_ball cross_bedding normally_grade rip_up_clasts tidal_flat subaerial_expo '
	. 'alluvial_fan pods cavern recrystallized porifera_spong bioherm_lens lithology_1 tabular_parall flat well_defined rock_unit approximate(?) '
	. 'package none_or_massive clay cm'));
$LEGACY_NOT_A_FIELD = array('thin (3-10 cm)');   // legacy prints "thin" only for laminae (bedding_thickness implode bug, above)

/** "a_b_c" + its fixLabel ("A B C") + humanize ("A b c") forms, lowercased. */
function raw_forms($n) {
	$sp = strtolower(trim(preg_replace('/_+/', ' ', $n)));
	return array_unique(array(strtolower($n), $sp));
}
function printed_has($printed, $needle) {
	foreach ($printed as $p) if (strpos($p, $needle) !== false) return true;
	return false;
}
/** A raw name counts as printed when it is a whole printed value or a whole comma part of one. */
function printed_raw($printed, $forms) {
	foreach ($printed as $p) {
		foreach (array_merge(array($p), explode(', ', $p)) as $part) {
			$part = strtolower(trim($part, " :"));
			if (in_array($part, $forms, true)) return $part;
		}
	}
	return null;
}

echo "Field choice translation: fieldbook labels (Phase 3)\n";
gf_cleanup();
try {
	section('Fixture');
	gf_upload();

	$legacy = array(); $legacyVals = array(); $enh = array(); $blocks = array(); $legacyRows = array();
	foreach (gf_datasets() as $dsid => $dsname) {
		$strabo = fresh_strabo();
		$get = array('dsids' => (string)$dsid, 'userpkey' => $OWNER);
		$json = $strabo->getDatasetSpotsSearch(null, $get);
		$features = $json['features'];
		$tags = $strabo->getTagsFromDatasetIds((string)$dsid);
		$tagsArr = is_array($tags) ? $tags : array();
		$out = new straboOutputClass($strabo, $get);
		$out->alltags = $tags;
		foreach ($features as $f) {
			$rec = new FvRecordingPdf();
			$spot = $f;
			ob_start(); $out->addSpotToPDF($rec, $spot, $features, 0); ob_end_clean();
			foreach ($rec->rows as $r) {
				$legacyRows[] = $r;
				if ($r[1] === '') continue;
				$legacy[] = $r[1];
				if ($r[0] !== '#title') $legacyVals[] = $r[1];   // section headings ("Fractures:") are not stored values
			}
			$b = FieldbookModel::spotBlock($f, $tagsArr);
			$blocks[(string)$f['properties']['id']] = $b;
			$sc = array(); FieldbookModel::blockScalars($b, $sc);
			foreach ($sc as $v) $enh[] = (string)$v;
		}
	}
	check('legacy walker printed rows', count($legacy) > 50, count($legacy));
	check('enhanced model has scalars', count($enh) > 50, count($enh));

	section('Expected labels printed');
	$missL = array(); $missE = array(); $nL = 0;
	foreach ($EXPECT as $l => $raw) {
		if (isset($LEGACY_PRINTED[$raw]) && !in_array($l, $LEGACY_NOT_A_FIELD, true)) { $nL++; if (!printed_has($legacy, $l)) $missL[] = $l; }
		if (!printed_has($enh, $l) && !printed_has($enh, ucfirst($l))) $missE[] = $l;
	}
	check("legacy book prints the label of all $nL names it printed before", !$missL, 'missing: ' . implode(' | ', $missL));
	check('enhanced book prints all ' . count($EXPECT) . ' labels', !$missE, 'missing: ' . implode(' | ', $missE));

	section('Raw names with a distinct label are not printed');
	$lowL = array_map('strtolower', $legacyVals); $lowE = array_map('strtolower', $enh);
	$leakL = array(); $leakE = array(); $nRaw = 0;
	foreach ($EXPECT as $l => $raw) {
		$forms = raw_forms($raw);
		if (in_array(strtolower($l), $forms, true) || ctype_digit($raw) || strlen($raw) < 3) continue;
		if ($raw === 'tabular_parall') continue;   // the fixture also stores it OFF-form (sed.bedding level): that copy rightly stays raw   // label = name modulo case/underscores, or too short to tell
		$nRaw++;
		if (isset($LEGACY_PRINTED[$raw]) && ($hit = printed_raw($lowL, $forms)) !== null) $leakL[] = "$raw ($hit)";
		if (($hit = printed_raw($lowE, $forms)) !== null) $leakE[] = "$raw ($hit)";
	}
	check('legacy book: none of the raw names it used to print', !$leakL, implode(' | ', $leakL));
	check("enhanced book: none of $nRaw raw names", !$leakE, implode(' | ', $leakE));

	section('Logic keys + casing');
	$S = gf_spots();
	$mineral = null;
	foreach ($legacyRows as $r) if (strpos($r[1], '(Igneous)') !== false || strpos($r[1], '(Metamorphic)') !== false) $mineral = $r[1];
	check('legacy minerals: raw class still selects "(Igneous)"', $mineral !== null && strpos($mineral, '(Igneous)') !== false, (string)$mineral);
	$st = $blocks[(string)$S['structure']];
	check('orientation cell = label as written: "joint"', $st['orientations'][0]['feature'] === 'joint', $st['orientations'][0]['feature']);
	check('orientation featureKey keeps the raw name for the nets', $st['orientations'][0]['featureKey'] === 'option_13');
	check('associated lineation: label + raw key', $st['orientations'][0]['children'][0]['feature'] === 'flow/transport direction' && $st['orientations'][0]['children'][0]['featureKey'] === 'flow_transport');
	check('tabular quality uses the tabular list', $st['orientations'][3]['quality'] === '5 - best - accurate', $st['orientations'][3]['quality']);
	check('image: type raw for logic, typeLabel printed', $st['images'][0]['type'] === 'geological_cs' && $st['images'][0]['typeLabel'] === 'geological cross section');
	$titles = array();
	foreach ($st['families'] as $fam) foreach ($fam['rows'] as $r) if ($r['h']) $titles[] = $r['k'];
	check('3D structure item titles = their free-text labels, capitalised ("Fold", "Tectonite")', in_array('Fold', $titles, true) && in_array('Tectonite', $titles, true), implode(' | ', $titles));
	$fabTitles = array();
	foreach ($blocks[(string)$S['petrology']]['families'] as $fam) if ($fam['key'] === 'fabrics') foreach ($fam['rows'] as $r) if ($r['h']) $fabTitles[] = $r['k'];
	check('fabric item titles keep their free-text labels', in_array('Fault rock fabric', $fabTitles, true) || in_array('fault rock fabric', $fabTitles, true), implode(' | ', $fabTitles));
	$nets = new FieldbookNets();
	$sym = $nets->symbol(true, 'fracture', 'fracture');
	check('nets: slot picked from the raw key (fracture -> hexagon), legend capitalised', $sym['shape'] === 'hexagon' && $sym['label'] === 'Fracture', json_encode($sym));
	$sym2 = $nets->symbol(true, 'joint', 'option_13');
	check('nets: option_13 is not the "joint" slot (raw key decides), legend "Joint"', $sym2['shape'] !== 'tridown' && $sym2['label'] === 'Joint', json_encode($sym2));
	$unitRows = array();
	foreach ($blocks[(string)$S['petrology']]['units'] as $u) foreach ($u['rows'] as $r) $unitRows[$r['k']] = $r['v'];
	check('geologic unit rows translated', isset($unitRows['Plutonic rock types']) && $unitRows['Plutonic rock types'] === 'alkali feldspar granite' && $unitRows['Eon'] === 'Phanerozoic', json_encode($unitRows));
	check('tag type heading label: tags form "Concept"', FieldbookProps::tagType('concept') === 'Concept' && FieldbookProps::tagType('geologic_unit') === 'Geologic unit');

	section('DOI PDF walker (doiOutputClass, new DOIs)');
	require_once 'doi/doiOutputClass.php';
	$doiDir = "/srv/app/www/doi/doiFiles/$DOI_UUID";
	@mkdir($doiDir, 0775, true);
	$o = new straboOutputClass(fresh_strabo(), array());
	file_put_contents("$doiDir/data.json", json_encode($o->doiDataOut(GF_PROJECT), JSON_PRETTY_PRINT));   // as build_doi.php writes it
	@mkdir("$doiDir/images", 0775, true);   // build_doi.php copies each image as images/<id>.jpg; the walker exit()s without it
	$im = imagecreatetruecolor(64, 48); imagejpeg($im, "$doiDir/images/977910000401.jpg", 80); imagedestroy($im);
	$doc = new doiOutputClass(fresh_strabo(), array());
	$doiVals = array();
	foreach ($doc->getProjectDatasets($DOI_UUID)->datasets as $d) {
		$d = (array)$d;
		$spots = $doc->getDatasetSpots($DOI_UUID, $d['id']);
		foreach ($spots as $sp) {
			$sp = (array)$sp; $props = (array)$sp['properties'];
			if (!empty($props['image_basemap'])) continue;
			$rec = new FvRecordingPdf();
			ob_start(); $doc->addSpotToPDF($DOI_UUID, $rec, $props, $spots, 5); ob_end_clean();
			foreach ($rec->rows as $r) if ($r[1] !== '' && $r[0] !== '#title') $doiVals[] = $r[1];
		}
	}
	// The DOI PDF never prints tags or geologic units (alltags is never set: audit §9.2), so those are not expected.
	$noTags = array('alkali feldspar granite', 'Phanerozoic', 'Mesozoic', 'greenschist facies', 'Geological Structure', 'Observation Timing');
	$missD = array(); $leakD = array(); $nD = 0;
	$lowD = array_map('strtolower', $doiVals);
	foreach ($EXPECT as $l => $raw) {
		if (!isset($LEGACY_PRINTED[$raw]) || in_array($l, $LEGACY_NOT_A_FIELD, true) || in_array($l, $noTags, true)) continue;
		$nD++;
		if (!printed_has($doiVals, $l)) $missD[] = $l;
		$forms = raw_forms($raw);
		if (in_array(strtolower($l), $forms, true) || ctype_digit($raw) || strlen($raw) < 3 || $raw === 'tabular_parall') continue;
		if (($hit = printed_raw($lowD, $forms)) !== null) $leakD[] = "$raw ($hit)";
	}
	check("DOI walker prints the labels ($nD)", !$missD, 'missing: ' . implode(' | ', $missD));
	check('DOI walker prints none of those raw names', !$leakD, implode(' | ', $leakD));
	$mineral = null;
	foreach ($doiVals as $v) if (strpos($v, '(Igneous)') !== false || strpos($v, '(Metamorphic)') !== false) $mineral = $v;
	check('DOI walker minerals: raw class still selects "(Igneous)"', $mineral !== null && strpos($mineral, '(Igneous)') !== false, (string)$mineral);
	@unlink("$doiDir/project.pdf");
	// doiPDFOut require()s PDF_LabBook (not _once), so it runs in its own process, as in build_doi.php
	$child = "chdir('/srv/app/www'); \$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www'; error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);"
		. " require 'includes/config.inc.php'; require 'db.php'; require 'neodb.php'; require 'includes/UUID.php'; require 'db/strabospotclass.php';"
		. " require 'doi/doiOutputClass.php'; \$s = new StraboSpot(\$neodb, $OWNER, \$db); \$s->setuuid(new UUID());"
		. " (new doiOutputClass(\$s, array()))->doiPDFOut('$DOI_UUID', true);";
	$stray = (string)shell_exec('php -r ' . escapeshellarg($child) . ' 2>&1');
	$pdfBytes = (string)@file_get_contents("$doiDir/project.pdf");
	check('DOI project.pdf built (doiPDFOut saveToDisk, the build_doi.php call)', strpos($pdfBytes, '%PDF') === 0 && strlen($pdfBytes) > 5000, strlen($pdfBytes) . ' bytes, stray: ' . substr($stray, 0, 200));

	section('Real PDFs build');
	Fieldbook::$mapsOverride = array('set' => 'none');
	// the legacy book takes ONE dataset (two dsids build invalid Cypher: long-standing, unlinked download)
	foreach (array(array('fieldbookOut', 'enhanced', GF_DS_A . ',' . GF_DS_B), array('legacyFieldbookOut', 'legacy A', (string)GF_DS_A), array('legacyFieldbookOut', 'legacy B', (string)GF_DS_B)) as $run) {
		list($method, $what, $dsids) = $run;
		$dir = sys_get_temp_dir() . '/fvfb_' . str_replace(' ', '_', $what) . '_' . getmypid();
		exec('rm -rf ' . escapeshellarg($dir)); mkdir($dir, 0775, true);
		$o = new straboOutputClass(fresh_strabo(), array('dsids' => $dsids, 'userpkey' => $OWNER));
		$o->captureDir = $dir;
		ob_start(); $o->$method(); $stray = ob_get_clean();
		$pdfs = glob("$dir/*.pdf");
		$bytes = $pdfs ? file_get_contents($pdfs[0]) : '';
		check("$what fieldbook PDF built", strpos($bytes, '%PDF') === 0 && strlen($bytes) > 5000, count($pdfs) . ' pdf, ' . strlen($bytes) . ' bytes, stray: ' . substr($stray, 0, 200));
		exec('rm -rf ' . escapeshellarg($dir));
	}
} catch (Throwable $e) {
	check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
	gf_cleanup();
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
