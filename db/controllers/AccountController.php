<?php
/**
 * File: AccountController.php
 * Description: AccountController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class AccountController extends MyController
{
	public function getAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function deleteAction($request) {

		// Field camp accounts belong to the instructor who created them; the
		// instructor removes them from the Field Camp Wizard.
		require_once __DIR__ . '/../../includes/fieldcamp/FieldCamp.php';
		if(FieldCamp::isCampAccount($this->strabo->db, $this->strabo->userpkey)){
			header("Forbidden", true, 403);
			$data["Error"] = "This is a field camp account. It can only be removed by the instructor who created it.";
			return $data;
		}

		$this->strabo->deleteUserAccount();
		header("Dataset deleted", true, 204);
		$data["message"] = "Account deleted.";
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
