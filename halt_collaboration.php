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

// Halt = suspend collaboration: collaborators drop to read-only while the owner
// regains full control. Keep accepted=TRUE so getProjectContext() still resolves
// these rows and maps disabled -> readonly (CollaborationAuth.php). Clearing
// accepted here would instead resolve them to 'none' (no access at all), which
// is revoke semantics, not halt. The collaboration_level is preserved so a later
// re-enable restores the original level.
$db->prepare_query("UPDATE collaborators set disabled = TRUE WHERE strabo_project_id = $1 and project_owner_user_pkey = $2", array($project_id, $userpkey));

header("Location: /my_field_data");

?>