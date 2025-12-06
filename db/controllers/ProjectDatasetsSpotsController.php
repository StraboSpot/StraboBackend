<?php
/**
 * File: ProjectDatasetsSpotsController.php
 * Description: ProjectDatasetsSpotsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectDatasetsSpotsController extends MyController
{

	public function getAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function postAction($request) {

		$upload = $request->parameters;
		unset($upload['apiformat']);

		$projectid = $upload['project']->id;
		if($projectid != ""){
			
		}else{
			$errors.="No Project ID Provided. ";
		}

		$showout = json_decode(json_encode($upload));




		$collabinfo = $this->strabo->getCollabInfo($projectid);

		$newuserpkey = $this->strabo->userpkey;

		//Keep track of collaborative pkeys for dataset? No, the inside code will do that. Just keep track of datasets that we are allowed to edit?
		$authorizeddatasets = [];
		
		//?? Strip out datasets we're not allowed to edit, and if we're allowed to collaborate, we pass them through, and change userpkey? 
		if($collabinfo->isCollaborativeProject){
			
			if($collabinfo->isOwner || ($collabinfo->isUserCollaborator && $collabinfo->collaborationLevel == "edit" && !$collabinfo->isHalted)){
			
				foreach($upload['project']->datasets as $d){
					$datasetid = $d->id;
					//first look natively.
					if($datasetid!=""){
						$dataset = $this->strabo->getDataset($datasetid);
					}
			
					//We didn't find a dataset with userpkey that matches userpkey, so let's look for a dataset that matches collaboratorpkey = userpkey
					if($dataset->id == ""){ 
						$dataset = $this->strabo->getDataset($datasetid, $this->strabo->userpkey);
					}

					if($dataset->id == ""){
						$this->strabo->throwJSONError("Dataset $datasetid not found.");
					}
					
					if($collabinfo->isUserCollaborator && $collabinfo->collaborationLevel == "edit" && $dataset->collaboratorpkey == $this->strabo->userpkey && !$collabinfo->isHalted){
						//echo "is collaborator with edit and dataset";
						$newuserpkey = $collabinfo->ownerpkey;
						$authorizeddatasets[] = $dataset->id;
					}elseif($collabinfo->isOwner && $dinfo->userpkey = $this->strabo->userpkey){
						//echo "is owner with dataset";
						//pkey can remain unchanged
						$authorizeddatasets[] = $dataset->id;
					}elseif($collabinfo->isOwner && $collabinfo->isHalted){
						//echo "is owner and project halted link project to dataset ";
						//pkey can remain unchanged
						$authorizeddatasets[] = $dataset->id;
					}
					
				}

			}else{
				$this->strabo->throwJSONError("Don't have permission to collaborate on this");
			}
		}else{
			//load all datasetids if not collaborative
			foreach($upload['project']->datasets as $d){
				$authorizeddatasets[] = $d->id;
			}
		}




		if(!$errors){

			$userpkey = $this->strabo->userpkey;
			$pcount = $this->strabo->db->get_var_prepared("SELECT count(*) FROM vprojects WHERE userpkey=$1 AND projectid=$2", array($userpkey, $projectid));
			if($pcount > 0){
				$this->strabo->createVersion($projectid);
				$this->strabo->db->prepare_query("DELETE FROM vprojects WHERE userpkey=$1 AND projectid=$2", array($userpkey, $projectid));
			}

			$datasets = $upload['project']->datasets;

			unset($upload['project']->datasets);
			$injson = json_encode($upload['project'], JSON_PRETTY_PRINT);

			$this->strabo->insertProject($injson);

			if($datasets != ""){
				foreach($datasets as $dataset){

					$datasetid = $dataset->id;

					if(in_array($datasetid, $authorizeddatasets)){
					
						$spots = $dataset->spots;
	
						unset($dataset->spots);
	
						if($datasetid!=""){
							//first, delete dataset relationships
							//$this->strabo->deleteDatasetRelationships($datasetid);
							$injson = json_encode($dataset, JSON_PRETTY_PRINT);
							$this->strabo->insertDataset($injson);
							$this->strabo->addDatasetToProject($projectid,$datasetid,"HAS_DATASET");
	
							//Check if this user is able to edit this dataset
							
							//if(user is able to edit) {
							
								if($spots->features != ""){
		
									foreach($spots->features as $spot){
		
										$spotid = $spot->properties->id;
		
										if($spotid!=""){
		
											$injson = json_encode($spot, JSON_PRETTY_PRINT);
											$this->strabo->insertSpot($injson,null,$newuserpkey);
											$this->strabo->addSpotToDataset($datasetid,$spotid,"HAS_SPOT");
		
										}
		
									}
								}
							
							//}
	
							$spotcount = count($spots->features);
							$userpkey = $this->strabo->userpkey;
							$this->strabo->db->prepare_query("
								INSERT INTO up_down_stats
									(
										userpkey,
										upload_download,
										data_type,
										count_type,
										count
									) VALUES (
										$1,
										$2,
										$3,
										$4,
										$5
									)
							", array($userpkey, 'upload', 'field app data', 'spot', $spotcount));
	
						}
	
						if($datasetid!=""){
							//$this->strabo->buildDatasetRelationships($datasetid);
							$this->strabo->setDatasetCenter($datasetid);
	
							//also add dataset to Postgres Database here.
						}
					}

				}

			}

			$this->strabo->setProjectCenter($projectid);

			if($datasetid!=""){
				$this->strabo->setDatasetCenter($datasetid);
				$this->strabo->buildPgDataset($datasetid);
			}

			$data = $showout;
		}else{
			header("Bad Request", true, 400);
			$data["Error"] = $errors;
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
