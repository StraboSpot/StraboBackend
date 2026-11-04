<?php
/**
 * File: tests/gpkgroundtrip/export_manifest_test.php
 * Description: GeoPackage round trip, phase 1 (export side), docs/GeoPackage/GeoPackage_RoundTrip_Design.md
 *              sections 3 and 4. Downloads the golden fixture through /searchdownload (owner session)
 *              as a project export and as a dataset export and checks:
 *                - layers: points, lines, polygons (one feature per spot), orientations (one per
 *                  measurement); attribute tables strabo_export, strabo_spots, layer_styles; every
 *                  table described in gpkg_contents
 *                - symbol_orientations on points: the spot's orientations in order (associated first)
 *                - strabo_id on every feature, proj_/ds_ columns
 *                - strabo_export (scope, project, datasets, owner) and strabo_spots (one row per spot,
 *                  layer, timestamp, original_geojson = the layer's geometry, editable = 0 for image
 *                  basemap and strat section spots)
 *                - styles: points = rule based per-spot orientation style (19 rules on symbol_orientations,
 *                  GeometryGenerator per rule, one copy per orientation), orientations = dots by default +
 *                  the per-measurement rules as a second style, strabo_id read-only everywhere
 *                - no file for a request without a session; another account's project id gives no data
 *
 *              Uses the golden fixture of tests/fieldvocab (needs the test users from
 *              tests/collaboration/setup_test_data.php).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/gpkgroundtrip/export_manifest_test.php
 *                docker exec strabo-php8 php /srv/app/www/tests/gpkgroundtrip/export_manifest_test.php
 */

$SID_PREFIX = 'fvgpkgrt';
require_once __DIR__ . '/../fieldvocab/fixture_lib.php';

$TMP = sys_get_temp_dir() . '/gpkgrt_' . getmypid();
const RT_BASEMAP_SPOT = 977910000108;   // added below: a spot drawn on the fixture image (id in the fixture range, so gf_cleanup removes it)

/** GET a GeoPackage through /searchdownload; returns [http status, path or null]. */
function download_gpkg($query, $auth = 'session') {
	global $TMP;
	list($st, $bytes) = http('GET', "/searchdownload?$query", null, $auth);
	$f = "$TMP/" . md5($query . $auth) . '.gpkg';
	file_put_contents($f, $bytes);
	return array($st, substr($bytes, 0, 15) === 'SQLite format 3' ? $f : null);
}

/** Rows of an SQL query on the file (all values as strings). */
function gpkg_rows($gpkg, $sql) {
	$csv = shell_exec('ogr2ogr -f CSV /vsistdout/ ' . escapeshellarg($gpkg) . ' -dialect SQLite -sql ' . escapeshellarg($sql) . ' 2>/dev/null');
	$lines = array_values(array_filter(explode("\n", (string)$csv), 'strlen'));
	if (!$lines) return array();
	$h = fopen('php://memory', 'r+'); fwrite($h, implode("\n", $lines)); rewind($h);
	$head = fgetcsv($h, 0, ',', '"', "\x01"); $rows = array();
	while (($r = fgetcsv($h, 0, ',', '"', "\x01")) !== false) $rows[] = array_combine($head, array_pad($r, count($head), ''));
	fclose($h);
	return $rows;
}

/** Features (properties + geometry) of one layer, as GDAL reads them. */
function layer_features($gpkg, $layer) {
	$fc = json_decode((string)shell_exec('ogr2ogr -f GeoJSON /vsistdout/ ' . escapeshellarg($gpkg) . ' ' . escapeshellarg($layer) . ' 2>/dev/null'), true);
	return isset($fc['features']) ? $fc['features'] : array();
}

/** The checks shared by the project and the dataset export. */
function check_file($what, $gpkg, array $wantLayers, array $wantSpots, $scope, $dsids) {
	global $OWNER;
	check("$what: a GeoPackage came back", $gpkg !== null);
	if ($gpkg === null) return;

	$contents = array();
	foreach (gpkg_rows($gpkg, 'SELECT table_name, data_type FROM gpkg_contents') as $r) $contents[$r['table_name']] = $r['data_type'];
	// layer_styles registered too: otherwise GDAL (and so QGIS) hides it once the file has attribute tables
	// strabo_export / strabo_spots: an extension data_type, so QGIS does not offer them as layers (Zane Jobe 2026-11-04)
	$want = array('strabo_export' => 'x-strabo-roundtrip', 'strabo_spots' => 'x-strabo-roundtrip', 'layer_styles' => 'attributes');
	foreach ($wantLayers as $l) $want[$l] = 'features';
	if (in_array('points', $wantLayers)) $want['orientations'] = 'features';   // one row per measurement
	ksort($want); ksort($contents);
	check("$what: tables " . implode(', ', array_keys($want)), $contents === $want, json_encode($contents));
	// every table is described for QGIS users (helper tables say they are not needed for mapping)
	$desc = array(); foreach (gpkg_rows($gpkg, 'SELECT table_name, description FROM gpkg_contents') as $r) $desc[$r['table_name']] = $r['description'];
	$noDesc = array(); foreach (array_keys($want) as $t) if (trim((string)($desc[$t] ?? '')) === '') $noDesc[] = $t;
	check("$what: every table has a gpkg_contents description", !$noDesc, implode(',', $noDesc));
	$helper = array(); foreach (array('strabo_export', 'strabo_spots', 'layer_styles') as $t) if (strpos((string)($desc[$t] ?? ''), 'Not needed for mapping') === false) $helper[] = $t;
	check("$what: strabo_export, strabo_spots, layer_styles say they are not needed for mapping", !$helper, implode(',', $helper));
	// what GDAL (and so QGIS) lists when the file is opened: the feature layers only
	$listed = array(); exec('ogrinfo -ro -q ' . escapeshellarg($gpkg) . ' 2>/dev/null', $lo);
	foreach ($lo as $line) if (preg_match('/^\d+: (\S+)/', $line, $m)) $listed[] = $m[1];
	$wantListed = array_merge($wantLayers, in_array('points', $wantLayers) ? array('orientations') : array(), array('layer_styles'));
	sort($listed); sort($wantListed);
	check("$what: GDAL lists only the feature layers (+ layer_styles, which QGIS hides), not strabo_export / strabo_spots", $listed === $wantListed, implode(',', $listed));

	// one feature per spot, strabo_id on every feature, the spot's dataset columns
	$byLayer = array(); $all = array();
	foreach (array('points', 'lines', 'polygons') as $layer) {
		if (!in_array($layer, $wantLayers)) continue;
		foreach (layer_features($gpkg, $layer) as $f) { $byLayer[$layer][] = (string)$f['properties']['strabo_id']; $all[(string)$f['properties']['strabo_id']] = $f; }
	}
	$ids = array(); foreach ($byLayer as $l => $list) foreach ($list as $id) $ids[] = $id;
	sort($ids); $w = array_keys($wantSpots); sort($w);
	check("$what: each spot exactly once over points/lines/polygons (" . count($w) . ')', $ids === array_map('strval', $w), json_encode($byLayer));
	foreach ($wantSpots as $id => $layer) {
		check("$what: spot $id in $layer", isset($byLayer[$layer]) && in_array((string)$id, $byLayer[$layer], true));
	}
	$noDs = array(); foreach ($all as $id => $f) if ((string)($f['properties']['ds_id'] ?? '') === '' || (string)($f['properties']['proj_id'] ?? '') !== (string)GF_PROJECT) $noDs[] = $id;
	check("$what: every feature has ds_id / ds_name / proj_id", !$noDs, implode(',', $noDs));

	// symbol_orientations: the structure spot's five orientations (the associated lineation first, as
	// explodeOrientations orders them), null on every other point
	$S = gf_spots();
	$so = array(); foreach (layer_features($gpkg, 'points') as $f) $so[(string)$f['properties']['strabo_id']] = $f['properties']['symbol_orientations'] ?? null;
	$st = $so[(string)$S['structure']] ?? null;
	if (is_string($st)) $st = json_decode($st, true);   // GDAL's GeoJSON output may already give the JSON as a list
	check("$what: GF structure symbol_orientations = lineation 210/20, planar 120/35, 40/80, 45/82, tabular 300/60",
		is_array($st) && count($st) === 5 && ($st[0]['lo_trend'] ?? null) === 210 && ($st[0]['lo_plunge'] ?? null) === 20
		&& ($st[1]['po_strike'] ?? null) === 120 && ($st[1]['po_dip'] ?? null) === 35 && ($st[2]['po_strike'] ?? null) === 40
		&& ($st[3]['po_dip'] ?? null) === 82 && ($st[4]['to_strike'] ?? null) === 300 && is_string($st[1]['po_feature_type'] ?? null), json_encode($st));
	$other = array(); foreach ($so as $id => $v) if ((string)$id !== (string)$S['structure'] && $v !== null) $other[] = $id;
	check("$what: symbol_orientations null on points without orientations", !$other, implode(',', $other));

	// orientations: one row per measurement in a single po_ / lo_ / to_ set (the points layout before
	// the round trip), one row for a point without one, strabo_id on every row
	if (in_array('points', $wantLayers)) {
		$rows = array(); $noId = array(); $cols = array();
		foreach (layer_features($gpkg, 'orientations') as $f) {
			$sid = (string)($f['properties']['strabo_id'] ?? '');
			if ($sid === '') $noId[] = json_encode($f['properties']);
			$rows[$sid][] = $f['properties'];
			$cols += $f['properties'];
		}
		$want = array(); foreach ($so as $id => $v) $want[(string)$id] = ((string)$id === (string)$S['structure']) ? 5 : 1;
		$got = array(); foreach ($rows as $id => $r) $got[(string)$id] = count($r);
		ksort($want); ksort($got);
		check("$what: orientations = 5 rows for the GF structure spot, 1 for every other point", $got === $want && !$noId, json_encode($got) . ' ' . implode(' | ', $noId));
		$r = $rows[(string)$S['structure']] ?? array();
		$seen = array(); foreach ($r as $p) $seen[] = isset($p['lo_trend']) ? "lo {$p['lo_trend']}/{$p['lo_plunge']}" : (isset($p['to_strike']) ? "to {$p['to_strike']}" : "po {$p['po_strike']}/{$p['po_dip']}");
		check("$what: orientations rows of the GF structure spot in order: lineation 210/20, planar 120/35, 40/80, 45/82, tabular 300",
			$seen === array('lo 210/20', 'po 120/35', 'po 40/80', 'po 45/82', 'to 300'), implode(', ', $seen));
		$numbered = array_values(array_filter(array_keys($cols), function ($c) { return preg_match('/^(po|lo|to)_[0-9]+_/', $c); }));
		check("$what: orientations has no symbol_orientations and no numbered po_2_ / lo_2_ columns for the multi spot",
			!array_key_exists('symbol_orientations', $cols) && !array_filter($r, function ($p) { foreach ($p as $k => $v) if (preg_match('/^(po|lo|to)_[0-9]+_/', $k) && $v !== null) return true; return false; }),
			implode(',', $numbered));
	}

	// strabo_export
	$ex = gpkg_rows($gpkg, 'SELECT * FROM strabo_export');
	check("$what: strabo_export has one row", count($ex) === 1, json_encode($ex));
	$e = $ex[0] ?? array();
	check("$what: strabo_export format 1, scope $scope, project, datasets, owner", ($e['format_version'] ?? '') === '1' && ($e['scope'] ?? '') === $scope
		&& ($e['project_id'] ?? '') === (string)GF_PROJECT && ($e['dataset_ids'] ?? '') === $dsids && ($e['owner_userpkey'] ?? '') === (string)$OWNER
		&& ($e['exported_by'] ?? '') === (string)$OWNER && abs((int)($e['exported_at'] ?? 0) - time()) < 600 && ($e['project_name'] ?? '') !== '', json_encode($e));

	// strabo_spots
	$rows = array(); foreach (gpkg_rows($gpkg, 'SELECT * FROM strabo_spots') as $r) $rows[$r['spot_id']] = $r;
	$rk = array_map('strval', array_keys($rows)); sort($rk);
	check("$what: strabo_spots has one row per exported spot", $rk === array_map('strval', $w), implode(',', $rk));
	$bad = array();
	foreach ($rows as $id => $r) {
		$f = $all[$id] ?? null;
		if ($f === null) { $bad[] = "$id not in a layer"; continue; }
		if ($r['layer'] !== $wantSpots[$id]) $bad[] = "$id layer {$r['layer']}";
		if (json_decode($r['original_geojson'], true) != $f['geometry']) $bad[] = "$id original_geojson " . $r['original_geojson'] . ' vs ' . json_encode($f['geometry']);
		if ($r['modified_timestamp'] !== (string)GF_TS) $bad[] = "$id timestamp {$r['modified_timestamp']}";
		if ($r['dataset_id'] !== (string)$f['properties']['ds_id']) $bad[] = "$id dataset {$r['dataset_id']}";
		$locked = in_array((int)$id, array($S['interval'], RT_BASEMAP_SPOT), true);
		if ($r['editable'] !== ($locked ? '0' : '1')) $bad[] = "$id editable {$r['editable']}";
	}
	check("$what: strabo_spots layer, original_geojson, timestamp, dataset, editable (0 for basemap + strat interval)", !$bad, implode(' | ', $bad));

	// styles
	$styles = array(); $extra = array();
	foreach (gpkg_rows($gpkg, 'SELECT f_table_name, styleName, styleQML, useAsDefault FROM layer_styles') as $r) {
		if ($r['useAsDefault'] === '1') $styles[$r['f_table_name']] = $r['styleQML']; else $extra[$r['f_table_name']][$r['styleName']] = $r['styleQML'];
	}
	$ps = $styles['points'] ?? '';
	// rule based (Zane Jobe 2026-11-04: one legend entry per structure type that can be switched off);
	// each rule's symbol repeats the point per orientation and draws its own type on matching copies
	$nRules = preg_match_all('/<rule [^>]*filter="([^"]*)"/', $ps, $rm);
	$soRules = 0; foreach ($rm[1] as $flt) if (strpos(html_entity_decode($flt, ENT_QUOTES | ENT_XML1), 'from_json("symbol_orientations")') !== false) $soRules++;
	check("$what: points style is rule based, 19 rules, each matching on symbol_orientations",
		strpos($ps, 'type="RuleRenderer"') !== false && $nRules === 19 && $soRules === 19 && strpos($ps, 'label="Bedding (inclined)"') !== false, "rules $nRules, on symbol_orientations $soRules");
	check("$what: points style draws every orientation from symbol_orientations (a generator per rule + enabled expressions)",
		substr_count($ps, 'class="GeometryGenerator"') >= 19 && strpos($ps, '@geometry_part_num') !== false
		&& substr_count($ps, 'name="enabled" type="Map"') >= 19, 'enabled: ' . substr_count($ps, 'name="enabled" type="Map"'));
	if (in_array('points', $wantLayers)) {
		$os = $styles['orientations'] ?? '';
		check("$what: orientations default style = plain dots without labels", strpos($os, 'type="singleSymbol"') !== false && strpos($os, 'labelsEnabled="0"') !== false && strpos($os, 'GeometryGenerator') === false);
		$alt = $extra['orientations']['StraboSpot Orientations (one per measurement)'] ?? '';
		check("$what: orientations second style = the one-per-measurement orientation rules (not default)", strpos($alt, 'type="RuleRenderer"') !== false && substr_count($alt, '<rule ') === 19 && strpos($alt, 'symbol_orientations') === false);
		$wantLayers[] = 'orientations';
	}
	check("$what: points label = first three dips from symbol_orientations + count of the rest, placed from the point", strpos($ps, "with_variable('dips'") !== false && strpos($ps, 'array_slice(@dips, 0, 2)') !== false && strpos($ps, "' (+'") !== false && preg_match('/<placement [^>]*offsetType="0"/', $ps) === 1);
	$noRo = array(); foreach ($wantLayers as $l) if (!isset($styles[$l]) || strpos($styles[$l], '<field name="strabo_id" editable="0"/>') === false) $noRo[] = $l;
	check("$what: strabo_id read-only in every layer style", !$noRo, implode(',', $noRo));
}

echo "GeoPackage round trip phase 1: export layout + manifest\n";
gf_cleanup();
exec('rm -rf ' . escapeshellarg($TMP)); mkdir($TMP, 0775, true);
$OGR_BEFORE = glob('/srv/app/www/ogrtemp/*') ?: array();
try {
	section('Fixture (+ a spot on an image basemap)');
	gf_upload();
	$S = gf_spots();
	$A = gf_features()[GF_DS_A];
	$A[] = gf_feature(RT_BASEMAP_SPOT, 'GF basemap spot', gf_point(30, 20), array('image_basemap' => 977910000401));
	list($st, $tx) = http('POST', '/db/datasetspots/' . GF_DS_A, array('type' => 'FeatureCollection', 'features' => $A));
	check('POST /db/datasetspots A with the basemap spot', $st >= 200 && $st < 300, "$st " . substr($tx, 0, 200));

	$spotsA = array($S['structure'] => 'points', $S['petrology'] => 'points', $S['legacy'] => 'points', $S['trace'] => 'lines', RT_BASEMAP_SPOT => 'points');
	$spotsB = array($S['sedbase'] => 'points', $S['interval'] => 'polygons', $S['surface'] => 'polygons');

	section('Project export (/searchdownload?type=gpkg&projectid=)');
	list($st, $f) = download_gpkg('type=gpkg&projectid=' . GF_PROJECT);
	check('project export HTTP 200', $st === 200, "HTTP $st");
	if (getenv('RT_KEEP') && $f) copy($f, getenv('RT_KEEP') . '/project_export.gpkg');   // e.g. to open it in QGIS
	$both = array(GF_DS_A, GF_DS_B); sort($both);
	check_file('project', $f, array('points', 'lines', 'polygons'), $spotsA + $spotsB, 'project', implode(',', $both));

	section('Dataset export (/searchdownload?type=gpkg&dsids=)');
	list($st, $f) = download_gpkg('type=gpkg&dsids=' . GF_DS_A);
	check('dataset export HTTP 200', $st === 200, "HTTP $st");
	check_file('dataset A', $f, array('points', 'lines'), $spotsA, 'dataset', (string)GF_DS_A);

	section('Load GeoPackage layer list (gpkgfuncs.php geopackageLayers)');
	require_once '/srv/app/www/gpkgfuncs.php';
	$names = $f ? array_keys(geopackageLayers($f)) : array();
	check('a StraboSpot dataset export lists exactly its feature layers (points, lines)', $names === array('lines', 'points'), json_encode($names));

	section('Access');
	list($st, $f) = download_gpkg('type=gpkg&projectid=' . GF_PROJECT, 'none');
	check('no session: no GeoPackage', $f === null, "HTTP $st");
	list($st, $f) = download_gpkg('type=gpkg&projectid=1');
	check('a project id the user cannot export: no GeoPackage', $f === null, "HTTP $st");
	list($st, $f) = download_gpkg('type=gpkg&projectid=' . GF_PROJECT . '&export_scope=selection');
	$e = $f ? (gpkg_rows($f, 'SELECT scope FROM strabo_export')[0]['scope'] ?? '') : '';
	check('export_scope from the request is ignored (still project)', $e === 'project', "scope '$e'");
} catch (Throwable $e) {
	check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
	gf_cleanup();
	exec('rm -rf ' . escapeshellarg($TMP));
	foreach (array_diff(glob('/srv/app/www/ogrtemp/*') ?: array(), $OGR_BEFORE) as $dd) exec('rm -rf ' . escapeshellarg($dd));
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
