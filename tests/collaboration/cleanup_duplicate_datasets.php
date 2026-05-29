<?php
/**
 * Cleanup: collapse duplicate collaborative :Dataset nodes
 * -------------------------------------------------------------------------
 * Companion remediation for the duplicate-datasets bug (see
 * docs/duplicate_datasets_error.txt). The upload-path fix in
 * db/strabospotclass.php (getDatasetById project-scoped match + userpkey heal)
 * stops NEW duplicates. This script heals projects that were already corrupted
 * before the fix shipped — e.g. project 16837490081659, dataset 17791184209841
 * ("CPM") which had 4 copies.
 *
 * What counts as a duplicate: a (project, dataset id) pair with more than one
 * distinct :Dataset node linked to the same project.
 *
 * For each such group it keeps ONE canonical node and removes the rest:
 *   - canonical = prefer userpkey == project owner, then most recent
 *     modified_timestamp, then most spots.
 *   - canonical userpkey is healed to the project owner (created_by preserved).
 *   - each duplicate node's spots are moved to the canonical node, deduped by
 *     spot id (a spot id already present under canonical is dropped from the
 *     duplicate).
 *   - duplicate dataset nodes are DETACH DELETEd.
 *   - exactly one HAS_DATASET edge from the project to the canonical remains.
 *
 * SAFETY:
 *   - DRY-RUN by default. It only reports. Pass --apply to mutate.
 *   - TAKE A NEO4J BACKUP before running with --apply.
 *   - Review the dry-run output first and confirm the canonical choices.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/cleanup_duplicate_datasets.php
 *   docker exec strabo-php php /srv/app/www/tests/collaboration/cleanup_duplicate_datasets.php --apply
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$APPLY = in_array('--apply', $argv, true);

// Optional --project=<id> to scope to a single project (e.g. target the
// reported project first in prod, or for the self-test). Default: all projects.
$onlyProject = null;
foreach ($argv as $a) {
    if (strpos($a, '--project=') === 0) {
        $onlyProject = (int)substr($a, strlen('--project='));
    }
}

function line($s = '') { echo $s . "\n"; }
function hr() { echo str_repeat('=', 72) . "\n"; }

hr();
line($APPLY ? "MODE: APPLY (will mutate Neo4j)" : "MODE: DRY-RUN (no changes; pass --apply to mutate)");
line("SCOPE: " . ($onlyProject !== null ? "project $onlyProject only" : "all projects"));
hr();
line();

// ---------------------------------------------------------------------------
// 1. Find (project, dataset id) groups with more than one distinct node.
// ---------------------------------------------------------------------------
$projectFilter = $onlyProject !== null ? "WHERE p.id = $onlyProject" : "";
$groups = $neodb->query("
    MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)
    $projectFilter
    WITH p, d.id AS did, collect(DISTINCT id(d)) AS neoids
    WHERE size(neoids) > 1
    RETURN p.id AS projectid, p.userpkey AS owner, did AS datasetid, neoids AS neoids, size(neoids) AS n
    ORDER BY n DESC
");

$groupCount = count($groups);
if ($groupCount === 0) {
    line("No duplicate collaborative datasets found. Nothing to do.");
    return;
}

line("Found $groupCount duplicated (project, dataset) group(s).");
line();

$totalDeleted = 0;

foreach ($groups as $g) {
    $projectid = $g->get('projectid');
    $owner     = (int)$g->get('owner');
    $datasetid = $g->get('datasetid');
    $neoids    = $g->get('neoids');

    hr();
    line("Project $projectid (owner pkey $owner) — dataset $datasetid — " . count($neoids) . " copies");
    hr();

    // Gather per-node detail
    $nodes = [];
    foreach ($neoids as $neoid) {
        $neoid = (int)$neoid;
        $row = $neodb->query("
            MATCH (d:Dataset) WHERE id(d) = $neoid
            OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot)
            RETURN d.userpkey AS userpkey, d.created_by AS created_by, d.modified_timestamp AS mt, count(s) AS spots
        ");
        $r = $row[0];
        $nodes[] = [
            'neoid'      => $neoid,
            'userpkey'   => $r->get('userpkey') !== null ? (int)$r->get('userpkey') : null,
            'created_by' => $r->get('created_by') !== null ? (int)$r->get('created_by') : null,
            'mt'         => $r->get('mt') !== null ? (int)$r->get('mt') : 0,
            'spots'      => (int)$r->get('spots'),
        ];
    }

    // Choose canonical: prefer userpkey == owner, then newest mt, then most spots.
    usort($nodes, function ($a, $b) use ($owner) {
        $ao = ($a['userpkey'] === $owner) ? 1 : 0;
        $bo = ($b['userpkey'] === $owner) ? 1 : 0;
        if ($ao !== $bo) return $bo - $ao;            // owner-owned first
        if ($a['mt'] !== $b['mt']) return $b['mt'] - $a['mt']; // newest first
        return $b['spots'] - $a['spots'];             // most spots first
    });

    $canonical = $nodes[0];
    $duplicates = array_slice($nodes, 1);

    line(sprintf("  KEEP  node#%d userpkey=%s created_by=%s mt=%d spots=%d%s",
        $canonical['neoid'], var_export($canonical['userpkey'], true),
        var_export($canonical['created_by'], true), $canonical['mt'], $canonical['spots'],
        $canonical['userpkey'] === $owner ? '' : '  (will heal userpkey -> ' . $owner . ')'));

    foreach ($duplicates as $dup) {
        line(sprintf("  DROP  node#%d userpkey=%s created_by=%s mt=%d spots=%d",
            $dup['neoid'], var_export($dup['userpkey'], true),
            var_export($dup['created_by'], true), $dup['mt'], $dup['spots']));
    }

    if (!$APPLY) {
        line("  (dry-run: no changes made)");
        line();
        $totalDeleted += count($duplicates);
        continue;
    }

    // -- APPLY --
    $canonId = $canonical['neoid'];

    // Heal canonical ownership to the project owner (created_by preserved).
    $neodb->query("MATCH (d:Dataset) WHERE id(d) = $canonId SET d.userpkey = $owner");

    foreach ($duplicates as $dup) {
        $dupId = $dup['neoid'];

        // Move spots to canonical, deduped by spot id.
        // Drop dup spots whose id already exists under canonical...
        $neodb->query("
            MATCH (canon:Dataset) WHERE id(canon) = $canonId
            MATCH (dup:Dataset) WHERE id(dup) = $dupId
            MATCH (dup)-[:HAS_SPOT]->(s:Spot)
            WHERE (canon)-[:HAS_SPOT]->(:Spot {id: s.id})
            DETACH DELETE s
        ");
        // ...and rewire the remaining dup spots onto canonical.
        $neodb->query("
            MATCH (canon:Dataset) WHERE id(canon) = $canonId
            MATCH (dup:Dataset) WHERE id(dup) = $dupId
            MATCH (dup)-[r:HAS_SPOT]->(s:Spot)
            CREATE (canon)-[:HAS_SPOT]->(s)
            DELETE r
        ");

        // Delete the duplicate dataset node (drops its HAS_DATASET edge too).
        $neodb->query("MATCH (dup:Dataset) WHERE id(dup) = $dupId DETACH DELETE dup");

        line("  -> removed node#$dupId");
    }

    // Ensure exactly one HAS_DATASET edge project -> canonical.
    $neodb->query("
        MATCH (p:Project {id: $projectid, userpkey: $owner})-[r:HAS_DATASET]->(canon:Dataset)
        WHERE id(canon) = $canonId
        WITH collect(r) AS rels, p, canon
        FOREACH (x IN rels[1..] | DELETE x)
    ");
    // (Re)create the link if somehow none remains.
    $linkCount = (int)$neodb->get_var("
        MATCH (p:Project {id: $projectid, userpkey: $owner})-[r:HAS_DATASET]->(canon:Dataset)
        WHERE id(canon) = $canonId RETURN count(r)
    ");
    if ($linkCount === 0) {
        $neodb->query("
            MATCH (p:Project {id: $projectid, userpkey: $owner})
            MATCH (canon:Dataset) WHERE id(canon) = $canonId
            CREATE (p)-[:HAS_DATASET]->(canon)
        ");
        line("  -> recreated missing HAS_DATASET link to canonical");
    }

    line("  done.");
    line();
    $totalDeleted += count($duplicates);
}

hr();
line($APPLY
    ? "APPLY complete. Removed $totalDeleted duplicate dataset node(s)."
    : "DRY-RUN complete. Would remove $totalDeleted duplicate dataset node(s). Re-run with --apply (after a Neo4j backup) to execute.");
hr();
