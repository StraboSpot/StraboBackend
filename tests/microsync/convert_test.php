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
	$again = $conv->convert($A, true);
	check('second apply skips (already_entity)', $again['status'] === 'skipped' && $again['reason'] === 'already_entity');
	$legacyUp = legacy('jwt', 'upload', $U, MsConvert::archiveDir($A) . '/project.zip', $fixtures[786]['sid']);
	check('legacy upload to the converted project is refused (P0-9 not built yet)', pid_of($db, $U, $fixtures[786]['sid']) === $A && prow($A)['sync_format'] === 'entity', json_encode($legacyUp));

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
		legacy('jwt', 'delete', $U, '-', $sid);
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
