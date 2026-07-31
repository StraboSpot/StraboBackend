<?php
/**
 * File: DatasetSpotsController.php
 * Description: DatasetSpotsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class DatasetSpotsController extends MyController
{

	public function getAction($request) {

		if(isset($request->url_elements[2])) {

			$feature_id = $request->url_elements[2];

			$data = $this->strabo->getDatasetSpots($feature_id);

			//Log download here
			$spotcount = count($data['features']);
			$userpkey = $this->strabo->userpkey;
			$this->strabo->db->query("
				insert into up_down_stats
					(
						userpkey,
						upload_download,
						data_type,
						count_type,
						count
					) values (
						$userpkey,
						'download',
						'field app data',
						'spot',
						$spotcount
					)
			");

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Bad Request. No Dataset id specified.";
		}
		return $data;
	}

	public function postAction($request) {

		// Large dataset uploads can exceed default PHP CPU-time limits and may
		// outlast a flaky client connection. Disable the script timeout and keep
		// running on client disconnect so partial inserts don't get stranded.
		set_time_limit(0);
		ignore_user_abort(true);

		if(isset($request->url_elements[2])) {

			//*******************************************************************************
			//update attributes for feature



			$feature_id = $request->url_elements[2];
			$datasetid = $feature_id;

			if($datasetid == ""){
				$this->strabo->throwJSONError("No dataset id provided.");
			}

			// Use CollaborationAuth to check dataset access (new authorization model)
			$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetid);

			if (!$context) {
				return $this->notFound("Dataset not found.");
			}

			// Check if user can edit this dataset
			// Uses created_by field to determine who can edit (new model)
			if (!$this->auth->canEditDataset($context, $context->datasetCreatedBy)) {
				return $this->forbidden("You don't have permission to edit this dataset: $datasetid");
			}

			// Save original uploader for image ownership tracking (before any userpkey swap)
			$originalUploader = $this->strabo->userpkey;

			// Determine owner for side-effect functions
			$ownerPkey = $context->effectiveOwner;

			// Set effective owner for strabo methods that still need it
			if ($context->effectiveOwner !== $this->strabo->userpkey) {
				$this->strabo->setuserpkey($context->effectiveOwner);
			}



//exit();



			//********************************************************************
			// check for Dataset with userid and id
			//********************************************************************
			if($this->strabo->findDataset($feature_id)){


				
				$upload = $request->parameters;

				unset($upload['apiformat']);

				$straboid=$upload['id'];

				$features = $upload['features'];

				if($straboid!=""){

					//OK, check to see if feature exists
					if($this->strabo->findSpot($straboid)){

						//see if it already exists in dataset
						if(!$this->strabo->findSpotInDataset($feature_id,$straboid)){

							//********************************************************************
							// Add to group
							//********************************************************************
							$this->strabo->addSpotToDataset($feature_id,$straboid);

							header("Spot added to dataset", true, 201);
							$data['message']="Spot $straboid added to dataset $feature_id.";

						}else{

							//Error, feature not found
							header("Spot $straboid already exists in dataset $feature_id.", true, 200);
							$data["Error"] = "Spot $straboid already exists in dataset $feature_id.";

						}

					}else{

						//Error, feature not found
						header("Bad Request", true, 404);
						$data["Error"] = "Spot $straboid not found.";

					}

				}elseif(count($features)>0){

					//********************************************************************
					// Load the feature collection and add it to dataset
					//********************************************************************

					//check to see if any spots belong to different dataset
					/*
					foreach($features as $feature){

						$spotid = $feature->properties->id;

						if($this->strabo->spotExistsInOtherDataset($spotid,$feature_id)){

							//also get name
							$spotname = $this->strabo->getSpotName($spotid);

							header("Bad Request", true, 400);
							$data["Error"] = "Spot(s) already exist in another dataset: $spotname ($spotid).";
							break;
						}

					}
					*/

					if($data["Error"]==""){

						
						
						//Gather features and store image ids and filenames
						$userpkey = $this->strabo->userpkey;
						$imagefilenames = $this->strabo->getImageFiles($feature_id);
						
						//$this->dumpVar($imagefilenames);exit();

						//delete relationships
						//$this->strabo->deleteDatasetReltationships($feature_id);

						

						$data['type']="FeatureCollection";

						//this turns pixel coordinates into real-world coordinates so we can do spatial searches
						$features=$this->strabo->fixIncomingBasemaps($features);

						// Resolve project context for the strabosamples mirror hook
						// (samples/field-integration). Looked up once per request.
						$projectStraboIdForSync = $this->strabo->getProjectId($feature_id);
						$this->strabo->setSampleSyncContext($projectStraboIdForSync, $feature_id);

						foreach($features as $feature){

							$spotid = $feature->properties->id;

							$this->strabo->deleteSingleSpot($spotid);

							if(!$this->strabo->spotExistsInOtherDataset($spotid,$feature_id)){

								$spotstarttime = microtime(true);

								$injson = json_encode($feature,JSON_PRETTY_PRINT);

								$thisdata = $this->strabo->insertSpot($injson, null, "", $originalUploader);

								$parts = $thisdata->properties->self;

								$parts = explode("/",$parts);
								$straboid = end($parts);

								if(!$this->strabo->findSpotInDataset($feature_id,$straboid)){



									$this->strabo->addSpotToDataset($feature_id,$straboid);

									//$totalspottime = microtime(true)-$spotstarttime; $this->strabo->logToFile("addspottodataset took: ".$totalspottime." secs","DATASET SPOT TIME");

								}

								$incomingspots[]=$feature->properties->id;

								$data['features'][]=$thisdata;

							}

						}
						$this->strabo->clearSampleSyncContext();

						//Roll through imagefilenames and update images with filenames
						$this->strabo->fixImageFileNames($imagefilenames);

						$spotcount = count($features);
						$userpkey = $this->strabo->userpkey;
						$this->strabo->db->query("
							insert into up_down_stats
								(
									userpkey,
									upload_download,
									data_type,
									count_type,
									count
								) values (
									$userpkey,
									'upload',
									'field app data',
									'spot',
									$spotcount
								)
						");

						//now look on server to see if any spots need to be deleted
						$serverspots = $this->strabo->getDatasetSpotIds($feature_id);
						foreach($serverspots as $ss){
							if(!in_array($ss,$incomingspots)){
								$this->strabo->deleteSingleSpot($ss);
							}
						}

						//$this->strabo->logToFile("Start building relationships...");
						$spotstarttime=microtime(true);

						//now build all relationships for project
						//$this->strabo->buildDatasetRelationships($feature_id);

						$this->strabo->setDatasetCenter($feature_id, $ownerPkey);

						$projectid = $this->strabo->getProjectId($feature_id);
						$this->strabo->setProjectCenter($projectid, $ownerPkey);

						$totalspottime = microtime(true)-$spotstarttime;
						//$this->strabo->logToFile("Relationships done in $totalspottime seconds ...");

						//also add dataset to Postgres Database here.
						$this->strabo->buildPgDataset($feature_id, $ownerPkey);
					}

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

		if(isset($request->url_elements[2])) {

			$feature_id = $request->url_elements[2];

			$this->strabo->deleteDatasetSpots($feature_id);

			header("Spots deleted", true, 204);
			$data['message']="Spots deleted.";

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Bad Request.";
		}
		return $data;

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
