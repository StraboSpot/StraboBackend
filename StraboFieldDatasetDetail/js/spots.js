/**
 * Load dataset spots from the API, explode by orientation, render as an OL
 * vector layer, and fit the map view to the dataset extent.
 */
(function (global) {
	'use strict';

	var POINT_FEATURE_FILL_COLOR   = 'rgba(0, 120, 255, 0.6)';
	var POINT_FEATURE_STROKE_COLOR = '#1155aa';
	var LINE_DEFAULT_COLOR  = '#663300';
	var POLY_DEFAULT_FILL   = 'rgba(0, 0, 255, 0.35)';
	var POLY_DEFAULT_STROKE = '#000099';

	function loadSpots(map, datasetId, onReady) {
		var url = '/StraboFieldDatasetDetail/api/spots.php?dataset_id=' + encodeURIComponent(datasetId);
		fetch(url)
			.then(function (r) {
				if (!r.ok) throw new Error('spots.php returned HTTP ' + r.status);
				return r.json();
			})
			.then(function (data) {
				renderSpots(map, data);
				if (typeof onReady === 'function') onReady(data);
			})
			.catch(function (err) {
				console.error('Failed to load dataset spots:', err);
			});
	}

	function renderSpots(map, data) {
		// Index all spots (including strat + image-basemap spots) by id so
		// the sidebar can look up the original spot on click regardless of
		// which exploded orientation feature was hit.
		var spotsById = {};
		(data.features || []).forEach(function (spot) {
			if (spot && spot.properties && spot.properties.id != null) {
				spotsById[spot.properties.id] = spot;
			}
		});
		global.DatasetDetail.spotsById = spotsById;

		var geographicFeatures = [];
		(data.features || []).forEach(function (spot) {
			// Skip spots that live on an image basemap or strat section —
			// those are rendered by Phase 4 / Phase 5 modes.
			if (spot.properties && spot.properties.image_basemap) return;
			if (spot.properties && spot.properties.strat_section_id) return;
			geographicFeatures = geographicFeatures.concat(
				DatasetDetailSymbology.explodeSpotOrientations(spot)
			);
		});

		if (geographicFeatures.length === 0) return;

		var featureCollection = {
			type: 'FeatureCollection',
			features: geographicFeatures
		};

		var source = new ol.source.Vector({
			features: new ol.format.GeoJSON().readFeatures(featureCollection, {
				featureProjection: 'EPSG:3857',
				dataProjection: 'EPSG:4326'
			})
		});

		var vectorLayer = new ol.layer.Vector({
			source: source,
			style: styleForFeature,
			title: 'Spots',
			name: 'spotsLayer'
		});

		map.addLayer(vectorLayer);

		// Fit view to dataset extent — prefer server-computed envelope, fall
		// back to the layer's source extent.
		var extent;
		if (data.envelope && data.envelope.length === 4) {
			extent = ol.proj.transformExtent(
				[data.envelope[0], data.envelope[1], data.envelope[2], data.envelope[3]],
				'EPSG:4326', 'EPSG:3857'
			);
		} else {
			extent = source.getExtent();
		}
		if (extent && isFinite(extent[0]) && isFinite(extent[2])) {
			map.getView().fit(extent, {
				padding: [60, 60, 60, 60],
				maxZoom: 18,
				duration: 400
			});
		}

		global.DatasetDetail.spotsLayer = vectorLayer;
		global.DatasetDetail.spotsData  = data;
	}

	function styleForFeature(feature) {
		var geomType = feature.getGeometry().getType();

		if (geomType === 'Point' || geomType === 'MultiPoint') {
			var orientation = feature.get('orientation');
			if (orientation) {
				return new ol.style.Style({ image: DatasetDetailSymbology.buildIcon(orientation) });
			}
			return new ol.style.Style({
				image: new ol.style.Circle({
					radius: 6,
					fill: new ol.style.Fill({ color: POINT_FEATURE_FILL_COLOR }),
					stroke: new ol.style.Stroke({ color: POINT_FEATURE_STROKE_COLOR, width: 1.5 })
				})
			});
		}

		if (geomType === 'LineString' || geomType === 'MultiLineString') {
			return new ol.style.Style({
				stroke: buildTraceStroke(feature.get('trace'))
			});
		}

		if (geomType === 'Polygon' || geomType === 'MultiPolygon') {
			return new ol.style.Style({
				fill: new ol.style.Fill({ color: POLY_DEFAULT_FILL }),
				stroke: new ol.style.Stroke({ color: POLY_DEFAULT_STROKE, width: 2 })
			});
		}

		return null;
	}

	function buildTraceStroke(trace) {
		var color = LINE_DEFAULT_COLOR;
		var width = 2;
		var lineDash = [1, 0];

		if (trace && trace.trace_type) {
			if (trace.trace_type === 'geologic_struc')      color = '#FF0000';
			else if (trace.trace_type === 'contact')        color = '#000000';
			else if (trace.trace_type === 'geomorphic_fea') { color = '#0000FF'; width = 4; }
			else if (trace.trace_type === 'anthropenic_fe') { color = '#800080'; width = 4; }
		}

		if (trace && trace.trace_quality) {
			if (trace.trace_quality === 'known') lineDash = [1, 0];
			else if (trace.trace_quality === 'approximate' || trace.trace_quality === 'questionable') lineDash = [20, 15];
			else if (trace.trace_quality === 'other') lineDash = [20, 15, 0, 15];
			else lineDash = [0.01, 10];
		}

		return new ol.style.Stroke({ color: color, width: width, lineDash: lineDash });
	}

	global.DatasetDetailSpots = { loadSpots: loadSpots };
})(window);
