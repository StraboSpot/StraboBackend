<?php
/**
 * File: getData.php
 * Description: AJAX endpoint for strat section data. Returns GeoJSON FeatureCollection
 *              containing the root strat section spot and all child spots.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();
include("../prepare_connections.php");

header('Content-type: application/json');

function showError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $msg], JSON_PRETTY_PRINT);
    exit();
}

$spot_id = isset($_GET['spot_id']) ? (int)$_GET['spot_id'] : 0;

if ($spot_id === 0) {
    showError("Missing or invalid spot_id parameter.");
}

// Find the dataset containing this spot
$dataset_id = $neodb->get_var("match (d:Dataset)-[HAS_SPOT]->(s:Spot) where s.id = $spot_id return d.id limit 1");

if ($dataset_id == "") {
    showError("Dataset not found for spot $spot_id.", 404);
}

// Get all spots in the dataset
$data = (object)$strabo->getPublicDatasetSpots($dataset_id);
$features = $data->features;

$out = new stdClass();
$out->type = "FeatureCollection";

$outfeatures = [];
$strat_section_id = "";

// First pass: find the root spot and extract strat_section_id
foreach ($features as $feature) {
    $feature = (object)$feature;

    if ($feature->properties['id'] == $spot_id) {
        $feature->properties['is_root_strat_section_spot'] = true;
        $outfeatures[] = $feature;
        $strat_section_id = $feature->properties['sed']->strat_section->strat_section_id;
    }
}

if ($strat_section_id == "") {
    showError("Strat section not found for spot $spot_id.", 404);
}

// Second pass: collect all spots belonging to this strat section
foreach ($features as $feature) {
    $feature = (object)$feature;

    if ($feature->properties['strat_section_id'] == $strat_section_id) {
        $newfeature = $feature;
        unset($newfeature->properties['lng']);
        unset($newfeature->properties['lat']);
        unset($newfeature->properties['time']);

        $outfeatures[] = $newfeature;
    }
}

$out->features = $outfeatures;

echo json_encode($out, JSON_PRETTY_PRINT);
?>
