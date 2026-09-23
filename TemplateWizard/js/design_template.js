/**
 * Template Wizard - Template Designer (column list builder, 2026-09-18).
 *
 * State is one ordered array of column descriptors (window.twDesigner.columns
 * on load). The page renders three views of it: the editable list, the
 * catalog (marking what is already in), and the read-only sheet preview.
 * Rules kept in normalize(): strabo_internal_id is always first and locked;
 * orientation_type exists exactly when an orientation field does and sits
 * just before the first one. Save posts the spec to ajax.php; Download
 * blank saves first, then streams the workbook from export.php.
 */

document.addEventListener('DOMContentLoaded', function() {
	const cfg = window.twDesigner;
	const el = {
		name:       document.getElementById('template_name'),
		save:       document.getElementById('tw-save'),
		download:   document.getElementById('tw-download'),
		cancel:     document.getElementById('tw-cancel'),
		status:     document.getElementById('tw-status'),
		columns:    document.getElementById('tw-columns'),
		count:      document.getElementById('tw-count'),
		filter:     document.getElementById('tw-filter'),
		catalog:    document.getElementById('tw-catalog'),
		customIn:   document.getElementById('tw-custom-header'),
		customAdd:  document.getElementById('tw-custom-add'),
		preview:    document.getElementById('tw-preview'),
	};

	let columns = cfg.columns.slice();
	let pkey = cfg.pkey || '';
	let saving = false;
	const savedSnapshot = { spec: '', name: '' };

	// Known headers (catalog + system) so a custom header cannot shadow one.
	const knownHeaders = {};
	cfg.catalog.forEach(function(g) {
		g.fields.forEach(function(f) { knownHeaders[f.header.toLowerCase()] = { group: g.key, name: f.name, label: f.label }; });
	});
	['strabo_internal_id', 'orientation_type', 'orientation_role', 'geometry_type', 'geometry_wkt'].forEach(function(k) {
		knownHeaders[k] = { system: k };
	});

	// ------------------------------------------------------------ helpers
	function isLocked(c)  { return c.kind === 'system' && c.key === 'strabo_internal_id'; }
	function isAuto(c)    { return c.kind === 'system' && c.key === 'orientation_type'; }
	function isPinned(c)  { return isLocked(c) || isAuto(c); }
	function sig(c) {
		if (c.kind === 'system') { return 'system:' + c.key; }
		if (c.kind === 'field')  { return 'field:' + c.group + '.' + c.name; }
		return 'custom:' + c.header.toLowerCase();
	}
	function has(s) { return columns.some(function(c) { return sig(c) === s; }); }

	function normalize() {
		// id first, once
		const id = columns.filter(isLocked)[0] || { kind: 'system', key: 'strabo_internal_id', header: 'strabo_internal_id',
			label: cfg.systemMeta.strabo_internal_id.label, section: 'system', hint: cfg.systemMeta.strabo_internal_id.hint };
		columns = columns.filter(function(c) { return !isLocked(c) && !isAuto(c); });
		columns.unshift(id);
		// orientation_type before the first orientation field, only when one exists
		let firstOrient = -1;
		for (let i = 0; i < columns.length; i++) {
			if (columns[i].kind === 'field' && columns[i].group === 'orientation') { firstOrient = i; break; }
		}
		if (firstOrient >= 0) {
			columns.splice(firstOrient, 0, { kind: 'system', key: 'orientation_type', header: 'orientation_type',
				label: cfg.systemMeta.orientation_type.label, section: 'system', hint: cfg.systemMeta.orientation_type.hint });
		}
	}

	function buildSpec() {
		return {
			spec_version: 1,
			layout: 'long',
			columns: columns.map(function(c) {
				if (c.kind === 'system') { return { kind: 'system', key: c.key }; }
				if (c.kind === 'field')  { return { kind: 'field', group: c.group, name: c.name }; }
				return { kind: 'custom', header: c.header };
			})
		};
	}

	function isDirty() {
		return JSON.stringify(buildSpec()) !== savedSnapshot.spec || el.name.value.trim() !== savedSnapshot.name;
	}
	function markClean() {
		savedSnapshot.spec = JSON.stringify(buildSpec());
		savedSnapshot.name = el.name.value.trim();
	}

	function setStatus(text, kind) {
		el.status.textContent = text || '';
		el.status.className = 'tw-status' + (text ? ' tw-status-' + (kind || 'info') : '');
	}

	function setEnabled(a, on) {
		a.setAttribute('aria-disabled', on ? 'false' : 'true');
		a.classList.toggle('disabled', !on);
	}

	function refreshActions() {
		const named = el.name.value.trim() !== '';
		setEnabled(el.save, named && columns.length > 0 && !saving);
		setEnabled(el.download, named && columns.length > 0 && !saving);
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function(ch) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
		});
	}

	function sectionLabel(key) { return cfg.sections[key] || key; }

	// ------------------------------------------------------------ list view
	function renderColumns() {
		let html = '';
		columns.forEach(function(c, i) {
			const pinned = isPinned(c);
			html += '<li class="tw-col tw-sec-' + escapeHtml(c.section) + (pinned ? ' tw-col-pinned' : '') + '" data-index="' + i + '"'
				+ (pinned ? '' : ' draggable="true"') + '>';
			html += '<span class="tw-col-handle" aria-hidden="true">' + (pinned ? '' : '&#8942;&#8942;') + '</span>';
			html += '<span class="tw-col-pos">' + (i + 1) + '</span>';
			html += '<span class="tw-col-body"><strong>' + escapeHtml(c.label) + '</strong>'
				+ '<code>' + escapeHtml(c.header) + '</code>'
				+ (c.hint ? '<small>' + escapeHtml(c.hint) + '</small>' : '') + '</span>';
			html += '<span class="tw-chip tw-chip-' + escapeHtml(c.section) + '">' + escapeHtml(sectionLabel(c.section)) + '</span>';
			html += '<span class="tw-col-controls">';
			if (isLocked(c)) {
				html += '<span class="tw-col-note">locked</span>';
			} else if (isAuto(c)) {
				html += '<span class="tw-col-note">automatic</span>';
			} else {
				html += '<button type="button" class="tw-btn" data-act="up" data-index="' + i + '" title="Move up" aria-label="Move ' + escapeHtml(c.label) + ' up">&#8593;</button>'
					+ '<button type="button" class="tw-btn" data-act="down" data-index="' + i + '" title="Move down" aria-label="Move ' + escapeHtml(c.label) + ' down">&#8595;</button>'
					+ '<button type="button" class="tw-btn tw-btn-remove" data-act="remove" data-index="' + i + '" title="Remove" aria-label="Remove ' + escapeHtml(c.label) + '">&#10005;</button>';
			}
			html += '</span></li>';
		});
		el.columns.innerHTML = html;
		el.count.textContent = '(' + columns.length + ')';
	}

	function moveBy(i, dir) {
		let j = i + dir;
		while (j > 0 && j < columns.length && isPinned(columns[j])) { j += dir; }
		if (j < 1 || j >= columns.length) { return; }
		const c = columns.splice(i, 1)[0];
		columns.splice(j, 0, c);
		afterChange();
	}

	function removeAt(i) {
		if (isPinned(columns[i])) { return; }
		columns.splice(i, 1);
		afterChange();
	}

	el.columns.addEventListener('click', function(e) {
		const btn = e.target.closest('button[data-act]');
		if (!btn) { return; }
		const i = parseInt(btn.getAttribute('data-index'), 10);
		if (btn.getAttribute('data-act') === 'up')     { moveBy(i, -1); }
		if (btn.getAttribute('data-act') === 'down')   { moveBy(i, 1); }
		if (btn.getAttribute('data-act') === 'remove') { removeAt(i); }
	});

	// Drag and drop (mouse); the arrows cover keyboard and phones.
	let dragIndex = null;
	el.columns.addEventListener('dragstart', function(e) {
		const li = e.target.closest('li.tw-col');
		if (!li || li.classList.contains('tw-col-pinned')) { e.preventDefault(); return; }
		dragIndex = parseInt(li.getAttribute('data-index'), 10);
		li.classList.add('tw-dragging');
		e.dataTransfer.effectAllowed = 'move';
		try { e.dataTransfer.setData('text/plain', String(dragIndex)); } catch (err) { /* Firefox requires setData; ignore failures */ }
	});
	el.columns.addEventListener('dragover', function(e) {
		if (dragIndex === null) { return; }
		const li = e.target.closest('li.tw-col');
		if (!li) { return; }
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';
		const rect = li.getBoundingClientRect();
		const after = (e.clientY - rect.top) > rect.height / 2;
		el.columns.querySelectorAll('.tw-drop-before, .tw-drop-after').forEach(function(x) { x.classList.remove('tw-drop-before', 'tw-drop-after'); });
		li.classList.add(after ? 'tw-drop-after' : 'tw-drop-before');
	});
	el.columns.addEventListener('dragleave', function(e) {
		const li = e.target.closest('li.tw-col');
		if (li) { li.classList.remove('tw-drop-before', 'tw-drop-after'); }
	});
	el.columns.addEventListener('drop', function(e) {
		if (dragIndex === null) { return; }
		const li = e.target.closest('li.tw-col');
		if (!li) { return; }
		e.preventDefault();
		const rect = li.getBoundingClientRect();
		const after = (e.clientY - rect.top) > rect.height / 2;
		let target = parseInt(li.getAttribute('data-index'), 10) + (after ? 1 : 0);
		const c = columns.splice(dragIndex, 1)[0];
		if (target > dragIndex) { target -= 1; }
		if (target < 1) { target = 1; }
		columns.splice(target, 0, c);
		dragIndex = null;
		afterChange();
	});
	el.columns.addEventListener('dragend', function() {
		dragIndex = null;
		el.columns.querySelectorAll('.tw-dragging, .tw-drop-before, .tw-drop-after').forEach(function(x) {
			x.classList.remove('tw-dragging', 'tw-drop-before', 'tw-drop-after');
		});
	});

	// ------------------------------------------------------------ catalog
	const openGroups = {};
	function renderCatalog() {
		const q = el.filter.value.trim().toLowerCase();
		let html = '';
		const groups = cfg.catalog.map(function(g) {
			return { key: g.key, label: g.label, fields: g.fields.map(function(f) {
				return { s: 'field:' + g.key + '.' + f.name, group: g.key, name: f.name, header: f.header, label: f.label, hint: f.hint };
			}) };
		});
		groups.push({ key: 'system', label: 'StraboSpot columns', fields: cfg.system.map(function(f) {
			return { s: 'system:' + f.key, system: f.key, header: f.header, label: f.label, hint: f.hint };
		}) });
		groups.forEach(function(g) {
			const fields = g.fields.filter(function(f) {
				return q === '' || f.label.toLowerCase().indexOf(q) >= 0 || f.header.toLowerCase().indexOf(q) >= 0;
			});
			if (q !== '' && fields.length === 0) { return; }
			const added = g.fields.filter(function(f) { return has(f.s); }).length;
			const open = q !== '' || openGroups[g.key];
			html += '<details class="tw-group tw-sec-' + escapeHtml(g.key) + '" data-group="' + escapeHtml(g.key) + '"' + (open ? ' open' : '') + '>';
			html += '<summary><span class="tw-chip tw-chip-' + escapeHtml(g.key) + '">' + escapeHtml(g.label) + '</span>'
				+ '<span class="tw-group-count">' + added + ' of ' + g.fields.length + ' added</span></summary>';
			html += '<ul class="tw-fields">';
			fields.forEach(function(f) {
				const inTpl = has(f.s);
				html += '<li class="tw-field' + (inTpl ? ' tw-field-added' : '') + '">';
				html += '<button type="button" class="tw-btn tw-btn-add" data-sig="' + escapeHtml(f.s) + '"' + (inTpl ? ' disabled' : '')
					+ ' aria-label="Add ' + escapeHtml(f.label) + '">' + (inTpl ? '&#10003;' : '+') + '</button>';
				html += '<span class="tw-field-body"><strong>' + escapeHtml(f.label) + '</strong><code>' + escapeHtml(f.header) + '</code>'
					+ (f.hint ? '<small>' + escapeHtml(f.hint) + '</small>' : '') + '</span>';
				html += '</li>';
			});
			html += '</ul></details>';
		});
		if (html === '') { html = '<p class="tw-hint">No fields match.</p>'; }
		el.catalog.innerHTML = html;
	}

	el.catalog.addEventListener('toggle', function(e) {
		const d = e.target;
		if (d && d.matches && d.matches('details.tw-group') && el.filter.value.trim() === '') {
			openGroups[d.getAttribute('data-group')] = d.open;
		}
	}, true);

	el.catalog.addEventListener('click', function(e) {
		const btn = e.target.closest('button.tw-btn-add');
		if (!btn || btn.disabled) { return; }
		addBySig(btn.getAttribute('data-sig'));
	});

	function addBySig(s) {
		if (has(s)) { return; }
		if (s.indexOf('field:') === 0) {
			const gn = s.substring(6).split('.');
			let found = null;
			cfg.catalog.forEach(function(g) {
				if (g.key !== gn[0]) { return; }
				g.fields.forEach(function(f) { if (f.name === gn[1]) { found = { g: g, f: f }; } });
			});
			if (!found) { return; }
			columns.push({ kind: 'field', group: found.g.key, name: found.f.name, header: found.f.header,
				label: found.f.label, section: found.g.key, hint: found.f.hint });
		} else if (s.indexOf('system:') === 0) {
			const k = s.substring(7);
			const f = cfg.system.filter(function(x) { return x.key === k; })[0];
			if (!f) { return; }
			columns.push({ kind: 'system', key: k, header: k, label: f.label, section: 'system', hint: f.hint });
		}
		afterChange();
		setStatus('', '');
	}

	el.filter.addEventListener('input', renderCatalog);

	// ------------------------------------------------------------ custom columns
	function addCustom() {
		const h = el.customIn.value.trim();
		if (h === '') { setStatus('Type a header for the custom column first.', 'error'); return; }
		const low = h.toLowerCase();
		if (knownHeaders[low]) {
			const k = knownHeaders[low];
			setStatus('"' + h + '" is a StraboField column. Add it from the catalog' + (k.label ? ' (' + k.label + ')' : '') + '.', 'error');
			return;
		}
		if (has('custom:' + low)) { setStatus('That custom column is already in the template.', 'error'); return; }
		columns.push({ kind: 'custom', header: h, label: h, section: 'custom', hint: 'custom field on the spot' });
		el.customIn.value = '';
		afterChange();
		setStatus('', '');
	}
	el.customAdd.addEventListener('click', function(e) { e.preventDefault(); addCustom(); });
	el.customIn.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); addCustom(); } });

	// ------------------------------------------------------------ preview
	function exampleCell(c, row) {
		if (c.kind === 'system') {
			if (c.key === 'strabo_internal_id') { return ''; }
			if (c.key === 'orientation_type')   { return row === 0 ? 'planar' : 'linear'; }
			if (c.key === 'orientation_role')   { return 'primary'; }
			if (c.key === 'geometry_type')      { return 'Point'; }
			if (c.key === 'geometry_wkt')       { return 'POINT (-95.2478 38.9581)'; }
		}
		if (c.kind === 'field' && c.group === 'orientation') {
			const planar = { strike: '045', dip: '30', dip_direction: '135', feature_type: 'bedding', quality: 'good' };
			const linear = { trend: '120', plunge: '15', feature_type: 'lineation', quality: 'good' };
			const v = row === 0 ? planar[c.name] : linear[c.name];
			return v !== undefined ? v : '';
		}
		if (row === 1) { return ''; }   // spot-level columns: first row only
		if (c.kind === 'field' && c.group === 'spot') {
			const spot = { name: 'Station 1', latitude: '38.9717', longitude: '-95.2353', altitude: '260', date: '2026-09-18', notes: 'Bedding and a lineation' };
			return spot[c.name] !== undefined ? spot[c.name] : '…';
		}
		return '…';
	}

	function renderPreview() {
		let band = '<tr class="tw-band">';
		let i = 0;
		while (i < columns.length) {
			const sec = columns[i].section;
			let span = 1;
			while (i + span < columns.length && columns[i + span].section === sec) { span++; }
			band += '<th class="tw-sec-' + escapeHtml(sec) + '" colspan="' + span + '">' + escapeHtml(sectionLabel(sec)) + '</th>';
			i += span;
		}
		band += '</tr>';
		let head = '<tr class="tw-head">';
		columns.forEach(function(c) { head += '<th>' + escapeHtml(c.header) + '</th>'; });
		head += '</tr>';
		let rows = '';
		for (let r = 0; r < 2; r++) {
			rows += '<tr>';
			columns.forEach(function(c) {
				const v = exampleCell(c, r);
				// the spot name repeats on every row of the spot
				const isName = c.kind === 'field' && c.group === 'spot' && c.name === 'name';
				rows += '<td>' + escapeHtml(isName ? 'Station 1' : v) + '</td>';
			});
			rows += '</tr>';
		}
		el.preview.innerHTML = '<thead>' + band + head + '</thead><tbody>' + rows + '</tbody>';
	}

	// ------------------------------------------------------------ save / download
	function saveTemplate(cb) {
		const name = el.name.value.trim();
		if (name === '') { setStatus('Give the template a name first.', 'error'); el.name.focus(); cb(false); return; }
		if (columns.length === 0) { setStatus('The template has no columns.', 'error'); cb(false); return; }
		saving = true; refreshActions();
		setStatus('Saving…', 'info');
		const body = new URLSearchParams();
		body.append('action', 'save_template');
		body.append('name', name);
		body.append('spec_json', JSON.stringify(buildSpec()));
		if (pkey !== '') { body.append('pkey', pkey); }
		fetch('ajax.php', { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(res) {
				saving = false; refreshActions();
				if (res && res.ok) {
					pkey = String(res.pkey);
					markClean();
					el.save.textContent = 'Save changes';
					cb(true);
				} else {
					setStatus((res && res.message) ? res.message : 'Could not save the template.', 'error');
					cb(false);
				}
			})
			.catch(function() {
				saving = false; refreshActions();
				setStatus('Could not reach the server to save the template.', 'error');
				cb(false);
			});
	}

	el.save.addEventListener('click', function(e) {
		e.preventDefault();
		if (el.save.getAttribute('aria-disabled') === 'true') { return; }
		saveTemplate(function(ok) {
			if (ok) { window.location = 'index.php?saved=' + encodeURIComponent(el.name.value.trim()); }
		});
	});

	el.download.addEventListener('click', function(e) {
		e.preventDefault();
		if (el.download.getAttribute('aria-disabled') === 'true') { return; }
		saveTemplate(function(ok) {
			if (!ok) { return; }
			setStatus('Template saved. Your blank spreadsheet is downloading.', 'info');
			window.location = 'export.php?what=template&template_id=' + encodeURIComponent(pkey) + '&format=xlsx';
		});
	});

	el.cancel.addEventListener('click', function(e) {
		if (isDirty() && !window.confirm('Leave without saving? Your column changes will be lost.')) { e.preventDefault(); }
	});
	window.addEventListener('beforeunload', function(e) {
		if (isDirty() && !saving) { e.preventDefault(); e.returnValue = ''; }
	});

	el.name.addEventListener('input', refreshActions);

	// ------------------------------------------------------------ boot
	function afterChange() {
		normalize();
		renderColumns();
		renderCatalog();
		renderPreview();
		refreshActions();
	}
	normalize();
	markClean();
	renderColumns();
	renderCatalog();
	renderPreview();
	refreshActions();
	if (cfg.method === 'new' && el.name.value.trim() === '') { el.name.focus(); }
});
