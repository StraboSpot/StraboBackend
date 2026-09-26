<?php
/**
 * File: tests/sesar/smoke_test_sesar_onboarding.php
 * Description: Phase 3 suite for the SESAR connect flow (D1 + the 2026-09-26
 *              onboarding addition): SesarOrcid (state nonce, code exchange,
 *              id_token claim checks), SesarOnboarding (no_account ->
 *              no_permission -> in-app access request -> approval ->
 *              no_code -> in-app code creation -> connected; expiry,
 *              outages, revoked connections, disconnect), then the real
 *              pages over HTTP with forged sessions (gate, methods, the
 *              popup start redirect, callback refusals, page render).
 *
 *              Unit part talks ONLY to FakeSesar + an in-file fake ORCID.
 *              The HTTP part makes NO SESAR or ORCID call (status reads,
 *              refusals and redirects only). Fixture users 94720-94724;
 *              pilot user 3 is used read-only for the page render.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/sesar/smoke_test_sesar_onboarding.php
 *
 * @package    StraboSpot Tests
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/sesar/SesarOnboarding.php';
require_once 'includes/sesar/SesarOrcid.php';
require_once __DIR__ . '/FakeSesar.php';

$U = array(94720, 94721, 94722, 94723, 94724);
$STATE = '/tmp/fake_sesar_onboarding.json';
$KEY = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$sessionFiles = array();

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 400) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function throwsKind($fn, $kind, $msgPart = null) {
	try { $fn(); return 'no exception'; }
	catch (SesarError $e) {
		if ($e->kind !== $kind) return 'kind ' . $e->kind . ': ' . $e->getMessage();
		if ($msgPart !== null && stripos($e->getMessage(), $msgPart) === false) return 'message: ' . $e->getMessage();
		return true;
	}
}
function cleanup() {
	global $db, $U, $sessionFiles;
	$in = implode(',', array_map('intval', $U));
	$db->query("DELETE FROM strabosamples.sesar_onboarding WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosamples.sesar_connections WHERE userpkey IN ($in)");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
	foreach ($sessionFiles as $f) @unlink($f);
}

/** Fake ORCID token endpoint: issues an id_token with configurable claims. */
class FakeOrcid implements SesarTransport
{
	public $calls = 0;
	public $status = 200;
	public $claims = array();
	public function send($method, $url, array $headers, $body)
	{
		$this->calls++;
		if ($this->status !== 200) return array($this->status, json_encode(array('error' => 'invalid_grant')));
		$c = array_merge(array('iss' => 'https://orcid.org', 'aud' => SesarAccess::orcidClientId(), 'sub' => '0000-0000-0000-0042',
			'exp' => time() + 86400, 'iat' => time()), $this->claims);
		$jwt = 'eyJhbGciOiJSUzI1NiJ9.' . rtrim(strtr(base64_encode(json_encode($c)), '+/', '-_'), '=') . '.sig';
		return array(200, json_encode(array('access_token' => 'a', 'id_token' => $jwt, 'orcid' => $c['sub'], 'name' => 'Test Person')));
	}
}

cleanup();
foreach ($U as $i => $u) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, 'Onboard', $2, $3, 'x', 'x', TRUE, FALSE)",
		array($u, 'Person' . $i, "onboard$u@test.strabospot.org"));
}

try {

// ===========================================================================
section('SesarOrcid: state nonce + code exchange');
$_SESSION = array();
$st = SesarOrcid::newState();
check('authorize URL uses the web client, openid, exact redirect, state',
	strpos(SesarOrcid::authorizeUrl($st), 'client_id=' . urlencode(SesarAccess::orcidClientId())) !== false
	&& strpos(SesarOrcid::authorizeUrl($st), 'scope=openid') !== false
	&& strpos(SesarOrcid::authorizeUrl($st), 'redirect_uri=' . urlencode('https://strabospot.org/sesar_orcid_callback.php')) !== false
	&& strpos(SesarOrcid::authorizeUrl($st), 'state=' . $st) !== false);
check('state accepted once', SesarOrcid::consumeState($st) === true);
check('state is single-use', SesarOrcid::consumeState($st) === false);
$st = SesarOrcid::newState();
check('wrong state refused (and consumed)', SesarOrcid::consumeState('nope') === false && SesarOrcid::consumeState($st) === false);
$st = SesarOrcid::newState(); $_SESSION[SesarOrcid::STATE_KEY]['at'] = time() - 2000;
check('stale state refused', SesarOrcid::consumeState($st) === false);
check('no state in session refused', SesarOrcid::consumeState('') === false);

$fo = new FakeOrcid(); $orcid = new SesarOrcid($fo);
$id = $orcid->exchangeCode('r2BzDh');
check('good code -> verified identity', $id['orcid'] === '0000-0000-0000-0042' && $id['expires_at'] > time() && strpos($id['id_token'], 'eyJ') === 0, $id);
$n = $fo->calls;
check('malformed code refused without calling ORCID', throwsKind(function () use ($orcid) { $orcid->exchangeCode('a b<c'); }, 'auth') === true && $fo->calls === $n);
$fo->status = 400;
check('ORCID refusal (reused code) -> friendly auth error', throwsKind(function () use ($orcid) { $orcid->exchangeCode('abc'); }, 'auth', 'expired') === true);
$fo->status = 200;
foreach (array('wrong audience' => array('aud' => 'APP-SOMEONE-ELSE'), 'wrong issuer' => array('iss' => 'https://evil.example'),
		'expired token' => array('exp' => time() - 5)) as $what => $claims) {
	$fo->claims = $claims;
	check("$what refused", throwsKind(function () use ($orcid) { $orcid->exchangeCode('abc'); }, 'auth', 'unexpected') === true);
}
$fo->claims = array();

// ===========================================================================
section('SesarOnboarding: brand-new user, all the way to connected');
$fake = new FakeSesar($STATE, true);
$client = new SesarClient('sandbox', $fake);
$conn = new SesarConnection($db, $client, $KEY);
$ob = new SesarOnboarding($db, $client, $conn, $KEY);
$A = $U[0];
$ident = function ($tok, $orcid, $exp = null) { return array('id_token' => $tok, 'orcid' => $orcid, 'name' => 'X', 'expires_at' => $exp === null ? time() + 86400 : $exp); };

$s = $ob->status($A);
check('fresh user: not_connected, nothing to check', $s['step'] === 'not_connected' && $s['can_check'] === false, $s);
check('request form prefilled from the StraboSpot account', $s['request_form']['first_name'] === 'Onboard' && $s['request_form']['email'] === "onboard$A@test.strabospot.org"
	&& $s['request_form']['institution'] === '' && strpos($s['request_form']['message'], 'StraboSpot') !== false, $s['request_form']);
check('links point at the sandbox app', $s['links']['developer_settings'] === 'https://app-sandbox.geosamples.org/profile/developer-settings', $s['links']);

$s = $ob->acceptOrcid($A, $ident('tok-A', '0000-0000-0000-00A1'));
check('ORCID unknown to SESAR -> no_account, can check again', $s['step'] === 'no_account' && $s['can_check'] === true && $s['orcid'] === '0000-0000-0000-00A1', $s);
$row = $db->get_row_prepared("SELECT * FROM strabosamples.sesar_onboarding WHERE userpkey = $1", array($A));
check('ORCID id_token stored encrypted', strpos($row->id_token_enc, 'v1:') === 0 && strpos($row->id_token_enc, 'tok-A') === false);
check('request_access refused before SESAR says permission is missing',
	throwsKind(function () use ($ob, $A) { $ob->requestAccess($A, array()); }, 'validation') === true);

$fake->addOrcidUser('tok-A', '0000-0000-0000-00A1', false);   // user signed in at SESAR: account exists, no API permission
$s = $ob->check($A);
check('Check again -> no_permission (no new ORCID sign-in needed)', $s['step'] === 'no_permission' && $s['can_check'] === true, $s);

$good = array('first_name' => 'Onboard', 'last_name' => 'Person0', 'email' => 'someone@ku.edu', 'institution' => 'University of Kansas',
	'position_role' => 'Professor', 'message' => 'Please enable the API for StraboSpot.', 'orcid' => '9999-9999-9999-9999');
check('missing field refused', throwsKind(function () use ($ob, $A, $good) { $f = $good; $f['institution'] = ' '; $ob->requestAccess($A, $f); }, 'validation') === true);
check('bad email refused', throwsKind(function () use ($ob, $A, $good) { $f = $good; $f['email'] = 'not-an-email'; $ob->requestAccess($A, $f); }, 'validation') === true);
check('over-long message refused', throwsKind(function () use ($ob, $A, $good) { $f = $good; $f['message'] = str_repeat('x', 6000); $ob->requestAccess($A, $f); }, 'validation') === true);
check('no request reached SESAR for invalid input', count($fake->state()['access_requests']) === 0);

$s = $ob->requestAccess($A, $good);
$reqs = $fake->state()['access_requests'];
check('request sent', $s['request_result'] === 'sent' && count($reqs) === 1 && $s['access_requested_at'] !== null, $s);
check('request carries the VERIFIED ORCID, not a client-supplied one', $reqs[0]['orcid'] === '0000-0000-0000-00A1', $reqs[0]);
check('request carries the form fields', $reqs[0]['institution'] === 'University of Kansas' && $reqs[0]['email'] === 'someone@ku.edu' && $reqs[0]['position_role'] === 'Professor');
check('institution + role remembered for next time', $s['request_form']['institution'] === 'University of Kansas' && $s['request_form']['position_role'] === 'Professor');
check('repeat request within a day refused (SESAR staff read each one)',
	throwsKind(function () use ($ob, $A, $good) { $ob->requestAccess($A, $good); }, 'validation', 'already sent') === true
	&& count($fake->state()['access_requests']) === 1);

$s = $ob->check($A);
check('still waiting on SESAR -> no_permission, request date kept', $s['step'] === 'no_permission' && $s['access_requested_at'] !== null);
$fake->grantUpload('0000-0000-0000-00A1');   // SESAR staff approve
$s = $ob->check($A);
check('after approval, Check again -> connected with codes', $s['step'] === 'connected' && $s['sesar_codes'] === array('IEFAK'), $s);
check('SESAR account identity shown (real auth/user shape)', $s['sesar_account']['name'] === 'User, Fake' && strpos($s['sesar_account']['email'], '@example.org') !== false, $s['sesar_account']);
$row = $db->get_row_prepared("SELECT * FROM strabosamples.sesar_onboarding WHERE userpkey = $1", array($A));
check('ORCID id_token dropped once connected', $row->id_token_enc === null && $row->stage === 'connected');
check('status never carries a token', strpos(json_encode($s), 'eyJ') === false && strpos(json_encode($s), 'tok-A') === false);

// ===========================================================================
section('SesarOnboarding: in-app SESAR code creation');
$B = $U[1];
$fake->addOrcidUser('tok-B', '0000-0000-0000-00B1', true, array());
$s = $ob->acceptOrcid($B, $ident('tok-B', '0000-0000-0000-00B1'));
check('connected without a code -> no_code', $s['step'] === 'no_code' && $s['sesar_codes'] === array(), $s);
check('bad format refused locally', throwsKind(function () use ($ob, $B) { $ob->createCode($B, 'A!'); }, 'validation', '3 letters') === true);
$n = count($fake->calls('sesar-codes/'));
check('taken code -> SESAR reason passed through (bare 400 dict parsed)',
	throwsKind(function () use ($ob, $B) { $ob->createCode($B, 'fak'); }, 'validation', 'already exists') === true);
check('the taken-code attempt did reach SESAR', count($fake->calls('sesar-codes/')) === $n + 1);
$s = $ob->createCode($B, 'b1x');
check('new code created uppercase, user now connected', $s['step'] === 'connected' && $s['sesar_codes'] === array('IEB1X'), $s);
$fake->addOrcidUser('tok-B2', '0000-0000-0000-00B2', true, array());
$B2 = $U[4];
$ob->acceptOrcid($B2, $ident('tok-B2', '0000-0000-0000-00B2'));
$s = $ob->createCode($B2, 'iezz9');
check('pasting the whole code (IEZZ9) works too', $s['sesar_codes'] === array('IEZZ9'), $s);

// ===========================================================================
section('SesarOnboarding: failures, expiry, revocation, disconnect');
$C = $U[2];
$fake->addOrcidUser('tok-C', '0000-0000-0000-00C1', false);
$ob->acceptOrcid($C, $ident('tok-C', '0000-0000-0000-00C1'));
$fake->set('fail_next', array('api-access-request/' => 500));
$s = $ob->requestAccess($C, $good);
check('SESAR refuses the in-app request -> failed + fallback instructions data', $s['request_result'] === 'failed'
	&& $s['access_request_error'] !== null && $s['access_requested_at'] === null && $s['step'] === 'no_permission', $s);

$fake->set('fail_next', array('auth/token/' => 0));
$s = $ob->check($C);
check('SESAR unreachable on Check again -> step kept, error shown', $s['step'] === 'no_permission' && strpos((string)$s['last_error'], 'did not respond') !== false, $s);
$s = $ob->check($C);
check('next successful check clears the error', $s['last_error'] === null && $s['step'] === 'no_permission', $s);

$db->prepare_query("UPDATE strabosamples.sesar_onboarding SET id_token_expires_at = now() - interval '1 minute' WHERE userpkey = $1", array($C));
$n = count($fake->calls());
$s = $ob->check($C);
check('ORCID sign-in expired -> can_check false, step kept, SESAR not called', $s['can_check'] === false && $s['step'] === 'no_permission'
	&& count($fake->calls()) === $n, $s);

// SESAR rejects an access token we still thought valid, but the refresh works: silent recovery.
$fake->expireAccessTokens();
$n = count($fake->calls('auth/token/refresh/'));
$s = $ob->check($A);
check('stale access token at SESAR -> one silent refresh, still connected', $s['step'] === 'connected'
	&& count($fake->calls('auth/token/refresh/')) === $n + 1, $s);

// A connected user whose SESAR tokens stop working (revoked at SESAR).
$D = $U[3];
$fake->addOrcidUser('tok-D', '0000-0000-0000-00D1', true);
$ob->acceptOrcid($D, $ident('tok-D', '0000-0000-0000-00D1'));
$fake->revokeTokensFor('0000-0000-0000-00D1');
$fake->expireAccessTokens();
$s = $ob->check($D);
check('revoked at SESAR, no ORCID sign-in held -> reconnect', $s['step'] === 'reconnect' && $s['can_check'] === false, $s);
$s = $ob->acceptOrcid($D, $ident('tok-D', '0000-0000-0000-00D1'));
check('signing in again restores the connection', $s['step'] === 'connected', $s);
$s = $ob->disconnect($D);
check('disconnect -> not_connected, tokens gone', $s['step'] === 'not_connected'
	&& $db->get_var_prepared("SELECT count(*) FROM strabosamples.sesar_connections WHERE userpkey = $1 AND refresh_token_enc IS NOT NULL", array($D)) == 0, $s);

// ===========================================================================
section('HTTP: pages + endpoint (forged sessions, no SESAR/ORCID calls)');
function forgeSession($pkey) {
	global $sessionFiles;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$path = '/var/lib/php/sessions/sess_' . $sid;
	file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
	chmod($path, 0600); @chown($path, 'www-data'); @chgrp($path, 'www-data');
	$sessionFiles[] = $path;
	return $sid;
}
function http($method, $path, $sid, $json = null) {
	$ch = curl_init('http://localhost' . $path);
	$h = array();
	if ($sid !== null) $h[] = 'Cookie: PHPSESSID=' . $sid;
	$o = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30);
	if ($json !== null) { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = is_string($json) ? $json : json_encode($json); }
	$o[CURLOPT_HTTPHEADER] = $h;
	curl_setopt_array($ch, $o);
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	$headers = substr($raw, 0, $hs); $body = substr($raw, $hs);
	$loc = preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '';
	return array('status' => $code, 'body' => $body, 'json' => json_decode($body, true), 'location' => $loc);
}
$pilot = forgeSession(3);
$other = forgeSession($U[0]);

$r = http('POST', '/sesar_connect.php', null, array('action' => 'status'));
check('endpoint: no session -> 401 JSON', $r['status'] === 401 && $r['json']['error'] === 'not_authenticated', $r['body']);
$r = http('POST', '/sesar_connect.php', $other, array('action' => 'status'));
check('endpoint: non-pilot -> 403', $r['status'] === 403 && $r['json']['error'] === 'not_allowed', $r['body']);
$r = http('GET', '/sesar_connect.php', $pilot);
check('endpoint: GET -> 405', $r['status'] === 405);
$r = http('POST', '/sesar_connect.php', $pilot, 'not json');
check('endpoint: bad JSON -> 400', $r['status'] === 400 && $r['json']['error'] === 'invalid_json');
$r = http('POST', '/sesar_connect.php', $pilot, array('action' => 'explode'));
check('endpoint: unknown action -> 400', $r['status'] === 400 && $r['json']['error'] === 'unknown_action');
$r = http('POST', '/sesar_connect.php', $pilot, array('action' => 'status'));
check('endpoint: pilot status -> 200, step + links, no tokens', $r['status'] === 200 && $r['json']['ok'] === true
	&& isset($r['json']['status']['step'], $r['json']['status']['links']) && strpos($r['body'], 'eyJ') === false, $r['body']);
if (!SesarAccess::devCodePaste()) {
	$r = http('POST', '/sesar_connect.php', $pilot, array('action' => 'dev_code', 'code' => 'abc'));
	check('endpoint: dev_code refused unless $sesar_dev_paste_code', $r['status'] === 403);
} else {
	echo "  (skip) dev_code refusal: \$sesar_dev_paste_code is on in this config\n";
}

$r = http('GET', '/sesar_orcid_start.php', $pilot);
check('popup start: 302 to ORCID with web client + state', $r['status'] === 302 && strpos($r['location'], 'https://orcid.org/oauth/authorize?') === 0
	&& strpos($r['location'], 'client_id=' . urlencode(SesarAccess::orcidClientId())) !== false && preg_match('/state=[0-9a-f]{32}/', $r['location']), $r['location']);
$r = http('GET', '/sesar_orcid_start.php', $other);
check('popup start: non-pilot -> 403', $r['status'] === 403);
$r = http('GET', '/sesar_orcid_callback.php?code=abc&state=forged', $pilot);
check('callback: forged state -> 400, nothing exchanged', $r['status'] === 400 && strpos($r['body'], 'expired or was already used') !== false);
$r = http('GET', '/sesar_orcid_callback.php?error=access_denied&state=x', $pilot);
check('callback: Deny at ORCID -> friendly cancel message', $r['status'] === 200 && strpos($r['body'], 'cancelled') !== false);
check('callback: posts result to the opener only on our origin', strpos($r['body'], 'window.location.origin') !== false);
$r = http('GET', '/sesar_orcid_callback.php?code=abc&state=x', $other);
check('callback: non-pilot -> 403', $r['status'] === 403);

$r = http('GET', '/samples_igsn.php', $pilot);
check('IGSN page renders for the pilot (shell, panel, table, no PHP noise)', $r['status'] === 200 && strpos($r['body'], 'IGSNs at SESAR') !== false
	&& strpos($r['body'], 'id="si-conn"') !== false && strpos($r['body'], 'id="si-rows"') !== false
	&& !preg_match('/(Warning|Notice|Fatal error|Error preparing)/', $r['body']), substr($r['body'], 0, 300));
check('IGSN page embeds no token', strpos($r['body'], 'eyJ') === false);
$r = http('GET', '/samples_igsn.php', $other);
check('IGSN page: non-pilot sees "not available", no panel', $r['status'] === 200 && strpos($r['body'], 'not available for your account') !== false
	&& strpos($r['body'], 'id="si-conn"') === false);
$r = http('GET', '/my_samples.php', $pilot);
check('My Samples: IGSNs button for the pilot', strpos($r['body'], 'href="/samples_igsn.php"') !== false);
$r = http('GET', '/my_samples.php', $other);
check('My Samples: no IGSNs button for others', $r['status'] === 200 && strpos($r['body'], 'href="/samples_igsn.php"') === false);

} finally {
	cleanup();
	@unlink($STATE);
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
