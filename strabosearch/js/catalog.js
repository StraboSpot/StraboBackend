/**
 * File: catalog.js
 * Description: StraboSearch criteria catalog (§4.2) + vocab loading +
 *              row⇄DSL serialization helpers. Pure data/logic — no DOM.
 *              Widget kinds map 1:1 onto §6.3.3's semantic classes:
 *
 *                text        — single-line full-text (U1, U10, I3, M1*)
 *                prefixtext  — text + exact-match toggle (U5, U6)
 *                polygon     — map modal drawn polygon (U2); serializes
 *                              as GeoJSON Polygon, legacy {bbox} values
 *                              load as rectangle polygons
 *                daterange   — paired date inputs (U3)
 *                numrange    — paired min/max numerics (F1–F4)
 *                owner       — single-pick typeahead over users (U4)
 *                samplevocab — dual chip-selects type+purpose (U7)
 *                subsystems  — U8; serializes to the DSL's TOP-LEVEL
 *                              `subsystems` array, not a criteria row
 *                flags       — U9 has-data flag chips
 *                vocab       — chip multi-select w/ typeahead (F5..E3, I1, F11)
 *                rocktype    — hierarchical flat-indented multi-select (F7)
 *                province    — single-pick typeahead over shapegeology (F10)
 *                tristate    — Any / Yes / No radio (I2; NOT hidden §6.3.4)
 *
 *              (*) M1 is served as a vocab facet by the API, so it uses
 *              the vocab widget with the mineral list — richer than the
 *              design's plain-text fallback and same predicate.
 *
 * @package    StraboSpot Web Site — StraboSearch
 */

(function (window) {
	'use strict';

	var CRITERIA = [
		// ---- Universal core (§4.2) --------------------------------------
		{ id: 'U1',  group: 'Universal', label: 'Keyword',
			widget: 'text', placeholder: 'e.g. granite, "fault zone", Tuscany' },
		{ id: 'U2',  group: 'Universal', label: 'Location (map area)',
			widget: 'polygon' },
		{ id: 'U3',  group: 'Universal', label: 'Date range',
			widget: 'daterange' },
		{ id: 'U4',  group: 'Universal', label: 'Owner',
			widget: 'owner', vocab: 'owner' },
		{ id: 'U5',  group: 'Universal', label: 'Sample name / id',
			widget: 'prefixtext', placeholder: 'sample name or id…' },
		{ id: 'U6',  group: 'Universal', label: 'IGSN',
			widget: 'prefixtext', placeholder: 'IGSN…' },
		{ id: 'U7',  group: 'Universal', label: 'Sample type / purpose',
			widget: 'samplevocab' },
		{ id: 'U8',  group: 'Universal', label: 'Subsystem',
			widget: 'subsystems', unique: true, noNot: true },
		{ id: 'U9',  group: 'Universal', label: 'Has data (Field flags)',
			widget: 'flags' },
		{ id: 'U10', group: 'Universal', label: 'Tag name',
			widget: 'text', placeholder: 'tag name…' },

		// ---- Field (F) ---------------------------------------------------
		{ id: 'F1',  group: 'Field', label: 'Orientation: strike range',
			widget: 'numrange', unit: '°', lo: 0, hi: 360, wrap: true },
		{ id: 'F2',  group: 'Field', label: 'Orientation: dip range',
			widget: 'numrange', unit: '°', lo: 0, hi: 90 },
		{ id: 'F3',  group: 'Field', label: 'Orientation: trend range',
			widget: 'numrange', unit: '°', lo: 0, hi: 360, wrap: true },
		{ id: 'F4',  group: 'Field', label: 'Orientation: plunge range',
			widget: 'numrange', unit: '°', lo: 0, hi: 90 },
		{ id: 'F5',  group: 'Field', label: 'Orientation: feature type',
			widget: 'vocab', vocab: 'feature_type' },
		{ id: 'F6',  group: 'Field', label: 'Orientation: planar / linear',
			widget: 'vocab', fixed: ['planar', 'linear'] },
		{ id: 'F7',  group: 'Field', label: 'Rock type / lithology',
			widget: 'rocktype', vocab: 'rock_type',
			hint: 'Selecting a parent includes all its descendants.' },
		{ id: 'F8',  group: 'Field', label: 'Metamorphic facies',
			widget: 'vocab', vocab: 'met_facies' },
		{ id: 'F9',  group: 'Field', label: 'Trace / contact type',
			widget: 'vocab', vocab: 'trace_type' },
		{ id: 'F10', group: 'Field', label: 'Tectonic province',
			widget: 'province', vocab: 'province' },
		{ id: 'F11', group: 'Field', label: 'Tag type',
			widget: 'vocab', vocab: 'tag_type' },

		// ---- Micro (M) ---------------------------------------------------
		{ id: 'M1',  group: 'Micro', label: 'Mineral',
			widget: 'vocab', vocab: 'mineral' },
		{ id: 'M2',  group: 'Micro', label: 'Mineral determination method',
			widget: 'vocab', vocab: 'mineral_method' },
		{ id: 'M3',  group: 'Micro', label: 'Instrument type',
			widget: 'vocab', vocab: 'instrument_type' },
		{ id: 'M4',  group: 'Micro', label: 'Detector type',
			widget: 'vocab', vocab: 'detector_type' },

		// ---- Experimental (E) --------------------------------------------
		{ id: 'E1',  group: 'Experimental', label: 'Apparatus type',
			widget: 'vocab', vocab: 'apparatus_type' },
		{ id: 'E2',  group: 'Experimental', label: 'DAQ sensor type',
			widget: 'vocab', vocab: 'daq_sensor_type' },
		{ id: 'E3',  group: 'Experimental', label: 'Measurement type',
			widget: 'vocab', vocab: 'measurement_type' },

		// ---- Image (I) ---------------------------------------------------
		{ id: 'I1',  group: 'Image', label: 'Image type',
			widget: 'vocab', vocab: 'image_type' },
		{ id: 'I2',  group: 'Image', label: 'Annotated',
			widget: 'tristate', noNot: true },
		{ id: 'I3',  group: 'Image', label: 'Image title / caption keyword',
			widget: 'text', placeholder: 'e.g. outcrop, bedding contact' }
	];

	var GROUP_SUBSYSTEM = { Field: 'field', Micro: 'micro', Experimental: 'exp' };

	var U9_FLAGS = [
		{ value: 'orientation',    label: 'Orientation' },
		{ value: 'samples',        label: 'Samples' },
		{ value: 'images',         label: 'Images' },
		{ value: 'microstructure', label: 'Microstructure' },
		{ value: 'strat',          label: 'Strat section' }
	];

	var SUBSYSTEMS = [
		{ value: 'field',   label: 'Field' },
		{ value: 'micro',   label: 'Micro' },
		{ value: 'exp',     label: 'Experimental' },
		{ value: 'samples', label: 'Samples' }
	];

	var byId = {};
	CRITERIA.forEach(function (c) { byId[c.id] = c; });

	// ---- vocab loading (cached per facet) --------------------------------
	// Normalized entry: {value, label, count?, depth?}
	var vocabCache = {};

	function normalizeVocab(facet, values) {
		return (values || []).map(function (v) {
			if (typeof v === 'string') return { value: v, label: v };
			if (v.path !== undefined)
				return { value: v.path, label: v.path.split(':').pop(),
					depth: v.depth || 0 };
			if (v.gid !== undefined)
				return { value: v.gid, label: v.name };
			if (v.pkey !== undefined)
				return { value: v.pkey, label: v.name };
			if (v.label !== undefined)
				return { value: v.value, label: v.label };
			return { value: v.value, label: v.value, count: v.count };
		});
	}

	function loadVocab(facet) {
		if (vocabCache[facet]) return vocabCache[facet];
		vocabCache[facet] = fetch(window.STRABO_SEARCH.api + '?action=vocab&facet=' + encodeURIComponent(facet))
			.then(function (r) {
				if (!r.ok) throw new Error('vocab ' + facet + ' failed');
				return r.json();
			})
			.then(function (j) { return normalizeVocab(facet, j.values); })
			.catch(function (e) {
				delete vocabCache[facet];   // allow retry
				throw e;
			});
		return vocabCache[facet];
	}

	// ---- row state → DSL --------------------------------------------------
	// Row state: {crit: 'U1', not: false, value: <widget-specific>}
	// Widget-specific value states are documented per case below.

	function isActive(row) {
		if (!row || !row.crit) return false;
		var c = byId[row.crit];
		var v = row.value;
		if (v === undefined || v === null) return false;
		switch (c.widget) {
			case 'text':
				return String(v).trim() !== '';
			case 'prefixtext':
				return v.text !== undefined && String(v.text).trim() !== '';
			case 'polygon':
				return Array.isArray(v.polygon) && v.polygon.length >= 3;
			case 'daterange':
			case 'numrange':
				return (v.min !== undefined && v.min !== '') ||
				       (v.max !== undefined && v.max !== '');
			case 'owner':
			case 'province':
				return v.value !== undefined && v.value !== null && v.value !== '';
			case 'samplevocab':
				return (v.sample_type && v.sample_type.length > 0) ||
				       (v.sample_purpose && v.sample_purpose.length > 0);
			case 'subsystems':
				return Array.isArray(v) && v.length > 0 && v.length < SUBSYSTEMS.length;
			case 'flags':
			case 'vocab':
			case 'rocktype':
				return Array.isArray(v) && v.length > 0;
			case 'tristate':
				return v === true || v === false;
			default:
				return false;
		}
	}

	/** row state → DSL criteria entry (null for inactive rows and U8). */
	function rowToDsl(row) {
		if (!isActive(row)) return null;
		var c = byId[row.crit];
		if (c.widget === 'subsystems') return null;   // top-level, not a row
		var v = row.value;
		var value;
		switch (c.widget) {
			case 'text':
				value = String(v).trim(); break;
			case 'prefixtext':
				value = { text: String(v.text).trim(), exact: !!v.exact }; break;
			case 'polygon': {
				// Open vertex ring → closed GeoJSON ring (API §4.4 form).
				var ring = v.polygon.map(function (p) { return [Number(p[0]), Number(p[1])]; });
				ring.push(ring[0].slice());
				value = { type: 'Polygon', coordinates: [ring] };
				break;
			}
			case 'daterange': {
				value = {};
				if (v.min) value.min = v.min;
				if (v.max) value.max = v.max;
				break;
			}
			case 'numrange': {
				value = {};
				if (v.min !== undefined && v.min !== '') value.min = Number(v.min);
				if (v.max !== undefined && v.max !== '') value.max = Number(v.max);
				break;
			}
			case 'owner':
			case 'province':
				value = Number(v.value); break;
			case 'samplevocab': {
				value = {};
				if (v.sample_type && v.sample_type.length) value.sample_type = v.sample_type.slice();
				if (v.sample_purpose && v.sample_purpose.length) value.sample_purpose = v.sample_purpose.slice();
				break;
			}
			case 'flags':
			case 'vocab':
			case 'rocktype':
				value = v.slice(); break;
			case 'tristate':
				value = (v === true); break;
		}
		var out = { id: row.crit, value: value };
		if (row.not && !c.noNot) out.not = true;
		// I2 "No" rides the NOT flag over a true predicate (§6.3.3: the
		// radio covers negation; the DSL wire value stays boolean-true).
		if (c.widget === 'tristate' && v === false) {
			out.value = true;
			out.not = true;
		}
		return out;
	}

	/** DSL criteria entry → row state (for saved/URL load). */
	function dslToRow(entry) {
		var c = byId[entry.id];
		if (!c) return null;
		var row = { crit: entry.id, not: !!entry.not, value: null };
		var v = entry.value;
		switch (c.widget) {
			case 'text':
				row.value = String(v); break;
			case 'prefixtext':
				row.value = (typeof v === 'string')
					? { text: v, exact: false }
					: { text: v.text || '', exact: !!v.exact };
				break;
			case 'polygon':
				// Legacy {bbox:[w,s,e,n]} values (pre-polygon saved searches
				// and share URLs) load as their rectangle polygon. MultiPolygon
				// (API-accepted, never UI-produced) still does not load.
				if (v && Array.isArray(v.bbox) && v.bbox.length === 4) {
					var b = v.bbox.map(Number);
					row.value = { polygon: [[b[0], b[1]], [b[2], b[1]], [b[2], b[3]], [b[0], b[3]]] };
				} else if (v && v.type === 'Polygon' && Array.isArray(v.coordinates)
						&& Array.isArray(v.coordinates[0]) && v.coordinates[0].length >= 4) {
					var outer = v.coordinates[0].map(function (p) { return [Number(p[0]), Number(p[1])]; });
					var first = outer[0], last = outer[outer.length - 1];
					if (first[0] === last[0] && first[1] === last[1]) outer.pop();
					row.value = { polygon: outer };
				} else {
					row.value = null;
				}
				break;
			case 'daterange': {
				if (v && v.year) {
					row.value = { min: v.year + '-01-01', max: v.year + '-12-31' };
				} else {
					row.value = { min: (v && v.min) || '', max: (v && v.max) || '' };
				}
				break;
			}
			case 'numrange':
				row.value = { min: (v && v.min !== undefined) ? v.min : '',
				              max: (v && v.max !== undefined) ? v.max : '' };
				break;
			case 'owner':
			case 'province':
				row.value = { value: Array.isArray(v) ? v[0] : v, label: null };
				break;
			case 'samplevocab':
				row.value = { sample_type: (v && v.sample_type) || [],
				              sample_purpose: (v && v.sample_purpose) || [] };
				break;
			case 'flags':
			case 'vocab':
			case 'rocktype':
				row.value = Array.isArray(v) ? v.slice() : [v]; break;
			case 'tristate':
				row.value = entry.not ? false : (v === true || v === 'true');
				row.not = false;
				break;
		}
		return row;
	}

	/** One-line human summary of a DSL, for the saved-search list (§6.6.1). */
	function summarizeDsl(dsl) {
		var parts = [];
		if (dsl.subsystems && dsl.subsystems.length && dsl.subsystems.length < 4) {
			parts.push('subsystem=' + dsl.subsystems.join('/'));
		}
		(dsl.criteria || []).forEach(function (e) {
			var c = byId[e.id];
			var name = c ? c.label : e.id;
			var v = e.value, s;
			if (v === null || v === undefined) s = '';
			else if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') s = String(v);
			else if (Array.isArray(v)) s = v.join(', ');
			else if (v.bbox) s = 'bbox ' + v.bbox.map(function (n) { return Number(n).toFixed(1); }).join(',');
			else if (v.type === 'Polygon' && Array.isArray(v.coordinates) && Array.isArray(v.coordinates[0]))
				s = 'polygon (' + Math.max(v.coordinates[0].length - 1, 0) + ' vertices)';
			else if (v.text !== undefined) s = v.text + (v.exact ? ' (exact)' : '');
			else if (v.min !== undefined || v.max !== undefined)
				s = (v.min !== undefined ? v.min : '…') + '–' + (v.max !== undefined ? v.max : '…');
			else if (v.year !== undefined) s = String(v.year);
			else s = [].concat(v.sample_type || [], v.sample_purpose || []).join(', ');
			parts.push((e.not ? 'NOT ' : '') + name + '=' + s);
		});
		return parts.join('; ') || '(no criteria)';
	}

	window.SSCatalog = {
		CRITERIA: CRITERIA,
		GROUP_SUBSYSTEM: GROUP_SUBSYSTEM,
		U9_FLAGS: U9_FLAGS,
		SUBSYSTEMS: SUBSYSTEMS,
		byId: byId,
		loadVocab: loadVocab,
		isActive: isActive,
		rowToDsl: rowToDsl,
		dslToRow: dslToRow,
		summarizeDsl: summarizeDsl
	};

})(window);
