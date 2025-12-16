<?php
/**
 * File: invite_collaborators.php
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

$email = $_SESSION['username'];

$project_id = $_GET['p'] ?? '';
$project_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $project_id);
if($project_id == "") exit("No project id provided.");

$project = $strabo->getProject($project_id);

if($project->Error != "") exit($project->Error);

$db->prepare_query("UPDATE collaborators set disabled = TRUE, accepted = FALSE, collaboration_level = 'readonly' WHERE strabo_project_id = $1 and project_owner_user_pkey = $2", array($project_id, $userpkey));

header("Location: /my_field_data");

?>