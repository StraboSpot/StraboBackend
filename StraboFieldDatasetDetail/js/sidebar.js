/**
 * Dataset-detail sidebar: renders the selected spot's metadata as a single
 * scrolling accordion. Sections that have no data for the current spot are
 * skipped entirely — there is no tab-per-field-group clutter.
 *
 * Section source fields (confirmed against legacy tab_builders.js):
 *   Spot Info:        spot.properties (id, name, geometry, date/time)
 *   Notes:            spot.properties.notes
 *   Orientations:     spot.properties.orientation_data[]
 *   Samples:          spot.properties.samples[]
 *   Images:           spot.properties.images[]
 *   3D Structures:    spot.properties._3d_structures[]
 *   Other Features:   spot.properties.other_features[]
 *   Tephra:           spot.properties.tephra[]
 *   Ig/Met:           spot.properties.pet
 *   Strat Section:    spot.properties.sed.strat_section
 *   Sedimentology:    spot.properties.sed.{lithologies, bedding, structures,
 *                                         diagenesis, fossils, interpretations}
 */
(function (global) {
	'use strict';

	var IMAGE_CDN = 'https://strabospot.org/mapimage/';

	// Remember which accordion sections the user has opened so their choice
	// carries across spot selections. Seeded with "Spot Info" so the first
	// click on any spot opens with at least that section expanded.
	var openSections = { 'Spot Info': true };

	// Fields we never want to render as generic rows (shown in their own section
	// or structurally uninteresting). Keep in sync with the section builders.
	var HIDDEN_FIELDS = {
		id: 1, name: 1, notes: 1, images: 1, orientation_data: 1, orientation: 1,
		samples: 1, tephra: 1, other_features: 1, _3d_structures: 1, pet: 1,
		sed: 1, trace: 1, surface_feature: 1, tags: 1, datasetid: 1, self: 1,
		modified_timestamp: 1, unix_timestamp: 1, image_basemap: 1,
		strat_section_id: 1
	};

	function open(spot) {
		var el = document.getElementById('dataset-sidebar');
		if (!el) return;
		el.innerHTML = renderSidebar(spot);
		el.classList.add('open');
		el.setAttribute('aria-hidden', 'false');
		bindSidebarEvents(el, spot);
	}

	function close() {
		var el = document.getElementById('dataset-sidebar');
		if (!el) return;
		el.classList.remove('open');
		el.setAttribute('aria-hidden', 'true');
	}

	function bindSidebarEvents(root, spot) {
		var closeBtn = root.querySelector('.ds-close');
		if (closeBtn) closeBtn.addEventListener('click', close);

		Array.prototype.forEach.call(root.querySelectorAll('.ds-section > .ds-section-header'), function (h) {
			h.addEventListener('click', function () {
				var section = h.parentElement;
				section.classList.toggle('ds-collapsed');
				var title = section.getAttribute('data-title');
				if (title) {
					if (section.classList.contains('ds-collapsed')) {
						delete openSections[title];
					} else {
						openSections[title] = true;
					}
				}
			});
		});

		Array.prototype.forEach.call(root.querySelectorAll('.ds-image-action'), function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('data-basemap-id');
				if (id && global.DatasetDetailImageBasemap) {
					global.DatasetDetailImageBasemap.enter(id);
				}
			});
		});
	}

	function renderSidebar(spot) {
		var props = (spot && spot.properties) || {};
		var sections = buildSections(spot, props);

		var html = '';
		html += '<div class="ds-header">';
		html += '  <div class="ds-title">' + escapeHtml(props.name || 'Unnamed spot') + '</div>';
		html += '  <button type="button" class="ds-close" aria-label="Close">&times;</button>';
		html += '</div>';
		html += '<div class="ds-body">';
		sections.forEach(function (s) {
			var isOpen = !!openSections[s.title];
			html += '<section class="ds-section' + (isOpen ? '' : ' ds-collapsed') + '" data-title="' + escapeHtml(s.title) + '">';
			html += '  <header class="ds-section-header"><span class="ds-chev">▸</span>' + escapeHtml(s.title) + '</header>';
			html += '  <div class="ds-section-body">' + s.html + '</div>';
			html += '</section>';
		});
		html += '</div>';
		return html;
	}

	function buildSections(spot, props) {
		var sections = [];

		var info = buildSpotInfo(spot, props);
		if (info) sections.push({ title: 'Spot Info', html: info });

		if (props.notes) sections.push({ title: 'Notes', html: '<p class="ds-notes">' + escapeHtml(String(props.notes)) + '</p>' });

		var orient = buildOrientations(props.orientation_data);
		if (orient) sections.push({ title: 'Orientations', html: orient });

		var samples = buildArrayOfObjects(props.samples, 'sample');
		if (samples) sections.push({ title: 'Samples', html: samples });

		var images = buildImages(props.images);
		if (images) sections.push({ title: 'Images', html: images });

		var threeD = buildArrayOfObjects(props._3d_structures, '3D structure');
		if (threeD) sections.push({ title: '3D Structures', html: threeD });

		var others = buildArrayOfObjects(props.other_features, 'other feature');
		if (others) sections.push({ title: 'Other Features', html: others });

		var teph = buildArrayOfObjects(props.tephra, 'tephra');
		if (teph) sections.push({ title: 'Tephra', html: teph });

		var trace = buildObject(props.trace);
		if (trace) sections.push({ title: 'Trace', html: trace });

		var surface = buildObject(props.surface_feature);
		if (surface) sections.push({ title: 'Surface Feature', html: surface });

		var igmet = buildObject(props.pet);
		if (igmet) sections.push({ title: 'Igneous / Metamorphic', html: igmet });

		var strat = buildObject(props.sed && props.sed.strat_section);
		if (strat) sections.push({ title: 'Strat Section', html: strat });

		var sed = buildSed(props.sed);
		if (sed) sections.push({ title: 'Sedimentology', html: sed });

		var rest = buildGenericRest(props);
		if (rest) sections.push({ title: 'Other', html: rest });

		return sections;
	}

	/* ---------------------- section builders ------------------------------ */

	function buildSpotInfo(spot, props) {
		var rows = [];
		rows.push(kv('ID', props.id));
		if (props.date) rows.push(kv('Date', props.date));
		if (props.time) rows.push(kv('Time', props.time));

		var geom = spot && spot.geometry;
		if (geom && geom.type) {
			rows.push(kv('Geometry', geom.type));
			if (geom.type === 'Point' && Array.isArray(geom.coordinates)) {
				rows.push(kv('Longitude', fmtCoord(geom.coordinates[0])));
				rows.push(kv('Latitude', fmtCoord(geom.coordinates[1])));
			}
		}
		if (props.altitude != null && props.altitude !== '') rows.push(kv('Altitude (m)', props.altitude));
		return rows.length ? renderKVList(rows) : null;
	}

	function buildOrientations(orientation_data) {
		if (!Array.isArray(orientation_data) || orientation_data.length === 0) return null;

		var html = '';
		orientation_data.forEach(function (o, idx) {
			html += '<div class="ds-item">';
			html += '  <div class="ds-item-title">' + escapeHtml(orientationLabel(o, idx)) + '</div>';
			html += renderOrientationBody(o);
			if (Array.isArray(o.associated_orientation) && o.associated_orientation.length) {
				o.associated_orientation.forEach(function (ao, aidx) {
					html += '<div class="ds-item ds-sub">';
					html += '  <div class="ds-item-title">Associated: ' + escapeHtml(orientationLabel(ao, aidx)) + '</div>';
					html += renderOrientationBody(ao);
					html += '</div>';
				});
			}
			html += '</div>';
		});
		return html;
	}

	function renderOrientationBody(o) {
		var rows = [];
		// Promote the geologically meaningful fields to the top, then dump anything else.
		var ordered = ['feature_type', 'strike', 'dip_direction', 'dip', 'trend', 'plunge', 'rake', 'facing', 'quality'];
		var seen = {};
		ordered.forEach(function (k) {
			if (o[k] != null && o[k] !== '') {
				rows.push(kv(labelFor(k), formatOrientationValue(k, o[k])));
				seen[k] = 1;
			}
		});
		Object.keys(o).forEach(function (k) {
			if (seen[k]) return;
			if (k === 'associated_orientation' || k === 'type' || k === 'id') return;
			if (HIDDEN_FIELDS[k]) return;
			var v = o[k];
			if (v == null || v === '') return;
			rows.push(kv(labelFor(k), formatValue(v)));
		});
		return renderKVList(rows);
	}

	function orientationLabel(o, idx) {
		var type = o.feature_type ? prettyLabel(o.feature_type) : (o.type ? prettyLabel(o.type) : 'Orientation');
		return type + ' ' + (idx + 1);
	}

	function formatOrientationValue(k, v) {
		if (k === 'strike' || k === 'dip_direction' || k === 'trend' || k === 'rake') {
			return fmtDegrees(v);
		}
		if (k === 'dip' || k === 'plunge') {
			return fmtDegrees(v);
		}
		return formatValue(v);
	}

	function buildImages(images) {
		if (!Array.isArray(images) || images.length === 0) return null;
		var basemapIds = (global.DatasetDetail && global.DatasetDetail.imageBasemapIds) || {};
		var html = '<div class="ds-images">';
		images.forEach(function (img) {
			var id = img && img.id;
			if (!id) return;
			var src = IMAGE_CDN + id + '.jpg';
			var title = img.title || img.caption || '';
			var isBasemap = !!basemapIds[String(id)];
			html += '<figure class="ds-image' + (isBasemap ? ' ds-image-basemap' : '') + '">';
			html += '  <a href="' + src + '" target="_blank" rel="noopener">';
			html += '    <img loading="lazy" src="' + src + '" alt="' + escapeHtml(title) + '">';
			html += '  </a>';
			if (title) html += '<figcaption>' + escapeHtml(title) + '</figcaption>';
			if (isBasemap) {
				html += '<button type="button" class="ds-image-action" data-basemap-id="' + escapeHtml(String(id)) + '">View as basemap</button>';
			}
			html += '</figure>';
		});
		html += '</div>';
		return html;
	}

	function buildArrayOfObjects(arr, itemLabel) {
		if (!Array.isArray(arr) || arr.length === 0) return null;
		var html = '';
		arr.forEach(function (item, idx) {
			var title = (item && (item.label || item.type || item.name))
				? prettyLabel(item.label || item.type || item.name)
				: (itemLabel + ' ' + (idx + 1));
			html += '<div class="ds-item">';
			html += '  <div class="ds-item-title">' + escapeHtml(title) + '</div>';
			html += renderKVList(objectToRows(item));
			html += '</div>';
		});
		return html;
	}

	function buildObject(obj) {
		if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return null;
		var rows = objectToRows(obj);
		return rows.length ? renderKVList(rows) : null;
	}

	function buildSed(sed) {
		if (!sed || typeof sed !== 'object') return null;
		var keys = ['lithologies', 'bedding', 'structures', 'diagenesis', 'fossils', 'interpretations'];
		var html = '';
		keys.forEach(function (k) {
			var v = sed[k];
			if (v == null) return;
			var inner;
			if (Array.isArray(v)) {
				if (v.length === 0) return;
				inner = buildArrayOfObjects(v, k.replace(/s$/, ''));
			} else if (typeof v === 'object') {
				inner = buildObject(v);
			} else {
				inner = renderKVList([kv(labelFor(k), formatValue(v))]);
			}
			if (!inner) return;
			html += '<div class="ds-sub-section">';
			html += '  <div class="ds-sub-title">' + escapeHtml(prettyLabel(k)) + '</div>';
			html += '  ' + inner;
			html += '</div>';
		});
		return html || null;
	}

	function buildGenericRest(props) {
		var rows = [];
		Object.keys(props).forEach(function (k) {
			if (HIDDEN_FIELDS[k]) return;
			if (k === 'date' || k === 'time' || k === 'altitude' || k === 'lat' || k === 'lng') return;
			var v = props[k];
			if (v == null || v === '') return;
			if (Array.isArray(v) && v.length === 0) return;
			if (typeof v === 'object') return; // nested objects are rendered by dedicated sections or skipped here
			rows.push(kv(labelFor(k), formatValue(v)));
		});
		return rows.length ? renderKVList(rows) : null;
	}

	/* ---------------------- rendering helpers ----------------------------- */

	function objectToRows(obj) {
		var rows = [];
		Object.keys(obj).forEach(function (k) {
			if (k === 'id' || k === 'type') return;
			if (HIDDEN_FIELDS[k]) return;
			var v = obj[k];
			if (v == null || v === '') return;
			if (Array.isArray(v)) {
				if (v.length === 0) return;
				if (typeof v[0] === 'object') {
					rows.push(kv(labelFor(k), '<div class="ds-nested">' + v.map(function (item, i) {
						if (typeof item !== 'object') return '<div>' + escapeHtml(String(item)) + '</div>';
						return '<div class="ds-nested-item"><div class="ds-nested-title">' + (i + 1) + '</div>' + renderKVList(objectToRows(item)) + '</div>';
					}).join('') + '</div>', true));
				} else {
					rows.push(kv(labelFor(k), escapeHtml(v.join(', '))));
				}
			} else if (typeof v === 'object') {
				var sub = objectToRows(v);
				if (sub.length) {
					rows.push(kv(labelFor(k), '<div class="ds-nested-obj">' + renderKVList(sub) + '</div>', true));
				}
			} else {
				rows.push(kv(labelFor(k), formatValue(v)));
			}
		});
		return rows;
	}

	function renderKVList(rows) {
		if (!rows.length) return '';
		var html = '<dl class="ds-kv">';
		rows.forEach(function (r) {
			html += '<dt>' + escapeHtml(r.label) + '</dt>';
			html += '<dd>' + (r.raw ? r.value : escapeHtml(r.value)) + '</dd>';
		});
		html += '</dl>';
		return html;
	}

	function kv(label, value, raw) {
		return { label: label, value: value == null ? '' : String(value), raw: !!raw };
	}

	function formatValue(v) {
		if (v == null) return '';
		if (typeof v === 'boolean') return v ? 'Yes' : 'No';
		if (Array.isArray(v)) return v.join(', ');
		if (typeof v === 'object') return JSON.stringify(v);
		return String(v);
	}

	function fmtDegrees(v) {
		if (v === '' || v == null) return '';
		var n = Number(v);
		if (isNaN(n)) return String(v);
		return (Math.round(n * 10) / 10) + '°';
	}

	function fmtCoord(v) {
		var n = Number(v);
		if (isNaN(n)) return String(v);
		return n.toFixed(6);
	}

	function labelFor(key) {
		return prettyLabel(key);
	}

	function prettyLabel(key) {
		if (!key) return '';
		return String(key)
			.replace(/^_+/, '')
			.replace(/_/g, ' ')
			.replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

	function escapeHtml(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	global.DatasetDetailSidebar = { open: open, close: close };
})(window);
