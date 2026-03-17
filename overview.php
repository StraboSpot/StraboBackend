<?php
/**
 * File: overview.php
 * Description: About StraboSpot - Overview of the StraboSpot ecosystem
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
			<h2>About StraboSpot</h2>
		</header>

		<!-- Tagline -->
		<section class="micro-section">
			<h2 class="exp-section-title" style="font-size: 1.6em;">Connecting Geology Across Scales.</h2>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.05em; line-height: 1.7; margin-bottom: 2em;">
				Designed by and for the geoscience community, StraboSpot unifies multidisciplinary workflows
				into a shared system that makes geologic data consistent, discoverable, and reusable.
			</p>
		</section>

		<!-- Quick Links -->
		<div class="landing-hero-buttons" style="margin-bottom: 3em;">
			<a href="/citations" class="button primary small">Citations</a>
			<a href="/privacy" class="button primary small">Privacy</a>
			<a href="/interoperability" class="button primary small">Interoperability</a>
			<a href="/api" class="button primary small">API</a>
		</div>

		<!-- Hierarchy Diagram -->
		<section class="micro-section">
			<div class="micro-screenshot-container">
				<img src="/includes/mimages/about_hierarchy.webp" alt="Hierarchical Data from the Field to the Lab and back" class="micro-screenshot">
			</div>
		</section>

		<!-- What is StraboSpot? -->
		<section class="micro-section">
			<h2 class="exp-section-title" style="font-size: 2em;">What is StraboSpot?</h2>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.0em; line-height: 1.7; margin-bottom: 1.5em;">
				StraboSpot is an interconnected ecosystem of applications that enables geoscientists to collect,
				manage, integrate, and share field and laboratory data within a single FAIR-aligned system.
				Designed through community collaboration, it supports workflows in structural geology, petrology,
				sedimentology, and tephra volcanology, using controlled vocabularies to standardize and enhance
				data discoverability. Built around the concept of &ldquo;spots&rdquo; which are spatially defined
				observations. StraboSpot links data and images from regional to microscopic scales, allowing
				users to organize complex geological relationships within one cohesive platform.
			</p>

			<h3 style="color: #ffffff; font-weight: 700; font-size: 1.5em; margin-bottom: 0.5em;">Motivation</h3>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.0em; line-height: 1.7; margin-bottom: 1.5em;">
				Structural Geology and Tectonics (SG&amp;T) investigate the architecture and deformation of the
				Earth&mdash;faults, folds, fractures, fabrics, and microstructures&mdash;across vast spatial and
				temporal scales to reconstruct Earth&rsquo;s history. Despite forming some of the most fundamental
				&ldquo;ground truth&rdquo; data in geoscience, SG&amp;T data have historically existed only in
				abstracted form within publications, with no standardized digital system for collecting, archiving,
				searching, or sharing them. This lack of common formats and cyberinfrastructure has limited data
				discovery, reuse, and cross-disciplinary integration. In response, community discussions through
				the EarthCube End User Domain Workshop identified the creation of a dedicated, standardized
				digital data system as a top priority, leading to the NSF-funded development of the Strabo Data
				System to provide accessible, sharable, and reusable structural geology data.
			</p>
		</section>

		<!-- Data Overview -->
		<section class="micro-section">
			<h2 class="exp-section-title" style="font-size: 2em;">Data Overview</h2>
			<h3 style="color: #ffffff; font-weight: 700; font-size: 1.5em; margin-bottom: 0.5em;">Spots: The Foundation of Spatially Connected Geologic Data.</h3>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.0em; line-height: 1.7;">
				A Spot is the core data unit in the Strabo Data System. A spatially defined observation that captures
				where and over what scale a measurement applies. By linking Spots hierarchically across field, map,
				and laboratory scales, StraboSpot preserves the spatial relationships, scale, and purpose of geologic
				data, enabling meaningful integration of primary observations and derived interpretations.
			</p>
		</section>

		<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include("includes/mfooter.php");
?>
