/* StraboSpot Mission Control (/stats). Renders the snapshot embedded by stats.php.
 * Needs d3 v7 and topojson-client. Every number comes from the snapshot; nothing
 * here queries the databases. */
(function () {
	'use strict';

	var S = null;
	try { S = JSON.parse(document.getElementById('snapshot').textContent); } catch (e) { S = null; }
	var CFG = window.STATS_CONFIG || {};
	var app = document.getElementById('app');
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	var C = {
		s1: '#3987e5', s2: '#d95926', s3: '#199e70',
		ramp: ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b'],
		text3: '#6f7f96', line: '#1e2b3d', accent: '#5fd4ff'
	};
	// On the dark panel the light end of the ramp must be the dense end.
	var heat = d3.interpolateRgbBasis(['#12304f', '#1c5cab', '#3987e5', '#86b6ef', '#e6f1ff']);

	var fmt = d3.format(',');
	function compact(n) {
		if (n == null || isNaN(n)) return '-';
		if (Math.abs(n) < 10000) return fmt(n);
		return d3.format('.3~s')(n).replace('G', 'B');
	}
	function pct(a, b) { return b ? (100 * a / b).toFixed(1) + '%' : '-'; }
	function get(o, path, dflt) {
		var parts = path.split('.');
		for (var i = 0; i < parts.length; i++) {
			if (o == null) return dflt;
			o = o[parts[i]];
		}
		return o == null ? dflt : o;
	}
	function el(tag, cls, html) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (html != null) e.innerHTML = html;
		return e;
	}
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// ---------- tooltip ----------
	var tip = el('div', 'tip');
	tip.setAttribute('role', 'status');
	document.body.appendChild(tip);
	function showTip(evt, title, value, extra) {
		tip.innerHTML = '<div class="t">' + esc(title) + '</div><div class="n">' + esc(value) + '</div>' +
			(extra ? '<div>' + esc(extra) + '</div>' : '');
		var x = evt.clientX + 14, y = evt.clientY + 14;
		var w = tip.offsetWidth, h = tip.offsetHeight;
		if (x + w > window.innerWidth - 8) x = evt.clientX - w - 14;
		if (y + h > window.innerHeight - 8) y = evt.clientY - h - 14;
		tip.style.left = x + 'px';
		tip.style.top = y + 'px';
		tip.style.opacity = 1;
	}
	function hideTip() { tip.style.opacity = 0; }

	// ---------- clocks + status ----------
	function tickClocks() {
		var now = new Date();
		document.getElementById('clockUtc').textContent = now.toISOString().substr(11, 8);
		document.getElementById('clockLocal').textContent = now.toLocaleTimeString('en-GB', { timeZone: 'America/Chicago', hour12: false });
		if (S && S.builtAtMs) {
			document.getElementById('snapshotAge').textContent = 'Data as of ' + ago(now.getTime() - S.builtAtMs);
		}
	}
	function ago(ms) {
		var m = Math.round(ms / 60000);
		if (m < 1) return 'just now';
		if (m < 60) return m + ' min ago';
		var h = Math.floor(m / 60);
		if (h < 48) return h + ' h ' + (m % 60) + ' min ago';
		return Math.round(h / 24) + ' days ago';
	}
	function setStatus() {
		var dot = document.getElementById('statusDot');
		var txt = document.getElementById('statusText');
		if (!S) { dot.className = 'status-dot warn'; txt.textContent = 'NO SNAPSHOT YET'; return; }
		var errs = (S.errors || []).length;
		var ageH = (Date.now() - S.builtAtMs) / 3600000;
		if (errs) { dot.className = 'status-dot bad'; txt.textContent = errs + ' SECTION' + (errs > 1 ? 'S' : '') + ' FAILED'; }
		else if (ageH > 12) { dot.className = 'status-dot warn'; txt.textContent = 'SNAPSHOT STALE'; }
		else { dot.className = 'status-dot good'; txt.textContent = 'ALL SYSTEMS NOMINAL'; }
	}
	setInterval(tickClocks, 1000);

	// ---------- refresh ----------
	var refreshBtn = document.getElementById('refreshBtn');
	refreshBtn.addEventListener('click', function () {
		refreshBtn.disabled = true;
		refreshBtn.textContent = 'Building...';
		var body = new URLSearchParams({ action: 'refresh', token: CFG.token || '' });
		fetch('/stats', { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j.ok) throw new Error(j.message || 'refused');
				poll(Date.now());
			})
			.catch(function (e) {
				refreshBtn.disabled = false;
				refreshBtn.textContent = 'Refresh failed: ' + e.message;
			});
	});
	function poll(started) {
		fetch('/stats?status=1', { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				var secs = Math.round((Date.now() - started) / 1000);
				if (j.pkey && j.pkey !== CFG.pkey && !j.building) { location.reload(); return; }
				if (secs > 600) { refreshBtn.disabled = false; refreshBtn.textContent = 'Still building, reload later'; return; }
				refreshBtn.textContent = 'Building... ' + secs + ' s';
				setTimeout(function () { poll(started); }, 3000);
			})
			.catch(function () { setTimeout(function () { poll(started); }, 5000); });
	}

	tickClocks();
	setStatus();
	if (!S) {
		app.appendChild(el('div', 'empty', '<h2>No snapshot yet</h2><p>Press <b>Refresh now</b> to build the first one (about a minute).</p>'));
		return;
	}

	// ---------- series helpers ----------
	var endDay = get(S, 'fieldSeries.datedThrough', S.builtAt.substr(0, 10));
	function monthly(daily, cumulative) {
		daily = daily || {};
		var keys = Object.keys(daily).filter(function (k) { return k <= endDay; }).sort();
		if (!keys.length) return [];
		var byMonth = {};
		keys.forEach(function (k) { var m = k.substr(0, 7); byMonth[m] = (byMonth[m] || 0) + daily[k]; });
		var start = new Date(keys[0].substr(0, 7) + '-01T00:00:00Z');
		var end = new Date(endDay.substr(0, 7) + '-01T00:00:00Z');
		var out = [], run = 0;
		for (var d = new Date(start); d <= end; d.setUTCMonth(d.getUTCMonth() + 1)) {
			var key = d.toISOString().substr(0, 7);
			var v = byMonth[key] || 0;
			run += v;
			out.push({ date: new Date(d), key: key, value: cumulative ? run : v });
		}
		return out;
	}
	function sumDaily(daily) {
		var t = 0;
		Object.keys(daily || {}).forEach(function (k) { if (k <= endDay) t += daily[k]; });
		return t;
	}

	var R = S.recent || {};
	var F = S.field || {};
	var X = S.searchIndex || {};
	var A = S.accounts || {};
	var M = S.micro || {};
	var E = S.experimental || {};
	var SM = S.samples || {};
	var P = S.platform || {};
	var FS = S.fieldSeries || {};
	var CB = S.contributors || {};

	var renderers = [];
	function panel(cls, title, sub, parent) {
		var p = el('section', 'panel ' + (cls || ''));
		p.style.animationDelay = (renderers.length * 40) + 'ms';
		var head = el('div', 'panel-head');
		head.appendChild(el('h2', 'panel-title', esc(title)));
		if (sub) head.appendChild(el('span', 'panel-sub', sub));
		p.appendChild(head);
		(parent || app).appendChild(p);
		return { root: p, head: head };
	}
	function segmented(head, options, onPick, initial) {
		var box = el('div', 'panel-tools');
		options.forEach(function (o) {
			var b = el('button', 'seg', esc(o.label));
			b.type = 'button';
			b.setAttribute('aria-pressed', o.key === initial ? 'true' : 'false');
			b.addEventListener('click', function () {
				box.querySelectorAll('.seg').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
				b.setAttribute('aria-pressed', 'true');
				onPick(o.key);
			});
			box.appendChild(b);
		});
		head.appendChild(box);
	}

	// =====================================================================
	// 1. hero tiles
	// =====================================================================
	var tilesRow = el('div', 'tiles');
	app.appendChild(tilesRow);
	var tiles = [
		{ label: 'Confirmed accounts', value: A.confirmed, recent: get(R, 'accounts.30'), spark: monthly(A.dailyConfirmedSignups, true) },
		{ label: 'Field contributors', value: F.usersWithSpots, sub: fmt(get(FS, 'activeUsers.30', 0)) + ' active in 30 days' },
		{ label: 'Field projects', value: F.projects, recent: get(R, 'projects.30'), spark: monthly(FS.projectsDaily, true) },
		{ label: 'Spots', value: F.spots, recent: get(R, 'spots.30'), spark: monthly(FS.spotsDaily, true) },
		{ label: 'Orientations', value: X.orientations, sub: compact(X.planarWithStrike) + ' planar, ' + compact(X.linearWithTrend) + ' linear' },
		{ label: 'Images', value: F.images, recent: get(R, 'images.30'), spark: monthly(FS.imagesDaily, true) },
		{ label: 'Samples', value: SM.samples, sub: fmt(SM.igsns || 0) + ' with an IGSN' },
		{ label: 'Micrographs', value: M.micrographs, sub: 'in ' + fmt(M.projects || 0) + ' Micro projects' }
	];
	tiles.forEach(function (t, i) {
		var d = el('div', 'tile');
		d.style.animationDelay = (i * 50) + 'ms';
		d.appendChild(el('div', 'tile-label', esc(t.label)));
		var v = el('div', 'tile-value', '0');
		d.appendChild(v);
		var sub = t.sub != null ? esc(t.sub)
			: (t.recent != null ? '<span class="up">+' + fmt(t.recent) + '</span> in the last 30 days' : '');
		d.appendChild(el('div', 'tile-sub', sub));
		tilesRow.appendChild(d);
		countUp(v, t.value || 0, 1200 + i * 80);
		if (t.spark && t.spark.length > 1) {
			renderers.push(function () { sparkline(d, t.spark); });
		}
	});
	function countUp(node, target, dur) {
		if (reduceMotion) { node.textContent = fmt(target); return; }
		var t0 = null;
		function step(ts) {
			if (!t0) t0 = ts;
			var k = Math.min(1, (ts - t0) / dur);
			node.textContent = fmt(Math.round(target * d3.easeCubicOut(k)));
			if (k < 1) requestAnimationFrame(step);
		}
		requestAnimationFrame(step);
	}
	function sparkline(tile, data) {
		d3.select(tile).select('svg').remove();
		var w = tile.clientWidth - 28, h = 30;
		var x = d3.scaleUtc().domain(d3.extent(data, function (d) { return d.date; })).range([0, w]);
		var y = d3.scaleLinear().domain([0, d3.max(data, function (d) { return d.value; }) || 1]).range([h - 2, 2]);
		var svg = d3.select(tile).append('svg').attr('width', w).attr('height', h).attr('aria-hidden', 'true');
		svg.append('path').datum(data).attr('fill', 'rgba(57,135,229,.18)')
			.attr('d', d3.area().x(function (d) { return x(d.date); }).y0(h).y1(function (d) { return y(d.value); }));
		svg.append('path').datum(data).attr('fill', 'none').attr('stroke', C.s1).attr('stroke-width', 1.5)
			.attr('d', d3.line().x(function (d) { return x(d.date); }).y(function (d) { return y(d.value); }));
	}

	// =====================================================================
	// 2. globe
	// =====================================================================
	(function globePanel() {
		var p = panel('span-8', 'Where the data is', 'each dot is a half-degree cell; drag to spin');
		var layer = 'spots';
		var layers = {
			spots: { cells: X.mapCells || [], name: 'Field spots' },
			samples: { cells: SM.mapCells || [], name: 'Samples' }
		};
		segmented(p.head, [{ key: 'spots', label: 'Field spots' }, { key: 'samples', label: 'Samples' }],
			function (k) { layer = k; buildIndex(); drawLegend(); draw(); }, 'spots');
		var wrap = el('div', 'globe-wrap');
		var box = el('div', 'globe-box');
		var canvas = document.createElement('canvas');
		canvas.setAttribute('role', 'img');
		canvas.setAttribute('aria-label', 'Globe showing where StraboSpot spots and samples are located');
		box.appendChild(canvas);
		box.appendChild(el('div', 'globe-hint', 'drag to rotate'));
		var side = el('div', 'globe-side');
		wrap.appendChild(box);
		wrap.appendChild(side);
		p.root.appendChild(wrap);

		var hemi = X.hemisphere || { north: 0, south: 0 };
		var located = hemi.north + hemi.south;
		side.innerHTML =
			'<div class="lbl">Spots with a map location</div><div class="big">' + fmt(X.located || 0) + '</div>' +
			'<p>' + pct(X.located || 0, X.spots || 0) + ' of indexed spots. The rest sit on images, strat sections or have no location.</p>' +
			'<div class="lbl">Hemispheres</div>' +
			'<p>North ' + pct(hemi.north, located) + ' &middot; South ' + pct(hemi.south, located) + '</p>' +
			'<div class="lbl">Cells with data</div><div class="big" id="cellCount"></div>' +
			'<div class="lbl" id="legendTitle">Spots per cell</div>' +
			'<div class="legend-ramp" id="legendRamp"></div><div class="legend-ticks" id="legendTicks"></div>' +
			'<div class="lbl">Samples with coordinates</div><div class="big">' + fmt(SM.located || 0) + '</div>';

		var land = null, index = {}, maxN = 1, color;
		var proj = d3.geoOrthographic().clipAngle(90).precision(0.5);
		var rot = [96, -25, 0]; // start over North America
		var size = 0, dpr = Math.min(window.devicePixelRatio || 1, 2);
		var ctx = canvas.getContext('2d');
		var dragging = false, lastInteract = 0;

		function buildIndex() {
			index = {};
			maxN = 1;
			layers[layer].cells.forEach(function (c) {
				index[c[0] + ',' + c[1]] = c[2];
				if (c[2] > maxN) maxN = c[2];
			});
			color = d3.scaleSequentialLog(heat).domain([1, maxN]);
			document.getElementById('cellCount').textContent = fmt(layers[layer].cells.length);
		}
		function drawLegend() {
			document.getElementById('legendTitle').textContent = (layer === 'spots' ? 'Spots' : 'Samples') + ' per cell (log scale)';
			var stops = d3.range(0, 1.01, 0.1).map(function (t) { return heat(t); });
			document.getElementById('legendRamp').style.background = 'linear-gradient(90deg,' + stops.join(',') + ')';
			document.getElementById('legendTicks').innerHTML = '<span>1</span><span>' + compact(Math.round(Math.sqrt(maxN))) + '</span><span>' + compact(maxN) + '</span>';
		}
		function resize() {
			canvas.style.display = 'none'; // measure the box without the old canvas in it
			var w = box.clientWidth;
			canvas.style.display = 'block';
			size = Math.max(260, Math.min(w, 640));
			canvas.width = size * dpr;
			canvas.height = size * dpr;
			canvas.style.width = size + 'px';
			canvas.style.height = size + 'px';
			proj.translate([size / 2, size / 2]).scale(size / 2 - 6);
			draw();
		}
		function draw() {
			if (!size) return;
			proj.rotate(rot);
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
			ctx.clearRect(0, 0, size, size);
			var path = d3.geoPath(proj, ctx);
			// ocean + glow
			var g = ctx.createRadialGradient(size * 0.42, size * 0.38, size * 0.05, size / 2, size / 2, size / 2);
			g.addColorStop(0, '#0f2440');
			g.addColorStop(1, '#070f1c');
			ctx.beginPath(); path({ type: 'Sphere' }); ctx.fillStyle = g; ctx.fill();
			ctx.beginPath(); path(d3.geoGraticule10()); ctx.strokeStyle = 'rgba(95,212,255,.07)'; ctx.lineWidth = 0.6; ctx.stroke();
			if (land) {
				ctx.beginPath(); path(land); ctx.fillStyle = '#16263a'; ctx.fill();
				ctx.strokeStyle = 'rgba(169,182,200,.25)'; ctx.lineWidth = 0.6; ctx.stroke();
			}
			// cells, faint first so dense cells sit on top
			var cells = layers[layer].cells;
			var center = [-rot[0], -rot[1]];
			var px = Math.max(1.6, (size / 2) * (Math.PI / 360) * 1.15);
			var sorted = cells.slice().sort(function (a, b) { return a[2] - b[2]; });
			for (var i = 0; i < sorted.length; i++) {
				var c = sorted[i];
				if (d3.geoDistance([c[0], c[1]], center) > Math.PI / 2 - 0.01) continue;
				var xy = proj([c[0], c[1]]);
				if (!xy) continue;
				var r = px * (0.8 + 0.9 * Math.log10(c[2]) / Math.log10(maxN + 1));
				ctx.beginPath();
				ctx.arc(xy[0], xy[1], r, 0, 2 * Math.PI);
				ctx.fillStyle = color(c[2]);
				ctx.globalAlpha = 0.9;
				ctx.fill();
			}
			ctx.globalAlpha = 1;
			ctx.beginPath(); path({ type: 'Sphere' }); ctx.strokeStyle = 'rgba(95,212,255,.35)'; ctx.lineWidth = 1; ctx.stroke();
		}
		function spin(ts) {
			if (!reduceMotion && !dragging && Date.now() - lastInteract > 4000 && isVisible()) {
				rot[0] = (rot[0] + 0.08) % 360;
				draw();
			}
			requestAnimationFrame(spin);
		}
		function isVisible() {
			var r = canvas.getBoundingClientRect();
			return r.bottom > 0 && r.top < window.innerHeight && !document.hidden;
		}
		d3.select(canvas).call(d3.drag()
			.on('start', function () { dragging = true; hideTip(); })
			.on('drag', function (e) {
				var k = 75 / proj.scale();
				rot[0] += e.dx * k * 1.6;
				rot[1] = Math.max(-80, Math.min(80, rot[1] - e.dy * k * 1.6));
				lastInteract = Date.now();
				draw();
			})
			.on('end', function () { dragging = false; lastInteract = Date.now(); }));
		canvas.addEventListener('mousemove', function (e) {
			if (dragging) return;
			var r = canvas.getBoundingClientRect();
			var ll = proj.invert([e.clientX - r.left, e.clientY - r.top]);
			if (!ll || d3.geoDistance(ll, [-rot[0], -rot[1]]) > Math.PI / 2) { hideTip(); return; }
			var gx = (Math.floor(ll[0] * 2) + 0.5) / 2, gy = (Math.floor(ll[1] * 2) + 0.5) / 2;
			var n = index[gx + ',' + gy];
			if (n) {
				lastInteract = Date.now();
				showTip(e, ll[1].toFixed(1) + '°, ' + ll[0].toFixed(1) + '°', fmt(n), layers[layer].name.toLowerCase() + ' in this cell');
			} else hideTip();
		});
		canvas.addEventListener('mouseleave', hideTip);

		buildIndex();
		drawLegend();
		renderers.push(resize);
		d3.json('/assets/js/stats/land-110m.json').then(function (topo) {
			land = topojson.feature(topo, topo.objects.land);
			draw();
		}).catch(function () { /* globe still shows the cells */ });
		requestAnimationFrame(spin);
	})();

	// =====================================================================
	// 3. pulse
	// =====================================================================
	var sideCol = el('div', 'stack span-4');
	app.appendChild(sideCol);
	(function pulsePanel() {
		var p = panel('', 'Pulse', 'new records by creation time', sideCol);
		var rows = [
			['Spots', R.spots], ['Images', R.images], ['Field projects', R.projects],
			['Accounts', R.accounts], ['Experiments', R.experiments],
			['People adding spots', FS.activeUsers]
		];
		var h = '<table class="pulse"><thead><tr><th>Window</th><th>24 h</th><th>7 d</th><th>30 d</th><th>1 yr</th></tr></thead><tbody>';
		rows.forEach(function (r) {
			var v = r[1] || {};
			h += '<tr><td>' + esc(r[0]) + '</td>';
			[1, 7, 30, 365].forEach(function (d) {
				var n = v[d] || 0;
				h += '<td class="' + (n ? '' : 'zero') + '">' + (n ? compact(n) : '0') + '</td>';
			});
			h += '</tr>';
		});
		h += '</tbody></table>';
		p.root.appendChild(el('div', '', h));
		var syncLine = el('p', 'panel-sub');
		syncLine.style.marginTop = '12px';
		syncLine.innerHTML = '<b style="color:var(--text)">' + fmt(P.syncUsers30d || 0) + '</b> people synced with the server in the last 30 days (' +
			fmt(P.uploadUsers30d || 0) + ' uploaded). 24 h is the current UTC day.';
		p.root.appendChild(syncLine);
	})();

	// =====================================================================
	// 4. growth
	// =====================================================================
	(function growthPanel() {
		var p = panel('span-8', 'Growth since launch', '');
		var metrics = {
			spots: { label: 'Spots', daily: FS.spotsDaily },
			images: { label: 'Images', daily: FS.imagesDaily },
			projects: { label: 'Field projects', daily: FS.projectsDaily },
			accounts: { label: 'Confirmed accounts', daily: A.dailyConfirmedSignups },
			experiments: { label: 'Experiments', daily: E.experimentsDaily }
		};
		var metric = 'spots', mode = 'total';
		segmented(p.head, Object.keys(metrics).map(function (k) { return { key: k, label: metrics[k].label }; }),
			function (k) { metric = k; render(); }, metric);
		var modeBox = el('div', 'panel-tools');
		modeBox.style.marginLeft = '8px';
		p.head.appendChild(modeBox);
		segmented(modeBox, [{ key: 'total', label: 'Running total' }, { key: 'month', label: 'Per month' }],
			function (k) { mode = k; render(); }, 'total');
		var holder = el('div', 'chart');
		var note = el('div', 'panel-sub');
		p.root.appendChild(holder);
		p.root.appendChild(note);

		function render() {
			var m = metrics[metric];
			var data = monthly(m.daily, mode === 'total');
			holder.innerHTML = '';
			var w = holder.clientWidth, h = 280, mg = { t: 10, r: 70, b: 26, l: 56 };
			var svg = d3.select(holder).append('svg').attr('width', w).attr('height', h)
				.attr('role', 'img').attr('aria-label', m.label + ' ' + (mode === 'total' ? 'running total' : 'per month'));
			if (!data.length) { svg.append('text').attr('x', 10).attr('y', 30).text('No data'); return; }
			var x = d3.scaleUtc().domain(d3.extent(data, function (d) { return d.date; })).range([mg.l, w - mg.r]);
			var y = d3.scaleLinear().domain([0, d3.max(data, function (d) { return d.value; }) || 1]).nice().range([h - mg.b, mg.t]);
			svg.append('g').attr('class', 'grid-line').attr('transform', 'translate(' + mg.l + ',0)')
				.call(d3.axisLeft(y).ticks(5).tickSize(-(w - mg.l - mg.r)).tickFormat(''));
			svg.append('g').attr('class', 'axis').attr('transform', 'translate(0,' + (h - mg.b) + ')')
				.call(d3.axisBottom(x).ticks(Math.max(2, Math.floor(w / 110))).tickSizeOuter(0));
			svg.append('g').attr('class', 'axis').attr('transform', 'translate(' + mg.l + ',0)')
				.call(d3.axisLeft(y).ticks(5).tickFormat(compact).tickSizeOuter(0));
			if (mode === 'total') {
				var grad = svg.append('defs').append('linearGradient').attr('id', 'gg').attr('x1', 0).attr('x2', 0).attr('y1', 0).attr('y2', 1);
				grad.append('stop').attr('offset', 0).attr('stop-color', C.s1).attr('stop-opacity', 0.35);
				grad.append('stop').attr('offset', 1).attr('stop-color', C.s1).attr('stop-opacity', 0.02);
				svg.append('path').datum(data).attr('fill', 'url(#gg)')
					.attr('d', d3.area().x(function (d) { return x(d.date); }).y0(y(0)).y1(function (d) { return y(d.value); }));
				var line = svg.append('path').datum(data).attr('fill', 'none').attr('stroke', C.s1).attr('stroke-width', 2)
					.attr('d', d3.line().x(function (d) { return x(d.date); }).y(function (d) { return y(d.value); }));
				if (!reduceMotion) {
					var len = line.node().getTotalLength();
					line.attr('stroke-dasharray', len + ' ' + len).attr('stroke-dashoffset', len)
						.transition().duration(1400).ease(d3.easeCubicOut).attr('stroke-dashoffset', 0);
				}
				var last = data[data.length - 1];
				svg.append('text').attr('class', 'direct-label').attr('x', x(last.date) + 6).attr('y', y(last.value) + 4).text(compact(last.value));
			} else {
				var bw = Math.max(1, (w - mg.l - mg.r) / data.length - 2);
				svg.append('g').selectAll('rect').data(data).join('rect')
					.attr('x', function (d) { return x(d.date) - bw / 2; }).attr('width', bw)
					.attr('y', function (d) { return y(d.value); })
					.attr('height', function (d) { return y(0) - y(d.value); })
					.attr('rx', Math.min(2, bw / 2)).attr('fill', C.s1);
				var peak = data.reduce(function (a, b) { return b.value > a.value ? b : a; }, data[0]);
				svg.append('text').attr('class', 'direct-label').attr('x', Math.min(x(peak.date) + 6, w - mg.r)).attr('y', y(peak.value) + 4)
					.text('peak ' + compact(peak.value) + ' (' + d3.utcFormat('%b %Y')(peak.date) + ')');
			}
			// crosshair
			var cross = svg.append('line').attr('stroke', C.text3).attr('y1', mg.t).attr('y2', h - mg.b).style('opacity', 0);
			var dot = svg.append('circle').attr('r', 4).attr('fill', C.s1).attr('stroke', '#0d1420').attr('stroke-width', 2).style('opacity', 0);
			var bis = d3.bisector(function (d) { return d.date; }).center;
			svg.append('rect').attr('x', mg.l).attr('y', mg.t).attr('width', w - mg.l - mg.r).attr('height', h - mg.t - mg.b)
				.attr('fill', 'transparent')
				.on('mousemove', function (e) {
					var d = data[bis(data, x.invert(d3.pointer(e)[0]))];
					cross.attr('x1', x(d.date)).attr('x2', x(d.date)).style('opacity', 1);
					dot.attr('cx', x(d.date)).attr('cy', y(d.value)).style('opacity', 1);
					showTip(e, d3.utcFormat('%B %Y')(d.date), fmt(d.value), m.label.toLowerCase() + (mode === 'total' ? ' in total' : ' that month'));
				})
				.on('mouseleave', function () { cross.style('opacity', 0); dot.style('opacity', 0); hideTip(); });
			var dated = sumDaily(m.daily);
			note.textContent = metric === 'spots'
				? 'Dated by creation time in the spot id. ' + fmt(FS.undatedSpots || 0) + ' spots have ids that carry no date and are not shown here (' + fmt(dated) + ' dated).'
				: metric === 'accounts'
					? 'Confirmed accounts by sign-up day. ' + fmt(A.confirmedWithoutSignupDate || 0) + ' older accounts have no sign-up date.'
					: metric === 'experiments' ? 'StraboExperimental experiments by creation time.' : 'Dated by creation time in the record id.';
		}
		renderers.push(render);
	})();

	// =====================================================================
	// 5. contributor distribution
	// =====================================================================
	(function contribPanel() {
		var p = panel('grow', 'How much people collect', 'accounts by number of spots', sideCol);
		var data = (CB.bins || []).map(function (b) { return { k: b[0], v: b[1] }; });
		var holder = el('div', 'chart');
		p.root.appendChild(holder);
		var note = el('div', 'panel-sub', fmt(CB.usersWithAnySpot || 0) + ' accounts own at least one spot node.');
		p.root.appendChild(note);
		renderers.push(function () {
			holder.innerHTML = '';
			var w = holder.clientWidth, h = 250, mg = { t: 18, r: 10, b: 26, l: 10 };
			var x = d3.scaleBand().domain(data.map(function (d) { return d.k; })).range([mg.l, w - mg.r]).padding(0.25);
			var y = d3.scaleLinear().domain([0, d3.max(data, function (d) { return d.v; }) || 1]).range([h - mg.b, mg.t]);
			var svg = d3.select(holder).append('svg').attr('width', w).attr('height', h).attr('role', 'img').attr('aria-label', 'Accounts by number of spots');
			svg.append('g').attr('class', 'axis').attr('transform', 'translate(0,' + (h - mg.b) + ')').call(d3.axisBottom(x).tickSizeOuter(0));
			svg.selectAll('rect').data(data).join('rect')
				.attr('x', function (d) { return x(d.k); }).attr('width', x.bandwidth())
				.attr('y', function (d) { return y(d.v); }).attr('height', function (d) { return y(0) - y(d.v); })
				.attr('rx', 3).attr('fill', C.s1)
				.on('mousemove', function (e, d) { showTip(e, d.k + ' spots', fmt(d.v), 'accounts'); })
				.on('mouseleave', hideTip);
			svg.selectAll('.v').data(data).join('text').attr('class', 'direct-label').attr('text-anchor', 'middle')
				.attr('x', function (d) { return x(d.k) + x.bandwidth() / 2; }).attr('y', function (d) { return y(d.v) - 5; })
				.text(function (d) { return fmt(d.v); });
		});
	})();

	// =====================================================================
	// 7. field season
	// =====================================================================
	(function seasonPanel() {
		var p = panel('span-4', 'When is field season?', "share of each hemisphere's spots by field month");
		var sea = X.seasonByMonth || { north: [], south: [] };
		var series = [
			{ key: 'north', label: 'Northern hemisphere', color: C.s1, v: sea.north || [] },
			{ key: 'south', label: 'Southern hemisphere', color: C.s2, v: sea.south || [] }
		];
		series.forEach(function (s) {
			var t = d3.sum(s.v) || 1;
			s.total = d3.sum(s.v);
			s.pts = s.v.map(function (n, i) { return { m: i, share: n / t, n: n }; });
		});
		var holder = el('div', 'chart');
		p.root.appendChild(holder);
		var legend = el('div', 'legend');
		series.forEach(function (s) { legend.appendChild(el('span', '', '<i style="background:' + s.color + '"></i>' + esc(s.label) + ' (' + compact(s.total) + ')')); });
		p.root.appendChild(legend);
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		renderers.push(function () {
			holder.innerHTML = '';
			var w = holder.clientWidth, h = 250, mg = { t: 12, r: 16, b: 24, l: 40 };
			var x = d3.scalePoint().domain(d3.range(12)).range([mg.l, w - mg.r]);
			var maxShare = d3.max(series, function (s) { return d3.max(s.pts, function (d) { return d.share; }); }) || 0.2;
			var y = d3.scaleLinear().domain([0, maxShare]).nice().range([h - mg.b, mg.t]);
			var svg = d3.select(holder).append('svg').attr('width', w).attr('height', h).attr('role', 'img').attr('aria-label', 'Field season by hemisphere');
			svg.append('g').attr('class', 'grid-line').attr('transform', 'translate(' + mg.l + ',0)').call(d3.axisLeft(y).ticks(4).tickSize(-(w - mg.l - mg.r)).tickFormat(''));
			svg.append('g').attr('class', 'axis').attr('transform', 'translate(0,' + (h - mg.b) + ')').call(d3.axisBottom(x).tickFormat(function (i) { return months[i]; }).tickSizeOuter(0));
			svg.append('g').attr('class', 'axis').attr('transform', 'translate(' + mg.l + ',0)').call(d3.axisLeft(y).ticks(4).tickFormat(d3.format('.0%')));
			series.forEach(function (s) {
				svg.append('path').datum(s.pts).attr('fill', 'none').attr('stroke', s.color).attr('stroke-width', 2)
					.attr('d', d3.line().curve(d3.curveMonotoneX).x(function (d) { return x(d.m); }).y(function (d) { return y(d.share); }));
				svg.append('g').selectAll('circle').data(s.pts).join('circle')
					.attr('cx', function (d) { return x(d.m); }).attr('cy', function (d) { return y(d.share); }).attr('r', 4)
					.attr('fill', s.color).attr('stroke', '#0d1420').attr('stroke-width', 2)
					.on('mousemove', function (e, d) { showTip(e, s.label + ', ' + months[d.m], d3.format('.1%')(d.share), fmt(d.n) + ' spots'); })
					.on('mouseleave', hideTip);
				var pk = s.pts.reduce(function (a, b) { return b.share > a.share ? b : a; }, s.pts[0] || { m: 0, share: 0 });
				svg.append('text').attr('class', 'direct-label').attr('text-anchor', 'middle')
					.attr('x', x(pk.m)).attr('y', y(pk.share) - 9).text(s.key === 'north' ? 'North' : 'South');
			});
		});
	})();

	// =====================================================================
	// 6. calendar heatmap
	// =====================================================================
	(function calendarPanel() {
		var p = panel('span-12', 'The last 12 months, day by day', 'spots created per day (UTC)');
		var holder = el('div', 'chart');
		p.root.appendChild(holder);
		var daily = FS.spotsDaily || {};
		var end = new Date(endDay + 'T00:00:00Z');
		var start = d3.utcSunday.floor(d3.utcDay.offset(end, -364));
		var days = d3.utcDays(start, d3.utcDay.offset(end, 1)).map(function (d) {
			var k = d.toISOString().substr(0, 10);
			return { d: d, k: k, v: daily[k] || 0 };
		});
		var max = d3.max(days, function (d) { return d.v; }) || 1;
		var total = d3.sum(days, function (d) { return d.v; });
		var busiest = days.reduce(function (a, b) { return b.v > a.v ? b : a; }, days[0]);
		var col = d3.scaleSequentialLog(heat).domain([1, max]);
		renderers.push(function () {
			holder.innerHTML = '';
			var weeks = d3.utcWeek.count(start, end) + 1;
			var w = holder.clientWidth, mg = { t: 18, l: 30, r: 4 };
			var cell = Math.max(6, Math.min(26, Math.floor((w - mg.l - mg.r) / weeks) - 2));
			var step = cell + 2;
			var h = mg.t + 7 * step + 30;
			var svg = d3.select(holder).append('svg').attr('width', Math.min(w, mg.l + weeks * step + mg.r)).attr('height', h)
				.attr('role', 'img').attr('aria-label', 'Spots created per day over the last year');
			['Mon', 'Wed', 'Fri'].forEach(function (n, i) {
				svg.append('text').attr('class', 'muted-label').attr('x', 0).attr('y', mg.t + (1 + i * 2) * step + cell - 2).text(n);
			});
			d3.utcMonths(d3.utcMonth.ceil(start), end).forEach(function (m) {
				svg.append('text').attr('class', 'muted-label').attr('x', mg.l + d3.utcWeek.count(start, m) * step).attr('y', 10)
					.text(d3.utcFormat('%b')(m));
			});
			svg.append('g').selectAll('rect').data(days).join('rect')
				.attr('x', function (d) { return mg.l + d3.utcWeek.count(start, d.d) * step; })
				.attr('y', function (d) { return mg.t + d.d.getUTCDay() * step; })
				.attr('width', cell).attr('height', cell).attr('rx', 2)
				.attr('fill', function (d) { return d.v ? col(d.v) : '#121c2b'; })
				.on('mousemove', function (e, d) { showTip(e, d3.utcFormat('%a %d %b %Y')(d.d), fmt(d.v), 'spots created'); })
				.on('mouseleave', hideTip);
			svg.append('text').attr('class', 'panel-sub').attr('x', mg.l).attr('y', h - 6)
				.style('fill', '#a9b6c8').style('font-size', '12px')
				.text(fmt(total) + ' spots in 12 months. Busiest day: ' + d3.utcFormat('%d %b %Y')(busiest.d) + ' with ' + fmt(busiest.v) + '.');
		});
	})();

	// =====================================================================
	// 8. orientation roses
	// =====================================================================
	(function rosePanel() {
		var p = panel('span-12', 'Every measurement, all at once', 'all orientations in the database, 10° bins');
		var box = el('div', 'roses');
		p.root.appendChild(box);
		var items = [
			{ bins: X.strikeBins, kind: 'rose', label: 'Strike', what: 'planar' },
			{ bins: X.dipBins, kind: 'hist', label: 'Dip', what: 'planar' },
			{ bins: X.trendBins, kind: 'rose', label: 'Trend', what: 'linear' },
			{ bins: X.plungeBins, kind: 'hist', label: 'Plunge', what: 'linear' }
		];
		var holders = items.map(function (it) {
			var cell = el('div', 'rose-cell');
			var chart = el('div', 'chart');
			cell.appendChild(chart);
			cell.appendChild(el('div', 'rose-cap', '<b>' + it.label + '</b> &middot; ' + compact(d3.sum(it.bins || [])) + ' ' + it.what));
			box.appendChild(cell);
			return chart;
		});
		renderers.push(function () {
			holders.forEach(function (h) { h.innerHTML = ''; }); // measure all cells empty
			items.forEach(function (it, i) {
				var holder = holders[i];
				var w = Math.min(holder.clientWidth, 300), bins = it.bins || [];
				if (it.kind === 'rose') rose(holder, w, bins, it);
				else hist(holder, w, bins, it);
			});
		});
		function rose(holder, w, bins, it) {
			var r = w / 2 - 14, max = d3.max(bins) || 1;
			var svg = d3.select(holder).append('svg').attr('width', w).attr('height', w).attr('role', 'img').attr('aria-label', it.label + ' rose diagram')
				.append('g').attr('transform', 'translate(' + w / 2 + ',' + w / 2 + ')');
			var rs = d3.scaleSqrt().domain([0, max]).range([0, r]);
			[0.25, 0.5, 1].forEach(function (f) {
				svg.append('circle').attr('r', rs(max * f)).attr('fill', 'none').attr('stroke', C.line);
			});
			['N', 'E', 'S', 'W'].forEach(function (n, i) {
				var a = i * Math.PI / 2;
				svg.append('text').attr('class', 'muted-label').attr('text-anchor', 'middle')
					.attr('x', Math.sin(a) * (r + 8)).attr('y', -Math.cos(a) * (r + 8) + 3).text(n);
			});
			var arc = d3.arc().innerRadius(0).padAngle(0.012);
			svg.selectAll('path').data(bins.map(function (v, i) { return { v: v, i: i }; })).join('path')
				.attr('d', function (d) {
					return arc({ startAngle: d.i * Math.PI / 18, endAngle: (d.i + 1) * Math.PI / 18, outerRadius: rs(d.v) });
				})
				.attr('fill', it.what === 'planar' ? C.s1 : C.s3).attr('fill-opacity', 0.9)
				.on('mousemove', function (e, d) { showTip(e, it.label + ' ' + d.i * 10 + '-' + (d.i * 10 + 9) + '°', fmt(d.v), 'measurements'); })
				.on('mouseleave', hideTip);
		}
		function hist(holder, w, bins, it) {
			var h = w, mg = { t: 16, r: 6, b: 22, l: 6 };
			var x = d3.scaleBand().domain(d3.range(bins.length)).range([mg.l, w - mg.r]).padding(0.15);
			var y = d3.scaleLinear().domain([0, d3.max(bins) || 1]).range([h - mg.b, mg.t]);
			var svg = d3.select(holder).append('svg').attr('width', w).attr('height', h).attr('role', 'img').attr('aria-label', it.label + ' histogram');
			svg.append('g').attr('class', 'axis').attr('transform', 'translate(0,' + (h - mg.b) + ')')
				.call(d3.axisBottom(x).tickValues([0, 3, 6, 8]).tickFormat(function (i) { return i * 10 + '°'; }).tickSizeOuter(0));
			svg.selectAll('rect').data(bins.map(function (v, i) { return { v: v, i: i }; })).join('rect')
				.attr('x', function (d) { return x(d.i); }).attr('width', x.bandwidth())
				.attr('y', function (d) { return y(d.v); }).attr('height', function (d) { return y(0) - y(d.v); })
				.attr('rx', 2).attr('fill', it.what === 'planar' ? C.s1 : C.s3)
				.on('mousemove', function (e, d) { showTip(e, it.label + ' ' + d.i * 10 + '-' + (d.i === 8 ? 90 : d.i * 10 + 9) + '°', fmt(d.v), 'measurements'); })
				.on('mouseleave', hideTip);
		}
	})();

	// =====================================================================
	// 9. ranked bars: rock types, structures
	// =====================================================================
	function rankedPanel(cls, title, sub, list, unit, labelFn) {
		var p = panel(cls, title, sub);
		var holder = el('div', 'chart');
		p.root.appendChild(holder);
		list = (list || []).slice(0, 12);
		renderers.push(function () {
			holder.innerHTML = '';
			var w = holder.clientWidth, rowH = 24, mg = { t: 2, r: 64, b: 2, l: Math.min(190, w * 0.42) };
			var h = mg.t + mg.b + list.length * rowH;
			var x = d3.scaleLinear().domain([0, d3.max(list, function (d) { return d[1]; }) || 1]).range([mg.l, w - mg.r]);
			var svg = d3.select(holder).append('svg').attr('width', w).attr('height', h).attr('role', 'img').attr('aria-label', title);
			var g = svg.selectAll('g').data(list).join('g').attr('transform', function (d, i) { return 'translate(0,' + (mg.t + i * rowH) + ')'; });
			g.append('text').attr('x', mg.l - 8).attr('y', rowH / 2 + 4).attr('text-anchor', 'end').style('fill', '#e8eef6').style('font-size', '12px')
				.text(function (d) { return labelFn ? labelFn(d[0]) : d[0]; });
			g.append('rect').attr('x', mg.l).attr('y', 5).attr('height', rowH - 10).attr('rx', 3).attr('fill', C.s1)
				.attr('width', reduceMotion ? function (d) { return x(d[1]) - mg.l; } : 0)
				.on('mousemove', function (e, d) { showTip(e, labelFn ? labelFn(d[0]) : d[0], fmt(d[1]), unit); })
				.on('mouseleave', hideTip)
				.transition().duration(reduceMotion ? 0 : 900).delay(function (d, i) { return i * 40; })
				.attr('width', function (d) { return Math.max(2, x(d[1]) - mg.l); });
			g.append('text').attr('class', 'muted-label').attr('y', rowH / 2 + 4)
				.attr('x', function (d) { return x(d[1]) + 6; }).text(function (d) { return compact(d[1]); });
		});
		return p;
	}
	function prettyRock(s) {
		var parts = String(s).split(':');
		return parts.length > 1 ? parts.slice(1).join(' / ').replace(/_/g, ' ') + ' (' + parts[0] + ')' : parts[0].replace(/_/g, ' ');
	}
	function pretty(s) { return String(s).replace(/_/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); }); }
	rankedPanel('span-4', 'Most described rock types', 'spots naming each rock type', X.rockTypes, 'spots', prettyRock);
	rankedPanel('span-4', 'What people measure', 'orientations by feature type', X.features, 'orientations', pretty);

	(function geomPanel() {
		var p = panel('span-4', 'Shapes on the map', 'spots by geometry');
		var g = F.geometryTypes || {};
		var groups = [
			{ k: 'Points', v: (g.Point || 0) + (g.MultiPoint || 0), c: C.s1 },
			{ k: 'Lines', v: (g.LineString || 0) + (g.MultiLineString || 0), c: C.s2 },
			{ k: 'Polygons', v: (g.Polygon || 0) + (g.MultiPolygon || 0), c: C.s3 },
			{ k: 'Other or none', v: (g.GeometryCollection || 0) + (g.None || 0), c: '#6f7f96' }
		];
		var total = d3.sum(groups, function (d) { return d.v; }) || 1;
		var holder = el('div', 'chart');
		p.root.appendChild(holder);
		var legend = el('div', 'legend');
		groups.forEach(function (d) { legend.appendChild(el('span', '', '<i style="background:' + d.c + '"></i>' + esc(d.k) + ' ' + pct(d.v, total))); });
		p.root.appendChild(legend);
		var rock = (X.rockClasses || []).filter(function (d) { return /^(igneous|sedimentary|metamorphic)$/.test(d[0]); });
		var rockTotal = d3.sum(rock, function (d) { return d[1]; }) || 1;
		var rockCols = { sedimentary: C.s1, metamorphic: C.s2, igneous: C.s3 };
		var holder2 = el('div', 'chart');
		holder2.style.marginTop = '18px';
		var cap = el('div', 'panel-title', 'Rock classes');
		cap.style.marginTop = '22px';
		p.root.appendChild(cap);
		p.root.appendChild(holder2);
		var legend2 = el('div', 'legend');
		rock.forEach(function (d) { legend2.appendChild(el('span', '', '<i style="background:' + rockCols[d[0]] + '"></i>' + esc(pretty(d[0])) + ' ' + pct(d[1], rockTotal))); });
		p.root.appendChild(legend2);
		function bar(holder, items, tot, unit) {
			holder.innerHTML = '';
			var w = holder.clientWidth, h = 36, x0 = 0;
			var svg = d3.select(holder).append('svg').attr('width', w).attr('height', h).attr('role', 'img').attr('aria-label', unit);
			items.forEach(function (d) {
				var bw = (d.v / tot) * w;
				svg.append('rect').attr('x', x0).attr('y', 0).attr('height', h).attr('width', Math.max(0, bw - 2)).attr('rx', 4).attr('fill', d.c)
					.on('mousemove', function (e) { showTip(e, d.k, fmt(d.v), pct(d.v, tot) + ' of ' + unit); })
					.on('mouseleave', hideTip);
				if (bw > 60) svg.append('text').attr('x', x0 + 8).attr('y', h / 2 + 4).style('fill', '#fff').style('font-size', '12px').style('pointer-events', 'none').text(pct(d.v, tot));
				x0 += bw;
			});
		}
		renderers.push(function () {
			bar(holder, groups, total, 'spots');
			bar(holder2, rock.map(function (d) { return { k: pretty(d[0]), v: d[1], c: rockCols[d[0]] }; }), rockTotal, 'spots with a rock class');
		});
	})();

	// =====================================================================
	// 10. the apps
	// =====================================================================
	(function appsPanel() {
		var p = panel('span-12', 'The StraboSpot family', '');
		var cards = [
			{ name: 'StraboField', stats: [
				[F.projects, 'projects'], [F.datasets, 'datasets'], [F.spots, 'spots'], [F.images, 'images'],
				[F.samples, 'samples'], [F.tags, 'tags'], [F.publicProjects, 'public projects'], [F.projectsWithSpots, 'projects with spots']] },
			{ name: 'StraboMicro', stats: [
				[M.projects, 'projects'], [M.owners, 'project owners'], [M.micrographs, 'micrographs'], [M.micrographsLocated, 'located micrographs'],
				[M.samples, 'samples'], [M.publicProjects, 'public projects'], [M.uploaders30d, 'people uploading, last 30 d']] },
			{ name: 'StraboExperimental', stats: [
				[E.projects, 'projects'], [E.experiments, 'experiments'], [E.owners, 'experimenters'], [E.publicProjects, 'public projects'],
				[E.facilities, 'facilities'], [E.apparatus, 'apparatus']] },
			{ name: 'StraboSamples', stats: [
				[SM.samples, 'samples'], [SM.igsns, 'IGSNs'], [SM.withFieldData, 'with field data'], [SM.withMicroData, 'with micro data'],
				[SM.withExperimentalData, 'with experimental data'], [SM.subsamples, 'subsamples'], [SM.located, 'with coordinates']] }
		];
		var box = el('div', 'apps');
		cards.forEach(function (c) {
			var h = '<div class="app-name">' + esc(c.name) + '</div><div class="app-stats">';
			c.stats.forEach(function (s) { h += '<div><div class="v">' + (s[0] == null ? '-' : fmt(s[0])) + '</div><div class="k">' + esc(s[1]) + '</div></div>'; });
			box.appendChild(el('div', 'app-card', h + '</div>'));
		});
		p.root.appendChild(box);
	})();

	// =====================================================================
	// 11. leaderboards
	// =====================================================================
	function board(cls, title, sub, rows) {
		var p = panel(cls, title, sub);
		var max = d3.max(rows, function (r) { return r.v; }) || 1;
		var ol = el('ol', 'board');
		rows.forEach(function (r) {
			var li = el('li', '', '<span class="name">' + esc(r.name) + (r.small ? '<small>' + esc(r.small) + '</small>' : '') + '</span>' +
				'<span class="val">' + fmt(r.v) + '</span><span class="bar" style="width:' + (100 * r.v / max).toFixed(1) + '%"></span>');
			ol.appendChild(li);
		});
		p.root.appendChild(ol);
	}
	board('span-4', 'Top collectors', 'distinct spots per account', (CB.topUsers || []).slice(0, 10).map(function (u) {
		return { name: u.name || ('User ' + u.userpkey), v: u.spots, small: '#' + u.userpkey };
	}));
	board('span-4', 'Biggest projects', 'spots per project', (X.topProjects || []).slice(0, 10).map(function (pr) {
		return { name: pr.name || ('Project ' + pr.projectId), v: pr.spots, small: pr.ownerName };
	}));
	(function microBoard() {
		var p = panel('span-4', 'Under the microscope', 'StraboMicro micrographs');
		var list = (M.minerals || []).slice(0, 6);
		var inst = (M.instruments || []).slice(0, 5);
		var h = '<div class="panel-title" style="margin-bottom:6px">Top minerals</div><ol class="board">';
		var mx = d3.max(list, function (d) { return d[1]; }) || 1;
		list.forEach(function (d) { h += '<li><span class="name">' + esc(pretty(d[0])) + '</span><span class="val">' + fmt(d[1]) + '</span><span class="bar" style="width:' + (100 * d[1] / mx).toFixed(1) + '%;background:' + C.s3 + '"></span></li>'; });
		h += '</ol><div class="panel-title" style="margin:14px 0 6px">Instruments</div><ol class="board">';
		var mi = d3.max(inst, function (d) { return d[1]; }) || 1;
		inst.forEach(function (d) { h += '<li><span class="name">' + esc(d[0]) + '</span><span class="val">' + fmt(d[1]) + '</span><span class="bar" style="width:' + (100 * d[1] / mi).toFixed(1) + '%;background:' + C.s2 + '"></span></li>'; });
		p.root.appendChild(el('div', '', h + '</ol>'));
	})();

	// =====================================================================
	// 12. platform
	// =====================================================================
	(function platformPanel() {
		var p = panel('span-5', 'Platform', '');
		var minis = [
			[P.dois, 'DOIs minted'], [P.versions, 'project versions saved'], [P.versionedProjects, 'projects with versions'],
			[P.offlineMapDownloads, 'offline map downloads'], [P.offlineTiles, 'offline map tiles delivered'], [P.syncUsers30d, 'people synced, last 30 d'],
			[P.fieldCamps, 'field camps'], [P.fieldCampAccounts, 'field camp student accounts'], [P.otaReleases, 'app updates published']
		];
		var h = '<div class="mini-tiles">';
		minis.forEach(function (m) { h += '<div class="mini"><div class="v">' + (m[0] == null ? '-' : compact(m[0])) + '</div><div class="k">' + esc(m[1]) + '</div></div>'; });
		p.root.appendChild(el('div', '', h + '</div>'));
		p.root.appendChild(el('p', 'panel-sub', fmt(A.unconfirmed || 0) + ' sign-ups never confirmed their email (mostly bots) and are not counted as accounts. ' +
			fmt(P.otaPhones7d || 0) + ' phones checked for app updates in the last 7 days.'));
	})();
	(function trafficPanel() {
		var p = panel('span-7', 'Sync traffic', 'StraboField spots moved per month');
		var rows = (P.syncTraffic || []).filter(function (r) { return r[2] === 'field app data'; });
		var months = Array.from(new Set(rows.map(function (r) { return r[0]; }))).sort();
		var series = [
			{ key: 'download', label: 'Downloaded to devices', color: C.s1 },
			{ key: 'upload', label: 'Uploaded from devices', color: C.s2 }
		];
		series.forEach(function (s) {
			s.pts = months.map(function (m) {
				var r = rows.find(function (x) { return x[0] === m && x[1] === s.key; });
				return { m: m, d: new Date(m + '-01T00:00:00Z'), v: r ? r[4] : 0, ev: r ? r[3] : 0 };
			});
		});
		var holder = el('div', 'chart');
		p.root.appendChild(holder);
		var legend = el('div', 'legend');
		series.forEach(function (s) { legend.appendChild(el('span', '', '<i style="background:' + s.color + '"></i>' + esc(s.label))); });
		p.root.appendChild(legend);
		p.root.appendChild(el('div', 'panel-sub', 'Logged since April 2024. A download sends a whole dataset, so it moves far more spots than an upload.'));
		renderers.push(function () {
			holder.innerHTML = '';
			if (!months.length) return;
			var w = holder.clientWidth, h = 240, mg = { t: 10, r: 20, b: 24, l: 56 };
			var x = d3.scaleUtc().domain(d3.extent(series[0].pts, function (d) { return d.d; })).range([mg.l, w - mg.r]);
			var y = d3.scaleLinear().domain([0, d3.max(series, function (s) { return d3.max(s.pts, function (d) { return d.v; }); }) || 1]).nice().range([h - mg.b, mg.t]);
			var svg = d3.select(holder).append('svg').attr('width', w).attr('height', h).attr('role', 'img').attr('aria-label', 'Sync traffic per month');
			svg.append('g').attr('class', 'grid-line').attr('transform', 'translate(' + mg.l + ',0)').call(d3.axisLeft(y).ticks(4).tickSize(-(w - mg.l - mg.r)).tickFormat(''));
			svg.append('g').attr('class', 'axis').attr('transform', 'translate(0,' + (h - mg.b) + ')').call(d3.axisBottom(x).ticks(Math.max(2, Math.floor(w / 110))).tickSizeOuter(0));
			svg.append('g').attr('class', 'axis').attr('transform', 'translate(' + mg.l + ',0)').call(d3.axisLeft(y).ticks(4).tickFormat(compact));
			series.forEach(function (s) {
				svg.append('path').datum(s.pts).attr('fill', 'none').attr('stroke', s.color).attr('stroke-width', 2)
					.attr('d', d3.line().x(function (d) { return x(d.d); }).y(function (d) { return y(d.v); }));
			});
			var cross = svg.append('line').attr('stroke', C.text3).attr('y1', mg.t).attr('y2', h - mg.b).style('opacity', 0);
			var bis = d3.bisector(function (d) { return d.d; }).center;
			svg.append('rect').attr('x', mg.l).attr('y', mg.t).attr('width', w - mg.l - mg.r).attr('height', h - mg.t - mg.b).attr('fill', 'transparent')
				.on('mousemove', function (e) {
					var i = bis(series[0].pts, x.invert(d3.pointer(e)[0]));
					var dn = series[0].pts[i], up = series[1].pts[i];
					cross.attr('x1', x(dn.d)).attr('x2', x(dn.d)).style('opacity', 1);
					showTip(e, d3.utcFormat('%B %Y')(dn.d), fmt(dn.v) + ' down', fmt(up.v) + ' up (' + fmt(dn.ev) + ' downloads, ' + fmt(up.ev) + ' uploads)');
				})
				.on('mouseleave', function () { cross.style('opacity', 0); hideTip(); });
		});
	})();

	// =====================================================================
	// 13. how we count
	// =====================================================================
	(function notesPanel() {
		var p = panel('span-12', 'How we count', '');
		var cov = S.indexCoverage ? (100 * S.indexCoverage).toFixed(1) + '%' : 'unknown';
		var notes = [
			'<b>Accounts</b> are sign-ups that confirmed their email and were not deleted. Bots sign up but never confirm, so the ' + fmt(A.unconfirmed || 0) + ' unconfirmed sign-ups are left out.',
			'<b>Spots, projects, datasets, images, samples and tags</b> count distinct ids in the field database. The same record can exist several times (collaboration copies, copied projects): there are ' + fmt(F.spotNodes || 0) + ' spot records for ' + fmt(F.spots || 0) + ' distinct spots.',
			'<b>Orientations, rock types, the map and the rose diagrams</b> come from the search index, one entry per distinct spot. It holds ' + fmt(X.spots || 0) + ' spots, ' + cov + ' of the field database.',
			'<b>Dates</b>: a StraboSpot id is the time it was created, so the growth chart, calendar and pulse use the id. The field season chart uses the date the geologist gave the spot.',
			'<b>Test and internal accounts are included</b> in every number, so totals run slightly high. Check the Top collectors list for accounts you know to be test or demo data.',
			'<b>Contributors</b> are accounts that own at least one project with a spot in it. "People adding spots" counts accounts whose spots were created in that window.',
			'<b>Micrographs</b> count distinct ids in the search index. <b>Samples</b> count the StraboSamples registry, which covers all three apps.'
		];
		p.root.appendChild(el('div', 'notes', notes.map(function (n) { return '<p>' + n + '</p>'; }).join('')));
		var info = 'Snapshot built ' + S.builtAt.replace('T', ' ').replace('+00:00', ' UTC') + ' in ' + S.buildSeconds + ' s.';
		(S.errors || []).forEach(function (e) { info += ' <span class="err">Section "' + esc(e.section) + '" failed: ' + esc(e.message) + '</span>'; });
		p.root.appendChild(el('div', 'build-info', info));
	})();

	// ---------- render + resize ----------
	function renderAll() { renderers.forEach(function (fn) { try { fn(); } catch (e) { console.error(e); } }); }
	renderAll();
	var lastW = window.innerWidth, t;
	window.addEventListener('resize', function () {
		clearTimeout(t);
		t = setTimeout(function () { if (window.innerWidth !== lastW) { lastW = window.innerWidth; renderAll(); } }, 200);
	});
})();
