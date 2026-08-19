<?php
/**
 * File: download_pdf.php
 * Description: Downloads an experiment as a PDF file
 *
 * Query params:
 *   id - Experiment pkey (required)
 *
 * Returns the experiment as a downloadable PDF file.
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

// Anonymous or expired sessions proceed with no identity; prepare_connections.php
// maps that to the no-user sentinel (99999) and the access-control query below
// (owner OR public project) decides visibility. See softlogincheck.php.
if ($_SESSION['loggedin'] != "yes") {
    $_SESSION['userpkey'] = "";
}

$userpkey = $_SESSION['userpkey'];

include_once("adminkeys.php");
include("prepare_connections.php");

$is_admin = in_array($userpkey, $admin_pkeys);

// Query experiment - must be owned by user, parent project is public, or user is admin.
// e.userpkey is selected for the spine-overlay lookup (samples-app edits flow
// back via strabosamples.* spine, keyed on owner).
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

// Overlay strabosamples spine onto the embedded sample block BEFORE PDF
// rendering, so the generated PDF reflects any Samples-app spine edits.
// See experimental/lib/sample_overlay.php for the spine → JSON path mapping.
require_once(__DIR__ . '/../lib/sample_overlay.php');
experimental_sample_overlay_apply($experimentData, $db, (int)$row->userpkey);

// Include the PDF generator
require_once(__DIR__ . '/../lib/ExperimentPDF.php');

// Generate the PDF
try {
    $pdf = new ExperimentPDF();
    $pdf->setExperimentData($experimentData, $row->experiment_id, $row->project_name);
    // Clickable drill-down into the linked StraboSamples record (keyed on
    // the experiment OWNER — the spine PK is composite (id, userpkey)).
    if (!empty($experimentData->sample->strabo_id)) {
        $pdf->setSamplesLink(experimental_samples_detail_url((int)$row->userpkey, $experimentData->sample->strabo_id));
    }
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
