<?php
/**
 * File: edit_instrument.php
 * Description: Edits records in instrument table(s)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");

$userpkey = (int)$_SESSION['userpkey'];

include("prepare_connections.php");

$credentials = $_SESSION['credentials'];

include 'includes/mheader.php';
?>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

<script src='/assets/js/jquery/jquery.min.js'></script>

<?php

if($_POST['submit']!=""){
	$instrument_pkey = $_POST['instrument_pkey'];

	if($instrument_pkey == "" || !is_numeric($instrument_pkey)){
		exit();
	}

	$institution_pkey = $_POST["i"];
	$instrument_name=$_POST["instrument_name"];
	$instrument_type=$_POST["instrument_type"];
	$instrument_brand=$_POST["instrument_brand"];
	$instrument_model=$_POST["instrument_model"];
	$university=$_POST["university"];
	$instrument_lab=$_POST["instrument_lab"];
	$data_collection_software=$_POST["data_collection_software"];
	$data_collection_software_version=$_POST["data_collection_software_version"];
	$post_processing_software=$_POST["post_processing_software"];
	$post_processing_software_version=$_POST["post_processing_software_version"];
	$filament_type=$_POST["filament_type"];
	$instrument_notes=$_POST["instrument_notes"];

	$error = "";

	if($instrument_name=="" || $instrument_type==""){
		$error = "Instrument Name and Instrument Type are required!";
	}

	if($error==""){

		$db->prepare_query("
				update instrument set
					instrumentname = $1,
					instrumenttype = $2,
					instrumentbrand = $3,
					instrumentmodel = $4,
					university = $5,
					laboratory = $6,
					datacollectionsoftware = $7,
					datacollectionsoftwareversion = $8,
					postprocessingsoftware = $9,
					postprocessingsoftwareversion = $10,
					filamenttype = $11,
					instrumentnotes = $12
					where pkey = $13
		", array($instrument_name, $instrument_type, $instrument_brand, $instrument_model, $university, $instrument_lab, $data_collection_software, $data_collection_software_version, $post_processing_software, $post_processing_software_version, $filament_type, $instrument_notes, $instrument_pkey));

		$db->prepare_query("delete from instrument_detector where instrument_pkey = $1", array($instrument_pkey));

		for ($d = 0; $d <= 10; $d++) {
			$dtype = $_POST["detectortype{$d}"];
			$dmake = $_POST["detectormake{$d}"];
			$dmodel = $_POST["detectormodel{$d}"];
			if ($dtype != "") {
				$db->prepare_query("insert into instrument_detector values ( nextval('instrument_detector_pkey_seq'), $1, $2, $3, $4 )", array($instrument_pkey, $dtype, $dmake, $dmodel));
			}
		}

		?>

		<header class="major"><h2>Success!</h2></header>

		<div style="text-align: center;">
			<p style="color: rgba(255,255,255,0.85); font-size: 1.1em; margin-bottom: 1.5em;">Instrument has been successfully updated.</p>
			<a href="instrumentcatalog" class="button primary" style="margin-right: 10px;">Back to Catalog</a>
			<a href="view_instrument?ii=<?php echo (int)$instrument_pkey; ?>" class="button">View Instrument</a>
		</div>

		<?php
		include 'includes/mfooter.php';
		exit();
	}

}else{
	$instrument_pkey = $_GET['ii'];

	if($instrument_pkey == "" || !is_numeric($instrument_pkey)){
		exit();
	}
}

$instcount = $db->get_var_prepared("
	select count(*)
	from
	instrument_users iu,
	institute ii,
	instrument i
	where
	iu.users_pkey = $1
	and iu.institution_pkey = ii.pkey
	and ii.pkey = i.institution_pkey
	and i.pkey = $2
", array($userpkey, $instrument_pkey));

if(!in_array($userpkey, $admin_pkeys) && $instcount == 0){
	exit();
}

$irow = $db->get_row_prepared("select * from instrument where pkey = $1", array($instrument_pkey));
$institution_pkey=$irow->institution_pkey;
$instrumentname=$irow->instrumentname;
$instrumenttype=$irow->instrumenttype;
$instrumentbrand=$irow->instrumentbrand;
$instrumentmodel=$irow->instrumentmodel;
$university=$irow->university;
$laboratory=$irow->laboratory;
$datacollectionsoftware=$irow->datacollectionsoftware;
$datacollectionsoftwareversion=$irow->datacollectionsoftwareversion;
$postprocessingsoftware=$irow->postprocessingsoftware;
$postprocessingsoftwareversion=$irow->postprocessingsoftwareversion;
$filamenttype=$irow->filamenttype;
$instrumentnotes=$irow->instrumentnotes;

// Get institute name
$institute = $db->get_row_prepared("SELECT * FROM institute WHERE pkey = $1", array($institution_pkey));

// Get existing detectors
$electronTypes = array(
	"Transmission Electron Microscopy (TEM)",
	"Scanning Transmission Electron Microscopy (STEM)",
	"Scanning Electron Microscopy (SEM)",
	"Electron Microprobe"
);
$showdetector = in_array($instrumenttype, $electronTypes) ? "block" : "none";

$drows = $db->get_results_prepared("select * from instrument_detector where instrument_pkey = $1 order by pkey", array($instrument_pkey));
$detectorData = array();
$y = 0;
if ($drows) {
	foreach ($drows as $drow) {
		$detectorData[$y] = array('type' => $drow->type, 'make' => $drow->make, 'model' => $drow->model);
		$y++;
	}
}
if ($y == 0) $y = 1;

?>

<header class="major"><h2>Edit Instrument</h2></header>

<!-- Back Link -->
<div style="margin-bottom: 1.5em;">
	<a href="instrumentcatalog" style="color: #e44c65;">&larr; Back to Instrument Catalog</a>
	<a href="view_instrument?ii=<?php echo (int)$instrument_pkey; ?>" style="color: #e44c65; margin-left: 20px;">View Instrument</a>
</div>

<?php if($error!=""){ ?>
<div style="background: rgba(228,76,101,0.15); border: 1px solid #e44c65; border-radius: 4px; padding: 1em; margin-bottom: 1.5em; color: #e44c65; font-size: 1.1em;">
	<?php echo htmlspecialchars($error); ?>
</div>
<?php } ?>

<div class="form-card">
<form method="POST" onsubmit="return validateForm()">

	<p style="color: rgba(255,255,255,0.6); margin-bottom: 1.5em;">Editing instrument at: <strong style="color: #fff;"><?php echo htmlspecialchars($institute->institute_name); ?></strong></p>

	<!-- Basic Info -->
	<div class="form-section">
		<h3 class="form-section-title">Instrument Information</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Instrument Name <span class="required">*</span></label>
				<input type="text" name="instrument_name" id="instrument_name" placeholder="e.g. SEM 1" value="<?php echo htmlspecialchars($instrumentname); ?>">
			</div>
			<div class="form-field">
				<label>Instrument Type <span class="required">*</span></label>
				<select name="instrument_type" id="instrument_type">
					<option value="">Select...</option>
					<?php
					$types = array(
						"Optical Microscopy", "Scanner",
						"Transmission Electron Microscopy (TEM)",
						"Scanning Transmission Electron Microscopy (STEM)",
						"Scanning Electron Microscopy (SEM)",
						"Electron Microprobe",
						"Fourier Transform Infrared Spectroscopy (FTIR)",
						"Raman Spectroscopy",
						"Atomic Force Microscopy (AFM)"
					);
					foreach ($types as $t) {
						$sel = ($instrumenttype == $t) ? " selected" : "";
						echo "<option value=\"" . htmlspecialchars($t) . "\"{$sel}>" . htmlspecialchars($t) . "</option>\n";
					}
					?>
				</select>
			</div>
		</div>
	</div>

	<!-- Make -->
	<div class="form-section">
		<h3 class="form-section-title">Instrument Make</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Brand</label>
				<input type="text" name="instrument_brand" placeholder="e.g. JEOL, Zeiss" value="<?php echo htmlspecialchars($instrumentbrand); ?>">
			</div>
			<div class="form-field">
				<label>Model</label>
				<input type="text" name="instrument_model" placeholder="e.g. HM5000" value="<?php echo htmlspecialchars($instrumentmodel); ?>">
			</div>
		</div>
	</div>

	<!-- Location -->
	<div class="form-section">
		<h3 class="form-section-title">Instrument Location</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>University</label>
				<input type="text" name="university" placeholder="e.g. Texas A&M" value="<?php echo htmlspecialchars($university); ?>">
			</div>
			<div class="form-field">
				<label>Lab</label>
				<input type="text" name="instrument_lab" placeholder="e.g. Geo Lab" value="<?php echo htmlspecialchars($laboratory); ?>">
			</div>
		</div>
	</div>

	<!-- Software -->
	<div class="form-section">
		<h3 class="form-section-title">Software (Data Collection)</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Application</label>
				<input type="text" name="data_collection_software" placeholder="e.g. Aztec" value="<?php echo htmlspecialchars($datacollectionsoftware); ?>">
			</div>
			<div class="form-field">
				<label>Version</label>
				<input type="text" name="data_collection_software_version" placeholder="e.g. 1.2.3" value="<?php echo htmlspecialchars($datacollectionsoftwareversion); ?>">
			</div>
		</div>
	</div>

	<div class="form-section">
		<h3 class="form-section-title">Software (Post-Processing)</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Application</label>
				<input type="text" name="post_processing_software" placeholder="e.g. Aztec" value="<?php echo htmlspecialchars($postprocessingsoftware); ?>">
			</div>
			<div class="form-field">
				<label>Version</label>
				<input type="text" name="post_processing_software_version" placeholder="e.g. 1.2.3" value="<?php echo htmlspecialchars($postprocessingsoftwareversion); ?>">
			</div>
		</div>
	</div>

	<!-- Detectors -->
	<div id="detectordetail" style="display:<?php echo $showdetector; ?>;">
		<div class="form-section">
			<h3 class="form-section-title">Filament &amp; Detectors</h3>
			<div class="form-field" style="margin-bottom: 1.5em;">
				<label>Filament Type</label>
				<input type="text" name="filament_type" value="<?php echo htmlspecialchars($filamenttype); ?>">
			</div>

			<?php for ($d = 0; $d <= 10; $d++) {
				$dval = isset($detectorData[$d]) ? $detectorData[$d] : array('type'=>'','make'=>'','model'=>'');
				$showrow = ($d == 0 || isset($detectorData[$d])) ? "block" : "none";
			?>
			<div class="detector-row" id="detectorrow<?php echo $d; ?>" style="display:<?php echo $showrow; ?>;">
				<div class="form-grid form-grid-3">
					<div class="form-field">
						<label>Type</label>
						<input type="text" name="detectortype<?php echo $d; ?>" id="detectortype<?php echo $d; ?>" value="<?php echo htmlspecialchars($dval['type']); ?>" placeholder="e.g. EBSD, Spectrometer">
					</div>
					<div class="form-field">
						<label>Make</label>
						<input type="text" name="detectormake<?php echo $d; ?>" id="detectormake<?php echo $d; ?>" value="<?php echo htmlspecialchars($dval['make']); ?>" placeholder="e.g. Oxford">
					</div>
					<div class="form-field">
						<label>Model</label>
						<input type="text" name="detectormodel<?php echo $d; ?>" id="detectormodel<?php echo $d; ?>" value="<?php echo htmlspecialchars($dval['model']); ?>" placeholder="e.g. Nordlys">
					</div>
				</div>
			</div>
			<?php } ?>

			<div style="margin-top: 0.5em;">
				<button onclick="adddetectorrow(); return false;" class="button small">Add Additional Detector</button>
			</div>
		</div>
	</div>

	<!-- Notes -->
	<div class="form-section">
		<h3 class="form-section-title">Notes</h3>
		<div class="form-field">
			<textarea name="instrument_notes" rows="5" style="width: 100%;"><?php echo htmlspecialchars($instrumentnotes); ?></textarea>
		</div>
	</div>

	<!-- Submit -->
	<div style="text-align: center; padding-top: 1em;">
		<input class="primary" type="submit" value="Save Changes" name="submit">
	</div>

	<input type="hidden" name="instrument_pkey" value="<?php echo (int)$instrument_pkey; ?>">

</form>
</div>

<script type='text/javascript'>
	var addrownum = <?php echo $y; ?>;

	function adddetectorrow(){
		if (addrownum <= 10) {
			$("#detectorrow" + addrownum).show();
			addrownum++;
		}
	}

	function validateForm(){
		var instrumentName = $("#instrument_name").val();
		var instrumentType = $("#instrument_type").val();
		if(instrumentName=="" || instrumentType==""){
			alert("Instrument Type and Instrument Name are required!");
			return false;
		}
	}

	function checkform(){
		var instrumentType = $("#instrument_type").val();
		var electronTypes = [
			"Transmission Electron Microscopy (TEM)",
			"Scanning Transmission Electron Microscopy (STEM)",
			"Scanning Electron Microscopy (SEM)",
			"Electron Microprobe"
		];

		if(electronTypes.indexOf(instrumentType) === -1){
			$("#detectordetail").hide();
			for (var i = 0; i <= 10; i++) {
				$("#detectortype" + i).val("");
				$("#detectormake" + i).val("");
				$("#detectormodel" + i).val("");
				if (i > 0) $("#detectorrow" + i).hide();
			}
		}else{
			$("#detectordetail").show();
		}
	}

	$("#instrument_type").change(function() { checkform(); });
</script>

<style>
.form-card {
	background: rgba(255, 255, 255, 0.04);
	border: 1px solid rgba(255, 255, 255, 0.1);
	border-radius: 6px;
	padding: 2em;
}

.form-section {
	margin-bottom: 2em;
	padding-bottom: 1.5em;
	border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.form-section:last-child {
	margin-bottom: 0;
	padding-bottom: 0;
	border-bottom: none;
}

.form-section-title {
	color: #fff;
	font-size: 1.1em;
	font-weight: 600;
	margin-bottom: 1em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.form-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 1.2em;
}

.form-grid-3 {
	grid-template-columns: 1fr 1fr 1fr;
}

@media (max-width: 768px) {
	.form-grid, .form-grid-3 {
		grid-template-columns: 1fr;
	}
}

.form-field label {
	display: block;
	color: rgba(255, 255, 255, 0.7);
	font-size: 0.9em;
	margin-bottom: 0.4em;
}

.required {
	color: #e44c65;
}

.detector-row {
	margin-bottom: 0.8em;
	padding-bottom: 0.8em;
	border-bottom: 1px solid rgba(255, 255, 255, 0.04);
}
</style>

		<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include 'includes/mfooter.php';
?>
