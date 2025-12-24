<?php
/**
 * File: ProjectController.php
 * Description: ProjectController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectController extends MyController
{
	public function getAction($request) {

		if(isset($request->url_elements[2])) {
			$projectId = $request->url_elements[2];

			if(isset($request->url_elements[3])) {
				// do nothing, this is not a supported action
				header("Bad Request", true, 400);
				$data["Error"] = $request->url_elements[3]." not supported.";

			} else {

				// Check if user has read access to this project
				$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectId);

				if (!$context->canRead()) {
					return $this->notFound("Project not found");
				}

				// Use effectiveOwner to get project from the correct owner's data
				$originalUserpkey = $this->strabo->userpkey;
				if ($context->effectiveOwner !== $originalUserpkey) {
					$this->strabo->setuserpkey($context->effectiveOwner);
				}

				$data = $this->strabo->getProject($projectId);

				// Restore original userpkey if changed
				if ($context->effectiveOwner !== $originalUserpkey) {
					$this->strabo->setuserpkey($originalUserpkey);
				}

				if($data->Error!=""){
					header("Project not Found", true, 404);
				}
			}
		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Project ID must be provided";
		}
		return $data;
	}

	public function deleteAction($request) {

		if(isset($request->url_elements[2])) {

			//********************************************************************
			// check for feature with userid and id; delete if exists
			//********************************************************************

			$projectId = (int)$request->url_elements[2];

			// Check if user is owner of this project (only owner can delete)
			$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectId);

			if (!$context->isOwner()) {
				// Return 404 to hide existence of project from non-owners
				return $this->notFound("Project $projectId not found.");
			}

			if($this->strabo->findProject($projectId)){

				$this->strabo->deleteProject($projectId);

				header("Project deleted", true, 204);
				$data['message']="Project $projectId deleted.";

			}else{
				//Error, feature not found
				header("Bad Request", true, 404);
				$data["Error"] = "Project $projectId not found.";
			}

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Dataset ID must be provided";
		}
		return $data;
	}

	public function postAction($request) {

		$thisid = $request->url_elements[2];

		$upload = $request->parameters;

		unset($upload['apiformat']);

		$projectname = $upload['description']->project_name;
		$projectid = $upload['id'];

		if($projectname==""){

			// bad body sent, error
			header("Bad Request", true, 400);
			$data["Error"] = "Invalid body JSON sent.";

		}else{

			// Check project authorization
			// Note: insertProject() handles both create and update
			// For updates, check if user has permission (owner or edit collaborator)
			$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectid);

			if ($context->canRead()) {
				// Project exists - check if user can edit
				// Project metadata (name, description, etc.) can only be edited by owner
				// But insertProject also handles datasets which collaborators can add
				// The business logic layer handles the fine-grained permissions

				// For now, allow owner and edit collaborators to call insertProject
				// The business logic will enforce per-field permissions
				if ($context->permissionLevel === 'readonly') {
					return $this->forbidden("You don't have edit permission on this project");
				}

				// Set effective owner for the operation
				$originalUserpkey = $this->strabo->userpkey;
				if ($context->effectiveOwner !== $originalUserpkey) {
					$this->strabo->setuserpkey($context->effectiveOwner);
				}

				if($uuid = $this->strabo->createVersion($projectid)){
					$this->strabo->logToFile("Version created: $uuid.");
				}else{
					$this->strabo->logToFile("Version creation failed.");
				}

				$injson = json_encode($upload);

				$data = $this->strabo->insertProject($injson,$thisid);

				// Restore original userpkey if changed
				if ($context->effectiveOwner !== $originalUserpkey) {
					$this->strabo->setuserpkey($originalUserpkey);
				}
			} else {
				// New project - anyone can create their own project

				if($uuid = $this->strabo->createVersion($projectid)){
					$this->strabo->logToFile("Version created: $uuid.");
				}else{
					$this->strabo->logToFile("Version creation failed.");
				}

				$injson = json_encode($upload);

				$data = $this->strabo->insertProject($injson,$thisid);
			}

			//valid JSON found. Look for id or self and update if exists

			if($data->Error!=""){
				header("Bad Request", true, 404);
			}else{
				header("Feature created", true, 201);
			}

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
