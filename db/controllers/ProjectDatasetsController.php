<?php
/**
 * File: ProjectDatasetsController.php
 * Description: ProjectDatasetsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectDatasetsController extends MyController
{

	public function getAction($request) {

		if(isset($request->url_elements[2])) {

			$feature_id = $request->url_elements[2];

			$data = $this->strabo->getProjectDatasets($feature_id);

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Bad Request. No Dataset id specified.";
		}
		return $data;
	}
















	public function postAction($request) {

		if(isset($request->url_elements[2])) {

			$feature_id = $request->url_elements[2];

			$upload = $request->parameters;

			unset($upload['apiformat']);
		
			$datasetid=$upload['id'];

			if($datasetid == ""){
			
				//Error, feature not found
				header("Bad Request", true, 404);
				$data["Error"] = "No dataset ID provided.";

			}else{
				$data = $this->strabo->addDatasetToProject($feature_id,$datasetid,"HAS_DATASET");
			}

		} else { //feature id is not set error

			//Error, feature not found
			header("Bad Request", true, 404);
			$data["Error"] = "No project ID provided.";

		}

		return $data;
	}



















	public function deleteAction($request) {

		if(isset($request->url_elements[2])) {

			$feature_id = $request->url_elements[2];

			$this->strabo->deleteProjectDatasets($feature_id);

			header("Datasets deleted", true, 204);
			$data['message']="Datasets deleted.";

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Bad Request.";
		}
		return $data;

	}    public function optionsAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}    public function copyAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}}
