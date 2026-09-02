<?php
/**
 * File: tests/fieldbook/parity_test_keys.php
 * Description: Completeness guarantee of the enhanced fieldbook
 *              (docs/Fieldbook_Design.md §9, decision D7): every value the
 *              LEGACY fieldbook walker (straboOutputClass::addSpotToPDF)
 *              prints for a spot must appear in the new document model
 *              (FieldbookModel::spotBlock). The legacy walker runs against a
 *              recording stub in place of the PDF object; the new model's
 *              scalars come from FieldbookModel::blockScalars. Tokens are
 *              normalised (case, underscores, ISO / epoch timestamps,
 *              numeric precision) before comparison.
 *
 *              Fixtures: real datasets of userpkey 3 on dev (read-only;
 *              each is skipped with a note when absent, e.g. on prod) plus
 *              any dataset ids given on the command line.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/fieldbook/parity_test_keys.php [dsid ...]
 */
chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('memory_limit', '2G');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/geophp/geoPHP.inc';
require_once 'includes/UUID.php';
require_once 'db/strabospotclass.php';
require_once 'includes/straboClasses/straboOutputClass.php';
require_once 'includes/fieldbook/Fieldbook.php';

$OWNER = 3;
$DATASETS = array(
	'17743978188553' => 'Fiddlers Green / Geology (orientations, samples, basemap children, photos)',
	'16437510335698' => 'GEOL 320 / Core Data (sed, strat sections)',
	'16529991929500' => 'FullyPopulatedProject (pet, fabrics, 3D, other, sed)',
	'17284172186138' => 'Crashtest (sed, pet, 3D, other)',
	'17262580674868' => 'Tags Test (custom fields)',
	'17158683183764' => 'PDF work / dataset2 (sed, tephra, samples)',
);
for ($i = 1; $i < $argc; $i++) $DATASETS[(string)(int)$argv[$i]] = 'command line';

$pass = 0; $fail = 0; $skip = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}

/** Records every (label, value) the legacy walker emits. */
class RecordingPdf {
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
	function GDImage($im, $x = null, $y = null, $w = 0, $h = 0, $link = '') {}
	function MemImage($d, $x = null, $y = null, $w = 0, $h = 0, $link = '') {}
	function Ln($h = null) {}
	function __call($m, $a) {}
}

/** Normalise a printed token for comparison. */
function norm($s) {
	$s = trim((string)$s);
	if ($s === '') return '';
	if (preg_match('/^\d{10}(\d{3})?$/', $s)) return strtolower(gmdate('F j, Y H:i', (int)substr($s, 0, 10)) . ' utc');
	if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $s) && ($t = strtotime($s)) !== false) return strtolower(gmdate('F j, Y H:i', $t) . ' utc');
	if (is_numeric($s)) return rtrim(rtrim(sprintf('%.5f', (float)$s), '0'), '.');
	$s = mb_strtolower($s, 'UTF-8');
	$s = str_replace('&', 'and', $s);
	$s = preg_replace('/_+/', ' ', $s);
	$s = preg_replace('/\s+/u', ' ', $s);
	return trim($s, " :");
}

/** Every alphanumeric chunk (digits split from letters, >= 2 chars) of $n occurs in $hay. */
function chunks_present($n, $hay) {
	$parts = preg_split('/[^a-z0-9]+/u', $n);
	$chunks = array();
	foreach ($parts as $p) {
		if ($p === '') continue;
		foreach (preg_split('/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/', $p) as $c) if (mb_strlen($c, 'UTF-8') >= 2 || ctype_digit($c)) $chunks[] = $c;
	}
	if (count($chunks) < 2) return false;
	$alias = array('avg' => 'average', 'max' => 'maximum', 'min' => 'minimum');   // legacy abbreviations of stored keys
	foreach ($chunks as $c) {
		if (strpos($hay, $c) !== false) continue;
		if (isset($alias[$c]) && strpos($hay, $alias[$c]) !== false) continue;
		return false;
	}
	return true;
}

/** Legacy labels that are bookkeeping, not observations (design §4.1 system keys). */
$SKIP_LABELS = array('created by', 'self', 'symbology', 'viewed timestamp', 'notestimestamp', 'userpkey', 'notes timestamp');
/** Legacy heading tokens the new book expresses structurally. */
$SKIP_TOKENS = array('planar orientation', 'linear orientation', 'tabular orientation', 'orientations', 'samples', 'tags', 'geologic unit(s)', 'geologic unit', 'images', 'other features', '3d structures', 'sed', 'tephra intervals', 'interval', 'strat section', 'lithologies', 'associated orientation data', 'download strat section', 'spots on basemap', 'array', 'yes', 'no', 'true', 'false', '1', '0', 'point', 'linestring', 'polygon', 'multipoint');

$strabo = new StraboSpot($neodb, $OWNER, $db); $strabo->setuuid(new UUID());
echo "Enhanced fieldbook key-parity harness (legacy walker vs FieldbookModel)\n";
$totalSpots = 0;
foreach ($DATASETS as $dsid => $label) {
	$get = array('dsids' => $dsid, 'userpkey' => $OWNER);
	$json = $strabo->getDatasetSpotsSearch(null, $get);
	if (!$json || empty($json['features'])) { $skip++; echo "  SKIP  dataset $dsid ($label): not on this database\n"; continue; }
	$features = $json['features'];
	$tags = $strabo->getTagsFromDatasetIds($dsid);
	$out = new straboOutputClass($strabo, $get);
	$out->alltags = $tags;
	$tagsArr = is_array($tags) ? $tags : array();
	$missingTotal = 0; $checked = 0; $examples = array();
	foreach ($features as $f) {
		$rec = new RecordingPdf();
		$spot = $f;
		ob_start();
		$out->addSpotToPDF($rec, $spot, $features, 0);   // recurses into basemap children like the book does
		ob_end_clean();
		// raw scalars of the feature: a legacy heading (valueTitle) only counts as data when the
		// same string is stored in the spot (tag name, sample label); hand-written section titles
		// such as "Lithification & Color" are structure, expressed differently by the new book
		$rawVals = array(); FieldbookProps::scalars($f['properties'], $rawVals);
		$rawSet = array(); foreach ($rawVals as $rv) { $n = norm($rv); if ($n !== '') $rawSet[$n] = true; }
		foreach ($tagsArr as $t) { $t = (array)$t; if (isset($t['name'])) $rawSet[norm($t['name'])] = true; }
		$legacy = array();
		foreach ($rec->rows as $r) {
			list($lab, $val) = $r;
			if ($lab === '#title' && !isset($rawSet[norm($val)])) continue;
			if (in_array(norm($lab), $SKIP_LABELS, true)) continue;
			foreach (array($val) as $v) {
				$n = norm($v);
				if ($n === '' || in_array($n, $SKIP_TOKENS, true)) continue;
				$legacy[$n] = $v;
				// comma-joined arrays were printed as one string; accept the parts too
				foreach (explode(', ', $v) as $part) { $np = norm($part); if ($np !== '') $legacy[$np] = $part; }
			}
		}
		// new model: this spot with its nested children (the legacy call above printed the children too)
		$b = FieldbookModel::spotBlock($f, $tagsArr);
		$new = array();
		FieldbookModel::blockScalars($b, $new);
		// children: legacy recursed through $allspots; do the same here
		foreach ($features as $c) {
			if (!empty($c['properties']['image_basemap'])) {
				foreach ($b['images'] as $img) if ((string)$img['id'] === (string)$c['properties']['image_basemap']) {
					$cb = FieldbookModel::spotBlock($c, $tagsArr);
					FieldbookModel::blockScalars($cb, $new);
					// grandchildren
					foreach ($features as $g) if (!empty($g['properties']['image_basemap'])) foreach ($cb['images'] as $ci) if ((string)$ci['id'] === (string)$g['properties']['image_basemap']) { $gb = FieldbookModel::spotBlock($g, $tagsArr); FieldbookModel::blockScalars($gb, $new); }
				}
			}
		}
		$newSet = array(); $newJoined = '';
		foreach ($new as $v) { $n = norm($v); if ($n !== '') { $newSet[$n] = true; foreach (explode(', ', $v) as $part) { $np = norm($part); if ($np !== '') $newSet[$np] = true; } } $newJoined .= ' | ' . norm($v); }
		foreach ($legacy as $n => $raw) {
			$checked++;
			if (isset($newSet[$n])) continue;
			if (mb_strlen($n, 'UTF-8') >= 4 && strpos($newJoined, $n) !== false) continue;   // printed as part of a longer value
			// legacy joined arrays (", marble, , , "): all non-empty parts present counts
			if (strpos($n, ',') !== false) {
				$ok = true; $any = false;
				foreach (explode(',', $n) as $part) { $part = trim($part); if ($part === '') continue; $any = true; if (!isset($newSet[$part]) && strpos($newJoined, $part) === false && !chunks_present($part, $newJoined)) { $ok = false; break; } }
				if ($ok && $any) continue;
			}
			// legacy composites ("Zircon (Metamorphic), Avg Size: 12mm"): every chunk present separately counts
			if (chunks_present($n, $newJoined)) continue;
			$missingTotal++;
			if (getenv('DETAIL')) echo "      miss  " . $f['properties']['name'] . "  =>  " . mb_substr($raw, 0, 90, 'UTF-8') . "\n";
			if (count($examples) < 8) $examples[] = $f['properties']['name'] . ': ' . mb_substr($raw, 0, 60, 'UTF-8');
		}
	}
	$totalSpots += count($features);
	check("dataset $dsid ($label): " . count($features) . ' spots, ' . $checked . ' legacy values all present in the new model', $missingTotal === 0, "$missingTotal missing; e.g. " . implode(' || ', $examples));
}
check('at least one fixture dataset was available', $totalSpots > 0);
echo "\n$pass passed, $fail failed, $skip skipped\n";
exit($fail ? 1 : 0);
