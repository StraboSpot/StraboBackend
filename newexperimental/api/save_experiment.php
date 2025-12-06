<?php
/**
 * File: save_experiment.php
 * Description: Creates or updates an experiment
 *
 * POST body (JSON):
 *   project_pkey - Parent project pkey (required for new experiments)
 *   pkey         - Experiment pkey (optional - if provided, updates existing)
 *   experiment_id - Experiment ID string (optional)
 *   data         - Full LAPS data object with sections: facility, apparatus, daq, sample, experiment, data
 *
 * Returns the saved experiment data on success.
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

include_once("adminkeys.php");
include("prepare_connections.php");
include_once("includes/UUID.php");

$is_admin = in_array($userpkey, $admin_pkeys);
$uuid_gen = new UUID();

// Get experiment ID from input or from data section
$experiment_id = '';
if (!empty($input->experiment_id)) {
    $experiment_id = pg_escape_string(trim($input->experiment_id));
} elseif (!empty($input->data->experiment->id)) {
    $experiment_id = pg_escape_string(trim($input->data->experiment->id));
}

// Prepare JSON data for storage
$json_data = new stdClass();

// Copy LAPS sections if provided
if (!empty($input->data)) {
    if (isset($input->data->facility)) $json_data->facility = $input->data->facility;
    if (isset($input->data->apparatus)) $json_data->apparatus = $input->data->apparatus;
    if (isset($input->data->daq)) $json_data->daq = $input->data->daq;
    if (isset($input->data->sample)) $json_data->sample = $input->data->sample;
    if (isset($input->data->experiment)) $json_data->experiment = $input->data->experiment;
    if (isset($input->data->data)) $json_data->data = $input->data->data;
}

$json_string = pg_escape_string(json_encode($json_data));

// Check if updating existing experiment
if (!empty($input->pkey)) {
    $experiment_pkey = (int)$input->pkey;

    // Verify ownership (or admin)
    if ($is_admin) {
        $row = $db->get_row_prepared(
            "SELECT * FROM straboexp.experiment WHERE pkey = $1",
            array($experiment_pkey)
        );
    } else {
        $row = $db->get_row_prepared(
            "SELECT * FROM straboexp.experiment WHERE pkey = $1 AND userpkey = $2",
            array($experiment_pkey, $userpkey)
        );
    }

    if (empty($row->pkey)) {
        http_response_code(404);
        echo json_encode(['error' => 'Experiment not found or access denied']);
        exit;
    }

    // Update experiment
    $db->prepare_query("
        UPDATE straboexp.experiment SET
            id = $1,
            json = $2,
            modified_timestamp = NOW()
        WHERE pkey = $3
    ", array($experiment_id, $json_string, $experiment_pkey));

    // Return updated experiment
    $result = new stdClass();
    $result->pkey = $experiment_pkey;
    $result->experiment_id = $experiment_id;
    $result->uuid = $row->uuid;
    $result->project_pkey = (int)$row->project_pkey;
    $result->success = true;
    $result->message = 'Experiment updated successfully';

} else {
    // Create new experiment - project_pkey is required
    if (empty($input->project_pkey)) {
        http_response_code(400);
        echo json_encode(['error' => 'Project pkey is required for new experiments']);
        exit;
    }

    $project_pkey = (int)$input->project_pkey;

    // Verify user owns the project (or is admin)
    if ($is_admin) {
        $project_row = $db->get_row_prepared(
            "SELECT * FROM straboexp.project WHERE pkey = $1",
            array($project_pkey)
        );
    } else {
        $project_row = $db->get_row_prepared(
            "SELECT * FROM straboexp.project WHERE pkey = $1 AND userpkey = $2",
            array($project_pkey, $userpkey)
        );
    }

    if (empty($project_row->pkey)) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found or access denied']);
        exit;
    }

    // Generate new pkey and uuid
    $experiment_pkey = $db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
    $uuid = $uuid_gen->v4();

    // Insert new experiment
    $db->prepare_query("
        INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, created_timestamp, modified_timestamp, json, uuid)
        VALUES ($1, $2, $3, $4, NOW(), NOW(), $5, $6)
    ", array($experiment_pkey, $project_pkey, $userpkey, $experiment_id, $json_string, $uuid));

    // Return created experiment
    $result = new stdClass();
    $result->pkey = (int)$experiment_pkey;
    $result->experiment_id = $experiment_id;
    $result->uuid = $uuid;
    $result->project_pkey = $project_pkey;
    $result->success = true;
    $result->message = 'Experiment created successfully';
}

echo json_encode($result, JSON_PRETTY_PRINT);
