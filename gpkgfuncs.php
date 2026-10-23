<?php
/**
 * File: gpkgfuncs.php
 * Description: GeoPackage helpers for load_geopackage.php. PHP has no SQLite driver in the
 *              strabo-php image, so the GeoPackage tables are read through GDAL (ogr2ogr).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Largest GeoPackage accepted (uncompressed); the import runs in one request.
define('GEOPACKAGE_MAX_BYTES', 50000000);

// Rows of an SQL query against a GeoPackage, as associative arrays (false if it fails).
function geopackageQuery($path, $sql){
	exec("ogr2ogr -f CSV /vsistdout/ ".escapeshellarg($path)." -sql ".escapeshellarg($sql)." 2>/dev/null", $lines, $rc);
	if($rc != 0 || count($lines) == 0) return false;
	// GDAL 3.2 ends a one-column header with a comma ("n,"): drop empty trailing header
	// fields and fit each row to the header
	$header = str_getcsv(array_shift($lines));
	while(count($header) > 0 && end($header) === "") array_pop($header);
	$rows = array();
	foreach($lines as $line){
		if($line === "") continue;
		$row = array_slice(array_pad(str_getcsv($line), count($header), ""), 0, count($header));
		$rows[] = array_combine($header, $row);
	}
	return $rows;
}

// True if the file is an SQLite database (every GeoPackage is one).
function geopackageIsSqlite($path){
	$fh = @fopen($path, "rb");
	if(!$fh) return false;
	$magic = fread($fh, 16);
	fclose($fh);
	return $magic === "SQLite format 3\0";
}

/*
	Feature layers of a GeoPackage: name => geometrytype, srsid, srs ("EPSG:32613"), count.
	Attribute-only tables and raster tiles are left out. false if the file cannot be read.
*/
function geopackageLayers($path){
	if(!geopackageIsSqlite($path)) return false;
	$rows = geopackageQuery($path, "SELECT c.table_name AS name, g.geometry_type_name AS geometrytype, c.srs_id AS srsid, s.organization AS org, s.organization_coordsys_id AS code FROM gpkg_contents c LEFT JOIN gpkg_geometry_columns g ON g.table_name = c.table_name LEFT JOIN gpkg_spatial_ref_sys s ON s.srs_id = c.srs_id WHERE c.data_type = 'features' ORDER BY c.table_name");
	if($rows === false) return false;
	$layers = array();
	foreach($rows as $row){
		$name = $row['name'];
		$count = geopackageQuery($path, "SELECT COUNT(*) AS n FROM \"".str_replace('"', '""', $name)."\"");
		$layers[$name] = array(
			'geometrytype' => $row['geometrytype'],
			'srsid' => (int)$row['srsid'],
			'srs' => ($row['org'] != "" && strtoupper($row['org']) != "NONE") ? strtoupper($row['org']).":".$row['code'] : "",
			'count' => $count ? (int)$count[0]['n'] : 0,
		);
	}
	return $layers;
}

/*
	ogr2ogr options that bring a layer to WGS84 lon/lat without Z/M, or "" if the layer has
	no coordinate system to transform from (srs_id -1 = undefined Cartesian).
	srs_id 0 is "undefined geographic" in the GeoPackage spec: taken as WGS84.
*/
function geopackageReadOptions($layerinfo){
	if($layerinfo['srsid'] == -1) return "";
	$options = array("-t_srs", "EPSG:4326", "-dim", "XY");
	if($layerinfo['srsid'] == 0) $options = array_merge(array("-s_srs", "EPSG:4326"), $options);
	return $options;
}

/*
	Column names go into the mapping form and into "col:strabocol;..." strings, so characters
	other than letters, digits, space, _ . ( ) - become "_" (renamed in the features too).
	Returns array(features, columns) with columns in first-seen order.
*/
function geopackageSafeColumns($features){
	$map = array();
	$columns = array();
	foreach($features as $f){
		foreach(array_keys($f['properties'] ?? array()) as $key){
			if(isset($map[$key])) continue;
			$safe = trim(preg_replace('/[^A-Za-z0-9_ .()\-]/', '_', (string)$key));
			if($safe === "") $safe = "column";
			$base = $safe;
			$n = 2;
			while(in_array($safe, $columns, true)){
				$safe = $base."_".$n;
				$n++;
			}
			$map[$key] = $safe;
			$columns[] = $safe;
		}
	}
	foreach($features as $i=>$f){
		$props = array();
		foreach(($f['properties'] ?? array()) as $key=>$value){
			$props[$map[$key]] = $value;
		}
		$features[$i]['properties'] = $props;
	}
	return array($features, $columns);
}

// Extract the one .gpkg in a zip to $target. Returns "" or an error message.
function geopackageExtractZip($zipPath, $target){
	$za = new ZipArchive();
	if($za->open($zipPath) !== true) return "Zip file could not be read.";
	$found = array();
	for($i = 0; $i < $za->numFiles; $i++){
		$name = $za->getNameIndex($i);
		if($name === false || substr($name, -1) === "/" || preg_match('#(^|/)__MACOSX/#', $name)) continue;
		if(strtolower(pathinfo($name, PATHINFO_EXTENSION)) === "gpkg") $found[] = $i;
	}
	if(count($found) != 1){
		$za->close();
		return count($found) == 0 ? "No GeoPackage (.gpkg) found in zip file." : "Zip file holds more than one GeoPackage. Please upload one at a time.";
	}
	$stat = $za->statIndex($found[0]);
	if($stat['size'] > GEOPACKAGE_MAX_BYTES){
		$za->close();
		return "GeoPackage is too large. (".number_format($stat['size'])." bytes)";
	}
	$in = $za->getStream($za->getNameIndex($found[0]));
	$out = fopen($target, "wb");
	stream_copy_to_stream($in, $out);
	fclose($out);
	fclose($in);
	$za->close();
	return "";
}

?>
