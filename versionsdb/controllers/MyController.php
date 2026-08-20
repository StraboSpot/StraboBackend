<?php
/**
 * File: MyController.php
 * Description: Base controller for the read-only Versions API. Child
 *              controllers implement getAction only; every mutating verb
 *              falls through to the 405 handlers here.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class MyController
{
	protected $sv;

	public function setstraboversionshandler($sv){
		$this->sv = $sv;
	}

	private function readOnly() {
		header("Method Not Allowed", true, 405);
		$data["Error"] = "Method not allowed. This API is read-only (GET).";
		return $data;
	}

	public function postAction($request)    { return $this->readOnly(); }
	public function putAction($request)     { return $this->readOnly(); }
	public function patchAction($request)   { return $this->readOnly(); }
	public function deleteAction($request)  { return $this->readOnly(); }
	public function optionsAction($request) { return $this->readOnly(); }

}
