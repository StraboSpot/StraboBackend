<?php
/**
 * File: events_admin.php
 * Description: Admin page for managing events displayed on the events page
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
$userpkey = (int)$_SESSION['userpkey'];
include("prepare_connections.php");

if(!in_array($userpkey, $admin_pkeys)){
	header("Location: /");
	exit();
}

$jsonFile = __DIR__ . '/data/events.json';
$imageDir = __DIR__ . '/includes/mimages/events/';

// ---------------------------------------------------------------------------
// AJAX handlers (POST requests return JSON and exit)
// ---------------------------------------------------------------------------

if($_SERVER['REQUEST_METHOD'] === 'POST'){

	// Image upload handler
	if(isset($_FILES['event_image'])){
		header('Content-Type: application/json');

		$file = $_FILES['event_image'];
		if($file['error'] !== UPLOAD_ERR_OK){
			echo json_encode(array('success' => false, 'error' => 'Upload failed.'));
			exit();
		}
		if($file['size'] > 2 * 1024 * 1024){
			echo json_encode(array('success' => false, 'error' => 'File must be under 2MB.'));
			exit();
		}
		$allowed = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime = finfo_file($finfo, $file['tmp_name']);
		finfo_close($finfo);
		if(!in_array($mime, $allowed)){
			echo json_encode(array('success' => false, 'error' => 'Only JPG, PNG, GIF, and WebP images are allowed.'));
			exit();
		}
		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		$safeName = 'event_' . time() . '.' . $ext;
		if(!move_uploaded_file($file['tmp_name'], $imageDir . $safeName)){
			echo json_encode(array('success' => false, 'error' => 'Could not save file.'));
			exit();
		}
		echo json_encode(array('success' => true, 'filename' => $safeName));
		exit();
	}

	// JSON action handlers
	header('Content-Type: application/json');
	$input = json_decode(file_get_contents('php://input'), true);
	$action = $input['action'] ?? '';

	$data = file_exists($jsonFile)
		? json_decode(file_get_contents($jsonFile), true)
		: array('events' => array());

	switch($action){

		case 'save':
			$event = array(
				'id'            => !empty($input['id']) ? $input['id'] : 'evt_' . time(),
				'title'         => trim($input['title'] ?? ''),
				'start_day'     => (int)($input['start_day'] ?? 0),
				'end_day'       => ($input['end_day'] ?? '') !== '' ? (int)$input['end_day'] : '',
				'month'         => trim($input['month'] ?? ''),
				'year'          => (int)($input['year'] ?? date('Y')),
				'tags'          => $input['tags'] ?? array(),
				'description'   => trim($input['description'] ?? ''),
				'register_text' => trim($input['register_text'] ?? ''),
				'register_url'  => trim($input['register_url'] ?? ''),
				'image'         => trim($input['image'] ?? ''),
				'sort_order'    => 0
			);

			// Validate required fields
			$errors = array();
			if($event['title'] === '') $errors[] = 'Title is required.';
			if($event['start_day'] < 1 || $event['start_day'] > 31) $errors[] = 'Valid start day is required.';
			if($event['end_day'] !== '' && ($event['end_day'] < 1 || $event['end_day'] > 31)) $errors[] = 'Valid end day is required.';
			if($event['month'] === '') $errors[] = 'Month is required.';
			if($event['year'] < 2020) $errors[] = 'Valid year is required.';
			if(empty($event['tags'])) $errors[] = 'At least one tag is required.';

			if(!empty($errors)){
				echo json_encode(array('success' => false, 'error' => implode(' ', $errors)));
				exit();
			}

			// Update existing or add new
			$found = false;
			foreach($data['events'] as &$existing){
				if($existing['id'] === $event['id']){
					$event['sort_order'] = $existing['sort_order'];
					$existing = $event;
					$found = true;
					break;
				}
			}
			unset($existing);

			if(!$found){
				// Assign sort_order after last
				$maxOrder = -1;
				foreach($data['events'] as $e){
					if(($e['sort_order'] ?? 0) > $maxOrder) $maxOrder = $e['sort_order'];
				}
				$event['sort_order'] = $maxOrder + 1;
				$data['events'][] = $event;
			}
			break;

		case 'delete':
			$deleteId = $input['id'] ?? '';
			$data['events'] = array_values(array_filter($data['events'], function($e) use ($deleteId){
				return $e['id'] !== $deleteId;
			}));
			break;

		case 'reorder':
			$orderedIds = $input['order'] ?? array();
			$idMap = array();
			foreach($data['events'] as &$e){
				$idMap[$e['id']] = &$e;
			}
			unset($e);
			foreach($orderedIds as $idx => $id){
				if(isset($idMap[$id])){
					$idMap[$id]['sort_order'] = $idx;
				}
			}
			break;

		default:
			echo json_encode(array('success' => false, 'error' => 'Unknown action.'));
			exit();
	}

	file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	echo json_encode(array('success' => true, 'events' => $data['events']));
	exit();
}

// ---------------------------------------------------------------------------
// Page rendering (GET)
// ---------------------------------------------------------------------------

$data = file_exists($jsonFile)
	? json_decode(file_get_contents($jsonFile), true)
	: array('events' => array());

$events = $data['events'] ?? array();
usort($events, function($a, $b){
	return ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0);
});

$tag_labels = array(
	'inperson' => 'In Person',
	'virtual' => 'Virtual',
	'workshop' => 'Workshop',
	'shortcourse' => 'Short Course',
	'fieldtrip' => 'Field Trip',
	'conference' => 'Conference',
	'poster' => 'Poster',
	'talk' => 'Talk'
);

$months = array('January','February','March','April','May','June','July','August','September','October','November','December');

include("includes/mheader.php");
?>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Events Admin</h2>
		</header>

		<div style="margin-bottom: 1.5em;">
			<a href="/events" style="color: #e44c65;">&larr; View Events Page</a>
		</div>

		<!-- Status Message -->
		<div id="statusMsg" style="display:none; border-radius: 4px; padding: 1em; margin-bottom: 1.5em; font-size: 1.1em;"></div>

		<!-- ================================================================ -->
		<!-- EVENT LIST -->
		<!-- ================================================================ -->
		<div class="form-card" style="margin-bottom: 2em;">
			<h3 class="form-section-title">Current Events</h3>
			<div id="eventList">
				<?php if(empty($events)): ?>
				<p style="color: rgba(255,255,255,0.5);">No events yet. Use the form below to add one.</p>
				<?php else: ?>
				<?php foreach($events as $idx => $event): ?>
				<div class="admin-event-card" data-id="<?php echo htmlspecialchars($event['id']); ?>">
					<div class="admin-event-info">
						<div class="admin-event-title"><?php echo htmlspecialchars($event['title']); ?></div>
						<div class="admin-event-meta">
							<span><?php echo htmlspecialchars($event['start_day'] . '-' . $event['end_day'] . ' ' . $event['month'] . ' ' . $event['year']); ?></span>
							<?php foreach(($event['tags'] ?? array()) as $tag): ?>
							<span class="event-tag event-tag-<?php echo htmlspecialchars($tag); ?>" style="font-size:0.75em;"><?php echo htmlspecialchars($tag_labels[$tag] ?? $tag); ?></span>
							<?php endforeach; ?>
						</div>
					</div>
					<?php if(!empty($event['image'])): ?>
					<div class="admin-event-thumb">
						<img src="/includes/mimages/events/<?php echo htmlspecialchars($event['image']); ?>" alt="">
					</div>
					<?php endif; ?>
					<div class="admin-event-actions">
						<button type="button" class="btn-action btn-move-up" onclick="moveEvent('<?php echo htmlspecialchars($event['id']); ?>', 'up')" title="Move Up"<?php if($idx === 0) echo ' disabled'; ?>>&uarr;</button>
						<button type="button" class="btn-action btn-move-down" onclick="moveEvent('<?php echo htmlspecialchars($event['id']); ?>', 'down')" title="Move Down"<?php if($idx === count($events)-1) echo ' disabled'; ?>>&darr;</button>
						<button type="button" class="btn-action btn-edit" onclick="editEvent('<?php echo htmlspecialchars($event['id']); ?>')" title="Edit">Edit</button>
						<button type="button" class="btn-action btn-delete" onclick="deleteEvent('<?php echo htmlspecialchars($event['id']); ?>')" title="Delete">Delete</button>
					</div>
				</div>
				<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- ================================================================ -->
		<!-- ADD / EDIT FORM -->
		<!-- ================================================================ -->
		<div class="form-card" id="eventFormCard">
			<h3 class="form-section-title" id="formTitle">Add New Event</h3>

			<input type="hidden" id="event_id" value="">
			<input type="hidden" id="event_image_filename" value="">

			<!-- Date -->
			<div class="form-section">
				<h3 class="form-section-title">Date</h3>
				<div class="form-grid">
					<div class="form-field">
						<label>Start Day <span class="required">*</span></label>
						<input type="number" id="event_start_day" min="1" max="31" placeholder="e.g. 21">
					</div>
					<div class="form-field">
						<label>End Day</label>
						<input type="number" id="event_end_day" min="1" max="31" placeholder="e.g. 24">
					</div>
					<div class="form-field">
						<label>Month <span class="required">*</span></label>
						<select id="event_month">
							<option value="">Select...</option>
							<?php foreach($months as $m): ?>
							<option value="<?php echo $m; ?>"><?php echo $m; ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="form-field">
						<label>Year <span class="required">*</span></label>
						<input type="number" id="event_year" min="2020" max="2100" value="<?php echo date('Y'); ?>">
					</div>
				</div>
			</div>

			<!-- Details -->
			<div class="form-section">
				<h3 class="form-section-title">Details</h3>
				<div class="form-grid">
					<div class="form-field" style="grid-column: 1 / -1;">
						<label>Title <span class="required">*</span></label>
						<input type="text" id="event_title" placeholder="e.g. GSA Cordilleran Short Course">
					</div>
					<div class="form-field" style="grid-column: 1 / -1;">
						<label>Description</label>
						<textarea id="event_description" rows="3" placeholder="Brief description of the event..."></textarea>
					</div>
				</div>
			</div>

			<!-- Tags -->
			<div class="form-section">
				<h3 class="form-section-title">Tags <span class="required">*</span></h3>
				<div style="display: flex; flex-wrap: wrap; gap: 0.6em;">
					<?php foreach($tag_labels as $key => $label): ?>
					<label class="tag-checkbox event-tag event-tag-<?php echo $key; ?>" style="cursor:pointer; opacity:0.35;">
						<input type="checkbox" name="event_tags" value="<?php echo $key; ?>" style="display:none;" onchange="this.parentElement.style.opacity = this.checked ? '1' : '0.35'">
						<?php echo htmlspecialchars($label); ?>
					</label>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Registration -->
			<div class="form-section">
				<h3 class="form-section-title">Registration Link</h3>
				<div class="form-grid">
					<div class="form-field">
						<label>Link Text</label>
						<input type="text" id="event_register_text" placeholder="e.g. Register Here:">
					</div>
					<div class="form-field">
						<label>URL</label>
						<input type="text" id="event_register_url" placeholder="https://...">
					</div>
				</div>
			</div>

			<!-- Image -->
			<div class="form-section">
				<h3 class="form-section-title">Image</h3>
				<div class="form-grid">
					<div class="form-field">
						<label>Upload Image (JPG, PNG, GIF, WebP &mdash; max 2MB)</label>
						<input type="file" id="event_image_file" accept="image/jpeg,image/png,image/gif,image/webp" onchange="uploadImage(this)">
					</div>
					<div class="form-field">
						<label>Current Image</label>
						<div id="imagePreview" style="min-height:60px;">
							<span style="color: rgba(255,255,255,0.4);">No image selected</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Submit -->
			<div style="text-align: center; padding-top: 1em; display: flex; justify-content: center; gap: 1em;">
				<button type="button" class="button primary small" id="btnSave" onclick="saveEvent()">Add Event</button>
				<button type="button" class="button small" id="btnCancel" onclick="cancelEdit()" style="display:none;">Cancel</button>
			</div>
		</div>

		<div class="bottomSpacer"></div>

	</div>
</div>

<!-- ====================================================================== -->
<!-- Event data for JS -->
<!-- ====================================================================== -->
<script>
var eventsData = <?php echo json_encode($events); ?>;

var tagLabels = <?php echo json_encode($tag_labels); ?>;

// ---- Status helpers ----
function showStatus(msg, isError){
	var el = $('#statusMsg');
	el.text(msg);
	if(isError){
		el.css({'background':'rgba(228,76,101,0.15)','border':'1px solid #e44c65','color':'#e44c65'});
	} else {
		el.css({'background':'rgba(56,118,29,0.15)','border':'1px solid #38761d','color':'#8bc34a'});
	}
	el.show();
	setTimeout(function(){ el.fadeOut(); }, 4000);
}

// ---- Render event list ----
function renderEventList(){
	eventsData.sort(function(a,b){ return (a.sort_order||0) - (b.sort_order||0); });
	var html = '';
	if(eventsData.length === 0){
		html = '<p style="color: rgba(255,255,255,0.5);">No events yet. Use the form below to add one.</p>';
	}
	for(var i = 0; i < eventsData.length; i++){
		var e = eventsData[i];
		html += '<div class="admin-event-card" data-id="' + e.id + '">';
		html += '<div class="admin-event-info">';
		html += '<div class="admin-event-title">' + escHtml(e.title) + '</div>';
		html += '<div class="admin-event-meta">';
		var dayStr = (e.end_day && e.end_day != e.start_day) ? e.start_day + '-' + e.end_day : e.start_day;
		html += '<span>' + dayStr + ' ' + escHtml(e.month) + ' ' + e.year + '</span> ';
		for(var t = 0; t < e.tags.length; t++){
			html += '<span class="event-tag event-tag-' + e.tags[t] + '" style="font-size:0.75em;">' + escHtml(tagLabels[e.tags[t]] || e.tags[t]) + '</span>';
		}
		html += '</div></div>';
		if(e.image){
			html += '<div class="admin-event-thumb"><img src="/includes/mimages/events/' + escHtml(e.image) + '" alt=""></div>';
		}
		html += '<div class="admin-event-actions">';
		html += '<button type="button" class="btn-action btn-move-up" onclick="moveEvent(\'' + e.id + '\', \'up\')" title="Move Up"' + (i===0?' disabled':'') + '>&uarr;</button>';
		html += '<button type="button" class="btn-action btn-move-down" onclick="moveEvent(\'' + e.id + '\', \'down\')" title="Move Down"' + (i===eventsData.length-1?' disabled':'') + '>&darr;</button>';
		html += '<button type="button" class="btn-action btn-edit" onclick="editEvent(\'' + e.id + '\')" title="Edit">Edit</button>';
		html += '<button type="button" class="btn-action btn-delete" onclick="deleteEvent(\'' + e.id + '\')" title="Delete">Delete</button>';
		html += '</div></div>';
	}
	$('#eventList').html(html);
}

function escHtml(str){
	var div = document.createElement('div');
	div.appendChild(document.createTextNode(str || ''));
	return div.innerHTML;
}

// ---- Save (add or update) ----
function saveEvent(){
	// Gather tags
	var tags = [];
	$('input[name="event_tags"]:checked').each(function(){ tags.push($(this).val()); });

	// Validate
	var errors = '';
	if($('#event_title').val().trim() === '') errors += 'Title is required.\n';
	if($('#event_start_day').val() === '' || $('#event_start_day').val() < 1) errors += 'Valid start day is required.\n';
	var endDay = $('#event_end_day').val();
	if(endDay !== '' && (parseInt(endDay) < 1 || parseInt(endDay) > 31)) errors += 'Valid end day is required.\n';
	if($('#event_month').val() === '') errors += 'Month is required.\n';
	if($('#event_year').val() === '' || $('#event_year').val() < 2020) errors += 'Valid year is required.\n';
	if(tags.length === 0) errors += 'At least one tag is required.\n';
	if(errors !== ''){
		alert(errors);
		return;
	}

	var payload = {
		action: 'save',
		id: $('#event_id').val(),
		title: $('#event_title').val().trim(),
		start_day: parseInt($('#event_start_day').val()),
		end_day: parseInt($('#event_end_day').val()),
		month: $('#event_month').val(),
		year: parseInt($('#event_year').val()),
		tags: tags,
		description: $('#event_description').val().trim(),
		register_text: $('#event_register_text').val().trim(),
		register_url: $('#event_register_url').val().trim(),
		image: $('#event_image_filename').val()
	};

	$.ajax({
		url: '/events_admin',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify(payload),
		dataType: 'json',
		success: function(resp){
			if(resp.success){
				eventsData = resp.events;
				renderEventList();
				cancelEdit();
				showStatus(payload.id ? 'Event updated.' : 'Event added.', false);
			} else {
				showStatus(resp.error || 'Save failed.', true);
			}
		},
		error: function(){
			showStatus('Request failed. Please try again.', true);
		}
	});
}

// ---- Edit ----
function editEvent(id){
	var event = null;
	for(var i = 0; i < eventsData.length; i++){
		if(eventsData[i].id === id){ event = eventsData[i]; break; }
	}
	if(!event) return;

	$('#event_id').val(event.id);
	$('#event_title').val(event.title);
	$('#event_start_day').val(event.start_day);
	$('#event_end_day').val(event.end_day);
	$('#event_month').val(event.month);
	$('#event_year').val(event.year);
	$('#event_description').val(event.description);
	$('#event_register_text').val(event.register_text || '');
	$('#event_register_url').val(event.register_url || '');
	$('#event_image_filename').val(event.image || '');

	// Set tag checkboxes
	$('input[name="event_tags"]').each(function(){
		var checked = event.tags.indexOf($(this).val()) !== -1;
		$(this).prop('checked', checked);
		$(this).parent().css('opacity', checked ? '1' : '0.35');
	});

	// Image preview
	if(event.image){
		$('#imagePreview').html('<img src="/includes/mimages/events/' + escHtml(event.image) + '" style="max-height:80px; border-radius:4px;">');
	} else {
		$('#imagePreview').html('<span style="color: rgba(255,255,255,0.4);">No image selected</span>');
	}

	$('#formTitle').text('Edit Event');
	$('#btnSave').text('Update Event');
	$('#btnCancel').show();

	// Scroll to form
	$('html, body').animate({ scrollTop: $('#eventFormCard').offset().top - 80 }, 300);
}

// ---- Cancel edit ----
function cancelEdit(){
	$('#event_id').val('');
	$('#event_title').val('');
	$('#event_start_day').val('');
	$('#event_end_day').val('');
	$('#event_month').val('');
	$('#event_year').val('<?php echo date('Y'); ?>');
	$('#event_description').val('');
	$('#event_register_text').val('');
	$('#event_register_url').val('');
	$('#event_image_filename').val('');
	$('#event_image_file').val('');

	$('input[name="event_tags"]').each(function(){
		$(this).prop('checked', false);
		$(this).parent().css('opacity', '0.35');
	});

	$('#imagePreview').html('<span style="color: rgba(255,255,255,0.4);">No image selected</span>');
	$('#formTitle').text('Add New Event');
	$('#btnSave').text('Add Event');
	$('#btnCancel').hide();
}

// ---- Delete ----
function deleteEvent(id){
	if(!confirm('Are you sure you want to delete this event?')) return;

	$.ajax({
		url: '/events_admin',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({ action: 'delete', id: id }),
		dataType: 'json',
		success: function(resp){
			if(resp.success){
				eventsData = resp.events;
				renderEventList();
				// If we were editing this event, cancel the edit
				if($('#event_id').val() === id) cancelEdit();
				showStatus('Event deleted.', false);
			} else {
				showStatus(resp.error || 'Delete failed.', true);
			}
		},
		error: function(){
			showStatus('Request failed. Please try again.', true);
		}
	});
}

// ---- Move (reorder) ----
function moveEvent(id, direction){
	var idx = -1;
	for(var i = 0; i < eventsData.length; i++){
		if(eventsData[i].id === id){ idx = i; break; }
	}
	if(idx === -1) return;
	if(direction === 'up' && idx === 0) return;
	if(direction === 'down' && idx === eventsData.length - 1) return;

	var swapIdx = direction === 'up' ? idx - 1 : idx + 1;
	var tmp = eventsData[idx];
	eventsData[idx] = eventsData[swapIdx];
	eventsData[swapIdx] = tmp;

	// Build new order array
	var order = [];
	for(var i = 0; i < eventsData.length; i++){
		order.push(eventsData[i].id);
	}

	$.ajax({
		url: '/events_admin',
		method: 'POST',
		contentType: 'application/json',
		data: JSON.stringify({ action: 'reorder', order: order }),
		dataType: 'json',
		success: function(resp){
			if(resp.success){
				eventsData = resp.events;
				renderEventList();
			} else {
				showStatus(resp.error || 'Reorder failed.', true);
			}
		},
		error: function(){
			showStatus('Request failed. Please try again.', true);
		}
	});
}

// ---- Image upload ----
function uploadImage(input){
	if(!input.files || !input.files[0]) return;

	var file = input.files[0];
	if(file.size > 2 * 1024 * 1024){
		alert('Image must be under 2MB.');
		input.value = '';
		return;
	}

	var formData = new FormData();
	formData.append('event_image', file);

	$('#imagePreview').html('<span style="color: rgba(255,255,255,0.4);">Uploading...</span>');

	$.ajax({
		url: '/events_admin',
		method: 'POST',
		data: formData,
		processData: false,
		contentType: false,
		dataType: 'json',
		success: function(resp){
			if(resp.success){
				$('#event_image_filename').val(resp.filename);
				$('#imagePreview').html('<img src="/includes/mimages/events/' + escHtml(resp.filename) + '" style="max-height:80px; border-radius:4px;">');
			} else {
				$('#imagePreview').html('<span style="color: #e44c65;">' + escHtml(resp.error) + '</span>');
			}
		},
		error: function(){
			$('#imagePreview').html('<span style="color: #e44c65;">Upload failed.</span>');
		}
	});
}
</script>

<style>
.form-card {
	background: rgba(255, 255, 255, 0.04);
	border: 1px solid rgba(255, 255, 255, 0.1);
	border-radius: 6px;
	padding: 2em;
}

.form-section {
	margin-bottom: 2em;
	padding-bottom: 1.5em;
	border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.form-section:last-child {
	margin-bottom: 0;
	padding-bottom: 0;
	border-bottom: none;
}

.form-section-title {
	color: #fff;
	font-size: 1.1em;
	font-weight: 600;
	margin-bottom: 1em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.form-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 1.2em;
}

@media (max-width: 768px) {
	.form-grid {
		grid-template-columns: 1fr;
	}
}

.form-field label {
	display: block;
	color: rgba(255, 255, 255, 0.7);
	font-size: 0.9em;
	margin-bottom: 0.4em;
}

.form-field input[type="number"],
.form-field input[type="text"],
.form-field input[type="file"],
.form-field textarea,
.form-field select {
	width: 100%;
	background: rgba(255, 255, 255, 0.08);
	border: 1px solid rgba(255, 255, 255, 0.2);
	border-radius: 4px;
	color: #ffffff;
	padding: 0.6em 0.8em;
	font-size: 1em;
	box-sizing: border-box;
}

.form-field input[type="number"]:focus,
.form-field input[type="text"]:focus,
.form-field textarea:focus,
.form-field select:focus {
	border-color: #e44c65;
	outline: none;
}

.form-field select option {
	background: #2a2a3a;
	color: #ffffff;
}

.required {
	color: #e44c65;
}

/* Event tag colors (reuse from events page) */
.event-tag {
	display: inline-block;
	color: #ffffff;
	font-size: 0.85em;
	font-weight: 700;
	padding: 0.2em 0.6em;
	margin-right: 0.4em;
	margin-bottom: 0.4em;
	border-radius: 2px;
}
.event-tag-inperson { background-color: #b45f06; }
.event-tag-virtual { background-color: #bf9000; }
.event-tag-workshop { background-color: #85200c; }
.event-tag-shortcourse { background-color: #0b5394; }
.event-tag-fieldtrip { background-color: #351c75; }
.event-tag-conference { background-color: #741b47; }
.event-tag-poster { background-color: #38761d; }
.event-tag-talk { background-color: #134f5c; }

/* Admin event list cards */
.admin-event-card {
	display: flex;
	align-items: center;
	background: rgba(255, 255, 255, 0.03);
	border: 1px solid rgba(255, 255, 255, 0.08);
	border-radius: 4px;
	padding: 1em;
	margin-bottom: 0.75em;
}

.admin-event-info {
	flex: 1;
	min-width: 0;
}

.admin-event-title {
	color: #ffffff;
	font-weight: 700;
	font-size: 1.05em;
	margin-bottom: 0.3em;
}

.admin-event-meta {
	color: rgba(255, 255, 255, 0.6);
	font-size: 0.9em;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.4em;
}

.admin-event-thumb {
	flex: 0 0 60px;
	margin: 0 1em;
}

.admin-event-thumb img {
	max-width: 60px;
	max-height: 45px;
	border-radius: 3px;
}

.admin-event-actions {
	flex: 0 0 auto;
	display: flex;
	gap: 0.4em;
}

.btn-action {
	background: rgba(255, 255, 255, 0.08);
	border: 1px solid rgba(255, 255, 255, 0.15);
	color: rgba(255, 255, 255, 0.8);
	padding: 0.3em 0.6em;
	border-radius: 3px;
	cursor: pointer;
	font-size: 0.85em;
}

.btn-action:hover {
	background: rgba(255, 255, 255, 0.15);
	color: #fff;
}

.btn-action:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

.btn-delete {
	color: #e44c65;
	border-color: rgba(228, 76, 101, 0.3);
}

.btn-delete:hover {
	background: rgba(228, 76, 101, 0.15);
	color: #e44c65;
}

.btn-edit {
	color: #8bc34a;
	border-color: rgba(139, 195, 74, 0.3);
}

.btn-edit:hover {
	background: rgba(139, 195, 74, 0.15);
	color: #8bc34a;
}

@media (max-width: 736px) {
	.admin-event-card {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.75em;
	}
	.admin-event-thumb {
		margin: 0;
	}
	.admin-event-actions {
		width: 100%;
		justify-content: flex-end;
	}
}
</style>

<?php
include("includes/mfooter.php");
?>
