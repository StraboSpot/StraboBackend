<?php
/**
 * File: smoke_test_search_api_http.php
 * Description: Permanent smoke suite for the /searchdb/ HTTP surface —
 *              everything the service-level suite deliberately skips:
 *              Apache routing, the Basic-auth-or-anonymous entry gate
 *              (§5.5.3: no credentials → anonymous; WRONG credentials →
 *              401, never a silent anonymous downgrade), the JWT variant
 *              at /searchdb/jwt/, the 401 gate on saved searches, the
 *              .htaccess denial of the CLI toolbox scripts that share
 *              the directory, and saved-search CRUD through real HTTP.
 *
 *              Hermetic: fixture user upk 94555 ('spsapihttp') with a
 *              crypt()-hashed password + two index rows (one public, one
 *              private project). Zero residue on exit.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_search_api_http.php
 *
 * @package    StraboSearch API
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/includes/jwt/quick-jwt.php';

$UPK   = 94555;
$EMAIL = 'spsapihttp@example.com';
$PASS  = 'spsapi-http-pass-1';
$PFX   = 'spsapihttp';
$BASE  = 'http://localhost';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

/**
 * Minimal HTTP client. Returns array($status, $decodedJsonOrNull, $rawBody).
 */
function http($method, $url, $body = null, $headers = array(), $basicAuth = null) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	if ($body !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		$headers[] = 'Content-Type: application/json';
	}
	if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	if ($basicAuth !== null) curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
	$raw = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array($status, json_decode((string)$raw, true), (string)$raw);
}

// ---------------------------------------------------------------------------
section('0. Fixtures');

$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_%'));
$db->prepare_query("DELETE FROM strabosearch.saved_search WHERE user_pkey = $1", array($UPK));
$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($UPK));

$db->prepare_query(
	"INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted)
	 VALUES ($1, 'Hank', 'Apihttp', $2, crypt($3, gen_salt('md5')), 'x', TRUE, FALSE)",
	array($UPK, $EMAIL, $PASS));

foreach (array(
	array('id' => $PFX . '_spub', 'proj' => $PFX . '_proj_pub',  'pub' => 'TRUE',  'text' => 'UNIQHTTP_pub spot'),
	array('id' => $PFX . '_spriv', 'proj' => $PFX . '_proj_priv', 'pub' => 'FALSE', 'text' => 'UNIQHTTP_priv spot'),
) as $s) {
	$db->query("INSERT INTO strabosearch.item_hit
		(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
		 project_name, project_ispublic, searchtext_tsv, source_modified)
		VALUES ('spot', '{$s['id']}', $UPK, '{$s['proj']}', $UPK, 'field',
		 'spsapihttp fixture', {$s['pub']}, to_tsvector('english', '{$s['text']}'),
		 '2024-01-01 00:00:00+00')");
	if ($db->last_error) { echo '  SEED FAILED: ' . $db->last_error . PHP_EOL; exit(1); }
}
echo "  fixtures seeded" . PHP_EOL;

$searchPub  = array('pathway' => 'projects', 'criteria' => array(array('id' => 'U1', 'value' => 'UNIQHTTP_pub')));
$searchPriv = array('pathway' => 'projects', 'criteria' => array(array('id' => 'U1', 'value' => 'UNIQHTTP_priv')));

// ---------------------------------------------------------------------------
section('1. Anonymous entry (§5.5.3)');

list($st, $j) = http('POST', "$BASE/searchdb/search", $searchPub);
check('anon search 200', $st === 200, "got $st");
check('anon sees public fixture', $j && $j['total'] === 1, json_encode($j ? $j['total'] : null));
list($st, $j) = http('POST', "$BASE/searchdb/search", $searchPriv);
check('anon cannot see private fixture', $st === 200 && $j['total'] === 0);
list($st, $j) = http('GET', "$BASE/searchdb/facets");
check('anon facets 200', $st === 200 && isset($j['facets']));
list($st, $j) = http('GET', "$BASE/searchdb/vocab/rock_type");
check('anon vocab 200', $st === 200 && isset($j['values']));
list($st) = http('GET', "$BASE/searchdb/saved");
check('anon saved list 401', $st === 401, "got $st");
list($st) = http('GET', "$BASE/searchdb/nosuchthing");
check('unknown endpoint 404', $st === 404, "got $st");

// ---------------------------------------------------------------------------
section('2. CLI toolbox denied over HTTP');

foreach (array(
	'searchdb/extractors/field.php', 'searchdb/extractors/refresh_vocab.php',
	'searchdb/install/install.php', 'searchdb/census/census_field_neo4j.php',
	'searchdb/sync/StraboSearchSync.php', 'searchdb/spike/build_spike.php',
	'searchdb/verify_extended.php', 'searchdb/verify_schema.php',
) as $path) {
	list($st) = http('GET', "$BASE/$path");
	check("403: $path", $st === 403, "got $st");
}

// ---------------------------------------------------------------------------
section('3. HTTP Basic auth');

list($st, $j) = http('POST', "$BASE/searchdb/search", $searchPriv, array(), "$EMAIL:$PASS");
check('owner Basic auth sees private fixture', $st === 200 && $j['total'] === 1,
	"st=$st total=" . json_encode($j ? $j['total'] : null));
list($st) = http('POST', "$BASE/searchdb/search", $searchPriv, array(), "$EMAIL:wrong-password");
check('wrong password → 401 (no anonymous downgrade)', $st === 401, "got $st");

// ---------------------------------------------------------------------------
section('4. Saved-search CRUD over HTTP (Basic)');

list($st, $j) = http('POST', "$BASE/searchdb/saved",
	array('search_name' => 'http suite search', 'dsl' => $searchPriv), array(), "$EMAIL:$PASS");
check('create saved 200', $st === 200 && isset($j['saved_search_pkey']), json_encode($j));
$spk = $j ? (int)$j['saved_search_pkey'] : 0;
list($st, $j) = http('GET', "$BASE/searchdb/saved", null, array(), "$EMAIL:$PASS");
check('list saved shows row + replayable dsl', $st === 200 && count($j['saved']) === 1
	&& $j['saved'][0]['dsl']['criteria'][0]['id'] === 'U1', json_encode($j));
list($st, $j) = http('PUT', "$BASE/searchdb/saved/$spk",
	array('search_name' => 'http suite renamed'), array(), "$EMAIL:$PASS");
check('rename saved 200', $st === 200 && isset($j['updated']));
list($st, $j) = http('POST', "$BASE/searchdb/saved",
	array('search_name' => 'bad', 'dsl' => array('criteria' => array(array('id' => 'ZZ')))),
	array(), "$EMAIL:$PASS");
check('invalid dsl on save → 400', $st === 400, "got $st");
list($st, $j) = http('DELETE', "$BASE/searchdb/saved/$spk", null, array(), "$EMAIL:$PASS");
check('delete saved 200', $st === 200 && isset($j['deleted']));
list($st) = http('DELETE', "$BASE/searchdb/saved/$spk", null, array(), "$EMAIL:$PASS");
check('re-delete → 404', $st === 404, "got $st");

// ---------------------------------------------------------------------------
section('5. JWT variant (/searchdb/jwt/)');

list($st) = http('POST', "$BASE/searchdb/jwt/search", $searchPub);
check('jwt: missing token 401', $st === 401, "got $st");
list($st) = http('POST', "$BASE/searchdb/jwt/search", $searchPub,
	array('Authorization: Bearer garbage.token.here'));
check('jwt: garbage token 401', $st === 401, "got $st");

$qjt = new QuickJWT();
$token = $qjt->sign(array(
	'iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE,
	'sub' => $UPK, 'email' => $EMAIL,
	'iat' => time(), 'exp' => time() + 3600,
), JWT_SECRET);
list($st, $j) = http('POST', "$BASE/searchdb/jwt/search", $searchPriv,
	array("Authorization: Bearer $token"));
check('jwt: valid token sees private fixture', $st === 200 && $j['total'] === 1,
	"st=$st total=" . json_encode($j ? $j['total'] : null));

$expired = $qjt->sign(array('iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE,
	'sub' => $UPK, 'iat' => time() - 7200, 'exp' => time() - 3600), JWT_SECRET);
list($st) = http('POST', "$BASE/searchdb/jwt/search", $searchPub,
	array("Authorization: Bearer $expired"));
check('jwt: expired token 401', $st === 401, "got $st");

list($st, $j) = http('POST', "$BASE/searchdb/jwt/saved",
	array('search_name' => 'jwt saved', 'dsl' => $searchPub),
	array("Authorization: Bearer $token"));
check('jwt: saved create 200', $st === 200 && isset($j['saved_search_pkey']), json_encode($j));
list($st, $j) = http('DELETE', "$BASE/searchdb/jwt/saved/" . ($j ? $j['saved_search_pkey'] : 0),
	null, array("Authorization: Bearer $token"));
check('jwt: saved delete 200', $st === 200);

// ---------------------------------------------------------------------------
section('6. Injection via HTTP');

list($st, $j) = http('POST', "$BASE/searchdb/search", array(
	'pathway' => 'projects',
	'criteria' => array(array('id' => 'U1', 'value' => "x'); DROP TABLE users; --"))));
check('sql-shaped keyword: clean 200', $st === 200 && isset($j['total']), "got $st");
list($st) = http('POST', "$BASE/searchdb/search", array('criteria' => array(array('id' => 'ZZ'))));
check('unknown criterion → 400', $st === 400, "got $st");
$n = (int)$db->get_var("SELECT count(*) FROM users WHERE pkey = $UPK");
check('users table intact', $n === 1);

// ---------------------------------------------------------------------------
section('7. Cleanup');

$db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_id LIKE $1", array($PFX . '_%'));
$db->prepare_query("DELETE FROM strabosearch.saved_search WHERE user_pkey = $1", array($UPK));
$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($UPK));
$n = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE '" . $PFX . "_%'")
   + (int)$db->get_var("SELECT count(*) FROM strabosearch.saved_search WHERE user_pkey = $UPK")
   + (int)$db->get_var("SELECT count(*) FROM users WHERE pkey = $UPK");
check('zero residue', $n === 0, "got $n");

echo PHP_EOL;
if ($failures) {
	echo count($failures) . " FAILURE(S):" . PHP_EOL;
	foreach ($failures as $f) echo "  - $f" . PHP_EOL;
	exit(1);
}
echo "ALL CHECKS PASSED." . PHP_EOL;
exit(0);
