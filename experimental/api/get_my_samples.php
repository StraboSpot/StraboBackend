<?php
/**
 * File: get_my_samples.php
 * Description: StraboSamples picker list for "Link Sample From
 *              StraboSamples" (Exp_StraboSamples_Linking.md §5.4). Returns
 *              the session user's spine samples (own + accepted
 *              collaborator-on) with the flags the picker needs: slice
 *              presence (lock rule) + experimental link count. The Exp web
 *              app uses session auth, not the JWT /samplesjwtdb/ routes the
 *              desktop apps call.
 *
 * Returns: { "samples": [...], "count": N }
 * Requires login.
 */

// Change to root directory for proper include path resolution
chdir('../..');

session_start();

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
    $_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

header('Content-type: application/json');

// Require login
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$userpkey = (int)$_SESSION['userpkey'];

include("prepare_connections.php");
require_once("samplesdb/services/StraboSamplesService.php");

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);

$samples = $svc->listMySamples(array('include_subsystem_flags' => true));

// viewer_pkey lets the picker distinguish own vs collaborated samples (the
// Exp Vue app has no client-side auth store to compare against).
echo json_encode(array(
    'samples'     => $samples,
    'count'       => count($samples),
    'viewer_pkey' => $userpkey,
), JSON_PRETTY_PRINT);
