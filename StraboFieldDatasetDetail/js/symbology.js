/**
 * Spot symbology — ported from /search/includes/map_search_functions.js.
 *
 * Selects a PNG icon from /assets/js/images/geology/ based on the feature's
 * orientation (feature_type + dip/plunge bucket), then rotates it by strike
 * (or dip_direction - 90, or trend).
 *
 * Multi-orientation spots are expanded into one OL feature per orientation
 * by explodeSpotOrientations() before styling.
 */
(function (global) {
	'use strict';

	var ICON_BASE = '/assets/js/images/geology/';

	// Map of symbol-key → PNG file. Mirrors the legacy `symbols` object
	// exactly (commented-out entries preserved as comments for clarity).
	var SYMBOLS = {
		'default_point': ICON_BASE + 'point.png',

		// Planar Feature Symbols
		'bedding_horizontal': ICON_BASE + 'bedding_horizontal.png',
		'bedding_inclined':   ICON_BASE + 'bedding_inclined.png',
		'bedding_vertical':   ICON_BASE + 'bedding_vertical.png',
		'contact_inclined':   ICON_BASE + 'contact_inclined.png',
		'contact_vertical':   ICON_BASE + 'contact_vertical.png',
		'fault':              ICON_BASE + 'fault.png',
		'foliation_horizontal': ICON_BASE + 'foliation_horizontal.png',
		'foliation_inclined':   ICON_BASE + 'foliation_general_inclined.png',
		'foliation_vertical':   ICON_BASE + 'foliation_general_vertical.png',
		'fracture':           ICON_BASE + 'fracture.png',
		'shear_zone_inclined': ICON_BASE + 'shear_zone_inclined.png',
		'shear_zone_vertical': ICON_BASE + 'shear_zone_vertical.png',
		'vein':               ICON_BASE + 'vein.png',

		// Linear Feature Symbols
		'lineation_general':  ICON_BASE + 'lineation_general.png'
	};

	function toRadians(deg) {
		return deg * (Math.PI / 180);
	}

	function getSymbolPath(feature_type, orientation, orientation_type) {
		var default_symbol = SYMBOLS.default_point;
		if (orientation_type === 'linear_orientation') {
			default_symbol = SYMBOLS.lineation_general;
		}

		switch (true) {
			case (orientation === 0):
				return SYMBOLS[feature_type + '_horizontal']
					|| SYMBOLS[feature_type + '_inclined']
					|| SYMBOLS[feature_type]
					|| default_symbol;
			case (orientation > 0 && orientation < 90):
				return SYMBOLS[feature_type + '_inclined']
					|| SYMBOLS[feature_type]
					|| default_symbol;
			case (orientation === 90):
				return SYMBOLS[feature_type + '_vertical']
					|| SYMBOLS[feature_type]
					|| default_symbol;
			default:
				return default_symbol;
		}
	}

	function resolveRotationAndOrientation(orientation) {
		var rotation = 0;
		var symbol_orientation = 0;
		var feature_type = 'none';
		var orientation_type = 'none';

		if (orientation) {
			if (orientation.strike != null && orientation.strike !== '') {
				rotation = Number(orientation.strike);
			} else if (orientation.dip_direction != null && orientation.dip_direction !== '') {
				var dd = Number(orientation.dip_direction);
				rotation = isNaN(dd) ? 0 : (dd - 90);
			} else if (orientation.trend != null && orientation.trend !== '') {
				rotation = Number(orientation.trend);
			}
			if (isNaN(rotation)) rotation = 0;

			if (orientation.dip != null && orientation.dip !== '') {
				symbol_orientation = Number(orientation.dip);
			} else if (orientation.plunge != null && orientation.plunge !== '') {
				symbol_orientation = Number(orientation.plunge);
			}
			if (isNaN(symbol_orientation)) symbol_orientation = 0;

			feature_type     = orientation.feature_type || feature_type;
			orientation_type = orientation.type         || orientation_type;
		}

		return {
			rotation: rotation,
			symbol_orientation: symbol_orientation,
			feature_type: feature_type,
			orientation_type: orientation_type
		};
	}

	/** Build an ol.style.Icon for a feature's single orientation. */
	function buildIcon(orientation) {
		var r = resolveRotationAndOrientation(orientation);
		return new ol.style.Icon({
			anchorXUnits: 'fraction',
			anchorYUnits: 'fraction',
			opacity: 1,
			rotation: toRadians(r.rotation),
			src: getSymbolPath(r.feature_type, r.symbol_orientation, r.orientation_type),
			scale: 0.05
		});
	}

	/**
	 * Explode a GeoJSON spot into one feature per orientation. A spot with
	 * no orientation_data is returned as-is (single element array). Matches
	 * the legacy _.each orientation_data + associated_orientation pattern.
	 */
	function explodeSpotOrientations(spot) {
		var out = [];
		var geomType = spot.geometry && spot.geometry.type;
		var isPointy = geomType === 'Point' || geomType === 'MultiPoint';
		var od = spot.properties && spot.properties.orientation_data;

		if (isPointy && Array.isArray(od) && od.length > 0) {
			od.forEach(function (orientation) {
				// Primary orientation on this spot.
				var primary = cloneFeature(spot);
				delete primary.properties.orientation_data;
				primary.properties.orientation = orientation;
				out.push(primary);

				// Any associated orientations (e.g. lineation on a plane).
				if (Array.isArray(orientation.associated_orientation)) {
					orientation.associated_orientation.forEach(function (assoc) {
						var f = cloneFeature(spot);
						delete f.properties.orientation_data;
						f.properties.orientation = assoc;
						out.push(f);
					});
				}
			});
		} else {
			var copy = cloneFeature(spot);
			delete copy.properties.orientation_data;
			out.push(copy);
		}
		return out;
	}

	function cloneFeature(f) {
		return JSON.parse(JSON.stringify(f));
	}

	global.DatasetDetailSymbology = {
		buildIcon: buildIcon,
		explodeSpotOrientations: explodeSpotOrientations,
		getSymbolPath: getSymbolPath,
		resolveRotationAndOrientation: resolveRotationAndOrientation,
		toRadians: toRadians
	};
})(window);
