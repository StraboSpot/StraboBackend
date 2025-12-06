<?php
/**
 * File: get_experiment.php
 * Description: Retrieves a single experiment with all its data
 *
 * Query params:
 *   id - Experiment pkey (required)
 *
 * Returns experiment JSON with all LAPS sections.
 * Requires login - user must own the experiment.
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

// Require login for experiment access
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userpkey = $_SESSION['userpkey'];

include_once("adminkeys.php");
include("prepare_connections.php");

$is_admin = in_array($userpkey, $admin_pkeys);

// Query experiment - must be owned by user or user is admin
if ($is_admin) {
    $row = $db->get_row_prepared("
        SELECT
            e.pkey,
            e.project_pkey,
            e.userpkey,
            e.id as experiment_id,
            e.uuid,
            e.json,
            to_char(e.created_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY') as created_date,
            to_char(e.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') as modified_date,
            EXTRACT(EPOCH FROM e.created_timestamp)::integer as created_timestamp,
            EXTRACT(EPOCH FROM e.modified_timestamp)::integer as modified_timestamp,
            p.name as project_name
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.pkey = $1
    ", array($experiment_pkey));
} else {
    $row = $db->get_row_prepared("
        SELECT
            e.pkey,
            e.project_pkey,
            e.userpkey,
            e.id as experiment_id,
            e.uuid,
            e.json,
            to_char(e.created_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY') as created_date,
            to_char(e.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') as modified_date,
            EXTRACT(EPOCH FROM e.created_timestamp)::integer as created_timestamp,
            EXTRACT(EPOCH FROM e.modified_timestamp)::integer as modified_timestamp,
            p.name as project_name
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.pkey = $1 AND e.userpkey = $2
    ", array($experiment_pkey, $userpkey));
}

if (empty($row->pkey)) {
    http_response_code(404);
    echo json_encode(['error' => 'Experiment not found']);
    exit;
}

// Build experiment object
$experiment = new stdClass();
$experiment->pkey = (int)$row->pkey;
$experiment->project_pkey = (int)$row->project_pkey;
$experiment->project_name = $row->project_name;
$experiment->experiment_id = $row->experiment_id;
$experiment->uuid = $row->uuid;
$experiment->created_date = $row->created_date;
$experiment->modified_date = $row->modified_date;
$experiment->created_timestamp = (int)$row->created_timestamp;
$experiment->modified_timestamp = (int)$row->modified_timestamp;

// Permission flags
$experiment->is_owner = ((int)$row->userpkey === $userpkey);
$experiment->can_edit = ($experiment->is_owner || $is_admin);
$experiment->can_delete = ($experiment->is_owner || $is_admin);

// Parse and include the full JSON data (LAPS sections)
if (!empty($row->json)) {
    $json_data = json_decode($row->json);
    if ($json_data) {
        $experiment->data = $json_data;
    } else {
        $experiment->data = new stdClass();
    }
} else {
    $experiment->data = new stdClass();
}

echo json_encode($experiment, JSON_PRETTY_PRINT);
