<?php

/*
******************************************************************
StraboSpot REST API
Dataset Spots Controller
Author: Jason Ash (jasonash@ku.edu)
Description: This controller adds/gets single spot from a given dataset.
******************************************************************
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

			$feature_id = $request->url_elements[2];
			$datasetid = $feature_id;

			if($datasetid == ""){
				$this->strabo->throwJSONError("No dataset id provided.");
			}

			// Use CollaborationAuth to check dataset access
			$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetid);

			if (!$context) {
				return $this->notFound("Dataset not found.");
			}

			// Check if user can edit this dataset
			if (!$this->auth->canEditDataset($context, $context->datasetCreatedBy)) {
				return $this->forbidden("You don't have permission to edit this dataset");
			}

			// Save original uploader for image ownership tracking
			$originalUploader = $this->strabo->userpkey;

			// Determine owner for side-effect functions
			$ownerPkey = $context->effectiveOwner;

			// Set effective owner for strabo methods that still need it
			if ($context->effectiveOwner !== $this->strabo->userpkey) {
				$this->strabo->setuserpkey($context->effectiveOwner);
			}

			//********************************************************************
			// check for Dataset with userid and id
			//********************************************************************
			if($this->strabo->findDataset($feature_id)){

				$upload = $request->parameters;

				unset($upload['apiformat']);

				$straboid=$upload['properties']->id;

				if($straboid!=""){

					//********************************************************************
					// Load the spot and add it to dataset
					//********************************************************************

					//delete relationships
					//$this->strabo->deleteDatasetReltationships($feature_id);

					//fix single spot basemap coords

					$injson = json_encode($upload,JSON_PRETTY_PRINT);

					$projectStraboIdForSync = $this->strabo->getProjectId($feature_id);
					$this->strabo->setSampleSyncContext($projectStraboIdForSync, $feature_id);
					$thisdata = $this->strabo->insertSpot($injson, null, "", $originalUploader);
					$this->strabo->clearSampleSyncContext();

					$parts = $thisdata->properties->self;

					$parts = explode("/",$parts);
					$straboid = end($parts);

					if(!$this->strabo->findSpotInDataset($feature_id,$straboid)){

						$this->strabo->addSpotToDataset($feature_id,$straboid);

						$totalspottime = microtime(true)-$spotstarttime; $this->strabo->logToFile("addspottodataset took: ".$totalspottime." secs","DATASET SPOT TIME");

					}




					$this->strabo->logToFile("Start building relationships...");
					$spotstarttime=microtime(true);

					//now build all relationships for project
					//$this->strabo->buildDatasetRelationships($feature_id);

					$this->strabo->setDatasetCenter($feature_id, $ownerPkey);

					$projectid = $this->strabo->getProjectId($feature_id);
					$this->strabo->setProjectCenter($projectid, $ownerPkey);

					$totalspottime = microtime(true)-$spotstarttime;
					$this->strabo->logToFile("Relationships done in $totalspottime seconds ...");

					//also add dataset to Postgres Database here.
					$this->strabo->buildPgDataset($feature_id, $ownerPkey);


					$data = $upload;
	
				}else{
				
					// bad body sent, error
					header("Bad Request", true, 400);
					$data["Error"] = "Invalid body JSON sent.";
				
				}

			}else{
				//Error, feature not found
				header("Bad Request", true, 404);
				$data["Error"] = "Dataset $feature_id not found.";
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
    }


    public function putAction($request) {
    	
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

        return $data;
    }

    public function optionsAction($request) {
    	
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

        return $data;
    }

    public function patchAction($request) {
    	
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

        return $data;
    }

    public function copyAction($request) {
    	
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

        return $data;
    }

    public function searchAction($request) {
    	
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

        return $data;
    }


}
