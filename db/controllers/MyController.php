<?php
/**
 * File: MyController.php
 * Description: MyController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class MyController
{
	protected $strabo;
	protected $auth;

	public function setstrabohandler($strabo){
		$this->strabo=$strabo;
	}

	public function setauthhandler($auth){
		$this->auth=$auth;
	}

	/**
	 * Return a 403 Forbidden response
	 *
	 * @param string $message Error message
	 * @return array
	 */
	protected function forbidden($message = "You don't have permission to perform this action") {
		header("Forbidden", true, 403);
		return ["Error" => $message];
	}

	/**
	 * Return a 404 Not Found response
	 *
	 * @param string $message Error message
	 * @return array
	 */
	protected function notFound($message = "Resource not found") {
		header("Not Found", true, 404);
		return ["Error" => $message];
	}

	public function foobar($value){

		echo "$value";exit();

	}

	public function dumpVar($var){

	echo "<pre>";
	print_r($var);
	echo "</pre>";
	}

	 // Default handlers for unsupported HTTP methods
	 // Child controllers can override these if needed

	 public function putAction($request) {
		 header("Bad Request", true, 400);
		 $data["Error"] = "Bad Request.";
		 return $data;
	 }

	 public function patchAction($request) {
		 header("Bad Request", true, 400);
		 $data["Error"] = "Bad Request.";
		 return $data;
	 }

	 public function optionsAction($request) {
		 header("Bad Request", true, 400);
		 $data["Error"] = "Bad Request.";
		 return $data;
	 }

	 public function searchAction($request) {
		 header("Bad Request", true, 400);
		 $data["Error"] = "Bad Request.";
		 return $data;
	 }

}
