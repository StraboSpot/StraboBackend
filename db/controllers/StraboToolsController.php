<?php
/**
 * File: StraboToolsController.php
 * Description: StraboToolsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class StraboToolsController extends MyController
{
	/**
	 * Get a StraboTools analysis
	 *
	 * GET /db/strabotools/<uuid>              -> the stored image bytes
	 * GET /db/strabotools/<uuid>?format=json  -> the analysis metadata
	 *
	 * Owner-only: analyses have no collaboration semantics.
	 */
	public function getAction($request) {

		if(!isset($request->url_elements[2])) {
			header("Bad Request", true, 400);
			$data["Error"] = "Analysis ID must be provided";
			return $data;
		}

		$analysis_id = $request->url_elements[2];

		if(isset($request->url_elements[3])) {
			// do nothing, this is not a supported action
			header("Bad Request", true, 400);
			$data["Error"] = $request->url_elements[3]." not supported.";
			return $data;
		}

		$info = $this->strabo->getToolsAnalysisInfo($analysis_id);

		if($info->count == 0){
			return $this->notFound("Analysis $analysis_id not found.");
		}

		if(isset($request->parameters['format']) && $request->parameters['format']=="json"){

			$data['id'] = $info->id;
			$data['self'] = "https://strabospot.org/db/strabotools/$info->id";
			$data['filename'] = $info->filename;
			$data['modified_timestamp'] = $info->modified_timestamp;
			$data['created_timestamp'] = $info->created_timestamp;
			$data['analysis'] = $info->analysis;

			return $data;

		}

		if($info->filename == "" || !file_exists("/srv/app/www/straboToolsImages/$info->filename")){
			return $this->notFound("Analysis $analysis_id has no uploaded file.");
		}

		header("Content-type:image/$info->extension");
		readfile("/srv/app/www/straboToolsImages/$info->filename");
		exit();

	}

	/**
	 * Upload a StraboTools analysis (image + metadata)
	 *
	 * multipart/form-data:
	 *   image_file - the JPEG (required)
	 *   analysis   - JSON string, must contain a UUID "id" (required)
	 *
	 * Idempotent by the client record UUID per user: re-uploading the
	 * same analysis.id updates the existing node + file in place.
	 */
	public function postAction($request) {

		if(isset($request->url_elements[2])) {
			// do nothing, this is not a supported action
			header("Bad Request", true, 400);
			$data["Error"] = "POST does not take an ID.";
			return $data;
		}

		if(!isset($_FILES['image_file'])){
			header("Bad Request", true, 400);
			$data["Error"] = "No image file provided. Send multipart/form-data with 'image_file' and 'analysis'.";
			return $data;
		}

		$data = $this->strabo->insertToolsAnalysis($_POST, $_FILES['image_file']);

		if(isset($data['Error']) && $data['Error'] != ""){
			header("Bad Request", true, 400);
		}else{
			header("Analysis saved.", true, 201);
		}

		return $data;

	}

	/**
	 * Delete a StraboTools analysis (node + file)
	 *
	 * Owner-only.
	 */
	public function deleteAction($request) {

		if(!isset($request->url_elements[2])) {
			header("Bad Request", true, 400);
			$data["Error"] = "Analysis ID must be provided";
			return $data;
		}

		$analysis_id = $request->url_elements[2];

		if(isset($request->url_elements[3])) {
			// do nothing, this is not a supported action
			header("Bad Request", true, 400);
			$data["Error"] = $request->url_elements[3]." not supported.";
			return $data;
		}

		$deleted = $this->strabo->deleteToolsAnalysis($analysis_id);

		if(!$deleted){
			return $this->notFound("Analysis $analysis_id not found.");
		}

		header("Analysis deleted", true, 204);
		$data['message']="Analysis $analysis_id deleted.";
		return $data;

	}

}
