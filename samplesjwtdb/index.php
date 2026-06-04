<?php
/**
 * File: index.php
 * Description: StraboSamples REST API entry point — JWT Authenticated.
 *              Mirrors /jwtdb/'s use of jwtauth/middleware.php for
 *              Bearer-token validation. Shares samplesdb/'s controllers
 *              and service class; only the auth gate differs.
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
include_once "../db.php";
include_once "../neodb.php";
include_once "../samplesdb/services/StraboSamplesService.php";
include_once "../includes/UUID.php";

include_once "../jwtauth/middleware.php";

// Authenticate via JWT
$user = authenticate();
$userpkey = (int)$user['sub'];

// Load Base Controller
include "../samplesdb/controllers/MyController.php";

// Load Additional Controllers
foreach (glob("../samplesdb/controllers/*.php") as $filename) {
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
