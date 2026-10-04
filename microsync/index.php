<?php
/**
 * File: index.php
 * Description: Front controller for the StraboMicro sync API, /microsync/v1/
 *              (collaboration Phase 0; design in the StraboMicro2 repo,
 *              docs/specs/collaboration-phase0-design.md §4).
 *
 *              Off unless config.inc.php defines MICROSYNC_ENABLED as true;
 *              every request then answers 503. JSON in and out except blob
 *              bodies. Authorization: Bearer <JWT> on everything but ping.
 *              Never pre-reads the request body (chunk uploads stream it).
 *              Must run on PHP 7.3 (production).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once "../includes/config.inc.php";
include_once "./lib/MsHttp.php";

if (!defined('MICROSYNC_ENABLED') || MICROSYNC_ENABLED !== true) {
	MsHttp::error(503, 'sync_disabled', 'StraboMicro sync is not enabled on this server');
	exit;
}

include_once "./lib/MsDb.php";
include_once "./lib/MsModel.php";
include_once "./lib/MsStore.php";
include_once "./lib/MsHistory.php";
include_once "./lib/MsProjects.php";
include_once "./lib/MsSync.php";
include_once "./lib/MsBlobs.php";
include_once "./lib/MsActivity.php";
include_once "./lib/MsWorker.php";
include_once "./lib/MsSmz.php";
include_once "./lib/MsAdopt.php";
include_once "./lib/MsMembers.php";
include_once "./lib/MsParked.php";
include_once "./lib/MsDelete.php";

const MICROSYNC_API_VERSION = 1;

$method = strtoupper($_SERVER['REQUEST_METHOD']);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pos = strpos($path, '/microsync/');
$path = $pos === false ? '' : trim(substr($path, $pos + strlen('/microsync/')), '/');

if (!preg_match('#^v1(?:/(.*))?$#', $path, $m)) {
	MsHttp::error(404, 'not_found', 'Unknown API version; use /microsync/v1/');
	exit;
}
$route = isset($m[1]) ? $m[1] : '';

if ($route === 'ping' && $method === 'GET') {
	MsHttp::json(200, array('ok' => true, 'apiVersion' => MICROSYNC_API_VERSION));
	exit;
}

// [method, pattern, handler]; pattern groups become handler arguments.
$pidPattern = '([1-9][0-9]{0,8})';
$userPattern = '([1-9][0-9]{0,9})';
$uuidPattern = '([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';
$routes = array(
	array('GET',    '#^projects$#',                                              array('MsProjects', 'listMine')),
	array('POST',   '#^projects$#',                                              array('MsProjects', 'create')),
	array('GET',    "#^projects/$pidPattern$#",                                  array('MsProjects', 'get')),
	array('DELETE', "#^projects/$pidPattern$#",                                  array('MsProjects', 'delete')),
	array('POST',   "#^projects/$pidPattern/ready$#",                            array('MsProjects', 'ready')),
	array('POST',   "#^projects/$pidPattern/adopt$#",                            array('MsAdopt', 'start')),
	array('DELETE', "#^projects/$pidPattern/adopt$#",                            array('MsAdopt', 'cancel')),
	array('GET',    "#^projects/$pidPattern/snapshot$#",                         array('MsProjects', 'snapshot')),
	array('GET',    "#^projects/$pidPattern/smz$#",                              array('MsProjects', 'smz')),
	array('POST',   "#^projects/$pidPattern/push$#",                             array('MsSync', 'push')),
	array('GET',    "#^projects/$pidPattern/changes$#",                          array('MsSync', 'changes')),
	array('GET',    "#^projects/$pidPattern/history$#",                          array('MsSync', 'history')),
	array('POST',   "#^projects/$pidPattern/activity$#",                         array('MsActivity', 'poll')),
	array('GET',    "#^projects/$pidPattern/parked$#",                           array('MsParked', 'listPending')),
	array('POST',   "#^projects/$pidPattern/parked/([0-9]{1,10})/review$#",      array('MsParked', 'review')),
	array('HEAD',   "#^projects/$pidPattern/blobs/([0-9a-f]{64})$#",             array('MsBlobs', 'head')),
	array('GET',    "#^projects/$pidPattern/blobs/([0-9a-f]{64})$#",             array('MsBlobs', 'get')),
	array('POST',   "#^projects/$pidPattern/uploads$#",                          array('MsBlobs', 'startUpload')),
	array('PUT',    "#^projects/$pidPattern/uploads/$uuidPattern$#",             array('MsBlobs', 'putChunk')),
	array('POST',   "#^projects/$pidPattern/uploads/$uuidPattern/complete$#",    array('MsBlobs', 'complete')),
	array('PUT',    "#^projects/$pidPattern/refs$#",                             array('MsBlobs', 'putRef')),
	array('DELETE', "#^projects/$pidPattern/refs$#",                             array('MsBlobs', 'deleteRef')),
	// Membership (Phase 2)
	array('GET',    "#^projects/$pidPattern/members$#",                          array('MsMembers', 'listMembers')),
	array('POST',   "#^projects/$pidPattern/members$#",                          array('MsMembers', 'invite')),
	array('PATCH',  "#^projects/$pidPattern/members/$userPattern$#",             array('MsMembers', 'changeRole')),
	array('DELETE', "#^projects/$pidPattern/members/$userPattern$#",             array('MsMembers', 'remove')),
	array('POST',   "#^projects/$pidPattern/transfer$#",                         array('MsMembers', 'offerTransfer')),
	array('DELETE', "#^projects/$pidPattern/transfer$#",                         array('MsMembers', 'cancelTransfer')),
	array('POST',   "#^projects/$pidPattern/transfer/accept$#",                  array('MsMembers', 'acceptTransfer')),
	array('POST',   "#^projects/$pidPattern/transfer/decline$#",                 array('MsMembers', 'declineTransfer')),
	array('GET',    '#^invites$#',                                               array('MsMembers', 'myInvites')),
	array('POST',   "#^invites/$pidPattern/accept$#",                            array('MsMembers', 'acceptInvite')),
	array('POST',   "#^invites/$pidPattern/decline$#",                           array('MsMembers', 'declineInvite')),
);

$handler = null;
$args = array();
$pathMatched = false;
foreach ($routes as $r) {
	if (preg_match($r[1], $route, $mm)) {
		$pathMatched = true;
		if ($r[0] === $method) {
			$handler = $r[2];
			$args = array_slice($mm, 1);
			break;
		}
	}
}
if ($handler === null) {
	if ($pathMatched) {
		MsHttp::error(405, 'method_not_allowed', "$method is not supported here");
	} else {
		MsHttp::error(404, 'not_found', 'Unknown endpoint');
	}
	exit;
}

// Auth (sends 401 JSON and exits on failure). Also loads $db.
include_once "../jwtauth/middleware.php";
$token = authenticate();

$ctx = new stdClass();
$ctx->me = (int)$token['sub'];
$ctx->pushId = null;
$ctx->project = null;

try {
	$ctx->db = new MsDb($db);
	$ctx->strabodb = $db; // wrapper, for shared helpers (StraboSamples overlay)
	foreach ($args as $i => $a) {
		if ($i === 0) {
			$args[$i] = (int)$a; // pid
		}
	}
	call_user_func_array($handler, array_merge(array($ctx), $args));
} catch (MsHttpError $e) {
	if (isset($ctx->db)) {
		$ctx->db->rollback();
	}
	if (!headers_sent()) {
		MsHttp::error($e->status, $e->errorCode, $e->getMessage(), $e->extra);
	}
} catch (Throwable $e) {
	if (isset($ctx->db)) {
		$ctx->db->rollback();
	}
	error_log('microsync ' . $method . ' ' . $route . ': ' . get_class($e) . ': ' . $e->getMessage()
		. ' at ' . $e->getFile() . ':' . $e->getLine());
	if (!headers_sent()) {
		$extra = (defined('MICROSYNC_DEBUG') && MICROSYNC_DEBUG === true) ? array('detail' => $e->getMessage()) : array();
		MsHttp::error(500, 'server_error', 'The server could not complete this request', $extra);
	}
}
