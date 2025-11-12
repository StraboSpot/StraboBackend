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
									<div class="row gtr-uniform gtr-25">
										<div class="col-12">
											<input type="radio" id="method_new" name="template_method" value="new">
											<label for="method_new">Create New Template</label>
										</div>

										<!-- New Template Section Selection (Hidden Initially) -->
										<div class="col-12" id="new_section" style="display:none; margin-left: 30px;">
											<h4>Spot Sections to Include</h4>
											<div class="row gtr-uniform gtr-25">
												<div class="col-12">
													<input type="checkbox" id="section_spot" name="selected_sections[]" value="spot" checked>
													<label for="section_spot">Spot Data</label>
												</div>
												<div class="col-12">
													<input type="checkbox" id="section_orientation" name="selected_sections[]" value="orientation">
													<label for="section_orientation">Orientation Data</label>
												</div>
												<div class="col-12">
													<input type="checkbox" id="section_rockunit" name="selected_sections[]" value="rockunit">
													<label for="section_rockunit">Rock Units</label>
												</div>
												<div class="col-12">
													<input type="checkbox" id="section_sample" name="selected_sections[]" value="sample">
													<label for="section_sample">Sample Data</label>
												</div>
											</div>
										</div>
									</div>

									<hr />

									<!-- Continue Button -->
									<div class="row gtr-uniform gtr-25">
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

<script src="js/choose_template.js"></script>

<?php
include("../includes/mfooter.php");
?>
