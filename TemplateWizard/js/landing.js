/**
 * Template Wizard - landing page (index_new.php)
 * Opens the new-template sections picker under its card, keeps Spot data
 * checked, and deletes saved templates.
 */

document.addEventListener('DOMContentLoaded', function() {
	const toggle = document.getElementById('tw-new-toggle');
	const panel = document.getElementById('tw-new-panel');
	const cancel = document.getElementById('tw-new-cancel');

	function openPanel() {
		panel.style.display = 'block';
		toggle.setAttribute('aria-expanded', 'true');
		panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}
	function closePanel() {
		panel.style.display = 'none';
		toggle.setAttribute('aria-expanded', 'false');
	}

	toggle.addEventListener('click', function(e) {
		e.preventDefault();
		if (panel.style.display === 'none') { openPanel(); } else { closePanel(); }
	});
	cancel.addEventListener('click', function(e) {
		e.preventDefault();
		closePanel();
	});

	// Spot data is always part of a template.
	const spotCheckbox = document.getElementById('section_spot');
	spotCheckbox.addEventListener('click', function(e) {
		if (!this.checked) {
			e.preventDefault();
			this.checked = true;
		}
	});

	// Delete a saved template (soft delete server-side; files exported with
	// it keep working because the template travels inside the file).
	document.querySelectorAll('.tw-delete-template').forEach(function(btn) {
		btn.addEventListener('click', function(e) {
			e.preventDefault();
			const name = this.getAttribute('data-name');
			const pkey = this.getAttribute('data-pkey');
			if (!window.confirm('Delete template "' + name + '"? Files exported with it keep working. Their template travels inside the file.')) {
				return;
			}
			const body = new URLSearchParams();
			body.append('action', 'delete_template');
			body.append('pkey', pkey);
			fetch('ajax.php', { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res && res.ok) {
						window.location = window.location.pathname + '?deleted=' + encodeURIComponent(name);
					} else {
						alert((res && res.message) ? res.message : 'Could not delete the template.');
					}
				})
				.catch(function() { alert('Could not reach the server to delete the template.'); });
		});
	});
});
