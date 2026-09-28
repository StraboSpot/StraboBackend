/**
 * File: assets/js/sesar_pull.js
 * Description: "Pull from SESAR" modals (StraboSamples IGSN integration
 *              Phase 5: D5). Talks to /sesar_pull.php. Needs
 *              assets/js/sesar_ui.js loaded first (dialog shell, styles).
 *
 *              SesarPull.single({ sampleId, onDone })
 *                  Per-field review for one sample: empty fields SESAR can
 *                  fill are ticked, values that differ are not. Field-linked
 *                  samples show location / material / purpose differences
 *                  that are never applied (the Field app owns them).
 *              SesarPull.bulk({ samples: [{id, name}], onDone })
 *                  Fill empty fields for each sample; "also overwrite" and
 *                  "set missing parent links" are explicit choices. One
 *                  server call per sample.
 *              SesarPull.create({ igsns, onDone })
 *                  "Create samples from IGSNs": paste a list (or pass the
 *                  ticked import rows), review, then one call per IGSN,
 *                  parents first.
 *
 *              The server re-reads SESAR for every change; the browser only
 *              says which fields (or which mode). onDone(changed) is called
 *              on close.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
(function () {
    'use strict';

    var CSS = ''
        + '.sp-cmp td.sp-f { width: 9.5em; color: rgba(255,255,255,0.7); }'
        + '.sp-cmp td.sp-v { width: 38%; word-break: break-word; }'
        + '.sp-cmp tr.sp-same td { color: rgba(255,255,255,0.45); }'
        + '.sp-cmp .sp-empty { color: rgba(255,255,255,0.4); font-style: italic; }'
        + '.sp-tag { display: inline-block; margin-left: 0.4em; font-size: 0.76em; padding: 0 0.5em; border-radius: 999px; white-space: nowrap;'
        + '  background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.7); }'
        + '.sp-tag.fill { background: rgba(110,190,120,0.2); color: #a8e0b0; }'
        + '.sp-tag.overwrite { background: rgba(240,180,60,0.18); color: #f3c97a; }'
        + '.sp-opts { margin: 0 0 1em; }'
        + '.sp-opts div { margin: 0.35em 0; }'
        + '.sp-opts input[type="checkbox"] + label { color: rgba(255,255,255,0.85); }'
        + '.sp-modal textarea { width: 100%; box-sizing: border-box; min-height: 9em; background: rgba(255,255,255,0.08); color: #fff;'
        + '  border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; padding: 0.55em 0.75em; font-family: monospace; font-size: 0.92em; }'
        + '.sp-rec { margin: 1em 0 0.5em; }'
        + '.sp-rec summary { cursor: pointer; color: rgba(255,255,255,0.8); }'
        + '.sp-rec dl { display: grid; grid-template-columns: 11em 1fr; gap: 0.2em 1em; margin: 0.6em 0 0; font-size: 0.9em; }'
        + '.sp-rec dt { color: rgba(255,255,255,0.6); }'
        + '.sp-rec dd { margin: 0; word-break: break-word; }'
        + '@media (max-width: 720px) {'
        + '  .sp-cmp tr { grid-template-columns: 2.2em 1fr !important; }'
        + '  .sp-cmp td.sp-v { grid-column: 2; width: auto; }'
        + '  .sp-rec dl { grid-template-columns: 1fr; }'
        + '  .sp-rec dt { margin-top: 0.4em; }'
        + '}';

    var LABEL = { name: 'name', description: 'description', display_sample_purpose: 'purpose',
                  display_sample_type: 'material type', location: 'location', parent: 'parent' };
    var st = null;

    function val(v) { return (v == null || v === '') ? '<span class="sp-empty">empty</span>' : esc(v); }
    function km(m) { return m == null ? '' : (m >= 1000 ? (m / 1000).toFixed(1) + ' km' : Math.round(m) + ' m'); }
    // SESAR timestamps ("2026-09-26T21:50:55.533105Z") as local dates; anything else as is.
    function when(v) {
        if (typeof v !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d/.test(v)) return v;
        var d = new Date(v.replace(/(\.\d{3})\d+/, '$1'));
        return isNaN(d) ? v : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }

    // ------------------------------------------------------------------
    // Dialog (shell and styles: assets/js/sesar_ui.js)
    // ------------------------------------------------------------------
    var ui = window.SesarUi, esc = ui.esc, link = ui.link;
    var dlg = ui.modal({ prefix: 'sp', cls: 'sp-modal', env: true, css: CSS, close: close, click: onClick, change: onChange });
    var body = dlg.body, foot = dlg.foot, env = dlg.env;
    function post(b) { return ui.post('/sesar_pull.php', b); }

    function show(kind, title, opts) {
        st = { kind: kind, opts: opts || {}, phase: 'loading', changed: false, stop: false };
        dlg.show(title);
    }

    function fail(message) {
        st.phase = 'error';
        dlg.fail(message);
    }

    function close() {
        if (!st) return;
        if (st.phase === 'running') {
            if (!window.confirm('Stop after the current sample?')) return;
            st.stop = true;
            return;
        }
        var changed = st.changed, cb = st.opts.onDone;
        st = null;
        dlg.hide();
        if (typeof cb === 'function') cb(changed);
    }

    function onClick(e) {
        var t = e.target.closest('[data-act]');
        if (!t || !st) return;
        var act = t.getAttribute('data-act');
        if (act === 'close') return close();
        if (act === 'stop') { st.stop = true; t.disabled = true; t.textContent = 'Stopping…'; return; }
        if (st.kind === 'single' && act === 'apply') return singleApply();
        if (st.kind === 'bulk' && act === 'start') return bulkStart();
        if (st.kind === 'create' && act === 'check') return createCheck();
        if (st.kind === 'create' && act === 'start') return createStart();
        if (st.kind === 'create' && act === 'back') return createAsk();
    }

    function onChange(e) {
        if (!st) return;
        var t = e.target;
        if (st.kind === 'single' && t.getAttribute('data-f') !== null && st.phase === 'review') {
            st.ticked[t.getAttribute('data-f')] = t.checked;
            singleFoot();
        }
        if (st.kind === 'create' && t.getAttribute('data-i') !== null && st.phase === 'review') {
            st.rows[+t.getAttribute('data-i')].checked = t.checked;
            createFoot();
        }
    }

    function recordHtml(rec) {
        var keys = Object.keys(rec || {});
        if (!keys.length) return '';
        return '<details class="sp-rec"><summary>Everything SESAR has for this sample</summary><dl>'
            + keys.map(function (k) { return '<dt>' + esc(k) + '</dt><dd>' + esc(when(rec[k])) + '</dd>'; }).join('') + '</dl></details>';
    }

    // ------------------------------------------------------------------
    // Single sample: per-field review
    // ------------------------------------------------------------------
    function single(opts) {
        show('single', 'Pull from SESAR', opts);
        body('<p>Reading this sample\'s record at SESAR…</p>');
        foot('<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>');
        post({ action: 'preview', sample_id: st.opts.sampleId }).then(function (j) {
            if (!st) return;
            if (!j.ok) return fail(j.message);
            st.p = j.preview;
            st.ticked = {};
            st.p.rows.forEach(function (r) { if (r.action === 'fill' || r.action === 'overwrite') st.ticked[r.field] = r.checked; });
            st.phase = 'review';
            singleRender();
        });
    }

    function singleRender(resultHtml) {
        var p = st.p, h = '';
        env(p.environment);
        h += '<p>SESAR record ' + link(p.landing_url, p.igsn) + ' for <strong>' + esc(p.name) + '</strong>.'
            + (p.linked ? '' : ' Pulling links this sample to it.') + '</p>';
        if (p.access === 'readonly') {
            h += '<div class="sm-public">This record belongs to another SESAR account. StraboSpot links it <strong>read-only</strong>: '
                + 'you can pull from it, but not send changes to SESAR.</div>';
        }
        if (resultHtml) h += resultHtml;
        var changeable = p.rows.filter(function (r) { return r.action === 'fill' || r.action === 'overwrite'; });
        h += '<div class="sm-group">Values' + (changeable.length && st.phase === 'review' ? '<small>tick the SESAR values to copy into this sample</small>' : '') + '</div>'
            + '<table class="sm-table sp-cmp"><thead><tr><th></th><th>Field</th><th>In StraboSamples</th><th>At SESAR</th></tr></thead><tbody>';
        p.rows.forEach(function (r, i) {
            if (r.action === 'same' && r.current == null && r.sesar == null) return;   // empty on both sides: nothing to say
            var can = (r.action === 'fill' || r.action === 'overwrite') && st.phase === 'review';
            var tag = r.action === 'fill' ? '<span class="sp-tag fill">fills an empty field</span>'
                : (r.action === 'overwrite' ? '<span class="sp-tag overwrite">replaces</span>' : '');
            var extra = (r.field === 'location' && r.action === 'overwrite' && r.distance_m != null) ? ' <span class="sm-muted">(' + km(r.distance_m) + ' apart)</span>' : '';
            h += '<tr class="' + (r.action === 'same' ? 'sp-same' : '') + '"><td class="sm-cb">'
                + (can ? '<input type="checkbox" id="sp-f-' + i + '" data-f="' + esc(r.field) + '"' + (st.ticked[r.field] ? ' checked' : '') + '>'
                       + '<label for="sp-f-' + i + '"><span class="sm-sr">Copy the SESAR ' + esc(r.label) + '</span></label>' : '')
                + '</td><td class="sp-f">' + esc(r.label) + '</td><td class="sp-v">' + val(r.current) + '</td>'
                + '<td class="sp-v">' + val(r.sesar) + (r.action === 'same' ? ' <span class="sp-tag">same</span>' : tag) + extra + '</td></tr>';
        });
        h += '</tbody></table>';
        if (p.parent_note) h += '<p class="sm-muted" style="margin-top:0.6em">' + esc(p.parent_note) + '</p>';
        if (p.flags.length) {
            h += '<div class="sm-group">Differences with the StraboField spot<small>shown only: the Field app owns these, so they are not changed here</small></div>'
                + '<table class="sm-table sp-cmp"><thead><tr><th></th><th>Field</th><th>StraboField</th><th>At SESAR</th></tr></thead><tbody>'
                + p.flags.map(function (f) {
                    return '<tr><td class="sm-cb"></td><td class="sp-f">' + esc(f.label) + '</td><td class="sp-v">' + val(f.current) + '</td><td class="sp-v">'
                        + val(f.sesar) + (f.distance_m != null ? ' <span class="sm-muted">(' + km(f.distance_m) + ' apart)</span>' : '') + '</td></tr>';
                }).join('') + '</tbody></table>';
        }
        h += recordHtml(p.record);
        body(h);
        singleFoot();
    }

    function singleFoot() {
        if (st.phase !== 'review') {
            foot('<span class="sm-count"></span><button type="button" class="sm-btn" data-act="close">Close</button>');
            return;
        }
        var n = Object.keys(st.ticked).filter(function (k) { return st.ticked[k]; }).length;
        var label = n ? 'Copy ' + n + ' value' + (n === 1 ? '' : 's') : (st.p.linked ? 'Refresh SESAR record' : 'Link without changes');
        var msg = n ? n + ' change' + (n === 1 ? '' : 's') + ' to this sample' : 'No changes to this sample';
        var countEl = document.getElementById('sp-count'), btn = document.querySelector('#sp-foot [data-act=apply]');
        if (countEl && btn) { countEl.textContent = msg; btn.textContent = label; return; }   // in place: see sesar_mint.js renderFoot
        foot('<span class="sm-count" id="sp-count">' + msg + '</span>'
            + '<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>'
            + '<button type="button" class="sm-btn" data-act="apply">' + label + '</button>');
    }

    function singleApply() {
        var accept = [], seen = {};
        st.p.rows.forEach(function (r) {
            if (r.field !== 'parent' && st.ticked[r.field]) { accept.push(r.field); seen[r.field] = r.sesar; }
        });
        st.phase = 'running';
        foot('<span class="sm-count">Working…</span>');
        post({ action: 'apply', sample_id: st.opts.sampleId, mode: 'review', accept: accept, seen: seen, parent: !!st.ticked.parent })
            .then(function (j) {
                if (!st) return;
                st.phase = 'done';
                if (!j.ok) {
                    singleRender('<div class="sm-msg err">' + esc(j.message || 'Something went wrong.') + '</div>');
                    return;
                }
                st.changed = true;
                var r = j.result, parts = r.applied.map(function (f) { return LABEL[f] || f; });
                if (r.parent_set) parts.push('parent');
                var h = '<div class="sm-msg ok">' + (parts.length ? 'Copied from SESAR: ' + esc(parts.join(', ')) + '.' : 'Linked. No values were changed.')
                    + ' The SESAR record is saved with this sample.</div>';
                if (r.skipped.length || r.notes.length) {
                    h += '<div class="sm-msg">' + r.skipped.map(function (s) { return esc((LABEL[s.field] || s.field) + ': ' + s.why); })
                        .concat(r.notes.map(esc)).join('<br>') + '</div>';
                }
                // Show the fresh comparison (values now the same).
                post({ action: 'preview', sample_id: st.opts.sampleId }).then(function (k) {
                    if (!st) return;
                    if (k.ok) st.p = k.preview;
                    singleRender(h);
                });
            });
    }

    // ------------------------------------------------------------------
    // Bulk pull (IGSN page selection)
    // ------------------------------------------------------------------
    var CAP = 100;

    function bulk(opts) {
        show('bulk', 'Pull from SESAR', opts);
        st.rows = (st.opts.samples || []).map(function (s) { return { id: s.id, name: s.name, status: null }; });
        st.phase = 'review';
        st.overwrite = false;
        st.parents = true;
        bulkRender();
    }

    function bulkRender() {
        var h = '', running = st.phase !== 'review';
        if (!running) {
            h += '<p>For each sample, StraboSpot reads its record at SESAR, fills the fields that are <strong>empty</strong> here, '
                + 'and saves the SESAR record with the sample. Samples linked to StraboField never get their location, material or purpose changed; '
                + 'differences are listed instead.</p>'
                + '<div class="sp-opts">'
                + '<div><input type="checkbox" id="sp-ow"' + (st.overwrite ? ' checked' : '') + '><label for="sp-ow">Also replace values that differ from SESAR</label></div>'
                + '<div><input type="checkbox" id="sp-par"' + (st.parents ? ' checked' : '') + '><label for="sp-par">Set a missing parent link when SESAR names another of your samples as the parent</label></div>'
                + '</div>';
        } else {
            var done = st.rows.filter(function (r) { return r.status && r.status.cls !== 'run'; }).length;
            h += '<p>' + (st.phase === 'running' ? 'Pulling ' + Math.min(done + 1, st.rows.length) + ' of ' + st.rows.length + '… Please keep this window open.'
                : bulkSummary()) + '</p><div class="sm-bar"><div style="width:' + Math.round(100 * done / Math.max(1, st.rows.length)) + '%"></div></div>';
        }
        h += '<table class="sm-table"><thead><tr><th>Sample</th></tr></thead><tbody>'
            + st.rows.map(function (r) {
                return '<tr><td><span class="sm-name">' + esc(r.name || r.id) + '</span>'
                    + (r.status ? '<div class="sm-status ' + r.status.cls + '">' + r.status.html + '</div>' : '') + '</td></tr>';
            }).join('') + '</tbody></table>';
        body(h);
        if (st.phase === 'review') {
            var n = st.rows.length;
            foot('<span class="sm-count">' + n + ' sample' + (n === 1 ? '' : 's') + (n > CAP ? ' (at most ' + CAP + ' per run)' : '') + '</span>'
                + '<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>'
                + '<button type="button" class="sm-btn" data-act="start"' + (n === 0 || n > CAP ? ' disabled' : '') + '>Pull ' + n + ' from SESAR</button>');
        } else if (st.phase === 'running') {
            foot('<span class="sm-count">Working…</span><button type="button" class="sm-btn sm-quiet" data-act="stop">Stop</button>');
        } else {
            foot('<span class="sm-count"></span><button type="button" class="sm-btn" data-act="close">Close</button>');
        }
    }

    function bulkSummary() {
        var ok = 0, err = 0, skip = 0;
        st.rows.forEach(function (r) { if (!r.status) skip++; else if (r.status.cls === 'ok') ok++; else if (r.status.cls === 'err') err++; else skip++; });
        var parts = [ok + ' pulled'];
        if (err) parts.push(err + ' failed');
        if (skip) parts.push(skip + ' not done');
        return (st.stop ? 'Stopped. ' : 'Done. ') + parts.join(', ') + '.';
    }

    function bulkStart() {
        st.overwrite = document.getElementById('sp-ow').checked;
        st.parents = document.getElementById('sp-par').checked;
        st.phase = 'running';
        st.pos = 0;
        bulkRender();
        bulkNext();
    }

    function bulkNext() {
        if (!st) return;
        if (st.stop || st.pos >= st.rows.length) { st.phase = 'done'; bulkRender(); return; }
        var r = st.rows[st.pos];
        r.status = { cls: 'run', html: 'Pulling…' };
        bulkRender();
        post({ action: 'apply', sample_id: r.id, mode: st.overwrite ? 'overwrite' : 'fill', parent: st.parents }).then(function (j) {
            if (!st) return;
            if (j.ok) {
                st.changed = true;
                var res = j.result, parts = res.applied.map(function (f) { return LABEL[f] || f; });
                env(res.environment);
                if (res.parent_set) parts.push('parent');
                var h = link(res.landing_url, res.igsn) + ': ' + (parts.length ? 'copied ' + esc(parts.join(', ')) : 'nothing to change')
                    + (res.access === 'readonly' ? ' (read-only link)' : '');
                if (res.flags.length) {
                    h += '<div class="sm-note">Differs from StraboField (not changed): ' + res.flags.map(function (f) {
                        return esc(LABEL[f.field] || f.field) + (f.distance_m != null ? ' ' + km(f.distance_m) + ' apart' : '');
                    }).join(', ') + '</div>';
                }
                if (res.notes.length) h += '<div class="sm-note">' + res.notes.map(esc).join('<br>') + '</div>';
                r.status = { cls: 'ok', html: h };
            } else {
                r.status = { cls: 'err', html: esc(j.message || 'Failed.') };
            }
            st.pos++;
            bulkNext();
        });
    }

    // ------------------------------------------------------------------
    // Create samples from IGSNs
    // ------------------------------------------------------------------
    function create(opts) {
        show('create', 'Create samples from IGSNs', opts);
        if (st.opts.igsns && st.opts.igsns.length) {
            st.text = st.opts.igsns.join('\n');
            createCheck();
        } else {
            st.text = '';
            createAsk();
        }
    }

    function createAsk() {
        st.phase = 'ask';
        body('<p>Paste IGSNs, one per line (commas and spaces also separate them). Each becomes a new sample in StraboSamples with the name, '
            + 'description, location, material and purpose from its SESAR record, linked to that record. Up to 100 at a time.</p>'
            + '<textarea id="sp-igsns" aria-label="IGSNs" placeholder="10.58052/IEABC0001&#10;IEABC0002">' + esc(st.text) + '</textarea>');
        foot('<span class="sm-count"></span><button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>'
            + '<button type="button" class="sm-btn" data-act="check">Check with SESAR</button>');
        document.getElementById('sp-igsns').focus();
    }

    function createCheck() {
        var ta = document.getElementById('sp-igsns');
        if (ta && st.phase === 'ask') st.text = ta.value;
        var list = st.text.split(/[\s,;]+/).map(function (s) { return s.trim(); }).filter(Boolean);
        if (!list.length) { if (ta) ta.focus(); return; }
        st.phase = 'loading';
        body('<p>Checking ' + list.length + ' IGSN' + (list.length === 1 ? '' : 's') + ' with SESAR…</p>');
        foot('<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>');
        post({ action: 'create_plan', igsns: list }).then(function (j) {
            if (!st) return;
            if (!j.ok) {
                st.phase = 'ask';
                createAsk();
                document.getElementById('sp-body').insertAdjacentHTML('afterbegin', '<div class="sm-msg err">' + esc(j.message || 'Something went wrong.') + '</div>');
                return;
            }
            env(j.plan.environment);
            st.rows = j.plan.rows.map(function (r) { return Object.assign({}, r, { checked: r.group === 'ready', status: null }); });
            st.phase = 'review';
            createRender();
        });
    }

    function createRow(r, i) {
        var running = st.phase !== 'review';
        var h = '<tr' + (r.group !== 'ready' ? ' class="sm-blocked"' : '') + '><td class="sm-cb">';
        if (r.group === 'ready') {
            h += '<input type="checkbox" id="sp-c-' + i + '" data-i="' + i + '"' + (r.checked ? ' checked' : '') + (running ? ' disabled' : '') + '>'
                + '<label for="sp-c-' + i + '"><span class="sm-sr">Create ' + esc(r.name || r.igsn) + '</span></label>';
        }
        h += '</td><td><span class="sm-name">' + esc(r.name || r.igsn || r.input) + '</span>'
            + (r.name ? '<div class="sm-sub">' + esc(r.igsn) + '</div>' : '');
        if (r.parent_igsn) {
            if (r.parent_in_run) h += '<div class="sm-sub">Child of ' + esc(r.parent_igsn) + ' (created first, then linked)</div>';
            else if (r.parent_holder) h += '<div class="sm-sub">Child of your sample ' + esc(r.parent_holder.name) + '</div>';
            else h += '<div class="sm-sub">SESAR parent ' + esc(r.parent_igsn) + ' is not one of your samples (no parent link)</div>';
        }
        if (r.holder) {
            h += '<div class="sm-reason">Already in StraboSamples: <a href="' + esc(r.holder.url) + '">' + esc(r.holder.name) + '</a></div>';
        } else if (r.reason) {
            h += '<div class="' + (r.group === 'ready' ? 'sm-note' : 'sm-reason') + '">' + esc(r.reason) + '</div>';
        }
        if (r.status) h += '<div class="sm-status ' + r.status.cls + '">' + r.status.html + '</div>';
        return h + '</td></tr>';
    }

    function createRender() {
        var h = '';
        if (st.phase !== 'review') {
            var q = st.queue || [], done = q.filter(function (i) { var s = st.rows[i].status; return s && s.cls !== 'run'; }).length;
            h += '<p>' + (st.phase === 'running' ? 'Creating ' + Math.min(done + 1, q.length) + ' of ' + q.length + '… Please keep this window open.'
                : createSummary()) + '</p><div class="sm-bar"><div style="width:' + Math.round(100 * done / Math.max(1, q.length)) + '%"></div></div>';
        }
        var idx = st.rows.map(function (r, i) { return { r: r, i: i }; });
        var group = function (g, title, hint) {
            var rows = idx.filter(function (x) { return x.r.group === g; });
            if (!rows.length) return '';
            return '<div class="sm-group">' + title + (hint ? '<small>' + hint + '</small>' : '') + '</div><table class="sm-table"><tbody>'
                + rows.map(function (x) { return createRow(x.r, x.i); }).join('') + '</tbody></table>';
        };
        h += group('ready', 'Ready to create', 'parents are created before their children');
        h += group('held', 'Already in StraboSamples', 'open the sample and use Pull from SESAR to update it');
        h += group('blocked', 'Cannot be created');
        body(h);
        createFoot();
    }

    function createFoot() {
        if (st.phase === 'review') {
            var n = st.rows.filter(function (r) { return r.group === 'ready' && r.checked; }).length;
            var countEl = document.getElementById('sp-count'), btn = document.querySelector('#sp-foot [data-act=start]');
            var label = 'Create ' + n + ' sample' + (n === 1 ? '' : 's');
            if (countEl && btn) { countEl.textContent = n + ' selected'; btn.textContent = label; btn.disabled = n === 0; return; }
            foot('<span class="sm-count" id="sp-count">' + n + ' selected</span>'
                + (st.opts.igsns && st.opts.igsns.length ? '' : '<button type="button" class="sm-btn sm-quiet" data-act="back">Edit list</button>')
                + '<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>'
                + '<button type="button" class="sm-btn" data-act="start"' + (n === 0 ? ' disabled' : '') + '>' + label + '</button>');
        } else if (st.phase === 'running') {
            foot('<span class="sm-count">Working…</span><button type="button" class="sm-btn sm-quiet" data-act="stop">Stop</button>');
        } else {
            foot('<span class="sm-count"></span><button type="button" class="sm-btn" data-act="close">Close</button>');
        }
    }

    function createSummary() {
        var ok = 0, err = 0, rest = 0;
        st.queue.forEach(function (i) { var s = st.rows[i].status; if (!s) rest++; else if (s.cls === 'ok') ok++; else if (s.cls === 'err') err++; else rest++; });
        var parts = [ok + ' created'];
        if (err) parts.push(err + ' failed');
        if (rest) parts.push(rest + ' not done');
        return (st.stop ? 'Stopped. ' : 'Done. ') + parts.join(', ') + '.';
    }

    function createStart() {
        st.queue = [];
        st.rows.forEach(function (r, i) { if (r.group === 'ready' && r.checked) st.queue.push(i); });   // server order = parents first
        if (!st.queue.length) return;
        st.phase = 'running';
        st.pos = 0;
        createRender();
        createNext();
    }

    function createNext() {
        if (!st) return;
        if (st.stop || st.pos >= st.queue.length) { st.phase = 'done'; createRender(); return; }
        var r = st.rows[st.queue[st.pos]];
        r.status = { cls: 'run', html: 'Creating…' };
        createRender();
        post({ action: 'create', igsn: r.igsn }).then(function (j) {
            if (!st) return;
            if (j.ok) {
                st.changed = true;
                var res = j.result;
                r.status = { cls: 'ok', html: 'Created <a href="' + esc(res.url) + '">' + esc(res.name) + '</a>'
                    + (res.parent_linked ? ', linked to its parent' : '') + (res.access === 'readonly' ? ' (read-only link)' : '')
                    + (res.notes.length ? '<div class="sm-note">' + res.notes.map(esc).join('<br>') + '</div>' : '') };
            } else {
                r.status = { cls: 'err', html: esc(j.message || 'Failed.') };
            }
            st.pos++;
            createNext();
        });
    }

    // Removes this sample's pulled link (the SESAR record card on Sample Overview).
    function unlink(sampleId) { return post({ action: 'unlink', sample_id: sampleId }); }

    window.SesarPull = { single: single, bulk: bulk, create: create, unlink: unlink };
})();
