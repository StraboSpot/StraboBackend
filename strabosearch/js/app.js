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
		// The default catalog (no criteria, list, Projects, default sort)
		// is what a bare URL already shows: keep that URL clean. Anything
		// else (a globe browse, a sorted or Images catalog, any search)
		// mirrors so the state can be shared.
		if (isCatalogDefault(lastRunDsl, urlState)) {
			window.history.replaceState(null, '', window.location.pathname);
			return;
		}
		var payload = {
			dsl: lastRunDsl,
			tab: urlState ? urlState.tab : 'projects',
			sort: urlState ? urlState.sort : null,
			view: urlState ? urlState.view : 'list'
		};
		var qs = '?q=' + encodeState(payload);
		window.history.replaceState(null, '', window.location.pathname + qs);
	}

	function isCatalogDefault(dsl, urlState) {
		return !!dsl && (!dsl.criteria || dsl.criteria.length === 0)
			&& (!urlState || ((urlState.view || 'list') === 'list'
				&& (urlState.tab || 'projects') === 'projects' && !urlState.sort));
	}

	// ---- search execution -------------------------------------------------

	/**
	 * Run the builder's current DSL. Empty criteria are a browse of the
	 * whole visible catalog (globe-only from M3; list + images too since
	 * 2026-09-14, Claire), so there is no active-row gate any more: the
	 * Search button always does something. opts.browse is kept for the
	 * callers that name the intent (the /globe door, shared browse links).
	 */
	function runSearch(opts) {
		lastRunDsl = window.SSBuilder.getDsl();
		window.SSResults.run(lastRunDsl, opts || {});
		updateExportButton();
		// mobile: reveal the results (no-op on desktop). Not for the
		// automatic catalog re-runs: a user emptying a keyword inside the
		// drawer must not have the drawer close under their thumb.
		if (!(opts && opts.auto)) closeDrawer();
	}

	/** The page's resting state: every project the visitor can see, newest
	 *  first, in the list view (Claire 2026-09-14, Jason: "default to list
	 *  view with the first page of results showing"). */
	function runCatalog(opts) {
		runSearch(Object.assign({ view: 'list', browse: true }, opts || {}));
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
		updateFiltersBadge();

		// Displayed results must always reflect the criteria above (Jason
		// 08-02): any change to the EFFECTIVE query — value edited, chip or
		// row removed, NOT toggled — invalidates them. Changes that don't
		// alter the effective query (e.g. adding an empty row) keep them.
		// Since 2026-09-14 the page rests on the full catalog, so criteria
		// emptied back out re-run that catalog (in whatever view is up);
		// any other change drops to the "press Search" prompt + clean URL.
		var now = window.SSBuilder.getDsl();
		var empty = !now.criteria || now.criteria.length === 0;
		if (!booted) {
			// Module init fires onChange before the boot code decides what
			// to run first (shared URL, /globe door, or the catalog).
		} else if (window.SSResults.hasResults() && lastRunDsl &&
			JSON.stringify(now) !== JSON.stringify(lastRunDsl)) {
			if (empty) {
				runSearch({ browse: true, auto: true });
			} else {
				window.SSResults.clear();
				lastRunDsl = null;
				window.history.replaceState(null, '', window.location.pathname);
			}
		} else if (!window.SSResults.hasResults() && empty && !lastRunDsl) {
			// Prompt showing, criteria now empty: back to the catalog.
			runCatalog({ auto: true });
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
	 * Rendered for everyone (Jason 2026-09-02: anonymous visitors must be
	 * able to see that exports exist). Signed in: click = POST the last-run
	 * DSL to the builder in a new tab; the builder opens in its search-door
	 * mode: only the projects (own, collaborated, public) with matching
	 * spots, preselected, and the carried-over filters shown read-only.
	 * Anonymous: the same gates, tooltip "Sign in to export…", and the
	 * click goes to /login.php?uri=<this search's URL> so sign-in (or
	 * account creation) returns to the same results, where one more click
	 * exports (the builder opens in a new tab, which needs a user gesture).
	 */
	var EXPORT_TITLE_READY = 'Open the Export Builder with the StraboField projects from these results preselected and these filters applied (exports cover StraboField projects for now)';
	var EXPORT_TITLE_SIGNIN = 'Sign in to export the StraboField projects from these results (exports cover StraboField projects for now)';
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
		btn.title = (ran && !hasCriteria) ? EXPORT_TITLE_BROWSE : (CFG.loggedIn ? EXPORT_TITLE_READY : EXPORT_TITLE_SIGNIN);
	}
	function openExportBuilder() {
		if (!exportableDsl(lastRunDsl) || !(window.SSResults.fieldProjectCount() > 0)) return;
		if (!CFG.loggedIn) {
			// The address bar already mirrors this search (?q=), so the login
			// round trip lands back on these results.
			window.location.href = '/login.php?uri=' + encodeURIComponent(window.location.pathname + window.location.search);
			return;
		}
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

	var booted = false;   // updateSearchButton's automatic runs wait for boot

	document.addEventListener('DOMContentLoaded', function () {

		window.SSResults.init(document.getElementById('ssResults'), {
			onStateChange: mirrorUrl
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
		// dsl is a shared BROWSE link (globe, or a sorted / Images catalog).
		var m = window.location.search.match(/[?&]q=([^&]+)/);
		var ran = false;
		if (m) {
			var st = decodeState(m[1]);
			if (st && st.dsl) {
				window.SSBuilder.loadDsl(st.dsl);
				runSearch({ tab: st.tab || 'projects', sort: st.sort || null,
					view: st.view || 'list', browse: !window.SSBuilder.hasActiveRow() });
				ran = true;
			}
		} else if (/[?&]view=globe(&|$)/.test(window.location.search)) {
			// /globe front door (M3): the redirect lands here with no ?q=.
			// Empty criteria in globe view = browse everything visible.
			runSearch({ view: 'globe', browse: true });
			ran = true;
		}

		// Plain load (Claire 2026-09-14, option B): open on the catalog,
		// every project the visitor can see newest first, so the page is
		// never empty and the Projects / Images tabs + List | Globe toggle
		// are live from the start. On phones the results pane is what
		// shows; the Filters pill opens the criteria drawer (M4).
		booted = true;
		if (!ran) runCatalog();
	});

})(window, document);
