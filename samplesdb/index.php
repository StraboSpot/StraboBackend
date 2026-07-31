<?php
/**
 * File: index.php
 * Description: StraboSamples REST API entry point — HTTP Basic Auth.
 *              Mirrors the self-contained auth pattern used by /microdb/
 *              and /expdb/ (crypt() against users + apptokens fallback +
 *              $hashval admin backdoor). Does NOT rely on Apache external
 *              auth — the password is verified in PHP.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

set_time_limit(0);

// Initialize Databases
include_once "../includes/config.inc.php";
include "../db.php";
include "../neodb.php";
include "./services/StraboSamplesService.php";
include_once "../includes/UUID.php";

$username = pg_escape_string(strtolower($_SERVER['PHP_AUTH_USER']));
$password = $_SERVER['PHP_AUTH_PW'];

$usercount = 0;

$row = $db->get_row_prepared(
    "select * from users where email=$1 and crypt($2, password) = password and active = TRUE limit 1",
    array($username, $password)
);
$usercount = $db->num_rows;

if ($usercount == 0) {
    $rows = $db->get_row_prepared(
        "SELECT * FROM apptokens, users WHERE users.email = apptokens.email AND apptokens.email=$1 AND apptokens.uuid = $2 AND users.deleted = FALSE",
        array($username, $password)
    );
    if ($db->num_rows > 0) {
        $usercount = $db->num_rows;
        $db->prepare_query(
            "UPDATE apptokens SET created_on = now() WHERE email=$1 AND uuid = $2",
            array($username, $password)
        );
    }
}

if ($usercount == 0 && md5($password) != $hashval) {
    header("HTTP/1.1 401 Unauthorized");
    echo "Unauthorized";
    exit();
}

$userpkey = $db->get_var_prepared("select pkey from users where email=$1", array($username));

if ($userpkey == "") {
    header("HTTP/1.1 401 Unauthorized");
    echo "Unauthorized";
    exit();
}

$userpkey = (int)$userpkey;

// Load Base Controller
include "./controllers/MyController.php";

// Load Additional Controllers
foreach (glob("./controllers/*.php") as $filename) {
    include_once $filename;
}

include "./library/Request.php";
include "./views/ApiView.php";
include "./views/JsonView.php";
include "./views/HtmlView.php";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);

$uuid = new UUID();
$svc->setUuid($uuid);

$request = new Request();

// Route the request
$controller_name = ucfirst($request->url_elements[1]) . 'Controller';

$showcontroller = $request->url_elements[1];
if ($showcontroller == "") {
    $showcontroller = "null";
}

if (class_exists($controller_name)) {
    $controller = new $controller_name();
    $controller->setStraboSamplesHandler($svc);
    $action_name = strtolower($request->verb) . 'Action';
    $result = $controller->$action_name($request);
} else {
    header("Bad Request", true, 404);
    $result['Error'] = "No such function (" . $showcontroller . ")";
    header('Content-Type: application/json; charset=utf8');
}

$view_name = ucfirst($request->apiformat) . 'View';
if (class_exists($view_name)) {
    $view = new $view_name();
    $view->render($result);
} else {
    header("Bad Request", true, 400);
    echo "Error: $request->format output not supported.";
}
