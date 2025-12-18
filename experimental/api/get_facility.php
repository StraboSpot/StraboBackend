<?php
/**
 * File: get_facility.php
 * Description: Retrieves a single facility (guest-friendly)
 *
 * This endpoint allows both logged-in and guest users to view facility details.
 */

// Change to root directory for proper include path resolution
chdir('../..');

include_once("adminkeys.php");

session_start();

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
    $_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

// Get user info - allow guests
if ($_SESSION['loggedin'] == "yes") {
    $userpkey = $_SESSION['userpkey'];
} else {
    $userpkey = 99999; // Guest user
}

// Check admin status
$is_admin = in_array($userpkey, $admin_pkeys);

include("prepare_connections.php");

// Get facility ID from query param
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id == 0) {
    $error = new stdClass();
    $error->Error = "Id not provided.";
    header('Content-type: application/json');
    echo json_encode($error, JSON_PRETTY_PRINT);
    exit();
}

// Query facility
$row = $db->get_row("SELECT * FROM apprepo.facility WHERE pkey = $id");

if (empty($row->pkey)) {
    $error = new stdClass();
    $error->Error = "Facility not found.";
    header('Content-type: application/json');
    echo json_encode($error, JSON_PRETTY_PRINT);
    exit();
}

// Parse JSON if available
if (!empty($row->json)) {
    $data = json_decode($row->json);
} else {
    $data = new stdClass();
}

// Add/override with direct columns
$data->id = $row->pkey;
$data->name = $row->name;
$data->institute = $row->institute;
$data->department = $row->department;

$data->created_timestamp = date("D, M j Y G:i:s T", $row->created_timestamp);
$data->modified_timestamp = date("D, M j Y G:i:s T", $row->modified_timestamp);

// Check if user is a PI for this facility
$pi_count = $db->get_var_prepared(
    "SELECT count(*) FROM apprepo.facility_users WHERE users_pkey = $1 AND facility_pkey = $2",
    array($userpkey, $id)
);
$is_pi = ($pi_count > 0);

// Permission flags
$data->is_pi = $is_pi;
$data->can_edit = ($is_pi || $is_admin);
$data->can_add_apparatus = ($is_pi || $is_admin);

header('Content-type: application/json');
echo json_encode($data, JSON_PRETTY_PRINT);
