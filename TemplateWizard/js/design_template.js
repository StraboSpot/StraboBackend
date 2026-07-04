/**
 * Template Wizard - Design Template (Page 2)
 * Handles HandsonTable initialization, template save (ajax.php), and
 * submission of pasted data to the review screen.
 *
 * Expects global variables to be set:
 * - window.templateMethod:   'existing' or 'new'
 * - window.templateColumns:  array of column headers (row 0 of the grid)
 * - window.templateSpecCols: array of column descriptors parallel to headers
 * - window.templatePkey:     saved template pkey ('' when new)
 * - window.headerMap:        display header -> column descriptor for every
 *                            known StraboField catalog column
 */

document.addEventListener('DOMContentLoaded', function() {
	const templateMethod = window.templateMethod;
	const columns = window.templateColumns;
	let templatePkey = window.templatePkey || '';

	// Grid layout: row 0 = color-coded section band (computed, read-only),
	// row 1 = headers, data from row 2. HDR names the header row so the
	// offset reads at every use site.
	const HDR = 1;

	// Create initial data: band placeholder + header row
	const initialData = [new Array(columns.length).fill(''), columns.slice()];

	// Add empty rows for data entry
	for (let i = 0; i < 50; i++) {
		initialData.push(new Array(columns.length).fill(''));
	}

	const container = document.getElementById('hot-container');
	const saveSection = document.getElementById('saveSection');
	const templateNameInput = document.getElementById('template_name');
	const saveBtn = document.getElementById('saveBtn');
	const errorModal = document.getElementById('errorModal');
	const modalTitle = document.getElementById('modalTitle');
	const closeModal = document.getElementById('closeModal');
	const projectInfo = document.getElementById('project_info');
	const projectSelect = document.getElementById('project_id');
	const downloadTemplateLink = document.getElementById('downloadTemplateLink');
	const uploadFileLink = document.getElementById('uploadFileLink');
	const fileInput = document.getElementById('fileInput');
	const errorMessage = document.getElementById('errorMessage');
	const addColumnSelect = document.getElementById('add_column_select');
	const addColumnBtn = document.getElementById('addColumnBtn');

	// Headers that map to real StraboField columns (or system columns) are
	// read-only in the grid; everything else is a custom column.
	function isKnownHeader(h) {
		return h !== null && h !== '' && Object.prototype.hasOwnProperty.call(window.headerMap, h);
	}

	// Section key for a header — drives the band row's label + color.
	// orientation_type/role band with the orientation section they discriminate.
	function sectionForHeader(h) {
		if (!isKnownHeader(h)) { return 'custom'; }
		const d = window.headerMap[h];
		if (d.kind === 'system') {
			return (d.key === 'orientation_type' || d.key === 'orientation_role') ? 'orientation' : 'system';
		}
		return d.group;
	}

	// The orientation_type value on a given row (short form), for per-row
	// feature_type vocab. Tolerates label-ish variants.
	function rowOtype(instance, row) {
		const headers = instance.getDataAtRow(HDR);
		for (let c = 0; c < headers.length; c++) {
			if (headers[c] === 'orientation_type') {
				const raw = instance.getDataAtCell(row, c);
				if (raw === null || raw === '') { return null; }
				const t = String(raw).toLowerCase().trim().replace(/[\s\-]+/g, '_');
				if (t === 'planar' || t === 'linear' || t === 'tabular_zone') { return t; }
				if (t === 'tabular') { return 'tabular_zone'; }
				return null;
			}
		}
		return null;
	}

	function showError(msg) {
		modalTitle.textContent = 'Error';
		modalTitle.style.color = '#bf616a';
		errorMessage.textContent = msg;
		errorModal.style.display = 'flex';
	}

	function showInfo(title, msg) {
		modalTitle.textContent = title;
		modalTitle.style.color = '#a3be8c';
		errorMessage.textContent = msg;
		errorModal.style.display = 'flex';
	}

	templateNameInput.addEventListener('input', updateSaveButtonVisibility);
	projectSelect.addEventListener('change', updateSaveButtonVisibility);

	closeModal.addEventListener('click', function() {
		errorModal.style.display = 'none';
	});
	errorModal.addEventListener('click', function(e) {
		if (e.target === errorModal) {
			errorModal.style.display = 'none';
		}
	});

	// ---- Download template: server-generated workbook (locked id column,
	// vocabulary dropdowns, embedded template spec). Saves first so the
	// download always matches what is on screen.
	downloadTemplateLink.addEventListener('click', function(e) {
		e.preventDefault();
		saveTemplate(function(ok) {
			if (ok) {
				window.location = 'export.php?what=template&template_id=' + encodeURIComponent(templatePkey) + '&format=xlsx';
			}
		});
	});

	// ---- Load a file INTO the grid (client-side parse; headers must match) ----
	uploadFileLink.addEventListener('click', function(e) {
		e.preventDefault();
		fileInput.click();
	});

	fileInput.addEventListener('change', function(e) {
		const file = e.target.files[0];
		if (!file) return;

		const maxSize = 5 * 1024 * 1024; // 5MB
		if (file.size > maxSize) {
			showError('Error! File size exceeds 5MB limit. Larger files can be uploaded directly on the Import page.');
			fileInput.value = '';
			return;
		}

		const reader = new FileReader();
		reader.onload = function(evt) {
			try {
				const data = new Uint8Array(evt.target.result);
				const workbook = XLSX.read(data, { type: 'array' });

				// Prefer the template's Data sheet when present
				const sheetName = workbook.SheetNames.indexOf('Data') !== -1 ? 'Data' : workbook.SheetNames[0];
				const worksheet = workbook.Sheets[sheetName];
				const fileData = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' });

				if (fileData.length === 0) {
					showError('Error! File is empty or could not be read.');
					fileInput.value = '';
					return;
				}

				const currentHeaders = orderedGrid()[0];

				// Generated files carry a section-band row above the headers —
				// locate the header row as the first row matching the current
				// headers exactly, so both banded and band-less files load.
				let headerIdx = -1;
				const scanMax = Math.min(fileData.length, 5);
				for (let r = 0; r < scanMax; r++) {
					const rowVals = fileData[r].map(function (h) { return (h === null) ? '' : String(h); });
					if (rowVals.length === currentHeaders.length) {
						let allMatch = true;
						for (let i = 0; i < currentHeaders.length; i++) {
							if (String(currentHeaders[i]) !== rowVals[i]) { allMatch = false; break; }
						}
						if (allMatch) { headerIdx = r; break; }
					}
				}
				if (headerIdx === -1) {
					// Keep the original, specific mismatch messages (vs row 0).
					const fileHeaders = fileData[0].map(function (h) { return (h === null) ? '' : String(h); });
					if (currentHeaders.length !== fileHeaders.length) {
						showError('Error! Column count mismatch. File has ' + fileHeaders.length +
							' columns, template has ' + currentHeaders.length + ' columns. ' +
							'To import a file with different columns, use the Import page instead.');
					} else {
						let msg = 'Error! Column headers do not match.';
						for (let i = 0; i < currentHeaders.length; i++) {
							if (String(currentHeaders[i]) !== fileHeaders[i]) {
								msg = 'Error! Column headers do not match. Expected "' +
									currentHeaders[i] + '" at position ' + (i + 1) + ', but found "' + fileHeaders[i] + '".';
								break;
							}
						}
						showError(msg);
					}
					fileInput.value = '';
					return;
				}

				const bandRow = new Array(currentHeaders.length).fill('');
				hot.loadData([bandRow].concat(fileData.slice(headerIdx)));
				refreshBand();
				checkTableData();
				updateSaveButtonVisibility();
				fileInput.value = '';

			} catch (error) {
				showError('Error! Could not parse file. Please ensure it is a valid CSV or Excel file.');
				fileInput.value = '';
			}
		};
		reader.readAsArrayBuffer(file);
	});

	// ---- Add a catalog column ----
	addColumnBtn.addEventListener('click', function(e) {
		e.preventDefault();
		const header = addColumnSelect.value;
		if (header === '') { return; }
		const headers = orderedGrid()[0];
		if (headers.indexOf(header) !== -1) {
			showError('That column is already in the template.');
			return;
		}
		const visualCol = hot.countCols();
		hot.alter('insert_col', visualCol, 1);
		hot.setDataAtCell(HDR, visualCol, header);
		refreshBand();
		addColumnSelect.value = '';
		updateSaveButtonVisibility();
	});

	// Initialize Handsontable (6.2.2 — last MIT release)
	const hot = new Handsontable(container, {
		data: initialData,
		colHeaders: true,
		rowHeaders: true,
		width: '100%',
		height: 500,
		colWidths: 150,
		licenseKey: 'non-commercial-and-evaluation',
		manualColumnMove: true,
		manualColumnResize: true,
		copyPaste: true,
		fillHandle: true,
		mergeCells: [],
		contextMenu: ['col_left', 'col_right', 'remove_col', '---------', 'row_above', 'row_below', 'remove_row', '---------', 'undo', 'redo'],
		minSpareRows: 5,
		autoWrapRow: false,
		autoWrapCol: false,
		cells: function(row, col) {
			const cellProperties = {};
			const instance = this.instance;
			if (row === 0) {
				// section band — computed, never hand-edited
				cellProperties.readOnly = true;
				cellProperties.className = 'tw-band tw-band-' + sectionForHeader(instance.getDataAtCell(HDR, col));
				return cellProperties;
			}
			if (row === HDR) {
				const cellValue = instance.getDataAtCell(row, col);
				if (isKnownHeader(cellValue)) {
					// StraboField catalog / system header — read-only
					cellProperties.readOnly = true;
					cellProperties.className = 'htCenter htMiddle htDimmed';
				} else {
					// Custom column header — editable
					cellProperties.readOnly = false;
					cellProperties.className = 'htCenter htMiddle';
				}
				return cellProperties;
			}

			// ---- data rows: per-column behavior from the header ----
			const header = instance.getDataAtCell(HDR, col);
			if (header === 'strabo_internal_id' || header === 'geometry_type') {
				// managed by StraboSpot — never hand-entered (updates flow
				// through export -> edit -> Import page)
				cellProperties.readOnly = true;
				cellProperties.className = 'htDimmed';
				return cellProperties;
			}
			const v = (header !== null && header !== '') ? window.columnVocab[header] : undefined;
			if (v && v.values) {
				cellProperties.type = 'dropdown';
				let src = v.values;
				if (v.by_type) {
					// feature_type vocab depends on the row's orientation_type
					const ot = rowOtype(instance, row);
					if (ot && v.by_type[ot]) { src = v.by_type[ot]; }
				}
				cellProperties.source = src;
				if (v.strict) {
					cellProperties.strict = true;
					cellProperties.allowInvalid = false;   // hard reject
				} else {
					cellProperties.strict = false;
					cellProperties.allowInvalid = true;    // keep + flag red; resolved at review
				}
			} else if (v && v.numeric) {
				cellProperties.allowInvalid = true;        // keep + flag red
				cellProperties.validator = function(value, cb) {
					if (value === null || value === '' || value === undefined) { cb(true); return; }
					const n = parseFloat(String(value).replace(',', '.'));
					if (isNaN(n)) { cb(false); return; }
					if (v.min !== undefined && n < v.min) { cb(false); return; }
					if (v.max !== undefined && n > v.max) { cb(false); return; }
					cb(true);
				};
			}
			return cellProperties;
		},
		beforePaste: function(data, coords) {
			// The id / geometry columns silently swallowing pasted values
			// would turn intended updates into duplicate creates — strip the
			// values AND tell the user where updates actually go.
			const managed = [];
			const headers = this.getDataAtRow(HDR);
			for (let c = 0; c < headers.length; c++) {
				if (headers[c] === 'strabo_internal_id' || headers[c] === 'geometry_type') {
					managed.push(c);
				}
			}
			if (!managed.length) { return; }
			let stripped = false;
			for (let k = 0; k < coords.length; k++) {
				for (let r = 0; r < data.length; r++) {
					if (coords[k].startRow + r <= HDR) { continue; }   // band + header rows handled by readOnly
					for (let j = 0; j < data[r].length; j++) {
						const target = coords[k].startCol + j;
						if (managed.indexOf(target) !== -1 && data[r][j] !== '' && data[r][j] !== null) {
							data[r][j] = '';
							stripped = true;
						}
					}
				}
			}
			if (stripped) {
				showError('Internal id / geometry values in your paste were ignored — those columns are managed by StraboSpot. ' +
					'To UPDATE existing spots, upload the exported file on the Import page instead (that path keeps the ids and avoids duplicates).');
			}
		},
		beforeChange: function(changes, source) {
			// Handle custom header prefix for editable header cells
			if (changes && source !== 'loadData' && source !== 'band') {
				for (let i = 0; i < changes.length; i++) {
					const [row, prop, oldValue, newValue] = changes[i];
					if (row === HDR && newValue !== null && newValue !== '') {
						if (!isKnownHeader(oldValue) && !isKnownHeader(newValue)) {
							let cleanValue = newValue.toString();
							if (cleanValue.startsWith('Custom_')) {
								cleanValue = cleanValue.substring(7);
							}
							if (cleanValue !== '' && !cleanValue.startsWith('Custom_')) {
								changes[i][3] = 'Custom_' + cleanValue;
							} else if (cleanValue !== '') {
								changes[i][3] = cleanValue;
							}
						}
					}
				}
			}
		},
		afterBeginEditing: function(row, column) {
			if (row === HDR) {
				const cellValue = this.getDataAtCell(row, column);
				if (cellValue && typeof cellValue === 'string') {
					if (cellValue.startsWith('Custom_') && !isKnownHeader(cellValue)) {
						const editor = this.getActiveEditor();
						if (editor && editor.TEXTAREA) {
							editor.TEXTAREA.value = cellValue.substring(7);
							editor.TEXTAREA.setSelectionRange(editor.TEXTAREA.value.length, editor.TEXTAREA.value.length);
						}
					}
				}
			}
		},
		afterChange: function(changes, source) {
			if (source === 'band') { return; }   // band rewrites must not recurse
			if (source !== 'loadData' && changes) {
				// header edits move columns between sections
				for (let i = 0; i < changes.length; i++) {
					if (changes[i][0] === HDR) { refreshBand(); break; }
				}
				checkTableData();
				updateSaveButtonVisibility();
			}
		},
		afterColumnMove: function(movedColumns, finalIndex) {
			refreshBand();
			updateSaveButtonVisibility();
		},
		afterRemoveCol: function() {
			refreshBand();
			updateSaveButtonVisibility();
		},
		afterCreateCol: function() {
			refreshBand();
			updateSaveButtonVisibility();
		},
		beforeCreateRow: function(index, amount, source) {
			// nothing may land above the header row
			if (index <= HDR && source !== 'auto') { return false; }
		},
		beforeRemoveRow: function(index, amount, physicalRows) {
			const rows = physicalRows || [index];
			for (let i = 0; i < rows.length; i++) {
				if (rows[i] <= HDR) {
					showError('The section band and header rows cannot be removed.');
					return false;
				}
			}
		},
		afterRenderer: function(TD, row, col, prop, value, cellProperties) {
			if (value && value.toString().trim() !== '') {
				TD.setAttribute('title', value);
			}
		}
	});

	/**
	 * Grid contents in VISUAL column order (drag/drop-aware), WITHOUT the
	 * band row — row 0 of the result is always the header row, so every
	 * consumer (spec build, review submit, file compare) keeps its original
	 * row semantics. getData() returns source order, so map each visual
	 * column to its physical one.
	 */
	function orderedGrid() {
		const rows = hot.countRows();
		const cols = hot.countCols();
		const source = hot.getData();
		const out = [];
		for (let r = HDR; r < rows; r++) {
			const row = [];
			for (let c = 0; c < cols; c++) {
				const phys = hot.toPhysicalColumn(c);
				const v = source[r][phys];
				row.push(v === null || v === undefined ? '' : v);
			}
			out.push(row);
		}
		return out;
	}

	/**
	 * Recompute the section band: label + color per contiguous run of
	 * same-section columns (visual order), merged into one cell per run.
	 * Called after anything that changes columns; writes with source 'band'
	 * so afterChange does not recurse.
	 */
	let bandRefreshing = false;
	function refreshBand() {
		if (bandRefreshing) { return; }
		bandRefreshing = true;
		try {
			const headers = orderedGrid()[0];
			const sections = [];
			for (let c = 0; c < headers.length; c++) {
				sections.push(sectionForHeader(headers[c] === '' ? null : headers[c]));
			}
			const merges = [];
			const writes = [];
			for (let c = 0; c < sections.length; c++) {
				const isStart = (c === 0) || (sections[c] !== sections[c - 1]);
				if (isStart) {
					let end = c;
					while (end + 1 < sections.length && sections[end + 1] === sections[c]) { end++; }
					if (end > c) {
						merges.push({ row: 0, col: c, rowspan: 1, colspan: end - c + 1 });
					}
					writes.push([0, c, window.sectionMeta[sections[c]] ? window.sectionMeta[sections[c]].label : '']);
				} else {
					writes.push([0, c, '']);
				}
			}
			// trailing spare columns (beyond the headers) carry no band
			for (let c = sections.length; c < hot.countCols(); c++) {
				writes.push([0, c, '']);
			}
			hot.updateSettings({ mergeCells: merges });
			if (writes.length) { hot.setDataAtCell(writes, 'band'); }
		} finally {
			bandRefreshing = false;
		}
	}

	/** Template spec built from the CURRENT grid headers (visual order). */
	function buildSpecFromGrid() {
		const headers = orderedGrid()[0];
		const cols = [];
		for (let i = 0; i < headers.length; i++) {
			const h = String(headers[i] === null ? '' : headers[i]).trim();
			if (h === '') { continue; }
			if (Object.prototype.hasOwnProperty.call(window.headerMap, h)) {
				const d = window.headerMap[h];
				if (d.kind === 'system') {
					cols.push({ kind: 'system', key: d.key });
				} else {
					cols.push({ kind: 'field', group: d.group, name: d.name });
				}
			} else {
				cols.push({ kind: 'custom', header: h });
			}
		}
		return { spec_version: 1, layout: 'long', columns: cols };
	}

	function hasTableData() {
		const tableData = hot.getData();
		for (let i = HDR + 1; i < tableData.length; i++) {
			for (let j = 0; j < tableData[i].length; j++) {
				if (tableData[i][j] !== null && tableData[i][j] !== '') {
					return true;
				}
			}
		}
		return false;
	}

	function updateSaveButtonVisibility() {
		const templateName = templateNameInput.value.trim();
		const hasData = hasTableData();
		const projectId = projectSelect ? projectSelect.value : '';

		if (templateName === '') {
			saveSection.style.display = 'none';
			return;
		}
		if (!hasData && templateName !== '') {
			saveSection.style.display = 'block';
			return;
		}
		if (hasData && templateName !== '' && projectId !== '') {
			saveSection.style.display = 'block';
		} else {
			saveSection.style.display = 'none';
		}
	}

	function checkTableData() {
		const hasData = hasTableData();
		if (hasData) {
			projectInfo.style.display = 'flex';
		} else {
			projectInfo.style.display = 'none';
			if (projectSelect) {
				projectSelect.selectedIndex = 0;
			}
		}
	}

	/** Persist the template via ajax.php; cb(ok). */
	function saveTemplate(cb) {
		const templateName = templateNameInput.value.trim();
		if (templateName === '') {
			showError('Template name is required');
			cb(false);
			return;
		}
		const spec = buildSpecFromGrid();
		if (spec.columns.length === 0) {
			showError('The template has no columns.');
			cb(false);
			return;
		}
		const body = new URLSearchParams();
		body.append('action', 'save_template');
		body.append('name', templateName);
		body.append('spec_json', JSON.stringify(spec));
		if (templatePkey !== '') { body.append('pkey', templatePkey); }

		fetch('ajax.php', { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(res) {
				if (res && res.ok) {
					templatePkey = String(res.pkey);
					cb(true);
				} else {
					showError((res && res.message) ? res.message : 'Could not save the template.');
					cb(false);
				}
			})
			.catch(function() {
				showError('Could not reach the server to save the template.');
				cb(false);
			});
	}

	// Handle Save Button Click: save template; with data present, continue
	// to the review screen (nothing is imported without review).
	saveBtn.addEventListener('click', function() {
		const templateName = templateNameInput.value.trim();
		if (templateName === '') {
			showError('Template name is required');
			return;
		}

		const grid = orderedGrid();
		const headerRow = grid[0];

		// Data in headerless columns?
		const columnsWithoutHeaders = [];
		for (let col = 0; col < headerRow.length; col++) {
			if (headerRow[col] === null || headerRow[col] === '') {
				let colHasData = false;
				for (let row = 1; row < grid.length; row++) {
					if (grid[row][col] !== null && grid[row][col] !== '') {
						colHasData = true;
						break;
					}
				}
				if (colHasData) {
					columnsWithoutHeaders.push(String.fromCharCode(65 + col));
				}
			}
		}
		if (columnsWithoutHeaders.length > 0) {
			showError('Error! Column' + (columnsWithoutHeaders.length > 1 ? 's' : '') + ' ' +
				columnsWithoutHeaders.join(', ') + ' ' +
				(columnsWithoutHeaders.length > 1 ? 'have' : 'has') + ' no header' +
				(columnsWithoutHeaders.length > 1 ? 's' : '') + '. Please fix.');
			return;
		}

		const dataPresent = hasTableData();
		const projectId = projectSelect ? projectSelect.value : '';
		if (dataPresent && projectId === '') {
			showError('Choose the Strabo project the data should upload into.');
			return;
		}

		saveTemplate(function(ok) {
			if (!ok) { return; }
			if (!dataPresent) {
				showInfo('Saved', 'Template "' + templateName + '" saved. Use it any time from the wizard, the Import page, or dataset Export.');
				return;
			}
			// Filter empty rows (keep header row) and continue to review.
			const filteredData = grid.filter(function(row, index) {
				if (index === 0) { return true; }
				return row.some(function(cell) { return cell !== null && cell !== ''; });
			});
			document.getElementById('hidden_template_pkey').value = templatePkey;
			document.getElementById('hidden_template_name').value = templateName;
			document.getElementById('hidden_project_id').value = projectId;
			document.getElementById('hidden_spec_json').value = JSON.stringify(buildSpecFromGrid());
			document.getElementById('hidden_grid_json').value = JSON.stringify(filteredData);
			document.getElementById('submitForm').submit();
		});
	});

	// initial band render (after all declarations above are live)
	refreshBand();
});
