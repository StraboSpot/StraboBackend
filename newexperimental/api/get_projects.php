<?php
/**
 * File: get_projects.php
 * Description: Retrieves user's experimental projects
 *
 * Requires login - returns projects owned by the current user.
 * Used by the project management views in the new experimental app.
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

// Require login for project access
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userpkey = $_SESSION['userpkey'];

include("prepare_connections.php");

// Query user's projects with experiment counts
$rows = $db->get_results_prepared("
    SELECT
        p.pkey,
        p.uuid,
        p.name,
        p.notes,
        p.ispublic,
        to_char(p.created_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY') as created_date,
        to_char(p.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') as modified_date,
        EXTRACT(EPOCH FROM p.created_timestamp)::integer as created_timestamp,
        EXTRACT(EPOCH FROM p.modified_timestamp)::integer as modified_timestamp,
        (SELECT COUNT(*) FROM straboexp.experiment e WHERE e.project_pkey = p.pkey) as experiment_count
    FROM straboexp.project p
    WHERE p.userpkey = $1
    ORDER BY p.modified_timestamp DESC
", array($userpkey));

$projects = [];

foreach ($rows as $row) {
    $p = new stdClass();
    $p->pkey = (int)$row->pkey;
    $p->uuid = $row->uuid;
    $p->name = $row->name;
    $p->description = $row->notes;
    $p->is_public = ($row->ispublic === 't' || $row->ispublic === true);
    $p->created_date = $row->created_date;
    $p->modified_date = $row->modified_date;
    $p->created_timestamp = (int)$row->created_timestamp;
    $p->modified_timestamp = (int)$row->modified_timestamp;
    $p->experiment_count = (int)$row->experiment_count;

    $projects[] = $p;
}

// Build response
$data = new stdClass();
$data->projects = $projects;

echo json_encode($data, JSON_PRETTY_PRINT);
