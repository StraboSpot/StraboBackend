/**
 * File: globe.js
 * Description: StraboSearch Globe view (D7 project-level pivot,
 *              docs/StraboSearch/GlobeView_Design_Proposal.md §9).
 *              Renders ONE MARKER PER MATCHING PROJECT on a Cesium
 *              globe: subsystem-colored dots + EntityCluster (the only
 *              render path that never blinked in Jason's Firefox during
 *              the M2 item-hit era, §8), fetched ONCE per search so
 *              camera moves make zero writes and zero requests. Click a
 *              marker for a popup card with list-row parity (icon, name,
 *              Public badge, owner, updated, data range, match counts)
 *              plus View Project / View results in list. The "N of M
 *              matching projects have locations" counter feeds the
 *              results top bar. With the Macrostrat overlay on (M5,
 *              §12) a click on empty ground runs Macrostrat's point
 *              query straight from the browser (CORS-open, CC-BY 4.0)
 *              and shows the mapped unit (name, age, lithology, source)
 *              in the same card, pinned at the click.
 *
 *              Cesium is SELF-HOSTED (/assets/js/cesium/, vendored 1.144)
 *              and lazy-loaded on the first toggle to Globe; list users
 *              never pay for it. The viewer survives view toggles and new
 *              searches (this module owns #ssGlobeWrap, which lives
 *              OUTSIDE the #ssResults region that results.js wipes).
 *
 * @package    StraboSpot Web Site (StraboSearch)
 */

(function (window, document) {
	'use strict';

	var CFG = window.STRABO_SEARCH;

	// Bumped on every globe change; logged on load so a stale-tab build is
	// diagnosable in seconds (searches don't reload the page, so an open
	// tab keeps running whatever JS it booted with).
	var BUILD = 'm5-geo-click-r1';
	try { console.log('[SSGlobe] build ' + BUILD); } catch (e) { /* ignore */ }

	var SUB_COLORS = {
		field:   '#e44c65',
		micro:   '#6a8cc7',
		exp:     '#4caf50',
		samples: '#e0b040'
	};
	var CLUSTER_COLOR = '#e44c65';

	var wrap = null;          // #ssGlobeWrap
	var statusEl = null;      // #ssGlobeStatus
	var popupEl = null;       // #ssGlobePopup
	var viewer = null;
	var dataSource = null;    // CustomDataSource for project markers
	var handler = null;       // ScreenSpaceEventHandler

	var cesiumLoading = null; // Promise while Cesium.js is being injected
	var baseDsl = null;       // criteria + subsystems of the active search
	var needFetch = false;    // a new DSL arrived while the globe was hidden
	var counter = null;       // {total, located} projects for the active DSL
	var fetchSeq = 0;
	var inflight = null;      // AbortController
	var active = false;       // globe view currently visible
	var callbacks = {};       // {onCounter, onOpenList}

	var layerOutdoors = null;   // streamed terrain basemap (default on)
	var layerSatellite = null;  // lazy: created on first Satellite pick
	var layerMacrostrat = null; // lazy: created on first overlay enable

	// Macrostrat geology click (M5): the click pin lives in its OWN data
	// source so search re-renders (which rebuild the marker source) never
	// touch it; the lookup carries a sequence + abort so a fresh click,
	// a closed card or a re-render can never be answered late.
	var pinSource = null;
	var geoSeq = 0;
	var geoInflight = null;   // AbortController
	var geoTimer = null;
	var GEO_TIMEOUT_MS = 6000;
	var SCALE_RANK = { large: 0, medium: 1, small: 2, tiny: 3 };

	// Layer choices survive reloads (per-browser convenience only; wrapped
	// in try/catch because storage can be absent or throw).
	var LAYER_PREFS_KEY = 'ssGlobeLayerPrefs';
	var layerPrefs = { basemap: 'terrain', macrostrat: false, opacity: 0.6 };
	try {
		var lp = JSON.parse(window.localStorage.getItem(LAYER_PREFS_KEY) || '{}');
		if (lp.basemap === 'satellite') layerPrefs.basemap = 'satellite';
		if (lp.macrostrat === true) layerPrefs.macrostrat = true;
		if (typeof lp.opacity === 'number' && lp.opacity >= 0.1 && lp.opacity <= 1) {
			layerPrefs.opacity = lp.opacity;
		}
	} catch (e) { /* storage unavailable: defaults */ }

	function saveLayerPrefs() {
		try { window.localStorage.setItem(LAYER_PREFS_KEY, JSON.stringify(layerPrefs)); }
		catch (e) { /* ignore */ }
	}

	/** Browse mode (M3): an empty-criteria DSL is the /globe front-door
	 *  contract; the list view stays gated, so popups drop their
	 *  "View results in list" links. */
	function isBrowse() {
		return !!(baseDsl && (!baseDsl.criteria || baseDsl.criteria.length === 0));
	}

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

	/**
	 * Destroy and recreate the marker data source. Called per search
	 * render, NOT per camera move: EntityCluster's incremental
	 * bookkeeping does not survive a wholesale entity replacement (a
	 * field search followed by a micro search left the old 315 point
	 * primitives in the cluster's internal collection plus orphaned
	 * cluster badges that ignored zoom and clicks, Jason 2026-08-23).
	 * A fresh source rebuilds clustering from scratch by construction;
	 * the cost is negligible once per search.
	 */
	function rebuildDataSource() {
		if (dataSource) {
			viewer.dataSources.remove(dataSource, true);   // true = destroy
		}
		dataSource = new Cesium.CustomDataSource('ss-projects');
		dataSource.clustering.enabled = true;
		dataSource.clustering.pixelRange = 42;
		dataSource.clustering.minimumClusterSize = 3;
		dataSource.clustering.clusterEvent.addEventListener(styleCluster);
		// Keep the marker source at index 0: the pin source (M5) is added
		// once, so every re-render would otherwise append the markers
		// above it (the FF harness probes read dataSources.get(0)).
		// DataSourceCollection.add pushes ASYNCHRONOUSLY (inside a then),
		// so the reorder must wait for it; a synchronous lowerToBottom
		// splices whatever sits last (the pin source) and duplicates the
		// markers, which stops Cesium rendering outright.
		var ds = dataSource;
		viewer.dataSources.add(ds).then(function () {
			if (viewer && viewer.dataSources.contains(ds)) viewer.dataSources.lowerToBottom(ds);
		});
	}

	function initViewer() {
		if (viewer) return;
		viewer = new Cesium.Viewer('ssGlobeContainer', {
			// Base imagery is added explicitly below: with requestRenderMode
			// on, the fromProviderAsync path resolves AFTER the initial
			// frames stop and nothing wakes the render loop: the globe
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
		layerOutdoors = viewer.imageryLayers.addImageryProvider(new Cesium.UrlTemplateImageryProvider({
			url: CFG.tiles.outdoors,
			maximumLevel: 19,
			credit: new Cesium.Credit('© Mapbox © OpenStreetMap · StraboSpot tiles')
		}));
		applyLayerPrefs();

		rebuildDataSource();
		pinSource = new Cesium.CustomDataSource('ss-geopin');
		viewer.dataSources.add(pinSource);

		viewer.camera.setView({
			destination: Cesium.Cartesian3.fromDegrees(-40.0, 25.0, 22000000)
		});
		// Keep zoom-out inside the validated envelope: beyond ~30,000 km
		// the globe is a speck and marker rendering was only verified up
		// to here (FF harness sweep, 2026-08-23 post-pivot).
		viewer.scene.screenSpaceCameraController.maximumZoomDistance = 30000000;

		handler = new Cesium.ScreenSpaceEventHandler(viewer.scene.canvas);
		handler.setInputAction(onLeftClick, Cesium.ScreenSpaceEventType.LEFT_CLICK);

		viewer.scene.preRender.addEventListener(updateHorizonScaling);

		// Console-debugging handle (harmless in production).
		window.__ssGlobeViewer = viewer;
	}

	/**
	 * Horizon fade, evaluated ON THE GPU (§9, carried over from M2 pt8).
	 * Markers depth-test honestly (see the renderFeatures marker comment
	 * for why depth-test-off is unusable on points), which already hides
	 * the far side; this scalar additionally fades rim markers out just
	 * past the horizon distance sqrt(h² + 2Rh) instead of letting them
	 * pop at the limb. The comparison runs per frame in the shader with
	 * ZERO buffer writes (the M2 bisection cleared scaleByDistance of
	 * any blink involvement). The scalar is only reassigned when camera
	 * height leaves an 8% band (and on marker creation); clusters
	 * restyle themselves on camera change and pick up a fresh scalar in
	 * styleCluster.
	 */
	var lastScaleHeight = 0;

	function horizonScalar() {
		var h = viewer.camera.positionCartographic.height;
		var R = 6371000.0;
		var dh = Math.sqrt(h * h + 2.0 * R * h);
		// 1.05 slack lets rim markers fade just past the geometric
		// horizon instead of popping.
		return new Cesium.NearFarScalar(dh, 1.0, dh * 1.05, 0.0);
	}

	function updateHorizonScaling(force) {
		if (!viewer || !dataSource) return;
		var h = viewer.camera.positionCartographic.height;
		if (force !== true && lastScaleHeight > 0
			&& h > lastScaleHeight * 0.92 && h < lastScaleHeight * 1.08) {
			return;
		}
		lastScaleHeight = h;
		var s = horizonScalar();
		var ents = dataSource.entities;
		ents.suspendEvents();   // coalesce 1 event per pass, not per marker
		var vals = ents.values;
		for (var i = 0; i < vals.length; i++) {
			if (vals[i].point) vals[i].point.scaleByDistance = s;
		}
		ents.resumeEvents();
	}

	// ══════════════════════════════════════════════════════════════════
	// fetch + render (once per search; camera moves never refetch)
	// ══════════════════════════════════════════════════════════════════

	// ------------------------------------------------------------------
	// Layers panel (M3): basemap swap + Macrostrat geology overlay
	// ------------------------------------------------------------------

	function ensureSatellite() {
		if (layerSatellite) return;
		layerSatellite = viewer.imageryLayers.addImageryProvider(new Cesium.UrlTemplateImageryProvider({
			url: CFG.tiles.satellite,
			maximumLevel: 19,
			credit: new Cesium.Credit('© Mapbox © Maxar · StraboSpot tiles')
		}));
		layerSatellite.show = false;
		keepMacrostratOnTop();
	}

	function ensureMacrostrat() {
		if (layerMacrostrat) return;
		layerMacrostrat = viewer.imageryLayers.addImageryProvider(new Cesium.UrlTemplateImageryProvider({
			url: CFG.tiles.macrostrat,
			// The site proxy composes 256px tiles from Macrostrat carto 512s;
			// real cartography runs out around z14, so let Cesium upsample
			// past it instead of asking the proxy for near-blank deep tiles.
			maximumLevel: 14,
			credit: new Cesium.Credit('Geology © Macrostrat')
		}));
		layerMacrostrat.show = false;
		layerMacrostrat.alpha = layerPrefs.opacity;
	}

	/** A lazily-added basemap lands ABOVE an existing overlay in Cesium's
	 *  layer stack; the geology must stay on top of whichever basemap is
	 *  showing. */
	function keepMacrostratOnTop() {
		if (layerMacrostrat) viewer.imageryLayers.raiseToTop(layerMacrostrat);
	}

	function applyLayerPrefs() {
		if (!viewer) return;
		if (layerPrefs.basemap === 'satellite') ensureSatellite();
		if (layerPrefs.macrostrat) ensureMacrostrat();
		if (layerOutdoors) layerOutdoors.show = (layerPrefs.basemap !== 'satellite');
		if (layerSatellite) layerSatellite.show = (layerPrefs.basemap === 'satellite');
		if (layerMacrostrat) layerMacrostrat.show = !!layerPrefs.macrostrat;
		keepMacrostratOnTop();
		syncLayersUI();
		viewer.scene.requestRender();
	}

	/** Reflect prefs into the panel controls (checked state + slider). */
	function syncLayersUI() {
		var t = document.getElementById('ssBaseTerrain');
		var sat = document.getElementById('ssBaseSatellite');
		var chk = document.getElementById('ssMacrostratChk');
		var op = document.getElementById('ssMacrostratOpacity');
		if (t) t.checked = (layerPrefs.basemap !== 'satellite');
		if (sat) sat.checked = (layerPrefs.basemap === 'satellite');
		if (chk) chk.checked = !!layerPrefs.macrostrat;
		if (op) {
			op.value = String(Math.round(layerPrefs.opacity * 100));
			op.disabled = !layerPrefs.macrostrat;
		}
		// M5 affordances: the "click the map" hint shows only while the
		// overlay is on, and the canvas cursor turns crosshair (CSS).
		var hint = document.getElementById('ssMacrostratHint');
		if (hint) hint.style.display = layerPrefs.macrostrat ? '' : 'none';
		if (wrap) {
			if (layerPrefs.macrostrat) wrap.classList.add('ss-geo-on');
			else wrap.classList.remove('ss-geo-on');
		}
		// Turning the overlay off retires any open geology card + pin.
		if (!layerPrefs.macrostrat && popupEl && popupEl.classList.contains('ss-gpop-geo')) hidePopup();
	}

	function wireLayersPanel() {
		var btn = document.getElementById('ssLayersBtn');
		var panel = document.getElementById('ssLayersPanel');
		if (!btn || !panel) return;

		btn.addEventListener('click', function (ev) {
			ev.stopPropagation();
			var open = panel.style.display !== 'none';
			panel.style.display = open ? 'none' : '';
			btn.setAttribute('aria-expanded', open ? 'false' : 'true');
		});
		panel.addEventListener('click', function (ev) { ev.stopPropagation(); });
		document.addEventListener('click', function () {
			if (panel.style.display !== 'none') {
				panel.style.display = 'none';
				btn.setAttribute('aria-expanded', 'false');
			}
		});

		[document.getElementById('ssBaseTerrain'),
		 document.getElementById('ssBaseSatellite')].forEach(function (r) {
			if (!r) return;
			r.addEventListener('change', function () {
				if (!r.checked) return;
				layerPrefs.basemap = (r.value === 'satellite') ? 'satellite' : 'terrain';
				saveLayerPrefs();
				applyLayerPrefs();
			});
		});

		var chk = document.getElementById('ssMacrostratChk');
		if (chk) chk.addEventListener('change', function () {
			layerPrefs.macrostrat = !!chk.checked;
			saveLayerPrefs();
			if (viewer) applyLayerPrefs(); else syncLayersUI();
		});

		var op = document.getElementById('ssMacrostratOpacity');
		if (op) op.addEventListener('input', function () {
			var v = Math.max(10, Math.min(100, parseInt(op.value, 10) || 60)) / 100;
			layerPrefs.opacity = v;
			saveLayerPrefs();
			if (layerMacrostrat) {
				layerMacrostrat.alpha = v;
				viewer.scene.requestRender();
			}
		});

		syncLayersUI();
	}

	function setStatus(msg) {
		if (statusEl) statusEl.textContent = msg || '';
	}

	function fetchGeo() {
		if (!viewer || !baseDsl) return;
		needFetch = false;

		if (inflight) inflight.abort();
		var ctrl = new AbortController();
		inflight = ctrl;
		var seq = ++fetchSeq;

		var body = {
			criteria: baseDsl.criteria || [],
			subsystems: baseDsl.subsystems,
			geo: { include_counter: true }
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
				counter = resp.counter;
				if (callbacks.onCounter) callbacks.onCounter(counter);
			}
			renderFeatures(resp);
		}).catch(function (e) {
			if (e.name === 'AbortError') return;
			inflight = null;
			needFetch = true;   // retry on the next show/setQuery
			setStatus(e.message || 'Globe query failed.');
		});
	}

	function fmtCount(n) {
		if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
		if (n >= 1000)    return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
		return String(n);
	}

	/**
	 * Full re-render per search (searches legitimately replace the
	 * content; there is no per-camera refetch left to diff against).
	 * One render path: subsystem-colored dots + EntityCluster, exactly
	 * the M2 "points mode" style that never blinked in Jason's Firefox.
	 */
	function renderFeatures(resp) {
		hidePopup();
		rebuildDataSource();
		var ents = dataSource.entities;
		var scal = horizonScalar();
		lastScaleHeight = viewer.camera.positionCartographic.height;

		ents.suspendEvents();
		(resp.features || []).forEach(function (f) {
			var color = SUB_COLORS[f.project_subsystem] || SUB_COLORS.field;
			ents.add({
				id: 'p|' + f.project_subsystem + '|' + f.project_userpkey + '|' + f.project_id,
				position: Cesium.Cartesian3.fromDegrees(f.lng, f.lat),
				point: {
					pixelSize: 9,
					color: Cesium.Color.fromCssColorString(color),
					outlineColor: Cesium.Color.WHITE.withAlpha(0.85),
					outlineWidth: 1.5,
					scaleByDistance: scal
					// NO disableDepthTestDistance here, unlike the M2 bins:
					// the POINT-primitive vertex shader's depth-disable branch
					// clips the marker entirely once camera height passes
					// ~22,000 km (FF harness bisect, 2026-08-23 post-pivot),
					// which read as "all dots vanish zoomed out". Honest depth
					// measured MORE stable for surface points (0-1% frame
					// spread vs 15-24% with the flag) and culls the far side
					// for free; scaleByDistance still fades the rim.
				},
				properties: { hit: f }
			});
		});
		ents.resumeEvents();

		var n = (resp.features || []).length;
		if (resp.capped && counter) {
			setStatus('Showing the ' + fmtCount(n) + ' largest of '
				+ fmtCount(counter.located) + ' located projects');
		} else {
			setStatus(n ? fmtCount(n) + ' projects with locations'
				: 'No matching projects have locations');
		}
		flyToFeatures(resp.features || []);
		viewer.scene.requestRender();
	}

	/**
	 * Orient the camera to the marker set when a search renders (Jason
	 * 2026-08-23: an owner query came up entirely on the far side of the
	 * globe). Runs once per search render, never on camera moves or
	 * List/Globe re-toggles (those don't refetch). A user drag cancels
	 * the flight, so it never fights active interaction.
	 */
	function flyToFeatures(features) {
		if (!features.length) return;

		// Spherical mean: the dominant direction of the marker mass. A
		// short mean vector means the results wrap the whole planet and
		// no orientation is "right", so leave the camera alone.
		var sx = 0, sy = 0, sz = 0;
		var lons = [], latMin = 90, latMax = -90;
		features.forEach(function (f) {
			var lon = f.lng * Math.PI / 180, lat = f.lat * Math.PI / 180;
			sx += Math.cos(lat) * Math.cos(lon);
			sy += Math.cos(lat) * Math.sin(lon);
			sz += Math.sin(lat);
			lons.push(f.lng);
			if (f.lat < latMin) latMin = f.lat;
			if (f.lat > latMax) latMax = f.lat;
		});
		var norm = Math.sqrt(sx * sx + sy * sy + sz * sz) / features.length;
		if (norm < 0.3) return;

		// Smallest longitude window containing every marker: the largest
		// gap between sorted longitudes (wraparound included) is the
		// complement of the window, so the seam is handled for free.
		lons.sort(function (a, b) { return a - b; });
		var last = lons.length - 1;
		var gap = (lons[0] + 360) - lons[last];   // wraparound gap
		var west = lons[0], east = lons[last];
		for (var i = 1; i < lons.length; i++) {
			var g = lons[i] - lons[i - 1];
			if (g > gap) { gap = g; west = lons[i]; east = lons[i - 1]; }
		}
		var width = 360 - gap;

		if (width > 140) {
			// Too wide to frame as a rectangle but still one-sided (the
			// mean vector was long): face the centroid at browse height.
			var cLon = Math.atan2(sy, sx) * 180 / Math.PI;
			var cLat = Math.atan2(sz, Math.sqrt(sx * sx + sy * sy)) * 180 / Math.PI;
			viewer.camera.flyTo({
				destination: Cesium.Cartesian3.fromDegrees(cLon, cLat, 22000000),
				duration: 1.5
			});
			return;
		}

		// Pad 15% per side (minimum 2°) and keep a sane floor so a
		// single-project result frames a region, not a rooftop.
		var height = latMax - latMin;
		var padLon = Math.max(2, width * 0.15);
		var padLat = Math.max(2, height * 0.15);
		west -= padLon; east += padLon;
		if (east - west < 4 && east >= west) { west -= 2; east += 2; }
		if (west < -180) west += 360;
		if (east > 180) east -= 360;
		var south = Math.max(-88, latMin - padLat);
		var north = Math.min(88, latMax + padLat);
		if (north - south < 4) { south = Math.max(-88, south - 2); north = Math.min(88, north + 2); }

		viewer.camera.flyTo({
			destination: Cesium.Rectangle.fromDegrees(west, south, east, north),
			duration: 1.5
		});
	}

	// Cluster point + label carry NO disableDepthTestDistance either (see
	// the marker comment in renderFeatures) so the pair depth-tests
	// identically and can never disagree (the pt4 circle/label split).
	function styleCluster(entities, cluster) {
		var scal = horizonScalar();
		// Cesium only assigns the entity array as the pick id on the
		// LABEL; the point disc we actually show picks as undefined, so
		// disc clicks were dead (Jason 2026-08-23). Assign it ourselves.
		cluster.point.id = entities;
		// Badge color = the dominant member subsystem (an all-micro
		// cluster reads blue like its dots, not accent red).
		var tally = {};
		var best = null;
		entities.forEach(function (e) {
			var hit = e.properties && e.properties.hit && e.properties.hit.getValue();
			var s = hit ? hit.project_subsystem : null;
			if (!s) return;
			tally[s] = (tally[s] || 0) + 1;
			if (best === null || tally[s] > tally[best]) best = s;
		});
		var color = (best && SUB_COLORS[best]) || CLUSTER_COLOR;
		cluster.billboard.show = false;
		cluster.point.show = true;
		cluster.point.pixelSize = 16 + Math.min(18, entities.length);
		cluster.point.color = Cesium.Color.fromCssColorString(color).withAlpha(0.85);
		cluster.point.outlineColor = Cesium.Color.WHITE.withAlpha(0.9);
		cluster.point.outlineWidth = 2;
		cluster.point.scaleByDistance = scal;
		cluster.label.show = true;
		cluster.label.text = String(entities.length);
		cluster.label.font = '700 12px "Source Sans Pro", sans-serif';
		cluster.label.fillColor = Cesium.Color.WHITE;
		cluster.label.horizontalOrigin = Cesium.HorizontalOrigin.CENTER;
		cluster.label.verticalOrigin = Cesium.VerticalOrigin.CENTER;
		cluster.label.scaleByDistance = scal;
	}

	// ══════════════════════════════════════════════════════════════════
	// interaction: cluster zoom + project popup
	// ══════════════════════════════════════════════════════════════════

	function onLeftClick(click) {
		var picked = viewer.scene.pick(click.position);
		hidePopup();
		if (!Cesium.defined(picked)) { geologyClick(click.position); return; }

		// EntityCluster cluster: picked.id is the ARRAY of clustered entities.
		if (Array.isArray(picked.id)) {
			handleClusterClick(picked.id, click.position);
			return;
		}
		var ent = picked.id;
		if (!ent || !ent.properties || !ent.properties.hit) {
			// Not a project marker (e.g. the geology pin itself): empty ground.
			geologyClick(click.position);
			return;
		}
		showPopup(ent.properties.hit.getValue(), click.position);
	}

	/**
	 * Zoom into a cluster only when zooming can actually split it. A
	 * cluster of CO-LOCATED projects (17 public micro projects share one
	 * centroid on dev, Jason 2026-08-23) never separates at any zoom, so
	 * for those (or once we're at the zoom floor) list the member
	 * projects in the popup instead.
	 */
	function handleClusterClick(members, screenPos) {
		var now = Cesium.JulianDate.now();
		var lonMin = 180, lonMax = -180, latMin = 90, latMax = -90;
		members.forEach(function (e) {
			var c = Cesium.Cartographic.fromCartesian(e.position.getValue(now));
			var lon = Cesium.Math.toDegrees(c.longitude);
			var lat = Cesium.Math.toDegrees(c.latitude);
			if (lon < lonMin) lonMin = lon;
			if (lon > lonMax) lonMax = lon;
			if (lat < latMin) latMin = lat;
			if (lat > latMax) latMax = lat;
		});
		var midLat = (latMin + latMax) / 2 * Math.PI / 180;
		var spreadM = Math.max(
			(latMax - latMin) * 111000,
			(lonMax - lonMin) * 111000 * Math.cos(midLat));

		// After a 0.25x zoom (floored at 2,500 m) the cluster splits when
		// its ground spread projects past the 42px cluster range; ~0.05 x
		// height is that threshold for the default 60 degree frustum.
		var nextH = Math.max(viewer.camera.positionCartographic.height * 0.25, 2500);
		if (spreadM > nextH * 0.05) {
			zoomToward(members[0].position.getValue(now), 0.25);
			return;
		}
		showClusterPopup(members, screenPos);
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

	function fmtInt(n) {
		return (n === undefined || n === null) ? '0' : Number(n).toLocaleString();
	}

	function dateOnly(ts) {
		if (!ts) return null;
		var m = String(ts).match(/^(\d{4}-\d{2}-\d{2})/);
		return m ? m[1] : String(ts);
	}

	var COUNT_LABELS = [
		['dataset', 'dataset', 'datasets'],
		['spot', 'spot', 'spots'],
		['sample', 'sample', 'samples'],
		['experiment', 'experiment', 'experiments'],
		['micrograph', 'micrograph', 'micrographs']
	];

	/** Project link target, identical routing to results.js projectCard
	 *  (field single-dataset deep-links to the dataset viewer). */
	function projectHref(hit) {
		if (hit.project_subsystem === 'field' && hit.dataset_ids && hit.dataset_ids.length === 1) {
			return CFG.fieldDataset + encodeURIComponent(hit.dataset_ids[0]);
		}
		return (CFG.landing[hit.project_subsystem] || CFG.landing.field) +
			encodeURIComponent(hit.project_id);
	}

	/** Popup card: list-row parity, i.e. the same fields, meta rules and
	 *  routing as results.js projectCard (D7). */
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
		if (hit.owner_name) meta.push('Owner: ' + hit.owner_name);
		var mod = dateOnly(hit.last_modified);
		if (mod) meta.push('Updated ' + mod);
		// Same suppression rule as the list card: micro/samples derive
		// date_value from the modified timestamp, so a single-day range
		// equal to "Updated" is pure duplication.
		var d0 = hit.date_range && dateOnly(hit.date_range[0]);
		var d1 = hit.date_range && dateOnly(hit.date_range[1]);
		if (d0 && d1 && !(d0 === d1 && d0 === mod)) {
			meta.push('Data: ' + (d0 === d1 ? d0 : d0 + ' – ' + d1));
		}
		if (meta.length) popupEl.appendChild(el('div', 'ss-gpop-meta', meta.join('  ·  ')));

		var counts = [];
		COUNT_LABELS.forEach(function (t) {
			var n = hit.match_counts ? hit.match_counts[t[0]] : 0;
			if (n > 0) counts.push(fmtInt(n) + ' ' + (n === 1 ? t[1] : t[2]));
		});
		if (counts.length) {
			popupEl.appendChild(el('div', 'ss-gpop-meta', counts.join(' · ') + ' matched'));
		}

		var links = el('div', 'ss-gpop-links');
		var open = el('a', null, 'View Project');
		open.href = projectHref(hit);
		open.target = '_blank';
		links.appendChild(open);
		if (!isBrowse()) {
			var toList = el('a', null, 'View results in list');
			toList.href = 'javascript:void(0);';
			toList.addEventListener('click', function () {
				hidePopup();
				if (callbacks.onOpenList) callbacks.onOpenList();
			});
			links.appendChild(toList);
		}
		popupEl.appendChild(links);

		placePopup(screenPos);
	}

	/** Clamp the card near the tap inside the wrap; on phones (M4) it is a
	 *  bottom sheet instead (search.css .ss-gpop-sheet). */
	var sheetMq = window.matchMedia ? window.matchMedia('(max-width: 639px)') : null;
	if (sheetMq && sheetMq.addEventListener) {
		// Crossing the phone breakpoint with a card open: the two layouts
		// don't share positioning, so drop the card rather than strand it.
		sheetMq.addEventListener('change', function () { hidePopup(); });
	}
	function placePopup(screenPos) {
		popupEl.style.display = '';
		if (sheetMq && sheetMq.matches) {
			popupEl.classList.add('ss-gpop-sheet');
			popupEl.style.left = '';
			popupEl.style.top = '';
			return;
		}
		popupEl.classList.remove('ss-gpop-sheet');
		var pad = 12;
		var x = Math.min(screenPos.x + 14, wrap.clientWidth - popupEl.offsetWidth - pad);
		var y = Math.min(screenPos.y + 14, wrap.clientHeight - popupEl.offsetHeight - pad);
		popupEl.style.left = Math.max(pad, x) + 'px';
		popupEl.style.top = Math.max(pad, y) + 'px';
	}

	/** Popup listing a cluster's member projects (unsplittable clusters:
	 *  co-located centroids or the zoom floor). */
	function showClusterPopup(members, screenPos) {
		popupEl.innerHTML = '';

		var hits = [];
		members.forEach(function (e) {
			var hit = e.properties && e.properties.hit && e.properties.hit.getValue();
			if (hit) hits.push(hit);
		});
		hits.sort(function (a, b) {
			return String(a.project_name || '').localeCompare(String(b.project_name || ''));
		});

		var head = el('div', 'ss-gpop-head');
		head.appendChild(el('div', 'ss-gpop-title',
			hits.length + ' projects at this location'));
		var close = el('a', 'ss-gpop-close', '×');
		close.href = 'javascript:void(0);';
		close.setAttribute('aria-label', 'Close');
		close.addEventListener('click', hidePopup);
		head.appendChild(close);
		popupEl.appendChild(head);

		var list = el('div', 'ss-gpop-list');
		var MAX = 12;
		hits.slice(0, MAX).forEach(function (hit) {
			var row = el('div', 'ss-gpop-list-row');
			var icon = el('img', 'ss-gpop-icon');
			icon.src = CFG.icons[hit.project_subsystem] || CFG.icons.field;
			icon.alt = hit.project_subsystem;
			row.appendChild(icon);
			var link = el('a', null, hit.project_name || '(unnamed project)');
			link.href = projectHref(hit);
			link.target = '_blank';
			row.appendChild(link);
			list.appendChild(row);
		});
		if (hits.length > MAX) {
			list.appendChild(el('div', 'ss-gpop-meta',
				'+ ' + (hits.length - MAX) + (isBrowse() ? ' more' : ' more in the list view')));
		}
		popupEl.appendChild(list);

		if (!isBrowse()) {
			var links = el('div', 'ss-gpop-links');
			var toList = el('a', null, 'View results in list');
			toList.href = 'javascript:void(0);';
			toList.addEventListener('click', function () {
				hidePopup();
				if (callbacks.onOpenList) callbacks.onOpenList();
			});
			links.appendChild(toList);
			popupEl.appendChild(links);
		}

		placePopup(screenPos);
	}

	function hidePopup() {
		if (popupEl) {
			popupEl.style.display = 'none';
			popupEl.innerHTML = '';
			popupEl.classList.remove('ss-gpop-geo');
		}
		cancelGeology();
		geoSeq++;          // a late answer to a closed card is dropped
		clearPin();
	}

	// ══════════════════════════════════════════════════════════════════
	// Macrostrat geology click (M5, §12): empty ground + overlay on ->
	// point query -> unit card. Markers and clusters keep priority above.
	// ══════════════════════════════════════════════════════════════════

	function geologyActive() {
		return !!(layerPrefs.macrostrat && layerMacrostrat && CFG.macrostrat && CFG.macrostrat.query);
	}

	/**
	 * Slippy zoom the geology tiles are drawn at around the click, from
	 * camera height: ground meters per screen pixel at nadir is
	 * 2 h tan(fovy/2) / canvasHeight, and the slippy pyramid runs
	 * 156543.03 cos(lat) / 2^z meters per pixel. Rounded and clamped to
	 * the overlay's tile range so the API names the SAME map scale the
	 * tiles show (map_query_v2 picks tiny/small/medium/large from z the
	 * way the carto tile service does).
	 */
	function estimateZoom(latDeg) {
		var h = viewer.camera.positionCartographic.height;
		var fovy = (viewer.camera.frustum && viewer.camera.frustum.fovy) || 1.0;
		var px = viewer.scene.canvas.clientHeight || 1;
		var mpp = 2 * h * Math.tan(fovy / 2) / px;
		var z = Math.log(156543.03 * Math.cos(latDeg * Math.PI / 180) / mpp) / Math.LN2;
		if (!isFinite(z)) return 0;
		return Math.max(0, Math.min(14, Math.round(z)));
	}

	function geologyClick(screenPos) {
		if (!geologyActive()) return;
		var cart = viewer.camera.pickEllipsoid(screenPos, viewer.scene.globe.ellipsoid);
		if (!cart) return;   // clicked the sky
		var c = Cesium.Cartographic.fromCartesian(cart);
		var lon = Cesium.Math.toDegrees(c.longitude);
		var lat = Cesium.Math.toDegrees(c.latitude);
		lookupGeology({ lon: lon, lat: lat, z: estimateZoom(lat) }, screenPos);
	}

	function lookupGeology(where, screenPos) {
		cancelGeology();
		dropPin(where.lon, where.lat);
		showGeologyCard(where, { state: 'loading' }, screenPos);

		var ctrl = new AbortController();
		geoInflight = ctrl;
		var seq = ++geoSeq;
		var timedOut = false;
		geoTimer = setTimeout(function () { timedOut = true; ctrl.abort(); }, GEO_TIMEOUT_MS);

		var url = CFG.macrostrat.query
			+ '?lng=' + where.lon.toFixed(5) + '&lat=' + where.lat.toFixed(5) + '&z=' + where.z;
		fetch(url, { signal: ctrl.signal, mode: 'cors' }).then(function (r) {
			if (!r.ok) throw new Error('Macrostrat answered ' + r.status + '.');
			return r.json();
		}).then(function (j) {
			if (seq !== geoSeq) return;
			finishGeology();
			var d = (j && j.success && j.success.data) || {};
			var units = (d.mapData || []).slice().sort(function (a, b) {
				return scaleRank(a) - scaleRank(b);   // largest scale first
			});
			showGeologyCard(where, {
				state: 'done', units: units,
				license: (j && j.success && j.success.license) || 'CC-BY 4.0'
			}, screenPos);
		}).catch(function (e) {
			if (seq !== geoSeq) return;
			if (e && e.name === 'AbortError' && !timedOut) return;   // superseded
			finishGeology();
			showGeologyCard(where, {
				state: 'error',
				message: timedOut ? 'Macrostrat did not answer in time.'
					: ((e && e.message) || 'Macrostrat lookup failed.')
			}, screenPos);
		});
	}

	function scaleRank(u) {
		var r = SCALE_RANK[u && u.scale];
		return r === undefined ? 9 : r;
	}

	function cancelGeology() {
		if (geoTimer) { clearTimeout(geoTimer); geoTimer = null; }
		if (geoInflight) { geoInflight.abort(); geoInflight = null; }
	}

	function finishGeology() {
		if (geoTimer) { clearTimeout(geoTimer); geoTimer = null; }
		geoInflight = null;
	}

	/** Ring pin at the queried point; styled unlike the project dots
	 *  (hollow, white) so it can't read as data. Cleared with the card. */
	function dropPin(lon, lat) {
		clearPin();
		if (!pinSource) return;
		pinSource.entities.add({
			id: 'ss-geopin',
			position: Cesium.Cartesian3.fromDegrees(lon, lat),
			point: {
				pixelSize: 18,
				color: Cesium.Color.WHITE.withAlpha(0.15),
				outlineColor: Cesium.Color.WHITE.withAlpha(0.95),
				outlineWidth: 2.5
			}
		});
		viewer.scene.requestRender();
	}

	function clearPin() {
		if (!pinSource || !pinSource.entities.values.length) return;
		pinSource.entities.removeAll();
		if (viewer) viewer.scene.requestRender();
	}

	function fmtCoord(lat, lon) {
		return Math.abs(lat).toFixed(4) + '°' + (lat < 0 ? 'S' : 'N') + ', '
			+ Math.abs(lon).toFixed(4) + '°' + (lon < 0 ? 'W' : 'E');
	}

	function fmtMa(v) {
		return String(Number(Number(v).toPrecision(4)));
	}

	/** "Pleistocene · 2.58 to 0.0117 Ma" (interval string from the API,
	 *  Ma bounds from the bounding intervals when both are numeric). */
	function ageLine(u) {
		var parts = [];
		if (u.age) parts.push(String(u.age));
		var b = u.b_int && u.b_int.b_age, t = u.t_int && u.t_int.t_age;
		if (typeof b === 'number' && typeof t === 'number') {
			parts.push(fmtMa(b) + ' to ' + fmtMa(t) + ' Ma');
		}
		return parts.join(' · ');
	}

	/** Macrostrat writes "Major:{sand,silt}, Minor:{clay,gravel}"; read it
	 *  back as "Major: sand, silt; Minor: clay, gravel". */
	function lithLine(u) {
		var s = u.lith ? String(u.lith) : '';
		if (!s && u.liths && u.liths.length) {
			s = u.liths.map(function (l) { return l.lith; }).filter(Boolean).join(', ');
		}
		s = s.replace(/\}\s*,\s*(?=\w+\s*:)/g, '}; ');
		s = s.replace(/\{([^}]*)\}/g, function (_, inner) {
			return inner.split(',').map(function (x) { return x.trim(); }).filter(Boolean).join(', ');
		});
		return s.replace(/\s*:\s*/g, ': ').replace(/\s*,\s*/g, ', ').replace(/\s*;\s*/g, '; ').trim();
	}

	function macrostratHref(where) {
		var lon = where.lon.toFixed(4), lat = where.lat.toFixed(4);
		return CFG.macrostrat.open + lon + '/' + lat + '#x=' + lon + '&y=' + lat + '&z=' + where.z;
	}

	/** The geology card: same vocabulary as the project popup (option B,
	 *  §12): unit name (+ strat name), age, lithology, clamped
	 *  description, source citation, Open in Macrostrat, attribution. */
	function showGeologyCard(where, res, screenPos) {
		popupEl.innerHTML = '';
		popupEl.classList.add('ss-gpop-geo');

		var units = res.units || [];
		var title;
		if (res.state === 'loading') title = 'Looking up geology…';
		else if (res.state === 'error') title = 'Geology lookup failed';
		else if (!units.length) title = 'No mapped geology here';
		else title = units[0].name || '(unnamed unit)';

		var head = el('div', 'ss-gpop-head');
		head.appendChild(el('div', 'ss-gpop-title', title));
		var close = el('a', 'ss-gpop-close', '×');
		close.href = 'javascript:void(0);';
		close.setAttribute('aria-label', 'Close');
		close.addEventListener('click', hidePopup);
		head.appendChild(close);
		popupEl.appendChild(head);

		popupEl.appendChild(el('div', 'ss-gpop-meta', 'At ' + fmtCoord(where.lat, where.lon)));
		if (res.state === 'error') popupEl.appendChild(el('div', 'ss-gpop-meta', res.message));

		units.forEach(function (u, i) {
			if (i > 0) popupEl.appendChild(el('div', 'ss-gpop-unit', u.name || '(unnamed unit)'));
			if (u.strat_name && String(u.strat_name) !== String(u.name || '')) {
				popupEl.appendChild(el('div', 'ss-gpop-meta', String(u.strat_name)));
			}
			var age = ageLine(u);
			if (age) popupEl.appendChild(el('div', 'ss-gpop-meta', 'Age: ' + age));
			var lith = lithLine(u);
			if (lith) popupEl.appendChild(el('div', 'ss-gpop-meta', 'Lithology: ' + lith));
			var desc = String(u.descrip || u.comments || '').trim();
			if (desc) appendClampedText(desc, screenPos);
			var ref = u.ref;
			if (ref && (ref.name || ref.ref_title)) {
				var src = el('div', 'ss-gpop-meta ss-gpop-source', 'Source: ');
				var label = (ref.name || ref.ref_title) + (ref.ref_year ? ' (' + ref.ref_year + ')' : '');
				if (ref.url) {
					var a = el('a', null, label);
					a.href = ref.url;
					a.target = '_blank';
					a.rel = 'noopener';
					src.appendChild(a);
				} else {
					src.appendChild(document.createTextNode(label));
				}
				popupEl.appendChild(src);
			}
		});

		var links = el('div', 'ss-gpop-links');
		if (res.state === 'error') {
			var retry = el('a', null, 'Try again');
			retry.href = 'javascript:void(0);';
			retry.addEventListener('click', function () { lookupGeology(where, screenPos); });
			links.appendChild(retry);
		}
		if (res.state !== 'loading') {
			var open = el('a', null, 'Open in Macrostrat');
			open.href = macrostratHref(where);
			open.target = '_blank';
			open.rel = 'noopener';
			links.appendChild(open);
		}
		if (links.childNodes.length) popupEl.appendChild(links);

		popupEl.appendChild(el('div', 'ss-gpop-attrib',
			'Geology © Macrostrat · ' + (res.license || 'CC-BY 4.0')));

		placePopup(screenPos);
	}

	/** Two-line clamp with a more/less toggle (some sources write
	 *  paragraphs); re-clamps the card after a toggle so it never
	 *  strands past the wrap edge. */
	function appendClampedText(text, screenPos) {
		var d = el('div', 'ss-gpop-desc', text);
		popupEl.appendChild(d);
		if (text.length <= 110) return;
		var more = el('a', 'ss-gpop-more', 'more');
		more.href = 'javascript:void(0);';
		more.addEventListener('click', function () {
			var open = d.classList.toggle('ss-gpop-desc-open');
			more.textContent = open ? 'less' : 'more';
			placePopup(screenPos);
		});
		popupEl.appendChild(more);
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
			wireLayersPanel();
		},

		/** New search DSL (criteria + subsystems). Fetches now if visible,
		 *  otherwise on the next show(). */
		setQuery: function (dsl) {
			baseDsl = dsl ? { criteria: dsl.criteria, subsystems: dsl.subsystems } : null;
			counter = null;
			needFetch = !!baseDsl;
			if (active && viewer && baseDsl) fetchGeo();
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
				if (baseDsl && needFetch) fetchGeo();
			}).catch(function (e) {
				setStatus(e.message || 'Could not load the globe engine.');
			});
		},

		hide: function () {
			active = false;
			hidePopup();
			if (inflight) { inflight.abort(); inflight = null; }
				if (wrap) wrap.style.display = 'none';
		},

		/** Full reset (criteria cleared; results region went quiet). */
		clear: function () {
			this.hide();
			baseDsl = null;
			counter = null;
			needFetch = false;
			if (viewer && dataSource) {
				rebuildDataSource();   // fresh source, no cluster residue
				viewer.scene.requestRender();
			}
		},

		getCounter: function () { return counter; },

		BUILD: BUILD
	};

})(window, document);
