<?php
/**
 * File: searchdownload.php
 * Description: Handles searchdownload operations
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

ini_set('max_execution_time', 1800);
include("logincheck.php");
include("prepare_connections.php");
session_write_close();   // a build can run for minutes: release the session lock so a second click, or any other page of the site, is not queued behind it (2026-09-04)

include("includes/straboClasses/straboOutputClass.php");

$getParams = $_GET;
if(!empty($_POST)){
	$getParams = array_merge($getParams, $_POST);
}

// Access check (2026-10-21, docs/Export_Access_Fix.md): the dsids / userpkey in the request are
// only a request. Keep the datasets this session may export and run as their owner, so a hand-made
// URL cannot reach another account's private data and collaborators can export shared datasets.
// scope_groups / all_dsids are Export Builder internals, never taken from a request.
unset($getParams['scope_groups'], $getParams['all_dsids'], $_GET['scope_groups'], $_GET['all_dsids'], $_POST['scope_groups'], $_POST['all_dsids']);
if(isset($getParams['dsids'])){
	$access = $strabo->exportDatasetParams($getParams['dsids']);
	foreach($access as $k => $v){
		$getParams[$k] = $v;
		if(isset($_GET[$k])) $_GET[$k] = $v;
		if(isset($_POST[$k])) $_POST[$k] = $v;
	}
}

$straboOut = new straboOutputClass($strabo,$getParams);

$type = $_GET['type'] ?? ($_POST['type'] ?? '');
$dsids = $getParams['dsids'] ?? '';

if($type=="shapefile"){
	$straboOut->shapefileOut();
}elseif($type=="kml"){
	$straboOut->kmlOut();
}elseif($type=="xls"){
	$straboOut->xlsOut();
}elseif($type=="stereonet"){
	$straboOut->stereonetOut();
}elseif($type=="fieldbook"){
	$straboOut->fieldbookOut();         // enhanced fieldbook (launched 2026-09-02, docs/Fieldbook_Design.md §11 step 5)
}elseif($type=="fieldbooklegacy"){
	$straboOut->legacyFieldbookOut();   // permanent, unlinked (design D8)
}elseif($type=="shapefiledev"){
	$straboOut->devshapefileOut();
}elseif($type=="stratsection"){
	header("Location: pdataset_strat_sections.php?dataset_ids=$dsids");
}elseif($type=="stereonetdev"){
	$straboOut->devstereonetOut();
}elseif($type=="xlsdev"){
	$straboOut->devxlsOut();
}elseif($type=="download_images"){
	$straboOut->downloadImages();
}elseif($type=="download_imagesdev"){
	$straboOut->downloadImagesdev();
}elseif($type=="debug"){
	$straboOut->debugOut();
}elseif($type=="devkml"){
	$straboOut->devkmlOut();
}elseif($type=="sample_list"){
	$straboOut->xlsSampleList();
}elseif($type=="dev_sample_list"){
	$straboOut->devxlsSampleList();
}elseif($type=="expandedShapefile"){
	$straboOut->expandedShapefileOut();
}elseif($type=="devexpandedShapefile"){
	$straboOut->devexpandedShapefileOut();
}elseif($type=="kml_dev"){
	$straboOut->devkmlOut();
}elseif($type=="doi"){
	$straboOut->doiPDFOut();
}elseif($type=="doidata"){
	$straboOut->doiDataOut();
}elseif($type=="gpkg"){
	$straboOut->gpkgOut();
}elseif($type=="geojson"){
	$straboOut->geoJSONOut();
}elseif($type=="devgeojson"){
	$straboOut->devgeoJSONOut();
}elseif($type=="hollytest"){
	$straboOut->hollyTest();
}elseif($type=="gems"){
	$straboOut->gemsOut();
}

?>