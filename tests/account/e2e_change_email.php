<?php
/**
 * File: tests/account/e2e_change_email.php
 * Description: Hermetic end-to-end test for the self-service email-change
 *              feature. Drives the REAL Apache endpoints over HTTP:
 *                - change_email.php       (logged-in request form, forged session)
 *                - confirmemailchange.php (the /changeemail/{token} commit link)
 *              and asserts the database / Neo4j side effects directly.
 *
 *              Run inside the app container:
 *                docker exec strabo-php php /srv/app/www/tests/account/e2e_change_email.php
 *
 *              Hermetic: seeds three throwaway users (prefix below), exercises
 *              every path, then removes all DB + Neo4j residue. Safe to re-run.
 *
 *              Note: on a dev box with no SMTP password configured, the actual
 *              mail send fails at auth (no message leaves) - the confirm path
 *              swallows it (best-effort notify), and the request-form path's
 *              insert still commits before the send is attempted. Assertions key
 *              on DB/Neo4j state, not on mail delivery, so the suite is green on
 *              dev and on a mail-configured host alike.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 */

include "/srv/app/www/includes/config.inc.php";
include "/srv/app/www/db.php";
include "/srv/app/www/neodb.php";

$BASE    = "http://localhost";
$SESSDIR = "/var/lib/php/sessions";
$PREFIX  = "e2echgmail";
$rand    = substr(bin2hex(random_bytes(6)), 0, 10);

$pass = 0; $fail = 0;
function ok($cond, $msg){ global $pass, $fail; if($cond){ $pass++; echo "  PASS  $msg\n"; } else { $fail++; echo "  FAIL  $msg\n"; } }
function section($t){ echo "\n== $t ==\n"; }
// A clean page must carry no leaked PHP notices/warnings (e.g. the function-scope
// DB-connection regression that produced "Require $dbuser..." / "Error preparing").
function clean_body($b){ return stripos($b, "Warning:")===false && stripos($b, "Error preparing")===false && stripos($b, "Require \$dbuser")===false && stripos($b, "Notice:")===false; }

function http($url, $opts = array()){
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	if(!empty($opts['post'])){
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['post']));
	}
	if(!empty($opts['sid'])){
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Cookie: PHPSESSID=".$opts['sid']));
	}
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array($code, (string)$body);
}

// ---- Seed helpers -----------------------------------------------------------

$KNOWN_PASS = "TestPass123!";
$seeded_pkeys = array();
$seeded_emails = array();

function seedUser($email){
	global $db, $neodb, $KNOWN_PASS, $rand, $seeded_pkeys, $seeded_emails;
	$hash = substr(md5($email.$rand), 0, 21);
	$db->prepare_query(
		"INSERT INTO users (firstname,lastname,password,hash,email,active,deleted) VALUES ($1,$2,crypt($3,gen_salt('md5')),$4,$5,true,false)",
		array("E2E", "ChgEmail", $KNOWN_PASS, $hash, $email)
	);
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1", array($email));
	$neodb->createNode(json_encode(array("userpkey"=>$pkey, "email"=>$email, "firstname"=>"E2E", "lastname"=>"ChgEmail")), "User");
	$seeded_pkeys[] = $pkey;
	$seeded_emails[] = $email;
	return $pkey;
}

function forgeSession($pkey, $email){
	global $SESSDIR, $rand;
	$sid = "e2echg".$rand.substr(md5($email), 0, 8);
	$p  = "";
	$p .= "loggedin|".serialize("yes");
	$p .= "LAST_ACTIVITY|".serialize(time());
	$p .= "userpkey|".serialize((string)$pkey);
	$p .= "username|".serialize($email);
	$p .= "loggedin_username|".serialize($email);
	$p .= "firstname|".serialize("E2E");
	$p .= "lastname|".serialize("ChgEmail");
	$p .= "userlevel|".serialize("user");
	$f = "$SESSDIR/sess_$sid";
	file_put_contents($f, $p);
	@chmod($f, 0666);
	return array($sid, $f);
}

// =============================================================================
echo "StraboSpot self-service email-change E2E  (rand=$rand)\n";

$emailA_old = "$PREFIX-a-old-$rand@example.com";
$emailA_new = "$PREFIX-a-new-$rand@example.com";
$emailB_old = "$PREFIX-b-old-$rand@example.com";
$emailB_new = "$PREFIX-b-new-$rand@example.com";
$emailDecoy = "$PREFIX-decoy-$rand@example.com";

$pkeyA = seedUser($emailA_old);
$pkeyB = seedUser($emailB_old);
$pkeyD = seedUser($emailDecoy);
echo "Seeded users: A=$pkeyA  B=$pkeyB  decoy=$pkeyD\n";

// -----------------------------------------------------------------------------
section("confirmemailchange.php - negative cases");

// C1: a syntactically valid but non-existent token
list($c, $b) = http("$BASE/changeemail/".str_repeat("a", 64));
ok($c==200 && strpos($b, "Expired or Invalid")!==false, "C1 nonexistent token -> Expired or Invalid (code=$c)");
ok(clean_body($b), "C1 page is free of PHP warnings/notices");

// C2: an expired token
$tokExpired = bin2hex(random_bytes(32));
$db->prepare_query(
	"INSERT INTO email_change_requests (userpkey,new_email,token,expires_at) VALUES ($1,$2,$3, now() - interval '1 hour')",
	array($pkeyA, $emailA_new, $tokExpired)
);
list($c, $b) = http("$BASE/changeemail/$tokExpired");
ok($c==200 && strpos($b, "Expired or Invalid")!==false, "C2 expired token -> Expired or Invalid");
$used = $db->get_var_prepared("SELECT used_at FROM email_change_requests WHERE token=$1", array($tokExpired));
ok($used=="", "C2 expired token left untouched (not consumed)");

// C3: target address already belongs to another account
$tokTaken = bin2hex(random_bytes(32));
$db->prepare_query(
	"INSERT INTO email_change_requests (userpkey,new_email,token,expires_at) VALUES ($1,$2,$3, now() + interval '1 hour')",
	array($pkeyA, $emailDecoy, $tokTaken)
);
list($c, $b) = http("$BASE/changeemail/$tokTaken");
ok($c==200 && strpos($b, "No Longer Available")!==false, "C3 taken target -> No Longer Available");
ok(clean_body($b), "C3 page is free of PHP warnings/notices");
$used = $db->get_var_prepared("SELECT used_at FROM email_change_requests WHERE token=$1", array($tokTaken));
ok($used!="", "C3 stale request burned (used_at set)");
$stillOld = $db->get_var_prepared("SELECT email FROM users WHERE pkey=$1", array($pkeyA));
ok($stillOld==$emailA_old, "C3 account email unchanged after rejection");

// -----------------------------------------------------------------------------
section("confirmemailchange.php - happy path commit");

// Give user A an app token (keyed by email) to prove re-keying.
$apptoken = "e2e-apptok-$rand";
$db->prepare_query("INSERT INTO apptokens (uuid,email) VALUES ($1,$2)", array($apptoken, $emailA_old));

$tokGood = bin2hex(random_bytes(32));
$db->prepare_query(
	"INSERT INTO email_change_requests (userpkey,new_email,token,expires_at) VALUES ($1,$2,$3, now() + interval '1 hour')",
	array($pkeyA, $emailA_new, $tokGood)
);
list($c, $b) = http("$BASE/changeemail/$tokGood");
ok($c==200 && strpos($b, "Email Address Updated")!==false, "C4 valid token -> Email Address Updated (code=$c)");
ok(clean_body($b), "C4 success page is free of PHP warnings/notices");

$newdbemail = $db->get_var_prepared("SELECT email FROM users WHERE pkey=$1", array($pkeyA));
ok($newdbemail==$emailA_new, "C4 users.email updated to new address");

$tokEmail = $db->get_var_prepared("SELECT email FROM apptokens WHERE uuid=$1", array($apptoken));
ok($tokEmail==$emailA_new, "C4 apptokens re-keyed to new address");

$neoEmail = $neodb->get_var("match (u:User) where u.userpkey=$pkeyA return u.email");
ok($neoEmail==$emailA_new, "C4 Neo4j User node email updated");

$used = $db->get_var_prepared("SELECT used_at FROM email_change_requests WHERE token=$1", array($tokGood));
ok($used!="", "C4 request marked used");

// C5: the link is single-use
list($c, $b) = http("$BASE/changeemail/$tokGood");
ok($c==200 && strpos($b, "Expired or Invalid")!==false, "C5 reused token rejected");
$afterReuse = $db->get_var_prepared("SELECT email FROM users WHERE pkey=$1", array($pkeyA));
ok($afterReuse==$emailA_new, "C5 reuse did not re-trigger a change");

// -----------------------------------------------------------------------------
section("change_email.php - request form (forged session)");

list($sidB, $sessfileB) = forgeSession($pkeyB, $emailB_old);

function pendingCount($pkey, $newemail){
	global $db;
	return (int)$db->get_var_prepared(
		"SELECT count(*) FROM email_change_requests WHERE userpkey=$1 AND new_email=$2 AND used_at IS NULL AND expires_at > now()",
		array($pkey, $newemail)
	);
}

// F1: wrong current password is rejected, no request created
list($c, $b) = http("$BASE/change_email.php", array('sid'=>$sidB, 'post'=>array('submit'=>'Submit','newemail'=>$emailB_new,'currentpassword'=>'wrongpass')));
ok(strpos($b, "Incorrect current password")!==false, "F1 wrong password -> error shown");
ok(pendingCount($pkeyB, $emailB_new)==0, "F1 no pending request created on bad password");

// F2: new email same as current is rejected
list($c, $b) = http("$BASE/change_email.php", array('sid'=>$sidB, 'post'=>array('submit'=>'Submit','newemail'=>$emailB_old,'currentpassword'=>$KNOWN_PASS)));
ok(strpos($b, "already your current email")!==false, "F2 same-as-current -> error shown");
ok(pendingCount($pkeyB, $emailB_old)==0, "F2 no pending request for same address");

// F3: an address already registered to someone else is rejected
list($c, $b) = http("$BASE/change_email.php", array('sid'=>$sidB, 'post'=>array('submit'=>'Submit','newemail'=>$emailDecoy,'currentpassword'=>$KNOWN_PASS)));
ok(strpos($b, "already registered to another")!==false, "F3 duplicate address -> error shown");
ok(pendingCount($pkeyB, $emailDecoy)==0, "F3 no pending request for duplicate address");

// F4: valid request creates exactly one pending row (mail send is best-effort)
list($c, $b) = http("$BASE/change_email.php", array('sid'=>$sidB, 'post'=>array('submit'=>'Submit','newemail'=>$emailB_new,'currentpassword'=>$KNOWN_PASS)));
ok(pendingCount($pkeyB, $emailB_new)==1, "F4 valid submit -> one pending request created");
$tokRow = $db->get_row_prepared("SELECT token, expires_at > now() AS future FROM email_change_requests WHERE userpkey=$1 AND new_email=$2 AND used_at IS NULL", array($pkeyB, $emailB_new));
ok(strlen($tokRow->token)==64 && ctype_xdigit($tokRow->token), "F4 token is 64 hex chars");
ok($tokRow->future=='t' || $tokRow->future===true, "F4 expiry is in the future");
ok($db->get_var_prepared("SELECT email FROM users WHERE pkey=$1", array($pkeyB))==$emailB_old, "F4 account email NOT changed yet (still pending)");

// F5: a second valid request supersedes the first (one active at a time)
$firstTok = $tokRow->token;
list($c, $b) = http("$BASE/change_email.php", array('sid'=>$sidB, 'post'=>array('submit'=>'Submit','newemail'=>$emailB_new,'currentpassword'=>$KNOWN_PASS)));
$firstUsed = $db->get_var_prepared("SELECT used_at FROM email_change_requests WHERE token=$1", array($firstTok));
ok($firstUsed!="", "F5 prior request superseded (first token consumed)");
ok(pendingCount($pkeyB, $emailB_new)==1, "F5 still exactly one active request");

// =============================================================================
section("cleanup");
@unlink($sessfileB);
foreach($seeded_emails as $e){
	$db->prepare_query("DELETE FROM apptokens WHERE email=$1", array($e));
}
foreach($seeded_pkeys as $pk){
	$db->prepare_query("DELETE FROM email_change_requests WHERE userpkey=$1", array($pk));
	$neodb->query("match (u:User) where u.userpkey=$pk detach delete u");
}
// also catch the apptoken that was re-keyed to the new address
$db->prepare_query("DELETE FROM apptokens WHERE email=$1 OR uuid=$2", array($emailA_new, "e2e-apptok-$rand"));
$db->prepare_query("DELETE FROM users WHERE email LIKE $1", array("$PREFIX-%-$rand@example.com"));
$residUsers = (int)$db->get_var_prepared("SELECT count(*) FROM users WHERE email LIKE $1", array("$PREFIX-%-$rand@example.com"));
$residReq   = (int)$db->get_var_prepared("SELECT count(*) FROM email_change_requests WHERE userpkey = ANY($1)", array("{".implode(",", $seeded_pkeys)."}"));
ok($residUsers==0, "cleanup: no residual users");
ok($residReq==0, "cleanup: no residual requests");
echo "  (removed users for pkeys: ".implode(", ", $seeded_pkeys).")\n";

// =============================================================================
echo "\n========================================\n";
echo "RESULT: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail==0 ? 0 : 1);
