<?php
/**
 * Request body parsing by Content-Type, every API's library/Request.php (2026-11-05).
 *
 * "application/json; charset=UTF-8" (documented in api.php, sent by many HTTP libraries) used to leave the
 * JSON body unread: verifyImages then answered "all present" and the app skipped uploads. JSON now matches
 * on the media type alone; form posts keep the exact match (with a charset they stay unparsed and keep the
 * json view, as before).
 *
 * Part 1 loads each Request.php in its own php process with a fake php://input. Part 2 sends real
 * verifyImages requests to /db with Basic Auth (throwaway user, removed at the end).
 *
 *   docker exec strabo-php php /srv/app/www/tests/lib/request_content_type_test.php
 */

$pass = 0; $fail = 0; $fails = array();
function ok($c, $m){ global $pass, $fail, $fails; if($c){ $pass++; echo "  PASS  $m\n"; } else { $fail++; $fails[] = $m; echo "  FAIL  $m\n"; } }

// ---- Part 1: each Request.php ------------------------------------------------------------------
if(($argv[1] ?? '') === '--one'){
	// child: argv = --one <file> <content-type or -> <body>
	class FakeInput {
		public static $body = ''; private $pos = 0; public $context;
		function stream_open($p, $m, $o, &$op){ return true; }
		function stream_read($n){ $r = substr(self::$body, $this->pos, $n); $this->pos += strlen($r); return $r; }
		function stream_eof(){ return $this->pos >= strlen(self::$body); }
		function stream_stat(){ return array(); }
	}
	FakeInput::$body = $argv[4];
	stream_wrapper_unregister('php'); stream_wrapper_register('php', 'FakeInput');
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['QUERY_STRING'] = 'q=1';
	if($argv[3] !== '-') $_SERVER['CONTENT_TYPE'] = $argv[3];
	require $argv[2];
	$r = new Request();
	echo json_encode(array('p' => $r->parameters, 'f' => $r->apiformat));
	exit(0);
}

$cases = array(
	// content type, body, expect parsed?, expected apiformat
	array('application/json', '{"a":"x"}', true, 'json'),
	array('application/json; charset=UTF-8', '{"a":"x"}', true, 'json'),
	array('application/json;charset=utf-8', '{"a":"x"}', true, 'json'),
	array('Application/JSON', '{"a":"x"}', true, 'json'),
	array(' application/json ; charset=utf-8', '{"a":"x"}', true, 'json'),
	array('application/json-patch+json', '{"a":"x"}', false, 'json'),
	array('application/x-www-form-urlencoded', 'a=x', true, 'html'),
	array('application/x-www-form-urlencoded; charset=UTF-8', 'a=x', false, 'json'),
	array('text/plain', '{"a":"x"}', false, 'json'),
	array('-', '{"a":"x"}', false, 'json'),
);
$files = glob('/srv/app/www/*/library/Request.php');
echo "== 1. Request.php copies: " . count($files) . "\n";
ok(count($files) >= 9, "found the API Request.php copies");
foreach($files as $f){
	$short = str_replace('/srv/app/www/', '', $f);
	$bad = array();
	foreach($cases as $c){
		$out = shell_exec('php ' . escapeshellarg(__FILE__) . ' --one ' . escapeshellarg($f) . ' ' . escapeshellarg($c[0]) . ' ' . escapeshellarg($c[1]));
		$j = json_decode($out, true);
		$parsed = isset($j['p']['a']) && $j['p']['a'] === 'x';
		if(!is_array($j) || $parsed !== $c[2] || $j['f'] !== $c[3] || ($j['p']['q'] ?? null) !== '1') $bad[] = "'{$c[0]}' -> " . trim((string)$out);
	}
	ok(!$bad, "$short" . ($bad ? ": " . implode('; ', $bad) : ""));
}

// ---- Part 2: real verifyImages requests ---------------------------------------------------------
include "/srv/app/www/includes/config.inc.php";
include "/srv/app/www/db.php";
include "/srv/app/www/neodb.php";
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
echo "\n== 2. POST /db/verifyImages\n";
$email = "reqct-" . bin2hex(random_bytes(4)) . "@test.strabospot.org";
$db->prepare_query("INSERT INTO users (firstname,lastname,password,hash,email,active,deleted) VALUES ('R','CT',crypt('testpass123',gen_salt('md5')),$1,$2,true,false)", array(md5($email), $email));
$pk = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1", array($email));
$missing = 97798000000001;
function vi($ct, $body){
	global $email;
	$ch = curl_init("http://localhost/db/verifyImages");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_USERPWD, "$email:testpass123");
	curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: $ct"));
	$r = curl_exec($ch); curl_close($ch);
	return trim((string)$r);
}
try {
	foreach(array('application/json', 'application/json; charset=UTF-8', 'application/json;charset=utf-8') as $ct){
		$r = vi($ct, "[$missing]");
		ok($r === "[\"$missing\"]", "'$ct': missing image reported ($r)");
	}
	$r = vi('application/x-www-form-urlencoded; charset=UTF-8', "[$missing]");
	ok($r === '[]', "form post with charset unchanged: body not read, json view ($r)");
} finally {
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($pk));
}

echo "\n" . ($fail ? "FAILED $fail / " . ($pass + $fail) . ":\n  - " . implode("\n  - ", $fails) : "ALL PASS ($pass)") . "\n";
exit($fail ? 1 : 0);
