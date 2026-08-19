<?php
/**
 * File: publicStratSections.php
 * Description: Handles publicStratSections operations
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("../prepare_connections.php");

/*

https://strabospot.org/search/?datasetid=$datasetid


Needed Params:
xmin
xmax
ymin
ymax

https://strabospot.org/REST/publicStratSections?xmin=-109.031390&xmax=-102.039524&ymin=36.993778&ymax=40.955011
https://strabospot.org/REST/publicStratSections?xmin=-114.044427&xmax=-109.031390&ymin=37.028869&ymax=40.988192
https://strabospot.org/REST/publicStratSections?xmin=-114.044427&xmax=-111.625857&ymin=37.028869&ymax=38.967951

https://strabospot.org/REST/publicStratSections?xmin=-114.044427&xmax=-111.625857&ymin=37.028869&ymax=38.967951

https://strabospot.org/REST/publicStratSections?xmin=-106.164745&xmax=-103.636240&ymin=38.225235&ymax=39.219487

example query:
and ST_Contains(ST_GeomFromText('POLYGON((-117.5125 38.977500343323,-113.2375 40.552500343323,-113.63125 37.346250343323,-117.5125 38.977500343323))'), s.location)
*/

$xmin = isset($_GET['xmin']) ? (float)$_GET['xmin'] : 0;
$xmax = isset($_GET['xmax']) ? (float)$_GET['xmax'] : 0;
$ymin = isset($_GET['ymin']) ? (float)$_GET['ymin'] : 0;
$ymax = isset($_GET['ymax']) ? (float)$_GET['ymax'] : 0;

function showError($error){
	$out = new stdClass();
	$error .= "The StraboSpot publicStratSections endpoint expects four parameters (xmin, xmax, ymin, ymax) used to create a bounding box for locating spots. These bounding values should be provided in decimal degrees.";
	$out->error = $error;
	header("Bad Request", true, 400);
	header('Content-type: application/json');
	echo json_encode($out, JSON_PRETTY_PRINT);
	exit();
}

if($xmin == "" || $xmax == "" || $ymin == "" || $ymax == ""){

	if($xmin == "") $error .= "xmin cannot be blank. ";
	if($xmax == "") $error .= "xmax cannot be blank. ";
	if($ymin == "") $error .= "ymin cannot be blank. ";
	if($ymax == "") $error .= "ymax cannot be blank. ";
	showError($error);

}else{

	if(($xmin > $xmax) || ($ymin > $ymax)){

		if($xmin > $xmax) $error .= "xmin cannot be greater than xmax. ";
		if($ymin > $ymax) $error .= "ymin cannot be greater than ymax. ";
		showError($error);

	}else{

		$out = new stdClass();
		$out->type = "FeatureCollection";
		$features = [];

		$poly = "$xmin $ymin, $xmin $ymax, $xmax $ymax, $xmax $ymin, $xmin $ymin";

		$rows = $db->get_results_prepared("
			select strat.*, d.*, strat.strabo_spot_id from 
				project p,
				dataset d,
				spot s,
				strat_sections strat
			where
				p.project_pkey = d.project_pkey and
				d.dataset_pkey = s.dataset_pkey and
				s.spot_pkey = strat.spot_pkey
				and p.ispublic
			and ST_Contains(ST_GeomFromText($1), strat.location)
			;
		", array("POLYGON(($poly))"));


		foreach($rows as $row){
		
			//$db->dumpVar($row->strabo_dataset_id);exit();
			
			$feature = new stdClass();
			$feature->properties->id = $row->spot_pkey;
			$feature->properties->detail_url = "https://strabospot.org/REST/publicStratSectionDetail/".$row->strabo_spot_id;
			$feature->properties->view_url = "https://strabospot.org/strat_section?id=".$row->strabo_spot_id."&did=".$row->strabo_dataset_id;

			
			$json = json_decode($row->json);
			
			$mywkt = geoPHP::load($json->wkt,"wkt");
			$newjson = $mywkt->out('json');
			$newjson = json_decode($newjson);
			$feature->geometry=$newjson;
			
			$sed = json_decode($json->sed);

			//$db->dumpVar($sed);exit();
			
			
			
			foreach($sed->strat_section as $key=>$value){
				
				if($key != "strat_section_id" && $key != "display_lithology_patterns" && $key != "images"){
				
					if(is_array(json_decode($value)) || is_object(json_decode($value))){
						$value=json_decode($value);
					}
	
					eval("\$feature->properties->$key=\$value;");
				
				}

			}
			
			$features[] = $feature;
			
			//exit();
		}


/*
    [0] => stdClass Object
        (
            [strat_section_pkey] => 1637
            [spot_pkey] => 14634263
            [json] => {
    "date": "2018-08-25T20:41:02.000Z",
    "userpkey": 306,
    "altitude": 1346.89,
    "wkt": "POINT (-110.305812 38.885744)",
    "sed": "{\"strat_section\":{\"strat_section_id\":15352300970354,\"column_profile\":\"basic_lithologies\",\"column_y_axis_units\":\"m\",\"section_well_name\":\"CM-MS1\",\"display_lithology_patterns\":true,\"how_is_section_georeferenced\":\"point_at_secti\",\"purpose\":[\"education\"],\"section_type\":\"outcrop\",\"location_locality\":\"Exhumed paleochannels southwest of Green River, Utah\",\"age\":\"Early Cretaceous\"}}",
    "name": "Exhumed Channel",
    "time": "2018-08-25T20:41:02.000Z",
    "origwkt": "POINT (-110.305812 38.885744)",
    "id": 15352296629518,
    "geometrytype": "Point",
    "modified_timestamp": 1647888717924
}
            [location] => 01010000005CAE7E6C92935BC06A50340F60714340
        )
		foreach($rows as $row){
			$datasetid = $row->strabo_dataset_id;
			$json = $row->spotjson;
			$json = str_replace("\/db\/image\/", "\/pi\/", $json);
			$json = json_decode($json);
			$json->properties->landing_page = "https://strabospot.org/search/?datasetid=$datasetid";
			if(in_array($datasetid, $neoids)){
				$features[] = $json;
			}
		}

*/


		$out->features = $features;

		if(count($features) > 0){

		}else{
			header("No spots found in bounding box.", true, 404);
		}
//exit();
		header('Content-type: application/json');
		echo json_encode($out, JSON_PRETTY_PRINT);

	}
}

?>