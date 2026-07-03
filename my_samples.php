<?php
/**
 * File: my_samples.php
 * Description: My StraboSamples — central sample dashboard across all three
 *              subsystems (Field, Micro, Experimental). Design §12.1 +
 *              mockup docs/StraboSamples/mockups/my_samples_mockup.png.
 *
 *              Server-fetches the user's spine list (own + accepted
 *              collaborator-on samples) + per-(sample, subsystem) link
 *              counts for the subsystem badges, then embeds the merged
 *              payload as JSON so the page can render + search + sort +
 *              filter client-side without round-trips. Mirrors the
 *              publications.php "embed and render" pattern.
 *
 *              Phase 4. The "+ New Sample" button opens an in-page modal
 *              that POSTs to /samples_create.php (session-authed proxy
 *              over StraboSamplesService::createSample). On success the
 *              new sample splices into the local list AND opens in a new
 *              tab so the user can fill in subsystem links / parent /
 *              etc. without losing their place in the list.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/samplesdb/services/StraboSamplesService.php";
require_once __DIR__ . "/samplesdb/lib/vocab.php";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);
$samples = $svc->listMySamples();

// One GROUP BY against sample_subsystem_links scoped to samples the
// caller can read (mirrors listMySamples' visibility predicate so the
// badge query returns zero rows for non-accessible samples even if some
// other path adds links to them).
$badgeRows = $db->get_results_prepared(
    "SELECT l.sample_id, l.sample_userpkey, l.subsystem, COUNT(*)::int AS n
       FROM strabosamples.sample_subsystem_links l
       JOIN strabosamples.samples s
         ON s.id = l.sample_id AND s.userpkey = l.sample_userpkey
      WHERE s.userpkey = $1
         OR EXISTS (
              SELECT 1 FROM strabosamples.sample_collaborators c
               WHERE c.sample_id = s.id
                 AND c.sample_userpkey = s.userpkey
                 AND c.collaborator_pkey = $1
                 AND c.accepted = TRUE
                 AND c.removed_at IS NULL
            )
      GROUP BY l.sample_id, l.sample_userpkey, l.subsystem",
    array($userpkey)
);

$badges = array();
if (is_array($badgeRows)) {
    foreach ($badgeRows as $b) {
        $key = $b->sample_id . '|' . (int)$b->sample_userpkey;
        if (!isset($badges[$key])) {
            $badges[$key] = array('field' => 0, 'micro' => 0, 'experimental' => 0);
        }
        $badges[$key][$b->subsystem] = (int)$b->n;
    }
}
foreach ($samples as &$s) {
    $key = $s['id'] . '|' . (int)$s['userpkey'];
    $s['badges'] = isset($badges[$key])
        ? $badges[$key]
        : array('field' => 0, 'micro' => 0, 'experimental' => 0);
}
unset($s);

// Ownership-cue enrichment: each row gets a `relationship` of
//   - 'mine_private'    = I own it, no accepted collaborators
//   - 'mine_shared'     = I own it, ≥1 accepted collaborator (collab_count populated)
//   - 'shared_with_me'  = I don't own it (owner_name populated)
// Drives the Show: tabs + the relationship pill on each card.

// Owner-name batched lookup for the not-owned rows.
$nonOwnedOwnerPkeys = array();
foreach ($samples as $s) {
    if ((int)$s['userpkey'] !== $userpkey) {
        $nonOwnedOwnerPkeys[(int)$s['userpkey']] = true;
    }
}
$ownerNames = array();
if (!empty($nonOwnedOwnerPkeys)) {
    $pkeys = array_keys($nonOwnedOwnerPkeys);
    $placeholders = array();
    for ($i = 0; $i < count($pkeys); $i++) {
        $placeholders[] = '$' . ($i + 1);
    }
    $rows = $db->get_results_prepared(
        "SELECT pkey, firstname, lastname, email FROM users WHERE pkey IN (" . implode(',', $placeholders) . ")",
        $pkeys
    );
    if (is_array($rows)) {
        foreach ($rows as $u) {
            $name = trim(($u->firstname ?: '') . ' ' . ($u->lastname ?: ''));
            $ownerNames[(int)$u->pkey] = $name !== '' ? $name : $u->email;
        }
    }
}

// Accepted-collaborator counts for my-owned samples (one GROUP BY).
$collabRows = $db->get_results_prepared(
    "SELECT sample_id, sample_userpkey, COUNT(*)::int AS n
       FROM strabosamples.sample_collaborators
      WHERE sample_userpkey = $1 AND accepted = TRUE AND removed_at IS NULL
      GROUP BY sample_id, sample_userpkey",
    array($userpkey)
);
$collabCounts = array();
if (is_array($collabRows)) {
    foreach ($collabRows as $c) {
        $k = $c->sample_id . '|' . (int)$c->sample_userpkey;
        $collabCounts[$k] = (int)$c->n;
    }
}

foreach ($samples as &$s) {
    if ((int)$s['userpkey'] === $userpkey) {
        $k = $s['id'] . '|' . (int)$s['userpkey'];
        $count = isset($collabCounts[$k]) ? $collabCounts[$k] : 0;
        $s['relationship'] = $count > 0 ? 'mine_shared' : 'mine_private';
        $s['collab_count'] = $count;
        $s['owner_name']   = null;
    } else {
        $s['relationship'] = 'shared_with_me';
        $s['collab_count'] = 0;
        $s['owner_name']   = isset($ownerNames[(int)$s['userpkey']])
            ? $ownerNames[(int)$s['userpkey']]
            : '(unknown user)';
    }
}
unset($s);

// Pending sample-collaboration invitations for the current user.
// Server-rendered above the controls — same embed-JSON + render-client-side
// pattern as the sample list itself. Same inviter-info enrichment as
// samples_invitations.php's `list` action; duplicated rather than abstracted
// because the proxy and the page are the only two sites today.
$invitations = $svc->listMyInvitations();
if (!empty($invitations)) {
    $inviterPkeys = array();
    foreach ($invitations as $inv) {
        $inviterPkeys[(int)$inv['added_by']] = true;
    }
    $inviterPkeys = array_keys($inviterPkeys);
    $placeholders = array();
    for ($i = 0; $i < count($inviterPkeys); $i++) {
        $placeholders[] = '$' . ($i + 1);
    }
    $userRows = $db->get_results_prepared(
        "SELECT pkey, firstname, lastname, email FROM users WHERE pkey IN (" . implode(',', $placeholders) . ")",
        $inviterPkeys
    );
    $inviterInfo = array();
    if (is_array($userRows)) {
        foreach ($userRows as $u) {
            $inviterInfo[(int)$u->pkey] = $u;
        }
    }
    foreach ($invitations as &$inv) {
        $u = isset($inviterInfo[(int)$inv['added_by']]) ? $inviterInfo[(int)$inv['added_by']] : null;
        if ($u) {
            $name = trim(($u->firstname ?: '') . ' ' . ($u->lastname ?: ''));
            $inv['inviter_name']     = $name !== '' ? $name : $u->email;
            $inv['inviter_initials'] = strtoupper(
                substr($u->firstname ?: $u->email, 0, 1) .
                substr($u->lastname  ?: '', 0, 1)
            );
        } else {
            $inv['inviter_name']     = '(unknown user)';
            $inv['inviter_initials'] = '?';
        }
    }
    unset($inv);
}

include("includes/mheader.php");
?>

<style>
.ms-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 1em;
    margin: 0 auto 1.5em auto;
    max-width: 760px;
    justify-content: center;
    align-items: center;
}
.ms-controls input[type="text"],
.ms-controls select {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    color: #ffffff;
    padding: 0.6em 0.8em;
    font-size: 1em;
    box-sizing: border-box;
}
.ms-controls input[type="text"]:focus,
.ms-controls select:focus {
    border-color: #e44c65;
    outline: none;
}
.ms-controls input[type="text"] {
    flex: 1 1 320px;
    min-width: 220px;
    max-width: 380px;
}
.ms-controls select {
    flex: 0 0 auto;
}
.ms-controls select option {
    background: #2a2a3a;
    color: #ffffff;
}
.ms-new-btn {
    background: #e44c65;
    color: #ffffff;
    border: none;
    border-radius: 4px;
    padding: 0.6em 1.1em;
    font-size: 1em;
    cursor: pointer;
}
.ms-new-btn:hover {
    background: #f06880;
}
.ms-io-btn {
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    border: none;
    border-radius: 4px;
    padding: 0.6em 1.1em;
    font-size: 1em;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}
.ms-io-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

.ms-type-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4em;
    justify-content: center;
    align-items: center;
    margin-bottom: 1.5em;
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.95em;
}
.ms-type-tabs .ms-type-label {
    margin-right: 0.6em;
    font-weight: 600;
}
.ms-tab {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: rgba(255, 255, 255, 0.85);
    border-radius: 999px;
    padding: 0.35em 0.95em;
    cursor: pointer;
    font-size: 0.9em;
}
.ms-tab:hover:not(.active) {
    background: rgba(255, 255, 255, 0.12);
}
.ms-tab.active {
    background: #e44c65;
    border-color: #e44c65;
    color: #ffffff;
}

.ms-result-count {
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.95em;
    margin-bottom: 1em;
    text-align: center;
}

.ms-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 6px;
    padding: 1.25em 1.5em;
    margin-bottom: 1em;
    color: #ffffff;
}
.ms-card-row {
    display: flex;
    gap: 1.5em;
    align-items: flex-start;
}
.ms-card-meta {
    flex: 0 0 230px;
    color: rgba(255, 255, 255, 0.9);
}
.ms-card-meta .ms-sample-id {
    color: #ffffff;
    font-weight: 700;
    font-size: 1.1em;
    word-break: break-all;
}
.ms-card-meta .ms-row {
    color: rgba(255, 255, 255, 0.75);
    font-size: 0.92em;
    margin-top: 0.3em;
}
.ms-card-meta .ms-row strong {
    color: rgba(255, 255, 255, 0.9);
    font-weight: 600;
}
.ms-view-btn {
    display: inline-block;
    margin-top: 0.8em;
    background: #e44c65;
    color: #ffffff;
    border: none;
    border-radius: 4px;
    padding: 0.45em 0.9em;
    font-size: 0.9em;
    cursor: pointer;
    text-decoration: none;
}
.ms-view-btn:hover {
    background: #f06880;
    color: #ffffff;
}

.ms-card-body {
    flex: 1;
    min-width: 0;
}
.ms-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 1.25em;
    margin-bottom: 0.6em;
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.92em;
}
.ms-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35em;
}
.ms-badge .ms-badge-icon {
    width: 22px;
    height: 22px;
    opacity: 0.9;
    object-fit: contain;
    vertical-align: middle;
}
.ms-description {
    color: rgba(255, 255, 255, 0.78);
    line-height: 1.5;
    font-size: 0.95em;
}
.ms-card mark {
    background: #fff3a3;
    color: #222222;
    padding: 0 1px;
    border-radius: 2px;
}

.ms-children-toggle {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.65);
    padding: 0.55em 0 0 0;
    cursor: pointer;
    font-size: 0.92em;
}
.ms-children-toggle:hover {
    color: #ffffff;
}
.ms-child-row {
    background: rgba(255, 255, 255, 0.07);
    border-radius: 4px;
    padding: 0.65em 1em;
    margin-top: 0.5em;
    display: flex;
    align-items: center;
    gap: 1em;
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.9em;
}
.ms-child-row .ms-child-id {
    font-weight: 600;
    color: #ffffff;
    word-break: break-all;
}
.ms-child-row .ms-view-btn {
    margin-top: 0;
    margin-left: auto;
}

.ms-empty {
    text-align: center;
    margin: 2em 0 6em 0;
    color: rgba(255, 255, 255, 0.6);
}

@media (max-width: 736px) {
    .ms-card-row {
        flex-direction: column;
        gap: 0.9em;
    }
    .ms-card-meta {
        flex: 0 0 auto;
    }
}

/* ---- Create-sample modal ---- */
.ms-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    z-index: 9999;
    padding: 4em 1em 2em 1em;
    overflow-y: auto;
}
/* Class display:flex above defeats the UA stylesheet's [hidden] rule,
   so we re-assert it explicitly. Same trap applies to any future
   overlay with an explicit display value. */
.ms-modal-overlay[hidden] { display: none; }
.ms-modal {
    background: #1f1f2e;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    width: 100%;
    max-width: 640px;
    color: #ffffff;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6);
}
.ms-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1em 1.25em;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}
.ms-modal-header h3 {
    margin: 0;
    font-size: 1.15em;
    color: #ffffff;
}
.ms-modal-close {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.7);
    font-size: 1.6em;
    line-height: 1;
    cursor: pointer;
    padding: 0 0.3em;
}
.ms-modal-close:hover { color: #ffffff; }
.ms-modal-form { padding: 1em 1.25em 1.25em 1.25em; }
.ms-modal-form .ms-form-row { margin-bottom: 0.9em; }
.ms-modal-form label {
    display: block;
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.9em;
    margin-bottom: 0.3em;
}
.ms-required { color: #e44c65; }
.ms-modal-form input[type="text"],
.ms-modal-form input[type="number"],
.ms-modal-form textarea,
.ms-modal-form select {
    width: 100%;
    box-sizing: border-box;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    color: #ffffff;
    padding: 0.55em 0.7em;
    font-size: 0.95em;
    font-family: inherit;
}
/* Override site-wide select { height: 3em; padding-right: 3em; background-image: <arrow> } from massets/css/main.css. */
.ms-modal-form select {
    height: auto;
    padding-right: 2.2em;
    background-position: right 0.55em center;
    background-size: 0.9em;
    appearance: auto;
}
.ms-modal-form select option,
.ms-modal-form select optgroup {
    background: #2a2a3a;
    color: #ffffff;
}
.ms-modal-form input:focus,
.ms-modal-form textarea:focus,
.ms-modal-form select:focus {
    border-color: #e44c65;
    outline: none;
}
.ms-modal-form textarea { resize: vertical; min-height: 4em; }
.ms-modal-form .ms-vocab-other { margin-top: 0.4em; }
.ms-modal-form .ms-vocab-other[hidden] { display: none; }
.ms-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 1em;
}
.ms-parent-picker { position: relative; }
.ms-parent-clear {
    position: absolute;
    right: 0.4em;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.7);
    font-size: 1.3em;
    line-height: 1;
    cursor: pointer;
    padding: 0 0.3em;
}
.ms-parent-clear:hover { color: #ffffff; }
.ms-parent-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #2a2a3a;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-top: none;
    border-radius: 0 0 4px 4px;
    max-height: 220px;
    overflow-y: auto;
    z-index: 1;
}
.ms-parent-hit {
    padding: 0.45em 0.7em;
    cursor: pointer;
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.92em;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.ms-parent-hit:last-child { border-bottom: none; }
.ms-parent-hit:hover { background: rgba(228, 76, 101, 0.2); }
.ms-parent-noresults {
    padding: 0.5em 0.7em;
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.9em;
    font-style: italic;
}
.ms-parent-selected {
    margin-top: 0.4em;
    padding: 0.4em 0.7em;
    background: rgba(228, 76, 101, 0.15);
    border-left: 3px solid #e44c65;
    border-radius: 3px;
    font-size: 0.9em;
    color: rgba(255, 255, 255, 0.9);
}
.ms-modal-error {
    background: rgba(228, 76, 101, 0.18);
    border-left: 3px solid #e44c65;
    padding: 0.55em 0.8em;
    border-radius: 3px;
    margin: 0.4em 0 0.8em 0;
    color: #ffffff;
    font-size: 0.92em;
}
.ms-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.6em;
    margin-top: 1em;
}
.ms-btn-cancel, .ms-btn-submit {
    border: none;
    border-radius: 4px;
    padding: 0.55em 1em;
    font-size: 0.95em;
    cursor: pointer;
}
.ms-btn-cancel {
    background: rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.85);
}
.ms-btn-cancel:hover { background: rgba(255, 255, 255, 0.18); }
.ms-btn-submit {
    background: #e44c65;
    color: #ffffff;
}
.ms-btn-submit:hover:not(:disabled) { background: #f06880; }
.ms-btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 640px) {
    .ms-form-grid { grid-template-columns: 1fr; }
}

/* ---- Pending-invitations banner ---- */
.ms-invitations {
    background: rgba(255, 200, 80, 0.10);
    border: 1px solid rgba(255, 200, 80, 0.35);
    border-radius: 6px;
    padding: 1em 1.25em;
    margin: 0 auto 1.5em auto;
    max-width: 760px;
    color: #fff;
}
.ms-invitations[hidden] { display: none; }
.ms-invitations-header {
    margin: 0 0 0.6em 0;
    color: #f5dc99;
    font-size: 0.95em;
    font-weight: 600;
}
.ms-invitation-row {
    display: flex;
    align-items: center;
    gap: 0.7em;
    padding: 0.6em 0;
    border-top: 1px solid rgba(255, 255, 255, 0.07);
}
.ms-invitation-row:first-child { border-top: none; padding-top: 0.4em; }
.ms-invitation-avatar {
    width: 32px; height: 32px; flex: 0 0 32px;
    background: rgba(228, 76, 101, 0.6);
    color: #fff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.82em; font-weight: 700;
}
.ms-invitation-text {
    flex: 1; min-width: 0;
    font-size: 0.93em;
    color: rgba(255, 255, 255, 0.85);
    line-height: 1.4;
}
.ms-invitation-text strong { color: #fff; }
.ms-invitation-text .ms-invitation-level {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.88em;
}
.ms-invitation-btn {
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 0.85em;
    cursor: pointer;
    flex: 0 0 auto;
    font-family: inherit;
}
.ms-invitation-btn.accept { background: #4cc86e; color: #fff; }
.ms-invitation-btn.accept:hover:not(:disabled) { background: #5ed583; }
.ms-invitation-btn.deny   { background: rgba(255, 255, 255, 0.12); color: #fff; }
.ms-invitation-btn.deny:hover:not(:disabled)   { background: rgba(255, 255, 255, 0.20); }
.ms-invitation-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* ---- Show: ownership filter tabs + relationship pills ---- */
.ms-show-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4em;
    justify-content: center;
    align-items: center;
    margin-bottom: 0.8em;
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.95em;
}
.ms-show-tabs .ms-type-label { margin-right: 0.6em; font-weight: 600; }

.ms-relationship-pill {
    display: inline-block;
    max-width: 100%;
    box-sizing: border-box;
    margin-top: 0.35em;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 0.78em;
    font-weight: 600;
    line-height: 1.5;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: middle;
}
.ms-relationship-pill.mine-shared {
    background: rgba(228, 76, 101, 0.22);
    color: #f5a3b3;
}
.ms-relationship-pill.shared-with-me {
    background: rgba(120, 170, 230, 0.20);
    color: #b6d6f4;
}
</style>

<div id="main" class="wrapper style1">
    <div class="container">
        <header class="major">
            <h2>My Samples</h2>
        </header>

        <?php if (!empty($invitations)): ?>
        <div class="ms-invitations" id="ms-invitations">
            <div class="ms-invitations-header">
                You have <?= count($invitations) ?> pending sample invitation<?= count($invitations) === 1 ? '' : 's' ?>.
            </div>
            <div class="ms-invitations-list" id="ms-invitations-list"></div>
        </div>
        <?php endif; ?>

        <div class="ms-controls">
            <button type="button" class="ms-new-btn" id="ms-new-sample-btn">+ New Sample</button>
            <a class="ms-io-btn" href="/samples_import.php" title="Bulk-import samples from a spreadsheet (XLSX/CSV)">&#8682; Import</a>
            <a class="ms-io-btn" href="/samples_tabular_download.php?what=export" title="Download your samples as a spreadsheet">&#8681; Export</a>
            <input type="text" id="ms-search" placeholder="Search ID, location, internal…" autocomplete="off">
            <select id="ms-sort">
                <option value="modified_desc">Sort: Most recent</option>
                <option value="modified_asc">Sort: Oldest</option>
                <option value="purpose">Sort: Purpose</option>
                <option value="type">Sort: Material</option>
                <option value="id_asc">Sort: Sample ID</option>
            </select>
        </div>

        <div class="ms-show-tabs">
            <span class="ms-type-label">Collaboration:</span>
            <button type="button" class="ms-tab ms-show-tab active" data-show="all">All</button>
            <button type="button" class="ms-tab ms-show-tab" data-show="mine">Mine</button>
            <button type="button" class="ms-tab ms-show-tab" data-show="shared_by_me">Shared by me</button>
            <button type="button" class="ms-tab ms-show-tab" data-show="shared_with_me">Shared with me</button>
        </div>

        <div class="ms-type-tabs">
            <span class="ms-type-label">Type:</span>
            <button type="button" class="ms-tab active" data-type="all">All</button>
            <button type="button" class="ms-tab" data-type="field">StraboField</button>
            <button type="button" class="ms-tab" data-type="micro">StraboMicro</button>
            <button type="button" class="ms-tab" data-type="experimental">StraboExperimental</button>
        </div>

        <div class="ms-result-count" id="ms-result-count"></div>

        <div id="ms-cards"></div>

        <div class="bottomSpacer"></div>
    </div>
</div>

<!-- Create-sample modal. Hidden until +New Sample is clicked. Driven by JS below. -->
<div id="ms-modal-overlay" class="ms-modal-overlay" hidden>
    <div class="ms-modal" role="dialog" aria-modal="true" aria-labelledby="ms-modal-title">
        <div class="ms-modal-header">
            <h3 id="ms-modal-title">Create Sample</h3>
            <button type="button" class="ms-modal-close" id="ms-modal-close" aria-label="Close">&times;</button>
        </div>
        <form id="ms-modal-form" class="ms-modal-form" novalidate>
            <div class="ms-form-row">
                <label for="ms-f-name">Sample ID <span class="ms-required">*</span></label>
                <input type="text" id="ms-f-name" maxlength="500" autocomplete="off" placeholder="e.g. PF-5">
            </div>
            <div class="ms-form-row">
                <label for="ms-f-igsn">IGSN</label>
                <input type="text" id="ms-f-igsn" maxlength="500" autocomplete="off">
            </div>
            <div class="ms-form-grid">
                <div class="ms-form-row">
                    <label for="ms-f-type">Material Type</label>
                    <?php echo samples_vocab_render_material_select('ms-f-type'); ?>
                    <input type="text" id="ms-f-type-other" class="ms-vocab-other" maxlength="500" autocomplete="off" placeholder="Enter material type" hidden>
                </div>
                <div class="ms-form-row">
                    <label for="ms-f-purpose">Sample Purpose</label>
                    <?php echo samples_vocab_render_purpose_select('ms-f-purpose'); ?>
                    <input type="text" id="ms-f-purpose-other" class="ms-vocab-other" maxlength="500" autocomplete="off" placeholder="Enter sample purpose" hidden>
                </div>
                <div class="ms-form-row">
                    <label for="ms-f-lat">Latitude</label>
                    <input type="text" id="ms-f-lat" inputmode="decimal" autocomplete="off" placeholder="-90 to 90">
                </div>
                <div class="ms-form-row">
                    <label for="ms-f-lng">Longitude</label>
                    <input type="text" id="ms-f-lng" inputmode="decimal" autocomplete="off" placeholder="-180 to 180">
                </div>
            </div>
            <div class="ms-form-row">
                <label for="ms-f-desc">Description</label>
                <textarea id="ms-f-desc" rows="2" maxlength="4000"></textarea>
            </div>
            <div class="ms-form-row">
                <label for="ms-f-notes">Notes</label>
                <textarea id="ms-f-notes" rows="2" maxlength="4000"></textarea>
            </div>
            <div class="ms-form-row">
                <label for="ms-f-parent">Parent Sample (optional)</label>
                <div class="ms-parent-picker">
                    <input type="text" id="ms-f-parent" autocomplete="off" placeholder="Type to search your samples&hellip;">
                    <button type="button" class="ms-parent-clear" id="ms-parent-clear" aria-label="Clear parent" hidden>&times;</button>
                    <div class="ms-parent-results" id="ms-parent-results" hidden></div>
                </div>
                <div class="ms-parent-selected" id="ms-parent-selected" hidden></div>
            </div>
            <div class="ms-modal-error" id="ms-modal-error" hidden></div>
            <div class="ms-modal-footer">
                <button type="button" class="ms-btn-cancel" id="ms-modal-cancel">Cancel</button>
                <button type="submit" class="ms-btn-submit" id="ms-modal-submit">Create Sample</button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="ms-data"><?php echo json_encode($samples, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="application/json" id="ms-invitations-data"><?php echo json_encode(!empty($invitations) ? $invitations : array(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

<script type="text/javascript">
(function() {
    'use strict';

    var rawData = JSON.parse(document.getElementById('ms-data').textContent || '[]');

    // Children map keyed by `${parent_sample_id}|${parent_userpkey}`. Top-level
    // cards = samples whose own parent isn't in the visible list (the rest
    // surface only via their parent's expand toggle). At launch this map is
    // empty since parent/child isn't migrated; it fills in as users set
    // parents via the API.
    var visibleKey = new Set(rawData.map(function(s) { return s.id + '|' + s.userpkey; }));
    var childrenByParent = {};
    rawData.forEach(function(s) {
        if (s.parent_sample_id && s.parent_userpkey && visibleKey.has(s.parent_sample_id + '|' + s.parent_userpkey)) {
            var pk = s.parent_sample_id + '|' + s.parent_userpkey;
            if (!childrenByParent[pk]) childrenByParent[pk] = [];
            childrenByParent[pk].push(s);
        }
    });
    var topLevel = rawData.filter(function(s) {
        return !(s.parent_sample_id && s.parent_userpkey && visibleKey.has(s.parent_sample_id + '|' + s.parent_userpkey));
    });

    // Scroll-position restore: when the user returns from a sample
    // detail page (browser back OR explicit Back link), put them back
    // at the same scroll position. We save on `pagehide` (fires on any
    // navigation away, including bfcache transitions) and restore on
    // load, gated by document.referrer pointing at /samples/{owner}/{id}
    // so unrelated navigations to MySamples don't scroll-jump from a
    // stale value. The sessionStorage entry is always cleared after the
    // read so subsequent visits start fresh.
    var pendingScroll = null;
    try {
        var stored = sessionStorage.getItem('my_samples_scroll');
        if (stored !== null) {
            sessionStorage.removeItem('my_samples_scroll');
            var ref = document.referrer || '';
            if (ref) {
                var refUrl = new URL(ref, window.location.origin);
                if (refUrl.origin === window.location.origin
                    && /^\/samples\/[^/]+\/[^/]+\/?$/.test(refUrl.pathname)) {
                    var n = parseInt(stored, 10);
                    if (!isNaN(n)) pendingScroll = n;
                }
            }
        }
    } catch (_) { /* sessionStorage may be unavailable; degrade gracefully */ }

    window.addEventListener('pagehide', function() {
        try { sessionStorage.setItem('my_samples_scroll', String(window.scrollY)); } catch (_) {}
    });

    // Read initial state from URL params so the page restores correctly
    // when the user navigates back from a sample detail page. Defaults
    // apply when params are absent.
    var urlParams = new URLSearchParams(window.location.search);
    var validSorts = {modified_desc: 1, modified_asc: 1, purpose: 1, type: 1, id_asc: 1};
    var validTypes = {all: 1, field: 1, micro: 1, experimental: 1};
    var validShows = {all: 1, mine: 1, shared_by_me: 1, shared_with_me: 1};
    var initialSort = urlParams.get('sort');
    var initialType = urlParams.get('type');
    var initialShow = urlParams.get('show');
    var state = {
        type:   (initialType && validTypes[initialType]) ? initialType : 'all',
        show:   (initialShow && validShows[initialShow]) ? initialShow : 'all',
        sort:   (initialSort && validSorts[initialSort]) ? initialSort : 'modified_desc',
        search: urlParams.get('search') || '',
        expanded: {},  // key → bool
    };

    // Reflect URL-derived state into the controls before first render so
    // the inputs/dropdowns/tabs match what the user is seeing.
    document.getElementById('ms-search').value = state.search;
    document.getElementById('ms-sort').value = state.sort;
    document.querySelectorAll('.ms-tab[data-type]').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-type') === state.type);
    });
    document.querySelectorAll('.ms-tab[data-show]').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-show') === state.show);
    });

    // Mirror current state into the URL via replaceState — we don't want
    // every keystroke to add a back-stack entry, just to keep the URL in
    // sync so browser back from a detail page restores the view.
    function syncUrl() {
        var p = new URLSearchParams();
        if (state.search)                              p.set('search', state.search);
        if (state.sort && state.sort !== 'modified_desc') p.set('sort', state.sort);
        if (state.type && state.type !== 'all')        p.set('type', state.type);
        if (state.show && state.show !== 'all')        p.set('show', state.show);
        var qs = p.toString();
        var newUrl = window.location.pathname + (qs ? '?' + qs : '');
        history.replaceState({}, '', newUrl);
    }

    var $cards     = document.getElementById('ms-cards');
    var $count     = document.getElementById('ms-result-count');
    var $search    = document.getElementById('ms-search');
    var $sort      = document.getElementById('ms-sort');
    var $typeTabs  = document.querySelectorAll('.ms-tab[data-type]');
    var $showTabs  = document.querySelectorAll('.ms-tab[data-show]');
    var $newBtn    = document.getElementById('ms-new-sample-btn');

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    function highlight(text, q) {
        if (!q) return escapeHtml(text);
        var safe = escapeHtml(text);
        var safeQ = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return safe.replace(new RegExp('(' + safeQ + ')', 'gi'), '<mark>$1</mark>');
    }
    function parsePgTimestamp(ts) {
        // Postgres timestamptz renders as "YYYY-MM-DD HH:MM:SS.ffffff-04"
        // (space separator, 6-digit fraction, 2-digit offset). Chrome and
        // Firefox parse that leniently; Safari/JavaScriptCore returns an
        // Invalid Date. Normalize to strict ISO 8601 before constructing.
        if (ts == null || ts === '') return null;
        if (typeof ts === 'number') return new Date(ts);
        var s = String(ts).trim();
        if (/^\d+$/.test(s)) return new Date(parseInt(s, 10));   // numeric epoch as string
        var iso = s.replace(' ', 'T')                 // space -> T separator
                   .replace(/(\.\d{3})\d+/, '$1')     // trim sub-millisecond digits
                   .replace(/([+-]\d{2})$/, '$1:00'); // -04 -> -04:00
        var d = new Date(iso);
        if (!isNaN(d.getTime())) return d;
        d = new Date(s);                              // last-ditch lenient parse
        return isNaN(d.getTime()) ? null : d;
    }
    function fmtDate(ts) {
        if (!ts) return '—';
        var d = parsePgTimestamp(ts);
        if (!d) return ts;
        return d.toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: 'numeric'});
    }
    function badgeIcon(kind) {
        // Canonical subsystem icons used across the site (also in
        // /fullsearch as .pickaxe-image / .microscope-image / .beaker-image).
        var src = kind === 'field' ? '/fullsearch/images/pickaxe.png'
                : kind === 'micro' ? '/fullsearch/images/microscope.png'
                :                    '/fullsearch/images/beaker.png';
        var alt = kind === 'field' ? 'StraboField'
                : kind === 'micro' ? 'StraboMicro'
                :                    'StraboExperimental';
        return '<img class="ms-badge-icon" src="' + src + '" alt="' + alt + '">';
    }
    function matchesType(sample, type) {
        if (type === 'all') return true;
        return (sample.badges[type] || 0) > 0;
    }
    function matchesShow(sample, show) {
        if (show === 'all') return true;
        if (show === 'mine')           return sample.relationship === 'mine_private' || sample.relationship === 'mine_shared';
        if (show === 'shared_by_me')   return sample.relationship === 'mine_shared';
        if (show === 'shared_with_me') return sample.relationship === 'shared_with_me';
        return true;
    }
    function matchesSearch(sample, q) {
        if (!q) return true;
        var hay = [
            sample.id, sample.name, sample.description, sample.notes, sample.igsn,
            sample.display_sample_type, sample.display_sample_purpose,
        ].filter(Boolean).join(' ').toLowerCase();
        return hay.indexOf(q.toLowerCase()) !== -1;
    }
    function sortFn(mode) {
        if (mode === 'modified_asc')  return function(a, b) { return (a.modified_at || '').localeCompare(b.modified_at || ''); };
        if (mode === 'purpose')       return function(a, b) { return (a.display_sample_purpose || '').localeCompare(b.display_sample_purpose || ''); };
        if (mode === 'type')          return function(a, b) { return (a.display_sample_type    || '').localeCompare(b.display_sample_type    || ''); };
        if (mode === 'id_asc')        return function(a, b) { return (a.name || a.id || '').localeCompare(b.name || b.id || ''); };
        return function(a, b) { return (b.modified_at || '').localeCompare(a.modified_at || ''); };  // modified_desc default
    }

    function viewSampleHref(sample) {
        return '/samples/' + encodeURIComponent(sample.userpkey) + '/' + encodeURIComponent(sample.id);
    }

    // Card renderer is the swap point for §16 #9 (multi-project samples).
    // v1: card-per-link is rendered IN the Sample Overview page, not here —
    // the MySamples list shows one card per sample regardless of how many
    // subsystem links it has.
    function renderCard(sample, q) {
        var key = sample.id + '|' + sample.userpkey;
        var children = childrenByParent[key] || [];
        var badges = [];
        ['field', 'micro', 'experimental'].forEach(function(kind) {
            var n = sample.badges[kind] || 0;
            if (n > 0) {
                var label = kind === 'experimental' ? 'Experiment' : (kind === 'micro' ? 'Micrograph' : 'Measurement');
                if (n !== 1) label = label + 's';
                badges.push('<span class="ms-badge">' + badgeIcon(kind) + n + ' ' + label + '</span>');
            }
        });

        var descRaw = sample.description || sample.notes || '';
        var desc = descRaw.length > 320 ? descRaw.substring(0, 320) + '…' : descRaw;

        // Relationship pill: collab cue near the Sample ID. mine_private gets
        // nothing (the absence is itself the cue — "no pill = just mine").
        var pillHtml = '';
        if (sample.relationship === 'mine_shared') {
            var n = sample.collab_count || 0;
            var label = 'Shared with ' + n + ' Collaborator' + (n === 1 ? '' : 's');
            pillHtml = '<div class="ms-relationship-pill mine-shared" title="' + escapeHtml(label) + '">' + label + '</div>';
        } else if (sample.relationship === 'shared_with_me') {
            var ownerName = sample.owner_name || 'someone';
            pillHtml = '<div class="ms-relationship-pill shared-with-me" title="' + escapeHtml('Shared by ' + ownerName) + '">Shared by ' + escapeHtml(ownerName) + '</div>';
        }

        var html = '';
        html += '<div class="ms-card">';
        html += '  <div class="ms-card-row">';
        html += '    <div class="ms-card-meta">';
        html += '      <div class="ms-sample-id">' + highlight(sample.name || sample.id, q) + '</div>';
        html += '      ' + pillHtml;
        html += '      <div class="ms-row"><strong>Material:</strong> ' + highlight(sample.display_sample_type || '—', q) + '</div>';
        html += '      <div class="ms-row"><strong>Purpose:</strong> ' + highlight(sample.display_sample_purpose || '—', q) + '</div>';
        html += '      <div class="ms-row"><strong>Updated:</strong> ' + fmtDate(sample.modified_at) + '</div>';
        html += '      <a class="ms-view-btn" href="' + escapeHtml(viewSampleHref(sample)) + '">View Sample</a>';
        html += '    </div>';
        html += '    <div class="ms-card-body">';
        html += '      <div class="ms-badges">' + (badges.length ? badges.join('') : '<span style="opacity:.6">No subsystem links yet.</span>') + '</div>';
        html += '      <div class="ms-description">' + highlight(desc, q) + '</div>';
        if (children.length) {
            var expanded = !!state.expanded[key];
            html += '      <button type="button" class="ms-children-toggle" data-toggle-key="' + escapeHtml(key) + '">';
            html += (expanded ? '▼' : '▶') + ' ' + children.length + ' child sample' + (children.length === 1 ? '' : 's');
            html += '      </button>';
            if (expanded) {
                children.forEach(function(child) {
                    html += '<div class="ms-child-row">';
                    html += '<span class="ms-child-id">' + escapeHtml(child.name || child.id) + '</span>';
                    html += '<span style="opacity:.7">' + escapeHtml(child.display_sample_type || '—') + ' / ' + escapeHtml(child.display_sample_purpose || '—') + '</span>';
                    html += '<span style="opacity:.7">Updated ' + escapeHtml(fmtDate(child.modified_at)) + '</span>';
                    html += '<a class="ms-view-btn" href="' + escapeHtml(viewSampleHref(child)) + '">View Sample</a>';
                    html += '</div>';
                });
            }
        }
        html += '    </div>';
        html += '  </div>';
        html += '</div>';
        return html;
    }

    function render() {
        var q = state.search.trim();
        var filtered = topLevel
            .filter(function(s) { return matchesShow(s, state.show); })
            .filter(function(s) { return matchesType(s, state.type); })
            .filter(function(s) { return matchesSearch(s, q); })
            .sort(sortFn(state.sort));

        if (!rawData.length) {
            $cards.innerHTML = '<div class="ms-empty">No samples yet. Create one with the “+ New Sample” button, or upload a project (Field / Micro / Experimental) to populate samples automatically.</div>';
            $count.textContent = '';
            return;
        }
        $count.textContent = filtered.length + ' sample' + (filtered.length === 1 ? '' : 's')
                           + (q ? ' matching "' + q + '"' : '');

        if (!filtered.length) {
            $cards.innerHTML = '<div class="ms-empty">No samples match the current filters.</div>';
            return;
        }
        $cards.innerHTML = filtered.map(function(s) { return renderCard(s, q); }).join('');
    }

    // Event wiring. Every state-changing handler also syncs URL so a
    // subsequent navigate-away → browser-back lands on the same view.
    $search.addEventListener('input', function(e) {
        state.search = e.target.value;
        syncUrl();
        render();
    });
    $sort.addEventListener('change', function(e) {
        state.sort = e.target.value;
        syncUrl();
        render();
    });
    $typeTabs.forEach(function(btn) {
        btn.addEventListener('click', function() {
            state.type = btn.getAttribute('data-type');
            $typeTabs.forEach(function(b) { b.classList.toggle('active', b === btn); });
            syncUrl();
            render();
        });
    });
    $showTabs.forEach(function(btn) {
        btn.addEventListener('click', function() {
            state.show = btn.getAttribute('data-show');
            $showTabs.forEach(function(b) { b.classList.toggle('active', b === btn); });
            syncUrl();
            render();
        });
    });
    $cards.addEventListener('click', function(e) {
        var toggle = e.target.closest && e.target.closest('[data-toggle-key]');
        if (!toggle) return;
        var key = toggle.getAttribute('data-toggle-key');
        state.expanded[key] = !state.expanded[key];
        render();
    });
    $newBtn.addEventListener('click', function() { openModal(); });

    // ---- Create-sample modal ----
    var $modal         = document.getElementById('ms-modal-overlay');
    var $modalForm     = document.getElementById('ms-modal-form');
    var $modalClose    = document.getElementById('ms-modal-close');
    var $modalCancel   = document.getElementById('ms-modal-cancel');
    var $modalSubmit   = document.getElementById('ms-modal-submit');
    var $modalError    = document.getElementById('ms-modal-error');
    var $fName         = document.getElementById('ms-f-name');
    var $parentInput   = document.getElementById('ms-f-parent');
    var $parentResults = document.getElementById('ms-parent-results');
    var $parentClear   = document.getElementById('ms-parent-clear');
    var $parentSelected= document.getElementById('ms-parent-selected');

    var selectedParent = null;  // {id, userpkey, name} or null
    var escHandler = null;

    function openModal() {
        $modal.hidden = false;
        document.body.style.overflow = 'hidden';
        setTimeout(function() { $fName.focus(); }, 0);
        escHandler = function(e) { if (e.key === 'Escape') closeModal(); };
        document.addEventListener('keydown', escHandler);
    }

    function closeModal() {
        $modal.hidden = true;
        document.body.style.overflow = '';
        $modalForm.reset();
        selectedParent = null;
        $parentResults.hidden = true;
        $parentResults.innerHTML = '';
        $parentClear.hidden = true;
        $parentSelected.hidden = true;
        $parentSelected.textContent = '';
        $modalError.hidden = true;
        $modalError.textContent = '';
        $modalSubmit.disabled = false;
        $modalSubmit.textContent = 'Create Sample';
        if (escHandler) {
            document.removeEventListener('keydown', escHandler);
            escHandler = null;
        }
    }

    // Click on the overlay backdrop (not the panel) closes.
    $modal.addEventListener('click', function(e) {
        if (e.target === $modal) closeModal();
    });
    $modalClose.addEventListener('click', closeModal);
    $modalCancel.addEventListener('click', closeModal);

    // Parent autocomplete: searches the rawData already on the page
    // (which already represents the full set of samples this user can
    // read — same visibility predicate the createSample parent_accessible
    // check uses, so a hit here will pass server-side validation).
    $parentInput.addEventListener('input', function(e) {
        // Typing invalidates any prior selection.
        selectedParent = null;
        $parentSelected.hidden = true;
        $parentSelected.textContent = '';
        var v = e.target.value;
        $parentClear.hidden = !v;
        var q = v.trim().toLowerCase();
        if (!q) { $parentResults.hidden = true; return; }
        var hits = rawData.filter(function(s) {
            var hay = ((s.name || '') + ' ' + s.id).toLowerCase();
            return hay.indexOf(q) !== -1;
        }).slice(0, 10);
        if (!hits.length) {
            $parentResults.innerHTML = '<div class="ms-parent-noresults">No matches.</div>';
            $parentResults.hidden = false;
            return;
        }
        $parentResults.innerHTML = hits.map(function(s) {
            return '<div class="ms-parent-hit" data-id="' + escapeHtml(s.id) + '" data-uk="' + s.userpkey + '">'
                 + '<strong>' + escapeHtml(s.name || s.id) + '</strong>'
                 + (s.display_sample_type ? ' <span style="opacity:.7">' + escapeHtml(s.display_sample_type) + '</span>' : '')
                 + '</div>';
        }).join('');
        $parentResults.hidden = false;
    });
    $parentResults.addEventListener('click', function(e) {
        var hit = e.target.closest && e.target.closest('[data-id]');
        if (!hit) return;
        var id = hit.getAttribute('data-id');
        var uk = parseInt(hit.getAttribute('data-uk'), 10);
        var match = rawData.find(function(s) { return s.id === id && s.userpkey === uk; });
        if (!match) return;
        selectedParent = {id: id, userpkey: uk, name: match.name || id};
        $parentInput.value = '';
        $parentResults.hidden = true;
        $parentResults.innerHTML = '';
        $parentClear.hidden = false;
        $parentSelected.textContent = 'Selected parent: ' + selectedParent.name;
        $parentSelected.hidden = false;
    });
    $parentClear.addEventListener('click', function() {
        selectedParent = null;
        $parentInput.value = '';
        $parentClear.hidden = true;
        $parentResults.hidden = true;
        $parentResults.innerHTML = '';
        $parentSelected.hidden = true;
        $parentSelected.textContent = '';
    });
    // Click outside the picker hides results.
    document.addEventListener('click', function(e) {
        if (!$modal || $modal.hidden) return;
        if (!$parentResults.contains(e.target) && e.target !== $parentInput) {
            $parentResults.hidden = true;
        }
    });

    // Lat/lng are type="text" with inputmode="decimal" (not type="number"):
    // both Firefox and Safari accept arbitrary typed text into type="number"
    // visually while returning '' from .value — so an oninput filter on
    // .value has nothing to clean up and the garbage stays on-screen.
    // type="text" guarantees .value matches what the user sees. We then
    // strip non-numeric chars on every input, allowing '-' / '.' / '-.'
    // mid-typing so users can build negative decimals without fighting
    // the cursor.
    function constrainNumericInput(input) {
        input.addEventListener('input', function(e) {
            var v = e.target.value;
            var m = v.match(/^-?\d*\.?\d*/);
            var stripped = m ? m[0] : '';
            if (v !== stripped) {
                e.target.value = stripped;
                try { e.target.setSelectionRange(stripped.length, stripped.length); } catch (_) {}
            }
        });
    }
    constrainNumericInput(document.getElementById('ms-f-lat'));
    constrainNumericInput(document.getElementById('ms-f-lng'));

    // Vocab "Other (free text)" toggle: when select === sentinel, reveal
    // the companion <input> for free-text entry. Sentinel kept in sync
    // with samples_vocab_render_*_select() server-side.
    var VOCAB_OTHER_SENTINEL = '__other__';
    function bindVocabOther(selectId, otherId) {
        var sel = document.getElementById(selectId);
        var oth = document.getElementById(otherId);
        if (!sel || !oth) return;
        sel.addEventListener('change', function() {
            var isOther = sel.value === VOCAB_OTHER_SENTINEL;
            oth.hidden = !isOther;
            if (isOther) oth.focus();
        });
    }
    bindVocabOther('ms-f-type',    'ms-f-type-other');
    bindVocabOther('ms-f-purpose', 'ms-f-purpose-other');

    // Read a vocab-with-Other field: returns the typed text when the
    // sentinel is selected, otherwise the selected option value.
    function readVocabField(selectId, otherId) {
        var sel = document.getElementById(selectId);
        var oth = document.getElementById(otherId);
        if (!sel) return '';
        if (sel.value === VOCAB_OTHER_SENTINEL) return (oth ? oth.value.trim() : '');
        return sel.value;
    }

    $modalForm.addEventListener('submit', function(e) {
        e.preventDefault();
        $modalError.hidden = true;
        $modalError.textContent = '';

        var name = $fName.value.trim();
        if (!name) {
            $modalError.textContent = 'Sample ID is required.';
            $modalError.hidden = false;
            $fName.focus();
            return;
        }

        var body = {name: name};
        var optionalText = [
            ['ms-f-igsn',    'igsn'],
            ['ms-f-desc',    'description'],
            ['ms-f-notes',   'notes'],
        ];
        optionalText.forEach(function(pair) {
            var v = document.getElementById(pair[0]).value.trim();
            if (v) body[pair[1]] = v;
        });
        var typeVal    = readVocabField('ms-f-type',    'ms-f-type-other');
        var purposeVal = readVocabField('ms-f-purpose', 'ms-f-purpose-other');
        if (typeVal)    body.display_sample_type    = typeVal;
        if (purposeVal) body.display_sample_purpose = purposeVal;
        // Number() rather than parseFloat — parseFloat('12abc') returns 12,
        // which would silently accept garbage. Number('12abc') returns NaN.
        var latRaw = document.getElementById('ms-f-lat').value.trim();
        var lngRaw = document.getElementById('ms-f-lng').value.trim();
        if (latRaw !== '') {
            var lat = Number(latRaw);
            if (!Number.isFinite(lat) || lat < -90 || lat > 90) {
                $modalError.textContent = 'Latitude must be a number between -90 and 90.';
                $modalError.hidden = false;
                return;
            }
            body.latitude = lat;
        }
        if (lngRaw !== '') {
            var lng = Number(lngRaw);
            if (!Number.isFinite(lng) || lng < -180 || lng > 180) {
                $modalError.textContent = 'Longitude must be a number between -180 and 180.';
                $modalError.hidden = false;
                return;
            }
            body.longitude = lng;
        }
        if (selectedParent) {
            body.parent_sample_id = selectedParent.id;
            body.parent_userpkey  = selectedParent.userpkey;
        }

        $modalSubmit.disabled = true;
        $modalSubmit.textContent = 'Creating…';

        fetch('/samples_create.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
        }).then(function(r) {
            return r.json().then(
                function(j) { return {status: r.status, body: j}; },
                function()  { return {status: r.status, body: null}; }
            );
        }).then(function(res) {
            if (res.body && res.body.ok && res.body.sample) {
                onCreateSuccess(res.body.sample);
                return;
            }
            var code = res.body && res.body.error ? res.body.error : 'unknown';
            var msg;
            switch (code) {
                case 'duplicate_id':
                    msg = 'A sample with that ID already exists. Please try again.'; break;
                case 'parent_not_accessible':
                    msg = 'The selected parent sample is not accessible.'; break;
                case 'parent_pair_required':
                    msg = 'Parent selection is incomplete — clear and re-select.'; break;
                case 'not_authenticated':
                    msg = 'Your session has expired. Please reload the page and sign in again.'; break;
                case 'invalid_json':
                    msg = 'The server could not read the request. Please reload and try again.'; break;
                default:
                    msg = 'Could not create sample (' + code + ').';
            }
            $modalError.textContent = msg;
            $modalError.hidden = false;
            $modalSubmit.disabled = false;
            $modalSubmit.textContent = 'Create Sample';
        }).catch(function() {
            $modalError.textContent = 'Network error — please try again.';
            $modalError.hidden = false;
            $modalSubmit.disabled = false;
            $modalSubmit.textContent = 'Create Sample';
        });
    });

    function onCreateSuccess(sample) {
        // New samples have no subsystem links yet — badges all zero. We
        // mirror only the spine fields the list renderer needs; the API's
        // richer response (composition / subsystem_links / etc.) is
        // already covered by the sample's own Overview page.
        var spine = {
            id: sample.id,
            userpkey: sample.userpkey,
            name: sample.name,
            igsn: sample.igsn,
            description: sample.description,
            notes: sample.notes,
            latitude: sample.latitude,
            longitude: sample.longitude,
            display_sample_type: sample.display_sample_type,
            display_sample_purpose: sample.display_sample_purpose,
            parent_sample_id: sample.parent_sample_id,
            parent_userpkey: sample.parent_userpkey,
            created_at: sample.created_at,
            created_by: sample.created_by,
            modified_at: sample.modified_at,
            modified_by: sample.modified_by,
            badges: {field: 0, micro: 0, experimental: 0},
        };
        rawData.unshift(spine);
        visibleKey.add(spine.id + '|' + spine.userpkey);
        if (spine.parent_sample_id && spine.parent_userpkey
            && visibleKey.has(spine.parent_sample_id + '|' + spine.parent_userpkey)) {
            var pk = spine.parent_sample_id + '|' + spine.parent_userpkey;
            if (!childrenByParent[pk]) childrenByParent[pk] = [];
            childrenByParent[pk].push(spine);
            // Child of a visible parent — only surfaces via the parent's
            // expand toggle, so leave topLevel alone.
        } else {
            topLevel.unshift(spine);
        }
        // Open the new sample's overview in a new tab. The MySamples
        // list stays as the user's anchor (matches the "View Subsystem
        // …" new-tab convention from samples/ui-newtab-subsystem-views).
        var href = '/samples/' + encodeURIComponent(spine.userpkey) + '/' + encodeURIComponent(spine.id);
        window.open(href, '_blank', 'noopener');
        closeModal();
        render();
    }

    // ---- Pending invitations banner ----
    // The banner wrapper only exists in the DOM when the server saw at
    // least one pending invitation. The data tag is always present (may
    // be []), so we read it once and rebuild the list as the user accepts
    // or denies. Accept reloads the page to surface the newly-accessible
    // sample in the list (server-side filter is the source of truth).
    var rawInvitations = JSON.parse(document.getElementById('ms-invitations-data').textContent || '[]');
    var $invitationsWrap = document.getElementById('ms-invitations');
    var $invitationsList = document.getElementById('ms-invitations-list');

    function renderInvitations() {
        if (!$invitationsWrap || !$invitationsList) return;
        if (!rawInvitations.length) {
            $invitationsWrap.hidden = true;
            return;
        }
        $invitationsList.innerHTML = rawInvitations.map(function(inv) {
            var levelLabel = inv.permission_level === 'edit' ? 'Edit' : 'Read-only';
            return '<div class="ms-invitation-row" data-uuid="' + escapeHtml(inv.uuid) + '" data-sample-id="' + escapeHtml(inv.sample_id) + '">'
                 +   '<div class="ms-invitation-avatar">' + escapeHtml(inv.inviter_initials || '?') + '</div>'
                 +   '<div class="ms-invitation-text">'
                 +     '<strong>' + escapeHtml(inv.inviter_name || '(unknown)') + '</strong>'
                 +     ' invited you to collaborate on '
                 +     '<strong>' + escapeHtml(inv.sample_name || inv.sample_id) + '</strong>'
                 +     ' <span class="ms-invitation-level">(' + escapeHtml(levelLabel) + ')</span>'
                 +   '</div>'
                 +   '<button type="button" class="ms-invitation-btn accept" data-action="accept">Accept</button>'
                 +   '<button type="button" class="ms-invitation-btn deny"   data-action="deny">Decline</button>'
                 + '</div>';
        }).join('');
    }

    if ($invitationsList) {
        $invitationsList.addEventListener('click', function(e) {
            if (!e.target.classList || !e.target.classList.contains('ms-invitation-btn')) return;
            var row    = e.target.closest('.ms-invitation-row');
            var action = e.target.getAttribute('data-action');
            var uuid   = row.getAttribute('data-uuid');
            var sid    = row.getAttribute('data-sample-id');
            var siblings = row.querySelectorAll('.ms-invitation-btn');
            siblings.forEach(function(b) { b.disabled = true; });

            fetch('/samples_invitations.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: action, sample_id: sid, uuid: uuid}),
            }).then(function(r) {
                return r.json().then(
                    function(j) { return {status: r.status, body: j}; },
                    function()  { return {status: r.status, body: null}; }
                );
            }).then(function(res) {
                if (res.body && res.body.ok) {
                    if (action === 'accept') {
                        // Page reload surfaces the new sample in the list +
                        // updates the badge counts. Cheaper than threading
                        // a second listMySamples + listMyInvitations round-trip
                        // into the existing render path.
                        window.location.reload();
                    } else {
                        rawInvitations = rawInvitations.filter(function(i) { return i.uuid !== uuid; });
                        renderInvitations();
                    }
                    return;
                }
                siblings.forEach(function(b) { b.disabled = false; });
                var err = res.body && res.body.error ? res.body.error : 'unknown';
                var msg;
                switch (err) {
                    case 'duplicate_id_conflict':
                        msg = 'You already own a sample with this ID. Cannot accept this invitation.'; break;
                    case 'already_accepted':
                        msg = 'This invitation has already been accepted.'; break;
                    case 'invitation_not_found':
                        msg = 'This invitation no longer exists. Refreshing the page.'; break;
                    case 'not_authenticated':
                        msg = 'Your session has expired. Please reload and sign in.'; break;
                    default:
                        msg = 'Could not ' + action + ' (' + err + ').';
                }
                alert(msg);
                if (err === 'invitation_not_found' || err === 'already_accepted') {
                    window.location.reload();
                }
            }).catch(function() {
                siblings.forEach(function(b) { b.disabled = false; });
                alert('Network error — please try again.');
            });
        });
        renderInvitations();
    }

    render();

    // Apply the pending scroll position AFTER the initial render so the
    // cards are in the DOM before we ask the browser to scroll. Defer to
    // the next animation frame for layout to settle.
    if (pendingScroll !== null) {
        requestAnimationFrame(function() {
            window.scrollTo(0, pendingScroll);
        });
    }
})();
</script>

<?php
include("includes/mfooter.php");
