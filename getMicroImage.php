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


$pkey = $_GET['pkey'];
$id = $_GET['id'];

$imagePath = $_SERVER['DOCUMENT_ROOT']."/straboMicroFiles/".$pkey."/images/".$id.".jpg";
$img = @imagecreatefromjpeg($imagePath);
if(!$img){ //missing or unreadable file: imagejpeg(false) is a TypeError on PHP 8
	http_response_code(404);
	exit();
}

header('Content-type: image/jpeg');
imagejpeg($img);

?>