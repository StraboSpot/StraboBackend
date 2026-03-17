<?php
/**
 * File: straboexperimental_landing.php
 * Description: StraboExperimental Landing Page - Information page for StraboExperimental application
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

		<!-- Hero Section with Background Image -->
		<section class="landing-hero" style="margin-top:20px;">
			<div class="landing-hero-background" style="background-image: url('/includes/mimages/straboexperimental/landing/image7.webp');">
				<div class="landing-hero-overlay">
					<h1 class="landing-hero-title">StraboExperimental</h1>
					<hr class="landing-hero-divider">
					<h2 class="landing-hero-tagline">Experimental Data, Fully Contextualized.</h2>
					<p class="landing-hero-description">
						Document apparatus metadata, acquisition system (DAQ) variables, experimental setup, sample
						information, and results in a standardized, reusable format.
					</p>
				</div>
			</div>
		</section>

		<!-- CTA Button Bar -->
		<div class="landing-hero-buttons">
			<a href="/newexperimental/" class="button primary small">Get Started</a>
			<a href="/newexperimental/apparatus_repository" class="button primary small">Apparatus Repository</a>
			<a href="/help" class="button primary small">Help</a>
		</div>

		<!-- Annotated Screenshot -->
		<section class="micro-section">
			<div class="micro-screenshot-container">
				<img src="/includes/mimages/straboexperimental/landing/image5.webp" alt="StraboExperimental Application Overview" class="micro-screenshot">
			</div>
		</section>

		<!-- StraboExperimental Highlights -->
		<section id="tools" class="micro-section">
			<h2 class="landing-highlights-title">StraboExperimental Highlights</h2>

			<!-- Feature Cards Grid (2x3) -->
			<div class="row gtr-uniform">

				<!-- Card 1: Future-Proof Your Research -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/straboexperimental/landing/image6.webp" alt="Future-Proof Your Research">
						</div>
						<h3 style="font-weight: bold;">Future-Proof Your Research</h3>
						<p>Preserve experimental data and context so results remain understandable, reusable, and reproducible years later.</p>
					</div>
				</div>

				<!-- Card 2: Sample-Centered Organization -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/straboexperimental/landing/image8.webp" alt="Sample-Centered Organization">
						</div>
						<h3 style="font-weight: bold;">Sample-Centered Organization</h3>
						<p>Link every dataset directly to the physical sample it came from including provenance, preparation, and metadata.</p>
					</div>
				</div>

				<!-- Card 3: Apparatus & Data Tracking -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/straboexperimental/landing/image1.webp" alt="Apparatus and Data Tracking">
						</div>
						<h3 style="font-weight: bold;">Apparatus &amp; Data Tracking</h3>
						<p>Capture instrument setups, hardware configurations, and acquisition parameters at the time of measurement.</p>
					</div>
				</div>

				<!-- Card 4: Reusable Experimental Metadata -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/straboexperimental/landing/image3.webp" alt="Reusable Experimental Metadata">
						</div>
						<h3 style="font-weight: bold;">Reusable Experimental Metadata</h3>
						<p>Copy metadata from previous experiments and modify only what changed, making it easy to run a series of experiments with consistent setups.</p>
					</div>
				</div>

				<!-- Card 5: Searchable, Structured Experiments -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/straboexperimental/landing/image2.webp" alt="Searchable, Structured Experiments">
						</div>
						<h3 style="font-weight: bold;">Searchable, Structured Experiments</h3>
						<p>Quickly find past experiments using metadata, samples, instruments, or acquisition conditions - not just filenames.</p>
					</div>
				</div>

				<!-- Card 6: Context-Rich Data Storage -->
				<div class="col-4 col-6-medium col-12-xsmall">
					<div class="micro-feature-card">
						<div class="micro-card-image">
							<img src="/includes/mimages/straboexperimental/landing/image4.webp" alt="Context-Rich Data Storage">
						</div>
						<h3 style="font-weight: bold;">Context-Rich Data Storage</h3>
						<p>Store experimental files alongside sample details, apparatus configurations, and DAQ settings so data never loses its meaning.</p>
					</div>
				</div>

			</div>
		</section>

		<!-- Why StraboExperimental / Built For Section -->
		<section class="landing-why-section">
			<div class="row gtr-uniform">
				<div class="col-6 col-12-medium">
					<div class="exp-info-box">
						<h3 class="exp-box-title">Why StraboExperimental?</h3>
						<ul class="exp-benefits-list">
							<li>Standardize experimental metadata across samples and projects</li>
							<li>Reuse apparatus and experimental templates</li>
							<li>Keep data private or share it publicly - your choice</li>
							<li>Search and retrieve experiments easily</li>
							<li>Export metadata for long-term preservation</li>
						</ul>
					</div>
				</div>
				<div class="col-6 col-12-medium">
					<div class="exp-info-box">
						<h3 class="exp-box-title">Built for:</h3>
						<ul class="exp-benefits-list">
							<li>Experimental geoscientists</li>
							<li>Rock deformation laboratories</li>
							<li>Graduate students and PIs</li>
							<li>Researchers who care about reproducibility and traceability</li>
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
