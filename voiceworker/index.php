<?php
/**
 * File: index.php
 * Description: Front controller for the Voice Stations worker API,
 *              /voiceworker/v1/ (build step 2, docs/AlternateStraboFieldIdea/
 *              Phase1_Plan.md). Transcription and extraction workers claim
 *              one station at a time, fetch its audio, keep their lease
 *              alive, and post a result or a failure. Logic in
 *              voicestations/lib/VsWorker.php.
 *
 *              Outside /db/ on purpose: Apache's Basic Auth gate there
 *              rejects Bearer tokens. Workers authenticate with their own
 *              token (VOICESTATIONS_WORKERS in config.inc.php), never a user
 *              login. JSON in and out, errors {"Error", "code", "field"}.
 *              Must run on PHP 7.3 (production).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once "../includes/config.inc.php";
include_once "../db.php"; // at global scope: it reads config's globals; connects lazily
require_once "../voicestations/lib/bootstrap.php";
// Scoring reads Neo4j (uploaded Spots, Stopwatch Spots). neodb.php reads
// config globals, so it loads here at global scope, and only for score/*.
if (strpos((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/voiceworker/v1/score/') !== false) {
	include_once "../neodb.php";
	require_once "../db/strabospotclass.php";
	include_once "../includes/geophp/geoPHP.inc";
	include_once "../includes/UUID.php";
}

const VOICEWORKER_API_VERSION = 1;

VsHttp::run(function () use ($db) {
	$method = strtoupper($_SERVER['REQUEST_METHOD']);
	$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$pos = strpos($path, '/voiceworker/');
	$path = $pos === false ? '' : trim(substr($path, $pos + strlen('/voiceworker/')), '/');
	if (!preg_match('#^v1(?:/(.*))?$#', $path, $m)) {
		throw VsHttp::notFound('Unknown API version; use /voiceworker/v1/');
	}
	$route = isset($m[1]) ? $m[1] : '';

	if ($route === 'ping' && $method === 'GET') {
		return array(200, array('ok' => true, 'apiVersion' => VOICEWORKER_API_VERSION));
	}

	// Scoring (step 6): its own token (VOICESTATIONS_SCORER); worker tokens
	// never reach these, the scorer token never reaches the job routes.
	if (preg_match('#^score/(export|results)$#', $route, $sm)) {
		$want = $sm[1] === 'export' ? 'GET' : 'POST';
		if ($method !== $want) {
			throw new VsHttpError(405, 'method_not_allowed', "$method is not supported here.");
		}
		$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION']
			: (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');
		if (!VsScore::authorized($auth, defined('VOICESTATIONS_SCORER') ? VOICESTATIONS_SCORER : null)) {
			header('WWW-Authenticate: Bearer');
			throw new VsHttpError(401, 'unauthorized', 'A valid scorer token is required.');
		}
		global $neodb;
		$score = new VsScore($db, $neodb);
		return $sm[1] === 'export' ? $score->export() : $score->saveResults();
	}

	$uuid = '([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';
	$routes = array(
		array('POST', '#^claim$#',                  'claim'),
		array('GET',  "#^jobs/$uuid/audio$#",       'audio'),
		array('POST', "#^jobs/$uuid/heartbeat$#",   'heartbeat'),
		array('POST', "#^jobs/$uuid/result$#",      'result'),
		array('POST', "#^jobs/$uuid/fail$#",        'fail'),
	);
	$handler = null;
	$args = array();
	$pathMatched = false;
	foreach ($routes as $r) {
		if (preg_match($r[1], $route, $mm)) {
			$pathMatched = true;
			if ($r[0] === $method) {
				$handler = $r[2];
				$args = array_map('strtolower', array_slice($mm, 1));
				break;
			}
		}
	}
	if ($handler === null) {
		if ($pathMatched) {
			throw new VsHttpError(405, 'method_not_allowed', "$method is not supported here.");
		}
		throw VsHttp::notFound('Unknown endpoint.');
	}

	$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION']
		: (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');
	$who = VsWorker::identify($auth, defined('VOICESTATIONS_WORKERS') ? VOICESTATIONS_WORKERS : null);
	if ($who === null) {
		header('WWW-Authenticate: Bearer');
		throw new VsHttpError(401, 'unauthorized', 'A valid worker token is required.');
	}

	$worker = new VsWorker($db, $who[0], $who[1]);
	return call_user_func_array(array($worker, $handler), $args);
});
