<?php
/**
 * File: my_micro_data.php
 * Description: Personal Strabo Micro data dashboard and project listing
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once(__DIR__ . '/microdb/lib/permalink.php');
require_once(__DIR__ . '/microdb/lib/sync_guard.php');
require_once(__DIR__ . '/microdb/lib/micro_members_web.php');

$credentials = $_SESSION['credentials'];
$microrows = $db->get_results_prepared("SELECT id, strabo_id, name, to_char(uploaddate, 'Month DD, YYYY, HH:MI:SS pm TZ') as uploaddate, ispublic, projectjson, sync_format FROM micro_projectmetadata WHERE userpkey = $1 AND " . micro_sync_visible_sql() . " ORDER BY micro_projectmetadata.uploaddate DESC NULLS LAST, id DESC", array($userpkey));
$total=0;

// Collaboration invitations (synced projects); a problem here never hides the page
$microInvites = array();
try {
	$microInvites = MsMembers::invitationsFor(micro_members_db($db), (int)$userpkey);
} catch (Throwable $e) {
	error_log('my_micro_data invitations: ' . $e->getMessage());
}
$microInviteResult = isset($_SESSION['micro_invite_result']) ? $_SESSION['micro_invite_result'] : null;
unset($_SESSION['micro_invite_result']);

// Synced projects deleted from StraboSpot that can still be restored (v3 17ad)
$microDeleted = array();
try {
	$microDeleted = MsDelete::restorableFor(micro_members_db($db), (int)$userpkey);
} catch (Throwable $e) {
	error_log('my_micro_data deleted projects: ' . $e->getMessage());
}
$microDeleteResult = isset($_SESSION['micro_delete_result']) ? $_SESSION['micro_delete_result'] : null;
unset($_SESSION['micro_delete_result']);
if (empty($_SESSION['micro_delete_token'])) {
	$_SESSION['micro_delete_token'] = bin2hex(random_bytes(16));
}

include("adminkeys.php");

$username = $_SESSION['username'];
$apptoken = $uuid = $uuid->v4();
$db->get_var("DELETE from apptokens WHERE created_on < NOW() - INTERVAL '24 hours'");
$db->prepare_query("INSERT INTO apptokens (uuid, email) VALUES ($1, $2)", array($apptoken, $username));
$tokencreds = base64_encode($username."*****".$apptoken);

include("includes/mheader.php");

?>

<script type='text/javascript'>
	function  projectMicroPub(projectid){
		if(document.getElementById('switch_'+projectid).checked){
			console.log("https://strabospot.org/micro_project_public?projectid="+projectid+"&state=public");
			$.get("/micro_project_public?projectid="+projectid+"&state=public");
		}else{
			console.log("https://strabospot.org/micro_project_public?projectid="+projectid+"&state=private");
			$.get("/micro_project_public?projectid="+projectid+"&state=private");
		}
	}

	function doMicroProjectDownload(pkey, projectname, murl, pid){

		var selected = $('#mdl-'+pkey).find(":selected").val();
		$('#mdl-'+pkey).find(":selected").prop('selected', false);
		switch(selected){
			case "view":
				window.open(murl, '_blank');
				break;
			case "download":
				window.location='/download_micro_file?project_id='+pkey;
				break;
			case "share":
				window.location='/share_micro_file?project_id='+pkey;
				break;
			case "doi":
				window.location='/publish_doi?p='+pkey+'&t=m';
				break;
			case "collaborators":
				window.location='/micro_collaborators?project_id='+pkey;
				break;
			case "history":
				// Synced projects: who changed what, and the project as of a date (v3 17ae)
				window.location='/micro_history?project_id='+pkey;
				break;
			case "deletesynced":
				// Synced projects: the confirmation page names who else has it (v3 17ac)
				window.location='/micro_delete?project_id='+pkey;
				break;
			case "delete":
				if (confirm("Are you sure you want to delete project "+projectname+"?") == true) {
					window.location='/delete_micrograph_project?project_id='+pid;
				}
				break;
		}

	}
</script>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>My StraboMicro Data</h2>
						</header>

							<section id="content">

<?php
if($microDeleteResult !== null){
	?>
		<div style="border:1px solid <?php echo $microDeleteResult['ok'] ? '#2e7d32' : '#c0392b'?>;padding:10px 14px;margin-bottom:20px;"><?php echo htmlspecialchars($microDeleteResult['text'])?></div>
	<?php
}
if($microInviteResult !== null){
	?>
		<div style="border:1px solid <?php echo $microInviteResult['ok'] ? '#2e7d32' : '#c0392b'?>;padding:10px 14px;margin-bottom:20px;"><?php echo htmlspecialchars($microInviteResult['text'])?></div>
	<?php
}
if(count($microInvites) > 0){
	?>
		<div>You have been invited to collaborate on the following StraboMicro projects:</div>
		<div class="table-wrapper">
			<table class="myDataTable">
				<thead>
					<tr>
						<th>Project</th>
						<th class="hideSmall">Role</th>
						<th class="hideSmall">Invited by</th>
						<th></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
<?php
	foreach($microInvites as $inv){
		$by = $inv['invitedBy'] !== null ? $inv['invitedBy']['name'] . (isset($inv['invitedBy']['email']) ? ' (' . $inv['invitedBy']['email'] . ')' : '') : '';
?>
					<tr>
						<td><?php echo htmlspecialchars($inv['name'])?></td>
						<td class="hideSmall"><?php echo htmlspecialchars(micro_role_label($inv['role']))?></td>
						<td class="hideSmall"><?php echo htmlspecialchars($by)?></td>
						<td>
							<form method="post" action="/micro_invitation" style="margin:0;">
								<input type="hidden" name="pid" value="<?php echo (int)$inv['pid']?>">
								<input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$inv['token'])?>">
								<button type="submit" name="action" value="accept" class="button primary fit small">Accept</button>
							</form>
						</td>
						<td>
							<form method="post" action="/micro_invitation" style="margin:0;">
								<input type="hidden" name="pid" value="<?php echo (int)$inv['pid']?>">
								<input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$inv['token'])?>">
								<button type="submit" name="action" value="decline" class="button fit small">Decline</button>
							</form>
						</td>
					</tr>
<?php
	}
?>
				</tbody>
			</table>
		</div>
		<div style="padding-bottom:40px;"></div>
	<?php
}
if(isset($_GET['deleteblocked'])){
	?>
		<div style="border:1px solid #c0392b;padding:10px 14px;margin-bottom:20px;">This project is shared with other people and cannot be deleted here. Manage it from StraboMicro.</div>
	<?php
}
if(count($microrows)==0){
	?>
		<div style="text-align:center;margin-bottom:500px;">No Projects found.</div>
	<?php
}else{

	foreach($microrows as $mr){

		if($mr->ispublic == "t"){
			$checked = " checked";
		}else{
			$checked = "";
		}

		$projectname = $mr->name;
		$projectid = $mr->id;
		$strabo_id = $mr->strabo_id;

		$micrographcount = 0;
		$spotcount = 0;

		$pdata = json_decode($mr->projectjson);
		foreach($pdata->datasets as $d){
			foreach($d->samples as $s){
				foreach($s->micrographs as $m){
					$micrographcount++;
					foreach($m->spots as $sp){
						$spotcount++;
					}
				}
			}
		}

		// Upload-stable permalink into the tier-agnostic front door; the old
		// tier-picked pkey URLs remain as fallback if a slug cannot be minted.
		$mslug = micro_permalink_get_or_create($db, $mr->strabo_id, (int)$userpkey);
		if($mslug !== null){
			$murl = "microproject?m=$mslug";
		}elseif(is_dir("straboMicroFiles/$projectid/webImages")){
			$murl = "straboMicroView/view?p=$projectid";
		}else{
			$murl = "microproject?id=$projectid";
		}

?>

								<!-- foreach project -->
								<section>
									<h3><?php echo $mr->name?></h3>
									<div style="margin-top:-5px" class="myDataTable">
										<ul class="actions MyDataUL">
											<li><h3><?php echo $mr->sync_format === 'entity' ? 'Last Changed' : 'Upload Date'?>: <?php echo $mr->uploaddate?></li>
											<li>
												<span>Public? </span><label class="switch"><input type="checkbox" name="switch_<?php echo $projectid?>" id="switch_<?php echo $projectid?>" onclick="projectMicroPub(<?php echo $projectid?>)"<?php echo $checked?>><div class="slider sliderFront"></div></label>
											</li>
										</ul>
									</div>

									<div class="table-wrapper">
		<div class="strabotable" style="margin-left:0px;margin-top:3px;">
			<table>

				<tr>
					<td style="width:300px;">&nbsp;</td>
					<td>Num Micrographs</td>
					<td>Num Spots</td>
				</tr>

				<tr>
					<td nowrap>
						<select class="myDataSelect" id="mdl-<?php echo $projectid?>" onChange="doMicroProjectDownload(<?php echo $projectid?>, '<?php echo $projectname?>', '<?php echo $murl?>', '<?php echo $strabo_id?>');">
							<option value=""  style="display:none">Options...</option>
							<option value="view">View</option>
							<option value="download">Download</option>
							<option value="share">Share</option>
<?php if($mr->sync_format === 'entity'){ ?>
							<option value="collaborators">Collaborators</option>
							<option value="history">History</option>
<?php } ?>
							<option value="doi">Get DOI</option>
							<option value="<?php echo $mr->sync_format === 'entity' ? 'deletesynced' : 'delete'?>">Delete</option>
						</select>
					</td>
					<td><?php echo $micrographcount?></td>
					<td><?php echo $spotcount?></td>
				</tr>

			</table>
		</div>
									</div>

								</section>

<?php
	}//end foreach project
}

if(count($microDeleted) > 0){
	?>
		<div style="padding-top:20px;"></div>
		<header>
			<h3>Deleted projects</h3>
		</header>
		<div>These synced projects were deleted from StraboSpot. Each can be restored, with its members, until the date shown; after that it is deleted for good.</div>
		<div class="table-wrapper">
			<table class="myDataTable">
				<thead>
					<tr>
						<th>Project</th>
						<th class="hideSmall">Deleted</th>
						<th>Can be restored until</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
<?php
	foreach($microDeleted as $dp){
		$untilTs = strtotime($dp['restorable_until']);
		$daysLeft = max(0, (int)ceil(($untilTs - time()) / 86400));
?>
					<tr>
						<td><?php echo htmlspecialchars((string)$dp['name'])?></td>
						<td class="hideSmall"><?php echo htmlspecialchars(date('F j, Y', strtotime($dp['deleted_at'])))?></td>
						<td><?php echo htmlspecialchars(date('F j, Y', $untilTs))?> (<?php echo $daysLeft === 1 ? '1 day left' : "$daysLeft days left"?>)</td>
						<td>
							<form method="post" action="/micro_delete" style="margin:0;">
								<input type="hidden" name="action" value="restore">
								<input type="hidden" name="pid" value="<?php echo (int)$dp['project_id']?>">
								<input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['micro_delete_token'])?>">
								<button type="submit" class="button primary fit small">Restore</button>
							</form>
						</td>
					</tr>
<?php
	}
?>
				</tbody>
			</table>
		</div>
	<?php
}
?>

							</section>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
?>