<?php
/**
 * File: load_geopackage.php
 * Description: Loads the feature layers of a GeoPackage (.gpkg) into StraboSpot datasets,
 *              one dataset per layer. Same steps as load_shapefile.php (shared steps in
 *              vector_import.php): upload, choose layers, map columns, import.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

set_time_limit(300);

include("logincheck.php");

$starttime=time();

include("shapefuncs.php");
include("gpkgfuncs.php");
include("gpkgroundtrip.php");
include("vector_import.php");
include("includes/datamodel.php");

include("prepare_connections.php");

function geopackageStopPage($message){
	include 'includes/mheader.php';
	echo "<div id=\"main\" class=\"wrapper style1\"><div class=\"container\"><header class=\"major\"><h2>Load GeoPackage</h2></header>";
	echo "<div style=\"color:#e44c65;\">".htmlspecialchars($message)."</div>";
	echo "<div style=\"padding-top:20px;\"><a href=\"load_geopackage\">Start again</a></div><div class=\"bottomSpacer\"></div></div></div>";
	include 'includes/mfooter.php';
	exit();
}

// <select name="projectid"> of the user's projects, $selected preselected
function geopackageProjectSelect($strabo, $selected){
	$projectrows = $strabo->getMyProjects()["projects"] ?? array();
	$html = "<select name=\"projectid\" id=\"projectid\"><option value=\"\">Select...</option>";
	foreach($projectrows as $pr){
		$html .= "<option value=\"".htmlspecialchars((string)$pr["id"], ENT_QUOTES)."\"".((string)$pr["id"] === (string)$selected ? " selected" : "").">".htmlspecialchars((string)$pr["name"])."</option>";
	}
	return $html."</select>";
}

// An upload loaded as new data without a project picked on the first page: ask for it, keep the upload
function geopackageProjectPage($strabo, $randnum, $gpkgname, $message = ""){
	include 'includes/mheader.php';
	echo "<div id=\"main\" class=\"wrapper style1\"><div class=\"container\"><header class=\"major\"><h2>Load GeoPackage</h2></header>";
	if($message !== "") echo "<div style=\"color:#e44c65;\">".htmlspecialchars($message)."</div><br>";
	echo "<form method=\"POST\"><h3>Project to load ".htmlspecialchars($gpkgname)." into:</h3>".geopackageProjectSelect($strabo, "");
	echo "<input type=\"hidden\" name=\"randnum\" value=\"".(int)$randnum."\"><input type=\"hidden\" name=\"gpkgname\" value=\"".htmlspecialchars($gpkgname, ENT_QUOTES)."\"><input type=\"hidden\" name=\"rtpath\" value=\"new\">";
	echo "<br><input class=\"primary\" type=\"submit\" name=\"rtsubmit\" value=\"Continue\"></form>";
	echo "<div style=\"padding-top:20px;\"><a href=\"load_geopackage\">Start again</a></div><div class=\"bottomSpacer\"></div></div></div>";
	include 'includes/mfooter.php';
	exit();
}

// The layer's features in WGS84 with safe column names: array(features, columns).
function geopackageLayerFeatures($randnum, $gpkgpath, $layer, $layerinfo){
	$options = geopackageReadOptions($layerinfo);
	if($options === ""){
		geopackageStopPage("Layer \"$layer\" has no coordinate system. Please assign one (for example EPSG:4326) and upload the file again.");
	}
	return geopackageSafeColumns(vectorImportReadFeatures($randnum, $gpkgpath, $layer, $options));
}

// Every step posts back the project, and after the upload the work folder and layer name(s):
// check them here so the steps below only see checked values. The upload is kept in
// ziptemp/<n>/upload.gpkg; the work folder helpers are the shapefile loader's.
$layers = array();
if(($_POST['projectid'] ?? "") !== ""){
	if(!preg_match('/^[0-9]+$/', $_POST['projectid']) || $strabo->straboIDToID($_POST['projectid'], "Project") == ""){
		geopackageStopPage("Project not found.");
	}
}
if(($_POST['randnum'] ?? "") !== ""){
	$randnum = shapefileWorkDir($_POST['randnum']);
	if(!$randnum){
		geopackageStopPage("This upload was not found. Please upload the file again.");
	}
	$gpkgpath = "ziptemp/$randnum/upload.gpkg";
	$layers = geopackageLayers($gpkgpath);
	if(!$layers){
		geopackageStopPage("This upload was not found. Please upload the file again.");
	}
	$postedlayers = array();
	foreach($_POST as $key=>$value){
		if($key === "layer" || preg_match('/^layer[0-9]+$/', $key)) $postedlayers[] = $value;
	}
	$layerqueue = json_decode($_POST['layerqueue'] ?? "[]", true);
	if(!is_array($layerqueue)) geopackageStopPage("Layer not found in this GeoPackage.");
	foreach(array_merge($postedlayers, $layerqueue) as $value){
		if(!is_string($value) || ($value !== "" && !isset($layers[$value]))){
			geopackageStopPage("Layer not found in this GeoPackage.");
		}
	}
}elseif(($_POST['layer'] ?? "") !== "" || ($_POST['layerqueue'] ?? "") !== "" || ($_POST['layer0'] ?? "") !== ""){
	geopackageStopPage("This upload was not found. Please upload the file again.");
}
$gpkgname = preg_replace('/[^A-Za-z0-9_.\-() ]/', '_', basename((string)($_POST['gpkgname'] ?? "")));
$spotnameprefix = $_POST['spotnameprefix'] ?? "";
$projectid = $_POST['projectid'] ?? "";

$error="";
$errordelim="";

if(($_POST['submitfile'] ?? "") != ""){

	$filename = $_FILES['gpkgfile']['name'] ?? "";
	$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

	// The project is asked after the upload (only needed to load the file as new data)
	if(($_FILES['gpkgfile']['error'] ?? 1) != 0){
		$error.=$errordelim."File upload failed.";$errordelim="<br>";
	}elseif($extension != "gpkg" && $extension != "zip"){
		$error.=$errordelim."Wrong file type detected. File must be a GeoPackage (.gpkg) or a zip file holding one.";$errordelim="<br>";
	}elseif($extension == "gpkg" && $_FILES['gpkgfile']['size'] > GEOPACKAGE_MAX_BYTES){
		$error.=$errordelim."GeoPackage is too large. (".number_format($_FILES['gpkgfile']['size'])." bytes)";$errordelim="<br>";
	}

	if($error==""){

		$randnum=(int)$db->get_var("select nextval('file_seq')");
		mkdir("ziptemp/$randnum");
		shapefileRememberWorkDir($randnum);
		$gpkgpath = "ziptemp/$randnum/upload.gpkg";

		if($extension == "zip"){
			$ziperror = geopackageExtractZip($_FILES['gpkgfile']['tmp_name'], $gpkgpath);
			if($ziperror != ""){
				$error.=$errordelim.$ziperror;$errordelim="<br>";
			}
		}else{
			move_uploaded_file($_FILES['gpkgfile']['tmp_name'], $gpkgpath);
		}
	}

	if($error==""){

		$gpkgname = preg_replace('/[^A-Za-z0-9_.\-() ]/', '_', basename($filename));
		$layers = geopackageLayers($gpkgpath);

		if($layers === false){
			$error.=$errordelim."This file could not be read as a GeoPackage.";$errordelim="<br>";
		}elseif(count($layers) == 0){
			$error.=$errordelim."No feature layers found in this GeoPackage.";$errordelim="<br>";
		}
	}

	if($error==""){
		// A StraboSpot GeoPackage export: update the original project or load as new data (round trip, design 5)
		$rtexport = gpkgRoundTripManifest($gpkgpath);
		if($rtexport !== null){
			$target = gpkgRoundTripTarget($neodb, $db, $userpkey, $rtexport);
			include 'includes/mheader.php';
			gpkgRoundTripChoicePage($target, geopackageProjectSelect($strabo, $projectid), array('randnum' => $randnum, 'gpkgname' => $gpkgname));
			include 'includes/mfooter.php';
			exit();
		}
		if($projectid == "") geopackageProjectPage($strabo, $randnum, $gpkgname);
		$startlayers = true;
	}

}//end if submit

// After the round trip choice page: the update preview, or today's flow from the layer list
if(($_POST['rtsubmit'] ?? "") != "" && $layers){
	if(($_POST['rtpath'] ?? "") == "update"){
		$rtexport = gpkgRoundTripManifest($gpkgpath);
		$target = $rtexport !== null ? gpkgRoundTripTarget($neodb, $db, $userpkey, $rtexport) : null;
		if($target === null || !$target['ok']){
			geopackageStopPage($target === null ? "This GeoPackage has no StraboSpot export data." : $target['reason']);
		}
		$result = gpkgRoundTripClassify($neodb, $db, $randnum, $gpkgpath, $layers, $target);
		include 'includes/mheader.php';
		gpkgRoundTripPreviewPage($target, $result, array('randnum' => $randnum, 'gpkgname' => $gpkgname) + ($projectid !== "" ? array('projectid' => $projectid) : array()));
		include 'includes/mfooter.php';
		exit();
	}
	if($projectid == "") geopackageProjectPage($strabo, $randnum, $gpkgname, ($_POST['rtpath'] ?? "") == "new" && isset($_POST['projectid']) ? "Project must be selected." : "");
	$startlayers = true;
}

if($startlayers ?? false){

	if(count($layers) > 1){

		// Several layers: choose which ones to load (one dataset each)
		include 'includes/mheader.php';
		?>

		<script type="text/javascript">

			function formvalidate(){
				var go='no';

				for (var i = 0; i < document.layerform.elements.length; i++ ) {
					if (document.layerform.elements[i].type == 'checkbox') {
						if (document.layerform.elements[i].checked == true) {
							go='yes';
						}
					}
				}

				if(go=='no'){
					alert("You must check at least one layer.");
					return false;
				}else{
					return true;
				}
			}

		</script>

		<!-- Main -->
			<div id="main" class="wrapper style1">
				<div class="container">

					<header class="major">
						<h2>Select Layer(s)</h2>
					</header>

		The following layers were found in <?php echo htmlspecialchars($gpkgname)?>.<br>
		Each layer you choose is loaded into its own dataset:<br><br>

		<form name="layerform" method="POST" onsubmit="return formvalidate();">

		<table>
			<thead>
				<tr><th></th><th>Layer</th><th>Geometry</th><th>Features</th><th>Coordinate System</th></tr>
			</thead>
		<?php
		$ln=0;
		foreach($layers as $name=>$info){
		?>
			<tr>
				<td><input type="checkbox" id="layer<?php echo $ln?>" name="layer<?php echo $ln?>" value="<?php echo htmlspecialchars($name, ENT_QUOTES)?>" <?php echo $info['count'] > 0 ? 'checked="true"' : ''?>><label for="layer<?php echo $ln?>"></label></td>
				<td><?php echo htmlspecialchars($name)?></td>
				<td><?php echo htmlspecialchars((string)$info['geometrytype'])?></td>
				<td><?php echo number_format($info['count'])?></td>
				<td><?php echo $info['srs'] != "" ? htmlspecialchars($info['srs']) : ($info['srsid'] == -1 ? "none" : "undefined (taken as WGS84)")?></td>
			</tr>
		<?php
			$ln++;
		}
		?>
		</table><br>

		<input type="hidden" name="randnum" value="<?php echo $randnum?>">
		<input type="hidden" name="projectid" value="<?php echo htmlspecialchars($projectid, ENT_QUOTES)?>">
		<input type="hidden" name="gpkgname" value="<?php echo htmlspecialchars($gpkgname, ENT_QUOTES)?>">

		<input class="primary" type="submit" name="indsubmit" value="Submit">

		</form>

				<div class="bottomSpacer"></div>

				</div>
			</div>

		<?php
		include 'includes/mfooter.php';
		exit();

	}else{
		$putinone="yes";
		$_POST['layer'] = array_key_first($layers);
	}
}

//check columns here for errors

$vocaberror = "";

if(($_POST['columnsubmit'] ?? "") != ""){

	include_once("includes/straboClasses/straboModelClass.php");

	$smodel = new straboModelClass();
	$smodel->setUserColumns($_POST['fixedcolumns'] ?? "");

	$layer = $_POST['layer'];
	list($features, $columns) = geopackageLayerFeatures($randnum, $gpkgpath, $layer, $layers[$layer]);
	$vocaberror = vectorImportVocabError($smodel, $features);

}

if(($_POST['indsubmit'] ?? "") != "" || ($putinone ?? "") == "yes" || ($vocaberror != "" && ($_POST['columnsubmit'] ?? "") != "")){

	// Layer to map now: the posted one (single layer, or a vocabulary error), else the
	// first checked layer or the first of the queue; the rest wait in the queue.
	$layerqueue = json_decode($_POST['layerqueue'] ?? "[]", true);
	$checked = array();
	foreach($_POST as $key=>$value){
		if(preg_match('/^layer[0-9]+$/', $key) && $value !== "") $checked[] = $value;
	}
	if(($_POST['layer'] ?? "") !== ""){
		$layer = $_POST['layer'];
	}elseif(count($checked) > 0){
		$layer = array_shift($checked);
		$layerqueue = $checked;
	}else{
		$layer = array_shift($layerqueue);
	}
	if($layer === null || !isset($layers[$layer])){
		geopackageStopPage("Layer not found in this GeoPackage.");
	}

	list($features, $columns) = geopackageLayerFeatures($randnum, $gpkgpath, $layer, $layers[$layer]);

	if(count($features) == 0){
		geopackageStopPage("Layer \"$layer\" has no features to load.");
	}

	include_once("includes/straboClasses/straboModelClass.php");

	include 'includes/mheader.php';

	vectorImportColumnsPage($smodel ?? null, $columns, array(
		'kind' => "GeoPackage",
		'showname' => "$gpkgname: $layer",
		'datasetname' => $layer,
		'spotnameprefix' => $spotnameprefix,
		'vocaberror' => $vocaberror,
		'hidden' => array(
			'randnum' => $randnum,
			'projectid' => $projectid,
			'gpkgname' => $gpkgname,
			'layer' => $layer,
			'layerqueue' => json_encode(array_values($layerqueue)),
		),
	));

	include 'includes/mfooter.php';

	exit();
}

if(($_POST['columnsubmit'] ?? "") != ""){

	$layerqueue = json_decode($_POST['layerqueue'] ?? "[]", true);

	// Form for the next chosen layer
	$newhtml = "";
	if(count($layerqueue) > 0){
		$newhtml="<form method=\"POST\"><input type=\"hidden\" name=\"randnum\" value=\"".(int)$randnum."\"><input type=\"hidden\" name=\"projectid\" value=\"".htmlspecialchars($projectid, ENT_QUOTES)."\"><input type=\"hidden\" name=\"gpkgname\" value=\"".htmlspecialchars($gpkgname, ENT_QUOTES)."\"><input type=\"hidden\" name=\"layerqueue\" value=\"".htmlspecialchars(json_encode(array_values($layerqueue)), ENT_QUOTES)."\"><input type=\"hidden\" name=\"spotnameprefix\" value=\"".htmlspecialchars($spotnameprefix, ENT_QUOTES)."\"><input type=\"submit\" name=\"indsubmit\" value=\"Continue to load ".htmlspecialchars($layerqueue[0], ENT_QUOTES)."\"></form>";
	}

	include 'includes/mheader.php';

	vectorImportRun($smodel, array(
		'features' => $features,
		'epsg' => 4326,
		'projectid' => $projectid,
		'randnum' => $randnum,
		'datasetname' => $_POST['datasetname'] ?? $layer,
		'spotnameprefix' => $spotnameprefix,
		'fixedcolumns' => $_POST['fixedcolumns'] ?? "",
		'datasetprops' => array('geopackage' => $gpkgname, 'layer' => $layer, 'datasettype' => "geopackage"),
		'loadingtitle' => "Loading GeoPackage...",
		'donetext' => "GeoPackage Load Complete.",
		'continuehtml' => str_replace("'", "&#039;", $newhtml),
	));

	include 'includes/mfooter.php';

	exit();
}

if($error!=""){
	$error = "<div style=\"color:#e44c65;\">$error</div><br>";
}

include 'includes/mheader.php';

?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>GeoPackage Upload</h2>
						</header>
<?php

//check for projects here
$projectrows = $strabo->getMyProjects();
$projectrows = $projectrows["projects"];

if(empty($projectrows)){
	?>
	No Projects found. You must create a project in which to store your GeoPackage. Click <a href="new_project">here</a> to add project.
	<?php
	exit();
}

?>

	<script type="text/javascript">

	function formvalidate(){
		var errors='';

		if(document.forms["uploadform"]["gpkgfile"].value=="" || document.forms["uploadform"]["gpkgfile"].value==null){errors=errors+'GeoPackage must be provided.\n';}

		if(errors!="" && errors!=null){
			alert(errors);
			return false;
		}
	}

	function doClickFile() {

		let FC = document.getElementById("gpkgfile");
		FC.click();

	}

	function changeFileName() {
		let fullPath = document.getElementById("gpkgfile").value;
		var filename = fullPath.replace(/^.*[\\/]/, '')
		document.getElementById("filename").value = filename;
	}

	</script>

	<?php echo $error?>

	<form name="uploadform" method="POST" onsubmit="return formvalidate();" enctype="multipart/form-data">

<div class="row gtr-uniform gtr-50">
	<div class="col-12">
		<h3>GeoPackage (.gpkg, or a .zip holding one):</h3>
	</div>
	<div class="col-12">
		<input type="text" id="filename" placeholder="Choose File..." onclick="doClickFile();" readonly>
	</div>
	<div class="col-12">
		Each feature layer is loaded into its own dataset; you choose the project after the upload. A GeoPackage downloaded
		from StraboSpot can instead update its original project. Any coordinate system is accepted; locations are stored in WGS84.
	</div>
	<div class="col-12">
		<ul class="actions">
			<li><input class="primary" type="submit" name="submitfile" value="Submit"></li>
			<li><input class="primary" type="reset" value="Reset"></li>
		</ul>
	</div>

</div>

		<input type="file" id="gpkgfile" name="gpkgfile" accept=".gpkg,.zip" style="display:none;" onchange="changeFileName();">

	</form>
					<div class="bottomSpacer"></div>

					</div>
				</div>
<?php
include 'includes/mfooter.php';
?>
