<?php
/**
 * File: tests/gpkgroundtrip/classify_test.php
 * Description: GeoPackage round trip, phase 2 (upload side, read-only), docs/GeoPackage/GeoPackage_RoundTrip_Design.md
 *              sections 5 and 6. Exports the golden fixture project as GeoPackage, builds edited copies
 *              the way a QGIS save would leave them (geometries moved, features copied / drawn / deleted,
 *              a multi-part feature, an extra layer, a save in another coordinate system) and checks:
 *                - gpkgRoundTripManifest: StraboSpot exports only
 *                - gpkgRoundTripTarget: owner ok; another server, a missing project, another user refused
 *                - gpkgRoundTripClassify: updated / unchanged / conflict / duplicate / new / locked /
 *                  gone / missing / refused / not read, per spot
 *                - load_geopackage.php: choice page, preview page, "load as new data" = the layer list,
 *                  a file that cannot update its project gets the note and only the normal import
 *                - nothing is written: every fixture spot is byte-identical in Neo4j after the HTTP steps
 *
 *              Uses the golden fixture of tests/fieldvocab (needs the test users from
 *              tests/collaboration/setup_test_data.php).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/gpkgroundtrip/classify_test.php
 *                docker exec strabo-php8 php /srv/app/www/tests/gpkgroundtrip/classify_test.php
 */

$SID_PREFIX = 'fvgpkgrt';
require_once __DIR__ . '/../fieldvocab/fixture_lib.php';
require_once '/srv/app/www/gpkgfuncs.php';
require_once '/srv/app/www/vector_import.php';
require_once '/srv/app/www/gpkgroundtrip.php';

$TMP = sys_get_temp_dir() . '/gpkgrt2_' . getmypid();
$RANDNUM = 900000000 + getmypid();   // ogrtemp/<n> scratch file of vectorImportReadFeatures
$_SERVER['HTTP_HOST'] = 'localhost';   // the export below is made through http://localhost
const RT_BASEMAP_SPOT = 977910000108;

/** Features (properties + geometry) of one layer, as GDAL reads them. */
function layer_features($gpkg, $layer) {
	$fc = json_decode((string)shell_exec('ogr2ogr -f GeoJSON /vsistdout/ ' . escapeshellarg($gpkg) . ' ' . escapeshellarg($layer) . ' 2>/dev/null'), true);
	return isset($fc['features']) ? $fc['features'] : array();
}

/**
 * A copy of $src with its layers passed through $edit($layer, $features) (return the features to
 * keep; extra layers come back under other keys), written in $srs, with the manifest tables copied
 * (strabo_export replaced by $export when given, left out when $export === false).
 */
function rebuild($src, $dst, $edit, $export = null, $srs = 'EPSG:4326', $types = array()) {
	global $TMP;
	@unlink($dst);
	$out = array();
	foreach (array('points', 'lines', 'polygons') as $layer) {
		$r = $edit($layer, layer_features($src, $layer));
		foreach ($r as $k => $v) { if (is_int($k)) $out[$layer][] = $v; else $out[$k] = $v; }
		if (!isset($out[$layer])) $out[$layer] = array();
	}
	$first = true;
	foreach ($out as $layer => $features) {
		if (!count($features)) continue;
		$nlt = $types[$layer] ?? array('points' => 'POINT', 'lines' => 'LINESTRING', 'polygons' => 'POLYGON')[$layer] ?? 'GEOMETRY';
		file_put_contents("$TMP/layer.json", json_encode(array('type' => 'FeatureCollection', 'features' => $features)));
		exec('ogr2ogr -f GPKG ' . ($first ? '' : '-update ') . "-nlt $nlt -nln " . escapeshellarg($layer) . ' -s_srs EPSG:4326 -t_srs ' . escapeshellarg($srs) . ' ' . escapeshellarg($dst) . ' ' . escapeshellarg("$TMP/layer.json") . ' 2>&1');
		$first = false;
	}
	exec('ogr2ogr -update -nlt NONE ' . escapeshellarg($dst) . ' ' . escapeshellarg($src) . ' strabo_spots 2>&1');
	if ($export === null) {
		exec('ogr2ogr -update -nlt NONE ' . escapeshellarg($dst) . ' ' . escapeshellarg($src) . ' strabo_export 2>&1');
	} elseif ($export !== false) {
		file_put_contents("$TMP/export.json", json_encode(array('type' => 'FeatureCollection', 'features' => array(array('type' => 'Feature', 'geometry' => null, 'properties' => $export)))));
		exec('ogr2ogr -update -nlt NONE -nln strabo_export ' . escapeshellarg($dst) . ' ' . escapeshellarg("$TMP/export.json") . ' 2>&1');
	}
	return $dst;
}

/** Move every position of a geometry by $dx, $dy degrees (only the first vertex when $first). */
function shift($g, $dx, $dy, $first = false) {
	$done = false;
	$walk = function ($c) use (&$walk, $dx, $dy, $first, &$done) {
		if (!is_array($c[0])) {
			if ($first && $done) return $c;
			$done = true;
			return array($c[0] + $dx, $c[1] + $dy);
		}
		return array_map($walk, $c);
	};
	$g['coordinates'] = $walk($g['coordinates']);
	return $g;
}

function sid($f) { return (string)($f['properties']['strabo_id'] ?? ''); }

function classify($gpkg) {
	global $neodb, $db, $OWNER, $RANDNUM;
	$export = gpkgRoundTripManifest($gpkg);
	$target = gpkgRoundTripTarget($neodb, $db, $OWNER, $export);
	if (!$target['ok']) return array('target' => $target);
	return gpkgRoundTripClassify($neodb, $db, $RANDNUM, $gpkg, geopackageLayers($gpkg), $target);
}

/** Spot ids per category (sorted), new / refused / notread as counts. */
function summary($r) {
	$s = array();
	foreach (array('update', 'unchanged', 'conflict', 'duplicate', 'locked', 'noaccess', 'gone', 'missing') as $k) {
		$ids = array_map(function ($e) { return (string)$e['id']; }, $r[$k] ?? array());
		sort($ids);
		$s[$k] = $ids;
	}
	foreach (array('new', 'refused', 'notread') as $k) $s[$k] = count($r[$k] ?? array());
	return $s;
}

function post($fields, $file = null) {
	global $SID;
	$out = tempnam(sys_get_temp_dir(), 'rt_');
	$cmd = 'curl -s -o ' . escapeshellarg($out) . ' -H ' . escapeshellarg("Cookie: PHPSESSID=$SID") . ' ';
	foreach ($fields as $k => $v) $cmd .= ($file ? '-F ' : '--data-urlencode ') . escapeshellarg("$k=$v") . ' ';
	if ($file) $cmd .= '-F ' . escapeshellarg("gpkgfile=@$file;filename=edited.gpkg;type=application/geopackage+sqlite3") . ' ';
	shell_exec($cmd . escapeshellarg('http://localhost/load_geopackage.php'));
	$html = (string)file_get_contents($out);
	@unlink($out);
	return $html;
}

function randnum_of($html) { return preg_match('/name="randnum" value="([0-9]+)"/', $html, $m) ? $m[1] : ''; }

/** Every fixture spot's stored properties (to prove the preview writes nothing). */
function spot_snapshot() {
	global $neodb, $OWNER;
	$snap = array();
	foreach ($neodb->get_results("MATCH (s:Spot) WHERE s.userpkey = $OWNER AND s.id >= " . GF_MIN . " AND s.id <= " . GF_MAX . " RETURN s ORDER BY s.id") ?: array() as $r) {
		$p = $r->get('s')->values();
		ksort($p);
		$snap[] = json_encode($p);
	}
	return $snap;
}

echo "GeoPackage round trip phase 2: manifest, target, classification, choice + preview pages\n";
gf_cleanup();
exec('rm -rf ' . escapeshellarg($TMP)); mkdir($TMP, 0775, true);
$OGR_BEFORE = glob('/srv/app/www/ogrtemp/*') ?: array();
$ZIP_BEFORE = glob('/srv/app/www/ziptemp/*') ?: array();
try {
	section('Fixture (+ a spot on an image basemap) and its project export');
	gf_upload();
	$S = gf_spots();
	$A = gf_features()[GF_DS_A];
	$A[] = gf_feature(RT_BASEMAP_SPOT, 'GF basemap spot', gf_point(30, 20), array('image_basemap' => 977910000401));
	list($st, $tx) = http('POST', '/db/datasetspots/' . GF_DS_A, array('type' => 'FeatureCollection', 'features' => $A));
	check('POST /db/datasetspots A with the basemap spot', $st >= 200 && $st < 300, "$st " . substr($tx, 0, 200));
	list($st, $bytes) = http('GET', '/searchdownload?type=gpkg&projectid=' . GF_PROJECT, null, 'session');
	$EXPORT = "$TMP/export.gpkg";
	file_put_contents($EXPORT, $bytes);
	check('project export downloaded', $st === 200 && substr($bytes, 0, 15) === 'SQLite format 3', "HTTP $st");
	$ALL = array();
	foreach (array('points', 'lines', 'polygons') as $l) foreach (layer_features($EXPORT, $l) as $f) $ALL[] = sid($f);
	sort($ALL);

	section('gpkgRoundTripManifest');
	$e = gpkgRoundTripManifest($EXPORT);
	check('the export: strabo_export row', is_array($e) && $e['project_id'] === (string)GF_PROJECT && $e['owner_userpkey'] === (string)$OWNER, json_encode($e));
	$plain = rebuild($EXPORT, "$TMP/plain.gpkg", function ($l, $fs) { return $fs; }, false);
	check('no strabo_export table: null (normal import)', gpkgRoundTripManifest($plain) === null);
	$v2 = rebuild($EXPORT, "$TMP/v2.gpkg", function ($l, $fs) { return $fs; }, array('format_version' => 2) + $e);
	check('unknown format_version: null', gpkgRoundTripManifest($v2) === null);

	section('gpkgRoundTripTarget');
	$t = gpkgRoundTripTarget($neodb, $db, $OWNER, $e);
	check('owner: ok, project name from the server', $t['ok'] && $t['projectname'] !== '' && $t['context'] !== null, json_encode(array($t['ok'], $t['reason'])));
	$t = gpkgRoundTripTarget($neodb, $db, $OWNER, array('server' => 'www.LOCALHOST:8186') + $e);
	check('same host with port / www.: ok', $t['ok'], $t['reason']);
	$t = gpkgRoundTripTarget($neodb, $db, $OWNER, array('server' => 'strabospot.org') + $e);
	check('exported from another server: refused', !$t['ok'] && strpos($t['reason'], 'strabospot.org') !== false, $t['reason']);
	$t = gpkgRoundTripTarget($neodb, $db, $OWNER, array('project_id' => '977910000999') + $e);
	check('project no longer exists: refused', !$t['ok'] && strpos($t['reason'], 'no longer exists') !== false, $t['reason']);
	$t = gpkgRoundTripTarget($neodb, $db, 1, $e);
	check('another user (no collaboration): refused', !$t['ok'] && strpos($t['reason'], 'edit access') !== false, $t['reason']);

	section('Classification: an unedited export');
	$r = summary(classify($EXPORT));
	check('every spot unchanged, nothing else', $r['unchanged'] === $ALL && !$r['update'] && !$r['conflict'] && !$r['new'] && !$r['missing'] && !$r['refused'] && !$r['locked'], json_encode($r));

	section('Classification: saved in EPSG:3857 (float noise within tolerance)');
	$merc = rebuild($EXPORT, "$TMP/merc.gpkg", function ($l, $fs) { return $fs; }, null, 'EPSG:3857');
	$r = summary(classify($merc));
	check('every spot unchanged after a save in another coordinate system', $r['unchanged'] === $ALL && !$r['update'] && !$r['conflict'], json_encode($r));

	section('Classification: edits');
	// the server copy of "legacy" moves after the export: moving it in QGIS too is a conflict
	$legacyWkt = (string)$neodb->get_var("MATCH (s:Spot {id: {$S['legacy']}}) WHERE s.userpkey = $OWNER RETURN s.wkt");
	$moved = geoPHP::load($legacyWkt, 'wkt');
	$newWkt = 'POINT (' . ($moved->x() + 0.01) . ' ' . ($moved->y() + 0.01) . ')';
	$neodb->query("MATCH (s:Spot {id: {$S['legacy']}}) WHERE s.userpkey = $OWNER SET s.wkt = '$newWkt', s.origwkt = '$newWkt'");
	$edited = rebuild($EXPORT, "$TMP/edited.gpkg", function ($layer, $fs) use ($S) {
		$out = array();
		foreach ($fs as $f) {
			$id = sid($f);
			if ($id === (string)$S['sedbase']) continue;                                         // deleted in QGIS
			if ($id === (string)$S['petrology'] || $id === (string)$S['legacy'] || $id === (string)RT_BASEMAP_SPOT) $f['geometry'] = shift($f['geometry'], 0.001, 0.002);
			if ($id === (string)$S['trace']) $f['geometry'] = shift($f['geometry'], 0.0005, 0, true);   // one vertex moved
			if ($id === (string)$S['structure']) { $c = $f; $c['geometry'] = shift($c['geometry'], 0.01, 0); $out[] = $c; }   // copy and paste, then moved
			if ($layer === 'polygons') {
				$f['geometry'] = array('type' => 'MultiPolygon', 'coordinates' => array($f['geometry']['coordinates']));   // QGIS multi layer
				if ($id === (string)$S['surface']) $f['geometry']['coordinates'][] = shift($f['geometry'], 1, 1)['coordinates'][0];   // second part
			}
			$out[] = $f;
		}
		if ($layer === 'points') {
			$out[] = array('type' => 'Feature', 'properties' => array('strabo_id' => null, 'spot_name' => 'drawn in QGIS'), 'geometry' => array('type' => 'Point', 'coordinates' => array(31, 21)));
			$out['my_notes'] = array(array('type' => 'Feature', 'properties' => array('note' => 'x'), 'geometry' => array('type' => 'Point', 'coordinates' => array(31, 21))));
		}
		return $out;
	}, null, 'EPSG:4326', array('polygons' => 'MULTIPOLYGON'));
	$full = classify($edited);
	$r = summary($full);
	$want = array(
		'update' => array((string)$S['petrology'], (string)$S['trace']),
		'unchanged' => array((string)$S['structure'], (string)$S['interval']),
		'conflict' => array((string)$S['legacy']),
		'duplicate' => array(),
		'locked' => array((string)RT_BASEMAP_SPOT),
		'noaccess' => array(),
		'gone' => array(),
		'missing' => array((string)$S['sedbase']),
		'new' => 2, 'refused' => 1, 'notread' => 1,
	);
	sort($want['update']);
	sort($want['unchanged']);
	foreach ($want as $k => $v) check("edited: $k = " . json_encode($v), $r[$k] === $v, json_encode($r[$k]));
	$copy = array_values(array_filter($full['new'], function ($n) { return isset($n['copy_of']); }));
	check('edited: the moved copy of "structure" is a new feature marked as its copy', count($copy) === 1 && $copy[0]['copy_of'] === (string)$S['structure']);
	check('edited: the multi-part polygon is refused with its reason', ($full['refused'][0]['id'] ?? '') === (string)$S['surface'] && strpos($full['refused'][0]['reason'] ?? '', '2 parts') !== false, json_encode($full['refused']));
	check('edited: the extra layer is not read', ($full['notread'][0]['layer'] ?? '') === 'my_notes');
	$u = array(); foreach ($full['update'] as $x) $u[$x['id']] = $x;
	check('edited: the update carries the file geometry (petrology moved by 0.001, 0.002)', isset($u[(string)$S['petrology']]) && abs($u[(string)$S['petrology']]['file']['coordinates'][0] - $u[(string)$S['petrology']]['orig']['coordinates'][0] - 0.001) < 1e-9);

	section('Classification: a copied spot whose copies both moved, a spot removed from the project');
	$dup = rebuild($EXPORT, "$TMP/dup.gpkg", function ($layer, $fs) use ($S) {
		$out = array();
		foreach ($fs as $f) {
			if (sid($f) === (string)$S['trace']) { $c = $f; $c['geometry'] = shift($c['geometry'], 0.01, 0); $out[] = $c; $f['geometry'] = shift($f['geometry'], 0, 0.01); }
			$out[] = $f;
		}
		return $out;
	});
	$neodb->query("MATCH (d:Dataset)-[r:HAS_SPOT]->(s:Spot {id: {$S['sedbase']}}) WHERE d.userpkey = $OWNER DELETE r");
	$full = classify($dup);
	$r = summary($full);
	check('dup: trace asks which copy updates it (2 features), no update, no new', $r['duplicate'] === array((string)$S['trace']) && count($full['duplicate'][0]['features']) === 2 && !$r['update'] && $r['new'] === 0, json_encode($r));
	check('dup: sedbase, removed from its dataset on the server, is gone', $r['gone'] === array((string)$S['sedbase']), json_encode($r['gone']));

	section('load_geopackage.php');
	$before = spot_snapshot();
	$html = post(array('submitfile' => 'Submit', 'projectid' => GF_PROJECT), $edited);
	$rn = randnum_of($html);
	check('upload of an export: choice page, update preselected', $rn !== '' && strpos($html, 'name="rtpath" value="update" checked') !== false && strpos($html, 'value="new"') !== false, substr(strip_tags($html), 0, 300));
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'update', 'randnum' => $rn, 'projectid' => GF_PROJECT, 'gpkgname' => 'edited.gpkg'));
	// sedbase (deleted in the file) was removed from the project above: gone, so not listed as deleted
	check('update: preview with the counts', strpos($html, '2 updated, 2 unchanged, 2 new, 1 to decide, 2 not updated</b>') !== false, substr(trim(preg_replace('/\s+/', ' ', strip_tags($html))), 0, 600));
	check('update: preview names the moved spots, no Apply yet', strpos($html, 'GF') !== false && stripos($html, 'name="apply"') === false);
	$html = post(array('rtsubmit' => 'Load as new data instead', 'rtpath' => 'new', 'randnum' => $rn, 'projectid' => GF_PROJECT, 'gpkgname' => 'edited.gpkg'));
	check('load as new data: today\'s layer list (4 feature layers)', strpos($html, 'Select Layer(s)') !== false && substr_count($html, 'type="checkbox"') === 4);
	check('nothing written: every fixture spot unchanged in Neo4j', spot_snapshot() === $before);

	$other = rebuild($EXPORT, "$TMP/other.gpkg", function ($l, $fs) { return $fs; }, array('server' => 'strabospot.org') + $e);
	$html = post(array('submitfile' => 'Submit', 'projectid' => GF_PROJECT), $other);
	$rn2 = randnum_of($html);
	check('export from another server: note, only "load as new data"', strpos($html, 'cannot be updated') !== false && strpos($html, 'value="update"') === false && strpos($html, 'name="rtpath" value="new"') !== false);
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'update', 'randnum' => $rn2, 'projectid' => GF_PROJECT, 'gpkgname' => 'other.gpkg'));
	check('posting "update" anyway: refused with the reason', strpos($html, 'cannot update a project on this site') !== false && strpos($html, 'updated,') === false);

	$html = post(array('submitfile' => 'Submit', 'projectid' => GF_PROJECT), $plain);
	check('a GeoPackage without strabo_export: today\'s layer list directly', strpos($html, 'Select Layer(s)') !== false && strpos($html, 'rtpath') === false);

	section('load_geopackage.php: the project is asked after the upload');
	list($st, $page) = http('GET', '/load_geopackage.php', null, 'session');
	check('upload page: file only, no project picker', $st === 200 && strpos($page, 'name="gpkgfile"') !== false && strpos($page, 'name="projectid"') === false, "HTTP $st");
	$html = post(array('submitfile' => 'Submit'), $edited);
	$rn = randnum_of($html);
	check('export, no project: choice page, project picker under "load as new data", nothing preselected', $rn !== '' && strpos($html, 'value="update" checked') !== false
		&& strpos($html, '<select name="projectid"') !== false && strpos($html, 'value="' . GF_PROJECT . '" selected') === false);
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'update', 'randnum' => $rn, 'projectid' => '', 'gpkgname' => 'edited.gpkg'));
	check('export, no project: update preview works', strpos($html, '2 updated,') !== false);
	check('preview: "load as new data instead" carries no empty project', strpos($html, 'name="projectid"') === false);
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'new', 'randnum' => $rn, 'projectid' => '', 'gpkgname' => 'edited.gpkg'));
	check('export, "load as new data" without a project: asked for it, upload kept', strpos($html, 'Project must be selected.') !== false && randnum_of($html) === $rn && strpos($html, 'Select Layer(s)') === false);
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'new', 'randnum' => $rn, 'projectid' => GF_PROJECT, 'gpkgname' => 'edited.gpkg'));
	check('export, project chosen: today\'s layer list', strpos($html, 'Select Layer(s)') !== false);
	$html = post(array('submitfile' => 'Submit', 'projectid' => GF_PROJECT), $edited);
	check('export, a project posted with the upload (older form): preselected under "load as new data"', strpos($html, 'value="' . GF_PROJECT . '" selected') !== false);

	$html = post(array('submitfile' => 'Submit'), $plain);
	$rn = randnum_of($html);
	check('plain GeoPackage, no project: project page (no error yet), upload kept', $rn !== '' && strpos($html, 'Project to load') !== false && strpos($html, 'Project must be selected') === false && strpos($html, 'Select Layer(s)') === false);
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'new', 'randnum' => $rn, 'projectid' => GF_PROJECT, 'gpkgname' => 'plain.gpkg'));
	check('plain GeoPackage, project chosen: today\'s layer list', strpos($html, 'Select Layer(s)') !== false && strpos($html, 'value="' . GF_PROJECT . '"') !== false);
	$html = post(array('rtsubmit' => 'Continue', 'rtpath' => 'new', 'randnum' => $rn, 'projectid' => '1', 'gpkgname' => 'plain.gpkg'));
	check('a project of another user: refused', strpos($html, 'Project not found.') !== false);
} catch (Throwable $e) {
	check('uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
	gf_cleanup();
	exec('rm -rf ' . escapeshellarg($TMP));
	foreach (array_diff(glob('/srv/app/www/ogrtemp/*') ?: array(), $OGR_BEFORE) as $dd) exec('rm -rf ' . escapeshellarg($dd));
	foreach (array_diff(glob('/srv/app/www/ziptemp/*') ?: array(), $ZIP_BEFORE) as $dd) exec('rm -rf ' . escapeshellarg($dd));
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : 'ALL PASS') . "\n";
exit(count($failures) ? 1 : 0);
