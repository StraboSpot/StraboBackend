<?php
/**
 * File: add_instrument.php
 * Description: Adds new records to instrument, instrument_detector table(s)
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
	$ipkey = $_POST['i'];

	if($ipkey == "" || !is_numeric($ipkey)){
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

		$instrument_pkey = $db->get_var("SELECT nextval('instrument_pkey_seq')");

		$db->prepare_query("
				INSERT INTO instrument VALUES
				($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14)
		", array(
			$instrument_pkey,
			$institution_pkey,
			$instrument_name,
			$instrument_type,
			$instrument_brand,
			$instrument_model,
			$university,
			$instrument_lab,
			$data_collection_software,
			$data_collection_software_version,
			$post_processing_software,
			$post_processing_software_version,
			$filament_type,
			$instrument_notes
		));

		// Insert detectors
		for ($d = 0; $d <= 10; $d++) {
			$dtype = $_POST["detectortype{$d}"];
			$dmake = $_POST["detectormake{$d}"];
			$dmodel = $_POST["detectormodel{$d}"];
			if ($dtype != "") {
				$db->prepare_query("INSERT INTO instrument_detector VALUES (nextval('instrument_detector_pkey_seq'), $1, $2, $3, $4)", array($instrument_pkey, $dtype, $dmake, $dmodel));
			}
		}

		?>

		<header class="major"><h2>Success!</h2></header>

		<div style="text-align: center;">
			<p style="color: rgba(255,255,255,0.85); font-size: 1.1em; margin-bottom: 1.5em;">Instrument has been successfully added.</p>
			<a href="instrumentcatalog" class="button primary">Continue to Catalog</a>
		</div>

		<?php
		include 'includes/mfooter.php';
		exit();
	}

}else{
	$ipkey = $_GET['i'];

	if($ipkey == "" || !is_numeric($ipkey)){
		exit();
	}
}

$instcount = $db->get_var_prepared("
	SELECT count(*)
	FROM instrument_users
	WHERE users_pkey = $1
	AND institution_pkey = $2
", array($userpkey, $ipkey));

if(!in_array($userpkey, $admin_pkeys) && $instcount == 0){
	exit();
}

$irow = $db->get_row_prepared("SELECT * FROM institute WHERE pkey = $1", array($ipkey));

?>

<header class="major"><h2>Add Instrument</h2></header>

<!-- Back Link -->
<div style="margin-bottom: 1.5em;">
	<a href="instrumentcatalog" style="color: #e44c65;">&larr; Back to Instrument Catalog</a>
</div>

<?php if($error!=""){ ?>
<div style="background: rgba(228,76,101,0.15); border: 1px solid #e44c65; border-radius: 4px; padding: 1em; margin-bottom: 1.5em; color: #e44c65; font-size: 1.1em;">
	<?php echo htmlspecialchars($error); ?>
</div>
<?php } ?>

<div class="form-card">
<form method="POST" onsubmit="return validateForm()">

	<p style="color: rgba(255,255,255,0.6); margin-bottom: 1.5em;">Adding instrument to: <strong style="color: #fff;"><?php echo htmlspecialchars($irow->institute_name); ?></strong></p>

	<!-- Basic Info -->
	<div class="form-section">
		<h3 class="form-section-title">Instrument Information</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Instrument Name <span class="required">*</span></label>
				<input type="text" name="instrument_name" id="instrument_name" placeholder="e.g. SEM 1" value="<?php echo htmlspecialchars($instrument_name); ?>">
			</div>
			<div class="form-field">
				<label>Instrument Type <span class="required">*</span></label>
				<select name="instrument_type" id="instrument_type">
					<option value="">Select...</option>
					<option value="Optical Microscopy">Optical Microscopy</option>
					<option value="Scanner">Scanner</option>
					<option value="Transmission Electron Microscopy (TEM)">Transmission Electron Microscopy (TEM)</option>
					<option value="Scanning Transmission Electron Microscopy (STEM)">Scanning Transmission Electron Microscopy (STEM)</option>
					<option value="Scanning Electron Microscopy (SEM)">Scanning Electron Microscopy (SEM)</option>
					<option value="Electron Microprobe">Electron Microprobe</option>
					<option value="Fourier Transform Infrared Spectroscopy (FTIR)">Fourier Transform Infrared Spectroscopy (FTIR)</option>
					<option value="Raman Spectroscopy">Raman Spectroscopy</option>
					<option value="Atomic Force Microscopy (AFM)">Atomic Force Microscopy (AFM)</option>
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
				<input type="text" name="instrument_brand" placeholder="e.g. JEOL, Zeiss" value="<?php echo htmlspecialchars($instrument_brand); ?>">
			</div>
			<div class="form-field">
				<label>Model</label>
				<input type="text" name="instrument_model" placeholder="e.g. HM5000" value="<?php echo htmlspecialchars($instrument_model); ?>">
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
				<input type="text" name="instrument_lab" placeholder="e.g. Geo Lab" value="<?php echo htmlspecialchars($instrument_lab); ?>">
			</div>
		</div>
	</div>

	<!-- Software -->
	<div class="form-section">
		<h3 class="form-section-title">Software (Data Collection)</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Application</label>
				<input type="text" name="data_collection_software" placeholder="e.g. Aztec" value="<?php echo htmlspecialchars($data_collection_software); ?>">
			</div>
			<div class="form-field">
				<label>Version</label>
				<input type="text" name="data_collection_software_version" placeholder="e.g. 1.2.3" value="<?php echo htmlspecialchars($data_collection_software_version); ?>">
			</div>
		</div>
	</div>

	<div class="form-section">
		<h3 class="form-section-title">Software (Post-Processing)</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Application</label>
				<input type="text" name="post_processing_software" placeholder="e.g. Aztec" value="<?php echo htmlspecialchars($post_processing_software); ?>">
			</div>
			<div class="form-field">
				<label>Version</label>
				<input type="text" name="post_processing_software_version" placeholder="e.g. 1.2.3" value="<?php echo htmlspecialchars($post_processing_software_version); ?>">
			</div>
		</div>
	</div>

	<!-- Detectors (shown for electron microscopy types) -->
	<div id="detectordetail" style="display:none;">
		<div class="form-section">
			<h3 class="form-section-title">Filament &amp; Detectors</h3>
			<div class="form-field" style="margin-bottom: 1.5em;">
				<label>Filament Type</label>
				<input type="text" name="filament_type">
			</div>

			<?php for ($d = 0; $d <= 10; $d++) { ?>
			<div class="detector-row" id="detectorrow<?php echo $d; ?>" style="display:<?php echo $d == 0 ? 'block' : 'none'; ?>;">
				<div class="form-grid form-grid-3">
					<div class="form-field">
						<label>Type</label>
						<input type="text" name="detectortype<?php echo $d; ?>" id="detectortype<?php echo $d; ?>" placeholder="e.g. EBSD, Spectrometer">
					</div>
					<div class="form-field">
						<label>Make</label>
						<input type="text" name="detectormake<?php echo $d; ?>" id="detectormake<?php echo $d; ?>" placeholder="e.g. Oxford">
					</div>
					<div class="form-field">
						<label>Model</label>
						<input type="text" name="detectormodel<?php echo $d; ?>" id="detectormodel<?php echo $d; ?>" placeholder="e.g. Nordlys">
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
			<textarea name="instrument_notes" rows="5" style="width: 100%;"><?php echo htmlspecialchars($instrument_notes); ?></textarea>
		</div>
	</div>

	<!-- Submit -->
	<div style="text-align: center; padding-top: 1em;">
		<input type="submit" value="Save Instrument" name="submit" class="primary">
	</div>

	<input type="hidden" name="i" value="<?php echo (int)$ipkey; ?>">

</form>
</div>

<script type='text/javascript'>
	var addrownum = 1;

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
