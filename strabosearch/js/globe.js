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
			baseLayer: Cesium.ImageryLayer.fromProviderAsync(
				Cesium.TileMapServiceImageryProvider.fromUrl(
					Cesium.buildModuleUrl('Assets/Textures/NaturalEarthII'))),
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

	// ══════════════════════════════════════════════════════════════════
	// fetch + render
	// ══════════════════════════════════════════════════════════════════

	function setStatus(msg) {
		if (statusEl) statusEl.textContent = msg || '';
	}

	function fetchGeo() {
		if (!viewer || !baseDsl) return;
		if (inflight) inflight.abort();
		var ctrl = new AbortController();
		inflight = ctrl;
		var seq = ++fetchSeq;

		var body = {
			criteria: baseDsl.criteria || [],
			subsystems: baseDsl.subsystems,
			geo: { bbox: currentBbox(), include_counter: needCounter }
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

	function renderFeatures(resp) {
		hidePopup();
		dataSource.entities.removeAll();
		var isBins = resp.mode === 'bins';
		dataSource.clustering.enabled = !isBins;

		if (isBins) {
			resp.features.forEach(function (b) {
				var px = 18 + Math.min(30, Math.round(Math.sqrt(b.n) / 3));
				dataSource.entities.add({
					position: Cesium.Cartesian3.fromDegrees(b.lng, b.lat),
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
				var color = SUB_COLORS[f.project_subsystem] || SUB_COLORS.field;
				dataSource.entities.add({
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
			if (active && viewer) fetchGeo();
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
			if (dataSource) {
				dataSource.entities.removeAll();
				if (viewer) viewer.scene.requestRender();
			}
		},

		getCounter: function () { return counter; }
	};

})(window, document);
