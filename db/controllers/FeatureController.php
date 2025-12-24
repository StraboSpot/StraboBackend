<?php
/**
 * File: FeatureController.php
 * Description: FeatureController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class FeatureController extends MyController
{

	public function getAction($request) {

		if(isset($request->url_elements[2])) {
			$spotId = $request->url_elements[2];

			if(isset($request->url_elements[3])) {
				// do nothing, this is not a supported action
				header("Bad Request", true, 400);
				$data["Error"] = $request->url_elements[3]." not supported.";

			} else {
				// Get dataset ID for this spot to check project context
				$datasetId = $this->strabo->getDatasetId($spotId);

				if ($datasetId) {
					$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

					if ($context !== null && !$context->canRead()) {
						return $this->notFound("Feature not found");
					}

					if ($context !== null && $context->effectiveOwner !== $this->strabo->userpkey) {
						$originalUserpkey = $this->strabo->userpkey;
						$this->strabo->setuserpkey($context->effectiveOwner);
						$data = $this->strabo->getSingleSpot($spotId);
						$this->strabo->setuserpkey($originalUserpkey);
					} else {
						$data = $this->strabo->getSingleSpot($spotId);
					}
				} else {
					// Spot not linked to dataset - use current user's context
					$data = $this->strabo->getSingleSpot($spotId);
				}

				if($data->Error!=""){
					header("Feature not Found", true, 404);
				}

			}

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Feature ID must be provided";
		}
		return $data;
	}

	public function deleteAction($request) {

		if(isset($request->url_elements[2])) {
			$spotId = (int)$request->url_elements[2];
			if(isset($request->url_elements[3])) {
				// do nothing, this is not a supported action
				header("Bad Request", true, 400);
				$data["Error"] = $request->url_elements[3]." not supported.";
			} else {

				// Get dataset ID for this spot to check project context
				$datasetId = $this->strabo->getDatasetId($spotId);

				if ($datasetId) {
					$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

					if ($context !== null) {
						// Check if user can edit this dataset
						if (!$this->auth->canEditDataset($context, $context->datasetCreatedBy)) {
							if ($context->permissionLevel === 'none') {
								return $this->notFound("Feature $spotId not found.");
							}
							return $this->forbidden("You don't have permission to delete spots in this dataset");
						}

						// Use effectiveOwner for the operation
						$originalUserpkey = $this->strabo->userpkey;
						if ($context->effectiveOwner !== $originalUserpkey) {
							$this->strabo->setuserpkey($context->effectiveOwner);
						}

						if($this->strabo->findSpot($spotId)){
							$projectid = $this->strabo->getProjectIdFromSpotId($spotId);
							$this->strabo->deleteSingleSpot($spotId);
							$this->strabo->setProjectCenter($projectid, $context->effectiveOwner);
							header("Feature deleted", true, 204);
							$data['message']="Feature $spotId deleted.";
						}else{
							header("Bad Request", true, 404);
							$data["Error"] = "Feature $spotId not found.";
						}

						// Restore original userpkey if changed
						if ($context->effectiveOwner !== $originalUserpkey) {
							$this->strabo->setuserpkey($originalUserpkey);
						}
					} else {
						// Spot in unlinked dataset - user can delete their own
						if($this->strabo->findSpot($spotId)){
							$projectid = $this->strabo->getProjectIdFromSpotId($spotId);
							$this->strabo->deleteSingleSpot($spotId);
							if ($projectid) {
								$this->strabo->setProjectCenter($projectid);
							}
							header("Feature deleted", true, 204);
							$data['message']="Feature $spotId deleted.";
						}else{
							header("Bad Request", true, 404);
							$data["Error"] = "Feature $spotId not found.";
						}
					}
				} else {
					// Spot not linked to dataset
					if($this->strabo->findSpot($spotId)){
						$this->strabo->deleteSingleSpot($spotId);
						header("Feature deleted", true, 204);
						$data['message']="Feature $spotId deleted.";
					}else{
						header("Bad Request", true, 404);
						$data["Error"] = "Feature $spotId not found.";
					}
				}
			}
		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Feature ID must be provided";
		}
		return $data;
	}

	public function oldpostAction($request) {

		if(isset($request->url_elements[2])) {

			$thisid = $request->url_elements[2];

		}

		$data["Error"] = "This function is deprecated. Please use datasetspots function instead.";

		return $data;
	}

	/**
	 * Create or update a spot/feature
	 *
	 * NOTE: Spots are typically created through datasetspots endpoint which
	 * handles project context. This endpoint is for direct spot creation/update.
	 * For updates to linked spots, we check collaboration permissions.
	 */
	public function postAction($request) {

		if(isset($request->url_elements[2])) {

			$thisid = $request->url_elements[2];

		}

		$upload = $request->parameters;

		unset($upload['apiformat']);

		$featuretype=$upload['type'];

		if($featuretype==""){

			// bad body sent, error
			header("Bad Request", true, 400);
			$data["Error"] = "Invalid body JSON sent.";

		}else{

			// Check if this is an update to an existing linked spot
			if ($thisid) {
				$datasetId = $this->strabo->getDatasetId($thisid);

				if ($datasetId) {
					$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

					if ($context !== null) {
						// Check if user can edit this dataset
						if (!$this->auth->canEditDataset($context, $context->datasetCreatedBy)) {
							if ($context->permissionLevel === 'none') {
								return $this->notFound("Feature not found");
							}
							return $this->forbidden("You don't have permission to edit spots in this dataset");
						}

						// Use effectiveOwner for the operation
						$originalUserpkey = $this->strabo->userpkey;
						if ($context->effectiveOwner !== $originalUserpkey) {
							$this->strabo->setuserpkey($context->effectiveOwner);
						}

						$injson = json_encode($upload);
						$data = $this->strabo->insertSpot($injson,$thisid);

						// Restore original userpkey if changed
						if ($context->effectiveOwner !== $originalUserpkey) {
							$this->strabo->setuserpkey($originalUserpkey);
						}
					} else {
						// Spot in unlinked dataset
						$injson = json_encode($upload);
						$data = $this->strabo->insertSpot($injson,$thisid);
					}
				} else {
					// Spot not linked to dataset
					$injson = json_encode($upload);
					$data = $this->strabo->insertSpot($injson,$thisid);
				}
			} else {
				// New spot
				$injson = json_encode($upload);
				$data = $this->strabo->insertSpot($injson,$thisid);
			}

			if($data->Error != ""){
				header("Bad Request", true, 400);
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
