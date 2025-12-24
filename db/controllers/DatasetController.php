<?php
/**
 * File: DatasetController.php
 * Description: DatasetController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class DatasetController extends MyController
{
	public function getAction($request) {

		if(isset($request->url_elements[2])) {
			$datasetId = $request->url_elements[2];

			if(isset($request->url_elements[3])) {
				// do nothing, this is not a supported action
				header("Bad Request", true, 400);
				$data["Error"] = $request->url_elements[3]." not supported.";

			} else {
				// Check if user has read access to this dataset's project
				$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

				// If dataset is linked to a project, check access
				if ($context !== null) {
					if (!$context->canRead()) {
						return $this->notFound("Dataset not found");
					}

					// Use effectiveOwner to get dataset from the correct owner's data
					$originalUserpkey = $this->strabo->userpkey;
					if ($context->effectiveOwner !== $originalUserpkey) {
						$this->strabo->setuserpkey($context->effectiveOwner);
					}

					$data = $this->strabo->getSingleDataset($datasetId);

					// Restore original userpkey if changed
					if ($context->effectiveOwner !== $originalUserpkey) {
						$this->strabo->setuserpkey($originalUserpkey);
					}
				} else {
					// Dataset not linked to project - use current user's context
					$data = $this->strabo->getSingleDataset($datasetId);
				}

				if($data["Error"]!=""){
					header("Dataset not Found", true, 404);
				}

			}
		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Dataset ID must be provided";
		}
		return $data;
	}

	public function deleteAction($request) {

		if(isset($request->url_elements[2])) {
			$datasetId = (int)$request->url_elements[2];
			if(isset($request->url_elements[3])) {
				// do nothing, this is not a supported action
				header("Bad Request", true, 400);
				$data["Error"] = $request->url_elements[3]." not supported.";
			} else {

				//********************************************************************
				// check for feature with userid and id; delete if exists
				//********************************************************************

				// Check if user has edit access to this dataset
				$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

				if ($context !== null) {
					// Dataset is linked to a project - check edit permission
					if (!$this->auth->canEditDataset($context, $context->datasetCreatedBy)) {
						if ($context->permissionLevel === 'none') {
							return $this->notFound("Dataset $datasetId not found.");
						}
						return $this->forbidden("You don't have permission to delete this dataset");
					}

					// Use effectiveOwner for the operation
					$originalUserpkey = $this->strabo->userpkey;
					if ($context->effectiveOwner !== $originalUserpkey) {
						$this->strabo->setuserpkey($context->effectiveOwner);
					}

					if($this->strabo->findDataset($datasetId)){

						$this->strabo->deleteSingleDataset($datasetId);

						header("Dataset deleted", true, 204);
						$data['message']="Dataset $datasetId deleted.";

					}else{
						//Error, feature not found
						header("Bad Request", true, 404);
						$data["Error"] = "Dataset $datasetId not found.";
					}

					// Restore original userpkey if changed
					if ($context->effectiveOwner !== $originalUserpkey) {
						$this->strabo->setuserpkey($originalUserpkey);
					}
				} else {
					// Dataset not linked to project - user can delete their own unlinked datasets
					if($this->strabo->findDataset($datasetId)){

						$this->strabo->deleteSingleDataset($datasetId);

						header("Dataset deleted", true, 204);
						$data['message']="Dataset $datasetId deleted.";

					}else{
						//Error, feature not found
						header("Bad Request", true, 404);
						$data["Error"] = "Dataset $datasetId not found.";
					}
				}

			}
		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Dataset ID must be provided";
		}
		return $data;
	}

	/**
	 * Create or update a dataset
	 *
	 * NOTE: Datasets are created before being linked to projects, so no
	 * project context is available at creation time. For updates to linked
	 * datasets, we check collaboration permissions.
	 */
	public function postAction($request) {

		$thisid = $request->url_elements[2];
		$upload = $request->parameters;
		unset($upload['apiformat']);

		// Check if this is an update to an existing linked dataset
		if ($thisid) {
			$context = $this->auth->getDatasetContext($this->strabo->userpkey, $thisid);

			if ($context !== null) {
				// Dataset is linked to a project - check edit permission
				if (!$this->auth->canEditDataset($context, $context->datasetCreatedBy)) {
					if ($context->permissionLevel === 'none') {
						return $this->notFound("Dataset not found");
					}
					return $this->forbidden("You don't have permission to edit this dataset");
				}

				// Use effectiveOwner for the operation
				$originalUserpkey = $this->strabo->userpkey;
				if ($context->effectiveOwner !== $originalUserpkey) {
					$this->strabo->setuserpkey($context->effectiveOwner);
				}

				$injson=json_encode($upload);
				$data = $this->strabo->insertDataset($injson,$thisid);

				// Restore original userpkey if changed
				if ($context->effectiveOwner !== $originalUserpkey) {
					$this->strabo->setuserpkey($originalUserpkey);
				}
			} else {
				// Dataset not linked to project - allow create/update
				$injson=json_encode($upload);
				$data = $this->strabo->insertDataset($injson,$thisid);
			}
		} else {
			// New dataset - anyone can create their own datasets
			$injson=json_encode($upload);
			$data = $this->strabo->insertDataset($injson,$thisid);
		}

		if($data->Error != ""){
			header("Bad Request", true, 400);
		}else{
			header("Dataset created", true, 201);
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
