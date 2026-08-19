<?php
/**
 * File: tests/session/smoke_test_session_timeout.php
 * Description: Smoke test for the auto-logout warning system. Drives the REAL
 *              Apache endpoints over HTTP with forged session files (the same
 *              technique as tests/account/e2e_change_email.php), so expiry is
 *              tested by back-dating LAST_ACTIVITY instead of waiting 2 hours.
 *
 *              Covers:
 *                - session_time_left.php reports remaining time and NEVER
 *                  counts as activity (the load-bearing pin: a status poll
 *                  that reset LAST_ACTIVITY would keep sessions alive forever)
 *                - session_extend.php resets the clock for valid sessions and
 *                  refuses to resurrect expired/anonymous ones
 *                - expired sessions still bounce off logincheck.php pages
 *                - loggedout.php destroys the session, carries a SAFE return
 *                  URI into $_SESSION['uri'] for login.php, and blocks open
 *                  redirects
 *                - mheader.php injects the countdown JS for logged-in
 *                  visitors only
 *                - the timeout constant is centralized (no drifting 7200s)
 *
 *              Run inside the app container:
 *                docker exec strabo-php php /srv/app/www/tests/session/smoke_test_session_timeout.php
 *
 *              Hermetic: only touches its own forged session files; removes
 *              them on exit. Safe to re-run.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

$BASE    = "http://localhost";
$SESSDIR = "/var/lib/php/sessions";
$WWW     = "/srv/app/www";
$rand    = substr(bin2hex(random_bytes(6)), 0, 10);

include_once("$WWW/includes/session_config.php");
$TIMEOUT = SESSION_IDLE_TIMEOUT;

$pass = 0; $fail = 0;
$forged = array();

function ok($cond, $msg){ global $pass, $fail; if($cond){ $pass++; echo "  PASS  $msg\n"; } else { $fail++; echo "  FAIL  $msg\n"; } }
function section($t){ echo "\n== $t ==\n"; }
function clean_body($b){ return stripos($b, "Warning:")===false && stripos($b, "Notice:")===false && stripos($b, "Fatal error")===false; }

function http($url, $opts = array()){
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	if(!empty($opts['sid'])){
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Cookie: PHPSESSID=".$opts['sid']));
	}
	$raw = (string)curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	return array($code, substr($raw, $hsize), substr($raw, 0, $hsize));
}

function forgeSession($lastActivity){
	global $SESSDIR, $rand, $forged;
	$sid = "sesstest".$rand.substr(md5(uniqid("", true)), 0, 8);
	$p  = "";
	$p .= "loggedin|".serialize("yes");
	$p .= "LAST_ACTIVITY|".serialize((int)$lastActivity);
	$p .= "userpkey|".serialize("999999901");
	$p .= "username|".serialize("sesstest@example.com");
	$p .= "loggedin_username|".serialize("sesstest@example.com");
	$p .= "firstname|".serialize("Sess");
	$p .= "lastname|".serialize("Test");
	$p .= "userlevel|".serialize("user");
	$f = "$SESSDIR/sess_$sid";
	file_put_contents($f, $p);
	@chmod($f, 0666);
	// The sessions dir is sticky (drwx-wx-wt): only a file's OWNER may unlink
	// it. Real sessions belong to www-data; this test runs as root, so hand
	// the forged file to www-data or Apache's session_destroy() cannot work.
	@chown($f, "www-data");
	@chgrp($f, "www-data");
	$forged[] = $f;
	return array($sid, $f);
}

function fileLastActivity($f){
	$c = @file_get_contents($f);
	if($c === false) return null;
	if(preg_match('/LAST_ACTIVITY\|i:(\d+);/', $c, $m)) return (int)$m[1];
	return null;
}

function fileUri($f){
	$c = @file_get_contents($f);
	if($c === false) return null;
	if(preg_match('/uri\|s:\d+:"([^"]*)";/', $c, $m)) return $m[1];
	return null;
}

function newSidFromHeaders($headers){
	if(preg_match('/Set-Cookie:\s*PHPSESSID=([^;\s]+)/i', $headers, $m)) return $m[1];
	return null;
}

// ---------------------------------------------------------------------------
section("session_time_left.php - status without side effects");

list($sid, $f) = forgeSession(time());
list($code, $body) = http("$BASE/session_time_left.php", array("sid"=>$sid));
$d = json_decode($body, true);
ok($code == 200 && is_array($d), "fresh session: 200 + JSON body");
ok($d && $d['loggedin'] === true, "fresh session: loggedin=true");
ok($d && $d['remaining'] > $TIMEOUT - 10 && $d['remaining'] <= $TIMEOUT, "fresh session: remaining ~ full window (got ".($d ? $d['remaining'] : "?").")");

$aged = time() - 1000;
list($sid, $f) = forgeSession($aged);
list($code, $body) = http("$BASE/session_time_left.php", array("sid"=>$sid));
$d = json_decode($body, true);
ok($d && abs($d['remaining'] - ($TIMEOUT - 1000)) <= 10, "aged session: remaining reflects LAST_ACTIVITY (got ".($d ? $d['remaining'] : "?").")");

// THE key pin: polling must not count as activity.
http("$BASE/session_time_left.php", array("sid"=>$sid));
ok(fileLastActivity($f) === $aged, "status poll does NOT touch LAST_ACTIVITY (still $aged)");

// ---------------------------------------------------------------------------
section("session_extend.php - stay logged in");

list($sid, $f) = forgeSession(time() - 1000);
list($code, $body) = http("$BASE/session_extend.php", array("sid"=>$sid));
$d = json_decode($body, true);
ok($code == 200 && $d && $d['ok'] === true, "valid session: extend ok=true");
ok($d && $d['remaining'] == $TIMEOUT, "valid session: extend returns full window");
$la = fileLastActivity($f);
ok($la !== null && abs($la - time()) <= 10, "extend stamps LAST_ACTIVITY to now");

list($code, $body) = http("$BASE/session_time_left.php", array("sid"=>$sid));
$d = json_decode($body, true);
ok($d && $d['remaining'] > $TIMEOUT - 10, "time_left after extend: full window again");

// ---------------------------------------------------------------------------
section("expired session - reported logged out, never resurrected");

$stale = time() - $TIMEOUT - 100;
list($sid, $f) = forgeSession($stale);
list($code, $body) = http("$BASE/session_time_left.php", array("sid"=>$sid));
$d = json_decode($body, true);
ok($d && $d['loggedin'] === false && $d['remaining'] == 0, "expired: time_left says loggedin=false, remaining=0");

list($code, $body) = http("$BASE/session_extend.php", array("sid"=>$sid));
$d = json_decode($body, true);
ok($d && $d['ok'] === false, "expired: extend refuses (ok=false)");
ok(fileLastActivity($f) === $stale, "expired: extend did NOT resurrect LAST_ACTIVITY");

list($code, $body, $headers) = http("$BASE/new_project.php", array("sid"=>$sid));
ok($code == 302 && stripos($headers, "Location:") !== false && stripos($headers, "/login.php") !== false,
	"expired: logincheck page still 302s to /login.php");

// ---------------------------------------------------------------------------
section("anonymous - no session cookie");

list($code, $body) = http("$BASE/session_time_left.php");
$d = json_decode($body, true);
ok($code == 200 && $d && $d['loggedin'] === false && $d['remaining'] == 0, "anonymous: time_left loggedin=false");

list($code, $body) = http("$BASE/session_extend.php");
$d = json_decode($body, true);
ok($d && $d['ok'] === false, "anonymous: extend ok=false");

// ---------------------------------------------------------------------------
section("loggedout.php - destroy + safe return URI");

list($sid, $f) = forgeSession(time());
list($code, $body, $headers) = http("$BASE/loggedout.php?uri=".urlencode("/my_field_data"), array("sid"=>$sid));
ok($code == 200 && stripos($body, "You Have Been Logged Out") !== false, "loggedout.php renders the message");
ok(clean_body($body), "loggedout.php body carries no PHP warnings/notices");
ok(!file_exists($f), "old session file destroyed");
$newsid = newSidFromHeaders($headers);
ok($newsid !== null && $newsid !== $sid, "fresh session id issued");
if($newsid){
	$nf = "$SESSDIR/sess_$newsid";
	$forged[] = $nf;
	ok(fileUri($nf) === "/my_field_data", "return URI carried into fresh session for login.php");
}
ok(stripos($body, "session_timeout.js") === false, "loggedout page does not re-arm the countdown (anonymous header)");

// open-redirect guards
foreach(array("https://evil.example.com/", "//evil.example.com/x", "/ok\\..\\bad") as $bad){
	list($sid, $f) = forgeSession(time());
	list($code, $body, $headers) = http("$BASE/loggedout.php?uri=".urlencode($bad), array("sid"=>$sid));
	$newsid = newSidFromHeaders($headers);
	$got = $newsid ? fileUri("$SESSDIR/sess_$newsid") : null;
	if($newsid) $forged[] = "$SESSDIR/sess_$newsid";
	ok($got === "/", "unsafe uri rejected -> \"/\" (".$bad.")");
}

// ---------------------------------------------------------------------------
section("mheader.php - countdown JS injected for logged-in visitors only");

list($sid, $f) = forgeSession(time());
list($code, $body) = http("$BASE/index.php", array("sid"=>$sid));
ok($code == 200 && stripos($body, "session_timeout.js") !== false, "logged-in page loads session_timeout.js");
ok(stripos($body, "timeoutSeconds: $TIMEOUT") !== false, "straboSessionConfig carries SESSION_IDLE_TIMEOUT ($TIMEOUT)");

list($code, $body) = http("$BASE/index.php");
ok($code == 200 && stripos($body, "session_timeout.js") === false, "anonymous page does NOT load session_timeout.js");

// ---------------------------------------------------------------------------
section("timeout constant centralized (no drifting 7200 literals)");

foreach(array("logincheck.php", "sessioncheck.php", "softlogincheck.php", "session_time_left.php", "session_extend.php") as $src){
	$c = file_get_contents("$WWW/$src");
	ok(strpos($c, "SESSION_IDLE_TIMEOUT") !== false && !preg_match('/>\s*7200\b/', $c),
		"$src uses SESSION_IDLE_TIMEOUT, no literal comparison");
}

// ---------------------------------------------------------------------------
section("cleanup");
$removed = 0;
foreach(array_unique($forged) as $f){ if(@unlink($f)) $removed++; }
echo "  removed $removed forged session file(s)\n";

echo "\n==============================\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);

?>
