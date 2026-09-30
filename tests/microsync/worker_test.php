<?php
/**
 * File: worker_test.php
 * Description: Tests for the microsync derived-view worker and the legacy
 *              path guards.
 *
 *              A. Equivalence: real dev project.json fixtures are split into
 *                 entities, pushed through /microsync/v1/, and rebuilt by the
 *                 worker; the resulting strabomicro rows, strabosamples and
 *                 strabosearch rows must equal a legacy upload of the same
 *                 JSON (ids, timestamps and sync bookkeeping ignored), the
 *                 stored and on-disk project.json must equal the input, and
 *                 deleting must restore the baseline.
 *              B. Files: images, thumbnails, attachments (hard links), tile
 *                 archives, point counts, removal of stale files, zip-slip
 *                 archives refused.
 *              C. Non-ASCII text survives (the legacy upload path garbles it).
 *              D. Kick after a push, cron sweep, housekeeping.
 *              E. Legacy guards: uploads refused, lists hide unbuilt projects,
 *                 shared projects cannot be deleted, both legacy APIs.
 *
 *              Usage (MICROSYNC_ENABLED true; MICROSYNC_QUIET_SECONDS short on dev):
 *                docker exec strabo-php php /srv/app/www/tests/microsync/worker_test.php [--json=N|--json=all]
 *
 *              Hermetic: throwaway users (removed at the end), mstest-w
 *              straboIds. Exits non-zero on any failure.
 */

set_time_limit(0);
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/includes/jwt/quick-jwt.php';
require_once '/srv/app/www/tests/lib/micro_snapshot.php';
require_once '/srv/app/www/microsync/lib/MsModel.php';
require_once '/srv/app/www/microdb/lib/sync_guard.php';
require_once '/srv/app/www/microdb/lib/sample_overlay.php';

$jsonCount = 12;
$only = null;
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--json=(all|\d+)$/', $arg, $m)) { $jsonCount = ($m[1] === 'all') ? PHP_INT_MAX : (int)$m[1]; }
	elseif (preg_match('/^--only=(\d+)$/', $arg, $m)) { $only = (int)$m[1]; }
}

$BASE = 'http://localhost/microsync/v1';
$FILES = '/srv/app/www/straboMicroFiles';
$W = '/tmp/microsync_worker_test';
@mkdir($W, 0777, true);
$RUN = substr(md5(uniqid('', true)), 0, 6);
$PREFIX = 'mstest-w' . $RUN . '-';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr($detail, 0, 1500) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function section($name) { echo "\n== $name\n"; }

function token($pkey) {
	$qjt = new QuickJWT();
	return $qjt->sign(array('iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE, 'iat' => time(), 'exp' => time() + 7200,
		'sub' => (string)$pkey, 'email' => 'x', 'name' => 'x'), JWT_SECRET);
}

function req($method, $path, $tok, $body = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	$h = array('Authorization: Bearer ' . $tok);
	if (is_string($body)) {
		$h[] = 'Content-Type: application/octet-stream';
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	} elseif ($body !== null) {
		$h[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('code' => $code, 'body' => json_decode($raw, true), 'raw' => $raw);
}

function uuid() {
	$b = random_bytes(16);
	$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
	$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
	$h = bin2hex($b);
	return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

/** Push changes in batches under the API limits; returns non-accepted results. */
function push_all($pid, $tok, $changes) {
	$bad = array();
	$batch = array();
	$bytes = 0;
	$flush = function () use (&$batch, &$bytes, &$bad, $pid, $tok) {
		if (!$batch) return;
		$r = req('POST', "/projects/$pid/push", $tok, array('pushId' => uuid(), 'clientId' => 'worker-test', 'changes' => $batch));
		if ($r['code'] !== 200) {
			$bad[] = array('http' => $r['code'], 'raw' => substr($r['raw'], 0, 500));
		} else {
			foreach ($r['body']['results'] as $res) {
				if ($res['status'] !== 'accepted') $bad[] = $res;
			}
		}
		$batch = array();
		$bytes = 0;
	};
	foreach ($changes as $c) {
		$len = strlen(json_encode($c));
		if (count($batch) >= 400 || $bytes + $len > 4000000) $flush();
		$batch[] = $c;
		$bytes += $len;
	}
	$flush();
	return $bad;
}

function upload_blob($pid, $tok, $bytes, $kind) {
	$sha = hash('sha256', $bytes);
	$r = req('POST', "/projects/$pid/uploads", $tok, array('sha256' => $sha, 'size' => strlen($bytes), 'kind' => $kind));
	if (!empty($r['body']['complete'])) return $sha;
	$up = $r['body']['uploadId'];
	$off = 0;
	while ($off < strlen($bytes)) {
		$chunk = substr($bytes, $off, $r['body']['chunkSize']);
		req('PUT', "/projects/$pid/uploads/$up?offset=$off", $tok, $chunk);
		$off += strlen($chunk);
	}
	req('POST', "/projects/$pid/uploads/$up/complete", $tok);
	return $sha;
}

function set_ref($pid, $tok, $type, $id, $role, $sha) {
	return req('PUT', "/projects/$pid/refs", $tok, array('entityType' => $type, 'entityId' => $id, 'role' => $role, 'sha256' => $sha));
}

/**
 * Run the worker for one project now, retrying while a kicked worker holds
 * the build lock. Returns its result line; PHP warnings printed by the
 * legacy loader go to $GLOBALS['lastWarnings'].
 */
function build_now($pid) {
	for ($i = 0; $i < 60; $i++) {
		$out = array();
		exec('php /srv/app/www/microsync/worker.php --project=' . (int)$pid . ' --now 2>/dev/null', $out, $rc);
		$lines = array_values(array_filter(array_map('trim', $out), 'strlen'));
		$last = count($lines) ? $lines[count($lines) - 1] : '';
		$GLOBALS['lastWarnings'] = count(array_filter($lines, function ($l) { return strpos($l, 'Warning:') === 0; }));
		if ($last !== 'busy') return $last;
		sleep(1);
	}
	return 'busy';
}

function legacy($api, $action, $user, $zip, $sid) {
	$cmd = 'php /srv/app/www/tests/microsync/legacy_child.php ' . escapeshellarg($api) . ' ' . escapeshellarg($action) . ' '
		. (int)$user . ' ' . escapeshellarg($zip) . ' ' . escapeshellarg($sid) . ' 2>/dev/null';
	$out = array();
	exec($cmd, $out);
	$j = json_decode(count($out) ? $out[count($out) - 1] : '', true);
	return is_array($j) ? $j['result'] : array('harness_error' => implode(' | ', array_slice($out, -3)));
}

function pid_of($db, $user, $sid) {
	$v = $db->get_var_prepared("SELECT id FROM strabomicro.micro_projectmetadata WHERE userpkey = $1 AND strabo_id = $2", array($user, $sid));
	return $v === null || $v === '' ? null : (int)$v;
}

function make_zip($json, $sid, $dst) {
	@unlink($dst);
	$za = new ZipArchive();
	$za->open($dst, ZipArchive::CREATE);
	$za->addFromString("$sid/project.json", $json);
	$za->close();
}

/** Canonical form for comparing JSON documents (sorted keys). */
function canon($v) {
	if (is_array($v)) {
		if ($v !== array() && array_keys($v) !== range(0, count($v) - 1)) ksort($v);
		foreach ($v as $k => $x) $v[$k] = canon($x);
	}
	return $v;
}

/**
 * Normalize a fixture so both paths see the same input: every child
 * collection an array, per-user fields gone, duplicate entities (same
 * type and id) collapsed, project id replaced. Returns null if unusable.
 */
function normalize_fixture($j, $sid) {
	if (!is_object($j)) return null;
	$seen = array();
	$walk = function ($obj, $type) use (&$walk, &$seen) {
		if (!is_object($obj) || !isset($obj->id) || !is_string($obj->id) || !MsModel::isId($obj->id)) return false;
		foreach (MsModel::perUserFields($type) as $f) unset($obj->$f);
		foreach (MsModel::$CHILD_KEYS[$type] as $key => $childType) {
			$list = array();
			foreach ((isset($obj->$key) && is_array($obj->$key)) ? $obj->$key : array() as $child) {
				$k = $childType . ':' . (is_object($child) && isset($child->id) ? $child->id : '');
				if (isset($seen[$k])) continue;
				if (!$walk($child, $childType)) return false;
				$seen[$k] = true;
				$list[] = $child;
			}
			$obj->$key = $list;
		}
		return true;
	};
	$j->id = $sid;
	return $walk($j, 'project') ? $j : null;
}

/** Entity creates for a normalized project, parents first, nested micrographs after their parent. */
function decompose($j) {
	$out = array();
	$emit = function ($type, $obj, $ptype, $pidv) use (&$out) {
		$body = clone $obj;
		$order = array();
		foreach (MsModel::$CHILD_KEYS[$type] as $key => $childType) {
			$order[$key] = array_map(function ($c) { return $c->id; }, $obj->$key);
			unset($body->$key);
		}
		$c = array('op' => 'create', 'type' => $type, 'id' => $obj->id, 'body' => $body);
		if ($ptype !== null) { $c['parentType'] = $ptype; $c['parentId'] = $pidv; }
		if ($order) $c['childOrder'] = $order;
		$out[] = $c;
	};
	$emit('project', $j, null, null);
	foreach (array('tags' => 'tag', 'groups' => 'group', 'presets' => 'preset') as $key => $type) {
		foreach ($j->$key as $x) $emit($type, $x, 'project', $j->id);
	}
	foreach ($j->datasets as $d) {
		$emit('dataset', $d, 'project', $j->id);
		foreach ($d->samples as $s) {
			$emit('sample', $s, 'dataset', $d->id);
			// Nested micrographs after their parent micrograph.
			$pending = $s->micrographs;
			$done = array();
			for ($guard = 0; $pending && $guard < 1000; $guard++) {
				$next = array();
				foreach ($pending as $m) {
					$nest = isset($m->parentID) && is_string($m->parentID) && $m->parentID !== '' ? $m->parentID : null;
					if ($nest === null || isset($done[$nest])) {
						$emit('micrograph', $m, 'sample', $s->id);
						$done[$m->id] = true;
						foreach ($m->spots as $p) $emit('spot', $p, 'micrograph', $m->id);
					} else {
						$next[] = $m;
					}
				}
				if (count($next) === count($pending)) {
					// parentID points outside this sample: push anyway, the server decides.
					foreach ($next as $m) {
						$emit('micrograph', $m, 'sample', $s->id);
						foreach ($m->spots as $p) $emit('spot', $p, 'micrograph', $m->id);
					}
					break;
				}
				$pending = $next;
			}
		}
	}
	return $out;
}

function lexemes($db, $pid) {
	return $db->get_var_prepared(
		"SELECT array_to_string(ARRAY(SELECT unnest(tsvector_to_array(keywords)) ORDER BY 1), ' ')
		   FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
}

// ---------------------------------------------------------------------------
// Users and baseline
// ---------------------------------------------------------------------------
$db->get_var('SELECT 1');
$users = array();
foreach (array('owner', 'member') as $who) {
	$email = "microsync-worker-$who-$RUN@test.strabospot.org";
	$pk = (int)$db->get_var_prepared(
		"INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Worker', $1, $2, 'x', 'x', true) RETURNING pkey",
		array(ucfirst($who), $email));
	if ($pk <= 0) {
		$pk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($email));
	}
	if ($pk <= 0) { fwrite(STDERR, "could not create test user\n"); exit(2); }
	$users[$who] = array('pkey' => $pk, 'tok' => token($pk));
}
$U = $users['owner']['pkey'];
$TOK = $users['owner']['tok'];

$SNAP_OPTS = array(
	'skipTables' => array('strabomicro.micro_members', 'strabomicro.micro_entities', 'strabomicro.micro_changes',
		'strabomicro.micro_pushes', 'strabomicro.micro_presence', 'strabomicro.micro_blobs', 'strabomicro.micro_blob_refs',
		'strabomicro.micro_uploads', 'strabomicro.micro_parked_pushes'),
	'skipColumns' => array_map(function ($c) { return "strabomicro.micro_projectmetadata.$c"; },
		array('projectjson', 'keywords', 'sync_format', 'sync_state', 'head_seq', 'files_dirty', 'pdf_dirty', 'original_filename', 'ispublic')),
	'canonicalJson' => true,
);
$baseline = snapshot_rows($db, $U, array(), $SNAP_OPTS);
$created = array(); // straboIds to clean up

try {

	// -----------------------------------------------------------------------
	section('A. Equivalence with the legacy upload');
	$rows = $db->get_results_prepared(
		"SELECT id, length(projectjson) AS len FROM strabomicro.micro_projectmetadata
		  WHERE projectjson IS NOT NULL AND sync_format = 'legacy' AND projectjson !~ '[^\\x01-\\x7f]'
		  ORDER BY length(projectjson), id", array());
	$n = count($rows);
	$pick = array();
	if ($jsonCount >= $n) { $pick = range(0, $n - 1); }
	else { for ($i = 0; $i < $jsonCount; $i++) $pick[] = (int)round($i * ($n - 1) / max(1, $jsonCount - 1)); }
	foreach ($rows as $i => $r) { if (in_array((int)$r->id, array(454, 471), true)) $pick[] = $i; }
	$fixtures = array();
	if ($only !== null) {
		$pick = array();
		foreach ($rows as $i => $r) { if ((int)$r->id === $only) $pick[] = $i; }
	}
	foreach (array_unique($pick) as $i) {
		$fixtures[] = array('name' => "project.json #{$rows[$i]->id} ({$rows[$i]->len} bytes)",
			'json' => $db->get_var_prepared("SELECT projectjson FROM strabomicro.micro_projectmetadata WHERE id = $1", array($rows[$i]->id)));
	}
	foreach (array(787, 775, 776, 773) as $id) {
		$zip = "$FILES/$id/project.zip";
		if (!is_file($zip)) continue;
		$za = new ZipArchive();
		if ($za->open($zip) !== true) continue;
		$text = null;
		for ($k = 0; $k < $za->numFiles; $k++) {
			$nm = $za->getNameIndex($k);
			if (preg_match('#^[^/]+/project\.json$#', $nm)) { $text = $za->getFromIndex($k); break; }
		}
		$za->close();
		if ($text !== null && !preg_match('/[\x80-\xff]/', $text)) {
			$fixtures[] = array('name' => "real .smz #$id", 'json' => $text);
		}
	}
	echo "  (" . count($fixtures) . " ASCII-only fixtures)\n";

	foreach ($fixtures as $fi => $fx) {
		$sid = $PREFIX . 'eq' . $fi;
		$j = normalize_fixture(json_decode($fx['json']), $sid);
		if ($j === null) { echo "  SKIP  {$fx['name']}: missing or bad entity ids\n"; continue; }
		$input = json_encode($j, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		$zip = "$W/$sid.zip";
		make_zip($input, $sid, $zip);
		$created[] = $sid;

		// Legacy path.
		$lr = legacy('jwt', 'upload', $U, $zip, $sid);
		$pidL = pid_of($db, $U, $sid);
		$rowsL = rows_minus(snapshot_rows($db, $U, array($pidL), $SNAP_OPTS), $baseline);
		$lexL = lexemes($db, $pidL);
		legacy('jwt', 'delete', $U, '-', $sid);

		// Worker path.
		$r = req('POST', '/projects', $TOK, array('straboId' => $sid, 'name' => 'eq'));
		$pidW = isset($r['body']['pid']) ? (int)$r['body']['pid'] : 0;
		$bad = push_all($pidW, $TOK, decompose($j));
		req('POST', "/projects/$pidW/ready", $TOK);
		$built = build_now($pidW);
		$rowsW = rows_minus(snapshot_rows($db, $U, array($pidW), $SNAP_OPTS), $baseline);
		$lexW = lexemes($db, $pidW);
		$storedW = $db->get_var_prepared("SELECT projectjson FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pidW));
		$fileW = @file_get_contents("$FILES/$pidW/project.json");
		$expected = json_decode($input);
		micro_sample_overlay_apply($expected, $db, $U);

		$ok = check("{$fx['name']}: pushed and built", $pidW > 0 && !$bad && $built === 'built',
			json_encode(array('legacy' => $lr, 'built' => $built, 'bad' => array_slice(array_values(array_filter($bad, function ($b) {
				return !isset($b['reason']) || !in_array($b['reason'], array('group_rejected', 'parent_rejected'), true); })), 0, 3))));
		if ($GLOBALS['lastWarnings'] > 0) {
			echo "  NOTE  {$fx['name']}: legacy loader printed {$GLOBALS['lastWarnings']} warnings (same loader as the legacy upload)\n";
		}
		if ($ok) {
			$nRows = 0;
			foreach ($rowsL as $t => $lines) $nRows += count($lines);
			check("{$fx['name']}: same rows as the legacy upload ($nRows rows in " . count($rowsL) . " tables)",
				$nRows > 0 && $rowsL === $rowsW, first_difference($rowsL, $rowsW));
			check("{$fx['name']}: same keywords", $lexL === $lexW, "legacy=$lexL worker=$lexW");
			check("{$fx['name']}: stored projectjson = input", canon(json_decode($storedW, true)) === canon(json_decode($input, true)));
			check("{$fx['name']}: project.json on disk = input + samples overlay",
				canon(json_decode($fileW, true)) === canon(json_decode(json_encode($expected), true)));
		}
		legacy('jwt', 'delete', $U, '-', $sid);
		$left = rows_minus(snapshot_rows($db, $U, array(), $SNAP_OPTS), $baseline);
		check("{$fx['name']}: delete restores the baseline", $left === array() && pid_of($db, $U, $sid) === null
			&& !is_dir("$FILES/$pidW"), json_encode(array_keys($left)));
	}

	// -----------------------------------------------------------------------
	section('B. Files');
	$sid = $PREFIX . 'files';
	$created[] = $sid;
	$r = req('POST', '/projects', $TOK, array('straboId' => $sid, 'name' => 'Files'));
	$P = (int)$r['body']['pid'];
	$bad = push_all($P, $TOK, array(
		array('op' => 'create', 'type' => 'project', 'id' => $sid, 'body' => array('name' => 'Files')),
		array('op' => 'create', 'type' => 'dataset', 'id' => 'wD', 'parentType' => 'project', 'parentId' => $sid, 'body' => array('name' => 'D')),
		array('op' => 'create', 'type' => 'sample', 'id' => 'wS', 'parentType' => 'dataset', 'parentId' => 'wD', 'body' => array('name' => 'Quartz µm 10° Mezőmadaras', 'label' => 'Quartz µm 10° Mezőmadaras')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'wM1', 'parentType' => 'sample', 'parentId' => 'wS', 'body' => array('name' => 'M1')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'wM2', 'parentType' => 'sample', 'parentId' => 'wS', 'body' => array('name' => 'M2', 'parentID' => 'wM1')),
		array('op' => 'create', 'type' => 'spot', 'id' => 'wP1', 'parentType' => 'micrograph', 'parentId' => 'wM1', 'body' => array('name' => 'P1')),
		array('op' => 'create', 'type' => 'point_count', 'id' => 'wPC1', 'parentType' => 'micrograph', 'parentId' => 'wM1', 'body' => array('name' => 'PC', 'points' => array(array('x' => 1)))),
	));
	check('synthetic project pushed', !$bad, json_encode($bad));
	$img = random_bytes(5000);
	$thumb = random_bytes(700);
	$att = "attachment\n";
	$shaImg = upload_blob($P, $TOK, $img, 'image');
	$shaThumb = upload_blob($P, $TOK, $thumb, 'thumbnail');
	$shaAtt = upload_blob($P, $TOK, $att, 'associated_file');
	$mkTiles = function ($entries) use ($W) {
		$f = "$W/tiles-" . uniqid() . '.zip';
		$za = new ZipArchive();
		$za->open($f, ZipArchive::CREATE);
		foreach ($entries as $name => $content) $za->addFromString($name, $content);
		$za->close();
		$b = file_get_contents($f);
		unlink($f);
		return $b;
	};
	$tiles1 = $mkTiles(array('metadata.json' => '{"width":256}', 'thumbnail.jpg' => 't', 'tiles/tile_0_0.webp' => 'w00'));
	$shaTiles1 = upload_blob($P, $TOK, $tiles1, 'tiles');
	$shaAff = upload_blob($P, $TOK, $mkTiles(array('metadata.json' => '{"affine":true}')), 'tiles_affine');
	set_ref($P, $TOK, 'micrograph', 'wM1', 'image', $shaImg);
	set_ref($P, $TOK, 'micrograph', 'wM1', 'thumbnail', $shaThumb);
	set_ref($P, $TOK, 'micrograph', 'wM1', 'tiles', $shaTiles1);
	set_ref($P, $TOK, 'micrograph', 'wM2', 'tiles_affine', $shaAff);
	set_ref($P, $TOK, 'spot', 'wP1', 'associated_file:notes.txt', $shaAtt);
	req('POST', "/projects/$P/ready", $TOK);
	check('build', build_now($P) === 'built');
	$d = "$FILES/$P";
	check('images/<id> is a hard link to the blob', is_file("$d/images/wM1") && fileinode("$d/images/wM1") === fileinode("$d/blobs/$shaImg"));
	check('compositeThumbnails/<id> linked', is_file("$d/compositeThumbnails/wM1") && file_get_contents("$d/compositeThumbnails/wM1") === $thumb);
	check('associatedFiles/<name> linked', is_file("$d/associatedFiles/notes.txt") && file_get_contents("$d/associatedFiles/notes.txt") === $att);
	check('tiles archive unpacked', @file_get_contents("$d/tiles/wM1/tiles/tile_0_0.webp") === 'w00'
		&& trim(@file_get_contents("$d/tiles/wM1/.blob")) === $shaTiles1);
	check('affine tiles unpacked', @file_get_contents("$d/tilesAffine/wM2/metadata.json") === '{"affine":true}');
	$pc = json_decode(@file_get_contents("$d/point-counts/wPC1.json"), true);
	check('point count written with its micrographId', is_array($pc) && $pc['micrographId'] === 'wM1' && $pc['name'] === 'PC');
	$pj = json_decode(@file_get_contents("$d/project.json"), true);
	check('project.json: nested micrograph flat in its sample, spot under M1',
		isset($pj['datasets'][0]['samples'][0]['micrographs']) && count($pj['datasets'][0]['samples'][0]['micrographs']) === 2
		&& $pj['datasets'][0]['samples'][0]['micrographs'][1]['parentID'] === 'wM1'
		&& $pj['datasets'][0]['samples'][0]['micrographs'][0]['spots'][0]['id'] === 'wP1');
	check('project.json holds no point counts', strpos(json_encode($pj), 'wPC1') === false);
	$flags = $db->get_row_prepared("SELECT views_built_at IS NOT NULL AS built, views_dirty_since IS NULL AS clean, files_dirty, pdf_dirty FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));
	check('flags: built, clean, files written, pdf pending', $flags->built === 't' && $flags->clean === 't' && $flags->files_dirty === 'f' && $flags->pdf_dirty === 't');
	check('no project.zip for synced projects', !file_exists("$d/project.zip"));

	section('C. Non-ASCII text');
	$label = $db->get_var_prepared(
		"SELECT s.label FROM strabomicro.micro_samplemetadata s JOIN strabomicro.micro_datasetmetadata d ON d.id = s.dataset_id WHERE d.project_id = $1",
		array($P));
	check('relational row keeps µ, °, ő', $label === 'Quartz µm 10° Mezőmadaras', (string)$label);
	$stored = $db->get_var_prepared("SELECT projectjson FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));
	check('stored projectjson keeps them', strpos($stored, 'Quartz µm 10° Mezőmadaras') !== false);
	check('project.json on disk keeps them', ($pj['datasets'][0]['samples'][0]['name'] ?? '') === 'Quartz µm 10° Mezőmadaras');

	section('B2. Changes remove stale files');
	$inode = fileinode("$d/images/wM1");
	check('rebuild without changes keeps links', build_now($P) === 'built' && fileinode("$d/images/wM1") === $inode);
	req('DELETE', "/projects/$P/refs?entityType=micrograph&entityId=wM1&role=image", $TOK);
	push_all($P, $TOK, array(
		array('op' => 'delete', 'type' => 'point_count', 'id' => 'wPC1', 'baseVersion' => 1),
		array('op' => 'delete', 'type' => 'micrograph', 'id' => 'wM2', 'baseVersion' => 1),
	));
	$tiles2 = $mkTiles(array('metadata.json' => '{"width":512}', 'tiles/tile_0_0.webp' => 'v2'));
	set_ref($P, $TOK, 'micrograph', 'wM1', 'tiles', upload_blob($P, $TOK, $tiles2, 'tiles'));
	check('rebuild', build_now($P) === 'built');
	check('image link removed with its ref', !file_exists("$d/images/wM1"));
	check('deleted point count file removed', !file_exists("$d/point-counts/wPC1.json"));
	check('deleted micrograph tiles removed', !file_exists("$d/tilesAffine/wM2"));
	check('new tiles archive replaced the old one', @file_get_contents("$d/tiles/wM1/tiles/tile_0_0.webp") === 'v2'
		&& !file_exists("$d/tiles/wM1/thumbnail.jpg"));
	$evil = $mkTiles(array('metadata.json' => '{}', '../../evil.txt' => 'x'));
	set_ref($P, $TOK, 'micrograph', 'wM1', 'tiles', upload_blob($P, $TOK, $evil, 'tiles'));
	build_now($P);
	check('zip-slip archive refused, old tiles kept', !file_exists("$d/evil.txt") && !file_exists("$FILES/evil.txt")
		&& @file_get_contents("$d/tiles/wM1/tiles/tile_0_0.webp") === 'v2');

	// -----------------------------------------------------------------------
	section('D. Kick, sweep, housekeeping');
	$before = $db->get_var_prepared("SELECT views_built_at FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P));
	push_all($P, $TOK, array(array('op' => 'update', 'type' => 'sample', 'id' => 'wS', 'baseVersion' => 1, 'fields' => array('notes' => 'kick'))));
	$quiet = defined('MICROSYNC_QUIET_SECONDS') ? (int)MICROSYNC_QUIET_SECONDS : 45;
	$rebuilt = false;
	for ($i = 0; $i < $quiet + 30 && !$rebuilt; $i++) {
		sleep(1);
		$now = $db->get_var_prepared("SELECT views_built_at FROM strabomicro.micro_projectmetadata WHERE id = $1 AND views_dirty_since IS NULL", array($P));
		$rebuilt = $now !== null && $now !== $before;
	}
	check("push kicks a worker that rebuilds after the quiet period ($quiet s)", $rebuilt);
	$pj = json_decode(@file_get_contents("$d/project.json"), true);
	check('kicked rebuild picked up the edit', ($pj['datasets'][0]['samples'][0]['notes'] ?? '') === 'kick');

	$db->prepare_query("UPDATE strabomicro.micro_projectmetadata SET views_dirty_since = now() - interval '10 minutes' WHERE id = $1", array($P));
	$db->prepare_query("UPDATE strabomicro.micro_changes SET at = now() - interval '10 minutes' WHERE project_id = $1", array($P));
	$stale = uuid();
	$db->prepare_query(
		"INSERT INTO strabomicro.micro_uploads (upload_id, project_id, user_pkey, sha256, size, kind, chunk_size, updated_at)
		 VALUES ($1, $2, $3, $4, 10, 'image', 16777216, now() - interval '8 days')",
		array($stale, $P, $U, str_repeat('a', 64)));
	file_put_contents("$FILES/_staging/$stale", 'partial');
	exec('php /srv/app/www/microsync/worker.php --sweep 2>&1', $out, $rc);
	check('sweep rebuilds a dirty quiet project', $rc === 0 && $db->get_var_prepared(
		"SELECT views_dirty_since IS NULL FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P)) === 't');
	check('sweep removes week-old uploads and staging files', !file_exists("$FILES/_staging/$stale")
		&& $db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_uploads WHERE upload_id = $1", array($stale)) === '0');
	exec('php /srv/app/www/microsync/worker.php --bogus 2>&1', $out2, $rc2);
	check('bad arguments -> usage, exit 2', $rc2 === 2);
	$web = @file_get_contents('http://localhost/microsync/worker.php');
	check('worker is not reachable over HTTP', strpos((string)$web, 'built') === false && strpos((string)$web, 'usage') === false);

	// -----------------------------------------------------------------------
	section('E. Legacy guards');
	$zipF = "$W/{$sid}_legacy.zip";
	make_zip(json_encode(array('id' => $sid, 'name' => 'overwrite attempt', 'datasets' => array())), $sid, $zipF);
	$entities = $db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1", array($P));
	foreach (array('jwt', 'microdb') as $api) {
		$r = legacy($api, 'upload', $U, $zipF, $sid);
		check("$api upload of a synced project refused", isset($r['Error']) && strpos($r['Error'], 'update StraboMicro') !== false, json_encode($r));
	}
	check('refused uploads changed nothing', $db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1", array($P)) === $entities
		&& $db->get_var_prepared("SELECT sync_format FROM strabomicro.micro_projectmetadata WHERE id = $1", array($P)) === 'entity');

	$sidU = $PREFIX . 'unbuilt';
	$created[] = $sidU;
	$PU = (int)req('POST', '/projects', $TOK, array('straboId' => $sidU, 'name' => 'Unbuilt'))['body']['pid'];
	foreach (array('jwt', 'microdb') as $api) {
		$ids = array_map(function ($p) { return $p['id']; }, (array)(legacy($api, 'list', $U, '-', '-')['projects'] ?? array()));
		check("$api list: built synced project shown, unbuilt hidden", in_array($sid, $ids, true) && !in_array($sidU, $ids, true), json_encode($ids));
	}
	check('micro_sync_is_unbuilt', micro_sync_is_unbuilt($db, $PU) && !micro_sync_is_unbuilt($db, $P));
	$legacyPid = (int)$db->get_var("SELECT id FROM strabomicro.micro_projectmetadata WHERE sync_format = 'legacy' ORDER BY id LIMIT 1");
	check('legacy projects are never "unbuilt"', !micro_sync_is_unbuilt($db, $legacyPid));

	$db->prepare_query("INSERT INTO strabomicro.micro_members (project_id, user_pkey, role, state, invited_by, responded_at) VALUES ($1, $2, 'editor', 'active', $3, now())",
		array($P, $users['member']['pkey'], $U));
	foreach (array('jwt', 'microdb') as $api) {
		$r = legacy($api, 'delete', $U, '-', $sid);
		check("$api delete of a shared synced project refused", is_string($r) && strpos($r, 'shared') !== false && pid_of($db, $U, $sid) === $P, json_encode($r));
	}
	$db->prepare_query("UPDATE strabomicro.micro_members SET state = 'removed', removed_at = now() WHERE project_id = $1 AND user_pkey = $2",
		array($P, $users['member']['pkey']));
	$r = legacy('microdb', 'delete', $U, '-', $sid);
	check('delete allowed once the owner is the only member (cascades the store)', $r === null && pid_of($db, $U, $sid) === null
		&& $db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1", array($P)) === '0');

} finally {
	foreach ($created as $s) {
		$pid = pid_of($db, $U, $s);
		if ($pid) {
			$db->prepare_query("DELETE FROM strabomicro.micro_members WHERE project_id = $1 AND user_pkey <> $2", array($pid, $U));
			legacy('jwt', 'delete', $U, '-', $s);
			if (is_dir("$FILES/$pid")) exec('rm -rf ' . escapeshellarg("$FILES/$pid"));
		}
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	$left = rows_minus(snapshot_rows($db, $U, array(), $SNAP_OPTS), $baseline);
	foreach ($users as $u) {
		$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u['pkey']));
	}
	echo "\nCleanup: " . ($left === array() ? 'baseline restored' : 'LEFTOVER rows in ' . implode(', ', array_keys($left))) . "\n";
	exec('rm -rf ' . escapeshellarg($W));
}

echo "\n" . (empty($failures) ? 'ALL PASSED' : count($failures) . " FAILED:\n  - " . implode("\n  - ", $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
