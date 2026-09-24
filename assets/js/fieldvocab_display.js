/**
 * File: assets/js/fieldvocab_display.js
 * Description: Field choice labels for the spot viewers (display only).
 *              Feeds that send spots to a viewer (StraboFieldDatasetDetail/
 *              api/spots.php, stratSectionDetail/getData.php, doi/doisearch.php,
 *              search + fieldland interfacesearch.php) keep the stored choice
 *              NAMES in properties, because map symbology and strat patterns
 *              key on them, and add a sparse overlay built by
 *              FieldVocab::displayOverlay: labels = [[path, label], ...].
 *
 *              FieldVocabDisplay.copy(obj, labels) returns a deep COPY of obj
 *              with every path set to its form label; the input is untouched.
 *              FieldVocabDisplay.isLabel(s) says whether a copy produced s, so
 *              title prettifiers can leave labels as the app writes them
 *              (leading capital only, decision D6).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
(function (global) {
	'use strict';

	var produced = {};

	function copy(obj, labels) {
		if (obj == null || typeof obj !== 'object') return obj;
		if (!Array.isArray(labels) || !labels.length) return obj;
		var out = JSON.parse(JSON.stringify(obj));
		labels.forEach(function (pl) {
			if (!Array.isArray(pl) || !Array.isArray(pl[0]) || !pl[0].length) return;
			var path = pl[0], o = out;
			for (var i = 0; i < path.length - 1; i++) {
				if (o == null || typeof o !== 'object') return;
				o = o[path[i]];
			}
			var last = path[path.length - 1];
			if (o == null || typeof o !== 'object' || !(last in o)) return;
			o[last] = pl[1];
			produced[pl[1]] = true;
		});
		return out;
	}

	/** A GeoJSON feature with its properties relabeled (geometry etc. shared). */
	function feature(f) {
		if (!f || !Array.isArray(f.labels) || !f.labels.length) return f;
		var out = {};
		Object.keys(f).forEach(function (k) { out[k] = f[k]; });
		out.properties = copy(f.properties, f.labels);
		return out;
	}

	function isLabel(s) {
		return typeof s === 'string' && produced[s] === true;
	}

	/** A label as a title: leading capital only; anything else via fallback(s). */
	function title(s, fallback) {
		s = String(s);
		if (isLabel(s)) return s.charAt(0).toUpperCase() + s.slice(1);
		return fallback ? fallback(s) : s;
	}

	global.FieldVocabDisplay = { copy: copy, feature: feature, isLabel: isLabel, title: title };
})(window);
