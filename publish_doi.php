<?php
/**
 * File: publish_doi.php
 * Description: Ready to Continue?
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");

$projectid = isset($_GET['p']) ? (int)$_GET['p'] : 0;
if($projectid == 0) die("No project provided.");

$type = "field";
if($_GET[t]=="m") $type = "micro";
if($_GET[t]=="e") $type = "experimental";

if($type == "field"){

	$safe_projectid = addslashes($projectid);
	$safe_userpkey = addslashes($userpkey);
	$count = $neodb->get_var("Match (p:Project) where p.id = $safe_projectid and p.userpkey = $safe_userpkey return count(p)");
	if($count == 0) die("Project not found.");

	$row = $neodb->getNode("Match (p:Project) where p.id = $safe_projectid and p.userpkey = $safe_userpkey return p");
	$desc = $row['json_description'];
	$desc = json_decode($desc);
	$projectname = $desc->project_name;
	$url = "build_doi.php";

}elseif($type == "micro"){

	$row = $db->get_row_prepared("SELECT * FROM micro_projectmetadata WHERE id = $1 AND userpkey = $2", array($projectid, $userpkey));
	if(!$row->id)  die("Project not found.");
	$projectname = $row->name;
	$url = "build_m_doi.php";

}elseif($type == "experimental"){

	$row = $db->get_row_prepared("SELECT * FROM straboexp.project WHERE pkey = $1 AND userpkey = $2", array($projectid, $userpkey));
	if(!$row->pkey)  die("Project not found.");
	$projectname = $row->name;
	$url = "build_e_doi.php";

}

include "includes/mheader.php";

?>
<script>
	function doBuildDOI(){
		jQuery('#buildingmessage').html('<div style="padding-top:20px;"><img src="/assets/js/images/box.gif"></td><td nowrap><h3>Building DOI Files.<br>This may take a while.<br>Please wait...</h3></div>');
		jQuery.ajax({
			url : "<?php echo $url?>?p=<?php echo $projectid?>",
			type: "GET",
			processData: false,
			contentType: false,
			success:function(data){
				if(data.Error){
					alert("Error!\n" + data.Error);
				}else{
					console.log(data);
					let newloc = 'edit_doi?u='+data.uuid+'&n=1';
					window.location.href = newloc;
				}
			},
			error: function(){
				//if fails
			}
		});
	}

	function doTest(){
		jQuery('#buildingmessage').html('<div style="padding-top:20px;"><img src="/assets/js/images/box.gif"></td><td nowrap><h3>Building DOI. Please wait...</h3></div>');
	}
</script>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Get DOI for Project: <?php echo $projectname?></h2>
						</header>

							<section id="content">

<div style="margin:auto;padding-top:10px;">

	<div style="text-align:center">
		<a href="https://zenodo.org/communities/strabospot" target="_blank">
			<img src="/includes/images/zenodo_logo.png" width="200px"/>
		</a>
	</div>
	<div style="padding-top:10px;padding-bottom:15px;">
		While StraboSpot does not provide Digital Object Identifiers directly, we recommend using <a href="https://zenodo.org" target="_blank">Zenodo</a>, a free research
		data repository operated by CERN, for publishing project data. (Zenodo replaces our previous recommendation, the Open Science Framework, which has discontinued
		its DOI service.) The process for creating StraboSpot data suitable for publication is as follows:
		<div style="padding-left:40px;padding-top:10px;">
			<ol>
				<li>Clicking the button below begins generating the files necessary for publication at <a href="https://zenodo.org" target="_blank">Zenodo</a>.</li>
				<li>A snapshot of your StraboSpot project is taken and saved to the StraboSpot server.</li>
				<li>After the snapshot has been created, links are provided to download landing page and project files.</li>
				<li>Sign in (or create a free account) at Zenodo, then click &quot;New upload&quot; on the <a href="https://zenodo.org/communities/strabospot" target="_blank">StraboSpot community page</a> so your record is part of the StraboSpot collection.</li>
				<li>Attach the downloaded project files, fill in the metadata (title, authors, description, keywords, license), and add your StraboSpot landing page URL as a Related identifier (&quot;Is derived from&quot;).</li>
				<li>Publish the upload. Zenodo assigns the DOI immediately. If you may update the dataset later, cite the &quot;Cite all versions&quot; DOI shown on your Zenodo record.</li>
				<li>The new DOI value can be saved with the archived project at StraboSpot.</li>
			</ol>
		</div>
	</div>

</div>

<div id="buildingmessage" style="text-align:center;">
	<div style="">
		<h2 style="">Ready to Continue?</h2>
	</div>
	<input class="primary" type="submit" onclick="doBuildDOI()" value="Create DOI Files"></input>
	<!--<button class="bigSubmitButton" style="width:250px;" onclick="doBuildDOI()"><span>Create DOI Files</span></button>-->
</div>

<!--
<div id="loadingmessage" style="text-align:center;">
	<div style="">
		<h2 style="">Ready to Continue?</h2>
	</div>
	<button class="bigSubmitButton" style="width:250px;" onclick="console.log('foo')"><span>Create DOI Files</span></button>
</div>
-->

<!--
<div id="loadingmessage" style="text-align:center;">
	<div style="padding-top:20px;"><img src="/assets/js/images/box.gif"></td><td nowrap><h3>Building DOI. Please wait...</h3></div>
</div>
-->

							</section>

					<div class="bottomSpacer"></div>

					</div>
				</div>
<?php
include "includes/mfooter.php";
?>