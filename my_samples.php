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
 *              Phase 4 scaffolding. The "New Sample" button is a stub
 *              that points at a future Sample-create modal (deferred to
 *              a follow-on sub-branch).
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
</style>

<div id="main" class="wrapper style1">
    <div class="container">
        <header class="major">
            <h2>My Samples</h2>
        </header>

        <div class="ms-controls">
            <button type="button" class="ms-new-btn" id="ms-new-sample-btn">+ New Sample</button>
            <input type="text" id="ms-search" placeholder="Search ID, location, internal…" autocomplete="off">
            <select id="ms-sort">
                <option value="modified_desc">Sort: Most recent</option>
                <option value="modified_asc">Sort: Oldest</option>
                <option value="purpose">Sort: Purpose</option>
                <option value="type">Sort: Type</option>
                <option value="id_asc">Sort: Sample ID</option>
            </select>
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

<script type="application/json" id="ms-data"><?php echo json_encode($samples, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

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

    var state = {
        type:   'all',
        sort:   'modified_desc',
        search: '',
        expanded: {},  // key → bool
    };

    var $cards   = document.getElementById('ms-cards');
    var $count   = document.getElementById('ms-result-count');
    var $search  = document.getElementById('ms-search');
    var $sort    = document.getElementById('ms-sort');
    var $tabs    = document.querySelectorAll('.ms-tab');
    var $newBtn  = document.getElementById('ms-new-sample-btn');

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
    function fmtDate(ts) {
        if (!ts) return '—';
        // Postgres returns ISO 8601 already; trim to date.
        var d = new Date(ts);
        if (isNaN(d.getTime())) return ts;
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
        if (mode === 'id_asc')        return function(a, b) { return (a.id || '').localeCompare(b.id || ''); };
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

        var html = '';
        html += '<div class="ms-card">';
        html += '  <div class="ms-card-row">';
        html += '    <div class="ms-card-meta">';
        html += '      <div class="ms-sample-id">' + highlight(sample.name || sample.id, q) + '</div>';
        html += '      <div class="ms-row"><strong>Type:</strong> ' + highlight(sample.display_sample_type || '—', q) + '</div>';
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

    // Event wiring.
    $search.addEventListener('input', function(e) {
        state.search = e.target.value;
        render();
    });
    $sort.addEventListener('change', function(e) {
        state.sort = e.target.value;
        render();
    });
    $tabs.forEach(function(btn) {
        btn.addEventListener('click', function() {
            state.type = btn.getAttribute('data-type');
            $tabs.forEach(function(b) { b.classList.toggle('active', b === btn); });
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
    $newBtn.addEventListener('click', function() {
        // Sample creation UI ships in a follow-on Phase 4 sub-branch.
        alert('Sample creation UI is on its way — for now, samples populate from Field / Micro / Experimental project uploads.');
    });

    render();
})();
</script>

<?php
include("includes/mfooter.php");
