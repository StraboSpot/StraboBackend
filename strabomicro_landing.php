<?php
/**
 * File: strabomicro_landing.php
 * Description: StraboMicro Landing Page - Information and download page for StraboMicro application
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
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

		<!-- Hero Section -->
		<section id="micro-hero" class="micro-landing-hero">
			<div class="row gtr-uniform">
				<div class="col-8 col-12-medium">
					<div class="micro-hero-content">
						<div class="micro-hero-logo">
							<img src="/includes/mimages/strabomicro/strabomicro_logo.png" alt="StraboMicro Logo" onerror="this.style.display='none'">
						</div>
						<div class="micro-hero-text">
							<!--<span class="micro-hero-title">StraboMicro</span>-->
							<h2 class="micro-hero-tagline">Organize, analyze, and connect<br>microstructural data.</h2>
							<p class="micro-hero-description">
								A desktop application for geologic micrograph analysis and data management.
								Upload photomicrographs at any scale, orient and nest related images, and
								capture observations using points, lines, or polygons. Attach supporting files,
								link your work to StraboField, and easily share projects with collaborators.
							</p>
						</div>
					</div>
				</div>
				<div class="col-4 col-12-medium">
					<div class="micro-hero-download">
						<a href="https://www.jdeploy.com/~strabomicro" target="_blank" class="button primary">Download</a>
						<p class="micro-version-text"><em>Current Version:</em> StraboMicro 2.0.0</p>
					</div>
				</div>
			</div>

			<!-- Navigation Tabs -->
			<nav class="micro-nav-tabs">
				<ul>
					<li><a href="#tools">Tools</a></li>
					<li><a href="#features">Application Features</a></li>
					<li><a href="https://www.jdeploy.com/~strabomicro" target="_blank">Download Information</a></li>
					<li><a href="/microhelp">Help Guide</a></li>
					<li><a href="https://www.youtube.com/channel/UC-bWoOvRvpedOswFERXQPiw" target="_blank">Video Tutorials</a></li>
				</ul>
			</nav>
		</section>

		<!-- Application Tools Section -->
		<section id="tools" class="micro-section">
			

			<!-- Screenshot -->
			<div class="micro-screenshot-container">
				<img src="/includes/mimages/strabomicro/application_tools_screenshot.jpg" alt="StraboMicro Application Tools" class="micro-screenshot">
			</div>
			
			<h2 class="micro-section-title">Application Tools</h2>

			<!-- Tools Feature Cards (3x3 grid with images) -->
			<div class="row gtr-uniform">

				<!-- Card 1: See micrographs your way -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/see_micrographs.jpg" alt="See micrographs your way">
						</div>
						<h3>See micrographs your way.</h3>
						<p>Adjust overlay transparency with per-micrograph opacity sliders and live previews for precise control.</p>
					</div>
				</div>

				<!-- Card 2: Count and classify points in a flash -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/count_classify.jpg" alt="Count and classify points in a flash">
						</div>
						<h3>Count and classify points in a flash.</h3>
						<p>Rapidly assign minerals using customizable grids, keyboard shortcuts, live stats, session saving, and CSV export.</p>
					</div>
				</div>

				<!-- Card 3: Compare multiple images instantly -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/compare_images.jpg" alt="Compare multiple images instantly">
						</div>
						<h3>Compare multiple images instantly.</h3>
						<p>View micrographs side-by-side in full-screen grids with independent or synchronized pan/zoom, overlay support, and theme-aware backgrounds.</p>
					</div>
				</div>

				<!-- Card 4: Detect grains automatically -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/detect_grains.jpg" alt="Detect grains automatically">
						</div>
						<h3>Detect grains automatically, classical or AI-powered.</h3>
						<p>Choose OpenCV for fast, parameterized detection or FastSAM AI for high-accuracy contour extraction across platforms with GPU acceleration.</p>
					</div>
				</div>

				<!-- Card 5: Edit spots faster than ever -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/edit_spots.jpg" alt="Edit spots faster than ever">
						</div>
						<h3>Edit spots faster than ever.</h3>
						<p>Keyboard-driven Quick Edit mode plus batch selection lets you classify, assign minerals, and track progress with visual feedback and auto-advance.</p>
					</div>
				</div>

				<!-- Card 6: Merge, split, and manage spots effortlessly -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/merge_split.jpg" alt="Merge, split, and manage spots effortlessly">
						</div>
						<h3>Merge, split, and manage spots effortlessly.</h3>
						<p>Combine or divide polygons with undo/redo, track provenance, and perform batch edits for multiple spots at once.</p>
					</div>
				</div>

				<!-- Card 7: Measure grains with precision -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/measure_grains.jpg" alt="Measure grains with precision">
						</div>
						<h3>Measure grains with precision.</h3>
						<p>Automatic real-world calculations for line lengths, polygon areas, and perimeters, plus on-canvas ruler measurements for quick checks.</p>
					</div>
				</div>

				<!-- Card 8: Analyze grain size and orientation quantitatively -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/analyze_grain.jpg" alt="Analyze grain size and orientation quantitatively">
						</div>
						<h3>Analyze grain size and orientation quantitatively.</h3>
						<p>Generate metrics, rose diagrams, histograms, mineral grouping, and exportable CSV or PDF reports for deep morphometric insights.</p>
					</div>
				</div>

				<!-- Card 9: Export your work anywhere -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/strabomicro/export_work.jpg" alt="Export your work anywhere">
						</div>
						<h3>Export your work anywhere.</h3>
						<p>Save fully editable vector spots with raster bases for Illustrator, Inkscape, or Affinity Designer—labels remain editable for downstream analysis.</p>
					</div>
				</div>

			</div>
		</section><!-- end #tools -->

		<!-- Application Features Section (3x3 grid, text-only) -->
		<section id="features" class="micro-section">
			<h2 class="micro-section-title">Application Features</h2>
			<div class="row gtr-uniform">

				<!-- Feature 1: Intuitive micrograph workspace -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Intuitive micrograph workspace.</h3>
						<p>View, overlay, and interact with micrographs seamlessly. Add spots, scroll through images, see notes, scale, cursor position, and metadata—everything you need without leaving the active micrograph.</p>
					</div>
				</div>

				<!-- Feature 2: Navigate overlays instantly -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Navigate overlays instantly.</h3>
						<p>Click to jump to any micrograph, move backward through history, explore unlimited depth, and get clear visual feedback with sidebar integration.</p>
					</div>
				</div>

				<!-- Feature 3: Always-sharp images -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Always-sharp images.</h3>
						<p>Dynamic level-of-detail rendering keeps micrographs crisp at any zoom, without sacrificing performance.</p>
					</div>
				</div>

				<!-- Feature 4: Context-aware spots -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Context-aware spots.</h3>
						<p>See spots from child micrographs on the parent view, fully interactive, with optional visibility controls—so high-resolution analyses stay in context.</p>
					</div>
				</div>

				<!-- Feature 5: Metadata and project totals at a glance -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Metadata and project totals at a glance.</h3>
						<p>Tabbed panels let you quickly access micrograph, spot, or project metadata, remembering your last selection. Live project totals update automatically at the bottom of the sidebar.</p>
					</div>
				</div>

				<!-- Feature 6: Efficient file and overlay management -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Efficient file and overlay management.</h3>
						<p>Bulk-import associated files, preview and apply metadata, align overlays precisely with 3-point registration, and open any associated file instantly in your system's default app.</p>
					</div>
				</div>

				<!-- Feature 7: Work safely and reliably -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Work safely and reliably.</h3>
						<p>Automatic saving, version history, restore options, and crash reporting ensure your projects and data are always protected.</p>
					</div>
				</div>

				<!-- Feature 8: Access projects instantly -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Access projects instantly.</h3>
						<p>Open recent projects or .smz files in one click, switch safely between workspaces, and continue exactly where you left off.</p>
					</div>
				</div>

				<!-- Feature 9: Stay up to date anywhere -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card micro-text-only">
						<h3>Stay up to date anywhere.</h3>
						<p>Automatic updates and cross-platform support keep your app current and running smoothly on Windows, macOS, and Linux.</p>
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
