<?php
/**
 * File: activate_version.php
 * Description: Restores (activates) a saved project version. Rebuilds the
 *              pre-Vue-rewrite experimental/activate_version.php deleted in
 *              7df1cfc. Link-navigated from versioning.php, so it responds
 *              with a redirect rather than JSON.
 *
 * Query params:
 *   p - straboexp.versions pkey (required; must belong to the session user)
 *
 * Snapshots the CURRENT project state first (so activation is undoable),
 * then restores the selected version. restoreVersion() handles the
 * strabosamples-spine teardown for samples that don't come back.
 */

// Change to root directory for proper include path resolution
chdir('../..');

session_start();

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
    $_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

// Require login
if ($_SESSION['loggedin'] != "yes") {
    header("Location: /login");
    exit;
}

$userpkey = $_SESSION['userpkey'];

$version_pkey = isset($_GET['p']) ? (int)$_GET['p'] : 0;
if ($version_pkey <= 0) {
    http_response_code(400);
    exit("version not specified.");
}

include("prepare_connections.php");
include_once("includes/UUID.php");
include_once("expdb/straboexpclass.php");

$exp = new StraboExp($neodb, $userpkey, $db);
$uuid_gen = new UUID();
$exp->setuuid($uuid_gen);

$uuid = $db->get_var_prepared(
    "SELECT uuid FROM straboexp.versions WHERE pkey = $1 AND userpkey = $2",
    array($version_pkey, $userpkey)
);
if (empty($uuid)) {
    http_response_code(404);
    exit("version not found on server.");
}

// Snapshot the CURRENT state before restoring, so the activation itself
// can be undone from versioning.php.
$project_pkey = $db->get_var_prepared(
    "SELECT pkey FROM straboexp.project WHERE uuid = $1 AND userpkey = $2",
    array($uuid, $userpkey)
);
if (!empty($project_pkey)) {
    $exp->createProjectVersion((int)$project_pkey);
}

if (!$exp->restoreVersion($version_pkey)) {
    http_response_code(404);
    exit("version not found on server.");
}

header("Location: /my_experimental_data");
