<?php
/**
 * File: smoke_test_sample_landing.php
 * Description: Smoke test for the ?sample_id= entry to the StraboField
 *              dataset landing page (feature: replace the legacy
 *              /spotdetails/?s= sample page).
 *
 *              Covers:
 *                - server-side sample -> (dataset, spot) resolution and the
 *                  highlight_spot_id config the page hands to JS
 *                - dataset_id-only requests still load with NO highlight
 *                - explicit dataset_id wins over sample_id
 *                - error pages: unknown sample, no params at all
 *                - legacy /spotdetails/?s= now 302-redirects to the new page
 *                  (and still serves nothing without ?s=)
 *                - JS assets carry the highlight wiring
 *
 *              Read-only: the Field fixture is DISCOVERED from dev data
 *              (any dataset->spot->sample triple in Neo4j). Zero residue.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fielddatasetdetail/smoke_test_sample_landing.php
 *
 * @package    StraboSpot Web Site
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$BASE = 'http://localhost';
$MISSING_SAMPLE_ID = 999999999999999; // plausible-format id no sample uses

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

/**
 * GET without following redirects. Returns array($status, $body, $headers)
 * where $headers is the raw response-header block.
 */
function httpGet($url) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	$raw = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	$raw = (string)$raw;
	return array($status, substr($raw, $headerSize), substr($raw, 0, $headerSize));
}

// ---------------------------------------------------------------------------
section('0. Discover a dataset->spot->sample fixture from dev data');

$rows = $neodb->get_results("
	MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot)-[:HAS_SAMPLE]->(smp:Sample)
	RETURN smp.id AS sample_id, s.id AS spot_id, d.id AS dataset_id
	LIMIT 1
");
check('0.1 dev data has at least one field sample', $rows && count($rows) > 0);
if (!$rows || count($rows) === 0) {
	echo PHP_EOL . 'Cannot continue without a fixture.' . PHP_EOL;
	exit(1);
}
$sample_id  = $rows[0]->value('sample_id');
$spot_id    = $rows[0]->value('spot_id');
$dataset_id = $rows[0]->value('dataset_id');
echo "  fixture: sample=$sample_id spot=$spot_id dataset=$dataset_id" . PHP_EOL;

// ---------------------------------------------------------------------------
section('1. ?sample_id= loads the dataset page with the spot highlighted');

list($status, $body) = httpGet("$BASE/StraboFieldDatasetDetail/?sample_id=$sample_id");
check('1.1 HTTP 200', $status === 200, "got $status");
check('1.2 no sample-not-found error', strpos($body, 'Sample not found') === false);
check('1.3 config carries the resolved dataset_id', strpos($body, "dataset_id: $dataset_id") !== false);
check('1.4 config carries highlight_spot_id of the containing spot', strpos($body, "highlight_spot_id: $spot_id") !== false);
check('1.5 map root rendered', strpos($body, 'dataset-detail-root') !== false);

// ---------------------------------------------------------------------------
section('2. ?dataset_id= entry unchanged (no highlight)');

list($status, $body) = httpGet("$BASE/StraboFieldDatasetDetail/?dataset_id=$dataset_id");
check('2.1 HTTP 200', $status === 200, "got $status");
check('2.2 highlight_spot_id is null', strpos($body, 'highlight_spot_id: null') !== false);
check('2.3 map root rendered', strpos($body, 'dataset-detail-root') !== false);

list($status, $body) = httpGet("$BASE/StraboFieldDatasetDetail/?dataset_id=$dataset_id&sample_id=$sample_id");
check('2.4 explicit dataset_id wins over sample_id (no highlight)', $status === 200 && strpos($body, 'highlight_spot_id: null') !== false);

// ---------------------------------------------------------------------------
section('3. Error paths');

list($status, $body) = httpGet("$BASE/StraboFieldDatasetDetail/?sample_id=$MISSING_SAMPLE_ID");
check('3.1 unknown sample -> Sample not found page', strpos($body, 'Sample not found') !== false);
check('3.2 unknown sample renders no map root', strpos($body, 'dataset-detail-root') === false);

list($status, $body) = httpGet("$BASE/StraboFieldDatasetDetail/");
check('3.3 no params -> usage error mentions both entries',
	strpos($body, 'dataset_id=XXXXXXXX') !== false && strpos($body, 'sample_id=XXXXXXXX') !== false);

// ---------------------------------------------------------------------------
section('4. Legacy /spotdetails/?s= redirects to the new page');

list($status, $body, $headers) = httpGet("$BASE/spotdetails/?s=$sample_id");
check('4.1 302 redirect', $status === 302, "got $status");
check('4.2 Location points at new page with same sample id',
	stripos($headers, "Location: /StraboFieldDatasetDetail/?sample_id=$sample_id") !== false);

list($status, $body) = httpGet("$BASE/spotdetails/");
check('4.3 no ?s= still serves the legacy empty exit (no redirect)', $status === 200 && trim($body) === '');

// ---------------------------------------------------------------------------
section('5. JS assets carry the highlight wiring');

$spots_js  = (string)file_get_contents('/srv/app/www/StraboFieldDatasetDetail/js/spots.js');
$detail_js = (string)file_get_contents('/srv/app/www/StraboFieldDatasetDetail/js/detail.js');
check('5.1 spots.js exports highlightSpot', strpos($spots_js, 'highlightSpot: highlightSpot') !== false);
check('5.2 spots.js builds a highlight layer', strpos($spots_js, "'highlightLayer'") !== false);
check('5.3 spots.js expands the Samples sidebar section', strpos($spots_js, "expandSection('Samples')") !== false);
check('5.4 detail.js wires highlight_spot_id after spot load', strpos($detail_js, 'cfg.highlight_spot_id') !== false);

// ---------------------------------------------------------------------------
section('RESULT');
if (count($failures) === 0) {
	echo '  ALL CHECKS PASSED' . PHP_EOL;
	exit(0);
}
echo '  ' . count($failures) . ' FAILURE(S):' . PHP_EOL;
foreach ($failures as $f) echo "    - $f" . PHP_EOL;
exit(1);
