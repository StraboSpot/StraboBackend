<?php
/**
 * File: add_institute.php
 * Description: Adds new records to institute table(s)
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

if(!in_array($userpkey, $admin_pkeys)){
	header("Location: instrumentcatalog");
	exit();
}

include 'includes/mheader.php';
?>

<script src='/assets/js/jquery/jquery.min.js'></script>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

<?php

if($_POST['submit']!=""){

	$institute_type = $_POST['institute_type'];
	$institute_name = $_POST['institute_name'];
	$lab_name = $_POST['lab_name'];
	$facility_id = $_POST['facility_id'];
	$website = $_POST['website'];
	$country = $_POST['country'];
	$state = $_POST['state'];
	$address1 = $_POST['address1'];
	$address2 = $_POST['address2'];
	$city = $_POST['city'];
	$zip = $_POST['zip'];
	$contact_first_name = $_POST['contact_first_name'];
	$contact_last_name = $_POST['contact_last_name'];
	$contact_title = $_POST['contact_title'];
	$contact_email = $_POST['contact_email'];

	$error = "";
	if($institute_name == "") $error .= "Institute name is required.\n";
	if($institute_type == "") $error .= "Institute type is required.\n";

	if($error == ""){
		$institute_pkey = $db->get_var("SELECT nextval('institute_pkey_seq')");

		$db->prepare_query("
			INSERT INTO institute VALUES (
				$1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16
			)
		", array(
			$institute_pkey,
			$institute_type,
			$institute_name,
			$lab_name,
			$facility_id,
			$website,
			$country,
			$state,
			$address1,
			$address2,
			$city,
			$zip,
			$contact_first_name,
			$contact_last_name,
			$contact_title,
			$contact_email
		));

		?>
		<header class="major"><h2>Success!</h2></header>

		<div style="text-align: center;">
			<p style="color: rgba(255,255,255,0.85); font-size: 1.1em; margin-bottom: 1.5em;">Institute has been successfully added.</p>
			<a href="instrumentcatalog" class="button primary">Continue to Catalog</a>
		</div>

		<div class="bottomSpacer"></div>
	</div>
</div>
		<?php
		include 'includes/mfooter.php';
		exit();
	}
}

?>

<header class="major"><h2>Add Institute</h2></header>

<!-- Back Link -->
<div style="margin-bottom: 1.5em;">
	<a href="instrumentcatalog" style="color: #e44c65;">&larr; Back to Instrument Catalog</a>
</div>

<?php if(isset($error) && $error != ""){ ?>
<div style="background: rgba(228,76,101,0.15); border: 1px solid #e44c65; border-radius: 4px; padding: 1em; margin-bottom: 1.5em; color: #e44c65; font-size: 1.1em;">
	<?php echo nl2br(htmlspecialchars($error)); ?>
</div>
<?php } ?>

<div class="form-card">
<form method="POST" onsubmit="return validateForm()">

	<!-- Basic Info -->
	<div class="form-section">
		<h3 class="form-section-title">Institute Information</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Institute Name <span class="required">*</span></label>
				<input type="text" name="institute_name" id="institute_name" value="<?php echo htmlspecialchars($institute_name ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Institute Type <span class="required">*</span></label>
				<select name="institute_type" id="institute_type">
					<option value="">Select...</option>
					<option value="University Lab"<?php if(($institute_type ?? '')=="University Lab") echo " selected"; ?>>University Lab</option>
					<option value="Government Facility"<?php if(($institute_type ?? '')=="Government Facility") echo " selected"; ?>>Government Facility</option>
					<option value="Private Industry Lab"<?php if(($institute_type ?? '')=="Private Industry Lab") echo " selected"; ?>>Private Industry Lab</option>
				</select>
			</div>
			<div class="form-field">
				<label>Lab Name</label>
				<input type="text" name="lab_name" value="<?php echo htmlspecialchars($lab_name ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Facility ID</label>
				<input type="text" name="facility_id" value="<?php echo htmlspecialchars($facility_id ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Website</label>
				<input type="text" name="website" placeholder="https://" value="<?php echo htmlspecialchars($website ?? ''); ?>">
			</div>
		</div>
	</div>

	<!-- Address -->
	<div class="form-section">
		<h3 class="form-section-title">Address</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>Address Line 1</label>
				<input type="text" name="address1" value="<?php echo htmlspecialchars($address1 ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Address Line 2</label>
				<input type="text" name="address2" value="<?php echo htmlspecialchars($address2 ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>City</label>
				<input type="text" name="city" value="<?php echo htmlspecialchars($city ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>State / Province</label>
				<input type="text" name="state" value="<?php echo htmlspecialchars($state ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Zip / Postal Code</label>
				<input type="text" name="zip" value="<?php echo htmlspecialchars($zip ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Country</label>
				<input type="text" name="country" value="<?php echo htmlspecialchars($country ?? ''); ?>">
			</div>
		</div>
	</div>

	<!-- Contact -->
	<div class="form-section">
		<h3 class="form-section-title">Contact</h3>
		<div class="form-grid">
			<div class="form-field">
				<label>First Name</label>
				<input type="text" name="contact_first_name" value="<?php echo htmlspecialchars($contact_first_name ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Last Name</label>
				<input type="text" name="contact_last_name" value="<?php echo htmlspecialchars($contact_last_name ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Title</label>
				<input type="text" name="contact_title" value="<?php echo htmlspecialchars($contact_title ?? ''); ?>">
			</div>
			<div class="form-field">
				<label>Email</label>
				<input type="email" name="contact_email" value="<?php echo htmlspecialchars($contact_email ?? ''); ?>">
			</div>
		</div>
	</div>

	<!-- Submit -->
	<div style="text-align: center; padding-top: 1em;">
		<input type="submit" value="Save Institute" name="submit" class="primary">
	</div>

</form>
</div>

<script>
function validateForm(){
	var error = "";
	if($("#institute_name").val() == "") error += "Institute name is required.\n";
	if($("#institute_type").val() == "") error += "Institute type is required.\n";
	if(error != ""){
		alert(error);
		return false;
	}
}
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

@media (max-width: 768px) {
	.form-grid {
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
</style>

		<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include 'includes/mfooter.php';
?>
