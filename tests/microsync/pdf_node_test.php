<?php
/**
 * File: pdf_node_test.php
 * Description: Project PDFs through the strabo-node container
 *              (microdb/lib/micro_pdf_node.php + the worker sweep):
 *                - routing: StraboMicro2-format folders go to Node, JavaFX
 *                  ones (images/<id>.jpg only) keep MicroProjectPDF
 *                - download path: a dirty project is rendered by Node, the
 *                  flag cleared, project.json refreshed first; a clean one
 *                  is left alone
 *                - a failed render puts the flag back and keeps the old PDF
 *                - JavaFX project: tFPDF as before
 *                - sweep: renders dirty StraboMicro2-format projects, leaves
 *                  JavaFX ones for the download path; after a failed render
 *                  it skips that project for an hour; with strabo-node
 *                  down it renders nothing and records no failures
 *              Every file and flag touched is restored at the end, and
 *              projects that were already dirty are set aside during the
 *              sweep so it only renders the fixtures.
 *
 *              Needs strabo-node running and the dev fixture folders 786
 *              (affine overlay), 888 (overlays) and 475 (JavaFX format).
 *
 *              Usage: docker exec -u www-data strabo-php php /srv/app/www/tests/microsync/pdf_node_test.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

set_time_limit(0);
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/microdb/lib/MicroProjectPDF.php';
require_once '/srv/app/www/microdb/lib/micro_pdf_node.php';

$FILES = '/srv/app/www/straboMicroFiles';
$FIXTURES = array(786, 888, 475);
$FAILS = '/srv/app/www/microsync_data/pdf_failures.json';
$failsBefore = @file_get_contents($FAILS);
$KEEP = array('project.pdf', 'project.json', 'project.zip');

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr($detail, 0, 1500) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function section($name) { echo "\n== $name\n"; }

function flags($pid) {
	global $db;
	return $db->get_row_prepared(
		"SELECT pdf_dirty, files_dirty FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
}
function setFlags($pid, $pdf, $files) {
	global $db;
	$db->prepare_query(
		"UPDATE strabomicro.micro_projectmetadata SET pdf_dirty = $2, files_dirty = $3 WHERE id = $1",
		array($pid, $pdf ? 't' : 'f', $files ? 't' : 'f'));
}
function owner($pid) {
	global $db;
	return (int)$db->get_var_prepared("SELECT userpkey FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
}
function producer($file) {
	$head = (string)@file_get_contents($file, false, null, 0, 4096);
	$tail = (string)@file_get_contents($file, false, null, max(0, @filesize($file) - 8192));
	if (strpos($head . $tail, 'react-pdf') !== false) return 'react-pdf';
	if (strpos($head . $tail, 'tFPDF') !== false || strpos($head . $tail, 'FPDF') !== false) return 'fpdf';
	return 'unknown';
}

$db->get_var('SELECT 1');

// ---- Back up fixture files and flags; set aside other dirty projects ----
$backup = "/tmp/pdf_node_test_" . substr(md5(uniqid('', true)), 0, 6);
@mkdir($backup, 0777, true);
$savedFlags = array();
foreach ($FIXTURES as $pid) {
	check("fixture folder $pid exists", is_dir("$FILES/$pid"));
	@mkdir("$backup/$pid");
	foreach ($KEEP as $f) {
		if (is_file("$FILES/$pid/$f")) copy("$FILES/$pid/$f", "$backup/$pid/$f");
	}
	$savedFlags[$pid] = flags($pid);
}
$otherDirty = array();
foreach ($db->get_results_prepared(
	"SELECT id FROM strabomicro.micro_projectmetadata WHERE pdf_dirty AND NOT (id = ANY($1::int[]))",
	array('{' . implode(',', $FIXTURES) . '}')) ?: array() as $r) {
	$otherDirty[] = (int)$r->id;
}
if ($otherDirty) {
	$db->prepare_query("UPDATE strabomicro.micro_projectmetadata SET pdf_dirty = FALSE WHERE id = ANY($1::int[])",
		array('{' . implode(',', $otherDirty) . '}'));
}
echo "set aside " . count($otherDirty) . " other dirty projects\n";

try {
	section('routing');
	check('786 (StraboMicro2 format) uses Node', micro_pdf_uses_node($db, 786) === true);
	check('888 (StraboMicro2 format) uses Node', micro_pdf_uses_node($db, 888) === true);
	check('475 (JavaFX, images/<id>.jpg only) keeps tFPDF', micro_pdf_uses_node($db, 475) === false);
	check('missing folder keeps tFPDF', micro_pdf_uses_node($db, 999999999) === false);

	section('download path: dirty StraboMicro2 project');
	setFlags(786, true, true);
	@file_put_contents("$FILES/786/project.pdf", "%PDF-old");
	@file_put_contents("$FILES/786/project.json", "{}");
	micro_regenerate_pdf_if_dirty($db, 786, owner(786));
	$f = flags(786);
	check('pdf_dirty cleared', $f->pdf_dirty === 'f');
	check('files_dirty cleared (project.json refreshed first)', $f->files_dirty === 'f');
	check('project.json rewritten from the database', strlen((string)@file_get_contents("$FILES/786/project.json")) > 100);
	check('project.pdf is the app renderer\'s (react-pdf) with images', producer("$FILES/786/project.pdf") === 'react-pdf'
		&& filesize("$FILES/786/project.pdf") > 1000000, filesize("$FILES/786/project.pdf") . ' bytes');
	check('no temp file left behind', !glob("$FILES/786/.*.tmp"));

	section('download path: clean project is left alone');
	$before = filemtime("$FILES/786/project.pdf");
	clearstatcache();
	sleep(1);
	micro_regenerate_pdf_if_dirty($db, 786, owner(786));
	clearstatcache();
	check('no render when pdf_dirty is false', filemtime("$FILES/786/project.pdf") === $before);
	check('render_node reports clean', micro_pdf_render_node($db, 786, owner(786), 30) === 'clean');

	section('failed render puts the flag back');
	setFlags(888, true, false);
	@file_put_contents("$FILES/888/project.pdf", "%PDF-old");
	rename("$FILES/888/project.json", "$FILES/888/project.json.hidden");
	$r = micro_pdf_render_node($db, 888, owner(888), 30);
	rename("$FILES/888/project.json.hidden", "$FILES/888/project.json");
	check('result is failed (no project.json)', strpos($r, 'failed: HTTP 404') === 0, $r);
	check('pdf_dirty set again', flags(888)->pdf_dirty === 't');
	check('old PDF kept', @file_get_contents("$FILES/888/project.pdf") === '%PDF-old');

	section('JavaFX project: tFPDF as before');
	setFlags(475, true, false);
	micro_regenerate_pdf_if_dirty($db, 475, owner(475));
	check('pdf_dirty cleared', flags(475)->pdf_dirty === 'f');
	check('project.pdf written by tFPDF', producer("$FILES/475/project.pdf") === 'fpdf', producer("$FILES/475/project.pdf"));

	section('sweep');
	setFlags(786, true, false);
	setFlags(888, true, false);
	setFlags(475, true, false);
	@file_put_contents("$FILES/786/project.pdf", "%PDF-old");
	@file_put_contents("$FILES/888/project.pdf", "%PDF-old");
	$out = array();
	exec('php /srv/app/www/microsync/worker.php --sweep 2>&1', $out, $code);
	check('sweep exits 0', $code === 0, implode("\n", $out));
	check('786 rendered by the sweep', flags(786)->pdf_dirty === 'f' && producer("$FILES/786/project.pdf") === 'react-pdf');
	check('888 rendered by the sweep', flags(888)->pdf_dirty === 'f' && producer("$FILES/888/project.pdf") === 'react-pdf');
	check('475 (JavaFX) left for the download path', flags(475)->pdf_dirty === 't');
	$log = (string)@file_get_contents('/srv/app/www/microsync_data/log/worker.log');
	check('sweep logged the renders', strpos($log, 'rendered 2 pdfs') !== false);

	section('sweep: strabo-node not ready');
	setFlags(475, false, false);
	setFlags(786, true, false);
	@file_put_contents("$FILES/786/project.pdf", "%PDF-old");
	$before = (string)@file_get_contents($FAILS);
	exec('STRABO_NODE_URL=http://127.0.0.1:9 php /srv/app/www/microsync/worker.php --sweep 2>&1', $out, $code);
	check('sweep still exits 0', $code === 0, implode("\n", $out));
	check('project stays dirty, old PDF kept', flags(786)->pdf_dirty === 't'
		&& @file_get_contents("$FILES/786/project.pdf") === '%PDF-old');
	check('not recorded as a failure', (string)@file_get_contents($FAILS) === $before
		|| !isset((json_decode((string)@file_get_contents($FAILS), true) ?: array())[786]));
	$log = (string)@file_get_contents('/srv/app/www/microsync_data/log/worker.log');
	check('logged that Node is not ready', strpos($log, 'strabo-node not ready') !== false);
	exec('php /srv/app/www/microsync/worker.php --sweep 2>&1', $out, $code);
	check('next sweep with Node up renders it', flags(786)->pdf_dirty === 'f' && producer("$FILES/786/project.pdf") === 'react-pdf');

	section('sweep: a failed render waits an hour');
	setFlags(475, false, false);
	setFlags(888, true, false);
	@file_put_contents("$FILES/888/project.pdf", "%PDF-old");
	rename("$FILES/888/project.json", "$FILES/888/project.json.hidden");
	exec('php /srv/app/www/microsync/worker.php --sweep 2>&1', $out, $code);
	rename("$FILES/888/project.json.hidden", "$FILES/888/project.json");
	$failed = json_decode((string)@file_get_contents($FAILS), true) ?: array();
	check('failure recorded, flag kept', isset($failed[888]) && flags(888)->pdf_dirty === 't');
	exec('php /srv/app/www/microsync/worker.php --sweep 2>&1', $out, $code);
	check('next sweep skips it (still dirty, old PDF kept)', flags(888)->pdf_dirty === 't'
		&& @file_get_contents("$FILES/888/project.pdf") === '%PDF-old');
	$failed[888] = time() - 3601;
	file_put_contents($FAILS, json_encode($failed));
	exec('php /srv/app/www/microsync/worker.php --sweep 2>&1', $out, $code);
	$failed = json_decode((string)@file_get_contents($FAILS), true) ?: array();
	check('after the hour it renders and the failure is forgotten', flags(888)->pdf_dirty === 'f'
		&& producer("$FILES/888/project.pdf") === 'react-pdf' && !isset($failed[888]));
} finally {
	// ---- Restore everything ----
	foreach ($FIXTURES as $pid) {
		foreach ($KEEP as $f) {
			if (is_file("$backup/$pid/$f")) copy("$backup/$pid/$f", "$FILES/$pid/$f");
		}
		if (is_file("$FILES/$pid/project.json.hidden")) rename("$FILES/$pid/project.json.hidden", "$FILES/$pid/project.json");
		setFlags($pid, $savedFlags[$pid]->pdf_dirty === 't', $savedFlags[$pid]->files_dirty === 't');
	}
	if ($otherDirty) {
		$db->prepare_query("UPDATE strabomicro.micro_projectmetadata SET pdf_dirty = TRUE WHERE id = ANY($1::int[])",
			array('{' . implode(',', $otherDirty) . '}'));
	}
	if ($failsBefore === false) @unlink($FAILS); else file_put_contents($FAILS, $failsBefore);
	exec('rm -rf ' . escapeshellarg($backup));
	echo "\nrestored fixture files and flags; " . count($otherDirty) . " other dirty projects put back\n";
}

echo "\n" . ($failures ? count($failures) . " FAILED:\n  " . implode("\n  ", $failures) : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
