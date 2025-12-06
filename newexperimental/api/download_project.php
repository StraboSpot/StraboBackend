<?php
/**
 * File: download_project.php
 * Description: Downloads a project as a JSON file
 *
 * Query params:
 *   id - Project pkey (required)
 *
 * Returns the project with all experiments as a downloadable JSON file.
 * Requires login - user must own the project, it must be public, or user is admin.
 */

// Change to root directory for proper include path resolution
chdir('../..');

session_start();

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
    $_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

// Require login
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    die('Authentication required');
}

// Validate project ID
$project_pkey = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($project_pkey <= 0) {
    http_response_code(400);
    die('Project ID is required');
}

$userpkey = $_SESSION['userpkey'];

include_once("adminkeys.php");
include("prepare_connections.php");

$is_admin = in_array($userpkey, $admin_pkeys);

// Query project - must be owned by user, public, or user is admin
if ($is_admin) {
    $row = $db->get_row_prepared("
        SELECT
            p.pkey,
            p.uuid,
            p.name,
            p.notes,
            to_char(p.created_timestamp AT TIME ZONE 'UTC', 'Dy, Mon DD YYYY HH24:MI:SS UTC') as created_timestamp,
            to_char(p.modified_timestamp AT TIME ZONE 'UTC', 'Dy, Mon DD YYYY HH24:MI:SS UTC') as modified_timestamp
        FROM straboexp.project p
        WHERE p.pkey = $1
    ", array($project_pkey));
} else {
    $row = $db->get_row_prepared("
        SELECT
            p.pkey,
            p.uuid,
            p.name,
            p.notes,
            to_char(p.created_timestamp AT TIME ZONE 'UTC', 'Dy, Mon DD YYYY HH24:MI:SS UTC') as created_timestamp,
            to_char(p.modified_timestamp AT TIME ZONE 'UTC', 'Dy, Mon DD YYYY HH24:MI:SS UTC') as modified_timestamp
        FROM straboexp.project p
        WHERE p.pkey = $1 AND (p.userpkey = $2 OR p.ispublic = true)
    ", array($project_pkey, $userpkey));
}

if (empty($row->pkey)) {
    http_response_code(404);
    die('Project not found');
}

// Build project object
$project = new stdClass();
$project->name = $row->name;
$project->created_timestamp = $row->created_timestamp;
$project->last_modified_timestamp = $row->modified_timestamp;
$project->notes = $row->notes;
$project->uuid = $row->uuid;

// Get all experiments for this project
$exp_rows = $db->get_results_prepared(
    "SELECT json FROM straboexp.experiment WHERE project_pkey = $1",
    array($project_pkey)
);

$experiments = [];
foreach ($exp_rows as $exp_row) {
    if (!empty($exp_row->json)) {
        $experiments[] = json_decode($exp_row->json);
    }
}

$project->experiments = $experiments;

// Wrap in project container (matches old download format)
$out = new stdClass();
$out->project = $project;

// Generate safe filename
$filename = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $row->name);
$filename = trim($filename);
if (empty($filename)) {
    $filename = 'project';
}
$filename .= '.json';

// Output as downloadable JSON
header('Content-disposition: attachment; filename=' . $filename);
header('Content-type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT);
