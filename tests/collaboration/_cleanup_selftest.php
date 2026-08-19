<?php
/**
 * Self-test for cleanup_duplicate_datasets.php (apply path).
 *
 * Seeds a synthetic corrupted project (dedicated id range), so the cleanup tool
 * can be run scoped with --project=<id> against it, then verifies the result.
 * Not a production artifact — exercises the remediation tool's mutation path.
 *
 *   php _cleanup_selftest.php seed    # build the synthetic duplicate
 *   php _cleanup_selftest.php check   # assert it collapsed to one node
 *   php _cleanup_selftest.php teardown
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$PROJECT = 9999999991;   // dedicated; safe to delete
$DATASET = 8888888891;
$OWNER   = (int) $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", ['repro_owner@test.strabospot.org']);
$EDITOR  = (int) $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", ['repro_editor@test.strabospot.org']);

$mode = $argv[1] ?? 'check';

function line($s = '') { echo $s . "\n"; }

function teardown($neodb, $PROJECT, $DATASET) {
    $neodb->query("MATCH (s:Spot) WHERE s.id >= 7777777790 AND s.id <= 7777777799 DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id = $DATASET DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id = $PROJECT DETACH DELETE p");
}

if ($mode === 'teardown') {
    teardown($neodb, $PROJECT, $DATASET);
    line("selftest fixtures torn down.");
    return;
}

if ($mode === 'seed') {
    if (!$OWNER || !$EDITOR) { die("Run repro_duplicate_datasets.php first to create repro users.\n"); }
    teardown($neodb, $PROJECT, $DATASET);
    $ts = time() * 1000;

    // Project owned by OWNER.
    $neodb->query("CREATE (p:Project {id: $PROJECT, desc_project_name: 'Cleanup Selftest', userpkey: $OWNER, modified_timestamp: $ts})");

    // Two duplicate dataset nodes (same id) both linked to the project:
    //   - node A: userpkey = EDITOR (the stale collaborator-owned copy), older
    //   - node B: userpkey = OWNER (the parallel node the bug created), newer
    // Spots: A has 7777777790 (unique) + 7777777792 (shared id);
    //        B has 7777777791 (unique) + 7777777792 (shared id).
    // Expect: canonical = B (owner-owned, newer); A's unique spot moves to B;
    //         the shared-id spot from A is dropped (B already has that id).
    $neodb->query("
        MATCH (p:Project {id: $PROJECT})
        CREATE (a:Dataset {id: $DATASET, name: 'CPM', userpkey: $EDITOR, created_by: $EDITOR, modified_timestamp: " . ($ts - 5000) . ", datasettype: 'app'})
        CREATE (p)-[:HAS_DATASET]->(a)
        CREATE (a)-[:HAS_SPOT]->(:Spot {id: 7777777790, userpkey: $OWNER, created_by: $EDITOR})
        CREATE (a)-[:HAS_SPOT]->(:Spot {id: 7777777792, userpkey: $OWNER, created_by: $EDITOR})
    ");
    $neodb->query("
        MATCH (p:Project {id: $PROJECT})
        CREATE (b:Dataset {id: $DATASET, name: 'CPM', userpkey: $OWNER, created_by: $EDITOR, modified_timestamp: $ts, datasettype: 'app'})
        CREATE (p)-[:HAS_DATASET]->(b)
        CREATE (b)-[:HAS_SPOT]->(:Spot {id: 7777777791, userpkey: $OWNER, created_by: $EDITOR})
        CREATE (b)-[:HAS_SPOT]->(:Spot {id: 7777777792, userpkey: $OWNER, created_by: $EDITOR})
    ");

    $nodes = (int)$neodb->get_var("MATCH (d:Dataset) WHERE d.id = $DATASET RETURN count(d)");
    $links = (int)$neodb->get_var("MATCH (p:Project {id: $PROJECT})-[r:HAS_DATASET]->(d:Dataset {id: $DATASET}) RETURN count(r)");
    line("seeded: nodes=$nodes links=$links (expect 2/2). Project scope id: $PROJECT");
    return;
}

// check
$nodes   = (int)$neodb->get_var("MATCH (d:Dataset) WHERE d.id = $DATASET RETURN count(d)");
$links   = (int)$neodb->get_var("MATCH (p:Project {id: $PROJECT})-[r:HAS_DATASET]->(d:Dataset {id: $DATASET}) RETURN count(r)");
$owner   = $neodb->get_var("MATCH (d:Dataset) WHERE d.id = $DATASET RETURN d.userpkey");
$created = $neodb->get_var("MATCH (d:Dataset) WHERE d.id = $DATASET RETURN d.created_by");
// distinct spot ids now under the surviving dataset
$spotIds = $neodb->query("MATCH (d:Dataset {id: $DATASET})-[:HAS_SPOT]->(s:Spot) RETURN DISTINCT s.id AS sid ORDER BY sid");
$ids = array_map(function ($r) { return (int)$r->get('sid'); }, $spotIds);
$totalSpotsWithIds = (int)$neodb->get_var("MATCH (s:Spot) WHERE s.id IN [7777777790,7777777791,7777777792] RETURN count(s)");

line("after cleanup: nodes=$nodes links=$links userpkey=$owner created_by=$created");
line("  surviving dataset spot ids: [" . implode(',', $ids) . "]");
line("  total Spot nodes in selftest id range: $totalSpotsWithIds");

$ok = true;
$ok = $ok && ($nodes === 1);                          // collapsed to one node
$ok = $ok && ($links === 1);                          // exactly one link
$ok = $ok && ((int)$owner === $OWNER);                // healed/kept owner
$ok = $ok && ((int)$created === $EDITOR);             // created_by preserved
$ok = $ok && ($ids === [7777777790, 7777777791, 7777777792]); // spots merged, deduped
$ok = $ok && ($totalSpotsWithIds === 3);              // shared-id spot deduped (no orphan dup)

teardown($neodb, $PROJECT, $DATASET);

if ($ok) {
    line("\033[32mSELFTEST PASS: duplicate collapsed correctly.\033[0m");
    exit(0);
}
line("\033[31mSELFTEST FAIL.\033[0m");
exit(1);
