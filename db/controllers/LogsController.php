<?php
/**
 * File: LogsController.php
 * Description: LogsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class LogsController extends MyController
{
	public function getAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function deleteAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}

	public function throwError($error){
		header('Content-Type: application/json; charset=utf-8');
		echo '{"status":"error","message":"'.$error.'"}';

		if(file_exists($_SERVER['DOCUMENT_ROOT']."/log.txt")){
			$fileError = print_r($error, true);
			file_put_contents ($_SERVER['DOCUMENT_ROOT']."/log.txt", "\n\n************************************************************************************************************************\n\n", FILE_APPEND);
			file_put_contents ($_SERVER['DOCUMENT_ROOT']."/log.txt", "error: ".$fileError."\n\n", FILE_APPEND);
		}

		exit();
	}

	public function postAction($request) {

		

		$email = strtolower(trim($_SERVER['PHP_AUTH_USER'] ?? ''));
		$notes = $_POST['notes'] ?? '';
		$appversion = $_POST['appversion'] ?? '';

		$inFiles = print_r($_FILES, true);

		if(file_exists($_SERVER['DOCUMENT_ROOT']."/log.txt")){
			if($email=="jasonash@ku.edu"){

				file_put_contents ($_SERVER['DOCUMENT_ROOT']."/log.txt", "\n\n************************************************************************************************************************\n\n", FILE_APPEND);
				file_put_contents ($_SERVER['DOCUMENT_ROOT']."/log.txt", "email: ".$email."\n\n", FILE_APPEND);
				file_put_contents ($_SERVER['DOCUMENT_ROOT']."/log.txt", "micropass: ".$micropass."\n\n", FILE_APPEND);
				file_put_contents ($_SERVER['DOCUMENT_ROOT']."/log.txt", "notes: ".$notes."\n\n", FILE_APPEND);
				file_put_contents ($_SERVER['DOCUMENT_ROOT']."/log.txt", "inFiles: ".$inFiles."\n\n", FILE_APPEND);

			}
		}

		$file = $_FILES['log_file'];

		if($file['name']==""){
			throwError("No file provided.");
		}

		$pathinfo = pathinfo($file['name']);

		if($pathinfo['extension'] != "zip") throwError("Wrong file type provided.");

		$tmp_name = $file['tmp_name'];

		$pkey = $this->strabo->db->get_var("SELECT nextval('micro_logs_pkey_seq')");

		exec("mv " . escapeshellarg($tmp_name) . " /srv/app/www/microLogs/" . intval($pkey) . ".txt");

		$this->strabo->db->prepare_query("
			INSERT INTO micro_logs (
				pkey,
				email,
				notes,
				appversion
			) VALUES (
				$1,
				$2,
				$3,
				$4
			)
		", array($pkey, $email, $notes, $appversion));

		// config.inc.php is already loaded by index.php; StraboMail reads it from the globals.
		require_once $_SERVER['DOCUMENT_ROOT']."/includes/StraboMail.php";
		$m = StraboMail::render(array(
			'title'  => 'StraboMicro Error Report',
			'intro'  => array_values(array_filter(array("A new error report has been sent by StraboMicro user $email", $notes), 'strlen')),
			'button' => array('View the report', "https://strabospot.org/microLogView?pkey=$pkey"),
		));
		try {
			StraboMail::send("jasonash@ku.edu", "StraboMicro Error Report", $m);
		} catch (Exception $e) {
			error_log('LogsController: error report mail failed: ' . $e->getMessage());
		}

		header('Content-Type: application/json; charset=utf-8');
		echo "{\"status\":\"success\"}";
		exit();

	}    public function optionsAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}    public function copyAction($request) {

		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

		return $data;
	}}
