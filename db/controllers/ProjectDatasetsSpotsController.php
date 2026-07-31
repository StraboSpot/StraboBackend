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


		// Use CollaborationAuth to check project access (new authorization model)
		$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectid);

		if (!$context->canRead()) {
			return $this->notFound("Project not found");
		}

		// Save original uploader for image ownership tracking (before any swaps)
		$originalUploader = $this->strabo->userpkey;

		// Determine owner for side-effect functions
		$ownerPkey = $context->effectiveOwner;

		// Build list of datasets user is authorized to edit
		$authorizeddatasets = [];

		foreach($upload['project']->datasets as $d){
			$datasetid = $d->id;

			if($datasetid == ""){
				continue;
			}

			// Get dataset context to check created_by
			$datasetContext = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetid);

			if ($datasetContext) {
				// Check if user can edit this dataset using new authorization model
				if ($this->auth->canEditDataset($datasetContext, $datasetContext->datasetCreatedBy)) {
					$authorizeddatasets[] = $datasetid;
				}
			} else {
				// New dataset being created - check if user can create datasets in this project
				if ($this->auth->canCreateDataset($context)) {
					$authorizeddatasets[] = $datasetid;
				}
			}
		}

		// If user has no edit permissions on any dataset, deny
		if (count($authorizeddatasets) == 0 && count($upload['project']->datasets) > 0) {
			return $this->forbidden("You don't have permission to edit any datasets in this project");
		}

		// Set effective owner for strabo methods that still need it
		if ($context->effectiveOwner !== $this->strabo->userpkey) {
			$this->strabo->setuserpkey($context->effectiveOwner);
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

			// Determine if this is a collaborative edit (user is collaborator, not owner)
			$isCollaborativeEdit = ($context->permissionLevel === 'edit' && !$context->isOwner());
			$this->strabo->insertProject($injson, null, $isCollaborativeEdit, $ownerPkey, $originalUploader);

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

							$this->strabo->setuserpkey((int)$userpkey);

							// Pass originalUploader for created_by tracking
							$this->strabo->insertDataset($injson, null, $originalUploader);

							$this->strabo->setuserpkey((int)$userpkey);
							$this->strabo->addDatasetToProject($projectid, $datasetid, "HAS_DATASET", $ownerPkey, $originalUploader);
	
							//Check if this user is able to edit this dataset
							
							//if(user is able to edit) {
							
								if($spots->features != ""){

									$this->strabo->setuserpkey((int)$ownerPkey);
									$this->strabo->setSampleSyncContext($projectid, $datasetid);

									foreach($spots->features as $spot){

										$spotid = $spot->properties->id;

										if($spotid!=""){

											$injson = json_encode($spot, JSON_PRETTY_PRINT);
											$this->strabo->insertSpot($injson,null,$ownerPkey,$originalUploader);
											$this->strabo->addSpotToDataset($datasetid,$spotid,"HAS_SPOT");

										}

									}
									$this->strabo->clearSampleSyncContext();
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
							$this->strabo->setDatasetCenter($datasetid, $ownerPkey);

							//also add dataset to Postgres Database here.
						}
					}

				}

			}

			$this->strabo->setProjectCenter($projectid, $ownerPkey);

			if($datasetid!=""){
				$this->strabo->setDatasetCenter($datasetid, $ownerPkey);
				$this->strabo->buildPgDataset($datasetid, $ownerPkey);
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
