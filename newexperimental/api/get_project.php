<?php
/**
 * File: get_project.php
 * Description: Retrieves a single project with its experiments
 *
 * Query params:
 *   id - Project pkey (required)
 *
 * Returns project details with list of experiments.
 * Requires login - user must own the project or it must be public.
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

// Validate project ID
$project_pkey = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($project_pkey <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Project ID is required']);
    exit;
}

// Require login for project access
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
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
            p.userpkey,
            p.uuid,
            p.name,
            p.notes,
            p.ispublic,
            to_char(p.created_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY') as created_date,
            to_char(p.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') as modified_date,
            EXTRACT(EPOCH FROM p.created_timestamp)::integer as created_timestamp,
            EXTRACT(EPOCH FROM p.modified_timestamp)::integer as modified_timestamp
        FROM straboexp.project p
        WHERE p.pkey = $1
    ", array($project_pkey));
} else {
    $row = $db->get_row_prepared("
        SELECT
            p.pkey,
            p.userpkey,
            p.uuid,
            p.name,
            p.notes,
            p.ispublic,
            to_char(p.created_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY') as created_date,
            to_char(p.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') as modified_date,
            EXTRACT(EPOCH FROM p.created_timestamp)::integer as created_timestamp,
            EXTRACT(EPOCH FROM p.modified_timestamp)::integer as modified_timestamp
        FROM straboexp.project p
        WHERE p.pkey = $1 AND (p.userpkey = $2 OR p.ispublic = true)
    ", array($project_pkey, $userpkey));
}

if (empty($row->pkey)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    exit;
}

// Build project object
$project = new stdClass();
$project->pkey = (int)$row->pkey;
$project->uuid = $row->uuid;
$project->name = $row->name;
$project->description = $row->notes;
$project->is_public = ($row->ispublic === 't' || $row->ispublic === true);
$project->created_date = $row->created_date;
$project->modified_date = $row->modified_date;
$project->created_timestamp = (int)$row->created_timestamp;
$project->modified_timestamp = (int)$row->modified_timestamp;

// Permission flags
$project->is_owner = ((int)$row->userpkey === $userpkey);
$project->can_edit = ($project->is_owner || $is_admin);
$project->can_delete = ($project->is_owner || $is_admin);

// Get experiments for this project
$exp_rows = $db->get_results_prepared("
    SELECT
        e.pkey,
        e.uuid,
        e.json,
        to_char(e.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') as modified_date,
        EXTRACT(EPOCH FROM e.modified_timestamp)::integer as modified_timestamp
    FROM straboexp.experiment e
    WHERE e.project_pkey = $1
    ORDER BY e.modified_timestamp DESC
", array($project_pkey));

$experiments = [];
foreach ($exp_rows as $exp_row) {
    $exp = new stdClass();
    $exp->pkey = (int)$exp_row->pkey;
    $exp->uuid = $exp_row->uuid;
    $exp->modified_date = $exp_row->modified_date;
    $exp->modified_timestamp = (int)$exp_row->modified_timestamp;

    // Extract useful info from experiment JSON
    if (!empty($exp_row->json)) {
        $json_data = json_decode($exp_row->json);
        if ($json_data) {
            // Get experiment ID from JSON
            $exp->id = isset($json_data->experiment_id) ? $json_data->experiment_id : null;

            // Get apparatus type from apparatus section
            if (isset($json_data->apparatus) && isset($json_data->apparatus->apparatus_type)) {
                $exp->apparatus_type = $json_data->apparatus->apparatus_type;
            } else {
                $exp->apparatus_type = null;
            }

            // Get sample name if available
            if (isset($json_data->sample) && isset($json_data->sample->sample_id)) {
                $exp->sample_id = $json_data->sample->sample_id;
            }
        }
    }

    $experiments[] = $exp;
}

$project->experiments = $experiments;

echo json_encode($project, JSON_PRETTY_PRINT);
