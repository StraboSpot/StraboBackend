<?php
/**
 * File: strabofield_landing.php
 * Description: StraboField Landing Page - Information and download page for StraboField mobile application
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
			<h2>StraboField</h2>
		</header>

		<section class="micro-section">
			<h2 style="color: #ffffff; font-size: 1.6em; font-weight: 300; margin-bottom: 0.5em;">From outcrop to insight.</h2>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.05em; line-height: 1.7; margin-bottom: 1.5em;">
				Go beyond digital mapping with data that stays organized, shareable, and scientifically meaningful.
			</p>
		</section>

		<!-- CTA Button Bar -->
		<div class="landing-hero-buttons">
			<a href="https://apps.apple.com/us/app/strabospot2/id1555903455" target="_blank" class="button primary small">App Store</a>
			<a href="https://play.google.com/store/apps/details?id=com.strabospot2&pcampaignid=web_share" target="_blank" class="button primary small">Google Play</a>
			<a href="/interoperability" class="button primary small">Interoperability</a>
			<a href="/whatisstrabospotoffline" class="button primary small">Offline</a>
			<a href="/help#field" class="button primary small">Help</a>
			<a href="/my_field_data" class="button primary small">My Field Data</a>
		</div>

		<!-- Annotated Screenshot -->
		<section class="micro-section">
			<div class="micro-screenshot-container">
				<img src="/includes/mimages/strabofield/landing/image2.webp" alt="StraboField Application Overview" class="micro-screenshot">
			</div>
		</section>

		<!-- StraboField Highlights -->
		<section id="tools" class="micro-section">
			<h2 class="landing-highlights-title">StraboField Highlights</h2>

			<!-- Feature Cards Grid (3x3) -->
			<div class="row gtr-uniform">

				<!-- Card 1: Work offline -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image7.webp" alt="Work offline">
						</div>
						<h3 style="font-weight: bold;">Work offline. Record everything. Never lose context.</h3>
						<p>Collect field observations as points, lines, and polygons with photos, notes, sketches, measurements, and orientations. Even in areas with no connectivity.</p>
					</div>
				</div>

				<!-- Card 2: Nest observations -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image1.webp" alt="Nest observations from outcrop to sample">
						</div>
						<h3 style="font-weight: bold;">Nest observations from outcrop to sample.</h3>
						<p>Use StraboField&rsquo;s hierarchical Spot system to capture geology across scales. Preserve spatial and conceptual relationships between observations, exactly how geologists think.</p>
					</div>
				</div>

				<!-- Card 3: Sync, share, and continue -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image10.webp" alt="Sync, share, and continue your research">
						</div>
						<h3 style="font-weight: bold;">Sync, share, and continue your research.</h3>
						<p>Sync your field data to StraboSpot to search, edit, share, and connect it with lab observations and microstructures in StraboMicro. Your fieldwork becomes part of a living research dataset.</p>
					</div>
				</div>

				<!-- Card 4: Structured data -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image6.webp" alt="Structured data designed for science">
						</div>
						<h3 style="font-weight: bold;">Structured data designed for science.</h3>
						<p>StraboField supports diverse geoscience workflows: structural geology, sedimentology, volcanology field work. All with controlled vocabularies, and FAIR-aligned data practices.</p>
					</div>
				</div>

				<!-- Card 5: Work with any basemap -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image5.webp" alt="Work with any basemap">
						</div>
						<h3 style="font-weight: bold;">Work with any basemap.</h3>
						<p>Use built-in or custom basemaps, downloadable for offline fieldwork.</p>
					</div>
				</div>

				<!-- Card 6: Georeferenced notes and sketches -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image8.webp" alt="Georeferenced notes and sketches">
						</div>
						<h3 style="font-weight: bold;">Georeferenced notes and sketches.</h3>
						<p>Capture photos, sketches, and notes tied to exact field locations.</p>
					</div>
				</div>

				<!-- Card 7: Tag and search -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image3.webp" alt="Tag and search your observations">
						</div>
						<h3 style="font-weight: bold;">Tag and search your observations.</h3>
						<p>Add conceptual tags to spots to filter and analyze data across your project.</p>
					</div>
				</div>

				<!-- Card 8: Measure, log, and interpret -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image11.webp" alt="Measure, log, and interpret in the field">
						</div>
						<h3 style="font-weight: bold;">Measure, log, and interpret in the field.</h3>
						<p>Take field measurements, capture observations, and construct stratigraphic columns in context.</p>
					</div>
				</div>

				<!-- Card 9: Export & share -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabofield/landing/image9.webp" alt="Export and share your data your way">
						</div>
						<h3 style="font-weight: bold;">Export &amp; share your data your way.</h3>
						<p>Export to common formats for GIS, analysis, and publication.</p>
					</div>
				</div>

			</div>
		</section>

		<!-- Why StraboField / Built For Section -->
		<section class="landing-why-section">
			<div class="row gtr-uniform">
				<div class="col-6 col-12-medium">
					<div class="exp-info-box">
						<h3 class="exp-box-title">Why StraboField?</h3>
						<ul class="exp-benefits-list">
							<li>Free and open-source</li>
							<li>NSF Supported</li>
							<li>FAIR Data principles</li>
							<li>Built by geologists for geologists</li>
							<li>Community designed</li>
							<li>Your data, your terms. Keep data private, share with collaborators, or publish openly</li>
						</ul>
					</div>
				</div>
				<div class="col-6 col-12-medium">
					<div class="exp-info-box">
						<h3 class="exp-box-title">Built for:</h3>
						<ul class="exp-benefits-list">
							<li>Researchers</li>
							<li>Educators</li>
							<li>Students</li>
							<li>Field Geologists with an interest in structural geology, tectonics, volcanology, petrology, sedimentology, geomorphology</li>
						</ul>
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
