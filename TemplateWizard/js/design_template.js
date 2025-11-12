/**
 * Template Wizard - Design Template (Page 2)
 * Handles HandsonTable initialization and save functionality
 *
 * Expects global variables to be set:
 * - window.templateMethod: 'existing' or 'new'
 * - window.templateColumns: array of column names
 */

document.addEventListener('DOMContentLoaded', function() {
	const templateMethod = window.templateMethod;
	const columns = window.templateColumns;

	// Create initial data with header row
	const initialData = [columns]; // First row is headers

	// Add empty rows for data entry
	for (let i = 0; i < 50; i++) {
		initialData.push(new Array(columns.length).fill(''));
	}

	const container = document.getElementById('hot-container');
	const saveSection = document.getElementById('saveSection');
	const templateNameSection = document.getElementById('templateNameSection');
	const templateNameInput = document.getElementById('template_name');
	const saveBtn = document.getElementById('saveBtn');
	const errorModal = document.getElementById('errorModal');
	const closeModal = document.getElementById('closeModal');
	const projectInfo = document.getElementById('project_info');
	const projectSelect = document.getElementById('project_id');
	const downloadTemplateLink = document.getElementById('downloadTemplateLink');
	const uploadFileLink = document.getElementById('uploadFileLink');
	const fileInput = document.getElementById('fileInput');
	const errorMessage = document.getElementById('errorMessage');

	let hasChanges = false;

	// Store the original column headers (vocabulary-controlled)
	const originalHeaders = columns.slice();

	// Template name field is always visible in the HTML

	// Update save button visibility when template name changes
	templateNameInput.addEventListener('input', function() {
		updateSaveButtonVisibility();
	});

	// Update save button visibility when project select changes
	projectSelect.addEventListener('change', function() {
		updateSaveButtonVisibility();
	});

	// Close modal when clicking OK or outside the modal
	closeModal.addEventListener('click', function() {
		errorModal.style.display = 'none';
	});

	errorModal.addEventListener('click', function(e) {
		if (e.target === errorModal) {
			errorModal.style.display = 'none';
		}
	});

	// Download template link click
	downloadTemplateLink.addEventListener('click', function(e) {
		e.preventDefault();

		// Get current headers from HandsonTable
		const headers = hot.getData()[0];

		// Create worksheet with headers only
		const ws = XLSX.utils.aoa_to_sheet([headers]);

		// Set column widths for better readability
		ws['!cols'] = headers.map(() => ({ wch: 20 }));

		// Apply cell styling and protection to header row
		for (let col = 0; col < headers.length; col++) {
			const cellRef = XLSX.utils.encode_cell({ r: 0, c: col });
			if (!ws[cellRef]) ws[cellRef] = {};
			if (!ws[cellRef].s) ws[cellRef].s = {};

			// Make header cells bold and locked
			ws[cellRef].s = {
				font: { bold: true },
				protection: { locked: true }
			};
		}

		// Enable sheet protection (locks the header row)
		ws['!protect'] = {
			sheet: true,
			selectLockedCells: true,
			selectUnlockedCells: true,
			formatRows: false,
			formatColumns: false,
			insertRows: true,
			deleteRows: true
		};

		// Create workbook and add worksheet
		const wb = XLSX.utils.book_new();
		XLSX.utils.book_append_sheet(wb, ws, "Template");

		// Sanitize template name for filename
		const templateName = templateNameInput.value.trim() || 'Untitled';
		const sanitizedName = templateName
			.replace(/[^a-zA-Z0-9_-]/g, '_')  // Replace special chars with underscore
			.replace(/_+/g, '_')               // Replace multiple underscores with single
			.replace(/^_|_$/g, '');            // Remove leading/trailing underscores

		// Generate filename
		const filename = 'StraboSpot_' + sanitizedName + '_template.xlsx';

		// Trigger download
		XLSX.writeFile(wb, filename);
	});

	// Upload file link click
	uploadFileLink.addEventListener('click', function(e) {
		e.preventDefault();
		fileInput.click();
	});

	// File input change handler
	fileInput.addEventListener('change', function(e) {
		const file = e.target.files[0];
		if (!file) return;

		// Check file size (5MB limit)
		const maxSize = 5 * 1024 * 1024; // 5MB in bytes
		if (file.size > maxSize) {
			errorMessage.textContent = 'Error! File size exceeds 5MB limit. Please use a smaller file.';
			errorModal.style.display = 'flex';
			fileInput.value = ''; // Reset file input
			return;
		}

		// Read file
		const reader = new FileReader();
		reader.onload = function(evt) {
			try {
				const data = new Uint8Array(evt.target.result);
				const workbook = XLSX.read(data, { type: 'array' });

				// Get first sheet
				const firstSheetName = workbook.SheetNames[0];
				const worksheet = workbook.Sheets[firstSheetName];

				// Convert to 2D array
				const fileData = XLSX.utils.sheet_to_json(worksheet, { header: 1 });

				if (fileData.length === 0) {
					errorMessage.textContent = 'Error! File is empty or could not be read.';
					errorModal.style.display = 'flex';
					fileInput.value = '';
					return;
				}

				// Get current table headers (first row)
				const currentHeaders = hot.getData()[0];
				const fileHeaders = fileData[0];

				// Validate headers match exactly
				if (currentHeaders.length !== fileHeaders.length) {
					errorMessage.textContent = 'Error! Column count mismatch. File has ' + fileHeaders.length +
						' columns, template has ' + currentHeaders.length + ' columns.';
					errorModal.style.display = 'flex';
					fileInput.value = '';
					return;
				}

				// Check each header matches
				for (let i = 0; i < currentHeaders.length; i++) {
					if (currentHeaders[i] !== fileHeaders[i]) {
						errorMessage.textContent = 'Error! Column headers do not match. Expected "' +
							currentHeaders[i] + '" at position ' + (i + 1) + ', but found "' + fileHeaders[i] + '".';
						errorModal.style.display = 'flex';
						fileInput.value = '';
						return;
					}
				}

				// Headers match! Load the data
				hot.loadData(fileData);

				// Trigger change detection
				checkTableData();
				updateSaveButtonVisibility();

				// Reset file input for next use
				fileInput.value = '';

			} catch (error) {
				errorMessage.textContent = 'Error! Could not parse file. Please ensure it is a valid CSV or Excel file.';
				errorModal.style.display = 'flex';
				fileInput.value = '';
			}
		};

		reader.readAsArrayBuffer(file);
	});

	// Initialize Handsontable
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
		manualColumnRemove: true,
		copyPaste: true,
		fillHandle: true,
		contextMenu: true,
		autoWrapRow: false,
		autoWrapCol: false,
		cells: function(row, col) {
			const cellProperties = {};
			if (row === 0) {
				// First row is header
				// Check if this cell contains an original vocabulary-controlled header
				const cellValue = this.instance.getDataAtCell(row, col);
				if (originalHeaders.includes(cellValue)) {
					// Original vocabulary-controlled header - read-only
					cellProperties.readOnly = true;
					cellProperties.className = 'htCenter htMiddle htDimmed';
				} else {
					// New custom column header - editable
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

					// Only process header row (row 0) and non-original headers
					if (row === 0 && newValue !== null && newValue !== '') {
						// Check if this is NOT an original vocabulary-controlled header
						if (!originalHeaders.includes(oldValue) && !originalHeaders.includes(newValue)) {
							// Strip "Custom_" prefix if it exists (user is editing)
							let cleanValue = newValue.toString();
							if (cleanValue.startsWith('Custom_')) {
								cleanValue = cleanValue.substring(7); // Remove "Custom_" prefix
							}

							// Add "Custom_" prefix if not already present
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
			// Strip "Custom_" prefix when user starts editing a custom header
			if (row === 0) {
				const cellValue = this.getDataAtCell(row, column);
				if (cellValue && typeof cellValue === 'string') {
					if (cellValue.startsWith('Custom_') && !originalHeaders.includes(cellValue)) {
						// Get the editor and set the value without prefix
						const editor = this.getActiveEditor();
						if (editor && editor.TEXTAREA) {
							editor.TEXTAREA.value = cellValue.substring(7);
							// Move cursor to end
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
		afterRenderer: function(TD, row, col, prop, value, cellProperties) {
			// Add title attribute to show full content on hover
			if (value && value.toString().trim() !== '') {
				TD.setAttribute('title', value);
			}
		}
	});

	function hasTableData() {
		// Get all data from the table
		const tableData = hot.getData();

		// Check if there's any non-empty data (excluding the header row)
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

		// Rule 1: If template name is empty, hide button
		if (templateName === '') {
			saveSection.style.display = 'none';
			return;
		}

		// Rule 2: If no data and template name is set, show button
		if (!hasData && templateName !== '') {
			saveSection.style.display = 'block';
			return;
		}

		// Rule 3: If has data, need both template name and project id set
		if (hasData && templateName !== '' && projectId !== '') {
			saveSection.style.display = 'block';
		} else {
			saveSection.style.display = 'none';
		}
	}

	function checkTableData() {
		const hasData = hasTableData();

		// Show or hide project_info based on whether there's data
		if (hasData) {
			projectInfo.style.display = 'flex';
		} else {
			projectInfo.style.display = 'none';
			// Reset the project select when hiding
			if (projectSelect) {
				projectSelect.selectedIndex = 0;
			}
		}
	}

	// Handle Save Button Click
	saveBtn.addEventListener('click', function() {
		// Validate template name (required for both new and existing templates)
		const templateName = templateNameInput.value.trim();
		if (templateName === '') {
			errorModal.style.display = 'flex';
			return;
		}
		document.getElementById('hidden_template_name').value = templateName;

		// Get project_id if selected
		const projectId = projectSelect ? projectSelect.value : '';
		document.getElementById('hidden_project_id').value = projectId;

		// Get all table data (preserving column order)
		const tableData = hot.getData();

		// Check for data in columns without headers
		const headerRow = tableData[0];
		const columnsWithoutHeaders = [];

		for (let col = 0; col < headerRow.length; col++) {
			// Check if this column has no header
			if (headerRow[col] === null || headerRow[col] === '') {
				// Check if any data row has content in this column
				let hasData = false;
				for (let row = 1; row < tableData.length; row++) {
					if (tableData[row][col] !== null && tableData[row][col] !== '') {
						hasData = true;
						break;
					}
				}
				if (hasData) {
					// Convert column index to letter (0=A, 1=B, etc.)
					const colLetter = String.fromCharCode(65 + col);
					columnsWithoutHeaders.push(colLetter);
				}
			}
		}

		// If there are columns with data but no headers, show error
		if (columnsWithoutHeaders.length > 0) {
			const errorMessage = document.getElementById('errorMessage');
			errorMessage.textContent = 'Error! Column' + (columnsWithoutHeaders.length > 1 ? 's' : '') + ' ' +
				columnsWithoutHeaders.join(', ') + ' ' +
				(columnsWithoutHeaders.length > 1 ? 'have' : 'has') + ' no header' +
				(columnsWithoutHeaders.length > 1 ? 's' : '') + '. Please fix.';
			errorModal.style.display = 'flex';
			return;
		}

		// Filter out completely empty rows (keep header row at index 0)
		const filteredData = tableData.filter(function(row, index) {
			// Always keep the header row (first row)
			if (index === 0) {
				return true;
			}
			// Check if row has any non-empty values
			return row.some(function(cell) {
				return cell !== null && cell !== '';
			});
		});

		// Store data as JSON
		document.getElementById('hidden_table_data').value = JSON.stringify(filteredData);

		// Submit form
		document.getElementById('submitForm').submit();
	});
});
