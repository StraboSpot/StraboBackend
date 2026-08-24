/**
 * File: app.js
 * Description: StraboSearch page orchestrator — wires builder + results
 *              + saved modules, the Search button enable rule (§6.4:
 *              ≥1 active row), the shareable URL state (?q=<base64-json>
 *              mirrors {dsl, tab, sort}; a page component existed before
 *              the 2026-08-12 infinite-scroll switch and is ignored on
 *              old links; loading a shared URL repopulates the builder
 *              and auto-runs), and the anonymous inline note
 *              (session-dismissable, §6.4).
 *
 * @package    StraboSpot Web Site — StraboSearch
 */

(function (window, document) {
	'use strict';

	var CFG = window.STRABO_SEARCH;

	// ---- URL state --------------------------------------------------------

	function encodeState(obj) {
		var json = JSON.stringify(obj);
		return btoa(unescape(encodeURIComponent(json)))
			.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function decodeState(s) {
		try {
			var b64 = s.replace(/-/g, '+').replace(/_/g, '/');
			while (b64.length % 4) b64 += '=';
			return JSON.parse(decodeURIComponent(escape(atob(b64))));
		} catch (e) {
			return null;
		}
	}

	var lastRunDsl = null;

	function mirrorUrl(urlState) {
		if (!lastRunDsl) return;
		var payload = {
			dsl: lastRunDsl,
			tab: urlState ? urlState.tab : 'projects',
			sort: urlState ? urlState.sort : null,
			view: urlState ? urlState.view : 'list'
		};
		var qs = '?q=' + encodeState(payload);
		window.history.replaceState(null, '', window.location.pathname + qs);
	}

	// ---- search execution -------------------------------------------------

	function runSearch(opts) {
		// Browse mode (M3): the empty-criteria globe run skips the
		// active-row gate; with active rows a browse request is just a
		// normal search opened in globe view.
		var browse = !!(opts && opts.browse);
		if (!window.SSBuilder.hasActiveRow() && !browse) return;
		lastRunDsl = window.SSBuilder.getDsl();
		window.SSResults.run(lastRunDsl, opts || {});
	}

	function updateSearchButton() {
		var btn = document.getElementById('ssSearchBtn');
		var ok = window.SSBuilder.hasActiveRow();
		btn.classList.toggle('disabled', !ok);
		btn.style.opacity = ok ? '' : '0.5';
		btn.setAttribute('aria-disabled', ok ? 'false' : 'true');

		// Displayed results must always reflect the criteria above (Jason
		// 08-02): any change to the EFFECTIVE query — value edited, chip or
		// row removed, NOT toggled — invalidates them. Reset to the quiet
		// prompt + clean URL until Search runs again. Changes that don't
		// alter the effective query (e.g. adding an empty row) keep them.
		if (window.SSResults.hasResults() && lastRunDsl &&
			JSON.stringify(window.SSBuilder.getDsl()) !== JSON.stringify(lastRunDsl)) {
			window.SSResults.clear();
			lastRunDsl = null;
			window.history.replaceState(null, '', window.location.pathname);
		}
	}

	// ---- boot -------------------------------------------------------------

	document.addEventListener('DOMContentLoaded', function () {

		window.SSResults.init(document.getElementById('ssResults'), {
			onStateChange: mirrorUrl,
			onBrowse: function () { runSearch({ view: 'globe', browse: true }); }
		});

		window.SSBuilder.init(document.getElementById('criteriaBuilder'), {
			onChange: updateSearchButton,
			onSearch: function () { runSearch(); }
		});

		window.SSSaved.init({
			loadIntoBuilder: function (dsl, autorun) {
				window.SSBuilder.loadDsl(dsl);
				updateSearchButton();
				if (autorun && window.SSBuilder.hasActiveRow()) runSearch();
			}
		});

		document.getElementById('ssSearchBtn')
			.addEventListener('click', function () { runSearch(); });

		// Quiet-prompt browse door (M3): the static link on first load
		// (results.js clear() rebuilds its own copy via onBrowse).
		var browseLink = document.getElementById('ssBrowseGlobe');
		if (browseLink) browseLink.addEventListener('click', function () {
			runSearch({ view: 'globe', browse: true });
		});

		var mine = document.getElementById('ssMySearchesBtn');
		if (mine) mine.addEventListener('click', window.SSSaved.openMySearches);

		var saveBtn = document.getElementById('ssSaveBtn');
		if (saveBtn) saveBtn.addEventListener('click', function () {
			window.SSSaved.openSaveCurrent(function () {
				return window.SSBuilder.getDsl();
			});
		});

		// Anonymous inline note (§6.4) — session-dismissable.
		if (!CFG.loggedIn) {
			var note = document.getElementById('ssAnonNote');
			var dismissed = false;
			try { dismissed = window.sessionStorage.getItem('ssAnonNoteDismissed') === '1'; }
			catch (e) { /* storage unavailable — show every load */ }
			if (!dismissed) note.style.display = '';
			document.getElementById('ssAnonNoteDismiss')
				.addEventListener('click', function () {
					note.style.display = 'none';
					try { window.sessionStorage.setItem('ssAnonNoteDismissed', '1'); }
					catch (e) { /* ignore */ }
				});
		}

		// Shared-URL load (§6.4): repopulate + auto-run. An empty-criteria
		// dsl with view=globe is a shared BROWSE link (M3): re-enter browse.
		var m = window.location.search.match(/[?&]q=([^&]+)/);
		if (m) {
			var st = decodeState(m[1]);
			if (st && st.dsl) {
				window.SSBuilder.loadDsl(st.dsl);
				updateSearchButton();
				if (window.SSBuilder.hasActiveRow()) {
					runSearch({ tab: st.tab || 'projects', sort: st.sort || null,
						view: st.view || 'list' });
				} else if (st.view === 'globe') {
					runSearch({ view: 'globe', browse: true });
				}
			}
		} else if (/[?&]view=globe(&|$)/.test(window.location.search)) {
			// /globe front door (M3): the redirect lands here with no ?q=.
			// Empty criteria in globe view = browse everything visible.
			runSearch({ view: 'globe', browse: true });
		}
	});

})(window, document);
