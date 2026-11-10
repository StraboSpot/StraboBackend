<?php
/**
 * File: field_camp.php
 * Description: Field Camp Wizard, one camp: the steps Basics, Study area and
 *              Accounts. ?new=1 starts a camp (Basics only); ?id=N&step=...
 *              opens an existing one. All changes post to field_camp_action.php.
 *              ?id=N&ajax=plan answers the Accounts step's live preview (which
 *              usernames a template would create and whether any is taken).
 *              Open only to the camp's owner (an instructor) and the admin.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . '/includes/fieldcamp/FieldCamp.php';

if(!FieldCamp::canUse($db, $userpkey)){
	header("HTTP/1.0 404 Not Found");
	exit("Not found.");
}

$isNew = isset($_GET['new']);
$camp = null;
if(!$isNew){
	$camp = FieldCamp::getCamp($db, (int)($_GET['id'] ?? 0));
	if(!$camp || !FieldCamp::canManage($camp, $userpkey)){
		header("HTTP/1.0 404 Not Found");
		exit("Not found.");
	}
}

// ---------------------------------------------------------------------------
// AJAX: account preview (JSON, no page chrome)
// ---------------------------------------------------------------------------
if($camp && isset($_GET['ajax'])){
	header('Content-Type: application/json');
	if($_GET['ajax'] === 'plan'){
		echo json_encode(FieldCamp::planAccounts($db, $camp, (string)($_GET['template'] ?? ''), (int)($_GET['count'] ?? 0)));
	}else{
		echo json_encode(array());
	}
	exit();
}

$steps = array('basics' => 'Basics', 'area' => 'Study area', 'accounts' => 'Accounts');
$step = (string)($_GET['step'] ?? 'basics');
if($isNew || !isset($steps[$step])) $step = 'basics';

$flash = $_SESSION['fieldcamp_flash'] ?? null;
unset($_SESSION['fieldcamp_flash']);
$form = ($flash && isset($flash['form']) && is_array($flash['form'])) ? $flash['form'] : array();
$csrf = FieldCamp::token();

/** A form value: what was just posted (after an error), else the saved value. */
function fc_val($form, $camp, $key, $default = ''){
	if(array_key_exists($key, $form)) return (string)$form[$key];
	if($camp && isset($camp->$key) && $camp->$key !== null) return (string)$camp->$key;
	return (string)$default;
}
function fc_hidden($csrf, $action, $camp){
	return '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
		. '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
		. ($camp ? '<input type="hidden" name="id" value="' . (int)$camp->pkey . '">' : '');
}

$accounts = array(); $viewers = array(); $liveCount = 0; $activeCount = 0;
if($camp && $step === 'accounts'){
	$accounts = FieldCamp::listAccounts($db, $camp->pkey);
	$viewers = FieldCamp::listViewers($db, $camp->pkey);
	$liveCount = count($accounts);
	foreach($accounts as $a){ if(FieldCamp::truthy($a->active)) $activeCount++; }
}
$size = $camp ? FieldCamp::areaSizeKm($camp) : null;

include("includes/mheader.php");
include("includes/fieldcamp/fieldcamp_style.php");
?>

<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2><?php echo $isNew ? 'New Field Camp' : htmlspecialchars($camp->camp_name); ?></h2>
		</header>

		<ul class="fc-steps">
			<li><a href="/field_camp_wizard">&laquo; All field camps</a></li>
<?php foreach($steps as $key => $label){ ?>
			<li><?php
				if($isNew && $key !== 'basics'){
					echo '<span class="fc-step-wait">' . $label . '</span>';
				}elseif($isNew){
					echo '<span class="fc-step-on">' . $label . '</span>';
				}else{
					echo '<a href="/field_camp?id=' . (int)$camp->pkey . '&amp;step=' . $key . '"'
						. ($key === $step ? ' class="fc-step-on"' : '') . '>' . $label . '</a>';
				}
			?></li>
<?php } ?>
		</ul>

<?php if($flash && !empty($flash['ok'])){ ?>
		<div class="fc-flash"><?php echo htmlspecialchars($flash['ok']); ?></div>
<?php } ?>
<?php if($flash && !empty($flash['err'])){ ?>
		<div class="fc-flash-err"><ul>
<?php foreach((array)$flash['err'] as $e){ ?>
			<li><?php echo htmlspecialchars($e); ?></li>
<?php } ?>
		</ul></div>
<?php } ?>

<?php
// ===========================================================================
// Basics
// ===========================================================================
if($step === 'basics'){
?>
		<div class="fc-panel">
			<h4>Basics</h4>
			<form method="post" action="/field_camp_action">
				<?php echo fc_hidden($csrf, $isNew ? 'create' : 'basics', $camp); ?>
				<div class="fc-field">
					<label for="fcName">Field camp name</label>
					<input type="text" name="camp_name" id="fcName" maxlength="120"
						value="<?php echo htmlspecialchars(fc_val($form, $camp, 'camp_name')); ?>"
						placeholder="e.g. KU Field Camp">
				</div>
				<div class="fc-row">
					<div class="fc-field">
						<label for="fcYear">Year</label>
						<input type="number" name="camp_year" id="fcYear" class="fc-short" min="2000" max="2100"
							value="<?php echo htmlspecialchars(fc_val($form, $camp, 'camp_year', date('Y'))); ?>">
					</div>
					<div class="fc-field">
						<label for="fcStart">Start date (optional)</label>
						<input type="date" name="start_date" id="fcStart"
							value="<?php echo htmlspecialchars(fc_val($form, $camp, 'start_date')); ?>">
					</div>
					<div class="fc-field">
						<label for="fcEnd">End date (optional)</label>
						<input type="date" name="end_date" id="fcEnd"
							value="<?php echo htmlspecialchars(fc_val($form, $camp, 'end_date')); ?>">
					</div>
				</div>
				<div class="fc-field">
					<label for="fcProject">Project name</label>
					<input type="text" name="project_name" id="fcProject" maxlength="120"
						value="<?php echo htmlspecialchars(fc_val($form, $camp, 'project_name')); ?>"
						placeholder="e.g. KU Field Camp 2027">
					<div class="fc-hint">The name of the StraboField project the students will work in.</div>
				</div>
				<div class="fc-buttons">
					<button type="submit" class="button primary small"><?php echo $isNew ? 'Create field camp' : 'Save'; ?></button>
<?php if(!$isNew){ ?>
					<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=area" class="button small">Next: Study area</a>
<?php } ?>
				</div>
			</form>
		</div>
<?php
}

// ===========================================================================
// Study area
// ===========================================================================
if($step === 'area'){
	$hasArea = ($size !== null);
?>
		<div class="fc-panel">
			<h4>Study area</h4>
			<div class="fc-mapnote">
				Zoom and move the map until the whole area the camp will work in is visible, then save.
				Everything in view becomes the study area.
			</div>
			<div id="fcMap" class="fc-map"></div>
			<div class="fc-mapnote">
				Area in view: <strong id="fcViewSize">...</strong>
<?php if($hasArea){ ?>
				<span class="fc-small">&nbsp; Saved area (outlined):
					<?php echo number_format($size['width'], 1); ?> km wide,
					<?php echo number_format($size['height'], 1); ?> km tall</span>
<?php } ?>
			</div>
			<form method="post" action="/field_camp_action" id="fcAreaForm">
				<?php echo fc_hidden($csrf, 'area', $camp); ?>
				<input type="hidden" name="west" id="fcWest">
				<input type="hidden" name="south" id="fcSouth">
				<input type="hidden" name="east" id="fcEast">
				<input type="hidden" name="north" id="fcNorth">
				<div class="fc-buttons">
					<button type="submit" class="button primary small"><?php echo $hasArea ? 'Replace the study area with this view' : 'Save this view as the study area'; ?></button>
					<a href="/field_camp?id=<?php echo (int)$camp->pkey; ?>&amp;step=accounts" class="button small">Next: Accounts</a>
				</div>
			</form>
		</div>

		<link rel="stylesheet" href="/assets/js/leaflet/leaflet.css" />
		<script src="/assets/js/leaflet/leaflet.js"></script>
		<script>
		(function(){
			var saved = <?php echo $hasArea ? json_encode(array(
				'west' => (float)$camp->bbox_west, 'south' => (float)$camp->bbox_south,
				'east' => (float)$camp->bbox_east, 'north' => (float)$camp->bbox_north)) : 'null'; ?>;

			var map = L.map('fcMap', {
				worldCopyJump: false,
				maxBounds: [[-85, -180], [85, 180]],
				maxBoundsViscosity: 1.0,
				minZoom: 2
			});
			var topo = L.tileLayer('https://tiles.strabospot.org/v5/mapbox.outdoors/{z}/{x}/{y}.png', {
				maxZoom: 18, noWrap: true,
				attribution: '&copy; Mapbox &copy; OpenStreetMap contributors'
			}).addTo(map);
			var satellite = L.tileLayer('https://tiles.strabospot.org/v5/mapbox.satellite/{z}/{x}/{y}.png', {
				maxZoom: 18, noWrap: true,
				attribution: '&copy; Mapbox &copy; OpenStreetMap contributors'
			});
			L.control.layers({'Topo': topo, 'Satellite': satellite}).addTo(map);
			L.control.scale().addTo(map);

			if(saved){
				var b = [[saved.south, saved.west], [saved.north, saved.east]];
				L.rectangle(b, {color: '#e44c65', weight: 2, fill: false, interactive: false}).addTo(map);
				map.fitBounds(b);
			}else{
				map.setView([39.5, -98.5], 4);
			}

			function clamp(v, lo, hi){ return Math.max(lo, Math.min(hi, v)); }

			function readView(){
				var v = map.getBounds();
				var west = clamp(v.getWest(), -180, 180), east = clamp(v.getEast(), -180, 180);
				var south = clamp(v.getSouth(), -85, 85), north = clamp(v.getNorth(), -85, 85);
				document.getElementById('fcWest').value = west.toFixed(6);
				document.getElementById('fcSouth').value = south.toFixed(6);
				document.getElementById('fcEast').value = east.toFixed(6);
				document.getElementById('fcNorth').value = north.toFixed(6);
				var midlat = (south + north) / 2;
				var w = (east - west) * 111.32 * Math.cos(midlat * Math.PI / 180);
				var h = (north - south) * 111.32;
				var fmt = function(km){ return km >= 100 ? Math.round(km).toLocaleString() : km.toFixed(1); };
				document.getElementById('fcViewSize').textContent = fmt(w) + ' km wide, ' + fmt(h) + ' km tall';
			}
			map.on('moveend', readView);
			readView();
		})();
		</script>
<?php
}

// ===========================================================================
// Accounts
// ===========================================================================
if($step === 'accounts'){
	$template = fc_val($form, $camp, 'username_template');
	$room = FieldCamp::MAX_ACCOUNTS - $liveCount;
?>
<?php if($liveCount > 0){ ?>
		<div class="fc-panel">
			<h4>Accounts for this camp (<?php echo $liveCount; ?>)</h4>
			<div class="fc-small" style="margin-bottom:0.75em;">
				Students sign in to StraboField with one of these usernames and the password you chose.
				These accounts cannot change their password.
			</div>
			<div class="strabotable" style="margin-left:0px;">
				<table class="fc-table">
					<tr><td>Username</td><td>Created</td><td>Status</td></tr>
<?php foreach($accounts as $a){ ?>
					<tr>
						<td><?php echo htmlspecialchars($a->username); ?></td>
						<td><?php echo htmlspecialchars(substr((string)$a->created_date, 0, 10)); ?></td>
						<td><?php echo FieldCamp::truthy($a->active) ? 'Active' : 'Deactivated'; ?></td>
					</tr>
<?php } ?>
				</table>
			</div>
		</div>
<?php } ?>

<?php if($room > 0){ ?>
		<div class="fc-panel">
			<h4><?php echo $liveCount > 0 ? 'Add more accounts' : 'Create accounts'; ?></h4>
			<form method="post" action="/field_camp_action" id="fcAccountForm">
				<?php echo fc_hidden($csrf, 'accounts_create', $camp); ?>
<?php if($liveCount > 0 || (string)$camp->username_template !== ''){ ?>
				<input type="hidden" name="username_template" id="fcTemplate" value="<?php echo htmlspecialchars((string)$camp->username_template); ?>">
				<div class="fc-field">
					<label>Username template</label>
					<div><code><?php echo htmlspecialchars((string)$camp->username_template); ?></code></div>
					<div class="fc-hint">New accounts continue the numbering of this camp.</div>
				</div>
<?php }else{ ?>
				<div class="fc-field">
					<label for="fcTemplate">Username template</label>
					<input type="text" name="username_template" id="fcTemplate" maxlength="60" autocomplete="off"
						value="<?php echo htmlspecialchars($template); ?>" placeholder="e.g. ku_camp27_{n}">
					<div class="fc-hint">Put <code>{n}</code> where the account number goes:
						<code>ku_camp27_{n}</code> gives ku_camp27_1, ku_camp27_2 and so on.
						Include your school and the year so the names are yours alone.</div>
				</div>
<?php } ?>
				<div class="fc-field">
					<label for="fcCount">How many accounts</label>
					<input type="number" name="count" id="fcCount" class="fc-short" min="1" max="<?php echo (int)$room; ?>"
						value="<?php echo htmlspecialchars((string)($form['count'] ?? '')); ?>">
					<div class="fc-hint">Up to <?php echo (int)$room; ?> <?php echo $liveCount > 0 ? 'more' : ''; ?>.</div>
				</div>
				<div class="fc-field">
					<label for="fcPassword">Password for <?php echo $liveCount > 0 ? 'these accounts' : 'every account'; ?></label>
					<input type="text" name="password" id="fcPassword" autocomplete="off" maxlength="72">
					<div class="fc-hint">
<?php if($liveCount > 0){ ?>
						Enter the password the camp's accounts already use, so every account matches.
<?php }else{ ?>
						At least <?php echo FieldCamp::MIN_PASSWORD_LENGTH; ?> characters. Every account of the camp gets this
						password. Write it down: it is not shown again and students cannot change it.
<?php } ?>
					</div>
				</div>
				<div class="fc-preview-msg" id="fcPreviewMsg"></div>
				<div class="fc-preview" id="fcPreview" style="display:none;"></div>
				<div class="fc-buttons">
					<button type="submit" class="button primary small" id="fcCreateBtn">Create accounts</button>
				</div>
			</form>
		</div>
<?php }else{ ?>
		<div class="fc-panel">
			<div class="fc-small">This camp has <?php echo FieldCamp::MAX_ACCOUNTS; ?> accounts, the most a camp can hold.</div>
		</div>
<?php } ?>

		<div class="fc-panel">
			<h4>Instructors</h4>
			<div class="fc-small" style="margin-bottom:0.75em;">
				Other StraboSpot accounts (co-instructors, teaching assistants) that may view each student's
				project once it has been uploaded. You, as the owner of the camp, are always included.
			</div>
<?php if($viewers){ ?>
			<div class="strabotable" style="margin-left:0px;">
				<table class="fc-table">
<?php foreach($viewers as $v){ ?>
					<tr>
						<td><?php echo htmlspecialchars($v->email); ?></td>
						<td><?php echo htmlspecialchars(trim($v->firstname . ' ' . $v->lastname)); ?></td>
						<td>
							<form method="post" action="/field_camp_action" style="margin:0;">
								<?php echo fc_hidden($csrf, 'viewer_remove', $camp); ?>
								<input type="hidden" name="viewer" value="<?php echo (int)$v->pkey; ?>">
								<button type="submit" class="button small">Remove</button>
							</form>
						</td>
					</tr>
<?php } ?>
				</table>
			</div>
<?php } ?>
			<form method="post" action="/field_camp_action">
				<?php echo fc_hidden($csrf, 'viewer_add', $camp); ?>
				<div class="fc-field">
					<label for="fcViewerEmail">Add an instructor by email</label>
					<input type="text" name="email" id="fcViewerEmail" autocomplete="off"
						value="<?php echo htmlspecialchars((string)($form['email'] ?? '')); ?>" placeholder="name@university.edu">
				</div>
				<div class="fc-buttons"><button type="submit" class="button small">Add</button></div>
			</form>
		</div>

<?php if($liveCount > 0){ ?>
		<div class="fc-panel fc-danger">
			<h4>After the camp</h4>
<?php if($activeCount > 0){ ?>
			<div class="fc-small" style="margin-bottom:0.75em;">
				Deactivating closes sign-in for the camp's accounts. Nothing is deleted, and you can
				reactivate them at any time.
			</div>
			<form method="post" action="/field_camp_action"
				onsubmit="return confirm('Deactivate <?php echo (int)$activeCount; ?> account(s)? Students will no longer be able to sign in. You can reactivate them later.');">
				<?php echo fc_hidden($csrf, 'accounts_deactivate', $camp); ?>
				<div class="fc-buttons"><button type="submit" class="button small">Deactivate all accounts</button></div>
			</form>
<?php } ?>
<?php if($activeCount < $liveCount){ ?>
			<form method="post" action="/field_camp_action" style="margin-top:1em;">
				<?php echo fc_hidden($csrf, 'accounts_reactivate', $camp); ?>
				<div class="fc-buttons"><button type="submit" class="button small">Reactivate <?php echo (int)($liveCount - $activeCount); ?> deactivated account(s)</button></div>
			</form>
			<form method="post" action="/field_camp_action" style="margin-top:1.5em;">
				<?php echo fc_hidden($csrf, 'accounts_remove', $camp); ?>
				<div class="fc-field">
					<label for="fcConfirm">Remove the <?php echo (int)($liveCount - $activeCount); ?> deactivated account(s)</label>
					<div class="fc-hint" style="margin-bottom:0.5em;">
						Removed accounts are closed for good and their usernames cannot be used again.
						To confirm, type the name of this camp: <strong><?php echo htmlspecialchars($camp->camp_name); ?></strong>
					</div>
					<input type="text" name="confirm" id="fcConfirm" autocomplete="off">
				</div>
				<div class="fc-buttons"><button type="submit" class="button small">Remove deactivated accounts</button></div>
			</form>
<?php } ?>
		</div>
<?php } ?>

		<script>
		(function(){
			var form = document.getElementById('fcAccountForm');
			if(!form) return;
			var tpl = document.getElementById('fcTemplate');
			var count = document.getElementById('fcCount');
			var box = document.getElementById('fcPreview');
			var msg = document.getElementById('fcPreviewMsg');
			var timer = null;

			function esc(s){
				var d = document.createElement('div');
				d.textContent = (s === null || s === undefined) ? '' : String(s);
				return d.innerHTML;
			}

			function preview(){
				var t = tpl.value.trim(), n = parseInt(count.value, 10);
				if(!t || !n){ box.style.display = 'none'; msg.textContent = ''; return; }
				fetch('/field_camp?id=<?php echo (int)$camp->pkey; ?>&ajax=plan&template=' + encodeURIComponent(t) + '&count=' + n)
					.then(function(r){ return r.json(); })
					.then(function(plan){
						if(plan.error){
							box.style.display = 'none';
							msg.className = 'fc-preview-msg fc-bad';
							msg.textContent = plan.error;
							return;
						}
						box.innerHTML = plan.names.map(function(p){
							return '<div' + (p.taken ? ' class="fc-taken"' : '') + '>' + esc(p.username)
								+ (p.taken ? ' (already in use)' : '') + '</div>';
						}).join('');
						box.style.display = '';
						if(plan.taken > 0){
							msg.className = 'fc-preview-msg fc-bad';
							msg.textContent = plan.taken + ' of these usernames ' + (plan.taken === 1 ? 'is' : 'are')
								+ ' already in use. Change the template, for example add the year.';
						}else{
							msg.className = 'fc-preview-msg';
							msg.textContent = 'These ' + plan.names.length + ' account(s) will be created:';
						}
					});
			}
			function later(){ clearTimeout(timer); timer = setTimeout(preview, 300); }
			if(tpl.type !== 'hidden') tpl.addEventListener('input', later);
			count.addEventListener('input', later);
			preview();
		})();
		</script>
<?php
}
?>

		<div class="bottomSpacer"></div>
	</div>
</div>

<?php
include("includes/mfooter.php");
?>
