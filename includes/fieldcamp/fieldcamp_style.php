<?php
/**
 * File: includes/fieldcamp/fieldcamp_style.php
 * Description: Shared styles of the Field Camp Wizard pages. Palette follows
 *              the site-wide dark-theme conventions (see my_samples.php):
 *              translucent-white surfaces, #2a2a3a panels, #e44c65 accent.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
?>
<style>
.fc-intro { max-width: 720px; margin: 0 auto 1.5em auto; text-align: center; color: rgba(255,255,255,0.8); }
.fc-flash { background: rgba(80,180,120,0.12); border: 1px solid rgba(80,180,120,0.45); border-radius: 4px;
	padding: 0.6em 1em; margin-bottom: 1.25em; }
.fc-flash-err { background: rgba(228,76,101,0.12); border: 1px solid rgba(228,76,101,0.4); border-radius: 4px;
	padding: 0.6em 1em; margin-bottom: 1.25em; }
.fc-flash-err ul, .fc-flash ul { margin: 0; padding-left: 1.2em; }
.fc-top { display: flex; flex-wrap: wrap; gap: 0.75em; align-items: center; justify-content: space-between;
	margin-bottom: 1.25em; }
.fc-list { display: flex; flex-direction: column; gap: 0.9em; }
.fc-camp { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.12); border-radius: 6px;
	padding: 1em 1.25em; display: flex; flex-wrap: wrap; gap: 0.5em 1.5em; align-items: center;
	justify-content: space-between; }
.fc-camp-name { font-weight: 600; font-size: 1.1em; color: #ffffff; }
.fc-meta { color: rgba(255,255,255,0.6); font-size: 0.88em; margin-top: 0.25em; }
.fc-meta span + span:before { content: " \00b7 "; color: rgba(255,255,255,0.3); }
.fc-pill { display: inline-block; font-size: 0.72em; text-transform: uppercase; letter-spacing: 0.06em;
	padding: 0.2em 0.6em; border-radius: 3px; border: 1px solid rgba(255,255,255,0.25);
	color: rgba(255,255,255,0.8); margin-left: 0.6em; vertical-align: middle; }
.fc-pill-ready { border-color: #5cb87a; color: #5cb87a; }
.fc-pill-failed { border-color: #f06880; color: #f06880; }
.fc-pill-off { border-color: rgba(255,255,255,0.2); color: rgba(255,255,255,0.45); }
.fc-empty { text-align: center; color: rgba(255,255,255,0.65); padding: 2.5em 1em;
	background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; }
.fc-steps { display: flex; flex-wrap: wrap; gap: 0.5em; margin: 0 0 1.5em 0; padding: 0; list-style: none; }
.fc-steps li { margin: 0; padding: 0; }
.fc-steps a, .fc-steps span { display: block; padding: 0.45em 1em; border-radius: 4px;
	border: 1px solid rgba(255,255,255,0.2); color: rgba(255,255,255,0.8); text-decoration: none; }
.fc-steps a:hover { background: rgba(255,255,255,0.08); }
.fc-steps .fc-step-on { background: rgba(228,76,101,0.25); border-color: #e44c65; color: #ffffff; }
.fc-steps .fc-step-wait { color: rgba(255,255,255,0.35); border-style: dashed; }
.fc-panel { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); border-radius: 6px;
	padding: 1.25em 1.5em; margin-bottom: 1.5em; }
.fc-panel h4 { margin-top: 0; }
.fc-field { margin-bottom: 1em; }
.fc-field label { display: block; font-weight: bold; margin-bottom: 0.25em; color: #ffffff; }
.fc-field .fc-hint { color: rgba(255,255,255,0.6); font-size: 0.88em; margin-top: 0.25em; }
.fc-field input[type=text], .fc-field input[type=number], .fc-field input[type=date] {
	width: 100%; max-width: 460px; box-sizing: border-box; background: rgba(255,255,255,0.08);
	border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: #ffffff; padding: 0.6em 0.8em;
	font-size: 1em; }
.fc-field input.fc-short { max-width: 160px; }
.fc-field input:focus { border-color: #e44c65; outline: none; }
.fc-row { display: flex; flex-wrap: wrap; gap: 0 1.5em; }
.fc-buttons { display: flex; flex-wrap: wrap; gap: 0.6em; align-items: center; margin-top: 0.5em; }
.fc-map { height: 520px; border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; margin-bottom: 0.75em; }
.fc-mapnote { color: rgba(255,255,255,0.75); margin-bottom: 0.75em; }
.fc-table td { padding: 0.35em 0.6em; }
.fc-preview { max-height: 220px; overflow-y: auto; background: rgba(0,0,0,0.25); border-radius: 4px;
	padding: 0.6em 0.9em; margin: 0.5em 0 1em 0; font-family: monospace; font-size: 0.9em; max-width: 460px; }
.fc-preview .fc-taken { color: #f06880; }
.fc-preview-msg { color: rgba(255,255,255,0.7); font-size: 0.9em; }
.fc-preview-msg.fc-bad { color: #f06880; }
.fc-danger { border-color: rgba(228,76,101,0.5); }
.fc-small { font-size: 0.88em; color: rgba(255,255,255,0.65); }
.fc-units { display: flex; flex-direction: column; }
.fc-unit { display: grid; grid-template-columns: 2.2em 1.8em 6.5em minmax(12em, 2fr) minmax(8em, 1fr) minmax(8em, 1.3fr);
	gap: 0 0.75em; align-items: center; padding: 0.3em 0; border-top: 1px solid rgba(255,255,255,0.08); }
/* The size sits on the cells: on the row it would shrink the em-based columns out of line with the rows below. */
.fc-unit-head { border-top: none; }
.fc-unit-head div { font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.5); }
.fc-unit input[type=text] { width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.08);
	border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: #ffffff; padding: 0.35em 0.6em;
	font-size: 0.95em; height: auto; line-height: 1.4; }
.fc-unit input[type=text]:focus { border-color: #e44c65; outline: none; }
.fc-unit input[type=checkbox] + label { margin: 0; padding-left: 1.6em; height: 1.4em; }
.fc-tileline { display: grid; grid-template-columns: 2.2em minmax(12em, 1fr) 12em; gap: 0 0.75em; align-items: center;
	padding: 0.3em 0; border-top: 1px solid rgba(255,255,255,0.08); }
.fc-zoomline { display: grid; grid-template-columns: 2.2em 4em 8em 8em 8em 1fr; gap: 0 0.75em; align-items: center;
	padding: 0.3em 0; border-top: 1px solid rgba(255,255,255,0.08); }
.fc-tileline input[type=checkbox] + label, .fc-zoomline input[type=radio] + label { margin: 0; padding-left: 1.6em; height: 1.4em; }
.fc-maphead { font-weight: bold; color: #ffffff; margin: 1.25em 0 0.4em 0; }
.fc-mapline { display: grid; grid-template-columns: 2.2em minmax(12em, 1.4fr) minmax(10em, 1.6fr) 7em;
	gap: 0 0.75em; align-items: center; padding: 0.3em 0; border-top: 1px solid rgba(255,255,255,0.08); }
.fc-mapline input[type=text] { width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.08);
	border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: #ffffff; padding: 0.35em 0.6em;
	font-size: 0.95em; height: auto; line-height: 1.4; }
.fc-mapline input[type=text]:focus { border-color: #e44c65; outline: none; }
.fc-mapline input[type=checkbox] + label { margin: 0; padding-left: 1.6em; height: 1.4em; }
.fc-unit-text { font-size: 0.9em; color: rgba(255,255,255,0.75); overflow-wrap: anywhere; }
.fc-unit-off .fc-unit-text, .fc-unit-off input[type=text] { opacity: 0.45; }
.fc-chip { display: inline-block; width: 1.4em; height: 1.4em; border-radius: 3px; vertical-align: middle;
	border: 1px solid rgba(255,255,255,0.35); background: transparent; }
@media (max-width: 900px) {
	.fc-unit { grid-template-columns: 2.2em 1.8em 6em 1fr; }
	.fc-unit-head div:nth-child(n+5) { display: none; }
	.fc-unit .fc-unit-text { grid-column: 3 / -1; }
}
</style>
