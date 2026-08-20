<?php
/**
 * File: smoke_test_versions_api.php
 * Description: HTTP-layer smoke test for the read-only /versionsdb/ API.
 *
 *              Covers:
 *                auth      — 401 unauthenticated / wrong password; password
 *                            and apptoken login paths both work
 *                myprojects — distinct project list, per-user isolation,
 *                            version counts, newest-first ordering
 *                projectversions — full metadata rows ordered by pkey;
 *                            foreign and unknown projectids 404 identically;
 *                            empty projectid 400
 *                version   — snapshot JSON round-trips (identity encoding),
 *                            gzip passthrough (Content-Encoding: gzip, raw
 *                            bytes = stored file); foreign, unknown,
 *                            malformed, and traversal uuids all 404 with the
 *                            same body (existence hiding); DB row with a
 *                            missing file 404s
 *                verbs     — POST/PUT/DELETE/HEAD all 405 (read-only API);
 *                            unknown controller 404
 *
 *              Hermetic: seeds its own users, versions rows, and snapshot
 *              files; everything is removed in cleanup (runs even on
 *              failure). No Neo4j involvement.
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/tests/versionsapi/smoke_test_versions_api.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$BASE = "http://localhost";
$VDIR = "/srv/app/www/versions";

$pass = 0; $fail = 0; $failures = array();
function check($label, $cond) {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else { $fail++; $failures[] = $label; echo "  \033[31mFAIL\033[0m  $label\n"; }
}
function section($t){ echo "\n\033[1;34m== $t ==\033[0m\n"; }

// $opts: basic, headers (array), post (bool), method (string)
// returns array(code, body, response headers string)
function http($url, $opts = array()){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if (!empty($opts['basic']))   { curl_setopt($ch, CURLOPT_USERPWD, $opts['basic']); }
    if (!empty($opts['headers'])) { curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']); }
    if (!empty($opts['method']))  { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method']); }
    if (!empty($opts['nobody']))  { curl_setopt($ch, CURLOPT_NOBODY, true); }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return array($code, substr((string)$resp, $hsize), substr((string)$resp, 0, $hsize));
}

// ---- Fixtures ---------------------------------------------------------------
$rand = substr(md5(uniqid("", true)), 0, 8);
$KNOWN_PASS  = "vapi-".$rand."-pw";
$ownerEmail  = "vapi-owner-$rand@example.com";
$otherEmail  = "vapi-other-$rand@example.com";

$seeded_pkeys = array(); $seeded_uuids = array(); $seeded_files = array();
$apptoken_uuid = "";

function seedUser($email){
    global $db, $KNOWN_PASS, $rand, $seeded_pkeys;
    $hash = substr(md5($email.$rand), 0, 21);
    $db->prepare_query(
        "INSERT INTO users (firstname,lastname,password,hash,email,active,deleted) VALUES ($1,$2,crypt($3,gen_salt('md5')),$4,$5,true,false)",
        array("VAPI", "Test", $KNOWN_PASS, $hash, $email)
    );
    $pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email=$1", array($email));
    $seeded_pkeys[] = $pkey;
    return $pkey;
}

function randUuid(){
    return sprintf('%s-%s-4%s-8%s-%s',
        bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
        substr(bin2hex(random_bytes(2)), 0, 3),
        substr(bin2hex(random_bytes(2)), 0, 3), bin2hex(random_bytes(6)));
}

function seedVersion($projectid, $userpkey, $projectname, $spots, $datasets, $daysAgo, $writeFile, $payload){
    global $db, $VDIR, $seeded_uuids, $seeded_files;
    $uuid = randUuid();
    $db->prepare_query(
        "INSERT INTO versions (projectid, datecreated, uuid, userpkey, projectname, spotcount, datasetcount)
         VALUES ($1, now() - ($2 || ' days')::interval, $3, $4, $5, $6, $7)",
        array($projectid, $daysAgo, $uuid, $userpkey, $projectname, $spots, $datasets)
    );
    $seeded_uuids[] = $uuid;
    if ($writeFile) {
        $f = "$VDIR/$uuid";
        file_put_contents($f, gzencode(json_encode($payload, JSON_PRETTY_PRINT)));
        chmod($f, 0644);
        $seeded_files[] = $f;
    }
    return $uuid;
}

function cleanup(){
    global $db, $seeded_pkeys, $seeded_uuids, $seeded_files, $apptoken_uuid;
    foreach ($seeded_files as $f) { @unlink($f); }
    foreach ($seeded_uuids as $u) {
        $db->prepare_query("DELETE FROM versions WHERE uuid = $1", array($u));
    }
    if ($apptoken_uuid !== "") {
        $db->prepare_query("DELETE FROM apptokens WHERE uuid = $1", array($apptoken_uuid));
    }
    foreach ($seeded_pkeys as $p) {
        $db->prepare_query("DELETE FROM users WHERE pkey = $1", array($p));
    }
}

echo "Seeding fixtures...\n";
$ownerPkey = seedUser($ownerEmail);
$otherPkey = seedUser($otherEmail);

// apptoken for the owner (second auth path)
$apptoken_uuid = randUuid();
$db->prepare_query("INSERT INTO apptokens (uuid, email) VALUES ($1, $2)", array($apptoken_uuid, $ownerEmail));

// Owner: project A with 2 versions (older + newer), project B with 1 version.
// One extra project-A row has NO file on disk (missing-file case).
$projA = "16$rand"."01";  // Field projectids are numeric strings; keep them unique
$projB = "16$rand"."02";
$projStranger = "16$rand"."03";

$payloadA1 = array("id" => $projA, "description" => array("project_name" => "VAPI Project A"),
                   "marker" => "vapi-a1-$rand", "versiondatasets" => array());
$payloadA2 = array("id" => $projA, "description" => array("project_name" => "VAPI Project A"),
                   "marker" => "vapi-a2-$rand", "versiondatasets" => array());
$payloadB1 = array("id" => $projB, "description" => array("project_name" => "VAPI Project B"),
                   "marker" => "vapi-b1-$rand", "versiondatasets" => array());
$payloadS1 = array("id" => $projStranger, "marker" => "vapi-s1-$rand");

$uuidA1 = seedVersion($projA, $ownerPkey, "VAPI Project A", 5, 1, "10", true,  $payloadA1);
$uuidA2 = seedVersion($projA, $ownerPkey, "VAPI Project A", 7, 2, "2",  true,  $payloadA2);
$uuidAMissing = seedVersion($projA, $ownerPkey, "VAPI Project A", 7, 2, "1", false, null);
$uuidB1 = seedVersion($projB, $ownerPkey, "VAPI Project B", 3, 1, "5",  true,  $payloadB1);
$uuidS1 = seedVersion($projStranger, $otherPkey, "VAPI Stranger Project", 9, 3, "3", true, $payloadS1);

$OWNER = "$ownerEmail:$KNOWN_PASS";
$OTHER = "$otherEmail:$KNOWN_PASS";

try {

// ---- Auth -------------------------------------------------------------------
section("auth");
list($c, ) = http("$BASE/versionsdb/myprojects");
check("unauthenticated myprojects 401", $c == 401);
list($c, ) = http("$BASE/versionsdb/version/$uuidA1");
check("unauthenticated version 401", $c == 401);
list($c, ) = http("$BASE/versionsdb/myprojects", array('basic' => "$ownerEmail:wrong-password"));
check("wrong password 401", $c == 401);
list($c, $b) = http("$BASE/versionsdb/myprojects", array('basic' => $OWNER));
check("password auth 200", $c == 200);
list($c, $b) = http("$BASE/versionsdb/myprojects", array('basic' => "$ownerEmail:$apptoken_uuid"));
check("apptoken auth 200", $c == 200);

// ---- myprojects -------------------------------------------------------------
section("myprojects");
list($c, $b) = http("$BASE/versionsdb/myprojects", array('basic' => $OWNER));
$j = json_decode($b, true);
check("returns JSON with projects array", is_array($j) && isset($j['projects']));
check("output is pretty-printed", strpos($b, "\n    ") !== false);
$projects = isset($j['projects']) ? $j['projects'] : array();
$ids = array_map(function($p){ return $p['projectid']; }, $projects);
check("owner sees exactly projects A and B", count($projects) == 2 && in_array($projA, $ids) && in_array($projB, $ids));
check("stranger project NOT listed", !in_array($projStranger, $ids));
$byId = array();
foreach ($projects as $p) { $byId[$p['projectid']] = $p; }
check("project A versioncount = 3 (incl. missing-file row)", isset($byId[$projA]) && $byId[$projA]['versioncount'] == 3);
check("project B versioncount = 1", isset($byId[$projB]) && $byId[$projB]['versioncount'] == 1);
check("projectname carried through", isset($byId[$projA]) && $byId[$projA]['projectname'] == "VAPI Project A");
check("ordering newest-first (A before B)", count($ids) == 2 && $ids[0] == $projA && $ids[1] == $projB);

list($c, $b) = http("$BASE/versionsdb/myprojects", array('basic' => $OTHER));
$j = json_decode($b, true);
$oids = array_map(function($p){ return $p['projectid']; }, isset($j['projects']) ? $j['projects'] : array());
check("stranger sees only their own project", count($oids) == 1 && $oids[0] == $projStranger);

// ---- projectversions --------------------------------------------------------
section("projectversions");
list($c, $b) = http("$BASE/versionsdb/projectversions/$projA", array('basic' => $OWNER));
$j = json_decode($b, true);
check("project A returns 200 with 3 versions", $c == 200 && isset($j['versions']) && count($j['versions']) == 3);
$v0 = isset($j['versions'][0]) ? $j['versions'][0] : array();
$expected_cols = array('pkey','projectid','datecreated','uuid','userpkey','projectname','spotcount','datasetcount');
$allcols = true;
foreach ($expected_cols as $col) { if (!array_key_exists($col, $v0)) { $allcols = false; } }
check("all versions-table metadata columns present", $allcols);
$pkeys = array_map(function($v){ return $v['pkey']; }, $j['versions']);
$sorted = $pkeys; sort($sorted);
check("versions ordered by pkey asc", $pkeys === $sorted);
$vuuids = array_map(function($v){ return $v['uuid']; }, $j['versions']);
check("row uuids match seeded uuids", in_array($uuidA1, $vuuids) && in_array($uuidA2, $vuuids) && in_array($uuidAMissing, $vuuids));
check("spotcount/datasetcount are ints", $v0['spotcount'] === 5 && $v0['datasetcount'] === 1);

list($c, $b) = http("$BASE/versionsdb/projectversions/$projStranger", array('basic' => $OWNER));
$foreignBody = $b;
check("foreign projectid 404s for owner", $c == 404);
list($c, $b) = http("$BASE/versionsdb/projectversions/nope-$rand", array('basic' => $OWNER));
check("unknown projectid 404s", $c == 404);
check("foreign and unknown projectid look alike (existence hiding)",
    str_replace($projStranger, "X", $foreignBody) == str_replace("nope-$rand", "X", $b));
list($c, $b) = http("$BASE/versionsdb/projectversions/", array('basic' => $OWNER));
check("empty projectid 400s", $c == 400);

// ---- version ----------------------------------------------------------------
section("version");
// identity encoding: full decoded JSON round-trip
list($c, $b, $h) = http("$BASE/versionsdb/version/$uuidA1", array('basic' => $OWNER));
$j = json_decode($b, true);
check("version 200 + JSON content-type", $c == 200 && stripos($h, 'Content-Type: application/json') !== false);
check("payload round-trips (marker + name intact)",
    is_array($j) && $j['marker'] == "vapi-a1-$rand" && $j['description']['project_name'] == "VAPI Project A");

// gzip passthrough: raw bytes must equal the stored file exactly
list($c, $b, $h) = http("$BASE/versionsdb/version/$uuidA2",
    array('basic' => $OWNER, 'headers' => array('Accept-Encoding: gzip')));
check("gzip passthrough advertises Content-Encoding: gzip", stripos($h, 'Content-Encoding: gzip') !== false);
check("gzip passthrough bytes identical to stored file", $b === file_get_contents("$VDIR/$uuidA2"));
$decoded = json_decode(gzdecode($b), true);
check("gzip passthrough decodes to the right payload", is_array($decoded) && $decoded['marker'] == "vapi-a2-$rand");

// authorization + existence hiding
list($c, $foreign) = http("$BASE/versionsdb/version/$uuidS1", array('basic' => $OWNER));
check("foreign uuid 404s for owner", $c == 404);
$ghost = randUuid();
list($c, $unknown) = http("$BASE/versionsdb/version/$ghost", array('basic' => $OWNER));
check("unknown well-formed uuid 404s", $c == 404);
check("foreign and unknown uuid bodies identical (existence hiding)", $foreign === $unknown);
list($c, $malformed) = http("$BASE/versionsdb/version/not-a-uuid", array('basic' => $OWNER));
check("malformed uuid 404s with same body", $c == 404 && $malformed === $unknown);
list($c, ) = http("$BASE/versionsdb/version/..%2F..%2Fincludes%2Fconfig.inc.php", array('basic' => $OWNER));
check("traversal attempt 404s", $c == 404);
list($c, ) = http("$BASE/versionsdb/version/$uuidAMissing", array('basic' => $OWNER));
check("DB row with missing file 404s", $c == 404);
list($c, ) = http("$BASE/versionsdb/version/", array('basic' => $OWNER));
check("empty uuid 404s", $c == 404);

// stranger can read their own
list($c, $b) = http("$BASE/versionsdb/version/$uuidS1", array('basic' => $OTHER));
$j = json_decode($b, true);
check("stranger reads their own version fine", $c == 200 && $j['marker'] == "vapi-s1-$rand");

// ---- verbs + routing --------------------------------------------------------
section("verbs + routing");
list($c, ) = http("$BASE/versionsdb/myprojects", array('basic' => $OWNER, 'method' => 'POST'));
check("POST myprojects 405", $c == 405);
list($c, ) = http("$BASE/versionsdb/version/$uuidA1", array('basic' => $OWNER, 'method' => 'DELETE'));
check("DELETE version 405", $c == 405);
list($c, ) = http("$BASE/versionsdb/projectversions/$projA", array('basic' => $OWNER, 'method' => 'PUT'));
check("PUT projectversions 405", $c == 405);
list($c, ) = http("$BASE/versionsdb/myprojects", array('basic' => $OWNER, 'nobody' => true));
check("HEAD myprojects 405 (no fatal)", $c == 405);
list($c, $b) = http("$BASE/versionsdb/nosuchendpoint", array('basic' => $OWNER));
check("unknown endpoint 404 (authed)", $c == 404 && strpos($b, "No such function") !== false);

} finally {
    echo "\nCleaning up fixtures...\n";
    cleanup();
}

// ---- Summary ----------------------------------------------------------------
echo "\n\033[1m$pass passed, $fail failed\033[0m\n";
if ($fail > 0) {
    foreach ($failures as $f) { echo "  \033[31mFAILED:\033[0m $f\n"; }
    exit(1);
}
exit(0);
