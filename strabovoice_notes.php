<?php
/**
 * File: strabovoice_notes.php
 * Description: Public tester notes for the Strabo Voice trial (step 6
 *              TestFlight point 4): install, phone settings, voice and
 *              stopwatch outcrops, review, the answer sheet, problems.
 *              Minimal coaching on purpose: one example of how to talk, no
 *              script. Prints on one sheet (both sides) without the site chrome.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("includes/mheader.php");
include("includes/strabovoice_style.php");
?>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Strabo Voice trial: tester notes</h2>
		</header>

		<div class="sv-page">
			<p class="sv-lead">Thank you for testing Strabo Voice. At some outcrops you will record your Spots by voice; at others you will enter them in StraboField as usual and time yourself. We compare the two for accuracy and speed.</p>

			<h3>1. Install and first run</h3>
			<ul>
				<li>On your iPhone, open the TestFlight invitation email and tap <b>View in TestFlight</b>. Install TestFlight from the App Store if asked, then install Strabo Voice.</li>
				<li>Sign in with your StraboSpot account. Read the <a href="/strabovoice_terms">trial terms</a> and tap <b>I agree</b>.</li>
				<li>Choose a project. Strabo Voice adds a dataset named <b>Voice Spots</b> to it; your voice Spots go there.</li>
			</ul>

			<h3>2. Phone settings</h3>
			<ul>
				<li>Location: allow it while using the app, with <b>Precise Location on</b> (Settings, Strabo Voice, Location). With it off, a Spot can land kilometers from where you stood.</li>
				<li>Allow the microphone and the camera when the app asks.</li>
			</ul>

			<h3>3. At a voice outcrop</h3>
			<ul>
				<li>Press <b>Record</b>, take your measurements and say them as you go, then press <b>Stop</b>. Take photos with the camera button while recording.</li>
				<li>Write your notebook <b>after</b> you press Stop, not while recording.</li>
				<li>Talk the way you would write your notebook: name the feature, then the numbers. For example:
					<div class="sv-example">"Bedding, strike zero four five, dip thirty two. Quality four."</div></li>
				<li>If a phone call, Siri or an alarm interrupts, the recording pauses. The last second before the interruption may be missing, so repeat your last value after you tap <b>Resume</b>.</li>
			</ul>

			<h3>4. At a StraboField outcrop (stopwatch)</h3>
			<ul>
				<li>Alternate through the day: voice at one outcrop, StraboField at the next. Use the same iPhone for both.</li>
				<li>In StraboField, put these Spots in a dataset named <b>Stopwatch Spots</b> in the same project.</li>
				<li>Start the iPhone stopwatch when you pick up your compass for the first measurement. Stop it when the last value is in and the Spot is saved. Then write your notebook.</li>
				<li>Note the time and the Spot name for your answer sheet.</li>
			</ul>

			<h3>5. Review</h3>
			<ul>
				<li>Recordings upload by themselves whenever you have signal. Open <b>Review</b> for the project whenever it suits you, at camp or in the evening.</li>
				<li>Check every value. Every flag needs a tap. Nothing goes into your project until you tap <b>Confirm</b>.</li>
			</ul>

			<h3>6. The same day: your answer sheet</h3>
			<ul>
				<li>Jason emails you a spreadsheet with a row for each Voice Spot (its name and time). Fill in what your <b>notebook</b> says, one row per measurement, plus the stopwatch tab. Use your notebook only, not the app.</li>
				<li>Email it back the same day if you can, while the outcrops are fresh.</li>
			</ul>

			<h3>7. Problems and feedback</h3>
			<ul>
				<li>Take a screenshot and send it from TestFlight (it offers <b>Share Beta Feedback</b>), or email <a href="mailto:strabospot@gmail.com?subject=Strabo Voice">strabospot@gmail.com</a>.</li>
			</ul>

			<p class="sv-print"><button type="button" class="button small" onclick="window.print()">Print these notes</button></p>
		</div>

	</div>
</div>

<?php
include("includes/mfooter.php");
?>
