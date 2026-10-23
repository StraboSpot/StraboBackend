<?php
/**
 * File: tests/gpkgroundtrip/export_manifest_test.php
 * Description: GeoPackage round trip, phase 1 (export side), docs/GeoPackage/GeoPackage_RoundTrip_Design.md
 *              sections 3 and 4. Downloads the golden fixture through /searchdownload (owner session)
 *              as a project export and as a dataset export and checks:
 *                - layers: points, lines, polygons (one feature per spot); attribute tables
 *                  strabo_export, strabo_spots, layer_styles
 *                - symbol_orientations on points: the spot's orientations in order (associated first)
 *                - strabo_id on every feature, proj_/ds_ columns
 *                - strabo_export (scope, project, datasets, owner) and strabo_spots (one row per spot,
 *                  layer, timestamp, original_geojson = the layer's geometry, editable = 0 for image
 *                  basemap and strat section spots)
 *                - styles: points = the per-spot orientation style (GeometryGenerator, one copy per
 *                  orientation, every rule as an "enabled" expression), strabo_id read-only everywhere
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
	$want = array('strabo_export' => 'attributes', 'strabo_spots' => 'attributes', 'layer_styles' => 'attributes');
	foreach ($wantLayers as $l) $want[$l] = 'features';
	ksort($want); ksort($contents);
	check("$what: tables " . implode(', ', array_keys($want)), $contents === $want, json_encode($contents));

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
	$styles = array(); foreach (gpkg_rows($gpkg, 'SELECT f_table_name, styleQML FROM layer_styles') as $r) $styles[$r['f_table_name']] = $r['styleQML'];
	$ps = $styles['points'] ?? '';
	check("$what: points style draws every orientation from symbol_orientations (generator + rules as enabled expressions)",
		strpos($ps, 'type="singleSymbol"') !== false && strpos($ps, 'class="GeometryGenerator"') !== false && strpos($ps, '@geometry_part_num') !== false
		&& substr_count($ps, 'name="enabled" type="Map"') >= 19 && strpos($ps, '<rule ') === false, 'enabled: ' . substr_count($ps, 'name="enabled" type="Map"'));
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
