/**
 * File: assets/js/sesar_batch.js
 * Description: "SESAR batch file" modal (StraboSamples IGSN integration
 *              Phase 8, B1 + B2). The user picks the spreadsheet SESAR's
 *              Batch Template Creator gave them; /sesar_batch.php checks it
 *              (what goes in, what is left out and why), then fills its
 *              Samples sheet with the selected samples and sends it back as
 *              a download. Needs assets/js/sesar_mint.js loaded first
 *              (shared sm- styles).
 *
 *              SesarBatch.open({ samples: [{id, name}], appBase, connected })
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
        + '.sb-steps { margin: 0 0 1em 1.2em; padding: 0; }'
        + '.sb-steps li { margin: 0 0 0.45em; color: rgba(255,255,255,0.85); }'
        + '.sb-file { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }'
        + '.sb-pick { display: flex; flex-wrap: wrap; gap: 0.6em 1em; align-items: center; margin: 0 0 1em; }'
        + '.sb-pick label.sm-btn { display: inline-block; margin: 0; }'
        + '.sb-fname { color: rgba(255,255,255,0.75); font-size: 0.92em; word-break: break-all; }'
        + '.sb-list { list-style: none; margin: 0 0 1em; padding: 0; font-size: 0.92em; }'
        + '.sb-list li { padding: 0.35em 0; border-bottom: 1px solid rgba(255,255,255,0.08); }'
        + '.sm-msg.sb-warn { background: rgba(240,180,60,0.14); border: 1px solid rgba(240,180,60,0.4); color: #f3c97a; }';

    var MAX = 4999;
    var st = null;

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function ext(url, text) { return '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(text) + '</a>'; }

    function send(action) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('ids', JSON.stringify(st.opts.samples.map(function (s) { return s.id; })));
        fd.append('file', st.file, st.file.name);
        return fetch('/sesar_batch.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) {
            var type = r.headers.get('Content-Type') || '';
            if (r.ok && type.indexOf('spreadsheetml') !== -1) {
                var cd = r.headers.get('Content-Disposition') || '';
                var m = /filename="([^"]+)"/.exec(cd);
                return r.blob().then(function (b) { return { ok: true, blob: b, name: m ? m[1] : 'SESAR_batch.xlsx' }; });
            }
            return r.json().catch(function () { return { ok: false, message: 'Unexpected response from StraboSpot (HTTP ' + r.status + ').' }; });
        }, function () {
            return { ok: false, message: 'Could not reach StraboSpot. Please check your connection.' };
        });
    }

    function ensureDom() {
        if (document.getElementById('sb-overlay')) return;
        if (window.SesarMint && window.SesarMint.injectStyles) window.SesarMint.injectStyles();
        var style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);
        var ov = document.createElement('div');
        ov.id = 'sb-overlay';
        ov.className = 'sm-overlay';
        ov.hidden = true;
        ov.innerHTML = '<div class="sm-modal" role="dialog" aria-modal="true" aria-labelledby="sb-title" style="max-width: 760px">'
            + '<div class="sm-head"><h3 id="sb-title">SESAR batch upload file</h3>'
            + '<button type="button" class="sm-x" id="sb-x" aria-label="Close">&times;</button></div>'
            + '<div class="sm-body" id="sb-body"></div>'
            + '<div class="sm-foot" id="sb-foot"></div></div>';
        document.body.appendChild(ov);
        document.getElementById('sb-x').addEventListener('click', close);
        ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        ov.addEventListener('click', onClick);
        ov.addEventListener('change', function (e) {
            if (e.target.id !== 'sb-file' || !e.target.files || !e.target.files[0]) return;
            st.file = e.target.files[0];
            st.plan = null;
            st.done = null;
            check();
        });
    }

    function body(html) { document.getElementById('sb-body').innerHTML = html; }
    function foot(html) { document.getElementById('sb-foot').innerHTML = html; }

    function open(opts) {
        ensureDom();
        st = { opts: opts || {}, file: null, plan: null, busy: false, done: null, error: null };
        document.getElementById('sb-overlay').hidden = false;
        render();
        document.getElementById('sb-x').focus();
    }

    function close() {
        if (!st || st.busy) return;
        st = null;
        document.getElementById('sb-overlay').hidden = true;
    }

    function intro() {
        var o = st.opts, n = o.samples.length;
        return '<p>This writes the ' + (n === 1 ? 'selected sample' : n + ' selected samples')
            + ' into a spreadsheet you upload to SESAR yourself. Use it if you register samples through SESAR\'s batch upload instead of from StraboSpot.</p>'
            + '<ol class="sb-steps">'
            + '<li>At ' + ext(o.appBase, 'SESAR') + ', open <strong>Batch Template Creator</strong> and create a template with your SESAR code. '
            + 'For <strong>Object Type</strong>, choose to enter it per sample. Tick any other fields you want, then click <strong>Generate Spreadsheet</strong>.</li>'
            + '<li>Choose that file below. StraboSpot fills in your samples and gives the file back. Nothing else in it changes.</li>'
            + '<li>Upload the filled file at SESAR (batch upload). SESAR\'s curators review it before the IGSNs are assigned.</li>'
            + '</ol>'
            + '<div class="sb-pick"><input type="file" class="sb-file" id="sb-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">'
            + '<label for="sb-file" class="sm-btn' + (st.file ? ' sm-quiet' : '') + '">' + (st.file ? 'Choose a different file' : 'Choose SESAR template') + '</label>'
            + (st.file ? '<span class="sb-fname">' + esc(st.file.name) + '</span>' : '') + '</div>';
    }

    function planHtml(p) {
        var h = '';
        if (p.code_warning) h += '<div class="sm-msg sb-warn">' + esc(p.code_warning) + '</div>';
        h += '<p><strong>' + p.included + '</strong> sample' + (p.included === 1 ? '' : 's') + ' will be written to this template (SESAR code '
            + esc(p.sesar_code) + (p.template_version ? ', template ' + esc(p.template_version) : '') + ').</p>';
        if (p.unfilled_columns && p.unfilled_columns.length) {
            h += '<div class="sm-msg sb-warn">Your template has no column for: ' + esc(p.unfilled_columns.join(', '))
                + '. Those values are left out. To include them, tick those fields in the Batch Template Creator and generate a new template.</div>';
        }
        var skipped = p.samples.filter(function (s) { return !s.included; });
        var noted = p.samples.filter(function (s) { return s.included && s.notes.length; });
        if (skipped.length) {
            h += '<div class="sm-group">Left out (' + skipped.length + ')</div><ul class="sb-list">'
                + skipped.map(function (s) { return '<li><span class="sm-name">' + esc(s.name) + '</span><div class="sm-reason">' + esc(s.reason) + '</div></li>'; }).join('')
                + '</ul>';
        }
        if (noted.length) {
            h += '<div class="sm-group">Notes</div><ul class="sb-list">'
                + noted.map(function (s) { return '<li><span class="sm-name">' + esc(s.name) + '</span>' + s.notes.map(function (x) { return '<div class="sm-note">' + esc(x) + '</div>'; }).join('') + '</li>'; }).join('')
                + '</ul>';
        }
        return h;
    }

    function afterHtml() {
        var h = '<div class="sm-msg ok">Downloaded <strong>' + esc(st.done) + '</strong>. Upload it at ' + ext(st.opts.appBase, 'SESAR') + ' with batch upload.</div>'
            + '<p><strong>Getting the IGSNs back.</strong> Each sample carries "StraboSpot" and its StraboSpot id in SESAR\'s Other Name(s) column. '
            + 'After SESAR\'s curators approve the batch, ';
        h += st.opts.connected
            ? 'use <strong>Find my batch IGSNs</strong> on this page to add each IGSN to its sample.</p>'
            : 'connect your SESAR account on this page and use <strong>Find my batch IGSNs</strong>. Without a connection, add the IGSNs with a spreadsheet import on '
              + '<a href="/my_samples.php">My Samples</a>: put each sample\'s StraboSpot id (with or without "StraboSpot") in the strabo_internal_id column and its IGSN in the igsn column.</p>';
        return h;
    }

    function render() {
        var o = st.opts;
        if (o.samples.length > MAX) {
            body('<div class="sm-msg err">SESAR takes at most ' + MAX + ' samples per file. Select fewer samples.</div>');
            foot('<span class="sm-count"></span><button type="button" class="sm-btn" data-act="close">Close</button>');
            return;
        }
        var h = intro();
        if (st.error) h += '<div class="sm-msg err">' + esc(st.error) + '</div>';
        if (st.busy === 'check') h += '<p class="sm-muted">Checking the template...</p>';
        if (st.plan && !st.done) h += planHtml(st.plan);
        if (st.done) h += afterHtml();
        body(h);
        var canFill = st.plan && st.plan.included > 0 && !st.busy;
        foot('<span class="sm-count"></span>'
            + '<button type="button" class="sm-btn sm-quiet" data-act="close">' + (st.done ? 'Close' : 'Cancel') + '</button>'
            + (st.done ? '' : '<button type="button" class="sm-btn" data-act="fill"' + (canFill ? '' : ' disabled') + '>'
                + (st.busy === 'fill' ? 'Filling...' : 'Download filled template') + '</button>'));
    }

    function check() {
        st.busy = 'check';
        st.error = null;
        render();
        send('check').then(function (j) {
            if (!st) return;
            st.busy = false;
            if (!j.ok) { st.error = j.message || 'The template could not be checked.'; st.plan = null; }
            else st.plan = j.plan;
            render();
        });
    }

    function fill() {
        st.busy = 'fill';
        st.error = null;
        render();
        send('fill').then(function (j) {
            if (!st) return;
            st.busy = false;
            if (!j.ok || !j.blob) { st.error = j.message || 'The template could not be filled.'; render(); return; }
            var url = URL.createObjectURL(j.blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = j.name;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
            st.done = j.name;
            render();
        });
    }

    function onClick(e) {
        var b = e.target.closest('[data-act]');
        if (!b || b.disabled) return;
        var act = b.getAttribute('data-act');
        if (act === 'close') return close();
        if (act === 'fill') return fill();
    }

    window.SesarBatch = { open: open };
})();
