/**
 * Inline strat section viewing. Mirrors the legacy pattern from
 * search/includes/strat.js + map_search_functions.js (switchToStratSection):
 *
 *   - OL map swaps to a pixel-space projection ("strat-section-{id}").
 *   - Child spots (those whose strat_section_id matches the parent's
 *     sed.strat_section.strat_section_id) render in strat pixel coords.
 *   - Axes (meter tick marks on Y, profile/grain-size labels on X) drawn on
 *     a map postrender hook using the 2D canvas context.
 *
 * Coordinate convention matches legacy: yMultiplier = 20 (1m = 20px),
 * xInterval = 10 (column width), X axis grouped by profile type
 * (clastic, carbonate, mixed_clastic, basic_lithologies).
 */
(function (global) {
	'use strict';

	var Y_MULTIPLIER = 20;
	var X_INTERVAL   = 10;

	var GRAIN_SIZES = {
		clastic: [
			'clay', 'silt', 'sand- very fine', 'sand- fine lower', 'sand- fine upper',
			'sand- medium lower', 'sand- medium upper', 'sand- coarse lower',
			'sand- coarse upper', 'sand- very coarse', 'granule', 'pebble', 'cobble', 'boulder'
		],
		carbonate: [
			'mudstone', 'wackestone', 'packstone', 'grainstone', 'floatstone',
			'rudstone', 'boundstone', 'framestone', 'bindstone', 'bafflestone',
			'cementstone', 'crystalline'
		],
		basic_lithologies: [
			'other', 'coal', 'mudstone', 'sandstone', 'conglomerate/breccia', 'limestone/dolostone'
		]
	};

	var state = {
		active: false,
		stratSectionId: null,
		stratSectionSpot: null,
		savedView: null,
		stratLayer: null,
		postrenderKey: null,
		backButton: null
	};

	function isActive() { return state.active; }

	function enter(stratSectionId) {
		var detail = global.DatasetDetail;
		if (!detail || !detail.map) return;
		var map = detail.map;

		var parent = findParentSpot(stratSectionId);
		if (!parent) {
			console.warn('No strat section parent spot for id', stratSectionId);
			return;
		}

		if (!state.active) {
			var view = map.getView();
			state.savedView = {
				center: view.getCenter(),
				zoom: view.getZoom()
			};
			hideGeographicLayers(map);
		} else {
			teardownStratLayers(map);
		}

		state.active = true;
		state.stratSectionId = stratSectionId;
		state.stratSectionSpot = parent;

		// Match legacy fixed projection extent. Spots may extend beyond
		// this; OL doesn't clip, but the View's extent bounds the pan area.
		var projExtent = [0, 0, 2000, 3000];
		var projection = new ol.proj.Projection({
			code: 'strat-section-' + stratSectionId,
			units: 'pixels',
			extent: projExtent
		});

		var stratView = new ol.View({
			projection: projection,
			center: [500, 1500],
			zoom: 3,
			minZoom: 0,
			maxZoom: 8
		});
		map.setView(stratView);

		state.stratLayer = buildStratLayer(stratSectionId, projection);
		if (state.stratLayer) map.addLayer(state.stratLayer);

		// Fit to the spots' extent if we have any; otherwise use projExtent.
		var fitExtent = state.stratLayer && state.stratLayer.getSource().getExtent();
		if (fitExtent && isFinite(fitExtent[0]) && isFinite(fitExtent[2])) {
			// Expand slightly to leave room for axes.
			stratView.fit(padExtent(fitExtent, 40), { padding: [40, 40, 60, 80] });
		} else {
			stratView.fit(projExtent, { padding: [40, 40, 40, 40] });
		}

		state.postrenderKey = map.on('postrender', function (evt) {
			if (!state.active) return;
			drawAxes(evt, parent);
		});

		ensureBackButton();
		if (global.DatasetDetailSidebar) global.DatasetDetailSidebar.close();
	}

	function exit() {
		var detail = global.DatasetDetail;
		if (!detail || !detail.map || !state.active) return;
		var map = detail.map;

		teardownStratLayers(map);

		if (state.postrenderKey) {
			ol.Observable.unByKey(state.postrenderKey);
			state.postrenderKey = null;
		}

		state.active = false;
		state.stratSectionId = null;
		state.stratSectionSpot = null;

		restoreGeographicLayers(map);

		if (state.savedView) {
			var geoView = new ol.View({
				projection: 'EPSG:3857',
				center: state.savedView.center,
				zoom: state.savedView.zoom,
				minZoom: 2
			});
			map.setView(geoView);
			detail.view = geoView;
		}

		if (state.backButton) state.backButton.style.display = 'none';

		// Trigger a redraw so the axes disappear.
		map.render();
	}

	/* ---------------- internals --------------------------------------- */

	function findParentSpot(stratSectionId) {
		var data = global.DatasetDetail && global.DatasetDetail.spotsData;
		if (!data || !data.features) return null;
		for (var i = 0; i < data.features.length; i++) {
			var f = data.features[i];
			var ss = f.properties && f.properties.sed && f.properties.sed.strat_section;
			if (ss && String(ss.strat_section_id) === String(stratSectionId)) return f;
		}
		return null;
	}

	function hideGeographicLayers(map) {
		var detail = global.DatasetDetail;
		if (detail.baseLayers) detail.baseLayers.setVisible(false);
		if (detail.spotsLayer) detail.spotsLayer.setVisible(false);
	}

	function restoreGeographicLayers(map) {
		var detail = global.DatasetDetail;
		if (detail.baseLayers) detail.baseLayers.setVisible(true);
		if (detail.spotsLayer) detail.spotsLayer.setVisible(true);
	}

	function teardownStratLayers(map) {
		if (state.stratLayer) {
			map.removeLayer(state.stratLayer);
			state.stratLayer = null;
		}
	}

	function buildStratLayer(stratSectionId, projection) {
		var detail = global.DatasetDetail;
		if (!detail || !detail.spotsData) return null;

		var features = [];
		detail.spotsData.features.forEach(function (spot) {
			if (!spot || !spot.properties) return;
			if (String(spot.properties.strat_section_id) !== String(stratSectionId)) return;
			features = features.concat(global.DatasetDetailSymbology.explodeSpotOrientations(spot));
		});

		if (features.length === 0) return null;

		var source = new ol.source.Vector({
			features: new ol.format.GeoJSON().readFeatures(
				{ type: 'FeatureCollection', features: features },
				{ featureProjection: projection, dataProjection: projection }
			)
		});

		var layer = new ol.layer.Vector({
			source: source,
			style: global.DatasetDetailSpots.styleForFeature
		});
		layer.set('name', 'stratLayer');
		layer.set('title', 'Strat Section');
		return layer;
	}

	function padExtent(e, m) { return [e[0] - m, e[1] - m, e[2] + m, e[3] + m]; }

	/* ---------------- axes rendering --------------------------------- */

	function drawAxes(evt, parentSpot) {
		var ctx = evt.context;
		if (!ctx) return;
		var map = evt.target || global.DatasetDetail.map;
		var pixelRatio = (evt.frameState && evt.frameState.pixelRatio) || 1;
		var stratMeta = parentSpot.properties.sed && parentSpot.properties.sed.strat_section;
		if (!stratMeta) return;

		var sectionHeight = getSectionHeight();
		var yAxisHeight = Math.max(sectionHeight + 40, 200);

		ctx.save();
		ctx.lineWidth = 1;
		ctx.font = (12 * pixelRatio) + 'px Arial';
		ctx.strokeStyle = '#222';
		ctx.fillStyle = '#222';
		ctx.textBaseline = 'middle';

		// --- Y axis (height in meters) ------------------------------------
		ctx.beginPath();
		var p0 = toPixel(map, [0, 0], pixelRatio);
		ctx.moveTo(p0.x, p0.y);
		var pTop = toPixel(map, [0, yAxisHeight], pixelRatio);
		ctx.lineTo(pTop.x, pTop.y);

		var zoom = map.getView().getZoom();
		var tickCount = Math.floor(yAxisHeight / Y_MULTIPLIER) + 1;
		for (var i = 0; i <= tickCount; i++) {
			var y = i * Y_MULTIPLIER;
			var showLabel = (
				i === 0 ||
				zoom >= 5 ||
				(zoom < 5 && zoom > 2 && i % 5 === 0) ||
				(zoom <= 2 && i % 10 === 0)
			);
			if (!showLabel) continue;

			var tickP = toPixel(map, [-5, y], pixelRatio);
			ctx.moveTo(tickP.x, tickP.y);
			var innerP = toPixel(map, [0, y], pixelRatio);
			ctx.lineTo(innerP.x, innerP.y);

			ctx.textAlign = 'right';
			var labelP = toPixel(map, [-10, y], pixelRatio);
			var label = i === 0 && stratMeta.column_y_axis_units
				? '0 ' + stratMeta.column_y_axis_units
				: String(i);
			ctx.fillText(label, labelP.x, labelP.y);
		}
		ctx.stroke();

		// --- X axis (profile labels) --------------------------------------
		var profile = stratMeta.column_profile || 'clastic';
		var y = 0;
		if (profile === 'clastic') {
			drawXAxis(ctx, map, pixelRatio, yAxisHeight, GRAIN_SIZES.clastic, 1, y, '#000', 'clastic');
		} else if (profile === 'carbonate') {
			drawXAxis(ctx, map, pixelRatio, yAxisHeight, GRAIN_SIZES.carbonate, 2.33, y, '#0044aa', 'carbonate');
		} else if (profile === 'mixed_clastic') {
			drawXAxis(ctx, map, pixelRatio, yAxisHeight, GRAIN_SIZES.clastic, 1, 0, '#000', 'clastic');
			drawXAxis(ctx, map, pixelRatio, yAxisHeight, GRAIN_SIZES.carbonate, 2.33, -90, '#0044aa', 'carbonate');
		} else if (profile === 'basic_lithologies') {
			drawXAxis(ctx, map, pixelRatio, yAxisHeight, GRAIN_SIZES.basic_lithologies, 2, y, '#000', 'basic lithologies');
		}

		ctx.restore();
	}

	function drawXAxis(ctx, map, pixelRatio, yAxisHeight, labels, spacing, yOffset, color, profileLabel) {
		var y = yOffset;
		ctx.save();
		ctx.strokeStyle = color;
		ctx.fillStyle = color;

		// Horizontal baseline at y.
		ctx.beginPath();
		var baseLeft = toPixel(map, [-10, y], pixelRatio);
		var xAxisLength = (labels.length + spacing) * X_INTERVAL;
		var baseRight = toPixel(map, [xAxisLength, y], pixelRatio);
		ctx.moveTo(baseLeft.x, baseLeft.y);
		ctx.lineTo(baseRight.x, baseRight.y);
		ctx.stroke();

		// Profile group label (vertical, at left).
		ctx.save();
		var groupP = toPixel(map, [-2, y - 2], pixelRatio);
		ctx.translate(groupP.x, groupP.y);
		ctx.rotate(-Math.PI / 2);
		ctx.textAlign = 'right';
		ctx.fillText(profileLabel, 0, 0);
		ctx.restore();

		// Column labels (rotated 45°).
		ctx.textAlign = 'left';
		ctx.font = (10 * pixelRatio) + 'px Arial';
		for (var i = 0; i < labels.length; i++) {
			var colX = spacing * X_INTERVAL + i * X_INTERVAL;

			// Tick on the baseline.
			ctx.beginPath();
			var t1 = toPixel(map, [colX, y], pixelRatio);
			var t2 = toPixel(map, [colX, y + 3], pixelRatio);
			ctx.moveTo(t1.x, t1.y);
			ctx.lineTo(t2.x, t2.y);
			ctx.stroke();

			ctx.save();
			var labelP = toPixel(map, [colX, y - 4], pixelRatio);
			ctx.translate(labelP.x, labelP.y);
			ctx.rotate(-Math.PI / 3);
			ctx.fillText(labels[i], 0, 0);
			ctx.restore();
		}
		ctx.restore();
	}

	function toPixel(map, coord, pixelRatio) {
		var pix = map.getPixelFromCoordinate(coord);
		if (!pix) return { x: 0, y: 0 };
		return { x: pix[0] * pixelRatio, y: pix[1] * pixelRatio };
	}

	function getSectionHeight() {
		var layer = state.stratLayer;
		if (!layer) return 0;
		var extent = layer.getSource().getExtent();
		if (!extent || !isFinite(extent[3])) return 0;
		return extent[3];
	}

	/* ---------------- back button ------------------------------------- */

	function ensureBackButton() {
		if (state.backButton) {
			state.backButton.style.display = 'block';
			state.backButton.textContent = '← Back to map';
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

	global.DatasetDetailStratSection = {
		enter: enter,
		exit: exit,
		isActive: isActive
	};
})(window);
