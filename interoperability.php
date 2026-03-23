<?php
/**
 * File: interoperability.php
 * Description: StraboSpot Interoperability - Information about data exchange and integration tools
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("includes/mheader.php");
?>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>StraboSpot Interoperability</h2>
		</header>

		<!-- Intro Section -->
		<section class="micro-section">
			<h2 class="exp-section-title">Interoperability</h2>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.05em; line-height: 1.7; margin-bottom: 2em;">
				StraboSpot supports open data exchange through APIs, structured exports, and integration
				pathways that allow researchers to connect with analytical tools, external databases, and
				custom-built applications. Interoperability is central to making geologic data reusable and
				extensible.
			</p>
		</section>

		<!-- Three-Column Integration Cards -->
		<section class="micro-section">
			<div class="row gtr-uniform">

				<!-- Stereonet -->
				<div class="col-4 col-12-medium">
					<div class="exp-info-box" style="height: 100%;">
						<h3 class="exp-box-title">Stereonet by Rick Allmendinger</h3>
						<p style="color: rgba(255, 255, 255, 0.85); line-height: 1.6; margin-bottom: 1em;">
							In the field, users can lasso spots with measurements and paste the data directly into the
							Stereonet mobile application for rapid visualization of bedding orientations, core data, and
							other structural measurements.
						</p>
						<p><a href="https://www.rickallmendinger.net/stereonet" target="_blank">https://www.rickallmendinger.net/stereonet</a></p>
					</div>
				</div>

				<!-- LAPS Integration -->
				<div class="col-4 col-12-medium">
					<div class="exp-info-box" style="height: 100%;">
						<h3 class="exp-box-title">LAPS Integration</h3>
						<p style="color: rgba(255, 255, 255, 0.85); line-height: 1.6; margin-bottom: 1em;">
							StraboExperimental integrates with LAPS tools to support experimental geophysical data
							workflows by enabling offline preparation and management of rock deformation experiment data
							and metadata. Using the JupyterLab environment and Python, LAPS provides a simple workflow
							for organizing and annotating experimental datasets locally so they can be seamlessly
							published into the StraboExperimental database, ensuring complete and interoperable
							experimental records.
						</p>
						<p><a href="http://verve.mit.edu/laps/" target="_blank">http://verve.mit.edu/laps/</a></p>
					</div>
				</div>

				<!-- User Built Tools -->
				<div class="col-4 col-12-medium">
					<div class="exp-info-box" style="height: 100%;">
						<h3 class="exp-box-title">User Built Tools</h3>
						<p style="color: rgba(255, 255, 255, 0.85); line-height: 1.6; margin-bottom: 1em;">
							Translation Tool from StraboField to GeMS used by the USGS, Andrew Hoxey<br>
							<a href="https://github.com/andrewkrh/StraboGeMSTranslator" target="_blank">More Info</a>
						</p>
						<p style="color: rgba(255, 255, 255, 0.85); line-height: 1.6; margin-bottom: 1em;">
							StraboField Data Project compiling and sorting tool, J Schneider<br>
							<a href="#">Link</a>
						</p>
						<p style="color: rgba(255, 255, 255, 0.85);"><em>Others?</em></p>
					</div>
				</div>

			</div>
		</section>

		<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include("includes/mfooter.php");
?>
