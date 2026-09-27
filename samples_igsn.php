<?php
/**
 * File: samples_igsn.php
 * Description: "IGSNs at SESAR" (StraboSamples IGSN integration, D4). Gated
 *              by SesarAccess::canUse (soft launch).
 *
 *              Top: the SESAR connection panel. SESAR makes new users clear
 *              up to three hurdles before its API works for them (a SESAR
 *              account, API permission that SESAR staff grant BY HAND, a
 *              SESAR code), so the panel walks through them one at a time
 *              with plain instructions, files the API permission request for
 *              the user, creates the SESAR code in place, and re-checks by
 *              itself when the user comes back to the tab
 *              (SesarOnboarding, sesar_connect.php).
 *
 *              Below, two tabs:
 *              - My samples: the user's OWN samples (owner-only, D3) with
 *                their IGSN state. Tick samples, then "Register IGSNs" opens
 *                the mint review (assets/js/sesar_mint.js, sesar_mint.php,
 *                D4) or "Pull from SESAR" fills them from their SESAR records
 *                (assets/js/sesar_pull.js, sesar_pull.php, D5). "Create
 *                samples from IGSNs" takes a pasted list.
 *              - Import from my SESAR account: the account's own SESAR
 *                samples, page by page, each marked when a StraboSamples
 *                sample already holds it; tick to create samples (D5).
 *              Phase 7: "Deactivation requested" / "Deactivated at SESAR"
 *              pills + filters, and "IGSNs whose sample was deleted" (orphan
 *              rows: Request deactivation or Keep; assets/js/sesar_deactivate.js).
 *              "Send to SESAR" pushes changed managed samples
 *              (assets/js/sesar_push.js, sesar_push.php, Phase 6); the
 *              "Changed since sent" pill compares each managed sample with
 *              its stored SESAR snapshot, no SESAR call (P1).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/includes/sesar/SesarOnboarding.php";
require_once __DIR__ . "/includes/sesar/SesarMapper.php";
require_once __DIR__ . "/includes/sesar/SesarPush.php";
require_once __DIR__ . "/includes/sesar/SesarDeactivate.php";

$allowed = SesarAccess::canUse($userpkey);
$configured = SesarAccess::isConfigured();
$status = null;
$rows = array();

if ($allowed && $configured) {
	$client = new SesarClient();
	$status = (new SesarOnboarding($db, $client, new SesarConnection($db, $client)))->status($userpkey);   // no SESAR call
	$status['dev_code_paste'] = SesarAccess::devCodePaste();

	$res = $db->get_results_prepared(
		"SELECT s.id, s.userpkey, s.name, s.igsn, s.latitude, s.longitude, s.modified_at,
		        r.igsn AS reg_igsn, r.state AS reg_state, r.access AS reg_access, r.field_flags::text AS field_flags,
		        r.pkey AS reg_pkey, r.origin AS reg_origin, r.snapshot::text AS reg_snapshot,
		        r.related_resource_id AS reg_rr, r.sesar_sample_id AS reg_sesar_id,
		        (SELECT g.igsn FROM strabosamples.sesar_registrations g
		          WHERE g.sample_id = s.id AND g.sample_userpkey = s.userpkey AND g.environment = $2 AND g.state = 'deactivated'
		          ORDER BY g.deactivated_at DESC NULLS LAST LIMIT 1) AS gone_igsn
		   FROM strabosamples.samples s
		   LEFT JOIN strabosamples.sesar_registrations r
		          ON r.sample_id = s.id AND r.sample_userpkey = s.userpkey
		         AND r.active AND r.environment = $2
		  WHERE s.userpkey = $1
		  ORDER BY s.modified_at DESC NULLS LAST, s.id",
		array($userpkey, SesarAccess::environment())
	);
	// Push status per managed row (P1: sample vs snapshot; ~2 ms each, no SESAR call).
	$conn = new SesarConnection($db, $client);
	$views = new SesarSampleView($db, $neodb);
	$push = new SesarPush($db, $client, $conn, $views,
		new SesarMint($db, $client, $conn, new SesarVocab($db, $client), $views, null),
		new SesarPull($db, $client, $conn, $views, $neodb));
	$orphans = (new SesarDeactivate($db, $client, $conn))->orphans($userpkey);   // no SESAR call
	foreach ((is_array($res) ? $res : array()) as $r) {
		$pushSt = null;
		if ($r->reg_igsn !== null && $r->reg_access === 'managed') {
			$v = $views->build((string)$r->id, (int)$r->userpkey);
			if ($v !== null) {
				try {
					$pushSt = $push->statusFor($v, (object)array('pkey' => $r->reg_pkey, 'igsn' => $r->reg_igsn, 'state' => $r->reg_state,
						'origin' => $r->reg_origin, 'access' => $r->reg_access, 'snapshot' => $r->reg_snapshot,
						'related_resource_id' => $r->reg_rr, 'sesar_sample_id' => $r->reg_sesar_id));
				} catch (Throwable $e) { $pushSt = null; }
			}
		}
		$igsn = trim((string)$r->igsn);
		$cls = SesarMapper::classifyIgsn($igsn);
		if ($igsn === '') $state = 'none';
		elseif ($r->reg_igsn !== null) $state = 'managed';
		elseif ($cls['kind'] === 'sesar') $state = 'unmanaged';
		elseif ($cls['kind'] === 'doi') $state = 'other';
		else $state = 'invalid';
		$flags = ($r->field_flags !== null) ? json_decode($r->field_flags, true) : null;
		$rows[] = array(
			'id'     => (string)$r->id,
			'owner'  => (int)$r->userpkey,
			'name'   => (string)$r->name,
			'igsn'   => $igsn,
			'state'  => $state,
			'reg'    => $r->reg_state,
			'readonly' => $r->reg_access === 'readonly',
			// Field vs SESAR differences as of the last pull (D5): labels only.
			'differs'  => is_array($flags) ? array_values(array_map(function ($f) { return (string)$f['label']; }, $flags)) : array(),
			'hasLoc' => is_numeric($r->latitude) && is_numeric($r->longitude),
			// Phase 6: would a send change anything at SESAR (labels), or add the missing link back.
			'pushable' => $pushSt !== null && $pushSt['pushable'],
			'changed'  => ($pushSt !== null && $pushSt['pushable']) ? $pushSt['fields'] : array(),
			'linkBack' => $pushSt !== null && $pushSt['pushable'] && $pushSt['link_back'],
			// Phase 7: pending request; IGSN in the field deactivated at SESAR (kept until the owner acts).
			'requested' => $r->reg_state === 'deactivation_requested',
			'gone'      => $r->reg_igsn === null && $r->gone_igsn !== null && $cls['normalized'] !== null
			               && strtoupper($cls['normalized']) === strtoupper((string)$r->gone_igsn),
		);
	}
}

include("includes/mheader.php");
?>

<style>
.si-wrap { max-width: 900px; margin: 0 auto; }
.si-env { display: inline-block; margin: -1em 0 1.5em; padding: 0.3em 0.8em; border-radius: 4px; font-size: 0.85em;
          background: rgba(240, 180, 60, 0.18); color: #f3c97a; border: 1px solid rgba(240, 180, 60, 0.45); }
.si-panel { background: #2a2a3a; border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; padding: 1.5em 1.75em; margin-bottom: 2em; }
.si-panel h3 { margin: 0 0 0.6em; font-size: 1.15em; color: #fff; }
.si-panel p { margin: 0 0 0.9em; color: rgba(255,255,255,0.8); }
.si-muted { color: rgba(255,255,255,0.6); font-size: 0.9em; }
.si-steps { display: flex; flex-wrap: wrap; gap: 0.4em 1.4em; margin: 0 0 1.25em; padding: 0; list-style: none; font-size: 0.88em; }
.si-steps li { color: rgba(255,255,255,0.45); }
.si-steps li.done { color: rgba(140, 210, 150, 0.95); }
.si-steps li.done:before { content: "\2713  "; }
.si-steps li.now { color: #fff; font-weight: 600; }
.si-steps li.now:before { content: "\25B8  "; color: #e44c65; }
.si-howto { margin: 0 0 1em 1.25em; padding: 0; color: rgba(255,255,255,0.8); }
.si-howto li { margin-bottom: 0.35em; }
.si-btn { background: #e44c65; color: #fff; border: none; border-radius: 4px; padding: 0.6em 1.2em; font-size: 1em; cursor: pointer; line-height: 1.4; }
.si-btn:hover { background: #f06880; }
.si-btn.si-quiet { background: rgba(255,255,255,0.12); }
.si-btn.si-quiet:hover { background: rgba(255,255,255,0.2); }
.si-btn[disabled] { opacity: 0.5; cursor: default; }
.si-actions { display: flex; flex-wrap: wrap; gap: 0.75em; align-items: center; margin-top: 0.5em; }
.si-note { margin-top: 1em; padding: 0.75em 1em; border-radius: 4px; background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.8); font-size: 0.95em; }
.si-note.si-ok { background: rgba(110, 190, 120, 0.14); border: 1px solid rgba(110, 190, 120, 0.35); }
.si-note.si-err { background: rgba(228, 76, 101, 0.14); border: 1px solid rgba(228, 76, 101, 0.4); }
.si-form { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8em 1em; margin: 0.5em 0 1em; }
.si-form label { display: block; font-size: 0.85em; color: rgba(255,255,255,0.65); margin-bottom: 0.25em; }
.si-form .si-full { grid-column: 1 / -1; }
.si-panel input[type="text"], .si-panel input[type="email"], .si-panel textarea, .si-filters input[type="text"], .si-filters select {
    width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.2);
    border-radius: 4px; color: #fff; padding: 0.55em 0.75em; font-size: 1em; font-family: inherit; }
.si-panel textarea { min-height: 5.5em; resize: vertical; }
.si-readonly { padding: 0.55em 0; color: #fff; }
.si-code { display: flex; align-items: center; gap: 0.5em; margin: 0.5em 0 0.75em; }
.si-code .si-prefix { font-size: 1.2em; color: #fff; font-family: monospace; }
.si-code input[type="text"] { width: 5.5em !important; font-family: monospace; font-size: 1.2em; text-transform: uppercase; letter-spacing: 0.1em; }
.si-codes { font-family: monospace; color: #fff; }
.si-dev { margin-top: 1.25em; padding-top: 1em; border-top: 1px dashed rgba(255,255,255,0.2); }
.si-dev .si-code input[type="text"] { width: 12em !important; text-transform: none; }
.si-filters { display: flex; flex-wrap: wrap; gap: 0.75em; margin-bottom: 1em; }
.si-filters input[type="text"] { flex: 1 1 16em; width: auto; }
.si-filters select { flex: 0 1 13em; width: auto; }
.si-table { width: 100%; border-collapse: collapse; font-size: 0.95em; }
.si-table th { text-align: left; font-weight: 600; color: rgba(255,255,255,0.7); border-bottom: 1px solid rgba(255,255,255,0.2); padding: 0.5em 0.6em; }
.si-table td { border-bottom: 1px solid rgba(255,255,255,0.08); padding: 0.5em 0.6em; vertical-align: top; }
.si-table td a { color: #fff; }
.si-igsn { font-family: monospace; }
.si-pill { display: inline-block; padding: 0.05em 0.6em; border-radius: 999px; font-size: 0.82em; white-space: nowrap; }
.si-pill.none { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.7); }
.si-pill.managed { background: rgba(110, 190, 120, 0.2); color: #a8e0b0; }
.si-pill.unmanaged { background: rgba(120, 170, 230, 0.2); color: #b6d6f4; }
.si-pill.other { background: rgba(170, 140, 230, 0.2); color: #d2c2f4; }
.si-pill.invalid { background: rgba(228, 76, 101, 0.18); color: #f5a3b3; }
.si-pill.noloc { background: rgba(240, 180, 60, 0.18); color: #f3c97a; }
.si-orphans { margin: 0 0 1.75em; padding: 1em 1.25em; border-radius: 6px; background: rgba(240,180,60,0.08); border: 1px solid rgba(240,180,60,0.35); }
.si-orphans h3 { margin: 0 0 0.4em; font-size: 1.05em; color: #fff; }
.si-orphans td.si-oact { text-align: right; white-space: nowrap; }
.si-orphans td.si-oact .si-btn { padding: 0.35em 0.8em; font-size: 0.9em; margin-left: 0.4em; }
@media (max-width: 640px) { .si-orphans td.si-oact { text-align: left; white-space: normal; } .si-orphans tr { display: grid; } }
.si-pill.requested { background: rgba(240, 180, 60, 0.18); color: #f3c97a; margin-left: 0.3em; }
.si-pill.gone { background: rgba(228, 76, 101, 0.18); color: #f5a3b3; margin-left: 0.3em; }
.si-pill.changed { background: rgba(228, 76, 101, 0.18); color: #f5a3b3; margin-left: 0.3em; }
.si-pill.differs { background: rgba(240, 180, 60, 0.18); color: #f3c97a; margin-left: 0.3em; }
.si-pill.ro { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.7); margin-left: 0.3em; }
.si-tabs { display: flex; gap: 0.25em; margin: 0 0 1.25em; border-bottom: 1px solid rgba(255,255,255,0.15); }
.si-tab { background: none; border: none; border-bottom: 3px solid transparent; color: rgba(255,255,255,0.65); padding: 0.55em 1em;
          font-size: 1em; cursor: pointer; box-shadow: none; height: auto; line-height: 1.4; margin-bottom: -1px; border-radius: 0; }
.si-tab:hover { color: #fff; background: none; }
.si-tab[aria-selected="true"] { color: #fff; border-bottom-color: #e44c65; font-weight: 600; }
.si-head { display: flex; flex-wrap: wrap; gap: 0.75em; align-items: center; margin-bottom: 0.75em; }
.si-head h3 { margin: 0; flex: 1 1 auto; }
.si-pager { display: flex; gap: 0.75em; align-items: center; justify-content: center; margin: 1em 0; }
.si-more { text-align: center; margin: 1em 0; }
.si-table td.si-cb, .si-table th.si-cb { width: 2.2em; }
.si-cb input[type="checkbox"] + label { padding-left: 1.6em; margin: 0; min-height: 1.4em; }
.si-cb input[type="checkbox"] + label:before { top: 0; }
.si-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.si-selbar { display: flex; flex-wrap: wrap; gap: 0.75em; align-items: center; margin: 0 0 1em; padding: 0.6em 0.9em; border-radius: 4px;
             background: rgba(255,255,255,0.05); }
.si-selbar .si-selcount { flex: 1 1 auto; color: rgba(255,255,255,0.75); font-size: 0.92em; }
/* My samples: five actions, so the count gets its own line and the buttons stay together on the next. */
.si-selbar #si-selcount { flex-basis: 100%; }
@media (max-width: 640px) {
    .si-form { grid-template-columns: 1fr; }
    .si-panel { padding: 1.1em; }
    .si-table .si-col-loc { display: none; }
}
</style>

<div id="main" class="wrapper style1">
    <div class="container">
        <header class="major">
            <h2>IGSNs at SESAR</h2>
        </header>

<?php if (!$allowed): ?>
        <div class="si-wrap"><div class="si-panel"><p>IGSN registration is not available for your account yet.</p>
            <p><a href="/my_samples.php">Back to My Samples</a></p></div></div>
<?php elseif (!$configured): ?>
        <div class="si-wrap"><div class="si-panel"><p>SESAR is not configured on this server yet.</p></div></div>
<?php else: ?>
        <div class="si-wrap">
            <?php if ($status['environment'] === 'sandbox'): ?>
            <div class="si-env" title="StraboSpot is connected to SESAR's test site. IGSNs registered here are for testing only.">SESAR test site (sandbox): IGSNs made here are for testing only</div>
            <?php endif; ?>

            <div class="si-panel" id="si-conn" aria-live="polite"></div>

            <div class="si-tabs" role="tablist">
                <button type="button" class="si-tab" role="tab" id="si-tab-mine" aria-selected="true" aria-controls="si-pane-mine">My samples</button>
                <button type="button" class="si-tab" role="tab" id="si-tab-import" aria-selected="false" aria-controls="si-pane-import">Import from my SESAR account</button>
            </div>

            <div id="si-pane-mine" role="tabpanel" aria-labelledby="si-tab-mine">
            <div class="si-orphans" id="si-orphans" hidden>
                <h3>IGSNs whose sample was deleted</h3>
                <p class="si-muted">These IGSNs are still live at SESAR, but their StraboSamples sample no longer exists.
                   Ask SESAR to deactivate them, or keep them if the specimen still exists.</p>
                <table class="si-table"><tbody id="si-orphan-rows"></tbody></table>
            </div>
            <div class="si-head">
                <h3>My samples</h3>
                <button type="button" class="si-btn si-quiet" id="si-create">Create samples from IGSNs</button>
            </div>
            <div class="si-filters">
                <input type="text" id="si-q" placeholder="Search sample name, ID or IGSN" autocomplete="off">
                <select id="si-state">
                    <option value="">All IGSN states</option>
                    <option value="none">No IGSN</option>
                    <option value="managed">IGSN managed here</option>
                    <option value="unmanaged">SESAR IGSN, not managed here</option>
                    <option value="other">Other DOI prefix</option>
                    <option value="invalid">Not a valid IGSN</option>
                    <option value="differs">Differs from StraboField</option>
                    <option value="changed">Changed since last sent to SESAR</option>
                    <option value="requested">Deactivation requested</option>
                    <option value="gone">Deactivated at SESAR</option>
                </select>
                <select id="si-loc">
                    <option value="">Any location</option>
                    <option value="yes">Has a location</option>
                    <option value="no">Missing location</option>
                </select>
            </div>
            <div class="si-selbar">
                <span class="si-selcount" id="si-selcount"></span>
                <button type="button" class="si-btn si-quiet" id="si-selclear" hidden>Clear selection</button>
                <button type="button" class="si-btn si-quiet" id="si-pull" disabled>Pull from SESAR</button>
                <button type="button" class="si-btn si-quiet" id="si-push" disabled>Send to SESAR</button>
                <button type="button" class="si-btn si-quiet" id="si-batch" disabled title="Write the selected samples into a SESAR batch template, to upload at SESAR yourself">SESAR batch file</button>
                <button type="button" class="si-btn" id="si-register" disabled>Register IGSNs</button>
            </div>
            <p class="si-muted" id="si-count"></p>
            <table class="si-table">
                <thead><tr><th class="si-cb"><input type="checkbox" id="si-all"><label for="si-all"><span class="si-sr">Select all shown samples</span></label></th>
                    <th>Sample</th><th>IGSN</th><th>IGSN state</th><th class="si-col-loc">Location</th></tr></thead>
                <tbody id="si-rows"></tbody>
            </table>
            <div class="si-more"><button type="button" class="si-btn si-quiet" id="si-more" hidden>Show more</button></div>
            </div>

            <div id="si-pane-import" role="tabpanel" aria-labelledby="si-tab-import" hidden>
                <p>Samples registered under your SESAR account, including ones made at SESAR directly (for example with SESAR's spreadsheet upload).
                   Tick the ones to bring into StraboSamples: each becomes a sample linked to its SESAR record.</p>
                <div class="si-filters">
                    <input type="text" id="si-imp-q" placeholder="Search at SESAR (name or IGSN), then press Enter" autocomplete="off">
                </div>
                <div class="si-selbar">
                    <span class="si-selcount" id="si-imp-selcount"></span>
                    <button type="button" class="si-btn" id="si-imp-create" disabled>Create samples</button>
                </div>
                <p class="si-muted" id="si-imp-count"></p>
                <table class="si-table">
                    <thead><tr><th class="si-cb"><input type="checkbox" id="si-imp-all"><label for="si-imp-all"><span class="si-sr">Select all importable samples on this page</span></label></th>
                        <th>SESAR sample</th><th>IGSN</th><th>In StraboSamples</th><th class="si-col-loc">Location</th></tr></thead>
                    <tbody id="si-imp-rows"></tbody>
                </table>
                <div class="si-pager"><button type="button" class="si-btn si-quiet" id="si-imp-prev" hidden>Previous</button>
                    <span class="si-muted" id="si-imp-page"></span>
                    <button type="button" class="si-btn si-quiet" id="si-imp-next" hidden>Next</button></div>
            </div>
        </div>

<script>
(function () {
    'use strict';
    var STATUS = <?= json_encode($status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var ROWS = <?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var ORPHANS = <?= json_encode(isset($orphans) ? $orphans : array(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var PAGE = 100;
    var AUTO_CHECK_EVERY = 60 * 1000;   // re-check on tab return at most once a minute
    var panel = document.getElementById('si-conn');
    var busy = false, lastCheck = 0, flash = null, popup = null;

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function fmtDate(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        return isNaN(d) ? '' : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }
    function ext(url, text) {
        return '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(text) + '</a>';
    }

    // ------------------------------------------------------------------
    // Server calls
    // ------------------------------------------------------------------
    function call(action, extra) {
        var body = Object.assign({ action: action }, extra || {});
        return fetch('/sesar_connect.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Unexpected response (HTTP ' + r.status + ').' }; });
        }).then(function (j) {
            if (j.status) STATUS = j.status;
            return j;
        }, function () {
            return { ok: false, message: 'Could not reach StraboSpot. Please check your connection and try again.' };
        });
    }

    function run(action, extra, okNote) {
        if (busy) return Promise.resolve();
        busy = true; flash = null;
        render(action === 'check' || action === 'dev_code' ? 'Checking with SESAR…' : 'Working…');
        return call(action, extra).then(function (j) {
            busy = false;
            if (action === 'check' || action === 'dev_code') lastCheck = Date.now();
            if (!j.ok) flash = { cls: 'si-err', html: esc(j.message || 'Something went wrong.') };
            else if (okNote) flash = okNote(j);
            render();
            return j;
        });
    }

    // ------------------------------------------------------------------
    // ORCID popup
    // ------------------------------------------------------------------
    function connect() {
        var w = 520, h = 720;
        var left = window.screenX + Math.max(0, (window.outerWidth - w) / 2);
        var top = window.screenY + Math.max(0, (window.outerHeight - h) / 2);
        popup = window.open('/sesar_orcid_start.php', 'sesarOrcid', 'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top);
        if (!popup) { window.location.href = '/sesar_orcid_start.php'; return; }   // popup blocked: go full page
        var watch = setInterval(function () {
            if (popup && popup.closed) { clearInterval(watch); popup = null; run('status'); }
        }, 800);
    }
    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || !e.data || e.data.source !== 'sesar-connect') return;
        if (e.data.problem) flash = { cls: 'si-err', html: esc(e.data.problem) };
        lastCheck = Date.now();
        call('status').then(function () { render(); });
    });

    // Coming back from SESAR in another tab: re-check by ourselves.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible' || busy || !STATUS.can_check) return;
        if (['no_account', 'no_permission', 'no_code'].indexOf(STATUS.step) < 0) return;
        if (Date.now() - lastCheck < AUTO_CHECK_EVERY) return;
        run('check');
    });

    // ------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------
    var STEP_ORDER = ['orcid', 'account', 'access', 'code', 'ready'];
    var STEP_LABEL = { orcid: 'Sign in with ORCID', account: 'SESAR account', access: 'API access', code: 'SESAR code', ready: 'Ready' };
    var STEP_AT = { not_connected: 'orcid', reconnect: 'orcid', no_account: 'account', no_permission: 'access', no_code: 'code', connected: 'ready' };

    function stepper() {
        var at = STEP_ORDER.indexOf(STEP_AT[STATUS.step] || 'orcid');
        return '<ol class="si-steps">' + STEP_ORDER.map(function (k, i) {
            var cls = (STATUS.step === 'connected' || i < at) ? 'done' : (i === at ? 'now' : '');
            return '<li class="' + cls + '">' + STEP_LABEL[k] + '</li>';
        }).join('') + '</ol>';
    }

    function checkButton(label) {
        return STATUS.can_check
            ? '<button type="button" class="si-btn" data-act="check">' + (label || 'Check again') + '</button>'
            : '<button type="button" class="si-btn" data-act="connect">Sign in with ORCID to check again</button>';
    }

    function orcidLine() {
        return STATUS.orcid ? '<p class="si-muted">ORCID iD: ' + ext('https://orcid.org/' + STATUS.orcid, STATUS.orcid) + '</p>' : '';
    }

    function body() {
        var s = STATUS, L = s.links, h = '';
        switch (s.step) {
        case 'not_connected':
            h += '<h3>Connect your SESAR account</h3>'
               + '<p>StraboSpot registers IGSNs for your samples in your name at SESAR (the System for Earth Sample Registration). '
               + 'Sign in with ORCID to connect; SESAR finds your account through your ORCID iD.</p>'
               + '<div class="si-actions"><button type="button" class="si-btn" data-act="connect">Connect with ORCID</button></div>';
            break;
        case 'reconnect':
            h += '<h3>Please reconnect to SESAR</h3>'
               + '<p>Your SESAR connection has expired or was revoked at SESAR. Sign in with ORCID again to restore it.</p>'
               + '<div class="si-actions"><button type="button" class="si-btn" data-act="connect">Reconnect with ORCID</button></div>';
            break;
        case 'no_account':
            h += '<h3>Create your SESAR account (one-time)</h3>'
               + '<p>SESAR does not have an account for your ORCID iD yet. Creating one takes a minute:</p>'
               + '<ol class="si-howto">'
               + '<li>Open ' + ext(L.sesar, 'SESAR') + ' in a new tab.</li>'
               + '<li>Click <strong>Log in</strong> and sign in with <strong>the same ORCID iD</strong>. Your first sign-in creates the account.</li>'
               + '<li>Come back to this tab. We check again automatically, or click <strong>Check again</strong>.</li>'
               + '</ol>' + orcidLine()
               + '<div class="si-actions">' + checkButton() + '</div>';
            break;
        case 'no_permission':
            h += noPermission();
            break;
        case 'no_code':
            h += '<h3>Choose your SESAR code (one-time)</h3>'
               + '<p>Your connection works. SESAR needs one more thing: a <strong>SESAR code</strong>, the prefix every IGSN you register will start with. '
               + 'It is <strong>IE</strong> plus 3 letters or digits of your choice, often your initials (for example IE<em>JMA</em>).</p>'
               + '<div class="si-code"><span class="si-prefix">IE</span><input type="text" id="si-suffix" maxlength="3" autocomplete="off" aria-label="Three letters or digits">'
               + '<button type="button" class="si-btn" data-act="create_code">Create code</button></div>'
               + '<p class="si-muted">Codes are unique across SESAR; if yours is taken, try another. ' + ext(L.code_help, 'About SESAR codes') + '. '
               + 'Already made one at SESAR? ' + '<a href="#" data-act="check">Check again</a>.</p>';
            break;
        case 'connected':
            var who = s.sesar_account && (s.sesar_account.name || s.sesar_account.email)
                ? esc(s.sesar_account.name || '') + (s.sesar_account.email ? ' (' + esc(s.sesar_account.email) + ')' : '') : 'your SESAR account';
            h += '<h3>Connected to SESAR</h3>'
               + '<p>Signed in as ' + who + '. SESAR code' + (s.sesar_codes.length === 1 ? '' : 's') + ': '
               + '<span class="si-codes">' + s.sesar_codes.map(esc).join(', ') + '</span></p>'
               + orcidLine()
               + '<div class="si-actions"><button type="button" class="si-btn si-quiet" data-act="check">Refresh from SESAR</button>'
               + '<button type="button" class="si-btn si-quiet" data-act="disconnect">Disconnect</button></div>';
            break;
        }
        return h;
    }

    function noPermission() {
        var s = STATUS, L = s.links, f = s.request_form;
        var h = '<h3>Request SESAR API access (one-time)</h3>';
        if (s.access_requested_at) {
            return h + '<p>Your request was sent to SESAR on <strong>' + esc(fmtDate(s.access_requested_at)) + '</strong>. '
                + 'SESAR staff review each request by hand, so approval can take a day or more. Nothing else is needed from you now.</p>'
                + '<p>When SESAR approves it, come back here: this page checks again by itself when you return to it, or click <strong>Check again</strong>.</p>'
                + orcidLine()
                + '<div class="si-actions">' + checkButton() + '</div>'
                + '<p class="si-muted" style="margin-top:1em">No answer after a few days? Write to ' + ext('mailto:info@geosamples.org', 'info@geosamples.org') + '.</p>';
        }
        if (s.access_request_error) {
            return h + '<div class="si-note si-err">We could not send the request for you. SESAR replied: ' + esc(s.access_request_error) + '</div>'
                + '<p style="margin-top:1em">You can send it at SESAR instead:</p>'
                + '<ol class="si-howto">'
                + '<li>Open ' + ext(L.developer_settings, 'SESAR Developer Settings') + ' (sign in with ORCID if asked).</li>'
                + '<li>Under <strong>API access required</strong>, click <strong>Request API access</strong>.</li>'
                + '<li>Fill in the form and click <strong>Submit</strong>.</li>'
                + '<li>When SESAR approves it (staff review requests by hand), click <strong>Check again</strong> here.</li>'
                + '</ol>'
                + '<div class="si-actions">' + checkButton() + '</div>';
        }
        return h + '<p>SESAR only lets approved accounts use its API, which is how StraboSpot registers samples for you. '
            + 'SESAR staff approve each request by hand, so there is a short wait, once. We can send the request for you now:</p>'
            + '<div class="si-form">'
            + field('first_name', 'First name', f.first_name) + field('last_name', 'Last name', f.last_name)
            + field('email', 'Email', f.email, 'email')
            + '<div><label>ORCID iD</label><div class="si-readonly">' + esc(s.orcid || '') + '</div></div>'
            + field('institution', 'Institution', f.institution) + field('position_role', 'Position / role', f.position_role)
            + '<div class="si-full"><label for="si-f-message">Message to SESAR</label><textarea id="si-f-message">' + esc(f.message) + '</textarea></div>'
            + '</div>'
            + '<div class="si-actions"><button type="button" class="si-btn" data-act="request_access">Send request to SESAR</button>'
            + '<a href="#" data-act="check">Already approved? Check again</a></div>'
            + '<p class="si-muted" style="margin-top:1em">Prefer to do it yourself? ' + ext(L.developer_settings, 'SESAR Developer Settings')
            + ' &rarr; <em>Request API access</em>.</p>';
    }

    function field(name, label, value, type) {
        return '<div><label for="si-f-' + name + '">' + label + '</label><input type="' + (type || 'text') + '" id="si-f-' + name
            + '" value="' + esc(value) + '" maxlength="255"></div>';
    }

    function footer() {
        var s = STATUS, h = '';
        if (s.last_error && !busy) h += '<div class="si-note si-err">Could not reach SESAR just now: ' + esc(s.last_error) + '</div>';
        if (s.checked_at && ['connected', 'not_connected', 'reconnect'].indexOf(s.step) < 0) {
            h += '<p class="si-muted" style="margin-top:1em">Last checked with SESAR: ' + esc(fmtDate(s.checked_at)) + '</p>';
        }
        if (s.dev_code_paste && s.step !== 'connected') {
            h += '<div class="si-dev"><p class="si-muted">DEV ONLY: ORCID cannot return to localhost. After the ORCID popup lands on '
               + 'strabospot.org, paste the <code>code=</code> value from its address bar here.</p>'
               + '<div class="si-code"><input type="text" id="si-devcode" autocomplete="off" aria-label="ORCID code">'
               + '<button type="button" class="si-btn si-quiet" data-act="dev_code">Use code</button></div></div>';
        }
        return h;
    }

    function render(working) {
        var h = stepper() + body();
        if (working) h += '<div class="si-note">' + esc(working) + '</div>';
        else if (flash) h += '<div class="si-note ' + flash.cls + '">' + flash.html + '</div>';
        h += footer();
        // Keep what the user typed in the request form across re-renders.
        var keep = {};
        panel.querySelectorAll('input[id^="si-f-"], textarea[id^="si-f-"], #si-suffix').forEach(function (el) { keep[el.id] = el.value; });
        panel.innerHTML = h;
        Object.keys(keep).forEach(function (id) { var el = document.getElementById(id); if (el) el.value = keep[id]; });
        panel.querySelectorAll('button').forEach(function (b) { b.disabled = busy; });
    }

    panel.addEventListener('click', function (e) {
        var t = e.target.closest('[data-act]');
        if (!t || busy) return;
        e.preventDefault();
        var act = t.getAttribute('data-act');
        if (act === 'connect') return connect();
        if (act === 'check') return run('check', null, function () {
            return STATUS.step === 'connected' ? { cls: 'si-ok', html: 'Connected. You are ready to register IGSNs.' } : null;
        });
        if (act === 'disconnect') {
            if (!window.confirm('Disconnect StraboSpot from your SESAR account? You can connect again at any time.')) return;
            return run('disconnect');
        }
        if (act === 'create_code') {
            var suffix = (document.getElementById('si-suffix').value || '').trim();
            return run('create_code', { suffix: suffix }, function () {
                return { cls: 'si-ok', html: 'Created your SESAR code IE' + esc(suffix.toUpperCase()) + '. You are ready to register IGSNs.' };
            });
        }
        if (act === 'dev_code') {
            return run('dev_code', { code: (document.getElementById('si-devcode').value || '').trim() });
        }
        if (act === 'request_access') {
            var form = {};
            ['first_name', 'last_name', 'email', 'institution', 'position_role', 'message'].forEach(function (k) {
                form[k] = (document.getElementById('si-f-' + k).value || '').trim();
            });
            var missing = Object.keys(form).filter(function (k) { return form[k] === ''; });
            if (missing.length) {
                flash = { cls: 'si-err', html: 'Please fill in every field.' };
                render();
                var first = document.getElementById('si-f-' + missing[0]); if (first) first.focus();
                return;
            }
            return run('request_access', { form: form }, function (j) {
                return j.status.request_result === 'sent'
                    ? { cls: 'si-ok', html: 'Request sent. SESAR will review it; we will check again whenever you come back.' } : null;
            });
        }
    });

    panel.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        if (e.target.id === 'si-suffix') { e.preventDefault(); panel.querySelector('[data-act="create_code"]').click(); }
        if (e.target.id === 'si-devcode') { e.preventDefault(); panel.querySelector('[data-act="dev_code"]').click(); }
    });

    render();

    // ------------------------------------------------------------------
    // Samples table
    // ------------------------------------------------------------------
    var q = document.getElementById('si-q'), fState = document.getElementById('si-state'), fLoc = document.getElementById('si-loc');
    var tbody = document.getElementById('si-rows'), count = document.getElementById('si-count'), more = document.getElementById('si-more');
    var shown = PAGE;
    var selected = {};   // sample id -> true
    var MAX_RUN = 100;
    var selAll = document.getElementById('si-all'), selCount = document.getElementById('si-selcount');
    var selClear = document.getElementById('si-selclear'), regBtn = document.getElementById('si-register'), pullBtn = document.getElementById('si-pull');
    var pushBtn = document.getElementById('si-push'), batchBtn = document.getElementById('si-batch');
    var BY_ID = {};
    ROWS.forEach(function (r) { BY_ID[r.id] = r; });
    function selectable() { return true; }
    function mintable(r) { return r.state === 'none' || r.state === 'invalid' || r.gone; }   // the mint review decides the rest
    function pullable(r) { return !r.gone && (r.state === 'managed' || r.state === 'unmanaged' || r.state === 'other'); }
    function pushable(r) { return r.pushable && (r.changed.length > 0 || r.linkBack); }   // manual send, only when it would do something
    function selectedRows(test) { return Object.keys(selected).map(function (id) { return BY_ID[id]; }).filter(function (r) { return r && test(r); }); }
    var STATE_TEXT = { none: 'No IGSN', managed: 'Managed here', unmanaged: 'SESAR, not managed here',
                       other: 'Other DOI prefix', invalid: 'Not a valid IGSN' };
    var STATE_TIP = { invalid: 'The IGSN field holds text that is not an IGSN. It is never sent to SESAR.',
                      other: 'A DOI under a prefix other than SESAR\'s 10.58052. Some SESAR teams use their own prefix; SESAR is asked before any action.' };

    function filtered() {
        var term = q.value.trim().toLowerCase(), st = fState.value, loc = fLoc.value;
        return ROWS.filter(function (r) {
            if (st === 'differs') { if (!r.differs.length) return false; }
            else if (st === 'changed') { if (!pushable(r)) return false; }
            else if (st === 'requested') { if (!r.requested) return false; }
            else if (st === 'gone') { if (!r.gone) return false; }
            else if (st && r.state !== st) return false;
            if (loc === 'yes' && !r.hasLoc) return false;
            if (loc === 'no' && r.hasLoc) return false;
            if (term && (r.name + ' ' + r.id + ' ' + r.igsn).toLowerCase().indexOf(term) < 0) return false;
            return true;
        });
    }

    function renderRows() {
        var list = filtered();
        count.textContent = list.length === ROWS.length
            ? ROWS.length + ' sample' + (ROWS.length === 1 ? '' : 's') + ' you own'
            : list.length + ' of ' + ROWS.length + ' samples';
        tbody.innerHTML = list.slice(0, shown).map(function (r) {
            var href = '/samples/' + encodeURIComponent(r.owner) + '/' + encodeURIComponent(r.id);
            var cb = selectable(r)
                ? '<input type="checkbox" id="si-cb-' + esc(r.id) + '" data-id="' + esc(r.id) + '"' + (selected[r.id] ? ' checked' : '') + '>'
                  + '<label for="si-cb-' + esc(r.id) + '"><span class="si-sr">Select ' + esc(r.name || r.id) + '</span></label>'
                : '';
            return '<tr><td class="si-cb">' + cb + '</td><td><a href="' + esc(href) + '">' + esc(r.name || r.id) + '</a>'
                + (r.name && r.name !== r.id ? '<div class="si-muted">' + esc(r.id) + '</div>' : '') + '</td>'
                + '<td class="si-igsn">' + (r.igsn ? esc(r.igsn) : '<span class="si-muted">none</span>') + '</td>'
                + '<td><span class="si-pill ' + r.state + '"' + (STATE_TIP[r.state] ? ' title="' + esc(STATE_TIP[r.state]) + '"' : '') + '>' + STATE_TEXT[r.state] + '</span>'
                + (r.readonly ? '<span class="si-pill ro" title="Linked to another SESAR account\'s record: you can pull from it, not send changes.">read-only</span>' : '')
                + (r.requested ? '<span class="si-pill requested" title="A SESAR curator is reviewing the deactivation request.">Deactivation requested</span>' : '')
                + (r.gone ? '<span class="si-pill gone" title="SESAR deactivated this IGSN. It stays in the sample until you clear it or register a new IGSN.">Deactivated at SESAR</span>' : '')
                + (pushable(r) ? '<span class="si-pill changed" title="' + esc(r.changed.length ? 'Changed here since last sent to SESAR: ' + r.changed.join(', ').toLowerCase()
                    : 'The SESAR record has no link back to this sample yet') + '">' + (r.changed.length ? 'Changed since sent' : 'No link back') + '</span>' : '')
                + (r.differs.length ? '<span class="si-pill differs" title="' + esc('As of the last pull, SESAR and the StraboField spot differ in: ' + r.differs.join(', ').toLowerCase()) + '">Differs from Field</span>' : '')
                + '</td>'
                + '<td class="si-col-loc">' + (r.hasLoc ? 'Yes' : '<span class="si-pill noloc">Missing</span>') + '</td></tr>';
        }).join('') || '<tr><td colspan="5" class="si-muted">No samples match.</td></tr>';
        more.hidden = list.length <= shown;
        var vis = list.slice(0, shown).filter(selectable);
        selAll.checked = vis.length > 0 && vis.every(function (r) { return selected[r.id]; });
        renderSel();
    }

    function renderSel() {
        var n = Object.keys(selected).length, m = selectedRows(mintable).length, p = selectedRows(pullable).length, u = selectedRows(pushable).length;
        selCount.textContent = n === 0 ? 'Tick samples to register IGSNs, pull their SESAR records, or send their changes to SESAR.'
            : n + ' sample' + (n === 1 ? '' : 's') + ' selected' + (Math.max(m, p, u) > MAX_RUN ? ' (at most ' + MAX_RUN + ' per run)' : '');
        selClear.hidden = n === 0;
        regBtn.disabled = m === 0 || m > MAX_RUN;
        regBtn.textContent = 'Register IGSN' + (m > 1 ? 's (' + m + ')' : '');
        regBtn.title = n && !m ? 'None of the selected samples needs an IGSN.' : '';
        pullBtn.disabled = p === 0 || p > MAX_RUN;
        pullBtn.textContent = 'Pull from SESAR' + (p > 1 ? ' (' + p + ')' : '');
        pullBtn.title = n && !p ? 'None of the selected samples has a SESAR IGSN.' : '';
        pushBtn.disabled = u === 0 || u > MAX_RUN;
        pushBtn.textContent = 'Send to SESAR' + (u > 1 ? ' (' + u + ')' : '');
        pushBtn.title = n && !u ? 'None of the selected samples has changes to send to SESAR.' : '';
        batchBtn.disabled = m === 0;
        batchBtn.textContent = 'SESAR batch file' + (m > 1 ? ' (' + m + ')' : '');
        batchBtn.title = n && !m ? 'None of the selected samples needs an IGSN.'
            : 'Write the selected samples into a SESAR batch template, to upload at SESAR yourself';
    }

    tbody.addEventListener('change', function (e) {
        var id = e.target.getAttribute('data-id');
        if (id === null) return;
        if (e.target.checked) selected[id] = true; else delete selected[id];
        renderRows();
    });
    selAll.addEventListener('change', function () {
        filtered().slice(0, shown).filter(selectable).forEach(function (r) {
            if (selAll.checked) selected[r.id] = true; else delete selected[r.id];
        });
        renderRows();
    });
    selClear.addEventListener('click', function () { selected = {}; renderRows(); });
    regBtn.addEventListener('click', function () {
        SesarMint.open({ sampleIds: selectedRows(mintable).map(function (r) { return r.id; }),
                         onDone: function (minted) { if (minted) window.location.reload(); } });
    });
    pullBtn.addEventListener('click', function () {
        SesarPull.bulk({ samples: selectedRows(pullable).map(function (r) { return { id: r.id, name: r.name }; }),
                         onDone: function (changed) { if (changed) window.location.reload(); } });
    });
    batchBtn.addEventListener('click', function () {
        SesarBatch.open({ samples: selectedRows(mintable).map(function (r) { return { id: r.id, name: r.name }; }),
                          appBase: STATUS.links.sesar, connected: STATUS.step === 'connected' });
    });
    pushBtn.addEventListener('click', function () {
        SesarPush.bulk({ samples: selectedRows(pushable).map(function (r) { return { id: r.id, name: r.name }; }),
                         onDone: function (changed) { if (changed) window.location.reload(); } });
    });
    // ------------------------------------------------------------------
    // IGSNs whose sample was deleted (Phase 7, Q2)
    // ------------------------------------------------------------------
    function renderOrphans() {
        var box = document.getElementById('si-orphans');
        box.hidden = ORPHANS.length === 0;
        document.getElementById('si-orphan-rows').innerHTML = ORPHANS.map(function (o, i) {
            var acts = o.state === 'deactivation_requested'
                ? '<span class="si-pill requested">Deactivation requested</span> <button type="button" class="si-btn si-quiet" data-oact="check" data-i="' + i + '">Check with SESAR</button>'
                : '<button type="button" class="si-btn si-quiet" data-oact="keep" data-i="' + i + '" title="The specimen still exists: stop listing this IGSN here">Keep</button>'
                  + '<button type="button" class="si-btn" data-oact="deact" data-i="' + i + '">Request deactivation</button>';
            return '<tr><td><a class="si-igsn" href="' + esc(o.landing_url) + '" target="_blank" rel="noopener">' + esc(o.igsn) + '</a>'
                + (o.name ? '<div class="si-muted">' + esc(o.name) + '</div>' : '') + '<div class="si-muted" id="si-omsg-' + i + '"></div></td>'
                + '<td class="si-oact">' + acts + '</td></tr>';
        }).join('');
    }
    document.getElementById('si-orphan-rows').addEventListener('click', function (e) {
        var b = e.target.closest('[data-oact]');
        if (!b || b.disabled) return;
        var i = +b.getAttribute('data-i'), o = ORPHANS[i], act = b.getAttribute('data-oact');
        var msg = document.getElementById('si-omsg-' + i);
        if (act === 'deact') {
            SesarDeactivate.open({ reg: o.reg, onDone: function (changed) { if (changed) window.location.reload(); } });
            return;
        }
        b.disabled = true;
        (act === 'keep' ? SesarDeactivate.keep(o.reg) : SesarDeactivate.check({ reg: o.reg })).then(function (j) {
            b.disabled = false;
            if (!j || !j.ok) { msg.textContent = (j && j.message) || 'Something went wrong.'; return; }
            if (act === 'check' && j.result.state === 'requested') { msg.textContent = j.result.message; return; }
            window.location.reload();
        });
    });
    renderOrphans();

    document.getElementById('si-create').addEventListener('click', function () {
        SesarPull.create({ onDone: function (changed) { if (changed) window.location.reload(); } });
    });

    [q, fState, fLoc].forEach(function (el) {
        el.addEventListener(el === q ? 'input' : 'change', function () { shown = PAGE; renderRows(); });
    });
    more.addEventListener('click', function () { shown += PAGE; renderRows(); });
    renderRows();

    // ------------------------------------------------------------------
    // Tabs
    // ------------------------------------------------------------------
    var tabs = { mine: document.getElementById('si-tab-mine'), 'import': document.getElementById('si-tab-import') };
    var panes = { mine: document.getElementById('si-pane-mine'), 'import': document.getElementById('si-pane-import') };
    var impLoaded = false;
    function showTab(k) {
        Object.keys(tabs).forEach(function (t) {
            tabs[t].setAttribute('aria-selected', t === k ? 'true' : 'false');
            panes[t].hidden = t !== k;
        });
        try { sessionStorage.setItem('si-tab', k); } catch (e) { /* storage blocked */ }
        if (k === 'import' && !impLoaded) { impLoaded = true; loadImport(1); }
    }
    tabs.mine.addEventListener('click', function () { showTab('mine'); });
    tabs['import'].addEventListener('click', function () { showTab('import'); });

    // ------------------------------------------------------------------
    // Import from my SESAR account (one SESAR page at a time)
    // ------------------------------------------------------------------
    var imp = { page: 1, pages: 1, rows: [], selected: {}, busy: false, search: '' };
    var impRows = document.getElementById('si-imp-rows'), impCount = document.getElementById('si-imp-count');
    var impPrev = document.getElementById('si-imp-prev'), impNext = document.getElementById('si-imp-next'), impPage = document.getElementById('si-imp-page');
    var impAll = document.getElementById('si-imp-all'), impSel = document.getElementById('si-imp-selcount'), impCreate = document.getElementById('si-imp-create');
    var impQ = document.getElementById('si-imp-q');

    function loadImport(page) {
        if (STATUS.step !== 'connected') {
            impRows.innerHTML = '<tr><td colspan="5" class="si-muted">Connect your SESAR account above to see its samples.</td></tr>';
            impCount.textContent = '';
            return;
        }
        imp.busy = true;
        impRows.innerHTML = '<tr><td colspan="5" class="si-muted">Loading your samples from SESAR…</td></tr>';
        impPrev.hidden = impNext.hidden = true;
        fetch('/sesar_pull.php', {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'import_page', page: page, search: imp.search })
        }).then(function (r) { return r.json(); }).catch(function () {
            return { ok: false, message: 'Could not reach StraboSpot. Please check your connection.' };
        }).then(function (j) {
            imp.busy = false;
            if (!j.ok) {
                impRows.innerHTML = '<tr><td colspan="5"><div class="si-note si-err">' + esc(j.message || 'Something went wrong.')
                    + ' <a href="#" data-imp-retry>Try again</a></div></td></tr>';
                return;
            }
            imp.page = j.page.page; imp.pages = j.page.pages; imp.rows = j.page.rows; imp.count = j.page.count;
            renderImport();
        });
    }

    function renderImport() {
        impCount.textContent = imp.count === 0 ? '' : imp.count + ' sample' + (imp.count === 1 ? '' : 's') + ' at SESAR'
            + (imp.search ? ' matching "' + imp.search + '"' : '');
        impRows.innerHTML = imp.rows.map(function (r, i) {
            var cb = r.holder ? '' : '<input type="checkbox" id="si-imp-' + i + '" data-igsn="' + esc(r.igsn) + '"' + (imp.selected[r.igsn] ? ' checked' : '') + '>'
                + '<label for="si-imp-' + i + '"><span class="si-sr">Import ' + esc(r.name || r.igsn) + '</span></label>';
            var meta = [r.object_type, r.material].filter(Boolean).join(', ');
            return '<tr><td class="si-cb">' + cb + '</td><td>' + esc(r.name || '(no name)')
                + (meta ? '<div class="si-muted">' + esc(meta) + '</div>' : '') + '</td>'
                + '<td class="si-igsn"><a href="' + esc(STATUS.links.landing_base + r.igsn) + '" target="_blank" rel="noopener">' + esc(r.igsn) + '</a></td>'
                + '<td>' + (r.holder ? '<a href="' + esc(r.holder.url) + '">' + esc(r.holder.name) + '</a>' : '<span class="si-muted">not yet</span>') + '</td>'
                + '<td class="si-col-loc">' + (r.has_location ? 'Yes' : '<span class="si-pill noloc">Missing</span>') + '</td></tr>';
        }).join('') || '<tr><td colspan="5" class="si-muted">' + (imp.search ? 'No samples match.' : 'Your SESAR account has no samples yet.') + '</td></tr>';
        impPrev.hidden = imp.page <= 1;
        impNext.hidden = imp.page >= imp.pages;
        impPage.textContent = imp.pages > 1 ? 'Page ' + imp.page + ' of ' + imp.pages : '';
        var open = imp.rows.filter(function (r) { return !r.holder; });
        impAll.checked = open.length > 0 && open.every(function (r) { return imp.selected[r.igsn]; });
        renderImpSel();
    }

    function renderImpSel() {
        var n = Object.keys(imp.selected).length;
        impSel.textContent = n === 0 ? 'Tick SESAR samples to create them in StraboSamples.'
            : n + ' selected' + (n > MAX_RUN ? ' (at most ' + MAX_RUN + ' per run)' : '') + (imp.pages > 1 ? ' (kept across pages)' : '');
        impCreate.disabled = n === 0 || n > MAX_RUN;
        impCreate.textContent = n > 1 ? 'Create ' + n + ' samples' : 'Create sample';
    }

    impRows.addEventListener('change', function (e) {
        var g = e.target.getAttribute('data-igsn');
        if (g === null) return;
        if (e.target.checked) imp.selected[g] = true; else delete imp.selected[g];
        renderImport();
    });
    impRows.addEventListener('click', function (e) {
        if (e.target.hasAttribute('data-imp-retry')) { e.preventDefault(); loadImport(imp.page); }
    });
    impAll.addEventListener('change', function () {
        imp.rows.forEach(function (r) { if (!r.holder) { if (impAll.checked) imp.selected[r.igsn] = true; else delete imp.selected[r.igsn]; } });
        renderImport();
    });
    impPrev.addEventListener('click', function () { if (!imp.busy) loadImport(imp.page - 1); });
    impNext.addEventListener('click', function () { if (!imp.busy) loadImport(imp.page + 1); });
    impQ.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        imp.search = impQ.value.trim();
        loadImport(1);
    });
    impCreate.addEventListener('click', function () {
        SesarPull.create({ igsns: Object.keys(imp.selected), onDone: function (changed) {
            if (!changed) return;
            imp.selected = {};
            try { sessionStorage.setItem('si-tab', 'import'); } catch (e) { /* storage blocked */ }
            window.location.reload();   // the My samples list gains the new samples
        } });
    });

    var startTab = 'mine';
    try { startTab = sessionStorage.getItem('si-tab') === 'import' ? 'import' : 'mine'; } catch (e) { /* storage blocked */ }
    showTab(startTab);
})();
</script>
<script src="/assets/js/sesar_mint.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/sesar_mint.js'); ?>"></script>
<script src="/assets/js/sesar_pull.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/sesar_pull.js'); ?>"></script>
<script src="/assets/js/sesar_push.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/sesar_push.js'); ?>"></script>
<script src="/assets/js/sesar_batch.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/sesar_batch.js'); ?>"></script>
<script src="/assets/js/sesar_deactivate.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/sesar_deactivate.js'); ?>"></script>
<?php endif; ?>
    </div>
</div>

<?php
include("includes/mfooter.php");
