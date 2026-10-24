<?php
/**
 * File: tests/gpkgroundtrip/apply_test.php
 * Description: GeoPackage round trip, phase 3 (Apply), docs/GeoPackage/GeoPackage_RoundTrip_Design.md section 7.
 *              Exports the golden fixture project, edits the file the way a QGIS save would (moved and
 *              reshaped spots, a conflict, a copied spot, drawn features), goes through load_geopackage.php
 *              (choice, preview, Apply) and checks:
 *                - updated spots: new wkt / origwkt, modified_timestamp now, every other property and
 *                  every relationship unchanged; the Postgres spot mirror has the new location
 *                - unchanged spots byte-identical in Neo4j
 *                - conflict answered "GeoPackage" written, the chosen copy of a copied spot written and
 *                  the other copy created as a new spot
 *                - new spots: names "<prefix>N", a ds_id of the project's dataset honoured (a copy keeps
 *                  its original's ds_id, so it lands in that dataset), the rest in a new dataset
 *                  (created_by the user, linked to the project)
 *                - dataset and project modified_timestamp bumped; a version of the project saved first,
 *                  holding the old geometry
 *                - the upload is removed (no second Apply); a fresh export of the project reads back
 *                  unchanged against the server
 *                - "keep the server geometry" writes nothing (no version); a spot changed on the server
 *                  between preview and Apply is not written
 *
 *              Uses the golden fixture of tests/fieldvocab (needs the test users from
 *              tests/collaboration/setup_test_data.php).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/gpkgroundtrip/apply_test.php
 *                docker exec strabo-php8 php /srv/app/www/tests/gpkgroundtrip/apply_test.php
 */

$SID_PREFIX = 'fvgpkgrt';
require_once __DIR__ . '/../fieldvocab/fixture_lib.php';
require_once __DIR__ . '/rt_lib.php';

$TMP = sys_get_temp_dir() . '/gpkgrt3_' . getmypid();
$RANDNUM = 900000000 + getmypid();
$_SERVER['HTTP_HOST'] = 'localhost';
const RT_BASEMAP_SPOT = 977910000108;

/** Upload a file, choose "update", return [randnum, preview html]. */
function preview($file) {
	$html = post(array('submitfile' => 'Submit'), $file);
	$rn = randnum_of($html);
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'update', 'randnum' => $rn, 'gpkgname' => basename($file)));
	return array($rn, $html);
}

function apply($rn, $file, $answers) {
	return post(array('rtsubmit' => 'Apply changes', 'rtpath' => 'apply', 'randnum' => $rn, 'gpkgname' => basename($file)) + $answers);
}

function text($html) { return trim(preg_replace('/\s+/', ' ', strip_tags($html))); }

/** Geometry of a spot as stored (geoPHP of wkt), as GeoJSON array. */
function stored_geometry($id) {
	$p = spot_props($id);
	return $p ? json_decode(geoPHP::load($p['wkt'], 'wkt')->out('json'), true) : null;
}

/** A spot's properties without the ones Apply may change. */
function untouched_props($p) {
	unset($p['wkt'], $p['origwkt'], $p['modified_timestamp'], $p['bbox']);
	return $p;
}

echo "GeoPackage round trip phase 3: Apply\n";
gf_cleanup();
exec('rm -rf ' . escapeshellarg($TMP)); mkdir($TMP, 0775, true);
$OGR_BEFORE = glob('/srv/app/www/ogrtemp/*') ?: array();
$ZIP_BEFORE = glob('/srv/app/www/ziptemp/*') ?: array();
try {
	section('Fixture and its project export');
	$EXPORT = rt_setup();
	$S = gf_spots();

	section('Edit the file, change "legacy" on the server');
	$legacyWkt = (string)$neodb->get_var("MATCH (s:Spot {id: {$S['legacy']}}) WHERE s.userpkey = $OWNER RETURN s.wkt");
	$lg = geoPHP::load($legacyWkt, 'wkt');
	$serverWkt = 'POINT (' . ($lg->x() + 0.01) . ' ' . ($lg->y() + 0.01) . ')';
	$neodb->query("MATCH (s:Spot {id: {$S['legacy']}}) WHERE s.userpkey = $OWNER SET s.wkt = '$serverWkt', s.origwkt = '$serverWkt'");
	$fileGeom = array();
	$edited = rebuild($EXPORT, "$TMP/edited.gpkg", function ($layer, $fs) use ($S, &$fileGeom) {
		$out = array();
		foreach ($fs as $f) {
			$id = sid($f);
			if ($id === (string)$S['petrology'] || $id === (string)$S['legacy']) $f['geometry'] = shift($f['geometry'], 0.001, 0.002);
			if ($id === (string)$S['trace']) $f['geometry'] = shift($f['geometry'], 0.0005, 0, true);
			if ($id === (string)$S['structure']) { $c = $f; $c['geometry'] = shift($c['geometry'], 0.01, 0); $out[] = $c; }
			if ($id === (string)$S['surface']) { $c = $f; $c['geometry'] = shift($c['geometry'], 0.003, 0); $out[] = $c; $fileGeom['surface_a'] = $c['geometry']; $f['geometry'] = shift($f['geometry'], 0, 0.003); $fileGeom['surface_b'] = $f['geometry']; }
			if ($id !== '') $fileGeom[$id] = $f['geometry'];
			$out[] = $f;
		}
		if ($layer === 'points') {
			$out[] = array('type' => 'Feature', 'properties' => array('strabo_id' => null, 'ds_id' => (string)GF_DS_B), 'geometry' => array('type' => 'Point', 'coordinates' => array(31, 21)));
			$out[] = array('type' => 'Feature', 'properties' => array('strabo_id' => null), 'geometry' => array('type' => 'Point', 'coordinates' => array(31.5, 21.5)));
		}
		return $out;
	});
	$ids = array_values($S); $ids[] = RT_BASEMAP_SPOT;
	$before = array(); $rels = array();
	foreach ($ids as $id) { $before[$id] = spot_props($id); $rels[$id] = spot_rels($id); }
	$versionsBefore = array_map(function ($r) { return $r->uuid; }, $db->get_results_prepared("SELECT uuid FROM versions WHERE projectid = $1", array((string)GF_PROJECT)) ?: array());
	$dsBefore = array();
	foreach (array(GF_DS_A, GF_DS_B) as $d) $dsBefore[$d] = $neodb->get_var("MATCH (d:Dataset {id: $d}) WHERE d.userpkey = $OWNER RETURN d.modified_timestamp");

	section('Preview');
	list($rn, $html) = preview($edited);
	check('preview: 2 updated, 3 to decide (1 conflict, 1 copied surface ... ) counts', strpos($html, '2 updated, ') !== false && strpos($html, ' 2 to decide,') !== false, substr(text($html), 0, 400));
	check('preview: conflict radios for legacy, copy radios for surface, new dataset preselected (project export)', strpos($html, 'name="c[' . $S['legacy'] . ']"') !== false
		&& substr_count($html, 'name="d[' . $S['surface'] . ']"') === 2 && strpos($html, '<option value="new" selected') !== false);
	preg_match_all('/name="d\[' . $S['surface'] . '\]" value="([0-9]+)"/', $html, $m);
	$copies = $m[1] ?? array();
	check('preview: two copies of surface to choose from', count($copies) === 2, json_encode($copies));
	$prefixMax = (int)$neodb->get_var("MATCH (s:Spot) WHERE s.userpkey = $OWNER AND toString(s.name) STARTS WITH 'RT Spot ' RETURN count(s)");
	check('no "RT Spot" names before', $prefixMax === 0);

	section('Apply');
	$t0 = (int)round(microtime(true) * 1000);
	$html = apply($rn, $edited, array('c[' . $S['legacy'] . ']' => 'file', 'd[' . $S['surface'] . ']' => $copies[0] ?? '', 'newds' => 'new', 'newdsname' => 'QGIS test additions', 'prefix' => 'RT Spot '));
	$t1 = (int)round(microtime(true) * 1000);
	check('result: 4 spots updated, 4 new spots created', strpos($html, '4 spots updated, 4 new spots created.') !== false, substr(text($html), 0, 500));
	check('result: version + "download again in StraboField" notes', strpos($html, 'Versioning') !== false && strpos($html, 'download this project again') !== false);

	section('Updated spots');
	foreach (array('petrology', 'legacy', 'trace') as $k) {
		$id = $S[$k];
		$p = spot_props($id);
		check("$k: geometry = the GeoPackage geometry", gpkgRoundTripSame(stored_geometry($id), $fileGeom[(string)$id]), json_encode(stored_geometry($id)) . ' vs ' . json_encode($fileGeom[(string)$id]));
		check("$k: wkt = origwkt", $p['wkt'] === $p['origwkt'], $p['wkt'] . ' | ' . $p['origwkt']);
		check("$k: modified_timestamp = now (ms)", (int)$p['modified_timestamp'] >= $t0 && (int)$p['modified_timestamp'] <= $t1, (string)$p['modified_timestamp']);
		check("$k: every other property unchanged", untouched_props($p) === untouched_props($before[$id]), json_encode(array_diff_assoc(array_map('json_encode', untouched_props($p)), array_map('json_encode', untouched_props($before[$id])))));
		check("$k: relationships unchanged " . json_encode($rels[$id]), spot_rels($id) === $rels[$id], json_encode(spot_rels($id)));
	}
	$sg = stored_geometry($S['surface']);
	$chosenA = gpkgRoundTripSame($sg, $fileGeom['surface_a']);
	$chosenB = gpkgRoundTripSame($sg, $fileGeom['surface_b']);
	check('surface: the chosen copy written (one of the two)', $chosenA xor $chosenB);
	check('surface: relationships unchanged', spot_rels($S['surface']) === $rels[$S['surface']]);
	$loc = $db->get_var_prepared("SELECT ST_AsText(location) FROM spot WHERE strabo_spot_id = $1 AND user_pkey = $2", array((string)$S['petrology'], $OWNER));
	$pg = $loc ? json_decode(geoPHP::load($loc, 'wkt')->out('json'), true) : null;
	check('petrology: Postgres spot mirror has the new location', $pg !== null && gpkgRoundTripSame($pg, $fileGeom[(string)$S['petrology']]), (string)$loc);

	section('Unchanged spots');
	foreach (array('structure', 'interval', 'sedbase') as $k) {
		check("$k: byte-identical in Neo4j", spot_props($S[$k]) === $before[$S[$k]]);
	}
	check('basemap spot: byte-identical in Neo4j', spot_props(RT_BASEMAP_SPOT) === $before[RT_BASEMAP_SPOT]);

	section('New spots');
	$new = array();
	foreach ($neodb->get_results("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = $OWNER AND toString(s.name) STARTS WITH 'RT Spot ' RETURN s.name AS name, s.wkt AS wkt, d.id AS ds, d.name AS dsname, d.created_by AS cb, s.modified_timestamp AS mt ORDER BY s.name") ?: array() as $r) {
		$new[(string)$r->get('name')] = array('wkt' => (string)$r->get('wkt'), 'ds' => (string)$r->get('ds'), 'dsname' => (string)$r->get('dsname'), 'cb' => (string)$r->get('cb'), 'mt' => (int)$r->get('mt'));
	}
	check('four new spots RT Spot 1..4 in the project', array_keys($new) === array('RT Spot 1', 'RT Spot 2', 'RT Spot 3', 'RT Spot 4'), json_encode(array_keys($new)));
	$where = array(); $newDs = '';
	foreach ($new as $name => $n) {
		$kind = strpos($n['wkt'], 'POINT (31 21)') === 0 ? 'drawn B' : (strpos($n['wkt'], 'POINT (31.5 21.5)') === 0 ? 'drawn' : (strpos($n['wkt'], 'POLYGON') === 0 ? 'surface copy' : 'structure copy'));
		$where[$kind] = $n['ds'] === (string)GF_DS_A ? 'A' : ($n['ds'] === (string)GF_DS_B ? 'B' : $n['dsname']);
		if ($n['dsname'] === 'QGIS test additions') $newDs = $n['ds'];
	}
	ksort($where);
	check('new spots: drawn with ds_id B -> B, copies -> their original\'s dataset, drawn without ds_id -> the new dataset',
		$where === array('drawn' => 'QGIS test additions', 'drawn B' => 'B', 'structure copy' => 'A', 'surface copy' => 'B'), json_encode($where));
	if ($newDs !== '') {
		$d = $neodb->get_results("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset {id: $newDs}) WHERE p.userpkey = $OWNER RETURN d.userpkey AS u, d.created_by AS cb, d.modified_timestamp AS mt");
		check('new dataset: linked to the project, owned by the owner, created_by the user', $d && (int)$d[0]->get('u') === $OWNER && (int)$d[0]->get('cb') === $OWNER);
	}
	$mts = array_column($new, 'mt');
	check('new spots: modified_timestamp now', min($mts) >= $t0 && max($mts) <= $t1);

	section('Timestamps, version, upload');
	foreach (array(GF_DS_A, GF_DS_B) as $d) {
		$mt = (int)$neodb->get_var("MATCH (d:Dataset {id: $d}) WHERE d.userpkey = $OWNER RETURN d.modified_timestamp");
		check("dataset $d modified_timestamp bumped", $mt >= $t0 && $mt <= $t1, "$mt (before {$dsBefore[$d]})");
	}
	$pmt = (int)$neodb->get_var("MATCH (p:Project {id: " . GF_PROJECT . "}) WHERE p.userpkey = $OWNER RETURN p.modified_timestamp");
	check('project modified_timestamp bumped', $pmt >= $t0 && $pmt <= $t1, (string)$pmt);
	$v = array_values(array_filter($db->get_results_prepared("SELECT uuid, spotcount FROM versions WHERE projectid = $1 AND userpkey = $2", array((string)GF_PROJECT, $OWNER)) ?: array(), function ($r) use ($versionsBefore) { return !in_array($r->uuid, $versionsBefore, true); }));
	check('one version of the project saved by Apply', count($v) === 1, json_encode($v));
	$versionCount = count($versionsBefore) + 1;
	$vjson = count($v) ? json_decode((string)@gzdecode((string)@file_get_contents('/srv/app/www/versions/' . $v[0]->uuid)), true) : null;
	$oldGeom = null;
	foreach (($vjson['versiondatasets'] ?? array()) as $vd) foreach (($vd['versionspots']['features'] ?? array()) as $vf) {
		if ((string)($vf['properties']['id'] ?? '') === (string)$S['petrology']) $oldGeom = $vf['geometry'];
	}
	check('the version holds petrology\'s old geometry (the undo)', $oldGeom !== null && gpkgRoundTripSame($oldGeom, json_decode(geoPHP::load($before[$S['petrology']]['wkt'], 'wkt')->out('json'), true)), json_encode($oldGeom));
	$html = apply($rn, $edited, array('c[' . $S['legacy'] . ']' => 'file', 'd[' . $S['surface'] . ']' => $copies[0] ?? ''));
	check('Apply again (reload): the upload is gone, nothing written', strpos($html, 'This upload was not found') !== false && count($db->get_results_prepared("SELECT uuid FROM versions WHERE projectid = $1", array((string)GF_PROJECT)) ?: array()) === $versionCount, substr(text($html), 0, 200));

	section('A fresh export of the project reads back unchanged');
	$again = rt_export('again');
	$r = summary(classify($again));
	check('fresh export: every spot unchanged (written geometries read back within tolerance)', !$r['update'] && !$r['conflict'] && !$r['new'] && !$r['missing'] && count($r['unchanged']) === 12, json_encode($r));

	section('"Keep the server geometry", and a change between preview and Apply');
	$e2 = rebuild($again, "$TMP/e2.gpkg", function ($layer, $fs) use ($S) {
		foreach ($fs as $i => $f) {
			if (sid($f) === (string)$S['petrology'] || sid($f) === (string)$S['trace']) $fs[$i]['geometry'] = shift($f['geometry'], 0.002, 0);
		}
		return $fs;
	});
	$pw = (string)$neodb->get_var("MATCH (s:Spot {id: {$S['petrology']}}) WHERE s.userpkey = $OWNER RETURN s.wkt");
	$pp = geoPHP::load($pw, 'wkt');
	$pw2 = 'POINT (' . ($pp->x() - 0.005) . ' ' . $pp->y() . ')';
	$neodb->query("MATCH (s:Spot {id: {$S['petrology']}}) WHERE s.userpkey = $OWNER SET s.wkt = '$pw2', s.origwkt = '$pw2'");
	list($rn2, $html) = preview($e2);
	check('preview 2: trace updated, petrology a conflict', strpos($html, '1 updated, ') !== false && strpos($html, 'name="c[' . $S['petrology'] . ']"') !== false, substr(text($html), 0, 300));
	// trace changes on the server after the preview
	$tw = (string)$neodb->get_var("MATCH (s:Spot {id: {$S['trace']}}) WHERE s.userpkey = $OWNER RETURN s.wkt");
	$tw2 = str_replace('LINESTRING (', 'LINESTRING (1 1, ', $tw);
	$neodb->query("MATCH (s:Spot {id: {$S['trace']}}) WHERE s.userpkey = $OWNER SET s.wkt = '$tw2', s.origwkt = '$tw2'");
	$p2 = spot_props($S['petrology']); $t2 = spot_props($S['trace']);
	$html = apply($rn2, $e2, array('c[' . $S['petrology'] . ']' => 'server'));
	check('apply 2: nothing changed (server kept, trace changed after the preview)', strpos($html, 'Nothing was changed.') !== false, substr(text($html), 0, 300));
	check('apply 2: petrology and trace untouched, no second version', spot_props($S['petrology']) === $p2 && spot_props($S['trace']) === $t2 && count($db->get_results_prepared("SELECT uuid FROM versions WHERE projectid = $1", array((string)GF_PROJECT)) ?: array()) === $versionCount);
} catch (Throwable $e) {
	check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
	// new spots / dataset have ids outside the fixture range: remove them by project
	$neodb->query("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) WHERE p.userpkey = $OWNER AND toString(s.name) STARTS WITH 'RT Spot ' DETACH DELETE s");
	foreach ($neodb->get_results("MATCH (p:Project {id: " . GF_PROJECT . "})-[:HAS_DATASET]->(d:Dataset) WHERE p.userpkey = $OWNER AND d.name = 'QGIS test additions' RETURN d.id AS id") ?: array() as $r) {
		$d = (int)$r->get('id');
		$db->query("DELETE FROM spot WHERE user_pkey = $OWNER AND dataset_pkey IN (SELECT dataset_pkey FROM dataset WHERE strabo_dataset_id = '$d' AND user_pkey = $OWNER)");
		$db->query("DELETE FROM dataset WHERE user_pkey = $OWNER AND strabo_dataset_id = '$d'");
		$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND project_id = $1", array((string)GF_PROJECT));
		$neodb->query("MATCH (d:Dataset {id: $d}) WHERE d.userpkey = $OWNER DETACH DELETE d");
	}
	$db->query("DELETE FROM spot WHERE user_pkey = $OWNER AND strabo_spot_id NOT LIKE '97791%' AND dataset_pkey IN (SELECT dataset_pkey FROM dataset WHERE strabo_dataset_id LIKE '97791%' AND user_pkey = $OWNER)");
	gf_cleanup();
	exec('rm -rf ' . escapeshellarg($TMP));
	foreach (array_diff(glob('/srv/app/www/ogrtemp/*') ?: array(), $OGR_BEFORE) as $dd) exec('rm -rf ' . escapeshellarg($dd));
	foreach (array_diff(glob('/srv/app/www/ziptemp/*') ?: array(), $ZIP_BEFORE) as $dd) exec('rm -rf ' . escapeshellarg($dd));
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
