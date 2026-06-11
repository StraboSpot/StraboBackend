<?php
/**
 * Self-test for cleanup_duplicate_spots.php (apply path).
 *
 * Seeds synthetic duplicate :Spot groups under a dedicated userpkey far
 * outside the real users range, so the cleanup tool can be run scoped with
 * --user=<pkey> against them, then verifies the result. Not a production
 * artifact — exercises the remediation tool's mutation path and the
 * manual-review guard.
 *
 *   php _spot_cleanup_selftest.php seed
 *   php tests/collaboration/cleanup_duplicate_spots.php --user=99999998 --apply
 *   php _spot_cleanup_selftest.php check
 *   php _spot_cleanup_selftest.php teardown
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

$UPK = 99999998;          // dedicated synthetic userpkey; safe to delete
$DATASET = 9966000000;    // dedicated id range 9966000000..9966000009

$mode = $argv[1] ?? 'check';

function line($s = '') { echo $s . "\n"; }

function teardown($neodb, $UPK, $DATASET) {
    $neodb->query("MATCH (s:Spot {userpkey: $UPK}) DETACH DELETE s");
    $neodb->query("MATCH (t:Trace {userpkey: $UPK}) DETACH DELETE t");
    $neodb->query("MATCH (t:Tag {userpkey: $UPK}) DETACH DELETE t");
    $neodb->query("MATCH (d:Dataset {id: $DATASET, userpkey: $UPK}) DETACH DELETE d");
}

if ($mode === 'teardown') {
    teardown($neodb, $UPK, $DATASET);
    line("spot selftest fixtures torn down.");
    return;
}

if ($mode === 'seed') {
    teardown($neodb, $UPK, $DATASET);
    $ts = time() * 1000;

    $neodb->query("CREATE (d:Dataset:Strabo {id: $DATASET, name: 'Spot Cleanup Selftest', userpkey: $UPK, modified_timestamp: $ts})");

    // Group A (9966000001): linked canonical + bare orphan -> orphan auto-dropped.
    $neodb->query("
        MATCH (d:Dataset {id: $DATASET, userpkey: $UPK})
        CREATE (d)-[:HAS_SPOT]->(:Spot:Strabo {id: 9966000001, userpkey: $UPK, name: 'A-canon', modified_timestamp: $ts})
    ");
    $neodb->query("CREATE (:Spot:Strabo {id: 9966000001, userpkey: $UPK, name: 'A-orphan', modified_timestamp: " . ($ts - 5000) . "})");

    // Group B (9966000002): linked canonical + tag-only orphan -> orphan
    // auto-dropped, shared Tag node must survive.
    $neodb->query("
        MATCH (d:Dataset {id: $DATASET, userpkey: $UPK})
        CREATE (d)-[:HAS_SPOT]->(:Spot:Strabo {id: 9966000002, userpkey: $UPK, name: 'B-canon', modified_timestamp: $ts})
    ");
    $neodb->query("
        CREATE (s:Spot:Strabo {id: 9966000002, userpkey: $UPK, name: 'B-orphan', modified_timestamp: " . ($ts - 5000) . "})
        CREATE (s)-[:IS_TAGGED]->(:Tag:Strabo {id: 9966000008, userpkey: $UPK, name: 'selftest-tag'})
    ");

    // Group C (9966000003): linked canonical + orphan with a HAS_TRACE child
    // -> MANUAL, both nodes untouched.
    $neodb->query("
        MATCH (d:Dataset {id: $DATASET, userpkey: $UPK})
        CREATE (d)-[:HAS_SPOT]->(:Spot:Strabo {id: 9966000003, userpkey: $UPK, name: 'C-canon', modified_timestamp: $ts})
    ");
    $neodb->query("
        CREATE (s:Spot:Strabo {id: 9966000003, userpkey: $UPK, name: 'C-orphan', modified_timestamp: " . ($ts - 5000) . "})
        CREATE (s)-[:HAS_TRACE]->(:Trace:Strabo {id: 9966000009, userpkey: $UPK})
    ");

    // Group D (9966000004): two orphans, neither linked -> keep newest,
    // drop the older.
    $neodb->query("CREATE (:Spot:Strabo {id: 9966000004, userpkey: $UPK, name: 'D-new', modified_timestamp: $ts})");
    $neodb->query("CREATE (:Spot:Strabo {id: 9966000004, userpkey: $UPK, name: 'D-old', modified_timestamp: " . ($ts - 5000) . "})");

    line("seeded 4 duplicate groups under userpkey $UPK.");
    line("now run: php tests/collaboration/cleanup_duplicate_spots.php --user=$UPK --apply");
    return;
}

// ---- check ----
$pass = 0; $fail = 0;
function check($label, $ok) {
    global $pass, $fail;
    if ($ok) { $pass++; line("  PASS  $label"); }
    else     { $fail++; line("  FAIL  $label"); }
}

$names = function ($sid) use ($neodb, $UPK) {
    $rows = $neodb->query("MATCH (s:Spot {userpkey: $UPK}) WHERE s.id = $sid RETURN s.name AS n");
    $out = [];
    foreach ($rows as $r) $out[] = $r->get('n');
    sort($out);
    return $out;
};

line("checking post-apply state for userpkey $UPK:");
check("group A collapsed to linked canonical", $names(9966000001) === ['A-canon']);
check("group B collapsed to linked canonical", $names(9966000002) === ['B-canon']);
check("group B shared Tag node survived",
    (int)$neodb->get_var("MATCH (t:Tag {id: 9966000008, userpkey: $UPK}) RETURN count(t)") === 1);
check("group C left untouched (manual review)", $names(9966000003) === ['C-canon', 'C-orphan']);
check("group C trace child survived",
    (int)$neodb->get_var("MATCH (t:Trace {id: 9966000009, userpkey: $UPK}) RETURN count(t)") === 1);
check("group D kept newest orphan", $names(9966000004) === ['D-new']);

line();
line($fail === 0 ? "SELFTEST PASS ($pass/$pass)" : "SELFTEST FAIL ($fail failure(s))");
exit($fail === 0 ? 0 : 1);
