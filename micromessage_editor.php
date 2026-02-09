<?php
/**
 * File: micromessage_editor.php
 * Description: WYSIWYG Markdown editor for StraboMicro startup messages
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");

if(!in_array($userpkey, $admin_pkeys)) die("Not authorized.");

// Handle POST (AJAX save)
if($_SERVER['REQUEST_METHOD'] === 'POST'){
	header('Content-Type: application/json');

	$input = file_get_contents('php://input');
	$data = json_decode($input, true);

	if(!$data || !isset($data['message'])){
		echo json_encode(array('success' => false, 'error' => 'Invalid input. Message field is required.'));
		exit();
	}

	$newUuid = UUID::v4();

	$output = array(
		'uuid' => $newUuid,
		'message' => $data['message']
	);

	$jsonOutput = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

	$result = file_put_contents(__DIR__ . '/micromessage.json', $jsonOutput);

	if($result === false){
		echo json_encode(array('success' => false, 'error' => 'Failed to write file. Check server permissions.'));
		exit();
	}

	echo json_encode(array('success' => true, 'uuid' => $newUuid));
	exit();
}

// Load existing message for GET
$existingMessage = '';
$existingUuid = '';
$jsonFile = __DIR__ . '/micromessage.json';
if(file_exists($jsonFile)){
	$contents = file_get_contents($jsonFile);
	$parsed = json_decode($contents, true);
	if($parsed){
		$existingMessage = isset($parsed['message']) ? $parsed['message'] : '';
		$existingUuid = isset($parsed['uuid']) ? $parsed['uuid'] : '';
	}
}

include 'includes/mheader.php';
?>
			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>StraboMicro Message Editor</h2>
							<p>Edit the startup message displayed in StraboMicro2. A new UUID is generated on each save so the app knows to show the updated message.</p>
						</header>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">

<style>
.EasyMDEContainer {
	background: #fff;
	border-radius: 6px;
	overflow: hidden;
}
.editor-toolbar {
	background: #f5f5f5;
	border-color: #ccc;
	border-bottom: 1px solid #ccc;
}
.editor-toolbar button {
	color: #333 !important;
}
.editor-toolbar button:hover,
.editor-toolbar button.active {
	background: #ddd;
}
.editor-toolbar i.separator {
	border-left-color: #ccc;
	border-right-color: #ccc;
}
.CodeMirror {
	background: #fff;
	color: #333;
	border-color: #ccc;
	min-height: 300px;
}
.CodeMirror .CodeMirror-cursor {
	border-left-color: #333;
}
.CodeMirror .cm-header {
	color: #111;
}
.CodeMirror .cm-link {
	color: #0366d6;
}
.CodeMirror .cm-url {
	color: #0366d6;
}
.editor-statusbar {
	background: #f5f5f5;
	border-top: 1px solid #ccc;
	color: #666;
}
.editor-preview {
	background: #fff;
	color: #333;
}
#saveStatus {
	margin-top: 15px;
	padding: 10px 15px;
	border-radius: 4px;
	display: none;
}
#saveStatus.success {
	background-color: #d4edda;
	color: #155724;
	border: 1px solid #c3e6cb;
}
#saveStatus.error {
	background-color: #f8d7da;
	color: #721c24;
	border: 1px solid #f5c6cb;
}
#currentUuid {
	font-family: monospace;
	font-size: 0.85em;
	color: #aaa;
	margin-bottom: 15px;
}
</style>

<div class="row gtr-uniform gtr-50">
	<div class="col-12">
		<?php if($existingUuid){ ?>
		<div id="currentUuid">Current UUID: <strong><?php echo htmlspecialchars($existingUuid)?></strong></div>
		<?php } ?>
		<textarea id="messageEditor"><?php echo htmlspecialchars($existingMessage)?></textarea>
	</div>
	<div class="col-12">
		<ul class="actions">
			<li><input class="primary" type="button" id="saveBtn" value="Save Message" onclick="saveMessage()"></li>
		</ul>
		<div id="saveStatus"></div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
<script>
var easyMDE = null;
try {
	easyMDE = new EasyMDE({
		element: document.getElementById('messageEditor'),
		spellChecker: false,
		autosave: { enabled: false },
		toolbar: [
			'bold', 'italic', 'heading', '|',
			'unordered-list', 'ordered-list', '|',
			'link', 'horizontal-rule', '|',
			'preview', 'side-by-side', 'fullscreen', '|',
			'guide'
		],
		placeholder: 'Type your message here...'
	});
} catch(e) {
	console.error('EasyMDE failed to initialize:', e);
}

function saveMessage(){
	var btn = document.getElementById('saveBtn');
	var statusDiv = document.getElementById('saveStatus');
	var message = easyMDE ? easyMDE.value() : document.getElementById('messageEditor').value;

	btn.disabled = true;
	btn.value = 'Saving...';
	statusDiv.style.display = 'none';

	var xhr = new XMLHttpRequest();
	xhr.open('POST', '/micromessage_editor.php', true);
	xhr.setRequestHeader('Content-Type', 'application/json');

	xhr.onload = function(){
		btn.disabled = false;
		btn.value = 'Save Message';

		if(xhr.status === 200){
			try {
				var resp = JSON.parse(xhr.responseText);
				if(resp.success){
					statusDiv.className = 'success';
					statusDiv.innerHTML = 'Message saved successfully! New UUID: <strong>' + resp.uuid + '</strong>';
					statusDiv.style.display = 'block';

					var uuidDiv = document.getElementById('currentUuid');
					if(uuidDiv){
						uuidDiv.innerHTML = 'Current UUID: <strong>' + resp.uuid + '</strong>';
					}
				}else{
					statusDiv.className = 'error';
					statusDiv.textContent = 'Error: ' + (resp.error || 'Unknown error');
					statusDiv.style.display = 'block';
				}
			} catch(e) {
				statusDiv.className = 'error';
				statusDiv.textContent = 'Error: Invalid server response.';
				statusDiv.style.display = 'block';
			}
		}else{
			statusDiv.className = 'error';
			statusDiv.textContent = 'Error: Server returned status ' + xhr.status;
			statusDiv.style.display = 'block';
		}
	};

	xhr.onerror = function(){
		btn.disabled = false;
		btn.value = 'Save Message';
		statusDiv.className = 'error';
		statusDiv.textContent = 'Error: Network request failed.';
		statusDiv.style.display = 'block';
	};

	xhr.send(JSON.stringify({message: message}));
}
</script>

					<div class="bottomSpacer"></div>

					</div>
				</div>
<?php
include 'includes/mfooter.php';
?>
