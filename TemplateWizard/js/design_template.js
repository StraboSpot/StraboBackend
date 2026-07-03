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

	// Create initial data with header row
	const initialData = [columns.slice()]; // First row is headers

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
				const fileHeaders = fileData[0].map(function (h) { return (h === null) ? '' : String(h); });

				if (currentHeaders.length !== fileHeaders.length) {
					showError('Error! Column count mismatch. File has ' + fileHeaders.length +
						' columns, template has ' + currentHeaders.length + ' columns. ' +
						'To import a file with different columns, use the Import page instead.');
					fileInput.value = '';
					return;
				}
				for (let i = 0; i < currentHeaders.length; i++) {
					if (String(currentHeaders[i]) !== fileHeaders[i]) {
						showError('Error! Column headers do not match. Expected "' +
							currentHeaders[i] + '" at position ' + (i + 1) + ', but found "' + fileHeaders[i] + '".');
						fileInput.value = '';
						return;
					}
				}

				hot.loadData(fileData);
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
		hot.setDataAtCell(0, visualCol, header);
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
		contextMenu: ['col_left', 'col_right', 'remove_col', '---------', 'row_above', 'row_below', 'remove_row', '---------', 'undo', 'redo'],
		minSpareRows: 5,
		autoWrapRow: false,
		autoWrapCol: false,
		cells: function(row, col) {
			const cellProperties = {};
			if (row === 0) {
				const cellValue = this.instance.getDataAtCell(row, col);
				if (isKnownHeader(cellValue)) {
					// StraboField catalog / system header — read-only
					cellProperties.readOnly = true;
					cellProperties.className = 'htCenter htMiddle htDimmed';
				} else {
					// Custom column header — editable
					cellProperties.readOnly = false;
					cellProperties.className = 'htCenter htMiddle';
				}
			}
			return cellProperties;
		},
		beforeChange: function(changes, source) {
			// Handle custom header prefix for editable header cells
			if (changes && source !== 'loadData') {
				for (let i = 0; i < changes.length; i++) {
					const [row, prop, oldValue, newValue] = changes[i];
					if (row === 0 && newValue !== null && newValue !== '') {
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
			if (row === 0) {
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
			if (source !== 'loadData' && changes) {
				checkTableData();
				updateSaveButtonVisibility();
			}
		},
		afterColumnMove: function(movedColumns, finalIndex) {
			updateSaveButtonVisibility();
		},
		afterRemoveCol: function() {
			updateSaveButtonVisibility();
		},
		afterCreateCol: function() {
			updateSaveButtonVisibility();
		},
		afterRenderer: function(TD, row, col, prop, value, cellProperties) {
			if (value && value.toString().trim() !== '') {
				TD.setAttribute('title', value);
			}
		}
	});

	/**
	 * Grid contents in VISUAL column order (drag/drop-aware). getData()
	 * returns source order, so map each visual column to its physical one.
	 */
	function orderedGrid() {
		const rows = hot.countRows();
		const cols = hot.countCols();
		const source = hot.getData();
		const out = [];
		for (let r = 0; r < rows; r++) {
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
		for (let i = 1; i < tableData.length; i++) {
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
});
