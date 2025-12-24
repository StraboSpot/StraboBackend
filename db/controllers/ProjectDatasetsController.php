<?php
/**
 * File: ProjectDatasetsController.php
 * Description: ProjectDatasetsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectDatasetsController extends MyController
{

	public function getAction($request) {

		if(isset($request->url_elements[2])) {

			$projectId = $request->url_elements[2];

			// Check if user has read access to this project
			$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectId);

			if (!$context->canRead()) {
				return $this->notFound("Project not found");
			}

			// Use effectiveOwner to get datasets from the correct owner's project
			$originalUserpkey = $this->strabo->userpkey;
			if ($context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($context->effectiveOwner);
			}

			$data = $this->strabo->getProjectDatasets($projectId);

			// Restore original userpkey if changed
			if ($context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($originalUserpkey);
			}

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Bad Request. No Project id specified.";
		}
		return $data;
	}

	public function postAction($request) {

		if(isset($request->url_elements[2])) {

			$projectId = $request->url_elements[2];

			// Check if user can add datasets to this project
			$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectId);

			if (!$this->auth->canCreateDataset($context)) {
				if ($context->permissionLevel === 'none') {
					return $this->notFound("Project not found");
				}
				return $this->forbidden("You don't have permission to add datasets to this project");
			}

			$upload = $request->parameters;

			unset($upload['apiformat']);

			$datasetid=$upload['id'];

			if($datasetid == ""){

				//Error, feature not found
				header("Bad Request", true, 404);
				$data["Error"] = "No dataset ID provided.";

			}else{
				// Use effectiveOwner for the operation
				$originalUserpkey = $this->strabo->userpkey;
				if ($context->effectiveOwner !== $originalUserpkey) {
					$this->strabo->setuserpkey($context->effectiveOwner);
				}

				$data = $this->strabo->addDatasetToProject($projectId,$datasetid,"HAS_DATASET");

				// Restore original userpkey if changed
				if ($context->effectiveOwner !== $originalUserpkey) {
					$this->strabo->setuserpkey($originalUserpkey);
				}
			}

		} else { //feature id is not set error

			//Error, feature not found
			header("Bad Request", true, 404);
			$data["Error"] = "No project ID provided.";

		}

		return $data;
	}

	public function deleteAction($request) {

		if(isset($request->url_elements[2])) {

			$projectId = $request->url_elements[2];

			// Only owner can delete all datasets from a project
			$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectId);

			if (!$context->isOwner()) {
				if ($context->permissionLevel === 'none') {
					return $this->notFound("Project not found");
				}
				return $this->forbidden("Only the project owner can delete all datasets");
			}

			$this->strabo->deleteProjectDatasets($projectId);

			header("Datasets deleted", true, 204);
			$data['message']="Datasets deleted.";

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Bad Request.";
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
