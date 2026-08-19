<?php
/**
 * File: ProjectDatasetSpotController.php
 * Description: ProjectDatasetSpotController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectDatasetSpotController extends MyController
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
			if($upload['dataset']!=""){
				$datasetid=$upload['dataset']->id;
				if($datasetid!=""){

					if($upload['spot']!=""){
						$spotid=$upload['spot']->properties->id;
						if($spotid==""){
						}
					}

				}else{
					$errors.="No Dataset ID Provided. ";
				}
			}
		}else{
			$errors.="No Project ID Provided. ";
		}

		if(!$errors){

			// Check project authorization
			$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectid);

			// If project exists, check if user can edit
			if ($context->canRead()) {
				// Project exists
				if ($context->permissionLevel === 'readonly') {
					return $this->forbidden("You don't have edit permission on this project");
				}

				// For dataset operations, check dataset permission
				if ($datasetid) {
					$datasetContext = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetid);
					$datasetCreatedBy = $datasetContext ? $datasetContext->datasetCreatedBy : $this->strabo->userpkey;

					// Check if user can edit dataset (or create new dataset)
					if ($datasetContext !== null && !$this->auth->canEditDataset($context, $datasetCreatedBy)) {
						return $this->forbidden("You don't have permission to edit this dataset");
					}
				}
			}
			// If project doesn't exist, user is creating a new project - allowed

			// Use effectiveOwner for operations
			$userpkey = $context->effectiveOwner ?: $this->strabo->userpkey;
			$originalUserpkey = $this->strabo->userpkey;
			if ($userpkey !== $originalUserpkey) {
				$this->strabo->setuserpkey($userpkey);
			}

			$pcount = $this->strabo->db->get_var_prepared("SELECT count(*) FROM vprojects WHERE userpkey=$1 AND projectid=$2", array($userpkey, $projectid));
			if($pcount > 0){
				$this->strabo->createVersion($projectid);
				$this->strabo->db->prepare_query("DELETE FROM vprojects WHERE userpkey=$1 AND projectid=$2", array($userpkey, $projectid));
			}

			$injson = json_encode($upload['project']);
			// Determine if this is a collaborative edit (user is collaborator, not owner)
			$isCollaborativeEdit = ($context && $context->permissionLevel === 'edit' && !$context->isOwner());
			$this->strabo->insertProject($injson, null, $isCollaborativeEdit, $userpkey, $originalUserpkey);

			if($datasetid!=""){
				//first, delete dataset relationships
				$this->strabo->deleteDatasetRelationships($datasetid);
				$injson = json_encode($upload['dataset']);
				// Pass originalUserpkey for created_by tracking
				$this->strabo->insertDataset($injson, null, $originalUserpkey);
				$this->strabo->addDatasetToProject($projectid, $datasetid, "HAS_DATASET", $userpkey, $originalUserpkey);

				if($spotid!=""){

					$injson = json_encode($upload['spot']);
					$this->strabo->setSampleSyncContext($projectid, $datasetid);
					$this->strabo->insertSpot($injson, null, "", $originalUserpkey);
					$this->strabo->addSpotToDataset($datasetid,$spotid,"HAS_SPOT");
					$this->strabo->clearSampleSyncContext();

					//fix real-world coordinates here
					$thisspot = $upload['spot'];
					$image_basemap = $thisspot->properties->image_basemap;

					$mygeojson=$thisspot->geometry;
					$mygeojson=trim(json_encode($mygeojson));
					$mywkt=geoPHP::load($mygeojson,"json");
					$origwkt = $mywkt->out('wkt');

					if($image_basemap!=""){
						$newwkt = $this->strabo->fixSpotCoordinates($image_basemap);
					}

					$safe_userpkey = addslashes($userpkey);
					$safe_spotid = addslashes($spotid);
					$safe_newwkt = addslashes($newwkt);
					$safe_origwkt = addslashes($origwkt);

					if($newwkt!=""){
						$this->strabo->neodb->query("match (s:Spot) where s.userpkey=$safe_userpkey and s.id=$safe_spotid set s.wkt='$safe_newwkt', s.origwkt='$safe_origwkt'");
					}else{
						$this->strabo->neodb->query("match (s:Spot) where s.userpkey=$safe_userpkey and s.id=$safe_spotid set s.origwkt='$safe_origwkt'");
					}
				}
			}

			if($datasetid!=""){
				$this->strabo->buildDatasetRelationships($datasetid);
				$this->strabo->setDatasetCenter($datasetid, $userpkey);

				//also add dataset to Postgres Database here.
				$this->strabo->buildPgDataset($datasetid, $userpkey);
			}

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
