<?php
/**
 * File: ProjectDatasetDeleteSpotController.php
 * Description: ProjectDatasetDeleteSpotController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectDatasetDeleteSpotController extends MyController
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
			if($upload['datasets']!=""){
				$datasets=$upload['datasets'];
				if(count($datasets) > 0){
					if($upload['spotId']!="" && $upload['spotId'] != null){
						$spotid=$upload['spotId'];
					}else{
						$errors.="No Spot ID Provided. ";
					}
				}else{
					$errors.="No Datasets Provided. ";
				}
			}
		}else{
			$errors.="No Project ID Provided. ";
		}

		$datasets=$upload['datasets'];
		$spotid=$upload['spotId'];


		// Use CollaborationAuth to check project access (new authorization model)
		$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectid);

		if (!$context->canRead()) {
			return $this->notFound("Project not found");
		}

		$originaluserpkey = $this->strabo->userpkey;

		// Determine owner for side-effect functions
		$ownerPkey = $context->effectiveOwner;

		// Build list of datasets user is authorized to edit
		$authorizeddatasets = [];

		foreach($upload['datasets'] as $d){
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
			}
		}

		// If user has no edit permissions on any dataset, deny
		if (count($authorizeddatasets) == 0 && count($upload['datasets']) > 0) {
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

			$injson = json_encode($upload['project']);
			// Determine if this is a collaborative edit (user is collaborator, not owner)
			$isCollaborativeEdit = ($context->permissionLevel === 'edit' && !$context->isOwner);
			$this->strabo->insertProject($injson, null, $isCollaborativeEdit, $ownerPkey);

			foreach($datasets as $d){
				$datasetid = $d->id;



				if(in_array($datasetid, $authorizeddatasets)){


					if($datasetid!=""){
						//first, delete dataset relationships
						//$this->strabo->deleteDatasetRelationships($datasetid);
						$injson = json_encode($d);


						$this->strabo->setuserpkey((int)$originaluserpkey);
						// Pass originaluserpkey for created_by tracking
						$this->strabo->insertDataset($injson, null, $originaluserpkey);
						$this->strabo->setuserpkey((int)$originaluserpkey);
						$this->strabo->addDatasetToProject($projectid, $datasetid, "HAS_DATASET", $ownerPkey, $originaluserpkey);
	
						if($spotid!=""){
	
							$this->strabo->setuserpkey((int)$newuserpkey);
							$this->strabo->deleteSingleSpot($spotid,$newuserpkey);
	
						}
					}
	
					if($datasetid!=""){

						$this->strabo->setuserpkey((int)$newuserpkey); // Kept for other strabo methods
						//$this->strabo->buildDatasetRelationships($datasetid);
						$this->strabo->setDatasetCenter($datasetid, $ownerPkey);

						//also add dataset to Postgres Database here.
						$this->strabo->buildPgDataset($datasetid, $ownerPkey);
					}



				}




			}

			$this->strabo->setProjectCenter($projectid, $ownerPkey);

			$data = $upload;
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
