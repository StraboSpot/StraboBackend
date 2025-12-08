<?php
/**
 * File: get_apparatus.php
 * Description: Retrieves a single apparatus (guest-friendly)
 *
 * This endpoint allows both logged-in and guest users to view apparatus details.
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

// Get apparatus ID from query param
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id == 0) {
    $error = new stdClass();
    $error->Error = "Id not provided.";
    header('Content-type: application/json');
    echo json_encode($error, JSON_PRETTY_PRINT);
    exit();
}

// Query apparatus
$row = $db->get_row("SELECT * FROM apprepo.apparatus WHERE pkey = $id");

if (empty($row->pkey)) {
    $error = new stdClass();
    $error->Error = "Apparatus not found.";
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
$data->pkey = $row->pkey;
$data->id = $row->apparatus_id;  // User-entered apparatus ID
$data->facility_id = $row->facility_pkey;
$data->name = $row->name;

// Handle "Other Apparatus" type display
if ($row->type == "Other Apparatus" && !empty($row->other_type)) {
    $data->type = $row->other_type;
} else {
    $data->type = $row->type;
}

$data->created_timestamp = date("D, M j Y G:i:s T", $row->created_timestamp);
$data->modified_timestamp = date("D, M j Y G:i:s T", $row->modified_timestamp);

// Permission flags
$data->is_owner = ($row->userpkey == $userpkey);
$data->can_edit = ($data->is_owner || $is_admin);

header('Content-type: application/json');
echo json_encode($data, JSON_PRETTY_PRINT);
