<?php

/*
******************************************************************
StraboSpot REST API
Logs Controller
Author: Jason Ash (jasonash@ku.edu)
Description: This controller allows users to submit error logs
******************************************************************
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

		//$userpkey = $this->sm->userpkey;
		//$email = $this->sm->db->get_var_prepared("select email from users where pkey = $1",array($userpkey));
		$email = pg_escape_string($_POST['email']);

		$notes = pg_escape_string($_POST['notes']);
		$appversion = pg_escape_string($_POST['appversion']);


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
			//throwError("No file provided.");
			header('Content-Type: application/json; charset=utf-8');
			echo "{\"Error\": \"No file provided.\"}";
			exit();
		}

		$tmp_name = $file['tmp_name'];

		$pkey = $this->sm->db->get_var("select nextval('micro_logs_pkey_seq')");

		exec("mv $tmp_name /srv/app/www/microLogs/$pkey.txt");

		$this->sm->db->query("
			insert into micro_logs (
				pkey,
				email,
				notes,
				appversion
			) values (
				$pkey,
				'$email',
				'$notes',
				'$appversion'
			)
		");

		// config.inc.php is already loaded by index.php; StraboMail reads it from the globals.
		require_once $_SERVER['DOCUMENT_ROOT']."/includes/StraboMail.php";
		$m = StraboMail::render(array(
			'title'  => 'StraboMicro Error Report',
			'intro'  => array_values(array_filter(array("A new error report has been sent by StraboMicro2 user $email", $notes), 'strlen')),
			'button' => array('View the report', "https://strabospot.org/microLogView?pkey=$pkey"),
		));
		try {
			StraboMail::send("jasonash@ku.edu", "StraboMicro Error Report", $m);
		} catch (Exception $e) {
			error_log('LogsController: error report mail failed: ' . $e->getMessage());
		}


		header('Content-Type: application/json; charset=utf-8');
		echo "{\"Status\":\"Success\"}";
		exit();


    }

    public function putAction($request) {
    	
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

        return $data;
    }

    public function optionsAction($request) {
    	
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

        return $data;
    }

    public function patchAction($request) {
    	
		header("Bad Request", true, 400);
		$data["Error"] = "Bad Request.";

        return $data;
    }

    public function copyAction($request) {
    	
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
