<?php
/**
 * File: micro_project_landing_page.php
 * Description: Application landing page
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


session_start();

if($_SESSION['userpkey']!=""){
	$userpkey = $_SESSION['userpkey'];
}else{
	$userpkey = 0;
}

$p = $_GET['p'];
include("prepare_connections.php");
require_once(__DIR__ . '/microdb/lib/permalink.php');

$project_row = $db->get_row_prepared("select * from micro_projectmetadata where strabo_id=$1 and (ispublic or userpkey = $2)", array($p, $userpkey));
$project_pkey = ($project_row && $project_row->id != "") ? $project_row->id : "";

if($project_pkey != ""){
	// Upload-stable permalink into the tier-agnostic front door; falls back
	// to the legacy pkey form if a slug cannot be minted.
	$murl = micro_permalink_landing_url($db, $project_row);
}

if($project_pkey == ""){
	include("includes/mheader.php");
?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">
						<header class="major">
							<h2>Project Not Found.</h2>
						</header>
					<div class="bottomSpacer"></div>
					</div>
				</div>
<?php
	include("includes/mfooter.php");
	exit();
}

header("Location: $murl");

?>

?>