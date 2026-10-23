<?php
/**
 * File: vector_import.php
 * Description: Steps shared by the vector file loaders (load_shapefile.php, load_geopackage.php):
 *              read features with ogr2ogr, check values against the vocabularies, the column
 *              mapping page, and the import of one layer into a new dataset.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

function mytime(){
	$string = microtime();
	$parts=explode(" ",$string);
	$newtime = $parts[1].substr($parts[0],2,3);
	return (int)$newtime;
}

/*
	Features of one vector source as GeoJSON feature arrays.
	$source: file path; $layer: layer name ("" = the only/first layer);
	$options: extra ogr2ogr options, each one passed as its own argument.
*/
function vectorImportReadFeatures($randnum, $source, $layer = "", $options = array()){
	$out = "ogrtemp/".(int)$randnum;
	if(file_exists($out)){
		unlink($out);
	}
	$cmd = "ogr2ogr -f GeoJSON";
	foreach($options as $option){
		$cmd .= " ".escapeshellarg($option);
	}
	$cmd .= " ".escapeshellarg($out)." ".escapeshellarg($source);
	if($layer !== ""){
		$cmd .= " ".escapeshellarg($layer);
	}
	exec($cmd." 2>&1", $results);
	$json = file_exists($out) ? json_decode(file_get_contents($out), true) : null;
	if(file_exists($out)){
		unlink($out);
	}
	return $json['features'] ?? array();
}

// Controlled-vocabulary and number errors for the mapped columns, "" if none.
function vectorImportVocabError($smodel, $features){
	$vocaberror = array();
	foreach($features as $j){
		$properties = $j["properties"];
		foreach($properties as $key=>$value){
			if($value!=""){
				$strabocol = $smodel->userColumnToStraboColumn($key);
				if($strabocol){
					if(in_array($strabocol, $smodel->columns_with_other_option ?? array())){ //don't check columns that have "other" option
					}elseif($smodel->isControlled($strabocol)){
						if(!$newval = $smodel->fitsControlled($strabocol,$value)){
							$vocaberror[$strabocol]=$smodel->createCVError($strabocol,$value);
						}
					}elseif($smodel->isNumericTyped($strabocol)){
						if(!is_numeric($value)){
							$vocaberror[$strabocol]=$smodel->createNumericTypeError($strabocol,$value);
						}
					}
				}
			}
		}

	}

	if($vocaberror){
		return $smodel->implodeCVError($vocaberror);
	}
	return "";
}

/*
	The page functions print only the page body: the caller includes includes/mheader.php
	and includes/mfooter.php at top level (they read the page's globals) and exits.

	Column mapping page. $o: kind ("Shapefile"), showname, datasetname, spotnameprefix,
	vocaberror, hidden (name => value, posted back with columnsubmit).
*/
function vectorImportColumnsPage($smodel, $columns, $o){
	$fcarray=$columns;

	?>

		<script type="text/javascript">

		function formvalidate(){
			//alert('hey');

			var index;
			var mystring="";
			var mydelim="";
	<?php
	$mydelim="";
	$mystring="";
	foreach($fcarray as $fc){
		$mystring.=$mydelim.json_encode((string)$fc, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG);
		$mydelim=",";
	}
	$mystring = "var columns = [$mystring];";
	?>
			<?php echo $mystring?>
			//var columns = ["period", "epoch", "notes"];
			for	(index = 0; index < columns.length; index++) {
				mystring += mydelim + columns[index] + ":" + document.forms["columnform"][columns[index]+"_select"].value;
				mydelim=";";
			}

			document.forms["columnform"]["fixedcolumns"].value=mystring;

		}

		</script>

	<?php

	$vocaberror=$o['vocaberror'];

	?>

		<!-- Main -->
			<div id="main" class="wrapper style1">
				<div class="container">

					<header class="major">
						<h2>Define Columns for <?php echo $o['kind']?>:<br><?php echo htmlspecialchars((string)$o['showname'], ENT_QUOTES)?></h2>
					</header>

	<?php
	if($vocaberror!=""){
	?>
	<div style="border:1px solid #e44c65; border-radius:4px; padding:12px 16px; margin-bottom:24px;">
		<div style="color:#e44c65; font-weight:bold; padding-bottom:8px;">Error!</div>
		<div><?php echo $vocaberror?></div>
	</div>
	<?php
	}
	?>

	<form name="columnform" method="POST" onsubmit="return formvalidate();">

	Dataset Name: <input type="text" name="datasetname" value="<?php echo htmlspecialchars((string)$o['datasetname'], ENT_QUOTES)?>"><br>
	Spot Name Prefix: (optional) <input type="text" name="spotnameprefix" value="<?php echo htmlspecialchars((string)$o['spotnameprefix'], ENT_QUOTES)?>" size="10">
	<br><br>

	<p>
	Use the form below to define columns for upload. A best guess has been performed on the file columns.
	</p>

	<p>
	If your <?php echo strtolower($o['kind'])?> column does not fit into any of the provided column names, you may select "Custom Column" to retain the <?php echo strtolower($o['kind'])?> information at strabospot.org.
	</p>

	<?php
	if($smodel->dumpVar){
	}
	?>

	<div class="mytable">
		<table style="padding-top:20px;">
			<thead>
				<tr>
					<th><strong><?php echo $o['kind']?> Column&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</strong></th><th><strong>StraboSpot Column</strong></th>
				</tr>
			</thead>
	<?php

	include_once("includes/straboClasses/straboModelClass.php");

	if(!$smodel){
		$smodel = new straboModelClass();
	}

	foreach($fcarray as $fc){

	?>

			<tr>
				<td><?php echo htmlspecialchars((string)$fc, ENT_QUOTES)?></td>
				<td>
					<?php echo $smodel->getSelect("$fc")?>
				</td>
			</tr>
	<?php
	}
	?>

		</table>

	</div>
	<div style="padding-top:20px;" align="center">
		<input class="primary" type="submit" name="columnsubmit" value="Submit">
	</div>
	<?php
	foreach($o['hidden'] as $name=>$value){
	?>
	<input type="hidden" name="<?php echo htmlspecialchars((string)$name, ENT_QUOTES)?>" value="<?php echo htmlspecialchars((string)$value, ENT_QUOTES)?>">
	<?php
	}
	?>
	<input type="hidden" name="fixedcolumns" value="">
	</form>
				<div class="bottomSpacer"></div>

				</div>
			</div>
	<?php
}

/*
	Import features into a new dataset of the project, printing the progress page.
	$o: features, epsg, projectid, randnum, datasetname, spotnameprefix, fixedcolumns,
	datasetprops (extra Dataset node properties), loadingtitle, donetext,
	continuehtml (next-layer form, or "" for the link back to My Field Data).
*/
function vectorImportRun($smodel, $o){


	global $db, $neodb, $strabo, $starttime;

	?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2><?php echo $o['loadingtitle']?></h2>
						</header>

	<div id="progress" style="width:100%;border:1px solid #ccc;"></div>
	<!-- Progress information -->
	<div id="information" style="width"></div>

	<div id="continuediv" style="padding-top:20px;"></div>

	<?php

	$randnum=$o['randnum'];
	$projectid=$o['projectid'];
	$fixedcolumns=$o['fixedcolumns'];
	$epsg=$o['epsg'];
	$userpkey=(int)$_SESSION['userpkey'];
	$datasetname = $o['datasetname'];
	$spotnameprefix = $o['spotnameprefix'];

	$dbcols=array();

	$parts=explode(";",$fixedcolumns);
	foreach($parts as $part){
		$bits=explode(":",$part);
		if($bits[1]!=""){
			$dbcols[$bits[1]]=$bits[0];
		}
	}

	$smodel->setDBCols($dbcols);

	$projectvars = $neodb->getNode("match (p:Project) where p.id=$projectid and p.userpkey=$userpkey return p");
	$smodel->setProjectVars($projectvars);

	$t=0;

	$json = $o['features'];

	$totalcount = count($json);

	{
		$step=floor($totalcount/100);
		if($step==0){$step=1;}

		if($totalcount > 0){

			//create a "Dataset" node here to group all of the features.
			unset($injson);
			$injson['userpkey']=$userpkey;
			$datasetid=$smodel->getId();

			$injson['id']=$datasetid;
			$injson['datecreated']=time();
			$injson['folder']=$randnum;
			$injson['name']=$datasetname;
			foreach($o['datasetprops'] as $key=>$value){
				$injson[$key]=$value;
			}
			$injson=json_encode($injson);

			//********************************************************************
			// OK, we have some JSON formed and ready for Neo4j.
			// Let's POST to the REST API to create a node
			//********************************************************************
			$id = $neodb->createNode($injson,"Dataset");

			//********************************************************************
			// Now add dataset to project.
			//********************************************************************
			$updateprojectid = $strabo->straboIDToID($projectid,"Project");
			$neodb->addRelationship($updateprojectid,$id,"HAS_DATASET");

			// Mirror any sample sub-objects into strabosamples.* with full
			// project/dataset context. insertSpot()'s sync hook CAN resolve
			// context via Cypher, but in the loop below addSpotToDataset()
			// runs AFTER insertSpot, so the spot->dataset edge doesn't exist
			// yet when the hook fires and the fallback would miss it. Set the
			// context explicitly, matching the bulk Field controllers.
			$strabo->setSampleSyncContext($projectid, $datasetid);

			// StraboSearch live-sync (§5.3.4): suppress per-spot touches for
			// the import loop; one batch sync at end-of-run beside
			// buildPgDataset.
			require_once __DIR__ . '/db/lib/search_sync.php';
			field_search_sync_suppress();

			$groupid = $id;

		}

		$foundspots = [];

		foreach($json as $onesample){

			$geometry = $onesample['geometry'];

			if($geometry != ""){

				$thisnewid = $smodel->getId();

				$putinfeature="yes";

				if($geometry!=""){
					$geometry = json_encode($geometry);
					$myjson = geoPHP::load($geometry,"json");
					$mywkt = $myjson->out('wkt');
				}else{
					$putinfeatur="no";
				}

				if($mywkt!=""){
					$myjson = geoPHP::load($geometry,"json");
					$mywkt = $myjson->out('wkt');
				}

				unset($injson);

				$injson['type']="Feature";

				$uploaddate = time();

				$geom = $mywkt;

				//blank geoms not allowed
				if($geom==""){$putinfeature="no";}

				//broken values from ESRI contain "+" sign

				if(strpos($geom,"+")>0){
					$putinfeature="no";
				}

				$fixedgeom = "";

				if($geom != ""){

					if($epsg != "" && $epsg!=""){

						// $epsg is cast to int earlier, $geom is from shapefile parsing
						$fixedgeom=$db->get_var_prepared("SELECT ST_AsText(ST_Transform(ST_GeomFromText($1,$2),4326)) as geom", array($geom, (int)$epsg));

					}else{

						$fixedgeom="empty";

					}

				}

				if($fixedgeom!=""){
					$mywkt = geoPHP::load($fixedgeom,"wkt");
					$mygeometry = $mywkt->out('json');
				}

				$onesampleproperties = $onesample['properties'];

				$smodel->setSampleProperties($onesampleproperties);

				/***********************************
					Get spot level properties
				***********************************/
				$spotvars = $smodel->get_vars("spot");

				foreach($spotvars as $key=>$value){
					if($key=="name"){
						$value = strval($value);
					}else{
						$value = $smodel->fixCast($value);
					}

					$injson['properties'][$key]=$value;
				}

				/***********************************
					Get orientation data
				***********************************/
				unset($planarorientation);
				$planarorientation=array();
				if($smodel->hasPlanarOrientationData()){
					$planarorientation["id"]=$smodel->getId();
					$planarorientation["type"]="planar_orientation";
					$planar_orientation_vars = $smodel->get_vars("planar_orientation");

					foreach($planar_orientation_vars as $key=>$value){
						if($key=="feature_type"){
							if($newvalue = $smodel->fitsControlled("planar_orientation_feature_type",$value)){
								$planarorientation['feature_type']=$newvalue;
							}else{
								if(trim($value=="")){$value="not given";}$oothers.="planar value: $value<br>";
								$planarorientation['feature_type']="other";
								$planarorientation['other_feature']=$value;
							}
						}elseif($key=="movement"){
							if($newvalue = $smodel->fitsControlled("planar_orientation_movement",$value)){
								$planarorientation['movement']=$newvalue;
							}else{
								$planarorientation['movement']="other";
								$planarorientation['other_movement']=$value;

							}
						}else{
							$value = $smodel->fixCast($value);
							$planarorientation[$key]=$value;
						}
					}

					if($planarorientation['feature_type']==""){ $planarorientation['feature_type']="other"; $planarorientation['other_feature']="not given"; }

					if($smodel->hasLinearOrientationData()){
						unset($linearorientation);
						$linearorientation=array();
						$linearorientation["id"]=$smodel->getId();
						$linearorientation["type"]="linear_orientation";
						$linear_orientation_vars = $smodel->get_vars("linear_orientation");
						foreach($linear_orientation_vars as $key=>$value){
							if($key=="feature_type"){
								if($newvalue = $smodel->fitsControlled("linear_orientation_feature_type",$value)){
									$linearorientation['feature_type']=$newvalue;
								}else{
									if(trim($value=="")){$value="not given";}$oothers.="inside linear value: $value<br>";
									$linearorientation['feature_type']="other";
									$linearorientation['other_feature']=$value;
								}
							}else{
								$value = $smodel->fixCast($value);
								$linearorientation[$key]=$value;
							}
						}

						if($linearorientation['feature_type']==""){ $linearorientation['feature_type']="other"; $linearorientation['other_feature']="not given"; }

						$planarorientation["associated_orientation"][]=$linearorientation;
					}

					$injson['properties']["orientation_data"][]=$planarorientation;

				}elseif($smodel->hasLinearOrientationData()){

					unset($linearorientation);
					$linearorientation=array();
					$linearorientation["id"]=$smodel->getId();
					$linearorientation["type"]="linear_orientation";
					$linear_orientation_vars = $smodel->get_vars("linear_orientation");
					foreach($linear_orientation_vars as $key=>$value){
						if($key=="feature_type"){
							if($newvalue = $smodel->fitsControlled("linear_orientation_feature_type",$value)){
								$linearorientation['feature_type']=$newvalue;
							}else{
								if(trim($value=="")){$value="not given";}$oothers.="outside linear value: $value<br>";
								$linearorientation['feature_type']="other";
								$linearorientation['other_feature']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$linearorientation[$key]=$value;
						}
					}

					if($linearorientation['feature_type']==""){ $linearorientation['feature_type']="other"; $linearorientation['other_feature']="not given"; }

					$injson['properties']["orientation_data"][]=$linearorientation;

				}

				unset($tabularzoneorientation);
				$tabularzoneorientation=array();
				if($smodel->hasTabularZoneOrientationData()){
					$tabularzoneorientation["id"]=$smodel->getId();
					$tabularzoneorientation["type"]="tabular_orientation";
					$tabular_zone_orientation_vars = $smodel->get_vars("tabular_zone_orientation");
					foreach($tabular_zone_orientation_vars as $key=>$value){
						if($key=="facing_defined_by"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_facing_defined_by",$value)){
								$tabularzoneorientation['facing_defined_by']=$newvalue;
							}else{
								$tabularzoneorientation['facing_defined_by']="other";
								$tabularzoneorientation['other_facing_defined_by']=$value;
							}
						}elseif($key=="feature_type"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_feature_type",$value)){
								$tabularzoneorientation['feature_type']=$newvalue;
							}else{
								$tabularzoneorientation['feature_type']="other";
								$tabularzoneorientation['other_feature']=$value;
							}
						}elseif($key=="intrusive_body_type"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_intrusive_body_type",$value)){
								$tabularzoneorientation['intrusive_body_type']=$newvalue;
							}else{
								$tabularzoneorientation['intrusive_body_type']="other";
								$tabularzoneorientation['other_intrusive_body']=$value;
							}
						}elseif($key=="vein_fill"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_vein_fill",$value)){
								$tabularzoneorientation['vein_fill']=$newvalue;
							}else{
								$tabularzoneorientation['vein_fill']="other";
								$tabularzoneorientation['other_vein_fill']=$value;
							}
						}elseif($key=="vein_array"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_vein_array",$value)){
								$tabularzoneorientation['vein_array']=$newvalue;
							}else{
								$tabularzoneorientation['vein_array']="other";
								$tabularzoneorientation['other_vein_array']=$value;
							}
						}elseif($key=="fault_or_sz"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_fault_or_sz",$value)){
								$tabularzoneorientation['fault_or_sz']=$newvalue;
							}else{
								$tabularzoneorientation['fault_or_sz']="other";
								$tabularzoneorientation['other_fault_or_sz']=$value;
							}
						}elseif($key=="movement"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_movement",$value)){
								$tabularzoneorientation['movement']=$newvalue;
							}else{
								$tabularzoneorientation['movement']="other";
								$tabularzoneorientation['other_movement']=$value;
							}
						}elseif($key=="movement_justification"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_movement_justification",$value)){
								$tabularzoneorientation['movement_justification']=$newvalue;
							}else{
								$tabularzoneorientation['movement_justification']="other";
								$tabularzoneorientation['other_movement_justification']=$value;
							}
						}elseif($key=="dir_indicators"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_dir_indicators",$value)){
								$tabularzoneorientation['dir_indicators']=$newvalue;
							}else{
								$tabularzoneorientation['dir_indicators']="other";
								$tabularzoneorientation['other_dir_indicators']=$value;
							}
						}elseif($key=="enveloping_surface_geometry"){
							if($newvalue = $smodel->fitsControlled("tabular_zone_orientation_enveloping_surface_geometry",$value)){
								$tabularzoneorientation['enveloping_surface_geometry']=$newvalue;
							}else{
								$tabularzoneorientation['enveloping_surface_geometry']="other";
								$tabularzoneorientation['other_surface_geometry']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$tabularzoneorientation[$key]=$value;
						}
					}

					if($tabularzoneorientation['feature_type']=="") $tabularzoneorientation['feature_type']="other";

					$injson['properties']["orientation_data"][]=$tabularzoneorientation;

				}

				/***********************************
						Get 3D Structure data
				***********************************/
				if($smodel->has3dStructureData()){

					if($smodel->hasFabricData()){
					unset($thisstruct);
					$thisstruct=array();
					$thisstruct["id"]=$smodel->getId();
					$thisstruct["type"]="fabric";
					$struct_vars = $smodel->get_vars("fabric");
					foreach($struct_vars as $key=>$value){
						if($key=="feature_type"){
							if($newvalue = $smodel->fitsControlled("fabric_feature_type",$value)){
								$thisstruct['feature_type']=$newvalue;
							}else{
								$thisstruct['feature_type']="other_fabric";
								$thisstruct['other_description']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$thisstruct[$key]=$value;
						}
					}
					$injson['properties']["_3d_structures"][]=$thisstruct;
					}

					if($smodel->hasFoldData()){
					unset($thisstruct);
					$thisstruct=array();
					$thisstruct["id"]=$smodel->getId();
					$thisstruct["type"]="fold";
					$struct_vars = $smodel->get_vars("fold");
					foreach($struct_vars as $key=>$value){
						if($key=="feature_type"){
							if($newvalue = $smodel->fitsControlled("fold_feature_type",$value)){
								$thisstruct['feature_type']=$newvalue;
							}else{
								$thisstruct['feature_type']="other";
								$thisstruct['other_dominant_geometry']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$thisstruct[$key]=$value;
						}
					}
					$injson['properties']["_3d_structures"][]=$thisstruct;
					}

					if($smodel->hasTensorData()){
					unset($thisstruct);
					$thisstruct=array();
					$thisstruct["id"]=$smodel->getId();
					$thisstruct["type"]="tensor";
					$struct_vars = $smodel->get_vars("tensor");
					foreach($struct_vars as $key=>$value){
						if($key=="ellipsoid_type"){
							if($newvalue = $smodel->fitsControlled("tensor_ellipsoid_type",$value)){
								$thisstruct['ellipsoid_type']=$newvalue;
							}else{
								$thisstruct['ellipsoid_type']="other";
								$thisstruct['other_ellipsoid_type']=$value;
							}
						}elseif($key=="non_ellipsoidal_type"){
							if($newvalue = $smodel->fitsControlled("tensor_non_ellipsoidal_type",$value)){
								$thisstruct['non_ellipsoidal_type']=$newvalue;
							}else{
								$thisstruct['non_ellipsoidal_type']="other";
								$thisstruct['other_non_ellipsoidal_type']=$value;
							}
						}elseif($key=="ellipse_type"){
							if($newvalue = $smodel->fitsControlled("tensor_ellipse_type",$value)){
								$thisstruct['ellipse_type']=$newvalue;
							}else{
								$thisstruct['ellipse_type']="other";
								$thisstruct['other_ellipse_type']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$thisstruct[$key]=$value;
						}
					}
					$injson['properties']["_3d_structures"][]=$thisstruct;
					}

					if($smodel->hasOther3dStructureData()){
					unset($thisstruct);
					$thisstruct=array();
					$thisstruct["id"]=$smodel->getId();
					$thisstruct["type"]="other";
					$struct_vars = $smodel->get_vars("other_3d_structure");
					foreach($struct_vars as $key=>$value){
						if($key=="feature_type"){
							if($newvalue = $smodel->fitsControlled("other_3d_structure_feature_type",$value)){
								$thisstruct['feature_type']=$newvalue;
							}else{
								$thisstruct['feature_type']="other_3d_structure";
								$thisstruct['other_structure_description']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$thisstruct[$key]=$value;
						}
					}
					$injson['properties']["_3d_structures"][]=$thisstruct;
					}

				}

				/***********************************
						Get Rock Unit data
				***********************************/
				if($smodel->hasRockUnitData()){
					unset($thisstruct);
					$thisstruct=array();
					$struct_vars = $smodel->get_vars("rock_unit");
					foreach($struct_vars as $key=>$value){
						if($key=="sediment_type"){
							if($newvalue = $smodel->fitsControlled("rock_unit_sediment_type",$value)){
								$thisstruct['sediment_type']=$newvalue;
							}else{
								$thisstruct['sediment_type']="other";
								$thisstruct['other_sediment_type']=$value;
							}
						}elseif($key=="sedimentary_rock_type"){
							if($newvalue = $smodel->fitsControlled("rock_unit_sedimentary_rock_type",$value)){
								$thisstruct['sedimentary_rock_type']=$newvalue;
							}else{
								$thisstruct['sedimentary_rock_type']="other";
								$thisstruct['other_sedimentary_rock_type']=$value;
							}
						}elseif($key=="volcanic_rock_type"){
							if($newvalue = $smodel->fitsControlled("rock_unit_volcanic_rock_type",$value)){
								$thisstruct['volcanic_rock_type']=$newvalue;
							}else{
								$thisstruct['volcanic_rock_type']="other";
								$thisstruct['other_volcanic_rock_type']=$value;
							}
						}elseif($key=="plutonic_rock_types"){
							if($newvalue = $smodel->fitsControlled("rock_unit_plutonic_rock_types",$value)){
								$thisstruct['plutonic_rock_types']=$newvalue;
							}else{
								$thisstruct['plutonic_rock_types']="other";
								$thisstruct['other_plutonic_rock_type']=$value;
							}
						}elseif($key=="metamorphic_rock_types"){
							if($newvalue = $smodel->fitsControlled("rock_unit_metamorphic_rock_types",$value)){
								$thisstruct['metamorphic_rock_types']=$newvalue;
							}else{
								$thisstruct['metamorphic_rock_types']="other";
								$thisstruct['other_metamorphic_rock_type']=$value;
							}
						}elseif($key=="metamorphic_grade"){
							if($newvalue = $smodel->fitsControlled("rock_unit_metamorphic_grade",$value)){
								$thisstruct['metamorphic_grade']=$newvalue;
							}else{
								$thisstruct['metamorphic_grade']="other";
								$thisstruct['other_metamorphic_grade']=$value;
							}
						}elseif($key=="epoch"){
							if($newvalue = $smodel->fitsControlled("rock_unit_epoch",$value)){
								$thisstruct['epoch']=$newvalue;
							}else{
								$thisstruct['epoch']="other";
								$thisstruct['other_epoch']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$thisstruct[$key]=$value;
						}
					}

					if($thisstruct['rock_type']=="igneous"){
						if($thisstruct['igneous_rock_class']==""){
							$thisstruct['igneous_rock_class']="";
						}
					}

					if($putinfeature=="yes"){
						$smodel->addRockUnitTag($thisstruct,$thisnewid);
						$tagsfound="yes";
					}
				}

				/***********************************
					  Get Trace data
				***********************************/
				if($smodel->hasTraceData()){
					unset($thisstruct);
					$thisstruct=array();
					$thisstruct["trace_feature"]=true;
					$struct_vars = $smodel->get_vars("trace");

					foreach($struct_vars as $key=>$value){
						if($key=="quality"){
							if($newvalue = $smodel->fitsControlled("trace_trace_quality",$value)){
								$thisstruct['trace_quality']=$newvalue;
							}else{
								$thisstruct['trace_quality']="other";
								$thisstruct['other_trace_quality']=$value;
							}
						}elseif($key=="contact_type"){
							if($newvalue = $smodel->fitsControlled("trace_contact_type",$value)){
								$thisstruct['contact_type']=$newvalue;
							}else{
								$thisstruct['contact_type']="other";
								$thisstruct['other_contact_type']=$value;
							}
						}elseif($key=="depositional_contact_type"){
							if($newvalue = $smodel->fitsControlled("trace_depositional_contact_type",$value)){
								$thisstruct['depositional_contact_type']=$newvalue;
							}else{
								$thisstruct['depositional_contact_type']="other";
								$thisstruct['other_depositional_type']=$value;
							}
						}elseif($key=="intrusive_contact_type"){
							if($newvalue = $smodel->fitsControlled("trace_intrusive_contact_type",$value)){
								$thisstruct['intrusive_contact_type']=$newvalue;
							}else{
								$thisstruct['intrusive_contact_type']="other";
								$thisstruct['other_intrusive_contact']=$value;
							}
						}elseif($key=="metamorphic_contact_type"){
							if($newvalue = $smodel->fitsControlled("trace_metamorphic_contact_type",$value)){
								$thisstruct['metamorphic_contact_type']=$newvalue;
							}else{
								$thisstruct['metamorphic_contact_type']="other";
								$thisstruct['other_metamorphic_contact']=$value;
							}
						}elseif($key=="shear_sense"){
							if($newvalue = $smodel->fitsControlled("trace_shear_sense",$value)){
								$thisstruct['shear_sense']=$newvalue;
							}else{
								$thisstruct['shear_sense']="other";
								$thisstruct['other_shear_sense']=$value;
							}
						}elseif($key=="other_structural_zones"){
							if($newvalue = $smodel->fitsControlled("trace_other_structural_zones",$value)){
								$thisstruct['other_structural_zones']=$newvalue;
							}else{
								$thisstruct['other_structural_zones']="other";
								$thisstruct['other_other_structural_zone']=$value;
							}
						}elseif($key=="fold_type"){
							if($newvalue = $smodel->fitsControlled("trace_fold_type",$value)){
								$thisstruct['fold_type']=$newvalue;
							}else{
								$thisstruct['fold_type']="other";
								$thisstruct['other_fold_type']=$value;
							}
						}elseif($key=="attitude"){
							if($newvalue = $smodel->fitsControlled("trace_fold_attitude",$value)){
								$thisstruct['attitude']=$newvalue;
							}else{
								$thisstruct['attitude']="other";
								$thisstruct['other_attitude']=$value;
							}
						}elseif($key=="geomorphic_feature"){
							if($newvalue = $smodel->fitsControlled("trace_geomorphic_feature",$value)){
								$thisstruct['geomorphic_feature']=$newvalue;
							}else{
								$thisstruct['geomorphic_feature']="other";
								$thisstruct['other_geomorphic_feature']=$value;
							}
						}elseif($key=="antropogenic_feature"){
							if($newvalue = $smodel->fitsControlled("trace_antropogenic_feature",$value)){
								$thisstruct['antropogenic_feature']=$newvalue;
							}else{
								$thisstruct['antropogenic_feature']="other";
								$thisstruct['other_antropogenic_feature']=$value;
							}
						}elseif($key=="other_feature"){
							if($newvalue = $smodel->fitsControlled("trace_other_feature",$value)){
								$thisstruct['other_feature']=$newvalue;
							}else{
								$thisstruct['other_feature']="other";
								$thisstruct['other_other_feature']=$value;
							}
						}elseif($key=="character"){
							if($newvalue = $smodel->fitsControlled("trace_trace_character",$value)){
								$thisstruct['character']=$newvalue;
							}else{
								$thisstruct['character']="other";
								$thisstruct['other_character']=$value;
							}
						}elseif($key=="type"){
							if($newvalue = $smodel->fitsControlled("trace_trace_type",$value)){
								$thisstruct['trace_type']=$newvalue;
							}else{
								$thisstruct['trace_type']="other_feature";
								$thisstruct['other_feature']="other";
								$thisstruct['other_other_feature']=$value;
							}
						}elseif($key=="geologic_structure_type"){
							if($newvalue = $smodel->fitsControlled("trace_geologic_structure_type",$value)){
								$thisstruct['geologic_structure_type']=$newvalue;
							}else{
								$thisstruct['geologic_structure_type']="other";
								$thisstruct['cother_feature']="other";
								$thisstruct['cother_other_feature']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$thisstruct[$key]=$value;
						}

					}

					if($thisstruct['trace_type']==""){

						if($thisstruct['contact_type']!=""){
							$thisstruct['trace_type']="contact";
						}else{
							$thisstruct['trace_type']="other_feature";
							$thisstruct['other_feature']="other";
							$thisstruct['other_other_feature']="not given";
						}
					}

					$injson['properties']["trace"]=$thisstruct;
				}

				/***********************************
					  Get Other Feature data
				***********************************/
				if($smodel->hasOtherFeatureData()){
					unset($thisstruct);
					$thisstruct=array();
					$thisstruct["id"]=$smodel->getId();
					$struct_vars = $smodel->get_vars("other_features");
					foreach($struct_vars as $key=>$value){
						$value = $smodel->fixCast($value);
						$thisstruct[$key]=$value;
					}
					$injson['properties']["other_features"][]=$thisstruct;
				}

				/***********************************
						Get Sample data
				***********************************/
				if($smodel->hasSampleData()){
					unset($thisstruct);
					$thisstruct=array();
					$thisstruct["id"]=$smodel->getId();
					$struct_vars = $smodel->get_vars("sample");
					foreach($struct_vars as $key=>$value){
						if($key=="material_type"){
							if($newvalue = $smodel->fitsControlled("sample_material_type",$value)){
								$thisstruct['material_type']=$newvalue;
							}else{
								$thisstruct['material_type']="other";
								$thisstruct['other_material_type']=$value;
							}
						}elseif($key=="main_sampling_purpose"){
							if($newvalue = $smodel->fitsControlled("sample_main_sampling_purpose",$value)){
								$thisstruct['main_sampling_purpose']=$newvalue;
							}else{
								$thisstruct['main_sampling_purpose']="other";
								$thisstruct['other_sampling_purpose']=$value;
							}
						}else{
							$value = $smodel->fixCast($value);
							$thisstruct[$key]=$value;
						}
					}
					$injson['properties']["samples"][]=$thisstruct;
				}

				/***********************************
						 Get custom fields
				***********************************/
				if($smodel->hasCustomFields()){
					unset($customfields);
					$customfields=array();
					$customfieldvars = $smodel->get_vars("sfcustom");
					$injson['properties']["custom_fields"]=$customfieldvars;
				}

				/***********************************
						Get Tag data
				***********************************/
				if($smodel->hasTagData()){
					unset($thisstruct);
					$thisstruct=array();
					$struct_vars = $smodel->get_vars("tag");
					foreach($struct_vars as $key=>$value){
						$value = $smodel->fixCast($value);
						$thisstruct[$key]=$value;
					}

					if($putinfeature=="yes"){
						$smodel->addTag($thisstruct,$thisnewid);
						$tagsfound="yes";
					}
				}

				$mytime=time();
				$myrand = rand(1000,9999);

				$injson_straboid= $smodel->getId();
				$injson_time = date("c");
				$injson_modified_timestamp = $mytime*1000;
				$injson_mydate = $injson_time;
				$injson_myname = $mytime.$myrand;

				$injson['properties']['id']=$thisnewid;
				$injson['properties']['time'] = $injson_time;
				$injson['properties']['modified_timestamp'] = mytime();
				$injson['properties']['date'] = $injson_mydate;

				if($injson['properties']['name']==""){
					$injson['properties']['name'] = $spotnameprefix.(string)$t;
				}

				$injson['geometry']=json_decode($mygeometry);

				$injson=json_encode($injson,JSON_PRETTY_PRINT);

				if($putinfeature=="yes"){

					$strabo->insertSpot($injson);
					$strabo->addSpotToDataset($datasetid,$thisnewid);
					$foundspots[] = $thisnewid;

				}else{//end if putinfeature == yes

				}

				//determine if this is 1/100 and display update if needed
				if($t % $step == 0){

					// Calculate the percentage
					$percent = intval($t/$totalcount * 100)."%";

					// Javascript for updating the progress bar and information
					echo '<script language="javascript">
					document.getElementById("progress").innerHTML="<div style=\"width:'.$percent.';background-color:#ddd;\">&nbsp;</div>";
					document.getElementById("information").innerHTML="'.$percent.' loaded.";
					</script>';

					// This is for the buffer achieve the minimum size in order to flush data
					echo str_repeat(' ',1024*128);

					// Send output to browser immediately
					flush();

				}

				$t++;

			}//end if geometry
		}//end foreach json

		$strabo->clearSampleSyncContext();

		if($tagsfound=="yes"){

			// Javascript for updating the progress bar and information
			echo '<script language="javascript">
			document.getElementById("progress").innerHTML="<div style=\"width:50%;background-color:#ddd;\">&nbsp;</div>";
			document.getElementById("information").innerHTML="Building Tag Relationships...";
			</script>';

			// This is for the buffer achieve the minimum size in order to flush data
			echo str_repeat(' ',1024*128);

			// Send output to browser immediately
			flush();

			

			$pid = $strabo->straboIDToID($projectid,"Project");

			//Query to get spot ids
			//17176789102895

			$sids = array();
			$rows = $neodb->get_results("match (p:Project)-[HAS_DATASET]->(d:Dataset)-[HAS_SPOT]->(s:Spot) where p.id = $projectid return distinct(s.id) as spotid;");
			foreach($rows as $row){
				$spotid=$row->get("spotid");
				$sids[] = $spotid;
			}

			$existingtags = $smodel->projectvars['json_tags'];
			if($existingtags != ""){
				$existingtags = json_decode($existingtags);

				$newfixtags = array();
				foreach($existingtags as $thistag){
					$goodspots = array();
					$oldspots = $thistag->spots;
					foreach($oldspots as $thisspot){
						if(in_array($thisspot, $sids)) $goodspots[] = $thisspot;
					}
					$thistag->spots = $goodspots;

					if(!empty($thistag->spots)){
						$newfixtags[] = $thistag;
					}
				}

				$smodel->projectvars['json_tags'] = json_encode($newfixtags);

			}

			//update project

			$neodb->updateNode($pid,json_encode($smodel->projectvars),"Project");
			$strabo->buildProjectRelationships($projectid);
		}

		//now find centers for dataset and project
		$strabo->setProjectCenter($projectid);
		$strabo->setDatasetCenter($datasetid);

		//also build PG dataset
		$strabo->buildPgDataset($datasetid); //need to re-implement JMA 02282020

		// StraboSearch live-sync (§5.3.4): end-of-import batch sync.
		require_once __DIR__ . '/db/lib/search_sync.php';
		field_search_sync_resume();
		field_search_sync_dataset($db, $neodb, $datasetid, $strabo->userpkey);

		$percent = 100;

		echo '<script language="javascript">
		document.getElementById("progress").innerHTML="<div style=\"width:'.$percent.';background-color:#ddd;\">&nbsp;</div>";
		document.getElementById("information").innerHTML="'.$o['donetext'].'";
		</script>';

	}

	// Tell user that the process is completed

	$timetook = time()-$starttime;

	$persamp = $totalcount > 0 ? $timetook/$totalcount : 0;

	echo '<script language="javascript">document.getElementById("information").innerHTML="<p>'.$o['donetext'].'</p><p>'.$t.' spots loaded in '.$timetook.' seconds.</p><p>('.$persamp.' per spot)</p>"</script>';

	$newhtml = $o['continuehtml'] != "" ? $o['continuehtml'] : "<a href=\"my_field_data\">Continue</a>";

	echo '<script language="javascript">document.getElementById("continuediv").innerHTML=\''.$newhtml.'\'</script>';

	?>
					<div class="bottomSpacer"></div>

					</div>
				</div>
	<?php
}

?>