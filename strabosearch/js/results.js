/**
 * File: results.js
 * Description: StraboSearch results presentation (§6.5) — pathway tabs
 *              with hybrid counts (§6.11 Q5: both totals immediately,
 *              inactive-tab rows lazy), subsystem triptych band reusing
 *              /fullsearch's searchCountResult component, project and
 *              image hit cards, infinite scroll (auto-fetch of the next
 *              offset page when a bottom sentinel nears the viewport,
 *              with a "Fetching more results" status row), smart-default
 *              sort. §6.7 empty/error/loading states inclusive of the
 *              10s "Still searching…" escalation.
 *
 *              v1 deviations (documented in design doc as-built notes):
 *              - No keyword snippet line on project cards — the §5.4.1
 *                response carries no snippet field.
 *              - Owner names render as text (no public profile page).
 *
 * @package    StraboSpot Web Site — StraboSearch
 */

(function (window, document, $) {
	'use strict';

	var CFG = window.STRABO_SEARCH;
	var PAGE_SIZE = { projects: 25, images: 48 };

	var region = null;
	var state = null;
	// state = {
	//   baseDsl,                 criteria+subsystems (no paging keys)
	//   sort,                    user override or null (smart default)
	//   tab: 'projects'|'images',
	//   data: {projects: acc|null, images: acc|null} where acc accumulates
	//     fetched pages: {total, page_size, counterpart_total,
	//     subsystem_summary, results: [], fetchedPages, done}
	//   seen: {projects: {}, images: {}}   append dedupe (offset drift)
	//   inflight: AbortController|null, slowTimer, stillTimer,
	//   loadingMore, observer, sentinel, status
	// }
	var onStateChange = function () {};   // app.js mirrors URL

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}

	function fmtInt(n) {
		return (n === undefined || n === null) ? '0' : Number(n).toLocaleString();
	}

	function dateOnly(ts) {
		if (!ts) return null;
		var m = String(ts).match(/^(\d{4}-\d{2}-\d{2})/);
		return m ? m[1] : String(ts);
	}

	// ══════════════════════════════════════════════════════════════════
	// fetch
	// ══════════════════════════════════════════════════════════════════

	/** Site-standard "Searching. Please wait..." overlay (§6.4 addendum:
	 *  Jason 08-02 — slow NOT-queries need an unmissable working signal). */
	function showLoading(on) {
		var el = document.getElementById('loadingScreen');
		if (el) el.style.display = on ? '' : 'none';
	}

	function abortInflight() {
		if (state && state.inflight) {
			state.inflight.abort();
			state.inflight = null;
		}
		if (state) {
			clearTimeout(state.slowTimer);
			clearTimeout(state.stillTimer);
			// An aborted next-page fetch resolves via the silent AbortError
			// path, so the flag must clear here or loading wedges shut.
			state.loadingMore = false;
		}
		showLoading(false);
	}

	/** Fetch one offset page. quiet = incremental scroll fetch: no overlay,
	 *  no "Still searching…" escalation (the status row covers it). */
	function fetchPage(pathway, page, quiet, cb, errCb) {
		abortInflight();
		var ctrl = new AbortController();
		state.inflight = ctrl;
		if (!quiet) showLoading(true);

		var dsl = JSON.parse(JSON.stringify(state.baseDsl));
		dsl.pathway = pathway;
		dsl.page = page;
		dsl.page_size = PAGE_SIZE[pathway];
		if (state.sort) dsl.sort = state.sort;

		// §6.7: >10s escalates the skeleton to "Still searching…" + Cancel.
		// The overlay comes down so the Cancel control is clickable.
		if (!quiet) state.stillTimer = setTimeout(function () {
			showLoading(false);
			var content = region.querySelector('.ss-tab-content');
			if (!content) return;
			var note = el('div', 'ss-empty-state');
			note.appendChild(el('div', null, 'Still searching…'));
			var cancelBtn = el('a', 'button small', 'Cancel');
			cancelBtn.href = 'javascript:void(0);';
			cancelBtn.addEventListener('click', function () {
				abortInflight();
				content.innerHTML = '';
				content.appendChild(emptyState('Search cancelled.'));
			});
			note.appendChild(cancelBtn);
			content.innerHTML = '';
			content.appendChild(note);
		}, 10000);

		fetch(CFG.api + '?action=search', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(dsl),
			signal: ctrl.signal
		}).then(function (r) {
			if (!r.ok) {
				return r.json().catch(function () { return {}; }).then(function (j) {
					throw new Error(j.Error || ('Search failed (' + r.status + ')'));
				});
			}
			return r.json();
		}).then(function (resp) {
			clearTimeout(state.slowTimer);
			clearTimeout(state.stillTimer);
			state.inflight = null;
			showLoading(false);
			cb(resp);
		}).catch(function (e) {
			if (e.name === 'AbortError') return;
			clearTimeout(state.slowTimer);
			clearTimeout(state.stillTimer);
			state.inflight = null;
			showLoading(false);
			errCb(e);
		});
	}

	/** Merge one fetched page into the pathway accumulator. */
	function ingest(pathway, resp) {
		var d = state.data[pathway];
		if (!d) {
			d = state.data[pathway] = {
				total: resp.total,
				page_size: resp.page_size,
				counterpart_total: resp.counterpart_total,
				subsystem_summary: resp.subsystem_summary,
				results: [],
				fetchedPages: 0
			};
			state.seen[pathway] = {};
		}
		(resp.results || []).forEach(function (r) {
			// Offset pages under live data can drift a row across a page
			// boundary between fetches; dropping re-seen keys beats showing
			// a duplicate card.
			var key = (pathway === 'projects')
				? r.project_subsystem + ':' + r.project_id
				: String(r.image_hit_pkey);
			if (state.seen[pathway][key]) return;
			state.seen[pathway][key] = true;
			d.results.push(r);
		});
		d.fetchedPages++;
		d.total = resp.total;
		d.done = (resp.results || []).length < d.page_size ||
		         d.fetchedPages * d.page_size >= d.total;
	}

	function loadFirstPage(pathway) {
		state.data[pathway] = null;
		state.seen[pathway] = {};
		renderSkeletons(pathway);
		fetchPage(pathway, 0, false, function (resp) {
			ingest(pathway, resp);
			renderAll();
		}, renderError);
	}

	// ══════════════════════════════════════════════════════════════════
	// top-level render
	// ══════════════════════════════════════════════════════════════════

	/** Start a brand-new search (Search button / URL load). */
	function run(baseDsl, opts) {
		abortInflight();
		disconnectObserver();
		state = {
			baseDsl: baseDsl,
			sort: (opts && opts.sort) || null,
			tab: (opts && opts.tab) || 'projects',
			data: { projects: null, images: null },
			seen: {},
			inflight: null,
			loadingMore: false,
			observer: null,
			sentinel: null,
			status: null
		};
		renderShell();
		loadFirstPage(state.tab);
		onStateChange(getUrlState());
	}

	function renderShell() {
		region.innerHTML = '';
		var tabbar = el('div', 'ss-tabbar');
		tabbar.setAttribute('role', 'tablist');

		['projects', 'images'].forEach(function (p) {
			var tab = el('button', 'ss-tab', p === 'projects' ? 'Projects' : 'Images');
			tab.id = 'ssTab-' + p;
			tab.setAttribute('role', 'tab');
			tab.setAttribute('aria-selected', state.tab === p ? 'true' : 'false');
			tab.setAttribute('aria-controls', 'ssTabContent');
			tab.dataset.pathway = p;
			tab.addEventListener('click', function () { switchTab(p); });
			tab.addEventListener('keydown', function (ev) {
				if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
					ev.preventDefault();
					switchTab(p === 'projects' ? 'images' : 'projects', true);
				}
			});
			tabbar.appendChild(tab);
		});

		var sortBox = el('div', 'ss-sort');
		var sortLabel = el('label', null, 'Sort');
		sortLabel.htmlFor = 'ssSortSel';
		var sel = el('select');
		sel.id = 'ssSortSel';
		[['', 'Smart default'],
		 ['relevance', 'Relevance'],
		 ['modified_desc', 'Recently updated'],
		 ['name_asc', 'Project name'],
		 ['owner_asc', 'Owner']].forEach(function (o) {
			var opt = el('option', null, o[1]);
			opt.value = o[0];
			sel.appendChild(opt);
		});
		sel.value = state.sort || '';
		sel.addEventListener('change', function () {
			state.sort = sel.value || null;
			state.data = { projects: null, images: null };
			state.seen = {};
			disconnectObserver();
			loadFirstPage(state.tab);
			onStateChange(getUrlState());
		});
		sortBox.appendChild(sortLabel);
		sortBox.appendChild(sel);
		tabbar.appendChild(sortBox);

		region.appendChild(tabbar);
		var content = el('div', 'ss-tab-content');
		content.id = 'ssTabContent';
		content.setAttribute('role', 'tabpanel');
		region.appendChild(content);
	}

	function switchTab(pathway, focus) {
		if (!state || state.tab === pathway) return;
		state.tab = pathway;
		var tabs = region.querySelectorAll('.ss-tab');
		Array.prototype.forEach.call(tabs, function (t) {
			t.setAttribute('aria-selected', t.dataset.pathway === pathway ? 'true' : 'false');
			if (focus && t.dataset.pathway === pathway) t.focus();
		});
		if (state.data[pathway]) {
			renderAll();
		} else {
			loadFirstPage(pathway);
		}
		onStateChange(getUrlState());
	}

	function renderSkeletons(pathway) {
		var content = region.querySelector('.ss-tab-content');
		content.innerHTML = '';
		if (pathway === 'images') {
			var grid = el('div', 'ss-image-grid');
			for (var i = 0; i < 8; i++) grid.appendChild(el('div', 'ss-skel ss-skel-img'));
			content.appendChild(grid);
		} else {
			for (var j = 0; j < 4; j++) content.appendChild(el('div', 'ss-skel'));
		}
	}

	function renderError(e) {
		var content = region.querySelector('.ss-tab-content');
		if (!content) return;
		content.innerHTML = '';
		var card = el('div', 'ss-error-card');
		card.appendChild(el('div', null, e.message || 'Search failed.'));
		var retry = el('a', 'button small', 'Retry');
		retry.href = 'javascript:void(0);';
		retry.style.marginTop = '0.8em';
		retry.addEventListener('click', function () {
			loadFirstPage(state.tab);
		});
		card.appendChild(retry);
		content.appendChild(card);
	}

	function emptyState(msg) {
		return el('div', 'ss-empty-state', msg);
	}

	function renderAll() {
		var d = state.data[state.tab];
		if (!d) return;
		updateTabBadges();
		disconnectObserver();
		state.sentinel = null;
		state.status = null;

		var content = region.querySelector('.ss-tab-content');
		content.innerHTML = '';

		var bothEmpty = d.total === 0 && (
			(state.data.projects && state.data.projects.total === 0 &&
			 state.data.images && state.data.images.total === 0) ||
			d.counterpart_total === 0);

		if (d.total === 0) {
			if (bothEmpty) {
				content.appendChild(emptyState('Nothing matched. Try removing or broadening a constraint, and double-check vocabulary values.'));
			} else if (state.tab === 'projects') {
				content.appendChild(emptyState('No projects matched. Try removing constraints or switching to the Images tab.'));
			} else {
				content.appendChild(emptyState('No images matched. Try removing constraints or switching to the Projects tab.'));
			}
			return;
		}

		if (state.tab === 'projects') {
			renderTriptych(content, d.subsystem_summary);
			d.results.forEach(function (r) {
				content.appendChild(projectCard(r));
			});
		} else {
			var grid = el('div', 'ss-image-grid');
			d.results.forEach(function (r) {
				grid.appendChild(imageCard(r));
			});
			content.appendChild(grid);
		}
		renderInfiniteTail(content, d);
	}

	function updateTabBadges() {
		var totals = { projects: null, images: null };
		['projects', 'images'].forEach(function (p) {
			if (state.data[p]) {
				totals[p] = state.data[p].total;
				totals[p === 'projects' ? 'images' : 'projects'] =
					totals[p === 'projects' ? 'images' : 'projects'] !== null
						? totals[p === 'projects' ? 'images' : 'projects']
						: state.data[p].counterpart_total;
			}
		});
		Array.prototype.forEach.call(region.querySelectorAll('.ss-tab'), function (t) {
			var p = t.dataset.pathway;
			var n = totals[p];
			t.textContent = (p === 'projects' ? 'Projects' : 'Images') +
				(n === null ? '' : ' (' + fmtInt(n) + ')');
			t.classList.toggle('ss-dimmed', n === 0);
		});
	}

	// ══════════════════════════════════════════════════════════════════
	// triptych (§6.5.2) — searchCountResult component reused verbatim
	// ══════════════════════════════════════════════════════════════════

	var TRIPTYCH = [
		{ sub: 'field', header: 'StraboField',
			rows: [['project', 'Projects'], ['dataset', 'Datasets'], ['spot', 'Spots'], ['sample', 'Samples']] },
		{ sub: 'micro', header: 'StraboMicro',
			rows: [['project', 'Projects'], ['sample', 'Samples'], ['micrograph', 'Micrographs']] },
		{ sub: 'exp', header: 'StraboExperimental',
			rows: [['project', 'Projects'], ['experiment', 'Experiments']] }
	];

	function renderTriptych(content, summary) {
		if (!summary) return;
		var rowDiv = el('div', 'row gtr-uniform gtr-50');
		TRIPTYCH.forEach(function (t) {
			var col = el('div', 'col-4 col-12-xsmall');
			var card = el('div', 'searchCountResult');
			var h = el('h4', 'countSearchHeader');
			var icon = el('img', 'ss-triptych-icon');
			icon.src = CFG.icons[t.sub];
			icon.alt = '';
			h.appendChild(icon);
			h.appendChild(document.createTextNode(t.header));
			card.appendChild(h);
			var s = summary[t.sub] || {};
			var shown = 0;
			t.rows.forEach(function (pair) {
				var n = s[pair[0]];
				if (n === undefined) n = 0;
				card.appendChild(el('div', 'countSearchResultRow',
					fmtInt(n) + ' ' + pair[1]));
				shown++;
			});
			for (; shown < 4; shown++) {
				var filler = el('div', 'countSearchResultRow');
				filler.innerHTML = '&nbsp;';
				card.appendChild(filler);
			}
			col.appendChild(card);
			rowDiv.appendChild(col);
		});
		content.appendChild(rowDiv);
	}

	// ══════════════════════════════════════════════════════════════════
	// project hit card (§6.5.3)
	// ══════════════════════════════════════════════════════════════════

	var COUNT_LABELS = [
		['dataset', 'dataset', 'datasets'],
		['spot', 'spot', 'spots'],
		['sample', 'sample', 'samples'],
		['experiment', 'experiment', 'experiments'],
		['micrograph', 'micrograph', 'micrographs'],
		['image', 'image matched', 'images matched']
	];

	function projectCard(r) {
		var card = el('div', 'ss-card');

		var iconBox = el('div', 'ss-card-icon');
		var icon = el('img');
		icon.src = CFG.icons[r.project_subsystem] || CFG.icons.field;
		icon.alt = r.project_subsystem;
		iconBox.appendChild(icon);
		card.appendChild(iconBox);

		var body = el('div', 'ss-card-body');

		var title = el('div', 'ss-card-title');
		var link = el('a', null, r.project_name || '(unnamed project)');
		// Field: single-dataset projects deep-link straight to the modern
		// dataset viewer; multi-dataset go through /fpl/ (the named dataset
		// chooser, whose own links now target the same viewer).
		if (r.project_subsystem === 'field' && r.dataset_ids && r.dataset_ids.length === 1) {
			link.href = CFG.fieldDataset + encodeURIComponent(r.dataset_ids[0]);
		} else {
			link.href = (CFG.landing[r.project_subsystem] || CFG.landing.field) +
				encodeURIComponent(r.project_id);
		}
		link.target = '_blank';
		title.appendChild(link);
		if (r.project_ispublic) {
			title.appendChild(el('span', 'ss-badge-public', 'Public'));
		}
		body.appendChild(title);

		var metaBits = [];
		if (r.owner_name) metaBits.push('Owner: ' + r.owner_name);
		var mod = dateOnly(r.last_modified);
		if (mod) metaBits.push('Updated ' + mod);
		// Data date range (labeled, Jason 08-04). Micro/samples extractors
		// derive date_value FROM the modified timestamp (no observation
		// date exists there), so a single-day range equal to "Updated" is
		// pure duplication — suppress it. Real ranges (Field observation
		// dates) still render.
		var d0 = r.date_range && dateOnly(r.date_range[0]);
		var d1 = r.date_range && dateOnly(r.date_range[1]);
		if (d0 && d1 && !(d0 === d1 && d0 === mod)) {
			metaBits.push('Data: ' + (d0 === d1 ? d0 : d0 + ' – ' + d1));
		}
		body.appendChild(el('div', 'ss-card-meta', metaBits.join('  ·  ')));

		var counts = [];
		COUNT_LABELS.forEach(function (t) {
			var n = r.match_counts ? r.match_counts[t[0]] : 0;
			if (n > 0) counts.push(fmtInt(n) + ' ' + (n === 1 ? t[1] : t[2]));
		});
		if (counts.length) {
			var line = counts.join(' · ');
			if (!/matched$/.test(line)) line += ' matched';
			body.appendChild(el('div', 'ss-card-counts', line));
		}

		card.appendChild(body);
		return card;
	}

	// ══════════════════════════════════════════════════════════════════
	// image hit card (§6.5.4)
	// ══════════════════════════════════════════════════════════════════

	function imageCard(r) {
		var card = el('div', 'ss-imgcard');

		var thumbBox = el('a', 'ss-thumbbox');
		thumbBox.href = 'javascript:void(0);';
		thumbBox.setAttribute('aria-label', 'View image');
		var img = el('img');
		img.loading = 'lazy';
		img.src = CFG.thumb + '?id=' + r.image_hit_pkey + '&size=300';
		img.alt = r.title || r.filename || 'image';
		thumbBox.appendChild(img);
		thumbBox.addEventListener('click', function () {
			var big = CFG.thumb + '?id=' + r.image_hit_pkey + '&size=1600';
			if ($ && $.featherlight) {
				$.featherlight(big, { type: 'image' });
			} else {
				window.open(big, '_blank');
			}
		});
		card.appendChild(thumbBox);

		var body = el('div', 'ss-imgcard-body');
		body.appendChild(el('div', 'ss-imgcard-title',
			r.title || r.filename || 'Untitled image'));

		var tags = el('div', 'ss-imgcard-tags');
		if (r.image_type) tags.appendChild(document.createTextNode(r.image_type + ' '));
		if (r.annotated === true) tags.appendChild(el('span', 'ss-badge-annotated', 'annotated'));
		if (tags.childNodes.length) body.appendChild(tags);

		if (r.project_name) {
			var parent = el('div', 'ss-imgcard-parent');
			parent.appendChild(document.createTextNode('in: '));
			var pl = el('a', null, r.project_name);
			pl.href = (CFG.landing[r.project_subsystem] || CFG.landing.field) +
				encodeURIComponent(r.project_id);
			pl.target = '_blank';
			parent.appendChild(pl);
			body.appendChild(parent);
		}

		card.appendChild(body);
		return card;
	}

	// ══════════════════════════════════════════════════════════════════
	// infinite scroll (§6.5.5, amended 2026-08-12: auto-pagination
	// replaced the numbered offset pager at the higher-ups' request)
	// ══════════════════════════════════════════════════════════════════

	function disconnectObserver() {
		if (state && state.observer) {
			state.observer.disconnect();
			state.observer = null;
		}
	}

	function setFetchStatus(msg, spin) {
		if (!state.status) return;
		state.status.innerHTML = '';
		if (spin) state.status.appendChild(el('span', 'ss-spinner'));
		if (msg) state.status.appendChild(document.createTextNode(msg));
	}

	function endNote(d) {
		return el('div', 'ss-end-note',
			'End of results · ' + fmtInt(d.total) + ' total');
	}

	/** Sentinel + live status row after the last rendered card. */
	function renderInfiniteTail(content, d) {
		if (d.done) {
			// A single-page result set needs no "end" marker.
			if (d.total > d.page_size) content.appendChild(endNote(d));
			return;
		}
		var sentinel = el('div', 'ss-scroll-sentinel');
		sentinel.setAttribute('aria-hidden', 'true');
		var status = el('div', 'ss-fetch-status');
		status.setAttribute('role', 'status');
		content.appendChild(sentinel);
		content.appendChild(status);
		state.sentinel = sentinel;
		state.status = status;
		armObserver();
	}

	function armObserver() {
		disconnectObserver();
		if (!state.sentinel) return;
		if (!('IntersectionObserver' in window)) {
			// Ancient-browser fallback: manual load.
			setFetchStatus('', false);
			var more = el('a', 'button small', 'Load more');
			more.href = 'javascript:void(0);';
			more.addEventListener('click', loadNext);
			state.status.appendChild(more);
			return;
		}
		// rootMargin prefetches ~a viewport ahead so the status row is
		// rarely seen; observe() fires immediately when the sentinel is
		// already inside the margin, which chains short pages until the
		// sentinel escapes the viewport.
		state.observer = new IntersectionObserver(function (entries) {
			for (var i = 0; i < entries.length; i++) {
				if (entries[i].isIntersecting) { loadNext(); break; }
			}
		}, { rootMargin: '600px 0px' });
		state.observer.observe(state.sentinel);
	}

	function loadNext() {
		var pathway = state.tab;
		var d = state.data[pathway];
		if (!d || d.done || state.loadingMore || state.inflight) return;
		state.loadingMore = true;
		setFetchStatus('Fetching more results…', true);

		fetchPage(pathway, d.fetchedPages, true, function (resp) {
			state.loadingMore = false;
			var before = d.results.length;
			ingest(pathway, resp);
			if (state.tab !== pathway) return;   // tab switched mid-fetch
			appendCards(pathway, d.results.slice(before));
			updateTabBadges();
			setFetchStatus('', false);
			if (d.done) {
				disconnectObserver();
				var content = region.querySelector('.ss-tab-content');
				state.sentinel.remove();
				state.status.remove();
				state.sentinel = null;
				state.status = null;
				if (content && d.total > d.page_size) content.appendChild(endNote(d));
			} else {
				armObserver();
			}
		}, function (e) {
			state.loadingMore = false;
			if (state.tab !== pathway || !state.status) return;
			setFetchStatus((e.message || 'Fetching more results failed.') + '  ', false);
			var retry = el('a', 'button small', 'Retry');
			retry.href = 'javascript:void(0);';
			retry.addEventListener('click', loadNext);
			state.status.appendChild(retry);
		});
	}

	function appendCards(pathway, newResults) {
		var content = region.querySelector('.ss-tab-content');
		if (!content) return;
		if (pathway === 'images') {
			var grid = content.querySelector('.ss-image-grid');
			newResults.forEach(function (r) { grid.appendChild(imageCard(r)); });
		} else {
			newResults.forEach(function (r) {
				content.insertBefore(projectCard(r), state.sentinel);
			});
		}
	}

	// ══════════════════════════════════════════════════════════════════
	// public API
	// ══════════════════════════════════════════════════════════════════

	function getUrlState() {
		if (!state) return null;
		return {
			tab: state.tab,
			sort: state.sort
		};
	}

	/** Reset to the pre-search quiet prompt (criteria emptied — Jason 08-02:
	 *  stale results with no criteria left are misleading). */
	function clear() {
		abortInflight();
		disconnectObserver();
		state = null;
		region.innerHTML = '';
		region.appendChild(el('div', 'ss-quiet-prompt',
			'Compose a search above to see results.'));
	}

	window.SSResults = {
		init: function (elRegion, opts) {
			region = elRegion;
			onStateChange = (opts && opts.onStateChange) || function () {};
		},
		run: run,
		clear: clear,
		hasResults: function () { return state !== null; },
		abort: abortInflight
	};

})(window, document, window.jQuery);
