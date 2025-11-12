/**
 * Template Wizard - Choose Template (Page 1)
 * Handles form interactions for template selection
 */

document.addEventListener('DOMContentLoaded', function() {
	const methodExisting = document.getElementById('method_existing');
	const methodNew = document.getElementById('method_new');
	const existingSection = document.getElementById('existing_section');
	const newSection = document.getElementById('new_section');
	const templateSelect = document.getElementById('template_select');
	const continueBtn = document.getElementById('continueBtn');

	// Handle Existing Template Radio Selection
	methodExisting.addEventListener('change', function() {
		if (this.checked) {
			existingSection.style.display = 'block';
			newSection.style.display = 'none';
			templateSelect.disabled = false;

			// Disable new template checkboxes
			document.querySelectorAll('#new_section input[type="checkbox"]:not(#section_spot)').forEach(cb => {
				cb.checked = false;
			});

			// Continue button enabled only if template selected
			continueBtn.disabled = (templateSelect.value === '');
		}
	});

	// Handle New Template Radio Selection
	methodNew.addEventListener('change', function() {
		if (this.checked) {
			newSection.style.display = 'block';
			existingSection.style.display = 'none';
			templateSelect.disabled = true;
			templateSelect.value = '';

			// Enable continue button (Spot Data always checked)
			continueBtn.disabled = false;
		}
	});

	// Handle Template Selection Change
	templateSelect.addEventListener('change', function() {
		continueBtn.disabled = (this.value === '');
	});

	// Prevent unchecking Spot Data (always required)
	const spotCheckbox = document.getElementById('section_spot');
	spotCheckbox.addEventListener('click', function(e) {
		if (!this.checked) {
			e.preventDefault();
			this.checked = true;
		}
	});
});
