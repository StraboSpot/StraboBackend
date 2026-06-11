<?php
/**
 * Cleanup: collapse duplicate :Spot nodes (same spot id + userpkey)
 * -------------------------------------------------------------------------
 * Companion remediation to cleanup_duplicate_datasets.php for the
 * concurrent-upload TOCTOU bug (fixed in fix/upload-duplicate-races,
 * develop @ 0f67b61). The fix stops NEW duplicates; this heals the ones
 * already in the graph. Use scan_duplicate_spots.php first to size the
 * damage (READ-ONLY).
 *
 * What counts as a duplicate: more than one :Spot node carrying the same
 * (s.id, s.userpkey) pair. Same spot id under DIFFERENT userpkeys is the
 * legitimate file-sharing model and is NOT touched.
 *
 * Policy per group:
 *   - canonical = the dataset-linked node (incoming HAS_SPOT). If none is
 *     linked, the newest modified_timestamp (tiebreak: highest degree).
 *   - a duplicate is AUTO-REMOVABLE only if its relationships are at most
 *     outgoing IS_TAGGED edges to :Tag nodes (tags are shared entities and
 *     survive; the orphan's tag memberships are reported, then dropped with
 *     the node). Bare nodes (degree 0) trivially qualify.
 *   - groups with MORE THAN ONE dataset-linked copy, and duplicates carrying
 *     any other relationship (HAS_TRACE, HAS_ORIENTATION, HAS_IMAGE, ...) are
 *     FLAGGED FOR MANUAL REVIEW and left untouched.
 *
 * SAFETY:
 *   - DRY-RUN by default. It only reports. Pass --apply to mutate.
 *   - TAKE A NEO4J BACKUP before running with --apply.
 *   - Review the dry-run output first.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/cleanup_duplicate_spots.php
 *   ... --apply            mutate (default is dry-run)
 *   ... --batch=100        userpkeys per batch (default 250)
 *   ... --from=N --to=M    scope a userpkey range (inclusive from, exclusive to)
 *   ... --user=N           single userpkey only
 *
 * Exit codes: 0 = nothing left to do, 1 = duplicates found (dry-run) or
 * manual-review groups remain (apply), 2 = usage/connection error.
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$APPLY = in_array('--apply', $argv, true);
$batchSize = 250;
$from = null;
$to = null;
$onlyUser = null;

foreach ($argv as $a) {
    if (strpos($a, '--batch=') === 0) $batchSize = max(1, (int)substr($a, 8));
    if (strpos($a, '--from=') === 0)  $from = (int)substr($a, 7);
    if (strpos($a, '--to=') === 0)    $to = (int)substr($a, 5);
    if (strpos($a, '--user=') === 0)  $onlyUser = (int)substr($a, 7);
}

function line($s = '') { echo $s . "\n"; }
function hr() { echo str_repeat('=', 72) . "\n"; }

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
line($APPLY ? "MODE: APPLY (will mutate Neo4j)" : "MODE: DRY-RUN (no changes; pass --apply to mutate)");
line("SCOPE: userpkey $from (inclusive) .. $to (exclusive), batch $batchSize");
hr();
line();

$autoGroups = 0;     // groups fully resolvable (all dups auto-removable)
$autoRemoved = 0;    // dup nodes removed (or would be)
$manualGroups = 0;   // groups left for manual review

/**
 * Relationship audit for a prospective duplicate. Returns
 * ['safe' => bool, 'tags' => [names], 'detail' => [strings]].
 * Safe = every relationship is an outgoing IS_TAGGED to a :Tag.
 */
function auditDupRels($neodb, $neoid) {
    $rows = $neodb->query("
        MATCH (s:Spot) WHERE id(s) = $neoid
        MATCH (s)-[r]-(o)
        RETURN type(r) AS rt, startNode(r) = s AS outgoing, labels(o) AS ol,
               o.name AS oname, count(*) AS c
    ");
    $safe = true;
    $tags = [];
    $detail = [];
    foreach ($rows as $r) {
        $rt = $r->get('rt');
        $outgoing = (bool)$r->get('outgoing');
        $ol = $r->get('ol');
        $ol = is_array($ol) ? $ol : iterator_to_array($ol);
        $isTagEdge = ($rt === 'IS_TAGGED' && $outgoing && in_array('Tag', $ol, true));
        if ($isTagEdge) {
            $tags[] = (string)$r->get('oname');
        } else {
            $safe = false;
        }
        $detail[] = sprintf("%s %s %s x%d", $rt, $outgoing ? '->' : '<-',
            implode(',', $ol), (int)$r->get('c'));
    }
    return ['safe' => $safe, 'tags' => $tags, 'detail' => $detail];
}

$processGroup = function ($upk, $sid, $neoids) use (&$autoGroups, &$autoRemoved, &$manualGroups, $neodb, $APPLY) {
    // Per-node facts.
    $nodes = [];
    foreach ($neoids as $neoid) {
        $neoid = (int)$neoid;
        $r = $neodb->query("
            MATCH (s:Spot) WHERE id(s) = $neoid
            OPTIONAL MATCH (d:Dataset)-[:HAS_SPOT]->(s)
            RETURN s.name AS name, s.modified_timestamp AS mt,
                   count(DISTINCT d) AS ds, collect(DISTINCT d.id) AS dsids,
                   size((s)--()) AS degree
        ")[0];
        $dsids = $r->get('dsids');
        $nodes[] = [
            'neoid'  => $neoid,
            'name'   => $r->get('name'),
            'mt'     => $r->get('mt') !== null ? (int)$r->get('mt') : 0,
            'ds'     => (int)$r->get('ds'),
            'dsids'  => is_array($dsids) ? $dsids : iterator_to_array($dsids),
            'degree' => (int)$r->get('degree'),
        ];
    }

    $linked = array_values(array_filter($nodes, function ($n) { return $n['ds'] > 0; }));

    line(sprintf("GROUP userpkey=%s spot id=%s  %d nodes (%d dataset-linked)",
        (string)$upk, (string)$sid, count($nodes), count($linked)));

    // More than one dataset-linked copy: ambiguous (possibly linked to
    // different datasets) — manual review only.
    if (count($linked) > 1) {
        $manualGroups++;
        foreach ($nodes as $n) {
            line(sprintf("  MANUAL node#%d mt=%d degree=%d datasets=[%s] name=%s",
                $n['neoid'], $n['mt'], $n['degree'],
                implode(',', array_map('strval', $n['dsids'])), var_export($n['name'], true)));
        }
        line("  -> multiple dataset-linked copies; left untouched, review by hand.");
        line();
        return;
    }

    // Canonical: the linked node, else newest mt (tiebreak: highest degree).
    if (count($linked) === 1) {
        $canonical = $linked[0];
    } else {
        usort($nodes, function ($a, $b) {
            if ($a['mt'] !== $b['mt']) return $b['mt'] - $a['mt'];
            return $b['degree'] - $a['degree'];
        });
        $canonical = $nodes[0];
    }

    line(sprintf("  KEEP  node#%d mt=%d degree=%d datasets=[%s] name=%s",
        $canonical['neoid'], $canonical['mt'], $canonical['degree'],
        implode(',', array_map('strval', $canonical['dsids'])), var_export($canonical['name'], true)));

    $groupManual = false;
    $removable = [];
    foreach ($nodes as $n) {
        if ($n['neoid'] === $canonical['neoid']) continue;
        $audit = auditDupRels($neodb, $n['neoid']);
        if ($audit['safe']) {
            $tagNote = count($audit['tags']) ? '  (drops tag membership: ' . implode(', ', $audit['tags']) . ')' : '';
            line(sprintf("  DROP  node#%d mt=%d degree=%d name=%s%s",
                $n['neoid'], $n['mt'], $n['degree'], var_export($n['name'], true), $tagNote));
            $removable[] = $n['neoid'];
        } else {
            $groupManual = true;
            line(sprintf("  MANUAL node#%d mt=%d degree=%d name=%s rels: %s",
                $n['neoid'], $n['mt'], $n['degree'], var_export($n['name'], true),
                implode('; ', $audit['detail'])));
        }
    }

    if ($groupManual) {
        $manualGroups++;
        line("  -> has non-tag relationships; auto-removal skipped for the flagged node(s), review by hand.");
    }
    if (count($removable) === 0) {
        line();
        return;
    }
    if (!$groupManual) $autoGroups++;

    if ($APPLY) {
        foreach ($removable as $neoid) {
            $neodb->query("MATCH (s:Spot) WHERE id(s) = $neoid DETACH DELETE s");
            line("  -> removed node#$neoid");
        }
    } else {
        line("  (dry-run: no changes made)");
    }
    $autoRemoved += count($removable);
    line();
};

// Walk userpkey ranges (same batching as scan_duplicate_spots.php — a
// whole-graph GROUP BY OOMs a small Neo4j heap).
$batchStart = $from;
while ($batchStart < $to) {
    $batchEnd = min($batchStart + $batchSize, $to);
    $groups = $neodb->query("
        MATCH (s:Spot)
        WHERE s.userpkey >= $batchStart AND s.userpkey < $batchEnd
        WITH s.userpkey AS upk, s.id AS sid, collect(id(s)) AS neoids
        WHERE size(neoids) > 1
        RETURN upk, sid, neoids
    ");
    foreach ($groups as $g) {
        $neoids = $g->get('neoids');
        $processGroup($g->get('upk'), $g->get('sid'),
            is_array($neoids) ? $neoids : iterator_to_array($neoids));
    }
    $batchStart = $batchEnd;
}

hr();
line(sprintf("%s: %d group(s) auto-resolvable, %d duplicate node(s) %s, %d group(s) flagged for manual review.",
    $APPLY ? "APPLY complete" : "DRY-RUN complete",
    $autoGroups, $autoRemoved,
    $APPLY ? "removed" : "would be removed",
    $manualGroups));
if (!$APPLY && ($autoRemoved > 0 || $manualGroups > 0)) {
    line("Re-run with --apply (after a Neo4j backup) to execute the DROPs.");
}
hr();
exit(($APPLY ? $manualGroups : ($autoRemoved + $manualGroups)) > 0 ? 1 : 0);
