<?php
/**
 * File: tests/fieldvocab/smoke_test_export_labels.php
 * Description: Field choice translation Phase 4: data exports carry form LABELS,
 *              translated IN PLACE (no extra columns, decision rule 5).
 *
 *              Every export runs twice through export_runner.php (own process):
 *              "labels" (normal) and "raw" (empty choice map = the pre-translation
 *              output of the same code). The two tables must have the same
 *              columns, the same rows and the same cells except where a raw
 *              choice name became exactly one of its labels (comma-joined
 *              multi-selects part by part), and some cells must have changed.
 *
 *                Excel (xlsOut), expanded shapefile, legacy shapefile, GeoPackage
 *                (+ every QML rule selects the same features in both files, D1),
 *                sample list, geologic units, strat-section CSV (HTTP, session),
 *                DOI XLS + DOI shapefile (HTTP, labels in / raw names out).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/fieldvocab/smoke_test_export_labels.php
 */

$SID_PREFIX = 'fvfbook';
require_once __DIR__ . '/fixture_lib.php';

$TMP = sys_get_temp_dir() . '/fvexp_' . getmypid();

/** name => every label it has anywhere in the map (current + retired + former). */
function all_labels() {
	static $idx = null;
	if ($idx !== null) return $idx;
	$idx = array();
	foreach (FieldVocab::map()['forms'] as $f) foreach ($f['fields'] as $fld) {
		foreach ($fld['choices'] as $n => $l) $idx[(string)$n][$l] = true;
		foreach (isset($fld['retired_choices']) ? $fld['retired_choices'] : array() as $n => $r) $idx[(string)$n][$r['label']] = true;
	}
	return $idx;
}
/** Is $lab the in-place translation of $raw (whole value, ", "-joined parts, a JSON cell leaf by leaf, "(n:x)" lists)? */
function is_translation($raw, $lab) {
	if ($raw === $lab) return true;
	// nested families written as JSON text in one cell (legacy shapefile / GeoPackage)
	$rj = json_decode($raw, true); $lj = json_decode($lab, true);
	if (is_array($rj) && is_array($lj)) return json_same_shape($rj, $lj);
	// JSON cut at the shapefile DBF 254-character limit (both runs): compare the quoted values in order,
	// the last one may be cut short
	if (($raw[0] === '[' || $raw[0] === '{') && (strlen($raw) >= 250 || strlen($lab) >= 250)) {
		preg_match_all('/"((?:[^"\\\\]|\\\\.)*)("|$)/', $raw, $rm); preg_match_all('/"((?:[^"\\\\]|\\\\.)*)("|$)/', $lab, $lm);
		$n = min(count($rm[1]), count($lm[1]));
		if ($n < 2) return false;
		for ($i = 0; $i < $n - 1; $i++) {
			$a = stripcslashes($rm[1][$i]); $b = stripcslashes($lm[1][$i]);
			if (!is_translation($a, $b)) return false;
		}
		return true;
	}
	// "(1:phanerozoic)(2:mesozoic)" style lists
	if (preg_match('/\(\d+:/', $raw)) {
		$rp = preg_split('/(\(\d+:|\))/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE); $lp = preg_split('/(\(\d+:|\))/', $lab, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (count($rp) === count($lp)) { foreach ($rp as $i => $p) if (!is_translation($p, $lp[$i])) return false; return true; }
	}
	$idx = all_labels();
	if (isset($idx[$raw][$lab])) return true;
	$rp = explode(', ', $raw); $lp = explode(', ', $lab);
	if (count($rp) < 2 || count($rp) !== count($lp)) {
		// labels may themselves contain ", " (e.g. "bed, mixed lithologies"): try joining raw parts' labels
		$joined = array();
		foreach ($rp as $p) { $joined[] = isset($idx[$p]) ? array_keys($idx[$p]) : array($p); }
		return in_array($lab, combos($joined), true);
	}
	foreach ($rp as $i => $p) if ($p !== $lp[$i] && !isset($idx[$p][$lp[$i]])) return false;
	return true;
}
/** Same keys / list lengths; leaves equal or translations. */
function json_same_shape($a, $b) {
	if (array_keys($a) !== array_keys($b)) return false;
	foreach ($a as $k => $v) {
		if (is_array($v) !== is_array($b[$k])) return false;
		if (is_array($v)) { if (!json_same_shape($v, $b[$k])) return false; continue; }
		if (!is_translation((string)$v, (string)$b[$k])) return false;
	}
	return true;
}
function combos($lists) {
	$out = array('');
	foreach ($lists as $k => $opts) {
		$next = array();
		foreach ($out as $o) foreach ($opts as $x) { $next[] = $o === '' && $k === 0 ? $x : $o . ', ' . $x; if (count($next) > 200) break 2; }
		$out = $next;
	}
	return $out;
}

/** Run one export; returns the output folder. */
function run_export($method, $dsids, $mode) {
	global $TMP;
	$dir = "$TMP/$method-" . str_replace(',', '_', $dsids) . "-$mode";
	exec('rm -rf ' . escapeshellarg($dir)); mkdir($dir, 0775, true);
	exec('php ' . escapeshellarg(__DIR__ . '/export_runner.php') . ' ' . escapeshellarg($method) . ' ' . escapeshellarg($dsids) . ' '
		. escapeshellarg($mode) . ' ' . escapeshellarg($dir) . ' > ' . escapeshellarg("$dir/stdout.bin") . ' 2>/dev/null');
	if (is_file("$dir/stdout.bin") && filesize("$dir/stdout.bin") === 0) unlink("$dir/stdout.bin");
	return $dir;
}

/** Every file of an export folder, zips expanded (recursively into <zip>.d/). */
function export_files($dir) {
	$out = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	$files = array(); foreach ($it as $f) $files[] = $f->getPathname();
	foreach ($files as $f) {
		$head = (string)file_get_contents($f, false, null, 0, 4);
		$isXlsx = false;
		if ($head === "PK\x03\x04") { $z = new ZipArchive(); if ($z->open($f) === true) { $isXlsx = $z->locateName('xl/workbook.xml') !== false; $z->close(); } }
		if ($head === "PK\x03\x04" && !$isXlsx) {
			$d = "$f.d"; @mkdir($d, 0775, true);
			exec('unzip -qo ' . escapeshellarg($f) . ' -d ' . escapeshellarg($d));
			foreach (export_files($d) as $x) $out[] = $x;
		} else {
			$out[] = $f;
		}
	}
	return $out;
}

/** xlsx => [sheet => rows[][]] (PHPExcel writes shared strings or inline strings). */
function xlsx_tables($path) {
	$z = new ZipArchive();
	if ($z->open($path) !== true) return array();
	$shared = array();
	if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
		$x = simplexml_load_string($ss);
		foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; }
	}
	$tables = array();
	for ($i = 1; ($xml = $z->getFromName("xl/worksheets/sheet$i.xml")) !== false; $i++) {
		$x = simplexml_load_string($xml);
		$rows = array();
		foreach ($x->sheetData->row as $row) {
			$r = array();
			foreach ($row->c as $c) {
				$ref = (string)$c['r']; preg_match('/^([A-Z]+)/', $ref, $m);
				$col = 0; foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
				$t = (string)$c['t'];
				$v = $t === 's' ? $shared[(int)$c->v] : ($t === 'inlineStr' ? (string)$c->is->t : (string)$c->v);
				$r[$col - 1] = $v;
			}
			$rows[(int)$row['r']] = $r;
		}
		$tables["sheet$i"] = $rows;
	}
	return $tables;
}

/** Shapefile / GeoPackage layer => rows[][] via ogr2ogr CSV. */
function ogr_tables($path) {
	$tables = array();
	$layers = array();
	if (preg_match('/\.gpkg$/i', $path)) {
		exec('ogrinfo -ro -q ' . escapeshellarg($path) . ' 2>/dev/null', $o);
		foreach ($o as $l) if (preg_match('/^\d+: (\w+)/', $l, $m)) $layers[] = $m[1];
	} else {
		$layers[] = basename($path, '.shp');
	}
	foreach ($layers as $ly) {
		$csv = sys_get_temp_dir() . '/fvexp_csv_' . getmypid() . "_$ly.csv";
		@unlink($csv);
		exec('ogr2ogr -f CSV ' . escapeshellarg($csv) . ' ' . escapeshellarg($path) . (preg_match('/\.gpkg$/i', $path) ? ' ' . escapeshellarg($ly) : '') . ' 2>/dev/null');
		$rows = array();
		if (($h = @fopen($csv, 'r')) !== false) { while (($r = fgetcsv($h, 0, ',', '"', "\x01")) !== false) $rows[] = $r; fclose($h); }
		@unlink($csv);
		$tables[$ly] = $rows;
	}
	return $tables;
}

/** Tables of one export folder, keyed by "<file>:<table>". */
function tables_of($dir) {
	$out = array();
	foreach (export_files($dir) as $f) {
		$rel = preg_replace('#^.*?-(labels|raw)/#', '', $f);
		$z = new ZipArchive();
		if ((string)file_get_contents($f, false, null, 0, 2) === 'PK' && $z->open($f) === true && $z->locateName('xl/workbook.xml') !== false) {
			foreach (xlsx_tables($f) as $t => $rows) $out["$rel:$t"] = $rows;
		} elseif (preg_match('/\.(shp|gpkg)$/i', $f)) {
			foreach (ogr_tables($f) as $t => $rows) $out["$rel:$t"] = $rows;
		} elseif (preg_match('/\.csv$/i', $f)) {
			$rows = array(); $h = fopen($f, 'r'); while (($r = fgetcsv($h, 0, ',', '"', "\x01")) !== false) $rows[] = $r; fclose($h); $out["$rel:csv"] = $rows;
		}
	}
	return $out;
}

/** Compare raw vs labeled tables; returns [ok, detail, changedCells]. */
function compare_in_place($raw, $lab) {
	if (!$raw) return array(false, 'no tables in the raw run', 0);
	if (array_keys($raw) !== array_keys($lab)) return array(false, 'table sets differ: ' . implode(',', array_keys($raw)) . ' vs ' . implode(',', array_keys($lab)), 0);
	$changed = 0; $bad = array();
	foreach ($raw as $t => $rows) {
		if (substr($t, -strlen(':layer_styles')) === ':layer_styles') continue;   // the QML: its filters are checked rule by rule (D1)
		$lrows = $lab[$t];
		if (count($rows) !== count($lrows)) return array(false, "$t: " . count($rows) . ' rows vs ' . count($lrows), 0);
		$rk = array_keys($rows); $lk = array_keys($lrows);
		if ($rk !== $lk) return array(false, "$t: row keys differ", 0);
		$first = reset($rk);
		if ($rows[$first] !== $lrows[$first]) return array(false, "$t: header row differs (extra or renamed columns)", 0);
		foreach ($rows as $ri => $r) {
			$l = $lrows[$ri];
			if (count($r) !== count($l) || array_keys($r) !== array_keys($l)) { $bad[] = "$t row $ri: cell layout differs"; continue; }
			foreach ($r as $ci => $v) {
				if ((string)$v === (string)$l[$ci]) continue;
				$changed++;
				if (!is_translation((string)$v, (string)$l[$ci])) $bad[] = "$t r$ri c$ci: \"$v\" -> \"{$l[$ci]}\"";
			}
		}
	}
	return array(!$bad, implode(' | ', array_slice($bad, 0, 6)), $changed);
}

/** All cell strings of a table set (for label / raw scans). */
function all_cells($tables) {
	$out = array();
	foreach ($tables as $rows) foreach ($rows as $r) foreach ($r as $v) if ((string)$v !== '') $out[] = (string)$v;
	return $out;
}

echo "Field choice translation: data exports translated in place (Phase 4)\n";
gf_cleanup();
exec('rm -rf ' . escapeshellarg($TMP)); mkdir($TMP, 0775, true);
$OGR_BEFORE = glob('/srv/app/www/ogrtemp/*') ?: array();   // the exports leave their ogrtemp/<n> work folders behind (long-standing)
try {
	section('Fixture');
	gf_upload();
	$both = GF_DS_A . ',' . GF_DS_B;

	$exports = array(
		array('xlsOut', $both, 'Excel', array('joint', 'fragmented rock', 'basaltic-andesite')),
		array('expandedShapefileOut', $both, 'expanded shapefile', array('joint', 'fragmented rock')),
		array('shapefileOut', (string)GF_DS_A, 'legacy shapefile (dataset A)', array('joint')),
		array('gpkgOut', $both, 'GeoPackage', array('joint')),
		array('xlsSampleList', (string)GF_DS_A, 'sample list', array('fragmented rock', 'fabric / microstructure', '5 - definitely in place')),
		array('geologicUnitsOut', $both, 'geologic units', array('alkali feldspar granite', 'Phanerozoic', 'greenschist facies')),
	);
	$labeledCells = array();
	foreach ($exports as $e) {
		list($method, $dsids, $what, $mustHave) = $e;
		section($what);
		$rawDir = run_export($method, $dsids, 'raw');
		$labDir = run_export($method, $dsids, 'labels');
		$raw = tables_of($rawDir); $lab = tables_of($labDir);
		list($ok, $detail, $changed) = compare_in_place($raw, $lab);
		check("$what: same tables, columns and rows; every changed cell is the label of its raw name", $ok, $detail);
		check("$what: cells were translated ($changed)", $changed > 0);
		$cells = all_cells($lab);
		$labeledCells[$what] = $cells;
		$miss = array();
		foreach ($mustHave as $l) { $hit = false; foreach ($cells as $c) if (strpos($c, $l) !== false) { $hit = true; break; } if (!$hit) $miss[] = $l; }
		check("$what: shows " . implode(', ', $mustHave), !$miss, 'missing: ' . implode(' | ', $miss));
		if ($method === 'gpkgOut') {
			// D1: every QML rule must select the same features in the labeled file as in the raw one
			$gl = null; $gr = null;
			foreach (export_files($labDir) as $f) if (preg_match('/\.gpkg$/i', $f)) $gl = $f;
			foreach (export_files($rawDir) as $f) if (preg_match('/\.gpkg$/i', $f)) $gr = $f;
			$counts = function ($gpkg) {
				$o = array(); exec('ogrinfo -ro -q -sql ' . escapeshellarg("SELECT f_table_name, styleQML FROM layer_styles") . ' ' . escapeshellarg($gpkg) . ' 2>/dev/null', $o);
				$txt = implode("\n", $o); $res = array();
				foreach (array('points', 'lines', 'polygons') as $layer) {
					if (!preg_match('/f_table_name \(String\) = ' . $layer . '\s+styleQML \(String\) = (.*?)(?=\n\s*OGRFeature|\z)/s', $txt, $m)) continue;
					preg_match_all('/<rule key="\{([^}]+)\}" filter="([^"]*)"/', $m[1], $rules, PREG_SET_ORDER);
					foreach ($rules as $r) {
						$flt = html_entity_decode($r[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
						$c = array(); exec('ogrinfo -ro -q -dialect SQLite -sql ' . escapeshellarg("SELECT COUNT(*) AS n FROM $layer WHERE $flt") . ' ' . escapeshellarg($gpkg) . ' 2>/dev/null', $c);
						$n = preg_match('/n \(Integer\) = (\d+)/', implode("\n", $c), $mm) ? (int)$mm[1] : -1;
						$res["$layer:{$r[1]}"] = array($n, $flt);
					}
				}
				return $res;
			};
			$cl = $gl ? $counts($gl) : array(); $cr = $gr ? $counts($gr) : array();
			$diff = array(); $total = 0;
			foreach ($cr as $k => $v) { $total += max(0, $v[0]); if (!isset($cl[$k]) || $cl[$k][0] !== $v[0]) $diff[] = "$k raw {$v[0]} vs labeled " . (isset($cl[$k]) ? $cl[$k][0] : 'missing') . " [{$cl[$k][1]}]"; }
			check('GeoPackage: ' . count($cr) . ' QML rules found, each selects the same features in both files (D1)', count($cr) >= 10 && !$diff && $total > 0, implode(' | ', $diff) . " (rules " . count($cr) . ", matched $total)");
			$lq = ''; foreach ($cl as $k => $v) $lq .= $v[1] . "\n";
			check('GeoPackage: QML filters are written with labels (shear zone, not shear_zone)', strpos($lq, "'shear zone'") !== false && strpos($lq, "'shear_zone'") === false);
		}
	}

	section('Strat-section CSV (HTTP, session)');
	$S = gf_spots();
	list($st, $csv) = http('GET', '/strat_section_csv.php?id=' . $S['sedbase'] . '&did=' . GF_DS_B, null, 'session');
	check('strat CSV 200 + header unchanged', $st === 200 && strpos($csv, '"unit_id","unit_name","unit_base","unit_top","unit_thickness","primary_lithology"') === 0, "HTTP $st " . substr($csv, 0, 120));
	// the fixture's first lithology (volcaniclastic) and unit (m) have labels equal to their names: this is a smoke check
	check('strat CSV: one unit row with lithology + thickness', strpos($csv, '"volcaniclastic"') !== false && strpos($csv, '"1.5 m"') !== false, $csv);

	section('DOI XLS + shapefile (view time, all DOIs: D3)');
	$doiDir = "/srv/app/www/doi/doiFiles/$DOI_UUID";
	@mkdir("$doiDir/images", 0775, true);
	$o = new straboOutputClass(fresh_strabo(), array());
	file_put_contents("$doiDir/data.json", json_encode($o->doiDataOut(GF_PROJECT), JSON_PRETTY_PRINT));
	foreach (array('xls' => 'DOI XLS', 'shapefile' => 'DOI shapefile') as $type => $what) {
		$d = "$TMP/doi_$type"; @mkdir($d, 0775, true);
		list($st, $bytes) = http('GET', "/doi/doisearchdownload.php?type=$type&u=$DOI_UUID", null, 'none');
		file_put_contents("$d/out." . ($type === 'xls' ? 'xlsx' : 'zip'), $bytes);
		$cells = all_cells(tables_of($d));
		// samples never reach the DOI shapefile (fixSpot buildSamples bug, audit §9.2): orientations only there
		$want = $type === 'xls' ? array('joint', 'fragmented rock') : array('joint'); $miss = array();
		foreach ($want as $l) { $hit = false; foreach ($cells as $c) if (strpos($c, $l) !== false) $hit = true; if (!$hit) $miss[] = $l; }
		$leak = array();
		foreach (array('option_13', 'fragmented_roc', 'basaltic_andes', 'fabric___micro') as $r) foreach ($cells as $c) if ($c === $r || strpos($c, "$r, ") === 0 || strpos($c, ", $r") !== false) { $leak[] = $r; break; }
		check("$what: labels present (" . count($cells) . ' cells)', $st === 200 && count($cells) > 10 && !$miss, "HTTP $st, missing: " . implode(' | ', $miss));
		check("$what: no raw names", !$leak, implode(' | ', $leak));
	}
} catch (Throwable $e) {
	check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
	gf_cleanup();
	exec('rm -rf ' . escapeshellarg($TMP));
	foreach (array_diff(glob('/srv/app/www/ogrtemp/*') ?: array(), $OGR_BEFORE) as $d) exec('rm -rf ' . escapeshellarg($d));
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
