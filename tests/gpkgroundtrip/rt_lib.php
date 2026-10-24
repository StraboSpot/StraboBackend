<?php
/**
 * File: tests/gpkgroundtrip/rt_lib.php
 * Description: Helpers of the GeoPackage round trip upload tests (classify_test.php, apply_test.php):
 *              read layers, build edited copies of an export the way a QGIS save leaves them, classify
 *              a file, drive load_geopackage.php over HTTP, snapshot the fixture spots.
 *              Include after tests/fieldvocab/fixture_lib.php; uses $TMP, $RANDNUM, $SID.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/gpkgfuncs.php';
require_once '/srv/app/www/vector_import.php';
require_once '/srv/app/www/gpkgroundtrip.php';

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

/** Outgoing relationship counts of a spot by type (orientations, samples, images, ...). */
function spot_rels($id) {
	global $neodb, $OWNER;
	$out = array();
	foreach ($neodb->get_results("MATCH (s:Spot {id: $id})-[r]->() WHERE s.userpkey = $OWNER RETURN type(r) AS t, count(r) AS n ORDER BY t") ?: array() as $r) $out[(string)$r->get('t')] = (int)$r->get('n');
	return $out;
}

/** One fixture spot's stored properties. */
function spot_props($id) {
	global $neodb, $OWNER;
	$r = $neodb->get_results("MATCH (s:Spot {id: $id}) WHERE s.userpkey = $OWNER RETURN s");
	if (!$r) return null;
	$p = $r[0]->get('s')->values();
	ksort($p);
	return $p;
}

/** Upload the golden fixture plus a spot on an image basemap, download the project export; returns its path. */
function rt_setup() {
	global $TMP;
	gf_upload();
	$A = gf_features()[GF_DS_A];
	$A[] = gf_feature(RT_BASEMAP_SPOT, 'GF basemap spot', gf_point(30, 20), array('image_basemap' => 977910000401));
	list($st, $tx) = http('POST', '/db/datasetspots/' . GF_DS_A, array('type' => 'FeatureCollection', 'features' => $A));
	check('POST /db/datasetspots A with the basemap spot', $st >= 200 && $st < 300, "$st " . substr($tx, 0, 200));
	return rt_export('export');
}

/** Download the fixture project as GeoPackage to $TMP/<name>.gpkg. */
function rt_export($name) {
	global $TMP;
	list($st, $bytes) = http('GET', '/searchdownload?type=gpkg&projectid=' . GF_PROJECT, null, 'session');
	$f = "$TMP/$name.gpkg";
	file_put_contents($f, $bytes);
	check("project export ($name) downloaded", $st === 200 && substr($bytes, 0, 15) === 'SQLite format 3', "HTTP $st");
	return $f;
}
