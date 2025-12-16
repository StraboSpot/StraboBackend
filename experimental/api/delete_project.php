<?php
/**
 * File: delete_project.php
 * Description: Deletes an experimental project and all its experiments
 *
 * Query params:
 *   id - Project pkey (required)
 *
 * Creates a version backup before deletion.
 * Requires login - user must own the project or be admin.
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

// Require login
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Only accept POST (for safety, even though it's a delete operation)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validate project ID
$project_pkey = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($project_pkey <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Project ID is required']);
    exit;
}

$userpkey = $_SESSION['userpkey'];

include_once("adminkeys.php");
include("prepare_connections.php");
include("expdb/straboexpclass.php");
include_once("includes/UUID.php");

$is_admin = in_array($userpkey, $admin_pkeys);

// Verify ownership (or admin)
if ($is_admin) {
    $row = $db->get_row_prepared(
        "SELECT * FROM straboexp.project WHERE pkey = $1",
        array($project_pkey)
    );
} else {
    $row = $db->get_row_prepared(
        "SELECT * FROM straboexp.project WHERE pkey = $1 AND userpkey = $2",
        array($project_pkey, $userpkey)
    );
}

if (empty($row->pkey)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found or access denied']);
    exit;
}

// Create a version backup before deletion
try {
    $exp = new StraboExp($neodb, $userpkey, $db);
    $uuid_gen = new UUID();
    $exp->setuuid($uuid_gen);
    $exp->createProjectVersion($project_pkey);
} catch (Exception $e) {
    // Log error but continue with deletion
    error_log("Failed to create version backup before deleting project $project_pkey: " . $e->getMessage());
}

// Delete all experiments for this project first - include userpkey for extra security
if ($is_admin) {
    $db->prepare_query(
        "DELETE FROM straboexp.experiment WHERE project_pkey = $1",
        array($project_pkey)
    );
} else {
    $db->prepare_query(
        "DELETE FROM straboexp.experiment WHERE project_pkey = $1 AND userpkey = $2",
        array($project_pkey, $userpkey)
    );
}

// Delete the project
if ($is_admin) {
    $db->prepare_query(
        "DELETE FROM straboexp.project WHERE pkey = $1",
        array($project_pkey)
    );
} else {
    $db->prepare_query(
        "DELETE FROM straboexp.project WHERE pkey = $1 AND userpkey = $2",
        array($project_pkey, $userpkey)
    );
}

// Return success
$result = new stdClass();
$result->success = true;
$result->message = 'Project deleted successfully';

echo json_encode($result, JSON_PRETTY_PRINT);
