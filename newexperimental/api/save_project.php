<?php
/**
 * File: save_project.php
 * Description: Creates or updates an experimental project
 *
 * POST body (JSON):
 *   name        - Project name (required)
 *   description - Project description (optional)
 *   pkey        - Project pkey (optional - if provided, updates existing project)
 *
 * Returns the saved project data on success.
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

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$userpkey = $_SESSION['userpkey'];

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), false);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// Validate required fields
if (empty($input->name) || trim($input->name) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Project name is required']);
    exit;
}

include_once("adminkeys.php");
include("prepare_connections.php");
include_once("includes/UUID.php");

$is_admin = in_array($userpkey, $admin_pkeys);
$uuid_gen = new UUID();

$name = pg_escape_string(trim($input->name));
$description = pg_escape_string(trim($input->description ?? ''));

// Check if updating existing project
if (!empty($input->pkey)) {
    $project_pkey = (int)$input->pkey;

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

    // Update project
    $db->prepare_query("
        UPDATE straboexp.project SET
            name = $1,
            notes = $2,
            modified_timestamp = NOW()
        WHERE pkey = $3
    ", array($name, $description, $project_pkey));

    // Return updated project
    $result = new stdClass();
    $result->pkey = $project_pkey;
    $result->name = $name;
    $result->description = $description;
    $result->uuid = $row->uuid;
    $result->success = true;
    $result->message = 'Project updated successfully';

} else {
    // Create new project
    $project_pkey = $db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
    $uuid = $uuid_gen->v4();

    $db->prepare_query("
        INSERT INTO straboexp.project (pkey, userpkey, uuid, created_timestamp, modified_timestamp, name, notes, ispublic, keywords)
        VALUES ($1, $2, $3, NOW(), NOW(), $4, $5, false, NULL)
    ", array($project_pkey, $userpkey, $uuid, $name, $description));

    // Return created project
    $result = new stdClass();
    $result->pkey = (int)$project_pkey;
    $result->name = $name;
    $result->description = $description;
    $result->uuid = $uuid;
    $result->success = true;
    $result->message = 'Project created successfully';
}

echo json_encode($result, JSON_PRETTY_PRINT);
