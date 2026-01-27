<?php
/**
 * File: ImageController.php
 * Description: ImageController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ImageController extends MyController
{
	/**
	 * Get an image file
	 *
	 * Supports both direct ownership and collaborative access.
	 * If user owns the image directly OR is a collaborator on the project
	 * that contains the image, they can retrieve it.
	 */
	public function getAction($request) {

		if(isset($request->url_elements[2])) {
			$image_id = $request->url_elements[2];

			if(isset($request->url_elements[3])) {
				// do nothing, this is not a supported action
				header("Bad Request", true, 400);
				$data["Error"] = $request->url_elements[3]." not supported.";

			} else {
				//get the feature from neo4j
				$imageinfo = $this->strabo->getImageInfo($image_id);

				if($imageinfo->count > 0){

					if($imageinfo->filename != ""){

						$filename = $imageinfo->filename;
						$origfilename = $imageinfo->origfilename;
						$extension = $imageinfo->extension;

						header("Content-type:image/$extension");
						readfile("/srv/app/www/dbimages/$filename");
						exit();

					}else{

						//Error, image not found
						header("Image not Found", true, 404);
						$data["Error"] = "Image $image_id has no uploaded file.";

					}

				}else{
					//Error, image not found
					header("Image not Found", true, 404);
					$data["Error"] = "Image $image_id not found.";
				}

			}
		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Image ID must be provided";
		}
		return $data;
	}

	/**
	 * Delete an image
	 *
	 * Supports collaborative deletion:
	 * - Project owner can delete any image in their project
	 * - Collaborators can delete images they uploaded (created_by == user)
	 */
	public function deleteAction($request) {

		if(isset($request->url_elements[2])) {
			$image_id = (int)$request->url_elements[2];
			if(isset($request->url_elements[3])) {
				// do nothing, this is not a supported action
				header("Bad Request", true, 400);
				$data["Error"] = $request->url_elements[3]." not supported.";
			} else {

				//********************************************************************
				// check for feature with userid and id
				//********************************************************************

				$imageinfo = $this->strabo->getImageInfo($image_id);

				if($imageinfo->count > 0){

					//get filename so we can delete actual file
					$filename = $imageinfo->filename;
					unlink("dbimages/$filename");

					$this->strabo->deleteImage($image_id);

					header("Image deleted", true, 204);
					$data['message']="Image $image_id deleted.";

				}else{
					//Error, feature not found
					header("Bad Request", true, 404);
					$data["Error"] = "Image $image_id not found.";
				}

			}
		} else {
			header("Bad Request", true, 400);
			$data["Error"] = "Image ID must be provided";
		}
		return $data;
	}

	/**
	 * Upload a new image
	 *
	 * Images are uploaded before being linked to spots/projects, so no
	 * project context is available at upload time. The image is owned by
	 * the uploading user until linked to a collaborative project.
	 *
	 * The created_by field tracks the original uploader even after
	 * ownership is transferred to the project owner during collaboration.
	 */
	public function postAction($request) {

		$data = $this->strabo->insertImage($_POST,$_FILES['image_file']);

		if($data->Error != ""){
			header("Bad Request", true, 400);
		}else{
			header("Image saved.", true, 201);
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
