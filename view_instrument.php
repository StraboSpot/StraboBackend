<?php
/**
 * File: view_instrument.php
 * Description: Read-only detail view of a single instrument
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once("adminkeys.php");
session_start();

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
	$_SESSION['loggedin'] = "no";
}
if (isset($_SESSION['LAST_ACTIVITY'])) {
	$_SESSION['LAST_ACTIVITY'] = time();
}

$is_logged_in = (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] == "yes");
$userpkey = $is_logged_in ? (int)$_SESSION['userpkey'] : 0;
$is_admin = $is_logged_in && in_array($userpkey, $admin_pkeys);

$instrument_pkey = isset($_GET['ii']) ? (int)$_GET['ii'] : 0;
if ($instrument_pkey == 0) {
	header("Location: instrumentcatalog");
	exit();
}

// Initialize database
include_once $_SERVER['DOCUMENT_ROOT'] . "/includes/config.inc.php";
include $_SERVER['DOCUMENT_ROOT'] . "/db.php";

// Get instrument
$irow = $db->get_row_prepared("SELECT * FROM instrument WHERE pkey = $1", array($instrument_pkey));
if (!$irow) {
	header("Location: instrumentcatalog");
	exit();
}

// Get institute name
$institute = $db->get_row_prepared("SELECT * FROM institute WHERE pkey = $1", array($irow->institution_pkey));

// Get detectors
$detectors = $db->get_results_prepared(
	"SELECT * FROM instrument_detector WHERE instrument_pkey = $1 ORDER BY pkey",
	array($instrument_pkey)
);

// Check if user can edit this instrument
$can_edit = false;
if ($is_admin) {
	$can_edit = true;
} elseif ($is_logged_in) {
	$instcount = $db->get_var_prepared("
		SELECT count(*)
		FROM instrument_users iu, institute ii, instrument i
		WHERE iu.users_pkey = $1
		AND iu.institution_pkey = ii.pkey
		AND ii.pkey = i.institution_pkey
		AND i.pkey = $2
	", array($userpkey, $instrument_pkey));
	$can_edit = ($instcount > 0);
}

include 'includes/mheader.php';
?>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2><?php echo htmlspecialchars($irow->instrumentname); ?></h2>
		</header>

		<!-- Back Link -->
		<div style="margin-bottom: 1.5em;">
			<a href="instrumentcatalog" style="color: #e44c65;">&larr; Back to Instrument Catalog</a>
			<?php if ($can_edit) { ?>
			<a href="edit_instrument?ii=<?php echo $instrument_pkey; ?>" class="button primary small" style="float: right;">Edit Instrument</a>
			<?php } ?>
		</div>

		<!-- Instrument Details Card -->
		<div class="view-card">

			<!-- Basic Info -->
			<div class="view-section">
				<h3 class="view-section-title">Instrument Information</h3>
				<div class="view-grid">
					<div class="view-field">
						<span class="view-label">Institute</span>
						<span class="view-value"><?php echo htmlspecialchars($institute->institute_name); ?></span>
					</div>
					<div class="view-field">
						<span class="view-label">Instrument Type</span>
						<span class="view-value"><?php echo htmlspecialchars($irow->instrumenttype); ?></span>
					</div>
					<?php if ($irow->instrumentbrand) { ?>
					<div class="view-field">
						<span class="view-label">Brand</span>
						<span class="view-value"><?php echo htmlspecialchars($irow->instrumentbrand); ?></span>
					</div>
					<?php } ?>
					<?php if ($irow->instrumentmodel) { ?>
					<div class="view-field">
						<span class="view-label">Model</span>
						<span class="view-value"><?php echo htmlspecialchars($irow->instrumentmodel); ?></span>
					</div>
					<?php } ?>
				</div>
			</div>

			<!-- Location -->
			<?php if ($irow->university || $irow->laboratory) { ?>
			<div class="view-section">
				<h3 class="view-section-title">Location</h3>
				<div class="view-grid">
					<?php if ($irow->university) { ?>
					<div class="view-field">
						<span class="view-label">University</span>
						<span class="view-value"><?php echo htmlspecialchars($irow->university); ?></span>
					</div>
					<?php } ?>
					<?php if ($irow->laboratory) { ?>
					<div class="view-field">
						<span class="view-label">Laboratory</span>
						<span class="view-value"><?php echo htmlspecialchars($irow->laboratory); ?></span>
					</div>
					<?php } ?>
				</div>
			</div>
			<?php } ?>

			<!-- Software -->
			<?php if ($irow->datacollectionsoftware || $irow->postprocessingsoftware) { ?>
			<div class="view-section">
				<h3 class="view-section-title">Software</h3>
				<div class="view-grid">
					<?php if ($irow->datacollectionsoftware) { ?>
					<div class="view-field">
						<span class="view-label">Data Collection Software</span>
						<span class="view-value"><?php echo htmlspecialchars($irow->datacollectionsoftware); ?><?php if ($irow->datacollectionsoftwareversion) echo ' v' . htmlspecialchars($irow->datacollectionsoftwareversion); ?></span>
					</div>
					<?php } ?>
					<?php if ($irow->postprocessingsoftware) { ?>
					<div class="view-field">
						<span class="view-label">Post-Processing Software</span>
						<span class="view-value"><?php echo htmlspecialchars($irow->postprocessingsoftware); ?><?php if ($irow->postprocessingsoftwareversion) echo ' v' . htmlspecialchars($irow->postprocessingsoftwareversion); ?></span>
					</div>
					<?php } ?>
				</div>
			</div>
			<?php } ?>

			<!-- Filament & Detectors -->
			<?php if ($irow->filamenttype || ($detectors && count($detectors) > 0)) { ?>
			<div class="view-section">
				<h3 class="view-section-title">Filament &amp; Detectors</h3>
				<?php if ($irow->filamenttype) { ?>
				<div class="view-grid" style="margin-bottom: 1em;">
					<div class="view-field">
						<span class="view-label">Filament Type</span>
						<span class="view-value"><?php echo htmlspecialchars($irow->filamenttype); ?></span>
					</div>
				</div>
				<?php } ?>
				<?php if ($detectors && count($detectors) > 0) { ?>
				<table class="instrument-table">
					<thead>
						<tr>
							<th>Detector Type</th>
							<th>Make</th>
							<th>Model</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($detectors as $det) { ?>
						<tr>
							<td><?php echo htmlspecialchars($det->type); ?></td>
							<td><?php echo $det->make ? htmlspecialchars($det->make) : '<span style="color: rgba(255,255,255,0.3);">&mdash;</span>'; ?></td>
							<td><?php echo $det->model ? htmlspecialchars($det->model) : '<span style="color: rgba(255,255,255,0.3);">&mdash;</span>'; ?></td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
				<?php } ?>
			</div>
			<?php } ?>

			<!-- Notes -->
			<?php if ($irow->instrumentnotes) { ?>
			<div class="view-section">
				<h3 class="view-section-title">Notes</h3>
				<p style="color: rgba(255,255,255,0.85); line-height: 1.7;"><?php echo nl2br(htmlspecialchars($irow->instrumentnotes)); ?></p>
			</div>
			<?php } ?>

		</div>

		<div class="bottomSpacer"></div>

	</div>
</div>

<style>
.view-card {
	background: rgba(255, 255, 255, 0.04);
	border: 1px solid rgba(255, 255, 255, 0.1);
	border-radius: 6px;
	padding: 2em;
}

.view-section {
	margin-bottom: 2em;
	padding-bottom: 1.5em;
	border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.view-section:last-child {
	margin-bottom: 0;
	padding-bottom: 0;
	border-bottom: none;
}

.view-section-title {
	color: #fff;
	font-size: 1.1em;
	font-weight: 600;
	margin-bottom: 1em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.view-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
	gap: 1.2em;
}

.view-field {
	display: flex;
	flex-direction: column;
	gap: 0.3em;
}

.view-label {
	color: rgba(255, 255, 255, 0.5);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.view-value {
	color: rgba(255, 255, 255, 0.9);
	font-size: 1.05em;
}

.instrument-table {
	width: 100%;
	border-collapse: collapse;
}

.instrument-table th {
	text-align: left;
	color: rgba(255, 255, 255, 0.5);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	padding: 0.7em 1em;
	border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.instrument-table td {
	padding: 0.7em 1em;
	color: rgba(255, 255, 255, 0.85);
	border-bottom: 1px solid rgba(255, 255, 255, 0.05);
	font-size: 0.95em;
}
</style>

<?php
include 'includes/mfooter.php';
?>
