<?php
/**
 * File: ProjectDeleteDatasetController.php
 * Description: ProjectDeleteDatasetController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectDeleteDatasetController extends MyController
{

	public function getAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function postAction($request) {

		$upload = $request->parameters;
		unset($upload['apiformat']);
		$errors = "";

		$projectid = $upload['project']->id;
		if($projectid != ""){
			if($upload['datasetId']!=""){
				$datasetid=$upload['datasetId'];
			}else{
				$errors.="No Dataset ID Provided. ";
			}
		}else{
			$errors.="No Project ID Provided. ";
		}

		$datasetid=$upload['datasetId'];
		$projectid=$upload['project']->id;

		if(!$errors){

			// Check project authorization
			$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectid);

			// Get dataset creator to check edit permission
			$datasetContext = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetid);
			$datasetCreatedBy = $datasetContext ? $datasetContext->datasetCreatedBy : $this->strabo->userpkey;

			// Check if user can delete this dataset
			if (!$this->auth->canEditDataset($context, $datasetCreatedBy)) {
				if ($context->permissionLevel === 'none') {
					return $this->notFound("Project not found");
				}
				return $this->forbidden("You don't have permission to delete this dataset");
			}

			// Use effectiveOwner for operations
			$userpkey = $context->effectiveOwner;
			$originalUserpkey = $this->strabo->userpkey;
			if ($userpkey !== $originalUserpkey) {
				$this->strabo->setuserpkey($userpkey);
			}

			$dcount = $this->strabo->neodb->get_var("match (p:Project)-[HAS_DATASET]->(d:Dataset) where p.id = $projectid and d.id = $datasetid return count(d)");

			$pcount = $this->strabo->db->get_var_prepared("SELECT count(*) FROM vprojects WHERE userpkey=$1 AND projectid=$2", array($userpkey, $projectid));
			if($pcount > 0){
				$this->strabo->createVersion($projectid);
				$this->strabo->db->prepare_query("DELETE FROM vprojects WHERE userpkey=$1 AND projectid=$2", array($userpkey, $projectid));
			}

			$injson = json_encode($upload['project']);
			// Determine if this is a collaborative edit (user is collaborator, not owner)
			$isCollaborativeEdit = ($context->permissionLevel === 'edit' && !$context->isOwner());
			$this->strabo->insertProject($injson, null, $isCollaborativeEdit, $userpkey);

			//Delete Dataset
			if($dcount > 0) $this->strabo->deleteSingleDataset($datasetid);

			$this->strabo->setProjectCenter($projectid, $userpkey);

			// Restore original userpkey if changed
			if ($userpkey !== $originalUserpkey) {
				$this->strabo->setuserpkey($originalUserpkey);
			}

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
