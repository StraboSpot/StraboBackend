<?php
/**
 * File: view_experimental_uploaded_file.php
 * Description: Serves legacy StraboExperimental document links.
 *
 * Legacy documents (and upload_document.php responses) embed URLs of the form
 * https://strabospot.org/i/{uuid}/{filename}, which the root .htaccess rewrites
 * to this file. It was removed in the Dec-2025 Vue rewrite, which broke every
 * stored document link (the SPA .htaccess fallback sent them to index.php).
 *
 * This endpoint is intentionally unauthenticated, matching the original
 * behavior: legacy links are baked into stored experiment JSON, DOI exports,
 * and public experiments, so an ownership gate here would break non-owner
 * viewers. New uploads viewed through the Vue app use api/view_document.php,
 * which does enforce ownership.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Sanitize UUID — files are stored by bare UUID, so this also blocks traversal
$uuid = isset($_REQUEST['uuid']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_REQUEST['uuid']) : '';
$original_filename = isset($_REQUEST['original_filename']) ? $_REQUEST['original_filename'] : '';

if (empty($uuid) || strlen($uuid) !== 36) {
    http_response_code(400);
    exit("Invalid or missing UUID.");
}

$filePath = __DIR__ . "/expimages/$uuid";

if (!file_exists($filePath)) {
    http_response_code(404);
    exit("File not found.");
}

// Determine MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

if (!$mimeType) {
    $mimeType = 'application/octet-stream';
}

// Sanitize filename for Content-Disposition header
$safeFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $original_filename);
if (empty($safeFilename)) {
    $safeFilename = $uuid;
}

header("Content-Type: $mimeType");
header('Content-Disposition: inline; filename="' . $safeFilename . '"');
header('Content-Length: ' . filesize($filePath));
header("Cache-Control: max-age=3600");

readfile($filePath);
exit;
?>
