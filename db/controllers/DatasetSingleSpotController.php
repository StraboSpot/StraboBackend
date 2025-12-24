<?php
/**
 * File: DatasetSingleSpotController.php
 * Description: DatasetSingleSpotController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class DatasetSingleSpotController extends MyController
{

	public function getAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function postAction($request) {

		if(isset($request->url_elements[2])) {

			//*******************************************************************************
			//update attributes for feature

			$datasetId = $request->url_elements[2];

			// Check if user has edit access to this dataset
			$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

			if ($context !== null) {
				// Dataset is linked to a project - check edit permission
				if (!$this->auth->canEditDataset($context, $context->datasetCreatedBy)) {
					if ($context->permissionLevel === 'none') {
						return $this->notFound("Dataset not found");
					}
					return $this->forbidden("You don't have permission to add spots to this dataset");
				}
			}

			// Use effectiveOwner for the operation
			$originalUserpkey = $this->strabo->userpkey;
			if ($context !== null && $context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($context->effectiveOwner);
			}

			//********************************************************************
			// check for Dataset with userid and id
			//********************************************************************
			if($this->strabo->findDataset($datasetId)){

				$upload = $request->parameters;

				unset($upload['apiformat']);

				$straboid=$upload['properties']->id;

				if($straboid!=""){

					//********************************************************************
					// Load the spot and add it to dataset
					//********************************************************************

					//delete relationships
					$this->strabo->deleteDatasetReltationships($datasetId);

					//fix single spot basemap coords

					$injson = json_encode($upload,JSON_PRETTY_PRINT);

					$thisdata = $this->strabo->insertSpot($injson);

					$parts = $thisdata->properties->self;

					$parts = explode("/",$parts);
					$straboid = end($parts);

					if(!$this->strabo->findSpotInDataset($datasetId,$straboid)){

						$this->strabo->addSpotToDataset($datasetId,$straboid);

						$totalspottime = microtime(true)-$spotstarttime; $this->strabo->logToFile("addspottodataset took: ".$totalspottime." secs","DATASET SPOT TIME");

					}

					$this->strabo->logToFile("Start building relationships...");
					$spotstarttime=microtime(true);

					//now build all relationships for project
					$this->strabo->buildDatasetRelationships($datasetId);

					$totalspottime = microtime(true)-$spotstarttime;
					$this->strabo->logToFile("Relationships done in $totalspottime seconds ...");

				}else{

					// bad body sent, error
					header("Bad Request", true, 400);
					$data["Error"] = "Invalid body JSON sent.";

				}

			}else{
				//Error, feature not found
				header("Bad Request", true, 404);
				$data["Error"] = "Dataset $datasetId not found.";
			}

			// Restore original userpkey if changed
			if ($context !== null && $context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($originalUserpkey);
			}

		} else { //feature id is not set error

			//Error, feature not found
			header("Bad Request", true, 404);
			$data["Error"] = "No dataset ID provided.";

		}

		return $data;
	}

	public function deleteAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

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
