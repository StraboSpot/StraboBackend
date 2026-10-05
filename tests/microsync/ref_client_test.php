<?php
/**
 * File: ref_client_test.php
 * Description: File ref changes carry the sending computer's clientId
 *              (found 2026-10-05 on prod: one account on two computers,
 *              the image of a micrograph added on one never reached the
 *              other). A ref change without a push was taken by every copy
 *              of the account for its own, so the activity poll counted it
 *              as nothing incoming and the copy never pulled it.
 *
 *              Checks, for one account on two computers (laptop, desktop):
 *              a ref set or removed with clientId counts as incoming for
 *              the other computer and not for the sender, the live notice
 *              names the sender, an unchanged ref adds no push, and a ref
 *              change without clientId (older app) keeps the old meaning.
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config):
 *                docker exec strabo-php php /srv/app/www/tests/microsync/ref_client_test.php
 *
 *              Hermetic: projects use the msref- straboId prefix and are
 *              deleted before and after the run. Exits non-zero on failure.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/includes/jwt/quick-jwt.php';

$BASE = 'http://localhost/microsync/v1';
$FILES = '/srv/app/www/straboMicroFiles';
$PREFIX = 'msref-';

$failures = array();
function check($label, $cond, $detail = null) {
	global $failures;
	$detail = $detail !== null && strlen($detail) > 300 ? substr($detail, 0, 300) . '...' : $detail;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== null ? "  -> $detail" : '') . "\n";
	if (!$cond) {
		$failures[] = $label;
	}
}
function section($name) {
	echo "\n== $name\n";
}

function token($pkey, $email) {
	$qjt = new QuickJWT();
	return $qjt->sign(array(
		'iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE, 'iat' => time(), 'exp' => time() + 3600,
		'sub' => (string)$pkey, 'email' => $email, 'name' => $email,
	), JWT_SECRET);
}

/** $body: array = JSON, null = none */
function req($method, $path, $tok, $body = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	$h = array('Authorization: Bearer ' . $tok);
	if ($body !== null) {
		$h[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
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
	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function cleanup() {
	global $db, $FILES, $PREFIX;
	$pids = $db->get_results_prepared(
		"SELECT id FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	foreach ((array)$pids as $r) {
		$pid = (int)$r->id;
		if ($pid > 0 && is_dir("$FILES/$pid")) {
			exec('rm -rf ' . escapeshellarg("$FILES/$pid"));
		}
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
}

/** How many changes the activity poll of $clientId (the owner's computer) counts as incoming since $since */
function incoming($pid, $tok, $clientId, $since) {
	$r = req('POST', "/projects/$pid/activity", $tok, array('since' => $since, 'clientId' => $clientId, 'state' => 'active'));
	if ($r['code'] !== 200) {
		return 'http_' . $r['code'];
	}
	$n = 0;
	foreach (isset($r['body']['pending']) ? $r['body']['pending'] : array() as $p) {
		$n += (int)$p['count'];
	}
	return $n;
}

/** The next live notice for $pid on the LISTEN connection (null after 3 s) */
function notice($listen, $pid) {
	$end = microtime(true) + 3;
	while (microtime(true) < $end) {
		$n = pg_get_notify($listen, PGSQL_ASSOC);
		if ($n !== false) {
			$m = json_decode($n['payload'], true);
			if (is_array($m) && isset($m['pid']) && (int)$m['pid'] === (int)$pid && $m['t'] === 'changed') {
				return $m;
			}
			continue;
		}
		usleep(20000);
	}
	return null;
}

$db->get_var('SELECT 1');
cleanup();
$email = 'owner@test.strabospot.org';
$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
if ($pkey <= 0) {
	fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
	exit(2);
}
$TOK = token($pkey, $email);
$listen = pg_connect("host=$dbhost dbname=$dbname user=$dbusername password=$dbpassword");
pg_query($listen, 'LISTEN microsync_live');

try {
	section('Setup');
	$SID = $PREFIX . bin2hex(random_bytes(4));
	$r = req('POST', '/projects', $TOK, array('straboId' => $SID, 'name' => 'Ref client test'));
	check('create project', $r['code'] === 201, $r['raw']);
	$PID = (int)$r['body']['pid'];
	$r = req('POST', "/projects/$PID/push", $TOK, array('pushId' => uuid(), 'clientId' => 'laptop',
		'changes' => array(array('op' => 'create', 'type' => 'project', 'id' => $SID, 'body' => array('name' => 'Ref client test')))));
	check('push the project entity from the laptop', $r['code'] === 200 && $r['body']['results'][0]['status'] === 'accepted', $r['raw']);
	$shaE = hash('sha256', '');
	$r = req('POST', "/projects/$PID/uploads", $TOK, array('sha256' => $shaE, 'size' => 0, 'kind' => 'associated_file'));
	check('a file on the server (empty blob)', $r['code'] === 200 || $r['code'] === 201, $r['raw']);
	$head = function () use ($db, $PID) {
		return (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID));
	};
	$pushes = function () use ($db, $PID) {
		return (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_pushes WHERE project_id = $1", array($PID));
	};
	while (pg_get_notify($listen) !== false) {
		// drop the setup notices
	}

	section('A ref set on the laptop');
	$h0 = $head();
	$r = req('PUT', "/projects/$PID/refs", $TOK, array('entityType' => 'project', 'entityId' => $SID,
		'role' => 'associated_file:a.txt', 'sha256' => $shaE, 'clientId' => 'laptop'));
	check('ref set', $r['code'] === 200 && $r['body']['changed'] === true, $r['raw']);
	$n = notice($listen, $PID);
	check('the live notice names the laptop', $n !== null && $n['by'] === 'laptop', json_encode($n));
	check('the desktop (same account) counts it as incoming', incoming($PID, $TOK, 'desktop', $h0) === 1);
	check('the laptop does not', incoming($PID, $TOK, 'laptop', $h0) === 0);
	$row = $db->get_row_prepared(
		"SELECT c.push_id, ps.client_id FROM strabomicro.micro_changes c
		   LEFT JOIN strabomicro.micro_pushes ps ON ps.push_id = c.push_id
		  WHERE c.project_id = $1 AND c.seq = $2", array($PID, $head()));
	check('the change has a push of its own from the laptop', $row && $row->push_id !== null && $row->client_id === 'laptop');

	section('The same ref again (no change)');
	$p0 = $pushes();
	$r = req('PUT', "/projects/$PID/refs", $TOK, array('entityType' => 'project', 'entityId' => $SID,
		'role' => 'associated_file:a.txt', 'sha256' => $shaE, 'clientId' => 'laptop'));
	check('unchanged', $r['code'] === 200 && $r['body']['changed'] === false, $r['raw']);
	check('no push recorded for it', $pushes() === $p0);

	section('A ref removed on the laptop');
	$h1 = $head();
	$r = req('DELETE', "/projects/$PID/refs?entityType=project&entityId=" . rawurlencode($SID)
		. '&role=' . rawurlencode('associated_file:a.txt') . '&clientId=laptop', $TOK);
	check('ref removed', $r['code'] === 200 && $r['body']['changed'] === true, $r['raw']);
	$n = notice($listen, $PID);
	check('the live notice names the laptop', $n !== null && $n['by'] === 'laptop', json_encode($n));
	check('the desktop counts it as incoming', incoming($PID, $TOK, 'desktop', $h1) === 1);
	check('the laptop does not', incoming($PID, $TOK, 'laptop', $h1) === 0);

	section('An older app (no clientId)');
	$h2 = $head();
	$p0 = $pushes();
	$r = req('PUT', "/projects/$PID/refs", $TOK, array('entityType' => 'project', 'entityId' => $SID,
		'role' => 'associated_file:b.txt', 'sha256' => $shaE));
	check('ref set', $r['code'] === 200 && $r['body']['changed'] === true, $r['raw']);
	check('no push recorded', $pushes() === $p0);
	$n = notice($listen, $PID);
	check('the live notice names nobody', $n !== null && $n['by'] === null, json_encode($n));
	check('as before: no copy of the account counts it', incoming($PID, $TOK, 'desktop', $h2) === 0);

	section('Bad clientId');
	$r = req('PUT', "/projects/$PID/refs", $TOK, array('entityType' => 'project', 'entityId' => $SID,
		'role' => 'associated_file:c.txt', 'sha256' => $shaE, 'clientId' => str_repeat('x', 201)));
	check('longer than 200 -> 400', $r['code'] === 400, $r['raw']);
	$r = req('PUT', "/projects/$PID/refs", $TOK, array('entityType' => 'project', 'entityId' => $SID,
		'role' => 'associated_file:c.txt', 'sha256' => $shaE, 'clientId' => 5));
	check('not a string -> 400', $r['code'] === 400, $r['raw']);
} finally {
	cleanup();
}

echo "\n" . (count($failures) ? count($failures) . ' FAILED' : 'ALL PASSED') . "\n";
exit(count($failures) ? 1 : 0);
