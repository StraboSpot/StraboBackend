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
 *              Needs assets/js/sesar_ui.js loaded first (dialog shell, styles).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
(function () {
    'use strict';

    var ROLE = { ancestor: 'parent', descendant: 'child' };
    var st = null;   // state of the open modal

    var ui = window.SesarUi, esc = ui.esc;
    var dlg = ui.modal({ prefix: 'sm', title: 'Register IGSNs at SESAR', env: true,
                         close: close, click: onClick, change: onChange, input: onInput });
    function post(body) { return ui.post('/sesar_mint.php', body); }

    function open(opts) {
        st = { opts: opts || {}, plan: null, rows: [], phase: 'loading', minted: 0, stop: false, done: {} };
        dlg.show();
        dlg.body('<p>Checking your samples with SESAR…</p>');
        dlg.foot('<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>');
        post({ action: 'plan', sample_ids: st.opts.sampleIds || [] }).then(function (j) {
            if (!st) return;
            if (!j.ok) {
                st.phase = 'error';
                document.getElementById('sm-body').innerHTML = '<div class="sm-msg err">' + esc(j.message || 'Something went wrong.') + '</div>';
                return;
            }
            if (!j.plan.choices.connected) {   // nothing can be registered without a SESAR connection
                st.phase = 'error';
                close();
                ui.connectFirst({ title: 'Register IGSNs at SESAR', what: 'register IGSNs', reconnect: j.plan.choices.reconnect });
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
        }).catch(function () {   // never leave the dialog on "Checking..."
            if (!st) return;
            st.phase = 'error';
            dlg.fail('The review could not be shown. Please reload the page and try again.');
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
        dlg.hide();
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
        if (!c.codes || !c.codes.length) {
            document.getElementById('sm-body').innerHTML = '<div class="sm-msg err">Your SESAR account has no SESAR code yet. '
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

    window.SesarMint = { open: open };
})();
