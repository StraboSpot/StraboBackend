<?php
/**
 * File: view_document.php
 * Description: Serves uploaded document files with proper headers
 *
 * Usage: view_document.php?uuid={uuid}&filename={original_filename}
 *
 * Requires authentication - user must own the document or be admin.
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

// Require login for document access
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    exit("Authentication required.");
}

$userpkey = $_SESSION['userpkey'];

// Get and sanitize parameters
$uuid = isset($_REQUEST['uuid']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_REQUEST['uuid']) : '';
$original_filename = isset($_REQUEST['filename']) ? $_REQUEST['filename'] : '';

// Validate UUID
if (empty($uuid) || strlen($uuid) !== 36) {
    http_response_code(400);
    exit("Invalid or missing UUID.");
}

// Include database and admin configuration
include_once("adminkeys.php");
include("prepare_connections.php");

$is_admin = in_array($userpkey, $admin_pkeys);

// Verify document ownership - user must own the document or be admin
if (!$is_admin) {
    $owner_check = $db->get_var_prepared(
        "SELECT userpkey FROM straboexp.file_holdings WHERE uuid = $1",
        array($uuid)
    );

    if ($owner_check === null) {
        // Document not found in database
        http_response_code(404);
        exit("Document not found.");
    }

    if ((int)$owner_check !== $userpkey) {
        // User doesn't own this document
        http_response_code(403);
        exit("Access denied.");
    }
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
