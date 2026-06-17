<?php
/**
 * File: download_micro_file.php
 * Description: Downloads files from Strabo Micro projects
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("prepare_connections.php");
require_once(__DIR__ . "/microdb/lib/sample_overlay.php");

$id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$meta = $db->get_row_prepared("SELECT name, userpkey FROM micro_projectmetadata WHERE id = $1", array($id));
$filename = $meta ? $meta->name : '';
$filename = preg_replace('/[^A-Za-z0-9\-_ ]/', '', $filename);
$filename = trim($filename);
$filename = str_replace(" ", "_", $filename);
$filename = strtolower($filename);
$filename = $filename.".smz";

$srcZip = $_SERVER['DOCUMENT_ROOT']."/straboMicroFiles/".$id."/project.zip";

// Overlay the strabosamples.* spine onto the project.json inside the .smz so
// the download reflects any Samples-app edits made after upload. Returns the
// original path (zero rewrite) when the project has no spine edits, or a temp
// overlaid zip otherwise. Never breaks the download — falls back to original.
$servePath = $meta ? micro_overlay_smz($db, $srcZip, (int)$meta->userpkey) : $srcZip;

header("Content-Type: application/smz");
header("Content-Disposition: attachment; filename=$filename");
header("Content-Length: " . filesize($servePath));

readfile($servePath);
if ($servePath !== $srcZip) @unlink($servePath);
exit;
?>

