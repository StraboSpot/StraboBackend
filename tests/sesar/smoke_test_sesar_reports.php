<?php
/**
 * File: tests/sesar/smoke_test_sesar_reports.php
 * Description: Phase 8 suite: SESAR batch file export (B1: SesarBatchTemplate
 *              reads / fills a SESAR-shaped template, only the Samples part
 *              changes; SesarMapper::batchRow; SesarBatchExport plan +
 *              fill), batch match-back (B2 + B3: SesarPull::batchMatches /
 *              batchLink, drafts unticked), the two D9 reports
 *              (SesarReports; R1: a refresh never writes the stored copy),
 *              the samples import accepting "StraboSpot <id>", then HTTP
 *              refusals for sesar_batch.php / sesar_reports.php / the new
 *              sesar_pull.php actions.
 *
 *              Unit part talks ONLY to FakeSesar; the HTTP part makes no
 *              SESAR call. Fixture users 94770-94772, samples "sesarrep-*".
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_reports.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarReports.php';
require_once 'includes/sesar/SesarBatchExport.php';
require_once 'samplesdb/services/SampleTabularService.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once __DIR__ . '/FakeSesar.php';

$U = array(94770, 94771, 94772);
$STATE = '/tmp/fake_sesar_reports.json';
$KEY = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$TMP = sys_get_temp_dir() . '/sesarrep_' . getmypid();
@mkdir($TMP);
$sessionFiles = array();

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function err($fn) {
	try { $fn(); return null; }
	catch (SesarError $e) { return $e; }
}
function cleanup() {
	global $db, $U, $sessionFiles;
	$in = implode(',', array_map('intval', $U));
	$rows = $db->get_results("SELECT id, userpkey FROM strabosamples.samples WHERE userpkey IN ($in)");
	foreach ((is_array($rows) ? $rows : array()) as $r) StraboSearchSync::removeSample($db, $r->id, (int)$r->userpkey);
	$db->query("DELETE FROM strabosamples.sesar_registrations WHERE sample_userpkey IN ($in)");
	$db->query("UPDATE strabosamples.samples SET parent_sample_id = NULL, parent_userpkey = NULL WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sesar_connections WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sesar_vocab_cache WHERE environment = 'sandbox'");   // fake terms must not linger
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
	foreach ($sessionFiles as $f) @unlink($f);
}
function mk($id, $owner, array $f = array()) {
	global $db;
	$db->prepare_query(
		"INSERT INTO strabosamples.samples (id, userpkey, name, igsn, latitude, longitude, description, display_sample_purpose,
		                                   parent_sample_id, parent_userpkey, created_by, modified_by)
		 VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $2, $2)",
		array($id, $owner, array_key_exists('name', $f) ? $f['name'] : $id, isset($f['igsn']) ? $f['igsn'] : null,
		      array_key_exists('lat', $f) ? $f['lat'] : 38.95, array_key_exists('lon', $f) ? $f['lon'] : -95.25,
		      isset($f['description']) ? $f['description'] : null, isset($f['purpose']) ? $f['purpose'] : null,
		      isset($f['parent']) ? $f['parent'] : null, isset($f['parent']) ? $owner : null)
	);
}
function spine($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($id, $owner));
}
function reg($id, $owner) {
	global $db;
	return $db->get_row_prepared("SELECT * FROM strabosamples.sesar_registrations WHERE sample_id = $1 AND sample_userpkey = $2 AND active", array($id, $owner));
}
function rowFor(array $rows, $key, $val) {
	foreach ($rows as $r) if ($r[$key] === $val) return $r;
	return null;
}

/**
 * A SESAR-shaped batch template (layout of SESAR template 8.0): Cover Page,
 * veryHidden Metadata + Lookups, Samples with list validations. $headers
 * go in row 1 ("Sample Name" through sharedStrings, the rest inline).
 */
function mkTemplate($path, array $headers, $code = 'IEFAK', array $opt = array()) {
	$esc = function ($s) { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
	$col = function ($i) { return SesarBatchTemplate::colName($i); };
	$ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
	$cell = function ($ref, $v) use ($esc) { return '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc($v) . '</t></is></c>'; };
	$sheets = array('Cover Page' => 'visible', 'Metadata' => 'veryHidden', 'Samples' => 'visible', 'Lookups' => 'veryHidden');
	if (!empty($opt['no_samples'])) unset($sheets['Samples']);
	if (!empty($opt['no_meta'])) unset($sheets['Metadata']);
	$wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook ' . $ns . '><sheets>';
	$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
	$parts = array();
	$i = 1;
	foreach ($sheets as $name => $state) {
		$i++;
		$wb .= '<sheet name="' . $name . '" sheetId="' . $i . '" state="' . $state . '" r:id="rId' . $i . '"/>';
		$rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		if ($name === 'Cover Page') {
			$x = '<row r="3">' . $cell('A3', 'SESAR Code') . $cell('B3', $code) . '</row><row r="4">' . $cell('A4', 'Object Type') . $cell('B4', 'Enter per sample in spreadsheet') . '</row>';
		} elseif ($name === 'Metadata') {
			$x = '<row r="1">' . $cell('A1', 'sesar_code') . $cell('B1', $code) . '</row><row r="2">' . $cell('A2', 'object_type') . $cell('B2', 'Enter per sample in spreadsheet') . '</row>'
				. '<row r="3">' . $cell('A3', 'template_version') . $cell('B3', '8.0') . '</row>';
		} elseif ($name === 'Lookups') {
			$A = array('Individual sample', 'Thin section', 'Core');
			$B = array('Granite', 'Limestone', 'Rock', 'Sediment');
			$x = '';
			for ($r = 1; $r <= 4; $r++) $x .= '<row r="' . $r . '">' . (isset($A[$r - 1]) ? $cell('A' . $r, $A[$r - 1]) : '') . $cell('B' . $r, $B[$r - 1]) . '</row>';
		} else {
			$x = '<row r="1">';
			foreach ($headers as $k => $h) {
				$x .= ($h === 'Sample Name') ? '<c r="' . $col($k + 1) . '1" t="s"><v>0</v></c>' : $cell($col($k + 1) . '1', $h);
			}
			$x .= '</row>';
			for ($r = 2; $r <= 6; $r++) $x .= '<row r="' . $r . '" spans="1:3"/>';
			if (!empty($opt['prefilled'])) $x = str_replace('<row r="2" spans="1:3"/>', '<row r="2">' . $cell('B2', 'typed by hand') . '</row>', $x);
		}
		$dv = '';
		if ($name === 'Samples') {
			$ot = array_search('Object Type', $headers); $gm = array_search('General Material Type', $headers); $dp = array_search('Sampling date precision', $headers);
			$dv = '<dataValidations>'
				. ($ot !== false ? '<dataValidation type="list" allowBlank="1" sqref="' . $col($ot + 1) . '2:' . $col($ot + 1) . '5001"><formula1>Lookups!$A$1:$A$3</formula1></dataValidation>' : '')
				. ($gm !== false ? '<dataValidation type="list" allowBlank="1" sqref="' . $col($gm + 1) . '2:' . $col($gm + 1) . '5001"><formula1>Lookups!$B$1:$B$4</formula1></dataValidation>' : '')
				. ($dp !== false ? '<dataValidation type="list" allowBlank="1" sqref="' . $col($dp + 1) . '2:' . $col($dp + 1) . '5001"><formula1>"year,month,day,time"</formula1></dataValidation>' : '')
				. '</dataValidations>';
		}
		$parts['xl/worksheets/sheet' . $i . '.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet ' . $ns . '>'
			. ($name === 'Samples' ? '<dimension ref="A1:' . $col(max(1, count($headers))) . '5001"/>' : '') . '<sheetData>' . $x . '</sheetData>' . $dv . '</worksheet>';
	}
	$wb .= '</sheets></workbook>';
	$rels .= '</Relationships>';
	$z = new ZipArchive();
	@unlink($path);
	$z->open($path, ZipArchive::CREATE);
	$z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
	$z->addFromString('xl/workbook.xml', $wb);
	$z->addFromString('xl/_rels/workbook.xml.rels', $rels);
	$z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst ' . $ns . ' count="1" uniqueCount="1"><si><t>Sample Name</t></si></sst>');
	$z->addFromString('xl/comments3.xml', '<comments>untouched</comments>');
	foreach ($parts as $p => $x) $z->addFromString($p, $x);
	$z->close();
	return $path;
}
/** Samples sheet cells of a filled file: row => header => value. */
function readFilled($bytesOrPath) {
	global $TMP;
	$p = is_file($bytesOrPath) ? $bytesOrPath : $TMP . '/read_' . mt_rand() . '.xlsx';
	if (!is_file($bytesOrPath)) file_put_contents($p, $bytesOrPath);
	$z = new ZipArchive(); $z->open($p);
	$x = $z->getFromName('xl/worksheets/sheet4.xml');
	$z->close();
	$d = new DOMDocument(); $d->loadXML($x);
	$hdr = array(); $out = array(); $types = array();
	foreach ($d->getElementsByTagName('c') as $c) {
		preg_match('/^([A-Z]+)(\d+)$/', $c->getAttribute('r'), $m);
		$v = $c->getAttribute('t') === 's' ? 'Sample Name' : $c->textContent;
		if ((int)$m[2] === 1) $hdr[$m[1]] = $v;
		else { $out[(int)$m[2]][$hdr[$m[1]]] = $v; $types[(int)$m[2]][$hdr[$m[1]]] = $c->getAttribute('t'); }
	}
	return array('rows' => $out, 'types' => $types, 'xml' => $x);
}

$ALL = array('Object Type', 'Sample Name', 'IGSN', 'Parent IGSN', 'Other Name(s)', 'General Material Type', 'Sample Description', 'Purpose',
	'Latitude (WGS84)', 'Longitude (WGS84)', 'Latitude End (WGS84)', 'Longitude End (WGS84)', 'Sampling start date', 'Sampling date precision', 'Country');

cleanup();
foreach ($U as $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Rep', 'Fixture', $2, 'x', 'x', TRUE, FALSE)",
		array($u, "sesarrep$u@test.strabospot.org"));
}

try {

// ===========================================================================
section('SesarBatchTemplate: reading');
$tpl = mkTemplate($TMP . '/t.xlsx', $ALL);
$t = SesarBatchTemplate::load($tpl);
check('code, version, per-sample object type, 15 headers', $t->sesarCode() === 'IEFAK' && $t->templateVersion() === '8.0'
	&& $t->objectTypeSetting() === 'Enter per sample in spreadsheet' && count($t->headers()) === 15, $t->headers());
check('shared-string header read ("Sample Name")', $t->hasHeader('Sample Name'));
check('dropdown lists from Lookups ranges + a literal list', $t->allowedValues('Object Type') === array('Individual sample', 'Thin section', 'Core')
	&& $t->allowedValues('General Material Type') === array('Granite', 'Limestone', 'Rock', 'Sediment')
	&& $t->allowedValues('Sampling date precision') === array('year', 'month', 'day', 'time') && $t->allowedValues('Sample Name') === null);
check('no data rows in a fresh template', $t->dataRowCount() === 0);
$t2 = SesarBatchTemplate::load(mkTemplate($TMP . '/nometa.xlsx', $ALL, 'IECVR', array('no_meta' => true)));
check('SESAR code falls back to the cover page', $t2->sesarCode() === 'IECVR');
file_put_contents($TMP . '/junk.xlsx', 'not a zip at all');
$e = err(function () use ($TMP) { SesarBatchTemplate::load($TMP . '/junk.xlsx'); });
check('not a zip -> 400 plain message', $e !== null && $e->status === 400 && strpos($e->getMessage(), '.xlsx') !== false);
$e = err(function () use ($TMP, $ALL) { SesarBatchTemplate::load(mkTemplate($TMP . '/nos.xlsx', $ALL, 'IEFAK', array('no_samples' => true))); });
check('no Samples sheet -> 400', $e !== null && $e->status === 400 && strpos($e->getMessage(), 'Samples') !== false);
$e = err(function () use ($TMP) { SesarBatchTemplate::load(mkTemplate($TMP . '/non.xlsx', array('Object Type', 'Name'))); });
check('no "Sample Name" column -> 400', $e !== null && $e->status === 400 && strpos($e->getMessage(), 'Sample Name') !== false);
$e = err(function () use ($TMP, $ALL) { SesarBatchTemplate::load(mkTemplate($TMP . '/nocode.xlsx', $ALL, '')); });
check('no SESAR code -> 400', $e !== null && $e->status === 400 && strpos($e->getMessage(), 'SESAR code') !== false);
$e = err(function () use ($TMP) { SesarBatchTemplate::load($TMP . '/missing.xlsx'); });
check('missing / empty file -> 400', $e !== null && $e->status === 400);

// A small file that unpacks to something huge, and a part that defines entities.
$swap = function ($name, $part, $xml) use ($TMP, $ALL) {
	$p = mkTemplate($TMP . '/' . $name, $ALL);
	$z = new ZipArchive(); $z->open($p);
	$z->addFromString($part, $xml);
	$z->close();
	return $p;
};
$big = $swap('bomb.xlsx', 'xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Sample Name</t></si>'
	. str_repeat(' ', SesarBatchTemplate::MAX_PART_BYTES) . '</sst>');
$e = err(function () use ($big) { SesarBatchTemplate::load($big); });
check('a small file with a part that unpacks past the limit -> 400 "too large"', $e !== null && $e->status === 400 && strpos($e->getMessage(), 'too large') !== false
	&& filesize($big) < 1048576, array(filesize($big), $e ? $e->getMessage() : null));
$ent = $swap('entities.xlsx', 'xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY a "Sample Name">]>'
	. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&a;</t></si></sst>');
$e = err(function () use ($ent) { SesarBatchTemplate::load($ent); });
check('a part that declares a DOCTYPE -> 400 "damaged"', $e !== null && $e->status === 400 && strpos($e->getMessage(), 'damaged') !== false, $e ? $e->getMessage() : null);

// ===========================================================================
section('SesarBatchTemplate: filling');
$bytes = $t->fill(array(
	array('Object Type' => 'Core', 'Sample Name' => 'A <&> "b"', 'Latitude (WGS84)' => 38.5, 'Longitude (WGS84)' => -95.125, 'Not a column' => 'x', 'Purpose' => ''),
	array('Sample Name' => 'second', 'Sampling start date' => '2025-07-12T04:45:10Z', 'Sampling date precision' => 'time'),
));
$f = readFilled($bytes);
check('values land under their headers from row 2', $f['rows'][2]['Sample Name'] === 'A <&> "b"' && $f['rows'][2]['Object Type'] === 'Core'
	&& $f['rows'][3]['Sample Name'] === 'second' && $f['rows'][3]['Sampling date precision'] === 'time', $f['rows']);
check('numbers are numeric cells, text inline strings', $f['types'][2]['Latitude (WGS84)'] === '' && $f['rows'][2]['Longitude (WGS84)'] === '-95.125'
	&& $f['types'][2]['Sample Name'] === 'inlineStr');
check('unknown headers and empty values not written', !isset($f['rows'][2]['Not a column']) && !isset($f['rows'][2]['Purpose']));
$za = new ZipArchive(); $za->open($tpl); $zb = new ZipArchive(); file_put_contents($TMP . '/filled.xlsx', $bytes); $zb->open($TMP . '/filled.xlsx');
$same = true; $names = array();
for ($i = 0; $i < $za->numFiles; $i++) {
	$n = $za->getNameIndex($i); $names[] = $n;
	if ($n !== 'xl/worksheets/sheet4.xml' && $za->getFromName($n) !== $zb->getFromName($n)) $same = false;
}
check('every other part byte-for-byte unchanged (cover, metadata, lookups, comments)', $same && $zb->numFiles === $za->numFiles);
check('dropdown validations kept on the Samples sheet', strpos($zb->getFromName('xl/worksheets/sheet4.xml'), 'Lookups!$A$1:$A$3') !== false);
$za->close(); $zb->close();
$t3 = SesarBatchTemplate::load($TMP . '/filled.xlsx');
check('a filled file reads back with 2 data rows', $t3->dataRowCount() === 2);
$e = err(function () use ($t3) { $t3->fill(array(array('Sample Name' => 'x'))); });
check('template that already has samples -> refused (use a fresh one)', $e !== null && $e->status === 400 && strpos($e->getMessage(), 'fresh template') !== false);
$tp = SesarBatchTemplate::load(mkTemplate($TMP . '/pre.xlsx', $ALL, 'IEFAK', array('prefilled' => true)));
check('hand-typed rows count as data', $tp->dataRowCount() === 1);
$e = err(function () use ($t) { $t->fill(array_fill(0, 5000, array('Sample Name' => 'x'))); });
check('more than 4999 samples -> refused', $e !== null && strpos($e->getMessage(), '4999') !== false);
$many = array(); for ($i = 0; $i < 8; $i++) $many[] = array('Sample Name' => 'n' . $i);
$f = readFilled($t->fill($many));
check('rows beyond the template placeholders are created in order', isset($f['rows'][9]) && $f['rows'][9]['Sample Name'] === 'n7'
	&& strpos($f['xml'], '<row r="6"') < strpos($f['xml'], '<row r="7"'));

// ===========================================================================
section('SesarMapper: batch row + Other Name(s)');
$row = SesarMapper::batchRow(array('id' => '1775', 'name' => ' Rock 1 ', 'latitude' => 38.123456789, 'longitude' => -95.5,
	'description' => 'Desc', 'display_sample_purpose' => 'Geochronology', 'parent_igsn' => '10.58052/IEFAK0001',
	'field_data' => array('collection_date' => '2025-07-12T04:45:10.722Z')), array('object_type' => 'Core', 'general_material_type' => 'Granite'));
check('mint values under SESAR headers', $row['Sample Name'] === 'Rock 1' && $row['Object Type'] === 'Core' && $row['General Material Type'] === 'Granite'
	&& $row['Sample Description'] === 'Desc' && $row['Purpose'] === 'Geochronology' && $row['Parent IGSN'] === '10.58052/IEFAK0001'
	&& $row['Sampling start date'] === '2025-07-12T04:45:10Z' && $row['Sampling date precision'] === 'time', $row);
check('coordinates as floats (6 places), our id as "StraboSpot <id>"', $row['Latitude (WGS84)'] === 38.123457 && $row['Longitude (WGS84)'] === -95.5
	&& $row['Other Name(s)'] === 'StraboSpot 1775', $row);
check('no external_sample_id header (the template has none)', !isset($row['external_sample_id']) && !in_array('external_sample_id', array_keys($row), true));
check('idFromOtherNames: list, text list with semicolons, any case', SesarMapper::idFromOtherNames(array('Alias', 'StraboSpot abc-1')) === 'abc-1'
	&& SesarMapper::idFromOtherNames('Alias; strabospot  99') === '99' && SesarMapper::idFromOtherNames('StraboSpotter 5') === null
	&& SesarMapper::idFromOtherNames(array()) === null && SesarMapper::idFromOtherNames(array(array('name' => 'StraboSpot x9'))) === 'x9');

// ===========================================================================
SesarAccess::setEnvironmentForTests('sandbox');
$fake = new FakeSesar($STATE, true);
$client = new SesarClient('sandbox', $fake);
$conn = new SesarConnection($db, $client, $KEY);
$views = new SesarSampleView($db, null, function () { return null; });
$vocab = new SesarVocab($db, $client);
$mint = new SesarMint($db, $client, $conn, $vocab, $views, null);
$pull = new SesarPull($db, $client, $conn, $views, null);
$push = new SesarPush($db, $client, $conn, $views, $mint, $pull);
$export = new SesarBatchExport($db, $views, $mint, $vocab, $conn, 'sandbox');
$reports = new SesarReports($db, $client, $conn, $views, $pull, $push);

$A = $U[0]; $B = $U[1];
$ORCID_A = '0000-0001-0000-0060';
$fake->addOrcidUser('idtok-A', $ORCID_A, true, array('IEFAK'));
$conn->connectWithOrcid($A, 'idtok-A', $ORCID_A);

section('SesarBatchExport: plan');
mk('sesarrep-parent', $A, array('name' => 'Parent', 'description' => 'Top', 'purpose' => 'Geochronology'));
mk('sesarrep-child', $A, array('name' => 'Child', 'parent' => 'sesarrep-parent'));
mk('sesarrep-reg', $A, array('name' => 'Registered one', 'igsn' => '10.58052/IEFAK0900'));
mk('sesarrep-kid-of-reg', $A, array('name' => 'Kid of registered', 'parent' => 'sesarrep-reg'));
mk('sesarrep-typed', $A, array('name' => 'Typed IGSN', 'igsn' => 'IEOTH0001'));
mk('sesarrep-noloc', $A, array('name' => 'No location', 'lat' => null, 'lon' => null));
mk('sesarrep-junk', $A, array('name' => 'Junk IGSN', 'igsn' => 'Carr_057_UM_#19'));
mk('sesarrep-b', $B, array('name' => 'Not mine'));
$db->prepare_query("INSERT INTO strabosamples.sesar_registrations (sample_id, sample_userpkey, environment, igsn, sesar_code, origin, state, created_by)
	VALUES ('sesarrep-reg', $1, 'sandbox', '10.58052/IEFAK0900', 'IEFAK', 'minted', 'active', $1)", array($A));

$ids = array('sesarrep-child', 'sesarrep-parent', 'sesarrep-reg', 'sesarrep-kid-of-reg', 'sesarrep-typed', 'sesarrep-noloc', 'sesarrep-junk', 'sesarrep-b');
$calls0 = count($fake->calls('samples/'));
$p = $export->plan($A, $ids, $t);
$by = function ($id) use ($p) { return rowFor($p['samples'], 'id', $id); };
check('4 written, 4 left out; samples listed in selection order', $p['included'] === 4 && $p['skipped'] === 4
	&& array_column($p['samples'], 'id') === $ids, array_column($p['samples'], 'id'));
check('already registered -> left out, names its IGSN', $by('sesarrep-reg')['included'] === false && strpos($by('sesarrep-reg')['reason'], '10.58052/IEFAK0900') !== false);
check('IGSN typed in its field -> left out', $by('sesarrep-typed')['included'] === false && strpos($by('sesarrep-typed')['reason'], '10.58052/IEOTH0001') !== false);
check('no location -> left out with the mint reason', $by('sesarrep-noloc')['included'] === false && strpos($by('sesarrep-noloc')['reason'], 'latitude') !== false);
check("another user's sample -> 'Not one of your samples'", $by('sesarrep-b')['included'] === false && $by('sesarrep-b')['reason'] === 'Not one of your samples.');
check('junk IGSN text -> written, with a note', $by('sesarrep-junk')['included'] === true && strpos($by('sesarrep-junk')['notes'][0], 'Carr_057_UM_#19') !== false);
$names = array_column($p['rows'], 'Sample Name');
check('parent written before its child (selected child first)', array_search('Parent', $names) < array_search('Child', $names), $names);
$child = rowFor($p['rows'], 'Sample Name', 'Child');
$kid = rowFor($p['rows'], 'Sample Name', 'Kid of registered');
check('parent without an IGSN -> Parent IGSN blank + note', !isset($child['Parent IGSN']) && strpos($by('sesarrep-child')['notes'][0], '"Parent"') !== false);
check("registered parent -> its IGSN in Parent IGSN", $kid['Parent IGSN'] === '10.58052/IEFAK0900', $kid);
check('object type from the template list, Other Name(s) carries the id', $child['Object Type'] === 'Individual sample'
	&& $child['Other Name(s)'] === 'StraboSpot sesarrep-child');
$par = rowFor($p['rows'], 'Sample Name', 'Parent');
check('description + purpose carried', $par['Sample Description'] === 'Top' && $par['Purpose'] === 'Geochronology');
check('plan makes no SESAR sample calls', count($fake->calls('samples/')) === $calls0);
check('no unfilled columns with an all-fields template', $p['unfilled_columns'] === array());
$tSmall = SesarBatchTemplate::load(mkTemplate($TMP . '/small.xlsx', array('Object Type', 'Sample Name', 'Other Name(s)', 'Latitude (WGS84)', 'Longitude (WGS84)')));
$p2 = $export->plan($A, array('sesarrep-parent'), $tSmall);
check('template without Purpose / Description columns -> reported', in_array('Purpose', $p2['unfilled_columns'], true)
	&& in_array('Sample Description', $p2['unfilled_columns'], true), $p2['unfilled_columns']);
$conn->refreshCodes($A);
$tOther = SesarBatchTemplate::load(mkTemplate($TMP . '/other.xlsx', $ALL, 'IEXYZ'));
$p3 = $export->plan($A, array('sesarrep-parent'), $tOther);
check("template code not among the connected account's codes -> warning", $p3['code_warning'] !== null && strpos($p3['code_warning'], 'IEXYZ') !== false, $p3['code_warning']);
check('matching code -> no warning', $p['code_warning'] === null);
$e = err(function () use ($export, $A, $t) { $export->plan($A, array(), $t); });
check('nothing selected -> 400', $e !== null && $e->status === 400);
$e = err(function () use ($export, $A, $t) { $export->fill($A, array('sesarrep-reg'), $t); });
check('nothing writable -> fill refused', $e !== null && $e->status === 400);
$res = $export->fill($A, $ids, $t);
$f = readFilled($res['bytes']);
check('fill writes the planned rows in order', count($f['rows']) === 4 && $f['rows'][2]['Sample Name'] === $p['rows'][0]['Sample Name']
	&& $f['rows'][2]['Other Name(s)'] === 'StraboSpot ' . $p['samples'][array_search($p['rows'][0]['Sample Name'], array_column($p['samples'], 'name'))]['id'], $f['rows']);
check('fill never touches the samples', spine('sesarrep-parent', $A)->igsn === null && reg('sesarrep-parent', $A) === null);

// ===========================================================================
section('batchMatches (B2 + B3)');
$base = array('_owner' => $ORCID_A, 'sesar_code' => 'IEFAK', 'latitude' => '38.95000000', 'longitude' => '-95.25000000',
	'object_type' => 'General sample types > Individual sample', 'last_update_date' => '2026-09-27T21:31:35Z', 'is_draft' => false, 'is_pending_review' => false);
mk('sesarrep-m-reg', $A, array('name' => 'Matched registered'));
mk('sesarrep-m-draft', $A, array('name' => 'Matched draft'));
mk('sesarrep-m-pend', $A, array('name' => 'Matched pending'));
mk('sesarrep-m-other', $A, array('name' => 'Holds another', 'igsn' => '10.58052/IEFAK0901'));
mk('sesarrep-m-junk', $A, array('name' => 'Matched junk', 'igsn' => 'n/a'));
mk('sesarrep-m-held', $A, array('name' => 'Batch twin'));
mk('sesarrep-holder', $A, array('name' => 'Holder', 'igsn' => '10.58052/IEFAK0B06'));
$fake->seedSample('10.58052/IEFAK0B01', $base + array('name' => 'Matched registered', 'other_names' => array('StraboSpot sesarrep-m-reg')));
$fake->seedSample('10.58052/IEFAK0B02', array('is_draft' => true, 'metadata_store_status' => 'draft') + $base + array('name' => 'Matched draft', 'other_names' => array('StraboSpot sesarrep-m-draft')));
$fake->seedSample('10.58052/IEFAK0B03', array('is_pending_review' => true) + $base + array('name' => 'At SESAR: pending', 'other_names' => array('Alias', 'StraboSpot sesarrep-m-pend')));
$fake->seedSample('10.58052/IEFAK0B04', $base + array('name' => 'x', 'other_names' => array('StraboSpot sesarrep-m-other')));
$fake->seedSample('10.58052/IEFAK0B05', $base + array('name' => 'Matched junk', 'other_names' => array('StraboSpot sesarrep-m-junk')));
$fake->seedSample('10.58052/IEFAK0B06', $base + array('name' => 'Batch twin', 'other_names' => array('StraboSpot sesarrep-m-held')));
$fake->seedSample('10.58052/IEFAK0B07', $base + array('name' => 'Not ours', 'other_names' => array('StraboSpot sesarrep-b')));
$fake->seedSample('10.58052/IEFAK0B08', $base + array('name' => 'No mark', 'other_names' => array()));
$fake->seedSample('10.58052/IEZZZ0B09', array('_owner' => 'someone-else') + $base + array('name' => 'Foreign', 'other_names' => array('StraboSpot sesarrep-m-reg')));

$m = $pull->batchMatches($A);
$mr = function ($igsn) use ($m) { return rowFor($m['rows'], 'igsn', $igsn); };
check('matches only own-account records naming own samples', count($m['rows']) === 6 && $mr('10.58052/IEFAK0B07') === null
	&& $mr('10.58052/IEFAK0B08') === null && $mr('10.58052/IEZZZ0B09') === null, array_column($m['rows'], 'igsn'));
check('registered -> ticked, linkable', $mr('10.58052/IEFAK0B01')['checked'] === true && $mr('10.58052/IEFAK0B01')['state'] === 'registered');
check('draft -> listed UNticked (B3), still linkable', $mr('10.58052/IEFAK0B02')['state'] === 'draft' && $mr('10.58052/IEFAK0B02')['checked'] === false
	&& $mr('10.58052/IEFAK0B02')['linkable'] === true);
check('pending review -> unticked, SESAR name shown, found among other names', $mr('10.58052/IEFAK0B03')['state'] === 'pending'
	&& $mr('10.58052/IEFAK0B03')['checked'] === false && $mr('10.58052/IEFAK0B03')['sesar_name'] === 'At SESAR: pending');
check('sample already holding another IGSN -> not linkable, reason names it', $mr('10.58052/IEFAK0B04')['linkable'] === false
	&& strpos($mr('10.58052/IEFAK0B04')['reason'], '10.58052/IEFAK0901') !== false);
check('junk IGSN field -> unticked with a replace note', $mr('10.58052/IEFAK0B05')['checked'] === false && $mr('10.58052/IEFAK0B05')['linkable'] === true
	&& strpos($mr('10.58052/IEFAK0B05')['note'], '"n/a"') !== false);
check('IGSN already in another of your samples -> not linkable', $mr('10.58052/IEFAK0B06')['linkable'] === false
	&& strpos($mr('10.58052/IEFAK0B06')['reason'], '"Holder"') !== false, $mr('10.58052/IEFAK0B06'));
check('batchMatches writes nothing', reg('sesarrep-m-reg', $A) === null && spine('sesarrep-m-reg', $A)->igsn === null);

section('batchLink');
$r = $pull->batchLink($A, 'sesarrep-m-reg', 'IEFAK0B01');
$g = reg('sesarrep-m-reg', $A);
check('links: IGSN written, linked + managed row, snapshot, nothing else changed', $r['ok'] === true && $r['applied'] === array()
	&& spine('sesarrep-m-reg', $A)->igsn === '10.58052/IEFAK0B01' && $g !== null && $g->origin === 'linked' && $g->access === 'managed'
	&& $g->snapshot !== null && spine('sesarrep-m-reg', $A)->name === 'Matched registered', array($r, $g));
$log = $db->get_row_prepared("SELECT changes::text AS c FROM strabosamples.sample_changelog WHERE sample_id = 'sesarrep-m-reg' AND sample_userpkey = $1 ORDER BY pkey DESC LIMIT 1", array($A));
check('changelog shows only the IGSN', $log !== null && strpos($log->c, 'IEFAK0B01') !== false && strpos($log->c, '"name"') === false, $log);
$m = $pull->batchMatches($A);
check('a linked match drops out of the list', rowFor($m['rows'], 'igsn', '10.58052/IEFAK0B01') === null && count($m['rows']) === 5);
$r = $pull->batchLink($A, 'sesarrep-m-draft', '10.58052/IEFAK0B02');
check('a draft links when ticked (status kept as SESAR says)', $r['ok'] === true && reg('sesarrep-m-draft', $A)->sesar_status === 'draft');
$r = $pull->batchLink($A, 'sesarrep-m-junk', '10.58052/IEFAK0B05');
check('junk field replaced by the IGSN', $r['ok'] === true && spine('sesarrep-m-junk', $A)->igsn === '10.58052/IEFAK0B05');
$e = err(function () use ($pull, $A) { $pull->batchLink($A, 'sesarrep-m-pend', '10.58052/IEFAK0B01'); });
check('IGSN whose Other Name(s) name another sample -> 409, nothing written', $e !== null && $e->status === 409
	&& spine('sesarrep-m-pend', $A)->igsn === null && reg('sesarrep-m-pend', $A) === null);
$e = err(function () use ($pull, $A) { $pull->batchLink($A, 'sesarrep-m-reg', '10.58052/IEZZZ0B09'); });
check("someone else's SESAR record -> 404", $e !== null && $e->status === 404);
$e = err(function () use ($pull, $A) { $pull->batchLink($A, 'sesarrep-m-other', '10.58052/IEFAK0B04'); });
check('sample holding another IGSN -> 409, field kept', $e !== null && $e->status === 409 && spine('sesarrep-m-other', $A)->igsn === '10.58052/IEFAK0901');
$e = err(function () use ($pull, $A) { $pull->batchLink($A, 'sesarrep-m-pend', 'not an igsn'); });
check('not an IGSN -> 400', $e !== null && $e->status === 400);
$e = err(function () use ($pull, $B) { $pull->batchLink($B, 'sesarrep-m-pend', '10.58052/IEFAK0B03'); });
check("another user's sample -> 404", $e !== null && $e->status === 404);

// ===========================================================================
section('Report 1: My IGSNs');
$rep = $reports->igsnReport($A, null, false);
$rr = function ($id) use (&$rep) { return rowFor($rep['rows'], 'StraboSpot id', $id); };
check('only samples with an IGSN or a link', $rr('sesarrep-parent') === null && $rr('sesarrep-m-reg') !== null && $rr('sesarrep-typed') !== null
	&& $rr('sesarrep-junk') !== null, array_column($rep['rows'], 'StraboSpot id'));
$x = $rr('sesarrep-m-reg');
check('links + stored-copy values', $x['DOI link'] === 'https://doi.org/10.58052/IEFAK0B01' && strpos($x['SESAR page'], 'sample/igsn/10.58052/IEFAK0B01') !== false
	&& $x['StraboSpot page'] === 'https://strabospot.org/samples/' . $A . '/sesarrep-m-reg' && $x['SESAR code'] === 'IEFAK'
	&& $x['Object type'] === 'Individual sample' && strpos($x['SESAR values from'], 'Stored copy of') === 0, $x);
check('managed / status texts', $x['Managed by StraboSpot'] === 'Yes (linked)' && $x['SESAR status'] === 'Registered'
	&& $rr('sesarrep-m-draft')['SESAR status'] === 'Draft' && $rr('sesarrep-reg')['Managed by StraboSpot'] === 'Yes (registered here)'
	&& $rr('sesarrep-typed')['Managed by StraboSpot'] === 'No' && $rr('sesarrep-junk')['SESAR status'] === 'Not a valid IGSN');
check('sandbox marked, parent name, coordinates numeric', $x['SESAR site'] === 'Test site (sandbox)' && $rr('sesarrep-kid-of-reg') === null
	&& is_float($x['Latitude']));
$db->prepare_query("UPDATE strabosamples.samples SET description = 'Edited here' WHERE id = 'sesarrep-m-reg' AND userpkey = $1", array($A));
$rep = $reports->igsnReport($A, array('sesarrep-m-reg', 'sesarrep-typed', 'sesarrep-parent'), false);
check('ids filter (samples without an IGSN dropped)', count($rep['rows']) === 2);
check('changed here since sent (sample vs stored copy)', $rr('sesarrep-m-reg')['Changed here since sent'] === 'Description', $rr('sesarrep-m-reg'));
$e = err(function () use ($reports, $A) { $reports->igsnReport($A, array(), false); });
check('empty id list -> 400', $e !== null && $e->status === 400);

$snapBefore = reg('sesarrep-m-reg', $A)->snapshot;
$snapAtBefore = reg('sesarrep-m-reg', $A)->snapshot_at;
$fake->editAtSesar('10.58052/IEFAK0B01', array('name' => 'Renamed at SESAR', 'purpose' => 'Changed at SESAR'));
$fake->markDeactivated('10.58052/IEFAK0B02');
$rep = $reports->igsnReport($A, null, true);
$x = $rr('sesarrep-m-reg');
check('refresh: live values + "Changed at SESAR since last read"', $x['SESAR values from'] === 'SESAR now'
	&& strpos($x['Changed at SESAR since last read'], 'Name') !== false && strpos($x['Changed at SESAR since last read'], 'Purpose') !== false, $x);
check('R1: the refresh did NOT write the stored copy', reg('sesarrep-m-reg', $A)->snapshot === $snapBefore && reg('sesarrep-m-reg', $A)->snapshot_at === $snapAtBefore);
check('refresh: deactivated IGSN shows as Deactivated, not recorded', $rr('sesarrep-m-draft')['SESAR status'] === 'Deactivated at SESAR'
	&& reg('sesarrep-m-draft', $A) !== null && reg('sesarrep-m-draft', $A)->state === 'active', $rr('sesarrep-m-draft'));
check('refresh: an IGSN SESAR does not know -> Not found', $rr('sesarrep-typed')['SESAR status'] === 'Not found at SESAR', $rr('sesarrep-typed'));
check('changedFields ignores fields the live row lacks', SesarReports::changedFields(array('name' => 'a'), array()) === array());

// ===========================================================================
section('Report 2: My SESAR account');
$acct = $reports->accountReport($A);
$ar = function ($igsn) use ($acct) { return rowFor($acct['rows'], 'IGSN', $igsn); };
check('whole own account, drafts included, foreign records excluded', $ar('10.58052/IEFAK0B03') !== null && $ar('10.58052/IEZZZ0B09') === null
	&& $ar('10.58052/IEFAK0B02') === null /* deactivated rows leave the list */, array_column($acct['rows'], 'IGSN'));
check('In StraboSamples yes (linked) / no', $ar('10.58052/IEFAK0B01')['In StraboSamples'] === 'Yes' && $ar('10.58052/IEFAK0B01')['StraboSamples sample'] === 'Matched registered'
	&& $ar('10.58052/IEFAK0B08')['In StraboSamples'] === 'No' && $ar('10.58052/IEFAK0B06')['In StraboSamples'] === 'Yes');
check('status + other names', $ar('10.58052/IEFAK0B03')['SESAR status'] === 'Waiting for curator review'
	&& $ar('10.58052/IEFAK0B03')['Other names'] === 'Alias; StraboSpot sesarrep-m-pend');
$e = err(function () use ($reports, $B) { $reports->accountReport($B); });
check('account report without a connection -> error, not a crash', $e !== null);

section('Writers');
$csv = SesarReports::csv(array('A', 'B'), array(array('A' => 'x, y', 'B' => 'é')));
check('CSV: BOM, quoted commas, UTF-8', strpos($csv, "\xEF\xBB\xBF") === 0 && strpos($csv, '"x, y"') !== false && strpos($csv, 'é') !== false);
$xb = SesarReports::xlsx('My IGSNs', array('IGSN', 'DOI link', 'Latitude'), array(array('IGSN' => 'I1', 'DOI link' => 'https://doi.org/I1', 'Latitude' => 1.5)), array('A note'));
file_put_contents($TMP . '/r.xlsx', $xb);
$z = new ZipArchive(); $z->open($TMP . '/r.xlsx');
$wbx = $z->getFromName('xl/workbook.xml'); $s1 = $z->getFromName('xl/worksheets/sheet1.xml');
check('XLSX: sheet named, Notes sheet, hyperlink, autofilter', strpos($wbx, 'My IGSNs') !== false && strpos($wbx, 'Notes') !== false
	&& strpos($s1, 'hyperlink') !== false && strpos($s1, 'autoFilter') !== false);
$z->close();

// ===========================================================================
section('Samples import: "StraboSpot <id>" in the id column');
file_put_contents($TMP . '/imp.csv', "strabo_internal_id,igsn\nStraboSpot sesarrep-m-pend,10.58052/IEFAK0B03\nsesarrep-typed,\n");
$svc = new SampleTabularService($db, null);
$svc->setUserpkey($A);
$parsed = $svc->parseUpload($TMP . '/imp.csv', 'imp.csv');
check('prefix stripped, plain ids unchanged', $parsed['rows'][0]['id'] === 'sesarrep-m-pend' && $parsed['rows'][1]['id'] === 'sesarrep-typed');
$plan = $svc->plan($parsed);
$pr = rowFor($plan['rows'], 'id', 'sesarrep-m-pend');
check('plan: only the IGSN changes', $pr['action'] === 'update' && array_keys($pr['input']) === array('igsn'), $pr);

// ===========================================================================
section('HTTP refusals (forged sessions, no SESAR calls)');
function forgeSession($pkey) {
	global $sessionFiles;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = '/var/lib/php/sessions/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600); @chown($path, 'www-data'); @chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}
function http($method, $path, $sid, $fields = null, $json = null) {
	$ch = curl_init('http://localhost' . $path);
	$h = array();
	if ($sid !== null) $h[] = 'Cookie: PHPSESSID=' . $sid;
	$o = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30);
	if ($fields !== null) $o[CURLOPT_POSTFIELDS] = $fields;
	if ($json !== null) { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = json_encode($json); }
	$o[CURLOPT_HTTPHEADER] = $h;
	curl_setopt_array($ch, $o);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('status' => $code, 'body' => $body, 'json' => json_decode($body, true));
}
$pilot = forgeSession(3);
$other = forgeSession($A);
$file = new CURLFile($tpl, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 't.xlsx');
$r = http('POST', '/sesar_batch.php', null, array('action' => 'check', 'ids' => '[]', 'file' => $file));
check('batch: no session -> 401', $r['status'] === 401 && $r['json']['error'] === 'not_authenticated');
$r = http('GET', '/sesar_batch.php', $pilot);
check('batch: GET -> 405', $r['status'] === 405);
$r = http('POST', '/sesar_batch.php', $pilot, array('action' => 'explode', 'ids' => '[]', 'file' => $file));
check('batch: unknown action -> 400', $r['status'] === 400 && $r['json']['error'] === 'unknown_action');
$r = http('POST', '/sesar_batch.php', $pilot, array('action' => 'check', 'ids' => '["x"]'));
check('batch: no file -> 400 plain message', $r['status'] === 400 && strpos($r['json']['message'], 'Batch Template Creator') !== false);
$r = http('POST', '/sesar_batch.php', $other, array('action' => 'check', 'ids' => '["sesarrep-parent"]', 'file' => $file));
check('batch: non-pilot -> 403 (server-side gate)', $r['status'] === 403 && $r['json']['error'] === 'not_allowed');
$r = http('POST', '/sesar_batch.php', $pilot, array('action' => 'check', 'ids' => '["sesarrep-parent"]', 'file' => new CURLFile($TMP . '/junk.xlsx', 'text/plain', 'junk.xlsx')));
check('batch: not a template -> 400', $r['status'] === 400 && $r['json']['error'] === 'validation');
$r = http('POST', '/sesar_batch.php', $pilot, array('action' => 'check', 'ids' => '["sesarrep-parent"]', 'file' => $file));
check("batch: pilot checking someone else's sample -> it is left out", $r['status'] === 200 && $r['json']['plan']['included'] === 0
	&& $r['json']['plan']['samples'][0]['reason'] === 'Not one of your samples.' && !isset($r['json']['plan']['rows']), $r['body']);
$r = http('POST', '/sesar_reports.php', null, array('report' => 'igsns'));
check('reports: no session -> 401', $r['status'] === 401);
$r = http('POST', '/sesar_reports.php', $other, array('report' => 'igsns'));
check('reports: non-pilot -> 403', $r['status'] === 403 && $r['json']['error'] === 'not_allowed');
$r = http('POST', '/sesar_reports.php', $pilot, array('report' => 'nope'));
check('reports: unknown report -> 400', $r['status'] === 400);
$r = http('GET', '/sesar_reports.php', $pilot);
check('reports: GET -> 405', $r['status'] === 405);
$r = http('POST', '/sesar_reports.php', $pilot, array('report' => 'igsns', 'ids' => '["sesarrep-m-reg"]', 'refresh' => '0', 'format' => 'csv'));
check("reports: pilot asking for someone else's sample -> header row only", $r['status'] === 200 && substr_count(trim($r['body']), "\n") === 0
	&& strpos($r['body'], 'StraboSpot id') !== false, $r['body']);
$r = http('POST', '/sesar_pull.php', null, null, array('action' => 'batch_matches'));
check('pull batch_matches: no session -> 401', $r['status'] === 401);
$r = http('POST', '/sesar_pull.php', $other, null, array('action' => 'batch_link', 'sample_id' => 'sesarrep-m-pend', 'igsn' => '10.58052/IEFAK0B03'));
check('pull batch_link: non-pilot -> 403, nothing written', $r['status'] === 403 && reg('sesarrep-m-pend', $A) === null);
$r = http('POST', '/sesar_pull.php', $pilot, null, array('action' => 'batch_link', 'sample_id' => 'sesarrep-m-pend', 'igsn' => 'bad'));
check('pull batch_link: not an IGSN -> 400', $r['status'] === 400);

} finally {
	SesarAccess::setEnvironmentForTests(null);
	cleanup();
	@unlink($STATE);
	foreach (glob($TMP . '/*') as $f) @unlink($f);
	@rmdir($TMP);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
