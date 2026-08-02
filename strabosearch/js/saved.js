/**
 * File: saved.js
 * Description: StraboSearch saved searches (§6.6) — "My searches"
 *              list modal (Load / Rename / Delete), "Save current" modal
 *              with client-side overwrite confirmation (the API is an
 *              upsert on (user, name)), and the §6.6.3 silent legacy
 *              translation: old /fullsearch rows (keyword-pill JSON) load
 *              as one U1 keyword row per pill; the legacy rows themselves
 *              are never modified.
 *
 * @package    StraboSpot Web Site — StraboSearch
 */

(function (window, document) {
	'use strict';

	var CFG = window.STRABO_SEARCH;
	var C = window.SSCatalog;
	var loadIntoBuilder = function () {};   // set by init

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}

	var modal = null;

	function closeModal() {
		if (!modal) return;
		modal.backdrop.remove();
		modal.card.remove();
		modal = null;
	}

	function openModal(titleText) {
		closeModal();
		var backdrop = el('div', 'grayOut');
		backdrop.style.display = 'inline';
		backdrop.addEventListener('click', closeModal);
		var card = el('div', 'ss-modal-card');
		card.setAttribute('role', 'dialog');
		card.setAttribute('aria-label', titleText);
		card.appendChild(el('h3', null, titleText));
		document.body.appendChild(backdrop);
		document.body.appendChild(card);
		modal = { backdrop: backdrop, card: card };
		document.addEventListener('keydown', escClose);
		return card;
	}

	function escClose(ev) {
		if (ev.key === 'Escape') {
			closeModal();
			document.removeEventListener('keydown', escClose);
		}
	}

	function api(action, body) {
		var opts = body
			? { method: 'POST', headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body) }
			: {};
		return fetch(CFG.api + '?action=' + action, opts).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) throw new Error(j.Error || (action + ' failed'));
				return j;
			});
		});
	}

	// ══════════════════════════════════════════════════════════════════
	// §6.6.3 — legacy fullsearches row → v1 DSL (silent AND translation)
	// ══════════════════════════════════════════════════════════════════

	function legacyToDsl(searchJson) {
		var criteria = [];
		var params = (searchJson && searchJson.params) || [];
		params.forEach(function (p) {
			(p.constraints || []).forEach(function (con) {
				if (con.constraintType === 'keyword' && con.constraintValue) {
					criteria.push({ id: 'U1', value: String(con.constraintValue) });
				}
				// Any non-keyword legacy constraint types (Field builder
				// extras) fold into keyword rows too — the 0.1 autopsy
				// found the deployed UI only ever emitted keywords.
				else if (con.constraintValue) {
					criteria.push({ id: 'U1', value: String(con.constraintValue) });
				}
			});
		});
		return {
			subsystems: ['field', 'micro', 'exp', 'samples'],
			criteria: criteria
		};
	}

	// ══════════════════════════════════════════════════════════════════
	// "My searches ▾" (§6.6.1)
	// ══════════════════════════════════════════════════════════════════

	function openMySearches() {
		var card = openModal('My searches');
		card.appendChild(el('div', 'ss-quiet-prompt', 'Loading…'));

		Promise.all([
			api('saved_list'),
			api('legacy_list').catch(function () { return { legacy: [] }; })
		]).then(function (both) {
			var saved = both[0].saved || [];
			var legacy = both[1].legacy || [];
			card.querySelector('.ss-quiet-prompt').remove();

			if (!saved.length && !legacy.length) {
				card.appendChild(el('div', 'ss-quiet-prompt',
					"You haven't saved any searches yet."));
			}

			saved.forEach(function (s) {
				card.appendChild(savedRow(s));
			});

			if (legacy.length) {
				var h = el('h3', null, 'From the old search');
				h.style.marginTop = '1em';
				card.appendChild(h);
				card.appendChild(el('div', 'ss-legacy-note',
					'Saved on the previous search page — loading converts them to the new criteria.'));
				legacy.forEach(function (s) {
					card.appendChild(legacyRow(s));
				});
			}

			var actions = el('div', 'ss-modal-actions');
			var close = el('a', 'button small', 'Close');
			close.href = 'javascript:void(0);';
			close.addEventListener('click', closeModal);
			actions.appendChild(close);
			card.appendChild(actions);
		}).catch(function (e) {
			card.appendChild(el('div', 'ss-error-card', e.message));
		});
	}

	function savedRow(s) {
		var row = el('div', 'ss-saved-row');
		row.appendChild(el('div', 'ss-saved-name', s.search_name));
		row.appendChild(el('div', 'ss-saved-summary', C.summarizeDsl(s.dsl || {})));
		var actions = el('div', 'ss-saved-actions');

		var load = el('a', null, 'Load');
		load.href = 'javascript:void(0);';
		load.addEventListener('click', function () {
			closeModal();
			loadIntoBuilder(s.dsl, true);
		});

		var rename = el('a', null, 'Rename');
		rename.href = 'javascript:void(0);';
		rename.addEventListener('click', function () {
			var name = window.prompt('New name:', s.search_name);
			if (!name || name.trim() === '' || name === s.search_name) return;
			api('saved_update', { pkey: s.saved_search_pkey, search_name: name.trim() })
				.then(function () { closeModal(); openMySearches(); })
				.catch(function (e) { alert(e.message); });
		});

		var del = el('a', null, 'Delete');
		del.href = 'javascript:void(0);';
		del.addEventListener('click', function () {
			if (!window.confirm('Delete saved search "' + s.search_name + '"?')) return;
			api('saved_delete', { pkey: s.saved_search_pkey })
				.then(function () { closeModal(); openMySearches(); })
				.catch(function (e) { alert(e.message); });
		});

		actions.appendChild(load);
		actions.appendChild(rename);
		actions.appendChild(del);
		row.appendChild(actions);
		return row;
	}

	function legacyRow(s) {
		var row = el('div', 'ss-saved-row');
		row.appendChild(el('div', 'ss-saved-name', s.search_name || '(unnamed)'));
		var dsl = legacyToDsl(s.search_json);
		row.appendChild(el('div', 'ss-saved-summary',
			C.summarizeDsl(dsl) + (s.date_saved ? '  ·  saved ' + s.date_saved : '')));
		var actions = el('div', 'ss-saved-actions');
		var load = el('a', null, 'Load');
		load.href = 'javascript:void(0);';
		load.addEventListener('click', function () {
			closeModal();
			loadIntoBuilder(dsl, true);
		});
		actions.appendChild(load);
		row.appendChild(actions);
		return row;
	}

	// ══════════════════════════════════════════════════════════════════
	// "Save current" (§6.6.2)
	// ══════════════════════════════════════════════════════════════════

	function openSaveCurrent(getDsl) {
		var dsl = getDsl();
		if (!dsl.criteria.length &&
			(!dsl.subsystems || dsl.subsystems.length === 4)) {
			alert('Compose a search first — there is nothing to save yet.');
			return;
		}
		var card = openModal('Save current search');
		card.appendChild(el('div', 'ss-saved-summary', C.summarizeDsl(dsl)));

		var input = el('input');
		input.type = 'text';
		input.placeholder = 'Name this search…';
		input.maxLength = 200;
		input.setAttribute('aria-label', 'Saved search name');
		input.style.marginTop = '0.8em';
		card.appendChild(input);

		var actions = el('div', 'ss-modal-actions');
		var save = el('a', 'button primary small', 'Save');
		var cancel = el('a', 'button small', 'Cancel');
		save.href = cancel.href = 'javascript:void(0);';
		cancel.addEventListener('click', closeModal);

		function doSave() {
			var name = input.value.trim();
			if (!name) { input.focus(); return; }
			api('saved_list').then(function (j) {
				var clash = (j.saved || []).some(function (s) {
					return s.search_name === name;
				});
				if (clash && !window.confirm(
					'A saved search named "' + name + '" already exists. Overwrite it?')) {
					input.focus();
					input.select();
					return;
				}
				return api('saved_create', { search_name: name, dsl: dsl })
					.then(function () { closeModal(); });
			}).catch(function (e) { alert(e.message); });
		}
		save.addEventListener('click', doSave);
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter') { ev.preventDefault(); doSave(); }
		});

		actions.appendChild(save);
		actions.appendChild(cancel);
		card.appendChild(actions);
		input.focus();
	}

	window.SSSaved = {
		init: function (opts) {
			loadIntoBuilder = opts.loadIntoBuilder;
		},
		openMySearches: openMySearches,
		openSaveCurrent: openSaveCurrent
	};

})(window, document);
