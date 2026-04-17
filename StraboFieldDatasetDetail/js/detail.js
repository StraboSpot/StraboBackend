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
})();
