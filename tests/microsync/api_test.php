<?php
/**
 * File: api_test.php
 * Description: HTTP test suite for the StraboMicro sync API (/microsync/v1/):
 *              projects, push rules (roles, conflicts, create groups,
 *              nesting, cascading delete and restore), idempotency, pull,
 *              history, snapshot, chunked uploads, blob refs, activity and
 *              presence, parking, and concurrent pushes.
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config):
 *                docker exec strabo-php php /srv/app/www/tests/microsync/api_test.php
 *
 *              Uses the collaboration fixture users (owner / editor /
 *              readonly / outsider @test.strabospot.org, plus
 *              maya.chen@test.strabospot.org as a Contributor). Tokens are
 *              minted with the dev JWT secret exactly as jwtauth/login.php
 *              does. Memberships other than the owner are inserted directly
 *              (the members endpoints arrive in Phase 2).
 *
 *              Hermetic: every project uses the mstest- straboId prefix and
 *              is deleted (with its blob files) before and after the run.
 *              Exits non-zero on any failure.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/includes/jwt/quick-jwt.php';

$BASE = 'http://localhost/microsync/v1';
$FILES = '/srv/app/www/straboMicroFiles';
$PREFIX = 'mstest-';

$failures = array();
function check($label, $cond, $detail = null) {
	global $failures;
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

/** HTTP request. $body: array/object = JSON, string = raw bytes, null = none. */
function req($method, $path, $tok, $body = null, $headers = array()) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	if ($method === 'HEAD') {
		curl_setopt($ch, CURLOPT_NOBODY, true);
	}
	$h = $headers;
	if ($tok !== null) {
		$h[] = 'Authorization: Bearer ' . $tok;
	}
	if (is_string($body)) {
		$h[] = 'Content-Type: application/octet-stream';
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	} elseif ($body !== null) {
		$h[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
	$respHeaders = array();
	curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $line) use (&$respHeaders) {
		$parts = explode(':', $line, 2);
		if (count($parts) === 2) {
			$respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
		}
		return strlen($line);
	});
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('code' => $code, 'body' => json_decode($raw, true), 'raw' => $raw, 'headers' => $respHeaders);
}

function uuid() {
	$b = random_bytes(16);
	$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
	$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
	$h = bin2hex($b);
	return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function push($pid, $tok, $changes, $pushId = null, $clientId = 'test-client') {
	return req('POST', "/projects/$pid/push", $tok,
		array('pushId' => $pushId === null ? uuid() : $pushId, 'clientId' => $clientId, 'changes' => $changes));
}

/** Result of the only change in a one-change push. */
function one($pid, $tok, $change) {
	$r = push($pid, $tok, array($change));
	return isset($r['body']['results'][0]) ? $r['body']['results'][0] : array('status' => 'http_' . $r['code'], 'raw' => $r['raw']);
}

/** JSON value with object keys sorted (jsonb does not keep key order). */
function canon($v) {
	if (is_array($v)) {
		if (array_keys($v) !== range(0, count($v) - 1)) {
			ksort($v);
		}
		foreach ($v as $k => $x) {
			$v[$k] = canon($x);
		}
	}
	return $v;
}

function st($res) {
	return isset($res['status']) ? $res['status'] . (isset($res['reason']) ? '/' . $res['reason'] : '') : json_encode($res);
}

function cleanup() {
	global $db, $FILES, $PREFIX;
	$pids = $db->get_results_prepared(
		"SELECT id FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	foreach ((array)$pids as $r) {
		$pid = (int)$r->id;
		$ups = $db->get_results_prepared("SELECT upload_id FROM strabomicro.micro_uploads WHERE project_id = $1", array($pid));
		foreach ((array)$ups as $u) {
			@unlink("$FILES/_staging/" . $u->upload_id);
		}
		if ($pid > 0 && is_dir("$FILES/$pid")) {
			exec('rm -rf ' . escapeshellarg("$FILES/$pid"));
		}
	}
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$db->get_var('SELECT 1');
$users = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org',
               'viewer' => 'readonly@test.strabospot.org', 'outsider' => 'outsider@test.strabospot.org',
               'contrib' => 'maya.chen@test.strabospot.org') as $k => $email) {
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	$users[$k] = array('pkey' => $pkey, 'tok' => token($pkey, $email));
}
$OWN = $users['owner']['tok'];
$EDT = $users['editor']['tok'];
$VIE = $users['viewer']['tok'];
$OUT = $users['outsider']['tok'];
$CON = $users['contrib']['tok'];

cleanup();
$run = substr(uuid(), 0, 8);
$SID = $PREFIX . $run;

try {

	// -----------------------------------------------------------------------
	section('Auth and routing');
	check('ping without auth', req('GET', '/ping', null)['code'] === 200);
	check('no token -> 401', req('GET', '/projects', null)['code'] === 401);
	check('bad token -> 401', req('GET', '/projects', 'not.a.token')['code'] === 401);
	check('unknown endpoint -> 404', req('GET', '/nope', $OWN)['code'] === 404);
	check('wrong method -> 405', req('DELETE', '/projects', $OWN)['code'] === 405);

	// -----------------------------------------------------------------------
	section('Projects');
	$r = req('POST', '/projects', $OWN, array('straboId' => $SID, 'name' => 'Sync test'));
	check('create -> 201 initializing', $r['code'] === 201 && $r['body']['syncState'] === 'initializing', $r['raw']);
	$PID = (int)$r['body']['pid'];
	$r = req('POST', '/projects', $OWN, array('straboId' => $SID, 'name' => 'again'));
	check('duplicate straboId -> 409 exists with pid', $r['code'] === 409 && $r['body']['error'] === 'exists' && $r['body']['pid'] === $PID);
	check('missing straboId -> 400', req('POST', '/projects', $OWN, array('name' => 'x'))['code'] === 400);
	$r = req('GET', '/projects', $OWN);
	$mine = array_values(array_filter($r['body'], function ($p) use ($PID) { return $p['pid'] === $PID; }));
	check('list shows it as owner', count($mine) === 1 && $mine[0]['role'] === 'owner' && $mine[0]['owner']['pkey'] === $users['owner']['pkey']);
	check('outsider cannot see it', req('GET', "/projects/$PID", $OUT)['code'] === 404);
	$legacy = (int)$db->get_var("SELECT id FROM strabomicro.micro_projectmetadata WHERE sync_format = 'legacy' ORDER BY id LIMIT 1");
	$legacyOwner = (int)$db->get_var_prepared("SELECT userpkey FROM strabomicro.micro_projectmetadata WHERE id = $1", array($legacy));
	check('legacy project is not served', req('GET', "/projects/$legacy/snapshot", token($legacyOwner, 'x'))['code'] === 404);
	$r = req('POST', "/projects/$PID/ready", $OWN);
	check('ready before project entity -> 409', $r['code'] === 409 && $r['body']['error'] === 'no_project_entity');

	foreach (array('editor' => 'editor', 'viewer' => 'viewer', 'contrib' => 'contributor') as $k => $role) {
		$db->prepare_query(
			"INSERT INTO strabomicro.micro_members (project_id, user_pkey, role, state, invited_by, responded_at)
			 VALUES ($1, $2, $3, 'active', $4, now())",
			array($PID, $users[$k]['pkey'], $role, $users['owner']['pkey']));
	}
	$r = req('GET', "/projects/$PID", $VIE);
	check('viewer sees metadata with 4 members', $r['code'] === 200 && count($r['body']['members']) === 4 && $r['body']['role'] === 'viewer');

	// -----------------------------------------------------------------------
	section('Push: creates');
	$r = push($PID, $OWN, array(
		array('op' => 'create', 'type' => 'project', 'id' => $SID, 'body' => array('name' => 'Sync test', 'presetKeyBindings' => array('1' => 'x'))),
		array('op' => 'create', 'type' => 'dataset', 'id' => 'D1', 'parentType' => 'project', 'parentId' => $SID, 'body' => array('name' => 'D1')),
		array('op' => 'create', 'type' => 'sample', 'id' => 'S1', 'parentType' => 'dataset', 'parentId' => 'D1', 'body' => array('name' => 'S1', 'isExpanded' => true)),
		array('op' => 'create', 'type' => 'sample', 'id' => 'S2', 'parentType' => 'dataset', 'parentId' => 'D1', 'body' => array('name' => 'S2')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'M1', 'parentType' => 'sample', 'parentId' => 'S1',
			'body' => array('name' => 'M1', 'scale' => 1.0, 'mineralogy' => array('notes' => 'n', 'minerals' => array()), 'tags' => array(), 'emptyObj' => new stdClass())),
		array('op' => 'create', 'type' => 'spot', 'id' => 'P1', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('name' => 'P1')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'MX', 'parentType' => 'sample', 'parentId' => 'S2', 'body' => array('name' => 'MX')),
	));
	$ok = $r['code'] === 200;
	foreach ((array)$r['body']['results'] as $res) {
		$ok = $ok && $res['status'] === 'accepted' && $res['version'] === 1;
	}
	check('initial tree accepted', $ok, $r['raw']);
	$head1 = $r['body']['headSeq'];
	check('headSeq = seq of last change', $head1 === $r['body']['results'][6]['seq']);

	check('child collection in body -> schema', st(one($PID, $OWN, array('op' => 'create', 'type' => 'micrograph', 'id' => 'MB', 'parentType' => 'sample', 'parentId' => 'S1',
		'body' => array('spots' => array())))) === 'invalid/schema');
	check('group.micrographs is a plain field', st(one($PID, $OWN, array('op' => 'create', 'type' => 'group', 'id' => 'G1', 'parentType' => 'project', 'parentId' => $SID,
		'body' => array('name' => 'G', 'micrographs' => array('M1'))))) === 'accepted');
	check('wrong parent type -> schema', st(one($PID, $OWN, array('op' => 'create', 'type' => 'spot', 'id' => 'PX', 'parentType' => 'sample', 'parentId' => 'S1', 'body' => new stdClass()))) === 'invalid/schema');
	check('missing parent -> parent_missing', st(one($PID, $OWN, array('op' => 'create', 'type' => 'spot', 'id' => 'PX', 'parentType' => 'micrograph', 'parentId' => 'NOPE', 'body' => new stdClass()))) === 'invalid/parent_missing');
	check('create existing -> exists', st(one($PID, $OWN, array('op' => 'create', 'type' => 'spot', 'id' => 'P1', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => new stdClass()))) === 'invalid/exists');
	check('body.id mismatch -> schema', st(one($PID, $OWN, array('op' => 'create', 'type' => 'spot', 'id' => 'PY', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('id' => 'other')))) === 'invalid/schema');
	check('bad op -> schema', st(one($PID, $OWN, array('op' => 'merge', 'type' => 'spot', 'id' => 'P1'))) === 'invalid/schema');
	check('non-object change -> schema', st(one($PID, $OWN, 'hello')) === 'invalid/schema');

	$r = push($PID, $OWN, array(
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'M2', 'parentType' => 'sample', 'parentId' => 'S1', 'body' => 'not an object'),
		array('op' => 'create', 'type' => 'spot', 'id' => 'P2', 'parentType' => 'micrograph', 'parentId' => 'M2', 'body' => array('name' => 'ok')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'M2n', 'parentType' => 'sample', 'parentId' => 'S1', 'body' => array('name' => 'nested', 'parentID' => 'M2')),
		array('op' => 'create', 'type' => 'spot', 'id' => 'P3', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => 'bad'),
		array('op' => 'create', 'type' => 'spot', 'id' => 'P4', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('name' => 'independent')),
	));
	$res = $r['body']['results'];
	check('rejected create rejects its children and nested micrographs',
		st($res[0]) === 'invalid/schema' && st($res[1]) === 'invalid/parent_rejected' && $res[1]['cause'] === 'micrograph:M2'
		&& st($res[2]) === 'invalid/parent_rejected', json_encode($res));
	check('a rejected child does not reject its parent or siblings', st($res[3]) === 'invalid/schema' && st($res[4]) === 'accepted');
	check('rejected creates left no rows', (int)$db->get_var_prepared(
		"SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_id IN ('M2','P2','M2n','P3')", array($PID)) === 0);

	// -----------------------------------------------------------------------
	section('Point count sessions');
	$r = one($PID, $OWN, array('op' => 'create', 'type' => 'point_count', 'id' => 'PC1', 'parentType' => 'micrograph', 'parentId' => 'M1',
		'body' => array('name' => 'Point Count 1', 'gridType' => 'regular', 'points' => array(array('x' => 1, 'y' => 2)))));
	check('point count created under a micrograph', st($r) === 'accepted', json_encode($r));
	$mid = $db->get_var_prepared("SELECT body->>'micrographId' FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'point_count' AND entity_id = 'PC1'", array($PID));
	check('micrographId filled in from the parent', $mid === 'M1');
	check('micrographId mismatch -> schema', st(one($PID, $OWN, array('op' => 'create', 'type' => 'point_count', 'id' => 'PC2', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('micrographId' => 'MX')))) === 'invalid/schema');
	check('point count under a sample -> schema', st(one($PID, $OWN, array('op' => 'create', 'type' => 'point_count', 'id' => 'PC2', 'parentType' => 'sample', 'parentId' => 'S1', 'body' => new stdClass()))) === 'invalid/schema');
	check('micrographId cannot be set by update', st(one($PID, $OWN, array('op' => 'update', 'type' => 'point_count', 'id' => 'PC1', 'baseVersion' => 1, 'fields' => array('micrographId' => 'MX')))) === 'invalid/schema');
	check('point count cannot move', st(one($PID, $OWN, array('op' => 'update', 'type' => 'point_count', 'id' => 'PC1', 'baseVersion' => 1, 'parentId' => 'MX'))) === 'invalid/schema');
	check('points list replaced atomically', st(one($PID, $OWN, array('op' => 'update', 'type' => 'point_count', 'id' => 'PC1', 'baseVersion' => 1, 'fields' => array('points' => array())))) === 'accepted');

	// -----------------------------------------------------------------------
	section('Push: updates and conflicts');
	$r = one($PID, $EDT, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 1,
		'fields' => array('mineralogy.notes' => 'edited', 'notes' => 'hello')));
	check('editor field update -> v2', st($r) === 'accepted' && $r['version'] === 2, json_encode($r));
	$r = one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 1, 'fields' => array('notes' => 'mine')));
	check('stale baseVersion -> conflict with current', st($r) === 'conflict' && $r['current']['version'] === 2
		&& $r['current']['body']['notes'] === 'hello' && $r['current']['updatedBy']['pkey'] === $users['editor']['pkey'], json_encode($r));
	check('missing baseVersion -> schema', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'fields' => array('notes' => 'x')))) === 'invalid/schema');
	check('set child collection as field -> schema', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 2, 'fields' => array('spots' => array())))) === 'invalid/schema');
	check('change id -> schema', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 2, 'fields' => array('id' => 'Z')))) === 'invalid/schema');
	check('path through a list -> schema', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 2, 'fields' => array('mineralogy.minerals.x' => 1)))) === 'invalid/schema');
	$r = one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 2,
		'fields' => array('mineralogy.notes' => null, 'isExpanded' => true, 'new.deep.value' => 5)));
	check('null removes, per-user ignored, deep path created', st($r) === 'accepted' && $r['version'] === 3);
	check('update missing entity -> not_found', st(one($PID, $OWN, array('op' => 'update', 'type' => 'spot', 'id' => 'NOPE', 'baseVersion' => 1, 'fields' => array('a' => 1)))) === 'invalid/not_found');

	$r = one($PID, $OWN, array('op' => 'update', 'type' => 'sample', 'id' => 'S1', 'childOrder' => array('micrographs' => array('NOPE', 'M1'))));
	check('childOrder-only update: no baseVersion, version unchanged', st($r) === 'accepted' && $r['version'] === 1, json_encode($r));
	check('childOrder with unknown key -> schema', st(one($PID, $OWN, array('op' => 'update', 'type' => 'sample', 'id' => 'S1', 'childOrder' => array('spots' => array())))) === 'invalid/schema');
	$stored = $db->get_var_prepared("SELECT child_order::text FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'sample' AND entity_id = 'S1'", array($PID));
	check('unknown ids dropped from stored order', $stored === '{"micrographs": ["M1"]}', $stored);

	// -----------------------------------------------------------------------
	section('Roles');
	$r = push($PID, $VIE, array(array('op' => 'create', 'type' => 'spot', 'id' => 'PV', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => new stdClass())));
	check('viewer write -> forbidden/viewer', st($r['body']['results'][0]) === 'forbidden/viewer');
	check('contributor creates spot on owner micrograph', st(one($PID, $CON, array('op' => 'create', 'type' => 'spot', 'id' => 'PC', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('name' => 'mine')))) === 'accepted');
	check('contributor edits own spot', st(one($PID, $CON, array('op' => 'update', 'type' => 'spot', 'id' => 'PC', 'baseVersion' => 1, 'fields' => array('name' => 'mine2')))) === 'accepted');
	check('contributor edits owner spot -> forbidden', st(one($PID, $CON, array('op' => 'update', 'type' => 'spot', 'id' => 'P1', 'baseVersion' => 1, 'fields' => array('name' => 'x')))) === 'forbidden/contributor_not_creator');
	check('contributor edits settings -> forbidden', st(one($PID, $CON, array('op' => 'update', 'type' => 'project', 'id' => $SID, 'baseVersion' => 1, 'fields' => array('name' => 'x')))) === 'forbidden/settings');
	check('contributor deletes owner micrograph -> forbidden', st(one($PID, $CON, array('op' => 'delete', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 3))) === 'forbidden/contributor_not_creator');
	check('editor edits settings', st(one($PID, $EDT, array('op' => 'update', 'type' => 'project', 'id' => $SID, 'baseVersion' => 1, 'fields' => array('name' => 'Renamed')))) === 'accepted');
	check('contributor creates micrograph', st(one($PID, $CON, array('op' => 'create', 'type' => 'micrograph', 'id' => 'MC', 'parentType' => 'sample', 'parentId' => 'S1', 'body' => array('name' => 'MC')))) === 'accepted');
	one($PID, $OWN, array('op' => 'create', 'type' => 'spot', 'id' => 'PO', 'parentType' => 'micrograph', 'parentId' => 'MC', 'body' => array('name' => 'owner spot on contributor micrograph')));
	check('contributor delete with others in cascade -> forbidden', st(one($PID, $CON, array('op' => 'delete', 'type' => 'micrograph', 'id' => 'MC', 'baseVersion' => 1))) === 'forbidden/cascade_includes_others');

	// -----------------------------------------------------------------------
	section('Nesting (micrograph parentID)');
	check('nest M3 under M1', st(one($PID, $OWN, array('op' => 'create', 'type' => 'micrograph', 'id' => 'M3', 'parentType' => 'sample', 'parentId' => 'S1', 'body' => array('name' => 'M3', 'parentID' => 'M1')))) === 'accepted');
	one($PID, $OWN, array('op' => 'create', 'type' => 'spot', 'id' => 'P3a', 'parentType' => 'micrograph', 'parentId' => 'M3', 'body' => array('name' => 'on M3')));
	check('M1 under M3 -> cycle', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 3, 'fields' => array('parentID' => 'M3')))) === 'invalid/cycle');
	check('self nesting -> cycle', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 3, 'fields' => array('parentID' => 'M1')))) === 'invalid/cycle');
	check('parentID in another sample -> parent_other_sample', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M3', 'baseVersion' => 1, 'fields' => array('parentID' => 'MX')))) === 'invalid/parent_other_sample');
	check('move micrograph with nested children -> parent_other_sample', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 3, 'parentId' => 'S2'))) === 'invalid/parent_other_sample');
	check('dangling parentID (never existed) is preserved', (function () use ($PID, $OWN, $db) {
		$r = push($PID, $OWN, array(
			array('op' => 'create', 'type' => 'micrograph', 'id' => 'MN1', 'parentType' => 'sample', 'parentId' => 'S2', 'body' => array('name' => 'x', 'parentID' => 'NEVER-EXISTED')),
			array('op' => 'create', 'type' => 'micrograph', 'id' => 'MN2', 'parentType' => 'sample', 'parentId' => 'S2', 'body' => array('name' => 'y', 'parentID' => 'MN1')),
		));
		return st($r['body']['results'][0]) === 'accepted' && st($r['body']['results'][1]) === 'accepted'
			&& $db->get_var_prepared("SELECT body->>'parentID' FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_id = 'MN1'", array($PID)) === 'NEVER-EXISTED';
	})());
	check('move a leaf micrograph to another sample', st(one($PID, $OWN, array('op' => 'update', 'type' => 'micrograph', 'id' => 'MX', 'baseVersion' => 1, 'parentId' => 'S1'))) === 'accepted');

	// -----------------------------------------------------------------------
	section('Delete and restore');
	check('stale delete -> conflict', st(one($PID, $EDT, array('op' => 'delete', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 1))) === 'conflict');
	$headBefore = (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID));
	$r = push($PID, $EDT, array(array('op' => 'delete', 'type' => 'micrograph', 'id' => 'M1', 'baseVersion' => 3)));
	$res = $r['body']['results'][0];
	check('delete M1 cascades to P1, P4, PC, PC1, M3, P3a', st($res) === 'accepted' && $res['cascaded'] === 6, json_encode($res));
	check('headSeq covers the cascaded rows', $r['body']['headSeq'] === $headBefore + 7, $headBefore . ' ' . $r['raw']);
	$tomb = $db->get_results_prepared("SELECT entity_id, deleted_root FROM strabomicro.micro_entities WHERE project_id = $1 AND deleted_at IS NOT NULL ORDER BY entity_id", array($PID));
	$ok = count($tomb) === 7;
	foreach ((array)$tomb as $t) {
		$ok = $ok && $t->deleted_root === 'micrograph:M1';
	}
	check('seven tombstones, all rooted at M1', $ok);
	$r = one($PID, $OWN, array('op' => 'update', 'type' => 'spot', 'id' => 'P1', 'baseVersion' => 2, 'fields' => array('name' => 'late')));
	check('update deleted -> deleted with deletedBy', st($r) === 'deleted' && $r['deletedBy']['pkey'] === $users['editor']['pkey']);
	$r = one($PID, $OWN, array('op' => 'create', 'type' => 'spot', 'id' => 'PL', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => new stdClass()));
	check('create under deleted -> deleted/parent_deleted', st($r) === 'deleted/parent_deleted' && $r['parent']['id'] === 'M1', json_encode($r));
	check('create over tombstone -> exists', st(one($PID, $OWN, array('op' => 'create', 'type' => 'spot', 'id' => 'P1', 'parentType' => 'micrograph', 'parentId' => 'MX', 'body' => new stdClass()))) === 'invalid/exists');
	check('contributor restore -> forbidden', st(one($PID, $CON, array('op' => 'restore', 'type' => 'micrograph', 'id' => 'M1', 'cascade' => true))) === 'forbidden/editor_required');
	check('restore nested child before its parent -> parent_deleted', st(one($PID, $EDT, array('op' => 'restore', 'type' => 'micrograph', 'id' => 'M3'))) === 'deleted/parent_deleted');
	$r = one($PID, $EDT, array('op' => 'restore', 'type' => 'micrograph', 'id' => 'M1', 'cascade' => true));
	check('cascade restore brings back all 6', st($r) === 'accepted' && $r['cascaded'] === 6 && $r['version'] === 5, json_encode($r));
	check('restore live -> not_deleted', st(one($PID, $EDT, array('op' => 'restore', 'type' => 'micrograph', 'id' => 'M1'))) === 'invalid/not_deleted');
	check('project entity cannot be deleted', st(one($PID, $OWN, array('op' => 'delete', 'type' => 'project', 'id' => $SID, 'baseVersion' => 2))) === 'invalid/schema');

	// -----------------------------------------------------------------------
	section('Idempotency and limits');
	$pushId = uuid();
	$a = push($PID, $OWN, array(array('op' => 'create', 'type' => 'tag', 'id' => 'T1', 'parentType' => 'project', 'parentId' => $SID, 'body' => array('name' => 'T'))), $pushId);
	$headA = (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID));
	$b = push($PID, $OWN, array(array('op' => 'create', 'type' => 'tag', 'id' => 'T1', 'parentType' => 'project', 'parentId' => $SID, 'body' => array('name' => 'T'))), $pushId);
	$headB = (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID));
	check('retried pushId returns the stored result', canon($a['body']) === canon($b['body']) && st($b['body']['results'][0]) === 'accepted', $a['raw'] . ' / ' . $b['raw']);
	check('retry wrote nothing', $headA === $headB);
	check('pushId reused by another user -> 409', push($PID, $EDT, array(array('op' => 'update', 'type' => 'tag', 'id' => 'T1', 'childOrder' => new stdClass())), $pushId)['code'] === 409);
	check('bad pushId -> 400', req('POST', "/projects/$PID/push", $OWN, array('pushId' => 'x', 'changes' => array(array())))['code'] === 400);
	check('empty changes -> 400', push($PID, $OWN, array())['code'] === 400);
	check('501 changes -> 413', push($PID, $OWN, array_fill(0, 501, array('op' => 'update')))['code'] === 413);
	check('outsider push -> 404', push($PID, $OUT, array(array('op' => 'update')))['code'] === 404);

	// -----------------------------------------------------------------------
	section('Pull and history');
	$all = array();
	$since = 0;
	$pages = 0;
	do {
		$r = req('GET', "/projects/$PID/changes?since=$since&limit=7", $VIE);
		$pages++;
		foreach ($r['body']['changes'] as $c) {
			$all[] = $c;
		}
		$since = $r['body']['headSeq'];
	} while ($r['body']['more'] && $pages < 100);
	$head = (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID));
	$logCount = (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_changes WHERE project_id = $1", array($PID));
	check('paged pull returns every change once, ending at head', count($all) === $logCount && $since === $head && $pages > 1, count($all) . "/$logCount pages $pages");
	$seqsOk = true;
	for ($i = 1; $i < count($all); $i++) {
		$seqsOk = $seqsOk && $all[$i]['seq'] > $all[$i - 1]['seq'];
	}
	check('seqs ascending', $seqsOk);
	$del = array_values(array_filter($all, function ($c) { return $c['op'] === 'delete' && $c['id'] === 'P1'; }));
	check('delete change has null body and a user', count($del) === 1 && $del[0]['body'] === null && $del[0]['user']['pkey'] === $users['editor']['pkey']);
	$cr = array_values(array_filter($all, function ($c) { return $c['op'] === 'create' && $c['id'] === 'M1'; }));
	check('create change carries parent, body, pushId', count($cr) === 1 && $cr[0]['parentId'] === 'S1' && $cr[0]['body']['name'] === 'M1' && $cr[0]['pushId'] !== null);
	$rawPull = req('GET', "/projects/$PID/changes?since=0&limit=1000", $VIE)['raw'];
	check('raw JSON keeps {} and 1.0', strpos($rawPull, '"emptyObj":{}') !== false && strpos($rawPull, '"scale":1.0') !== false);

	$r = req('GET', "/projects/$PID/history?entity=micrograph:M1", $VIE);
	$ops = array_map(function ($c) { return $c['op']; }, $r['body']['changes']);
	check('entity history: create, 2 updates, delete, restore', $ops === array('create', 'update', 'update', 'delete', 'restore'), json_encode($ops));
	$upd = $r['body']['changes'][1];
	check('history has before and after', $upd['after']['body']['notes'] === 'hello' && !isset($upd['before']['body']['notes']), json_encode($upd));
	$r = req('GET', "/projects/$PID/history?since=0&user=" . $users['contrib']['pkey'], $VIE);
	$ok = count($r['body']['changes']) > 0;
	foreach ($r['body']['changes'] as $c) {
		$ok = $ok && $c['user']['pkey'] === $users['contrib']['pkey'];
	}
	check('history by user', $ok);
	check('history bad entity -> 400', req('GET', "/projects/$PID/history?entity=nonsense", $VIE)['code'] === 400);

	// -----------------------------------------------------------------------
	section('Snapshot');
	one($PID, $OWN, array('op' => 'update', 'type' => 'sample', 'id' => 'S1', 'childOrder' => array('micrographs' => array('MC', 'M1'))));
	$r = req('GET', "/projects/$PID/snapshot", $VIE);
	$snap = $r['body'];
	$liveCount = (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1 AND deleted_at IS NULL", array($PID));
	check('snapshot: every live entity, headSeq = head', $r['code'] === 200 && count($snap['entities']) === $liveCount
		&& $snap['headSeq'] === (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID)));
	$byKey = array();
	$posOk = true;
	foreach ($snap['entities'] as $i => $e) {
		$byKey[$e['type'] . ':' . $e['id']] = $e;
		if ($e['parentType'] !== null && !isset($byKey[$e['parentType'] . ':' . $e['parentId']])) {
			$posOk = false;
		}
	}
	check('parents come before children', $posOk);
	check('S1 child order: stored first, rest appended in creation order (MX predates M3)',
		$byKey['sample:S1']['childOrder']['micrographs'] === array('MC', 'M1', 'MX', 'M3'), json_encode($byKey['sample:S1']['childOrder']));
	check('project childOrder lists datasets, tags, groups, presets',
		array_keys($byKey['project:' . $SID]['childOrder']) === array('datasets', 'tags', 'groups', 'presets'));
	check('per-user fields stripped', !isset($byKey['sample:S1']['body']['isExpanded']) && !isset($byKey['project:' . $SID]['body']['presetKeyBindings']));
	check('outsider snapshot -> 404', req('GET', "/projects/$PID/snapshot", $OUT)['code'] === 404);

	// -----------------------------------------------------------------------
	section('Blobs: chunked upload');
	$big = random_bytes(16777216 + 1000);
	$sha = hash('sha256', $big);
	check('HEAD missing blob -> 404', req('HEAD', "/projects/$PID/blobs/$sha", $VIE)['code'] === 404);
	check('viewer cannot upload', req('POST', "/projects/$PID/uploads", $VIE, array('sha256' => $sha, 'size' => strlen($big), 'kind' => 'image'))['code'] === 403);
	check('bad kind -> 400', req('POST', "/projects/$PID/uploads", $OWN, array('sha256' => $sha, 'size' => strlen($big), 'kind' => 'video'))['code'] === 400);
	$r = req('POST', "/projects/$PID/uploads", $OWN, array('sha256' => $sha, 'size' => strlen($big), 'kind' => 'image'));
	$up = $r['body']['uploadId'];
	check('start -> 201 with 16 MB chunks', $r['code'] === 201 && $r['body']['chunkSize'] === 16777216 && $r['body']['received'] === 0, $r['raw']);
	check('wrong chunk length -> 400', req('PUT', "/projects/$PID/uploads/$up?offset=0", $OWN, 'short')['code'] === 400);
	$r = req('PUT', "/projects/$PID/uploads/$up?offset=5", $OWN, substr($big, 5, 16777216));
	check('wrong offset -> 409 with received', $r['code'] === 409 && $r['body']['received'] === 0);
	$r = req('PUT', "/projects/$PID/uploads/$up?offset=0", $OWN, substr($big, 0, 16777216));
	check('chunk 1 stored', $r['code'] === 200 && $r['body']['received'] === 16777216, $r['raw']);
	check('complete early -> 409 incomplete', req('POST', "/projects/$PID/uploads/$up/complete", $OWN)['code'] === 409);
	$r = req('POST', "/projects/$PID/uploads", $OWN, array('sha256' => $sha, 'size' => strlen($big), 'kind' => 'image'));
	check('restart resumes the same upload', $r['code'] === 200 && $r['body']['uploadId'] === $up && $r['body']['received'] === 16777216);
	check('other user cannot write my upload', req('PUT', "/projects/$PID/uploads/$up?offset=16777216", $EDT, substr($big, 16777216))['code'] === 404);
	check('chunk 2 (last, short) stored', req('PUT', "/projects/$PID/uploads/$up?offset=16777216", $OWN, substr($big, 16777216))['body']['received'] === strlen($big));
	$r = req('POST', "/projects/$PID/uploads/$up/complete", $OWN);
	check('complete -> blob stored', $r['code'] === 200 && $r['body']['sha256'] === $sha && is_file("$FILES/$PID/blobs/$sha"), $r['raw']);
	$r = req('HEAD', "/projects/$PID/blobs/$sha", $VIE);
	check('HEAD -> 200 with length', $r['code'] === 200 && (int)$r['headers']['content-length'] === strlen($big));
	$r = req('GET', "/projects/$PID/blobs/$sha", $VIE, null, array('Range: bytes=16777210-16777229'));
	check('Range -> 206 exact bytes', $r['code'] === 206 && $r['raw'] === substr($big, 16777210, 20)
		&& $r['headers']['content-range'] === 'bytes 16777210-16777229/' . strlen($big));
	$r = req('GET', "/projects/$PID/blobs/$sha", $VIE, null, array('Range: bytes=-10'));
	check('suffix Range', $r['code'] === 206 && $r['raw'] === substr($big, -10));
	check('unsatisfiable Range -> 416', req('GET', "/projects/$PID/blobs/$sha", $VIE, null, array('Range: bytes=999999999-'))['code'] === 416);
	$r = req('GET', "/projects/$PID/blobs/$sha", $VIE);
	check('full GET matches', $r['code'] === 200 && hash('sha256', $r['raw']) === $sha);
	check('outsider GET -> 404', req('GET', "/projects/$PID/blobs/$sha", $OUT)['code'] === 404);
	$r = req('POST', "/projects/$PID/uploads", $EDT, array('sha256' => $sha, 'size' => strlen($big), 'kind' => 'image'));
	check('present blob -> complete immediately', $r['code'] === 200 && $r['body']['complete'] === true);

	// Empty files (an empty attachment): no chunks, stored at start.
	$shaE = hash('sha256', '');
	check('negative size -> 400', req('POST', "/projects/$PID/uploads", $OWN, array('sha256' => $shaE, 'size' => -1, 'kind' => 'associated_file'))['code'] === 400);
	check('size 0 with the wrong sha256 -> 422', req('POST', "/projects/$PID/uploads", $OWN, array('sha256' => $sha, 'size' => 0, 'kind' => 'associated_file'))['code'] === 422);
	check('viewer cannot store an empty blob', req('POST', "/projects/$PID/uploads", $VIE, array('sha256' => $shaE, 'size' => 0, 'kind' => 'associated_file'))['code'] === 403);
	$r = req('POST', "/projects/$PID/uploads", $OWN, array('sha256' => $shaE, 'size' => 0, 'kind' => 'associated_file'));
	check('empty blob -> complete at start, file and row stored', $r['code'] === 200 && $r['body']['complete'] === true && $r['body']['size'] === 0
		&& is_file("$FILES/$PID/blobs/$shaE") && filesize("$FILES/$PID/blobs/$shaE") === 0
		&& $db->get_var_prepared("SELECT size FROM strabomicro.micro_blobs WHERE project_id = $1 AND sha256 = $2", array($PID, $shaE)) === '0', $r['raw']);
	check('empty blob again -> complete', req('POST', "/projects/$PID/uploads", $EDT, array('sha256' => $shaE, 'size' => 0, 'kind' => 'associated_file'))['body']['complete'] === true);
	$r = req('HEAD', "/projects/$PID/blobs/$shaE", $VIE);
	check('HEAD empty blob -> 200, length 0', $r['code'] === 200 && (int)$r['headers']['content-length'] === 0);
	$r = req('GET', "/projects/$PID/blobs/$shaE", $VIE);
	check('GET empty blob -> 200, empty body', $r['code'] === 200 && $r['raw'] === '' && (int)$r['headers']['content-length'] === 0);
	check('no upload row left for the empty blob', $db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_uploads WHERE project_id = $1 AND sha256 = $2", array($PID, $shaE)) === '0');

	$small = 'attachment one';
	$shaA = hash('sha256', $small);
	$fake = str_repeat('0', 64);
	$r = req('POST', "/projects/$PID/uploads", $OWN, array('sha256' => $fake, 'size' => strlen($small), 'kind' => 'associated_file'));
	req('PUT', "/projects/$PID/uploads/" . $r['body']['uploadId'] . '?offset=0', $OWN, $small);
	$r2 = req('POST', "/projects/$PID/uploads/" . $r['body']['uploadId'] . '/complete', $OWN);
	check('hash mismatch -> 422, upload discarded', $r2['code'] === 422
		&& (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_uploads WHERE project_id = $1", array($PID)) === 0);
	foreach (array($small, 'attachment two') as $content) {
		$s = hash('sha256', $content);
		$r = req('POST', "/projects/$PID/uploads", $OWN, array('sha256' => $s, 'size' => strlen($content), 'kind' => 'associated_file'));
		req('PUT', "/projects/$PID/uploads/" . $r['body']['uploadId'] . '?offset=0', $OWN, $content);
		req('POST', "/projects/$PID/uploads/" . $r['body']['uploadId'] . '/complete', $OWN);
	}
	$shaB = hash('sha256', 'attachment two');

	// -----------------------------------------------------------------------
	section('Refs');
	$r = req('PUT', "/projects/$PID/refs", $OWN, array('entityType' => 'micrograph', 'entityId' => 'M1', 'role' => 'image', 'sha256' => $sha));
	check('set image ref -> logged change', $r['code'] === 200 && $r['body']['changed'] === true, $r['raw']);
	$refSeq = $r['body']['seq'];
	check('same ref again -> unchanged', req('PUT', "/projects/$PID/refs", $OWN, array('entityType' => 'micrograph', 'entityId' => 'M1', 'role' => 'image', 'sha256' => $sha))['body']['changed'] === false);
	check('kind mismatch -> 400', req('PUT', "/projects/$PID/refs", $OWN, array('entityType' => 'micrograph', 'entityId' => 'M1', 'role' => 'thumbnail', 'sha256' => $sha))['code'] === 400);
	check('unknown blob -> 409 blob_missing', req('PUT', "/projects/$PID/refs", $OWN, array('entityType' => 'micrograph', 'entityId' => 'M1', 'role' => 'image', 'sha256' => $fake))['code'] === 409);
	check('bad role -> 400', req('PUT', "/projects/$PID/refs", $OWN, array('entityType' => 'micrograph', 'entityId' => 'M1', 'role' => 'associated_file:../x', 'sha256' => $shaA))['code'] === 400);
	check('attach a.txt', req('PUT', "/projects/$PID/refs", $OWN, array('entityType' => 'micrograph', 'entityId' => 'M1', 'role' => 'associated_file:a.txt', 'sha256' => $shaA))['body']['changed'] === true);
	check('same name + same content elsewhere is fine', req('PUT', "/projects/$PID/refs", $OWN, array('entityType' => 'spot', 'entityId' => 'P1', 'role' => 'associated_file:a.txt', 'sha256' => $shaA))['code'] === 200);
	$r = req('PUT', "/projects/$PID/refs", $OWN, array('entityType' => 'spot', 'entityId' => 'P4', 'role' => 'associated_file:A.TXT', 'sha256' => $shaB));
	check('same name (any case) + other content -> 409 name_taken', $r['code'] === 409 && $r['body']['error'] === 'name_taken');
	check('contributor sets image on owner micrograph -> 403', req('PUT', "/projects/$PID/refs", $CON, array('entityType' => 'micrograph', 'entityId' => 'M1', 'role' => 'image', 'sha256' => $sha))['code'] === 403);
	$r = req('GET', "/projects/$PID/changes?since=" . ($refSeq - 1), $VIE);
	$c = $r['body']['changes'][0];
	check('pull shows the ref change', $c['seq'] === $refSeq && $c['changedPaths'] === array('refs.image') && $c['refs']['image'] === $sha && $c['pushId'] === null);
	$r = req('DELETE', "/projects/$PID/refs?entityType=spot&entityId=P1&role=" . rawurlencode('associated_file:a.txt'), $OWN);
	check('delete ref', $r['code'] === 200 && $r['body']['changed'] === true);
	$snap = req('GET', "/projects/$PID/snapshot", $VIE)['body'];
	// 4 blobs: the big image, two attachments, the empty file.
	check('snapshot lists blobs and refs', count($snap['blobs']) === 4 && count($snap['refs']) === 2, json_encode(array(count($snap['blobs']), $snap['refs'])));

	// -----------------------------------------------------------------------
	section('Activity and presence');
	$headNow = (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID));
	$r = req('POST', "/projects/$PID/activity", $OWN, array('since' => 0, 'clientId' => 'test-client', 'state' => 'active'));
	$pend = array();
	foreach ($r['body']['pending'] as $p) {
		$pend[$p['user']['pkey']] = $p['count'];
	}
	check('activity: pending per user, my client excluded', $r['code'] === 200 && $r['body']['changed'] === true
		&& !isset($pend[$users['owner']['pkey']]) && isset($pend[$users['editor']['pkey']]) && isset($pend[$users['contrib']['pkey']]), json_encode($r['body']['pending']));
	req('POST', "/projects/$PID/activity", $EDT, array('since' => $headNow, 'clientId' => 'editor-pc', 'viewing' => array('type' => 'micrograph', 'id' => 'M1')));
	$r = req('POST', "/projects/$PID/activity", $OWN, array('since' => $headNow, 'clientId' => 'test-client'));
	$pres = $r['body']['presence'];
	check('presence shows the editor on M1', count($pres) === 1 && $pres[0]['user']['pkey'] === $users['editor']['pkey'] && $pres[0]['viewing']['id'] === 'M1', json_encode($pres));
	$r2 = req('POST', "/projects/$PID/activity", $OWN, array('since' => $headNow, 'clientId' => 'test-client', 'presenceHash' => $r['body']['presenceHash']));
	check('nothing new -> changed false', $r2['body'] === array('changed' => false), $r2['raw']);
	check('bad activity body -> 400', req('POST', "/projects/$PID/activity", $OWN, array('since' => -1, 'clientId' => 'x'))['code'] === 400);

	// -----------------------------------------------------------------------
	section('Concurrency');
	$mh = curl_multi_init();
	$handles = array();
	foreach (array($OWN, $EDT) as $i => $tok) {
		$ch = curl_init("$BASE/projects/$PID/push");
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => array('Content-Type: application/json', "Authorization: Bearer $tok"),
			CURLOPT_POSTFIELDS => json_encode(array('pushId' => uuid(), 'clientId' => "race-$i", 'changes' => array(
				array('op' => 'update', 'type' => 'micrograph', 'id' => 'MX', 'baseVersion' => 2, 'fields' => array('notes' => "writer $i")),
				array('op' => 'create', 'type' => 'spot', 'id' => "R$i", 'parentType' => 'micrograph', 'parentId' => 'MX', 'body' => array('name' => "R$i")),
			)))));
		curl_multi_add_handle($mh, $ch);
		$handles[] = $ch;
	}
	do {
		curl_multi_exec($mh, $running);
		curl_multi_select($mh);
	} while ($running > 0);
	$st = array();
	$creates = 0;
	foreach ($handles as $ch) {
		$b = json_decode(curl_multi_getcontent($ch), true);
		$st[] = $b['results'][0]['status'];
		$creates += $b['results'][1]['status'] === 'accepted' ? 1 : 0;
		curl_multi_remove_handle($mh, $ch);
	}
	sort($st);
	check('racing updates on one entity: one accepted, one conflict', $st === array('accepted', 'conflict'), json_encode($st));
	check('racing creates of different spots: both accepted', $creates === 2);
	$dups = (int)$db->get_var_prepared(
		"SELECT count(*) - count(DISTINCT seq) FROM strabomicro.micro_changes WHERE project_id = $1", array($PID));
	$maxSeq = (int)$db->get_var_prepared("SELECT max(seq) FROM strabomicro.micro_changes WHERE project_id = $1", array($PID));
	$head = (int)$db->get_var_prepared("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID));
	check('head_seq = max seq after the race', $dups === 0 && $head === $maxSeq);

	// -----------------------------------------------------------------------
	section('Ready and parking');
	check('editor cannot mark ready', req('POST', "/projects/$PID/ready", $EDT)['code'] === 403);
	$r = req('POST', "/projects/$PID/ready", $OWN);
	check('owner marks ready, views dirty', $r['code'] === 200 && $r['body']['syncState'] === 'ready'
		&& $db->get_var_prepared("SELECT views_dirty_since IS NOT NULL FROM strabomicro.micro_projectmetadata WHERE id = $1", array($PID)) === 't');
	$db->prepare_query("UPDATE strabomicro.micro_members SET state = 'removed', removed_at = now() WHERE project_id = $1 AND user_pkey = $2",
		array($PID, $users['contrib']['pkey']));
	$r = push($PID, $CON, array(array('op' => 'update', 'type' => 'spot', 'id' => 'PC', 'baseVersion' => 2, 'fields' => array('name' => 'after removal'))));
	check('removed member: first push parked', $r['code'] === 403 && $r['body']['error'] === 'access_changed' && $r['body']['parked'] === true, $r['raw']);
	$r = push($PID, $CON, array(array('op' => 'update', 'type' => 'spot', 'id' => 'PC', 'baseVersion' => 2, 'fields' => array('name' => 'again'))));
	check('removed member: later pushes refused', $r['code'] === 403 && $r['body']['error'] === 'access_removed');
	check('removed member cannot read', req('GET', "/projects/$PID/snapshot", $CON)['code'] === 404);
	$r = req('POST', "/projects/$PID/activity", $OWN, array('since' => 0, 'clientId' => 'test-client'));
	check('owner sees parkedCount 1', $r['body']['parkedCount'] === 1);
	check('parked push changed nothing', $db->get_var_prepared(
		"SELECT body->>'name' FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_id = 'PC'", array($PID)) === 'mine2');

} finally {
	cleanup();
	$left = (int)$db->get_var_prepared("SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%'));
	echo "\nCleanup: $left test projects left\n";
}

echo "\n" . (empty($failures) ? 'ALL PASSED' : count($failures) . " FAILED:\n  - " . implode("\n  - ", $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
