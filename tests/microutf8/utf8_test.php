<?php
/**
 * File: utf8_test.php
 * Description: Non-ASCII text in StraboMicro uploads and the one-time repair
 *              (microdb/lib/micro_text.php, microdb/tools/repair_utf8.php).
 *
 *              A. Upload paths (jwtmicrodb, first upload and in-place
 *                 re-upload): a UTF-8 project.json lands unchanged in
 *                 projectjson, the relational rows, keywords, the samples
 *                 spine and search; a CP1252 file (legacy JavaFX) is read as
 *                 CP1252 (’, ö, €), not Latin-1.
 *              B. Repair: a project put into the exact state the old code
 *                 left (projectjson encoded twice, rows and spine once) is
 *                 restored to equal a fresh upload of the same file; a
 *                 Samples-app edit made after the upload survives and is
 *                 left out of the changelog entry; the static project.json
 *                 and the project.zip entry are clean; a second run changes
 *                 nothing; the dry run changes nothing; a CP1252 project
 *                 repairs too.
 *
 *              Usage: docker exec strabo-php php /srv/app/www/tests/microutf8/utf8_test.php
 *              Hermetic: a throwaway user (removed at the end), mstest-u
 *              straboIds, journal in /tmp. Exits non-zero on any failure.
 */

set_time_limit(0);
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/microdb/lib/micro_text.php';
require_once '/srv/app/www/tests/lib/microsync_client.php';

$FILES = '/srv/app/www/straboMicroFiles';
$RUN = substr(md5(uniqid('', true)), 0, 6);
$W = "/tmp/microutf8_$RUN";
$JOURNAL = "$W/journal";
@mkdir($JOURNAL, 0777, true);
$PREFIX = 'mstest-u' . $RUN . '-';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr($detail, 0, 1500) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function section($name) { echo "\n== $name\n"; }
function val($sql, $params) {
	global $db;
	$r = pg_query_params($db->dbh, $sql, $params);
	$row = $r ? pg_fetch_row($r) : false;
	return $row ? $row[0] : null;
}

/** A project.json with non-ASCII text in the project, a sample, a micrograph and a spot. */
function project_json($sid, $samplePrefix, $text) {
	return json_encode(array(
		'id' => $sid, 'name' => "Project $text", 'notes' => "Notes $text",
		'datasets' => array(array('id' => $samplePrefix . 'D', 'name' => "Dataset $text", 'samples' => array(
			array('id' => $samplePrefix . 'S1', 'name' => "Sample $text", 'label' => "Sample $text", 'sampleID' => "Sample $text",
				'sampleDescription' => "Described $text",
				'micrographs' => array(array('id' => $samplePrefix . 'M1', 'name' => "Micrograph $text", 'notes' => "Seen $text",
					'spots' => array(array('id' => $samplePrefix . 'P1', 'name' => "Spot $text", 'notes' => "Spot notes $text"))))),
			array('id' => $samplePrefix . 'S2', 'name' => "Other $text", 'label' => "Other $text", 'sampleID' => "Other $text",
				'sampleDescription' => "Second $text", 'micrographs' => array()),
		))),
	), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function upload($user, $sid, $bytes) {
	global $W;
	$zip = "$W/$sid-" . uniqid() . '.zip';
	make_zip($bytes, $sid, $zip);
	return legacy('jwt', 'upload', $user, $zip, $sid);
}

/** Text values a project's rows carry (sample label and description, micrograph and spot names, keywords). */
function stored($pid) {
	return array(
		'projectjson' => val("SELECT projectjson FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid)),
		'pname'       => val("SELECT name FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid)),
		'label'       => val("SELECT s.label FROM strabomicro.micro_samplemetadata s JOIN strabomicro.micro_datasetmetadata d ON d.id = s.dataset_id WHERE d.project_id = $1 ORDER BY s.label LIMIT 1", array($pid)),
		'mname'       => val("SELECT m.name FROM strabomicro.micro_micrographmetadata m JOIN strabomicro.micro_samplemetadata s ON s.id = m.sample_id JOIN strabomicro.micro_datasetmetadata d ON d.id = s.dataset_id WHERE d.project_id = $1 LIMIT 1", array($pid)),
		'keywords'    => val("SELECT keywords::text FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid)),
	);
}
function spine($sampleId, $user, $col = 'name') {
	return val("SELECT $col FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($sampleId, $user));
}
function repair($args) {
	global $JOURNAL;
	$out = array();
	exec('php /srv/app/www/microdb/tools/repair_utf8.php --journal=' . escapeshellarg($JOURNAL) . ' ' . $args . ' 2>&1', $out, $rc);
	return array('rc' => $rc, 'out' => implode("\n", $out));
}

$db->get_var('SELECT 1');
$email = "microutf8-$RUN@test.strabospot.org";
$db->get_var_prepared("INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Utf', 'Test', $1, 'x', 'x', true) RETURNING pkey", array($email));
$U = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($email));
if ($U <= 0) { fwrite(STDERR, "could not create test user\n"); exit(2); }
$sids = array();

$TEXT = "Mezőmadaras 000° µm ’quote’ 粒间孔 😃";
try {
	// -----------------------------------------------------------------------
	section('A. Upload paths');
	$sid = $PREFIX . 'up';
	$sids[] = $sid;
	$json = project_json($sid, "u{$RUN}", $TEXT);
	upload($U, $sid, $json);
	$pid = pid_of($db, $U, $sid);
	$st = stored($pid);
	check('first upload: projectjson = the file', $st['projectjson'] === $json);
	check('first upload: project name, sample label, micrograph name clean',
		$st['pname'] === "Project $TEXT" && $st['label'] === "Other $TEXT" && $st['mname'] === "Micrograph $TEXT",
		json_encode(array($st['pname'], $st['label'], $st['mname']), JSON_UNESCAPED_UNICODE));
	check('first upload: keywords hold the clean words', strpos($st['keywords'], "'mezőmadara'") !== false, (string)$st['keywords']);
	check('first upload: samples spine name clean', spine("u{$RUN}S1", $U) === "Sample $TEXT", (string)spine("u{$RUN}S1", $U));
	$json2 = str_replace('Seen', 'Seen again', $json);
	upload($U, $sid, $json2);
	$st = stored($pid_of = pid_of($db, $U, $sid));
	check('re-upload in place: same id, projectjson = the file, rows clean', $pid_of === $pid && $st['projectjson'] === $json2
		&& $st['mname'] === "Micrograph $TEXT");

	$sidC = $PREFIX . 'cp';
	$sids[] = $sidC;
	$cp = "{\"id\":\"$sidC\",\"name\":\"It\x92s M\xF6 \x80 5\xB0\",\"datasets\":[]}";
	upload($U, $sidC, $cp);
	$pidC = pid_of($db, $U, $sidC);
	check('CP1252 file: accepted and read as CP1252 (’ ö € °)', $pidC !== null
		&& val("SELECT name FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pidC)) === "It’s Mö € 5°",
		(string)val("SELECT name FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pidC)));
	check('CP1252 file: projectjson stored as UTF-8', micro_text_is_utf8(val("SELECT projectjson FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pidC))));

	// -----------------------------------------------------------------------
	section('B. Repair');
	$sidR = $PREFIX . 'rep';
	$sids[] = $sidR;
	$jsonR = project_json($sidR, "r{$RUN}", $TEXT);
	// The old code's result: rows and spine from utf8_encode(file) (valid,
	// garbled UTF-8, now stored as given), projectjson encoded once more.
	upload($U, $sidR, utf8_encode($jsonR));
	$pidR = pid_of($db, $U, $sidR);
	pg_query_params($db->dbh, "UPDATE strabomicro.micro_projectmetadata SET projectjson = $2 WHERE id = $1",
		array($pidR, utf8_encode(utf8_encode($jsonR))));
	@file_put_contents("$FILES/$pidR/project.json", utf8_encode($jsonR)); // as if rewritten from projectjson earlier
	check('setup: garbled state (spine name garbled, projectjson twice)', spine("r{$RUN}S1", $U) === utf8_encode("Sample $TEXT")
		&& micro_projectjson_repair(stored($pidR)['projectjson']) !== null);
	// A Samples-app edit made after the upload, on the second sample.
	pg_query_params($db->dbh, "UPDATE strabosamples.samples SET description = 'Edited in Samples ✓' WHERE id = $1 AND userpkey = $2",
		array("r{$RUN}S2", $U));

	$before = stored($pidR);
	$dry = repair("--only=$pidR");
	check('dry run: lists the project, changes nothing', strpos($dry['out'], "#$pidR ") !== false && stored($pidR) === $before, $dry['out']);

	$ap = repair("--apply --only=$pidR");
	check('apply: exit 0', $ap['rc'] === 0, $ap['out']);
	$st = stored($pidR);
	check('projectjson = the uploaded file', $st['projectjson'] === $jsonR);
	check('rows clean (project, sample label, micrograph)', $st['pname'] === "Project $TEXT" && $st['label'] === "Other $TEXT"
		&& $st['mname'] === "Micrograph $TEXT", json_encode(array($st['pname'], $st['label'], $st['mname']), JSON_UNESCAPED_UNICODE));
	check('keywords clean', strpos($st['keywords'], "'mezőmadara'") !== false);
	check('spine: garbled name corrected', spine("r{$RUN}S1", $U) === "Sample $TEXT", (string)spine("r{$RUN}S1", $U));
	check('spine: garbled description corrected', spine("r{$RUN}S1", $U, 'description') === "Described $TEXT");
	check('spine: later Samples-app edit kept', spine("r{$RUN}S2", $U, 'description') === 'Edited in Samples ✓',
		(string)spine("r{$RUN}S2", $U, 'description'));
	$log = val("SELECT changes::text FROM strabosamples.sample_changelog WHERE sample_id = $1 AND sample_userpkey = $2 AND source_subsystem = 'micro' ORDER BY pkey DESC LIMIT 1",
		array("r{$RUN}S2", $U));
	$logj = json_decode((string)$log, true);
	check('changelog: repair entry marked, kept field not in its diff', is_array($logj) && !empty($logj['utf8_repair'])
		&& !isset($logj['spine_diff']['description']), (string)$log);
	$disk = json_decode((string)@file_get_contents("$FILES/$pidR/project.json"), true);
	check('static project.json clean (overlay applied)', is_array($disk) && $disk['name'] === "Project $TEXT"
		&& $disk['datasets'][0]['samples'][1]['sampleDescription'] === 'Edited in Samples ✓');
	$za = new ZipArchive();
	$zipJson = null;
	if ($za->open("$FILES/$pidR/project.zip") === true) {
		for ($i = 0; $i < $za->numFiles; $i++) if (substr($za->getNameIndex($i), -12) === 'project.json') $zipJson = $za->getFromIndex($i);
		$za->close();
	}
	$zj = json_decode((string)$zipJson, true);
	check('project.zip entry clean', is_array($zj) && $zj['name'] === "Project $TEXT", substr((string)$zipJson, 0, 200));
	check('pdf marked for regeneration', val("SELECT pdf_dirty FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pidR)) === 't');
	check('same id, share key, upload date', pid_of($db, $U, $sidR) === $pidR);

	$again = repair("--apply --only=$pidR");
	check('second run: nothing to do, nothing changed', $again['rc'] === 0 && strpos($again['out'], "#$pidR ") === false
		&& stored($pidR) === $st, $again['out']);

	// Equal to a fresh upload of the same file.
	$sidF = $PREFIX . 'fresh';
	$sids[] = $sidF;
	upload($U, $sidF, str_replace($sidR, $sidF, $jsonR));
	$fresh = stored(pid_of($db, $U, $sidF));
	check('repaired rows equal a fresh upload of the file', $fresh['label'] === $st['label'] && $fresh['mname'] === $st['mname']
		&& $fresh['keywords'] === $st['keywords']);

	// CP1252 project in the old state.
	$sidK = $PREFIX . 'rcp';
	$sids[] = $sidK;
	$cpK = "{\"id\":\"$sidK\",\"name\":\"It\x92s M\xF6\",\"datasets\":[]}";
	upload($U, $sidK, utf8_encode($cpK)); // old code: once for the rows
	$pidK = pid_of($db, $U, $sidK);
	pg_query_params($db->dbh, "UPDATE strabomicro.micro_projectmetadata SET projectjson = $2 WHERE id = $1",
		array($pidK, utf8_encode(utf8_encode($cpK))));
	$apK = repair("--apply --only=$pidK");
	check('CP1252 project repaired (’ restored, not a control character)',
		val("SELECT name FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pidK)) === 'It’s Mö', $apK['out']);

} finally {
	foreach ($sids as $s) {
		$pid = pid_of($db, $U, $s);
		legacy('jwt', 'delete', $U, '-', $s);
		if ($pid && is_dir("$FILES/$pid")) exec('rm -rf ' . escapeshellarg("$FILES/$pid"));
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	$db->prepare_query("DELETE FROM strabosamples.sample_changelog WHERE sample_userpkey = $1", array($U));
	$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($U));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($U));
	exec('rm -rf ' . escapeshellarg($W));
}

echo "\n" . (empty($failures) ? 'ALL PASSED' : count($failures) . " FAILED:\n  - " . implode("\n  - ", $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
