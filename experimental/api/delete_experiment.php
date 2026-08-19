<?php
/**
 * File: delete_experiment.php
 * Description: Deletes an experiment
 *
 * Query params:
 *   id - Experiment pkey (required)
 *
 * Returns success message on deletion.
 * Requires login - user must own the experiment or be admin.
 */

// Change to root directory for proper include path resolution
chdir('../..');

session_start();

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
    $_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

header('Content-type: application/json');

// Validate experiment ID
$experiment_pkey = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($experiment_pkey <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Experiment ID is required']);
    exit;
}

// Require login
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Only accept POST for deletion
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST to delete.']);
    exit;
}

$userpkey = $_SESSION['userpkey'];

include_once("adminkeys.php");
include("prepare_connections.php");
include_once("experimental/api/update_keywords.php");

$is_admin = in_array($userpkey, $admin_pkeys);

// Get experiment and verify ownership
if ($is_admin) {
    $row = $db->get_row_prepared("
        SELECT e.*, p.name as project_name
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.pkey = $1
    ", array($experiment_pkey));
} else {
    $row = $db->get_row_prepared("
        SELECT e.*, p.name as project_name
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.pkey = $1 AND e.userpkey = $2
    ", array($experiment_pkey, $userpkey));
}

if (empty($row->pkey)) {
    http_response_code(404);
    echo json_encode(['error' => 'Experiment not found or access denied']);
    exit;
}

// Store experiment info for response
$experiment_id = $row->id;
$project_pkey = (int)$row->project_pkey;
$project_name = $row->project_name;

include_once("includes/UUID.php");
$uuid_gen = new UUID();

// Version snapshot BEFORE deleting the experiment (restores the
// pre-Vue-rewrite delete_experiment behavior). Log-and-continue: a
// snapshot failure must not block the deletion.
try {
    include_once("expdb/straboexpclass.php");
    $exp = new StraboExp($neodb, $userpkey, $db);
    $exp->setuuid($uuid_gen);
    $exp->createProjectVersion($project_pkey);
} catch (Exception $e) {
    error_log("Failed to create version backup before deleting experiment $experiment_pkey: " . $e->getMessage());
}

// Mirror the sample removal into strabosamples.* BEFORE deleting the
// experiment row. exp_sample_sync's empty-sample branch removes the
// straboexp.sample row + the spine link (priority-aware). Keyed on the
// experiment OWNER's userpkey ($row->userpkey), which differs from the
// session user on the admin path. Without this the spine row is orphaned.
include_once("experimental/lib/sample_sync.php");
$owner_userpkey = (int)$row->userpkey;
exp_sample_sync($db, $uuid_gen, $experiment_pkey, $owner_userpkey, null, $neodb);

// Delete the experiment - include userpkey in WHERE for extra security
if ($is_admin) {
    $db->prepare_query("DELETE FROM straboexp.experiment WHERE pkey = $1", array($experiment_pkey));
} else {
    $db->prepare_query("DELETE FROM straboexp.experiment WHERE pkey = $1 AND userpkey = $2", array($experiment_pkey, $userpkey));
}

// Regenerate parent project keywords after experiment removal
updateExpProjectKeywords($db, $project_pkey);

// StraboSearch live-sync (§5.3): drop the experiment's index row — keyed on
// the OWNER (item_id = experiment.id string, item_userpkey = e.userpkey).
require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';
StraboSearchSync::removeExperiment($db, $experiment_id, $owner_userpkey);

// Return success response
$result = new stdClass();
$result->success = true;
$result->message = 'Experiment deleted successfully';
$result->deleted_experiment_id = $experiment_id;
$result->project_pkey = $project_pkey;
$result->project_name = $project_name;

echo json_encode($result, JSON_PRETTY_PRINT);
