<?php
/**
 * Regression: dataset upload modified_timestamp gate
 *
 * Contract (historical, pre-`6035f73`): on a dataset upload (POST /db/dataset),
 * insertDataset compares the incoming modified_timestamp against the existing
 * server node's modified_timestamp.
 *   - incoming  >  server  -> OVERWRITE, modified_on_server = true
 *   - incoming <= server   -> SKIP,      modified_on_server = false
 *
 * Bug (introduced in `6035f73`, survived the `68ad574` refactor): a stray
 * `$modified_timestamp = 0;` line just before the comparison clobbered the
 * server timestamp, so `$inmodified_timestamp > 0` was always true and the
 * dataset was overwritten REGARDLESS of timestamp.
 *
 * This script seeds one server dataset node, then drives insertDataset with an
 * OLDER, an EQUAL, and a NEWER incoming timestamp, asserting both the
 * modified_on_server flag and whether the stored node actually changed.
 *
 * Usage: docker exec strabo-php php /srv/app/www/tests/collaboration/repro_dataset_timestamp_gate.php
 *
 * Non-destructive: dedicated id range + dedicated repro user, cleaned up on run.
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
require_once('db/strabospotclass.php');

$REPRO_DATASET = 8888888821;
$ownerEmail    = 'repro_owner@test.strabospot.org';

$passed = 0;
$failed = 0;

function line($s = '') { echo $s . "\n"; }
function hr() { echo str_repeat('-', 70) . "\n"; }

function check($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) { $passed++; $status = "\033[32mPASS\033[0m"; }
    else            { $failed++; $status = "\033[31mFAIL\033[0m"; }
    line(sprintf("  [%s] %s%s", $status, $name, $details !== '' ? "  ($details)" : ''));
}

function ensureUser($db, $email) {
    $pkey = $db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$email]);
    if ($pkey) return (int)$pkey;
    $hash = md5(uniqid(rand(), true));
    $pw   = crypt('testpass123', '$1$' . substr(md5(uniqid(rand(), true)), 0, 8));
    $db->prepare_query(
        "INSERT INTO users (email, password, firstname, lastname, hash, active, deleted)
         VALUES ($1, $2, 'Repro', 'User', $3, true, false)",
        [$email, $pw, $hash]
    );
    return (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", [$email]);
}

// Read the stored node's name + modified_timestamp straight from Neo4j.
function serverNode($neodb, $datasetid) {
    $rows = $neodb->query("MATCH (d:Dataset) WHERE d.id = $datasetid RETURN d.name AS name, d.modified_timestamp AS mt");
    if (count($rows) === 0) return null;
    return ['name' => $rows[0]->get('name'), 'mt' => $rows[0]->get('mt')];
}

function teardown($neodb, $datasetid) {
    $neodb->query("MATCH (d:Dataset) WHERE d.id = $datasetid DETACH DELETE d");
}

function seed($neodb, $datasetid, $ownerPkey, $ts) {
    $neodb->query("
        CREATE (d:Dataset {id: $datasetid, name: 'SERVER_VERSION', userpkey: $ownerPkey,
                           created_by: $ownerPkey, modified_timestamp: $ts,
                           datasettype: 'app', datecreated: " . time() . "})
    ");
}

function makeStrabo($neodb, $db, $userpkey) {
    // StraboSpot uses a PHP4-style named constructor; set handlers explicitly.
    $strabo = new StraboSpot($neodb, $userpkey, $db);
    $strabo->setneohandler($neodb);
    $strabo->setdbhandler($db);
    $strabo->setuserpkey($userpkey);
    return $strabo;
}

$ownerPkey = ensureUser($db, $ownerEmail);
line("Owner pkey: $ownerPkey ($ownerEmail)");
line();

$baseTs = time() * 1000;

// ---------------------------------------------------------------------------
// Case 1: OLDER incoming timestamp -> must NOT overwrite
// ---------------------------------------------------------------------------
hr(); line("CASE 1: incoming OLDER than server -> reject (modified_on_server=false)"); hr();
teardown($neodb, $REPRO_DATASET);
seed($neodb, $REPRO_DATASET, $ownerPkey, $baseTs);

$strabo = makeStrabo($neodb, $db, $ownerPkey);
$result = $strabo->insertDataset(json_encode([
    'id' => $REPRO_DATASET, 'name' => 'STALE_UPLOAD',
    'modified_timestamp' => $baseTs - 5000, 'datasettype' => 'app',
]), null, null);

$node = serverNode($neodb, $REPRO_DATASET);
check('returned modified_on_server === false', isset($result->modified_on_server) && $result->modified_on_server === false,
    'got ' . var_export($result->modified_on_server ?? null, true));
check('server node NOT overwritten (name still SERVER_VERSION)', $node['name'] === 'SERVER_VERSION',
    'name=' . var_export($node['name'], true));
check('server modified_timestamp unchanged', (int)$node['mt'] === (int)$baseTs,
    'mt=' . var_export($node['mt'], true));
line();

// ---------------------------------------------------------------------------
// Case 2: EQUAL incoming timestamp -> must NOT overwrite
// ---------------------------------------------------------------------------
hr(); line("CASE 2: incoming EQUAL to server -> reject (modified_on_server=false)"); hr();
teardown($neodb, $REPRO_DATASET);
seed($neodb, $REPRO_DATASET, $ownerPkey, $baseTs);

$strabo = makeStrabo($neodb, $db, $ownerPkey);
$result = $strabo->insertDataset(json_encode([
    'id' => $REPRO_DATASET, 'name' => 'EQUAL_UPLOAD',
    'modified_timestamp' => $baseTs, 'datasettype' => 'app',
]), null, null);

$node = serverNode($neodb, $REPRO_DATASET);
check('returned modified_on_server === false', isset($result->modified_on_server) && $result->modified_on_server === false,
    'got ' . var_export($result->modified_on_server ?? null, true));
check('server node NOT overwritten (name still SERVER_VERSION)', $node['name'] === 'SERVER_VERSION',
    'name=' . var_export($node['name'], true));
line();

// ---------------------------------------------------------------------------
// Case 3: NEWER incoming timestamp -> MUST overwrite
// ---------------------------------------------------------------------------
hr(); line("CASE 3: incoming NEWER than server -> overwrite (modified_on_server=true)"); hr();
teardown($neodb, $REPRO_DATASET);
seed($neodb, $REPRO_DATASET, $ownerPkey, $baseTs);

$strabo = makeStrabo($neodb, $db, $ownerPkey);
$newTs  = $baseTs + 5000;
$result = $strabo->insertDataset(json_encode([
    'id' => $REPRO_DATASET, 'name' => 'FRESH_UPLOAD',
    'modified_timestamp' => $newTs, 'datasettype' => 'app',
]), null, null);

$node = serverNode($neodb, $REPRO_DATASET);
check('returned modified_on_server === true', isset($result->modified_on_server) && $result->modified_on_server === true,
    'got ' . var_export($result->modified_on_server ?? null, true));
check('server node overwritten (name now FRESH_UPLOAD)', $node['name'] === 'FRESH_UPLOAD',
    'name=' . var_export($node['name'], true));
check('server modified_timestamp advanced', (int)$node['mt'] === (int)$newTs,
    'mt=' . var_export($node['mt'], true));
line();

// Cleanup
teardown($neodb, $REPRO_DATASET);
line("Repro fixtures cleaned up. (Repro user left in place for reuse.)");
line();

hr();
line(sprintf("Passed: %d   Failed: %d", $passed, $failed));
hr();

if ($failed > 0) {
    line("\033[31mFAILED: dataset timestamp gate is not behaving correctly.\033[0m");
    exit(1);
}
line("\033[32mPASSED: dataset timestamp gate enforced.\033[0m");
exit(0);
