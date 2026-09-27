/**
 * File: assets/js/sesar_mint.js
 * Description: The IGSN mint review + run modal (StraboSamples IGSN
 *              integration Phase 4: D2, D3, D4, D8). Shared by Sample
 *              Overview (one sample plus its family) and the IGSN page
 *              (a selection). Talks to /sesar_mint.php.
 *
 *              Review: rows come back from the server already grouped and
 *              parents first. READY rows are checked, REPLACE rows (the
 *              IGSN field holds something that is not a live SESAR IGSN)
 *              start unchecked, BLOCKED rows are listed with reasons.
 *              SESAR code + collector are chosen once; object type and
 *              material per row, each with "apply to all checked".
 *
 *              Run: one server call per sample, in order (D4). A child
 *              whose parent was part of this run but did not get an IGSN
 *              is skipped, never registered without its parent (D8). The
 *              server enforces every rule again. Up to 100 per run.
 *
 *              Usage: SesarMint.open({ sampleIds: [...], onDone: fn(anyMinted) })
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
        + '.sm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); display: flex; align-items: flex-start; justify-content: center;'
        + '  z-index: 10000; padding: 3em 1em 2em; overflow-y: auto; }'
        + '.sm-overlay[hidden] { display: none; }'
        + '.sm-modal { background: #1f1f2e; border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; width: 100%; max-width: 980px; color: #fff;'
        + '  box-shadow: 0 10px 40px rgba(0,0,0,0.6); }'
        + '.sm-head { display: flex; align-items: center; gap: 0.8em; padding: 1em 1.25em; border-bottom: 1px solid rgba(255,255,255,0.12); }'
        + '.sm-head h3 { margin: 0; color: #fff; font-size: 1.15em; flex: 1 1 auto; }'
        + '.sm-env { font-size: 0.78em; padding: 0.15em 0.6em; border-radius: 4px; background: rgba(240,180,60,0.18); color: #f3c97a;'
        + '  border: 1px solid rgba(240,180,60,0.45); white-space: nowrap; }'
        + '.sm-x { background: none; border: none; color: rgba(255,255,255,0.7); font-size: 1.6em; line-height: 1; cursor: pointer; padding: 0 0.3em; }'
        + '.sm-x:hover { color: #fff; }'
        + '.sm-body { padding: 1em 1.25em 0.5em; }'
        + '.sm-body p { margin: 0 0 0.8em; color: rgba(255,255,255,0.82); }'
        + '.sm-muted { color: rgba(255,255,255,0.6); font-size: 0.88em; }'
        + '.sm-public { padding: 0.65em 0.9em; border-radius: 4px; background: rgba(120,170,230,0.12); border: 1px solid rgba(120,170,230,0.35);'
        + '  color: rgba(255,255,255,0.85); font-size: 0.9em; margin-bottom: 1em; }'
        + '.sm-shared { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8em 1em; margin-bottom: 1em; }'
        + '.sm-shared label, .sm-bulk label { display: block; font-size: 0.85em; color: rgba(255,255,255,0.65); margin-bottom: 0.25em; }'
        + '.sm-modal input[type="text"], .sm-modal select { width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.08);'
        + '  border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; color: #fff; padding: 0.45em 0.6em; font-size: 0.92em;'
        + '  font-family: inherit; height: auto; line-height: 1.4; margin: 0; }'
        + '.sm-modal select option, .sm-modal select optgroup { background: #2a2a3a; color: #fff; }'
        + '.sm-modal input.sm-bad { border-color: #e44c65; background: rgba(228,76,101,0.12); }'
        + '.sm-bulk { display: flex; flex-wrap: wrap; gap: 0.6em 1em; align-items: flex-end; margin: 0 0 0.6em; padding: 0.6em 0.75em;'
        + '  background: rgba(255,255,255,0.04); border-radius: 4px; }'
        + '.sm-bulk > div { flex: 1 1 14em; }'
        + '.sm-bulk .sm-btn { flex: 0 0 auto; }'
        + '.sm-group { margin: 1em 0 0.4em; font-size: 0.95em; font-weight: 600; color: #fff; }'
        + '.sm-group small { font-weight: 400; color: rgba(255,255,255,0.6); margin-left: 0.4em; }'
        + '.sm-table { width: 100%; border-collapse: collapse; font-size: 0.92em; margin: 0; }'
        + '.sm-table th { text-align: left; font-weight: 600; color: rgba(255,255,255,0.65); border-bottom: 1px solid rgba(255,255,255,0.18);'
        + '  padding: 0.35em 0.5em; font-size: 0.9em; }'
        + '.sm-table td { border-bottom: 1px solid rgba(255,255,255,0.08); padding: 0.45em 0.5em; vertical-align: top; }'
        + '.sm-table td.sm-cb { width: 2.2em; }'
        + '.sm-table td.sm-ot { width: 30%; }'
        + '.sm-table td.sm-mat { width: 24%; }'
        + '.sm-cb input[type="checkbox"] + label { padding-left: 1.6em; margin: 0; min-height: 1.4em; }'
        + '.sm-cb input[type="checkbox"] + label:before { top: 0; }'
        + '.sm-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }'
        + '.sm-name { color: #fff; font-weight: 600; word-break: break-word; }'
        + '.sm-role { display: inline-block; margin-left: 0.4em; font-size: 0.78em; padding: 0 0.5em; border-radius: 999px;'
        + '  background: rgba(170,140,230,0.2); color: #d2c2f4; font-weight: 400; }'
        + '.sm-sub { font-size: 0.84em; color: rgba(255,255,255,0.6); margin-top: 0.15em; }'
        + '.sm-note { font-size: 0.84em; color: #f3c97a; margin-top: 0.2em; }'
        + '.sm-reason { font-size: 0.86em; color: #f5a3b3; }'
        + '.sm-blocked td { color: rgba(255,255,255,0.75); }'
        + '.sm-status { font-size: 0.86em; margin-top: 0.25em; }'
        + '.sm-status.ok { color: #a8e0b0; }'
        + '.sm-status.ok a { color: #a8e0b0; }'
        + '.sm-status.err { color: #f5a3b3; }'
        + '.sm-status.skip { color: #f3c97a; }'
        + '.sm-status.run { color: #fff; }'
        + '.sm-foot { display: flex; flex-wrap: wrap; gap: 0.75em; align-items: center; justify-content: flex-end; padding: 1em 1.25em;'
        + '  border-top: 1px solid rgba(255,255,255,0.12); position: sticky; bottom: 0; background: #1f1f2e; border-radius: 0 0 8px 8px; }'
        + '.sm-foot .sm-count { flex: 1 1 auto; color: rgba(255,255,255,0.7); font-size: 0.92em; }'
        + '.sm-btn { background: #e44c65; color: #fff; border: none; border-radius: 4px; padding: 0.55em 1.15em; font-size: 0.95em; cursor: pointer;'
        + '  line-height: 1.4; box-shadow: none; height: auto; }'
        + '.sm-btn:hover { background: #f06880; }'
        + '.sm-btn.sm-quiet { background: rgba(255,255,255,0.12); }'
        + '.sm-btn.sm-quiet:hover { background: rgba(255,255,255,0.2); }'
        + '.sm-btn[disabled] { opacity: 0.5; cursor: default; }'
        + '.sm-bar { height: 0.5em; background: rgba(255,255,255,0.1); border-radius: 999px; overflow: hidden; margin: 0.25em 0 1em; }'
        + '.sm-bar > div { height: 100%; background: #e44c65; width: 0; transition: width 0.3s; }'
        + '.sm-msg { padding: 0.75em 1em; border-radius: 4px; background: rgba(255,255,255,0.06); margin: 0 0 1em; }'
        + '.sm-msg.err { background: rgba(228,76,101,0.14); border: 1px solid rgba(228,76,101,0.4); }'
        + '.sm-msg.ok { background: rgba(110,190,120,0.14); border: 1px solid rgba(110,190,120,0.35); }'
        + '.sm-msg a { color: #fff; }'
        + '@media (max-width: 720px) {'
        + '  .sm-shared { grid-template-columns: 1fr; }'
        + '  .sm-table thead { display: none; }'
        + '  .sm-table tr { display: grid; grid-template-columns: 2.2em 1fr; padding: 0.4em 0; border-bottom: 1px solid rgba(255,255,255,0.1); }'
        + '  .sm-table td { border: none; padding: 0.2em 0.3em; }'
        + '  .sm-table td.sm-ot, .sm-table td.sm-mat { grid-column: 2; width: auto; }'
        + '  .sm-overlay { padding: 3.75em 0.5em 0.5em; }'
        + '  .sm-head { flex-wrap: wrap; gap: 0.4em 0.8em; }'
        + '  .sm-head h3 { flex: 1 1 60%; }'
        + '  .sm-env { order: 3; }'
        + '}';

    var ROLE = { ancestor: 'parent', descendant: 'child' };
    var st = null;   // state of the open modal

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function post(body) {
        return fetch('/sesar_mint.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Unexpected response from StraboSpot (HTTP ' + r.status + ').' }; });
        }, function () {
            return { ok: false, message: 'Could not reach StraboSpot. Please check your connection.' };
        });
    }

    // Shared with the pull modal (assets/js/sesar_pull.js), which reuses the sm- classes.
    function injectStyles() {
        if (document.getElementById('sm-styles')) return;
        var style = document.createElement('style');
        style.id = 'sm-styles';
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    function ensureDom() {
        if (document.getElementById('sm-overlay')) return;
        injectStyles();
        var ov = document.createElement('div');
        ov.id = 'sm-overlay';
        ov.className = 'sm-overlay';
        ov.hidden = true;
        ov.innerHTML = '<div class="sm-modal" role="dialog" aria-modal="true" aria-labelledby="sm-title">'
            + '<div class="sm-head"><h3 id="sm-title">Register IGSNs at SESAR</h3><span class="sm-env" id="sm-env" hidden>SESAR test site (sandbox)</span>'
            + '<button type="button" class="sm-x" id="sm-x" aria-label="Close">&times;</button></div>'
            + '<div class="sm-body" id="sm-body"></div>'
            + '<div class="sm-foot" id="sm-foot"></div></div>';
        document.body.appendChild(ov);
        document.getElementById('sm-x').addEventListener('click', close);
        ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        ov.addEventListener('click', onClick);
        ov.addEventListener('change', onChange);
        ov.addEventListener('input', onInput);
    }

    function open(opts) {
        ensureDom();
        st = { opts: opts || {}, plan: null, rows: [], phase: 'loading', minted: 0, stop: false, done: {} };
        var ov = document.getElementById('sm-overlay');
        ov.hidden = false;
        document.getElementById('sm-body').innerHTML = '<p>Checking your samples with SESAR…</p>';
        document.getElementById('sm-foot').innerHTML = '<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>';
        document.getElementById('sm-x').focus();
        post({ action: 'plan', sample_ids: st.opts.sampleIds || [] }).then(function (j) {
            if (!st) return;
            if (!j.ok) {
                st.phase = 'error';
                document.getElementById('sm-body').innerHTML = '<div class="sm-msg err">' + esc(j.message || 'Something went wrong.') + '</div>';
                return;
            }
            st.plan = j.plan;
            st.rows = j.plan.rows.map(function (r) {
                return Object.assign({}, r, {
                    checked: r.group === 'ready',
                    object_type: r.object_type || '',
                    material: r.material || '',
                    status: null
                });
            });
            st.phase = 'review';
            render();
        });
    }

    function close() {
        if (!st) return;
        if (st.phase === 'running') {
            if (!window.confirm('Stop after the sample being registered now?')) return;
            st.stop = true;
            return;
        }
        var minted = st.minted > 0, cb = st.opts.onDone;
        st = null;
        document.getElementById('sm-overlay').hidden = true;
        if (typeof cb === 'function') cb(minted);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------
    function checkedRows() {
        return st.rows.filter(function (r) { return r.group !== 'blocked' && r.checked; });
    }

    function materialOk(v) {
        if (!v) return true;
        var lc = v.trim().toLowerCase();
        return st.plan.choices.materials.some(function (m) { return m.toLowerCase() === lc; });
    }

    function objectTypeOptions(selected) {
        var groups = st.plan.choices.object_types, h = '';
        Object.keys(groups).forEach(function (g) {
            h += '<optgroup label="' + esc(g) + '">';
            groups[g].forEach(function (l) {
                h += '<option value="' + esc(l) + '"' + (l === selected ? ' selected' : '') + '>' + esc(l) + '</option>';
            });
            h += '</optgroup>';
        });
        return h;
    }

    function rowHtml(r, i) {
        var running = st.phase !== 'review';
        var h = '<tr data-i="' + i + '"' + (r.group === 'blocked' ? ' class="sm-blocked"' : '') + '>';
        if (r.group === 'blocked') {
            h += '<td class="sm-cb"></td>';
        } else {
            h += '<td class="sm-cb"><input type="checkbox" id="sm-cb-' + i + '" data-i="' + i + '"' + (r.checked ? ' checked' : '')
               + (running ? ' disabled' : '') + '><label for="sm-cb-' + i + '"><span class="sm-sr">Register ' + esc(r.name || r.id) + '</span></label></td>';
        }
        h += '<td><span class="sm-name">' + esc(r.name || r.id) + '</span>';
        if (ROLE[r.role]) h += '<span class="sm-role" title="Suggested because it is in the same family">' + ROLE[r.role] + '</span>';
        if (r.parent) {
            var pn = r.parent.name ? esc(r.parent.name) : 'its parent';
            if (r.parent.in_run) h += '<div class="sm-sub">Child of ' + pn + ' (linked at SESAR once the parent is registered)</div>';
            else if (r.parent.igsn) h += '<div class="sm-sub">Child of ' + pn + ', ' + esc(r.parent.igsn) + '</div>';
            else h += '<div class="sm-sub">Child of ' + pn + ' (no SESAR IGSN, so no parent link is sent)</div>';
        }
        if (r.current_igsn && r.group !== 'blocked') h += '<div class="sm-sub">IGSN field now: ' + esc(r.current_igsn) + '</div>';
        if (r.location_note) h += '<div class="sm-sub">' + esc(r.location_note) + '</div>';
        (r.notes || []).forEach(function (n) { h += '<div class="sm-note">' + esc(n) + '</div>'; });
        (r.reasons || []).forEach(function (n) { h += '<div class="sm-reason">' + esc(n) + '</div>'; });
        if (r.status) h += '<div class="sm-status ' + r.status.cls + '">' + r.status.html + '</div>';
        h += '</td>';
        if (r.group === 'blocked') {
            h += '<td class="sm-ot"></td><td class="sm-mat"></td>';
        } else {
            h += '<td class="sm-ot"><select data-i="' + i + '" data-f="object_type" aria-label="Object type"' + (running ? ' disabled' : '') + '>'
               + objectTypeOptions(r.object_type) + '</select></td>'
               + '<td class="sm-mat"><input type="text" list="sm-materials" data-i="' + i + '" data-f="material" value="' + esc(r.material) + '"'
               + ' placeholder="(none)" aria-label="Material" autocomplete="off"' + (running ? ' disabled' : '')
               + (materialOk(r.material) ? '' : ' class="sm-bad"') + '></td>';
        }
        return h + '</tr>';
    }

    function table(rows, heading, hint) {
        if (!rows.length) return '';
        return '<div class="sm-group">' + heading + (hint ? '<small>' + hint + '</small>' : '') + '</div>'
            + '<table class="sm-table"><thead><tr><th></th><th>Sample</th><th>SESAR object type</th><th>Material (optional)</th></tr></thead><tbody>'
            + rows.map(function (x) { return rowHtml(x.r, x.i); }).join('') + '</tbody></table>';
    }

    function render() {
        var p = st.plan, c = p.choices;
        document.getElementById('sm-env').hidden = p.environment !== 'sandbox';
        var body = '';
        if (!c.codes.length) {
            document.getElementById('sm-body').innerHTML = '<div class="sm-msg err">Your SESAR account is not connected, or has no SESAR code yet. '
                + '<a href="/samples_igsn.php">Set it up on the IGSNs page</a>, then try again.</div>';
            document.getElementById('sm-foot').innerHTML = '<button type="button" class="sm-btn sm-quiet" data-act="close">Close</button>';
            return;
        }
        if (st.phase === 'review') {
            body += '<div class="sm-public"><strong>IGSNs are public and permanent.</strong> SESAR publishes each sample\'s name, location, '
                + 'description, collection date and purpose with its IGSN (a DOI). A registered IGSN cannot be deleted; SESAR staff can only deactivate it.</div>';
            body += '<div class="sm-shared"><div><label for="sm-code">SESAR code</label><select id="sm-code">'
                + c.codes.map(function (k) { return '<option' + (k === (st.code || c.last_code) ? ' selected' : '') + '>' + esc(k) + '</option>'; }).join('')
                + '</select></div><div><label for="sm-collector">Collector</label><input type="text" id="sm-collector" maxlength="255" value="'
                + esc(st.collector != null ? st.collector : c.collector) + '"></div></div>';
            var editable = st.rows.some(function (r) { return r.group !== 'blocked'; });
            if (editable && st.rows.filter(function (r) { return r.group !== 'blocked'; }).length > 1) {
                body += '<div class="sm-bulk"><div><label for="sm-all-ot">Object type for all checked samples</label><select id="sm-all-ot">'
                    + '<option value="">Choose…</option>' + objectTypeOptions('') + '</select></div>'
                    + '<button type="button" class="sm-btn sm-quiet" data-act="all-ot">Apply</button>'
                    + '<div><label for="sm-all-mat">Material for all checked samples</label><input type="text" id="sm-all-mat" list="sm-materials" '
                    + 'placeholder="(none)" autocomplete="off"></div>'
                    + '<button type="button" class="sm-btn sm-quiet" data-act="all-mat">Apply</button></div>';
            }
        } else {
            var total = st.queue ? st.queue.length : 0, doneN = Object.keys(st.done).length;
            body += '<p id="sm-progress">' + (st.phase === 'running'
                ? 'Registering ' + Math.min(doneN + 1, total) + ' of ' + total + '… Please keep this window open.'
                : summaryText()) + '</p>'
                + '<div class="sm-bar"><div style="width:' + (total ? Math.round(100 * doneN / total) : 0) + '%"></div></div>';
        }
        var idx = st.rows.map(function (r, i) { return { r: r, i: i }; });
        body += table(idx.filter(function (x) { return x.r.group === 'ready'; }), 'Ready to register');
        body += table(idx.filter(function (x) { return x.r.group === 'replace'; }), 'Needs your OK',
            'the IGSN field holds a value that is not a registered IGSN; tick a sample to replace that value');
        body += table(idx.filter(function (x) { return x.r.group === 'blocked'; }), 'Cannot be registered');
        if (p.missing && p.missing.length) {
            body += '<p class="sm-muted" style="margin-top:1em">' + p.missing.length + ' selected sample' + (p.missing.length === 1 ? ' is' : 's are')
                + ' not yours and cannot be registered by you.</p>';
        }
        body += '<datalist id="sm-materials">' + c.materials.map(function (m) { return '<option value="' + esc(m) + '">'; }).join('') + '</datalist>';

        var keep = document.getElementById('sm-body');
        var scroll = document.getElementById('sm-overlay').scrollTop;
        keep.innerHTML = body;
        document.getElementById('sm-overlay').scrollTop = scroll;
        renderFoot();
    }

    function renderFoot() {
        var foot = document.getElementById('sm-foot');
        if (st.phase === 'review') {
            var n = checkedRows().length, cap = st.plan.cap;
            var bad = checkedRows().some(function (r) { return !materialOk(r.material) || !r.object_type; });
            var msg = n === 0 ? 'Nothing selected' : (n + ' sample' + (n === 1 ? '' : 's') + ' selected');
            if (n > cap) msg += ' (at most ' + cap + ' per run)';
            else if (bad) msg += ' (fix the highlighted material: pick one from the list or leave it blank)';
            var label = 'Register ' + (n === 1 ? 'IGSN' : n + ' IGSNs'), off = n === 0 || n > cap || bad;
            // Update in place: rebuilding would swap the button out between
            // mousedown and click when a field's change event fires on blur,
            // and the first click on Register would be lost.
            var countEl = document.getElementById('sm-count'), startEl = foot.querySelector('[data-act=start]');
            if (countEl && startEl) {
                countEl.textContent = msg;
                startEl.textContent = label;
                startEl.disabled = off;
                return;
            }
            foot.innerHTML = '<span class="sm-count" id="sm-count">' + msg + '</span>'
                + '<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>'
                + '<button type="button" class="sm-btn" data-act="start"' + (off ? ' disabled' : '') + '>' + label + '</button>';
        } else if (st.phase === 'running') {
            foot.innerHTML = '<span class="sm-count">Working…</span><button type="button" class="sm-btn sm-quiet" data-act="stop"'
                + (st.stop ? ' disabled' : '') + '>' + (st.stop ? 'Stopping…' : 'Stop') + '</button>';
        } else {
            foot.innerHTML = '<span class="sm-count"></span><button type="button" class="sm-btn" data-act="close">Close</button>';
        }
    }

    function summaryText() {
        var ok = 0, err = 0, skip = 0;
        st.queue.forEach(function (i) {
            var s = st.rows[i].status;
            if (!s) skip++;
            else if (s.cls === 'ok') ok++;
            else if (s.cls === 'err') err++;
            else skip++;
        });
        var parts = [ok + ' registered'];
        if (err) parts.push(err + ' failed');
        if (skip) parts.push(skip + ' skipped');
        return (st.stop ? 'Stopped. ' : 'Done. ') + parts.join(', ') + '.';
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------
    function onClick(e) {
        var t = e.target.closest('[data-act]');
        if (!t || !st) return;
        var act = t.getAttribute('data-act');
        if (act === 'close') return close();
        if (act === 'stop') { st.stop = true; renderFoot(); return; }
        if (act === 'start') return start();
        if (act === 'all-ot' || act === 'all-mat') {
            var el = document.getElementById(act === 'all-ot' ? 'sm-all-ot' : 'sm-all-mat');
            var v = (el.value || '').trim();
            if (act === 'all-ot' && !v) return;
            if (act === 'all-mat' && !materialOk(v)) { el.classList.add('sm-bad'); el.focus(); return; }
            checkedRows().forEach(function (r) { r[act === 'all-ot' ? 'object_type' : 'material'] = v; });
            rememberShared();
            render();
        }
    }

    function onChange(e) {
        if (!st || st.phase !== 'review') return;
        var t = e.target, i = t.getAttribute('data-i');
        if (t.type === 'checkbox' && i !== null) {
            st.rows[+i].checked = t.checked;
            renderFoot();
        } else if (i !== null && t.getAttribute('data-f')) {
            st.rows[+i][t.getAttribute('data-f')] = t.value.trim();
            if (t.getAttribute('data-f') === 'material') t.classList.toggle('sm-bad', !materialOk(t.value));
            renderFoot();
        } else if (t.id === 'sm-code' || t.id === 'sm-collector') {
            rememberShared();
        }
    }

    function onInput(e) {
        if (!st || st.phase !== 'review') return;
        var t = e.target, i = t.getAttribute('data-i');
        if (i !== null && t.getAttribute('data-f') === 'material') {
            st.rows[+i].material = t.value.trim();
            t.classList.toggle('sm-bad', !materialOk(t.value));
            renderFoot();
        }
        if (t.id === 'sm-all-mat') t.classList.remove('sm-bad');
    }

    function rememberShared() {
        var code = document.getElementById('sm-code'), col = document.getElementById('sm-collector');
        if (code) st.code = code.value;
        if (col) st.collector = col.value;
    }

    // ------------------------------------------------------------------
    // The run (D4 loop, D8 parents first)
    // ------------------------------------------------------------------
    function start() {
        rememberShared();
        var chosen = checkedRows();
        if (!chosen.length) return;
        var inRun = {};
        chosen.forEach(function (r) { inRun[r.id] = true; });
        st.queue = [];
        st.rows.forEach(function (r, i) { if (r.group !== 'blocked' && r.checked) st.queue.push(i); });   // server order = parents first
        st.inRun = inRun;
        st.phase = 'running';
        st.stop = false;
        st.pos = 0;
        render();
        next();
    }

    function next() {
        if (!st) return;
        if (st.stop || st.pos >= st.queue.length) {
            st.phase = 'done';
            render();
            return;
        }
        var i = st.queue[st.pos], r = st.rows[i];
        var parentInRun = r.parent_id && st.inRun[r.parent_id];
        if (parentInRun) {
            var pr = st.rows.filter(function (x) { return x.id === r.parent_id; })[0];
            if (!pr || !pr.status || pr.status.cls !== 'ok') {
                r.status = { cls: 'skip', html: 'Skipped: its parent was not registered, so it would lose its parent link.' };
                st.done[r.id] = true;
                st.pos++;
                render();
                return next();
            }
        }
        r.status = { cls: 'run', html: 'Registering…' };
        render();
        post({
            action: 'mint', sample_id: r.id,
            choices: { sesar_code: st.code, object_type: r.object_type, material: r.material, collector: st.collector },
            replace_existing: r.group === 'replace', expect_parent: !!parentInRun
        }).then(function (j) {
            if (!st) return;
            if (j.ok) {
                var res = j.result;
                st.minted++;
                r.status = { cls: 'ok', html: 'Registered: <a href="' + esc(res.landing_url) + '" target="_blank" rel="noopener">' + esc(res.igsn) + '</a>'
                    + (res.notes && res.notes.length ? '<div class="sm-note">' + res.notes.map(esc).join('<br>') + '</div>' : '') };
            } else {
                r.status = { cls: 'err', html: esc(j.message || 'Failed.') };
            }
            st.done[r.id] = true;
            st.pos++;
            render();
            next();
        });
    }

    window.SesarMint = { open: open, injectStyles: injectStyles };
})();
