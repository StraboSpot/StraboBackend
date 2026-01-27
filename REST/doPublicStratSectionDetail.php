<?php
/**
 * File: doPublicStratSectionDetail.php
 * Description: Handles doPublicStratSectionDetail operations
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("../prepare_connections.php");




$spot_id = $_GET['spot_id'];

if($spot_id == "") exit();

$dataset_id = $neodb->get_var("match (d:Dataset)-[HAS_SPOT]->(s:Spot) where s.id = $spot_id return d.id limit 1");

if($dataset_id == "") exit("dataset not found.");

$data = (object)$strabo->getPublicDatasetSpots($dataset_id);
$features = $data->features;

$out = new stdClass();
$out->type = "FeatureCollection";

$outfeatures = [];

foreach($features as $feature){
	$feature = (object)$feature;
	
	//$db->dumpVar($feature);
	
	
	if($feature->properties['id'] == $spot_id){
		$feature->properties['is_root_strat_section_spot'] = true;
		$outfeatures[] = $feature;
		$strat_section_id = $feature->properties['sed']->strat_section->strat_section_id;
	}
}

if($strat_section_id == "") exit("strat section not found");

foreach($features as $feature){
	$feature = (object)$feature;

	if($feature->properties['strat_section_id'] == $strat_section_id){
	
		$newfeature = $feature;
		unset($newfeature->properties['lng']);
		unset($newfeature->properties['lat']);
		unset($newfeature->properties['time']);

		
		$outfeatures[] = $newfeature;
	
	}
}

$out->features = $outfeatures;

		header('Content-type: application/json');
		echo json_encode($out, JSON_PRETTY_PRINT);

?>