<?php
/**
 * File: UpdateDatasetSpotsController.php
 * Description: UpdateDatasetSpotsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class UpdateDatasetSpotsController extends MyController
{

	public function getAction($request) {

		if(isset($request->url_elements[2])) {

			$datasetId = $request->url_elements[2];

			// Check if user has read access to this dataset's project
			$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

			if ($context !== null && !$context->canRead()) {
				return $this->notFound("Dataset not found");
			}

			// Use effectiveOwner for the query
			$originalUserpkey = $this->strabo->userpkey;
			if ($context !== null && $context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($context->effectiveOwner);
			}

			$data = $this->strabo->getDatasetSpots($datasetId);

			// Restore original userpkey if changed
			if ($context !== null && $context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($originalUserpkey);
			}

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Bad Request. No Dataset id specified.";
		}
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
					return $this->forbidden("You don't have permission to update spots in this dataset");
				}
			}

			// Use effectiveOwner for the operation
			$originalUserpkey = $this->strabo->userpkey;
			$effectiveUserpkey = $context !== null ? $context->effectiveOwner : $this->strabo->userpkey;
			if ($effectiveUserpkey !== $originalUserpkey) {
				$this->strabo->setuserpkey($effectiveUserpkey);
			}

			//********************************************************************
			// check for Dataset with userid and id
			//********************************************************************
			if($this->strabo->findDataset($datasetId)){

				$upload = $request->parameters;

				unset($upload['apiformat']);

				$straboid=$upload['id'];

				$features = $upload['features'];

				if($straboid!=""){

					//OK, check to see if feature exists
					if($this->strabo->findSpot($straboid)){

						//see if it already exists in dataset
						if(!$this->strabo->findSpotInDataset($datasetId,$straboid)){

							//********************************************************************
							// Add to group
							//********************************************************************
							$this->strabo->addSpotToDataset($datasetId,$straboid);

							header("Spot added to dataset", true, 201);
							$data['message']="Spot $straboid added to dataset $datasetId.";

						}else{

							//Error, feature not found
							header("Spot $straboid already exists in dataset $datasetId.", true, 200);
							$data["Error"] = "Spot $straboid already exists in dataset $datasetId.";

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

						if($this->strabo->spotExistsInOtherDataset($spotid,$datasetId)){

							//also get name
							$spotname = $this->strabo->getSpotName($spotid);

							header("Bad Request", true, 400);
							$data["Error"] = "Spot(s) already exist in another dataset: $spotname ($spotid).";
							break;
						}

					}
					*/

					if($data["Error"]==""){

						//delete relationships
						$this->strabo->deleteDatasetReltationships($datasetId);

						$data['type']="FeatureCollection";

						//this turns pixel coordinates into real-world coordinates so we can do spatial searches
						$features=$this->strabo->fixIncomingBasemaps($features);

						foreach($features as $feature){

							$spotid = $feature->properties->id;

							if(!$this->strabo->spotExistsInOtherDataset($spotid,$datasetId)){

								$spotstarttime = microtime(true);

								$injson = json_encode($feature,JSON_PRETTY_PRINT);

								$thisdata = $this->strabo->insertSpot($injson, null, "", $originalUserpkey);

								$parts = $thisdata->properties->self;

								$parts = explode("/",$parts);
								$straboid = end($parts);

								if(!$this->strabo->findSpotInDataset($datasetId,$straboid)){

									$this->strabo->addSpotToDataset($datasetId,$straboid);

									$totalspottime = microtime(true)-$spotstarttime; $this->strabo->logToFile("addspottodataset took: ".$totalspottime." secs","DATASET SPOT TIME");

								}

								$incomingspots[]=$feature->properties->id;

								$data['features'][]=$thisdata;

							}

						}

						// Log upload stats (use original user for tracking)
						$spotcount = count($features);
						$this->strabo->db->query("
							insert into up_down_stats
								(
									userpkey,
									upload_download,
									data_type,
									count_type,
									count
								) values (
									$originalUserpkey,
									'upload',
									'field app data',
									'spot',
									$spotcount
								)
						");

						//now look on server to see if any spots need to be deleted


						$this->strabo->logToFile("Start building relationships...");
						$spotstarttime=microtime(true);

						//now build all relationships for project
						$this->strabo->buildDatasetRelationships($datasetId);

						$this->strabo->setDatasetCenter($datasetId, $effectiveUserpkey);

						$projectid = $this->strabo->getProjectId($datasetId);
						$this->strabo->setProjectCenter($projectid, $effectiveUserpkey);

						$totalspottime = microtime(true)-$spotstarttime;
						$this->strabo->logToFile("Relationships done in $totalspottime seconds ...");

						//also add dataset to Postgres Database here.
						$this->strabo->buildPgDataset($datasetId, $effectiveUserpkey);
					}

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
			if ($effectiveUserpkey !== $originalUserpkey) {
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
