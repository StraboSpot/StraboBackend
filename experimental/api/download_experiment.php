<?php
/**
 * File: download_experiment.php
 * Description: Downloads an experiment as a JSON file
 *
 * Query params:
 *   id - Experiment pkey (required)
 *
 * Returns the experiment JSON as a downloadable file.
 * Requires login - user must own the experiment OR parent project must be public.
 */

// Change to root directory for proper include path resolution
chdir('../..');

session_start();

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
    $_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

// Validate experiment ID
$experiment_pkey = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($experiment_pkey <= 0) {
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Experiment ID is required']);
    exit;
}

// Require login
if ($_SESSION['loggedin'] != "yes") {
    header('Content-type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userpkey = $_SESSION['userpkey'];

include_once("adminkeys.php");
include("prepare_connections.php");

$is_admin = in_array($userpkey, $admin_pkeys);

// Query experiment - must be owned by user, parent project is public, or user is admin.
// e.userpkey is selected for the spine-overlay lookup at render time
// (samples-app edits flow back via strabosamples.* spine, keyed on owner).
if ($is_admin) {
    $row = $db->get_row_prepared("
        SELECT
            e.pkey,
            e.project_pkey,
            e.userpkey,
            e.id as experiment_id,
            e.uuid,
            e.json,
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
            p.name as project_name
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.pkey = $1 AND (e.userpkey = $2 OR p.ispublic = true)
    ", array($experiment_pkey, $userpkey));
}

if (empty($row->pkey)) {
    header('Content-type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Experiment not found']);
    exit;
}

// Build the export object
$export = new stdClass();
$export->experiment_id = $row->experiment_id;
$export->uuid = $row->uuid;
$export->project_name = $row->project_name;
$export->exported_at = date('c');

// Parse and include the LAPS data
if (!empty($row->json)) {
    $json_data = json_decode($row->json);
    if ($json_data) {
        // Overlay strabosamples spine onto the embedded sample block so any
        // Samples-app edit made after this experiment was last saved is
        // reflected in the JSON download. The owner pkey is e.userpkey
        // (the experiment's owner). See experimental/lib/sample_overlay.php
        // for the spine → JSON path mapping + v1 limitations.
        require_once(__DIR__ . '/../lib/sample_overlay.php');
        experimental_sample_overlay_apply($json_data, $db, (int)$row->userpkey);

        // Merge LAPS sections into export
        if (isset($json_data->facility)) $export->facility = $json_data->facility;
        if (isset($json_data->apparatus)) $export->apparatus = $json_data->apparatus;
        if (isset($json_data->daq)) $export->daq = $json_data->daq;
        if (isset($json_data->sample)) $export->sample = $json_data->sample;
        if (isset($json_data->experiment)) $export->experiment = $json_data->experiment;
        if (isset($json_data->data)) $export->data = $json_data->data;
    }
}

// Generate filename
$filename = 'experiment';
if (!empty($row->experiment_id)) {
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $row->experiment_id);
}
$filename .= '_' . date('Y-m-d') . '.json';

// Set headers for file download
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
