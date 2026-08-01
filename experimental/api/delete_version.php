<?php
/**
 * File: delete_version.php
 * Description: Deletes a saved project version snapshot. Rebuilds the
 *              pre-Vue-rewrite experimental/delete_version.php deleted in
 *              7df1cfc. Link-navigated from versioning.php, so it responds
 *              with a redirect rather than JSON.
 *
 * Query params:
 *   p - straboexp.versions pkey (required; must belong to the session user)
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

$db->prepare_query(
    "DELETE FROM straboexp.versions WHERE pkey = $1 AND userpkey = $2",
    array($version_pkey, $userpkey)
);

header("Location: /versioning");
