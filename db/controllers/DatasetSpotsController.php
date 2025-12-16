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

		if(isset($request->url_elements[2])) {

			//*******************************************************************************
			//update attributes for feature


			
			$feature_id = $request->url_elements[2];
			$datasetid = $feature_id;
			
			if($datasetid == ""){
				$this->strabo->throwJSONError("No dataset id provided.");
			}

			//first look natively.
			if($datasetid!=""){
				$dataset = $this->strabo->getDataset($datasetid);
			}
	
			//We didn't find a dataset with userpkey that matches userpkey, so let's look for a dataset that matches collaboratorpkey = userpkey
			if($dataset->id == ""){ 
				$dataset = $this->strabo->getDataset($datasetid, $this->strabo->userpkey);
			}
	
			if($dataset->id == ""){
				$this->strabo->throwJSONError("Dataset not found.");
			}

			$projectid = $this->strabo->neodb->get_var("match (p:Project)-[HAS_DATASET]->(d:Dataset) where d.id = $feature_id and d.userpkey = ".$this->strabo->userpkey." OR d.collaboratorpkey = ".$this->strabo->userpkey." return p.id");
			if($projectid != ""){
				$collabinfo = $this->strabo->getCollabInfo($projectid);
			}
		
			//$this->dumpVar($collabinfo);exit();
			//$this->dumpVar($dataset);exit();
			/*
			stdClass Object
			(
				[isOwner] => 1
				[neoid] => 3770198
				[isCollaborativeProject] => 1
				[isUserCollaborator] => 
				[collaborationLevel] => none
				[isHalted] => 
			)
			
			stdClass Object
			(
				[date] => 2025-12-02T17:36:05.157Z
				[userpkey] => 8988
				[name] => Default
				[datecreated] => 1764944241
				[id] => 17646969651573
				[collaboratorpkey] => 8988
				[modified_timestamp] => 1764943395720
				[datasettype] => app
				[neoid] => 3770197
			)
			*/
			
			//$this->dumpVar($collabinfo);exit();
			




//$this->dumpVar($collabinfo);exit();
/*
stdClass Object
(
    [isOwner] => 
    [isCollaborativeProject] => 1
    [ownerpkey] => 8988
    [isUserCollaborator] => 1
    [collaborationLevel] => edit
    [neoid] => 3770309
    [isHalted] => 
)
*/


//$this->dumpVar($dataset);exit();

/*
stdClass Object
(
    [date] => 2025-12-02T17:36:05.157Z
    [userpkey] => 8988
    [name] => Default2
    [datecreated] => 1765317851
    [id] => 17646969651444
    [collaboratorpkey] => 3
    [modified_timestamp] => 1764943395720
    [datasettype] => app
    [neoid] => 3770318
)
*/

			if($collabinfo->isUserCollaborator && $collabinfo->collaborationLevel == "edit" && $dataset->collaboratorpkey == $this->strabo->userpkey && !$collabinfo->isHalted){
				//echo "is collaborator with edit and dataset";
				$this->strabo->setuserpkey((int)$collabinfo->ownerpkey);
			}elseif($collabinfo->isOwner && $dataset->userpkey == $this->strabo->userpkey){
				//echo "is owner with dataset";
				//pkey can remain unchanged
			}elseif($collabinfo->isOwner && $collabinfo->isHalted){
				//echo "is owner and project halted link project to dataset ";
				//pkey can remain unchanged
			}else{
				//Error, don't have permissions
				header("Don't have permission to collaborate on this", true, 403);
				$data["Error"] = "Don't have permission to collaborate on this";
				return $data;
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



						foreach($features as $feature){

							$spotid = $feature->properties->id;

							$this->strabo->deleteSingleSpot($spotid);

							if(!$this->strabo->spotExistsInOtherDataset($spotid,$feature_id)){

								$spotstarttime = microtime(true);

								$injson = json_encode($feature,JSON_PRETTY_PRINT);

								$thisdata = $this->strabo->insertSpot($injson);

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

						$this->strabo->setDatasetCenter($feature_id);

						$projectid = $this->strabo->getProjectId($feature_id);
						$this->strabo->setProjectCenter($projectid);

						$totalspottime = microtime(true)-$spotstarttime;
						//$this->strabo->logToFile("Relationships done in $totalspottime seconds ...");

						//also add dataset to Postgres Database here.
						$this->strabo->buildPgDataset($feature_id); //need to re-implement JMA 02282020
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
