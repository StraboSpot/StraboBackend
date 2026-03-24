<?php
/**
 * File: gems_export.php
 * Description: USGS GeMS export configuration page
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
include("includes/straboClasses/straboOutputClass.php");
include 'includes/mheader.php';

$dsids = $_GET['dsids'] ?? '';

// Scan dataset and compute auto-mappings
$straboOut = new straboOutputClass($strabo, $_GET);
$scanResult = $straboOut->gemsScanDataset($dsids);
$uniqueLineTypes = $straboOut->gemsGetUniqueLineTypes($scanResult['lines']);
$uniqueOrTypes = $straboOut->gemsGetUniqueOrientationTypes($scanResult['points']);
$lineMaps = $straboOut->gemsAutoMapLines($uniqueLineTypes);
$orMaps = $straboOut->gemsAutoMapOrientations($uniqueOrTypes);

// Get dataset name for default filename
$datasetName = $strabo->getDatasetName($dsids);
$datasetName = $datasetName ?: 'GeMS_Export';

// Config options for dropdowns
$lnSortOptions = ["ContactsAndFaults", "GeologicLines", "MapUnitLines", "CartographicLines"];
$lnTypeOptions = [
	"contact","igneous contact", "intrusive contact", "metamorphic contact",
	"internal contact", "angular unconformity",
	"disconformity", "nonconformity", "paraconformity",
	"unconformity", "fault", "normal fault", "thrust fault",
	"reverse fault","right-lateral strike-slip fault",
	"left-lateral strike-slip fault", "right-lateral oblique-slip fault",
	"left-lateral oblique-slip fault", "detachment fault",
	"low-angle normal fault", "fault scarp", "scarp",
	"elevation profile", "eolian", "escarpment", "geophysical fault",
	"gradational contact", "headscarp", "joint", "breccia",
	"miscellaneous map element", "map boundary",
	"anticline", "asymmetric anticline", "syncline", "asymmetric syncline",
	"crest", "escarpment", "geophysical boundary", "geophysical survey",
	"headscarp", "landslide", "lineament", "lineation", "metamorphic facies",
	"monocline", "monocline, anticlinal bend", "monocline, synclinal bend",
	"overturned anticline", "overturned syncline", "sedimentary facies",
	"shear zone",
	"key bed", "dike", "clay bed", "coal bed", "N/A",
	"analytical", "bedding line", "cross-section line",
	"feature label", "leader", "measured-section line",
	"scale change", "trench", "well"
];
$ptTypeOptions = [
	"anticline", "bedding", "crenulation lineation", "cumulate foliation", "dike inclination",
	"eolian", "fault", "fault decoration", "fault inclination", "fault offset", "fluvial",
	"fold decoration", "fold hinge", "foliation", "groundwater movement", "intersection lineation",
	"joint", "landslide", "lineation", "local fault offset", "mineral lineation", "minor fault",
	"minor fold", "modern current", "overturned bedding", "paleocurrent", "plunge",
	"primary foliation", "secondary foliation", "slickenline", "spring", "stretching lineation",
	"syncline", "Toreva block"
];
?>

		<!-- Main -->
			<div id="main" class="wrapper style1">
				<div class="container">

					<header class="major">
						<h2>USGS GeMS Export</h2>
					</header>
					<section id="content">

<?php if(empty($scanResult['lines']) && empty($scanResult['points'])): ?>
	<p><em>No data found for this dataset. Please verify the dataset contains features.</em></p>
<?php endif; ?>

<?php if(!empty($scanResult['errors'])): ?>
	<p><em><?php echo count($scanResult['errors']); ?> line(s) without trace data were skipped.</em></p>
<?php endif; ?>

<form method="POST" action="/searchdownload" id="gemsForm">
	<input type="hidden" name="type" value="gems">
	<input type="hidden" name="dsids" value="<?php echo htmlspecialchars($dsids); ?>">
	<input type="hidden" name="userpkey" value="<?php echo htmlspecialchars($userpkey); ?>">

	<!-- Step 1: Metadata -->
	<h3>Step 1: Enter Metadata</h3>
	<div style="margin-bottom:30px;">
		<table style="border-collapse:collapse;">
			<tr>
				<td style="padding:5px 10px 5px 0;"><label for="gems_dsid">Data Source ID:</label></td>
				<td style="padding:5px;"><input type="text" id="gems_dsid" name="gems_dsid" value="" style="width:250px;" placeholder="e.g. DAS001"></td>
			</tr>
			<tr>
				<td style="padding:5px 10px 5px 0;"><label for="gems_lsid">Location Source ID:</label></td>
				<td style="padding:5px;"><input type="text" id="gems_lsid" name="gems_lsid" value="LSStrabo" style="width:250px;"></td>
			</tr>
			<tr>
				<td style="padding:5px 10px 5px 0;"><label for="gems_osid">Orientation Source ID:</label></td>
				<td style="padding:5px;"><input type="text" id="gems_osid" name="gems_osid" value="" style="width:250px;" placeholder="e.g. OSStrabo"></td>
			</tr>
			<tr>
				<td style="padding:5px 10px 5px 0;"><label for="gems_dataset_name">Output Dataset Name:</label></td>
				<td style="padding:5px;"><input type="text" id="gems_dataset_name" name="gems_dataset_name" value="<?php echo htmlspecialchars($datasetName); ?>" style="width:250px;"></td>
			</tr>
		</table>
	</div>

	<!-- Step 2: Review Attribute Mappings -->
	<h3>Step 2: Review Attribute Mappings</h3>
	<p>The mappings below have been auto-generated from your dataset. Review and adjust as needed before running the translation.</p>

	<div style="margin-bottom:20px;">
		<button type="button" class="gems-tab-btn active" onclick="showTab('lines')" id="tabBtnLines" style="padding:8px 16px;cursor:pointer;background:#4CAF50;color:white;border:none;border-radius:4px 4px 0 0;margin-right:2px;">Line Attributes (<?php echo count($uniqueLineTypes); ?>)</button>
		<button type="button" class="gems-tab-btn" onclick="showTab('orientations')" id="tabBtnOrientations" style="padding:8px 16px;cursor:pointer;background:#ddd;color:#333;border:none;border-radius:4px 4px 0 0;">Orientation Attributes (<?php echo count($uniqueOrTypes); ?>)</button>
	</div>

	<style>
		.gems-table { width:100%; table-layout:fixed; border-collapse:collapse; }
		.gems-table th { text-align:left; padding:8px; border-bottom:2px solid #555; font-size:0.9em; }
		.gems-table td { padding:8px; border-bottom:1px solid #444; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; }
		.gems-table td input[type="text"],
		.gems-table td select { width:100%; box-sizing:border-box; padding:4px 6px; font-size:0.85em; }
		.gems-tab-content { border:1px solid #555; padding:10px; border-radius:0 4px 4px 4px; max-height:450px; overflow-y:auto; }
	</style>

	<!-- Line Attributes Tab -->
	<div id="tabLines" class="gems-tab-content">
		<?php if(empty($uniqueLineTypes)): ?>
			<p><em>No line features found in this dataset.</em></p>
		<?php else: ?>
			<table class="gems-table">
				<colgroup>
					<col style="width:30%;">
					<col style="width:15%;">
					<col style="width:25%;">
					<col style="width:30%;">
				</colgroup>
				<thead>
					<tr>
						<th>Strabo Input</th>
						<th>FGDC Symbol</th>
						<th>GeMS Sort</th>
						<th>GeMS Line Type</th>
					</tr>
				</thead>
				<tbody>
					<?php for($i = 0; $i < count($uniqueLineTypes); $i++): ?>
					<tr>
						<td title="<?php echo htmlspecialchars($lineMaps['sb'][$i]); ?>">
							<?php echo htmlspecialchars($lineMaps['sb'][$i]); ?>
							<input type="hidden" name="ln_sb[]" value="<?php echo htmlspecialchars($lineMaps['sb'][$i]); ?>">
						</td>
						<td>
							<input type="text" name="ln_symbol[]" value="<?php echo htmlspecialchars($lineMaps['symbol'][$i]); ?>">
						</td>
						<td>
							<select name="ln_sort[]">
								<?php foreach($lnSortOptions as $opt): ?>
									<option value="<?php echo htmlspecialchars($opt); ?>"<?php echo ($lineMaps['sort'][$i] === $opt) ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select name="ln_type[]">
								<?php foreach($lnTypeOptions as $opt): ?>
									<option value="<?php echo htmlspecialchars($opt); ?>"<?php echo ($lineMaps['type'][$i] === $opt) ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php endfor; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Orientation Attributes Tab -->
	<div id="tabOrientations" class="gems-tab-content" style="display:none;">
		<?php if(empty($uniqueOrTypes)): ?>
			<p><em>No orientation data found in this dataset.</em></p>
		<?php else: ?>
			<table class="gems-table">
				<colgroup>
					<col style="width:40%;">
					<col style="width:20%;">
					<col style="width:40%;">
				</colgroup>
				<thead>
					<tr>
						<th>Strabo Input</th>
						<th>FGDC Symbol</th>
						<th>GeMS Orientation Type</th>
					</tr>
				</thead>
				<tbody>
					<?php for($i = 0; $i < count($uniqueOrTypes); $i++): ?>
					<tr>
						<td title="<?php echo htmlspecialchars($orMaps['sb'][$i]); ?>">
							<?php echo htmlspecialchars($orMaps['sb'][$i]); ?>
							<input type="hidden" name="pt_sb[]" value="<?php echo htmlspecialchars($orMaps['sb'][$i]); ?>">
						</td>
						<td>
							<input type="text" name="pt_symbol[]" value="<?php echo htmlspecialchars($orMaps['symbol'][$i]); ?>">
						</td>
						<td>
							<select name="pt_type[]">
								<?php foreach($ptTypeOptions as $opt): ?>
									<option value="<?php echo htmlspecialchars($opt); ?>"<?php echo ($orMaps['type'][$i] === $opt) ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php endfor; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<br>

	<!-- Step 3: Run Translation -->
	<h3>Step 3: Run Translation</h3>
	<p>Click the button below to generate 9 GeMS-compliant GeoJSON files packaged as a ZIP download.</p>
	<div style="text-align:center;padding-top:10px;">
		<input type="submit" class="button primary" value="Run Translation &amp; Download">
	</div>

</form>

<script>
function showTab(tab){
	document.getElementById('tabLines').style.display = (tab === 'lines') ? 'block' : 'none';
	document.getElementById('tabOrientations').style.display = (tab === 'orientations') ? 'block' : 'none';
	document.getElementById('tabBtnLines').style.background = (tab === 'lines') ? '#4CAF50' : '#ddd';
	document.getElementById('tabBtnLines').style.color = (tab === 'lines') ? 'white' : '#333';
	document.getElementById('tabBtnOrientations').style.background = (tab === 'orientations') ? '#4CAF50' : '#ddd';
	document.getElementById('tabBtnOrientations').style.color = (tab === 'orientations') ? 'white' : '#333';
}
</script>

					</section>

				<div class="bottomSpacer"></div>

				</div>
			</div>

<?php
include 'includes/mfooter.php';
?>
