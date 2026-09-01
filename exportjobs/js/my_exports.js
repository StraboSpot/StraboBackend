/**
 * File: exportjobs/js/my_exports.js
 * Description: My Exports page behavior (docs/ExportBuilder_Design.md §9.2):
 *              renders the job list from ?action=status, polls while work
 *              is active (2 s), slowly otherwise (10 s), pauses when the tab
 *              is hidden, and drives the per-row actions and the clear
 *              dialog. No window.confirm anywhere.
 *
 * @package    StraboSpot Web Site
 */
(function (window, document) {
	'use strict';
	var CFG = window.MY_EXPORTS;
	var BUILD = 'my-exports-m5-r1';
	if (window.console && console.log) console.log('[MyExports] ' + BUILD);

	function $(id) { return document.getElementById(id); }
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined && text !== null) e.textContent = text;
		return e;
	}
	function fmtN(n) { return Number(n).toLocaleString(); }
	function fmtBytes(n) {
		n = Number(n || 0);
		if (n >= 1073741824) return (n / 1073741824).toFixed(2) + ' GB';
		if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
		if (n >= 1024) return Math.round(n / 1024) + ' KB';
		return n + ' bytes';
	}
	function parseTs(s) {
		if (!s) return null;
		// ISO 8601 from the API; tolerate the PostgreSQL form too (space, microseconds, "-04" offset)
		var m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.\d+)?\s*(Z|[+-]\d{2})(:?\d{2})?$/.exec(String(s));
		var iso = m ? m[1] + 'T' + m[2] + (m[3] ? m[3].substring(0, 4) : '') + (m[4] === 'Z' ? 'Z' : m[4] + ':' + (m[5] ? m[5].replace(':', '') : '00')) : String(s);
		var d = new Date(iso);
		return isNaN(d.getTime()) ? null : d;
	}
	function fmtWhen(s) {
		var d = parseTs(s); if (!d) return '';
		var diff = (Date.now() - d.getTime()) / 1000;
		if (diff < 60) return 'just now';
		if (diff < 3600) return Math.round(diff / 60) + ' min ago';
		if (diff < 86400) return Math.round(diff / 3600) + ' h ago';
		return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}
	function fmtUntil(s) {
		var d = parseTs(s); if (!d) return '';
		var diff = (d.getTime() - Date.now()) / 1000;
		if (diff <= 0) return 'expiring';
		if (diff < 3600) return 'expires in ' + Math.max(1, Math.round(diff / 60)) + ' min';
		if (diff < 86400) return 'expires in ' + Math.round(diff / 3600) + ' h';
		return 'expires in ' + Math.round(diff / 86400) + ' day' + (Math.round(diff / 86400) === 1 ? '' : 's');
	}
	function phaseLabel(job) {
		var p = job.phase || '';
		if (p === 'resolve') return 'Resolving selection';
		if (p === 'children') return 'Adding nested spots';
		if (p === 'gather') return 'Gathering spots';
		if (p.indexOf('format:') === 0) return 'Writing ' + p.substring(7);
		if (p === 'package') return 'Packaging';
		if (p === 'zip') return 'Zipping';
		return job.status === 'queued' ? 'Waiting for a worker' : 'Working';
	}

	// ---------------------------------------------------------------- api
	function api(action, body, method) {
		var opts = { method: method || (body ? 'POST' : 'GET'), credentials: 'same-origin', headers: {} };
		if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
		return fetch(CFG.api + '?action=' + action + (method === 'GET' && body ? '' : ''), opts)
			.then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
	}

	// ---------------------------------------------------------------- render
	var jobs = [], openDetails = {};
	function render() {
		var list = $('me-list');
		list.innerHTML = '';
		if (!jobs.length) {
			var e = el('div', 'me-empty');
			e.appendChild(document.createTextNode('No exports yet. '));
			var a = el('a', null, 'Build one'); a.href = CFG.builder;
			e.appendChild(a); e.appendChild(document.createTextNode(' from your StraboField projects.'));
			list.appendChild(e);
			return;
		}
		jobs.forEach(function (j) { list.appendChild(renderJob(j)); });
	}
	function renderJob(j) {
		var card = el('div', 'me-job' + (openDetails[j.uuid] ? ' me-open' : ''));
		card.setAttribute('data-uuid', j.uuid);
		card.setAttribute('data-status', j.status);
		var head = el('div', 'me-job-head');
		head.appendChild(el('span', 'me-summary', j.summary || 'Export'));
		head.appendChild(el('span', 'me-pill me-pill-' + j.status, j.status));
		card.appendChild(head);

		var meta = el('div', 'me-meta');
		meta.appendChild(el('span', null, 'created ' + fmtWhen(j.created_at)));
		if (j.status === 'done') {
			if (j.item_count !== null) meta.appendChild(el('span', null, fmtN(j.item_count) + ' spot' + (j.item_count === 1 ? '' : 's') + (j.child_count ? ' + ' + fmtN(j.child_count) + ' nested' : '')));
			meta.appendChild(el('span', null, fmtBytes(j.result_bytes)));
			meta.appendChild(el('span', null, fmtUntil(j.expires_at)));
		} else if (j.status === 'expired') {
			meta.appendChild(el('span', null, 'expired ' + fmtWhen(j.expired_at)));
		} else if (j.status === 'failed' || j.status === 'cancelled') {
			meta.appendChild(el('span', null, j.status + ' ' + fmtWhen(j.finished_at)));
		}
		if (j.origin === 'rerun') meta.appendChild(el('span', null, 're-run'));
		if (j.email_on_done) meta.appendChild(el('span', null, 'email on completion'));
		card.appendChild(meta);

		if (j.status === 'queued' || j.status === 'running') {
			var prog = el('div', 'me-progress');
			var bar = el('div', 'me-bar'); var fill = el('div');
			var det = j.progress_total > 0;
			if (det) fill.style.width = Math.min(100, Math.round(100 * j.progress_done / j.progress_total)) + '%';
			else bar.classList.add('me-indeterminate');
			bar.appendChild(fill); prog.appendChild(bar);
			var note = phaseLabel(j);
			if (det) note += ' ' + fmtN(j.progress_done) + ' of ' + fmtN(j.progress_total);
			if (j.progress_note) note += ' (' + j.progress_note + ')';
			prog.appendChild(el('div', 'me-note', note));
			card.appendChild(prog);
		}
		if (j.status === 'failed' && j.error_text) card.appendChild(el('div', 'me-error', j.error_text));
		if (j.status === 'expired') card.appendChild(el('div', 'me-meta', 'The file was removed. Re-run to build it again.'));

		var acts = el('div', 'me-row-actions');
		if (j.status === 'done') {
			var dl = el('a', 'button primary small', 'Download'); dl.href = CFG.download + '?j=' + encodeURIComponent(j.uuid); dl.setAttribute('data-act', 'download');
			acts.appendChild(dl);
		}
		if (j.status === 'queued' || j.status === 'running') {
			var cn = el('a', 'button small', 'Cancel'); cn.href = 'javascript:void(0);'; cn.setAttribute('data-act', 'cancel');
			cn.addEventListener('click', function () { doCancel(j); });
			acts.appendChild(cn);
		} else {
			var rr = el('a', 'button small', 'Re-run'); rr.href = 'javascript:void(0);'; rr.setAttribute('data-act', 'rerun');
			rr.addEventListener('click', function () { doRerun(j, rr); });
			acts.appendChild(rr);
			var ed = el('a', 'me-link', 'Edit and re-run'); ed.href = CFG.builder + '?from=' + encodeURIComponent(j.uuid); ed.setAttribute('data-act', 'edit');
			acts.appendChild(ed);
		}
		var dt = el('a', 'me-link', openDetails[j.uuid] ? 'Hide details' : 'Details'); dt.href = 'javascript:void(0);'; dt.setAttribute('data-act', 'details');
		dt.addEventListener('click', function () { toggleDetails(j, card, dt); });
		acts.appendChild(dt);
		card.appendChild(acts);

		var details = el('div', 'me-details');
		var pre = el('pre', null, openDetails[j.uuid] || '');
		details.appendChild(pre);
		card.appendChild(details);
		return card;
	}

	function toggleDetails(j, card, link) {
		if (openDetails[j.uuid]) { delete openDetails[j.uuid]; card.classList.remove('me-open'); link.textContent = 'Details'; return; }
		link.textContent = 'Loading…';
		api('detail&uuid=' + encodeURIComponent(j.uuid)).then(function (r) {
			if (!r.ok) { link.textContent = 'Details'; showNotice(r.error || 'Could not load details.', false); return; }
			var jb = r.job, lines = [];
			lines.push('Export: ' + (jb.summary || ''));
			lines.push('Status: ' + jb.status + (jb.error_text ? ' (' + jb.error_text + ')' : ''));
			lines.push('Formats: ' + (jb.formats || []).join(', ') + ((jb.extras || []).length ? '; extras: ' + jb.extras.join(', ') : ''));
			lines.push('Layout: ' + jb.layout + '   Filters: ' + jb.filter_count + '   Projects: ' + jb.project_count);
			if (jb.notes) lines.push('Notes: ' + jb.notes);
			if (jb.readme) { lines.push(''); lines.push(jb.readme.trim()); }
			openDetails[j.uuid] = lines.join('\n');
			card.querySelector('.me-details pre').textContent = openDetails[j.uuid];
			card.classList.add('me-open'); link.textContent = 'Hide details';
		}).catch(function () { link.textContent = 'Details'; });
	}
	function doCancel(j) {
		api('cancel', { uuid: j.uuid }).then(function (r) {
			if (!r.ok) showNotice(r.error || 'Could not cancel.', false);
			refresh();
		});
	}
	function doRerun(j, btn) {
		btn.textContent = 'Queuing…';
		api('rerun', { uuid: j.uuid }).then(function (r) {
			if (!r.ok) { btn.textContent = 'Re-run'; showNotice(r.error || 'Could not re-run.', false); return; }
			showNotice('Re-run queued. Progress shows below.', true);
			refresh();
		}).catch(function () { btn.textContent = 'Re-run'; });
	}

	// ---------------------------------------------------------------- clear dialog
	var dialogOk = null;
	function openDialog(title, text, onOk) {
		$('me-dialog-title').textContent = title; $('me-dialog-text').textContent = text;
		dialogOk = onOk;
		$('me-dialog').classList.add('me-show'); $('me-dialog-back').classList.add('me-show');
		$('me-dialog-ok').focus();
	}
	function closeDialog() { $('me-dialog').classList.remove('me-show'); $('me-dialog-back').classList.remove('me-show'); dialogOk = null; }
	$('me-dialog-cancel').addEventListener('click', closeDialog);
	$('me-dialog-back').addEventListener('click', closeDialog);
	$('me-dialog-ok').addEventListener('click', function () { var f = dialogOk; closeDialog(); if (f) f(); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && dialogOk) closeDialog(); });
	function clearWhich(which) {
		var n = jobs.filter(function (j) { return which === 'expired' ? j.status === 'expired' : (j.status !== 'queued' && j.status !== 'running'); }).length;
		if (!n) { showNotice('Nothing to clear.', true); return; }
		openDialog(which === 'expired' ? 'Clear expired exports?' : 'Clear finished exports?',
			n + ' export' + (n === 1 ? '' : 's') + ' will be removed from this list. Queued and running exports stay. This cannot be undone.',
			function () { api('clear', { which: which }).then(function (r) { showNotice(r.ok ? 'Cleared ' + r.cleared + ' export' + (r.cleared === 1 ? '' : 's') + '.' : (r.error || 'Could not clear.'), !!r.ok); refresh(); }); });
	}
	$('me-clear-finished').addEventListener('click', function () { clearWhich('finished'); });
	$('me-clear-expired').addEventListener('click', function () { clearWhich('expired'); });

	// ---------------------------------------------------------------- notices
	function showNotice(text, ok) {
		var n = $('me-notice');
		n.textContent = text; n.className = 'me-notice' + (ok ? ' me-notice-ok' : ''); n.style.display = '';
	}
	function initialNotice() {
		var no = CFG.notice; if (!no) return;
		if (no.kind === 'new') showNotice('Your export is queued. This page updates as it builds; you can leave and come back.', true);
		else if (no.kind === 'expired') showNotice('That export has expired and its file was removed. Re-run it below to build it again.', false);
		else if (no.kind === 'notready') showNotice('That export is not ready yet.', false);
		if (no.uuid) setTimeout(function () {
			var c = document.querySelector('.me-job[data-uuid="' + no.uuid + '"]');
			if (c) { c.classList.add('me-flash'); c.scrollIntoView({ block: 'center' }); }
		}, 300);
	}

	// ---------------------------------------------------------------- polling
	var timer = null, active = false;
	function schedule() {
		if (timer) clearTimeout(timer);
		if (document.hidden) return;   // resumed by visibilitychange
		timer = setTimeout(refresh, active ? 2000 : 10000);
	}
	function refresh() {
		return api('status').then(function (r) {
			if (!r.ok) { if (r._status === 401) { window.location.href = '/login.php'; return; } showNotice(r.error || 'Could not load exports.', false); schedule(); return; }
			jobs = r.jobs || [];
			active = jobs.some(function (j) { return j.status === 'queued' || j.status === 'running'; });
			render();
			schedule();
		}).catch(function () { schedule(); });
	}
	document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); else if (timer) { clearTimeout(timer); timer = null; } });

	refresh().then(initialNotice);
	window.MyExportsPage = { refresh: refresh, BUILD: BUILD };
})(window, document);
