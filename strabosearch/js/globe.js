/**
 * File: globe.js
 * Description: StraboSearch Globe view (Globe View M2 —
 *              docs/StraboSearch/GlobeView_Design_Proposal.md §4-5).
 *              Renders the CURRENT search DSL as item hits on a Cesium
 *              globe: zoom-adaptive server bins (count-labeled circles)
 *              or real item points (subsystem-colored, EntityCluster for
 *              residual overlap), popup cards reusing the project-card
 *              routing rules, and the "N of M results have locations"
 *              counter fed back to the results top bar.
 *
 *              Cesium is SELF-HOSTED (/assets/js/cesium/, vendored 1.144)
 *              and lazy-loaded on the first toggle to Globe — list users
 *              never pay for it. The viewer survives view toggles and new
 *              searches (this module owns #ssGlobeWrap, which lives
 *              OUTSIDE the #ssResults region that results.js wipes).
 *
 * @package    StraboSpot Web Site — StraboSearch
 */

(function (window, document) {
	'use strict';

	var CFG = window.STRABO_SEARCH;

	var SUB_COLORS = {
		field:   '#e44c65',
		micro:   '#6a8cc7',
		exp:     '#4caf50',
		samples: '#e0b040'
	};
	var BIN_COLOR = '#e44c65';
	var MOVE_DEBOUNCE_MS = 350;

	var wrap = null;          // #ssGlobeWrap
	var statusEl = null;      // #ssGlobeStatus
	var popupEl = null;       // #ssGlobePopup
	var viewer = null;
	var dataSource = null;    // CustomDataSource for hits
	var handler = null;       // ScreenSpaceEventHandler

	var cesiumLoading = null; // Promise while Cesium.js is being injected
	var baseDsl = null;       // criteria + subsystems of the active search
	var needCounter = false;  // ask the server for the global counter once per DSL
	var counter = null;       // {total, located} for the active DSL
	var fetchSeq = 0;
	var inflight = null;      // AbortController
	var moveTimer = null;
	// Last successful fetch: {bbox (padded, as sent), cell, height}. Lets
	// small pans/zooms inside the padded viewport at the same ladder cell
	// skip the round trip entirely (anti-blink, Jason 2026-08-23 pt3).
	var lastFetch = null;
	var active = false;       // globe view currently visible
	var callbacks = {};       // {onCounter, onOpenList}

	// ══════════════════════════════════════════════════════════════════
	// lazy Cesium loader
	// ══════════════════════════════════════════════════════════════════

	function loadCesium() {
		if (window.Cesium) return Promise.resolve();
		if (cesiumLoading) return cesiumLoading;
		window.CESIUM_BASE_URL = CFG.cesium.base;

		var css = document.createElement('link');
		css.rel = 'stylesheet';
		css.href = CFG.cesium.css;
		document.head.appendChild(css);

		cesiumLoading = new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = CFG.cesium.js;
			s.onload = function () { resolve(); };
			s.onerror = function () {
				cesiumLoading = null;
				reject(new Error('Could not load the globe engine.'));
			};
			document.head.appendChild(s);
		});
		return cesiumLoading;
	}

	function initViewer() {
		if (viewer) return;
		viewer = new Cesium.Viewer('ssGlobeContainer', {
			// Base imagery is added explicitly below: with requestRenderMode
			// on, the fromProviderAsync path resolves AFTER the initial
			// frames stop and nothing wakes the render loop — the globe
			// stays black with zero tile requests.
			baseLayer: false,
			baseLayerPicker: false,
			geocoder: false,
			homeButton: false,
			sceneModePicker: false,
			navigationHelpButton: false,
			animation: false,
			timeline: false,
			fullscreenButton: false,
			vrButton: false,
			infoBox: false,
			selectionIndicator: false,
			requestRenderMode: true,
			maximumRenderTimeChange: Infinity
		});
		viewer.scene.globe.baseColor = Cesium.Color.fromCssColorString('#1c1d26');

		// Two-tier base imagery: self-hosted NaturalEarthII (levels 0-2,
		// ships inside the Cesium build) paints instantly and offline; the
		// site's own tile proxy (tiles.strabospot.org, same mapbox.outdoors
		// set every Leaflet map on the site uses) streams sharp tiles on
		// top down to street level. Satellite/Macrostrat toggles land in M3.
		Cesium.TileMapServiceImageryProvider.fromUrl(
			Cesium.buildModuleUrl('Assets/Textures/NaturalEarthII')
		).then(function (provider) {
			if (!viewer) return;
			viewer.imageryLayers.addImageryProvider(provider, 0);
			viewer.scene.requestRender();
		});
		viewer.imageryLayers.addImageryProvider(new Cesium.UrlTemplateImageryProvider({
			url: CFG.tiles.outdoors,
			maximumLevel: 19,
			credit: new Cesium.Credit('© Mapbox © OpenStreetMap · StraboSpot tiles')
		}));

		dataSource = new Cesium.CustomDataSource('ss-hits');
		// Residual client-side clustering for point mode (§2 core scope).
		dataSource.clustering.enabled = false;
		dataSource.clustering.pixelRange = 42;
		dataSource.clustering.minimumClusterSize = 3;
		dataSource.clustering.clusterEvent.addEventListener(styleCluster);
		viewer.dataSources.add(dataSource);

		viewer.camera.setView({
			destination: Cesium.Cartesian3.fromDegrees(-40.0, 25.0, 22000000)
		});
		viewer.camera.moveEnd.addEventListener(function () {
			if (!active) return;
			clearTimeout(moveTimer);
			moveTimer = setTimeout(function () { fetchGeo(); }, MOVE_DEBOUNCE_MS);
		});

		handler = new Cesium.ScreenSpaceEventHandler(viewer.scene.canvas);
		handler.setInputAction(onLeftClick, Cesium.ScreenSpaceEventType.LEFT_CLICK);

		// Console-debugging handle (harmless in production).
		window.__ssGlobeViewer = viewer;
	}

	// ══════════════════════════════════════════════════════════════════
	// viewport → bbox
	// ══════════════════════════════════════════════════════════════════

	function currentBbox() {
		var rect = viewer.camera.computeViewRectangle(viewer.scene.globe.ellipsoid);
		if (!rect) return [-180, -90, 180, 90];   // horizon/space view
		var d = Cesium.Math.toDegrees;
		var w = d(rect.west), s = d(rect.south), e = d(rect.east), n = d(rect.north);
		// A whole-earth rectangle sometimes reports epsilon-shy bounds.
		if ((e - w) > 359.9) { w = -180; e = 180; }
		return [w, s, e, n];
	}

	function bboxWidth(b) {
		return (b[2] >= b[0]) ? (b[2] - b[0]) : ((180 - b[0]) + (b[2] + 180));
	}

	/** Mirror of the server's power-of-two cell ladder (7.5°/2^k). */
	function ladderCell(width) {
		var raw = (width > 0 ? width : 0.0002) / 48;
		var k = Math.ceil(Math.log(7.5 / raw) / Math.LN2);
		if (k < 0) k = 0;
		if (k > 15) k = 15;
		return 7.5 / Math.pow(2, k);
	}

	/** Pad the viewport 25% per side (skip when crossing the antimeridian —
	 *  padding across the seam isn't worth the corner cases). */
	function padBbox(b) {
		if (b[0] > b[2]) return b;
		var dx = (b[2] - b[0]) * 0.25, dy = (b[3] - b[1]) * 0.25;
		return [Math.max(-180, b[0] - dx), Math.max(-90, b[1] - dy),
		        Math.min(180, b[2] + dx),  Math.min(90, b[3] + dy)];
	}

	function bboxInside(inner, outer) {
		if (inner[0] > inner[2] || outer[0] > outer[2]) return false;
		return inner[0] >= outer[0] && inner[1] >= outer[1] &&
		       inner[2] <= outer[2] && inner[3] <= outer[3];
	}

	// ══════════════════════════════════════════════════════════════════
	// fetch + render
	// ══════════════════════════════════════════════════════════════════

	function setStatus(msg) {
		if (statusEl) statusEl.textContent = msg || '';
	}

	function fetchGeo(force) {
		if (!viewer || !baseDsl) return;

		var view = currentBbox();
		var height = viewer.camera.positionCartographic.height;
		// Skip when the view stayed inside the last padded fetch at the
		// same ladder cell and roughly the same altitude — the data on
		// screen is already exactly what the server would return.
		if (!force && !needCounter && lastFetch && !inflight
			&& ladderCell(bboxWidth(padBbox(view))) === lastFetch.cell
			&& bboxInside(view, lastFetch.bbox)
			&& height > lastFetch.height * 0.85 && height < lastFetch.height * 1.18) {
			return;
		}

		if (inflight) inflight.abort();
		var ctrl = new AbortController();
		inflight = ctrl;
		var seq = ++fetchSeq;

		var padded = padBbox(view);
		var body = {
			criteria: baseDsl.criteria || [],
			subsystems: baseDsl.subsystems,
			geo: { bbox: padded, include_counter: needCounter }
		};

		setStatus('Loading…');
		fetch(CFG.api + '?action=search_geo', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body),
			signal: ctrl.signal
		}).then(function (r) {
			if (!r.ok) {
				return r.json().catch(function () { return {}; }).then(function (j) {
					throw new Error(j.Error || ('Globe query failed (' + r.status + ')'));
				});
			}
			return r.json();
		}).then(function (resp) {
			if (seq !== fetchSeq) return;   // a newer fetch superseded this one
			inflight = null;
			if (resp.counter) {
				needCounter = false;
				counter = resp.counter;
				if (callbacks.onCounter) callbacks.onCounter(counter);
			}
			lastFetch = { bbox: padded, cell: resp.cell_deg, height: height };
			renderFeatures(resp);
		}).catch(function (e) {
			if (e.name === 'AbortError') return;
			inflight = null;
			setStatus(e.message || 'Globe query failed.');
		});
	}

	function fmtCount(n) {
		if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
		if (n >= 1000)    return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
		return String(n);
	}

	/**
	 * Diff-based renderer: entities are keyed by stable ids and updated in
	 * place; only stale ones are removed. The old removeAll()-per-fetch
	 * made every marker vanish and reappear on each camera stop ("blink",
	 * Jason 2026-08-23 pt3). Mode switches still clear fully.
	 */
	var renderedMode = null;

	function renderFeatures(resp) {
		var ents = dataSource.entities;
		var isBins = resp.mode === 'bins';
		if (renderedMode !== resp.mode) {
			hidePopup();
			ents.removeAll();
			renderedMode = resp.mode;
			dataSource.clustering.enabled = !isBins;
		}

		var keep = {};
		if (isBins) {
			resp.features.forEach(function (b) {
				var id = 'b|' + resp.cell_deg + '|' + b.cx + '|' + b.cy;
				if (keep[id]) return;
				keep[id] = true;
				var pos = Cesium.Cartesian3.fromDegrees(b.lng, b.lat);
				var px = 18 + Math.min(30, Math.round(Math.sqrt(b.n) / 3));
				var ent = ents.getById(id);
				if (ent) {
					ent.position = pos;
					ent.point.pixelSize = px;
					ent.label.text = fmtCount(b.n);
					ent.properties.n = b.n;
					ent.properties.lng = b.lng;
					ent.properties.lat = b.lat;
					return;
				}
				ents.add({
					id: id,
					position: pos,
					point: {
						pixelSize: px,
						color: Cesium.Color.fromCssColorString(BIN_COLOR).withAlpha(0.75),
						outlineColor: Cesium.Color.WHITE.withAlpha(0.9),
						outlineWidth: 2,
						disableDepthTestDistance: Number.POSITIVE_INFINITY
					},
					label: {
						text: fmtCount(b.n),
						font: '700 12px "Source Sans Pro", sans-serif',
						fillColor: Cesium.Color.WHITE,
						// Default anchor is LEFT — counts render clipped at
						// the circle's edge without these.
						horizontalOrigin: Cesium.HorizontalOrigin.CENTER,
						verticalOrigin: Cesium.VerticalOrigin.CENTER,
						disableDepthTestDistance: Number.POSITIVE_INFINITY,
						eyeOffset: new Cesium.Cartesian3(0, 0, -1000)
					},
					properties: { kind: 'bin', n: b.n, lng: b.lng, lat: b.lat,
						cell: resp.cell_deg }
				});
			});
			setStatus(fmtCount(resp.in_view_located) + ' located results in view · zoom in for detail');
		} else {
			resp.features.forEach(function (f) {
				var id = 'p|' + f.project_subsystem + '|' + f.project_userpkey + '|'
					+ f.project_id + '|' + f.item_type + '|' + f.item_id + '|'
					+ f.lng.toFixed(6) + ',' + f.lat.toFixed(6);
				if (keep[id]) return;
				keep[id] = true;
				if (ents.getById(id)) return;   // static per item — nothing to update
				var color = SUB_COLORS[f.project_subsystem] || SUB_COLORS.field;
				ents.add({
					id: id,
					position: Cesium.Cartesian3.fromDegrees(f.lng, f.lat),
					point: {
						pixelSize: 9,
						color: Cesium.Color.fromCssColorString(color),
						outlineColor: Cesium.Color.WHITE.withAlpha(0.85),
						outlineWidth: 1.5,
						disableDepthTestDistance: Number.POSITIVE_INFINITY
					},
					properties: { kind: 'point', hit: f }
				});
			});
			setStatus(resp.features.length
				? fmtCount(resp.features.length) + ' located results in view'
				: 'No located results in this view');
		}

		var all = ents.values.slice();
		for (var i = 0; i < all.length; i++) {
			if (!keep[all[i].id]) ents.remove(all[i]);
		}
		viewer.scene.requestRender();
	}

	function styleCluster(entities, cluster) {
		cluster.billboard.show = false;
		cluster.label.show = false;
		cluster.point.show = true;
		cluster.point.pixelSize = 16 + Math.min(18, entities.length);
		cluster.point.color = Cesium.Color.fromCssColorString(BIN_COLOR).withAlpha(0.8);
		cluster.point.outlineColor = Cesium.Color.WHITE.withAlpha(0.9);
		cluster.point.outlineWidth = 2;
		cluster.point.disableDepthTestDistance = Number.POSITIVE_INFINITY;
		cluster.label.show = true;
		cluster.label.text = String(entities.length);
		cluster.label.font = '700 12px "Source Sans Pro", sans-serif';
		cluster.label.fillColor = Cesium.Color.WHITE;
		cluster.label.horizontalOrigin = Cesium.HorizontalOrigin.CENTER;
		cluster.label.verticalOrigin = Cesium.VerticalOrigin.CENTER;
		cluster.label.disableDepthTestDistance = Number.POSITIVE_INFINITY;
		cluster.label.eyeOffset = new Cesium.Cartesian3(0, 0, -1000);
	}

	// ══════════════════════════════════════════════════════════════════
	// interaction: bin zoom + point popup
	// ══════════════════════════════════════════════════════════════════

	function onLeftClick(click) {
		var picked = viewer.scene.pick(click.position);
		hidePopup();
		if (!Cesium.defined(picked)) return;

		// EntityCluster cluster: picked.id is the ARRAY of clustered entities.
		if (Array.isArray(picked.id)) {
			zoomToward(picked.id[0].position.getValue(Cesium.JulianDate.now()), 0.25);
			return;
		}
		var ent = picked.id;
		if (!ent || !ent.properties) return;
		var kind = ent.properties.kind && ent.properties.kind.getValue();

		if (kind === 'bin') {
			var lng = ent.properties.lng.getValue();
			var lat = ent.properties.lat.getValue();
			var cell = ent.properties.cell.getValue();
			// Fly to the bin's cell footprint — the next moveEnd refetches
			// at the finer grid automatically.
			viewer.camera.flyTo({
				destination: Cesium.Rectangle.fromDegrees(
					lng - cell, lat - cell * 0.6, lng + cell, lat + cell * 0.6),
				duration: 0.9
			});
			return;
		}
		if (kind === 'point') {
			showPopup(ent.properties.hit.getValue(), click.position);
		}
	}

	function zoomToward(cartesian, factor) {
		var carto = Cesium.Cartographic.fromCartesian(cartesian);
		var h = viewer.camera.positionCartographic.height * factor;
		viewer.camera.flyTo({
			destination: Cesium.Cartesian3.fromRadians(
				carto.longitude, carto.latitude, Math.max(h, 2500)),
			duration: 0.9
		});
	}

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}

	var ITEM_LABELS = { spot: 'Spot', sample: 'Sample',
		experiment: 'Experiment', micrograph: 'Micrograph' };

	/** Popup card: same fields + routing rules as results.js projectCard. */
	function showPopup(hit, screenPos) {
		popupEl.innerHTML = '';

		var head = el('div', 'ss-gpop-head');
		var icon = el('img', 'ss-gpop-icon');
		icon.src = CFG.icons[hit.project_subsystem] || CFG.icons.field;
		icon.alt = hit.project_subsystem;
		head.appendChild(icon);
		var title = el('div', 'ss-gpop-title', hit.project_name || '(unnamed project)');
		head.appendChild(title);
		if (hit.project_ispublic) head.appendChild(el('span', 'ss-badge-public', 'Public'));
		var close = el('a', 'ss-gpop-close', '×');
		close.href = 'javascript:void(0);';
		close.setAttribute('aria-label', 'Close');
		close.addEventListener('click', hidePopup);
		head.appendChild(close);
		popupEl.appendChild(head);

		var meta = [];
		meta.push(ITEM_LABELS[hit.item_type] || hit.item_type);
		if (hit.item_type === 'sample' && hit.sample_name) meta.push(hit.sample_name);
		if (hit.owner_name) meta.push('Owner: ' + hit.owner_name);
		popupEl.appendChild(el('div', 'ss-gpop-meta', meta.join('  ·  ')));

		var links = el('div', 'ss-gpop-links');
		var open = el('a', null, 'Open project');
		// Field single-dataset routing — identical to projectCard.
		if (hit.project_subsystem === 'field' && hit.dataset_ids && hit.dataset_ids.length === 1) {
			open.href = CFG.fieldDataset + encodeURIComponent(hit.dataset_ids[0]);
		} else {
			open.href = (CFG.landing[hit.project_subsystem] || CFG.landing.field) +
				encodeURIComponent(hit.project_id);
		}
		open.target = '_blank';
		links.appendChild(open);
		var toList = el('a', null, 'View results in list');
		toList.href = 'javascript:void(0);';
		toList.addEventListener('click', function () {
			hidePopup();
			if (callbacks.onOpenList) callbacks.onOpenList();
		});
		links.appendChild(toList);
		popupEl.appendChild(links);

		popupEl.style.display = '';
		// Clamp near the click, inside the wrap.
		var pad = 12;
		var x = Math.min(screenPos.x + 14, wrap.clientWidth - popupEl.offsetWidth - pad);
		var y = Math.min(screenPos.y + 14, wrap.clientHeight - popupEl.offsetHeight - pad);
		popupEl.style.left = Math.max(pad, x) + 'px';
		popupEl.style.top = Math.max(pad, y) + 'px';
	}

	function hidePopup() {
		if (popupEl) { popupEl.style.display = 'none'; popupEl.innerHTML = ''; }
	}

	// ══════════════════════════════════════════════════════════════════
	// public API (results.js drives this)
	// ══════════════════════════════════════════════════════════════════

	window.SSGlobe = {

		init: function (cbs) {
			callbacks = cbs || {};
			wrap = document.getElementById('ssGlobeWrap');
			statusEl = document.getElementById('ssGlobeStatus');
			popupEl = document.getElementById('ssGlobePopup');
		},

		/** New search DSL (criteria + subsystems). Refetches if visible. */
		setQuery: function (dsl) {
			baseDsl = dsl ? { criteria: dsl.criteria, subsystems: dsl.subsystems } : null;
			needCounter = true;
			counter = null;
			lastFetch = null;   // old data no longer answers the new DSL
			if (active && viewer) fetchGeo(true);
		},

		/** Show the globe pane; boots Cesium on first use. */
		show: function () {
			active = true;
			wrap.style.display = '';
			setStatus(window.Cesium ? '' : 'Loading globe…');
			loadCesium().then(function () {
				if (!active) return;
				initViewer();
				viewer.resize();
				if (baseDsl) fetchGeo();
			}).catch(function (e) {
				setStatus(e.message || 'Could not load the globe engine.');
			});
		},

		hide: function () {
			active = false;
			hidePopup();
			if (inflight) { inflight.abort(); inflight = null; }
			clearTimeout(moveTimer);
			if (wrap) wrap.style.display = 'none';
		},

		/** Full reset (criteria cleared — results region went quiet). */
		clear: function () {
			this.hide();
			baseDsl = null;
			counter = null;
			lastFetch = null;
			renderedMode = null;
			if (dataSource) {
				dataSource.entities.removeAll();
				if (viewer) viewer.scene.requestRender();
			}
		},

		getCounter: function () { return counter; }
	};

})(window, document);
