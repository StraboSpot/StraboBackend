<?php
/**
 * File: deny_collaboration.php
 * Description: Denies collaboration requests
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");

$project_id = $_GET['p'] ?? '';
if(!is_numeric($project_id) || $project_id == "") exit("Invalid project id provided.");

$uuid = $_GET['u'] ?? '';
$uuid = preg_replace('/[^a-zA-Z0-9\-]/', '', $uuid);
if($uuid == "") exit("No uuid provided.");

$count = $db->get_var_prepared("SELECT count(*) FROM collaborators WHERE uuid = $1", array($uuid));
if($count == 0){
	sleep(1);
	exit("Collaboration Invite not Found.");
}

// Denying an invitation is an action for the invited user. Scope the UPDATE to
// the invitee so a stranger who knows the uuid cannot disable the collaboration.
$db->prepare_query("update collaborators set disabled = true WHERE uuid = $1 AND collaborator_user_pkey = $2", array($uuid, $userpkey));

header("Location: my_field_data");

?>