/**
 * File: exportjobs/js/export_builder.js
 * Description: Export Builder page behavior (docs/ExportBuilder_Design.md
 *              §9.1). Renders the project/dataset picker from the embedded
 *              window.EXPORT_BUILDER payload, hosts the StraboSearch
 *              criteria builder (SSBuilder, trimmed to Field criteria),
 *              keeps the live "N spots match" count fresh (debounced POST
 *              ?action=count), and submits the recipe (POST ?action=create)
 *              then lands on My Exports.
 *
 *              Site CSS only styles checkboxes/radios rendered as
 *              input + ADJACENT label[for]; every control here follows that.
 *
 * @package    StraboSpot Web Site
 */
(function (window, document) {
	'use strict';
	var CFG = window.EXPORT_BUILDER;
	var BUILD = 'export-builder-m5-r1';
	if (window.console && console.log) console.log('[ExportBuilder] ' + BUILD);

	// ---------------------------------------------------------------- state
	var sel = {};          // "pid|owner" -> { whole: bool, ds: {id: true} }
	var byKey = {};        // "pid|owner" -> project entry
	CFG.projects.forEach(function (p) { byKey[p.id + '|' + p.owner] = p; });

	function $(id) { return document.getElementById(id); }
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined && text !== null) e.textContent = text;
		return e;
	}
	function fmtN(n) { return Number(n).toLocaleString(); }

	// ---------------------------------------------------------------- picker
	function renderProjects() {
		var box = $('eb-projects');
		box.innerHTML = '';
		if (!CFG.projects.length) {
			box.appendChild(el('div', 'eb-empty', 'You have no StraboField projects yet, and no collaborations. Upload a project from the StraboField app first.'));
			return;
		}
		CFG.projects.forEach(function (p) {
			var key = p.id + '|' + p.owner;
			var wrap = el('div', 'eb-proj');
			wrap.setAttribute('data-key', key);
			wrap.setAttribute('data-name', p.name.toLowerCase());
			var row = el('div', 'eb-proj-row');
			var cb = document.createElement('input');
			cb.type = 'checkbox'; cb.id = 'eb-p-' + p.id + '-' + p.owner; cb.className = 'eb-pcb';
			var lab = el('label', null, p.name);
			lab.htmlFor = cb.id;
			var small = el('small', null, fmtN(p.spots) + ' spot' + (p.spots === 1 ? '' : 's') + ' in ' + p.datasets.length + ' dataset' + (p.datasets.length === 1 ? '' : 's'));
			lab.appendChild(small);
			var partial = el('small', 'eb-partial', '');
			lab.appendChild(partial);
			row.appendChild(cb); row.appendChild(lab);
			var acc = el('span', 'eb-access', p.access === 'owner' ? 'own' : 'shared by ' + p.owner_name);
			row.appendChild(acc);
			var tog = el('button', 'eb-toggle', 'datasets');
			tog.type = 'button'; tog.setAttribute('aria-expanded', 'false');
			row.appendChild(tog);
			wrap.appendChild(row);
			var dsBox = el('div', 'eb-datasets');
			p.datasets.forEach(function (d) {
				var dr = el('div', 'eb-ds');
				var dcb = document.createElement('input');
				dcb.type = 'checkbox'; dcb.id = 'eb-d-' + p.id + '-' + p.owner + '-' + d.id; dcb.className = 'eb-dcb';
				dcb.setAttribute('data-ds', d.id);
				var dl = el('label', null, d.name);
				dl.htmlFor = dcb.id;
				dl.appendChild(el('small', null, fmtN(d.spots) + ' spot' + (d.spots === 1 ? '' : 's')));
				dr.appendChild(dcb); dr.appendChild(dl);
				dsBox.appendChild(dr);
				dcb.addEventListener('change', function () { onDataset(key, d.id, dcb.checked); });
			});
			if (!p.datasets.length) dsBox.appendChild(el('div', 'eb-note', 'No datasets.'));
			wrap.appendChild(dsBox);
			cb.addEventListener('change', function () { onProject(key, cb.checked); });
			tog.addEventListener('click', function () {
				var open = wrap.classList.toggle('eb-open');
				tog.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
			box.appendChild(wrap);
		});
	}

	function onProject(key, checked) {
		var p = byKey[key];
		if (checked) {
			sel[key] = { whole: true, ds: {} };
			p.datasets.forEach(function (d) { sel[key].ds[d.id] = true; });
		} else {
			delete sel[key];
		}
		syncProject(key);
		changed();
	}
	function onDataset(key, dsId, checked) {
		var p = byKey[key];
		if (!sel[key]) sel[key] = { whole: false, ds: {} };
		if (checked) sel[key].ds[dsId] = true; else delete sel[key].ds[dsId];
		var n = Object.keys(sel[key].ds).length;
		sel[key].whole = (n === p.datasets.length && n > 0);
		if (n === 0) delete sel[key];
		syncProject(key);
		changed();
	}
	function syncProject(key) {
		var p = byKey[key];
		var wrap = document.querySelector('.eb-proj[data-key="' + key + '"]');
		if (!wrap) return;
		var s = sel[key];
		var pcb = wrap.querySelector('.eb-pcb');
		pcb.checked = !!(s && s.whole);
		var n = s ? Object.keys(s.ds).length : 0;
		wrap.querySelector('.eb-partial').textContent = (s && !s.whole) ? ' (' + n + ' of ' + p.datasets.length + ' datasets)' : '';
		Array.prototype.forEach.call(wrap.querySelectorAll('.eb-dcb'), function (dcb) {
			dcb.checked = !!(s && s.ds[dcb.getAttribute('data-ds')]);
		});
	}
	function clearSelection() {
		Object.keys(sel).forEach(function (k) { delete sel[k]; syncProject(k); });
		changed();
	}
	function filterProjects(q) {
		q = (q || '').trim().toLowerCase();
		Array.prototype.forEach.call(document.querySelectorAll('.eb-proj'), function (w) {
			w.classList.toggle('eb-hidden', q !== '' && w.getAttribute('data-name').indexOf(q) === -1);
		});
	}

	// ---------------------------------------------------------------- recipe
	function scope() {
		var out = { projects: [], datasets: [] };
		Object.keys(sel).forEach(function (k) {
			var p = byKey[k], s = sel[k];
			out.projects.push({ id: p.id, owner: p.owner });
			if (!s.whole) Object.keys(s.ds).forEach(function (d) { out.datasets.push({ id: d, project_id: p.id, owner: p.owner }); });
		});
		return out;
	}
	function checkedValues(cls) {
		return Array.prototype.map.call(document.querySelectorAll('.' + cls + ':checked'), function (c) { return c.value; });
	}
	function recipe() {
		var dsl = window.SSBuilder ? window.SSBuilder.getDsl() : { criteria: [] };
		var layoutEl = document.querySelector('input[name="eb-layout"]:checked');
		return {
			scope: scope(),
			criteria: dsl.criteria || [],
			children: $('eb-children').checked ? 'matched_parents' : 'none',
			formats: checkedValues('eb-fmt'),
			layout: layoutEl ? layoutEl.value : 'merged',
			extras: $('eb-extras-block').classList.contains('eb-hidden') ? [] : checkedValues('eb-extra'),
			sample_list_csv: $('eb-sample-csv').checked,
			notes: $('eb-notes').value
		};
	}
	function summaryHint() {
		var names = Object.keys(sel).map(function (k) { return byKey[k].name; });
		if (!names.length) return '';
		var s = names.slice(0, 3).join(', ');
		if (names.length > 3) s += ' +' + (names.length - 3) + ' more';
		return s;
	}

	// ---------------------------------------------------------------- output gating
	function syncOutputBlocks() {
		var keys = Object.keys(sel);
		var multi = keys.length > 1;
		var anyWhole = false;
		keys.forEach(function (k) {
			var s = sel[k];
			if (s.whole) anyWhole = true;
			if (Object.keys(s.ds).length > 1) multi = true;
		});
		$('eb-layout-block').classList.toggle('eb-hidden', !multi);
		if (!multi) $('eb-layout-merged').checked = true;
		$('eb-extras-block').classList.toggle('eb-hidden', !anyWhole);
		$('eb-csv-wrap').classList.toggle('eb-hidden', !$('eb-fmt-sample_list').checked);
	}

	// ---------------------------------------------------------------- live count
	var countTimer = null, countSeq = 0, lastCount = null;
	function changed() {
		syncOutputBlocks();
		scheduleCount();
		syncBuild();
	}
	function scheduleCount() {
		if (countTimer) clearTimeout(countTimer);
		countTimer = setTimeout(runCount, 400);
	}
	function setCount(html, cls) {
		var c = $('eb-count');
		c.className = 'eb-count' + (cls ? ' ' + cls : '');
		c.innerHTML = html;
	}
	function runCount() {
		countTimer = null;
		var r = recipe();
		if (!r.scope.projects.length) { lastCount = null; setCount('Select a project to begin.'); syncBuild(); return; }
		var seq = ++countSeq;
		setCount('Counting…');
		fetch(CFG.api + '?action=count', { method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ recipe: r }) })
			.then(function (res) { return res.json().then(function (j) { j._status = res.status; return j; }); })
			.then(function (j) {
				if (seq !== countSeq) return;
				if (!j.ok) { lastCount = null; setCount(escapeHtml(j.error || 'Count failed.'), 'eb-err'); syncBuild(); return; }
				lastCount = j;
				var n = fmtN(j.count);
				var html = (j.approximate ? 'About ' : '') + '<strong>' + n + '</strong> spot' + (j.count === 1 ? '' : 's') + ' match';
				if (j.approximate) html += '<span class="eb-approx">area filter applied to full geometries at build time</span>';
				if (j.over_max) html += '<span class="eb-approx">over the ' + fmtN(j.max_items) + ' spot limit: narrow the selection</span>';
				setCount(html, j.over_max ? 'eb-warn' : '');
				$('eb-drift').textContent = j.used_index
					? 'Filtered selections are evaluated against the StraboSpot search index, which is updated as data is uploaded.'
					: '';
				syncBuild();
			})
			.catch(function () { if (seq === countSeq) { lastCount = null; setCount('Count failed (network).', 'eb-err'); syncBuild(); } });
	}
	function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

	// ---------------------------------------------------------------- build
	var building = false;
	function canBuild() {
		var r = recipe();
		if (!r.scope.projects.length) return 'Select at least one project or dataset.';
		if (!r.formats.length && !r.extras.length) return 'Choose at least one output format.';
		if (lastCount && lastCount.over_max) return 'Too many spots: narrow the selection.';
		if (lastCount && lastCount.count === 0 && !r.extras.length) return 'No spots match this selection.';
		return null;
	}
	function syncBuild() {
		var why = canBuild();
		$('eb-build').classList.toggle('eb-disabled', !!why || building);
		$('eb-build').setAttribute('aria-disabled', (why || building) ? 'true' : 'false');
		$('eb-msg').textContent = '';
	}
	function build() {
		var why = canBuild();
		if (why) { $('eb-msg').textContent = why; return; }
		if (building) return;
		building = true; syncBuild();
		$('eb-build').textContent = 'Submitting…';
		fetch(CFG.api + '?action=create', { method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ recipe: recipe(), email_on_done: $('eb-email').checked, summary: summaryHint(), origin: 'builder' }) })
			.then(function (res) { return res.json(); })
			.then(function (j) {
				if (!j.ok) { building = false; $('eb-build').textContent = 'Build export'; syncBuild(); $('eb-msg').textContent = j.error || 'Could not start the export.'; return; }
				window.location.href = '/my_exports?new=' + encodeURIComponent(j.uuid);
			})
			.catch(function () { building = false; $('eb-build').textContent = 'Build export'; syncBuild(); $('eb-msg').textContent = 'Network error. Please try again.'; });
	}

	// ---------------------------------------------------------------- criteria builder (Field-only catalog)
	function initCriteria() {
		var C = window.SSCatalog;
		if (!C || !window.SSBuilder) return;
		var keep = C.CRITERIA.filter(function (c) {
			if (c.group === 'Field') return true;
			if (c.group === 'Universal') return c.id !== 'U4' && c.id !== 'U8';
			return false;
		});
		C.CRITERIA.length = 0;
		keep.forEach(function (c) { C.CRITERIA.push(c); });   // same array instance the builder holds
		window.SSBuilder.init($('criteriaBuilder'), { onChange: changed, onSearch: function () {} });
	}

	// ---------------------------------------------------------------- initial state (doors)
	function applyInitial() {
		var init = CFG.initial, pre = CFG.preselect, missing = [];
		if (init && init.scope) {
			(init.scope.projects || []).forEach(function (p) {
				var key = String(p.id) + '|' + p.owner;
				if (!byKey[key]) { missing.push(String(p.id)); return; }
				var named = (init.scope.datasets || []).filter(function (d) { return String(d.project_id) === String(p.id) && Number(d.owner) === Number(p.owner); });
				if (!named.length) { sel[key] = { whole: true, ds: {} }; byKey[key].datasets.forEach(function (d) { sel[key].ds[d.id] = true; }); }
				else {
					sel[key] = { whole: false, ds: {} };
					named.forEach(function (d) { sel[key].ds[String(d.id)] = true; });
					sel[key].whole = Object.keys(sel[key].ds).length === byKey[key].datasets.length;
				}
				syncProject(key);
				var wrap = document.querySelector('.eb-proj[data-key="' + key + '"]');
				if (wrap && !sel[key].whole) wrap.classList.add('eb-open');
			});
			(init.formats || []).forEach(function (f) { var c = $('eb-fmt-' + f); if (c) c.checked = true; });
			(init.extras || []).forEach(function (x) { var c = $('eb-extra-' + x); if (c) c.checked = true; });
			var lay = $('eb-layout-' + ({ merged: 'merged', split_project: 'project', split_dataset: 'dataset' }[init.layout] || 'merged'));
			if (lay) lay.checked = true;
			$('eb-sample-csv').checked = !!init.sample_list_csv;
			$('eb-children').checked = init.children !== 'none';
			$('eb-notes').value = init.notes || '';
			if (window.SSBuilder && init.criteria && init.criteria.length) window.SSBuilder.loadDsl({ criteria: init.criteria });
			if (missing.length) $('eb-msg').textContent = 'Some projects of the earlier export are no longer available to you: ' + missing.join(', ');
		} else if (pre) {
			var key = pre.project_id + '|' + pre.owner;
			if (byKey[key]) {
				if (pre.dataset_id) { sel[key] = { whole: false, ds: {} }; sel[key].ds[pre.dataset_id] = true; sel[key].whole = byKey[key].datasets.length === 1; }
				else { sel[key] = { whole: true, ds: {} }; byKey[key].datasets.forEach(function (d) { sel[key].ds[d.id] = true; }); }
				syncProject(key);
				var w = document.querySelector('.eb-proj[data-key="' + key + '"]');
				if (w) { w.classList.add('eb-open'); w.scrollIntoView({ block: 'nearest' }); }
			}
		}
		if (!init) $('eb-fmt-geojson').checked = true;   // a sensible default for a fresh page
	}

	// ---------------------------------------------------------------- wire up
	renderProjects();
	initCriteria();
	applyInitial();
	$('eb-proj-search').addEventListener('input', function () { filterProjects(this.value); });
	$('eb-select-none').addEventListener('click', clearSelection);
	Array.prototype.forEach.call(document.querySelectorAll('.eb-fmt, .eb-extra, input[name="eb-layout"], #eb-sample-csv, #eb-children'), function (c) {
		c.addEventListener('change', changed);
	});
	$('eb-build').addEventListener('click', build);
	changed();

	window.ExportBuilderPage = { recipe: recipe, BUILD: BUILD };
})(window, document);
