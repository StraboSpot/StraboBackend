<?php
/**
 * File: index.php
 * Description: Front controller for the StraboField over-the-air update
 *              server, /ota/ (Expo Updates protocol v1).
 *                GET /ota/manifest              phones (no auth)
 *                GET|HEAD /ota/assets/<file>    phones (no auth)
 *                /ota/publish/...               publish script (Bearer token)
 *              Design: docs/StraboField_Update_Server/DESIGN.md.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once "../includes/config.inc.php";
include_once "../includes/ota/Ota.php";
include_once "../includes/ota/OtaServe.php";
include_once "../includes/ota/OtaPublish.php";

$method = strtoupper($_SERVER['REQUEST_METHOD']);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pos = strpos($path, '/ota/');
$route = $pos === false ? '' : trim(substr($path, $pos + strlen('/ota/')), '/');

$routes = array(
	array('GET',  '#^manifest$#',                                   array('OtaServe', 'manifest'), false),
	array('GET',  '#^assets/([0-9a-f]{64})((?:\.[A-Za-z0-9]{1,10})?)$#', 'asset', false),
	array('HEAD', '#^assets/([0-9a-f]{64})((?:\.[A-Za-z0-9]{1,10})?)$#', 'asset', false),
	array('GET',  '#^publish/ping$#',                               array('OtaPublish', 'ping'), true),
	array('POST', '#^publish/assets/missing$#',                     array('OtaPublish', 'missing'), true),
	array('PUT',  '#^publish/assets/([0-9a-f]{64})$#',              array('OtaPublish', 'putAsset'), true),
	array('POST', '#^publish/releases$#',                           array('OtaPublish', 'createRelease'), true),
	array('GET',  '#^publish/releases$#',                           array('OtaPublish', 'listReleases'), true),
	array('GET',  '#^publish/releases/([1-9][0-9]{0,9})$#',         array('OtaPublish', 'getRelease'), true),
	array('POST', '#^publish/releases/([1-9][0-9]{0,9})/withdraw$#', array('OtaPublish', 'withdraw'), true),
	array('POST', '#^publish/releases/([1-9][0-9]{0,9})/restore$#', array('OtaPublish', 'restore'), true),
	array('GET',  '#^publish/stats$#',                              array('OtaPublish', 'stats'), true),
);

$handler = null;
$args = array();
$auth = false;
$pathMatched = false;
foreach ($routes as $r) {
	if (preg_match($r[1], $route, $mm)) {
		$pathMatched = true;
		if ($r[0] === $method) {
			$handler = $r[2];
			$args = array_slice($mm, 1);
			$auth = $r[3];
			break;
		}
	}
}

try {
	if ($handler === null) {
		if ($pathMatched) {
			throw new OtaError(405, 'method_not_allowed', "$method is not supported here");
		}
		throw new OtaError(404, 'not_found', 'Unknown endpoint');
	}
	if (!Ota::enabled()) {
		if ($route === 'manifest') {
			OtaServe::noUpdate(); // switched off: phones simply see no update
			exit;
		}
		throw new OtaError(503, 'ota_disabled', 'The update server is not enabled');
	}
	if ($auth) {
		OtaPublish::authorize();
	}
	include_once "../db.php";
	$odb = new OtaDb($db);
	if ($handler === 'asset') {
		OtaServe::asset($odb, $args[0], $args[1], $method === 'HEAD');
	} else {
		call_user_func_array($handler, array_merge(array($odb), $args));
	}
} catch (OtaError $e) {
	if (!headers_sent()) {
		if ($e->status === 500) {
			error_log('ota ' . $method . ' ' . $route . ': ' . $e->errorCode . ': ' . $e->getMessage());
		}
		Ota::error($e->status, $e->errorCode, $e->getMessage());
	}
} catch (Throwable $e) {
	error_log('ota ' . $method . ' ' . $route . ': ' . get_class($e) . ': ' . $e->getMessage()
		. ' at ' . $e->getFile() . ':' . $e->getLine());
	if (!headers_sent()) {
		Ota::error(500, 'server_error', 'The server could not complete this request');
	}
}
