/**
 * StraboField Dataset Detail — main bootstrap.
 * Phase 1: build OL 10 map with basemaps + layer switcher.
 */
(function () {
	'use strict';

	var cfg = window.DATASET_DETAIL_CONFIG || {};

	var baseLayers = DatasetDetailBasemaps.buildBaseLayers();

	var mapView = new ol.View({
		projection: 'EPSG:3857',
		center: [-9000000, 4600000],
		zoom: 3,
		minZoom: 2
	});

	var controlDefaults = (ol.control.defaults && typeof ol.control.defaults.defaults === 'function')
		? ol.control.defaults.defaults()
		: ol.control.defaults();

	var map = new ol.Map({
		target: 'map',
		view: mapView,
		controls: controlDefaults,
		layers: [baseLayers]
	});

	var LayerSwitcherCtor = (typeof LayerSwitcher === 'function')
		? LayerSwitcher
		: (window.LayerSwitcher && window.LayerSwitcher.default) || null;

	if (LayerSwitcherCtor) {
		map.addControl(new LayerSwitcherCtor({
			tipLabel: 'Layers',
			groupSelectStyle: 'children'
		}));
	}

	// Expose for later phases (spots, image basemap, strat section).
	window.DatasetDetail = {
		map: map,
		view: mapView,
		baseLayers: baseLayers,
		config: cfg
	};

	if (cfg.dataset_id && window.DatasetDetailSpots) {
		DatasetDetailSpots.loadSpots(map, cfg.dataset_id, function () {
			// ?sample_id= arrival: the server resolved the sample to its
			// containing spot — select it once the layer exists.
			if (cfg.highlight_spot_id) {
				DatasetDetailSpots.highlightSpot(map, cfg.highlight_spot_id);
			}
		});
	}

	function isSpotsLayer(layer) {
		if (!layer) return false;
		var name = layer.get && layer.get('name');
		return name === 'spotsLayer' || name === 'ibSpotsLayer' || name === 'stratLayer';
	}

	// Open the metadata sidebar when a spot is clicked. Hit-test only the
	// spots layer(s) to avoid swallowing basemap clicks.
	map.on('singleclick', function (evt) {
		var hit = null;
		map.forEachFeatureAtPixel(evt.pixel, function (feature, layer) {
			if (isSpotsLayer(layer)) {
				hit = feature;
				return true;
			}
		});
		if (!hit) return;
		var spotId = hit.get('id');
		var spot = window.DatasetDetail.spotsById && window.DatasetDetail.spotsById[spotId];
		if (spot && window.DatasetDetailSidebar) {
			window.DatasetDetailSidebar.open(spot);
		}
	});

	// Pointer cursor over clickable spots.
	map.on('pointermove', function (evt) {
		if (evt.dragging) return;
		var pixel = map.getEventPixel(evt.originalEvent);
		var overSpot = map.hasFeatureAtPixel(pixel, { layerFilter: isSpotsLayer });
		map.getTargetElement().style.cursor = overSpot ? 'pointer' : '';
	});
})();
