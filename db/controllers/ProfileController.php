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

		// A field camp account is told about the project package its instructor prepared, so the app can
		// offer to download it at sign-in (GET /db/fieldCampPackage). No other account gets the key.
		// A problem here must never break sign-in.
		try {
			require_once __DIR__ . '/../../includes/fieldcamp/FieldCampPackage.php';
			$camp = FieldCampPackage::profileKey($this->strabo->db, $this->strabo->userpkey, FieldCampPackageController::serverBase());
			if ($camp !== null) $data->field_camp = $camp;
		} catch (\Throwable $e) {
			error_log("ProfileController field_camp: " . $e->getMessage());
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
		unset($upload['field_camp']);   // sent by the server with GET (field camp accounts); never part of a stored profile

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
