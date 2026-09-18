<?php
/**
 * File: index_new.php
 * Description: Template Wizard - landing page (task-first mockup, 2026-09-18).
 *              Researchers arrive to move their own data in and out of
 *              spreadsheets, so the three jobs lead (Export, Import, Design)
 *              and the saved-template list supports them. Replaces the
 *              "Please Choose" wizard step: Edit on a saved template IS the
 *              "choose existing" path, and the sections picker for a new
 *              template opens inline under its card.
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

<style>
	/* Landing cards: the theme's .box with equal heights and the button
	   pinned to the bottom so the three doors line up. */
	.tw-doors { align-items: stretch; }
	.tw-doors > div { display: flex; }
	.tw-door {
		display: flex;
		flex-direction: column;
		width: 100%;
		margin-bottom: 0;
		padding: 1.25em 1.25em 1.1em 1.25em;
		background-color: rgba(255, 255, 255, 0.05);
	}
	.tw-door h3 { margin-bottom: 0.4em; }
	.tw-door p { flex: 1 1 auto; margin-bottom: 1em; font-size: 0.95em; }
	.tw-door .button { display: block; width: 100%; padding: 0 0.5em; text-align: center; margin: 0; }
	.tw-intro { margin-bottom: 1.5em; }
	.tw-intro p { margin-bottom: 0.5em; }
	/* Inline sections picker under the Design card. */
	#tw-new-panel { margin-top: 1.5em; }
	#tw-new-panel .box { background-color: rgba(255, 255, 255, 0.05); margin-bottom: 0; }
	#tw-new-panel h4 { margin-bottom: 0.75em; }
	#tw-new-panel .col-12 { padding-top: 0.25em; padding-bottom: 0.25em; }
	.tw-hint { font-size: 0.9em; color: rgba(255, 255, 255, 0.6); }
	.tw-templates { margin-top: 2.5em; }
	.tw-templates h3 { margin-bottom: 0.25em; }
	.tw-templates .tw-hint { margin-bottom: 1em; }
	/* Theme tables have no phone layout: collapse rows to stacked cards at
	   the theme's small breakpoint (carried over from the current page). */
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
		table.tw-tpl-table td .actions { display: flex; margin: 0.75em 0 0 0; }
		table.tw-tpl-table td .actions li { flex: 1 1 0; padding: 0 0 0 0.6em; }
		table.tw-tpl-table td .actions li:first-child { padding-left: 0; }
		table.tw-tpl-table td .actions li .button { width: 100%; padding: 0 0.25em; text-align: center; }
		.tw-doors > div { margin-bottom: 1em; }
	}
</style>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>StraboSpot Template Wizard</h2>
							<p>Move StraboField data in and out of spreadsheets.</p>
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

								<div class="tw-intro">
									<p>
										Export a dataset to a spreadsheet, edit it in Excel or Sheets, and import it back to add or update
										spots. A <strong>template</strong> decides which columns the spreadsheet carries. Every file StraboSpot
										exports includes its template, so you only design one when you want a custom layout.
										<a href="howto.php">How multiple measurements per spot are laid out</a>.
									</p>
								</div>

								<!-- The three jobs -->
								<div class="row gtr-uniform gtr-25 tw-doors">
									<div class="col-4 col-12-medium">
										<div class="box tw-door">
											<h3>Export a dataset</h3>
											<p>Download any of your datasets as a spreadsheet. Use the default layout or one of your templates.</p>
											<a href="export.php" class="button primary">Export&hellip;</a>
										</div>
									</div>
									<div class="col-4 col-12-medium">
										<div class="box tw-door">
											<h3>Import a spreadsheet</h3>
											<p>Upload a file you exported and edited, or a filled-in blank template. You review every change before it is saved.</p>
											<a href="review.php" class="button primary">Import&hellip;</a>
										</div>
									</div>
									<div class="col-4 col-12-medium">
										<div class="box tw-door">
											<h3>Design a template</h3>
											<p>Choose the columns your spreadsheets carry. Start from the sections you need, then add, remove or reorder columns.</p>
											<a href="#tw-new-panel" id="tw-new-toggle" class="button" aria-expanded="false" aria-controls="tw-new-panel">New template&hellip;</a>
										</div>
									</div>
								</div>

								<!-- Sections picker for a new template (hidden until the card is used) -->
								<form id="tw-new-panel" method="POST" action="design_template.php" style="display:none;">
									<input type="hidden" name="template_method" value="new">
									<div class="box">
										<h4>Start the new template with these sections</h4>
										<div class="row gtr-uniform gtr-25">
											<div class="col-4 col-6-medium col-12-small">
												<input type="checkbox" id="section_spot" name="selected_sections[]" value="spot" checked>
												<label for="section_spot">Spot data (always included)</label>
											</div>
											<div class="col-4 col-6-medium col-12-small">
												<input type="checkbox" id="section_orientation" name="selected_sections[]" value="orientation" checked>
												<label for="section_orientation">Orientations</label>
											</div>
											<div class="col-4 col-6-medium col-12-small">
												<input type="checkbox" id="section_geologic_unit" name="selected_sections[]" value="geologic_unit">
												<label for="section_geologic_unit">Rock units</label>
											</div>
											<div class="col-4 col-6-medium col-12-small">
												<input type="checkbox" id="section_trace" name="selected_sections[]" value="trace">
												<label for="section_trace">Traces</label>
											</div>
											<div class="col-4 col-6-medium col-12-small">
												<input type="checkbox" id="section_other_features" name="selected_sections[]" value="other_features">
												<label for="section_other_features">Other features</label>
											</div>
											<div class="col-4 col-6-medium col-12-small">
												<input type="checkbox" id="section_sample" name="selected_sections[]" value="sample">
												<label for="section_sample">Samples</label>
											</div>
										</div>
										<ul class="actions" style="margin-top: 1.25em;">
											<li><input type="submit" value="Open the designer" class="primary" /></li>
											<li><a href="#" id="tw-new-cancel" class="button">Cancel</a></li>
										</ul>
										<p class="tw-hint" style="margin: 0.75em 0 0 0;">You can change columns in the designer; this only picks the starting set.</p>
									</div>
								</form>

								<!-- Saved templates -->
								<div class="tw-templates">
									<h3>My templates</h3>
<?php if (count($twTemplates)): ?>
									<p class="tw-hint">Download gives a blank spreadsheet to fill in by hand. Files you export already carry their template.</p>
									<div class="table-wrapper">
										<table class="tw-tpl-table">
											<thead>
												<tr><th>Name</th><th>Last modified</th><th></th></tr>
											</thead>
											<tbody>
												<?php foreach ($twTemplates as $t): ?>
												<tr>
													<td><?php echo htmlspecialchars($t->name); ?></td>
													<td style="white-space: nowrap;"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($t->modified_at))); ?></td>
													<td style="white-space: nowrap;">
														<ul class="actions fixed" style="margin-bottom: 0;">
															<li><a href="export.php?what=template&amp;template_id=<?php echo (int)$t->pkey; ?>&amp;format=xlsx" class="button small" title="Download a blank, fillable spreadsheet for this template">Download blank</a></li>
															<li><a href="design_template.php?template_id=<?php echo (int)$t->pkey; ?>" class="button small">Edit</a></li>
															<li><a href="#" class="button small tw-delete-template" data-pkey="<?php echo (int)$t->pkey; ?>" data-name="<?php echo htmlspecialchars($t->name); ?>">Delete</a></li>
														</ul>
													</td>
												</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
<?php else: ?>
									<p class="tw-hint">No saved templates yet. You only need one for a custom layout or a blank spreadsheet to fill in by hand; exported files carry their own.</p>
<?php endif; ?>
								</div>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<script src="js/landing.js?v=<?php echo filemtime(__DIR__ . '/js/landing.js'); ?>"></script>

<?php
include("includes/mfooter.php");
?>
