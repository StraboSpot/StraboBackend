/**
 * File: builder.js
 * Description: StraboSearch criteria builder (§6.3) — renders criterion
 *              rows into #criteriaBuilder, keeps row state, serializes
 *              to/from the §4.4 DSL via SSCatalog. Notes vs the spec:
 *
 *              - Criterion picker is a native <select> with optgroups
 *                (§6.9 explicitly allows native select; ~30 entries).
 *              - Site CSS hides native radios/checkboxes unless the
 *                input+ADJACENT-label pattern is used — chips/toggles
 *                here are button-role spans instead, tri-states use the
 *                adjacent-label pattern.
 *              - U2 map modal: Leaflet click-vertex polygon drawing
 *                (double-click closes, right-click undoes a vertex),
 *                plus a "Use current view" rectangle shortcut.
 *
 * @package    StraboSpot Web Site — StraboSearch
 */

(function (window, document) {
	'use strict';

	var C = window.SSCatalog;
	var container = null;
	var rows = [];            // [{crit, not, value, el, valueBox, notBtn}]
	var changeCb = function () {};
	var searchCb = function () {};   // Enter-in-widget → run search (§6.9)
	var rowSeq = 0;

	// ══════════════════════════════════════════════════════════════════
	// helpers
	// ══════════════════════════════════════════════════════════════════

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}

	function notifyChange() {
		updatePickerGating();
		updateAddRow();
		changeCb();
	}

	function bindEnterToSearch(input) {
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter') { ev.preventDefault(); searchCb(); }
		});
	}

	/** Active subsystem set from U8 rows (null = unconstrained). */
	function activeSubsystems() {
		for (var i = 0; i < rows.length; i++) {
			var r = rows[i];
			if (r.crit === 'U8' && Array.isArray(r.value) && r.value.length > 0
				&& r.value.length < C.SUBSYSTEMS.length) {
				return r.value;
			}
		}
		return null;
	}

	/** §6.3.2: disable subsystem-extension options excluded by U8. */
	function updatePickerGating() {
		var subs = activeSubsystems();
		rows.forEach(function (r) {
			var sel = r.el.querySelector('.ss-crit-select');
			if (!sel) return;
			Array.prototype.forEach.call(sel.options, function (opt) {
				if (!opt.value) return;
				var c = C.byId[opt.value];
				var g = C.GROUP_SUBSYSTEM[c.group];
				var disabled = false;
				if (g && subs && subs.indexOf(g) === -1) disabled = true;
				// U8 is single-use (§6.3.2 exception — two subsystem rows
				// are contradictory, unlike repeated value criteria).
				if (c.unique && r.crit !== opt.value) {
					for (var i = 0; i < rows.length; i++) {
						if (rows[i] !== r && rows[i].crit === opt.value) disabled = true;
					}
				}
				opt.disabled = disabled;
			});
		});
	}

	function updateAddRow() {
		var btn = container.querySelector('.ss-add-row a');
		if (!btn) return;
		var bottom = rows[rows.length - 1];
		var ok = bottom && bottom.crit;
		btn.classList.toggle('ss-disabled', !ok);
		btn.title = ok ? '' : 'Pick a criterion above first';

		// "Start over" is a no-op on a pristine builder (single empty row);
		// results/URL can't be stale then — app.js invalidation already
		// cleared them the moment the effective query diverged.
		var reset = container.querySelector('.ss-start-over');
		if (reset) {
			var pristine = rows.length === 1 && !rows[0].crit;
			reset.classList.toggle('ss-disabled', pristine);
			reset.title = pristine ? 'Nothing to clear' : '';
		}
	}

	// ══════════════════════════════════════════════════════════════════
	// value widgets (§6.3.3) — each wires events straight into row.value
	// ══════════════════════════════════════════════════════════════════

	function buildWidget(row) {
		var c = C.byId[row.crit];
		var box = row.valueBox;
		box.innerHTML = '';
		if (!c) return;
		switch (c.widget) {
			case 'text':        return wText(row, box, c);
			case 'prefixtext':  return wPrefixText(row, box, c);
			case 'polygon':     return wPolygon(row, box, c);
			case 'daterange':   return wDateRange(row, box, c);
			case 'numrange':    return wNumRange(row, box, c);
			case 'owner':
			case 'province':    return wSinglePick(row, box, c);
			case 'samplevocab': return wSampleVocab(row, box, c);
			case 'subsystems':  return wFixedChips(row, box, C.SUBSYSTEMS, 'Subsystems');
			case 'flags':       return wFixedChips(row, box, C.U9_FLAGS, 'Has-data flags');
			case 'vocab':       return c.fixed
				? wFixedChips(row, box, c.fixed.map(function (v) { return { value: v, label: v }; }), c.label)
				: wVocabChips(row, box, c);
			case 'rocktype':    return wVocabChips(row, box, c);
			case 'tristate':    return wTristate(row, box, c);
		}
	}

	function wText(row, box, c) {
		var input = el('input');
		input.type = 'text';
		input.placeholder = c.placeholder || '';
		input.setAttribute('aria-label', c.label);
		if (typeof row.value === 'string') input.value = row.value;
		input.addEventListener('input', function () {
			row.value = input.value;
			notifyChange();
		});
		bindEnterToSearch(input);
		box.appendChild(input);
	}

	function wPrefixText(row, box, c) {
		if (!row.value || typeof row.value !== 'object') row.value = { text: '', exact: false };
		var input = el('input');
		input.type = 'text';
		input.placeholder = c.placeholder || '';
		input.setAttribute('aria-label', c.label);
		input.value = row.value.text || '';
		input.addEventListener('input', function () {
			row.value.text = input.value;
			notifyChange();
		});
		bindEnterToSearch(input);

		var exact = el('span', 'ss-chipbtn', 'Exact match');
		exact.setAttribute('role', 'button');
		exact.tabIndex = 0;
		exact.setAttribute('aria-pressed', row.value.exact ? 'true' : 'false');
		exact.classList.toggle('ss-on', !!row.value.exact);
		function flip() {
			row.value.exact = !row.value.exact;
			exact.classList.toggle('ss-on', row.value.exact);
			exact.setAttribute('aria-pressed', row.value.exact ? 'true' : 'false');
			notifyChange();
		}
		exact.addEventListener('click', flip);
		exact.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); flip(); }
		});

		box.appendChild(input);
		var hint = el('span', 'ss-hint');
		hint.appendChild(exact);
		hint.appendChild(document.createTextNode(' off = prefix match ("Tqm…")'));
		box.appendChild(hint);
	}

	function polygonLabel(ring) {
		return 'polygon: ' + ring.length + ' vertices';
	}

	function wPolygon(row, box) {
		var btn = el('a', 'button small', row.value && row.value.polygon ? 'Edit area' : 'Set area…');
		btn.href = 'javascript:void(0);';
		var summary = el('span', 'ss-bbox-summary',
			row.value && row.value.polygon ? polygonLabel(row.value.polygon) : 'no area set');
		btn.addEventListener('click', function () {
			openPolygonModal(row.value && row.value.polygon, function (ring) {
				row.value = ring ? { polygon: ring } : null;
				summary.textContent = ring ? polygonLabel(ring) : 'no area set';
				btn.textContent = ring ? 'Edit area' : 'Set area…';
				notifyChange();
			});
		});
		box.appendChild(btn);
		box.appendChild(summary);
	}

	function wDateRange(row, box) {
		if (!row.value || typeof row.value !== 'object') row.value = { min: '', max: '' };
		var pair = el('div', 'ss-range-pair');
		['min', 'max'].forEach(function (k, i) {
			var input = el('input');
			input.type = 'date';
			input.setAttribute('aria-label', (k === 'min' ? 'From' : 'To') + ' date');
			input.value = row.value[k] || '';
			input.addEventListener('input', function () {
				row.value[k] = input.value;
				notifyChange();
			});
			bindEnterToSearch(input);
			if (i === 1) pair.appendChild(el('span', null, '–'));
			pair.appendChild(input);
		});
		box.appendChild(pair);
		box.appendChild(el('span', 'ss-hint', 'Either side may be left open.'));
	}

	function wNumRange(row, box, c) {
		if (!row.value || typeof row.value !== 'object') row.value = { min: '', max: '' };
		var pair = el('div', 'ss-range-pair');
		['min', 'max'].forEach(function (k, i) {
			var input = el('input');
			input.type = 'number';
			input.min = c.lo; input.max = c.hi; input.step = 'any';
			input.placeholder = (k === 'min' ? 'min ' : 'max ') + c.lo + '–' + c.hi;
			input.setAttribute('aria-label', c.label + ' ' + k);
			input.value = (row.value[k] !== undefined) ? row.value[k] : '';
			input.addEventListener('input', function () {
				row.value[k] = input.value;
				notifyChange();
			});
			bindEnterToSearch(input);
			if (i === 1) pair.appendChild(el('span', null, '–'));
			pair.appendChild(input);
			pair.appendChild(el('span', null, c.unit || ''));
		});
		box.appendChild(pair);
		if (c.wrap) box.appendChild(el('span', 'ss-hint',
			'min > max wraps through 0/360 (e.g. 350–10).'));
	}

	function wTristate(row, box, c) {
		// input + ADJACENT label-for — the only pattern site CSS renders.
		var name = 'ss-tri-' + (++rowSeq);
		var states = [
			{ v: null,  label: 'Any' },
			{ v: true,  label: 'Yes' },
			{ v: false, label: 'No' }
		];
		var wrap = el('div', 'ss-tristate');
		states.forEach(function (s, i) {
			var id = name + '-' + i;
			var input = el('input');
			input.type = 'radio';
			input.name = name;
			input.id = id;
			input.checked = (row.value === s.v);
			input.addEventListener('change', function () {
				if (input.checked) { row.value = s.v; notifyChange(); }
			});
			var label = el('label', null, s.label);
			label.htmlFor = id;
			wrap.appendChild(input);
			wrap.appendChild(label);
		});
		box.appendChild(wrap);
	}

	// ---- chip multi-select over a fixed list (U8, U9, F6) ----------------

	function wFixedChips(row, box, list, aria) {
		if (!Array.isArray(row.value)) row.value = [];
		var wrap = el('div', 'ss-chips');
		wrap.setAttribute('role', 'group');
		wrap.setAttribute('aria-label', aria);
		list.forEach(function (entry) {
			var chip = el('span', 'ss-chipbtn', entry.label);
			chip.setAttribute('role', 'button');
			chip.tabIndex = 0;
			function sync() {
				var on = row.value.indexOf(entry.value) !== -1;
				chip.classList.toggle('ss-on', on);
				chip.setAttribute('aria-pressed', on ? 'true' : 'false');
			}
			function flip() {
				var i = row.value.indexOf(entry.value);
				if (i === -1) row.value.push(entry.value); else row.value.splice(i, 1);
				sync();
				notifyChange();
			}
			chip.addEventListener('click', flip);
			chip.addEventListener('keydown', function (ev) {
				if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); flip(); }
			});
			sync();
			wrap.appendChild(chip);
		});
		box.appendChild(wrap);
	}

	// ---- typeahead machinery (vocab chips + single-picks) ----------------

	function attachTypeahead(input, listEl, getEntries, onPick) {
		var active = -1;
		var current = [];

		function close() {
			listEl.style.display = 'none';
			listEl.innerHTML = '';
			active = -1;
			current = [];
		}

		function render(entries) {
			current = entries.slice(0, 200);
			listEl.innerHTML = '';
			if (!current.length) { close(); return; }
			current.forEach(function (entry, i) {
				var li = el('li', entry.depth ? ('ss-ta-depth-' + Math.min(entry.depth, 3)) : null);
				li.textContent = entry.label;
				if (entry.depth) li.textContent = entry.label;
				if (entry.count !== undefined) {
					var n = el('span', 'ss-ta-count', String(entry.count));
					li.appendChild(n);
				}
				li.addEventListener('mousedown', function (ev) {
					ev.preventDefault();   // beat the input blur
					onPick(entry);
					close();
				});
				listEl.appendChild(li);
			});
			listEl.style.display = 'block';
		}

		function refresh() {
			var q = input.value.trim().toLowerCase();
			getEntries().then(function (entries) {
				var f = q
					? entries.filter(function (e) {
						return String(e.label).toLowerCase().indexOf(q) !== -1 ||
						       String(e.value).toLowerCase().indexOf(q) !== -1;
					})
					: entries;
				render(f);
			}).catch(function () {
				render([{ value: null, label: '(vocabulary unavailable — retry)', count: undefined }]);
			});
		}

		input.addEventListener('input', refresh);
		input.addEventListener('focus', refresh);
		input.addEventListener('blur', function () { setTimeout(close, 150); });
		input.addEventListener('keydown', function (ev) {
			var items = listEl.querySelectorAll('li');
			if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
				ev.preventDefault();
				if (!items.length) { refresh(); return; }
				active += (ev.key === 'ArrowDown') ? 1 : -1;
				if (active < 0) active = items.length - 1;
				if (active >= items.length) active = 0;
				Array.prototype.forEach.call(items, function (li, i) {
					li.classList.toggle('ss-active', i === active);
				});
				items[active].scrollIntoView({ block: 'nearest' });
			} else if (ev.key === 'Enter') {
				ev.preventDefault();
				if (active >= 0 && current[active]) {
					onPick(current[active]);
					close();
				} else if (items.length === 1 && current[0]) {
					onPick(current[0]);
					close();
				} else {
					searchCb();
				}
			} else if (ev.key === 'Escape') {
				close();
			}
		});
	}

	function wVocabChips(row, box, c) {
		if (!Array.isArray(row.value)) row.value = [];
		var ta = el('div', 'ss-typeahead');
		var input = el('input');
		input.type = 'text';
		input.placeholder = 'type to filter ' + c.label.toLowerCase() + '…';
		input.setAttribute('aria-label', c.label);
		var list = el('ul', 'ss-ta-list');
		list.style.display = 'none';
		ta.appendChild(input);
		ta.appendChild(list);

		var chips = el('div', 'ss-chips');

		function renderChips() {
			chips.innerHTML = '';
			row.value.forEach(function (v) {
				var chip = el('span', 'ss-chip', String(v));
				var x = el('a', null, '×');
				x.setAttribute('role', 'button');
				x.setAttribute('aria-label', 'Remove ' + v);
				x.tabIndex = 0;
				function remove() {
					var i = row.value.indexOf(v);
					if (i !== -1) row.value.splice(i, 1);
					renderChips();
					notifyChange();
				}
				x.addEventListener('click', remove);
				x.addEventListener('keydown', function (ev) {
					if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); remove(); }
				});
				chip.appendChild(x);
				chips.appendChild(chip);
			});
		}

		attachTypeahead(input, list, function () { return C.loadVocab(c.vocab); },
			function (entry) {
				if (entry.value === null) return;
				if (row.value.indexOf(entry.value) === -1) {
					row.value.push(entry.value);
					renderChips();
					notifyChange();
				}
				input.value = '';
			});

		box.appendChild(ta);
		box.appendChild(chips);
		if (c.hint) box.appendChild(el('span', 'ss-hint', c.hint));
		renderChips();
	}

	function wSinglePick(row, box, c) {
		// row.value: {value, label} | null
		var ta = el('div', 'ss-typeahead');
		var input = el('input');
		input.type = 'text';
		input.placeholder = 'type to find ' + c.label.toLowerCase() + '…';
		input.setAttribute('aria-label', c.label);
		var list = el('ul', 'ss-ta-list');
		list.style.display = 'none';
		ta.appendChild(input);
		ta.appendChild(list);

		function labelFor(val, entries) {
			for (var i = 0; i < entries.length; i++) {
				if (String(entries[i].value) === String(val)) return entries[i].label;
			}
			return String(val);
		}

		if (row.value && row.value.value !== undefined && row.value.value !== null) {
			input.value = row.value.label || String(row.value.value);
			// Resolve label for URL/saved loads that only carry the id.
			if (!row.value.label) {
				C.loadVocab(c.vocab).then(function (entries) {
					row.value.label = labelFor(row.value.value, entries);
					input.value = row.value.label;
				}).catch(function () {});
			}
		}

		attachTypeahead(input, list, function () { return C.loadVocab(c.vocab); },
			function (entry) {
				if (entry.value === null) return;
				row.value = { value: entry.value, label: entry.label };
				input.value = entry.label;
				notifyChange();
			});

		input.addEventListener('input', function () {
			// Free text clears the committed pick until re-selected.
			if (row.value && input.value !== row.value.label) {
				row.value = null;
				notifyChange();
			}
		});

		box.appendChild(ta);
	}

	function wSampleVocab(row, box) {
		if (!row.value || typeof row.value !== 'object') {
			row.value = { sample_type: [], sample_purpose: [] };
		}
		[['sample_type', 'Sample type'], ['sample_purpose', 'Sample purpose']]
			.forEach(function (pair) {
				var key = pair[0];
				var sub = el('div');
				sub.appendChild(el('span', 'ss-hint', pair[1]));
				// Reuse the vocab-chip widget against a facade row that
				// reads/writes the sub-array in place.
				var facade = { value: row.value[key] };
				wVocabChips(facade, sub, { label: pair[1], vocab: key });
				// facade.value stays the SAME array object — mutations via
				// splice/push flow through; only re-point on identity loss.
				row.value[key] = facade.value;
				box.appendChild(sub);
			});
	}

	// ══════════════════════════════════════════════════════════════════
	// U2 map modal (Leaflet click-vertex polygon, §6.3.3)
	// ══════════════════════════════════════════════════════════════════

	var polyModal = null;

	function openPolygonModal(existing, done) {
		closePolygonModal();
		var backdrop = el('div', 'grayOut');
		backdrop.style.display = 'inline';
		var card = el('div', 'ss-modal-card ss-modal-map');
		card.setAttribute('role', 'dialog');
		card.setAttribute('aria-label', 'Set search area');
		card.appendChild(el('h3', null, 'Set search area'));

		var mapDiv = el('div');
		mapDiv.id = 'ssPolyMap';
		card.appendChild(mapDiv);
		card.appendChild(el('span', 'ss-hint',
			'Click the map to drop vertices and double-click to close the polygon. ' +
			'Right-click removes the last vertex. "Use current view" makes a rectangle from the visible map.'));

		var actions = el('div', 'ss-modal-actions');
		var useView = el('a', 'button small', 'Use current view');
		var clear = el('a', 'button small', 'Clear');
		var save = el('a', 'button primary small', 'Save');
		var cancel = el('a', 'button small', 'Cancel');
		[useView, clear, save, cancel].forEach(function (b) {
			b.href = 'javascript:void(0);';
			actions.appendChild(b);
		});
		card.appendChild(actions);

		document.body.appendChild(backdrop);
		document.body.appendChild(card);
		polyModal = { backdrop: backdrop, card: card };

		var map = L.map(mapDiv, { worldCopyJump: true, doubleClickZoom: false });
		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 18,
			attribution: '&copy; OpenStreetMap contributors'
		}).addTo(map);

		var verts = [];      // open ring of [lng, lat], oldest first
		var closed = false;
		var layers = [];

		function round4(n) { return Math.round(n * 10000) / 10000; }

		function redraw() {
			layers.forEach(function (l) { map.removeLayer(l); });
			layers = [];
			// interactive: false on every preview layer, or the vertex dots
			// and line swallow map clicks. The natural closing gesture is a
			// double-click ON the just-placed vertex, which must fall through
			// to the map's dblclick handler.
			var latlngs = verts.map(function (p) { return [p[1], p[0]]; });
			if (closed) {
				layers.push(L.polygon(latlngs,
					{ color: '#d9534f', weight: 2, fillOpacity: 0.08, interactive: false }).addTo(map));
				return;
			}
			if (latlngs.length > 1) {
				layers.push(L.polyline(latlngs,
					{ color: '#d9534f', weight: 2, dashArray: '6 4', interactive: false }).addTo(map));
			}
			latlngs.forEach(function (ll) {
				layers.push(L.circleMarker(ll,
					{ radius: 4, color: '#d9534f', weight: 2, fillOpacity: 0.9, interactive: false }).addTo(map));
			});
		}

		// Shift all longitudes by one common multiple of 360 so rings drawn
		// on a worldCopyJump copy land back in [-180, 180]. A single shared
		// shift keeps the ring intact (per-vertex wrapping would tear
		// polygons that touch the antimeridian).
		function normalizeRing(ring) {
			var mean = ring.reduce(function (a, p) { return a + p[0]; }, 0) / ring.length;
			var shift = 360 * Math.round(mean / 360);
			if (!shift) return ring;
			return ring.map(function (p) { return [round4(p[0] - shift), p[1]]; });
		}

		map.on('click', function (ev) {
			if (closed) return;   // Clear starts a new polygon
			verts.push([round4(ev.latlng.lng), round4(ev.latlng.lat)]);
			redraw();
		});

		map.on('dblclick', function (ev) {
			if (closed) return;
			// The double-click's own click events already dropped this vertex
			// (twice on most browsers); collapse them into one final vertex.
			var pt = map.latLngToContainerPoint(ev.latlng);
			while (verts.length) {
				var last = verts[verts.length - 1];
				if (pt.distanceTo(map.latLngToContainerPoint([last[1], last[0]])) < 10) verts.pop();
				else break;
			}
			verts.push([round4(ev.latlng.lng), round4(ev.latlng.lat)]);
			if (verts.length >= 3) closed = true;
			redraw();
		});

		map.on('contextmenu', function () {
			if (closed || !verts.length) return;
			verts.pop();
			redraw();
		});

		if (existing) {
			verts = existing.map(function (p) { return [Number(p[0]), Number(p[1])]; });
			closed = true;
			redraw();
			map.fitBounds(verts.map(function (p) { return [p[1], p[0]]; }), { padding: [20, 20] });
		} else {
			map.setView([20, 0], 2);
		}

		useView.addEventListener('click', function () {
			// getBounds is unclamped: a zoomed-out or copy-panned view can
			// exceed [-180, 180], and stored data never does. Re-center by a
			// common 360 multiple, then clamp to the world so a wide view
			// means "everywhere" instead of a ring no location falls inside.
			var b = map.getBounds();
			var w = b.getWest(), s = b.getSouth();
			var e = b.getEast(), n = b.getNorth();
			var shift = 360 * Math.round(((w + e) / 2) / 360);
			w -= shift; e -= shift;
			if (e - w >= 360) { w = -180; e = 180; }
			w = Math.max(w, -180); e = Math.min(e, 180);
			verts = [[round4(w), round4(s)], [round4(e), round4(s)],
			         [round4(e), round4(n)], [round4(w), round4(n)]];
			closed = true;
			redraw();
		});
		clear.addEventListener('click', function () {
			verts = [];
			closed = false;
			redraw();
		});
		save.addEventListener('click', function () {
			if (!closed && verts.length >= 3) closed = true;   // Save implies close
			if (verts.length > 0 && !closed) {
				alert('A polygon needs at least 3 vertices. Keep clicking, or use Clear to start over.');
				return;
			}
			closePolygonModal();
			map.remove();
			done(closed ? normalizeRing(verts) : null);
		});
		cancel.addEventListener('click', function () {
			closePolygonModal();
			map.remove();
		});

		setTimeout(function () { map.invalidateSize(); }, 60);
	}

	function closePolygonModal() {
		if (!polyModal) return;
		polyModal.backdrop.remove();
		polyModal.card.remove();
		polyModal = null;
	}

	// ══════════════════════════════════════════════════════════════════
	// rows (§6.3.1)
	// ══════════════════════════════════════════════════════════════════

	function buildPicker(row) {
		var sel = el('select', 'ss-crit-select');
		sel.setAttribute('aria-label', 'Criterion');
		var empty = el('option', null, 'Choose Criteria');
		empty.value = '';
		sel.appendChild(empty);
		var groups = {};
		C.CRITERIA.forEach(function (c) {
			if (!groups[c.group]) {
				var og = document.createElement('optgroup');
				og.label = (c.group === 'Universal') ? 'Universal core' : c.group;
				groups[c.group] = og;
				sel.appendChild(og);
			}
			var opt = el('option', null, c.label);
			opt.value = c.id;
			groups[c.group].appendChild(opt);
		});
		sel.value = row.crit || '';
		sel.addEventListener('change', function () {
			row.crit = sel.value || null;
			row.value = null;
			row.not = false;
			syncNot(row);
			buildWidget(row);
			notifyChange();
		});
		return sel;
	}

	function syncNot(row) {
		var c = row.crit ? C.byId[row.crit] : null;
		var show = c && !c.noNot;
		row.notBtn.style.display = show ? '' : 'none';
		row.notBtn.classList.toggle('ss-on', !!row.not);
		row.notBtn.setAttribute('aria-pressed', row.not ? 'true' : 'false');
	}

	function addRow(state) {
		var row = {
			crit: (state && state.crit) || null,
			not: (state && state.not) || false,
			value: (state && state.value !== undefined) ? state.value : null
		};

		var wrap = el('div', 'ss-row');
		wrap.setAttribute('role', 'group');
		row.el = wrap;

		var valueBox = el('div', 'ss-value');
		row.valueBox = valueBox;

		var controls = el('div', 'ss-row-controls');
		var notBtn = el('span', 'ss-chipbtn', 'NOT');
		notBtn.setAttribute('role', 'button');
		notBtn.tabIndex = 0;
		row.notBtn = notBtn;
		function flipNot() {
			row.not = !row.not;
			syncNot(row);
			notifyChange();
		}
		notBtn.addEventListener('click', flipNot);
		notBtn.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); flipNot(); }
		});

		var removeBtn = el('span', 'ss-chipbtn', '×');
		removeBtn.setAttribute('role', 'button');
		removeBtn.setAttribute('aria-label', 'Remove this criterion');
		removeBtn.tabIndex = 0;
		function remove() {
			var i = rows.indexOf(row);
			if (i !== -1) rows.splice(i, 1);
			wrap.remove();
			if (rows.length === 0) addRow();   // §6.3.4: never zero rows
			notifyChange();
		}
		removeBtn.addEventListener('click', remove);
		removeBtn.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); remove(); }
		});

		controls.appendChild(notBtn);
		controls.appendChild(removeBtn);

		wrap.appendChild(buildPicker(row));
		wrap.appendChild(valueBox);
		wrap.appendChild(controls);

		var addBar = container.querySelector('.ss-add-row');
		container.insertBefore(wrap, addBar);
		rows.push(row);
		syncNot(row);
		if (row.crit) buildWidget(row);
		return row;
	}

	// ══════════════════════════════════════════════════════════════════
	// public API
	// ══════════════════════════════════════════════════════════════════

	function init(elContainer, opts) {
		container = elContainer;
		changeCb = (opts && opts.onChange) || function () {};
		searchCb = (opts && opts.onSearch) || function () {};

		var addBar = el('div', 'ss-add-row');
		var addBtn = el('a', 'button small', '+ AND row');
		addBtn.href = 'javascript:void(0);';
		addBtn.addEventListener('click', function () {
			if (addBtn.classList.contains('ss-disabled')) return;
			addRow();
			notifyChange();
		});
		addBar.appendChild(addBtn);

		// "Start over" (Jason 08-04): one-click reset to the first-load
		// state. No confirm — rebuilding is cheap and Save current sits
		// nearby for queries worth keeping. Stale results + the ?q= share
		// URL are cleared by app.js's effective-query invalidation, which
		// fires off the notifyChange below.
		var resetBtn = el('a', 'button small ss-start-over', 'Start over');
		resetBtn.href = 'javascript:void(0);';
		resetBtn.addEventListener('click', function () {
			if (resetBtn.classList.contains('ss-disabled')) return;
			rows.slice().forEach(function (r) { r.el.remove(); });
			rows = [];
			addRow();
			notifyChange();
		});
		addBar.appendChild(resetBtn);
		container.appendChild(addBar);

		addRow();   // open on an empty "— pick criterion —" row (Jason 08-02)
		notifyChange();
	}

	function hasActiveRow() {
		return rows.some(C.isActive);
	}

	/** Compose the full §4.4 DSL from current rows. */
	function getDsl(extra) {
		var criteria = [];
		rows.forEach(function (r) {
			var e = C.rowToDsl(r);
			if (e) criteria.push(e);
		});
		var dsl = {
			subsystems: activeSubsystems() || C.SUBSYSTEMS.map(function (s) { return s.value; }),
			criteria: criteria
		};
		if (extra) Object.keys(extra).forEach(function (k) { dsl[k] = extra[k]; });
		return dsl;
	}

	/** Repopulate the builder from a DSL (saved search / shared URL). */
	function loadDsl(dsl) {
		rows.slice().forEach(function (r) { r.el.remove(); });
		rows = [];
		if (dsl.subsystems && dsl.subsystems.length &&
			dsl.subsystems.length < C.SUBSYSTEMS.length) {
			addRow({ crit: 'U8', value: dsl.subsystems.slice() });
		}
		(dsl.criteria || []).forEach(function (entry) {
			var state = C.dslToRow(entry);
			if (state) addRow(state);
		});
		if (rows.length === 0) addRow();
		notifyChange();
	}

	window.SSBuilder = {
		init: init,
		getDsl: getDsl,
		loadDsl: loadDsl,
		hasActiveRow: hasActiveRow
	};

})(window, document);
