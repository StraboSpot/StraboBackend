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
		updateExportButton();   // results state changed (first page landed, view flip): re-evaluate the Export… gate
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
		updateExportButton();
		closeDrawer();   // mobile: reveal the results (no-op on desktop)
	}

	// ---- mobile criteria drawer (Globe View M4) ---------------------------
	// Below 1024px the rail is an off-canvas drawer (search.css). The
	// frame class is the single source of truth; on desktop the class is
	// inert, so every call here is safe at any width.

	var mobileMq = window.matchMedia ? window.matchMedia('(max-width: 1023px)') : null;
	function isMobile() { return !!(mobileMq && mobileMq.matches); }

	function drawerOpen() {
		return document.getElementById('ssAppFrame').classList.contains('ss-drawer-open');
	}

	function openDrawer() {
		if (!isMobile()) return;
		var frame = document.getElementById('ssAppFrame');
		frame.classList.add('ss-drawer-open');
		document.getElementById('ssFiltersBtn').setAttribute('aria-expanded', 'true');
		updateDrawerCloseLabel();
		var first = frame.querySelector('.ss-rail select, .ss-rail input');
		if (first) { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }
	}

	function closeDrawer() {
		var frame = document.getElementById('ssAppFrame');
		if (!frame.classList.contains('ss-drawer-open')) return;
		frame.classList.remove('ss-drawer-open');
		var pill = document.getElementById('ssFiltersBtn');
		pill.setAttribute('aria-expanded', 'false');
		if (isMobile() && frame.contains(document.activeElement)) pill.focus();
	}

	/** "Close" / "Close and return to globe|list" per the results state. */
	function updateDrawerCloseLabel() {
		var link = document.getElementById('ssDrawerCloseLink');
		var v = window.SSResults.getView();
		link.textContent = v ? 'Close and return to ' + v : 'Close';
	}

	/** Filters pill badge = active criteria rows (hidden at zero). */
	function updateFiltersBadge() {
		var badge = document.getElementById('ssFiltersBadge');
		var n = window.SSBuilder.activeRowCount();
		badge.textContent = String(n);
		badge.style.display = n > 0 ? '' : 'none';
	}

	function updateSearchButton() {
		var btn = document.getElementById('ssSearchBtn');
		var ok = window.SSBuilder.hasActiveRow();
		btn.classList.toggle('disabled', !ok);
		btn.style.opacity = ok ? '' : '0.5';
		btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
		updateFiltersBadge();

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
		updateExportButton();
	}

	/**
	 * Export… (Export Builder door, 2026-09-01): live only while results
	 * on screen reflect the criteria above (same invalidation rule as the
	 * results themselves), at least one criteria row ran, AND the results
	 * hold at least one StraboField project (exports cover Field only for
	 * now). Three states (Jason 2026-09-02):
	 *   - no run yet / results invalidated: disabled, ready tooltip;
	 *   - globe browse run (empty DSL, the whole visible corpus, not an
	 *     export scope): disabled, tooltip says to add a filter;
	 *   - results landed with ZERO Field projects (Micro/Exp-only search,
	 *     or Field excluded by the subsystem row): the button is HIDDEN,
	 *     there is nothing it could export;
	 *   - results still loading: disabled until the first page lands
	 *     (results.js calls back through onStateChange).
	 * Logged-in only (the anchor is not rendered otherwise). Click = POST
	 * the last-run DSL to the builder in a new tab; the builder opens in
	 * its search-door mode: only the projects (own, collaborated, public)
	 * with matching spots, preselected, and the carried-over filters shown
	 * read-only.
	 */
	var EXPORT_TITLE_READY = 'Open the Export Builder with the StraboField projects from these results preselected and these filters applied (exports cover StraboField projects for now)';
	var EXPORT_TITLE_BROWSE = 'Add at least one search filter and run the search, then export the matching projects';
	function exportableDsl(dsl) {
		return !!(dsl && dsl.criteria && dsl.criteria.length > 0);
	}
	function updateExportButton() {
		var btn = document.getElementById('ssExportBtn');
		if (!btn) return;
		var ran = !!lastRunDsl;
		var hasCriteria = exportableDsl(lastRunDsl);
		var fieldN = (ran && hasCriteria) ? window.SSResults.fieldProjectCount() : null;   // null = unknown yet
		var hide = ran && hasCriteria && fieldN === 0;
		var ok = ran && hasCriteria && fieldN !== null && fieldN > 0;
		btn.style.display = hide ? 'none' : '';
		btn.classList.toggle('disabled', !ok);
		btn.style.opacity = ok ? '' : '0.5';
		btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
		btn.title = (ran && !hasCriteria) ? EXPORT_TITLE_BROWSE : EXPORT_TITLE_READY;
	}
	function openExportBuilder() {
		if (!exportableDsl(lastRunDsl) || !(window.SSResults.fieldProjectCount() > 0)) return;
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = CFG.exportBuilder || '/export_builder';
		form.target = '_blank';
		form.style.display = 'none';
		var f = document.createElement('input');
		f.type = 'hidden';
		f.name = 'search_dsl';
		f.value = JSON.stringify(lastRunDsl);
		form.appendChild(f);
		document.body.appendChild(form);
		form.submit();
		form.parentNode.removeChild(form);
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
		var exportBtn = document.getElementById('ssExportBtn');
		if (exportBtn) exportBtn.addEventListener('click', openExportBuilder);

		// Mobile drawer wiring (M4): pill opens, X / footer link / backdrop /
		// Escape close. Results-invalidating edits (updateSearchButton's
		// clear) keep the drawer where it is: the user is mid-edit.
		document.getElementById('ssFiltersBtn').addEventListener('click', function () {
			if (drawerOpen()) closeDrawer(); else openDrawer();
		});
		document.getElementById('ssDrawerClose').addEventListener('click', closeDrawer);
		document.getElementById('ssDrawerCloseLink').addEventListener('click', closeDrawer);
		document.getElementById('ssDrawerBackdrop').addEventListener('click', closeDrawer);
		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && drawerOpen()) { ev.preventDefault(); closeDrawer(); }
		});

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

		// Mobile first load with nothing to show (M4): open the drawer so
		// the criteria builder is the first thing on screen, not a quiet
		// prompt behind a pill. Shared links / the browse door land on
		// their results instead.
		if (!window.SSResults.hasResults()) openDrawer();
	});

})(window, document);
