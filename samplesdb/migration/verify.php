<?php
/**
 * File: verify.php
 * Description: Phase E verifier for the Phase 1 migration. Runs after
 *              `run.php --apply` to confirm:
 *
 *                1. Row-count parity. Distinct source samples (per the §10.2
 *                   extraction rules) match strabosamples.samples count to
 *                   within the expected collapse delta (~191 Field↔Micro
 *                   collapses on prod per §10.5).
 *                2. No orphaned child rows (composition / parameters /
 *                   documents pointing at a missing sample).
 *                3. Every source sample row has a corresponding
 *                   sample_subsystem_links row.
 *                4. Every sample_subsystem_links.reference_id resolves to
 *                   a real source object (spot in Neo4j / Micro project /
 *                   Experimental experiment).
 *                5. Spot-check round-trip on a random N samples — pull the
 *                   sample row back, compare its *_data JSONB byte-for-byte
 *                   to a freshly-extracted source row.
 *
 *              Exits non-zero on any failure so this can be wired to CI.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/samplesdb/migration/verify.php
 *     [--source=field|micro|experimental]  scope checks to one source
 *     [--spot-check=N]                    sample N rows for round-trip (default 5)
 *     [--help]
 *
 * @package    StraboSamples Migration
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$webroot = realpath(__DIR__ . '/../..');
chdir($webroot);

require_once($webroot . '/includes/config.inc.php');
require_once($webroot . '/db.php');
require_once($webroot . '/neodb.php');

require_once(__DIR__ . '/extract_field.php');
require_once(__DIR__ . '/extract_micro.php');
require_once(__DIR__ . '/extract_experimental.php');

// ---------------------------------------------------------------------------
// CLI args
// ---------------------------------------------------------------------------

$only = null;
$spotCheckN = 5;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--help' || $a === '-h') {
        echo "Usage: php verify.php [--source=field|micro|experimental] [--spot-check=N]\n";
        exit(0);
    }
    if (strpos($a, '--source=') === 0)     $only = strtolower(substr($a, 9));
    if (strpos($a, '--spot-check=') === 0) $spotCheckN = (int)substr($a, 13);
}
if ($only !== null && !in_array($only, array('field','micro','experimental'), true)) {
    fwrite(STDERR, "ERROR: --source must be one of: field, micro, experimental\n");
    exit(2);
}

$failures = array();
$results  = array();

echo "==============================================================\n";
echo " StraboSamples Phase 1 verification\n";
if ($only) echo "   scope: $only only\n";
echo "==============================================================\n\n";

// ---------------------------------------------------------------------------
// Check 1 — row-count parity
// ---------------------------------------------------------------------------

echo "[1/5] Row-count parity ...\n";

$srcCounts = array();
if ($only === null || $only === 'field') {
    $rows = migration_extract_field($neodb);
    $srcCounts['field'] = migration_distinct_pairs($rows);
}
if ($only === null || $only === 'micro') {
    $rows = migration_extract_micro($db);
    $srcCounts['micro'] = migration_distinct_pairs($rows);
}
if ($only === null || $only === 'experimental') {
    $rows = migration_extract_experimental($db);
    $srcCounts['experimental'] = migration_distinct_pairs($rows);
}

$dbSampleCount = (int)$db->get_var("SELECT COUNT(*) FROM strabosamples.samples");

$distinctUnion = array();
$totalRows = 0;
foreach ($srcCounts as $src => $info) {
    $totalRows += $info['rows'];
    foreach ($info['pairs'] as $k => $_) {
        $distinctUnion[$k] = true;
    }
    echo "    $src: " . $info['rows'] . " source rows, " . count($info['pairs']) . " distinct (id,userpkey)\n";
}
$expectedSamples = count($distinctUnion);

echo "    Expected distinct samples (union across sources): $expectedSamples\n";
echo "    Actual strabosamples.samples count               : $dbSampleCount\n";

if ($only !== null) {
    // Scoped check — DB may have more (other sources contributed); we can
    // only verify that every distinct source-pair exists in DB.
    $missing = 0;
    foreach ($distinctUnion as $k => $_) {
        list($id, $up) = explode('|', $k, 2);
        $hit = $db->get_var_prepared(
            "SELECT 1 FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            array($id, (int)$up));
        if (!$hit) $missing++;
    }
    if ($missing === 0) {
        echo "    OK — every $only source row maps to a strabosamples.samples row.\n";
        $results['rowcount'] = 'ok';
    } else {
        echo "    FAIL — $missing $only source row(s) have no strabosamples row.\n";
        $results['rowcount'] = 'fail';
        $failures[] = "rowcount: $missing missing samples from scoped $only run";
    }
} else {
    if ($dbSampleCount === $expectedSamples) {
        echo "    OK — exact parity.\n";
        $results['rowcount'] = 'ok';
    } else {
        $delta = $dbSampleCount - $expectedSamples;
        echo "    FAIL — delta=$delta (db - expected)\n";
        $results['rowcount'] = 'fail';
        $failures[] = "rowcount: delta=$delta (db=$dbSampleCount, expected=$expectedSamples)";
    }
}

// ---------------------------------------------------------------------------
// Check 2 — no orphan child rows
// ---------------------------------------------------------------------------

echo "\n[2/5] Orphan child rows ...\n";

$orphanChecks = array(
    'sample_composition' => "SELECT COUNT(*) FROM strabosamples.sample_composition c
                              LEFT JOIN strabosamples.samples s
                                ON s.id = c.sample_id AND s.userpkey = c.sample_userpkey
                              WHERE s.id IS NULL",
    'sample_parameters'  => "SELECT COUNT(*) FROM strabosamples.sample_parameters p
                              LEFT JOIN strabosamples.samples s
                                ON s.id = p.sample_id AND s.userpkey = p.sample_userpkey
                              WHERE s.id IS NULL",
    'sample_documents'   => "SELECT COUNT(*) FROM strabosamples.sample_documents d
                              LEFT JOIN strabosamples.samples s
                                ON s.id = d.sample_id AND s.userpkey = d.sample_userpkey
                              WHERE s.id IS NULL",
);
$totalOrphans = 0;
foreach ($orphanChecks as $tbl => $sql) {
    $n = (int)$db->get_var($sql);
    $totalOrphans += $n;
    echo "    $tbl orphans: $n\n";
}
if ($totalOrphans === 0) {
    echo "    OK\n";
    $results['orphans'] = 'ok';
} else {
    echo "    FAIL — $totalOrphans orphaned child row(s).\n";
    $results['orphans'] = 'fail';
    $failures[] = "orphans: $totalOrphans child rows pointing at missing samples";
}

// ---------------------------------------------------------------------------
// Check 3 — every source sample has a sample_subsystem_links row
// ---------------------------------------------------------------------------

echo "\n[3/5] Subsystem-link coverage ...\n";

$linkMisses = 0;
foreach ($srcCounts as $src => $info) {
    $thisSrcMiss = 0;
    foreach ($info['pairs'] as $k => $_) {
        list($id, $up) = explode('|', $k, 2);
        $hit = $db->get_var_prepared("
            SELECT 1 FROM strabosamples.sample_subsystem_links
            WHERE sample_id = $1 AND sample_userpkey = $2 AND subsystem = $3
            LIMIT 1
        ", array($id, (int)$up, $src));
        if (!$hit) $thisSrcMiss++;
    }
    echo "    $src samples missing a $src link: $thisSrcMiss\n";
    $linkMisses += $thisSrcMiss;
}
if ($linkMisses === 0) {
    echo "    OK\n";
    $results['links'] = 'ok';
} else {
    echo "    FAIL — $linkMisses source samples have no link row for their own subsystem.\n";
    $results['links'] = 'fail';
    $failures[] = "links: $linkMisses missing subsystem links";
}

// ---------------------------------------------------------------------------
// Check 4 — every link's reference_id resolves to a real source object
// ---------------------------------------------------------------------------

echo "\n[4/5] Link reference resolution ...\n";

$linkRows = $db->get_results(
    "SELECT subsystem, reference_id, reference_userpkey
       FROM strabosamples.sample_subsystem_links"
);
$linkRows = $linkRows ?: array();

$miss = array('field' => 0, 'micro' => 0, 'experimental' => 0);
$seenField = array();  // cache spot existence checks per (id,userpkey)
foreach ($linkRows as $r) {
    $sub = $r->subsystem;
    if ($only !== null && $sub !== $only) continue;
    $ref = (string)$r->reference_id;
    $ru  = (int)$r->reference_userpkey;

    if ($sub === 'field') {
        $k = $ref . '|' . $ru;
        if (!isset($seenField[$k])) {
            $exists = (int)$neodb->get_var(
                "MATCH (s:Spot) WHERE s.id = $ref AND s.userpkey = $ru RETURN count(s) AS c"
            );
            $seenField[$k] = $exists > 0;
        }
        if (!$seenField[$k]) $miss['field']++;
    } else if ($sub === 'micro') {
        $exists = $db->get_var_prepared(
            "SELECT 1 FROM strabomicro.micro_projectmetadata WHERE id = $1 AND userpkey = $2",
            array((int)$ref, $ru));
        if (!$exists) $miss['micro']++;
    } else if ($sub === 'experimental') {
        $exists = $db->get_var_prepared(
            "SELECT 1 FROM straboexp.experiment WHERE pkey = $1 AND userpkey = $2",
            array((int)$ref, $ru));
        if (!$exists) $miss['experimental']++;
    }
}
foreach ($miss as $k => $n) {
    if ($only !== null && $k !== $only) continue;
    echo "    $k links pointing at a missing source object: $n\n";
}
$totalLinkMiss = array_sum($miss);
if ($totalLinkMiss === 0) {
    echo "    OK\n";
    $results['link_resolution'] = 'ok';
} else {
    echo "    FAIL — $totalLinkMiss link(s) reference a deleted source.\n";
    $results['link_resolution'] = 'fail';
    $failures[] = "link_resolution: $totalLinkMiss dangling links";
}

// ---------------------------------------------------------------------------
// Check 5 — spot-check round-trip on N random samples
// ---------------------------------------------------------------------------

echo "\n[5/5] Spot-check round-trip ($spotCheckN samples) ...\n";

$mismatch = 0;
$checked  = 0;
foreach ($srcCounts as $src => $info) {
    if ($only !== null && $src !== $only) continue;
    if (empty($info['pairs'])) continue;
    $keys = array_keys($info['pairs']);
    shuffle($keys);
    $sample = array_slice($keys, 0, $spotCheckN);
    foreach ($sample as $k) {
        list($id, $up) = explode('|', $k, 2);
        $row = $db->get_row_prepared("
            SELECT field_data::text  AS field_data,
                   micro_data::text  AS micro_data,
                   experimental_data::text AS experimental_data
              FROM strabosamples.samples
             WHERE id = $1 AND userpkey = $2
        ", array($id, (int)$up));
        if (!$row) {
            $mismatch++;
            echo "    MISS [$src $id/$up] — no sample row found\n";
            continue;
        }
        $dataKey = ($src === 'field') ? 'field_data'
                : (($src === 'micro') ? 'micro_data' : 'experimental_data');
        if (empty($row->{$dataKey})) {
            $mismatch++;
            echo "    MISS [$src $id/$up] — {$dataKey} is null\n";
            continue;
        }
        // First source-row we find with this (id, userpkey)
        $srcRow = null;
        foreach ($info['raw'] as $rr) {
            if ($rr['sample_id'] === $id && (int)$rr['sample_userpkey'] === (int)$up) {
                $srcRow = $rr; break;
            }
        }
        if (!$srcRow) {
            $mismatch++;
            echo "    MISS [$src $id/$up] — source row vanished between checks\n";
            continue;
        }
        $expectedJson = json_encode($srcRow['subsystem_data']);
        $stored = json_decode($row->{$dataKey}, true);
        $storedJson = json_encode($stored);

        // For sources that get re-merged (a sample collapsed from multiple
        // sources), the stored *_data is the merged variant for THIS source's
        // column — should still byte-match this single source's contribution.
        if (migration_canonical($expectedJson) !== migration_canonical($storedJson)) {
            $mismatch++;
            echo "    DIFF [$src $id/$up] — stored {$dataKey} != extracted source\n";
        }
        $checked++;
    }
}
if ($mismatch === 0) {
    echo "    OK — $checked sample(s) round-trip cleanly.\n";
    $results['roundtrip'] = 'ok';
} else {
    echo "    FAIL — $mismatch / $checked spot-checks did not round-trip.\n";
    $results['roundtrip'] = 'fail';
    $failures[] = "roundtrip: $mismatch / $checked spot-checks differ";
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n==============================================================\n";
if (empty($failures)) {
    echo " VERIFY: PASS\n";
    foreach ($results as $k => $v) echo "   $k: $v\n";
    echo "==============================================================\n";
    exit(0);
}
echo " VERIFY: FAIL\n";
foreach ($failures as $f) echo "   - $f\n";
echo "==============================================================\n";
exit(1);


// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Reduce a source-row stream to (1) distinct (id,userpkey) pair count,
 * (2) raw rows for spot-check round-trip.
 */
function migration_distinct_pairs($rows) {
    $pairs = array();
    foreach ($rows as $r) {
        if ($r['sample_id'] === '' || (int)$r['sample_userpkey'] <= 0) continue;
        $pairs[$r['sample_id'] . '|' . $r['sample_userpkey']] = true;
    }
    return array('rows' => count($rows), 'pairs' => $pairs, 'raw' => $rows);
}

function migration_canonical($s) {
    if (!is_string($s)) return $s;
    $d = json_decode($s, true);
    if (!is_array($d)) return $s;
    _migration_canon_sort($d);
    return json_encode($d);
}
function _migration_canon_sort(&$a) {
    if (!is_array($a)) return;
    ksort($a);
    foreach ($a as &$v) {
        if (is_array($v)) _migration_canon_sort($v);
    }
}
