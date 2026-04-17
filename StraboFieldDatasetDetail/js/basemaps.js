/**
 * Base layer definitions for the StraboField dataset landing page.
 * Mirrors the basemap set from the legacy /search page.
 */
(function (global) {
	'use strict';

	function buildBaseLayers() {
		return new ol.layer.Group({
			title: 'Base maps',
			layers: [
				new ol.layer.Tile({
					title: 'OSM',
					type: 'base',
					visible: false,
					source: new ol.source.OSM()
				}),
				new ol.layer.Group({
					title: 'MacroStrat',
					type: 'base',
					combine: true,
					visible: false,
					layers: [
						new ol.layer.Tile({
							source: new ol.source.XYZ({
								url: 'https://tiles.strabospot.org/v5/mapbox.satellite/{z}/{x}/{y}.png'
							})
						}),
						new ol.layer.Tile({
							source: new ol.source.XYZ({
								url: 'https://tiles.strabospot.org/v5/macrostrat/{z}/{x}/{y}.png'
							})
						})
					]
				}),
				new ol.layer.Tile({
					title: 'Mapbox Satellite',
					type: 'base',
					visible: false,
					source: new ol.source.XYZ({
						url: 'https://tiles.strabospot.org/v5/mapbox.satellite/{z}/{x}/{y}.png'
					})
				}),
				new ol.layer.Tile({
					title: 'Mapbox Outdoors',
					type: 'base',
					visible: true,
					source: new ol.source.XYZ({
						url: 'https://tiles.strabospot.org/v5/mapbox.outdoors/{z}/{x}/{y}.png'
					})
				})
			]
		});
	}

	global.DatasetDetailBasemaps = { buildBaseLayers: buildBaseLayers };
})(window);
