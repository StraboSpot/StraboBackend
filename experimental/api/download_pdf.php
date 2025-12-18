<?php
/**
 * File: download_pdf.php
 * Description: Downloads an experiment as a PDF file
 *
 * Query params:
 *   id - Experiment pkey (required)
 *
 * Returns the experiment as a downloadable PDF file.
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

// Query experiment - must be owned by user or user is admin
if ($is_admin) {
    $row = $db->get_row_prepared("
        SELECT
            e.pkey,
            e.project_pkey,
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
            e.id as experiment_id,
            e.uuid,
            e.json,
            p.name as project_name
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.pkey = $1 AND e.userpkey = $2
    ", array($experiment_pkey, $userpkey));
}

if (empty($row->pkey)) {
    header('Content-type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Experiment not found']);
    exit;
}

// Parse the JSON data
$experimentData = null;
if (!empty($row->json)) {
    $experimentData = json_decode($row->json);
}

if (empty($experimentData)) {
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'No experiment data available']);
    exit;
}

// Include the PDF generator
require_once(__DIR__ . '/../lib/ExperimentPDF.php');

// Generate the PDF
try {
    $pdf = new ExperimentPDF();
    $pdf->setExperimentData($experimentData, $row->experiment_id, $row->project_name);
    $pdf->generate();

    // Generate filename
    $filename = 'experiment';
    if (!empty($row->experiment_id)) {
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $row->experiment_id);
    }
    $filename .= '_' . date('Y-m-d') . '.pdf';

    // Output the PDF
    $pdf->Output('D', $filename);

} catch (Exception $e) {
    header('Content-type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Failed to generate PDF: ' . $e->getMessage()]);
    exit;
}
