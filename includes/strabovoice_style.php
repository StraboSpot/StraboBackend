<?php
/**
 * File: strabovoice_style.php
 * Description: Shared page CSS for the public Strabo Voice trial pages
 *              (strabovoice_terms.php, strabovoice_notes.php): the site's dark
 *              palette on screen, black on white without the site chrome when
 *              printed (the tester notes are meant to be carried on one sheet).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
?>
<style>
	.sv-page { max-width: 46em; margin: 0 auto; }
	.sv-page p, .sv-page li { color: rgba(255, 255, 255, 0.85); line-height: 1.65; }
	.sv-page h3 { color: #ffffff; font-size: 1.25em; margin: 1.6em 0 0.5em; }
	.sv-page ul, .sv-page ol { margin-bottom: 0.6em; }
	.sv-page li { margin-bottom: 0.45em; }
	.sv-page .sv-lead { font-size: 1.08em; color: rgba(255, 255, 255, 0.9); }
	.sv-page .sv-closing { font-weight: 600; margin-top: 1.4em; }
	.sv-page .sv-meta { color: rgba(255, 255, 255, 0.6); font-size: 0.9em; margin-top: 2em; }
	.sv-page .sv-example { background: rgba(255, 255, 255, 0.08); border-left: 3px solid #e44c65; padding: 0.6em 1em; margin: 0.6em 0 0.8em; color: #ffffff; font-style: italic; }
	.sv-page a { color: #e44c65; }
	.sv-page a:hover { color: #f06880; }
	.sv-page .sv-print { margin-top: 1.5em; }
	@media print {
		#header, #footer, .sv-print, header.major:after { display: none !important; }
		html, body, #page-wrapper, #main, .wrapper, .container { background: #ffffff !important; color: #000000 !important; padding: 0 !important; margin: 0 !important; }
		header.major h2, .sv-page h3, .sv-page p, .sv-page li, .sv-page b, .sv-page .sv-lead, .sv-page .sv-meta { color: #000000 !important; }
		header.major h2 { font-size: 16pt; margin: 0 0 6pt; }
		.sv-page { max-width: none; font-size: 9.5pt; }
		.sv-page h3 { font-size: 11pt; margin: 9pt 0 3pt; }
		.sv-page li { margin-bottom: 2pt; line-height: 1.35; }
		.sv-page p { line-height: 1.35; }
		.sv-page .sv-example { background: none; border-left: 2pt solid #000000; color: #000000; }
		.sv-page a { color: #000000; text-decoration: underline; }
		.sv-page b { font-weight: 700; }
	}
</style>
