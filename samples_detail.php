<?php
/**
 * File: samples_detail.php
 * Description: Sample Overview — public detail page for a single sample.
 *              Design §12.2 + mockups (sample_overview_mockup.png,
 *              …parent_child_1.png, …parent_child_2.png). Routed via
 *              /samples/{owner_pkey}/{id} from the .htaccess rewrite.
 *
 *              Auth: §12.2 specifies the Share URL is public-read for
 *              any logged-in user. logincheck.php gates the page; the
 *              spine/links/family reads bypass StraboSamplesService's
 *              canRead because the URL itself acts as the auth gate.
 *              Editing (Collaborate / Edit buttons) is conditionally
 *              rendered based on ownership / accepted-collab grants.
 *
 *              Layout (Phase 4 v1): card-per-link for §16 #9. The
 *              render layer takes a flat list of subsystem_links rows
 *              and emits one card per row. Swapping to a card-per-
 *              subsystem-with-picker later only touches the renderer;
 *              the data path is layout-agnostic.
 *
 *              Family-tree widget (§12.2.1): lightweight SVG radial
 *              layout — focus center, parent above, children arc'd
 *              below, click for a detail card with "View Sample" CTA
 *              that navigates to the neighbor's overview. The full
 *              Neo4j-Graph-Browser feel (force layout, drag, smooth
 *              animations) is a follow-on iteration; this v1 covers
 *              the data + interaction shape so the widget is real
 *              from launch and grows polish later.
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

$ownerPkey = isset($_GET['owner']) ? (int)$_GET['owner'] : 0;
$sampleId  = isset($_GET['id'])    ? trim((string)$_GET['id']) : '';

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);

// ---- Direct spine read (public-read per §12.2, bypasses canRead). ----
$spineRow = null;
if ($ownerPkey > 0 && $sampleId !== '') {
    $spineRow = $db->get_row_prepared(
        "SELECT id, userpkey, name, igsn, description, notes,
                latitude, longitude,
                display_sample_type, display_sample_purpose,
                parent_sample_id, parent_userpkey,
                field_data, micro_data, experimental_data,
                created_at, created_by, modified_at, modified_by
           FROM strabosamples.samples
          WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
}

$notFound = !$spineRow;

if (!$notFound) {
    $sample = array(
        'id'                     => $spineRow->id,
        'userpkey'               => (int)$spineRow->userpkey,
        'name'                   => $spineRow->name,
        'igsn'                   => $spineRow->igsn,
        'description'            => $spineRow->description,
        'notes'                  => $spineRow->notes,
        'latitude'               => $spineRow->latitude !== null ? (float)$spineRow->latitude  : null,
        'longitude'              => $spineRow->longitude !== null ? (float)$spineRow->longitude : null,
        'display_sample_type'    => $spineRow->display_sample_type,
        'display_sample_purpose' => $spineRow->display_sample_purpose,
        'parent_sample_id'       => $spineRow->parent_sample_id,
        'parent_userpkey'        => $spineRow->parent_userpkey !== null ? (int)$spineRow->parent_userpkey : null,
        'field_data'             => $spineRow->field_data        ? json_decode($spineRow->field_data,        true) : null,
        'micro_data'             => $spineRow->micro_data        ? json_decode($spineRow->micro_data,        true) : null,
        'experimental_data'      => $spineRow->experimental_data ? json_decode($spineRow->experimental_data, true) : null,
        'created_at'             => $spineRow->created_at,
        'created_by'             => $spineRow->created_by !== null ? (int)$spineRow->created_by : null,
        'modified_at'            => $spineRow->modified_at,
        'modified_by'            => $spineRow->modified_by !== null ? (int)$spineRow->modified_by : null,
    );

    // Subsystem links — drives card-per-link rendering.
    $linkRows = $db->get_results_prepared(
        "SELECT subsystem, reference_id, reference_userpkey, reference_metadata,
                created_at, modified_at
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2
          ORDER BY subsystem, reference_id",
        array($sampleId, $ownerPkey)
    );
    $links = array();
    if (is_array($linkRows)) {
        foreach ($linkRows as $r) {
            $links[] = array(
                'subsystem'          => $r->subsystem,
                'reference_id'       => $r->reference_id,
                'reference_userpkey' => (int)$r->reference_userpkey,
                'reference_metadata' => $r->reference_metadata ? json_decode($r->reference_metadata, true) : null,
                'created_at'         => $r->created_at,
                'modified_at'        => $r->modified_at,
            );
        }
    }

    // Collaborators (accepted only — pending invites stay private to the owner).
    $collabRows = $db->get_results_prepared(
        "SELECT c.collaborator_pkey, c.permission_level, c.accepted, c.accepted_at,
                u.firstname, u.lastname, u.email
           FROM strabosamples.sample_collaborators c
           JOIN users u ON u.pkey = c.collaborator_pkey
          WHERE c.sample_id=$1 AND c.sample_userpkey=$2
            AND c.accepted = TRUE AND c.removed_at IS NULL
          ORDER BY c.accepted_at",
        array($sampleId, $ownerPkey)
    );
    $collaborators = array();
    if (is_array($collabRows)) {
        foreach ($collabRows as $c) {
            $name = trim(($c->firstname ?: '') . ' ' . ($c->lastname ?: ''));
            $collaborators[] = array(
                'pkey'             => (int)$c->collaborator_pkey,
                'name'             => $name !== '' ? $name : $c->email,
                'email'            => $c->email,
                'permission_level' => $c->permission_level,
                'initials'         => strtoupper(substr($c->firstname ?: $c->email, 0, 1)
                                              . substr($c->lastname  ?: '', 0, 1)),
            );
        }
    }

    // Owner display name for the metadata block.
    $ownerRow = $db->get_row_prepared(
        "SELECT firstname, lastname, email FROM users WHERE pkey = $1",
        array($ownerPkey)
    );
    $ownerName = '';
    if ($ownerRow) {
        $ownerName = trim(($ownerRow->firstname ?: '') . ' ' . ($ownerRow->lastname ?: ''));
        if ($ownerName === '') $ownerName = $ownerRow->email;
    }

    // Family snapshot — direct queries so public-read works.
    $family = array('focus' => null, 'parent' => null, 'children' => array());
    $family['focus'] = array(
        'id'                     => $sample['id'],
        'userpkey'               => $sample['userpkey'],
        'name'                   => $sample['name'],
        'display_sample_type'    => $sample['display_sample_type'],
        'display_sample_purpose' => $sample['display_sample_purpose'],
    );
    if ($sample['parent_sample_id'] !== null && $sample['parent_userpkey'] !== null) {
        $pRow = $db->get_row_prepared(
            "SELECT id, userpkey, name, display_sample_type, display_sample_purpose
               FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sample['parent_sample_id'], $sample['parent_userpkey'])
        );
        if ($pRow) {
            $family['parent'] = array(
                'id'                     => $pRow->id,
                'userpkey'               => (int)$pRow->userpkey,
                'name'                   => $pRow->name,
                'display_sample_type'    => $pRow->display_sample_type,
                'display_sample_purpose' => $pRow->display_sample_purpose,
            );
        } else {
            $family['parent'] = array(
                'orphaned'         => true,
                'parent_sample_id' => $sample['parent_sample_id'],
                'parent_userpkey'  => $sample['parent_userpkey'],
            );
        }
    }
    $childRows = $db->get_results_prepared(
        "SELECT id, userpkey, name, display_sample_type, display_sample_purpose
           FROM strabosamples.samples
          WHERE parent_sample_id=$1 AND parent_userpkey=$2
          ORDER BY created_at",
        array($sampleId, $ownerPkey)
    );
    if (is_array($childRows)) {
        foreach ($childRows as $c) {
            $family['children'][] = array(
                'id'                     => $c->id,
                'userpkey'               => (int)$c->userpkey,
                'name'                   => $c->name,
                'display_sample_type'    => $c->display_sample_type,
                'display_sample_purpose' => $c->display_sample_purpose,
            );
        }
    }

    // Permission flags: drive Edit/Collaborate button visibility.
    $isOwner = ((int)$userpkey === $ownerPkey);
    $canEdit = $isOwner;
    if (!$canEdit) {
        $row = $db->get_row_prepared(
            "SELECT 1 AS ok FROM strabosamples.sample_collaborators
              WHERE sample_id=$1 AND sample_userpkey=$2
                AND collaborator_pkey=$3 AND accepted=TRUE
                AND removed_at IS NULL AND permission_level='edit'
              LIMIT 1",
            array($sampleId, $ownerPkey, (int)$userpkey)
        );
        $canEdit = (bool)$row;
    }

    $payload = array(
        'sample'        => $sample,
        'owner'         => array('pkey' => $ownerPkey, 'name' => $ownerName),
        'links'         => $links,
        'collaborators' => $collaborators,
        'family'        => $family,
        'permissions'   => array('isOwner' => $isOwner, 'canEdit' => $canEdit),
    );
}

// Build the canonical Share URL from the request host so it's correct
// across staging / prod without hard-coding.
$shareScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$shareHost   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'strabospot.org';
$shareUrl    = $shareScheme . '://' . $shareHost . '/samples/' . $ownerPkey . '/' . rawurlencode((string)$sampleId);

include("includes/mheader.php");
?>

<style>
.sd-wrap { max-width: 1100px; margin: 0 auto; padding: 0 1em; color: #ffffff; }
.sd-header {
    text-align: center;
    margin-bottom: 1em;
}
.sd-header h1 {
    color: #ffffff;
    margin-bottom: 0.5em;
    border-bottom: 3px solid #e44c65;
    display: inline-block;
    padding-bottom: 0.3em;
}
.sd-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75em;
    justify-content: center;
    align-items: center;
    margin-bottom: 1.25em;
}
.sd-action-btn {
    background: #e44c65;
    color: #ffffff;
    border: none;
    border-radius: 4px;
    padding: 0.55em 1.05em;
    cursor: pointer;
    font-size: 0.95em;
    text-decoration: none;
}
.sd-action-btn:hover { background: #f06880; color: #ffffff; }
.sd-action-btn.outline {
    background: transparent;
    color: #e44c65;
    border: 1px solid #e44c65;
}
.sd-action-btn.outline:hover { background: rgba(228, 76, 101, 0.12); }
.sd-share-line {
    color: rgba(255, 255, 255, 0.75);
    font-size: 0.92em;
    word-break: break-all;
}
.sd-share-line strong { color: #ffffff; margin-right: 0.4em; }

.sd-avatars { display: inline-flex; gap: 0.4em; margin-left: 0.4em; }
.sd-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #4fa8c1;
    color: #ffffff;
    font-size: 0.78em;
    font-weight: 700;
    border: 2px solid rgba(255,255,255,0.25);
    cursor: default;
}
.sd-avatar[title] { cursor: help; }

.sd-section {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 6px;
    padding: 1.25em 1.5em;
    margin-bottom: 1.25em;
}
.sd-section h3 {
    color: #ffffff;
    font-size: 1.1em;
    margin-bottom: 0.6em;
    border-bottom: 1px solid rgba(255,255,255,0.12);
    padding-bottom: 0.4em;
}
.sd-metadata-row {
    display: grid;
    grid-template-columns: minmax(320px, 1fr) 280px;
    gap: 1.5em;
    align-items: start;
}
.sd-metadata-fields { color: rgba(255, 255, 255, 0.88); font-size: 0.95em; line-height: 1.7; }
.sd-metadata-fields .sd-field-label {
    color: rgba(255, 255, 255, 0.6);
    margin-right: 0.5em;
    font-weight: 600;
}
.sd-metadata-actions { margin-top: 0.9em; display: flex; gap: 0.5em; flex-wrap: wrap; }

.sd-family-widget {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
    padding: 0.5em;
    height: 240px;
    position: relative;
}
.sd-family-widget svg { width: 100%; height: 100%; display: block; }
.sd-family-node { cursor: pointer; transition: transform 0.15s ease; }
.sd-family-node:hover { transform: scale(1.05); transform-origin: center; }
.sd-family-node.focus circle { fill: #e44c65; stroke: rgba(255,255,255,0.5); }
.sd-family-node circle { fill: rgba(255,255,255,0.85); stroke: rgba(255,255,255,0.35); stroke-width: 2; }
.sd-family-node text { font-size: 11px; fill: #ffffff; text-anchor: middle; pointer-events: none; }
.sd-family-edge { stroke: rgba(255,255,255,0.45); stroke-width: 1.5; fill: none; }
.sd-family-detail {
    position: absolute;
    top: 12px;
    left: 12px;
    right: 12px;
    background: rgba(30, 30, 45, 0.96);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 4px;
    padding: 0.7em 0.85em;
    font-size: 0.85em;
    color: #ffffff;
    display: none;
}
.sd-family-detail.visible { display: block; }
.sd-family-detail .sd-family-close {
    float: right;
    cursor: pointer;
    color: rgba(255,255,255,0.6);
    margin-left: 0.6em;
}
.sd-family-detail .sd-action-btn { margin-top: 0.5em; display: inline-block; }

.sd-type-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4em;
    margin-bottom: 1.25em;
    color: rgba(255,255,255,0.7);
    font-size: 0.95em;
    align-items: center;
}
.sd-tab {
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.2);
    color: rgba(255,255,255,0.85);
    border-radius: 999px;
    padding: 0.35em 0.95em;
    cursor: pointer;
    font-size: 0.9em;
}
.sd-tab:hover:not(.active) { background: rgba(255,255,255,0.12); }
.sd-tab.active { background: #e44c65; border-color: #e44c65; color: #ffffff; }

.sd-link-card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 6px;
    padding: 1em 1.2em;
    margin-bottom: 0.9em;
    color: #ffffff;
    position: relative;
}
.sd-link-card-head {
    display: flex;
    align-items: center;
    gap: 0.6em;
    color: rgba(255,255,255,0.8);
    font-size: 0.92em;
    margin-bottom: 0.6em;
}
.sd-link-card-head .sd-link-icon {
    width: 22px;
    height: 22px;
    opacity: 0.9;
}
.sd-link-card-head .sd-view-btn { margin-left: auto; }
.sd-link-fields {
    color: rgba(255,255,255,0.88);
    font-size: 0.92em;
    line-height: 1.55;
}
.sd-link-fields .sd-field-label {
    color: rgba(255,255,255,0.55);
    margin-right: 0.4em;
    font-weight: 600;
}
.sd-view-btn {
    background: #e44c65;
    color: #ffffff;
    border: none;
    border-radius: 4px;
    padding: 0.4em 0.85em;
    font-size: 0.85em;
    cursor: pointer;
    text-decoration: none;
}
.sd-view-btn:hover { background: #f06880; color: #ffffff; }

.sd-empty-cards {
    text-align: center;
    margin: 1.5em 0 3em 0;
    color: rgba(255,255,255,0.55);
}

.sd-notfound {
    text-align: center;
    margin: 4em auto;
    color: rgba(255,255,255,0.75);
}

@media (max-width: 800px) {
    .sd-metadata-row { grid-template-columns: 1fr; }
    .sd-family-widget { height: 280px; }
}
</style>

<div id="main" class="wrapper style1">
    <div class="container">

<?php if ($notFound): ?>
        <div class="sd-notfound">
            <h2>Sample not found.</h2>
            <p>The sample may have been removed, or you may have followed a broken link.</p>
            <p><a class="sd-action-btn" href="/my_samples.php">Back to My Samples</a></p>
        </div>
<?php else: ?>
        <div class="sd-wrap">
            <div class="sd-header">
                <h1 id="sd-title"></h1>
            </div>

            <div class="sd-actions">
                <a class="sd-action-btn outline" href="#" id="sd-share-btn">Share</a>
                <div class="sd-share-line"><strong>SAMPLE URL:</strong><span id="sd-share-url"></span></div>
                <a class="sd-action-btn" href="#" id="sd-collab-btn" style="display:none">Collaborate</a>
                <div class="sd-avatars" id="sd-avatars"></div>
            </div>

            <div class="sd-section">
                <h3>Sample Metadata</h3>
                <div class="sd-metadata-row">
                    <div>
                        <div class="sd-metadata-fields" id="sd-metadata-fields"></div>
                        <div class="sd-metadata-actions">
                            <a class="sd-action-btn" href="#" id="sd-edit-btn" style="display:none">Edit Metadata</a>
                            <a class="sd-action-btn outline" href="#" id="sd-changelog-btn">View Changelog</a>
                        </div>
                    </div>
                    <div class="sd-family-widget" id="sd-family-widget">
                        <svg viewBox="0 0 280 220" id="sd-family-svg"></svg>
                        <div class="sd-family-detail" id="sd-family-detail"></div>
                    </div>
                </div>
            </div>

            <div class="sd-type-tabs">
                <span>Type:</span>
                <button type="button" class="sd-tab active" data-type="all">All</button>
                <button type="button" class="sd-tab" data-type="field">StraboField</button>
                <button type="button" class="sd-tab" data-type="micro">StraboMicro</button>
                <button type="button" class="sd-tab" data-type="experimental">StraboExperimental</button>
            </div>

            <div id="sd-cards"></div>

            <div class="bottomSpacer"></div>
        </div>
<?php endif; ?>

    </div>
</div>

<?php if (!$notFound): ?>
<script type="application/json" id="sd-data"><?php echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="application/json" id="sd-share-url-data"><?php echo json_encode($shareUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="text/javascript">
(function() {
    'use strict';

    var payload  = JSON.parse(document.getElementById('sd-data').textContent || '{}');
    var shareUrl = JSON.parse(document.getElementById('sd-share-url-data').textContent || '""');
    var sample = payload.sample, owner = payload.owner, links = payload.links || [];
    var collabs = payload.collaborators || [], family = payload.family || {};
    var perms = payload.permissions || {isOwner: false, canEdit: false};

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function fmtDate(ts) {
        if (!ts) return '—';
        var d = new Date(ts);
        if (isNaN(d.getTime())) return ts;
        return d.toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: 'numeric'});
    }
    function field(label, value) {
        if (value === null || value === undefined || value === '') return '';
        return '<div><span class="sd-field-label">' + escapeHtml(label) + ':</span> ' + escapeHtml(value) + '</div>';
    }
    function subsystemLabel(s) {
        if (s === 'field')        return 'StraboField';
        if (s === 'micro')        return 'StraboMicro';
        if (s === 'experimental') return 'StraboExperimental';
        return s;
    }
    function subsystemIcon(s) {
        if (s === 'field') {
            return '<svg class="sd-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 19l14-14M5 19h6M5 19v-6"/></svg>';
        }
        if (s === 'micro') {
            return '<svg class="sd-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/></svg>';
        }
        return '<svg class="sd-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6M10 3v6L6 18a3 3 0 0 0 3 4h6a3 3 0 0 0 3-4l-4-9V3"/></svg>';
    }

    function viewSampleHref(node) {
        return '/samples/' + encodeURIComponent(node.userpkey) + '/' + encodeURIComponent(node.id);
    }

    // ---- Header + metadata block ----
    document.getElementById('sd-title').textContent = 'Sample: ' + (sample.name || sample.id);
    var shareEl = document.getElementById('sd-share-url');
    shareEl.textContent = shareUrl;
    document.getElementById('sd-share-btn').addEventListener('click', function(e) {
        e.preventDefault();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(shareUrl).then(function() {
                this.textContent = 'Copied!';
                setTimeout(function() { document.getElementById('sd-share-btn').textContent = 'Share'; }, 1200);
            }.bind(this));
        }
    });

    var metaHtml = '';
    metaHtml += field('Sample ID',                    sample.id);
    metaHtml += field('Sample Owner',                 owner && owner.name);
    metaHtml += field('Last Updated',                 fmtDate(sample.modified_at));
    metaHtml += field('Sample Type',                  sample.display_sample_type);
    metaHtml += field('Sample Purpose',               sample.display_sample_purpose);
    if (sample.latitude !== null && sample.longitude !== null) {
        metaHtml += field('Current Sample Location', sample.latitude.toFixed(6) + ', ' + sample.longitude.toFixed(6));
    }
    metaHtml += field('IGSN',                         sample.igsn);
    metaHtml += field('Description',                  sample.description);
    metaHtml += field('Notes',                        sample.notes);
    document.getElementById('sd-metadata-fields').innerHTML = metaHtml || '<div style="opacity:.6">No metadata recorded.</div>';

    // Edit + Collaborate visibility from perms.
    if (perms.canEdit) document.getElementById('sd-edit-btn').style.display    = 'inline-block';
    if (perms.isOwner) document.getElementById('sd-collab-btn').style.display  = 'inline-block';
    document.getElementById('sd-edit-btn').addEventListener('click', function(e) {
        e.preventDefault();
        alert('Sample editing UI is on its way — the API endpoints are live (PUT /samplesdb/sample/{id}).');
    });
    document.getElementById('sd-collab-btn').addEventListener('click', function(e) {
        e.preventDefault();
        alert('Collaborator management UI is on its way — the API endpoints are live (POST /samplesdb/sample/{id}/collaborators).');
    });
    document.getElementById('sd-changelog-btn').addEventListener('click', function(e) {
        e.preventDefault();
        alert('Changelog viewer is on its way — feed available at GET /samplesdb/sample/' + sample.id + '/changelog?owner=' + owner.pkey);
    });

    // ---- Collaborator avatars ----
    var avatarsHtml = collabs.map(function(c) {
        var title = c.name + ' (' + c.permission_level + ')';
        return '<span class="sd-avatar" title="' + escapeHtml(title) + '">' + escapeHtml(c.initials || '?') + '</span>';
    }).join('');
    document.getElementById('sd-avatars').innerHTML = avatarsHtml;

    // ---- Family tree widget (simple radial; §12.2.1 v1) ----
    (function renderFamily() {
        var svg = document.getElementById('sd-family-svg');
        var detail = document.getElementById('sd-family-detail');
        var W = 280, H = 220, cx = W/2, cy = H/2 + 10;
        var children = family.children || [];
        var parent = family.parent;

        var positions = [];
        positions.push({key: 'focus', x: cx, y: cy, label: family.focus && (family.focus.name || family.focus.id) || sample.id, node: family.focus});

        if (parent) {
            positions.push({
                key: 'parent', x: cx, y: 30,
                label: parent.orphaned ? '(inaccessible)' : (parent.name || parent.id),
                node: parent,
            });
        }

        if (children.length) {
            // Arc the children below the focus.
            var arcR = 70, spanDeg = Math.min(180, 30 * children.length + 60);
            var startDeg = 90 + spanDeg/2, stepDeg = children.length > 1 ? spanDeg / (children.length - 1) : 0;
            children.forEach(function(c, i) {
                var deg = children.length === 1 ? 90 : (startDeg - stepDeg * i);
                var rad = deg * Math.PI / 180;
                positions.push({
                    key: 'child-' + i, x: cx + arcR * Math.cos(rad), y: cy + arcR * Math.sin(rad),
                    label: c.name || c.id, node: c,
                });
            });
        }

        // Edges from focus.
        var edges = '';
        positions.forEach(function(p) {
            if (p.key === 'focus') return;
            edges += '<line class="sd-family-edge" x1="' + cx + '" y1="' + cy + '" x2="' + p.x + '" y2="' + p.y + '"/>';
        });

        var nodes = positions.map(function(p, i) {
            var safe = escapeHtml(p.label).substring(0, 18);
            return '<g class="sd-family-node ' + (p.key === 'focus' ? 'focus' : '') + '" data-idx="' + i + '">'
                + '<circle cx="' + p.x + '" cy="' + p.y + '" r="18"/>'
                + '<text x="' + p.x + '" y="' + (p.y + 32) + '">' + safe + '</text>'
                + '</g>';
        }).join('');

        svg.innerHTML = edges + nodes;

        svg.addEventListener('click', function(e) {
            var g = e.target.closest && e.target.closest('.sd-family-node');
            if (!g) { detail.classList.remove('visible'); return; }
            var idx = parseInt(g.getAttribute('data-idx'), 10);
            var p = positions[idx];
            if (!p || !p.node) return;
            if (p.key === 'focus') { detail.classList.remove('visible'); return; }
            var n = p.node;
            var html = '';
            html += '<span class="sd-family-close" data-close="1">×</span>';
            if (n.orphaned) {
                html += '<div><strong>Parent (inaccessible)</strong></div>';
                html += '<div style="opacity:.7">id: ' + escapeHtml(n.parent_sample_id) + ' / userpkey: ' + n.parent_userpkey + '</div>';
            } else {
                html += '<div><strong>' + escapeHtml(n.name || n.id) + '</strong></div>';
                html += '<div style="opacity:.75">' + escapeHtml(n.display_sample_type || '—') + ' / ' + escapeHtml(n.display_sample_purpose || '—') + '</div>';
                html += '<a class="sd-action-btn" href="' + escapeHtml(viewSampleHref(n)) + '">View Sample</a>';
            }
            detail.innerHTML = html;
            detail.classList.add('visible');
        });
        detail.addEventListener('click', function(e) {
            if (e.target.getAttribute('data-close')) detail.classList.remove('visible');
        });
    })();

    // ---- Subsystem cards (card-per-link, §16 #9 v1) ----
    // The render contract: takes a flat list of {subsystem, reference_id, ...}
    // and emits one card per row. Swapping to card-per-subsystem + picker
    // is purely a render-layer change — links[] stays the same.
    function renderCards(typeFilter) {
        var visible = links.filter(function(l) {
            return typeFilter === 'all' || l.subsystem === typeFilter;
        });
        if (!links.length) {
            return '<div class="sd-empty-cards">No subsystem links yet. Upload a Field / Micro / Experimental project that references this sample.</div>';
        }
        if (!visible.length) {
            return '<div class="sd-empty-cards">No ' + escapeHtml(subsystemLabel(typeFilter)) + ' links.</div>';
        }
        return visible.map(function(l) { return renderLinkCard(l); }).join('');
    }

    function renderLinkCard(link) {
        var meta = link.reference_metadata || {};
        var subData = link.subsystem === 'field'        ? (sample.field_data || {})
                    : link.subsystem === 'micro'        ? (sample.micro_data || {})
                    :                                     (sample.experimental_data || {});
        var label = subsystemLabel(link.subsystem);
        var viewHref = '#';
        var viewText = 'View ' + label;
        // Best-effort deep links into existing subsystem pages.
        if (link.subsystem === 'field' && meta.dataset_id) {
            viewHref = '/StraboFieldDatasetDetail/?dataset_id=' + encodeURIComponent(meta.dataset_id);
        } else if (link.subsystem === 'micro' && meta.project_id) {
            viewHref = '/microproject?id=' + encodeURIComponent(meta.project_id);
        } else if (link.subsystem === 'experimental' && meta.experiment_pkey) {
            viewHref = '/experimental/view_experiment.php?pkey=' + encodeURIComponent(meta.experiment_pkey);
        }

        var fieldsHtml = '';
        fieldsHtml += field('Reference ID', link.reference_id);
        if (meta.project_name)  fieldsHtml += field('Project',  meta.project_name);
        if (meta.dataset_name)  fieldsHtml += field('Dataset',  meta.dataset_name);
        if (subData.material_type)  fieldsHtml += field('Material Type',  subData.material_type);
        if (subData.sample_id_name) fieldsHtml += field('Sample Label',   subData.sample_id_name);
        if (subData.inplaceness)    fieldsHtml += field('In-place-ness',  subData.inplaceness);
        if (subData.notes)          fieldsHtml += field('Notes',          subData.notes);
        if (!fieldsHtml) fieldsHtml = '<div style="opacity:.6">No subsystem-specific fields stored.</div>';

        return ''
            + '<div class="sd-link-card" data-subsystem="' + escapeHtml(link.subsystem) + '">'
            +   '<div class="sd-link-card-head">'
            +     subsystemIcon(link.subsystem)
            +     '<span>' + escapeHtml(label) + ' &middot; Last Updated ' + escapeHtml(fmtDate(link.modified_at)) + '</span>'
            +     '<a class="sd-view-btn" href="' + escapeHtml(viewHref) + '">' + escapeHtml(viewText) + '</a>'
            +   '</div>'
            +   '<div class="sd-link-fields">' + fieldsHtml + '</div>'
            + '</div>';
    }

    var $cards = document.getElementById('sd-cards');
    var $tabs  = document.querySelectorAll('.sd-tab');
    function applyFilter(type) {
        $tabs.forEach(function(b) { b.classList.toggle('active', b.getAttribute('data-type') === type); });
        $cards.innerHTML = renderCards(type);
    }
    $tabs.forEach(function(b) {
        b.addEventListener('click', function() { applyFilter(b.getAttribute('data-type')); });
    });
    applyFilter('all');
})();
</script>
<?php endif; ?>

<?php
include("includes/mfooter.php");
