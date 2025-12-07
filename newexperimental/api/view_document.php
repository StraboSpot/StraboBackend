<?php
/**
 * File: view_document.php
 * Description: Serves uploaded document files with proper headers
 *
 * Usage: view_document.php?uuid={uuid}&filename={original_filename}
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Get and sanitize parameters
$uuid = isset($_REQUEST['uuid']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_REQUEST['uuid']) : '';
$original_filename = isset($_REQUEST['filename']) ? $_REQUEST['filename'] : '';

// Validate UUID
if (empty($uuid) || strlen($uuid) !== 36) {
    http_response_code(400);
    exit("Invalid or missing UUID.");
}

// Path to the uploaded file
$filePath = dirname(__DIR__) . "/expimages/$uuid";

// Check if file exists
if (!file_exists($filePath)) {
    http_response_code(404);
    exit("File not found.");
}

// Determine MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

// If we couldn't determine MIME type, default to octet-stream
if (!$mimeType) {
    $mimeType = 'application/octet-stream';
}

// Sanitize filename for Content-Disposition header
$safeFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $original_filename);
if (empty($safeFilename)) {
    $safeFilename = $uuid;
}

// Set headers
header("Content-Type: $mimeType");
header('Content-Disposition: inline; filename="' . $safeFilename . '"');
header('Content-Length: ' . filesize($filePath));
header("Cache-Control: max-age=3600"); // Cache for 1 hour

// Clear output buffer and send file
ob_clean();
flush();
readfile($filePath);
exit;
?>
