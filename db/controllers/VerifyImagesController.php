<?php
/**
 * File: VerifyImagesController.php
 * Description: VerifyImagesController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class VerifyImagesController extends MyController
{
	/**
	 * Bulk verify if image files exist
	 *
	 * NOTE: This controller is used during the upload flow to check which images
	 * need to be uploaded. Unlike VerifyImageController, this does NOT filter by
	 * userpkey - it simply checks filesystem existence.
	 *
	 * This is intentional as images may be shared across users in collaborative
	 * projects, and the check is just for existence (no sensitive data returned).
	 */
	public function getAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function postAction($request) {

		$indata = $request->parameters;
		unset($indata['apiformat']);

		$data = $this->strabo->findImageFiles($indata);

		if($data->Error != ""){
			header("Bad Request", true, 400);
		}else{
			header("Valid Request", true, 200);
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
