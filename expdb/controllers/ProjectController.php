<?php
/**
 * File: ProjectController.php
 * Description: GET /expdb/project/{pkey}: read-only StraboExperimental project
 *              for API clients (the StraboField app follows the project_pkey in
 *              a download's strabosamples_linked key). Logic and access rule:
 *              experimental/lib/experiment_read.php.
 *
 * @package    StraboExperimental
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */
class ProjectController extends MyController
{
	public function getAction($request) {
		if (!isset($request->url_elements[2]) || !ctype_digit((string)$request->url_elements[2])) {
			header("Bad Request", true, 400);
			return array("Error" => "Project pkey (a number) must be provided");
		}
		if (isset($request->url_elements[3])) {
			header("Bad Request", true, 400);
			return array("Error" => $request->url_elements[3] . " not supported.");
		}
		require_once __DIR__ . '/../../experimental/lib/experiment_read.php';
		$e = $this->expclass;
		$data = exp_read_project($e->db, $e->neodb, (int)$e->userpkey, (int)$request->url_elements[2]);
		if ($data === null) {
			header("Not Found", true, 404);
			return array("Error" => "Project not found.");
		}
		return $data;
	}

	public function postAction($request) {
		header("Bad Request", true, 400);
		return array("Error" => "Read only.");
	}

	public function deleteAction($request) {
		header("Bad Request", true, 400);
		return array("Error" => "Read only.");
	}
}
