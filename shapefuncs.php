<?php
/**
 * File: shapefuncs.php
 * Description: Handles shapefuncs operations
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


function myWKT($data, $shape_type)
{
	if (!$data) return null;
	switch ($shape_type) {
		case 1:
			return 'POINT('.$data['x'].' '.$data['y'].')';

		case 11:
			return 'POINT('.$data['x'].' '.$data['y'].')';

		case 8:
			return 'MULTIPOINT'.myImplodePoints($data['points']);

		case 3:
			$wkt = array();
			if($data['parts']!=""){
				foreach ($data['parts'] as $part) {
					$wkt[] = myLineImplodeParts($part);
				}

				if ($data['numparts'] > 1) {
					return 'MULTILINESTRING('.implode(', ', $wkt).')';
				} else {
					return 'LINESTRING'.implode(', ', $wkt);
				}

			}else{
				return "";
			}

			

		case 5:
			$wkt = array();
			foreach ($data['parts'] as $part) {
				$wkt[] = myImplodeParts($part);
			}
			if ($data['numparts'] > 1) {
				return 'MULTIPOLYGON('.implode(', ', $wkt).')';
			} else {
				return 'POLYGON'.implode(', ', $wkt).'';
			}
	}
}

function myImplodeParts($parts)
{
	$wkt = array();
	foreach ($parts as $part) {
		$wkt[] = '('.myImplodePoints($part).')';
	}
	return implode(', ', $wkt);
}

function myLineImplodeParts($parts)
{
	$wkt = array();
	foreach ($parts as $part) {
		$wkt[] = myImplodePoints($part);
	}
	return implode(', ', $wkt);
}

function myImplodePoints($points)
{
	$wkt = array();
	foreach ($points as $point) {
		$wkt[] = $point['x'].' '.$point['y'];
	}
	return '('.implode(', ', $wkt).')';
}

/*
	Upload work folders (ziptemp/<n>/) are served by Apache, so only the parts of a
	shapefile are ever written there, and the folder number and file names posted back
	by the later form steps are checked against this user's session before use.
*/

function shapefileAllowedExtensions(){
	return array("shp","shx","dbf","prj","cpg","sbn","sbx","qix","qpj","xml","fix","atx","aih","ain");
}

// Total uncompressed size of a zip, or false if it cannot be read.
function shapefileZipSize($zipPath){
	$za = new ZipArchive();
	if($za->open($zipPath) !== true) return false;
	$size = 0;
	for($i = 0; $i < $za->numFiles; $i++){
		$stat = $za->statIndex($i);
		$size += $stat['size'];
	}
	$za->close();
	return $size;
}

// Extract only shapefile parts into $destDir, keeping folders, spaces -> "_".
// Returns "" on success or an error message.
function shapefileExtractZip($zipPath, $destDir){
	$za = new ZipArchive();
	if($za->open($zipPath) !== true) return "Zip file could not be read.";
	$allowed = shapefileAllowedExtensions();
	$count = 0;
	for($i = 0; $i < $za->numFiles; $i++){
		$name = $za->getNameIndex($i);
		if($name === false || $name === "" || substr($name, -1) === "/") continue;
		if($name[0] === "/" || strpos($name, "\\") !== false || preg_match('#(^|/)\.\.(/|$)#', $name)) continue;
		if(preg_match('#(^|/)__MACOSX/#', $name)) continue;
		$parts = explode("/", str_replace(" ", "_", $name));
		$base = end($parts);
		if($base === "" || $base[0] === ".") continue;
		$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
		if(!in_array($ext, $allowed)) continue;
		foreach($parts as $part){
			if(!preg_match('/^[A-Za-z0-9_.\-()]+$/', $part)) continue 2;
		}
		$target = $destDir."/".implode("/", $parts);
		if(!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
		$in = $za->getStream($name);
		if(!$in) continue;
		$out = fopen($target, "wb");
		stream_copy_to_stream($in, $out);
		fclose($out);
		fclose($in);
		$count++;
	}
	$za->close();
	return $count > 0 ? "" : "No shapefile parts found in zip file.";
}

// Every .shp under a work folder.
function shapefileFindShp($dir){
	$found = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach($it as $file){
		if(strtolower($file->getExtension()) === "shp") $found[] = $file->getPathname();
	}
	sort($found);
	return $found;
}

function shapefileRememberWorkDir($randnum){
	$_SESSION['shapefile_workdirs'][] = (int)$randnum;
}

// The posted work folder number, if this session created it; otherwise 0.
function shapefileWorkDir($posted){
	$randnum = (int)$posted;
	if($randnum > 0 && in_array($randnum, $_SESSION['shapefile_workdirs'] ?? array(), true)){
		return $randnum;
	}
	return 0;
}

// A posted .shp path, if it is a .shp inside ziptemp/<randnum>/; otherwise "".
function shapefileCheckPath($path, $randnum){
	if(!is_string($path) || strtolower(substr($path, -4)) !== ".shp") return "";
	$real = realpath($path);
	$base = realpath("ziptemp/".(int)$randnum);
	if($real === false || $base === false || strpos($real, $base."/") !== 0) return "";
	return $path;
}

?>