<?php
/**
 * File: strabomicro_landing.php
 * Description: StraboMicro Landing Page - Information and download page for StraboMicro application
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
			<h2>StraboMicro</h2>
		</header>

		<section class="micro-section">
			<h2 style="color: #ffffff; font-size: 1.6em; font-weight: 300; margin-bottom: 0.5em;">Small scale. Big ideas.</h2>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.05em; line-height: 1.7; margin-bottom: 1.5em;">
				StraboMicro is a tool for managing images and reporting geologic data at the microstructural scale
				(e.g., from thin sections). It is part of StraboSpot, a digital system for collecting, managing, and
				sharing geologic data across scales. From field and laboratory observations to experimental rock
				deformation data.
			</p>
		</section>

		<!-- CTA Button Bar -->
		<div class="landing-hero-buttons">
			<a href="/micro_download" class="button primary small">Download Now</a>
			<a href="/instrumentcatalog" class="button primary small">Instrument Repository</a>
			<a href="/help#micro" class="button primary small">Help</a>
		</div>

		<!-- Annotated Screenshot -->
		<section class="micro-section">
			<div class="micro-screenshot-container">
				<img src="/includes/mimages/strabomicro/annotated_screenshot.webp" alt="StraboMicro Application Overview" class="micro-screenshot">
			</div>
		</section>

		<!-- StraboMicro Highlights -->
		<section id="tools" class="micro-section">
			<h2 class="landing-highlights-title">StraboMicro Highlights</h2>

			<!-- Tools Feature Cards (3x3 grid with images) -->
			<div class="row gtr-uniform">

				<!-- Card 1: See micrographs your way -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/see_micrographs_new.webp" alt="See micrographs your way">
						</div>
						<h3 style="font-weight: bold;">See micrographs your way.</h3>
						<p>Adjust overlay transparency with per-micrograph opacity sliders and live previews for precise control.</p>
					</div>
				</div>

				<!-- Card 2: Count and classify points in a flash -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/count_classify_new.webp" alt="Count and classify points in a flash">
						</div>
						<h3 style="font-weight: bold;">Count and classify points in a flash.</h3>
						<p>Rapidly assign minerals using customizable grids, keyboard shortcuts, live stats, session saving, and CSV export.</p>
					</div>
				</div>

				<!-- Card 3: Compare multiple images instantly -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/compare_images_new.webp" alt="Compare multiple images instantly">
						</div>
						<h3 style="font-weight: bold;">Compare multiple images instantly.</h3>
						<p>View micrographs side-by-side in full-screen grids with independent or synchronized pan/zoom, overlay support, and theme-aware backgrounds.</p>
					</div>
				</div>

				<!-- Card 4: Detect grains automatically -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/detect_grains_new.webp" alt="Detect grains automatically">
						</div>
						<h3 style="font-weight: bold;">Detect grains automatically, classical or AI-powered.</h3>
						<p>Choose OpenCV for fast, parameterized detection or FastSAM AI for high-accuracy contour extraction across platforms with GPU acceleration.</p>
					</div>
				</div>

				<!-- Card 5: Edit spots faster than ever -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/edit_spots_new.webp" alt="Edit spots faster than ever">
						</div>
						<h3 style="font-weight: bold;">Edit spots faster than ever.</h3>
						<p>Keyboard-driven Quick Edit mode plus batch selection lets you classify, assign minerals, and track progress with visual feedback and auto-advance.</p>
					</div>
				</div>

				<!-- Card 6: Merge, split, and manage spots effortlessly -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/merge_split_new.webp" alt="Merge, split, and manage spots effortlessly">
						</div>
						<h3 style="font-weight: bold;">Merge, split, and manage spots effortlessly.</h3>
						<p>Combine or divide polygons with undo/redo, track provenance, and perform batch edits for multiple spots at once.</p>
					</div>
				</div>

				<!-- Card 7: Measure grains with precision -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/measure_grains_new.webp" alt="Measure grains with precision">
						</div>
						<h3 style="font-weight: bold;">Measure grains with precision.</h3>
						<p>Automatic real-world calculations for line lengths, polygon areas, and perimeters, plus on-canvas ruler measurements for quick checks.</p>
					</div>
				</div>

				<!-- Card 8: Analyze grain size and orientation quantitatively -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/analyze_grain_new.webp" alt="Analyze grain size and orientation quantitatively">
						</div>
						<h3 style="font-weight: bold;">Analyze grain size and orientation quantitatively.</h3>
						<p>Generate metrics, rose diagrams, histograms, mineral grouping, and exportable CSV or PDF reports for deep morphometric insights.</p>
					</div>
				</div>

				<!-- Card 9: Export your work anywhere -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/export_work_new.webp" alt="Export your work anywhere">
						</div>
						<h3 style="font-weight: bold;">Export your work anywhere.</h3>
						<p>Save fully editable vector spots with raster bases for Illustrator, Inkscape, or Affinity Designer—labels remain editable for downstream analysis.</p>
					</div>
				</div>

			</div>
		</section>

		<!-- Why StraboMicro / Built For Section -->
		<section class="landing-why-section">
			<div class="row gtr-uniform">
				<div class="col-6 col-12-medium">
					<div class="exp-info-box">
						<h3 class="exp-box-title">Why StraboMicro?</h3>
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
