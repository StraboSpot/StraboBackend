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

		<header class="major">
			<h2>StraboExperimental</h2>
		</header>

		<!-- Hero Section -->
		<section id="exp-hero" class="exp-landing-hero">
			<div class="row gtr-uniform">
				<!--
				<div class="col-2 col-12-medium">
					<div class="exp-hero-logo">
						<img src="/files/strabospot_pub_logo.png" alt="StraboSpot Logo">
					</div>
				</div>
				-->
				<div class="col-9 col-12-medium">
					<div class="exp-hero-content">
						<div class="exp-hero-text">
							<h2 class="exp-hero-tagline exp-section-title">StraboExperimental helps researchers organize, document, and share experimental geoscience data in a structured, reproducible way.</h2>
							<p class="exp-hero-description">
								A free, open platform for documenting apparatus metadata, acquisition system (DAQ) variables, experimental setup, sample information, and results in a standardized, reusable format.
							</p>
						</div>
					</div>
				</div>
				<div class="col-3 col-12-medium" style="text-align: center;">
					<a href="/experimental" class="button primary">Get Started</a>
					<a href="#" class="button primary" style="margin-top: 12px;">Explore Example Experiment</a>
					<a href="/manual/experimental" target="_blank" class="button primary" style="margin-top: 12px;">Read the Manual</a>
				</div>
			</div>
		</section>

		<!-- How It Works Section -->
		<section id="how-it-works" class="exp-section">
			

			<!-- Workflow Screenshot -->
			<div class="exp-screenshot-container exp-community-section">
				<img src="/includes/mimages/straboexperimental/Experimental_promo.png" alt="StraboExperimental Workflow" class="exp-screenshot">
			</div>
			
			<h2 class="exp-section-title">How it works:</h2>

			<!-- Steps -->
			<div class="exp-steps">
				<ol class="exp-steps-list">
					<li><strong>Start a new project</strong></li>
					<li><strong>Add an experiment</strong> <span class="exp-tip">(tip: load example data (green button) to explore)</span></li>
					<li><strong>Describe experimental conditions</strong>, sample information, and acquisition systems</li>
					<li><strong>Select your apparatus</strong> from the repository or add your facility's instrumentation</li>
					<li><strong>Save, search, or share</strong> when ready</li>
				</ol>
			</div>
		</section>

		<h2 class="exp-section-title" style="border-top: 1px solid rgba(255, 255, 255, 0.1); padding-top: 25px;">View projects and experiments on the data page:</h2>

		<!-- Project Example Screenshot -->
		<section class="exp-section">
			<div class="exp-screenshot-container">
				<img src="/includes/mimages/straboexperimental/experiment_list.png" alt="StraboExperimental Project View" class="exp-screenshot">
			</div>
		</section>

		<!-- Why StraboExperimental and Built For Section -->
		<section class="exp-section">
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

		<!-- Community Statement -->
		<section class="exp-section exp-community-section">
			<h2 class="exp-community-title">Built by the experimental community for geoscientists.</h2>
			<p class="exp-community-subtitle">Developed with researchers, educators, and students to support real scientific workflows.</p>
		</section>

		<!-- Three Feature Boxes -->
		<section class="exp-section">
			<div class="row gtr-uniform">
				<div class="col-4 col-12-medium">
					<div class="exp-feature-box">
						<h4 style="font-weight: bold;">Free, open-source, and community designed</h4>
						<p>Built with support from public research funding.</p>
					</div>
				</div>
				<div class="col-4 col-12-medium">
					<div class="exp-feature-box">
						<h4 style="font-weight: bold;">Your data, your terms</h4>
						<p>Keep experiments private, share with collaborators, or publish openly on your terms.</p>
					</div>
				</div>
				<div class="col-4 col-12-medium">
					<div class="exp-feature-box">
						<h4 style="font-weight: bold;">FAIR data principles</h4>
						<p>Supports FAIR data principles so experiments remain findable, accessible, and reusable.</p>
					</div>
				</div>
			</div>
		</section>

		<!-- Call to Action -->
		<section class="exp-section exp-cta-section">
			<h2 class="exp-cta-title">Start documenting experiments clearly.</h2>
			<div class="exp-cta-buttons">
				<a href="/experimental" class="button primary">Get Started</a>
				<a href="/manual/experimental" target="_blank" class="button">Read the Manual</a>
			</div>
		</section>

		<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include("includes/mfooter.php");
?>
