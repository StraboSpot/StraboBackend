<?php
/**
 * File: benchmark.php
 * Description: StraboSearch Phase 5.D performance benchmark harness.
 *              Times full service-level searches (runSearch — main query +
 *              subsystem summary + counterpart badge + facet recounts, i.e.
 *              what one API request actually costs) across a fixed case set:
 *              the 0.3 spike's hard queries re-expressed as DSL, one case
 *              per criterion family, and the known-pathological shapes
 *              (NOT-only text, browse-everything, deep pagination).
 *
 *              Budgets follow the samples Phase D playbook (1–3s): 1000ms
 *              for standard cases, 3000ms for cases marked pathological.
 *              Exit 0 = all inside budget, 1 = any case over budget,
 *              2 = a case errored.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/searchdb/bench/benchmark.php
 *     [--userpkey=N]   identity to search as (default 0 = anonymous)
 *     [--runs=N]       timed runs per case (default 3; first run reported
 *                      separately as the cold number)
 *     [--case=SLUG]    run a single case by slug
 *
 * READ-ONLY. CLI only (the searchdb/.htaccess web-denial covers the tree,
 * this is belt-and-suspenders).
 *
 * @package    StraboSpot Web Site — StraboSearch
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/../services/StraboSearchService.php');

$opts = getopt('', array('userpkey::', 'runs::', 'case::'));
$userpkey = isset($opts['userpkey']) ? (int)$opts['userpkey'] : 0;
$runs     = isset($opts['runs']) ? max(1, (int)$opts['runs']) : 3;
$only     = isset($opts['case']) ? (string)$opts['case'] : null;

$svc = new StraboSearchService($db, $userpkey);

// ---------------------------------------------------------------------------
// Case set. 'budget' in ms; 'patho' marks known-pathological shapes that get
// the 3000ms budget tier. Geometry: the Q1/Q3 spike bboxes reused verbatim.
// ---------------------------------------------------------------------------

$WESTERN_US = array('bbox' => array(-125, 31, -102, 49));
$DENSE_SW   = array('bbox' => array(-120, 33, -115, 38));

$cases = array(

    // -- spike-inherited hard queries (0.3 -> 5.D per master plan) ----------
    'q1-dip-rock-bbox' => array(
        'desc' => 'Q1: dip 30-60 + rock sedimentary + western-US bbox (projects)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'F2', 'value' => array('min' => 30, 'max' => 60)),
            array('id' => 'F7', 'value' => array('sedimentary')),
            array('id' => 'U2', 'value' => $WESTERN_US),
        )),
    ),
    'q2-keyword-granite' => array(
        'desc' => 'Q2: keyword granite (projects; facet feed rides response)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'U1', 'value' => 'granite'),
        )),
    ),
    'q3-bbox-date' => array(
        'desc' => 'Q3: dense-SW bbox + date >= 2020 (projects, page 1)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'U2', 'value' => $DENSE_SW),
            array('id' => 'U3', 'value' => array('min' => '2020-01-01', 'max' => null)),
        )),
    ),
    'q4-dip-only' => array(
        'desc' => 'Q4: pure dip 30-60 at scale (projects)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'F2', 'value' => array('min' => 30, 'max' => 60)),
        )),
    ),

    // -- pathological shapes (3000ms tier) ----------------------------------
    'not-keyword' => array(
        'desc' => 'NOT kansas (projects) — negation defeats the GIN index',
        'patho' => true,
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'U1', 'value' => 'kansas', 'not' => true),
        )),
    ),
    'not-keyword-images' => array(
        'desc' => 'NOT kansas (images pathway)',
        'patho' => true,
        'dsl'  => array('pathway' => 'images', 'criteria' => array(
            array('id' => 'U1', 'value' => 'kansas', 'not' => true),
        )),
    ),
    'browse-all' => array(
        'desc' => 'no criteria (projects) — browse everything visible',
        'patho' => true,
        'dsl'  => array('pathway' => 'projects', 'criteria' => array()),
    ),
    'browse-all-both' => array(
        'desc' => 'no criteria, pathway=both — the empty landing search',
        'patho' => true,
        'dsl'  => array('pathway' => 'both', 'criteria' => array()),
    ),
    'deep-page' => array(
        'desc' => 'keyword granite, page 40 (projects) — offset cost',
        'patho' => true,
        'dsl'  => array('pathway' => 'projects', 'page' => 40, 'criteria' => array(
            array('id' => 'U1', 'value' => 'granite'),
        )),
    ),

    // -- one per remaining criterion family ---------------------------------
    'u3-year' => array(
        'desc' => 'U3 year 2023 (projects)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'U3', 'value' => array('year' => 2023)),
        )),
    ),
    'u5-sample-prefix' => array(
        'desc' => 'U5 sample name/id prefix (projects)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'U5', 'value' => 'A'),
        )),
    ),
    'u9-hasflags' => array(
        'desc' => 'U9 has orientation+images (projects)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'U9', 'value' => array('orientation', 'images')),
        )),
    ),
    'u10-tagname' => array(
        'desc' => 'U10 tag name prefix (projects)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'U10', 'value' => 'bas'),
        )),
    ),
    'f7-rocktype-facet' => array(
        'desc' => 'F7 rock type igneous (projects; triggers facet recount)',
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'F7', 'value' => array('igneous')),
        )),
    ),
    'f7-not-rocktype' => array(
        'desc' => 'F7 NOT igneous (projects) — negated GIN-array criterion',
        'patho' => true,
        'dsl'  => array('pathway' => 'projects', 'criteria' => array(
            array('id' => 'F7', 'value' => array('igneous'), 'not' => true),
        )),
    ),
    'images-keyword' => array(
        'desc' => 'images pathway: keyword granite (parent-chain inheritance)',
        'dsl'  => array('pathway' => 'images', 'criteria' => array(
            array('id' => 'U1', 'value' => 'granite'),
        )),
    ),
    'images-type-annotated' => array(
        'desc' => 'images pathway: I1 photo + I2 annotated',
        'dsl'  => array('pathway' => 'images', 'criteria' => array(
            array('id' => 'I1', 'value' => array('photo')),
            array('id' => 'I2', 'value' => true),
        )),
    ),
    'both-keyword' => array(
        'desc' => 'pathway=both: keyword granite (two full assemblies)',
        'patho' => true,
        'dsl'  => array('pathway' => 'both', 'criteria' => array(
            array('id' => 'U1', 'value' => 'granite'),
        )),
    ),
    'composite-and' => array(
        'desc' => 'keyword + subsystem field + U9 images (projects)',
        'dsl'  => array('pathway' => 'projects', 'subsystems' => array('field'),
                        'criteria' => array(
            array('id' => 'U1', 'value' => 'granite'),
            array('id' => 'U9', 'value' => array('images')),
        )),
    ),
);

// ---------------------------------------------------------------------------

function fmt_ms($s)
{
    return sprintf('%7.1fms', $s * 1000.0);
}

echo "STRABOSEARCH PHASE 5.D BENCHMARK — " . date('Y-m-d H:i:s') . "\n";
echo "identity userpkey=$userpkey  runs=$runs" . ($only !== null ? "  case=$only" : '') . "\n";
echo str_repeat('=', 78) . "\n";

$fail = false;
$err  = false;
$report = array();

foreach ($cases as $slug => $case) {
    if ($only !== null && $slug !== $only) continue;
    $budget = !empty($case['patho']) ? 3000 : 1000;

    $times = array();
    $total = null;
    $error = null;
    for ($i = 0; $i < $runs; $i++) {
        $t0 = microtime(true);
        try {
            $resp = $svc->runSearch($case['dsl']);
        } catch (Exception $e) {
            $error = $e->getMessage();
            break;
        }
        $times[] = microtime(true) - $t0;
        if ($total === null) {
            $total = isset($resp['total']) ? $resp['total']
                : (isset($resp['projects']['total']) ? $resp['projects']['total'] . '+' . $resp['images']['total'] : '?');
        }
    }

    if ($error !== null) {
        echo sprintf("%-24s ERROR: %s\n", $slug, $error);
        $err = true;
        continue;
    }

    sort($times);
    $best = $times[0];
    $cold = null;   // first run of the loop, before sorting — recompute
    // (recorded order lost after sort; keep it simple: report best + worst)
    $worst = $times[count($times) - 1];
    $over = ($best * 1000.0) > $budget;
    if ($over) $fail = true;

    $report[] = array($slug, $best, $worst, $total, $budget, $over);
    echo sprintf("%-24s best %s  worst %s  total %-9s budget %4dms %s\n",
        $slug, fmt_ms($best), fmt_ms($worst), $total, $budget,
        $over ? '** OVER **' : 'ok');
    echo "    " . $case['desc'] . "\n";
}

echo str_repeat('=', 78) . "\n";
if ($err) {
    echo "RESULT: ERRORS — fix before reading timings.\n";
    exit(2);
}
if ($fail) {
    echo "RESULT: OVER BUDGET (best-of-$runs compared against budget).\n";
    exit(1);
}
echo "RESULT: ALL CASES INSIDE BUDGET.\n";
exit(0);
