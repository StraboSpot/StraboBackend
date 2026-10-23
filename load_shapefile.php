<?php
/**
 * File: load_shapefile.php
 * Description: Loads and imports shapefile geographic data
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

//14878844758700
//1487884568
// id is unix time + 4 rand digits

set_time_limit(300);

include("logincheck.php");

$starttime=time();

include("shapefuncs.php");
include("vector_import.php");
include("logincheck.php");
include("includes/datamodel.php");

include("prepare_connections.php");

function shapefileStopPage($message){
	include 'includes/mheader.php';
	echo "<div id=\"main\" class=\"wrapper style1\"><div class=\"container\"><header class=\"major\"><h2>Load Shapefile</h2></header>";
	echo "<div style=\"color:#e44c65;\">".htmlspecialchars($message)."</div>";
	echo "<div style=\"padding-top:20px;\"><a href=\"load_shapefile\">Start again</a></div><div class=\"bottomSpacer\"></div></div></div>";
	include 'includes/mfooter.php';
	exit();
}

// Every step posts back the project, and after the upload the work folder and the
// .shp path(s): check them here so the steps below only see checked values.
if(($_POST['projectid'] ?? "") !== ""){
	if(!preg_match('/^[0-9]+$/', $_POST['projectid']) || $strabo->straboIDToID($_POST['projectid'], "Project") == ""){
		shapefileStopPage("Project not found.");
	}
}
if(($_POST['randnum'] ?? "") !== ""){
	$checkedrandnum = shapefileWorkDir($_POST['randnum']);
	if(!$checkedrandnum){
		shapefileStopPage("This upload was not found. Please upload the file again.");
	}
	$_POST['randnum'] = (string)$checkedrandnum;
	foreach($_POST as $key=>$value){
		if($key === "shapefilename" || preg_match('/^shapefilename[0-9]+$/', $key)){
			if($value !== "" && shapefileCheckPath($value, $checkedrandnum) === ""){
				shapefileStopPage("Shapefile not found in this upload.");
			}
		}
	}
	if(($_POST['shapefilearray'] ?? "") !== ""){
		foreach(explode("***", $_POST['shapefilearray']) as $value){
			if($value !== "" && shapefileCheckPath($value, $checkedrandnum) === ""){
				shapefileStopPage("Shapefile not found in this upload.");
			}
		}
	}
}elseif(($_POST['shapefilename'] ?? "") !== "" || ($_POST['shapefilearray'] ?? "") !== "" || ($_POST['shapefilename0'] ?? "") !== ""){
	shapefileStopPage("This upload was not found. Please upload the file again.");
}
if(isset($_POST['epsg'])){
	$_POST['epsg'] = preg_match('/^[0-9]+$/', $_POST['epsg']) ? $_POST['epsg'] : "";
}

function getid(){
	$string = time();
	$newtime = $string.rand(1111,9999);
	return (int)$newtime;
}

function validateplanartype($intype){

	$featuretypes=array("bedding","contact","foliation","fracture","vein","fault","shear_zone_boundary");

	if(in_array($intype,$featuretypes)){
		return true;
	}else{
		return false;
	}
}

function validatelineartype($intype){

	$featuretypes=array("groove_marks","parting_lineat","magmatic_miner_1","xenolith_encla","intersection","pencil_cleav","mineral_align","deformed_marke","rodding","boudin","mullions","fold_hinge","striations","slickenlines","slickenfibers","mineral_streak","vorticity_axis","flow_transport","vergence","vector");

	if(in_array($intype,$featuretypes)){
		return true;
	}else{
		return false;
	}
}

function dumpVar($var){
	echo "<pre>";
	print_r($var);
	echo "</pre>";
}

if($_POST['submitfile']!="" || $_POST['submitarc']!=""){

	//file has been submitted. Check it here and save it for
	//processing shapefiles

	if($error==""){

		if($_POST['submitfile']!=""){
			//should check from filename because 'type' can vary

			$pos = strpos($_FILES['zipfile']['name'],".zip");

			if($pos === false) {
				$error.=$errordelim."Wrong file type detected. File must be zip archive.";$errordelim="<br>";
			}
		}elseif($_POST['arcid']!=""){

			$arcid = $_POST['arcid'] ?? '';
			$arcid = preg_replace('/[^a-zA-Z0-9\-]/', '', $arcid);
			$frow = $db->get_row_prepared("SELECT * FROM arcfiles WHERE arcid=$1", array($arcid));

			$pos = strpos($frow->name,".zip");

			if($pos === false) {

				include 'includes/mheader.php';

				echo "<h2>Load Shapefile</h2><br>";

				echo "Wrong file type detected. File must be zip archive.";

				include 'includes/mfooter.php';
				exit();

			}

		}
	}

	if($error==""){
		//unzip file here

		$projectid=$_POST['projectid'];

		$randnum=(int)$db->get_var("select nextval('file_seq')");

		if($_POST['submitfile']!=""){
			$tempname=$_FILES['zipfile']['tmp_name'];
		}elseif($_POST['arcid']!=""){
			$arcid = $_POST['arcid'];

			if($arcid==""){echo "arcid not found.";exit();}
			$tempname = "arcfiles/".(int)$frow->pkey;
		}

		mkdir("ziptemp/$randnum");
		shapefileRememberWorkDir($randnum);

		// The zip itself stays outside the served work folder; only shapefile parts are extracted into it.
		$newfilename = $tempname;

	}

	if($error==""){

		$size = shapefileZipSize($newfilename);

		if($size === false){

			$error.=$errordelim."Zip file could not be read.";$errordelim="<br>";

		}elseif($size > 20000000){

			$error.=$errordelim."Zip file is too large. (".number_format($size)." bytes uncompressed)";$errordelim="<br>";

		}
	}

	if($error==""){

		//unzip shapefile parts only
		$ziperror = shapefileExtractZip($newfilename, "ziptemp/$randnum");
		if($ziperror != ""){
			$error.=$errordelim.$ziperror;$errordelim="<br>";
		}

	}

	if($error==""){

		$shapefiles = shapefileFindShp("ziptemp/$randnum");

		if(count($shapefiles)==0){

			$error.=$errordelim."No shapefiles (.shp) found in zip file.";$errordelim="<br>";

		}
	}

	if($error==""){

		$dbs = glob("ziptemp/$randnum/{,*/,*/*/,*/*/*/}*.[dD][bB][fF]", GLOB_BRACE);

		if(count($dbs)==0){

			$error.=$errordelim."No DBF (.dbf) files found in zip file.";$errordelim="<br>";

		}
	}

	if($error==""){

		$prjs = glob("ziptemp/$randnum/{,*/,*/*/,*/*/*/}*.[pP][rR][jJ]", GLOB_BRACE);

		if(count($prjs)==0){

			$error.=$errordelim."No Projection (.prj) files found in zip file.";$errordelim="<br>";

		}
	}

	if($error==""){

		// File is OK. If there is one .shp file, just move forward and put it in.
		// If there is more than one .shp file, display list of files for submission.

		if(count($shapefiles)>1){
			//show list here

			include 'includes/mheader.php';

			//verify form here.
			?>

			<script type="text/javascript">

				function formvalidate(){
					var go='no';

					for (var i = 0; i < document.shapeform.elements.length; i++ ) {
						if (document.shapeform.elements[i].type == 'checkbox') {
							if (document.shapeform.elements[i].checked == true) {
								go='yes';
							}
						}
					}

					if(go=='no'){
						alert("You must check at least one shapefile.");
						return false;
					}else{
						return true;
					}
				}

			</script>

			<?php

			echo "<h2>Select Shapefile(s)</h2><br>";

			echo "The following shapefile(s) where found in your zip file.<br>";
			echo "Please choose the file(s) you would like to add to your project:<br><br>";

			echo "<form name=\"shapeform\" style=\"display:inline; margin:0px; padding:0px;\" method=\"POST\" onsubmit=\"return formvalidate();\">";

			echo "<table>";

				$sn=0;
				foreach($shapefiles as $sf){

					$showname = basename($sf);

					?>
					<tr>
					<td><input type="checkbox" name="shapefilename<?php echo $sn?>" value="<?php echo $sf?>" checked="true"></td>
					<td><?php echo $showname?></td>
					</tr>

					<?php

					$sn++;
				}

			?>
			</table><br>

			<input type="hidden" name="randnum" value="<?php echo htmlspecialchars((string)$randnum, ENT_QUOTES)?>">
			<input type="hidden" name="projectid" value="<?php echo htmlspecialchars((string)$projectid, ENT_QUOTES)?>">
			<input type="hidden" name="arcid" value="<?php echo htmlspecialchars((string)$arcid, ENT_QUOTES)?>">

			<input class="primary" type="submit" name="indsubmit" value="Submit">

			</form>

			<?php

			include 'includes/mfooter.php';
			exit();
		}else{
			$putinone="yes";
		}

	}

}//end if submit

//check columns here for errors

if($_POST['columnsubmit']!=""){

	include_once("includes/straboClasses/straboModelClass.php");

	$smodel = new straboModelClass();

	$datasetname=$_POST['datasetname'];
	$spotnameprefix=$_POST['spotnameprefix'];
	$shapefilename=$_POST['shapefilename'];
	$randnum=$_POST['randnum'];
	$projectid=$_POST['projectid'];
	$arcid=$_POST['arcid'];
	$featuretype=$_POST['featuretype'];
	$filecolumns=$_POST['filecolumns'];
	$epsg=$_POST['epsg'];
	$fixedcolumns=$_POST['fixedcolumns'];
	$shapefilearray=$_POST['shapefilearray'];

	$smodel->setUserColumns($fixedcolumns);

	$vocaberror = vectorImportVocabError($smodel, vectorImportReadFeatures($randnum, $shapefilename));

	

}

if($_POST['indsubmit']!="" || $putinone=="yes" || ($vocaberror!="" && $_POST['columnsubmit']!="")){

	//OK, this is either an individual file, or a filename has been posted.

	//need to look for lowest shapefile and keep the rest in an array

	$fromlist = false;
	for($x=1;$x<100;$x++){
		if($_POST['shapefilename'.$x]!=""){
			$fromlist=true;
		}
	}

	$firstoneset=false;

	if($fromlist){
		//create array and pull off first value

		for($x=0;$x<100;$x++){

			if(!$firstoneset){
				if($_POST['shapefilename'.$x]!=""){
					$shapefilename=$_POST['shapefilename'.$x];
					$firstoneset=true;
				}
			}else{

				if($_POST['shapefilename'.$x]!=""){
					$shapefilearray.=$sfdelim.$_POST['shapefilename'.$x];
					$sfdelim="***";
				}

			}
		}

	}elseif($_POST['shapefilearray']!="" && !$vocaberror){

		if($vocaberroro){
			$_POST['shapefilearray']="$shapefilename"."***".$_POST['shapefilearray'];
		}

		$sfarray=explode("***",$_POST['shapefilearray']);
		$shapefilename=$sfarray[0];
		for($x=1;$x<100;$x++){
			if($sfarray[$x]!=""){
				$shapefilearray.=$sfdelim.$sfarray[$x];
				$sfdelim="***";
			}
		}
	}else{
		if($shapefilename==""){
			$shapefilename=$shapefiles[0];
		}
	}

	if($_POST['randnum']!=""){
		$randnum=$_POST['randnum'];
	}

	if($_POST['projectid']!=""){
		$projectid=$_POST['projectid'];
	}

	if($_POST['arcid']!=""){
		$arcid=$_POST['arcid'];
	}

	if($_POST['shapefilename']!=""){
		$arcid=$_POST['shapefilename'];
	}

	//let's try to get the projection here

	//first, find the .prj file
	$prjs=array();

	$prjfilename = str_replace(".shp",".prj",$shapefilename);

	if(file_exists($prjfilename)){

		//now we have a custom python script instead of calling a web service
		exec("/srv/app/www/prj2epsg.py ".escapeshellarg($prjfilename),$epsg);
		$epsg=$epsg[0];

		exec("/srv/app/www/prj2wkt.py ".escapeshellarg($prjfilename), $wkt);
		$wkt = $wkt[0];

/*
Bad:
epsg: None
wkt: GEOGCS["GCS_GDA_1994",DATUM["Geocentric_Datum_of_Australia_1994",SPHEROID["GRS_1980",6378137.0,298.257222101]],PRIMEM["Greenwich",0.0],UNIT["Degree",0.0174532925199433]]

Good:
epsg: 4326
wkt: GEOGCS["GCS_WGS_1984",DATUM["WGS_1984",SPHEROID["WGS_84",6378137.0,298.257223563]],PRIMEM["Greenwich",0.0],UNIT["Degree",0.0174532925199433]]

*/
		if($epsg=="None"){

			//OK, do another check, but this time from postgres
			exec("/srv/app/www/prj2wkt.py ".escapeshellarg($prjfilename), $wkt);
			$wkt = $wkt[0];

			$newepsg = $db->get_var_prepared("SELECT srid FROM spatial_ref_sys WHERE srtext LIKE $1 LIMIT 1", array('%'.$wkt.'%'));

			if($newepsg == ""){
				//error here
				include 'includes/mheader.php';
				?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Unknown Shapefile Projection</h2>
						</header>

				<div style="color:red;">Error!</div>

				<div style="padding-top:10px;">
				Your shapefile appears to have an unknown shapefile projection. Here is the projection found by the StraboSpot Server:
				</div>

				<div style="color:#a22330;padding-top:20px;">
				<?php echo $wkt?>
				</div>

				<div style="padding-top:20px;">
				Please try re-projecting your shapefile to a more common SRS, such as EPSG:4326.
				</div>

				<div style="padding-top:20px;">
				Regards,
				</div>

				<div style="padding-top:20px;">
				The StraboSpot Team
				</div>

					<div class="bottomSpacer"></div>

					</div>
				</div>
				<?php

				include 'includes/mfooter.php';

				

				exit();
			}else{
				$epsg = $newepsg;
			}
		}

		

	}else{

		$error.=$errordelim."Projection file (".basename($prjfilename).") not found in zip file.";$errordelim="<br>";

	}

	if($error==""){

		/*
		Let's try to figure out what kind of file this is :
		Attitudes	:	point
		Rocks		:	point or poly
		Contact		:	line
		Folds		:	line
		Notes		:	point
		*/

		///****************************************************************************************************************
		///****************************************************************************************************************

		require_once('ShapeFile.inc.php');
		try {
			$shp = new ShapeFile($shapefilename, array('noparts' => false));

			$shapetype=$shp->shp_type;

			while ($record = $shp->getNext()) {

				$dataarray = $record->getDbfData();
				break;

			}

		} catch (ShapeFileException $e) {
			exit('Error '.$e->getCode().': '.$e->getMessage());
		}

		$filecolumns=array();
		foreach($dataarray as $key=>$value){
			//if(strtolower($key)!="objectid" && strtolower($key)!="shape_leng" && strtolower($key)!="shape_area" && strtolower($key)!="souerce" && strtolower($key)!="labelout" && strtolower($key)!="deleted"){
			if(strtolower($key)!="souerce" && strtolower($key)!="labelout" && strtolower($key)!="deleted"){
				$filecolumns[]=$key;
			}
		}

		///****************************************************************************************************************
		///****************************************************************************************************************

		include 'includes/mheader.php';

		vectorImportColumnsPage($smodel ?? null, $filecolumns, array(
			'kind' => "Shapefile",
			'showname' => basename($shapefilename),
			'datasetname' => basename($shapefilename),
			'spotnameprefix' => $spotnameprefix,
			'vocaberror' => $vocaberror,
			'hidden' => array(
				'shapefilename' => $shapefilename,
				'randnum' => $randnum,
				'projectid' => $projectid,
				'arcid' => $arcid,
				'featuretype' => $featuretype,
				'filecolumns' => "",
				'epsg' => $epsg,
				'shapefilearray' => $shapefilearray,
			),
		));

		include 'includes/mfooter.php';

		exit();

	}else{

		//show error here
		include 'includes/mheader.php';

		?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Error</h2>
						</header>

		<div style="color:red;"><?php echo $error?></div><br>

		Please try your upload again.

					<div class="bottomSpacer"></div>

					</div>
				</div>
		<?php

		include 'includes/mfooter.php';
		exit();

	}

}

if($_POST['columnsubmit']!=""){

	include_once("includes/straboClasses/straboModelClass.php");

	if(!$smodel){
		$smodel = new straboModelClass();
	}

	$shapefilename=$_POST['shapefilename'];
	$shapefilearray=$_POST['shapefilearray'];
	$randnum=$_POST['randnum'];
	$projectid=$_POST['projectid'];
	$arcid=$_POST['arcid'];
	$featuretype=$_POST['featuretype'];
	$filecolumns=$_POST['filecolumns'];
	$fixedcolumns=$_POST['fixedcolumns'];
	$epsg=$_POST['epsg'];
	$userpkey=(int)$_SESSION['userpkey'];
	$datasetname = $_POST['datasetname'];
	$spotnameprefix = $_POST['spotnameprefix'];

	// Form for the next shapefile of a multi-file zip
	$newhtml = "";
	if($shapefilearray!=""){

		$sfarray=explode("***",$shapefilearray);
		$sfname=$sfarray[0];

		$showname = basename($sfname);

		$newhtml="<form method=\"POST\"><input type=\"hidden\" name=\"shapefilearray\" value=\"".htmlspecialchars((string)$shapefilearray, ENT_QUOTES)."\"><input type=\"hidden\" name=\"randnum\" value=\"".htmlspecialchars((string)$randnum, ENT_QUOTES)."\"><input type=\"hidden\" name=\"projectid\" value=\"".htmlspecialchars((string)$projectid, ENT_QUOTES)."\"><input type=\"hidden\" name=\"arcid\" value=\"".htmlspecialchars((string)$arcid, ENT_QUOTES)."\"><input type=\"hidden\" name=\"featuretype\" value=\"".htmlspecialchars((string)$featuretype, ENT_QUOTES)."\"><input type=\"hidden\" name=\"filecolumns\" value=\"".htmlspecialchars((string)$filecolumns, ENT_QUOTES)."\"><input type=\"hidden\" name=\"fixedcolumns\" value=\"".htmlspecialchars((string)$fixedcolumns, ENT_QUOTES)."\"><input type=\"hidden\" name=\"epsg\" value=\"".htmlspecialchars((string)$epsg, ENT_QUOTES)."\"><input type=\"hidden\" name=\"userpkey\" value=\"".htmlspecialchars((string)$userpkey, ENT_QUOTES)."\"><input type=\"hidden\" name=\"datasetname\" value=\"".htmlspecialchars((string)$datasetname, ENT_QUOTES)."\"><input type=\"hidden\" name=\"spotnameprefix\" value=\"".htmlspecialchars((string)$spotnameprefix, ENT_QUOTES)."\"><input type=\"submit\" name=\"indsubmit\" value=\"Continue to load ".htmlspecialchars((string)$showname, ENT_QUOTES)."\"></form>";

	}

	$shpname=explode("/",$shapefilename);

	include 'includes/mheader.php';

	vectorImportRun($smodel, array(
		'features' => vectorImportReadFeatures($randnum, $shapefilename),
		'epsg' => $epsg,
		'projectid' => $projectid,
		'randnum' => $randnum,
		'datasetname' => $datasetname,
		'spotnameprefix' => $spotnameprefix,
		'fixedcolumns' => $fixedcolumns,
		'datasetprops' => array('shapefile' => $shpname[2], 'featuretype' => $featuretype, 'datasettype' => "shapefile"),
		'loadingtitle' => "Loading Shapefile...",
		'donetext' => "Shapefile Load Complete.",
		'continuehtml' => $newhtml,
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
							<h2>Shapefile Upload</h2>
						</header>
<?php

//check for projects here
$projectrows = $strabo->getMyProjects();
$projectrows = $projectrows["projects"];

if(empty($projectrows)){
	?>
	No Projects found. You must create a project in which to store your shapefile. Click <a href="new_project">here</a> to add project.
	<?php
	exit();
}

?>

	<script type="text/javascript">

	function formvalidate(){
		var errors='';

		var e = document.getElementById("projectid");
		var projectid = e.options[e.selectedIndex].value;

		if(projectid=="" || projectid==null){errors=errors+'Project must be selected.\n';}

		if(document.forms["uploadform"]["zipfile"].value=="" || document.forms["uploadform"]["zipfile"].value==null){errors=errors+'Shapefile must be provided.\n';}

		if(errors!="" && errors!=null){
			alert(errors);
			return false;
		}
	}

	function doClickFile() {

		let FC = document.getElementById("zipfile");
		FC.click();

	}

	function changeFileName() {
		let fullPath = document.getElementById("zipfile").value;
		var filename = fullPath.replace(/^.*[\\/]/, '')
		document.getElementById("filename").value = filename;
	}

	</script>

	<?php echo $error?>

	<form name="uploadform" method="POST" onsubmit="return formvalidate();" enctype="multipart/form-data">

<div class="row gtr-uniform gtr-50">
	<div class="col-12">
		<h3>Project:</h3>
	</div>
	<div class="col-12">
		<select name="projectid" id="projectid">
			<option value="">Select...
			<?php
			foreach($projectrows as $pr){
			?>
			<option value="<?php echo $pr["id"]?>"><?php echo $pr["name"]?>
			<?php
			}
			?>
		</select>
	</div>
	<div class="col-12">
		<h3>Shapefile (.zip):</h3>
	</div>
	<div class="col-12">
		<input type="text" id="filename" placeholder="Choose File..." onclick="doClickFile();" readonly>
	</div>
	<div class="col-12">
		<ul class="actions">
			<li><input class="primary" type="submit" name="submitfile" value="Submit"></li>
			<li><input class="primary" type="reset" value="Reset"></li>
		</ul>
	</div>

</div>

<!--

<input type="file" id="docFile" class="formControl" onchange="exper_uploadSampleFile(0)">

-->

		<input type="file" id="zipfile" name="zipfile" accept=".zip" style="display:none;" onchange="changeFileName();">

		<input type="hidden" name="filename">
	</form>
					<div class="bottomSpacer"></div>

					</div>
				</div>
<?php
include 'includes/mfooter.php';
?>
