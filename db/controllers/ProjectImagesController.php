<?php
/**
 * File: ProjectImagesController.php
 * Description: ProjectImagesController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectImagesController extends MyController
{

	public function getAction($request) {

		if(isset($request->url_elements[2])) {

			$projectId = $request->url_elements[2];

			// Check if user has read access to this project
			$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectId);

			if (!$context->canRead()) {
				return $this->notFound("Project not found");
			}

			// Use effectiveOwner to get images from the correct owner's project
			$originalUserpkey = $this->strabo->userpkey;
			if ($context->effectiveOwner !== $originalUserpkey) {
				$this->strabo->setuserpkey($context->effectiveOwner);
			}

			$data = $this->strabo->getProjectImagesForAPI($projectId);

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
