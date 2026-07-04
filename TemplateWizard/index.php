<?php
/**
 * File: index.php
 * Description: Template Wizard - Choose or Build Template (Page 1)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir(dirname(__DIR__));
include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/services/FieldTabularService.php";

$twsvc = new FieldTabularService($db, $neodb, $strabo);
$twsvc->setUserpkey($userpkey);
$twTemplates = $twsvc->listTemplates();

include("includes/mheader.php");
?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>StraboSpot Template Wizard</h2>
						</header>

						<!-- Content -->
							<section id="content">

<?php
$twFlash = '';
if (isset($_GET['saved']) && $_GET['saved'] !== '') {
    $twFlash = 'Template &ldquo;' . htmlspecialchars($_GET['saved']) . '&rdquo; saved.';
} elseif (isset($_GET['deleted']) && $_GET['deleted'] !== '') {
    $twFlash = 'Template &ldquo;' . htmlspecialchars($_GET['deleted']) . '&rdquo; deleted.';
}
if ($twFlash !== ''): ?>
								<!-- Flash confirmation (redirect from save/delete): solid green, fades out -->
								<div id="twFlashBanner" style="background-color: #a3be8c; color: #2e3440; padding: 12px; margin-bottom: 20px; border-radius: 5px; font-weight: bold;">
									<?php echo $twFlash; ?>
								</div>
								<script>
									setTimeout(function() { $('#twFlashBanner').fadeOut(1000); }, 2500);
									if (window.history && window.history.replaceState) {
										history.replaceState(null, '', window.location.pathname);
									}
								</script>
<?php endif; ?>

								<!-- Instructions -->
								<div style="background-color: #3b4252; color: #eceff4; padding: 10px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #5e81ac;">
									<strong>About the StraboSpot Template Wizard:</strong>
									<div style="margin: 10px 0 0 0; padding-left: 20px;">
										The StraboSpot Template Wizard allows for the creation of custom, reusable spreadsheet templates suited for importing data into the StraboField
										database. This interface provides options for the creation of new templates, as well as the use and editing of existing templates. Saved
										templates work in both directions: they define the spreadsheet you upload <em>and</em> the shape of dataset exports. Please
										use the menu below to get started.
									</div>
								</div>

<?php if (count($twTemplates)): ?>
								<!-- My Templates: manage saved designs -->
								<style>
									/* Theme tables have no phone layout (only .table-wrapper h-scroll,
									   which just looks clipped) — collapse rows to stacked cards at the
									   theme's small breakpoint. !important beats the inline desktop
									   nowrap on the date/actions cells. */
									@media screen and (max-width: 736px) {
										table.tw-tpl-table thead { display: none; }
										table.tw-tpl-table,
										table.tw-tpl-table tbody,
										table.tw-tpl-table tbody tr,
										table.tw-tpl-table td { display: block; width: 100%; }
										table.tw-tpl-table { margin-bottom: 1em; }
										table.tw-tpl-table tbody tr {
											padding: 1em;
											margin-bottom: 1em;
											border: solid 1px rgba(255, 255, 255, 0.3);
											border-radius: 6px;
											background-color: rgba(255, 255, 255, 0.075);
										}
										table.tw-tpl-table td { padding: 0.15em 0; white-space: normal !important; }
										table.tw-tpl-table td:first-child { font-weight: bold; color: #ffffff; font-size: 1.1em; }
										table.tw-tpl-table td:nth-child(2) { color: rgba(255, 255, 255, 0.6); }
										/* Three equal-width buttons on one row; the theme's 2.25em button
										   side padding would clip the labels at a third of a phone width. */
										table.tw-tpl-table td .actions { display: flex; margin: 0.75em 0 0 0; }
										table.tw-tpl-table td .actions li { flex: 1 1 0; padding: 0 0 0 0.6em; }
										table.tw-tpl-table td .actions li:first-child { padding-left: 0; }
										table.tw-tpl-table td .actions li .button {
											width: 100%;
											padding: 0 0.25em;
											text-align: center;
										}
									}
								</style>
								<div class="row gtr-uniform gtr-25">
									<div class="col-12">
										<h3>My Templates</h3>
										<div class="table-wrapper">
											<table class="tw-tpl-table">
												<thead>
													<tr><th>Name</th><th>Last Modified</th><th></th></tr>
												</thead>
												<tbody>
													<?php foreach ($twTemplates as $t): ?>
													<tr>
														<td><?php echo htmlspecialchars($t->name); ?></td>
														<td style="white-space: nowrap;"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($t->modified_at))); ?></td>
														<td style="white-space: nowrap;">
															<!-- .fixed keeps the buttons in a row on phones; the theme stacks
															     non-fixed action lists full-width under 480px. On small screens
															     the row itself becomes a stacked card (style block above). -->
															<ul class="actions fixed" style="margin-bottom: 0;">
																<li><a href="export.php?what=template&amp;template_id=<?php echo (int)$t->pkey; ?>&amp;format=xlsx" class="button small" title="Download a blank, fillable spreadsheet for this template">Download</a></li>
																<li><a href="design_template.php?template_id=<?php echo (int)$t->pkey; ?>" class="button small">Edit</a></li>
																<li><a href="#" class="button small tw-delete-template" data-pkey="<?php echo (int)$t->pkey; ?>" data-name="<?php echo htmlspecialchars($t->name); ?>">Delete</a></li>
															</ul>
														</td>
													</tr>
													<?php endforeach; ?>
												</tbody>
											</table>
										</div>
									</div>
								</div>

								<hr />
<?php endif; ?>

								<form id="templateForm" method="POST" action="design_template.php">

									<!-- Option 1: Choose Existing Template -->
									<div class="row gtr-uniform">
										<div class="col-12">
											<h3>Please Choose:</h3>
										</div>

										<div class="col-12">
											<input type="radio" id="method_existing" name="template_method" value="existing" <?php echo count($twTemplates) ? '' : 'disabled'; ?>>
											<label for="method_existing">Choose Existing Template</label>
										</div>

										<!-- Existing Template Selection (Hidden Initially) -->
										<div class="col-12" id="existing_section" style="display:none; margin-left: 30px;">
											<label for="template_select">My Templates</label>
											<select name="template_id" id="template_select" disabled>
												<option value="">-- Select a Template --</option>
												<?php foreach ($twTemplates as $t): ?>
												<option value="<?php echo (int)$t->pkey; ?>"><?php echo htmlspecialchars($t->name); ?></option>
												<?php endforeach; ?>
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
											<h4 style="margin-bottom: 10px;">Spot Sections to Include</h4>
											<div class="row gtr-uniform gtr-25">
												<div class="col-12">
													<input type="checkbox" id="section_spot" name="selected_sections[]" value="spot" checked>
													<label for="section_spot">Spot Data (required)</label>
												</div>
												<div class="col-12">
													<input type="checkbox" id="section_orientation" name="selected_sections[]" value="orientation" checked>
													<label for="section_orientation">Orientation Data</label>
												</div>
												<div class="col-12">
													<input type="checkbox" id="section_geologic_unit" name="selected_sections[]" value="geologic_unit">
													<label for="section_geologic_unit">Rock Units (Geologic Unit)</label>
												</div>
												<div class="col-12">
													<input type="checkbox" id="section_trace" name="selected_sections[]" value="trace">
													<label for="section_trace">Trace</label>
												</div>
												<div class="col-12">
													<input type="checkbox" id="section_other_features" name="selected_sections[]" value="other_features">
													<label for="section_other_features">Other Features</label>
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

								<hr />

								<!-- Direct doors: file import / dataset export -->
								<div class="row gtr-uniform gtr-25">
									<div class="col-12">
										<h3>Shortcuts</h3>
										<ul class="actions">
											<li><a href="review.php" class="button">&#8682; Import a Spreadsheet</a></li>
											<li><a href="export.php" class="button"><span style="display:inline-block;transform:rotate(180deg);">&#8682;</span> Export a Dataset</a></li>
										</ul>
										<p style="font-size: 0.9em;">Importing a file exported by StraboSpot? Its template travels inside the file &mdash; just upload it.</p>
									</div>
								</div>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<script src="js/choose_template.js?v=<?php echo filemtime(__DIR__ . '/js/choose_template.js'); ?>"></script>

<?php
include("includes/mfooter.php");
?>
