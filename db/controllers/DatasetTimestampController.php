<?php
/**
 * File: DatasetTimestampController.php
 * Description: DatasetTimestampController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class DatasetTimestampController extends MyController
{

	public function getAction($request) {
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function deleteAction($request) {
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function postAction($request) {

		$upload = $request->parameters;
		unset($upload['apiformat']);
		$errors = "";

		if(isset($request->url_elements[2])) {

			$datasetId = $request->url_elements[2];

			// Check if user has write access to this dataset
			$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

			if (!$context) {
				return $this->notFound("Dataset not found");
			}

			// Check if user can edit this dataset (must be creator, or owner when halted)
			if (!$this->auth->canEditDataset($context, $context->datasetCreatedBy)) {
				return $this->forbidden("You don't have permission to update this dataset");
			}

			if($upload['timestamp'] != ""){

				$timestamp = $upload['timestamp'];
				$userpkey = $context->effectiveOwner;

				//update here
				$this->strabo->neodb->query("match (p:Dataset {id:$datasetId, userpkey:$userpkey}) set p.modified_timestamp=$timestamp");

			}else{
				$errors.="No Timestamp Provided. ";
			}

		}else{
			$errors.="No Dataset ID Provided. ";
		}

		 if(!$errors){

			$data = $upload;

		}else{
			header("Bad Request", true, 400);
			$data["Error"] = $errors;
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
