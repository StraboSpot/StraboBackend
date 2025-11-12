<?php
/**
 * File: save_template.php
 * Description: Template Wizard - Save and Debug Output (Page 3)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("../db.php");
include("../includes/mheader.php");
?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Template Saved - Debug Output</h2>
						</header>

						<!-- Content -->
							<section id="content">

								<h3>POST Data Received:</h3>

								<div style="background-color: #2e3440; color: #eceff4; padding: 20px; border-radius: 5px; overflow-x: auto;">
									<pre><?php
// Display all POST data for debugging
$db->dumpVar($_POST);
?></pre>
								</div>

								<hr />

								<h3>Parsed Table Data:</h3>

								<?php
								// Parse and display table data in a readable format
								if (isset($_POST['table_data'])) {
									$tableData = json_decode($_POST['table_data'], true);

									if ($tableData && is_array($tableData)) {
										echo "<p><strong>Number of rows:</strong> " . count($tableData) . "</p>";

										// Display as HTML table
										echo '<div style="overflow-x: auto;">';
										echo '<table style="width: 100%; border-collapse: collapse;">';

										foreach ($tableData as $rowIndex => $row) {
											echo '<tr>';
											$isHeader = ($rowIndex === 0);

											foreach ($row as $cell) {
												$tag = $isHeader ? 'th' : 'td';
												$style = $isHeader
													? 'background-color: #3b4252; color: #eceff4; padding: 10px; border: 1px solid #4c566a; font-weight: bold;'
													: 'background-color: #2e3440; color: #eceff4; padding: 8px; border: 1px solid #4c566a;';

												echo "<{$tag} style=\"{$style}\">" . htmlspecialchars($cell) . "</{$tag}>";
											}

											echo '</tr>';
										}

										echo '</table>';
										echo '</div>';
									} else {
										echo '<p style="color: #bf616a;">Error: Could not parse table data</p>';
									}
								} else {
									echo '<p style="color: #bf616a;">No table data received</p>';
								}
								?>

								<hr />

								<h3>Template Information:</h3>
								<ul>
									<li><strong>Method:</strong> <?php echo isset($_POST['template_method']) ? htmlspecialchars($_POST['template_method']) : 'N/A'; ?></li>
									<?php if (isset($_POST['template_id']) && $_POST['template_id'] !== ''): ?>
										<li><strong>Template ID:</strong> <?php echo htmlspecialchars($_POST['template_id']); ?></li>
									<?php endif; ?>
									<?php if (isset($_POST['template_name']) && $_POST['template_name'] !== ''): ?>
										<li><strong>Template Name:</strong> <?php echo htmlspecialchars($_POST['template_name']); ?></li>
									<?php endif; ?>
								</ul>

								<hr />

								<!-- Navigation -->
								<div class="row gtr-uniform">
									<div class="col-12">
										<ul class="actions">
											<li><a href="./" class="button">Start Over</a></li>
										</ul>
									</div>
								</div>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("../includes/mfooter.php");
?>
