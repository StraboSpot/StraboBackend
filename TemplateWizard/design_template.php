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

// Build column list and template name based on method
$columns = array();
$template_name = '';

if ($template_method === 'existing') {
	// Placeholder columns for existing template testing
	$columns = array('Name', 'Date', 'Strike', 'Dip', 'Notes');

	// Get template name based on ID (placeholder for now)
	$template_names = array(
		'1' => 'Field Survey Template',
		'2' => 'Rock Sample Collection',
		'3' => 'Quick Entry Template'
	);
	$template_name = isset($template_names[$template_id]) ? $template_names[$template_id] : 'Unknown Template';
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
<link rel="stylesheet" href="css/template_designer.css">

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Template Design</h2>
						</header>

						<!-- Content -->
							<section id="content">

								<!-- Instructions -->
								<div style="background-color: #3b4252; color: #eceff4; padding: 15px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #5e81ac;">
									<strong>Instructions:</strong>
									<ul style="margin: 10px 0 0 0; padding-left: 20px;">
										<li><strong>Reorder columns:</strong> Click a column header to select it, then drag the handle that appears to move it</li>
										<li><strong>Resize columns:</strong> Drag the edge of any column header</li>
										<li><strong>Enter data:</strong> Click any cell to edit, or paste from Excel/CSV</li>
									</ul>
								</div>

								<!-- Template Name and Save Button -->
								<div class="row" style="margin-bottom: 20px;">
									<div class="col-2 col-12-small gtr-25">
										<div>Template Name <span class="highlighted">*</span></div>
									</div>
									<div class="col-7 col-12-small gtr-25">
										<input type="text" id="template_name" name="template_name" placeholder="Enter template name" value="<?php echo htmlspecialchars($template_name); ?>" />
									</div>
									<div class="col-3 col-12-small gtr-25" id="saveSection" style="display: none;">
										
										<!--<button id="saveBtn" class="button primary fit"><?php echo ($template_method === 'existing') ? 'Save Changes' : 'Save'; ?></button>-->
										
										<ul class="actions fit" style="margin-bottom: 0px;">
											<li><a id="saveBtn" class="button primary fit"><?php echo ($template_method === 'existing') ? 'Save Changes' : 'Save'; ?></a></li>
										</ul>
									</div>
								</div>

								<!-- Project to Save -->
								<div id="project_info" class="row" style="margin-bottom: 20px;display: none;">
									<div class="col-2 col-12-small gtr-25">
										<div>Strabo Project <span class="highlighted">*</span></div>
									</div>
									<div class="col-7 col-12-small gtr-25">
										<select name="project_id" id="project_id">
											<option value="">Please Select Project...</option>
											<option value="12345678">My Test Project 1</option>
											<option value="23456789">My Test Project 2</option>
											<option value="34567890">My Test Project 3</option>
										</select>
									</div>
								</div>

								<!-- Error Modal -->
								<div id="errorModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); align-items: center; justify-content: center;">
									<div style="background-color: #2e3440; padding: 30px; border-radius: 5px; width: 90%; max-width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); position: relative;">
										<h3 style="margin-top: 0; color: #bf616a;">Error</h3>
										<p id="errorMessage" style="margin-bottom: 20px; color: #eceff4;">Template name is required</p>
										<button id="closeModal" class="button primary" style="width: 100%;">OK</button>
									</div>
								</div>

								<!-- HandsonTable Container -->
								<div id="hot-container"></div>

								<!-- Hidden form for POST submission -->
								<form id="submitForm" method="POST" action="save_template.php" style="display:none;">
									<input type="hidden" name="template_method" value="<?php echo htmlspecialchars($template_method); ?>">
									<input type="hidden" name="template_id" value="<?php echo htmlspecialchars($template_id); ?>">
									<input type="hidden" name="template_name" id="hidden_template_name">
									<input type="hidden" name="project_id" id="hidden_project_id">
									<input type="hidden" name="table_data" id="hidden_table_data">
								</form>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<!-- HandsonTable JS -->
<script src="https://cdn.jsdelivr.net/npm/handsontable@12.4.0/dist/handsontable.full.min.js"></script>

<script>
// Pass PHP data to JavaScript
window.templateMethod = '<?php echo $template_method; ?>';
window.templateColumns = <?php echo json_encode($columns); ?>;
window.templateId = '<?php echo $template_id; ?>';
</script>
<script src="js/design_template.js"></script>

<?php
include("../includes/mfooter.php");
?>
