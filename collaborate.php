<?php
/**
 * File: collaborate.php
 * Description: Project Collaborators
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
$project_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $project_id);
if($project_id == "") exit("No project id provided.");

$project = $strabo->getProject($project_id);

if($project->Error != "") exit($project->Error);

$project_name = $project->description->project_name;

$rows = $db->get_results_prepared("
	SELECT 	collaborator_user_pkey,
			collaboration_level,
			accepted,
			uuid,
			(SELECT email FROM users WHERE pkey = collaborator_user_pkey) as email
	FROM collaborators
	WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 and disabled = false
	ORDER BY email
", array($project_id, $userpkey));

include 'includes/mheader.php';
?>
<script>

function updateCollaborationLevel(uuid){
	console.log(uuid + ' changed');

	var collablevel = $('#collaborationlevel_'+uuid).find(":selected").val();
	console.log("level: " + collablevel);

	$.get("/update_collaboration_level?u="+uuid+"&l="+collablevel);

}

</script>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Project Collaborators</h2>
						</header>
<?php
// One-shot result of the last "Invite Collaborators" submit (invite_collaborators.php).
$inviteResults = $_SESSION['invite_results'] ?? null;
unset($_SESSION['invite_results']);
if(is_array($inviteResults) && (count($inviteResults['invited']) || count($inviteResults['updated']) || count($inviteResults['errors']))){
?>
						<div class="invite-results" style="margin:0 0 1.5em 0;padding:1em 1.25em;border-radius:6px;background:rgba(255,255,255,0.06);border-left:4px solid #e44c65;font-size:.95em;line-height:1.5;">
<?php if(count($inviteResults['invited'])){ ?>
							<div><strong>Invited:</strong></div>
							<ul style="margin:0 0 .5em 1.25em;">
<?php foreach($inviteResults['invited'] as $line){ ?>
								<li><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8')?></li>
<?php } ?>
							</ul>
<?php } if(count($inviteResults['updated'])){ ?>
							<div><strong>Updated:</strong></div>
							<ul style="margin:0 0 .5em 1.25em;">
<?php foreach($inviteResults['updated'] as $line){ ?>
								<li><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8')?></li>
<?php } ?>
							</ul>
<?php } if(count($inviteResults['errors'])){ ?>
							<div><strong>Not invited:</strong></div>
							<ul style="margin:0 0 0 1.25em;">
<?php foreach($inviteResults['errors'] as $line){ ?>
								<li><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8')?></li>
<?php } ?>
							</ul>
<?php } ?>
						</div>
<?php } ?>

<?php
if(count($rows) > 0){

?>

<div class="row gtr-uniform gtr-50">

<?php
foreach($rows as $row){
?>
	<div class="col-3 col-12-small">
		<?php echo $row->email?>
	</div>
	<div class="col-3 col-12-small">
		<select name="collaborationlevel_<?php echo $row->uuid?>" onchange="updateCollaborationLevel('<?php echo $row->uuid?>');" id="collaborationlevel_<?php echo $row->uuid?>" class="amyDataSelect">
			<option value="readonly"<?php if($row->collaboration_level=="readonly"){echo " selected";}?>>Read Only</option>
			<option value="edit"<?php if($row->collaboration_level=="edit"){echo " selected";}?>>Edit</option>
		</select>
	</div>
	<div class="col-3 col-12-small">

<?php
if($row->accepted == "t"){
?>

		<div class="button primary fit green">Status: Active</div>

<?php
}else{
?>

		<div class="button primary fit">Status: Pending</div>

<?php
}
?>
	</div>
	<div class="col-3 col-12-small">
		<!--<td style="width:10px;"><a href="delete_collaborator?p=<?php echo $project_id?>&u=<?php echo $row->uuid?>" class="amyDataSelect" onclick="return confirm('Are you sure you want to remove <?php echo $row->email?> from your collaborator list? This user will no longer be able to work on this project.')">X</a></td>-->

		<a href="delete_collaborator?p=<?php echo $project_id?>&u=<?php echo $row->uuid?>" class="button primary fit" onclick="return confirm('Are you sure you want to remove <?php echo $row->email?> from your collaborator list? This user will no longer be able to work on this project.')">Delete</a>

	</div>
	<div class="col-12 col-12-small hideBigNineEighty">
		<hr>
	</div>

<?php
}
?>

</div>

<?php
}else{
?>

		<div class="medHeader" style="padding-top:0px;padding-bottom:20px;text-align:center;">
			No collaborators exist yet for project <?php echo $project_name?>
			<div style="padding-top:30px;">
			</div>

		</div>

<?php
}
?>



		<div class="row aln-center padtop">
			<div class="col-3 col-6-medium col-12-xsmall">
				<ul class="actions stacked">
					<li><a href="invite_collaborators?p=<?php echo $project_id?>" class="button primary fit green">Invite Collaborators</a></li>
				</ul>
			</div>
			<?php if(count($rows) > 0){?>
			<div class="col-3 col-6-medium col-12-xsmall">
				<ul class="actions stacked">
					<li><a href="halt_collaboration?p=<?php echo $project_id?>" class="button primary fit" onclick="return confirm('Are you sure you want to stop collaborating on this project?\nCollaborators will no longer be able to work on this project.')">Halt Collaboration</a></li>
				</ul>
			</div>
			<?php } ?>
		</div>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include 'includes/mfooter.php';

?>