<?php
/**
 * File: help_igsn.php
 * Description: Help guide for registering and managing IGSNs at SESAR
 *              from StraboSamples. Linked from the IGSN page; add it to
 *              help.php when the IGSN feature opens to everyone.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("includes/mheader.php");

/** One screenshot, linked to its full-size file. $w = its width in CSS pixels (the files are 1.5x). */
function ih_fig($file, $w, $alt, $caption) {
	$src = '/includes/mimages/igsn_help/' . $file . '.webp';
	echo '<figure class="ih-fig"><a href="' . $src . '" target="_blank" rel="noopener" title="Open full size"><img src="' . $src
		. '" width="' . (int)$w . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" loading="lazy"></a><figcaption>' . $caption . '</figcaption></figure>';
}
?>

<style>
.ih { max-width: 60em; margin: 0 auto; color: rgba(255,255,255,0.85); line-height: 1.7; }
.ih h2 { color: #fff; font-size: 1.6em; font-weight: 300; margin: 2.2em 0 0.6em; padding-bottom: 0.3em;
	border-bottom: 1px solid rgba(255,255,255,0.15); scroll-margin-top: 100px; }
.ih h3 { color: #fff; font-size: 1.15em; font-weight: 400; margin: 1.6em 0 0.4em; scroll-margin-top: 100px; }
.ih p, .ih li { font-size: 1em; }
.ih ul, .ih ol { margin: 0 0 1.2em 1.4em; }
.ih li { margin-bottom: 0.35em; }
.ih strong { color: #fff; }
.ih a { color: #e44c65; }
.ih a:hover { color: #f06880; }
.ih code { background: rgba(255,255,255,0.08); padding: 0.1em 0.35em; border-radius: 3px; font-size: 0.92em; }
.ih-lead { font-size: 1.1em; }
.ih-note { background: #2a2a3a; border-left: 3px solid #e44c65; padding: 0.9em 1.2em; margin: 1.2em 0; border-radius: 3px; }
.ih-note.ih-warn { border-left-color: #d6a943; }
.ih-note p:last-child, .ih-note ul:last-child { margin-bottom: 0; }
.ih-toc { background: #2a2a3a; padding: 1em 1.4em; border-radius: 4px; margin: 1.5em 0 2em; }
.ih-toc ol { margin: 0.4em 0 0 1.2em; columns: 2; column-gap: 2.5em; }
.ih-toc li { break-inside: avoid; }
.ih-fig { margin: 1.3em 0 1.8em; text-align: center; }
.ih-fig img { max-width: 100%; height: auto; border: 1px solid rgba(255,255,255,0.15); border-radius: 4px; }
.ih-fig figcaption { font-size: 0.9em; color: rgba(255,255,255,0.6); margin-top: 0.5em; }
.ih-steps { counter-reset: ih; list-style: none; margin-left: 0 !important; }
.ih-steps > li { counter-increment: ih; position: relative; padding-left: 2.4em; margin-bottom: 0.8em; }
.ih-steps > li::before { content: counter(ih); position: absolute; left: 0; top: 0.1em; width: 1.7em; height: 1.7em;
	border-radius: 50%; background: #e44c65; color: #fff; text-align: center; line-height: 1.7em; font-size: 0.9em; }
.ih-table { width: 100%; border-collapse: collapse; margin: 1em 0 1.6em; font-size: 0.95em; }
.ih-table th, .ih-table td { text-align: left; vertical-align: top; padding: 0.55em 0.8em; border-bottom: 1px solid rgba(255,255,255,0.1); }
.ih-table th { color: #fff; font-weight: 600; background: #2a2a3a; }
.ih-pill { display: inline-block; white-space: nowrap; padding: 0.05em 0.6em; border-radius: 1em; font-size: 0.88em;
	background: rgba(255,255,255,0.1); color: #fff; }
.ih-pill.ok { background: rgba(80,160,100,0.25); color: #a8e0b5; }
.ih-pill.warn { background: rgba(214,169,67,0.22); color: #f0cf84; }
.ih-pill.bad { background: rgba(228,76,101,0.22); color: #f59aa9; }
.ih-top { font-size: 0.85em; text-align: right; margin-top: -0.6em; }
.ih-faq dt { color: #fff; font-weight: 600; margin-top: 1.1em; }
.ih-faq dd { margin: 0.3em 0 0 0; }
@media (max-width: 736px) {
	.ih-toc ol { columns: 1; }
	.ih-table th, .ih-table td { padding: 0.45em 0.5em; }
}
</style>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>IGSNs in StraboSpot</h2>
		</header>

		<div class="ih">

		<p class="ih-lead">StraboSpot can register an <strong>IGSN</strong> for any of your samples at
		<strong>SESAR</strong> (the System for Earth Sample Registration), keep the SESAR record in step with
		your sample, and bring samples you already registered at SESAR into StraboSamples. This guide walks
		through every part of it, with best practices at the end.</p>

		<div class="ih-note">
			<p>The screenshots in this guide were taken on SESAR's test site, so some of them show a yellow
			<span class="ih-pill warn">SESAR test</span> badge. You will not see that badge on your own samples.</p>
		</div>

		<nav class="ih-toc" aria-label="Contents">
			<strong>Contents</strong>
			<ol>
				<li><a href="#about">What an IGSN is</a></li>
				<li><a href="#connect">Connect your SESAR account (once)</a></li>
				<li><a href="#page">The IGSNs page</a></li>
				<li><a href="#register">Register IGSNs</a></li>
				<li><a href="#after">After registering</a></li>
				<li><a href="#send">Send your edits to SESAR</a></li>
				<li><a href="#pull">Pull from SESAR</a></li>
				<li><a href="#import">Import samples from SESAR</a></li>
				<li><a href="#batch">SESAR batch file</a></li>
				<li><a href="#reports">Reports</a></li>
				<li><a href="#deactivate">Retire an IGSN (deactivation)</a></li>
				<li><a href="#states">What the IGSN states mean</a></li>
				<li><a href="#public">Who can see a registered sample</a></li>
				<li><a href="#best">Best practices</a></li>
				<li><a href="#faq">Troubleshooting and questions</a></li>
			</ol>
		</nav>

		<!-- ================================================================ -->
		<h2 id="about">1. What an IGSN is</h2>

		<p>An IGSN (International Generic Sample Number) is a permanent, citable identifier for a physical
		sample: a rock, a core, a thin section, a mineral separate. Each IGSN is also a DOI, so it looks like
		<code>10.58052/IEJMA0010</code> and resolves on the web to a page describing the sample. You can cite
		it in papers and data sets, and anyone who reads it can find the sample's record.</p>

		<p>SESAR issues IGSNs. Every IGSN you register starts with your <strong>SESAR code</strong>, a
		five-character prefix such as <code>IEJMA</code> that belongs to you.</p>

		<div class="ih-note ih-warn">
			<p><strong>IGSNs are public and permanent.</strong> SESAR publishes each sample's name, location,
			description, collection date and purpose with its IGSN. A registered IGSN cannot be deleted; SESAR
			staff can only deactivate it. Register a sample when its details are right and you are ready for
			them to be public.</p>
		</div>

		<p>StraboSpot registers IGSNs <strong>in your name</strong>, under your own SESAR account. The records
		are yours at SESAR, exactly as if you had made them there.</p>

		<!-- ================================================================ -->
		<h2 id="connect">2. Connect your SESAR account (once)</h2>

		<p>Everything starts on the <strong>IGSNs</strong> page. Open <a href="/my_samples.php">My Samples</a>
		and click the <strong>IGSNs</strong> button in the toolbar.</p>

		<?php ih_fig('my_samples_toolbar', 784, 'My Samples toolbar with the IGSNs button', 'The IGSNs button on My Samples opens the IGSNs page.'); ?>

		<p>The panel at the top of the IGSNs page walks you through a one-time setup. Its steps are shown
		across the top of the panel: <em>Sign in with ORCID &rsaquo; SESAR account &rsaquo; API access
		&rsaquo; SESAR code &rsaquo; Ready</em>. You only do the steps you have not done before.</p>

		<ol class="ih-steps">
			<li><strong>Sign in with ORCID.</strong> Click <strong>Connect with ORCID</strong>. A small ORCID
			window opens; sign in and it closes by itself. SESAR finds your account through your ORCID iD, so
			you need an <a href="https://orcid.org/register" target="_blank" rel="noopener">ORCID iD</a>.</li>
			<li><strong>SESAR account.</strong> If SESAR has no account for your ORCID iD yet, the panel says
			so. Open SESAR, click <strong>Log in</strong> and sign in with the <em>same</em> ORCID iD; your first
			sign-in creates the account. Come back and click <strong>Check again</strong>.</li>
			<li><strong>API access.</strong> SESAR only lets approved accounts use its API, which is how
			StraboSpot registers samples for you. The panel fills in a request for you (name, email,
			institution, role); click <strong>Send request to SESAR</strong>. SESAR staff approve each request
			by hand, so allow a day or more. You do not need to keep the page open: it checks again whenever
			you come back. No answer after a few days? Write to
			<a href="mailto:info@geosamples.org">info@geosamples.org</a>.</li>
			<li><strong>SESAR code.</strong> Choose your SESAR code: <code>IE</code> plus three letters or
			digits of your choice, often your initials. Codes are unique across SESAR, so if yours is taken,
			try another. If you already made one at SESAR, click <strong>Check again</strong> instead.</li>
			<li><strong>Ready.</strong> The panel shows <strong>Connected to SESAR</strong> with your name and
			code.</li>
		</ol>

		<?php ih_fig('connected', 924, 'Connected to SESAR panel', 'When every step has a check mark, you are ready to register IGSNs.'); ?>

		<p><strong>Refresh from SESAR</strong> re-reads your SESAR account (for example after you add a
		second SESAR code at SESAR). <strong>Disconnect</strong> removes StraboSpot's access to your SESAR
		account; you can connect again at any time, and nothing at SESAR changes.</p>

		<p>If your connection expires, the panel asks you to <strong>Reconnect with ORCID</strong>. If you
		click a SESAR action anywhere before connecting, StraboSpot offers <strong>Connect SESAR</strong>
		first; after connecting (even in another tab), just click the action again.</p>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="page">3. The IGSNs page</h2>

		<p>The IGSNs page has two tabs:</p>
		<ul>
			<li><strong>My samples</strong>: every sample you own in StraboSamples, with its IGSN (if any) and
			how it stands with SESAR. This is where you register, send and pull.</li>
			<li><strong>Import from SESAR</strong>: samples that exist at SESAR but not yet in StraboSamples.
			See <a href="#import">Import samples from SESAR</a>.</li>
		</ul>

		<p>The collapsible <strong>What the buttons and IGSN states mean</strong> box on the page is a quick
		reminder of everything below.</p>

		<h3>Finding samples</h3>
		<ul>
			<li><strong>Search</strong> matches a sample's name, ID or IGSN.</li>
			<li><strong>IGSN state</strong> narrows the list to one state, such as <em>No IGSN yet</em> or
			<em>Changed since last sent to SESAR</em>.</li>
			<li><strong>Location</strong> shows only samples that have, or are missing, a location.</li>
			<li><strong>Ready for an IGSN</strong> is a shortcut for "no IGSN yet and has a location": the
			samples you can register right now.</li>
			<li>Click a column heading to sort by it; click again to reverse.</li>
		</ul>

		<?php ih_fig('ready_filter', 924, 'Search, filters and the Ready for an IGSN button', 'Ready for an IGSN lists the samples SESAR will accept now.'); ?>

		<h3>Acting on samples</h3>
		<p>Tick samples, then pick an action in the bar above the list. Each button shows how many of the
		ticked samples it applies to, and is greyed out when none do. Once you tick something, the bar stays
		pinned under the site menu while you scroll. Most actions take up to 100 samples at a time.</p>

		<?php ih_fig('selection_bar', 924, 'Three samples ticked, with the action buttons showing counts', 'Three samples ticked. Register IGSNs (3) counts the ones without an IGSN.'); ?>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="register">4. Register IGSNs</h2>

		<p>You can register from two places:</p>
		<ul>
			<li><strong>One sample:</strong> open the sample's page and click <strong>Register IGSN</strong>.</li>
			<li><strong>Many samples:</strong> on the IGSNs page, tick samples and click
			<strong>Register IGSNs</strong>.</li>
		</ul>

		<?php ih_fig('overview_register_button', 1093, 'Register IGSN button on a sample page', 'The Register IGSN button on a sample\'s page.'); ?>

		<p>Either way, a review opens first. <strong>Nothing is sent to SESAR until you click the Register
		button at the bottom of the review.</strong></p>

		<?php ih_fig('register_review', 980, 'The Register IGSNs at SESAR review', 'The review: choices for the whole run at the top, one row per sample below.'); ?>

		<h3>What the review asks</h3>
		<ul>
			<li><strong>SESAR code</strong>: which of your codes the new IGSNs start with (most people have
			one).</li>
			<li><strong>Collector</strong>: filled in with your name. Change it if someone else collected the
			samples.</li>
			<li><strong>SESAR object type</strong> (required, per sample): what kind of object it is, from
			SESAR's list, for example <em>Rock hand sample</em>, <em>Thin section</em>, <em>Core</em> or
			<em>Mineral separate</em>. StraboSpot suggests one; check it.</li>
			<li><strong>Material</strong> (optional, per sample): start typing and pick from SESAR's list, for
			example <em>Granite</em> or <em>Zircon</em>. Leave it blank if nothing fits; SESAR only accepts
			names from its list.</li>
			<li>With several samples, the <strong>for all checked samples</strong> boxes let you set the object
			type or material for every ticked row at once.</li>
		</ul>

		<h3>The three groups</h3>
		<ul>
			<li><strong>Ready to register</strong>: ticked, and will be registered.</li>
			<li><strong>Needs your OK</strong>: the sample's IGSN field already holds something that is not a
			registered IGSN (a placeholder, a typo, or an IGSN that SESAR deactivated). These start unticked.
			Tick one to register a real IGSN in its place; the old value is kept in the sample's history.</li>
			<li><strong>Cannot be registered</strong>, with the reason. The usual ones: the sample has no
			<strong>location</strong> (SESAR requires a latitude and longitude) or no name, or it already has a
			SESAR IGSN (use <a href="#pull">Pull from SESAR</a> to link that one instead).</li>
		</ul>

		<h3>Parents and children</h3>
		<p>If a sample has children (splits, thin sections, separates) or a parent without an IGSN, the
		review adds them for you, tagged <span class="ih-pill">parent</span> or
		<span class="ih-pill">child</span>. Untick any you do not want yet. Parents are always registered
		first, and each child's SESAR record is linked to its parent's IGSN. A child is never registered
		without its parent: if the parent fails, the child is skipped so it does not lose its parent
		link.</p>

		<h3>Running it</h3>
		<p>Click <strong>Register</strong>. Progress shows sample by sample; keep the window open until it
		finishes. <strong>Stop</strong> finishes the sample in progress and stops there. At the end you see
		each new IGSN and a summary.</p>

		<?php ih_fig('register_done', 980, 'Registration results', 'Five IGSNs registered, parent first. The sample without a location was left out.'); ?>

		<p>If SESAR does not answer, simply run the registration again: StraboSpot checks SESAR first, so a
		sample is never registered twice.</p>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="after">5. After registering</h2>

		<p>The new IGSN is written into the sample's IGSN field (and into the StraboField spot, for samples
		that came from StraboField). On the sample's page the IGSN becomes a link to its SESAR page.</p>

		<?php ih_fig('metadata_igsn', 742, 'Sample metadata with the IGSN link', 'The IGSN links to the sample\'s page at SESAR.'); ?>

		<p>A <strong>SESAR record</strong> card also appears on the sample's page. It shows the record as
		SESAR had it the last time StraboSpot read it, with an <strong>Open at SESAR</strong> link.</p>

		<?php ih_fig('sesar_record_card', 1093, 'The SESAR record card on a sample page', 'The SESAR record card. Request deactivation is covered in section 11.'); ?>

		<p>On the IGSNs page, the sample's state is now <span class="ih-pill ok">Managed here</span>: StraboSpot
		can keep its SESAR record up to date. My Samples also shows the IGSN on the sample's card.</p>

		<?php ih_fig('my_samples_card', 1160, 'A My Samples card showing the IGSN', 'My Samples cards show the IGSN, linked to SESAR.'); ?>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="send">6. Send your edits to SESAR</h2>

		<p>When you edit a registered sample in StraboSamples (its name, description, location, purpose,
		collection date or parent), SESAR does not change by itself. StraboSpot notices the difference and
		tells you. <strong>Nothing is sent until you choose to.</strong></p>

		<?php ih_fig('send_notice', 1045, 'Changed since last sent to SESAR notice', 'On the sample\'s page, a notice lists what changed.'); ?>

		<p>Click <strong>Send to SESAR</strong> (on the sample's page, or for many samples at once on the
		IGSNs page, where changed samples carry a <span class="ih-pill bad">Changed since sent</span> tag).
		The review shows exactly which fields will change at SESAR; everything else stays as it is.</p>

		<?php ih_fig('send_dialog', 980, 'The Send to SESAR review', 'Only the fields listed under Changes to send change at SESAR.'); ?>

		<h3>If the record was also changed at SESAR</h3>
		<p>Before sending, StraboSpot reads the record at SESAR. If someone changed a field there since
		StraboSpot last read it, that field is marked <em>changed at SESAR</em> and sending is blocked, so
		their edit is not overwritten. Use <a href="#pull">Pull from SESAR</a> first, then send.</p>

		<h3>What is never sent</h3>
		<ul>
			<li>Empty fields. A value you cleared in StraboSamples is never cleared at SESAR.</li>
			<li>The object type, material, SESAR code and collectors. Change those at SESAR if needed.</li>
			<li>Anything, automatically. Sending only ever happens when you click Send.</li>
		</ul>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="pull">7. Pull from SESAR</h2>

		<p><strong>Pull from SESAR</strong> copies values from a sample's SESAR record into the sample. Use it
		when:</p>
		<ul>
			<li>you (or a colleague) edited the record at SESAR, or</li>
			<li>a sample's IGSN was typed in by hand, or came from elsewhere. Pulling <strong>links</strong> the
			IGSN, so StraboSpot can manage it from then on.</li>
		</ul>

		<p>The review lists the sample's values next to SESAR's. Tick the SESAR values you want:</p>
		<ul>
			<li><span class="ih-pill ok">fills an empty field</span>: ticked for you.</li>
			<li><span class="ih-pill warn">replaces</span>: SESAR's value differs from yours. Unticked; tick
			it only if SESAR is right.</li>
			<li><span class="ih-pill">same</span>: nothing to do.</li>
		</ul>

		<?php ih_fig('pull_dialog', 980, 'The Pull from SESAR review', 'SESAR\'s description fills an empty field; its material would replace the sample\'s, so that one starts unticked.'); ?>

		<p>Locations less than 10 m apart count as the same. <strong>Everything SESAR has for this
		sample</strong> expands to show the full record.</p>

		<p><strong>Samples from StraboField:</strong> the StraboField app is in charge of their location,
		material and purpose, so a pull never changes those. Any differences are listed instead, and the
		sample shows <span class="ih-pill warn">Differs from Field</span> on the IGSNs page. Fix whichever side
		is wrong.</p>

		<p>On the IGSNs page, <strong>Pull from SESAR</strong> works on many samples at once: it fills empty
		fields only, unless you tick <em>Also replace values that differ from SESAR</em>.</p>

		<h3>Records owned by another SESAR account</h3>
		<p>You can link a colleague's public IGSN to your sample. It is linked
		<span class="ih-pill">read-only</span>: you can pull from it, but not send changes to it or ask for it
		to be deactivated.</p>

		<h3>Unlink from SESAR</h3>
		<p>An IGSN can be linked to only one of your samples. If you linked it to the wrong one with a pull,
		click <strong>Unlink from SESAR</strong> on that sample's SESAR record card, then pull on the right
		sample. Unlinking changes nothing at SESAR or in the sample's values. (An IGSN registered from a
		sample through StraboSpot stays with that sample and cannot be unlinked.)</p>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="import">8. Import samples from SESAR</h2>

		<p>If you already have samples at SESAR (registered at SESAR directly, or long ago), the
		<strong>Import from SESAR</strong> tab brings them into StraboSamples. Each one becomes a new sample,
		filled in from its SESAR record (name, description, location, material, purpose) and linked to it.</p>

		<h3>From your SESAR account</h3>
		<p>The tab lists every sample in your SESAR account. Type a name or IGSN in the search box and press
		Enter to narrow the list. Samples already in StraboSamples show which sample holds them; tick the
		others and click <strong>Create samples</strong>. Parents are created before their children and
		linked to them.</p>

		<?php ih_fig('import_tab', 924, 'The Import from SESAR tab', 'Your SESAR account\'s samples. These five are already in StraboSamples.'); ?>

		<h3>Paste a list of IGSNs</h3>
		<p>Quicker when you already have the IGSNs, for example in a spreadsheet. Click <strong>Paste a list
		of IGSNs</strong>, paste them one per line (commas and spaces work too), and click <strong>Check
		with SESAR</strong>. You see what will be created before anything happens. This works for any public
		IGSN, including samples registered by colleagues; those are linked read-only.</p>

		<?php ih_fig('paste_list', 980, 'The paste a list of IGSNs dialog', 'Paste up to 100 IGSNs at a time.'); ?>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="batch">9. SESAR batch file</h2>

		<p>Some labs register through SESAR's own batch upload. <strong>SESAR batch file</strong> fills your
		samples into a SESAR spreadsheet template for you, so you do not retype them.</p>

		<?php ih_fig('batch_file', 760, 'The SESAR batch upload file dialog', 'The three steps are listed in the dialog.'); ?>

		<ol class="ih-steps">
			<li>At SESAR, open the <strong>Batch Template Creator</strong>, make a template with your SESAR
			code, choose to enter <strong>Object Type</strong> per sample, and click <strong>Generate
			Spreadsheet</strong>.</li>
			<li>On the IGSNs page, tick the samples, click <strong>SESAR batch file</strong>, and choose that
			template. StraboSpot fills in the samples and gives you the file back (nothing else in it
			changes). Up to 4,999 samples per file.</li>
			<li>Upload the filled file at SESAR. SESAR assigns the IGSNs right away; its curators review the
			batch before the records go public.</li>
			<li>Back on the IGSNs page, click <strong>Find my batch IGSNs</strong>. StraboSpot finds the new
			IGSNs (each row carries "StraboSpot" and the sample's ID in SESAR's <em>Other Name(s)</em> column)
			and adds each one to its sample. Drafts still waiting for review start unticked.</li>
		</ol>

		<p>This route does not need a SESAR connection until the last step. Without one, you can add the
		IGSNs with a spreadsheet <strong>Import</strong> on My Samples instead.</p>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="reports">10. Reports</h2>

		<ul>
			<li><strong>Download report</strong> (My samples tab): an Excel or CSV file with one row per
			sample: its IGSN and links, its StraboSamples values, its SESAR values, and whether the two are in
			step. It covers the ticked samples, or every sample with an IGSN that the list currently shows.
			<em>Refresh from SESAR first</em> reads SESAR now and flags anything changed there; it never
			changes your samples.</li>
			<li><strong>Download account report</strong> (Import from SESAR tab): every sample in your SESAR
			account, drafts included, and whether each one is in StraboSamples.</li>
		</ul>

		<?php ih_fig('report_dialog', 620, 'The Download IGSN report dialog', 'The IGSN report.'); ?>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="deactivate">11. Retire an IGSN (deactivation)</h2>

		<p>An IGSN is never deleted. If one should not exist (a test, a duplicate, a sample that does not
		exist), you can ask SESAR to <strong>deactivate</strong> it. A deactivated IGSN still resolves, to a
		page saying it was deactivated, and it leaves SESAR's catalog search.</p>

		<ol class="ih-steps">
			<li>Open the sample's page and click <strong>Request deactivation</strong> on its SESAR record
			card.</li>
			<li>Choose a reason and type the IGSN to confirm, then click <strong>Request
			deactivation</strong>.</li>
			<li>A SESAR curator reviews the request and emails you the decision. Meanwhile the sample shows
			<span class="ih-pill warn">Deactivation requested</span> and changes are not sent to it.
			<strong>Check with SESAR now</strong> shows the decision; StraboSpot also checks every night.</li>
			<li>Once approved, the IGSN is removed from the sample (it stays in the sample's history) and you
			may register a new one. If SESAR declines, the IGSN stays active.</li>
		</ol>

		<?php ih_fig('deactivation_dialog', 640, 'The Request deactivation dialog', 'Opening the dialog changes nothing; only the Request deactivation button sends the request.'); ?>

		<p>If you already asked SESAR directly, the dialog says SESAR is not taking a new request and offers
		<strong>Show as requested</strong>, so StraboSpot stops sending changes; you can take that back with
		<strong>Show as active again</strong>.</p>

		<p><strong>Deleted samples:</strong> if a sample that has an IGSN is deleted (for example, removed
		from its StraboField project), the IGSN stays live at SESAR. The IGSNs page lists these under <em>IGSNs whose sample was deleted</em>, where you can request
		deactivation or click <strong>Keep</strong> if the specimen still exists.</p>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="states">12. What the IGSN states mean</h2>

		<?php ih_fig('list_states', 924, 'The My samples list showing several states', 'Registered samples show Managed here; the others have no IGSN yet.'); ?>

		<table class="ih-table">
			<thead><tr><th>State</th><th>Meaning</th><th>What to do</th></tr></thead>
			<tbody>
				<tr><td><span class="ih-pill">No IGSN</span></td><td>Nothing registered yet.</td>
					<td>Register IGSNs, or use a SESAR batch file.</td></tr>
				<tr><td><span class="ih-pill ok">Managed here</span></td><td>Linked to its SESAR record; StraboSpot can keep
					that record up to date.</td><td>Nothing, unless it also shows <em>Changed since sent</em>.</td></tr>
				<tr><td><span class="ih-pill bad">Changed since sent</span></td><td>Edited in StraboSamples since the
					last send.</td><td>Send to SESAR when you are ready.</td></tr>
				<tr><td><span class="ih-pill">No link back</span></td><td>The SESAR record does not link back to the
					sample's StraboSpot page yet.</td><td>Send to SESAR adds it.</td></tr>
				<tr><td><span class="ih-pill">SESAR, not managed here</span></td><td>The IGSN field holds a SESAR IGSN that
					is not linked yet (typed in by hand, for example).</td><td>Pull from SESAR links it.</td></tr>
				<tr><td><span class="ih-pill">read-only</span></td><td>Linked to a record another SESAR account
					owns.</td><td>You can pull from it; changes go through its owner.</td></tr>
				<tr><td><span class="ih-pill warn">Differs from Field</span></td><td>The sample comes from StraboField and
					SESAR disagrees on location, material or purpose.</td><td>Fix whichever side is wrong.</td></tr>
				<tr><td><span class="ih-pill warn">Deactivation requested</span></td><td>A SESAR curator is reviewing your
					request.</td><td>Wait for SESAR's email, or Check with SESAR now.</td></tr>
				<tr><td><span class="ih-pill bad">Deactivated at SESAR</span></td><td>SESAR retired this IGSN.</td>
					<td>Register a new IGSN, or clear the field.</td></tr>
				<tr><td><span class="ih-pill">Other DOI prefix</span></td><td>The field holds a DOI from another
					registry (some SESAR teams use their own prefix).</td><td>Usually nothing; StraboSpot asks SESAR
					before acting on it.</td></tr>
				<tr><td><span class="ih-pill">Not a valid IGSN</span></td><td>The field holds text that is not an IGSN.
					It is never sent to SESAR.</td><td>Correct it, or register a real IGSN in its place.</td></tr>
				<tr><td><span class="ih-pill warn">Missing</span> (Location)</td><td>The sample has no location.</td>
					<td>Add one before registering; SESAR requires it.</td></tr>
			</tbody>
		</table>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="public">13. Who can see a registered sample</h2>

		<p>SESAR's record for an IGSN is public, and it links back to the sample's page in StraboSpot. So that
		link works for everyone, <strong>a sample you register through StraboSpot gets a public page</strong>
		at StraboSpot, even if its project is private.</p>
		<ul>
			<li>That public page shows only the basics SESAR already publishes: the sample's name, IGSN,
			location, description, material and purpose, plus its parent or children if those are public
			too.</li>
			<li>Notes, custom fields, StraboField, StraboMicro and StraboExperimental data, private projects,
			collaborators and the change history stay private.</li>
			<li>Linking an existing IGSN with Pull or Import does not make a sample public; only registering
			one through StraboSpot does.</li>
			<li>A deactivated IGSN no longer makes the sample public.</li>
		</ul>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="best">14. Best practices</h2>

		<ul>
			<li><strong>Register real, finished samples.</strong> An IGSN is permanent and public. Register
			when the name, location and description are right, not while a sample is still a placeholder.
			Never register test samples; the most you can do afterwards is deactivate them.</li>
			<li><strong>Get the location right first.</strong> SESAR requires one, publishes it, and people
			will use it to find the sample. Use <em>Ready for an IGSN</em> to see which samples qualify, and the
			<em>Missing</em> location filter to find the rest.</li>
			<li><strong>Build the family before you register.</strong> Set each sample's parent in StraboSamples
			first; registration then links children to their parents at SESAR for you. Registering a parent
			and its children together is easiest.</li>
			<li><strong>Use meaningful names.</strong> The sample name is the record's title at SESAR. Your
			field or lab number (for example <code>PP-24-001A</code>) is ideal.</li>
			<li><strong>Write descriptions for strangers.</strong> The description is public. Say what the
			sample is and where it came from; keep private remarks in Notes, which are never sent.</li>
			<li><strong>Choose the object type carefully.</strong> A hand sample, a thin section cut from it
			and a mineral separate are different objects: give each its own sample, its own object type and
			its own IGSN, linked as parent and children.</li>
			<li><strong>Edit in one place.</strong> Make changes in StraboSamples and use Send to SESAR. If you
			must edit at SESAR, Pull afterwards so both sides agree.</li>
			<li><strong>Do not type IGSNs by hand</strong> when you can avoid it. Pull, Import and Find my batch
			IGSNs link the IGSN properly; a hand-typed one only shows as <em>SESAR, not managed here</em> until
			you pull it.</li>
			<li><strong>For StraboField samples,</strong> correct the location, material or purpose in the
			StraboField app. StraboSpot never overwrites those from SESAR.</li>
			<li><strong>Download a report now and then.</strong> It is an easy way to spot samples that
			changed on either side or are out of step with StraboField.</li>
			<li><strong>Cite the IGSN</strong> in papers and data sets as its DOI link, for example
			<code>https://doi.org/10.58052/IEJMA0010</code>.</li>
		</ul>

		<p class="ih-top"><a href="#main">Back to top</a></p>

		<!-- ================================================================ -->
		<h2 id="faq">15. Troubleshooting and questions</h2>

		<dl class="ih-faq">
			<dt>A sample is under "Cannot be registered: It has no location."</dt>
			<dd>Open the sample, click Edit Metadata, add a latitude and longitude, save, and register again.</dd>

			<dt>The panel is waiting on "API access" for days.</dt>
			<dd>SESAR reviews API requests by hand. Write to <a href="mailto:info@geosamples.org">info@geosamples.org</a>
			if you have heard nothing after a few days.</dd>

			<dt>"Please reconnect to SESAR."</dt>
			<dd>Your SESAR connection expired or was revoked at SESAR. Click Reconnect with ORCID; nothing is
			lost.</dd>

			<dt>The material I typed is highlighted in red.</dt>
			<dd>SESAR only accepts materials from its own list. Pick one from the suggestions, or leave it
			blank.</dd>

			<dt>Send to SESAR is blocked with "changed at SESAR".</dt>
			<dd>Someone edited the record at SESAR since StraboSpot last read it. Pull from SESAR first, then
			send.</dd>

			<dt>"An IGSN can be linked to only one sample."</dt>
			<dd>The IGSN is already linked to another of your samples. Open that one, click Unlink from SESAR on
			its SESAR record card, then pull on the right sample.</dd>

			<dt>A registration stopped halfway or SESAR did not answer.</dt>
			<dd>Run it again. StraboSpot checks SESAR first, so nothing is registered twice.</dd>

			<dt>I registered something by mistake.</dt>
			<dd>Request deactivation on the sample's SESAR record card (section 11).</dd>

			<dt>Can I change the object type or material after registering?</dt>
			<dd>Yes, at SESAR. Open the record with Open at SESAR and edit it there, then Pull from SESAR to
			refresh StraboSpot's copy.</dd>
		</dl>

		<div class="ih-note">
			<p>Questions about StraboSpot: <a href="mailto:strabospot@gmail.com?subject=IGSN%20question">strabospot@gmail.com</a>.
			Questions about your SESAR account, codes or records: <a href="mailto:info@geosamples.org">info@geosamples.org</a>.</p>
		</div>

		</div>
	</div>
</div>

<?php
include("includes/mfooter.php");
?>
