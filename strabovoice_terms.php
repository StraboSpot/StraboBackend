<?php
/**
 * File: strabovoice_terms.php
 * Description: Public page with the Strabo Voice trial terms (consent text),
 *              read from the same file the app shows
 *              (voicestations/consent/consent_v<N>.json via VsConfig), so the
 *              two can never disagree. Linked from the tester invite email and
 *              used as the privacy policy link for TestFlight review.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/voicestations/lib/VsConfig.php';

$consent = VsConfig::consentText();

// Plain text with any https link made clickable.
function sv_text($s) {
	$out = '';
	foreach (preg_split('#(https://[^\s]+)#', $s, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
		$h = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
		$out .= $i % 2 === 1 ? '<a href="' . $h . '" target="_blank" rel="noopener">' . $h . '</a>' : $h;
	}
	return $out;
}

include("includes/mheader.php");
include("includes/strabovoice_style.php");
?>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2><?php echo htmlspecialchars($consent->title, ENT_QUOTES, 'UTF-8'); ?></h2>
		</header>

		<div class="sv-page">
			<p class="sv-lead"><?php echo sv_text($consent->intro); ?></p>
<?php foreach ($consent->sections as $sec) { ?>
			<h3><?php echo htmlspecialchars($sec->heading, ENT_QUOTES, 'UTF-8'); ?></h3>
			<ul>
<?php foreach ($sec->items as $item) { ?>
				<li><?php echo sv_text($item); ?></li>
<?php } ?>
			</ul>
<?php } ?>
			<p class="sv-closing"><?php echo sv_text($consent->closing); ?></p>
			<p class="sv-meta">Version <?php echo (int)$consent->version; ?>. The Strabo Voice app shows this same text and asks you to agree before your first recording. Tester notes: <a href="/strabovoice_notes">how the trial works</a>.</p>
		</div>

	</div>
</div>

<?php
include("includes/mfooter.php");
?>
