<?php
/**
 * File: spotjson.php
 * Description: Returns geological spot data in JSON format
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("softlogincheck.php"); // resolves the session (2-hour timeout) without redirecting anonymous readers

include_once "./includes/config.inc.php";
include("db.php");

$id = isset($_GET['id']) ? trim($_GET['id']) : '';
$userpkey = isset($_SESSION['userpkey']) ? (int)$_SESSION['userpkey'] : 0;

header('Content-Type: application/json');

$json = "";
if($id != ""){
	// Visible when the spot's project is public, or the signed-in user owns it or is an
	// accepted, active collaborator. Anything else answers exactly like a missing spot.
	$json = $db->get_var_prepared("
		SELECT s.spotjson
		FROM spot s
		JOIN project p ON p.project_pkey = s.project_pkey
		WHERE s.strabo_spot_id = $1
		AND (
			p.ispublic
			OR p.user_pkey = $2
			OR EXISTS (
				SELECT 1 FROM collaborators c
				WHERE c.strabo_project_id = p.strabo_project_id
				AND c.project_owner_user_pkey = p.user_pkey
				AND c.collaborator_user_pkey = $2
				AND c.accepted = true
				AND c.disabled = false
			)
		)
		LIMIT 1
	", array($id, $userpkey));
}

if($json != ""){
	echo $json;
}else{
	http_response_code(404);
	echo "{\"Error\":\"Spot not found.\"}";
}
