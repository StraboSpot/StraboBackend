/**
 * File: assets/js/sesar_push.js
 * Description: "Send to SESAR" modals (StraboSamples IGSN integration
 *              Phase 6: D6 + review P1-P4). Talks to /sesar_push.php. Needs
 *              assets/js/sesar_mint.js loaded first (shared sm- styles).
 *
 *              SesarPush.status(sampleId) -> Promise of {ok, status}
 *                  No SESAR call: would sending change anything? Drives the
 *                  "Changed since last sent to SESAR" notice.
 *              SesarPush.single({ sampleId, onDone })
 *                  Review: "SESAR now" vs "will send", differing fields
 *                  only. Fields edited at SESAR since StraboSpot last read
 *                  them block the send until the user pulls.
 *              SesarPush.bulk({ samples: [{id, name}], onDone })
 *                  One server call per sample; rows changed at SESAR are
 *                  skipped with "pull first".
 *
 *              The server re-reads SESAR for every send and refuses values
 *              that moved since the review; onDone(changed) is called on
 *              close.
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
        + '.su-cmp td.su-f { width: 9.5em; color: rgba(255,255,255,0.7); }'
        + '.su-cmp td.su-v { width: 40%; word-break: break-word; }'
        + '.su-cmp .su-empty { color: rgba(255,255,255,0.4); font-style: italic; }'
        + '.su-cmp tr.su-conflict td { background: rgba(228,76,101,0.1); }'
        + '.su-tag { display: inline-block; margin-left: 0.4em; font-size: 0.76em; padding: 0 0.5em; border-radius: 999px; white-space: nowrap;'
        + '  background: rgba(228,76,101,0.2); color: #f5a3b3; }'
        + '@media (max-width: 720px) {'
        + '  .su-cmp tr { grid-template-columns: 1fr !important; }'
        + '  .su-cmp td.su-v { width: auto; }'
        + '  .su-cmp td.su-v:before { content: attr(data-l) ": "; color: rgba(255,255,255,0.55); font-size: 0.9em; }'
        + '  .su-cmp td.su-f { font-weight: 600; color: #fff; }'
        + '}';

    var CAP = 100;
    var st = null;

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function val(v) { return (v == null || v === '') ? '<span class="su-empty">empty</span>' : esc(v); }
    function link(url, text) { return '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(text) + '</a>'; }

    function post(body) {
        return fetch('/sesar_push.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Unexpected response from StraboSpot (HTTP ' + r.status + ').' }; });
        }, function () {
            return { ok: false, message: 'Could not reach StraboSpot. Please check your connection.' };
        });
    }

    // ------------------------------------------------------------------
    // Modal shell (same look as the mint and pull modals)
    // ------------------------------------------------------------------
    function ensureDom() {
        if (document.getElementById('su-overlay')) return;
        if (window.SesarMint && window.SesarMint.injectStyles) window.SesarMint.injectStyles();
        var style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);
        var ov = document.createElement('div');
        ov.id = 'su-overlay';
        ov.className = 'sm-overlay';
        ov.hidden = true;
        ov.innerHTML = '<div class="sm-modal su-modal" role="dialog" aria-modal="true" aria-labelledby="su-title">'
            + '<div class="sm-head"><h3 id="su-title"></h3><span class="sm-env" id="su-env" hidden>SESAR test site (sandbox)</span>'
            + '<button type="button" class="sm-x" id="su-x" aria-label="Close">&times;</button></div>'
            + '<div class="sm-body" id="su-body"></div>'
            + '<div class="sm-foot" id="su-foot"></div></div>';
        document.body.appendChild(ov);
        document.getElementById('su-x').addEventListener('click', close);
        ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        ov.addEventListener('click', onClick);
    }

    function show(kind, title, opts) {
        ensureDom();
        st = { kind: kind, opts: opts || {}, phase: 'loading', changed: false, stop: false };
        document.getElementById('su-title').textContent = title;
        document.getElementById('su-env').hidden = true;
        document.getElementById('su-overlay').hidden = false;
        document.getElementById('su-x').focus();
    }
    function body(html) { document.getElementById('su-body').innerHTML = html; }
    function foot(html) { document.getElementById('su-foot').innerHTML = html; }
    function env(e) { document.getElementById('su-env').hidden = e !== 'sandbox'; }
    function closeFoot() { foot('<span class="sm-count"></span><button type="button" class="sm-btn" data-act="close">Close</button>'); }

    function fail(message) {
        st.phase = 'error';
        body('<div class="sm-msg err">' + esc(message || 'Something went wrong.') + '</div>');
        closeFoot();
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
        document.getElementById('su-overlay').hidden = true;
        if (typeof cb === 'function') cb(changed);
    }

    function onClick(e) {
        var t = e.target.closest('[data-act]');
        if (!t || !st) return;
        var act = t.getAttribute('data-act');
        if (act === 'close') return close();
        if (act === 'stop') { st.stop = true; t.disabled = true; t.textContent = 'Stopping…'; return; }
        if (st.kind === 'single' && act === 'send') return singleSend();
        if (st.kind === 'bulk' && act === 'start') return bulkStart();
    }

    // ------------------------------------------------------------------
    // Single sample
    // ------------------------------------------------------------------
    function single(opts) {
        show('single', 'Send to SESAR', opts);
        body('<p>Reading this sample\'s record at SESAR…</p>');
        foot('<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>');
        post({ action: 'preview', sample_id: st.opts.sampleId }).then(function (j) {
            if (!st) return;
            if (!j.ok) return fail(j.message);
            st.p = j.preview;
            st.phase = 'review';
            singleRender();
        });
    }

    function singleRender(resultHtml) {
        var p = st.p, h = '';
        env(p.environment);
        h += '<p>SESAR record ' + link(p.landing_url, p.igsn) + ' for <strong>' + esc(p.name) + '</strong>.</p>';
        if (resultHtml) h += resultHtml;
        if (p.blocked && st.phase === 'review') {
            h += '<div class="sm-msg err">Someone changed the marked field' + (p.rows.filter(function (r) { return r.conflict; }).length === 1 ? '' : 's')
                + ' at SESAR since StraboSpot last read this record. Use <strong>Pull from SESAR</strong> first, so those edits are not overwritten.</div>';
        }
        if (p.rows.length) {
            h += '<div class="sm-group">Changes to send<small>only these fields change at SESAR; everything else stays as it is</small></div>'
                + '<table class="sm-table su-cmp"><thead><tr><th>Field</th><th>At SESAR now</th><th>Will send</th></tr></thead><tbody>'
                + p.rows.map(function (r) {
                    return '<tr class="' + (r.conflict ? 'su-conflict' : '') + '"><td class="su-f">' + esc(r.label) + '</td>'
                        + '<td class="su-v" data-l="At SESAR now">' + val(r.sesar) + (r.conflict ? ' <span class="su-tag">changed at SESAR</span>' : '') + '</td>'
                        + '<td class="su-v" data-l="Will send">' + val(r.send) + '</td></tr>';
                }).join('') + '</tbody></table>';
        } else if (!resultHtml) {
            h += '<div class="sm-msg">SESAR already has this sample\'s values. Nothing to send.</div>';
        }
        if (p.link_back && st.phase === 'review') {
            h += '<p class="sm-muted" style="margin-top:0.8em">The SESAR record has no link back to this sample\'s StraboSpot page yet; sending adds it.</p>';
        }
        body(h);
        if (st.phase !== 'review' || p.blocked || (!p.rows.length && !p.link_back)) { closeFoot(); return; }
        var n = p.rows.length;
        foot('<span class="sm-count">' + (n ? n + ' field' + (n === 1 ? '' : 's') + ' to send' : 'Link back only') + '</span>'
            + '<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>'
            + '<button type="button" class="sm-btn" data-act="send">' + (n ? 'Send to SESAR' : 'Add link back') + '</button>');
    }

    function singleSend() {
        var seen = {};
        st.p.rows.forEach(function (r) { seen[r.field] = r.send; });
        st.phase = 'running';
        foot('<span class="sm-count">Sending…</span>');
        post({ action: 'apply', sample_id: st.opts.sampleId, mode: 'review', seen: seen }).then(function (j) {
            if (!st) return;
            st.phase = 'done';
            if (!j.ok) {
                singleRender('<div class="sm-msg err">' + esc(j.message || 'Something went wrong.') + '</div>');
                return;
            }
            st.changed = true;
            var r = j.result;
            var h = '<div class="sm-msg ok">' + (r.sent.length ? 'Sent to SESAR: ' + esc(r.sent.join(', ')) + '.' : 'Nothing needed sending.')
                + (r.link_back_added ? ' The link back to StraboSpot was added.' : '') + '</div>';
            if (r.notes.length) h += '<div class="sm-msg">' + r.notes.map(esc).join('<br>') + '</div>';
            st.p.rows = [];
            singleRender(h);
        });
    }

    // ------------------------------------------------------------------
    // Bulk (IGSN page selection)
    // ------------------------------------------------------------------
    function bulk(opts) {
        show('bulk', 'Send to SESAR', opts);
        st.rows = (st.opts.samples || []).map(function (s) { return { id: s.id, name: s.name, status: null }; });
        st.phase = 'review';
        bulkRender();
    }

    function bulkRender() {
        var h = '';
        if (st.phase === 'review') {
            h += '<p>For each sample, StraboSpot reads its record at SESAR and sends only the fields that differ. '
                + 'A sample whose record was changed at SESAR since StraboSpot last read it is skipped: pull it first.</p>';
        } else {
            var done = st.rows.filter(function (r) { return r.status && r.status.cls !== 'run'; }).length;
            h += '<p>' + (st.phase === 'running' ? 'Sending ' + Math.min(done + 1, st.rows.length) + ' of ' + st.rows.length + '… Please keep this window open.'
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
                + '<button type="button" class="sm-btn" data-act="start"' + (n === 0 || n > CAP ? ' disabled' : '') + '>Send ' + n + ' to SESAR</button>');
        } else if (st.phase === 'running') {
            foot('<span class="sm-count">Working…</span><button type="button" class="sm-btn sm-quiet" data-act="stop">Stop</button>');
        } else {
            closeFoot();
        }
    }

    function bulkSummary() {
        var ok = 0, err = 0, skip = 0;
        st.rows.forEach(function (r) {
            if (!r.status) skip++; else if (r.status.cls === 'ok') ok++; else if (r.status.cls === 'err') err++; else skip++;
        });
        var parts = [ok + ' sent'];
        if (skip) parts.push(skip + ' skipped');
        if (err) parts.push(err + ' failed');
        return (st.stop ? 'Stopped. ' : 'Done. ') + parts.join(', ') + '.';
    }

    function bulkStart() {
        st.phase = 'running';
        st.pos = 0;
        bulkRender();
        bulkNext();
    }

    function bulkNext() {
        if (!st) return;
        if (st.stop || st.pos >= st.rows.length) { st.phase = 'done'; bulkRender(); return; }
        var r = st.rows[st.pos];
        r.status = { cls: 'run', html: 'Sending…' };
        bulkRender();
        post({ action: 'apply', sample_id: r.id, mode: 'bulk' }).then(function (j) {
            if (!st) return;
            if (j.ok) {
                var res = j.result;
                env(res.environment);
                if (res.sent.length || res.link_back_added) st.changed = true;
                var h = link(res.landing_url, res.igsn) + ': ' + (res.sent.length ? 'sent ' + esc(res.sent.join(', ')) : 'nothing to send')
                    + (res.link_back_added ? '; link back added' : '');
                if (res.notes.length) h += '<div class="sm-note">' + res.notes.map(esc).join('<br>') + '</div>';
                r.status = { cls: 'ok', html: h };
            } else if (j.fields && j.fields.pull_first) {
                r.status = { cls: 'skip', html: esc(j.message) };
            } else {
                r.status = { cls: 'err', html: esc(j.message || 'Failed.') };
            }
            st.pos++;
            bulkNext();
        });
    }

    function status(sampleId) { return post({ action: 'status', sample_id: sampleId }); }

    window.SesarPush = { status: status, single: single, bulk: bulk };
})();
