<?php
/**
 * File: microsync_client.php
 * Description: Test-side client for /microsync/v1/ and fixture helpers:
 *              tokens, requests, batched pushes, blob uploads, refs, running
 *              the worker, legacy API calls in a child process, and turning
 *              a project.json into entity creates. Callers set $BASE (API
 *              root URL). Shared by tests/microsync/worker_test.php,
 *              smz_test.php and seed_from_smz.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/jwt/quick-jwt.php';
require_once '/srv/app/www/microsync/lib/MsModel.php';

if (!isset($BASE)) $BASE = 'http://localhost/microsync/v1';

function token($pkey) {
	$qjt = new QuickJWT();
	return $qjt->sign(array('iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE, 'iat' => time(), 'exp' => time() + 7200,
		'sub' => (string)$pkey, 'email' => 'x', 'name' => 'x'), JWT_SECRET);
}

/**
 * API request to /microsync/v1 ($BASE). A string body is sent raw, anything
 * else as JSON. Returns code, body (decoded), raw, headers (lowercase keys).
 */
function req($method, $path, $tok, $body = null) {
	global $BASE;
	return http_req($method, $BASE . $path, $tok, $body);
}

/** HTTP request to any URL; same return shape as req(). */
function http_req($method, $url, $tok = null, $body = null) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	$headers = array();
	curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$headers) {
		$p = strpos($line, ':');
		if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
		return strlen($line);
	});
	$h = array();
	if ($tok !== null) $h[] = 'Authorization: Bearer ' . $tok;
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
	return array('code' => $code, 'body' => json_decode((string)$raw, true), 'raw' => (string)$raw, 'headers' => $headers);
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
	if (empty($r['body']['uploadId']) || (int)($r['body']['chunkSize'] ?? 0) <= 0) return null; // refused: never loop
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

/** Upload a file from disk (read one chunk at a time); returns its sha256. */
function upload_file($pid, $tok, $path, $kind) {
	$sha = hash_file('sha256', $path);
	$size = filesize($path);
	$r = req('POST', "/projects/$pid/uploads", $tok, array('sha256' => $sha, 'size' => $size, 'kind' => $kind));
	if (!empty($r['body']['complete'])) return $sha;
	if (empty($r['body']['uploadId']) || (int)($r['body']['chunkSize'] ?? 0) <= 0) return null; // refused: never loop
	$up = $r['body']['uploadId'];
	$cs = (int)$r['body']['chunkSize'];
	$fh = fopen($path, 'rb');
	for ($off = (int)$r['body']['received']; $off < $size; $off += $cs) {
		fseek($fh, $off);
		req('PUT', "/projects/$pid/uploads/$up?offset=$off", $tok, fread($fh, $cs));
	}
	fclose($fh);
	$c = req('POST', "/projects/$pid/uploads/$up/complete", $tok);
	return empty($c['body']['complete']) ? null : $sha;
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

/**
 * Delete a test project through the legacy door (jwt). A synced project gets
 * the 30-day delete there (v3 17ac), so it is purged at once
 * (microsync/tools/deleted.php) and its tombstone dropped: nothing is left.
 * Returns the legacy answer.
 */
function delete_synced($db, $user, $sid) {
	$pid = pid_of($db, $user, $sid);
	$r = legacy('jwt', 'delete', $user, '-', $sid);
	if ($pid && $db->get_var_prepared("SELECT 1 FROM strabomicro.micro_deleted_projects WHERE project_id = $1", array($pid)) !== null) {
		exec('php /srv/app/www/microsync/tools/deleted.php --purge=' . (int)$pid . ' --force 2>/dev/null');
		$db->prepare_query("DELETE FROM strabomicro.micro_deleted_projects WHERE project_id = $1", array($pid));
	}
	return $r;
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
