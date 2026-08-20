<?php
/**
 * File: index.php
 * Description: Entry point for the read-only StraboField Versions API.
 *
 * Serves versioned StraboField project JSON (snapshots written by
 * createVersion in db/strabospotclass.php). Three GET endpoints:
 *
 *   GET /versionsdb/myprojects                  distinct versioned projects
 *   GET /versionsdb/projectversions/{projectid} version metadata for a project
 *   GET /versionsdb/version/{uuid}              the snapshot JSON itself
 *
 * Authentication is HTTP Basic against the users table (password or
 * apptoken), same pattern as /microdb/. All queries are scoped to the
 * authenticated userpkey. PostgreSQL only: version metadata lives in the
 * public.versions table and payloads in /srv/app/www/versions/{uuid}
 * (gzip-compressed JSON), so Neo4j is never touched.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

set_time_limit(0);

//Initialize database (PostgreSQL only; versions never touch Neo4j)
include_once "../includes/config.inc.php";
include "../db.php";
include "./straboversionsclass.php";

$username = pg_escape_string(strtolower($_SERVER['PHP_AUTH_USER']));
$password = $_SERVER['PHP_AUTH_PW'];

$usercount = 0;

$row = $db->get_row_prepared("select * from users where email=$1 and crypt($2, password) = password and active = TRUE and deleted = FALSE limit 1", array($username, $password));
$usercount = $db->num_rows;

if($usercount == 0){
	$rows = $db->get_row_prepared("SELECT * FROM apptokens, users WHERE users.email = apptokens.email AND apptokens.email=$1 AND apptokens.uuid = $2 AND users.deleted = FALSE", array($username, $password));
	if($db->num_rows > 0){
		$usercount = $db->num_rows;
		$db->prepare_query("UPDATE apptokens SET created_on = now() WHERE email=$1 AND uuid = $2", array($username, $password));
	}
}

if($usercount == 0){
	header('WWW-Authenticate: Basic realm="StraboVersions"');
	header("HTTP/1.1 401 Unauthorized");
	echo "Unauthorized";exit();
}

$userpkey = $db->get_var_prepared("select pkey from users where email=$1", array($username));

if($userpkey == ""){
	header('WWW-Authenticate: Basic realm="StraboVersions"');
	header("HTTP/1.1 401 Unauthorized");
	echo "Unauthorized";exit();
}

$userpkey = (int)$userpkey;

//Load Base Controller
include "./controllers/MyController.php";

//Load Additional Controllers
foreach (glob("./controllers/*.php") as $filename){
	include_once $filename;
}

include "./library/Request.php";
include "./views/ApiView.php";
include "./views/JsonView.php";
include "./views/HtmlView.php";

$sv = new StraboVersions($db, $userpkey);

$request = new Request();

// route the request to the right place
$controller_name = ucfirst($request->url_elements[1]) . 'Controller';

$showcontroller = $request->url_elements[1];
if($showcontroller == ""){$showcontroller = "null";}

if (class_exists($controller_name)) {
	$controller = new $controller_name();
	$controller->setstraboversionshandler($sv);
	$action_name = strtolower($request->verb) . 'Action';
	if (method_exists($controller, $action_name)) {
		$result = $controller->$action_name($request);
	} else {
		header("Method Not Allowed", true, 405);
		$result['Error'] = "Method not allowed. This API is read-only (GET).";
		header('Content-Type: application/json; charset=utf8');
	}
}else{
	//send an error header with brief explanation.
	header("Bad Request", true, 404);
	$result['Error'] = "No such function (".$showcontroller.")";
	header('Content-Type: application/json; charset=utf8');
}

$view_name = ucfirst($request->apiformat) . 'View';
if(class_exists($view_name)) {
	$view = new $view_name();
	$view->render($result);
}else{
	header("Bad Request", true, 400);
	echo "Error: $request->apiformat output not supported.";
}
