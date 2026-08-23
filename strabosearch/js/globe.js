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
 *              results top bar.
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
	var BUILD = 'd7-project-markers-r3-fly-to-results';
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
		viewer.imageryLayers.addImageryProvider(new Cesium.UrlTemplateImageryProvider({
			url: CFG.tiles.outdoors,
			maximumLevel: 19,
			credit: new Cesium.Credit('© Mapbox © OpenStreetMap · StraboSpot tiles')
		}));

		dataSource = new Cesium.CustomDataSource('ss-projects');
		dataSource.clustering.enabled = true;
		dataSource.clustering.pixelRange = 42;
		dataSource.clustering.minimumClusterSize = 3;
		dataSource.clustering.clusterEvent.addEventListener(styleCluster);
		viewer.dataSources.add(dataSource);

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
		var ents = dataSource.entities;
		var scal = horizonScalar();
		lastScaleHeight = viewer.camera.positionCartographic.height;

		ents.suspendEvents();
		ents.removeAll();
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
		cluster.billboard.show = false;
		cluster.point.show = true;
		cluster.point.pixelSize = 16 + Math.min(18, entities.length);
		cluster.point.color = Cesium.Color.fromCssColorString(CLUSTER_COLOR).withAlpha(0.8);
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
		if (!Cesium.defined(picked)) return;

		// EntityCluster cluster: picked.id is the ARRAY of clustered entities.
		if (Array.isArray(picked.id)) {
			zoomToward(picked.id[0].position.getValue(Cesium.JulianDate.now()), 0.25);
			return;
		}
		var ent = picked.id;
		if (!ent || !ent.properties || !ent.properties.hit) return;
		showPopup(ent.properties.hit.getValue(), click.position);
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
		// Field single-dataset routing, identical to projectCard.
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
			if (dataSource) {
				dataSource.entities.removeAll();
				if (viewer) viewer.scene.requestRender();
			}
		},

		getCounter: function () { return counter; },

		BUILD: BUILD
	};

})(window, document);
