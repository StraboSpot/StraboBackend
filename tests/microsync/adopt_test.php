<?php
/**
 * File: adopt_test.php
 * Description: P1-1 adoption of a legacy project row into the sync store
 *              (microsync/lib/MsAdopt.php). A throwaway user uploads the dev
 *              fixture 786 through the legacy path, the folder gets JavaFX-style
 *              leftovers (webImages/, webThumbnails/), then:
 *                - POST projects answers 409 with syncFormat/syncState
 *                - only the owner may adopt; adopt is resumable
 *                - while adopting, the project is legacy for every reader
 *                  (visible in lists, static project.zip served, download size)
 *                  and old-app uploads (jwtmicrodb and microdb) are refused;
 *                  the batch converter skips it
 *                - the app's initial upload (real entities, images, tiles)
 *                  then ready: project.zip + leftovers archived with a journal,
 *                  entity/ready, worker builds, streamed download, tiles/
 *                - converter revert puts zip and leftovers back
 *                - cancel removes every store row, upload and blob; the
 *                  legacy project is byte-identical
 *                - ready refuses (and moves nothing) when an old app replaced
 *                  project.zip since the adoption started
 *                - the sweep drops adoptions idle for 7 days, not fresh ones
 *
 *              Usage: docker exec strabo-php php /srv/app/www/tests/microsync/adopt_test.php
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
require_once '/srv/app/www/microsync/lib/MsAdopt.php';
require_once '/srv/app/www/microdb/lib/sync_guard.php';
$FILES = '/srv/app/www/straboMicroFiles';
$HOST = 'http://localhost';
$RUN = substr(md5(uniqid('', true)), 0, 6);
$W = "/tmp/microsync_adopt_test_$RUN";
@mkdir("$W/src", 0777, true);
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
		(SELECT count(*) FROM strabomicro.micro_changes c WHERE c.project_id = p.id) AS changes,
		(SELECT count(*) FROM strabomicro.micro_uploads u WHERE u.project_id = p.id) AS uploads,
		(SELECT count(*) FROM strabomicro.micro_members m WHERE m.project_id = p.id) AS members
		FROM strabomicro.micro_projectmetadata p WHERE id = $1", array($pid));
}
/** Plain legacy: no store rows, zip unchanged, no blobs folder, no adopt record. */
function isPlainLegacy($pid, $zipMd5) {
	global $FILES;
	$r = prow($pid);
	return $r['sync_format'] === 'legacy' && $r['sync_state'] === 'ready' && (int)$r['entities'] === 0 && (int)$r['blobs'] === 0
		&& (int)$r['changes'] === 0 && (int)$r['uploads'] === 0 && (int)$r['members'] === 0
		&& is_file("$FILES/$pid/project.zip") && md5_file("$FILES/$pid/project.zip") === $zipMd5
		&& !is_dir("$FILES/$pid/blobs") && !is_file(MsAdopt::archiveDir($pid) . '/adopt.json');
}

$db->get_var('SELECT 1');
$mk = function ($tag) use ($db, $RUN) {
	$email = "microsync-adopt-$tag-$RUN@test.strabospot.org";
	$db->get_var_prepared("INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Adopt', 'Test', $1, 'x', 'x', true)", array($email));
	return (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($email));
};
$U = $mk('owner');
$O = $mk('other');
$TOK = token($U);
$conv = new MsConvert($db);
$P = null;

try {
	section('Fixture');
	$zip = "$FILES/786/project.zip";
	if (!check('fixture 786 has project.zip', is_file($zip))) throw new Exception('fixture missing');
	$z = new ZipArchive();
	$z->open($zip);
	$sid = substr($z->getNameIndex(0), 0, strpos($z->getNameIndex(0), '/'));
	$z->extractTo("$W/src");
	$z->close();
	$R = "$W/src/$sid";
	legacy('jwt', 'upload', $U, $zip, $sid);
	$P = pid_of($db, $U, $sid);
	check('legacy upload', $P !== null);
	$root = "$FILES/$P";
	@mkdir("$root/webImages", 0775, true);
	@mkdir("$root/webThumbnails", 0775, true);
	file_put_contents("$root/webImages/a.jpg", 'web image');
	file_put_contents("$root/webThumbnails/a.jpg", 'web thumb');
	$md5 = md5_file("$root/project.zip");
	$zipBytes = filesize("$root/project.zip");
	check('starts as plain legacy', isPlainLegacy($P, $md5));

	section('Start');
	$r = req('POST', '/projects', $TOK, array('straboId' => $sid, 'name' => 'x'));
	check('POST projects -> 409 exists, legacy, ready', $r['code'] === 409 && $r['body']['pid'] === $P
		&& $r['body']['syncFormat'] === 'legacy' && $r['body']['syncState'] === 'ready', $r['raw']);
	check('another user cannot adopt it', req('POST', "/projects/$P/adopt", token($O))['code'] === 404);
	check('adopting an unknown project -> 404', req('POST', '/projects/999999999/adopt', $TOK)['code'] === 404);
	$r = req('POST', "/projects/$P/adopt", $TOK);
	check('adopt -> 201 adopting', $r['code'] === 201 && $r['body']['syncState'] === 'adopting' && $r['body']['straboId'] === $sid, $r['raw']);
	$p = prow($P);
	check('row stays legacy, state adopting, owner member', $p['sync_format'] === 'legacy' && $p['sync_state'] === 'adopting' && (int)$p['members'] === 1);
	$rec = json_decode((string)@file_get_contents(MsAdopt::archiveDir($P) . '/adopt.json'), true);
	check('adopt.json records the zip', is_array($rec) && $rec['zip']['bytes'] === $zipBytes);
	$r = req('POST', "/projects/$P/adopt", $TOK);
	check('adopt again resumes (200)', $r['code'] === 200 && $r['body']['resumed'] === true, $r['raw']);
	$r = req('POST', '/projects', $TOK, array('straboId' => $sid, 'name' => 'x'));
	check('POST projects now says adopting', $r['code'] === 409 && $r['body']['syncState'] === 'adopting', $r['raw']);
	$mine = array_values(array_filter((req('GET', '/projects', $TOK)['body'] ?: array()), function ($x) use ($P) { return $x['pid'] === $P; }));
	check('listed in my sync projects as adopting', count($mine) === 1 && $mine[0]['syncState'] === 'adopting', json_encode($mine));

	section('Legacy readers while adopting');
	$visible = $db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE id = $1 AND " . micro_sync_visible_sql(), array($P));
	check('visible to legacy lists', (int)$visible === 1);
	$dl = http_req('GET', "$HOST/straboMicroFiles/$P/project.zip");
	check('static project.zip served unchanged', $dl['code'] === 200 && md5($dl['raw']) === $md5);
	check('download size is the zip', micro_sync_download_bytes($db, $P) === $zipBytes);
	$res = legacy('jwt', 'upload', $U, $zip, $sid);
	check('old-app upload (jwtmicrodb) refused', isset($res['Error']) && strpos($res['Error'], 'being set up for sync') !== false, json_encode($res));
	$res = legacy('microdb', 'upload', $U, $zip, $sid);
	check('old-app upload (microdb) refused', isset($res['Error']) && strpos($res['Error'], 'being set up for sync') !== false, json_encode($res));
	check('zip untouched by the refused uploads', md5_file("$root/project.zip") === $md5);
	$cr = $conv->convert($P, false);
	check('batch converter skips it', $cr['status'] === 'skipped' && $cr['reason'] === 'adopting', json_encode($cr));

	section('Initial upload and ready');
	check('ready before the project entity -> 409', req('POST', "/projects/$P/ready", $TOK)['code'] === 409);
	$j = normalize_fixture(json_decode(file_get_contents("$R/project.json")), $sid);
	$bad = push_all($P, $TOK, decompose($j));
	check('every entity accepted', count($bad) === 0, json_encode(array_slice($bad, 0, 3)));
	$zipDir = function ($dir) use ($W) {
		$f = "$W/t-" . uniqid() . '.zip';
		$zz = new ZipArchive();
		$zz->open($f, ZipArchive::CREATE);
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			$name = substr($file->getPathname(), strlen($dir) + 1);
			$zz->addFile($file->getPathname(), $name);
			$zz->setCompressionName($name, ZipArchive::CM_STORE);
		}
		$zz->close();
		return $f;
	};
	$refsOk = true;
	$tileMids = array();
	foreach ($j->datasets as $d) foreach ($d->samples as $s) foreach ($s->micrographs as $m) {
		if (is_file("$R/images/{$m->id}")) $refsOk = $refsOk && set_ref($P, $TOK, 'micrograph', $m->id, 'image', upload_file($P, $TOK, "$R/images/{$m->id}", 'image'))['code'] === 200;
		if (is_dir("$R/tiles/{$m->id}")) {
			$tf = $zipDir("$R/tiles/{$m->id}");
			$refsOk = $refsOk && set_ref($P, $TOK, 'micrograph', $m->id, 'tiles', upload_file($P, $TOK, $tf, 'tiles'))['code'] === 200;
			$tileMids[] = $m->id;
		}
	}
	check('images and tiles uploaded and referenced', $refsOk && count($tileMids) > 0);
	check('still legacy after the upload (nothing built, zip in place)', prow($P)['sync_format'] === 'legacy' && md5_file("$root/project.zip") === $md5);
	$headBefore = (int)prow($P)['head_seq'];
	$r = req('POST', "/projects/$P/ready", $TOK);
	check('ready -> 200 adopted', $r['code'] === 200 && $r['body']['adopted'] === true && $r['body']['syncState'] === 'ready', $r['raw']);
	$p = prow($P);
	check('row is entity/ready, head kept', $p['sync_format'] === 'entity' && $p['sync_state'] === 'ready' && (int)$p['head_seq'] === $headBefore);
	$arch = MsAdopt::archiveDir($P);
	$jr = json_decode((string)@file_get_contents("$arch/journal.json"), true);
	check('zip and leftovers archived, journal lists them', !is_file("$root/project.zip") && md5_file("$arch/project.zip") === $md5
		&& is_file("$arch/webImages/a.jpg") && is_file("$arch/webThumbnails/a.jpg") && !is_dir("$root/webImages") && !is_dir("$root/webThumbnails")
		&& is_array($jr) && in_array('webImages', $jr['moved'], true) && $jr['headSeq'] === $headBefore && !is_file("$arch/adopt.json"), json_encode($jr));
	$built = build_now($P);
	check("worker builds it ($built)", $built === 'built' && prow($P)['built'] === 't');
	check('tiles/ laid out (landing page routes to /microview/)', is_dir("$root/tiles/{$tileMids[0]}") && is_file("$root/tiles/{$tileMids[0]}/.blob"));
	$dl = http_req('GET', "$HOST/straboMicroFiles/$P/project.zip");
	check('download is now the streamed archive', $dl['code'] === 200 && strlen($dl['raw']) > 1000 && md5($dl['raw']) !== $md5);
	check('_archive never served', http_req('GET', "$HOST/straboMicroFiles/_archive/$P/project.zip")['code'] === 403);
	check('adopt on a synced project -> 409', req('POST', "/projects/$P/adopt", $TOK)['code'] === 409);

	section('Revert puts everything back');
	$why = $conv->revert($P);
	check('converter revert works for an adopted project', $why === null, (string)$why);
	check('zip and leftovers back in the folder', isPlainLegacy($P, $md5) && is_file("$root/webImages/a.jpg") && is_file("$root/webThumbnails/a.jpg") && !is_dir($arch));

	section('Cancel');
	check('adopt again', req('POST', "/projects/$P/adopt", $TOK)['code'] === 201);
	$bad = push_all($P, $TOK, array_slice(decompose($j), 0, 3));
	$sha = upload_file($P, $TOK, "$R/project.json", 'image');
	$st = req('POST', "/projects/$P/uploads", $TOK, array('sha256' => hash('sha256', 'pending'), 'size' => 7, 'kind' => 'image'));
	$p = prow($P);
	check('partial store present (entities, blob, pending upload)', (int)$p['entities'] > 0 && (int)$p['blobs'] > 0 && (int)$p['uploads'] > 0 && is_dir("$root/blobs"), json_encode($p));
	check('another user cannot cancel', req('DELETE', "/projects/$P/adopt", token($O))['code'] === 404);
	$r = req('DELETE', "/projects/$P/adopt", $TOK);
	check('cancel -> 200 legacy', $r['code'] === 200 && $r['body']['syncFormat'] === 'legacy', $r['raw']);
	check('every store row, upload and blob gone; legacy byte-identical', isPlainLegacy($P, $md5) && is_dir("$root/webImages"));
	check('cancel again -> 404 (no longer a sync project)', req('DELETE', "/projects/$P/adopt", $TOK)['code'] === 404);

	section('Ready refuses when an old app changed the project');
	check('adopt again', req('POST', "/projects/$P/adopt", $TOK)['code'] === 201);
	push_all($P, $TOK, decompose($j));
	touch("$root/project.zip", time() + 120);
	$r = req('POST', "/projects/$P/ready", $TOK);
	check('ready -> 409 legacy_changed', $r['code'] === 409 && $r['body']['error'] === 'legacy_changed', $r['raw']);
	check('nothing moved, still adopting', is_file("$root/project.zip") && is_dir("$root/webImages") && !is_file(MsAdopt::archiveDir($P) . '/project.zip')
		&& prow($P)['sync_state'] === 'adopting');
	check('cancel after the refusal', req('DELETE', "/projects/$P/adopt", $TOK)['code'] === 200 && isPlainLegacy($P, $md5));

	section('Idle adoptions expire after 7 days');
	check('adopt again', req('POST', "/projects/$P/adopt", $TOK)['code'] === 201);
	push_all($P, $TOK, array_slice(decompose($j), 0, 2));
	check('a fresh adoption is kept', !in_array($P, MsAdopt::expireIdle($ms), true) && prow($P)['sync_state'] === 'adopting');
	$ms->q("UPDATE strabomicro.micro_members SET responded_at = now() - interval '8 days' WHERE project_id = $1", array($P));
	$ms->q("UPDATE strabomicro.micro_changes SET at = now() - interval '8 days' WHERE project_id = $1", array($P));
	$ms->q("UPDATE strabomicro.micro_pushes SET at = now() - interval '8 days' WHERE project_id = $1", array($P));
	check('activity 6 days ago still keeps it', (function () use ($ms, $P) {
		$ms->q("UPDATE strabomicro.micro_changes SET at = now() - interval '6 days' WHERE seq = (SELECT max(seq) FROM strabomicro.micro_changes WHERE project_id = $1)", array($P));
		$kept = !in_array($P, MsAdopt::expireIdle($ms), true);
		$ms->q("UPDATE strabomicro.micro_changes SET at = now() - interval '8 days' WHERE project_id = $1", array($P));
		return $kept;
	})());
	check('idle for 8 days: dropped, back to plain legacy', in_array($P, MsAdopt::expireIdle($ms), true) && isPlainLegacy($P, $md5));
	$res = legacy('jwt', 'upload', $U, $zip, $sid);
	check('old-app uploads work again', !isset($res['Error']), json_encode($res));
} catch (Exception $e) {
	check('no exception', false, $e->getMessage());
} finally {
	if ($P !== null) {
		if ($ms->val("SELECT sync_state FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P)) === 'adopting') MsAdopt::drop($ms, $P);
		if ($ms->val("SELECT sync_format FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P)) === 'entity') $conv->revert($P);
		legacy('jwt', 'delete', $U, '-', $sid);
		$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));
		if (is_dir("$FILES/$P")) exec('rm -rf ' . escapeshellarg("$FILES/$P"));
		if (is_dir(MsAdopt::archiveDir($P))) exec('rm -rf ' . escapeshellarg(MsAdopt::archiveDir($P)));
	}
	foreach (array($U, $O) as $u) {
		$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($u));
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u));
	}
	exec('rm -rf ' . escapeshellarg($W));
}

echo "\n" . ($failures ? count($failures) . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
