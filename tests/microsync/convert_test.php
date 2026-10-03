<?php
/**
 * File: convert_test.php
 * Description: Rollout step 4 conversion (microsync/lib/MsConvert.php,
 *              microsync/tools/convert.php). A throwaway user uploads real
 *              .smz files through the legacy jwtmicrodb path (so the folder
 *              and project.zip look exactly like production), then:
 *                - dry run changes nothing
 *                - apply: store, blobs (hard links), archive, journal, views,
 *                  streamed download equal to the archived zip, _archive
 *                  never served, tile folders kept (.blob marker)
 *                - revert: back to legacy, zip byte-identical, static file
 *                  served again; refused once the project changed
 *                - failures after the blobs exist are undone completely:
 *                  archive slot taken (after commit), upload during the
 *                  conversion (before commit)
 *                - JavaFX-format zip is skipped; differing duplicates stop
 *                - P0-9: old-app uploads of a converted single-member project
 *                  (with a file, and the chunked no-file path) become the
 *                  owner's changes in the store; an unchanged re-upload
 *                  changes nothing; an upload that cannot be taken in puts
 *                  the project back to legacy with that upload; a shared
 *                  project is refused. Run as root (the no-file path needs
 *                  /StraboData/bigDriveData/tempFiles, made here on dev).
 *
 *              Needs the dev fixture folders 786 (tiles + tilesAffine) and
 *              747 (point counts, attachment) in straboMicroFiles/.
 *
 *              Usage: docker exec -u www-data strabo-php php /srv/app/www/tests/microsync/convert_test.php
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
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microsync/lib/MsConvert.php';

$FILES = '/srv/app/www/straboMicroFiles';
$HOST = 'http://localhost';
$RUN = substr(md5(uniqid('', true)), 0, 6);
$W = "/tmp/microsync_convert_test_$RUN";
@mkdir($W, 0777, true);

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr($detail, 0, 1500) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function section($name) { echo "\n== $name\n"; }

$ms = new MsDb($db);
function prow($pid) {
	global $ms;
	return $ms->row("SELECT sync_format, sync_state, head_seq, views_built_at IS NOT NULL AS built,
		(SELECT count(*) FROM strabomicro.micro_entities e WHERE e.project_id = p.id) AS entities,
		(SELECT count(*) FROM strabomicro.micro_blobs b WHERE b.project_id = p.id) AS blobs,
		(SELECT count(*) FROM strabomicro.micro_members m WHERE m.project_id = p.id) AS members
		FROM strabomicro.micro_projectmetadata p WHERE id = $1", array($pid));
}
function isLegacyClean($pid, $zipMd5) {
	global $FILES;
	$r = prow($pid);
	$markers = trim((string)shell_exec('find ' . escapeshellarg("$FILES/$pid") . ' -name .blob | wc -l'));
	return $r['sync_format'] === 'legacy' && (int)$r['entities'] === 0 && (int)$r['blobs'] === 0 && (int)$r['members'] === 0
		&& is_file("$FILES/$pid/project.zip") && md5_file("$FILES/$pid/project.zip") === $zipMd5
		&& !is_dir("$FILES/$pid/blobs") && !is_dir(MsConvert::archiveDir($pid)) && $markers === '0';
}
/** project.pdf inside a zip (root folder entry), or null. */
function zipPdf($path) {
	$z = new ZipArchive();
	if ($z->open($path) !== true) return null;
	$out = null;
	for ($i = 0; $i < $z->numFiles; $i++) {
		$n = $z->getNameIndex($i);
		if (substr_count($n, '/') === 1 && substr($n, -12) === '/project.pdf') { $out = $z->getFromIndex($i); break; }
	}
	$z->close();
	return $out;
}
function servedPdf($pid) {
	global $HOST;
	return http_req('GET', "$HOST/download_micro_pdf.php?project_id=$pid")['raw'];
}
function zipIndex($path) {
	$z = new ZipArchive();
	$z->open($path);
	$out = array();
	for ($i = 0; $i < $z->numFiles; $i++) {
		$st = $z->statIndex($i);
		if (substr($st['name'], -1) === '/') continue;
		$rel = substr($st['name'], strpos($st['name'], '/') + 1);
		$out[$rel] = array($st['size'], $st['crc']);
	}
	$z->close();
	return $out;
}

// ---------------------------------------------------------------------------
$db->get_var('SELECT 1');
$email = "microsync-convert-$RUN@test.strabospot.org";
$db->get_var_prepared("INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Convert', 'Test', $1, 'x', 'x', true) RETURNING pkey", array($email));
$U = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($email));
if ($U <= 0) { echo "cannot create the test user\n"; exit(1); }
$conv = new MsConvert($db);
$pids = array();

try {
	section('Setup: legacy uploads of real projects');
	$fixtures = array();
	foreach (array(786, 747) as $src) {
		$zip = "$FILES/$src/project.zip";
		if (!check("fixture $src has project.zip", is_file($zip))) throw new Exception('fixtures missing');
		$z = new ZipArchive();
		$z->open($zip);
		$sid = substr($z->getNameIndex(0), 0, strpos($z->getNameIndex(0), '/'));
		$z->close();
		if ($src === 747) {
			// No new-app fixture has attachments: give one micrograph and one
			// spot the same file, as the app would write it.
			copy($zip, "$W/747-att.zip");
			$zip = "$W/747-att.zip";
			$z = new ZipArchive();
			$z->open($zip);
			$pj = json_decode($z->getFromName("$sid/project.json"));
			$m0 = $pj->datasets[0]->samples[0]->micrographs[0];
			$af = (object)array('fileName' => 'notes é.txt', 'fileType' => 'Other', 'notes' => 'test');
			$m0->associatedFiles = array($af);
			if (isset($m0->spots[0])) $m0->spots[0]->associatedFiles = array($af);
			$z->addFromString("$sid/project.json", json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			$z->addFromString("$sid/associatedFiles/notes é.txt", "attachment body\n");
			$z->close();
		}
		$res = legacy('jwt', 'upload', $U, $zip, $sid);
		$pid = pid_of($db, $U, $sid);
		check("legacy upload of $src", $pid !== null, json_encode($res));
		$pids[] = $pid;
		$fixtures[$src] = array('pid' => $pid, 'sid' => $sid, 'md5' => md5_file("$FILES/$pid/project.zip"));
	}
	$A = $fixtures[786]['pid'];
	$B = $fixtures[747]['pid'];

	section('Dry run changes nothing');
	foreach (array($A, $B) as $pid) {
		$r = $conv->convert($pid, false);
		check("#$pid would convert", $r['status'] === 'would_convert', json_encode($r));
		check("#$pid still legacy and untouched", isLegacyClean($pid, md5_file("$FILES/$pid/project.zip")));
	}

	section('Apply');
	$before = zipIndex("$FILES/$A/project.zip");
	$tileDir = "$FILES/$A/tiles/" . scandir("$FILES/$A/tiles")[2];
	$tileFile = $tileDir . '/metadata.json';
	$tileMtime = filemtime($tileFile);
	$r = $conv->convert($A, true);
	check('#A converted', $r['status'] === 'converted', json_encode($r));
	$p = prow($A);
	check('entity, ready, views built', $p['sync_format'] === 'entity' && $p['sync_state'] === 'ready' && $p['built'] === 't', json_encode($p));
	check('owner is the only member', (int)$p['members'] === 1);
	check('static project.zip gone, archive + journal present',
		!is_file("$FILES/$A/project.zip") && is_file(MsConvert::archiveDir($A) . '/project.zip') && is_file(MsConvert::archiveDir($A) . '/journal.json'));
	check('archive is the original zip', md5_file(MsConvert::archiveDir($A) . '/project.zip') === $fixtures[786]['md5']);
	$img = glob("$FILES/$A/images/*");
	$linked = count($img) > 0;
	foreach ($img as $f) {
		if (substr($f, -4) === '.jpg' || fileinode($f) !== fileinode("$FILES/$A/blobs/" . hash_file('sha256', $f))) $linked = false;
	}
	check('images are hard links to their blobs (0-byte composites gone)', $linked);
	clearstatcache();
	check('tile folder kept as is (marker written, not unpacked again)', is_file("$tileDir/.blob") && filemtime($tileFile) === $tileMtime);

	$dl = http_req('GET', "$HOST/straboMicroFiles/$A/project.zip");
	file_put_contents("$W/a.smz", $dl['raw']);
	check('download is the streamed archive (HTTP 200)', $dl['code'] === 200 && strlen($dl['raw']) > 1000);
	$after = zipIndex("$W/a.smz");
	$files = function ($ix) {
		foreach (array_keys($ix) as $k) {
			if (in_array($k, array('project.pdf', 'README.txt', 'project.json'), true) || basename($k) === '.gitkeep') unset($ix[$k]);
		}
		ksort($ix);
		return $ix;
	};
	check('every file entry equals the archived zip (name, size, CRC)', $files($before) === $files($after),
		json_encode(array_slice(array_diff_key($files($before), $files($after)), 0, 3)) . json_encode(array_slice(array_diff_key($files($after), $files($before)), 0, 3)));
	foreach (array("_archive/$A/project.zip", "_archive/$A/journal.json", "$A/blobs/") as $u) {
		$code = http_req('GET', "$HOST/straboMicroFiles/$u")['code'];
		check("never served: $u", $code === 403, "HTTP $code");
	}
	$appPdf = zipPdf(MsConvert::archiveDir($A) . '/project.zip');
	check('the app PDF is kept (not marked for regeneration)', $appPdf !== null
		&& $ms->val("SELECT pdf_dirty FROM strabomicro.micro_projectmetadata WHERE id = $1", array($A)) === 'f'
		&& md5(servedPdf($A)) === md5($appPdf));
	$ms->q("UPDATE strabomicro.micro_projectmetadata SET pdf_dirty = true WHERE id = $1", array($A));
	servedPdf($A); // what the first prod conversions hit: a text-only server PDF
	check('restore-pdf puts the app PDF back', $conv->restorePdf($A) === null && md5(servedPdf($A)) === md5($appPdf)
		&& $ms->val("SELECT pdf_dirty FROM strabomicro.micro_projectmetadata WHERE id = $1", array($A)) === 'f');
	$again = $conv->convert($A, true);
	check('second apply skips (already_entity)', $again['status'] === 'skipped' && $again['reason'] === 'already_entity');

	section('Revert');
	$ms->q("UPDATE strabomicro.micro_projectmetadata SET head_seq = head_seq + 1 WHERE id = $1", array($A));
	check('revert refused after a change', $conv->revert($A) !== null);
	$ms->q("UPDATE strabomicro.micro_projectmetadata SET head_seq = head_seq - 1 WHERE id = $1", array($A));
	check('revert accepted when unchanged', $conv->revert($A) === null);
	check('back to legacy, zip byte-identical, nothing left', isLegacyClean($A, $fixtures[786]['md5']));
	$dl = http_req('GET', "$HOST/straboMicroFiles/$A/project.zip");
	check('static zip served again', $dl['code'] === 200 && md5($dl['raw']) === $fixtures[786]['md5']);

	section('Failure after commit: archive slot taken');
	@mkdir(MsConvert::archiveDir($B), 0775, true);
	file_put_contents(MsConvert::archiveDir($B) . '/project.zip', 'blocker');
	$r = $conv->convert($B, true);
	check('fails with io', $r['status'] === 'failed' && $r['reason'] === 'io', json_encode($r));
	check('undone flag set', !empty($r['details']['undone']));
	$p = prow($B);
	check('store rows gone, legacy again', $p['sync_format'] === 'legacy' && (int)$p['entities'] === 0 && (int)$p['blobs'] === 0 && (int)$p['members'] === 0, json_encode($p));
	check('zip in place and unchanged, blobs gone', md5_file("$FILES/$B/project.zip") === $fixtures[747]['md5'] && !is_dir("$FILES/$B/blobs"));
	check('blocker file left alone', @file_get_contents(MsConvert::archiveDir($B) . '/project.zip') === 'blocker');
	@unlink(MsConvert::archiveDir($B) . '/project.zip');
	@rmdir(MsConvert::archiveDir($B));

	section('Failure before commit: a legacy upload lands during the conversion');
	$conv->beforeLockHook = function ($in) {
		touch($in['zip'], $in['zipStat']['mtime'] + 5);
	};
	$r = $conv->convert($B, true);
	$conv->beforeLockHook = null;
	check('fails with changed', $r['status'] === 'failed' && $r['reason'] === 'changed', json_encode($r));
	check('undone: legacy, no rows, no blobs, no markers', isLegacyClean($B, $fixtures[747]['md5']));

	section('Point counts and attachments convert');
	$r = $conv->convert($B, true);
	check('#B converted', $r['status'] === 'converted', json_encode($r));
	$pc = (int)$ms->val("SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'point_count'", array($B));
	check('point_count entities', $pc === count(glob("$FILES/747/point-counts/*.json")), "$pc");
	$att = (int)$ms->val("SELECT count(*) FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND role LIKE 'associated_file:%'", array($B));
	check('attachment refs (micrograph + spot share one blob)', $att === 2
		&& (int)$ms->val("SELECT count(DISTINCT sha256) FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND role LIKE 'associated_file:%'", array($B)) === 1, "$att");
	$dl = http_req('GET', "$HOST/straboMicroFiles/$B/project.zip");
	file_put_contents("$W/b.smz", $dl['raw']);
	$z = new ZipArchive();
	$z->open("$W/b.smz");
	check('attachment in the stream', $z->getFromName($fixtures[747]['sid'] . '/associatedFiles/notes é.txt') === "attachment body\n");
	$z->close();
	check('revert', $conv->revert($B) === null && isLegacyClean($B, $fixtures[747]['md5']));

	section('P0-9: old-app upload of a converted project');
	$ms->q("SELECT 1"); // keep the connection warm
	$r = $conv->convert($A, true);
	check('#A converted again', $r['status'] === 'converted', json_encode($r));
	$sidA = $fixtures[786]['sid'];
	$orig = MsConvert::archiveDir($A) . '/project.zip';
	$headBefore = (int)prow($A)['head_seq'];

	// The same project edited in the old app: rename, delete a spot, add a
	// spot with an attachment, reorder micrographs, clear a field, new thumbnail.
	copy($orig, "$W/edit.zip");
	$z = new ZipArchive();
	$z->open("$W/edit.zip");
	$pj = json_decode($z->getFromName("$sidA/project.json"));
	$mgs = $pj->datasets[0]->samples[0]->micrographs;
	$m0 = $mgs[0];
	$m1 = $mgs[1];
	$m0OldName = isset($m0->name) ? $m0->name : null;
	$m0->name = 'renamed in the old app';
	$deletedSpot = isset($m0->spots[0]) ? $m0->spots[0]->id : (isset($m1->spots[0]) ? $m1->spots[0]->id : null);
	foreach (array($m0, $m1) as $m) {
		$m->spots = array_values(array_filter($m->spots, function ($sp) use ($deletedSpot) { return $sp->id !== $deletedSpot; }));
	}
	$newSpot = (object)array('id' => 'p09-spot-' . $RUN, 'name' => 'added offline', 'geometryType' => 'point',
		'points' => array((object)array('X' => 10, 'Y' => 20)), 'color' => '#ff0000',
		'associatedFiles' => array((object)array('fileName' => 'p09.txt', 'fileType' => 'Other')));
	$m1->spots[] = $newSpot;
	$pj->datasets[0]->samples[0]->micrographs = array($m1, $m0);
	$cleared = null;
	foreach (get_object_vars($m1) as $k => $v) {
		if (is_string($v) && $v !== '' && !in_array($k, array('id', 'name', 'parentID', 'imageType'), true)) { $cleared = $k; break; }
	}
	if ($cleared !== null) $m1->$cleared = null;
	$z->addFromString("$sidA/project.json", json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	$z->addFromString("$sidA/associatedFiles/p09.txt", "p09 attachment\n");
	$z->addFromString("$sidA/compositeThumbnails/{$m0->id}", "new thumbnail bytes");
	$z->close();
	$oldThumbSha = $ms->val("SELECT sha256 FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND entity_type = 'micrograph' AND entity_id = $2 AND role = 'thumbnail'", array($A, $m0->id));

	$res = legacy('jwt', 'upload', $U, "$W/edit.zip", $sidA);
	check('upload answers success', isset($res['status']) && $res['status'] === 'success', json_encode($res));
	$p = prow($A);
	check('still synced, same id, views built', pid_of($db, $U, $sidA) === $A && $p['sync_format'] === 'entity' && $p['built'] === 't', json_encode($p));
	check('uploaded project.zip removed (the stream serves the store)', !is_file("$FILES/$A/project.zip"));
	check("the upload's own PDF is served", zipPdf("$W/edit.zip") !== null && md5(servedPdf($A)) === md5(zipPdf("$W/edit.zip")));
	$log = @file(MsConvert::archiveDir($A) . '/uploads.log');
	$last = $log ? json_decode(end($log), true) : null;
	check('uploads.log: replaced', is_array($last) && $last['status'] === 'replaced', json_encode($last));
	$e = function ($type, $id) use ($ms, $A) {
		return $ms->row("SELECT body::text AS body, deleted_at, version, updated_by FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3", array($A, $type, $id));
	};
	$em0 = $e('micrograph', $m0->id);
	check('rename is an update by the owner', json_decode($em0['body'])->name === 'renamed in the old app' && (int)$em0['updated_by'] === $U && (int)$em0['version'] > 1, json_encode($em0));
	check('logged with the before value', $ms->val(
		"SELECT before->'body'->>'name' FROM strabomicro.micro_changes WHERE project_id = $1 AND entity_type = 'micrograph' AND entity_id = $2 AND op = 'update' AND 'name' = ANY(changed_paths) ORDER BY seq DESC LIMIT 1",
		array($A, $m0->id)) === $m0OldName, (string)$m0OldName);
	if ($deletedSpot !== null) {
		$ed = $e('spot', $deletedSpot);
		check('removed spot is tombstoned (restorable)', $ed !== null && $ed['deleted_at'] !== null);
	}
	check('new spot created', $e('spot', $newSpot->id) !== null);
	check('attachment ref on the new spot', $ms->val("SELECT 1 FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND entity_id = $2 AND role = 'associated_file:p09.txt'", array($A, $newSpot->id)) === '1');
	$newThumbSha = $ms->val("SELECT sha256 FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND entity_type = 'micrograph' AND entity_id = $2 AND role = 'thumbnail'", array($A, $m0->id));
	check('thumbnail ref points at the new file, old blob kept for history',
		$newThumbSha === hash('sha256', 'new thumbnail bytes') && $oldThumbSha !== null && is_file("$FILES/$A/blobs/$oldThumbSha"));
	$dl = http_req('GET', "$HOST/straboMicroFiles/$A/project.zip");
	file_put_contents("$W/p09.smz", $dl['raw']);
	$z = new ZipArchive();
	$z->open("$W/p09.smz");
	$sj = json_decode($z->getFromName("$sidA/project.json"));
	$z->close();
	$order = array_map(function ($m) { return $m->id; }, $sj->datasets[0]->samples[0]->micrographs);
	check('stream has the new micrograph order', $order === array($m1->id, $m0->id), json_encode($order));
	if ($cleared !== null) {
		check("cleared field ($cleared) is gone from the stream", !isset($sj->datasets[0]->samples[0]->micrographs[0]->$cleared));
	}
	$headAfter = (int)prow($A)['head_seq'];
	check('head moved', $headAfter > $headBefore);

	// The same file again: nothing to change.
	$res = legacy('jwt', 'upload', $U, "$W/edit.zip", $sidA);
	check('unchanged re-upload: success, no new changes', isset($res['status']) && $res['status'] === 'success' && (int)prow($A)['head_seq'] === $headAfter,
		json_encode($res) . ' head ' . prow($A)['head_seq'] . " vs $headAfter");

	// The chunked path (insertProjectWithoutFile).
	$tmpDir = '/StraboData/bigDriveData/tempFiles';
	if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
	if (is_dir($tmpDir) && is_writable($tmpDir)) {
		$z = new ZipArchive();
		copy("$W/edit.zip", "$W/edit2.zip");
		$z->open("$W/edit2.zip");
		$pj2 = json_decode($z->getFromName("$sidA/project.json"));
		$pj2->datasets[0]->samples[0]->micrographs[1]->name = 'renamed again (chunked upload)';
		$z->addFromString("$sidA/project.json", json_encode($pj2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$z->close();
		$res = legacy('jwt', 'uploadnofile', $U, "$W/edit2.zip", $sidA);
		check('chunked upload: success', isset($res['status']) && $res['status'] === 'success', json_encode($res));
		check('chunked upload: taken into the store', json_decode($e('micrograph', $m0->id)['body'])->name === 'renamed again (chunked upload)');
		check('chunked upload: temp file removed, no static zip', !is_file("$tmpDir/micro_$sidA.zip") && !is_file("$FILES/$A/project.zip"));
	} else {
		check('chunked path (needs root for /StraboData on dev)', false, 'run as root');
	}

	// A shared project is refused.
	$db->get_var_prepared("INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Convert', 'Member', $1, 'x', 'x', true)", array("microsync-convert-m-$RUN@test.strabospot.org"));
	$U2 = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array("microsync-convert-m-$RUN@test.strabospot.org"));
	$ms->q("INSERT INTO strabomicro.micro_members (project_id, user_pkey, role, state, invited_by, responded_at) VALUES ($1, $2, 'editor', 'active', $3, now())", array($A, $U2, $U));
	$headShared = (int)prow($A)['head_seq'];
	$res = legacy('jwt', 'upload', $U, $orig, $sidA);
	check('shared project: upload refused', isset($res['Error']) && strpos($res['Error'], 'shared') !== false && (int)prow($A)['head_seq'] === $headShared, json_encode($res));
	$ms->q("DELETE FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey = $2", array($A, $U2));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($U2));

	// An upload the store cannot take (differing duplicate): back to legacy WITH it.
	copy($orig, "$W/dup.zip");
	$z = new ZipArchive();
	$z->open("$W/dup.zip");
	$pj = json_decode($z->getFromName("$sidA/project.json"));
	$dup = clone $pj->datasets[0]->samples[0]->micrographs[0];
	$dup->name = 'differs';
	$pj->datasets[0]->samples[0]->micrographs[] = $dup;
	$z->addFromString("$sidA/project.json", json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	$z->close();
	$res = legacy('jwt', 'upload', $U, "$W/dup.zip", $sidA);
	$p = prow($A);
	check('fallback: upload still answers success', isset($res['status']) && $res['status'] === 'success', json_encode($res));
	check('fallback: legacy, store gone, same id', pid_of($db, $U, $sidA) === $A && $p['sync_format'] === 'legacy' && (int)$p['entities'] === 0 && (int)$p['blobs'] === 0 && (int)$p['members'] === 0, json_encode($p));
	check('fallback: the new upload is the static zip', is_file("$FILES/$A/project.zip") && md5_file("$FILES/$A/project.zip") === md5_file("$W/dup.zip") && !is_dir("$FILES/$A/blobs"));
	check('fallback: pre-conversion archive and logs kept', is_file($orig) && is_file(MsConvert::archiveDir($A) . '/journal.json'));
	$log = @file(MsConvert::archiveDir($A) . '/uploads.log');
	$last = $log ? json_decode(end($log), true) : null;
	check('uploads.log: fell_back with the reason', is_array($last) && $last['status'] === 'fell_back' && $last['reason'] === 'duplicate_differs', json_encode($last));

	section('Skips and stops');
	// A JavaFX-format zip: same project, uiImages/ added.
	copy("$FILES/$B/project.zip", "$W/b.zip");
	$z = new ZipArchive();
	$z->open("$W/b.zip");
	$z->addFromString($fixtures[747]['sid'] . '/uiImages/x', 'x');
	$z->close();
	$keep = "$W/b-orig.zip";
	rename("$FILES/$B/project.zip", $keep);
	copy("$W/b.zip", "$FILES/$B/project.zip");
	$r = $conv->convert($B, true);
	check('JavaFX format skipped', $r['status'] === 'skipped' && $r['reason'] === 'javafx', json_encode($r));
	rename($keep, "$FILES/$B/project.zip");

	// Differing duplicate: the same micrograph twice with different content.
	$orig = $ms->val("SELECT projectjson FROM strabomicro.micro_projectmetadata WHERE id = $1", array($B));
	$j = json_decode($orig);
	$m = clone $j->datasets[0]->samples[0]->micrographs[0];
	$m->name = 'changed copy';
	$j->datasets[0]->samples[0]->micrographs[] = $m;
	$ms->q("UPDATE strabomicro.micro_projectmetadata SET projectjson = $2 WHERE id = $1", array($B, json_encode($j)));
	$r = $conv->convert($B, true);
	check('differing duplicate stops', $r['status'] === 'failed' && $r['reason'] === 'duplicate_differs', json_encode($r));
	$m->name = $j->datasets[0]->samples[0]->micrographs[0]->name;
	$ms->q("UPDATE strabomicro.micro_projectmetadata SET projectjson = $2 WHERE id = $1", array($B, json_encode($j)));
	$r = $conv->convert($B, false);
	check('identical duplicate collapses (dry run passes, warning)', $r['status'] === 'would_convert' && count($r['warnings']) > 0, json_encode($r));
	$ms->q("UPDATE strabomicro.micro_projectmetadata SET projectjson = $2 WHERE id = $1", array($B, $orig));
	check('#B untouched by the stops', isLegacyClean($B, $fixtures[747]['md5']));
} catch (Exception $e) {
	check('no exception', false, $e->getMessage());
} finally {
	foreach ($pids as $pid) {
		if ($pid === null) continue;
		if (prow($pid)['sync_format'] === 'entity') $conv->revert($pid);
		$sid = $ms->val("SELECT strabo_id FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		delete_synced($db, $U, $sid);
		$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		if (is_dir("$FILES/$pid")) exec('rm -rf ' . escapeshellarg("$FILES/$pid"));
		if (is_dir(MsConvert::archiveDir($pid))) exec('rm -rf ' . escapeshellarg(MsConvert::archiveDir($pid)));
	}
	$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($U));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($U));
	exec('rm -rf ' . escapeshellarg($W));
}

echo "\n" . ($failures ? count($failures) . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
