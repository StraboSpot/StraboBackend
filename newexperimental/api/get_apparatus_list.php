<?php
/**
 * File: get_apparatus_list.php
 * Description: Returns all facilities with their apparatuses for the apparatus repository
 *              Used for "From Apparatus Repository" functionality
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

// This endpoint is accessible to logged-in users (needed for apparatus selection)
// Guest users can view apparatus repository but not load into experiments
if (!isset($_SESSION['userpkey'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated'], JSON_PRETTY_PRINT);
    exit;
}

// Include database connection
require_once('../../prepare_connections.php');

try {
    $facilities = [];

    // Get all facilities ordered by institute
    $frows = $db->get_results("SELECT * FROM apprepo.facility ORDER BY institute");

    if ($frows) {
        foreach ($frows as $frow) {
            $fpkey = $frow->pkey;

            // Get apparatuses for this facility
            $arows = $db->get_results("SELECT * FROM apprepo.apparatus WHERE facility_pkey = $fpkey ORDER BY modified_timestamp DESC");

            // Only include facilities that have apparatuses
            if ($arows && count($arows) > 0) {
                $f = json_decode($frow->json);
                $f->id = $fpkey;

                // Format timestamps
                if (isset($f->created_timestamp)) {
                    $f->created_timestamp = date("D, M j Y G:i:s T", $f->created_timestamp);
                }
                if (isset($f->modified_timestamp)) {
                    $f->modified_timestamp = date("D, M j Y G:i:s T", $f->modified_timestamp);
                }

                $apps = [];
                foreach ($arows as $arow) {
                    $a = json_decode($arow->json);
                    $a->id = $arow->pkey;

                    // Format timestamps
                    if (isset($a->created_timestamp)) {
                        $a->created_timestamp = date("D, M j Y G:i:s T", $a->created_timestamp);
                    }
                    if (isset($a->modified_timestamp)) {
                        $a->modified_timestamp = date("D, M j Y G:i:s T", $a->modified_timestamp);
                    }

                    $apps[] = $a;
                }

                $f->apparatuses = $apps;
                $facilities[] = $f;
            }
        }
    }

    $data = new stdClass();
    $data->facilities = $facilities;
    echo json_encode($data, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()], JSON_PRETTY_PRINT);
}
?>
