<?php
/**
 * File: buildCenters.php
 * Description: Handles buildCenters operations - helper script
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

exit();
ini_set('max_execution_time', 0);
include("logincheck.php");
include("prepare_connections.php");

echo str_repeat(" ", 256);

if($userpkey != 3) exit();

$userRows = $db->get_results("SELECT * FROM users WHERE pkey != 3 AND pkey > 4493 ORDER BY pkey");

foreach($userRows as $userRow){

	$upkey = $userRow->pkey;
	$name = $userRow->firstname." ".$userRow->lastname;
	echo "User: $name<br>";

	//Get projects
	$projectRows = $neodb->get_results("match (p:Project) where p.userpkey = $upkey return p;");
	foreach($projectRows as $prow){
		$prow = $prow->get("p");
		$prow=(object)$prow->values();
		$pcentroid = trim($prow->centroid);
		$pstraboid = $prow->id;
		$projectname = $prow->desc_project_name;

		echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$projectname<br>";

		if($pcentroid != "" && $pstraboid != ""){
			$db->prepare_query("UPDATE project SET location = ST_GeomFromText($1) WHERE strabo_project_id = $2 AND user_pkey = $3",
				array($pcentroid, $pstraboid, $upkey));
		}

		$datasetRows = $neodb->get_results("match (p:Project)-[HAS_DATASET]->(d:Dataset) where p.userpkey = $upkey and p.id=$pstraboid return d;");
		foreach($datasetRows as $drow){
			$drow = $drow->get("d");
			$drow=(object)$drow->values();
			$dcentroid = trim($drow->centroid);
			$dstraboid = $drow->id;
			$datasetname = $drow->name;

			if($dcentroid != "" && $dstraboid != ""){
				$db->prepare_query("UPDATE dataset SET location = ST_GeomFromText($1) WHERE strabo_dataset_id = $2 AND user_pkey = $3",
					array($pcentroid, $dstraboid, $upkey));
			}

			$querystring = "match (a:Dataset)-[r:HAS_SPOT]->(s:Spot) where a.userpkey=$upkey and a.id=$dstraboid optional match (s)-[c:HAS_IMAGE]-(i:Image) with s, collect(i) as i RETURN s,i;";
			$json = $strabo->getPGFeatureCollection($querystring);

			$spots = $json['features'];

			$spotcount = count($spots);

			echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Dataset: $datasetname ($spotcount spots)<br>";
			flush();
			ob_flush();

			foreach($spots as $spot){
				$strabo_spot_id = $spot['properties']['id'];

				if($spot['geometry']->type!=""){
					$locjson = json_encode($spot['geometry']);
					$mywkt=geoPHP::load($locjson,"json");
					$spotlocation = $mywkt->out('wkt');
				}else{
					$spotlocation="null";
				}

				if($spotlocation != "null"){
					$db->prepare_query("UPDATE spot SET location = ST_GeomFromText($1) WHERE strabo_spot_id = $2 AND user_pkey = $3",
						array($spotlocation, $strabo_spot_id, $upkey));
				}
			}

		}

	}

}

?>