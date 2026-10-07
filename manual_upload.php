<?
include("logincheck.php");
include("prepare_connections.php");

if(!in_array($userpkey,[3,7217])) die("Not authorized.");

include 'includes/mheader.php';

if($_POST){
	//$db->dumpVar($_POST);
	//field, micro, experimental
	/*
            [name] => test.pdf
            [type] => application/pdf
            [tmp_name] => /tmp/php2uK3oG
            [error] => 0
            [size] => 8629
	*/
	
	if($_POST['manualtype']){
		$manualtype = $_POST['manualtype'];
	}else{
		exit("No manual type provided.");
	}
	
	if(!in_array($manualtype,['field','micro','micro2','experimental','samples','groupworkflows','tools'])){
		exit("Incorrect manual type provided.");
	}
	
	if($_FILES['pdffile']){
		$pdffile = $_FILES['pdffile'];
		
	}else{
		exit("No pdf file provided.");
	}
	
	if($manualtype == "field") $showtype = "StraboField";
	if($manualtype == "micro") $showtype = "StraboMicro";
	if($manualtype == "micro2") $showtype = "StraboMicro2";
	if($manualtype == "experimental") $showtype = "StraboExperimental";
	if($manualtype == "samples") $showtype = "StraboSamples";
	if($manualtype == "groupworkflows") $showtype = "StraboField Group Workflows";
	if($manualtype == "tools") $showtype = "StraboTools";

	
	if($pdffile['error'] != UPLOAD_ERR_OK){
		if($pdffile['error'] == UPLOAD_ERR_INI_SIZE || $pdffile['error'] == UPLOAD_ERR_FORM_SIZE){
			exit("Upload failed: the PDF is larger than the server allows (".ini_get('upload_max_filesize').").");
		}
		exit("Upload failed (error code ".(int)$pdffile['error'].").");
	}

	$tempname = $pdffile['tmp_name'];
	if(!move_uploaded_file($tempname, "manuals/".$manualtype.".pdf")){
		exit("Upload failed: the manual could not be saved on the server.");
	}
	
	?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Success!</h2>
						</header>
						
<div class="row gtr-uniform gtr-50">
	<div class="col-12">
		<h3><?=$showtype?> manual uploaded successfully!</h3>
	</div>
	<div class="col-12">
		<h3>Link: <a href="https://strabospot.org/manual/<?=$manualtype?>" target="_blank">https://strabospot.org/manual/<?=$manualtype?></a></h3>
	</div>
	<div class="col-12">
		<ul class="actions">
			<li><input class="primary" type="submit" onclick="window.location.href='/manual_upload'" value="Continue"></li>
		</ul>
	</div>
</div>
	

					<div class="bottomSpacer"></div>

					</div>
				</div>
	<?
	include 'includes/mfooter.php';
	exit();
}

?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Strabo Manual Upload</h2>
						</header>


	
	<script type="text/javascript">

	function formvalidate(){
		var errors='';
		
		var e = document.getElementById("manualtype");
		var manualtype = e.options[e.selectedIndex].value;
		
		if(manualtype=="" || manualtype==null){errors=errors+'Manual type must be selected.\n';}
		
		if(document.forms["uploadform"]["pdffile"].value=="" || document.forms["uploadform"]["pdffile"].value==null){errors=errors+'PDF must be provided.\n';}

		if(errors!="" && errors!=null){
			alert(errors);
			return false;
		}
	}
	
	function doClickFile() {
	
		let FC = document.getElementById("pdffile");
		FC.click();
	
	}
	
	function changeFileName() {
		let fullPath = document.getElementById("pdffile").value;
		var filename = fullPath.replace(/^.*[\\/]/, '')
		document.getElementById("filename").value = filename;
	}
	
	
	</script>

	<?=$error?>

	<form name="uploadform" method="POST" onsubmit="return formvalidate();" enctype="multipart/form-data">






<div class="row gtr-uniform gtr-50">
	<div class="col-12">
		<h3>Manual Type:</h3>
	</div>
	<div class="col-12">
		<select name="manualtype" id="manualtype">
			<option value="">Select...</option>
			<option value="field">StraboField</option>
			<option value="micro">StraboMicro</option>
			<option value="micro2">StraboMicro2</option>
			<option value="experimental">StraboExperimental</option>
			<option value="samples">StraboSamples</option>
			<option value="groupworkflows">StraboField Group Workflows</option>
			<option value="tools">StraboTools</option>
		</select>
	</div>
	<div class="col-12">
		<h3>Manual (.pdf):</h3>
	</div>
	<div class="col-12">
		<input type="text" id="filename" placeholder="Choose File..." onclick="doClickFile();" readonly>
	</div>
	<div class="col-12">
		<ul class="actions">
			<li><input class="primary" type="submit" name="submitfile" value="Submit"></li>
			<li><input class="primary" type="reset" value="Reset"></li>
		</ul>
	</div>



</div>


<!--

<input type="file" id="docFile" class="formControl" onchange="exper_uploadSampleFile(0)">

-->


		
		<input type="file" id="pdffile" name="pdffile" accept=".pdf" style="display:none;" onchange="changeFileName();">
	
		<input type="hidden" name="filename">
	</form>
					<div class="bottomSpacer"></div>

					</div>
				</div>
<?
include 'includes/mfooter.php';
?>