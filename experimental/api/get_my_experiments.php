<?php
/**
 * File: get_my_experiments.php
 * Description: Returns all of the current user's projects with their experiments
 *              Used for "Load from Previous Experiment" functionality
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

$userpkey = (int)$_SESSION['userpkey'];

// Include database connection
require_once('../../prepare_connections.php');

try {
    $json = new stdClass();
    $projects = [];

    // Get all projects for this user
    $project_rows = $db->get_results_prepared(
        "SELECT * FROM straboexp.project WHERE userpkey = $1 ORDER BY modified_timestamp DESC",
        array($userpkey)
    );

    if ($project_rows) {
        foreach ($project_rows as $prow) {
            $p = new stdClass();
            $p->pkey = $prow->pkey;
            $p->userpkey = $prow->userpkey;
            $p->uuid = $prow->uuid;
            $p->created_timestamp = $prow->created_timestamp;
            $p->modified_timestamp = $prow->modified_timestamp;
            $p->name = $prow->name;
            $p->notes = $prow->notes;
            $p->ispublic = $prow->ispublic;

            // Get all experiments for this project
            $experiments = [];
            $experiment_rows = $db->get_results_prepared(
                "SELECT * FROM straboexp.experiment WHERE project_pkey = $1 ORDER BY modified_timestamp DESC",
                array($p->pkey)
            );

            if ($experiment_rows) {
                foreach ($experiment_rows as $erow) {
                    $e = new stdClass();
                    $e->pkey = $erow->pkey;
                    $e->project_pkey = $erow->project_pkey;
                    $e->userpkey = $erow->userpkey;
                    $e->id = $erow->id;
                    $e->created_timestamp = $erow->created_timestamp;
                    $e->modified_timestamp = $erow->modified_timestamp;
                    $e->uuid = $erow->uuid;
                    $experiments[] = $e;
                }
            }

            $p->experiments = $experiments;
            $projects[] = $p;
        }
    }

    $json->projects = $projects;
    echo json_encode($json, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()], JSON_PRETTY_PRINT);
}
?>
