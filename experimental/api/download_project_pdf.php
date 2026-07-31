<?php
/**
 * File: download_project_pdf.php
 * Description: Downloads a project as a PDF file with all experiments
 *
 * Query params:
 *   id - Project pkey (required)
 *
 * Generates a single PDF with project metadata followed by all experiments.
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

// Validate project ID
$project_pkey = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($project_pkey <= 0) {
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Project ID is required']);
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

// Query project with access control
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
    header('Content-type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Project not found']);
    exit;
}

// Get all experiments for this project. e.userpkey is selected for the
// per-experiment spine-overlay lookup (per-experiment owner, not project owner).
$exp_rows = $db->get_results_prepared("
    SELECT e.id as experiment_id, e.json, e.userpkey
    FROM straboexp.experiment e
    WHERE e.project_pkey = $1
    ORDER BY e.modified_timestamp DESC
", array($project_pkey));

if (empty($exp_rows)) {
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'No experiments found in this project']);
    exit;
}

// Pre-decode the experiment JSONs and apply spine overlay in a single
// batched lookup before PDF rendering. Storing the decoded objects on
// each row prevents a second json_decode + drift between the JSON the
// overlay touched and the JSON the renderer reads.
require_once(__DIR__ . '/../lib/sample_overlay.php');
$overlayTuples = array();
foreach ($exp_rows as $exp_row) {
    $exp_row->_experimentData = null;
    if (!empty($exp_row->json)) {
        $decoded = json_decode($exp_row->json);
        if ($decoded) {
            $exp_row->_experimentData = $decoded;
            $overlayTuples[] = array($decoded, (int)$exp_row->userpkey);
        }
    }
}
if (!empty($overlayTuples)) {
    experimental_sample_overlay_apply_batch($overlayTuples, $db);
}

// Include the PDF generator
require_once(__DIR__ . '/../lib/ExperimentPDF.php');

try {
    $pdf = new ExperimentPDF();

    // Generate project cover page
    $pdf->AliasNbPages();
    $pdf->AddPage();

    // Project title
    $pdf->SetFont('DejaVu', 'B', 24);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->Ln(20);
    $pdf->MultiCell(0, 12, $row->name ?: 'Untitled Project', 0, 'C');
    $pdf->Ln(5);

    // Subtitle
    $pdf->SetFont('DejaVu', '', 12);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 8, 'StraboExperimental Project Report', 0, 1, 'C');
    $pdf->Ln(10);

    // Project metadata
    $pdf->SetFont('DejaVu', '', 10);
    $pdf->SetTextColor(33, 37, 41);

    if (!empty($row->notes)) {
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->Cell(0, 6, 'Description:', 0, 1);
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->MultiCell(0, 5, $row->notes, 0, 'L');
        $pdf->Ln(5);
    }

    $pdf->SetFont('DejaVu', 'B', 9);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(45, 5, 'Created:', 0, 0);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->Cell(0, 5, $row->created_timestamp, 0, 1);

    $pdf->SetFont('DejaVu', 'B', 9);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(45, 5, 'Last Modified:', 0, 0);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->Cell(0, 5, $row->modified_timestamp, 0, 1);

    $pdf->SetFont('DejaVu', 'B', 9);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(45, 5, 'Experiments:', 0, 0);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->Cell(0, 5, count($exp_rows), 0, 1);

    $pdf->SetFont('DejaVu', 'B', 9);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(45, 5, 'Exported:', 0, 0);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->Cell(0, 5, date('Y-m-d H:i:s T'), 0, 1);

    $pdf->Ln(10);

    // Table of contents
    $pdf->SetFont('DejaVu', 'B', 12);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->Cell(0, 8, 'Experiments in this Project:', 'B', 1);
    $pdf->Ln(3);

    $pdf->SetFont('DejaVu', '', 10);
    foreach ($exp_rows as $idx => $exp_row) {
        $exp_id = $exp_row->experiment_id ?: 'Experiment ' . ($idx + 1);
        $pdf->Cell(10, 6, ($idx + 1) . '.', 0, 0);
        $pdf->Cell(0, 6, $exp_id, 0, 1);
    }

    // Generate each experiment
    foreach ($exp_rows as $exp_row) {
        // Use the spine-overlaid decoded data stashed earlier so the PDF
        // reads the same JSON the overlay touched.
        $experimentData = isset($exp_row->_experimentData) ? $exp_row->_experimentData : null;
        if (empty($experimentData)) continue;

        // Set experiment data and generate on a new page
        $pdf->setExperimentData($experimentData, $exp_row->experiment_id, $row->name);
        $pdf->AddPage();
        $pdf->generateTitleSection();

        if (!empty($experimentData->sample)) {
            $pdf->generateSampleSection($experimentData->sample);
        }

        if (!empty($experimentData->facility) || !empty($experimentData->apparatus)) {
            $pdf->generateFacilityApparatusSection(
                $experimentData->facility ?? null,
                $experimentData->apparatus ?? null
            );
        }

        if (!empty($experimentData->experiment)) {
            $pdf->generateExperimentalSetupSection($experimentData->experiment);
        }

        if (!empty($experimentData->daq)) {
            $pdf->generateDAQSection($experimentData->daq);
        }

        if (!empty($experimentData->experiment->protocol)) {
            $pdf->generateProtocolSection($experimentData->experiment->protocol);
        }

        if (!empty($experimentData->data)) {
            $pdf->generateDataSection($experimentData->data);
        }
    }

    // Generate filename
    $filename = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $row->name);
    $filename = trim($filename);
    if (empty($filename)) {
        $filename = 'project';
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
