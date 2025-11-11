<?php
/**
 * File: delete_micrograph_project.php
 * Description: Deletes projects and all associated data from the system
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");

include("prepare_connections.php");

$id = $_GET['project_id'];

$sm->deleteProject($id);

header("Location:/my_micro_data");

?>