<?php
/**
 * File: restore_images_test.php
 * Description: microsync/tools/restore_archived_images.php, on the incident
 *              it was written for (StraboMicro Sentry ELECTRON-39, project
 *              950): a legacy upload WITH images is adopted by a copy that
 *              pushes its entities but no images, so the live project has no
 *              image refs and the images sit only in _archive/<id>/project.zip.
 *                - dry run: reports every micrograph, writes nothing
 *                - apply: image, thumbnail and tiles refs from the archive,
 *                  blobs byte-identical to the archived files, change rows
 *                  with no push id (every copy pulls them), head moved, the
 *                  pull endpoint lists them, the worker builds images/
 *                - a micrograph whose size changed since is skipped
 *                - refs a micrograph already has are never touched; a rerun
 *                  restores only what is still missing
 *                - a legacy project is refused
 *
 *              Usage: docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/restore_images_test.php
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
$FILES = '/srv/app/www/straboMicroFiles';
$TOOL = '/srv/app/www/microsync/tools/restore_archived_images.php';
$RUN = substr(md5(uniqid('', true)), 0, 6);
$W = "/tmp/microsync_restore_test_$RUN";
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

/** Run the tool; returns array(exit code, output, report). */
function tool($pid, $apply) {
	global $TOOL;
	$out = array();
	exec('php ' . escapeshellarg($TOOL) . ' --only=' . (int)$pid . ($apply ? ' --apply' : '') . ' 2>&1', $out, $rc);
	$report = null;
	foreach ($out as $l) {
		if (preg_match('/report: (\S+)$/', $l, $m) && is_file($m[1])) {
			$report = json_decode(file_get_contents($m[1]), true);
			@unlink($m[1]);
		}
	}
	return array($rc, implode("\n", $out), $report);
}
function refCount($pid, $role) {
	global $ms;
	return (int)$ms->val("SELECT count(*) FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND role = $2", array($pid, $role));
}
function action($report, $mid) {
	foreach ($report['micrographs'] as $m) if ($m['id'] === $mid) return $m['action'];
	return null;
}

$db->get_var('SELECT 1');
$email = "microsync-restore-$RUN@test.strabospot.org";
$db->get_var_prepared("INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Restore', 'Test', $1, 'x', 'x', true)", array($email));
$U = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($email));
$TOK = token($U);
$conv = new MsConvert($db);
$P = null;

try {
	section('The incident: an upload with images, adopted by a copy without them');
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
	check('adopt', req('POST', "/projects/$P/adopt", $TOK)['code'] === 201);
	$j = normalize_fixture(json_decode(file_get_contents("$R/project.json")), $sid);
	check('entities pushed (no images)', count(push_all($P, $TOK, decompose($j))) === 0);
	check('ready', req('POST', "/projects/$P/ready", $TOK)['code'] === 200);
	check('archived with images', is_file(MsAdopt::archiveDir($P) . '/project.zip'));
	check('built', build_now($P) === 'built');
	$withImage = array();
	$withTiles = array();
	$withThumb = array();
	$sizes = array();
	foreach ($j->datasets as $d) foreach ($d->samples as $s) foreach ($s->micrographs as $m) {
		if (is_file("$R/images/{$m->id}")) {
			$withImage[] = $m->id;
			if (is_dir("$R/tiles/{$m->id}")) $withTiles[] = $m->id;
			if (is_file("$R/compositeThumbnails/{$m->id}")) $withThumb[] = $m->id;
			$sizes[$m->id] = @getimagesize("$R/images/{$m->id}");
		}
	}
	$sizeOk = count($withImage) > 1;
	foreach ($withImage as $id) {
		$m = $ms->row("SELECT body->>'width' AS w, body->>'height' AS h FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'micrograph' AND entity_id = $2", array($P, $id));
		$sizeOk = $sizeOk && $sizes[$id] !== false && (int)$m['w'] === (int)$sizes[$id][0] && (int)$m['h'] === (int)$sizes[$id][1];
	}
	check('fixture: 2+ micrographs with images, stored sizes match the files', $sizeOk, json_encode(array($withImage, $sizes)));
	check('the live project has no image, tiles or thumbnail refs', refCount($P, 'image') === 0 && refCount($P, 'tiles') === 0 && refCount($P, 'thumbnail') === 0);
	$headBefore = (int)$ms->val("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));
	$root = "$FILES/$P";

	section('Dry run');
	list($rc, $out, $rep) = tool($P, false);
	check('exit 0, status ok', $rc === 0 && $rep['status'] === 'ok', $out);
	check('every micrograph with an archived image is planned', $rep['restored'] === count($withImage)
		&& action($rep, $withImage[0]) === 'restore', $out);
	check('tiles and thumbnail planned where the archive has them',
		in_array('tiles', $rep['micrographs'][array_search($withTiles[0], array_column($rep['micrographs'], 'id'))]['roles'], true), $out);
	check('nothing written: no refs, no blobs, head unchanged', refCount($P, 'image') === 0
		&& (int)$ms->val("SELECT count(*) FROM strabomicro.micro_blobs WHERE project_id = $1", array($P)) === 0
		&& (int)$ms->val("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P)) === $headBefore);
	check('no work folder left', count(glob("$root/.restore-work-*")) === 0);

	section('Apply, one micrograph changed size since');
	$changed = $withImage[1];
	$ms->q("UPDATE strabomicro.micro_entities SET body = jsonb_set(body::jsonb, '{width}', to_jsonb((body->>'width')::int + 10))::json
		WHERE project_id = $1 AND entity_type = 'micrograph' AND entity_id = $2", array($P, $changed));
	list($rc, $out, $rep) = tool($P, true);
	check('exit 0, status ok', $rc === 0 && $rep['status'] === 'ok', $out);
	check('the changed micrograph is skipped with the reason', strpos((string)action($rep, $changed), 'skip: size changed since the archive') === 0, $out);
	check('the others are restored', $rep['restored'] === count($withImage) - 1, $out);
	check('image refs set for them', refCount($P, 'image') === count($withImage) - 1);
	$same = true;
	foreach ($withImage as $id) {
		if ($id === $changed) continue;
		$sha = $ms->val("SELECT sha256 FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND entity_id = $2 AND role = 'image'", array($P, $id));
		$same = $same && $sha === hash_file('sha256', "$R/images/$id") && hash_file('sha256', MsStore::blobPath($P, $sha)) === $sha;
	}
	check('each image blob is the archived file, byte for byte', $same);
	check('tiles and thumbnails referenced', refCount($P, 'tiles') === count(array_diff($withTiles, array($changed)))
		&& refCount($P, 'thumbnail') === count(array_diff($withThumb, array($changed))));
	$rows = $ms->rows("SELECT seq, push_id FROM strabomicro.micro_changes WHERE project_id = $1 AND seq > $2 ORDER BY seq", array($P, $headBefore));
	check('one change per ref, no push id (every copy pulls them)', count($rows) === $rep['refsSet']
		&& count(array_filter($rows, function ($r) { return $r['push_id'] !== null; })) === 0, json_encode($rows));
	$head = (int)$ms->val("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));
	check('head moved to the last change', $head === (int)$rows[count($rows) - 1]['seq']);
	$r = req('GET', "/projects/$P/changes?since=$headBefore", $TOK);
	$imageRefs = 0;
	foreach ($r['body']['changes'] ?: array() as $c) {
		if (in_array('refs.image', $c['changedPaths'] ?: array(), true)) $imageRefs++;
	}
	check('the pull endpoint lists the image changes', $r['code'] === 200 && $imageRefs === count($withImage) - 1, substr($r['raw'], 0, 600));
	check('no work folder left', count(glob("$root/.restore-work-*")) === 0);
	check('worker builds it', build_now($P) === 'built');
	check('images/ laid out for the web viewer', is_file("$root/images/{$withImage[0]}") && !is_file("$root/images/$changed"));

	section('Rerun after the size is put right');
	$ms->q("UPDATE strabomicro.micro_entities SET body = jsonb_set(body::jsonb, '{width}', to_jsonb((body->>'width')::int - 10))::json
		WHERE project_id = $1 AND entity_type = 'micrograph' AND entity_id = $2", array($P, $changed));
	$shaFirst = $ms->val("SELECT sha256 FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND entity_id = $2 AND role = 'image'", array($P, $withImage[0]));
	list($rc, $out, $rep) = tool($P, true);
	check('only the skipped one is restored now', $rc === 0 && $rep['restored'] === 1 && action($rep, $changed) === 'restore'
		&& action($rep, $withImage[0]) === 'skip: already has an image', $out);
	check('every micrograph has its image', refCount($P, 'image') === count($withImage));
	check('existing refs untouched', $ms->val("SELECT sha256 FROM strabomicro.micro_blob_refs WHERE project_id = $1 AND entity_id = $2 AND role = 'image'", array($P, $withImage[0])) === $shaFirst);
	list($rc, $out, $rep) = tool($P, true);
	check('a third run has nothing to do', $rc === 0 && $rep['restored'] === 0 && empty($rep['refsSet']), $out);

	section('Refusals');
	$legacyId = (int)$ms->val("SELECT id FROM strabomicro.micro_projectmetadata WHERE sync_format = 'legacy' ORDER BY id LIMIT 1");
	list($rc, $out, $rep) = tool($legacyId, true);
	check("a legacy project (#$legacyId) is refused", $legacyId > 0 && $rc === 1 && $rep['status'] === 'stopped'
		&& strpos($rep['reason'], 'not a ready synced project') !== false, $out);
	list($rc, $out, $rep) = tool(786, true);
	check('a synced project with no archive is refused', $rc === 1 && $rep['status'] === 'stopped'
		&& strpos($rep['reason'], 'cannot open') === 0, $out);
	exec('php ' . escapeshellarg($TOOL) . ' --apply 2>&1', $o, $rc2);
	check('no --only: usage, exit 2', $rc2 === 2);
} catch (Exception $e) {
	check('no exception', false, $e->getMessage());
} finally {
	if ($P !== null) {
		if ($ms->val("SELECT sync_state FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P)) === 'adopting') MsAdopt::drop($ms, $P);
		delete_synced($db, $U, $sid);
		$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));
		if (is_dir("$FILES/$P")) exec('rm -rf ' . escapeshellarg("$FILES/$P"));
		if (is_dir(MsAdopt::archiveDir($P))) exec('rm -rf ' . escapeshellarg(MsAdopt::archiveDir($P)));
	}
	$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($U));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($U));
	exec('rm -rf ' . escapeshellarg($W));
}

echo "\n" . ($failures ? count($failures) . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
