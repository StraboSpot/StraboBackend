<?php
/**
 * File: events.php
 * Description: Events - Upcoming workshops, courses, field trips, and conferences
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("includes/mheader.php");
?>

<style>
	.event-tag {
		display: inline-block;
		color: #ffffff;
		font-size: 0.85em;
		font-weight: 700;
		padding: 0.2em 0.6em;
		margin-right: 0.4em;
		margin-bottom: 0.4em;
		border-radius: 2px;
	}
	.event-tag-inperson { background-color: #b45f06; }
	.event-tag-virtual { background-color: #bf9000; }
	.event-tag-workshop { background-color: #85200c; }
	.event-tag-shortcourse { background-color: #0b5394; }
	.event-tag-fieldtrip { background-color: #351c75; }
	.event-tag-conference { background-color: #741b47; }
	.event-tag-poster { background-color: #38761d; }
	.event-tag-talk { background-color: #134f5c; }

	.event-row {
		display: flex;
		align-items: stretch;
		border: 1px solid rgba(255, 255, 255, 0.1);
		margin-bottom: 1.5em;
		border-radius: 4px;
		overflow: hidden;
	}

	.event-date {
		flex: 0 0 130px;
		display: flex;
		flex-direction: column;
		justify-content: center;
		align-items: center;
		text-align: center;
		padding: 1.5em 1em;
		border-right: 1px solid rgba(255, 255, 255, 0.1);
	}

	.event-date-days {
		color: #e44c65;
		font-size: 1.6em;
		font-weight: 700;
		line-height: 1.2;
	}

	.event-date-month,
	.event-date-year {
		color: #e44c65;
		font-size: 1.1em;
		font-weight: 700;
	}

	.event-details {
		flex: 1;
		padding: 1.5em;
	}

	.event-title {
		color: #ffffff;
		font-size: 1.4em;
		font-weight: 700;
		margin: 0 0 0.5em 0;
	}

	.event-tags {
		margin-bottom: 0.75em;
	}

	.event-description {
		color: rgba(255, 255, 255, 0.85);
		line-height: 1.6;
		margin-bottom: 0.5em;
	}

	.event-register {
		color: #e44c65;
		font-style: italic;
	}

	.event-image {
		flex: 0 0 160px;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 1em;
	}

	.event-image img {
		max-width: 100%;
		max-height: 150px;
		height: auto;
		border-radius: 4px;
	}

	@media screen and (max-width: 736px) {
		.event-row {
			flex-direction: column;
		}

		.event-date {
			flex: none;
			flex-direction: row;
			gap: 0.5em;
			padding: 1em;
			border-right: none;
			border-bottom: 1px solid rgba(255, 255, 255, 0.1);
		}

		.event-image {
			flex: none;
			padding: 1em;
		}
	}
</style>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Events</h2>
		</header>

		<!-- Intro -->
		<section class="micro-section">
			<h2 class="exp-section-title" style="font-size: 1.6em;">Explore. Learn. Connect.</h2>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.05em; line-height: 1.7; margin-bottom: 2em;">
				Discover upcoming workshops, online opportunities, short courses, field trips, and conferences
				that help you grow, collaborate, and advance your work with StraboSpot.
			</p>
		</section>

		<!-- Filters -->
		<section class="micro-section">
			<p style="color: #ffffff; font-weight: 700; font-size: 1.1em; margin-bottom: 0.5em;">Filters:</p>
			<div style="margin-bottom: 2em;">
				<span class="event-tag event-tag-inperson">In Person</span>
				<span class="event-tag event-tag-virtual">Virtual</span>
				<span class="event-tag event-tag-workshop">Workshop</span>
				<span class="event-tag event-tag-shortcourse">Short Course</span>
				<span class="event-tag event-tag-fieldtrip">Field Trip</span>
				<span class="event-tag event-tag-conference">Conference</span>
				<span class="event-tag event-tag-poster">Poster</span>
				<span class="event-tag event-tag-talk">Talk</span>
			</div>
		</section>

		<!-- Event 1: GSA Cordilleran Short Course -->
		<div class="event-row">
			<div class="event-date">
				<span class="event-date-days">21-24</span>
				<span class="event-date-month">April</span>
				<span class="event-date-year">2026</span>
			</div>
			<div class="event-details">
				<h3 class="event-title">GSA Cordilleran Short Course</h3>
				<div class="event-tags">
					<span class="event-tag event-tag-inperson">In Person</span>
					<span class="event-tag event-tag-shortcourse">Short Course</span>
					<span class="event-tag event-tag-conference">Conference</span>
				</div>
				<p class="event-description">Join us for a short course exploring _____ using the StraboField application!</p>
				<p class="event-register">Register Here:</p>
			</div>
			<div class="event-image">
				<img src="/includes/mimages/events/image2.webp" alt="GSA Cordilleran Section">
			</div>
		</div>

		<!-- Event 2: SZ4D Community Meeting -->
		<div class="event-row">
			<div class="event-date">
				<span class="event-date-days">20-22</span>
				<span class="event-date-month">April</span>
				<span class="event-date-year">2026</span>
			</div>
			<div class="event-details">
				<h3 class="event-title">SZ4D Community Meeting</h3>
				<div class="event-tags">
					<span class="event-tag event-tag-inperson">In Person</span>
					<span class="event-tag event-tag-conference">Conference</span>
					<span class="event-tag event-tag-poster">Poster</span>
				</div>
				<p class="event-description">Check out our poster at ______</p>
			</div>
			<div class="event-image">
				<img src="/includes/mimages/events/image1.webp" alt="SZ4D Community Meeting">
			</div>
		</div>

		<!-- Event 3: Santa Catalina Field Trip -->
		<div class="event-row">
			<div class="event-date">
				<span class="event-date-days">22-24</span>
				<span class="event-date-month">April</span>
				<span class="event-date-year">2026</span>
			</div>
			<div class="event-details">
				<h3 class="event-title">Santa Catalina Field Trip</h3>
				<div class="event-tags">
					<span class="event-tag event-tag-inperson">In Person</span>
					<span class="event-tag event-tag-fieldtrip">Field Trip</span>
				</div>
				<p class="event-description">Join the SZ4D Field Trip to Santa Catalina and optionally learn more about StraboSpot and StraboField</p>
				<p class="event-register">Register Here:</p>
			</div>
			<div class="event-image">
				<img src="/includes/mimages/events/image4.webp" alt="SZ4D">
			</div>
		</div>

		<!-- Event 4: EGU Field Trip & Poster -->
		<div class="event-row">
			<div class="event-date">
				<span class="event-date-days">3-8</span>
				<span class="event-date-month">May</span>
				<span class="event-date-year">2026</span>
			</div>
			<div class="event-details">
				<h3 class="event-title">EGU Field Trip &amp; Poster</h3>
				<div class="event-tags">
					<span class="event-tag event-tag-inperson">In Person</span>
					<span class="event-tag event-tag-fieldtrip">Field Trip</span>
					<span class="event-tag event-tag-conference">Conference</span>
					<span class="event-tag event-tag-poster">Poster</span>
				</div>
				<p class="event-description">Check out our Poster ___ or join our Field Trip with the Tephra community to learn about the new Tephra tools in StraboField</p>
				<p class="event-register">Register Here:</p>
			</div>
			<div class="event-image">
				<img src="/includes/mimages/events/image3.webp" alt="EGU">
			</div>
		</div>

		<!-- Event 5: StraboField for Field Camp -->
		<div class="event-row">
			<div class="event-date">
				<span class="event-date-days">6-9</span>
				<span class="event-date-month">July</span>
				<span class="event-date-year">2026</span>
			</div>
			<div class="event-details">
				<h3 class="event-title">StraboField for Field Camp</h3>
				<div class="event-tags">
					<span class="event-tag event-tag-inperson">In Person</span>
					<span class="event-tag event-tag-workshop">Workshop</span>
				</div>
				<p class="event-description">Join us for a short course exploring _____ using the StraboField application!</p>
				<p class="event-register">Register Here:</p>
			</div>
			<div class="event-image">
				<img src="/includes/mimages/events/image5.webp" alt="StraboSpot">
			</div>
		</div>

		<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include("includes/mfooter.php");
?>
