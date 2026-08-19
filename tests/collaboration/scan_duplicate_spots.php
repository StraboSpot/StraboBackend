<?php
/**
 * Scan: find duplicate :Spot nodes (same spot id + userpkey, multiple nodes)
 * -------------------------------------------------------------------------
 * READ-ONLY companion to cleanup_duplicate_datasets.php. The concurrent-upload
 * TOCTOU bug (fixed in fix/upload-duplicate-races, develop @ 0f67b61) could
 * duplicate Spot nodes as well as Dataset nodes. This scans for the spot-level
 * damage so it can be triaged.
 *
 * What counts as a duplicate: more than one :Spot node carrying the same
 * (s.id, s.userpkey) pair. Same spot id under DIFFERENT userpkeys is the
 * legitimate file-sharing model and is NOT flagged.
 *
 * A whole-graph GROUP BY OOMs a small Neo4j heap (dev died scanning 1.7M
 * spots), so this batches by userpkey range and aggregates per batch. Spots
 * with NULL userpkey are scanned in a final dedicated pass.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/scan_duplicate_spots.php
 *   ... --batch=100        userpkeys per batch (default 250)
 *   ... --from=N --to=M    resume / scope a userpkey range (inclusive from, exclusive to)
 *   ... --user=N           scan a single userpkey only
 *   ... --no-detail        skip the per-node detail queries (faster summary-only pass)
 *
 * Exit codes: 0 = no duplicates found, 1 = duplicates found (so the runbook
 * can gate on it), 2 = usage/connection error.
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$batchSize = 250;
$from = null;
$to = null;
$onlyUser = null;
$detail = true;

foreach ($argv as $a) {
    if (strpos($a, '--batch=') === 0) $batchSize = max(1, (int)substr($a, 8));
    if (strpos($a, '--from=') === 0)  $from = (int)substr($a, 7);
    if (strpos($a, '--to=') === 0)    $to = (int)substr($a, 5);
    if (strpos($a, '--user=') === 0)  $onlyUser = (int)substr($a, 7);
    if ($a === '--no-detail')         $detail = false;
}

function line($s = '') { echo $s . "\n"; }
function hr() { echo str_repeat('=', 72) . "\n"; }

// Resolve the userpkey range to walk.
if ($onlyUser !== null) {
    $from = $onlyUser;
    $to = $onlyUser + 1;
} else {
    if ($from === null) $from = 0;
    if ($to === null) {
        $maxUser = (int)$db->get_var("SELECT MAX(pkey) FROM users");
        if ($maxUser <= 0) {
            line("ERROR: could not resolve MAX(pkey) from users table.");
            exit(2);
        }
        $to = $maxUser + 1;
    }
}

hr();
line("SCAN: duplicate :Spot nodes by (id, userpkey)  [READ-ONLY]");
line("RANGE: userpkey $from (inclusive) .. $to (exclusive), batch $batchSize");
hr();
line();

$totalGroups = 0;
$totalExcess = 0;
$perUser = [];   // userpkey => excess node count

$reportGroup = function ($upk, $sid, $neoids) use (&$totalGroups, &$totalExcess, &$perUser, $neodb, $detail) {
    $totalGroups++;
    $excess = count($neoids) - 1;
    $totalExcess += $excess;
    $key = $upk === null ? 'NULL' : (string)$upk;
    $perUser[$key] = ($perUser[$key] ?? 0) + $excess;

    line(sprintf("DUP  userpkey=%s spot id=%s  %d nodes", $key, $sid, count($neoids)));
    if (!$detail) return;

    foreach ($neoids as $neoid) {
        $neoid = (int)$neoid;
        $rows = $neodb->query("
            MATCH (s:Spot) WHERE id(s) = $neoid
            OPTIONAL MATCH (d:Dataset)-[:HAS_SPOT]->(s)
            RETURN s.name AS name, s.modified_timestamp AS mt,
                   collect(DISTINCT d.id) AS datasets, size((s)--()) AS degree
        ");
        $r = $rows[0];
        $datasets = $r->get('datasets');
        line(sprintf("     node#%d mt=%s degree=%d datasets=[%s] name=%s",
            $neoid,
            var_export($r->get('mt'), true),
            (int)$r->get('degree'),
            implode(',', array_map('strval', is_array($datasets) ? $datasets : iterator_to_array($datasets))),
            var_export($r->get('name'), true)));
    }
};

// Walk userpkey ranges.
$batchStart = $from;
while ($batchStart < $to) {
    $batchEnd = min($batchStart + $batchSize, $to);
    $groups = $neodb->query("
        MATCH (s:Spot)
        WHERE s.userpkey >= $batchStart AND s.userpkey < $batchEnd
        WITH s.userpkey AS upk, s.id AS sid, collect(id(s)) AS neoids
        WHERE size(neoids) > 1
        RETURN upk, sid, neoids
        ORDER BY upk, sid
    ");
    foreach ($groups as $g) {
        $reportGroup($g->get('upk'), $g->get('sid'), $g->get('neoids'));
    }
    line(sprintf("  ...batch %d..%d done (%d dup group(s) so far)", $batchStart, $batchEnd, $totalGroups));
    $batchStart = $batchEnd;
}
line();

// Final pass: spots with no userpkey at all (shouldn't exist, but flag them).
if ($onlyUser === null) {
    $nullGroups = $neodb->query("
        MATCH (s:Spot)
        WHERE s.userpkey IS NULL
        WITH s.id AS sid, collect(id(s)) AS neoids
        WHERE size(neoids) > 1
        RETURN sid, neoids
        ORDER BY sid
    ");
    foreach ($nullGroups as $g) {
        $reportGroup(null, $g->get('sid'), $g->get('neoids'));
    }
    $nullSpotCount = (int)$neodb->get_var("MATCH (s:Spot) WHERE s.userpkey IS NULL RETURN count(s)");
    if ($nullSpotCount > 0) {
        line("NOTE: $nullSpotCount :Spot node(s) carry NULL userpkey (flagged above only if duplicated).");
    }
}

line();
hr();
if ($totalGroups === 0) {
    line("CLEAN: no duplicate (id, userpkey) spot groups found in range.");
    hr();
    exit(0);
}
line("FOUND: $totalGroups duplicate group(s), $totalExcess excess node(s) to remove.");
line();
line("Excess nodes by userpkey:");
arsort($perUser);
foreach ($perUser as $upk => $n) {
    line(sprintf("  userpkey %-8s %d", $upk, $n));
}
hr();
exit(1);
