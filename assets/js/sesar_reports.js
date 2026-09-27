/**
 * File: assets/js/sesar_reports.js
 * Description: Report downloads on the IGSN page (StraboSamples IGSN
 *              integration Phase 8, D9 + R1). Talks to /sesar_reports.php.
 *              Needs assets/js/sesar_mint.js loaded first (shared sm- styles).
 *
 *              SesarReports.igsns({ selected: [ids], shown: [ids] })
 *                  "My IGSNs": which samples (selected, or all shown with an
 *                  IGSN), XLSX or CSV, "Refresh from SESAR first" (checked
 *                  by default; reads SESAR live and never changes what
 *                  StraboSpot has stored).
 *              SesarReports.account({ format })
 *                  "My SESAR account": every sample in the connected SESAR
 *                  account; straight download.
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
        + '.sr-opts { display: grid; gap: 1em; margin: 0 0 1em; }'
        + '.sr-opts fieldset { border: none; margin: 0; padding: 0; }'
        + '.sr-opts legend { font-weight: 600; color: #fff; margin: 0 0 0.35em; padding: 0; }'
        + '.sr-opts .sr-row { margin: 0 0 0.3em; }'
        + '.sr-opts .sr-row label { margin: 0; color: rgba(255,255,255,0.88); }'
        + '.sr-opts .sm-sub { margin-left: 2.1em; }';

    var st = null;

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** POST form -> file download; resolves {ok, rows, notes} or {ok:false, message}. */
    function download(fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch('/sesar_reports.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) {
            var type = r.headers.get('Content-Type') || '';
            if (r.ok && type.indexOf('application/json') === -1) {
                var m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
                var rows = r.headers.get('X-Report-Rows'), notes = r.headers.get('X-Report-Notes');
                return r.blob().then(function (b) {
                    var url = URL.createObjectURL(b), a = document.createElement('a');
                    a.href = url; a.download = m ? m[1] : 'report';
                    document.body.appendChild(a); a.click(); a.remove();
                    setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
                    return { ok: true, name: a.download, rows: rows === null ? null : +rows, notes: notes ? decodeURIComponent(notes) : '' };
                });
            }
            return r.json().catch(function () { return { ok: false, message: 'Unexpected response from StraboSpot (HTTP ' + r.status + ').' }; });
        }, function () {
            return { ok: false, message: 'Could not reach StraboSpot. Please check your connection.' };
        });
    }

    function ensureDom() {
        if (document.getElementById('sr-overlay')) return;
        if (window.SesarMint && window.SesarMint.injectStyles) window.SesarMint.injectStyles();
        var style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);
        var ov = document.createElement('div');
        ov.id = 'sr-overlay';
        ov.className = 'sm-overlay';
        ov.hidden = true;
        ov.innerHTML = '<div class="sm-modal" role="dialog" aria-modal="true" aria-labelledby="sr-title" style="max-width: 620px">'
            + '<div class="sm-head"><h3 id="sr-title">Download IGSN report</h3>'
            + '<button type="button" class="sm-x" id="sr-x" aria-label="Close">&times;</button></div>'
            + '<div class="sm-body" id="sr-body"></div>'
            + '<div class="sm-foot" id="sr-foot"></div></div>';
        document.body.appendChild(ov);
        document.getElementById('sr-x').addEventListener('click', close);
        ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        ov.addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b || b.disabled) return;
            if (b.getAttribute('data-act') === 'close') close();
            if (b.getAttribute('data-act') === 'go') go();
        });
    }

    function close() {
        if (!st || st.busy) return;
        st = null;
        document.getElementById('sr-overlay').hidden = true;
    }

    function radio(name, value, label, checked, sub) {
        var id = 'sr-' + name + '-' + value;
        return '<div class="sr-row"><input type="radio" name="sr-' + name + '" id="' + id + '" value="' + value + '"' + (checked ? ' checked' : '') + '>'
            + '<label for="' + id + '">' + label + '</label>' + (sub ? '<div class="sm-sub">' + sub + '</div>' : '') + '</div>';
    }

    function igsns(opts) {
        ensureDom();
        st = { opts: opts || {}, busy: false, msg: null };
        var sel = st.opts.selected.length, shown = st.opts.shown.length;
        var h = '<p>One row per sample: its IGSN and links, its StraboSamples values, its SESAR values, and whether StraboSpot and SESAR are in step.</p>'
            + '<div class="sr-opts"><fieldset><legend>Samples</legend>'
            + (sel ? radio('scope', 'selected', 'The ' + (sel === 1 ? 'selected sample' : sel + ' selected samples'), true) : '')
            + radio('scope', 'shown', 'All ' + shown + ' shown samples with an IGSN', !sel, 'Uses the search and filters above the list.')
            + '</fieldset><fieldset><legend>Format</legend>'
            + radio('format', 'xlsx', 'Excel (.xlsx)', true) + radio('format', 'csv', 'CSV', false)
            + '</fieldset><fieldset><legend>SESAR values</legend>'
            + '<div class="sr-row"><input type="checkbox" id="sr-refresh" checked><label for="sr-refresh">Refresh from SESAR first</label>'
            + '<div class="sm-sub">Reads SESAR now and marks anything changed there since StraboSpot last read it. Nothing StraboSpot has stored is changed; use Pull from SESAR to take changes in. '
            + 'Untick to use StraboSpot\'s stored copies (faster).</div></div>'
            + '</fieldset></div><div id="sr-msg"></div>';
        document.getElementById('sr-title').textContent = 'Download IGSN report';
        document.getElementById('sr-body').innerHTML = h;
        renderFoot();
        document.getElementById('sr-overlay').hidden = false;
        document.getElementById('sr-x').focus();
    }

    function renderFoot() {
        document.getElementById('sr-foot').innerHTML = '<span class="sm-count">' + (st.busy ? 'Preparing the report...' : '') + '</span>'
            + '<button type="button" class="sm-btn sm-quiet" data-act="close"' + (st.busy ? ' disabled' : '') + '>Close</button>'
            + '<button type="button" class="sm-btn" data-act="go"' + (st.busy ? ' disabled' : '') + '>Download</button>';
    }

    function go() {
        var pick = function (n) { var el = document.querySelector('input[name="sr-' + n + '"]:checked'); return el ? el.value : null; };
        var ids = pick('scope') === 'selected' ? st.opts.selected : st.opts.shown;
        var msg = document.getElementById('sr-msg');
        if (!ids.length) { msg.innerHTML = '<div class="sm-msg err">No samples with an IGSN are shown.</div>'; return; }
        st.busy = true;
        msg.innerHTML = '';
        renderFoot();
        download({ report: 'igsns', format: pick('format') || 'xlsx', refresh: document.getElementById('sr-refresh').checked ? '1' : '0', ids: JSON.stringify(ids) })
            .then(function (j) {
                if (!st) return;
                st.busy = false;
                renderFoot();
                msg.innerHTML = j.ok
                    ? '<div class="sm-msg ok">Downloaded ' + esc(j.name) + (j.rows !== null ? ' (' + j.rows + ' sample' + (j.rows === 1 ? '' : 's') + ')' : '') + '.</div>'
                      + (j.notes ? '<div class="sm-msg err">' + esc(j.notes) + '</div>' : '')
                    : '<div class="sm-msg err">' + esc(j.message || 'The report could not be made.') + '</div>';
            });
    }

    /** Report 2: straight download; returns the promise so the caller can show busy / errors. */
    function account(opts) {
        return download({ report: 'account', format: (opts && opts.format) || 'xlsx' });
    }

    window.SesarReports = { igsns: igsns, account: account };
})();
