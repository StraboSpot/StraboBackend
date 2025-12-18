<?php
/**
 * File: upload_document.php
 * Description: Uploads a document file for experiments
 *
 * This is a wrapper around the existing /experimental/inNewDocument.php
 * to maintain consistency with the new experimental API structure.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Set JSON response header
header('Content-Type: application/json');

// Include login check and database connections
include("../../logincheck.php");
include("../../prepare_connections.php");

// Check if user is logged in
if (!isset($userpkey) || $userpkey == 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Validate required fields
if (!isset($_REQUEST['uuid']) || empty($_REQUEST['uuid'])) {
    http_response_code(400);
    echo json_encode(['error' => 'UUID is required']);
    exit;
}

if (!isset($_FILES['infile']) || $_FILES['infile']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload'
    ];
    $errorCode = $_FILES['infile']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errorMsg = $errorMessages[$errorCode] ?? 'Unknown upload error';
    echo json_encode(['error' => $errorMsg]);
    exit;
}

// Extract file data
$f = $_FILES['infile'];
$original_filename = $f['name'];
$uuid = preg_replace('/[^a-zA-Z0-9\-]/', '', $_REQUEST['uuid']); // Sanitize UUID
$tmp_name = $f['tmp_name'];

// Validate UUID format (should be a valid UUID v4)
if (strlen($uuid) !== 36) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid UUID format']);
    exit;
}

// Sanitize filename - replace spaces with underscores
$original_filename = preg_replace('/\s+/', '_', $original_filename);

// Store file metadata in PostgreSQL
$result = $db->query("
    INSERT INTO straboexp.file_holdings
        (userpkey, uuid, original_filename)
    VALUES (
        $userpkey,
        '$uuid',
        '" . pg_escape_string($original_filename) . "'
    )
");

if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store file metadata']);
    exit;
}

// Move file to filesystem (new location for newexperimental app)
$targetPath = "/srv/app/www/experimental/expimages/$uuid";
if (!move_uploaded_file($tmp_name, $targetPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to move uploaded file']);
    exit;
}

// Return success response
echo json_encode([
    'success' => true,
    'uuid' => $uuid,
    'filename' => $original_filename,
    'url' => "https://strabospot.org/i/$uuid/$original_filename"
]);
?>
