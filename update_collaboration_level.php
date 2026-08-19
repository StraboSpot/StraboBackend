<?php
/**
 * File: update_collaboration_level.php
 * Description: Collaboration management interface
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");

$level = $_GET['l'] ?? '';
if(!in_array($level, array('readonly', 'edit'), true)){
	exit("Invalid collaboration level.");
}

$uuid = $_GET['u'] ?? '';
$uuid = preg_replace('/[^a-zA-Z0-9\-]/', '', $uuid);
if($uuid == "") exit("No uuid provided.");

// Only the project owner may change a collaborator's level. Scoping the UPDATE
// by project_owner_user_pkey means a non-owner (or the collaborator themselves)
// matches zero rows, closing the self-escalation / tamper path.
$db->prepare_query(
	"UPDATE collaborators SET collaboration_level = $1 WHERE uuid = $2 AND project_owner_user_pkey = $3",
	array($level, $uuid, $userpkey)
);

?>