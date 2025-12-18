<?php
/**
 * File: get_facilities.php
 * Description: Retrieves facilities with apparatuses and permission flags
 *
 * This endpoint allows both logged-in and guest users to browse the
 * apparatus repository. It returns permission flags so the frontend
 * can conditionally show edit/delete actions.
 *
 * Permission model (matching old apparatus_repository.php):
 * - View: Everyone (guests included)
 * - Add Facility: Admins only
 * - Edit/Delete Facility: Facility PI (in facility_users table) OR Admin
 * - Add Apparatus: Facility PI OR Admin
 * - Edit/Delete Apparatus: Apparatus owner (userpkey matches) OR Admin
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

// Get user info - allow guests (like old apparatus_repository.php)
if ($_SESSION['loggedin'] == "yes") {
    $userpkey = $_SESSION['userpkey'];
} else {
    $userpkey = 99999; // Guest user
}

// Check admin status
$is_admin = in_array($userpkey, $admin_pkeys);

include("prepare_connections.php");

// Query facilities with PI status for current user (matching old apparatus_repository.php query)
$frows = $db->get_results_prepared("
    SELECT
        pkey AS facility_pkey,
        name,
        institute,
        department,
        json,
        CASE
            WHEN admincount > 0 THEN 'yes'
            ELSE 'no'
        END AS is_pi
    FROM (
        SELECT
            pkey,
            name,
            institute,
            department,
            json,
            (SELECT count(*) FROM apprepo.facility_users WHERE users_pkey = $1 AND facility_pkey = f.pkey) AS admincount
        FROM apprepo.facility f
        ORDER BY name
    ) foo
    ORDER BY institute, name
", array($userpkey));

$facilities = [];

foreach ($frows as $frow) {
    // Parse JSON if available, otherwise build from columns
    if (!empty($frow->json)) {
        $f = json_decode($frow->json);
    } else {
        $f = new stdClass();
    }

    // Always use direct columns for key fields (they're authoritative)
    $f->id = $frow->facility_pkey;
    $f->name = $frow->name;
    $f->institute = $frow->institute;
    $f->department = $frow->department;

    // Permission flags for this facility
    $f->is_pi = ($frow->is_pi === 'yes');
    $f->can_edit = ($f->is_pi || $is_admin);
    $f->can_add_apparatus = ($f->is_pi || $is_admin);

    // Get apparatuses for this facility
    $arows = $db->get_results_prepared(
        "SELECT pkey, userpkey, name, type, other_type, json, modified_timestamp FROM apprepo.apparatus WHERE facility_pkey = $1 ORDER BY modified_timestamp DESC",
        array($frow->facility_pkey)
    );

    $apps = [];
    foreach ($arows as $arow) {
        // Parse JSON if available
        if (!empty($arow->json)) {
            $a = json_decode($arow->json);
        } else {
            $a = new stdClass();
        }

        $a->id = $arow->pkey;
        $a->name = $arow->name;

        // Handle "Other Apparatus" type display (matching old code)
        if ($arow->type == "Other Apparatus" && !empty($arow->other_type)) {
            $a->type = $arow->other_type;
        } else {
            $a->type = $arow->type;
        }

        $a->modified_timestamp = date("D, M j Y G:i:s T", $arow->modified_timestamp);

        // Permission flags for this apparatus
        $a->is_owner = ($arow->userpkey == $userpkey);
        $a->can_edit = ($a->is_owner || $is_admin);

        $apps[] = $a;
    }

    $f->apparatuses = $apps;
    $facilities[] = $f;
}

// Build response
$data = new stdClass();
$data->facilities = $facilities;
$data->user = new stdClass();
$data->user->is_logged_in = ($_SESSION['loggedin'] == "yes");
$data->user->is_admin = $is_admin;
$data->user->userpkey = $userpkey;

header('Content-type: application/json');
echo json_encode($data, JSON_PRETTY_PRINT);
