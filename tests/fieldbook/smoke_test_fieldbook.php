<?php
/**
 * File: tests/fieldbook/smoke_test_fieldbook.php
 * Description: M1 smoke suite for the enhanced fieldbook
 *              (docs/Fieldbook_Design.md §9, §12 M1). Synthetic fixture
 *              (two projects, three datasets, spots across two field days
 *              with orientations + associated lineations, samples, a
 *              geologic unit + a tag, a photo with a spot drawn on it, an
 *              orphan basemap child, sed + custom_fields families).
 *              Checks the document model (tree, days, ordering, nesting,
 *              counts, filename), both doors (legacy capture run with
 *              fb_* options, Export Builder worker with merged and
 *              split_dataset layouts, the hidden fieldbook_legacy format),
 *              option validation, the searchdownload routing, the progress
 *              callback and stray output.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/fieldbook/smoke_test_fieldbook.php
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
require_once 'searchdb/sync/StraboSearchSync.php';
require_once 'exportjobs/lib/export_config.php';
require_once 'exportjobs/lib/ExportJobService.php';
require_once 'exportjobs/plugins/FieldExportPlugin.php';
require_once 'includes/fieldbook/Fieldbook.php';

$OWNER = 94591;   // fieldbook fixture user (export suites use 94540..94582)
$P1 = 945911001; $P2 = 945911002;
$DS_A = 945912001; $DS_B = 945912002; $DS_C = 945912003;
// spot ids carry their creation time in the first 10 digits (the day grouping rule)
$S1 = 1721030400101; $S2 = 1721034000102; $S3 = 1721037600103; $S4 = 1721041200104;   // 2024-07-15
$S5 = 1721120400105;                                                                 // 2024-07-16 (DS_B)
$S6 = 1721206800106;                                                                 // 2024-07-17 (DS_C)
$S7 = 1721044800107;                                                                 // 2024-07-15 orphan basemap child
$IMG1 = 945914001;
$TMP = '/tmp/fb_smoke_' . getmypid();
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
	global $db, $neodb, $OWNER, $IMG1, $TMP, $cfg;
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image) DETACH DELETE i, s");
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) DETACH DELETE d, p");
	$neodb->query("MATCH (u:User {userpkey: $OWNER}) DETACH DELETE u");
	$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_userpkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_userpkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM export_jobs WHERE userpkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($OWNER));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($OWNER));
	@unlink("/srv/app/www/dbimages/$IMG1");
	rmrf($TMP); rmrf(rtrim($cfg['results_root'], '/') . "/$OWNER");
}
echo "Enhanced fieldbook M1 smoke suite\n";
cleanup();
mkdir($TMP, 0775, true);

// ------------------------------------------------------------------ fixtures
$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active) VALUES ($1, 'Field', 'Bookster', $2, 'x', 'x', false)", array($OWNER, "fb-$OWNER@test.strabospot.org"));
$neodb->query("CREATE (u:User {userpkey: $OWNER, email: 'fb-$OWNER@test.strabospot.org'})");
$TAGS = '[{\"id\": 945916001, \"name\": \"Granite of Fixture\", \"type\": \"geologic_unit\", \"unit_label_abbreviation\": \"Kgf\", \"rock_type\": \"igneous\", \"spots\": [' . $S1 . ', ' . $S3 . ']},'
      . ' {\"id\": 945916002, \"name\": \"Shear zone\", \"type\": \"concept\", \"concept_type\": \"geological_structure\", \"spots\": [' . $S1 . ']}]';
$NOTES = '[{\"date\": \"2024-07-15T08:00:00Z\", \"notes\": \"Fixture daily notes for the fifteenth.\"}, {\"date\": \"2024-07-16T08:00:00Z\", \"notes\": \"Second day notes.\"}]';
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P1, userpkey: $OWNER, desc_project_name: 'Fieldbook Project One', json_tags: '$TAGS', desc_daily_setup: '$NOTES'}) CREATE (u)-[:HAS_PROJECT]->(p)");
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P2, userpkey: $OWNER, desc_project_name: 'Fieldbook Project Two'}) CREATE (u)-[:HAS_PROJECT]->(p)");
foreach (array($P1 => 'Fieldbook Project One', $P2 => 'Fieldbook Project Two') as $pid => $pn) {
	$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, $2, $3, FALSE)", array($OWNER, $pn, (string)$pid));
}
foreach (array($DS_A => array($P1, 'Alpha Dataset'), $DS_B => array($P1, 'Beta Dataset'), $DS_C => array($P2, 'Gamma Dataset')) as $did => $pp) {
	$neodb->query("MATCH (p:Project {id: {$pp[0]}, userpkey: $OWNER}) CREATE (d:Dataset {id: $did, userpkey: $OWNER, name: '{$pp[1]}'}) CREATE (p)-[:HAS_DATASET]->(d)");
}
$ORI = '[{\"strike\": 120, \"dip\": 30, \"dip_direction\": 210, \"type\": \"planar_orientation\", \"feature_type\": \"bedding\", \"quality\": \"good\", \"facing\": \"upright\", \"id\": 945917001, \"unix_timestamp\": 1721030500000,'
     . ' \"associated_orientation\": [{\"trend\": 200, \"plunge\": 12, \"type\": \"linear_orientation\", \"feature_type\": \"lineation_general\", \"id\": 945917002}]},'
     . ' {\"trend\": 45, \"plunge\": 60, \"type\": \"linear_orientation\", \"feature_type\": \"fold_hinge\", \"id\": 945917003}]';
$SAMPLES = '[{\"id\": 945918001, \"label\": \"FB-001\", \"sample_id_name\": \"FB-001\", \"material_type\": \"intact_rock\", \"inplaceness_of_sample\": \"5___definitely\", \"sample_description\": \"fixture sample\", \"collection_date\": \"2024-07-15T09:30:00.000Z\"}]';
$SED = '{\"lithologies\": [{\"id\": 945919001, \"primary_lithology\": \"siliciclastic\", \"siliciclastic_type\": \"sandstone\", \"grain_size\": \"medium_sand\"}], \"bedding\": {\"bed_thickness\": 0.5, \"cross_bedding\": true}}';
$CUSTOM = '{\"field_partner\": \"A. Tester\", \"weather\": \"clear\"}';
function spot($did, $id, $name, $wkt, $extra = '') {
	global $neodb, $OWNER;
	$neodb->query("MATCH (d:Dataset {id: $did, userpkey: $OWNER}) CREATE (s:Spot {id: $id, userpkey: $OWNER, name: '$name', wkt: '$wkt', origwkt: '$wkt',
		modified_timestamp: 1722400000000, date: '2024-07-15T10:00:00Z', time: '2024-07-15T10:00:00Z', notes: 'notes for $name' $extra}) CREATE (d)-[:HAS_SPOT]->(s)");
}
spot($DS_A, $S1, 'FB Station 1', 'POINT (-118.25 34.05)', ", json_orientation_data: '$ORI', json_samples: '$SAMPLES', sed: '$SED', custom_fields: '$CUSTOM', altitude: 812, gps_accuracy: 4");
spot($DS_A, $S2, 'FB Contact', 'LINESTRING (-118.26 34.04, -118.24 34.06)', ", json_trace: '{\"trace_feature\": true, \"trace_type\": \"contact\"}'");
spot($DS_A, $S3, 'FB Outcrop Photo Station', 'POINT (-118.255 34.052)');
spot($DS_A, $S4, 'FB Detail on photo', 'POINT (-118.255 34.052)', ", image_basemap: '$IMG1', json_orientation_data: '[{\"strike\": 10, \"dip\": 80, \"type\": \"planar_orientation\", \"feature_type\": \"fault\", \"id\": 945917004}]'");
spot($DS_B, $S5, 'FB Beta Station', 'POINT (-118.28 34.02)', ", json_orientation_data: '$ORI'");
spot($DS_C, $S6, 'FB Gamma Station', 'POINT (-118.26 34.03)');
spot($DS_A, $S7, 'FB Orphan child', 'POINT (100 200)', ", image_basemap: '999999999'");
$im = imagecreatetruecolor(64, 48); imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 40, 120, 200)); imagejpeg($im, "/srv/app/www/dbimages/$IMG1", 80); imagedestroy($im);
$neodb->query("MATCH (s:Spot {id: $S3, userpkey: $OWNER}) CREATE (i:Image {id: $IMG1, userpkey: $OWNER, image_type: 'photo', title: 'Outcrop overview', caption: 'Looking north', width: 64, height: 48, annotated: '1', filename: '$IMG1'}) CREATE (s)-[:HAS_IMAGE]->(i)");
foreach (array($S1, $S2, $S3, $S4, $S5, $S6, $S7) as $sid) StraboSearchSync::touchSpot($db, $neodb, $sid, $OWNER);
$strabo = new StraboSpot($neodb, $OWNER, $db); $strabo->setuuid(new UUID());
Fieldbook::$mapsOverride = array('set' => 'none');   // no network in this suite; maps have their own (smoke_test_maps.php)

function capture_run($strabo, $get, $method, $dir, $progress = null) {
	rmrf($dir); mkdir($dir, 0775, true);
	$out = new straboOutputClass($strabo, $get); $out->captureDir = $dir; if ($progress) $out->progress = $progress;
	ob_start(); $out->$method(); $stray = ob_get_clean();
	return array($out->captured, $stray);
}
function pdf_pages($bytes) { return preg_match_all('#/Type /Page(?!s)#', $bytes); }

// ------------------------------------------------------------------ 1. model
$GET = array('dsids' => (string)$DS_A, 'userpkey' => $OWNER);
$json = $strabo->getDatasetSpotsSearch(null, $GET);
check('fixture fetch: 5 features in Alpha', count($json['features']) === 5, count($json['features']));
$tree = Fieldbook::treeFromNeo4j($strabo, $OWNER, array($DS_A, $DS_B));
check('treeFromNeo4j: one project, two datasets, spot map covers 6 spots', count($tree) === 1 && count($tree[0]['dsids']) === 2 && count($tree[0]['spot_map']) === 6, json_encode(array_map(function ($t) { return array($t['project_name'], $t['dsids'], count($t['spot_map'])); }, $tree)));
check('treeFromNeo4j: datasets sorted by name', $tree[0]['dataset_names'][$tree[0]['dsids'][0]] === 'Alpha Dataset');
$notes = array((string)$DS_A => $strabo->getDailyNotesFromDatasetID($DS_A));
$m = FieldbookModel::build($json['features'], $strabo->getTagsFromDatasetIds((string)$DS_A), $notes, Fieldbook::treeFromNeo4j($strabo, $OWNER, array($DS_A)), Fieldbook::meta($strabo, $OWNER, Fieldbook::treeFromNeo4j($strabo, $OWNER, array($DS_A)), $GET));
check('model: title = dataset, subtitle = project, owner from users table', $m->meta['title'] === 'Alpha Dataset' && $m->meta['subtitle'] === 'Project: Fieldbook Project One' && $m->meta['owner'] === 'Field Bookster', json_encode($m->meta));
$days = $m->projects[0]['datasets'][0]['days'];
check('model: one field day (2024-07-15) with the daily note attached', count($days) === 1 && $days[0]['key'] === '2024-07-15' && count($days[0]['notes']) === 1, json_encode(array_map(function ($d) { return array($d['key'], count($d['spots']), count($d['notes'])); }, $days)));
$names = array_map(function ($s) { return $s['name']; }, $days[0]['spots']);
check('model: 4 top-level spots in creation order, orphan child kept top-level', $names === array('FB Station 1', 'FB Contact', 'FB Outcrop Photo Station', 'FB Orphan child'), json_encode($names));
check('model: spots numbered 1..4', $days[0]['spots'][3]['n'] === 4);
$s3 = $days[0]['spots'][2];
check('model: child nested under its image with the image attributes', count($s3['images']) === 1 && $s3['images'][0]['title'] === 'Outcrop overview' && count($s3['images'][0]['children']) === 1 && $s3['images'][0]['children'][0]['name'] === 'FB Detail on photo');
check('model: orphan flagged', $days[0]['spots'][3]['orphan'] === true);
$s1 = $days[0]['spots'][0];
check('model: orientation row with associated lineation, 3 measurements counted on S1', count($s1['orientations']) === 2 && count($s1['orientations'][0]['children']) === 1 && $s1['orientationCount'] === 3 && $s1['orientations'][0]['feature'] === 'Bedding' && $s1['orientations'][0]['a'] === '120');
check('model: unix_timestamp of a measurement lands in "more" as a date', !empty($s1['orientations'][0]['more']) && strpos($s1['orientations'][0]['more'][0]['v'], '2024') !== false, json_encode($s1['orientations'][0]['more']));
check('model: sample title + humanised inplaceness + ISO date', $s1['samples'][0]['title'] === 'FB-001' && in_array('5 definitely', array_column($s1['samples'][0]['rows'], 'v'), true) && in_array('July 15, 2024 09:30 UTC', array_column($s1['samples'][0]['rows'], 'v'), true), json_encode($s1['samples'][0]));
check('model: unit + tag split', count($s1['units']) === 1 && $s1['units'][0]['name'] === 'Granite of Fixture' && count($s1['tags']) === 1 && $s1['tags'][0]['name'] === 'Shear zone');
$famKeys = array_column($s1['families'], 'key');
check('model: generic families sed + custom_fields present, handled keys absent', $famKeys === array('sed', 'custom_fields'), json_encode($famKeys));
$sedRows = $s1['families'][0]['rows'];
check('model: sed walker nests list items under a heading and humanises tokens', $sedRows[0]['h'] === true && in_array('Medium sand', array_column($sedRows, 'v'), true) && in_array('Yes', array_column($sedRows, 'v'), true), json_encode($sedRows));
check('model: coords + altitude + gps accuracy on the header line', count($s1['coords']) === 4 && $s1['coords'][2][1] === '812 m', json_encode($s1['coords']));
check('model: counts (4 spots + 1 child, 4 measurements, 1 sample, 1 photo)', $m->counts['spots'] === 4 && $m->counts['children'] === 1 && $m->counts['orientations'] === 4 && $m->counts['samples'] === 1 && $m->counts['images'] === 1, json_encode($m->counts));
check('model: summaries (1 unit x2 spots, 1 tag, 1 sample, 1 image)', $m->summary['units']['Granite of Fixture']['count'] === 2 && count($m->summary['tags']) === 1 && count($m->summary['samples']) === 1 && count($m->summary['images']) === 1);
check('model: filename from the dataset name', $m->filename === 'Alpha_Dataset_fieldbook_' . date('Y-m-d') . '.pdf', $m->filename);
$vals = array(); FieldbookModel::blockScalars($s1, $vals);
check('model: blockScalars carries notes, sample, unit, custom field values', in_array('notes for FB Station 1', $vals, true) && in_array('A. Tester', $vals, true) && in_array('Kgf', $vals, true));

// ------------------------------------------------------------------ 2. legacy door (capture run = the download page minus the browser)
$calls = 0;
list($cap, $stray) = capture_run($strabo, $GET, 'fieldbookOut', "$TMP/cap_new", function ($stage, $d, $t, $n) use (&$calls) { $calls++; });
$pdf = $cap ? file_get_contents($cap[0]['path']) : '';
check('new fieldbook captured, no stray output', $cap && substr($pdf, 0, 4) === '%PDF' && $stray === '', $stray);
check('new fieldbook filename', $cap && $cap[0]['name'] === 'Alpha_Dataset_fieldbook_' . date('Y-m-d') . '.pdf', $cap ? $cap[0]['name'] : 'none');
check('new fieldbook has an outline (bookmarks) and Letter pages', strpos($pdf, '/Outlines') !== false && strpos($pdf, '/MediaBox [0 0 612.00 792.00]') !== false);
check('new fieldbook: cover + contents + body + summary >= 4 pages', pdf_pages($pdf) >= 4, pdf_pages($pdf));
check('progress callback fired once per spot (5)', $calls === 5, $calls);
list($cap,) = capture_run($strabo, $GET + array('fb_page' => 'a4', 'fb_photos' => 'none', 'fb_map' => 'bogus'), 'fieldbookOut', "$TMP/cap_a4");
$pdfA4 = $cap ? file_get_contents($cap[0]['path']) : '';
check('fb_page=a4 switches the page size; bogus option falls back silently', strpos($pdfA4, '/MediaBox [0 0 595.28 841.89]') !== false);
list($cap, $stray) = capture_run($strabo, $GET, 'legacyFieldbookOut', "$TMP/cap_legacy");
check('legacy generator still produces its PDF under its old name', $cap && strpos($cap[0]['name'], 'StraboSpot_Field_Book_') === 0 && substr(file_get_contents($cap[0]['path']), 0, 4) === '%PDF', $cap ? $cap[0]['name'] : $stray);
list($cap,) = capture_run($strabo, $GET, 'fieldbookOut', "$TMP/cap_twice");
check('new fieldbook renders twice in one process', (bool)$cap);
try { capture_run($strabo, array('dsids' => (string)$DS_C, 'userpkey' => $OWNER, 'spot_ids' => array()), 'fieldbookOut', "$TMP/cap_empty"); check('empty selection in capture mode throws ExportNoDataException', false); }
catch (ExportNoDataException $e) { ob_end_clean(); check('empty selection in capture mode throws ExportNoDataException', strpos($e->getMessage(), 'No spots') === 0, $e->getMessage()); }
$route = file_get_contents('searchdownload.php');
check('searchdownload routes: fieldbook -> legacy, fieldbookdev -> new, fieldbooklegacy -> legacy', preg_match('/"fieldbook"\)\{\s*\$straboOut->legacyFieldbookOut\(\)/', $route) && preg_match('/"fieldbookdev"\)\{\s*\$straboOut->fieldbookOut\(\)/', $route) && preg_match('/"fieldbooklegacy"\)\{\s*\$straboOut->legacyFieldbookOut\(\)/', $route));
check('devfieldbookOut removed', strpos(file_get_contents('includes/straboClasses/straboOutputClass.php'), 'function devfieldbookOut') === false);

// ------------------------------------------------------------------ 3. options + builder
$v = FieldExportPlugin::validateOutput(array('formats' => array('fieldbook'), 'fieldbook' => array('page' => 'A4', 'photos' => 'full')));
check('validateOutput normalises fieldbook options', $v['fieldbook'] === array('map' => 'outdoors', 'photos' => 'full', 'nets' => 'on', 'page' => 'a4'), json_encode($v['fieldbook']));
try { FieldExportPlugin::validateOutput(array('formats' => array('fieldbook'), 'fieldbook' => array('page' => 'tabloid'))); check('validateOutput rejects an unknown page size', false); }
catch (ExportJobError $e) { check('validateOutput rejects an unknown page size', strpos($e->getMessage(), 'tabloid') !== false, $e->getMessage()); }
check('fieldbook_legacy is a registered (hidden) builder format', isset(FieldExportPlugin::$formats['fieldbook_legacy']));
check('export_builder.php does not list fieldbook_legacy', strpos(file_get_contents('export_builder.php'), 'fieldbook_legacy') === false);

function run_job($svc, $recipe, $summary) {
	global $OWNER;
	$job = $svc->create($OWNER, $recipe, array('summary' => $summary, 'origin' => 'test'));
	exec('php /srv/app/www/exportjobs/worker.php --job=' . $job['uuid'] . ' 2>&1', $o);
	return $svc->get($job['uuid']);
}
function fb_zip_list($job) { global $cfg; list(, $l) = ejt_sh('unzip -Z1 ' . escapeshellarg(rtrim($cfg['results_root'], '/') . '/' . $job['result_path'])); return array_filter(explode("\n", $l)); }
function fb_zip_read($job, $member) { global $cfg; return shell_exec('unzip -p ' . escapeshellarg(rtrim($cfg['results_root'], '/') . '/' . $job['result_path']) . ' ' . escapeshellarg($member)); }
$scope2 = array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER), array('id' => (string)$P2, 'owner' => $OWNER)));
$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope2, 'formats' => array('fieldbook', 'fieldbook_legacy'), 'layout' => 'merged', 'fieldbook' => array('page' => 'a4', 'map' => 'none')), 'fieldbook merged');
check('merged job done', $j['status'] === 'done', $j['status'] . ' ' . $j['error_text']);
$zl = fb_zip_list($j);
$book = null; $legacy = null;
foreach ($zl as $mmb) { if (strpos($mmb, 'strabospot_fieldbook_') === 0) $book = $mmb; if (strpos($mmb, 'StraboSpot_Field_Book_') === 0) $legacy = $mmb; }
check('merged zip: one book spanning both projects + the legacy PDF beside it', $book && $legacy, implode(',', $zl));
$mb = $book ? fb_zip_read($j, $book) : '';
check('merged book: A4, outline, project + dataset title pages (>= 8 pages)', strpos($mb, '/MediaBox [0 0 595.28 841.89]') !== false && strpos($mb, '/Outlines') !== false && pdf_pages($mb) >= 8, pdf_pages($mb));
$j2 = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope2, 'formats' => array('fieldbook'), 'layout' => 'split_dataset', 'fieldbook' => array('map' => 'none')), 'fieldbook split');
check('split_dataset job done', $j2['status'] === 'done', $j2['status'] . ' ' . $j2['error_text']);
$zl2 = fb_zip_list($j2);
$books = array_values(array_filter($zl2, function ($mmb) { return preg_match('#/datasets/[^/]+/[^/]+_fieldbook_\d{4}-\d{2}-\d{2}\.pdf$#', $mmb); }));
check('split_dataset zip: one book per dataset (3) named after the dataset', count($books) === 3 && preg_match('#Alpha_Dataset_\d+/Alpha_Dataset_fieldbook_#', implode(',', $books)), implode(',', $zl2));
$ab = fb_zip_read($j2, $books[0]);
check('split book is a real PDF with an outline', substr($ab, 0, 4) === '%PDF' && strpos($ab, '/Outlines') !== false);
check('worker residue: work dir empty', count(glob(rtrim($cfg['work_root'], '/') . '/*')) === 0);

cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
