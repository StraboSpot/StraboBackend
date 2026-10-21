<?php
/**
 * File: getMicroImage.php
 * Description: Retrieves and outputs JPEG images from Strabo Micro projects
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Access check (2026-10-21, docs/Micro_Image_Access_Fix.md): the pkey and id come from the URL
// and used to go straight into the file path with no login or project check. Now: a numeric
// project id the viewer may read (public, or own: the landing pages' rule), a micrograph id
// that cannot carry path pieces, and 404 for anything else.
include_once($_SERVER['DOCUMENT_ROOT']."/includes/config.inc.php");
include_once($_SERVER['DOCUMENT_ROOT']."/db.php");
include_once($_SERVER['DOCUMENT_ROOT']."/microdb/lib/landing_access.php");

$pkey = $_GET['pkey'] ?? '';
$id = $_GET['id'] ?? '';

if(!micro_landing_valid_image_id($id)) micro_landing_not_found();
if(!micro_landing_readable_row($db, $pkey, micro_landing_session_userpkey())) micro_landing_not_found();

$imagePath = $_SERVER['DOCUMENT_ROOT']."/straboMicroFiles/".(int)$pkey."/images/".$id.".jpg";
$img = is_file($imagePath) ? @imagecreatefromjpeg($imagePath) : false;
if(!$img) micro_landing_not_found(); //missing or unreadable file

header('Content-type: image/jpeg');
imagejpeg($img);

?>
