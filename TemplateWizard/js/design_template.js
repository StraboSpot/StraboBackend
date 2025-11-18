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

	let hasChanges = false;

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
		copyPaste: true,
		fillHandle: true,
		contextMenu: true,
		columns: columns.map(() => ({ type: 'text' })),
		cells: function(row, col) {
			const cellProperties = {};
			if (row === 0) {
				// First row is header - make it read-only
				cellProperties.readOnly = true;
				cellProperties.className = 'htCenter htMiddle htDimmed';
			}
			return cellProperties;
		},
		afterChange: function(changes, source) {
			if (source !== 'loadData' && changes) {
				checkTableData();
				updateSaveButtonVisibility();
			}
		},
		afterColumnMove: function(movedColumns, finalIndex) {
			updateSaveButtonVisibility();
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

		// Store data as JSON
		document.getElementById('hidden_table_data').value = JSON.stringify(tableData);

		// Submit form
		document.getElementById('submitForm').submit();
	});
});
