<?php

/*
******************************************************************
StraboSpot REST API
Logs Controller
Author: Jason Ash (jasonash@ku.edu)
Description: This controller allows users to submit error logs
******************************************************************
*/

/*
{
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdHJhYm9zcG90Lm9yZyIsImF1ZCI6InN0cmFib3Nwb3QiLCJpYXQiOjE3NjU0NzAxODYsImV4cCI6MTc2NTQ3Mzc4Niwic3ViIjoiMyIsImVtYWlsIjoiamFzb25hc2hAa3UuZWR1IiwibmFtZSI6Ikphc29uIEFzaCJ9.S23ebq6U1ZOqStb5DwbnCsM6Ujis85kXGTwT5Yrj3GA",
  "refresh_token": "18e68431825c65960df6833e00939299cbf57837577716f4ed7230c295d2ba55",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "pkey": "3",
    "email": "jasonash@ku.edu",
    "name": "Jason Ash"
  }
}


curl -X POST https://strabospot.org/jwtmicrodb/logs \
     -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdHJhYm9zcG90Lm9yZyIsImF1ZCI6InN0cmFib3Nwb3QiLCJpYXQiOjE3NjU0NzAxODYsImV4cCI6MTc2NTQ3Mzc4Niwic3ViIjoiMyIsImVtYWlsIjoiamFzb25hc2hAa3UuZWR1IiwibmFtZSI6Ikphc29uIEFzaCJ9.S23ebq6U1ZOqStb5DwbnCsM6Ujis85kXGTwT5Yrj3GA" \
     -H "Content-Type: application/json" \
     -d 'name=linuxize'




curl -X POST https://strabospot.org/jwtmicrodb/logs \
     -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdHJhYm9zcG90Lm9yZyIsImF1ZCI6InN0cmFib3Nwb3QiLCJpYXQiOjE3NjU0NzAxODYsImV4cCI6MTc2NTQ3Mzc4Niwic3ViIjoiMyIsImVtYWlsIjoiamFzb25hc2hAa3UuZWR1IiwibmFtZSI6Ikphc29uIEFzaCJ9.S23ebq6U1ZOqStb5DwbnCsM6Ujis85kXGTwT5Yrj3GA" \
     -F "log_file=@/Users/jason/Desktop/log.txt" \
     -F "notes=some notes about error report here..." \
     -F "appversion=v1.2.3"


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

		/*
			[PHP_AUTH_USER] => jasonash@ku.edu
			[PHP_AUTH_PW] => strabotest
		*/


		//var_dump($_POST);
		//var_dump($_FILES);

		$userpkey = $this->sm->userpkey;

		$email = $this->sm->db->get_var_prepared("select email from users where pkey = $1",array($userpkey));
		
		//echo "email: $email";

		//$email = pg_escape_string($_SERVER['PHP_AUTH_USER']);
		//exit();



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

		//exit();

		$pkey = $this->sm->db->get_var("select nextval('micro_logs_pkey_seq')");

		//exec("/usr/bin/unzip $tmp_name -d /srv/app/www/microLogs/$pkey");

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

		require_once "Mail.php";
		include($_SERVER['DOCUMENT_ROOT']."/includes/config.inc.php");
		$from     = "StraboSpot <strabospot@gmail.com>";
		$subject="StraboMicro Error Report";

		$host     = "ssl://smtp.gmail.com";
		$port     = "465";
		$emailusername = "$straboemailaddress";  //<> give errors
		$password = "$straboemailpassword";

		$headers = array(
			'From'    => $from,
			'Subject' => $subject,
			'Content-Type' => "text/html; charset=iso-8859-1"
		);

		$message= "<html><body>
					<h2>StraboMicro Error Report</h2>
					A new error report has been sent by StraboMicro2 user $email<br><br>
			
					$notes<br><br>
			
					<a href=\"https://www.strabospot.org/microLogView?pkey=$pkey\">https://www.strabospot.org/microLogView/$pkey</a><br><br>

			
					<br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br>
					</body></html>";
			

		$smtp = Mail::factory('smtp', array(
			'host'     => $host,
			'port'     => $port,
			'auth'     => true,
			'username' => $emailusername,
			'password' => $password
		));

		$to = "jasonash@ku.edu";

		$headers['To']=$to;

		$mail = $smtp->send($to, $headers, $message);


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
