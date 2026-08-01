<?php
/**
 * File: project_public.php
 * Description: Toggles the public/private flag on an experimental project.
 *              Replaces the pre-Vue experimental/project_public.php deleted
 *              in the rewrite (7df1cfc); the my_experimental_data.php toggle
 *              had been calling a URL the SPA rewrite swallowed.
 *
 * Query params:
 *   projectid - Project pkey (required)
 *   state     - "public" or "private" (anything else means private)
 *
 * Returns JSON {success, pkey, ispublic}. Owner-only.
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

$userpkey = $_SESSION['userpkey'];

$project_pkey = isset($_GET['projectid']) ? (int)$_GET['projectid'] : 0;
if ($project_pkey <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Project ID is required']);
    exit;
}

$state = $_GET['state'] ?? '';
$ispublic = ($state === 'public');

include("prepare_connections.php");

// Verify ownership — the toggle is owner-only (no admin path, matching
// the pre-rewrite endpoint).
$owned = $db->get_var_prepared(
    "SELECT pkey FROM straboexp.project WHERE pkey = $1 AND userpkey = $2",
    array($project_pkey, $userpkey)
);
if (empty($owned)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found or access denied']);
    exit;
}

$db->prepare_query(
    "UPDATE straboexp.project SET ispublic = $1 WHERE pkey = $2 AND userpkey = $3",
    array($ispublic ? 't' : 'f', $project_pkey, $userpkey)
);

// StraboSearch live-sync (§5.3): ACL flip — rebuild the project's index
// slice so project_ispublic follows immediately (a public→private toggle
// must not leave items publicly searchable until the nightly heal).
require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';
StraboSearchSync::touchExpProject($db, $project_pkey);

$result = new stdClass();
$result->success = true;
$result->pkey = $project_pkey;
$result->ispublic = $ispublic;

echo json_encode($result, JSON_PRETTY_PRINT);
