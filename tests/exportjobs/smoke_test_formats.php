<?php
/**
 * File: tests/exportjobs/smoke_test_formats.php
 * Description: M3 smoke suite for the Export Builder FORMAT stage
 *              (docs/ExportBuilder_Design.md §8, §12 M3).
 *
 *              PARITY: for every live generator the legacy streaming path
 *              (output-buffered, exactly what a dropdown download sends) is
 *              compared with capture mode on the same parameters. Zip-based
 *              outputs compare member by member (xlsx skips docProps
 *              timestamps), PDFs compare with creation dates stripped,
 *              GeoPackage compares ogrinfo layer summaries, text/json exact.
 *              ATTRIBUTION: scope_groups + attribution mode stamps
 *              proj_id/proj_name/ds_id/ds_name onto every feature.
 *              PLUGIN: full worker runs for merged / split_project /
 *              split_dataset layouts, extras (project_json + geologic
 *              units, incl. the "no units" warning), a polygon + criteria
 *              recipe, an unknown format rejection, and zero residue.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/exportjobs/smoke_test_formats.php
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';   // PDF_LabBook.php resolves tfpdf through it
ini_set('memory_limit', '2G');
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/geophp/geoPHP.inc';
require_once 'includes/UUID.php';
require_once 'db/strabospotclass.php';
require_once 'includes/straboClasses/straboOutputClass.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once 'exportjobs/lib/export_config.php';
require_once 'exportjobs/lib/ExportJobService.php';
require_once 'exportjobs/plugins/FieldExportPlugin.php';
require_once 'includes/fieldbook/Fieldbook.php';

$OWNER = 94551;
$P1 = 945511001; $P2 = 945511002;
$DS_A = 945512001; $DS_B = 945512002; $DS_C = 945512003;
$S_PT = 945513001; $S_LN = 945513002; $S_PG = 945513003; $S_B = 945513004; $S_C = 945513005; $S_PT2 = 945513006;
$IMG1 = 945514001; $IMG2 = 945514002;
$TMP = '/tmp/ej_formats_' . getmypid();
$cfg = export_config();
$svc = new ExportJobService($db);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function ejt_sh($cmd) { exec($cmd . ' 2>&1', $o, $rc); return array($rc, implode("\n", $o)); }
function rmrf($d) { if (is_dir($d)) exec('rm -rf ' . escapeshellarg($d)); }
function cleanup() {
	global $db, $neodb, $OWNER, $IMG1, $IMG2, $TMP, $cfg;
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
		OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image) DETACH DELETE i, s");
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) DETACH DELETE d, p");
	$neodb->query("MATCH (u:User {userpkey: $OWNER}) DETACH DELETE u");
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_userpkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_userpkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM export_jobs WHERE userpkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($OWNER));
	@unlink("/srv/app/www/dbimages/$IMG1"); @unlink("/srv/app/www/dbimages/$IMG2");
	rmrf($TMP); rmrf(rtrim($cfg['results_root'], '/') . "/$OWNER");
}

echo "Export Builder M3 formats smoke suite\n";
cleanup();
mkdir($TMP, 0775, true);

// ------------------------------------------------------------------ fixtures
$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active) VALUES ($1, 'fmt', 'fixture', $2, 'x', 'x', false)", array($OWNER, "fmt-$OWNER@test.strabospot.org"));
$neodb->query("CREATE (u:User {userpkey: $OWNER, email: 'fmt-$OWNER@test.strabospot.org'})");
// "features" = spot id -> sub-feature ids (an OBJECT): the tag shape that crashed geologicUnitsOut on 2026-09-01
$UNITS = '[{\"id\": 945516001, \"name\": \"Granite of Fixture\", \"type\": \"geologic_unit\", \"unit_label_abbreviation\": \"Kgf\", \"eon\": [\"phanerozoic\"], \"spots\": [' . $S_PT . '], \"features\": {\"' . $S_PT . '\": [945516099]}},'
       . ' {\"id\": 945516002, \"name\": \"Plain tag\", \"type\": \"concept\", \"spots\": [' . $S_LN . ']}]';
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P1, userpkey: $OWNER, desc_project_name: 'Fmt Project One', json_tags: '$UNITS'}) CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P2, userpkey: $OWNER, desc_project_name: 'Fmt Project Two'}) CREATE (u)-[:HAS_PROJECT]->(p)");
foreach (array($P1 => 'Fmt Project One', $P2 => 'Fmt Project Two') as $pid => $pn) {
	$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, $2, $3, FALSE)", array($OWNER, $pn, (string)$pid));
}
foreach (array($DS_A => array($P1, 'Alpha Dataset'), $DS_B => array($P1, 'Beta Dataset'), $DS_C => array($P2, 'Gamma Dataset')) as $did => $pp) {
	$neodb->query("MATCH (p:Project {id: {$pp[0]}, userpkey: $OWNER}) CREATE (d:Dataset {id: $did, userpkey: $OWNER, name: '{$pp[1]}'}) CREATE (p)-[:HAS_DATASET]->(d)");
}
$ORI = '[{\"strike\": 120, \"dip\": 30, \"type\": \"planar_orientation\", \"feature_type\": \"bedding\", \"id\": 945517001}]';
function spot($did, $id, $name, $wkt, $extra = '') {
	global $neodb, $OWNER;
	$neodb->query("MATCH (d:Dataset {id: $did, userpkey: $OWNER}) CREATE (s:Spot {id: $id, userpkey: $OWNER, name: '$name', wkt: '$wkt', origwkt: '$wkt',
		modified_timestamp: 1722400000000, date: '2026-07-15T10:00:00Z', time: '2026-07-15T10:00:00Z', notes: 'notes for $name' $extra}) CREATE (d)-[:HAS_SPOT]->(s)");
}
spot($DS_A, $S_PT, 'Fmt Point', 'POINT (-118.25 34.05)', ", json_orientation_data: '$ORI'");
spot($DS_A, $S_LN, 'Fmt Line', 'LINESTRING (-118.26 34.04, -118.24 34.06)', ", json_trace: '{\"trace_feature\": true, \"trace_type\": \"contact\"}'");
spot($DS_A, $S_PG, 'Fmt Polygon', 'POLYGON ((-118.27 34.03, -118.23 34.03, -118.23 34.07, -118.27 34.07, -118.27 34.03))');
spot($DS_A, $S_PT2, 'Fmt Outside', 'POINT (-118.10 34.05)', ", json_orientation_data: '$ORI'");
spot($DS_B, $S_B, 'Fmt Beta Point', 'POINT (-118.28 34.02)', ", json_orientation_data: '$ORI'");
spot($DS_C, $S_C, 'Fmt Gamma Point', 'POINT (-118.26 34.03)');
// two real JPEGs in the image store (the generators copy / thumbnail them)
foreach (array($IMG1 => $S_PT, $IMG2 => $S_B) as $iid => $sid) {
	$im = imagecreatetruecolor(64, 48); imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 40, 120, 200)); imagejpeg($im, "/srv/app/www/dbimages/$iid", 80); imagedestroy($im);
	$neodb->query("MATCH (s:Spot {id: $sid, userpkey: $OWNER}) CREATE (i:Image {id: $iid, userpkey: $OWNER, image_type: 'photo', title: 'Fixture image $iid', filename: '$iid', width: 64, height: 48, modified_timestamp: 1722400001000}) CREATE (s)-[:HAS_IMAGE]->(i)");
}
foreach (array($S_PT, $S_LN, $S_PG, $S_PT2, $S_B, $S_C) as $sid) StraboSearchSync::touchSpot($db, $neodb, $sid, $OWNER);
check('fixtures indexed', (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_userpkey = $1 AND item_type='spot'", array($OWNER)) === 6);

$strabo = new StraboSpot($neodb, $OWNER, $db); $strabo->setuuid(new UUID());
$GET = array('dsids' => (string)$DS_A, 'userpkey' => $OWNER);

// ------------------------------------------------------------------ helpers for parity
function legacy_bytes($strabo, $get, $method) {
	$out = new straboOutputClass($strabo, $get);
	ob_start(); $out->$method(); return ob_get_clean();
}
function capture_run($strabo, $get, $method, $dir) {
	rmrf($dir); mkdir($dir, 0775, true);
	$out = new straboOutputClass($strabo, $get); $out->captureDir = $dir;
	ob_start(); $out->$method(); $stray = ob_get_clean();
	return array($out->captured, $stray);
}
/** zip bytes -> [member => sha256] */
function zip_members($zipPath, $skipPrefix = null) {
	$out = array();
	list(, $list) = ejt_sh('unzip -Z1 ' . escapeshellarg($zipPath));
	foreach (array_filter(explode("\n", $list)) as $m) {
		if (substr($m, -1) === '/') continue;
		if ($skipPrefix !== null && strpos($m, $skipPrefix) === 0) continue;
		$pat = str_replace('[', '[[]', $m);   // unzip treats [ ] as a glob ([Content_Types].xml)
		$out[$m] = hash('sha256', shell_exec('unzip -p ' . escapeshellarg($zipPath) . ' ' . escapeshellarg($pat)));
	}
	ksort($out); return $out;
}
function dir_members($dir) {
	$out = array();
	foreach (glob(rtrim($dir, '/') . '/*') as $f) if (is_file($f)) $out[basename($f)] = hash_file('sha256', $f);
	ksort($out); return $out;
}
function pdf_norm($bytes) { return preg_replace('#/(CreationDate|ModDate) \([^)]*\)#', '', $bytes); }

// ------------------------------------------------------------------ 1. parity per format
$legacyZip = "$TMP/legacy.zip";
// shapefile (zip of shp set) vs capture dir shapefile/
file_put_contents($legacyZip, legacy_bytes($strabo, $GET, 'expandedShapefileOut'));
list($cap,) = capture_run($strabo, $GET, 'expandedShapefileOut', "$TMP/cap_shp");
$lz = zip_members($legacyZip); $cd = dir_members("$TMP/cap_shp/shapefile");
check('shapefile parity: same members + bytes', $lz === $cd && count($lz) >= 9, count($lz) . ' vs ' . count($cd));
// kmz
file_put_contents($legacyZip, legacy_bytes($strabo, $GET, 'kmlOut'));
list($cap,) = capture_run($strabo, $GET, 'kmlOut', "$TMP/cap_kmz");
$capKmz = glob("$TMP/cap_kmz/*.kmz");
check('kmz parity: same members + bytes', $capKmz && zip_members($legacyZip) === zip_members($capKmz[0]), json_encode(array_keys(zip_members($legacyZip))));
// xlsx (skip docProps timestamps)
file_put_contents($legacyZip, legacy_bytes($strabo, $GET, 'xlsOut'));
list($cap,) = capture_run($strabo, $GET, 'xlsOut', "$TMP/cap_xls");
$capX = glob("$TMP/cap_xls/*.xlsx");
check('xlsx parity: same sheets + bytes (docProps skipped)', $capX && zip_members($legacyZip, 'docProps/') === zip_members($capX[0], 'docProps/'));
// stereonet text
$lt = legacy_bytes($strabo, $GET, 'stereonetOut');
list($cap,) = capture_run($strabo, $GET, 'stereonetOut', "$TMP/cap_st");
check('stereonet parity: exact text', $cap && file_get_contents($cap[0]['path']) === $lt && strlen($lt) > 10, strlen($lt));
// fieldbook pdf: the builder's "fieldbook" format is the ENHANCED book since 2026-09 (docs/Fieldbook_Design.md D8);
// the legacy generator stays behind the hidden fieldbook_legacy format and keeps byte parity with its streaming path
$lp = legacy_bytes($strabo, $GET, 'legacyFieldbookOut');
list($cap,) = capture_run($strabo, $GET, 'legacyFieldbookOut', "$TMP/cap_fb");
check('legacy fieldbook parity: PDF bytes equal with dates stripped', $cap && substr($lp, 0, 4) === '%PDF' && pdf_norm(file_get_contents($cap[0]['path'])) === pdf_norm($lp), strlen($lp) . ' / ' . ($cap ? filesize($cap[0]['path']) : 'none'));
check('legacy fieldbook can run twice in one process (require_once)', (function () use ($strabo, $GET, $TMP) { list($c,) = capture_run($strabo, $GET, 'legacyFieldbookOut', "$TMP/cap_fb2"); return (bool)$c; })());
Fieldbook::$mapsOverride = array('set' => 'none');   // keep this suite off the network
list($cap, $stray) = capture_run($strabo, $GET, 'fieldbookOut', "$TMP/cap_fbnew");
check('enhanced fieldbook: PDF with outline captured under the dataset name, no stray output', $cap && strpos($cap[0]['name'], 'Alpha_Dataset_fieldbook_') === 0 && strpos(file_get_contents($cap[0]['path']), '/Outlines') !== false && $stray === '', $stray);
// geojson
$lj = legacy_bytes($strabo, $GET, 'geoJSONOut');
list($cap,) = capture_run($strabo, $GET, 'geoJSONOut', "$TMP/cap_gj");
check('geojson parity: exact', $cap && file_get_contents($cap[0]['path']) === $lj && json_decode($lj) !== null);
$gj = json_decode($lj, true);
check('geojson carries project tags for tagged spots', count($gj['features']) === 4 && (function ($gj, $S_PT) { foreach ($gj['features'] as $f) if ((string)$f['properties']['id'] === (string)$S_PT) return !empty($f['properties']['tags']); return false; })($gj, $S_PT));
// gpkg (sqlite carries timestamps: compare ogrinfo summaries)
$lg = legacy_bytes($strabo, $GET, 'gpkgOut'); file_put_contents("$TMP/legacy.gpkg", $lg);
list($cap,) = capture_run($strabo, $GET, 'gpkgOut', "$TMP/cap_gp");
$norm = function ($p) { list(, $o) = ejt_sh('ogrinfo -al -so ' . escapeshellarg($p)); return preg_replace('#^INFO: Open of.*$#m', '', $o); };
check('gpkg parity: ogrinfo layer summaries equal', $cap && $norm("$TMP/legacy.gpkg") === $norm($cap[0]['path']) && strpos($norm("$TMP/legacy.gpkg"), 'Layer name: points') !== false);
// images: legacy redirects (nothing in the body); capture yields images/ with the named copies
$li = legacy_bytes($strabo, $GET, 'downloadImages');
list($cap,) = capture_run($strabo, $GET, 'downloadImages', "$TMP/cap_im");
$imgFiles = glob("$TMP/cap_im/images/*");
check('images: legacy body empty (redirect) + capture has the copied image', trim($li) === '' && count($imgFiles) === 1 && strpos(basename($imgFiles[0]), 'Fmt-Point') !== false && filesize($imgFiles[0]) === filesize("/srv/app/www/dbimages/$IMG1"), json_encode(array_map('basename', $imgFiles)));
check('images: legacy ziptemp scratch cleaned by the generator', count(glob('ziptemp/*/StraboImages')) === 0);
// no-data seam
$noData = false;
try { capture_run($strabo, array('dsids' => '999999999999', 'userpkey' => $OWNER), 'xlsOut', "$TMP/cap_nd"); } catch (ExportNoDataException $e) { $noData = true; }
check('capture mode: empty selection throws instead of exit()', $noData);

// ------------------------------------------------------------------ 2. attribution + scope groups
$GETA = array('dsids' => (string)$DS_A, 'all_dsids' => "$DS_A,$DS_C", 'userpkey' => $OWNER, 'attribution' => 1,
	'scope_groups' => array(array('userpkey' => $OWNER, 'dsids' => array($DS_A, $DS_C), 'spot_ids' => array($S_PT, $S_LN, $S_C))));
list($cap,) = capture_run($strabo, $GETA, 'geoJSONOut', "$TMP/cap_attr");
$gj = json_decode(file_get_contents($cap[0]['path']), true);
$byId = array(); foreach ($gj['features'] as $f) $byId[(string)$f['properties']['id']] = $f['properties'];
check('scope_groups restrict to the listed spots across two projects', count($byId) === 3 && isset($byId[$S_PT]) && isset($byId[$S_C]) && !isset($byId[$S_PG]), json_encode(array_keys($byId)));
check('attribution columns on every feature', $byId[$S_PT]['proj_name'] === 'Fmt Project One' && $byId[$S_PT]['ds_name'] === 'Alpha Dataset' && $byId[$S_PT]['proj_id'] === (string)$P1 && $byId[$S_C]['proj_name'] === 'Fmt Project Two' && $byId[$S_C]['ds_id'] === (string)$DS_C, json_encode($byId[$S_PT]));
check('merged geojson carries tags from the first project via all_dsids', !empty($byId[$S_PT]['tags']));
list($cap,) = capture_run($strabo, $GETA, 'xlsOut', "$TMP/cap_attr_x");
$ss = shell_exec('unzip -p ' . escapeshellarg($cap[0]['path']) . ' xl/sharedStrings.xml');
check('attribution reaches the xlsx (header "Proj Name" + values)', strpos($ss, 'Proj Name') !== false && strpos($ss, 'Ds Name') !== false && strpos($ss, 'Fmt Project Two') !== false && strpos($ss, 'Gamma Dataset') !== false, substr($ss, 0, 200));
list($cap,) = capture_run($strabo, $GETA, 'expandedShapefileOut', "$TMP/cap_attr_s");
$dbf = file_get_contents("$TMP/cap_attr_s/shapefile/points.dbf");
check('attribution reaches the shapefile dbf', strpos($dbf, 'proj_name') !== false && strpos($dbf, 'Fmt Project') !== false);
check('legacy fetch (no attribution) has no attribution keys', !isset(json_decode($lj, true)['features'][0]['properties']['proj_name']));

// ------------------------------------------------------------------ 3. plugin through the real worker
function run_job($svc, $recipe, $summary) {
	global $OWNER;
	$job = $svc->create($OWNER, $recipe, array('summary' => $summary, 'origin' => 'test'));
	exec('php /srv/app/www/exportjobs/worker.php --job=' . $job['uuid'] . ' 2>&1', $o);
	return $svc->get($job['uuid']);
}
function ejt_zip_list($svc, $job) { global $cfg; list(, $l) = ejt_sh('unzip -Z1 ' . escapeshellarg(rtrim($cfg['results_root'], '/') . '/' . $job['result_path'])); return array_filter(explode("\n", $l)); }
function ejt_zip_read($svc, $job, $member) { global $cfg; return shell_exec('unzip -p ' . escapeshellarg(rtrim($cfg['results_root'], '/') . '/' . $job['result_path']) . ' ' . escapeshellarg($member)); }
$scope2 = array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER), array('id' => (string)$P2, 'owner' => $OWNER)));
$allFmts = array_keys(FieldExportPlugin::$formats);

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope2, 'formats' => $allFmts, 'layout' => 'merged', 'extras' => array('project_json', 'geologic_units')), 'merged all formats');
check('merged job done', $j['status'] === 'done', $j['status'] . ' ' . $j['error_text']);
check('merged job counts (6 spots, 0 children)', $j['item_count'] === 6 && $j['child_count'] === 0, $j['item_count'] . '/' . $j['child_count']);
$zl = ejt_zip_list($svc, $j);
$has = function ($needle) use (&$zl) { foreach ($zl as $m) if (strpos($m, $needle) !== false) return true; return false; };
check('merged zip: one output set at the root for every format', $has('shapefile/points.shp') && $has('.kmz') && $has('.xlsx') && $has('StraboSpot_Search_') && $has('Field_Book') && $has('images/') && $has('.json') && $has('.gpkg'), implode(',', $zl));
check('merged zip: README + manifest', $has('README.txt') && $has('manifest.json'));
check('merged zip: images from both datasets that have them', $has('Fmt-Point') && $has('Fmt-Beta-Point'));
check('merged zip: extras per project folder', $has('projects/Fmt_Project_One_' . $P1 . '/project.json') && $has('projects/Fmt_Project_One_' . $P1 . '/geologic_units.xlsx') && $has('projects/Fmt_Project_Two_' . $P2 . '/project.json'));
$guTmp = sys_get_temp_dir() . '/ejt_gu_' . getmypid() . '.xlsx';
file_put_contents($guTmp, ejt_zip_read($svc, $j, 'projects/Fmt_Project_One_' . $P1 . '/geologic_units.xlsx'));
$gus = (string)shell_exec('unzip -p ' . escapeshellarg($guTmp) . ' xl/sharedStrings.xml'); @unlink($guTmp);
check('geologic_units.xlsx: unit with a "features" object writes (crash fixed), list values joined, no Features/Spots columns', strpos($gus, 'Kgf') !== false && strpos($gus, 'phanerozoic') !== false && stripos($gus, 'features') === false && stripos($gus, '>spots<') === false, substr($gus, 0, 300));
$readme = ejt_zip_read($svc, $j, 'README.txt');
check('README names both projects and the no-units warning for project two', strpos($readme, 'Fmt Project One') !== false && strpos($readme, 'Fmt Project Two') !== false && strpos($readme, 'has no geologic units') !== false, substr($readme, 0, 300));
$gjm = null; foreach ($zl as $m) if (substr($m, -5) === '.json' && strpos($m, 'manifest') === false && strpos($m, 'project.json') === false) $gjm = $m;
$gjd = json_decode(ejt_zip_read($svc, $j, $gjm), true);
check('merged geojson has 6 features with attribution from both projects', $gjd && count($gjd['features']) === 6 && (function ($gjd) { $n = array(); foreach ($gjd['features'] as $f) $n[$f['properties']['proj_name']] = 1; return count($n) === 2; })($gjd));
$pj = json_decode(ejt_zip_read($svc, $j, 'projects/Fmt_Project_One_' . $P1 . '/project.json'), true);
check('project.json is the project JSON export', is_array($pj) && !empty($pj));

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope2, 'formats' => array('geojson', 'stereonet'), 'layout' => 'split_project'), 'split by project');
$zl = ejt_zip_list($svc, $j);
check('split_project: per-project folders', $j['status'] === 'done' && !empty($j['result_path']) && $has('projects/Fmt_Project_One_' . $P1 . '/') && $has('projects/Fmt_Project_Two_' . $P2 . '/') && !$has('projects/Fmt_Project_One_' . $P1 . '/datasets/'), $j['status'] . ' ' . $j['error_text'] . ' ' . implode(',', $zl));
$gjOne = null; foreach ($zl as $m) if (strpos($m, 'Fmt_Project_One') !== false && substr($m, -5) === '.json') $gjOne = $m;
$g1 = json_decode(ejt_zip_read($svc, $j, $gjOne), true);
check('split_project: project one file holds only its 5 spots', $g1 && count($g1['features']) === 5);

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope2, 'formats' => array('geojson'), 'layout' => 'split_dataset'), 'split by dataset');
$zl = ejt_zip_list($svc, $j);
check('split_dataset: per-dataset folders', $j['status'] === 'done' && $has('/datasets/Alpha_Dataset_' . $DS_A . '/') && $has('/datasets/Beta_Dataset_' . $DS_B . '/') && $has('/datasets/Gamma_Dataset_' . $DS_C . '/'), implode(',', $zl));
$gjB = null; foreach ($zl as $m) if (strpos($m, 'Beta_Dataset') !== false && substr($m, -5) === '.json') $gjB = $m;
$gB = json_decode(ejt_zip_read($svc, $j, $gjB), true);
check('split_dataset: beta file holds exactly its 1 spot', $gB && count($gB['features']) === 1 && (string)$gB['features'][0]['properties']['id'] === (string)$S_B);

$poly = array('type' => 'Polygon', 'coordinates' => array(array(array(-118.30, 34.00), array(-118.20, 34.00), array(-118.20, 34.10), array(-118.30, 34.10), array(-118.30, 34.00))));
$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope2, 'formats' => array('geojson', 'xls'), 'layout' => 'merged',
	'criteria' => array(array('id' => 'U2', 'value' => $poly), array('id' => 'U9', 'value' => array('orientation')))), 'polygon + orientation');
$zl = ejt_zip_list($svc, $j);
$gjm = null; foreach ($zl as $m) if (substr($m, -5) === '.json' && strpos($m, 'manifest') === false) $gjm = $m;
$gp = json_decode(ejt_zip_read($svc, $j, $gjm), true);
$pids = array(); foreach ($gp['features'] as $f) $pids[] = (int)$f['properties']['id']; sort($pids);
check('polygon + orientation recipe -> point + beta point only', $j['status'] === 'done' && $pids === array($S_PT, $S_B), json_encode($pids) . ' ' . $j['error_text']);
$readme = ejt_zip_read($svc, $j, 'README.txt');
check('README records the index sync + area filter + dropped count', strpos($readme, 'StraboSearch index') !== false && strpos($readme, 'Area filter') !== false && strpos($readme, '1 spots outside') !== false, substr($readme, 0, 400));

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)),
	'datasets' => array(array('id' => (string)$DS_B, 'project_id' => (string)$P1, 'owner' => $OWNER))), 'formats' => array('geojson'), 'extras' => array('project_json'), 'layout' => 'merged'), 'partial project + extra');
$readme = ejt_zip_read($svc, $j, 'README.txt');
check('extras skipped with a warning when only some datasets are selected', $j['status'] === 'done' && strpos($readme, 'project_json skipped') !== false, $j['status']);

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope2, 'formats' => array('gems'), 'layout' => 'merged'), 'bad format');
check('unknown format fails the job cleanly', $j['status'] === 'failed' && strpos($j['error_text'], "Unknown format") !== false, $j['status']);
$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope2, 'formats' => array('geojson'), 'criteria' => array(array('id' => 'U1', 'value' => 'zzz_nothing_matches_zzz'))), 'empty match');
check('empty match fails with a clear message', $j['status'] === 'failed' && strpos($j['error_text'], 'No spots matched') !== false, $j['status'] . ' ' . $j['error_text']);
check('worker left no workspaces', count(glob(rtrim($cfg['work_root'], '/') . '/*', GLOB_ONLYDIR)) === 0);

// ------------------------------------------------------------------ cleanup
cleanup();
check('zero residue', (int)$neodb->query("MATCH (u:User {userpkey: $OWNER}) RETURN count(u) AS c")[0]->value('c') === 0
	&& (int)$db->get_var_prepared("SELECT count(*) FROM export_jobs WHERE userpkey = $1", array($OWNER)) === 0
	&& !file_exists("/srv/app/www/dbimages/$IMG1") && !is_dir($TMP));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
