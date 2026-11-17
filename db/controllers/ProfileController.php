<?php
/**
 * File: ProfileController.php
 * Description: ProfileController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProfileController extends MyController
{
	public function getAction($request) {

		$data = $this->strabo->getProfile();

		// Projects prepared for this account on the web (today: a field camp account's camp package), so the
		// app can offer to download them (GET /db/projectPackage/{id}). Accounts without one get no key.
		// The key is generic on purpose: a later wizard for single users adds entries, not app changes.
		// A problem here must never break sign-in.
		try {
			require_once __DIR__ . '/../../includes/fieldcamp/FieldCampPackage.php';
			$prepared = FieldCampPackage::preparedProjects($this->strabo->db, $this->strabo->userpkey, ProjectPackageController::serverBase());
			if ($prepared) $data->prepared_projects = $prepared;
		} catch (\Throwable $e) {
			error_log("ProfileController prepared_projects: " . $e->getMessage());
		}

		return $data;
	}

	public function deleteAction($request) {

		// delete the profile
		$this->strabo->deleteProfile();

		header("Profile deleted", true, 204);
		$data['message']="Profile deleted.";

		return $data;
	}

	public function postAction($request) {

		$upload = $request->parameters;

		unset($upload['apiformat']);
		// sent by the server with GET; never part of a stored profile (field_camp: the first name of prepared_projects)
		unset($upload['prepared_projects'], $upload['field_camp']);

		$continueprofile="no";

		//count properties to make sure json is set
		if(!empty($upload)){$continueprofile="yes";}

		if($continueprofile=="no"){

			// bad body sent, error
			header("Bad Request", true, 400);
			$data["Error"] = "Invalid body JSON sent.";

		}else{

			$injson = json_encode($upload);

			$data = $this->strabo->insertProfile($injson);
			header("Profile created", true, 201);

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
