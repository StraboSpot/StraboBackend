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
require_once __DIR__ . '/rt_lib.php';

$TMP = sys_get_temp_dir() . '/gpkgrt2_' . getmypid();
$RANDNUM = 900000000 + getmypid();   // ogrtemp/<n> scratch file of vectorImportReadFeatures
$_SERVER['HTTP_HOST'] = 'localhost';   // the export below is made through http://localhost
const RT_BASEMAP_SPOT = 977910000108;

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
	check('update: preview names the moved spots, Apply button, a choice for the conflict', strpos($html, 'GF') !== false && strpos($html, 'value="Apply changes"') !== false
		&& strpos($html, 'name="c[' . $S['legacy'] . ']" value="server"') !== false && strpos($html, 'name="c[' . $S['legacy'] . ']" value="file"') !== false);
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
