<?php
/**
 * File: design_template.php
 * Description: Template Wizard - Template Designer with HandsonTable (Page 2)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Get POST data from Page 1
$template_method = isset($_POST['template_method']) ? $_POST['template_method'] : 'new';
$template_id = isset($_POST['template_id']) ? $_POST['template_id'] : '';
$selected_sections = isset($_POST['selected_sections']) ? $_POST['selected_sections'] : array('spot');

// Define column mappings
$column_mappings = array(
	'spot' => array('Name', 'Date', 'Self', 'Notes', 'Real World Coordinates', 'Pixel Coordinates', 'Latitude', 'Longitude', 'Altitude'),
	'orientation' => array('Strike', 'Dip', 'Planar Feature Type', 'Other Feature', 'Rake', 'Trend', 'Plunge', 'Linear Feature Type'),
	'rockunit' => array('Label', 'Type', 'Color'),
	'sample' => array('Sample Type', 'Sample Size', 'Sample Color')
);

// Build column list based on method
$columns = array();
if ($template_method === 'existing') {
	// Placeholder columns for existing template testing
	$columns = array('Name', 'Date', 'Strike', 'Dip', 'Notes');
} else {
	// Build columns from selected sections
	foreach ($selected_sections as $section) {
		if (isset($column_mappings[$section])) {
			$columns = array_merge($columns, $column_mappings[$section]);
		}
	}
}

include("../includes/mheader.php");
?>

<!-- HandsonTable CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@12.4.0/dist/handsontable.full.min.css">

<style>
/* Dark theme for HandsonTable */
.htCore {
	background-color: #2e3440;
	color: #eceff4;
}

.handsontable th {
	background-color: #3b4252;
	color: #eceff4;
	font-weight: bold;
}

.handsontable td {
	background-color: #2e3440;
	color: #eceff4;
	border-color: #4c566a;
}

.handsontable td.area {
	background-color: #434c5e;
}

.ht_master .wtHolder {
	background-color: #2e3440;
}

.handsontable .htDimmed {
	color: #d8dee9;
}

#hot-container {
	margin: 20px 0;
	height: 500px;
	overflow: hidden;
}

#saveSection {
	display: none;
	margin-top: 20px;
}

#templateNameSection {
	display: none;
	margin-bottom: 15px;
}
</style>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Template Design</h2>
						</header>

						<!-- Content -->
							<section id="content">

								<!-- HandsonTable Container -->
								<div id="hot-container"></div>

								<!-- Save Section -->
								<div id="saveSection">
									<!-- Template Name (only for new templates) -->
									<div id="templateNameSection" class="row gtr-uniform">
										<div class="col-12">
											<label for="template_name">Template Name *</label>
											<input type="text" id="template_name" name="template_name" placeholder="Enter template name" />
											<span id="nameError" style="color: #bf616a; display: none;">Template name is required</span>
										</div>
									</div>

									<!-- Save Button -->
									<div class="row gtr-uniform">
										<div class="col-12">
											<ul class="actions">
												<li><button id="saveBtn" class="button primary">Save</button></li>
											</ul>
										</div>
									</div>
								</div>

								<!-- Hidden form for POST submission -->
								<form id="submitForm" method="POST" action="save_template.php" style="display:none;">
									<input type="hidden" name="template_method" value="<?php echo htmlspecialchars($template_method); ?>">
									<input type="hidden" name="template_id" value="<?php echo htmlspecialchars($template_id); ?>">
									<input type="hidden" name="template_name" id="hidden_template_name">
									<input type="hidden" name="table_data" id="hidden_table_data">
								</form>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<!-- HandsonTable JS -->
<script src="https://cdn.jsdelivr.net/npm/handsontable@12.4.0/dist/handsontable.full.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
	const templateMethod = '<?php echo $template_method; ?>';
	const columns = <?php echo json_encode($columns); ?>;

	// Create initial data with header row
	const initialData = [columns]; // First row is headers

	// Add a few empty rows for data entry
	for (let i = 0; i < 10; i++) {
		initialData.push(new Array(columns.length).fill(''));
	}

	const container = document.getElementById('hot-container');
	const saveSection = document.getElementById('saveSection');
	const templateNameSection = document.getElementById('templateNameSection');
	const templateNameInput = document.getElementById('template_name');
	const nameError = document.getElementById('nameError');
	const saveBtn = document.getElementById('saveBtn');

	let hasChanges = false;

	// Show template name field if this is a new template
	if (templateMethod === 'new') {
		templateNameSection.style.display = 'block';
	}

	// Initialize Handsontable
	const hot = new Handsontable(container, {
		data: initialData,
		colHeaders: true,
		rowHeaders: true,
		width: '100%',
		height: 500,
		colWidths: 150,
		licenseKey: 'non-commercial-and-evaluation',
		manualColumnMove: true,
		manualColumnResize: true,
		copyPaste: true,
		fillHandle: true,
		contextMenu: true,
		columns: columns.map(() => ({ type: 'text' })),
		cells: function(row, col) {
			const cellProperties = {};
			if (row === 0) {
				// First row is header - make it read-only
				cellProperties.readOnly = true;
				cellProperties.className = 'htCenter htMiddle htDimmed';
			}
			return cellProperties;
		},
		afterChange: function(changes, source) {
			if (source !== 'loadData' && changes) {
				showSaveSection();
			}
		},
		afterColumnMove: function(movedColumns, finalIndex) {
			showSaveSection();
		}
	});

	function showSaveSection() {
		if (!hasChanges) {
			hasChanges = true;
			saveSection.style.display = 'block';
		}
	}

	// Handle Save Button Click
	saveBtn.addEventListener('click', function() {
		// Validate template name if new template
		if (templateMethod === 'new') {
			const templateName = templateNameInput.value.trim();
			if (templateName === '') {
				nameError.style.display = 'inline';
				templateNameInput.focus();
				return;
			}
			document.getElementById('hidden_template_name').value = templateName;
		}

		// Get all table data (preserving column order)
		const tableData = hot.getData();

		// Store data as JSON
		document.getElementById('hidden_table_data').value = JSON.stringify(tableData);

		// Submit form
		document.getElementById('submitForm').submit();
	});

	// Clear name error when typing
	templateNameInput.addEventListener('input', function() {
		nameError.style.display = 'none';
	});
});
</script>

<?php
include("../includes/mfooter.php");
?>
