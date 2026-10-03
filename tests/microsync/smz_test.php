<?php
/**
 * File: smz_test.php
 * Description: Tests for the streamed .smz of synced StraboMicro projects
 *              (rollout step 3, microsync/lib/MsSmz.php).
 *
 *              A. Every download door serves the same archive: the static
 *                 straboMicroFiles/<id>/project.zip URL (root .htaccess
 *                 fallback, what getProjectURL / getSharedURL / share codes
 *                 hand the app), download_micro_file.php (website, viewer,
 *                 deep links) and GET /microsync/v1/projects/{pid}/smz.
 *                 Content-Length is exact and equals the bytes the legacy
 *                 APIs report (projectURL, sharedURL, myProjects).
 *              B. Contents equal the entity store: project.json (with the
 *                 StraboSamples overlay), images, thumbnails, attachments,
 *                 tile archive entries copied raw (method and CRC kept),
 *                 point counts; every CRC verified by libzip.
 *              C. ZIP64: forced records on every entry, and a real archive
 *                 with more than 65,535 entries.
 *              D. Edges: a missing blob is left out and the archive stays
 *                 valid; unfinished and unknown projects 404; legacy
 *                 projects keep their static file; the archive is the same
 *                 bytes for the same state.
 *
 *              Archives are also written to microsync_data/smz_test/ (bind
 *              mounted, gitignored) for the host checks with Python zipfile
 *              and the app's own unzipper.
 *
 *              Usage (MICROSYNC_ENABLED true):
 *                docker exec strabo-php php /srv/app/www/tests/microsync/smz_test.php
 *
 *              Hermetic: throwaway users (removed at the end), mstest-z
 *              straboIds. Exits non-zero on any failure.
 */

set_time_limit(0);
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/microsync/lib/MsSmz.php';
require_once '/srv/app/www/microdb/lib/sync_guard.php';
require_once '/srv/app/www/tests/lib/microsync_client.php';

$HOST = 'http://localhost';
$BASE = "$HOST/microsync/v1";
$FILES = '/srv/app/www/straboMicroFiles';
$OUTDIR = '/srv/app/www/microsync_data/smz_test';
$W = '/tmp/microsync_smz_test';
@mkdir($W, 0777, true);
exec('rm -rf ' . escapeshellarg($OUTDIR));
@mkdir($OUTDIR, 0777, true);
$RUN = substr(md5(uniqid('', true)), 0, 6);
$PREFIX = 'mstest-z' . $RUN . '-';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr($detail, 0, 1500) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function section($name) { echo "\n== $name\n"; }

function legacy_delete($user, $sid) {
	global $db;
	delete_synced($db, $user, $sid);
}

/** A ZIP built by libzip; $entries name => [content, 'store'|'deflate']. */
function make_archive($entries) {
	global $W;
	$f = "$W/a-" . uniqid() . '.zip';
	$za = new ZipArchive();
	$za->open($f, ZipArchive::CREATE);
	foreach ($entries as $name => $e) {
		$za->addFromString($name, $e[0]);
		$za->setCompressionName($name, $e[1] === 'store' ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
	}
	$za->close();
	$b = file_get_contents($f);
	unlink($f);
	return $b;
}

/**
 * Open an archive with libzip and verify it: every entry's CRC checked
 * (by reading it), names => [content, method]. Returns [entries, error].
 */
function read_archive($path) {
	$za = new ZipArchive();
	$rc = $za->open($path, ZipArchive::CHECKCONS);
	if ($rc !== true) return array(null, "open failed ($rc)");
	$out = array();
	for ($i = 0; $i < $za->numFiles; $i++) {
		$st = $za->statIndex($i);
		$data = $za->getFromIndex($i);
		if ($data === false || strlen($data) !== $st['size'] || sprintf('%u', crc32($data)) !== sprintf('%u', $st['crc'])) {
			$za->close();
			return array(null, "entry {$st['name']} fails its size or CRC");
		}
		$out[$st['name']] = array($data, $st['comp_method']);
	}
	$za->close();
	return array($out, null);
}

function save($name, $bytes) {
	global $OUTDIR;
	file_put_contents("$OUTDIR/$name", $bytes);
	return "$OUTDIR/$name";
}

// ---------------------------------------------------------------------------
$db->get_var('SELECT 1');
$users = array();
foreach (array('owner', 'member', 'outsider') as $who) {
	$email = "microsync-smz-$who-$RUN@test.strabospot.org";
	$pk = (int)$db->get_var_prepared(
		"INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Smz', $1, $2, 'x', 'x', true) RETURNING pkey",
		array(ucfirst($who), $email));
	if ($pk <= 0) {
		$pk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($email));
	}
	if ($pk <= 0) { fwrite(STDERR, "could not create test user\n"); exit(2); }
	$users[$who] = array('pkey' => $pk, 'tok' => token($pk));
}
$U = $users['owner']['pkey'];
$TOK = $users['owner']['tok'];
$pids = array();
$sids = array();

try {
	// -----------------------------------------------------------------------
	section('Setup: a synced project with every kind of file');
	$sid = $PREFIX . 'main';
	$r = req('POST', '/projects', $TOK, array('straboId' => $sid, 'name' => 'Smz Test µ'));
	$P = (int)$r['body']['pid'];
	$pids[] = $P;
	$sids[] = $sid;
	$bad = push_all($P, $TOK, array(
		array('op' => 'create', 'type' => 'project', 'id' => $sid, 'body' => array('name' => 'Smz Test µ')),
		array('op' => 'create', 'type' => 'dataset', 'id' => 'zD', 'parentType' => 'project', 'parentId' => $sid, 'body' => array('name' => 'D')),
		array('op' => 'create', 'type' => 'sample', 'id' => 'zS', 'parentType' => 'dataset', 'parentId' => 'zD',
			'body' => array('name' => 'Quartz 10° Mezőmadaras', 'label' => 'Quartz 10° Mezőmadaras', 'sampleID' => 'Quartz 10° Mezőmadaras')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'zM1', 'parentType' => 'sample', 'parentId' => 'zS', 'body' => array('name' => 'M1')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'zM2', 'parentType' => 'sample', 'parentId' => 'zS', 'body' => array('name' => 'M2', 'parentID' => 'zM1')),
		array('op' => 'create', 'type' => 'spot', 'id' => 'zP1', 'parentType' => 'micrograph', 'parentId' => 'zM1', 'body' => array('name' => 'P1')),
		array('op' => 'create', 'type' => 'point_count', 'id' => 'zPC1', 'parentType' => 'micrograph', 'parentId' => 'zM1',
			'body' => array('name' => 'PC', 'points' => array(array('x' => 1, 'y' => 2)))),
	));
	check('project pushed', !$bad, json_encode($bad));
	$img1 = random_bytes(300000);
	$img2 = random_bytes(1000);
	$thumb = random_bytes(700);
	$att = "attachment µ\n";
	$empty = '';
	$tilesEntries = array(
		'metadata.json' => array('{"width":256,"height":256}', 'deflate'),
		'thumbnail.jpg' => array(random_bytes(400), 'store'),
		'medium.jpg' => array(str_repeat('compressible ', 500), 'deflate'),
		'tiles/tile_0_0.webp' => array('w00', 'store'),
		'tiles/tile_1_0.webp' => array(str_repeat('w', 5000), 'deflate'),
		'tiles/tile_2_0.webp' => array('', 'store'), // zero-length entry
	);
	$tiles = make_archive($tilesEntries);
	$affine = make_archive(array('metadata.json' => array('{"affine":true}', 'store')));
	$shaImg1 = upload_blob($P, $TOK, $img1, 'image');
	$shaImg2 = upload_blob($P, $TOK, $img2, 'image');
	$shaThumb = upload_blob($P, $TOK, $thumb, 'thumbnail');
	$shaAtt = upload_blob($P, $TOK, $att, 'associated_file');
	$shaEmpty = upload_blob($P, $TOK, $empty, 'associated_file');
	$shaTiles = upload_blob($P, $TOK, $tiles, 'tiles');
	$shaAff = upload_blob($P, $TOK, $affine, 'tiles_affine');
	check('upload completion cached the CRC next to the blob',
		trim((string)@file_get_contents("$FILES/$P/blobs/$shaImg1.crc32")) === hash('crc32b', $img1));
	set_ref($P, $TOK, 'micrograph', 'zM1', 'image', $shaImg1);
	set_ref($P, $TOK, 'micrograph', 'zM2', 'image', $shaImg2);
	set_ref($P, $TOK, 'micrograph', 'zM1', 'thumbnail', $shaThumb);
	set_ref($P, $TOK, 'micrograph', 'zM1', 'tiles', $shaTiles);
	set_ref($P, $TOK, 'micrograph', 'zM2', 'tiles_affine', $shaAff);
	set_ref($P, $TOK, 'spot', 'zP1', 'associated_file:notes µ.txt', $shaAtt);
	set_ref($P, $TOK, 'micrograph', 'zM2', 'associated_file:empty.csv', $shaEmpty);

	section('D1. Unfinished project (initial upload not done) is not downloadable');
	check('static URL 404 before ready', http_req('GET', "$HOST/straboMicroFiles/$P/project.zip")['code'] === 404);
	check('v1 smz 409 before ready', req('GET', "/projects/$P/smz", $TOK)['code'] === 409);
	check('size helper 0 before ready', micro_sync_download_bytes($db, $P) === 0);

	req('POST', "/projects/$P/ready", $TOK);
	check('worker built it', build_now($P) === 'built');
	$sharekey = $db->get_var_prepared("SELECT sharekey FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));

	// -----------------------------------------------------------------------
	section('A. Every door serves the same archive');
	$static = http_req('GET', "$HOST/straboMicroFiles/$P/project.zip");
	check('static project.zip URL -> 200 application/zip', $static['code'] === 200
		&& strpos($static['headers']['content-type'] ?? '', 'application/zip') === 0, $static['code'] . ' ' . substr($static['raw'], 0, 200));
	check('Content-Length exact', (int)($static['headers']['content-length'] ?? -1) === strlen($static['raw']));
	check('no Content-Encoding', !isset($static['headers']['content-encoding']));
	$len = strlen($static['raw']);
	$dl = http_req('GET', "$HOST/download_micro_file?project_id=$P");
	check('download_micro_file -> same bytes, .smz file name', $dl['code'] === 200 && $dl['raw'] === $static['raw']
		&& strpos($dl['headers']['content-disposition'] ?? '', 'smz_test.smz') !== false, $dl['headers']['content-disposition'] ?? '');
	$v1 = req('GET', "/projects/$P/smz", $TOK);
	check('v1 smz (owner) -> same bytes', $v1['code'] === 200 && $v1['raw'] === $static['raw']);
	check('v1 smz outsider -> 404', req('GET', "/projects/$P/smz", $users['outsider']['tok'])['code'] === 404);
	check('size helper = streamed length', micro_sync_download_bytes($db, $P) === $len && MsSmz::length($db, $P) === $len);

	$pu = http_req('GET', "$HOST/jwtmicrodb/projectURL/$sid", $TOK);
	check('projectURL: static URL and exact bytes', $pu['code'] === 200 && ($pu['body']['url'] ?? '') === "/straboMicroFiles/$P/project.zip"
		&& (int)($pu['body']['bytes'] ?? 0) === $len, $pu['raw']);
	$su = http_req('GET', "$HOST/jwtmicrodb/sharedURL/$P", $TOK);
	check('sharedURL: static URL and exact bytes', $su['code'] === 200 && (int)($su['body']['bytes'] ?? 0) === $len, $su['raw']);
	$sp = http_req('GET', "$HOST/jwtmicrodb/sharedProject/$sharekey", $users['member']['tok']);
	check('share code -> key = project id (the app then GETs the static URL)', (string)($sp['body']['key'] ?? '') === (string)$P, $sp['raw']);
	$mp = http_req('GET', "$HOST/jwtmicrodb/myProjects", $TOK);
	$mine = null;
	foreach (($mp['body']['projects'] ?? array()) as $x) if ($x['id'] === $sid) $mine = $x;
	check('myProjects lists it with exact bytes', $mine !== null && (int)$mine['bytes'] === $len, $mp['raw']);
	$again = http_req('GET', "$HOST/straboMicroFiles/$P/project.zip");
	check('same state -> same bytes', $again['raw'] === $static['raw']);

	// -----------------------------------------------------------------------
	section('B. Contents');
	$path = save('main.smz', $static['raw']);
	list($z, $err) = read_archive($path);
	check('libzip opens it with consistency checks, every CRC matches', $z !== null, (string)$err);
	$z = $z ?: array();
	$pre = "$sid/";
	$pj = json_decode($z[$pre . 'project.json'][0] ?? 'null', true);
	$disk = json_decode((string)@file_get_contents("$FILES/$P/project.json"), true);
	check('project.json = the worker\'s project.json (same overlay)', is_array($pj) && $pj === $disk);
	check('project.json keeps non-ASCII text', ($pj['datasets'][0]['samples'][0]['name'] ?? '') === 'Quartz 10° Mezőmadaras');
	check('images/<id>', ($z[$pre . 'images/zM1'][0] ?? null) === $img1 && ($z[$pre . 'images/zM2'][0] ?? null) === $img2);
	check('compositeThumbnails/<id>', ($z[$pre . 'compositeThumbnails/zM1'][0] ?? null) === $thumb);
	check('associatedFiles/<name> (non-ASCII name, empty file)', ($z[$pre . 'associatedFiles/notes µ.txt'][0] ?? null) === $att
		&& isset($z[$pre . 'associatedFiles/empty.csv']) && $z[$pre . 'associatedFiles/empty.csv'][0] === '');
	$tileOk = true;
	foreach ($tilesEntries as $name => $e) {
		$got = $z[$pre . "tiles/zM1/$name"] ?? null;
		$method = $e[1] === 'store' ? 0 : 8;
		if ($got === null || $got[0] !== $e[0] || (int)$got[1] !== $method) { $tileOk = false; echo "        tile $name wrong\n"; }
	}
	check('tiles/<id>/... copied raw (content, method store/deflate kept)', $tileOk);
	check('tilesAffine/<id>/...', ($z[$pre . 'tilesAffine/zM2/metadata.json'][0] ?? null) === '{"affine":true}');
	$pc = json_decode($z[$pre . 'point-counts/zPC1.json'][0] ?? 'null', true);
	check('point-counts/<id>.json', is_array($pc) && $pc['name'] === 'PC' && $pc['micrographId'] === 'zM1');
	$expectNames = array('project.json', 'images/zM1', 'images/zM2', 'compositeThumbnails/zM1', 'associatedFiles/empty.csv',
		'associatedFiles/notes µ.txt', 'tilesAffine/zM2/metadata.json', 'point-counts/zPC1.json');
	foreach (array_keys($tilesEntries) as $n) $expectNames[] = "tiles/zM1/$n";
	$gotNames = array_map(function ($n) use ($pre) { return substr($n, strlen($pre)); }, array_keys($z));
	sort($expectNames);
	sort($gotNames);
	check('nothing else in the archive, one root folder', $gotNames === $expectNames, json_encode($gotNames));
	$raw = $static['raw'];
	check('no data descriptors (bit 3 clear in every local header)', (function () use ($raw) {
		$p = 0;
		while (($p = strpos($raw, "PK\x03\x04", $p)) !== false) {
			$flags = unpack('v', substr($raw, $p + 6, 2))[1];
			if ($flags & 0x08) return false;
			$p += 4;
		}
		return true;
	})());

	// StraboSamples overlay: a Samples-app edit shows in the download at once.
	$spine = $db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE id = 'zS' AND userpkey = $1", array($U));
	if ((int)$spine > 0) {
		$db->prepare_query("UPDATE strabosamples.samples SET name = 'Edited in Samples' WHERE id = 'zS' AND userpkey = $1", array($U));
		$ov = http_req('GET', "$HOST/straboMicroFiles/$P/project.zip");
		$f = save('overlay.smz', $ov['raw']);
		list($zo) = read_archive($f);
		$pjo = json_decode($zo[$pre . 'project.json'][0] ?? 'null', true);
		check('StraboSamples edit appears in project.json (sampleID, label, name)',
			($pjo['datasets'][0]['samples'][0]['sampleID'] ?? '') === 'Edited in Samples'
			&& ($pjo['datasets'][0]['samples'][0]['label'] ?? '') === 'Edited in Samples');
		check('Content-Length still exact after the overlay', (int)$ov['headers']['content-length'] === strlen($ov['raw']));
		$db->prepare_query("UPDATE strabosamples.samples SET name = 'Quartz 10° Mezőmadaras' WHERE id = 'zS' AND userpkey = $1", array($U));
	} else {
		check('samples spine row exists for the synced sample', false);
	}

	// -----------------------------------------------------------------------
	section('C. ZIP64');
	MsSmz::$forceZip64 = true;
	$plan = MsSmz::plan($db, $P, true);
	$bytes = '';
	$n = MsSmz::write($plan, function ($b) use (&$bytes) { $bytes .= $b; return true; });
	MsSmz::$forceZip64 = false;
	check('forced ZIP64: length as planned', $n === $plan['length'] && strlen($bytes) === $plan['length']);
	$f = save('zip64-forced.smz', $bytes);
	list($z64, $err) = read_archive($f);
	check('forced ZIP64: libzip reads every entry', $z64 !== null && count($z64) === count($z), (string)$err);
	check('forced ZIP64: same contents', $z64 !== null && array_map(function ($e) { return $e[0]; }, $z64) === array_map(function ($e) { return $e[0]; }, $z));

	$sid2 = $PREFIX . 'many';
	$r = req('POST', '/projects', $TOK, array('straboId' => $sid2, 'name' => 'Many'));
	$P2 = (int)$r['body']['pid'];
	$pids[] = $P2;
	$sids[] = $sid2;
	push_all($P2, $TOK, array(
		array('op' => 'create', 'type' => 'project', 'id' => $sid2, 'body' => array('name' => 'Many')),
		array('op' => 'create', 'type' => 'dataset', 'id' => 'mD', 'parentType' => 'project', 'parentId' => $sid2, 'body' => array('name' => 'D')),
		array('op' => 'create', 'type' => 'sample', 'id' => 'mS', 'parentType' => 'dataset', 'parentId' => 'mD', 'body' => array('name' => 'S')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'mM', 'parentType' => 'sample', 'parentId' => 'mS', 'body' => array('name' => 'M')),
	));
	$manyEntries = array('metadata.json' => array('{}', 'store'));
	for ($i = 0; $i < 70000; $i++) $manyEntries['tiles/tile_' . ($i % 300) . '_' . intdiv($i, 300) . '.webp'] = array("t$i", 'store');
	set_ref($P2, $TOK, 'micrograph', 'mM', 'tiles', upload_blob($P2, $TOK, make_archive($manyEntries), 'tiles'));
	req('POST', "/projects/$P2/ready", $TOK);
	build_now($P2);
	$many = http_req('GET', "$HOST/straboMicroFiles/$P2/project.zip");
	check('70,001-entry project: 200, exact length', $many['code'] === 200 && (int)$many['headers']['content-length'] === strlen($many['raw']));
	$f = save('many.smz', $many['raw']);
	list($zm, $err) = read_archive($f);
	check('70,001-entry project: ZIP64 end records, libzip reads all entries', $zm !== null && count($zm) === 70002
		&& strpos($many['raw'], "PK\x06\x06") !== false, (string)$err . ' count=' . ($zm === null ? 'null' : count($zm)));
	check('70,001-entry project: a tile round-trips', ($zm[$sid2 . '/tiles/mM/tiles/tile_99_233.webp'][0] ?? null) === 't69999');

	// -----------------------------------------------------------------------
	section('D. Edges');
	check('unknown project id -> 404', http_req('GET', "$HOST/straboMicroFiles/999999999/project.zip")['code'] === 404);
	check('download_micro_file unknown id -> 404', http_req('GET', "$HOST/download_micro_file?project_id=999999999")['code'] === 404);
	$legacy = null;
	foreach ($db->get_results("SELECT id FROM strabomicro.micro_projectmetadata WHERE sync_format = 'legacy' ORDER BY id DESC") as $lr) {
		if (is_file("$FILES/{$lr->id}/project.zip")) { $legacy = (int)$lr->id; break; }
	}
	$legacyZip = "$FILES/$legacy/project.zip";
	if ($legacy) {
		$lg = http_req('GET', "$HOST/straboMicroFiles/$legacy/project.zip");
		check("legacy project #$legacy: static project.zip served unchanged", $lg['code'] === 200 && $lg['raw'] === file_get_contents($legacyZip));
		check("legacy project #$legacy: size helper = file size", micro_sync_download_bytes($db, $legacy) === filesize($legacyZip));
	} else {
		echo "  SKIP  no legacy project with a project.zip on this box\n";
	}
	$blobThumb = "$FILES/$P/blobs/$shaThumb";
	rename($blobThumb, "$blobThumb.away");
	$miss = http_req('GET', "$HOST/straboMicroFiles/$P/project.zip");
	rename("$blobThumb.away", $blobThumb);
	$f = save('missing-blob.smz', $miss['raw']);
	list($zx, $err) = read_archive($f);
	check('missing blob: left out, archive valid, length exact', $miss['code'] === 200 && $zx !== null
		&& !isset($zx[$pre . 'compositeThumbnails/zM1']) && isset($zx[$pre . 'images/zM1'])
		&& (int)$miss['headers']['content-length'] === strlen($miss['raw']), (string)$err);
	$bad = make_archive(array('../evil.txt' => array('x', 'store')));
	$shaBad = upload_blob($P, $TOK, $bad, 'tiles');
	set_ref($P, $TOK, 'micrograph', 'zM2', 'tiles', $shaBad);
	$ev = http_req('GET', "$HOST/straboMicroFiles/$P/project.zip");
	$f = save('unsafe-tiles.smz', $ev['raw']);
	list($ze, $err) = read_archive($f);
	$names = $ze === null ? array() : array_keys($ze);
	check('tiles archive with an unsafe name: left out, rest intact', $ze !== null && !preg_grep('#evil#', $names)
		&& isset($ze[$pre . 'tiles/zM1/tiles/tile_0_0.webp']), (string)$err);

} finally {
	foreach ($sids as $s) {
		legacy_delete($U, $s);
	}
	foreach ($pids as $pid) {
		$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		if ($pid > 0 && is_dir("$FILES/$pid")) exec('rm -rf ' . escapeshellarg("$FILES/$pid"));
	}
	$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = ANY (ARRAY[$1, $2, $3]::int[])",
		array($users['owner']['pkey'], $users['member']['pkey'], $users['outsider']['pkey']));
	foreach ($users as $u) {
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u['pkey']));
	}
	exec('rm -rf ' . escapeshellarg($W));
	echo "\nArchives for host checks: $OUTDIR\n";
}

echo "\n" . (empty($failures) ? 'ALL PASSED' : count($failures) . " FAILED:\n  - " . implode("\n  - ", $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
