<?php
/**
 * Regression: SQL injection in fullsearch Field constraint builder
 *
 * /fullsearch/fullsearch.php is an unauthenticated endpoint. Its Field query
 * builder (includes/straboClasses/searchQueryRowBuilder.php) interpolated
 * constraintValue directly into SQL for every branch except keyword:
 *   - string context: rockType, metFacies, imageType, sampleID
 *   - numeric context: owner, minYear/maxYear, tectonicProvince (gid, which
 *     also flowed into a get_var lookup -> second query injectable)
 *
 * Fix: pg_escape_string() on string-context values (matching the existing
 * keyword branch), (int) cast on numeric-context values.
 *
 * Part 1 drives the builder directly and asserts payloads are neutralized in
 * the generated SQL while benign values produce the same fragments as before.
 * Part 2 POSTs to the live endpoint and asserts boolean-injection payloads
 * return zero results instead of the whole public dataset.
 *
 * Usage: docker exec strabo-php php /srv/app/www/tests/fullsearch/repro_fullsearch_sqli.php
 *
 * Read-only: no rows are written anywhere.
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('includes/straboClasses/searchQueryRowBuilder.php');

$passed = 0;
$failed = 0;

function line($s = '') { echo $s . "\n"; }

function check($label, $cond) {
	global $passed, $failed;
	if ($cond) {
		$passed++;
		line("  PASS: $label");
	} else {
		$failed++;
		line("  FAIL: $label");
	}
}

// Build the request JSON the UI/API sends for a single constraint row.
function constraintJson($type, $value, $qualifier = 'and', $extra = null) {
	$constraints = array(array('constraintType' => $type, 'constraintValue' => $value));
	if ($extra !== null) {
		$constraints[] = array('constraintType' => $extra[0], 'constraintValue' => $extra[1]);
	}
	return json_encode(array('searchType' => 'count', 'params' => array(array(
		'num' => 0,
		'qualifier' => $qualifier,
		'constraints' => $constraints,
	))));
}

$builder = new searchQueryRowBuilder();
$builder->setDb($db);

line("=== Part 1: builder-level — payloads neutralized, benign values unchanged ===");

// rockType
$sql = $builder->buildSearchQueryRows(constraintJson('rockType', 'granite'));
check('rockType benign produces exact fragment', strpos($sql, "rock_type.strabo_rock_type = 'granite'") !== false);

$payload = "x') OR ('1'='1";
$sql = $builder->buildSearchQueryRows(constraintJson('rockType', $payload));
check('rockType payload does not survive raw', strpos($sql, $payload) === false);
check('rockType payload is quote-escaped', strpos($sql, "x'') OR (''1''=''1") !== false);

// metFacies
$sql = $builder->buildSearchQueryRows(constraintJson('metFacies', $payload));
check('metFacies payload does not survive raw', strpos($sql, $payload) === false);

// imageType
$sql = $builder->buildSearchQueryRows(constraintJson('imageType', $payload));
check('imageType payload does not survive raw', strpos($sql, $payload) === false);

// sampleID (builder lowercases first)
$sqlipayload = "x' or '1'='1";
$sql = $builder->buildSearchQueryRows(constraintJson('sampleID', $sqlipayload));
check('sampleID payload does not survive raw', strpos($sql, $sqlipayload) === false);

// owner (numeric context)
$sql = $builder->buildSearchQueryRows(constraintJson('owner', '123'));
check('owner benign produces exact fragment', strpos($sql, "users.pkey = 123") !== false);

$sql = $builder->buildSearchQueryRows(constraintJson('owner', '0 OR 1=1'));
check('owner payload int-cast to 0', strpos($sql, "users.pkey = 0") !== false);
check('owner payload OR-clause stripped', strpos($sql, "OR 1=1") === false);

// minYear / maxYear (numeric context inside date literals)
$sql = $builder->buildSearchQueryRows(constraintJson('minYear', '2015', 'and', array('maxYear', '2020')));
check('year range benign produces exact fragment', strpos($sql, "BETWEEN '2015-1-1' AND '2020-12-31'") !== false);

$sql = $builder->buildSearchQueryRows(constraintJson('minYear', "2015' AND pg_sleep(5)--", 'and', array('maxYear', "2020' --")));
check('minYear payload int-cast', strpos($sql, "BETWEEN '2015-1-1' AND '2020-12-31'") !== false);
check('minYear payload does not survive raw', strpos($sql, "pg_sleep") === false);

// tectonicProvince — gid feeds a get_var lookup (second-order sink) then ST_Contains.
// UNION probe: pre-fix the lookup returns the injected string and it lands in the
// generated SQL; post-fix the (int) cast reduces it to gid=0 -> no polygon.
$sql = $builder->buildSearchQueryRows(constraintJson('tectonicProvince', "0 UNION SELECT 'INJECTED'"));
check('tectonicProvince UNION probe does not reach output', strpos($sql, 'INJECTED') === false);

// Positive control only where geometry data exists (dev restores carry
// shapegeology with all-NULL the_geom, so the polygon lookup has nothing to
// return there no matter what the code does).
$realgid = $db->get_var("select gid from shapegeology where the_geom is not null and the_geom != '' order by gid limit 1");
if ($realgid) {
	$sql = $builder->buildSearchQueryRows(constraintJson('tectonicProvince', (string)$realgid));
	check('tectonicProvince benign still resolves a polygon', strpos($sql, 'ST_Contains') !== false && strpos($sql, 'POLYGON') !== false);
} else {
	line("  SKIP: tectonicProvince benign polygon control (no populated the_geom rows on this DB)");
}

// keyword — already escaped pre-fix; guard against regression
$sql = $builder->buildSearchQueryRows(constraintJson('keyword', "O'Brien"));
check('keyword quote stays escaped', strpos($sql, "O''Brien") !== false);

// NOT qualifier still produces negation
$sql = $builder->buildSearchQueryRows(constraintJson('rockType', 'granite', 'not'));
check('NOT qualifier produces != fragment', strpos($sql, "rock_type.strabo_rock_type != 'granite'") !== false);

line('');
line("=== Part 2: endpoint-level — boolean injection returns nothing, benign search works ===");

function postSearch($json) {
	$cmd = "curl -s -m 60 -X POST -H 'Content-Type: application/json' --data " . escapeshellarg($json) .
		" http://localhost/fullsearch/fullsearch.php";
	$body = shell_exec($cmd);
	// Pre-existing wart (out of scope here): the Micro/Exp builders emit an
	// empty "AND ( )" group for any non-keyword constraint row, so their
	// queries error and PHP warnings leak before the JSON. Strip the prefix,
	// same as tests/strabosamples/e2e_workflows.php does for save_experiment.
	$start = strpos($body, '{');
	if ($start === false) return null;
	return json_decode(substr($body, $start));
}

$resp = postSearch(constraintJson('keyword', 'zircon'));
check('benign keyword search returns valid JSON with counts', is_object($resp) && isset($resp->straboField->counts));

$resp = postSearch(constraintJson('rockType', "x' OR '1'='1"));
check('rockType boolean injection returns valid JSON', is_object($resp) && isset($resp->straboField->counts));
check('rockType boolean injection yields 0 projects', is_object($resp) && (int)$resp->straboField->counts->projectcount === 0);

$resp = postSearch(constraintJson('owner', '0 OR 1=1'));
check('owner boolean injection yields 0 projects', is_object($resp) && (int)$resp->straboField->counts->projectcount === 0);

line('');
line("Passed: $passed  Failed: $failed");
exit($failed > 0 ? 1 : 0);
?>
