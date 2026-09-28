/**
 * File: assets/js/sesar_ui.js
 * Description: What every SESAR dialog shares (StraboSamples IGSN
 *              integration): the dialog shell and its styles (the sm-
 *              classes), HTML escaping, and the calls to the sesar_*.php
 *              endpoints. Load it BEFORE the other assets/js/sesar_*.js
 *              files.
 *
 *              SesarUi.esc(text), SesarUi.link(url, text)
 *              SesarUi.post(url, body) -> Promise of the JSON answer;
 *                  never rejects: {ok:false, message} when StraboSpot
 *                  cannot be reached or answers something else.
 *              SesarUi.postForm(url, formData, isFile(contentType))
 *                  -> the same, or {ok:true, blob, name, headers} when the
 *                  answer is a file.
 *              SesarUi.save(blob, name): hand a file to the browser.
 *              SesarUi.modal({ prefix, title, cls, style, env, css,
 *                              close, click, change, input })
 *                  One dialog, built on first use. prefix names its
 *                  elements (<prefix>-overlay, -title, -env, -x, -body,
 *                  -foot); env: it has the "SESAR test site" badge; css:
 *                  its own rules; close: called by the x button and Escape;
 *                  click / change / input: handlers for the whole dialog.
 *                  Returns { show(title), hide(), title(text), env(name),
 *                  body(html), foot(html), closeFoot(), fail(message) }.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
(function () {
    'use strict';

    // The shared look: every dialog is built from these classes.
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
        + 'a.sm-btn, a.sm-btn:hover, a.sm-btn:focus, a.sm-btn:visited { color: #fff; text-decoration: none; display: inline-block; }'
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

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function link(url, text) { return '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(text) + '</a>'; }

    // ------------------------------------------------------------------
    // Calls to the sesar_*.php endpoints
    // ------------------------------------------------------------------
    function notJson(r) { return { ok: false, message: 'Unexpected response from StraboSpot (HTTP ' + r.status + ').' }; }
    function unreachable() { return { ok: false, message: 'Could not reach StraboSpot. Please check your connection.' }; }

    function post(url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return notJson(r); });
        }, unreachable);
    }

    function postForm(url, formData, isFile) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: formData }).then(function (r) {
            if (r.ok && isFile(r.headers.get('Content-Type') || '')) {
                var m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
                return r.blob().then(function (b) { return { ok: true, blob: b, name: m ? m[1] : null, headers: r.headers }; });
            }
            return r.json().catch(function () { return notJson(r); });
        }, unreachable);
    }

    function save(blob, name) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
    }

    // ------------------------------------------------------------------
    // Dialog shell
    // ------------------------------------------------------------------
    function injectStyles() {
        if (document.getElementById('sm-styles')) return;
        var style = document.createElement('style');
        style.id = 'sm-styles';
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    function modal(o) {
        var ov = null;
        function el(part) { return document.getElementById(o.prefix + '-' + part); }

        function build() {
            if (ov) return;
            injectStyles();
            if (o.css) {
                var style = document.createElement('style');
                style.textContent = o.css;
                document.head.appendChild(style);
            }
            ov = document.createElement('div');
            ov.id = o.prefix + '-overlay';
            ov.className = 'sm-overlay';
            ov.hidden = true;
            ov.innerHTML = '<div class="sm-modal' + (o.cls ? ' ' + o.cls : '') + '" role="dialog" aria-modal="true" aria-labelledby="' + o.prefix + '-title"'
                + (o.style ? ' style="' + o.style + '"' : '') + '>'
                + '<div class="sm-head"><h3 id="' + o.prefix + '-title">' + esc(o.title || '') + '</h3>'
                + (o.env ? '<span class="sm-env" id="' + o.prefix + '-env" hidden>SESAR test site (sandbox)</span>' : '')
                + '<button type="button" class="sm-x" id="' + o.prefix + '-x" aria-label="Close">&times;</button></div>'
                + '<div class="sm-body" id="' + o.prefix + '-body"></div>'
                + '<div class="sm-foot" id="' + o.prefix + '-foot"></div></div>';
            document.body.appendChild(ov);
            el('x').addEventListener('click', o.close);
            ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') o.close(); });
            if (o.click) ov.addEventListener('click', o.click);
            if (o.change) ov.addEventListener('change', o.change);
            if (o.input) ov.addEventListener('input', o.input);
        }

        function title(text) { build(); el('title').textContent = text; }
        function env(name) { build(); if (o.env) el('env').hidden = name !== 'sandbox'; }
        function body(html) { build(); el('body').innerHTML = html; }
        function foot(html) { build(); el('foot').innerHTML = html; }
        function closeFoot() { foot('<span class="sm-count"></span><button type="button" class="sm-btn" data-act="close">Close</button>'); }
        function fail(message) {
            body('<div class="sm-msg err">' + esc(message || 'Something went wrong.') + '</div>');
            closeFoot();
        }
        /** Opens the dialog: the badge starts hidden (env() shows it), the x button gets the focus. */
        function show(text) {
            build();
            if (text != null) title(text);
            env(null);
            ov.hidden = false;
            el('x').focus();
        }
        function hide() { if (ov) ov.hidden = true; }

        return { show: show, hide: hide, title: title, env: env, body: body, foot: foot, closeFoot: closeFoot, fail: fail };
    }

    // ------------------------------------------------------------------
    // "Connect SESAR first": every action that needs the user's SESAR
    // connection opens this instead when there is none (or it expired).
    // The connection flow itself lives on the IGSN page's panel.
    // ------------------------------------------------------------------
    var IGSN_PAGE = '/samples_igsn.php';
    var cf = null;
    function onIgsnPage() { return window.location.pathname === IGSN_PAGE; }

    /** o: {title, what ("register IGSNs"), reconnect (bool)} */
    function connectFirst(o) {
        o = o || {};
        if (!cf) {
            cf = modal({ prefix: 'sc', title: '', close: function () { cf.hide(); }, click: function (e) {
                var b = e.target.closest('[data-act]');
                if (!b) return;
                if (b.getAttribute('data-act') === 'close') { cf.hide(); return; }
                if (b.getAttribute('data-act') === 'connect') {
                    cf.hide();
                    if (onIgsnPage()) {   // the panel is on this page: go to it
                        e.preventDefault();
                        var panel = document.getElementById('si-conn');
                        if (panel) { panel.scrollIntoView({ behavior: 'smooth', block: 'center' }); panel.focus && panel.focus(); }
                    }
                }
            } });
        }
        cf.show(o.title || 'SESAR');
        cf.body('<p>' + (o.reconnect
                ? 'Your SESAR connection has expired. To ' + esc(o.what || 'use SESAR') + ', connect your SESAR account again. It takes one sign-in with ORCID.'
                : 'To ' + esc(o.what || 'use SESAR') + ', first connect your SESAR account. It is a one-time sign-in with ORCID.')
            + '</p>' + (onIgsnPage() ? '' : '<p class="sm-muted">The IGSNs page opens in a new tab. Come back here when you are connected and try again.</p>'));
        cf.foot('<button type="button" class="sm-btn sm-quiet" data-act="close">Cancel</button>'
            + '<a class="sm-btn" data-act="connect" href="' + IGSN_PAGE + '"' + (onIgsnPage() ? '' : ' target="_blank" rel="noopener"')
            + '>Connect SESAR</a>');
    }

    /** True for an endpoint answer meaning "no usable SESAR connection" (SesarConnection's 401s). */
    function notConnected(j) { return !!(j && j.ok === false && j.error === 'auth'); }

    window.SesarUi = { esc: esc, link: link, post: post, postForm: postForm, save: save, injectStyles: injectStyles, modal: modal,
                       connectFirst: connectFirst, notConnected: notConnected };
})();
