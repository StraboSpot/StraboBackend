<?php
/**
 * File: view_document.php
 * Description: Serves uploaded document files with proper headers
 *
 * Usage: view_document.php?uuid={uuid}&filename={original_filename}
 *
 * Access: public-by-obscurity (unguessable UUID), matching the legacy /i/
 * endpoint (view_experimental_uploaded_file.php) that serves the SAME
 * files from the same directory without authentication — document URLs are
 * baked into stored experiment JSON, so an ownership gate here only broke
 * non-owner viewers of public experiments while securing nothing the /i/
 * route didn't already expose. Matches the site-wide accepted posture for
 * image/file serving.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Change to root directory for proper include path resolution
chdir('../..');

session_start();

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
    $_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

// Get and sanitize parameters
// NOTE: named $doc_uuid, not $uuid — prepare_connections.php (included below)
// assigns a UUID-generator instance to $uuid and would clobber it
$doc_uuid = isset($_REQUEST['uuid']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_REQUEST['uuid']) : '';
$original_filename = isset($_REQUEST['filename']) ? $_REQUEST['filename'] : '';

// Validate UUID
if (empty($doc_uuid) || strlen($doc_uuid) !== 36) {
    http_response_code(400);
    exit("Invalid or missing UUID.");
}

// Include database configuration
include("prepare_connections.php");

// Confirm the document is a registered upload (404 for unknown UUIDs).
// No ownership gate — see the access note in the header.
$holding_check = $db->get_var_prepared(
    "SELECT userpkey FROM straboexp.file_holdings WHERE uuid = $1",
    array($doc_uuid)
);

if ($holding_check === null) {
    http_response_code(404);
    exit("Document not found.");
}

// Path to the uploaded file
$filePath = dirname(__DIR__) . "/expimages/$doc_uuid";

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
    $safeFilename = $doc_uuid;
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
