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

	// My Templates table: delete (soft delete server-side; imports/exports
	// that used the template are unaffected — the spec travels in the files)
	document.querySelectorAll('.tw-delete-template').forEach(function(btn) {
		btn.addEventListener('click', function(e) {
			e.preventDefault();
			const name = this.getAttribute('data-name');
			const pkey = this.getAttribute('data-pkey');
			if (!window.confirm('Delete template "' + name + '"? Files exported with it keep working — their template travels inside the file.')) {
				return;
			}
			const body = new URLSearchParams();
			body.append('action', 'delete_template');
			body.append('pkey', pkey);
			fetch('ajax.php', { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res && res.ok) {
						window.location = 'index.php?deleted=' + encodeURIComponent(name);
					} else {
						alert((res && res.message) ? res.message : 'Could not delete the template.');
					}
				})
				.catch(function() { alert('Could not reach the server to delete the template.'); });
		});
	});
});
