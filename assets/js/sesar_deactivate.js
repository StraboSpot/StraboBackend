/**
 * File: assets/js/sesar_deactivate.js
 * Description: "Request deactivation" dialog (StraboSamples IGSN integration
 *              Phase 7: D7 + review Q1-Q3). Talks to /sesar_deactivate.php.
 *              Needs assets/js/sesar_mint.js loaded first (shared sm- styles).
 *
 *              SesarDeactivate.open({ sampleId | reg, onDone })
 *                  Reads SESAR first (may this account ask? already pending?),
 *                  then: what deactivation means, SESAR's reason, type the
 *                  IGSN to confirm. reg = an orphan row (sample deleted).
 *              SesarDeactivate.check({ sampleId | reg }) -> Promise
 *                  "Check with SESAR now" for a pending request.
 *              SesarDeactivate.keep(reg) -> Promise
 *                  "Keep" an orphan IGSN (stop listing it).
 *
 *              onDone(changed) is called on close.
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
        + '.sd7-reasons div { margin: 0.35em 0; }'
        + '.sd7-reasons input[type="radio"] + label { color: rgba(255,255,255,0.9); }'
        + '.sd7-modal textarea, .sd7-modal input.sd7-confirm { width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.08); color: #fff;'
        + '  border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; padding: 0.55em 0.75em; font-size: 0.95em; font-family: inherit; }'
        + '.sd7-modal textarea { min-height: 4.5em; resize: vertical; }'
        + '.sd7-modal input.sd7-confirm { font-family: monospace; height: auto; display: block; }'
        + '.sd7-field { margin: 0.9em 0; }'
        + '.sd7-field label.sd7-l { display: block; font-size: 0.88em; color: rgba(255,255,255,0.7); margin-bottom: 0.3em; }'
        + '.sd7-facts { margin: 0 0 1em 1.2em; padding: 0; color: rgba(255,255,255,0.85); }'
        + '.sd7-facts li { margin-bottom: 0.3em; }';

    var st = null;

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function link(url, text) { return '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(text) + '</a>'; }
    function target(o) { return o.reg ? { reg: o.reg } : { sample_id: o.sampleId }; }

    function post(body) {
        return fetch('/sesar_deactivate.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Unexpected response from StraboSpot (HTTP ' + r.status + ').' }; });
        }, function () {
            return { ok: false, message: 'Could not reach StraboSpot. Please check your connection.' };
        });
    }
    function merge(a, b) { var o = {}, k; for (k in a) o[k] = a[k]; for (k in b) o[k] = b[k]; return o; }

    // ------------------------------------------------------------------
    // Modal shell (same look as the mint, pull and push modals)
    // ------------------------------------------------------------------
    function ensureDom() {
        if (document.getElementById('sd7-overlay')) return;
        if (window.SesarMint && window.SesarMint.injectStyles) window.SesarMint.injectStyles();
        var style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);
        var ov = document.createElement('div');
        ov.id = 'sd7-overlay';
        ov.className = 'sm-overlay';
        ov.hidden = true;
        ov.innerHTML = '<div class="sm-modal sd7-modal" role="dialog" aria-modal="true" aria-labelledby="sd7-title" style="max-width:640px">'
            + '<div class="sm-head"><h3 id="sd7-title">Request deactivation</h3><span class="sm-env" id="sd7-env" hidden>SESAR test site (sandbox)</span>'
            + '<button type="button" class="sm-x" id="sd7-x" aria-label="Close">&times;</button></div>'
            + '<div class="sm-body" id="sd7-body"></div>'
            + '<div class="sm-foot" id="sd7-foot"></div></div>';
        document.body.appendChild(ov);
        document.getElementById('sd7-x').addEventListener('click', close);
        ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        ov.addEventListener('click', onClick);
        ov.addEventListener('input', onInput);
        ov.addEventListener('change', onInput);
    }
    function body(html) { document.getElementById('sd7-body').innerHTML = html; }
    function foot(html) { document.getElementById('sd7-foot').innerHTML = html; }
    function closeFoot() { foot('<span class="sm-count"></span><button type="button" class="sm-btn" data-act="close">Close</button>'); }

    function close() {
        if (!st || st.phase === 'sending') return;
        var changed = st.changed, cb = st.opts.onDone;
        st = null;
        document.getElementById('sd7-overlay').hidden = true;
        if (typeof cb === 'function') cb(changed);
    }

    function onClick(e) {
        var t = e.target.closest('[data-act]');
        if (!t || !st) return;
        var act = t.getAttribute('data-act');
        if (act === 'close') return close();
        if (act === 'send') return send();
    }

    // ------------------------------------------------------------------
    // Flow
    // ------------------------------------------------------------------
    function open(opts) {
        ensureDom();
        st = { opts: opts || {}, phase: 'loading', changed: false };
        document.getElementById('sd7-env').hidden = true;
        document.getElementById('sd7-overlay').hidden = false;
        document.getElementById('sd7-x').focus();
        body('<p>Checking this IGSN at SESAR…</p>');
        foot('<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>');
        post(merge({ action: 'preview' }, target(st.opts))).then(function (j) {
            if (!st) return;
            if (!j.ok) { st.phase = 'done'; body('<div class="sm-msg err">' + esc(j.message || 'Something went wrong.') + '</div>'); closeFoot(); return; }
            st.p = j.preview;
            document.getElementById('sd7-env').hidden = st.p.environment !== 'sandbox';
            if (st.p.state !== 'ready') {
                st.phase = 'done';
                st.changed = true;   // the page should show the recorded state
                body('<div class="sm-msg">' + esc(st.p.message) + '</div>');
                closeFoot();
                return;
            }
            st.phase = 'form';
            render();
        });
    }

    function render(errHtml) {
        var p = st.p, h = '';
        h += '<p>Ask SESAR to deactivate ' + link(p.landing_url, p.igsn) + (p.orphan ? '' : ' (<strong>' + esc(p.name) + '</strong>)') + '.</p>'
            + '<ul class="sd7-facts">'
            + '<li>IGSNs are never deleted. A deactivated IGSN still resolves, to a page saying it was deactivated, and it leaves SESAR\'s catalog search.</li>'
            + '<li>A SESAR curator reviews the request and may decline it. SESAR emails you with the decision.</li>'
            + '<li>This cannot be undone once approved.</li>'
            + (p.orphan ? '<li>The StraboSamples sample it belonged to was already deleted.</li>'
                        : '<li>Nothing changes in StraboSamples until SESAR approves. Then the IGSN is removed from this sample (kept in its history), and you may register a new one.</li>')
            + '</ul>';
        if (errHtml) h += errHtml;
        h += '<div class="sm-group">Reason</div><div class="sd7-reasons">';
        var i = 0;
        for (var k in p.reasons) {
            h += '<div><input type="radio" name="sd7-reason" id="sd7-r' + i + '" value="' + esc(k) + '"' + (st.reason === k ? ' checked' : '') + '>'
                + '<label for="sd7-r' + i + '">' + esc(p.reasons[k]) + '</label></div>';
            i++;
        }
        h += '</div>';
        var needDetail = st.reason === 'other' || st.reason === 'duplicate igsn';
        h += '<div class="sd7-field" id="sd7-detail-wrap"' + (needDetail ? '' : ' hidden') + '>'
            + '<label class="sd7-l" for="sd7-detail" id="sd7-detail-label">' + (st.reason === 'duplicate igsn' ? 'The IGSN(s) this one duplicates' : 'Why (250 characters at most)') + '</label>'
            + '<textarea id="sd7-detail" maxlength="1000">' + esc(st.detail || '') + '</textarea></div>'
            + '<div class="sd7-field"><label class="sd7-l" for="sd7-confirm">Type the IGSN to confirm: <span style="font-family:monospace;color:#fff">' + esc(p.igsn) + '</span></label>'
            + '<input type="text" class="sd7-confirm" id="sd7-confirm" autocomplete="off" spellcheck="false" value="' + esc(st.confirm || '') + '"></div>';
        body(h);
        renderFoot();
    }

    function ready() {
        if (!st.reason) return false;
        var d = (st.detail || '').trim();
        if ((st.reason === 'other' || st.reason === 'duplicate igsn') && d === '') return false;
        if (st.reason === 'other' && d.length > 250) return false;
        var typed = (st.confirm || '').trim().toUpperCase().replace(/^HTTPS?:\/\/(DX\.)?DOI\.ORG\//, '');
        var want = st.p.igsn.toUpperCase();
        return typed === want || ('10.58052/' + typed) === want;
    }

    function renderFoot() {
        var ok = ready();
        var btn = document.querySelector('#sd7-foot [data-act=send]');
        if (btn) { btn.disabled = !ok; return; }   // in place, so typing keeps focus
        foot('<span class="sm-count"></span><button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>'
            + '<button type="button" class="sm-btn" data-act="send"' + (ok ? '' : ' disabled') + '>Request deactivation</button>');
    }

    function onInput(e) {
        if (!st || st.phase !== 'form') return;
        var t = e.target;
        if (t.name === 'sd7-reason') {
            st.reason = t.value;
            var wrap = document.getElementById('sd7-detail-wrap');
            wrap.hidden = !(st.reason === 'other' || st.reason === 'duplicate igsn');
            document.getElementById('sd7-detail-label').textContent = st.reason === 'duplicate igsn' ? 'The IGSN(s) this one duplicates' : 'Why (250 characters at most)';
        }
        if (t.id === 'sd7-detail') st.detail = t.value;
        if (t.id === 'sd7-confirm') st.confirm = t.value;
        renderFoot();
    }

    function send() {
        if (!ready()) return;
        st.phase = 'sending';
        foot('<span class="sm-count">Sending the request to SESAR…</span>');
        post(merge({ action: 'request', reason: st.reason, detail: st.detail || '', confirm: st.confirm || '' }, target(st.opts))).then(function (j) {
            if (!st) return;
            if (!j.ok) {
                st.phase = 'form';
                render('<div class="sm-msg err">' + esc(j.message || 'Something went wrong.') + '</div>');
                return;
            }
            st.phase = 'done';
            st.changed = true;
            body('<div class="sm-msg ok">' + esc(j.result.message) + '</div>');
            closeFoot();
        });
    }

    function check(opts) { return post(merge({ action: 'check' }, target(opts))); }
    function keep(reg) { return post({ action: 'keep', reg: reg }); }

    window.SesarDeactivate = { open: open, check: check, keep: keep };
})();
