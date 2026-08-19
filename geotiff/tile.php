<?php
/**
 * File: tile.php
 * Description: Handles tile operations
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


$hash=$_GET['hash'];
$x=$_GET['x'];
$y=$_GET['y'];
$z=$_GET['z'];

if(!preg_match('/^[A-Za-z0-9]+$/', $hash)){exit("Invalid Request");}
if(!is_numeric($x)){exit("Invalid Request");}
if(!is_numeric($y)){exit("Invalid Request");}
if(!is_numeric($z)){exit("Invalid Request");}

if(file_exists("/srv/app/www/geotiff/upload/files/$hash.tif") && file_exists("/srv/app/www/geotiff/upload/maps/$hash.map")){

	//Render with the mapserv binary in THIS container. The map file's
	///var/www/... paths resolve here via the /var/www -> /srv/app/www
	//symlink. The old file_get_contents to strabospot.org/cgi-bin/mapserv
	//looped back through the public host Apache and tied up a second
	//worker per tile (2026-08-18 wedge incident, same class as hillshade).
	putenv("QUERY_STRING=map=/var/www/geotiff/upload/maps/".$hash.".map&layer=geotifflayer&mode=tile&tile=".$x."+".$y."+".$z);
	putenv("REQUEST_METHOD=GET");
	$out = shell_exec("/usr/lib/cgi-bin/mapserv");
	putenv("QUERY_STRING");
	putenv("REQUEST_METHOD");

	//mapserv emits CGI headers, then the image
	$img = false;
	$headerend = strpos($out, "\r\n\r\n");
	if($headerend !== false){
		$img = substr($out, $headerend + 4);
	}

	if($img !== false && substr($img,0,8) == "\x89PNG\r\n\x1a\n"){
		header("Content-Type: image/png");
		echo $img;
	}else{
		header("HTTP/1.0 404 Not Found");
		header("Content-Type: image/png");
		$im = @imagecreate(256, 256)
			or die("Cannot Initialize new GD image stream");
		$background_color = imagecolorallocate($im, 255, 255, 255);
		$text_color = imagecolorallocate($im, 0, 0, 0);
		imagestring($im, 5, 110, 90,  "Error!", $text_color);
		imagestring($im, 2, 60, 110,  "GeoTIFF $hash", $text_color);
		imagestring($im, 5, 60, 130,  "failed to render.", $text_color);
		imagepng($im);
		imagedestroy($im);
	}

}else{

	header("HTTP/1.0 404 Not Found");

	header("Content-Type: image/png");
	$im = @imagecreate(256, 256)
		or die("Cannot Initialize new GD image stream");
	$background_color = imagecolorallocate($im, 255, 255, 255);
	$text_color = imagecolorallocate($im, 0, 0, 0);
	imagestring($im, 5, 110, 90,  "Error!", $text_color);
	imagestring($im, 2, 60, 110,  "GeoTIFF $hash", $text_color);
	imagestring($im, 5, 70, 130,  "does not exist.", $text_color);
	imagepng($im);
	imagedestroy($im);

}

exit();

function dumpVar($var){
	echo "<pre>";
	print_r($var);
	echo "</pre>";
}

dumpVar($_GET);

?>