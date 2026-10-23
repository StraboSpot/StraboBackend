<?php
/**
 * File: tests/exportsections/export_sections_test.php
 * Description: Samples, 3D structures (fold, fault, fabric) and other features reach the
 *              exports built on fixSpot(): GeoPackage, legacy shapefile and the DOI shapefile.
 *              Before the fix buildSamples / buildStructures returned an undefined local, so
 *              those columns were missing from every one of these files; faults (no prefix
 *              of their own) would have come out as "foo..." columns.
 *
 *              Uses the golden fixture of tests/fieldvocab (needs the test users from
 *              tests/collaboration/setup_test_data.php) and its export_runner.php.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/exportsections/export_sections_test.php
 *                docker exec strabo-php8 php /srv/app/www/tests/exportsections/export_sections_test.php
 */

$SID_PREFIX = 'fvexpsec';
require_once __DIR__ . '/../fieldvocab/fixture_lib.php';

$TMP = sys_get_temp_dir() . '/expsec_' . getmypid();

/** Run one export in its own process (export_runner.php); returns the output folder. */
function run_export($method, $dsids) {
	global $TMP;
	$dir = "$TMP/$method";
	exec('rm -rf ' . escapeshellarg($dir)); mkdir($dir, 0775, true);
	exec('php ' . escapeshellarg(__DIR__ . '/../fieldvocab/export_runner.php') . ' ' . escapeshellarg($method) . ' ' . escapeshellarg($dsids)
		. ' labels ' . escapeshellarg($dir) . ' > ' . escapeshellarg("$dir/stdout.bin") . ' 2>/dev/null');
	return $dir;
}

/** Every .gpkg / .shp below $dir, zips expanded. */
function vector_files($dir) {
	foreach (glob("$dir/*") ?: array() as $f) {
		if (is_file($f) && (string)file_get_contents($f, false, null, 0, 4) === "PK\x03\x04") exec('unzip -qo ' . escapeshellarg($f) . ' -d ' . escapeshellarg("$f.d"));
	}
	$out = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) if (preg_match('/\.(gpkg|shp)$/i', $f->getFilename())) $out[] = $f->getPathname();
	sort($out);
	return $out;
}

/** All features (properties only) of every vector layer of the files. */
function features_of(array $files) {
	$props = array();
	foreach ($files as $f) {
		$layers = array();
		if (preg_match('/\.gpkg$/i', $f)) {
			exec('ogrinfo -ro -q ' . escapeshellarg($f) . ' 2>/dev/null', $o);
			foreach ($o as $line) if (preg_match('/^\d+: (\S+) \(/', $line, $m)) $layers[] = $m[1];
			unset($o);
		} else {
			$layers[] = '';
		}
		foreach ($layers as $layer) {
			$json = shell_exec('ogr2ogr -f GeoJSON /vsistdout/ ' . escapeshellarg($f) . ($layer !== '' ? ' ' . escapeshellarg($layer) : '') . ' 2>/dev/null');
			$fc = json_decode((string)$json, true);
			foreach (isset($fc['features']) ? $fc['features'] : array() as $ft) $props[] = (array)$ft['properties'];
		}
	}
	return $props;
}

/** The first feature whose $col equals $value (null if none). */
function feature_with(array $features, $col, $value) {
	foreach ($features as $p) if (isset($p[$col]) && (string)$p[$col] === $value) return $p;
	return null;
}

/** Column names starting with "foo" and cells reading "Array" (both are signs of the old bugs). */
function junk(array $features) {
	$bad = array();
	foreach ($features as $p) foreach ($p as $k => $v) {
		if (stripos($k, 'foo') === 0) $bad["column $k"] = true;
		if (is_string($v) && preg_match('/(^|, )Array(,|$)/', $v)) $bad["$k = $v"] = true;
	}
	return array_keys($bad);
}

/** Checks shared by the three files: $cols maps the expected column name to its expected value. */
function check_sections($what, array $features, array $structureCols, array $traceCols) {
	check("$what: features read", count($features) > 0, 'no features (export failed?)');
	$st = feature_with($features, 'spot_name', 'GF structure');
	check("$what: GF structure spot present", $st !== null);
	foreach ($structureCols as $col => $want) {
		$got = $st !== null && array_key_exists($col, $st) ? (string)$st[$col] : null;
		check("$what: GF structure $col = $want", $got === $want, $got === null ? "column missing; columns: " . implode(',', $st ? array_keys($st) : array()) : "got '$got'");
	}
	$tr = feature_with($features, 'spot_name', 'GF trace');
	foreach ($traceCols as $col => $want) {
		$got = $tr !== null && array_key_exists($col, $tr) ? (string)$tr[$col] : null;
		check("$what: GF trace $col = $want", $got === $want, $got === null ? 'column missing' : "got '$got'");
	}
	$j = junk($features);
	check("$what: no 'foo' columns, no 'Array' cells", !$j, implode(' | ', $j));
}

echo "Export sections: samples, 3D structures and other features in fixSpot exports\n";
gf_cleanup();
exec('rm -rf ' . escapeshellarg($TMP)); mkdir($TMP, 0775, true);
$OGR_BEFORE = glob('/srv/app/www/ogrtemp/*') ?: array();
try {
	section('Fixture');
	gf_upload();
	$both = GF_DS_A . ',' . GF_DS_B;

	// Values are the form labels (FieldVocab, decision D1) except free text.
	$full = array(
		'samp_label' => 'GF-S1', 'samp_sample_notes' => 'golden sample', 'samp_material_type' => 'fragmented rock',
		'fold_label' => 'fold', 'fold_tightness' => 'gentle (180-120)',
		'flt_label' => 'fault', 'flt_fault_or_sz_type' => 'dextral strike-slip',
		'fab_label' => 'tectonite',
	);
	// Shapefile DBF: names cut to 10 characters, so only the short ones are checked by name.
	$short = array('samp_label' => 'GF-S1', 'fold_label' => 'fold', 'flt_label' => 'fault', 'fab_label' => 'tectonite');

	section('GeoPackage');
	check_sections('GeoPackage', features_of(vector_files(run_export('gpkgOut', $both))), $full, array('of_label' => 'GF other feature', 'of_description' => 'golden'));

	section('Legacy shapefile');
	check_sections('Legacy shapefile', features_of(vector_files(run_export('shapefileOut', (string)GF_DS_A))), $short, array('of_label' => 'GF other feature'));

	section('DOI shapefile (doiOutputClass)');
	$doiDir = "/srv/app/www/doi/doiFiles/$DOI_UUID";
	@mkdir("$doiDir/images", 0775, true);
	$o = new straboOutputClass(fresh_strabo(), array());
	file_put_contents("$doiDir/data.json", json_encode($o->doiDataOut(GF_PROJECT), JSON_PRETTY_PRINT));
	$d = "$TMP/doi"; @mkdir($d, 0775, true);
	list($st, $bytes) = http('GET', "/doi/doisearchdownload.php?type=shapefile&u=$DOI_UUID", null, 'none');
	check('DOI shapefile: HTTP 200', $st === 200, "HTTP $st");
	file_put_contents("$d/out.zip", $bytes);
	check_sections('DOI shapefile', features_of(vector_files($d)), $short, array('of_label' => 'GF other feature'));
} catch (Throwable $e) {
	check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
	gf_cleanup();
	exec('rm -rf ' . escapeshellarg($TMP));
	foreach (array_diff(glob('/srv/app/www/ogrtemp/*') ?: array(), $OGR_BEFORE) as $dd) exec('rm -rf ' . escapeshellarg($dd));
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
