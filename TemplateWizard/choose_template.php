<?php
/**
 * File: choose_template.php
 * Description: Template Wizard - Choose or Build Template (Page 1)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("../includes/mheader.php");
?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Build or Choose Template</h2>
						</header>

						<!-- Content -->
							<section id="content">

								<form id="templateForm" method="POST" action="design_template.php">

									<!-- Option 1: Choose Existing Template -->
									<div class="row gtr-uniform">
										<div class="col-12">
											<h3>Choose Your Method</h3>
										</div>

										<div class="col-12">
											<input type="radio" id="method_existing" name="template_method" value="existing">
											<label for="method_existing">Choose Existing Template</label>
										</div>

										<!-- Existing Template Selection (Hidden Initially) -->
										<div class="col-12" id="existing_section" style="display:none; margin-left: 30px;">
											<label for="template_select">My Templates</label>
											<select name="template_id" id="template_select" disabled>
												<option value="">-- Select a Template --</option>
												<option value="1">Field Survey Template</option>
												<option value="2">Rock Sample Collection</option>
												<option value="3">Quick Entry Template</option>
											</select>
										</div>
									</div>

									<hr />

									<!-- Option 2: Create New Template -->
									<div class="row gtr-uniform">
										<div class="col-12">
											<input type="radio" id="method_new" name="template_method" value="new">
											<label for="method_new">Create New Template</label>
										</div>

										<!-- New Template Section Selection (Hidden Initially) -->
										<div class="col-12" id="new_section" style="display:none; margin-left: 30px;">
											<h4>Spot Sections to Include</h4>
											<div class="row gtr-uniform">
												<div class="col-6 col-12-xsmall">
													<input type="checkbox" id="section_spot" name="selected_sections[]" value="spot" checked>
													<label for="section_spot">Spot Data</label>
												</div>
												<div class="col-6 col-12-xsmall">
													<input type="checkbox" id="section_orientation" name="selected_sections[]" value="orientation">
													<label for="section_orientation">Orientation Data</label>
												</div>
												<div class="col-6 col-12-xsmall">
													<input type="checkbox" id="section_rockunit" name="selected_sections[]" value="rockunit">
													<label for="section_rockunit">Rock Units</label>
												</div>
												<div class="col-6 col-12-xsmall">
													<input type="checkbox" id="section_sample" name="selected_sections[]" value="sample">
													<label for="section_sample">Sample Data</label>
												</div>
											</div>
										</div>
									</div>

									<hr />

									<!-- Continue Button -->
									<div class="row gtr-uniform">
										<div class="col-12">
											<ul class="actions">
												<li><input type="submit" id="continueBtn" value="Continue" class="primary" disabled /></li>
											</ul>
										</div>
									</div>

								</form>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	const methodExisting = document.getElementById('method_existing');
	const methodNew = document.getElementById('method_new');
	const existingSection = document.getElementById('existing_section');
	const newSection = document.getElementById('new_section');
	const templateSelect = document.getElementById('template_select');
	const continueBtn = document.getElementById('continueBtn');

	// Handle Existing Template Radio Selection
	methodExisting.addEventListener('change', function() {
		if (this.checked) {
			existingSection.style.display = 'block';
			newSection.style.display = 'none';
			templateSelect.disabled = false;

			// Disable new template checkboxes
			document.querySelectorAll('#new_section input[type="checkbox"]:not(#section_spot)').forEach(cb => {
				cb.checked = false;
			});

			// Continue button enabled only if template selected
			continueBtn.disabled = (templateSelect.value === '');
		}
	});

	// Handle New Template Radio Selection
	methodNew.addEventListener('change', function() {
		if (this.checked) {
			newSection.style.display = 'block';
			existingSection.style.display = 'none';
			templateSelect.disabled = true;
			templateSelect.value = '';

			// Enable continue button (Spot Data always checked)
			continueBtn.disabled = false;
		}
	});

	// Handle Template Selection Change
	templateSelect.addEventListener('change', function() {
		continueBtn.disabled = (this.value === '');
	});

	// Prevent unchecking Spot Data (always required)
	const spotCheckbox = document.getElementById('section_spot');
	spotCheckbox.addEventListener('click', function(e) {
		if (!this.checked) {
			e.preventDefault();
			this.checked = true;
		}
	});
});
</script>

<?php
include("../includes/mfooter.php");
?>
