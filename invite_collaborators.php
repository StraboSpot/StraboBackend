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
require_once("db/services/CollaborationAuth.php");

$collabAuth = new CollaborationAuth($db, $neodb);

$email = $_SESSION['username'];

$project_id = $_GET['p'] ?? '';
$project_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $project_id);
if($project_id == "") exit("No project id provided.");

$project = $strabo->getProject($project_id);

if($project->Error != "") exit($project->Error);

$project_name = $project->description->project_name ?? '';
if($project_name === ''){
	// Older projects may carry no json_description; fall back to the owner's PG mirror row.
	$project_name = (string)$db->get_var_prepared("SELECT project_name FROM project WHERE strabo_project_id = $1 AND user_pkey = $2 ORDER BY last_modified DESC NULLS LAST LIMIT 1", array($project_id, $userpkey));
}

/**
 * Email the invitee (includes/StraboMail.php). Returns a short status suffix for
 * the results banner; a mail failure never blocks the invitation.
 */
function invitationMail($address, $collaborator_pkey, $inviter, $project_name, $level, $levelLabels){
	global $db;
	require_once __DIR__ . '/includes/StraboMail.php';
	$site = 'https://strabospot.org';
	$invitee = $db->get_row_prepared("SELECT firstname FROM users WHERE pkey = $1", array($collaborator_pkey));
	$who = $inviter ? trim($inviter->firstname . ' ' . $inviter->lastname) : '';
	$who = $who !== '' ? $who : 'A StraboSpot user';
	$whoMail = $inviter ? $inviter->email : '';
	$label = $levelLabels[$level] ?? $level;
	$m = StraboMail::render(array(
		'title'    => 'You are invited to collaborate on a StraboField project',
		'greeting' => 'Hi ' . (($invitee && $invitee->firstname !== '') ? $invitee->firstname : 'there') . ',',
		'intro'    => array("$who" . ($whoMail !== '' ? " ($whoMail)" : '') . " has invited you to collaborate on the StraboField project \"$project_name\"."),
		'facts'    => array(
			'Project'    => $project_name,
			'Invited by' => $who . ($whoMail !== '' ? " ($whoMail)" : ''),
			'Access'     => $label . ($level === 'edit' ? ' (you can add and change data in this project)' : ' (you can view and download this project)'),
		),
		'button'   => array('Review the invitation', "$site/my_field_data"),
		'after'    => array(
			'Sign in to StraboSpot and accept or decline the invitation from the top of your My StraboField Data page. Nothing changes in your account until you accept.',
			'Once accepted, the project appears in your StraboField app on the next sync.',
		),
		'site_url' => $site,
		'footer'   => "You received this because $who invited the StraboSpot account $address to a project. If you were not expecting it, you can decline it or simply ignore this message.",
	));
	try{
		$how = StraboMail::send($address, "$who invited you to collaborate on \"$project_name\" in StraboSpot", $m, array('to_name' => $invitee ? $invitee->firstname : ''));
		return $how === 'none' ? '' : ' (invitation email sent)';
	}catch(Exception $e){
		error_log('invite_collaborators: mail to ' . $address . ' failed: ' . $e->getMessage());
		return ' (invitation saved, but the email could not be sent)';
	}
}

if($_POST){

	$collaborationlevel = $_POST['collaborationlevel'];

	$addresses = $_POST['addresses'];
	$addresses = explode("\n", $addresses);

	$foundaddresses = [];
	$errors = [];
	$results = ['invited' => [], 'updated' => [], 'errors' => []];   // shown once on collaborate.php

	// Inviter (for the notification) and the level label.
	$inviter = $db->get_row_prepared("SELECT firstname, lastname, email FROM users WHERE pkey = $1", array($userpkey));
	$levelLabels = ['readonly' => 'Read Only', 'edit' => 'Edit', 'admin' => 'Admin'];

	foreach($addresses as $address){
		$address = trim($address);
		if($address != "" && filter_var($address, FILTER_VALIDATE_EMAIL)){
			if($address != $email){
				if(!in_array($address, $foundaddresses)){
					$collaborator_pkey = $db->get_var_prepared("SELECT pkey FROM users WHERE email=$1", array($address));
					if($collaborator_pkey == ""){
						$results['errors'][] = "$address: no StraboSpot account with this address (they need to register first)";
					}else{

						$existcount = $db->get_var_prepared("SELECT count(*) FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3", array($project_id, $userpkey, $collaborator_pkey));
						if($existcount == 0){

							// Check if invitee can be invited (duplicate project ID prevention)
							$canInvite = $collabAuth->canInviteUser($project_id, (int)$collaborator_pkey);
							if(!$canInvite['allowed']){
								$errors[] = "$address: " . $canInvite['reason'];
							}else{
								$uuid = $strabo->uuid->v4();

								$db->prepare_query("
									INSERT INTO collaborators (
										strabo_project_id,
										project_owner_user_pkey,
										collaborator_user_pkey,
										collaboration_level,
										uuid
									) VALUES ($1, $2, $3, $4, $5)
								", array($project_id, $userpkey, $collaborator_pkey, $collaborationlevel, $uuid));
								$results['invited'][] = $address . invitationMail($address, $collaborator_pkey, $inviter, $project_name, $collaborationlevel, $levelLabels);
							}

						}else{
							//Exists, just update. A pending or denied (disabled) invitation is
							//(re)issued and notified; an accepted collaborator only gets the new level.
							$existing = $db->get_row_prepared("SELECT accepted, disabled FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3 LIMIT 1", array($project_id, $userpkey, $collaborator_pkey));
							$wasLive = $existing && $existing->accepted === 't' && $existing->disabled === 'f';
							$wasPending = $existing && $existing->accepted === 'f' && $existing->disabled === 'f';
							$db->prepare_query("UPDATE collaborators SET disabled = FALSE, collaboration_level = $4 WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3", array($project_id, $userpkey, $collaborator_pkey, $collaborationlevel));
							if($wasLive || $wasPending){
								$results['updated'][] = $address . ($wasLive ? " (already a collaborator; level set to " . ($levelLabels[$collaborationlevel] ?? $collaborationlevel) . ")" : " (invitation already pending; level set to " . ($levelLabels[$collaborationlevel] ?? $collaborationlevel) . ", no new email)");
							}else{
								$results['invited'][] = $address . invitationMail($address, $collaborator_pkey, $inviter, $project_name, $collaborationlevel, $levelLabels);
							}
						}
					}
				}
			}
		}

		$foundaddresses[] = $address;
	}

	// Results (invited / updated / errors) are shown once on collaborate.php
	$results['errors'] = array_merge($results['errors'], $errors);
	$_SESSION['invite_results'] = $results;

	header("Location: /collaborate?p=$project_id");

	exit();
}

include 'includes/mheader.php';

?>
<script>
	function validateForm() {

		var error = '';

		let addresses = document.getElementById('addresses').value;
		if(addresses == ""){
			error += "Addresses list cannot be blank.\n";
		}

		let collaborationlevel = document.getElementById('collaborationlevel').value;
		if(collaborationlevel == ""){
			error += "Collaboration level cannot be blank.\n";
		}

		if(error != ''){
			alert(error);
			return false;
		}

	}
</script>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Invite Collaborators</h2>
						</header>

<div class="medHeader" style="padding-top:0px;padding-bottom:20px;text-align:center;">
Invite users to collaborate on <?php echo $project_name?>. Enter Strabo email addresse(s), one line at a time.
<div style="padding-top:30px;">

		<form method="post" onsubmit="return validateForm()" >
			<div class="row gtr-uniform gtr-50">
				<div class="col-12">
					<textarea name="addresses" id="addresses" placeholder="Enter Strabo email addresse(s), one line at a time." rows="6"></textarea>
				</div>

				<div class="col-12">
					<select name="collaborationlevel" id="collaborationlevel">
						<option value="">Collaboration Level</option>
						<option value="readonly">Read Only</option>
						<option value="edit">Edit</option>
						<!--<option value="admin">Admin</option>-->
					</select>
				</div>

				<div class="col-12">
					<ul class="actions">
						<li><input type="submit" value="Invite Users" class="primary"></li>
						<li><input type="reset" onclick="window.location='collaborate?p=<?php echo $project_id?>'; return false;" value="Cancel"></li>
					</ul>
				</div>
			</div>
		</form>

</div>
</div>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include 'includes/mfooter.php';
?>