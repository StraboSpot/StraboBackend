/**
 * Image-basemap viewing mode. Swaps the OL map into a pixel-space projection,
 * renders a single strabospot.org/mapimage/{id}.jpg as the base, and plots the
 * spots whose `image_basemap` property equals the current image id.
 *
 * Matches the legacy pattern from search/includes/map_search_functions.js:
 *   - projection "map-image" with units:'pixels' and extent:[0,0,W,H]
 *   - ol.source.ImageStatic for the image itself
 *   - spot coordinates consumed verbatim (no Y-flip — mirrors legacy)
 *
 * Breadcrumb stack supports nested basemap-on-basemap drill-down (kept for
 * parity with legacy; the back button unwinds it).
 */
(function (global) {
	'use strict';

	var IMAGE_CDN = 'https://strabospot.org/mapimage/';

	var state = {
		active: false,
		crumbs: [],               // stack of image ids (for nested drill-down)
		savedView: null,          // { center, zoom, resolution } from geographic mode
		imageLayer: null,
		spotsLayer: null,
		backButton: null
	};

	function isActive() { return state.active; }

	function currentImageId() {
		return state.crumbs.length ? state.crumbs[state.crumbs.length - 1] : null;
	}

	function enter(imageId) {
		var detail = global.DatasetDetail;
		if (!detail || !detail.map) return;
		var map = detail.map;

		var dims = lookupImageDimensions(imageId);
		if (!dims) {
			console.warn('No dimensions for image basemap', imageId);
			return;
		}

		// If the strat-section viewer is active, unwind it first so we don't
		// end up with two pixel-space modes layered on top of each other.
		if (global.DatasetDetailStratSection && global.DatasetDetailStratSection.isActive()) {
			global.DatasetDetailStratSection.exit();
		}

		if (!state.active) {
			var view = map.getView();
			state.savedView = {
				center: view.getCenter(),
				zoom: view.getZoom(),
				resolution: view.getResolution()
			};
			hideGeographicLayers(map);
			setPixelModeClass(true);
		} else {
			removeImageModeLayers(map);
		}

		state.crumbs.push(imageId);
		state.active = true;

		var extent = [0, 0, dims.width, dims.height];
		var projection = new ol.proj.Projection({
			code: 'map-image-' + imageId,
			units: 'pixels',
			extent: extent
		});

		var newView = new ol.View({
			projection: projection,
			center: ol.extent.getCenter(extent),
			extent: extent,
			zoom: 2,
			minZoom: 0,
			maxZoom: 8
		});
		map.setView(newView);

		state.imageLayer = new ol.layer.Image({
			source: new ol.source.ImageStatic({
				url: IMAGE_CDN + imageId + '.jpg',
				projection: projection,
				imageExtent: extent
			})
		});
		map.addLayer(state.imageLayer);

		state.spotsLayer = buildImageSpotsLayer(imageId, projection);
		if (state.spotsLayer) map.addLayer(state.spotsLayer);

		newView.fit(extent, { padding: [30, 30, 30, 30] });

		ensureBackButton();
		updateBackButtonLabel();

		if (global.DatasetDetailSidebar) global.DatasetDetailSidebar.close();
	}

	function exit() {
		var detail = global.DatasetDetail;
		if (!detail || !detail.map || !state.active) return;
		var map = detail.map;

		removeImageModeLayers(map);
		state.crumbs = [];
		state.active = false;

		// Restore geographic view.
		restoreGeographicLayers(map);
		if (state.savedView) {
			var view = map.getView();
			if (state.savedView.center) view.setCenter(state.savedView.center);
			if (state.savedView.zoom != null) view.setZoom(state.savedView.zoom);
		}

		setPixelModeClass(false);
		if (state.backButton) state.backButton.style.display = 'none';
	}

	function setPixelModeClass(on) {
		var root = document.getElementById('dataset-detail-root');
		if (root) root.classList.toggle('ds-pixel-mode', !!on);
	}

	function goBackOne() {
		// Pop the current level. If crumbs still has entries, switch to the
		// previous image. Otherwise exit image-basemap mode entirely.
		if (!state.active) return;
		state.crumbs.pop();
		var prev = currentImageId();
		if (prev) {
			// Re-enter with the previous image id; `enter` will push it again
			// so we pop it first to avoid doubling up.
			state.crumbs.pop();
			enter(prev);
		} else {
			exit();
		}
	}

	/* ---------------- internals --------------------------------------- */

	function hideGeographicLayers(map) {
		// Hide the baseLayers group and the geographic spots layer. The
		// layer-switcher control remains but its group will be invisible.
		if (global.DatasetDetail.baseLayers) global.DatasetDetail.baseLayers.setVisible(false);
		if (global.DatasetDetail.spotsLayer) global.DatasetDetail.spotsLayer.setVisible(false);
	}

	function restoreGeographicLayers(map) {
		// Re-swap to the saved geographic view/projection.
		var detail = global.DatasetDetail;
		// Create a fresh geographic view — the old one is still held by detail.view.
		var geoView = new ol.View({
			projection: 'EPSG:3857',
			center: state.savedView ? state.savedView.center : [0, 0],
			zoom: state.savedView ? state.savedView.zoom : 3,
			minZoom: 2
		});
		map.setView(geoView);
		detail.view = geoView;

		if (detail.baseLayers) detail.baseLayers.setVisible(true);
		if (detail.spotsLayer) detail.spotsLayer.setVisible(true);
	}

	function removeImageModeLayers(map) {
		if (state.imageLayer) { map.removeLayer(state.imageLayer); state.imageLayer = null; }
		if (state.spotsLayer) { map.removeLayer(state.spotsLayer); state.spotsLayer = null; }
	}

	function buildImageSpotsLayer(imageId, projection) {
		var detail = global.DatasetDetail;
		if (!detail || !detail.spotsData || !detail.spotsData.features) return null;

		var exploded = [];
		detail.spotsData.features.forEach(function (spot) {
			if (!spot || !spot.properties) return;
			// Match both string and numeric equality — spot.image_basemap is
			// sometimes serialized as a number, sometimes a string.
			if (String(spot.properties.image_basemap) !== String(imageId)) return;
			exploded = exploded.concat(global.DatasetDetailSymbology.explodeSpotOrientations(spot));
		});

		if (exploded.length === 0) return null;

		var source = new ol.source.Vector({
			features: new ol.format.GeoJSON().readFeatures(
				{ type: 'FeatureCollection', features: exploded },
				{ featureProjection: projection, dataProjection: projection }
			)
		});

		var layer = new ol.layer.Vector({
			source: source,
			style: global.DatasetDetailSpots.styleForFeature
		});
		layer.set('name', 'ibSpotsLayer');
		layer.set('title', 'Spots');
		return layer;
	}

	function lookupImageDimensions(imageId) {
		var idx = global.DatasetDetail && global.DatasetDetail.imagesById;
		if (!idx) return null;
		var img = idx[String(imageId)];
		if (!img) return null;
		var w = Number(img.width), h = Number(img.height);
		if (!(w > 0 && h > 0)) return null;
		return { width: w, height: h };
	}

	function ensureBackButton() {
		if (state.backButton) {
			state.backButton.style.display = 'block';
			return;
		}
		var container = document.getElementById('dataset-detail-body');
		if (!container) return;
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'ds-back-to-map';
		btn.textContent = '← Back to map';
		btn.addEventListener('click', exit);
		container.appendChild(btn);
		state.backButton = btn;
	}

	function updateBackButtonLabel() {
		if (!state.backButton) return;
		state.backButton.textContent = state.crumbs.length > 1
			? '← Back (image ' + (state.crumbs.length - 1) + ')'
			: '← Back to map';
	}

	global.DatasetDetailImageBasemap = {
		enter: enter,
		exit: exit,
		goBackOne: goBackOne,
		isActive: isActive,
		currentImageId: currentImageId
	};
})(window);
