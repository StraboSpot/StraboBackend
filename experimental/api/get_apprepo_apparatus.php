<?php
/**
 * File: get_apprepo_apparatus.php
 * Description: Returns a single apparatus from the apparatus repository
 *              Used when loading apparatus data from apparatus repository selection
 *
 * @package    StraboSpot Experimental
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');

// Check if user is logged in
if (!isset($_SESSION['userpkey'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated'], JSON_PRETTY_PRINT);
    exit;
}

// Get apparatus ID from query parameter
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Apparatus ID not provided'], JSON_PRETTY_PRINT);
    exit;
}

// Include database connection
require_once('../../prepare_connections.php');

try {
    $row = $db->get_row("SELECT * FROM apprepo.apparatus WHERE pkey = $id");

    if (!$row || !$row->pkey) {
        http_response_code(404);
        echo json_encode(['error' => 'Apparatus not found'], JSON_PRETTY_PRINT);
        exit;
    }

    $data = json_decode($row->json);

    // Format timestamps
    if (isset($data->created_timestamp)) {
        $data->created_timestamp = date("D, M j Y G:i:s T", $data->created_timestamp);
    }
    if (isset($data->modified_timestamp)) {
        $data->modified_timestamp = date("D, M j Y G:i:s T", $data->modified_timestamp);
    }

    echo json_encode($data, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()], JSON_PRETTY_PRINT);
}
?>
