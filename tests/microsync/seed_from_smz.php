<?php
/**
 * File: seed_from_smz.php
 * Description: Round trip of a real .smz through the sync store: the
 *              project is pushed as entities under a fresh project id (so an
 *              import never collides with a local copy), its images,
 *              thumbnails, attachments, tile pyramids (one ZIP per
 *              micrograph, as the Phase 1 client will upload them) and point
 *              counts uploaded through /microsync/v1/, the worker builds it,
 *              and the streamed .smz is downloaded from the static
 *              project.zip URL the app uses. Every entry is compared with the
 *              source (project.pdf and README.txt are not carried; JSON
 *              files compare by content; the project name gets a
 *              " (stream test)" suffix).
 *
 *              The output is for opening in packaged StraboMicro2 releases
 *              (the old-client check before step 3 goes to production).
 *
 *              Usage (MICROSYNC_ENABLED true):
 *                docker exec strabo-php php /srv/app/www/tests/microsync/seed_from_smz.php <file.smz> [--keep]
 *              Output: microsync_data/seed/<name>-stream-test.smz (gitignored).
 *              --keep leaves the throwaway user and project in place.
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
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microdb/lib/sample_overlay.php';

$args = array_slice($argv, 1);
$keep = in_array('--keep', $args, true);
$args = array_values(array_filter($args, function ($a) { return $a !== '--keep'; }));
if (count($args) !== 1 || !is_file($args[0])) {
	fwrite(STDERR, "usage: php seed_from_smz.php <file.smz> [--keep]\n");
	exit(2);
}
$SRC = $args[0];
$HOST = 'http://localhost';
$FILES = '/srv/app/www/straboMicroFiles';
$RUN = substr(md5(uniqid('', true)), 0, 6);
$W = "/tmp/microsync_seed_$RUN";
$OUTDIR = '/srv/app/www/microsync_data/seed';
@mkdir("$W/src", 0777, true);
@mkdir($OUTDIR, 0777, true);
$t0 = microtime(true);
function step($msg) {
	global $t0;
	printf("[%6.1f s] %s\n", microtime(true) - $t0, $msg);
}

// ---------------------------------------------------------------------------
$za = new ZipArchive();
if ($za->open($SRC) !== true || !$za->extractTo("$W/src")) {
	fwrite(STDERR, "cannot extract $SRC\n");
	exit(2);
}
$za->close();
$roots = array_values(array_filter(scandir("$W/src"), function ($f) use ($W) { return $f[0] !== '.' && is_dir("$W/src/$f"); }));
if (count($roots) !== 1 || !is_file("$W/src/{$roots[0]}/project.json")) {
	fwrite(STDERR, "expected one root folder with project.json\n");
	exit(2);
}
$R = "$W/src/{$roots[0]}";
step("extracted {$roots[0]}");

$sid = uuid();
$j = normalize_fixture(json_decode(file_get_contents("$R/project.json")), $sid);
if ($j === null) {
	fwrite(STDERR, "project.json has missing or bad entity ids\n");
	exit(2);
}
$j->name = (isset($j->name) ? $j->name : 'Project') . ' (stream test)';

$db->get_var('SELECT 1');
$email = "microsync-seed-$RUN@test.strabospot.org";
$db->get_var_prepared("INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Seed', 'Test', $1, 'x', 'x', true) RETURNING pkey", array($email));
$U = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($email));
$TOK = token($U);

$exit = 1;
$P = 0;
try {
	$r = req('POST', '/projects', $TOK, array('straboId' => $sid, 'name' => $j->name));
	$P = isset($r['body']['pid']) ? (int)$r['body']['pid'] : 0;
	if ($P <= 0) throw new Exception('create project: ' . $r['raw']);
	$changes = decompose($j);
	$bad = push_all($P, $TOK, $changes);
	step(count($changes) . ' entities pushed, ' . count($bad) . ' not accepted');
	foreach (array_slice($bad, 0, 5) as $b) echo '    ' . json_encode($b) . "\n";

	// Files per micrograph and spot.
	$refs = 0;
	$ref = function ($type, $id, $role, $sha) use ($P, $TOK, &$refs) {
		$r = set_ref($P, $TOK, $type, $id, $role, $sha);
		if ($r['code'] !== 200) echo "    ref $type:$id $role -> {$r['code']} {$r['raw']}\n";
		else $refs++;
	};
	$zipDir = function ($dir) use ($W) {
		$f = "$W/pyr-" . uniqid() . '.zip';
		$z = new ZipArchive();
		$z->open($f, ZipArchive::CREATE);
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			$name = substr($file->getPathname(), strlen($dir) + 1);
			$z->addFile($file->getPathname(), $name);
			$z->setCompressionName($name, ZipArchive::CM_STORE);
		}
		$z->close();
		return $f;
	};
	$attach = function ($type, $obj) use ($R, $P, $TOK, $ref) {
		if (!isset($obj->associatedFiles) || !is_array($obj->associatedFiles)) return;
		foreach ($obj->associatedFiles as $af) {
			$name = is_object($af) && isset($af->fileName) ? (string)$af->fileName : '';
			if ($name !== '' && is_file("$R/associatedFiles/$name")) {
				$ref($type, $obj->id, "associated_file:$name", upload_file($P, $TOK, "$R/associatedFiles/$name", 'associated_file'));
			}
		}
	};
	$nMg = 0;
	foreach ($j->datasets as $d) foreach ($d->samples as $s) foreach ($s->micrographs as $m) {
		$nMg++;
		$id = $m->id;
		if (is_file("$R/images/$id")) $ref('micrograph', $id, 'image', upload_file($P, $TOK, "$R/images/$id", 'image'));
		if (is_file("$R/compositeThumbnails/$id")) $ref('micrograph', $id, 'thumbnail', upload_file($P, $TOK, "$R/compositeThumbnails/$id", 'thumbnail'));
		foreach (array('tiles' => 'tiles', 'tilesAffine' => 'tiles_affine') as $folder => $role) {
			if (is_dir("$R/$folder/$id")) {
				$zf = $zipDir("$R/$folder/$id");
				$ref('micrograph', $id, $role, upload_file($P, $TOK, $zf, $role));
				unlink($zf);
			}
		}
		$attach('micrograph', $m);
		foreach ($m->spots as $sp) $attach('spot', $sp);
	}
	step("$nMg micrographs: $refs file refs set");

	$pcs = array();
	foreach ((array)@scandir("$R/point-counts") as $f) {
		if (substr($f, -5) !== '.json') continue;
		$pc = json_decode(file_get_contents("$R/point-counts/$f"));
		if (is_object($pc) && isset($pc->id, $pc->micrographId)) {
			$pcs[] = array('op' => 'create', 'type' => 'point_count', 'id' => $pc->id,
				'parentType' => 'micrograph', 'parentId' => $pc->micrographId, 'body' => $pc);
		}
	}
	$badPc = push_all($P, $TOK, $pcs);
	step(count($pcs) . ' point counts pushed, ' . count($badPc) . ' not accepted');

	req('POST', "/projects/$P/ready", $TOK);
	$built = build_now($P);
	step("worker: $built");

	$dl = http_req('GET', "$HOST/straboMicroFiles/$P/project.zip");
	// straboMicroFiles/<id>/project.zip -> "<id>", else the file name.
	$base = basename($SRC) === 'project.zip' ? basename(dirname($SRC)) : basename($SRC, '.smz');
	$safe = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $base), '-'));
	$OUT = "$OUTDIR/$safe-stream-test.smz";
	file_put_contents($OUT, $dl['raw']);
	step(sprintf('streamed .smz: HTTP %d, %d bytes (Content-Length %s) -> %s',
		$dl['code'], strlen($dl['raw']), isset($dl['headers']['content-length']) ? $dl['headers']['content-length'] : '-', $OUT));

	// Compare every entry with the source.
	$out = new ZipArchive();
	if ($out->open($OUT, ZipArchive::CHECKCONS) !== true) throw new Exception('streamed archive does not open');
	$got = array();
	for ($i = 0; $i < $out->numFiles; $i++) $got[substr($out->getNameIndex($i), strlen($sid) + 1)] = $i;
	$want = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($R, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		$rel = substr($file->getPathname(), strlen($R) + 1);
		if (in_array($rel, array('project.pdf', 'README.txt', 'project.json'), true) || basename($rel) === '.gitkeep') continue;
		$want[$rel] = $file->getPathname();
	}
	$missing = array_diff_key($want, $got);
	$extra = array_diff_key($got, $want, array('project.json' => 1));
	$differ = array();
	$reordered = 0;
	foreach (array_intersect_key($want, $got) as $rel => $srcPath) {
		$data = $out->getFromIndex($got[$rel]);
		$orig = file_get_contents($srcPath);
		if ($data !== false && $data === $orig) continue;
		// Point count JSON is re-encoded by the server: indentation, and key
		// order (entity bodies are jsonb, which orders keys by length).
		if ($data !== false && substr($rel, -5) === '.json' && json_decode($orig) !== null
			&& canon(json_decode($data, true)) === canon(json_decode($orig, true))) {
			$reordered++;
			continue;
		}
		$differ[] = $rel;
	}
	$pjOut = json_decode($out->getFromIndex($got['project.json']), true);
	$out->close();
	// Legacy downloads apply the StraboSamples overlay (the spine name is
	// written to sampleID, label and name), so the expectation does too.
	$expected = json_decode(json_encode($j));
	micro_sample_overlay_apply($expected, $db, $U);
	$pjIn = json_decode(json_encode($expected), true);
	$pjSame = canon($pjOut) === canon($pjIn);

	echo "\nEntries in source (without project.json/pdf/README): " . count($want) . "\n";
	echo "Missing from stream: " . count($missing) . (count($missing) ? ' e.g. ' . implode(', ', array_slice(array_keys($missing), 0, 5)) : '') . "\n";
	echo "Extra in stream:     " . count($extra) . (count($extra) ? ' e.g. ' . implode(', ', array_slice(array_keys($extra), 0, 5)) : '') . "\n";
	echo "JSON, same content:  $reordered (formatting / key order only)\n";
	echo "Different bytes:     " . count($differ) . (count($differ) ? ' e.g. ' . implode(', ', array_slice($differ, 0, 5)) : '') . "\n";
	echo 'project.json:        ' . ($pjSame ? 'equal to the pushed project + StraboSamples overlay (canonical compare)' : 'DIFFERS from the pushed project + overlay') . "\n";
	if (!$pjSame) {
		file_put_contents("$OUTDIR/$safe-in.json", json_encode(canon($pjIn), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		file_put_contents("$OUTDIR/$safe-out.json", json_encode(canon($pjOut), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		echo "                     (canonical copies: $OUTDIR/$safe-in.json, -out.json)\n";
	}
	$ok = $dl['code'] === 200 && !$bad && !$badPc && $built === 'built' && !$missing && !$extra && !$differ && $pjSame;
	echo "\n" . ($ok ? 'ROUND TRIP OK' : 'ROUND TRIP HAS DIFFERENCES') . "\n";
	$exit = $ok ? 0 : 1;
} catch (Exception $e) {
	echo 'ERROR: ' . $e->getMessage() . "\n";
} finally {
	if (!$keep) {
		if ($P > 0) {
			legacy('jwt', 'delete', $U, '-', $sid);
			$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));
			if (is_dir("$FILES/$P")) exec('rm -rf ' . escapeshellarg("$FILES/$P"));
		}
		$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($U));
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($U));
	} else {
		echo "Kept: user $U ($email), project $P ($sid)\n";
	}
	exec('rm -rf ' . escapeshellarg($W));
}
exit($exit);
