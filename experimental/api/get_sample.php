<?php
/**
 * File: get_sample.php
 * Description: Full StraboSamples record for prefill after a picker
 *              selection (Exp_StraboSamples_Linking.md §5.4): spine fields,
 *              per-subsystem slices (lock rule derives from
 *              field_data/micro_data presence), subsystem_links, parent.
 *
 * Query params:
 *   id    - spine sample id (required)
 *   owner - owner userpkey (optional; needed for collaborator-on samples
 *           where the owner differs from the session user)
 *
 * Returns: { "sample": {...} }; 404 when the sample does not exist OR the
 * session user has no read access (existence-hiding, same posture as the
 * samples API).
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

$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Sample id is required']);
    exit;
}
$owner = isset($_GET['owner']) ? (int)$_GET['owner'] : 0;

$userpkey = (int)$_SESSION['userpkey'];

include("prepare_connections.php");
require_once("samplesdb/services/StraboSamplesService.php");

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);

// getSample() gates on canRead (owner or accepted collaborator) and
// returns null otherwise — surfaced as 404 either way.
$sample = $svc->getSample($id, $owner > 0 ? $owner : null);
if ($sample === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Sample not found']);
    exit;
}

echo json_encode(array('sample' => $sample), JSON_PRETTY_PRINT);
