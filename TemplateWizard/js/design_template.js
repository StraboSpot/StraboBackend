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

	// Add a few empty rows for data entry
	for (let i = 0; i < 10; i++) {
		initialData.push(new Array(columns.length).fill(''));
	}

	const container = document.getElementById('hot-container');
	const saveSection = document.getElementById('saveSection');
	const templateNameSection = document.getElementById('templateNameSection');
	const templateNameInput = document.getElementById('template_name');
	const saveBtn = document.getElementById('saveBtn');
	const errorModal = document.getElementById('errorModal');
	const closeModal = document.getElementById('closeModal');

	let hasChanges = false;

	// Template name field is always visible in the HTML

	// Show save button when template name changes
	templateNameInput.addEventListener('input', function() {
		showSaveSection();
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
				showSaveSection();
			}
		},
		afterColumnMove: function(movedColumns, finalIndex) {
			showSaveSection();
		}
	});

	function showSaveSection() {
		if (!hasChanges) {
			hasChanges = true;
			saveSection.style.display = 'flex';
		}
	}

	// Handle Save Button Click
	saveBtn.addEventListener('click', function() {
		// Validate template name (required for both new and existing templates)
		const templateName = templateNameInput.value.trim();
		if (templateName === '') {
			errorModal.style.display = 'block';
			return;
		}
		document.getElementById('hidden_template_name').value = templateName;

		// Get all table data (preserving column order)
		const tableData = hot.getData();

		// Store data as JSON
		document.getElementById('hidden_table_data').value = JSON.stringify(tableData);

		// Submit form
		document.getElementById('submitForm').submit();
	});
});
