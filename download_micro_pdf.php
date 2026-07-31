<?php
/**
 * File: download_micro_pdf.php
 * Description: PDF document generation handler
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("prepare_connections.php");
require_once(__DIR__ . "/microdb/lib/MicroProjectPDF.php");

$id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$meta = $db->get_row_prepared("SELECT name, userpkey FROM micro_projectmetadata WHERE id = $1", array($id));
$filename = $meta ? $meta->name : '';
$filename = preg_replace('/[^A-Za-z0-9\-_ ]/', '', $filename);
$filename = trim($filename);
$filename = str_replace(" ", "_", $filename);
$filename = strtolower($filename);
$filename = $filename.".pdf";

// Regenerate project.pdf with the strabosamples.* spine overlay when a
// Samples-app edit has marked it dirty, so the website PDF download reflects
// those edits (the app/REST download paths already do this). No-op + never
// fatal when clean — see micro_regenerate_pdf_if_dirty.
if ($meta) micro_regenerate_pdf_if_dirty($db, $id, (int)$meta->userpkey);

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=$filename");
header("Content-Length: " . filesize($_SERVER['DOCUMENT_ROOT']."/straboMicroFiles/".$id."/project.pdf"));

readfile($_SERVER['DOCUMENT_ROOT']."/straboMicroFiles/".$id."/project.pdf");
exit;
?>

