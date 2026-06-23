<?php
/**
 * File: smoke_test_version_restore_sync.php
 * Description: Tier 4 coverage for the version-restore round-trip's spine
 *              reconvergence (samples/phase-i-followups). The Field bulk-delete
 *              fix (a21289e) made switchVersion's delete-half clean the spine;
 *              smoke_test_field_delete_sync proved the delete-half. This proves
 *              the FULL round-trip end-to-end: switchVersion deletes the live
 *              project (spine removed) AND re-inserts the snapshot's spots,
 *              re-mirroring their samples — so the spine reconverges to the
 *              snapshot values, with no orphan/duplicate rows.
 *
 *                - seed project/dataset/rich sample-spot + spine ('SnapshotName')
 *                - createVersion() snapshots it
 *                - edit the spine ('EditedAfterSnapshot') to diverge
 *                - switchVersion() restores → spine reconverges to 'SnapshotName'
 *                  (the post-snapshot edit is correctly overwritten by the
 *                  restore — last-writer-wins, restore IS the writer), exactly
 *                  one spine row, spot back in Neo4j
 *
 *              Hermetic: per-run id space; spine + Neo4j + versions/verlog rows
 *              + version files cleaned in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_version_restore_sync.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/db/strabospotclass.php';
require_once '/srv/app/www/includes/geophp/geoPHP.inc';   // switchVersion->fixIncomingBasemaps needs geoPHP

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$owner = (int)$db->get_var("SELECT pkey FROM users WHERE deleted=FALSE AND active=TRUE ORDER BY pkey LIMIT 1");
$uuid_gen = new UUID();
$strabo = new StraboSpot($neodb, $owner, $db);
$strabo->setuuid($uuid_gen);

$base = time();
$pid  = (int)($base * 1000 + 11);
$did  = (int)($base * 1000 + 12);
$spot = (int)($base * 1000 + 13);
$sStr = (string)$spot;   // rich sample-spot: sample id == spot id

$sampleObj = array('id' => $sStr, 'sample_id_name' => 'SnapshotName', 'material_type' => 'igneous');

function spineName($db, $owner, $sid) {
    return $db->get_var_prepared("SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sid, $owner));
}
function spineCount($db, $owner, $sid) {
    return (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sid, $owner));
}

try {
    // ---- seed real project/dataset/rich sample-spot + mirror ----
    $neodb->query("CREATE (p:Project {id:$pid, userpkey:$owner, desc_project_name:'verrestore $base', name:'verrestore $base'})");
    $neodb->query("CREATE (d:Dataset {id:$did, userpkey:$owner, name:'ds $base'})");
    $neodb->query("MATCH (p:Project {id:$pid,userpkey:$owner}),(d:Dataset {id:$did,userpkey:$owner}) CREATE (p)-[:HAS_DATASET]->(d)");
    $js = json_encode(array($sampleObj));
    $neodb->query("CREATE (s:Spot {id:$spot, userpkey:$owner, isSample:1, modified_timestamp:$base,
        json_samples:'" . addslashes($js) . "', wkt:'POINT (-110.0 40.0)'})");
    $neodb->query("MATCH (d:Dataset {id:$did,userpkey:$owner}),(s:Spot {id:$spot,userpkey:$owner}) CREATE (d)-[:HAS_SPOT]->(s)");

    $props = (object)array('id' => $spot, 'samples' => array((object)$sampleObj), 'isSample' => 1, 'wkt' => 'POINT (-110.0 40.0)');
    field_sample_sync_spot($db, $neodb, $props, $spot, $owner, (string)$pid, (string)$did);
    check("seed: spine name = 'SnapshotName'", spineName($db, $owner, $sStr) === 'SnapshotName');

    // ---- createVersion snapshots the current state ----
    $verUuid = $strabo->createVersion($pid);
    $verRow = $db->get_row_prepared("SELECT uuid FROM versions WHERE projectid=$1 AND userpkey=$2 ORDER BY pkey DESC LIMIT 1", array((string)$pid, $owner));
    $verUuid = $verRow ? $verRow->uuid : $verUuid;
    check("createVersion produced a version row", !empty($verUuid));

    // ---- diverge the spine via a post-snapshot edit ----
    $db->prepare_query("UPDATE strabosamples.samples SET name='EditedAfterSnapshot' WHERE id=$1 AND userpkey=$2", array($sStr, $owner));
    check("post-snapshot edit applied", spineName($db, $owner, $sStr) === 'EditedAfterSnapshot');

    // ---- restore the version ----
    $ok = $strabo->switchVersion($verUuid);
    check("switchVersion returned truthy", $ok === true || $ok == 1);

    // ---- assert reconvergence ----
    check("spine reconverged to snapshot ('SnapshotName')", spineName($db, $owner, $sStr) === 'SnapshotName');
    check("exactly one spine row (no orphan/dup)",          spineCount($db, $owner, $sStr) === 1);
    check("exactly one field link (no orphan/dup)",
        (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'", array($sStr, $owner)) === 1);
    check("spot restored into Neo4j",
        (int)$neodb->get_var("MATCH (s:Spot {id:$spot,userpkey:$owner}) RETURN count(s)") === 1);

} finally {
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sStr, $owner));
    $neodb->query("MATCH (s:Spot {id:$spot,userpkey:$owner}) DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset {id:$did,userpkey:$owner}) DETACH DELETE d");
    $neodb->query("MATCH (p:Project {id:$pid,userpkey:$owner}) DETACH DELETE p");
    // version artifacts
    $rows = $db->get_results_prepared("SELECT uuid FROM versions WHERE projectid=$1 AND userpkey=$2", array((string)$pid, $owner));
    if (is_array($rows)) foreach ($rows as $r) { @unlink("/srv/app/www/versions/" . $r->uuid); }
    $db->prepare_query("DELETE FROM versions WHERE projectid=$1 AND userpkey=$2", array((string)$pid, $owner));
    $db->prepare_query("DELETE FROM verlog WHERE projectid=$1 AND userpkey=$2", array((string)$pid, $owner));
}

echo "\n";
if (empty($failures)) { echo "RESULT: all checks PASS\n"; exit(0); }
echo "RESULT: " . count($failures) . " failure(s):\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
