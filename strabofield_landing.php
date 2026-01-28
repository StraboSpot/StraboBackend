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

		<!-- Hero Section -->
		<section id="field-hero" class="field-landing-hero">
			<div class="field-hero-background" style="background-image: url('/includes/mimages/strabofield/header_image.jpg');">
				<div class="field-hero-overlay">
					<div class="field-hero-content">
						<div class="field-hero-logo">
							<img src="/files/strabospot_pub_logo.png" alt="StraboSpot Logo">
						</div>
						<div class="field-hero-text">
							<span class="field-hero-title">StraboField</span>
							<h2 class="field-hero-tagline">From outcrop to insight.</h2>
							<p class="field-hero-description">
								Go beyond digital mapping with data that stays organized, shareable, and scientifically meaningful.
							</p>
						</div>
						<div class="field-hero-buttons">
							<a href="https://apps.apple.com/us/app/strabospot2/id1555903455" target="_blank" class="field-store-btn field-apple-btn">
								<span class="field-store-icon">&#63743;</span>
								<span class="field-store-text">
									<span class="field-store-small">Download on the</span>
									<span class="field-store-large">App Store</span>
								</span>
							</a>
							<a href="https://play.google.com/store/apps/details?id=gov.kansas.ku.cgerg.strabospot2" target="_blank" class="field-store-btn field-google-btn">
								<span class="field-store-icon">&#9658;</span>
								<span class="field-store-text">
									<span class="field-store-small">Get it on</span>
									<span class="field-store-large">Google Play</span>
								</span>
							</a>
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- Section 1: Capture Real Geology -->
		<section id="capture" class="field-section">
			<div class="row gtr-uniform field-content-row">
				<div class="col-6 col-12-medium">
					<div class="field-section-text">
						<h2 class="field-section-title">Capture real geology, anywhere.</h2>
						<h3 class="field-section-subtitle">Work offline. Record everything. Never lose context.</h3>
						<p>
							Collect field observations as points, lines, and polygons with photos, notes, sketches,
							measurements, and orientations. Even in areas with no connectivity.
						</p>
						<p>
							Field conditions are unpredictable. StraboField is built for real geology, not just digital mapping.
						</p>
					</div>
				</div>
				<div class="col-6 col-12-medium">
					<div class="field-section-image">
						<div class="field-image-label">USE BUILT IN BASEMAPS</div>
						<img src="/includes/mimages/strabofield/iPad_Image Basemap.png" alt="StraboField Basemaps">
					</div>
				</div>
			</div>
		</section>

		<!-- Section 2: Multi-scale Observations -->
		<section id="multiscale" class="field-section">
			<div class="row gtr-uniform field-content-row field-row-reverse">
				<div class="col-6 col-12-medium field-order-2">
					<div class="field-section-text">
						<h2 class="field-section-title">Multi-scale observations that stay connected.</h2>
						<h3 class="field-section-subtitle">Nest observations from outcrop to sample.</h3>
						<p>
							Use StraboField's hierarchical Spot system to capture geology across scales.
							Preserve spatial and conceptual relationships between observations, exactly how geologists think.
						</p>
						<p>
							Most tools flatten your data. StraboField keeps relationships intact for deeper interpretation.
						</p>
					</div>
				</div>
				<div class="col-6 col-12-medium field-order-1">
					<div class="field-section-image">
						<div class="field-image-label">NEST OBSERVATIONS</div>
						<img src="/includes/mimages/strabofield/iPad_Nesting.png" alt="StraboField Nesting">
					</div>
				</div>
			</div>
		</section>

		<!-- Section 3: Field to Lab -->
		<section id="fieldtolab" class="field-section">
			<div class="row gtr-uniform field-content-row">
				<div class="col-6 col-12-medium">
					<div class="field-section-text">
						<h2 class="field-section-title">One workflow from field to lab.</h2>
						<h3 class="field-section-subtitle">Sync, share, and continue your research.</h3>
						<p>
							Sync your field data to StraboSpot to search, edit, share, and connect it with lab observations
							and microstructures in StraboMicro. Your fieldwork becomes part of a living research dataset.
						</p>
						<p>
							Research doesn't stop at the outcrop. StraboField connects fieldwork to the full scientific workflow.
						</p>
					</div>
				</div>
				<div class="col-6 col-12-medium">
					<div class="field-section-image">
						<div class="field-image-label">FIELD TO LAB</div>
						<img src="/includes/mimages/strabofield/iPad_Field-Micro.png" alt="StraboField to StraboMicro workflow">
					</div>
				</div>
			</div>
		</section>

		<!-- Section 4: Built for Research -->
		<section id="research" class="field-section">
			<div class="row gtr-uniform field-content-row field-row-reverse">
				<div class="col-6 col-12-medium field-order-2">
					<div class="field-section-text">
						<h2 class="field-section-title">Built for research, teaching, and reuse.</h2>
						<h3 class="field-section-subtitle">Structured data designed for science.</h3>
						<p>
							StraboField supports diverse geoscience workflows: structural geology, sedimentology,
							volcanology field work. All with controlled vocabularies, and FAIR-aligned data practices.
						</p>
						<p>
							Your data stays understandable, shareable, and reusable long after the field season ends.
						</p>
					</div>
				</div>
				<div class="col-6 col-12-medium field-order-1">
					<div class="field-section-image">
						<div class="field-image-label">CUSTOMIZE DATA PAGES</div>
						<img src="/includes/mimages/strabofield/iPad_More Pages.png" alt="StraboField Data Pages">
					</div>
				</div>
			</div>
		</section>

		<!-- Feature Cards Grid -->
		<section id="features" class="field-section">
			<div class="row gtr-uniform">
				<!-- Card 1: Work with any basemap -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="field-feature-card">
						<h3>Work with any basemap.</h3>
						<p>Use built-in or custom basemaps, downloadable for offline fieldwork.</p>
					</div>
				</div>

				<!-- Card 2: Tag and search -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="field-feature-card">
						<h3>Tag and search your observations.</h3>
						<p>Add conceptual tags to spots to filter and analyze data across your project.</p>
					</div>
				</div>

				<!-- Card 3: Georeferenced notes -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="field-feature-card">
						<h3>Georeferenced notes and sketches.</h3>
						<p>Capture photos, sketches, and notes tied to exact field locations.</p>
					</div>
				</div>

				<!-- Card 4: Measure and log -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="field-feature-card">
						<h3>Measure, log, and interpret in the field.</h3>
						<p>Take field measurements, capture observations, and construct stratigraphic columns in context.</p>
					</div>
				</div>

				<!-- Card 5: Offline first -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="field-feature-card">
						<h3>Offline first, sync later.</h3>
						<p>Collect data anywhere and upload when you're back online.</p>
					</div>
				</div>

				<!-- Card 6: Export and share -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="field-feature-card">
						<h3>Export &amp; share your data your way.</h3>
						<p>Export to common formats for GIS, analysis, and publication.</p>
					</div>
				</div>
			</div>
		</section>

		<!-- Community Section -->
		<section id="community" class="field-section field-community-section">
			<h2 class="field-section-title">Built by the geoscience community for geoscientists.</h2>
			<h3 class="field-section-subtitle">Developed with researchers, educators, and students to support real scientific workflows.</h3>

			<div class="row gtr-uniform field-community-cards">
				<div class="col-4 col-12-medium">
					<div class="field-community-card">
						<p>Used in research &amp; teaching.</p>
					</div>
				</div>
				<div class="col-4 col-12-medium">
					<div class="field-community-card">
						<p>NSF-supported development.</p>
					</div>
				</div>
				<div class="col-4 col-12-medium">
					<div class="field-community-card">
						<p>Free and open-source ecosystem.</p>
					</div>
				</div>
			</div>
		</section>

		<!-- CTA Section -->
		<section id="cta" class="field-section field-cta-section">
			<h2 class="field-section-title">Start collecting better field data today.</h2>
			<h3 class="field-section-subtitle">Turn field observations into structured, reusable science.</h3>

			<div class="field-cta-buttons">
				<a href="https://apps.apple.com/us/app/strabospot2/id1555903455" target="_blank" class="field-store-btn field-apple-btn">
					<span class="field-store-icon">&#63743;</span>
					<span class="field-store-text">
						<span class="field-store-small">Download on the</span>
						<span class="field-store-large">App Store</span>
					</span>
				</a>
				<a href="https://play.google.com/store/apps/details?id=gov.kansas.ku.cgerg.strabospot2" target="_blank" class="field-store-btn field-google-btn">
					<span class="field-store-icon">&#9658;</span>
					<span class="field-store-text">
						<span class="field-store-small">Get it on</span>
						<span class="field-store-large">Google Play</span>
					</span>
				</a>
			</div>

			<div class="field-cta-links">
				<a href="/help" class="field-cta-link">StraboField Help Page</a>
				<a href="https://github.com/StraboSpot/StraboSpot2" target="_blank" class="field-cta-link">StraboField GitHub</a>
			</div>
		</section>

		<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include("includes/mfooter.php");
?>
