<?php
/**
 * File: tests/exportjobs/smoke_test_sample_list.php
 * Description: M4 smoke suite for the Export Builder sample list format
 *              (docs/ExportBuilder_Design.md §7.3, §8.1, §12 M4).
 *
 *              SERVICE: SampleTabularService::exportRowsForIds (id set,
 *              owner isolation, junk ids, empty set), pgTextArray escaping
 *              round-trip through PostgreSQL, context columns in
 *              buildCsv/buildWorkbook, and re-import safety (the context
 *              columns are ignored by parseUpload, custom keys survive).
 *              PLUGIN: full worker runs -> samples.xlsx + samples.csv from
 *              the spine links of the exported spots only (unlinked sample
 *              absent), a Micro-linked sample proving cross-subsystem via
 *              linked_systems, a sample linked to two spots in two
 *              datasets, polygon filter dropping a sample, split_dataset
 *              lists, dataset-scoped selection, the no-samples warning,
 *              sample_list as the only format, README line, zero residue.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/exportjobs/smoke_test_sample_list.php
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('memory_limit', '2G');
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once 'exportjobs/lib/export_config.php';
require_once 'exportjobs/lib/ExportJobService.php';
require_once 'exportjobs/plugins/FieldExportPlugin.php';
require_once 'samplesdb/services/SampleTabularService.php';

$OWNER = 94561; $OTHER = 94562;
$P1 = 945611001;
$DS_A = 945612001; $DS_B = 945612002;
$S1 = 945613001; $S2 = 945613002; $S3 = 945613003; $S4 = 945613004; $S5 = 945613005;
$SA = '945615001'; $SB = '945615002'; $SC = '945615003'; $SD = '945615004'; $SE = '945615005';
$TMP = '/tmp/ej_samples_' . getmypid();
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
	global $db, $neodb, $OWNER, $OTHER, $TMP, $cfg;
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) DETACH DELETE s");
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) DETACH DELETE d, p");
	$neodb->query("MATCH (u:User {userpkey: $OWNER}) DETACH DELETE u");
	foreach (array($OWNER, $OTHER) as $u) {
		$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($u));   // links cascade
		$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_userpkey = $1", array($u));
		$db->prepare_query("DELETE FROM export_jobs WHERE userpkey = $1", array($u));
		$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($u));
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u));
	}
	rmrf($TMP); rmrf(rtrim($cfg['results_root'], '/') . "/$OWNER");
}

echo "Export Builder M4 sample list smoke suite\n";
cleanup();
mkdir($TMP, 0775, true);

// ------------------------------------------------------------------ fixtures
foreach (array($OWNER => 'smp', $OTHER => 'oth') as $u => $fn) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active) VALUES ($1, $2, 'fixture', $3, 'x', 'x', false)", array($u, $fn, "$fn-$u@test.strabospot.org"));
}
$neodb->query("CREATE (u:User {userpkey: $OWNER, email: 'smp-$OWNER@test.strabospot.org'})");
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P1, userpkey: $OWNER, desc_project_name: 'Sample Project'}) CREATE (u)-[:HAS_PROJECT]->(p)");
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Sample Project', $2, FALSE)", array($OWNER, (string)$P1));
foreach (array($DS_A => 'Alpha Dataset', $DS_B => 'Beta Dataset') as $did => $dn) {
	$neodb->query("MATCH (p:Project {id: $P1, userpkey: $OWNER}) CREATE (d:Dataset {id: $did, userpkey: $OWNER, name: '$dn'}) CREATE (p)-[:HAS_DATASET]->(d)");
}
function spot($did, $id, $name, $wkt) {
	global $neodb, $OWNER;
	$neodb->query("MATCH (d:Dataset {id: $did, userpkey: $OWNER}) CREATE (s:Spot {id: $id, userpkey: $OWNER, name: '$name', wkt: '$wkt', origwkt: '$wkt',
		modified_timestamp: 1722400000000, date: '2026-07-15T10:00:00Z', time: '2026-07-15T10:00:00Z'}) CREATE (d)-[:HAS_SPOT]->(s)");
}
spot($DS_A, $S1, 'Spot One', 'POINT (-118.25 34.05)');
spot($DS_A, $S2, 'Spot Two', 'POINT (-118.24 34.05)');
spot($DS_B, $S3, 'Spot Three', 'POINT (-118.26 34.04)');
spot($DS_A, $S4, 'Spot Outside', 'POINT (-118.10 34.05)');
spot($DS_A, $S5, 'Nosample Spot', 'POINT (-118.23 34.06)');
foreach (array($S1, $S2, $S3, $S4, $S5) as $sid) StraboSearchSync::touchSpot($db, $neodb, $sid, $OWNER);
check('fixtures indexed', (int)$db->get_var_prepared("SELECT count(*) FROM strabosearch.item_hit WHERE project_userpkey = $1 AND item_type='spot'", array($OWNER)) === 5);

function sample($id, $owner, $name, $custom = null, $type = 'igneous') {
	global $db;
	$db->prepare_query("INSERT INTO strabosamples.samples (id, userpkey, name, display_sample_type, custom_data, created_at, created_by, modified_at, modified_by)
		VALUES ($1, $2, $3, $4, $5::jsonb, now(), $2, now(), $2)", array($id, $owner, $name, $type, $custom));
}
function slink($sample, $owner, $subsystem, $ref, $refOwner, $ds = null) {
	global $db;
	$meta = $ds !== null ? json_encode(array('rich' => false, 'dataset_id' => (string)$ds)) : '{}';
	$db->prepare_query("INSERT INTO strabosamples.sample_subsystem_links (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata, created_at, modified_at)
		VALUES ($1, $2, $3, $4, $5, $6::jsonb, now(), now())", array($sample, $owner, $subsystem, (string)$ref, $refOwner, $meta));
}
sample($SA, $OWNER, 'Alpha Sample');
sample($SB, $OWNER, 'Bravo Sample', '{"lab_code": "LC-7"}');
sample($SC, $OWNER, 'Charlie Sample');
sample($SD, $OWNER, 'Delta Sample');
sample($SE, $OWNER, 'Echo Unlinked');
sample($SA, $OTHER, 'Other Owner Same Id');   // spine identity is (id, userpkey)
slink($SA, $OWNER, 'field', $S1, $OWNER, $DS_A);
slink($SA, $OWNER, 'micro', 'micro-ref-1', $OWNER);
slink($SB, $OWNER, 'field', $S2, $OWNER, $DS_A);
slink($SB, $OWNER, 'field', $S3, $OWNER, $DS_B);
slink($SC, $OWNER, 'field', $S3, $OWNER, $DS_B);
slink($SD, $OWNER, 'field', $S4, $OWNER, $DS_A);

// ------------------------------------------------------------------ 1. service
$tab = new SampleTabularService($db, $neodb); $tab->setUserpkey($OWNER);
$r = $tab->exportRowsForIds(array(), $OWNER);
check('exportRowsForIds: empty id set -> no rows', $r['rows'] === array() && $r['custom_keys'] === array());
$r = $tab->exportRowsForIds(array($SB, 'junk', $SA, '', $SB), $OWNER);
$names = array_map(function ($x) { return $x['sample_id']; }, $r['rows']);
check('exportRowsForIds: only the requested ids, name order, junk + dupes ignored', $names === array('Alpha Sample', 'Bravo Sample'), json_encode($names));
check('exportRowsForIds: custom keys + linked_systems for the set', $r['custom_keys'] === array('lab_code') && preg_match('/micro/', $r['rows'][0]['linked_systems']) && preg_match('/field×2/', $r['rows'][1]['linked_systems']), json_encode($r['rows']));
$r = $tab->exportRowsForIds(array($SA, $SB), $OTHER);
check('exportRowsForIds: owner isolation (same id, other owner -> only theirs)', count($r['rows']) === 1 && $r['rows'][0]['sample_id'] === 'Other Owner Same Id');
$all = $tab->exportRows();
check('exportRows (owner-wide) regression: all 5 samples', count($all['rows']) === 5 && $all['custom_keys'] === array('lab_code'));
check('pgTextArray literal', SampleTabularService::pgTextArray(array('a"b', 'c\\d', 'plain')) === '{"a\\"b","c\\\\d","plain"}', SampleTabularService::pgTextArray(array('a"b', 'c\\d', 'plain')));
$un = $db->get_results_prepared("SELECT unnest($1::text[]) AS v", array(SampleTabularService::pgTextArray(array('a"b', 'c\\d', 'x,y', '{z}'))));
$vals = array_map(function ($x) { return $x->v; }, $un);
check('pgTextArray round-trips through PostgreSQL', $vals === array('a"b', 'c\\d', 'x,y', '{z}'), json_encode($vals));

$ctxCols = FieldExportPlugin::$sampleContextCols;
$rows = $tab->exportRowsForIds(array($SB), $OWNER);
$rows['rows'][0]['_context'] = array('field_project' => 'Sample Project', 'field_dataset' => 'Alpha Dataset; Beta Dataset', 'field_spot_id' => "$S2; $S3", 'field_spot_name' => 'Spot Two; Spot Three');
$csv = $tab->buildCsv($rows['rows'], $rows['custom_keys'], false, $ctxCols);
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
check('buildCsv: context columns after modified_at, before custom keys', array_slice($lines[0], -6) === array('modified_at', 'field_project', 'field_dataset', 'field_spot_id', 'field_spot_name', 'lab_code'), json_encode($lines[0]));
check('buildCsv: context values written', in_array('Alpha Dataset; Beta Dataset', $lines[1], true) && in_array("$S2; $S3", $lines[1], true) && in_array('LC-7', $lines[1], true), json_encode($lines[1]));
file_put_contents("$TMP/reimport.csv", $csv);
$parsed = $tab->parseUpload("$TMP/reimport.csv", 'reimport.csv');
check('re-import safety: context columns ignored, custom key kept', $parsed['ok'] && $parsed['custom_headers'] === array('lab_code') && count(array_intersect($ctxCols, $parsed['ignored_headers'])) === 4, json_encode(array($parsed['custom_headers'], $parsed['ignored_headers'])));

check('validateOutput accepts sample_list alone + csv flag', FieldExportPlugin::validateOutput(array('formats' => array('sample_list'), 'sample_list_csv' => 1)) === array('formats' => array('sample_list'), 'extras' => array(), 'layout' => 'merged', 'sample_list_csv' => true, 'fieldbook' => array('map' => 'outdoors', 'photos' => 'sheets', 'nets' => 'on', 'page' => 'letter')));   // fieldbook defaults since the enhanced fieldbook (docs/Fieldbook_Design.md)
try { FieldExportPlugin::validateOutput(array('formats' => array('samples'))); check('validateOutput still rejects unknown formats', false); }
catch (ExportJobError $e) { check('validateOutput still rejects unknown formats', strpos($e->getMessage(), 'Unknown format') !== false); }

// ------------------------------------------------------------------ 2. plugin through the real worker
function run_job($svc, $recipe, $summary) {
	global $OWNER;
	$job = $svc->create($OWNER, $recipe, array('summary' => $summary, 'origin' => 'test'));
	exec('php /srv/app/www/exportjobs/worker.php --job=' . $job['uuid'] . ' 2>&1', $o);
	return $svc->get($job['uuid']);
}
function ejt_zip_list($job) { global $cfg; list(, $l) = ejt_sh('unzip -Z1 ' . escapeshellarg(rtrim($cfg['results_root'], '/') . '/' . $job['result_path'])); return array_filter(explode("\n", $l)); }
function ejt_zip_read($job, $member) { global $cfg; return shell_exec('unzip -p ' . escapeshellarg(rtrim($cfg['results_root'], '/') . '/' . $job['result_path']) . ' ' . escapeshellarg($member)); }
/** csv text -> [assoc rows keyed by header] */
function csv_rows($text) {
	$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
	$fh = fopen('php://temp', 'r+'); fwrite($fh, $text); rewind($fh);
	$hdr = fgetcsv($fh); $out = array();
	while (($l = fgetcsv($fh)) !== false) { if ($l === array(null)) continue; $out[] = array_combine($hdr, $l); }
	fclose($fh); return $out;
}
function names_of($rows) { $n = array(); foreach ($rows as $r) $n[] = $r['sample_id']; sort($n); return $n; }
$scope = array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)));

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope, 'formats' => array('sample_list', 'geojson'), 'layout' => 'merged', 'sample_list_csv' => true), 'merged sample list');
check('merged job done (5 spots)', $j['status'] === 'done' && $j['item_count'] === 5, $j['status'] . ' ' . $j['error_text']);
$zl = ejt_zip_list($j);
check('merged zip: samples.xlsx + samples.csv at the root next to the geojson', in_array('samples.xlsx', $zl, true) && in_array('samples.csv', $zl, true) && count(preg_grep('/\.json$/', $zl)) >= 2, implode(',', $zl));
$rows = csv_rows(ejt_zip_read($j, 'samples.csv'));
check('csv: the 4 linked samples, unlinked Echo absent', names_of($rows) === array('Alpha Sample', 'Bravo Sample', 'Charlie Sample', 'Delta Sample'), json_encode(names_of($rows)));
$byName = array(); foreach ($rows as $r) $byName[$r['sample_id']] = $r;
check('csv: Micro-linked sample shows both systems (cross-subsystem)', preg_match('/field/', $byName['Alpha Sample']['linked_systems']) && preg_match('/micro/', $byName['Alpha Sample']['linked_systems']), $byName['Alpha Sample']['linked_systems']);
check('csv: sample linked to two spots lists both spots + both datasets', $byName['Bravo Sample']['field_spot_id'] === "$S2; $S3" && $byName['Bravo Sample']['field_spot_name'] === 'Spot Two; Spot Three' && $byName['Bravo Sample']['field_dataset'] === 'Alpha Dataset; Beta Dataset' && $byName['Bravo Sample']['field_project'] === 'Sample Project', json_encode($byName['Bravo Sample']));
check('csv: custom column carried, blank on the others', $byName['Bravo Sample']['lab_code'] === 'LC-7' && $byName['Alpha Sample']['lab_code'] === '' && isset($byName['Charlie Sample']['lab_code']));
check('csv: single-spot sample context', $byName['Delta Sample']['field_spot_id'] === (string)$S4 && $byName['Delta Sample']['field_dataset'] === 'Alpha Dataset');
$readme = ejt_zip_read($j, 'README.txt');
check('README: sample list line with cross-subsystem count', strpos($readme, 'Sample list: 4 samples linked to the exported spots (1 also linked in StraboMicro or StraboExperimental') !== false, substr($readme, 0, 600));
// xlsx: readable, same rows, context columns locked
file_put_contents("$TMP/samples.xlsx", ejt_zip_read($j, 'samples.xlsx'));
require_once 'PHPExcel.php';
$wb = PHPExcel_IOFactory::load("$TMP/samples.xlsx");
$sheet = $wb->getSheetByName('Data');
$hdr = array(); $c = 0; while (($v = $sheet->getCellByColumnAndRow($c, 1)->getValue()) !== null && $v !== '') { $hdr[] = $v; $c++; }
$pIdx = array_search('field_project', $hdr, true); $nIdx = array_search('sample_id', $hdr, true);
$xn = array(); for ($r = 2; $r <= 5; $r++) $xn[] = $sheet->getCellByColumnAndRow($nIdx, $r)->getValue(); sort($xn);
check('xlsx: Data sheet has the 4 samples + context headers', $pIdx !== false && $xn === array('Alpha Sample', 'Bravo Sample', 'Charlie Sample', 'Delta Sample') && $sheet->getCellByColumnAndRow($nIdx, 6)->getValue() === null, json_encode(array($hdr, $xn)));
$pCol = PHPExcel_Cell::stringFromColumnIndex($pIdx);
check('xlsx: context column locked, custom column editable, sheet protected', $sheet->getStyle($pCol . '2')->getProtection()->getLocked() === PHPExcel_Style_Protection::PROTECTION_PROTECTED
	&& $sheet->getStyle(PHPExcel_Cell::stringFromColumnIndex(array_search('lab_code', $hdr, true)) . '2')->getProtection()->getLocked() === PHPExcel_Style_Protection::PROTECTION_UNPROTECTED
	&& $sheet->getProtection()->getSheet());
check('xlsx: Vocabulary + Instructions sheets present', $wb->getSheetByName('Vocabulary') !== null && $wb->getSheetByName('Instructions') !== null);
$wb->disconnectWorksheets(); unset($wb);

$poly = array('type' => 'Polygon', 'coordinates' => array(array(array(-118.30, 34.00), array(-118.20, 34.00), array(-118.20, 34.10), array(-118.30, 34.10), array(-118.30, 34.00))));
$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope, 'formats' => array('sample_list'), 'layout' => 'merged', 'sample_list_csv' => true,
	'criteria' => array(array('id' => 'U2', 'value' => $poly))), 'polygon sample list');
$rows = $j['status'] === 'done' ? csv_rows(ejt_zip_read($j, 'samples.csv')) : array();
check('polygon filter: Delta (outside) dropped, sample_list as the only format', $j['status'] === 'done' && names_of($rows) === array('Alpha Sample', 'Bravo Sample', 'Charlie Sample'), $j['status'] . ' ' . $j['error_text'] . ' ' . json_encode(names_of($rows)));

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope, 'formats' => array('sample_list'), 'layout' => 'split_dataset', 'sample_list_csv' => true), 'split dataset sample lists');
$zl = ejt_zip_list($j);
$aDir = 'projects/Sample_Project_' . $P1 . '/datasets/Alpha_Dataset_' . $DS_A . '/'; $bDir = 'projects/Sample_Project_' . $P1 . '/datasets/Beta_Dataset_' . $DS_B . '/';
check('split_dataset: a sample list per dataset folder', $j['status'] === 'done' && in_array($aDir . 'samples.csv', $zl, true) && in_array($bDir . 'samples.csv', $zl, true) && !in_array('samples.csv', $zl, true), $j['status'] . ' ' . implode(',', $zl));
$ra = csv_rows(ejt_zip_read($j, $aDir . 'samples.csv')); $rb = csv_rows(ejt_zip_read($j, $bDir . 'samples.csv'));
check('split_dataset: Alpha list = Alpha, Bravo, Delta; Beta list = Bravo, Charlie', names_of($ra) === array('Alpha Sample', 'Bravo Sample', 'Delta Sample') && names_of($rb) === array('Bravo Sample', 'Charlie Sample'), json_encode(array(names_of($ra), names_of($rb))));
$rbBravo = null; foreach ($rb as $r) if ($r['sample_id'] === 'Bravo Sample') $rbBravo = $r;
check('split_dataset: per-dataset context only names that dataset\'s spot', $rbBravo && $rbBravo['field_spot_id'] === (string)$S3 && $rbBravo['field_dataset'] === 'Beta Dataset', json_encode($rbBravo));
$readme = ejt_zip_read($j, 'README.txt');
check('split_dataset README lists per-group sample counts', strpos($readme, 'Sample list: 5 samples') !== false && strpos($readme, 'Beta Dataset: 2 samples') !== false, substr($readme, 0, 600));

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => array('projects' => array(array('id' => (string)$P1, 'owner' => $OWNER)),
	'datasets' => array(array('id' => (string)$DS_B, 'project_id' => (string)$P1, 'owner' => $OWNER))), 'formats' => array('sample_list'), 'layout' => 'merged', 'sample_list_csv' => true), 'dataset scoped');
$rows = $j['status'] === 'done' ? csv_rows(ejt_zip_read($j, 'samples.csv')) : array();
check('dataset-scoped selection: only Beta\'s samples', $j['status'] === 'done' && names_of($rows) === array('Bravo Sample', 'Charlie Sample'), json_encode(names_of($rows)));

$j = run_job($svc, array('v' => 1, 'plugin' => 'field', 'scope' => $scope, 'formats' => array('sample_list', 'geojson'), 'layout' => 'merged',
	'criteria' => array(array('id' => 'U1', 'value' => 'Nosample'))), 'no linked samples');
$zl = ejt_zip_list($j); $readme = ejt_zip_read($j, 'README.txt');
check('spots without samples: job done with a warning, no sample file, geojson still written', $j['status'] === 'done' && $j['item_count'] === 1 && !in_array('samples.xlsx', $zl, true) && count(preg_grep('/\.json$/', $zl)) >= 2
	&& strpos($readme, 'none of the exported spots has a linked sample') !== false && strpos($readme, 'Sample list: 0 samples') !== false, $j['status'] . ' ' . $j['error_text'] . ' ' . implode(',', $zl));
check('no xlsx without csv flag', !in_array('samples.csv', $zl, true));
check('worker left no workspaces', count(glob(rtrim($cfg['work_root'], '/') . '/*', GLOB_ONLYDIR)) === 0);

// ------------------------------------------------------------------ cleanup
cleanup();
check('zero residue', (int)$neodb->query("MATCH (u:User {userpkey: $OWNER}) RETURN count(u) AS c")[0]->value('c') === 0
	&& (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE sample_userpkey IN ($1, $2)", array($OWNER, $OTHER)) === 0
	&& (int)$db->get_var_prepared("SELECT count(*) FROM export_jobs WHERE userpkey = $1", array($OWNER)) === 0
	&& !is_dir($TMP));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
