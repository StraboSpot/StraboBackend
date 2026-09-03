<?php
/**
 * File: my_field_data2.php
 * Description: My StraboField Data
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");

$credentials = $_SESSION['credentials'];
$projectrows = $neodb->get_results("match (p:Project {userpkey:$userpkey}) optional match (p)-[HAS_DATASET]->(d:Dataset) optional match (d)-[HAS_SPOT]->(s:Spot) with p,d,count(s) as count with p,collect ({d:d,count:count}) as d return p,d order by p.uploaddate desc;");
$total=0;

include("adminkeys.php");

$username = $_SESSION['username'];
$apptoken = $uuid = $uuid->v4();
$db->get_var("DELETE from apptokens WHERE created_on < NOW() - INTERVAL '24 hours'");
$db->prepare_query("insert into apptokens (uuid, email) values ($1,$2)", array($apptoken, $username));
$tokencreds = base64_encode($username."*****".$apptoken);

$collaboration_rows = $strabo->getCollaborationProjects();

// Project transfers (docs/ProjectTransfer_Design.md §4): pending requests in
// both directions, lazy expiry (mails the owner once per expired request),
// and the one-shot notice left by cancel_transfer.php.
require_once("includes/transfer/ProjectTransfer.php");
require_once("includes/transfer/ProjectTransferMail.php");
$transfers_out = array(); $transfers_in = array();
if(ProjectTransfer::tableExists($db)){
	$transferSvc = new ProjectTransfer($db, $neodb);
	$expiredRows = $transferSvc->expireStale();
	if($expiredRows){
		$transferMailer = new ProjectTransferMail($db, $neodb);
		foreach($expiredRows as $xr){ $transferMailer->expired($xr); }
	}
	$transfers_out = $transferSvc->listOutgoing($userpkey);
	$transfers_in  = $transferSvc->listIncoming($userpkey);
}
$transfer_notice = $_SESSION['transfer_notice'] ?? null;
unset($_SESSION['transfer_notice']);

include("includes/mheader.php");

?>

<script type='text/javascript'>

	function  moveDataset(datasetid){
		var e = document.getElementById("dataset"+datasetid);
		var newproject = e.options[e.selectedIndex].value;

		if(newproject != "" && newproject != "null"){
			document.location.href = "move_dataset?did="+datasetid+"&pid="+newproject;
		}

	}

	function  devmoveDataset(datasetid){
		var e = document.getElementById("dataset"+datasetid);
		var newproject = e.options[e.selectedIndex].value;

		if(newproject != "" && newproject != "null"){
			console.log("https://strabospot.org/dev_move_dataset?did="+datasetid+"&pid="+newproject);
		}

	}

	function  projectPub(projectid){
		if(document.getElementById('switch_'+projectid).checked){
			console.log("https://strabospot.org/project_public?projectid="+projectid+"&state=public");
			$.get("/project_public?projectid="+projectid+"&state=public");
		}else{
			console.log("https://strabospot.org/project_public?projectid="+projectid+"&state=private");
			$.get("/project_public?projectid="+projectid+"&state=private");
		}
	}

	function doDownload(id){

		var selected = $('#dl-'+id).find(":selected").val();

		$('#dl-'+id).find(":selected").prop('selected', false);

		if(selected=="shapefile"){
			window.location='/chooseshapefile?type=shapefiledev&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="kml"){
			window.location='/searchdownload?type=kml&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="xls"){
			window.location='/searchdownload?type=xls&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="stereonet"){
			window.location='/searchdownload?type=stereonet&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="fieldbook"){
			window.open('/searchdownload?type=fieldbook&userpkey=<?php echo $userpkey?>&dsids='+id);
		}else if(selected=="strat_sections"){
			window.location='/dataset_strat_sections?dataset_id='+id;
		}else if(selected=="image_basemaps"){
			window.location='/image_basemaps?dataset_id='+id;
		}else if(selected=="sample_list"){
			window.location='/searchdownload?type=sample_list&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="dev_sample_list"){
			window.location='/searchdownload?type=dev_sample_list&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="shapefiledev"){
			window.location='/chooseshapefile?type=shapefiledev&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="landing_page"){
			window.location='/landingpage?dsid='+id;
		}else if(selected=="xlsdev"){
			window.location='/searchdownload?type=xlsdev&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="download_images"){
			window.location='/searchdownload?type=download_images&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="kml_dev"){
			window.location='/searchdownload?type=kml_dev&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="gpkg"){
			window.location='/searchdownload?type=gpkg&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="geojson"){
			window.location='/searchdownload?type=geojson&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="gems"){
			window.location='/gems_export?dsids='+id;
		}else if(selected=="custom_template"){
			window.location='/TemplateWizard/export.php?dataset_id='+id;
		}else if(selected=="geologic_units"){
			window.location='/searchdownload?type=geologic_units&userpkey=<?php echo $userpkey?>&dsids='+id;
		}else if(selected=="debug"){
			alert(id);
		}
	}

	function doProjectDownload(pid, projectname){
		var selected = $('#pdl-'+pid).find(":selected").val();
		$('#pdl-'+pid).find(":selected").prop('selected', false);

		switch(selected){
			case "edit":
				let randnum = Math.floor(Math.random()*90000) + 10000;
				let editurl = "https://app2.strabospot.org/index.html#/app/manage-project?credentials="+tokenCreds+"&projectid="+pid+"&r="+randnum;
				console.log(editurl);
				window.open(editurl, '_blank').focus();
				break;
			case "collaborate":
				window.location='/collaborate?p='+pid;
				break;
			case "transfer":
				window.location='/transfer_project?p='+pid;
				break;
			case "delete":
				if (confirm("Are you sure you want to delete project "+projectname+"?") == true) {
					window.location='delete_project?id='+pid;
				}
				break;
			case "field":
				window.location='/download_field_project?p='+pid;
				break;
			case "doi":
				window.location='/publish_doi?p='+pid;
				break;
			case "json":
				window.open('/debugproject/'+pid,'_blank');
				break;
			case "geologic_units":
				window.location='/project_geologic_units?p='+pid;
				break;
		}
	}

</script>

<style>
.mfd-toolbar { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 0.75em 1em; margin: -0.5em auto 2em auto; }
.mfd-toolbar .button { margin: 0; }
</style>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>My StraboField Data</h2>
						</header>

						<!-- Page-level actions (2026-09-01): the two things that are
						     not about one project or dataset live here, mirroring the
						     My Samples toolbar. Everything per-project stays in the
						     project cards below. -->
						<div class="mfd-toolbar">
							<a href="/new_project" class="button primary small">+ New Project</a>
							<?php /*<a href="/export_builder" class="button small" title="Build a downloadable package from several projects or datasets, with optional filters (Export Builder)">Custom export&hellip;</a>*/?>
						</div>

<?php
// One row per invitation. The PG project mirror holds one row per user who has a
// copy of a project (owner + every accepted collaborator) and Field project ids are
// not unique across users, so joining project on strabo_project_id alone fanned each
// invitation out N times (Jason on prod 2026-09-02: Cronese listed 4x, Santa
// Catalina 2026 2x). The project name is resolved from the OWNER's mirror row.
$collabquery = "
select 	c.uuid,
	c.strabo_project_id,
	(select p.project_name
	   from project p
	  where p.strabo_project_id = c.strabo_project_id
	    and p.user_pkey = c.project_owner_user_pkey
	  order by p.last_modified desc nulls last, p.project_pkey desc
	  limit 1) as project_name,
	c.collaboration_level,
	u.firstname,
	u.lastname,
	u.email
from
	collaborators c
	join users u on u.pkey = c.project_owner_user_pkey
where
	c.disabled IS FALSE and
	c.accepted = false and
	c.collaborator_user_pkey = $1 and
	exists (select 1 from project p
	         where p.strabo_project_id = c.strabo_project_id
	           and p.user_pkey = c.project_owner_user_pkey)
order by c.created_date, c.pkey
";

$collabrows = $db->get_results_prepared($collabquery, array((int)$userpkey));
if(!is_array($collabrows)) $collabrows = array();

	if(count($collabrows) > 0){
?>

<div>You have been invited to collaborate on the following StraboField Projects:</div>

<div class="table-wrapper">
	<table class="myDataTable">
		<thead>
			<tr>
				<th>Project</th>
				<th class="hideSmall">Type</th>
				<th class="hideSmall">Owner</th>
				<th></th>
				<th></th>
			</tr>
		</thead>
		<tbody>

<?php
foreach($collabrows as $c){

$clevel = $c->collaboration_level;
if($clevel == "readonly") $showlevel = "Read Only";
if($clevel == "edit") $showlevel = "Edit";
if($clevel == "admin") $showlevel = "Admin";

?>
			<!-- foreach collaboration request -->
			<tr>
				<td><?php echo $c->project_name?></td>
				<td class="hideSmall"><?php echo $showlevel?> </td>
				<td class="hideSmall"><?php echo $c->firstname?> <?php echo $c->lastname?> (<?php echo $c->email?>)</td>
				<td><a href="accept_collaboration?p=<?php echo $c->strabo_project_id?>&u=<?php echo $c->uuid?>" class="button primary fit small">Accept</a></td>
				<td><a href="deny_collaboration?p=<?php echo $c->strabo_project_id?>&u=<?php echo $c->uuid?>" class="button primary fit small">Deny</a></td>
			</tr>
<?php
}
?>

		</tbody>
	</table>
</div>

<div style="padding-bottom:100px;"></div>

<?php
	}
?>


<?php
// ---------------------------------------------------------------- project transfers
$hx = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
if(is_array($transfer_notice) && !empty($transfer_notice['text'])){
?>
<div class="transfer-notice transfer-notice-<?php echo $hx($transfer_notice['kind'] ?? 'ok'); ?>" style="margin:0 0 1.5em 0;padding:1em 1.25em;border-radius:6px;background:rgba(255,255,255,0.06);border-left:4px solid <?php echo (($transfer_notice['kind'] ?? 'ok') === 'ok') ? '#6fbf73' : '#f0ad4e'; ?>;font-size:.95em;line-height:1.5;">
	<?php echo $hx($transfer_notice['text']); ?>
</div>
<?php
}
if(count($transfers_in) > 0){
?>

<div>The following StraboField Projects have been offered to your account:</div>

<div class="table-wrapper">
	<table class="myDataTable" id="transfers-incoming">
		<thead>
			<tr>
				<th>Project</th>
				<th class="hideSmall">From</th>
				<th class="hideSmall">Expires</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
<?php
foreach($transfers_in as $t){
?>
			<tr>
				<td><?php echo $hx($t->project_name); ?></td>
				<td class="hideSmall"><?php echo $hx(trim($t->from_firstname . ' ' . $t->from_lastname)); ?> (<?php echo $hx($t->from_email); ?>)</td>
				<td class="hideSmall"><?php echo $hx(date('F j, Y', strtotime($t->expires_date))); ?></td>
				<td><a href="transfer_respond?t=<?php echo $hx($t->uuid); ?>" class="button primary fit small">Review</a></td>
			</tr>
<?php
}
?>
		</tbody>
	</table>
</div>

<div style="padding-bottom:60px;"></div>

<?php
}
if(count($transfers_out) > 0){
?>

<div>You have offered the following StraboField Projects to another account (nothing changes until they accept):</div>

<div class="table-wrapper">
	<table class="myDataTable" id="transfers-outgoing">
		<thead>
			<tr>
				<th>Project</th>
				<th class="hideSmall">Offered to</th>
				<th class="hideSmall">Expires</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
<?php
foreach($transfers_out as $t){
?>
			<tr>
				<td><?php echo $hx($t->project_name); ?></td>
				<td class="hideSmall"><?php echo $hx($t->to_email); ?></td>
				<td class="hideSmall"><?php echo $hx(date('F j, Y', strtotime($t->expires_date))); ?></td>
				<td><a href="cancel_transfer?t=<?php echo $hx($t->uuid); ?>" class="button primary fit small" onclick="return confirm('Withdraw the transfer request for <?php echo $hx(str_replace("'", '', $t->project_name)); ?>?')">Cancel</a></td>
			</tr>
<?php
}
?>
		</tbody>
	</table>
</div>

<div style="padding-bottom:60px;"></div>

<?php
}
?>

<?php
if($collaboration_rows != ""){
?>

						<header class="majorcollab">
							<h2>Collaborative Projects</h2>
						</header>

<?php
	foreach($collaboration_rows as $crow){

		$collab = $crow->collaboration;
		$collaboration_level = $collab->collaboration_level;
		$cuuid = $collab->uuid;

		if($collaboration_level == "admin") $showlevel = "Admin";
		if($collaboration_level == "edit") $showlevel = "Edit";
		if($collaboration_level == "readonly") $showlevel = "Read Only";

		$owner_row = $db->get_row_prepared("select * from users where pkey = $1", array($collab->project_owner_user_pkey));
		$owner_name = $owner_row->firstname." ".$owner_row->lastname." <span class=\"hideSmall\">(".$owner_row->email.")</span>";

		$projectrow = $crow->project;
		
		$pvals = $projectrow->get("p")->values();

		if($pvals["public"]){
			$checked = " checked";
		}else{
			$checked = "";
		}

		if($userpkey == 3){
		}

		$projectid=$pvals["id"];
		$uploaddate = date("F j, Y, g:i a T", $pvals["uploaddate"]);
		$projectname = $pvals["desc_project_name"];

		$dropdown_projectname = str_replace("'", "", $projectname);

		$drows=$projectrow->get("d");
		$datasetcount = count($drows);

		$collabcount = $db->get_var_prepared("select count(*) from collaborators where strabo_project_id = $1 and project_owner_user_pkey = $2 and accepted = true", array($projectid, $userpkey));

?>

								<!-- foreach project -->
								<section>
									<h3><?php echo $projectname?></h3>

									<div class="row" style="padding-bottom:10px;">
										<div class="col-6 col-12-xsmall">
												<h4>Owned by: <?php echo $owner_name?></h4>
										</div>

										<div class="col-6 col-12-xsmall">
												<h4>Collaboration Level: <?php echo $showlevel?> <a href="/delete_collaborator?u=<?php echo $cuuid?>" onclick="return confirm('Are you sure you want to stop collaborating on <?php echo $projectname?>?')" style="color: #ed7287;">(Remove)</a></h4>
										</div>
									</div>

									<div style="margin-top:-5px" class="myDataTable">
										<ul class="actions MyDataUL">
											<li>Last Uploaded: <?php echo $uploaddate?></li>
											<li>
												<select class="myDataSelect" id="pdl-<?php echo $projectid?>" onChange="doProjectDownload(<?php echo $projectid?>,'<?php echo $dropdown_projectname?>');">
													<option value="" style="display:none">Options...</option>
<?php
if($collaboration_level == "editttt"){ //Turn this option off for now per request from Jessica Novak 20260831
?>
													<option value="edit">View/Edit/Add Data</option>
<?php
}
?>
													<option value="field">Download/Share StraboMobile Project File</option>
													<option value="json">Download Project in Strabo JSON Format</option>
													<option value="geologic_units">Download Geologic Units</option>
												</select>
											</li>
											<li>
<?php
if($collaboration_level == "admin"){
?>
												<span>Public? </span><label class="switch"><input type="checkbox" name="switch_<?php echo $projectid?>" id="switch_<?php echo $projectid?>" onclick="projectPub(<?php echo $projectid?>)"<?php echo $checked?>><div class="slider sliderFront"></div></label>
<?php
}
?>
											</li>
<?php
if($collabcount > 0){
?>
											<li>
											<a href="collaborate?p=<?php echo $projectid?>">(<?php echo $collabcount?> <?php if($collabcount == 1){ echo "Collaborator";}else{echo "Collaborators";}?>)</a>
											</li>
<?php
}
?>
										</ul>
									</div>

								<?php
								if($drows[0]["d"]){ //If datasets exist
								?>
									<div class="table-wrapper">
										<table class="myDataTable">
											<thead>
												<tr>
													<th></th>
													<th></th>
													<th>Dataset Name</th>
													<th>Spots</th>
													<th class="hideSmall"></th>
													<th class="hideSmall">Modified</th>
												</tr>
											</thead>
											<tbody>

											<?php
											foreach($drows as $d){
											$featurecount = $d["count"];

											if($d["d"]){

											$dvals = $d["d"]->values();

											}

											$id = $dvals["id"];
											$featuretype = ucfirst($dvals["featuretype"]);
											$uploaddate = date("F j, Y, g:i a T P", $dvals["datecreated"]);
											$modified_timestamp = date("F j, Y, g:i a T", substr($dvals["modified_timestamp"],0,10));
											$name = $dvals["name"];
											// Use created_by instead of collaboratorpkey for new collaboration model
											$datasetCreatedBy = $dvals["created_by"] ?? $dvals["collaboratorpkey"] ?? $dvals["userpkey"];

											?>

												<!-- foreach dataset -->
												<tr>
													<td>
<?php
if($datasetCreatedBy == $userpkey || $collaboration_level == "admin"){
?>
														<a href="delete_dataset?id=<?php echo $id?>" OnClick="return confirm('Are you sure you want to delete <?php echo $name?>?')">Delete</a>
<?php
}else{
?>
														<div style="width:55px;">&nbsp;</div>
<?php
}
?>
													</td>
													<td>
														<select class="myDataSelect" id="dl-<?php echo $id?>" onChange="doDownload(<?php echo $id?>);">
															<option value="" style="display:none;">Download</option>
															<option value="shapefile">Shapefile</option>
															<option value="kml">KMZ</option>
															<option value="xls">XLS</option>
															<option value="stereonet">Stereonet Mobile</option>
															<option value="fieldbook">Field Book</option>
															<option value="strat_sections">Strat Section(s)</option>
															<option value="download_images">Download Photos</option>
															<option value="landing_page">Landing Page</option>
															<option value="sample_list">Sample List</option>
															<option value="gpkg">GeoPackage</option>
															<option value="geojson">GeoJSON</option>
															<option value="gems">USGS GeMS</option>
																<option value="image_basemaps">Image Basemaps</option>
														</select>
													</td>
													<td><?php echo $name?></td>
													<td><?php echo $featurecount?></td>
													<td class="hideSmall">
														&nbsp;
													</td>
													<td class="hideSmall"><?php echo $modified_timestamp?></td>
												</tr>

											<?php
											}
											?>

											</tbody>
										</table>
									</div>

								<?php
								}else{
								?>
									<div class="padLeft padBottom">No datasets exist for this project.</div>
								<?php
								}
								?>

								</section>

<?php
	}//end foreach project

?>

<?php
}//end if collaboration_rows
?>

<?php
if($collaboration_rows != ""){
?>
						<div style="padding-top:200px;"></div>
						<header class="majorcollab">
							<h2>My Projects</h2>
						</header>

<?php
}
?>

							<section id="content">

<?php
if(count($projectrows)==0){
	?>
		<div style="text-align:center;margin-bottom:500px;">No projects yet.<br>Use <strong>+ New Project</strong> above to add one.</div>
	<?php
}else{

	foreach($projectrows as $projectrow){

		$pvals = $projectrow->get("p")->values();

		if($pvals["public"]){
			$checked = " checked";
		}else{
			$checked = "";
		}

		if($userpkey == 3){
		}

		$projectid=$pvals["id"];
		$uploaddate = date("F j, Y, g:i a T", $pvals["uploaddate"]);
		$projectname = $pvals["desc_project_name"];

		$dropdown_projectname = str_replace("'", "", $projectname);

		$drows=$projectrow->get("d");
		$datasetcount = count($drows);

		$collabcount = $db->get_var_prepared("select count(*) from collaborators where strabo_project_id = $1 and project_owner_user_pkey = $2 and accepted = true and disabled = false", array($projectid, $userpkey));

?>

								<!-- foreach project -->
								<section>
									<h3><?php echo $projectname?></h3>
									<div style="margin-top:-5px" class="myDataTable">
										<ul class="actions MyDataUL">
											<li>Last Uploaded: <?php echo $uploaddate?></li>
											<li>
												<select class="myDataSelect" id="pdl-<?php echo $projectid?>" onChange="doProjectDownload(<?php echo $projectid?>,'<?php echo $dropdown_projectname?>');">
													<option value="" style="display:none">Options...</option>
													<option value="edit">View/Edit/Add Data</option>
													<option value="field">Download/Share StraboMobile Project File</option>
													<option value="doi">Get DOI for Project</option>
<?php
if(in_array($userpkey, $acollaboration_testing_pkeys)){
?>
													<option value="collaborate">Invite Collaborators</option>
<?php
}
?>													
													<option value="json">Download Project in Strabo JSON Format</option>
													<option value="geologic_units">Download Geologic Units</option>
<?php
if(ProjectTransfer::canInitiate($userpkey, $_SESSION['username'] ?? '')){ // Project transfer: fully launched 2026-09-03 (always true; docs/ProjectTransfer_Design.md D6)
?>
													<option value="transfer">Transfer to Other Account</option>
<?php
}
?>
													<option value="delete">Delete Project</option>
												</select>
											</li>
											<li>
												<span>Public? </span><label class="switch"><input type="checkbox" name="switch_<?php echo $projectid?>" id="switch_<?php echo $projectid?>" onclick="projectPub(<?php echo $projectid?>)"<?php echo $checked?>><div class="slider sliderFront"></div></label>
											</li>
<?php
if($collabcount > 0){
?>
											<li>
											<a href="collaborate?p=<?php echo $projectid?>">(<?php echo $collabcount?> <?php if($collabcount == 1){ echo "Collaborator";}else{echo "Collaborators";}?>)</a>
											</li>
<?php
}
?>
										</ul>
									</div>

								<?php
								if($drows[0]["d"]){ //If datasets exist
								?>
									<div class="table-wrapper">
										<table class="myDataTable">
											<thead>
												<tr>
													<th></th>
													<th></th>
													<th>Dataset Name</th>
													<th>Spots</th>
													<th class="hideSmall"></th>
													<th class="hideSmall">Modified</th>
												</tr>
											</thead>
											<tbody>

											<?php
											foreach($drows as $d){
											$featurecount = $d["count"];

											if($d["d"]){

											$dvals = $d["d"]->values();

											}

											$id = $dvals["id"];
											$featuretype = ucfirst($dvals["featuretype"]);
											$uploaddate = date("F j, Y, g:i a T P", $dvals["datecreated"]);
											$modified_timestamp = date("F j, Y, g:i a T", substr($dvals["modified_timestamp"],0,10));
											$name = $dvals["name"];

											?>

												<!-- foreach dataset -->
												<tr>
													<td><a href="delete_dataset?id=<?php echo $id?>" OnClick="return confirm('Are you sure you want to delete <?php echo $name?>?')">Delete</a></td>
													<td>
														<select class="myDataSelect" id="dl-<?php echo $id?>" onChange="doDownload(<?php echo $id?>);">
															<option value="" style="display:none;">Download</option>
															<option value="shapefile">Shapefile</option>
															<option value="kml">KMZ</option>
															<option value="xls">XLS</option>
															<option value="stereonet">Stereonet Mobile</option>
															<option value="fieldbook">Field Book</option>
															<option value="strat_sections">Strat Section(s)</option>
															<option value="download_images">Download Photos</option>
															<option value="landing_page">Landing Page</option>
															<option value="sample_list">Sample List</option>
<?php
if($userpkey==3 || $userpkey==3){
?>
															<option value="dev_sample_list">Dev Sample List</option>
<?php
}
?>
															<option value="gpkg">GeoPackage</option>
															<option value="geojson">GeoJSON</option>
															<option value="gems">USGS GeMS</option>
																<option value="image_basemaps">Image Basemaps</option>
														</select>
													</td>
													<td><?php echo $name?></td>
													<td><?php echo $featurecount?></td>
													<td class="hideSmall">
									<?php
									if($userpkey == 3){
									?>
														<select id="dataset<?php echo $id?>" onchange="devmoveDataset(<?php echo $id?>)" class="myDataSelect">
									<?php
									}else{
									?>
														<select id="dataset<?php echo $id?>" onchange="moveDataset(<?php echo $id?>)" class="myDataSelect">
									<?php
									}
									?>
															<option value="" style="display:none;">Move To</option>
															<?php

															foreach($projectrows as $pr){

																$pvals = $pr->get("p")->values();
																$thisprojectid=$pvals["id"];
																$thisprojectname = $pvals["desc_project_name"];

																if($thisprojectid!=$projectid){
																?>
																<option value="<?php echo $thisprojectid?>"><?php echo $thisprojectname?>
																<?php
																}
															}

															?>
														</select>
													</td>
													<td class="hideSmall"><?php echo $modified_timestamp?></td>
												</tr>

											<?php
											}
											?>

											</tbody>
										</table>
									</div>

								<?php
								}else{
								?>
									<div class="padLeft padBottom">No datasets exist for this project.</div>
								<?php
								}
								?>

								</section>

<?php
	}//end foreach project
}
?>

							</section>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<script type='text/javascript'>

var tokenCreds = "foo";

var intervalID = window.setInterval(refreshLoginToken, 1800000);

function refreshLoginToken() {
	let request = new XMLHttpRequest();
	request.open("GET", "/update_token", true);
	request.responseType = 'json';
	request.onload = () => {
		if (request.status == 200) {
			console.log('response', request.response);
			tokenCreds = request.response.tokencreds;
		}
	}
	request.send();
}

refreshLoginToken();

</script>

<?php
include("includes/mfooter.php");
?>