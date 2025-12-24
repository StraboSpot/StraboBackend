<?php
/**
 * File: DatasetFieldsController.php
 * Description: DatasetFieldsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class DatasetFieldsController extends MyController
{

	public function getAction($request) {

		if(isset($request->url_elements[2])) {

			$datasetId = $request->url_elements[2];
			$ingtype = strtolower($request->url_elements[3]);

			// Check if user has read access to this dataset's project
			$context = $this->auth->getDatasetContext($this->strabo->userpkey, $datasetId);

			if (!$context || !$context->canRead()) {
				return $this->notFound("Dataset not found");
			}

			// Use effectiveOwner to get dataset from the correct owner's data
			$originalUserpkey = $this->strabo->userpkey;
			if ($context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($context->effectiveOwner);
			}

			$data = $this->strabo->getDatasetFields($datasetId, $ingtype);

			// Restore original userpkey if changed
			if ($context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($originalUserpkey);
			}

		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Bad Request. No Dataset id specified.";
		}
		return $data;
	}

	public function postAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

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
